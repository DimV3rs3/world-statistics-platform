<?php
/**
 * Очистка CSV по 8 шагам (загрузка → дубликаты → пропуски → типы → выбросы → строки → признаки → кодирование/нормализация).
 *
 * Режим по умолчанию `platform`: сохраняет смысл чисел (без MinMax/One-Hot), без глобального IQR по колонкам
 * (иначе в формате «страна–год–показатель» крупные страны все режутся к одному потолку).
 * Полный шаг 8 (масштабирование, label encoding) включается через опции.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Csv_Cleaner {

	private const MAX_ROWS = 100000;

	/** @var list<string> */
	private $log = array();

	/** @var array<string, mixed> */
	private $options = array();

	/**
	 * @param array<string, mixed> $options {
	 *   @type string $purpose           `platform` (по умолчанию) или `analytics` (полная нормализация шага 8).
	 *   @type bool   $apply_minmax      Принудительно MinMax [0,1] для числовых колонок (только analytics).
	 *   @type bool   $apply_label_encode Кодировать строки в 1..n (только analytics).
	 *   @type bool   $apply_iqr_outliers Глобальный IQR winsorize по числовым колонкам (по умолчанию только в analytics).
	 * }
	 * @return array{success:bool, clean_file?:string, log?:list<string>, rows?:int, error?:string}|\WP_Error
	 */
	public function process( string $file_path, ?string $output_path = null, array $options = array() ) {
		$this->log     = array();
		$this->options = array_merge(
			array(
				'purpose'           => 'platform',
				'apply_iqr_outliers'=> null,
			),
			$options
		);
		if ( $this->options['apply_iqr_outliers'] === null ) {
			$this->options['apply_iqr_outliers'] = ( isset( $this->options['purpose'] ) && (string) $this->options['purpose'] === 'analytics' );
		}

		$this->log_step( 'Начата обработка: ' . basename( $file_path ) );

		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Файл не найден или не читается.', 'flavor-worldstat' ) );
		}

		$loaded = $this->load_csv( $file_path );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$data       = $loaded['rows'];
		$delimiter  = $loaded['delimiter'];
		$num_cols   = $loaded['cols'];
		$this->log_step( sprintf( 'Загрузка и осмотр → строк данных: %d, колонок: %d, разделитель: %s', count( $data ), $num_cols, $delimiter ) );

		$data = $this->remove_duplicates( $data );
		$data = $this->handle_missing_values( $data );
		$data = $this->convert_data_types( $data );
		$data = $this->handle_outliers( $data );
		$data = $this->clean_strings( $data );
		$data = $this->feature_engineering( $data );
		$data = $this->encode_and_normalize( $data );
		$data = $this->remove_rows_without_numeric_value( $data );

		if ( count( $data ) < 2 ) {
			return new \WP_Error(
				'wsp_csv_clean',
				__( 'После очистки не осталось строк с числовым значением в колонке показателя (3-я колонка).', 'flavor-worldstat' )
			);
		}

		if ( $output_path === null ) {
			$output_path = preg_replace( '/\.csv$/i', '_cleaned.csv', $file_path );
		}

		$written = $this->write_csv( $data, $output_path );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		$this->log_step( 'Готово: ' . basename( $output_path ) );

		return array(
			'success'    => true,
			'clean_file' => $output_path,
			'log'        => $this->log,
			'rows'       => count( $data ) - 1,
		);
	}

	/**
	 * @return array{rows:list<list<string|int|float|null>>, delimiter:string, cols:int}|\WP_Error
	 */
	private function load_csv( string $file_path ) {
		$raw = file_get_contents( $file_path );
		if ( $raw === false ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Не удалось прочитать файл.', 'flavor-worldstat' ) );
		}

		if ( strncmp( $raw, "\xef\xbb\xbf", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		}

		$first_line = strtok( $raw, "\r\n" );
		if ( $first_line === false ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Файл пуст.', 'flavor-worldstat' ) );
		}

		$delimiter = $this->detect_delimiter( (string) $first_line );
		$lines     = preg_split( "/\r\n|\n|\r/", $raw );
		if ( ! is_array( $lines ) ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Ошибка разбора строк.', 'flavor-worldstat' ) );
		}

		$rows = array();
		$n    = 0;
		foreach ( $lines as $line ) {
			if ( $line === '' || $line === null ) {
				continue;
			}
			$row = str_getcsv( (string) $line, $delimiter, '"' );
			if ( $row === null ) {
				$row = array();
			}
			$rows[] = $row;
			++$n;
			if ( $n > self::MAX_ROWS ) {
				return new \WP_Error( 'wsp_csv_clean', __( 'Слишком много строк (лимит обработки).', 'flavor-worldstat' ) );
			}
		}

		if ( count( $rows ) < 2 ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Нужны заголовок и хотя бы одна строка данных.', 'flavor-worldstat' ) );
		}

		$cols = max( array_map( 'count', $rows ) );
		foreach ( $rows as $i => $r ) {
			$diff = $cols - count( $r );
			if ( $diff > 0 ) {
				$rows[ $i ] = array_merge( $r, array_fill( 0, $diff, '' ) );
			}
		}

		return array(
			'rows'      => $rows,
			'delimiter' => $delimiter,
			'cols'      => $cols,
		);
	}

	private function detect_delimiter( string $line ): string {
		$comma = substr_count( $line, ',' );
		$semi  = substr_count( $line, ';' );
		$tab   = substr_count( $line, "\t" );
		if ( $semi >= $comma && $semi >= $tab && $semi > 0 ) {
			return ';';
		}
		if ( $tab >= $comma && $tab >= $semi && $tab > 0 ) {
			return "\t";
		}
		return ',';
	}

	private function log_step( string $message ): void {
		$this->log[] = $message;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function remove_duplicates( array $data ): array {
		$header = array_shift( $data );
		$before = count( $data );
		$seen   = array();
		$unique = array();
		foreach ( $data as $row ) {
			$key = md5( serialize( $row ) );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$unique[]     = $row;
			}
		}
		array_unshift( $unique, $header );
		$this->log_step( sprintf( 'Дубликаты: удалено строк: %d', max( 0, $before - count( $unique ) ) ) );
		return $unique;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function handle_missing_values( array $data ): array {
		$header = array_shift( $data );
		$na     = array( '', 'nan', 'na', 'n/a', '#n/a', 'null', '-', '--', '.', 'none', 'unknown' );
		foreach ( $data as &$row ) {
			foreach ( $row as &$value ) {
				if ( $value === null ) {
					continue;
				}
				$s = is_string( $value ) ? trim( $value ) : (string) $value;
				if ( $s === '' ) {
					$value = null;
					continue;
				}
				$low = strtolower( $s );
				if ( in_array( $low, $na, true ) ) {
					$value = null;
				}
			}
		}
		unset( $row, $value );
		array_unshift( $data, $header );
		$this->log_step( 'Пропуски: пустые и маркеры NA/N/A/- заменены на пустое значение' );
		return $data;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function convert_data_types( array $data ): array {
		$header = array_shift( $data );
		$width  = count( $header );

		foreach ( $data as &$row ) {
			for ( $i = 0; $i < $width; $i++ ) {
				if ( ! isset( $row[ $i ] ) ) {
					$row[ $i ] = null;
					continue;
				}
				$v = $row[ $i ];
				if ( $v === null ) {
					continue;
				}
				if ( is_numeric( $v ) ) {
					$row[ $i ] = strpos( (string) $v, '.' ) !== false || stripos( (string) $v, 'e' ) !== false
						? (float) $v
						: (int) $v;
					continue;
				}
				if ( ! is_string( $v ) ) {
					continue;
				}
				$t = trim( $v );
				if ( $t === '' ) {
					$row[ $i ] = null;
					continue;
				}
				$low = strtolower( $t );
				if ( in_array( $low, array( 'true', 'yes', '1', 'да' ), true ) ) {
					$row[ $i ] = 1;
					continue;
				}
				if ( in_array( $low, array( 'false', 'no', '0', 'нет' ), true ) ) {
					$row[ $i ] = 0;
					continue;
				}
				$ts = strtotime( $t );
				if ( $ts !== false && $ts > 0 && preg_match( '/\d{4}/', $t ) ) {
					$row[ $i ] = gmdate( 'Y-m-d', $ts );
				}
			}
		}
		unset( $row );
		array_unshift( $data, $header );
		$this->log_step( 'Типы: числа, булевы, распознанные даты → ISO Y-m-d' );
		return $data;
	}

	/**
	 * IQR winsorize по числовым колонкам.
	 *
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function handle_outliers( array $data ): array {
		if ( empty( $this->options['apply_iqr_outliers'] ) ) {
			$this->log_step(
				'Выбросы: без изменений (глобальный IQR отключён: для данных «страна–год–показатель» он подрезает крупные страны к одному порогу)'
			);
			return $data;
		}

		$header = array_shift( $data );
		$cols   = count( $header );
		$capped = 0;

		for ( $c = 0; $c < $cols; $c++ ) {
			$vals = array();
			foreach ( $data as $row ) {
				$v = $row[ $c ] ?? null;
				if ( is_int( $v ) || is_float( $v ) ) {
					$vals[] = (float) $v;
				}
			}
			$n = count( $vals );
			if ( $n < 8 ) {
				continue;
			}
			sort( $vals, SORT_NUMERIC );
			$q1   = $this->percentile( $vals, 0.25 );
			$q3   = $this->percentile( $vals, 0.75 );
			$iqr  = $q3 - $q1;
			$low  = $q1 - 1.5 * $iqr;
			$high = $q3 + 1.5 * $iqr;

			foreach ( $data as &$row ) {
				$v = $row[ $c ] ?? null;
				if ( ! is_int( $v ) && ! is_float( $v ) ) {
					continue;
				}
				$fv = (float) $v;
				if ( $fv < $low ) {
					$row[ $c ] = $low;
					++$capped;
				} elseif ( $fv > $high ) {
					$row[ $c ] = $high;
					++$capped;
				}
			}
			unset( $row );
		}

		array_unshift( $data, $header );
		$this->log_step( 'Выбросы: IQR-ограничение (winsorize), скорректировано ячеек: ' . $capped );
		return $data;
	}

	/**
	 * @param list<float> $sorted
	 */
	private function percentile( array $sorted, float $p ): float {
		$n = count( $sorted );
		if ( $n === 0 ) {
			return 0.0;
		}
		$idx = ( $n - 1 ) * $p;
		$lo  = (int) floor( $idx );
		$hi  = (int) ceil( $idx );
		if ( $lo === $hi ) {
			return (float) $sorted[ $lo ];
		}
		return (float) ( $sorted[ $lo ] * ( $hi - $idx ) + $sorted[ $hi ] * ( $idx - $lo ) );
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function clean_strings( array $data ): array {
		$header = array_shift( $data );
		$cols   = count( $header );

		foreach ( $data as &$row ) {
			for ( $i = 0; $i < $cols; $i++ ) {
				$v = $row[ $i ] ?? null;
				if ( ! is_string( $v ) ) {
					continue;
				}
				$t = preg_replace( '/\x{00a0}/u', ' ', $v );
				$t = trim( (string) $t );
				if ( $this->should_preserve_string_case( $t, $i ) ) {
					$row[ $i ] = $t;
				} else {
					$row[ $i ] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
				}
			}
		}
		unset( $row );
		array_unshift( $data, $header );
		$this->log_step( 'Строки: trim, нормализация регистра (коды ISO/числа не трогаем)' );
		return $data;
	}

	private function should_preserve_string_case( string $t, int $col_index ): bool {
		if ( preg_match( '/^[A-Z]{3}$/', $t ) ) {
			return true;
		}
		if ( $col_index === 0 && preg_match( '/^[A-Za-z]{3}$/', $t ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function feature_engineering( array $data ): array {
		$header = array_shift( $data );
		$added  = false;

		foreach ( $header as $ci => $name ) {
			$sample = null;
			foreach ( $data as $row ) {
				if ( isset( $row[ $ci ] ) && is_string( $row[ $ci ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row[ $ci ] ) ) {
					$sample = $row[ $ci ];
					break;
				}
			}
			if ( $sample !== null ) {
				$new_name = 'year_from_' . sanitize_title( (string) $name );
				if ( $new_name === 'year_from_' ) {
					$new_name = 'year_col_' . $ci;
				}
				$header[] = $new_name;
				foreach ( $data as &$row ) {
					$d = isset( $row[ $ci ] ) && is_string( $row[ $ci ] ) ? $row[ $ci ] : '';
					if ( preg_match( '/^(\d{4})-\d{2}-\d{2}$/', $d, $m ) ) {
						$row[] = (int) $m[1];
					} else {
						$row[] = null;
					}
				}
				unset( $row );
				$added = true;
				break;
			}
		}

		array_unshift( $data, $header );
		$this->log_step( $added ? 'Признаки: добавлен год из колонки с датой' : 'Признаки: без изменений (нет колонок Y-m-d)' );
		return $data;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function encode_and_normalize( array $data ): array {
		$purpose = isset( $this->options['purpose'] ) ? (string) $this->options['purpose'] : 'platform';

		if ( $purpose !== 'analytics' ) {
			$this->log_step( 'Кодирование/нормализация: режим platform — масштаб чисел не меняем (подходит для карт/стран)' );
			return $data;
		}

		$header = array_shift( $data );
		$cols   = count( $header );

		$apply_label = ! empty( $this->options['apply_label_encode'] );
		$apply_mm    = ! empty( $this->options['apply_minmax'] );

		for ( $c = 0; $c < $cols; $c++ ) {
			$is_numeric_col = true;
			foreach ( $data as $row ) {
				$v = $row[ $c ] ?? null;
				if ( $v !== null && ! is_int( $v ) && ! is_float( $v ) ) {
					$is_numeric_col = false;
					break;
				}
			}

			if ( $apply_mm && $is_numeric_col ) {
				$min = null;
				$max = null;
				foreach ( $data as $row ) {
					$v = $row[ $c ] ?? null;
					if ( $v === null ) {
						continue;
					}
					$fv = (float) $v;
					if ( $min === null || $fv < $min ) {
						$min = $fv;
					}
					if ( $max === null || $fv > $max ) {
						$max = $fv;
					}
				}
				if ( $min !== null && $max !== null && $max > $min ) {
					foreach ( $data as &$row ) {
						if ( isset( $row[ $c ] ) && ( is_int( $row[ $c ] ) || is_float( $row[ $c ] ) ) ) {
							$row[ $c ] = ( (float) $row[ $c ] - $min ) / ( $max - $min );
						}
					}
					unset( $row );
				}
			}

			if ( $apply_label && ! $is_numeric_col && $c > 0 ) {
				$map   = array();
				$next  = 1;
				foreach ( $data as &$row ) {
					$v = $row[ $c ] ?? null;
					if ( $v === null || $v === '' ) {
						$row[ $c ] = null;
						continue;
					}
					$key = (string) $v;
					if ( ! isset( $map[ $key ] ) ) {
						$map[ $key ] = $next++;
					}
					$row[ $c ] = $map[ $key ];
				}
				unset( $row );
			}
		}

		array_unshift( $data, $header );
		$this->log_step( 'Analytics: применены опции MinMax / label encoding' );
		return $data;
	}

	/**
	 * Удаляет строки данных, где в третьей колонке нет конечного числа (типичный формат: код, год, значение).
	 * Иначе в БД и файле остаются «ABW,1960,» с пустым показателем.
	 *
	 * @param list<list<string|int|float|null>> $data
	 * @return list<list<string|int|float|null>>
	 */
	private function remove_rows_without_numeric_value( array $data ): array {
		if ( count( $data ) < 2 ) {
			return $data;
		}

		$header = array_shift( $data );
		$width  = count( $header );
		if ( $width < 3 ) {
			array_unshift( $data, $header );
			$this->log_step( 'Целостность: меньше трёх колонок — отбор по значению не применяется' );
			return $data;
		}

		$before = count( $data );
		$kept   = array();

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 3 ) {
				continue;
			}
			$v = $row[2] ?? null;
			if ( $v === null || $v === '' ) {
				continue;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				if ( is_float( $v ) && ! is_finite( $v ) ) {
					continue;
				}
				$kept[] = $row;
				continue;
			}
			if ( is_string( $v ) ) {
				$t = trim( $v );
				if ( $t === '' || ! is_numeric( $t ) ) {
					continue;
				}
				$fv = (float) $t + 0.0;
				if ( ! is_finite( $fv ) ) {
					continue;
				}
				$row[2] = strpos( $t, '.' ) !== false || stripos( $t, 'e' ) !== false ? $fv : (int) $t;
				$kept[] = $row;
			}
		}

		array_unshift( $kept, $header );
		$data_rows_left = count( $kept ) - 1;
		$removed        = max( 0, $before - $data_rows_left );
		$this->log_step(
			sprintf(
				'Целостность: удалено строк без числового значения в 3-й колонке: %d (осталось строк данных: %d)',
				$removed,
				$data_rows_left
			)
		);

		return $kept;
	}

	/**
	 * @param list<list<string|int|float|null>> $data
	 */
	private function write_csv( array $data, string $path ): true|\WP_Error {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Не удалось создать каталог для результата.', 'flavor-worldstat' ) );
		}

		$fp = fopen( $path, 'wb' );
		if ( ! $fp ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Не удалось записать файл.', 'flavor-worldstat' ) );
		}
		fwrite( $fp, "\xef\xbb\xbf" );
		foreach ( $data as $row ) {
			$line = array();
			foreach ( $row as $cell ) {
				if ( $cell === null ) {
					$line[] = '';
				} elseif ( is_float( $cell ) ) {
					$line[] = $cell;
				} elseif ( is_int( $cell ) ) {
					$line[] = $cell;
				} else {
					$line[] = (string) $cell;
				}
			}
			fputcsv( $fp, $line, ',', '"' );
		}
		fclose( $fp );
		@chmod( $path, 0644 );
		return true;
	}
}
