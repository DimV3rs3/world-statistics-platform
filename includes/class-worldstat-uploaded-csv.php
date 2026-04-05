<?php
/**
 * User-uploaded CSV files (wp-content/uploads/worldstat-csv/).
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Uploaded_Csv {

	public const SUBDIR = 'worldstat-csv';

	/**
	 * Absolute path to upload directory (trailing slash) or empty string on failure.
	 */
	public static function get_dir(): string {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}
		return trailingslashit( $upload['basedir'] ) . self::SUBDIR . '/';
	}

	/**
	 * Increment when upload dir changes so country-page CSV cache keys stay fresh.
	 */
	public static function bump_files_revision(): void {
		update_option( 'wsp_csv_files_revision', (int) get_option( 'wsp_csv_files_revision', 0 ) + 1 );
	}

	private static function admin_error_transient_key(): string {
		return 'wsp_csv_admin_err_' . ( get_current_user_id() ?: 0 );
	}

	public static function set_admin_error_flash( string $message ): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		delete_option( 'wsp_csv_last_error' );
		set_transient( self::admin_error_transient_key(), $message, 300 );
	}

	public static function has_admin_error_flash(): bool {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		$v = get_transient( self::admin_error_transient_key() );
		return $v !== false && $v !== '';
	}

	/**
	 * Read and remove one-time admin error (same request as redirect target).
	 */
	public static function take_admin_error_flash(): string {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return '';
		}
		$key = self::admin_error_transient_key();
		$v   = get_transient( $key );
		if ( $v === false || $v === '' ) {
			return '';
		}
		delete_transient( $key );
		return (string) $v;
	}

	private static function process_log_transient_key(): string {
		return 'wsp_csv_process_log_' . ( get_current_user_id() ?: 0 );
	}

	/**
	 * @param list<string> $lines
	 */
	public static function set_process_log_flash( array $lines ): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		set_transient( self::process_log_transient_key(), $lines, 300 );
	}

	/**
	 * @return list<string>
	 */
	public static function take_process_log_flash(): array {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return array();
		}
		$key = self::process_log_transient_key();
		$v   = get_transient( $key );
		delete_transient( $key );
		return is_array( $v ) ? $v : array();
	}

	public static function ensure_dir(): bool {
		$dir = self::get_dir();
		if ( $dir === '' ) {
			return false;
		}
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$incoming = $dir . 'incoming';
		if ( ! is_dir( $incoming ) ) {
			wp_mkdir_p( $incoming );
		}
		$inc_index = $incoming . '/index.php';
		if ( ! file_exists( $inc_index ) ) {
			file_put_contents( $inc_index, "<?php\n// Silence is golden.\n" );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return true;
	}

	/**
	 * @return list<array{name:string,path:string,size:int,mtime:int}>
	 */
	public static function list_files(): array {
		$dir = self::get_dir();
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return [];
		}
		$paths = glob( $dir . '*.csv' ) ?: [];
		$out   = [];
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$out[] = [
				'name'  => basename( $path ),
				'path'  => $path,
				'size'  => (int) filesize( $path ),
				'mtime' => (int) filemtime( $path ),
			];
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['mtime'] <=> $a['mtime'];
			}
		);
		return $out;
	}

	/**
	 * @param array<string,mixed> $file Single entry from $_FILES.
	 * @return string|\WP_Error Saved file basename (e.g. data.csv).
	 */
	public static function save_upload( array $file ) {
		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'wsp_csv_dir', __( 'Не удалось создать каталог для загрузок.', 'flavor-worldstat' ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'wsp_csv_upload', __( 'Файл не был загружен.', 'flavor-worldstat' ) );
		}

		if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'wsp_csv_upload', __( 'Ошибка загрузки файла.', 'flavor-worldstat' ) );
		}

		$max = wp_max_upload_size();
		$sz  = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $max > 0 && $sz > $max ) {
			return new \WP_Error( 'wsp_csv_size', __( 'Файл слишком большой для настроек сервера.', 'flavor-worldstat' ) );
		}

		$orig = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		if ( $ext !== 'csv' ) {
			return new \WP_Error( 'wsp_csv_type', __( 'Разрешены только файлы .csv.', 'flavor-worldstat' ) );
		}

		$base = pathinfo( $orig, PATHINFO_FILENAME );
		$base = sanitize_file_name( $base );
		if ( $base === '' ) {
			$base = 'dataset-' . gmdate( 'Y-m-d-His' );
		}

		$dir  = self::get_dir();
		$name = $base . '.csv';
		$dest = $dir . $name;

		if ( file_exists( $dest ) ) {
			$suffix = '-' . wp_generate_password( 6, false, false );
			$name   = $base . $suffix . '.csv';
			$dest   = $dir . $name;
		}

		$incoming_dir = $dir . 'incoming/';
		if ( ! is_dir( $incoming_dir ) && ! wp_mkdir_p( $incoming_dir ) ) {
			return new \WP_Error( 'wsp_csv_dir', __( 'Не удалось создать каталог для приёма файла.', 'flavor-worldstat' ) );
		}

		$temp = $incoming_dir . 'tmp-' . wp_generate_password( 16, false, false ) . '.csv';
		if ( ! @move_uploaded_file( $file['tmp_name'], $temp ) ) {
			return new \WP_Error( 'wsp_csv_move', __( 'Не удалось сохранить файл.', 'flavor-worldstat' ) );
		}

		@chmod( $temp, 0644 );

		if ( ! class_exists( 'WorldStat_Csv_Cleaner' ) ) {
			@unlink( $temp );
			return new \WP_Error( 'wsp_csv_clean', __( 'Модуль очистки CSV не загружен.', 'flavor-worldstat' ) );
		}

		$cleaner = new WorldStat_Csv_Cleaner();
		$opts    = apply_filters(
			'worldstat_csv_cleaner_options',
			array(
				'purpose' => 'platform',
			),
			$temp,
			$dest
		);
		$result  = $cleaner->process( $temp, $dest, is_array( $opts ) ? $opts : array( 'purpose' => 'platform' ) );

		@unlink( $temp );

		if ( is_wp_error( $result ) ) {
			if ( file_exists( $dest ) ) {
				@unlink( $dest );
			}
			return $result;
		}

		if ( empty( $result['success'] ) ) {
			if ( file_exists( $dest ) ) {
				@unlink( $dest );
			}
			return new \WP_Error(
				'wsp_csv_clean',
				isset( $result['error'] ) ? (string) $result['error'] : __( 'Ошибка обработки CSV.', 'flavor-worldstat' )
			);
		}

		@chmod( $dest, 0644 );

		if ( ! empty( $result['log'] ) && is_array( $result['log'] ) ) {
			self::set_process_log_flash( $result['log'] );
		}

		return $name;
	}

	/**
	 * @param string $basename File name only, e.g. mydata.csv
	 * @return true|\WP_Error
	 */
	public static function delete_file( string $basename ) {
		$dir = self::get_dir();
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return new \WP_Error( 'wsp_csv_dir', __( 'Каталог загрузок недоступен.', 'flavor-worldstat' ) );
		}

		$base = wp_basename( $basename );
		if ( $base === '' || false !== strpos( $base, '..' ) || preg_match( '#[/\\\\]#', $base ) ) {
			return new \WP_Error( 'wsp_csv_name', __( 'Некорректное имя файла.', 'flavor-worldstat' ) );
		}
		if ( ! preg_match( '/\.csv$/i', $base ) ) {
			return new \WP_Error( 'wsp_csv_name', __( 'Некорректное имя файла.', 'flavor-worldstat' ) );
		}

		$full = $dir . $base;
		clearstatcache( true, $full );

		if ( ! is_file( $full ) ) {
			return new \WP_Error( 'wsp_csv_missing', __( 'Файл не найден.', 'flavor-worldstat' ) );
		}

		$dir_root = rtrim( $dir, '/\\' );

		@chmod( $full, 0666 );

		// Prefer core helper; on some Windows setups it returns false while unlink() still works.
		if ( function_exists( 'wp_delete_file_from_directory' ) && wp_delete_file_from_directory( $full, $dir_root ) ) {
			return true;
		}

		$real_dir  = realpath( $dir_root );
		$real_file = realpath( $full );
		if ( $real_dir && $real_file ) {
			$prefix = trailingslashit( wp_normalize_path( $real_dir ) );
			$nf     = wp_normalize_path( $real_file );
			if ( strncmp( $nf, $prefix, strlen( $prefix ) ) !== 0 ) {
				return new \WP_Error( 'wsp_csv_path', __( 'Отказ в доступе к файлу.', 'flavor-worldstat' ) );
			}
			$target = $real_file;
		} else {
			$prefix = trailingslashit( wp_normalize_path( $dir_root ) );
			$nf     = wp_normalize_path( $full );
			if ( strncmp( $nf, $prefix, strlen( $prefix ) ) !== 0 ) {
				return new \WP_Error( 'wsp_csv_path', __( 'Отказ в доступе к файлу.', 'flavor-worldstat' ) );
			}
			$target = $full;
		}

		@chmod( $target, 0666 );
		if ( function_exists( 'wp_delete_file' ) && wp_delete_file( $target ) ) {
			return true;
		}
		if ( @unlink( $target ) ) {
			return true;
		}

		clearstatcache( true, $full );
		if ( $target !== $full && is_file( $full ) ) {
			@chmod( $full, 0666 );
			if ( ( function_exists( 'wp_delete_file' ) && wp_delete_file( $full ) ) || @unlink( $full ) ) {
				return true;
			}
		}

		return new \WP_Error( 'wsp_csv_delete', __( 'Не удалось удалить файл.', 'flavor-worldstat' ) );
	}
}
