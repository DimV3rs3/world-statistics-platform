<?php
/**
 * Импорт CSV в post meta стран wsp_country: ряд [год => значение] под ключом wsp_metric_{slug}.
 *
 * Поддерживаются:
 * — «длинный» формат (как road_length_km.csv): rank, country, {metric}, year;
 * — «широкий»: первая колонка — код страны (ISO2/ISO3), остальные заголовки — годы (4 цифры).
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Csv_Country_Meta_Importer {

	public const META_PREFIX = 'wsp_metric_';

	public const OPTION_METRIC_SLUGS = 'wsp_csv_imported_metric_slugs';

	public const OPTION_IMPORT_REVISION = 'wsp_country_metric_import_revision';

	/** @var array<string,int>|null */
	private static ?array $name_to_post = null;

	/**
	 * @return array{posts_touched:int,rows_written:int,rows_skipped:int,unknown_country:int,metrics:string[]}
	 */
	public static function import_from_csv_string( string $csv_body, string $metric_hint = '' ): array {
		$out = array(
			'posts_touched'    => 0,
			'rows_written'     => 0,
			'rows_skipped'     => 0,
			'unknown_country'  => 0,
			'metrics'          => array(),
		);

		if ( $csv_body === '' ) {
			return $out;
		}

		if ( strncmp( $csv_body, "\xef\xbb\xbf", 3 ) === 0 ) {
			$csv_body = substr( $csv_body, 3 );
		}

		$lines = preg_split( "/\r\n|\n|\r/", $csv_body );
		if ( ! is_array( $lines ) || count( $lines ) < 2 ) {
			return $out;
		}

		$header = str_getcsv( (string) $lines[0] );
		if ( count( $header ) < 2 ) {
			return $out;
		}

		$hint_slug = self::slug_from_hint( $metric_hint );

		$long = self::try_parse_long_format( $header, $lines, $hint_slug, $out );
		if ( $long !== null ) {
			return $long;
		}

		$wide = self::try_parse_wide_format( $header, $lines, $hint_slug );
		if ( $wide !== null ) {
			return $wide;
		}

		return $out;
	}

	private static function slug_from_hint( string $hint ): string {
		$hint = sanitize_file_name( $hint );
		$hint = pathinfo( $hint, PATHINFO_FILENAME );
		return sanitize_key( $hint );
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $lines
	 * @param array<string,int|string> $out
	 * @return array<string,mixed>|null
	 */
	private static function try_parse_long_format( array $header, array $lines, string $hint_slug, array $out ): ?array {
		$h = self::normalize_headers( $header );
		$year_idx = self::find_header_index( $h, array( 'year' ) );
		if ( $year_idx === null ) {
			return null;
		}

		$country_idx = self::find_header_index(
			$h,
			array( 'country_code', 'iso2', 'cca2', 'code', 'iso_alpha2', 'country' )
		);
		if ( $country_idx === null ) {
			return null;
		}

		$skip = array(
			'rank', 'year', 'country', 'country_code', 'iso2', 'iso3', 'cca2', 'cca3',
			'code', 'iso_alpha2', 'iso_alpha3', 'name', 'id', 'index', '#',
		);

		$metric_cols = array();
		foreach ( $header as $i => $raw_name ) {
			if ( $i === $year_idx || $i === $country_idx ) {
				continue;
			}
			$sl = isset( $h[ $i ] ) ? $h[ $i ] : sanitize_key( $raw_name );
			if ( $sl === '' || in_array( $sl, $skip, true ) ) {
				continue;
			}
			$metric_cols[ $i ] = $sl;
		}

		if ( empty( $metric_cols ) && $hint_slug !== '' ) {
			// Один числовой столбец без имени — берём имя из файла.
			foreach ( $header as $i => $raw_name ) {
				if ( $i === $year_idx || $i === $country_idx ) {
					continue;
				}
				$sl = isset( $h[ $i ] ) ? $h[ $i ] : sanitize_key( $raw_name );
				if ( $sl === '' || in_array( $sl, $skip, true ) ) {
					continue;
				}
				$metric_cols[ $i ] = $hint_slug;
				break;
			}
		}

		if ( empty( $metric_cols ) ) {
			return null;
		}

		$touched = array();
		$pending = array();

		for ( $li = 1, $n = count( $lines ); $li < $n; $li++ ) {
			$line = trim( (string) $lines[ $li ] );
			if ( $line === '' ) {
				continue;
			}
			$row = str_getcsv( $line );
			$need = 1;
			foreach ( array_keys( $metric_cols ) as $idx ) {
				$need = max( $need, (int) $idx + 1 );
			}
			$need = max( $need, $year_idx + 1, $country_idx + 1 );
			if ( count( $row ) < $need ) {
				++$out['rows_skipped'];
				continue;
			}

			$country_cell = isset( $row[ $country_idx ] ) ? trim( (string) $row[ $country_idx ] ) : '';
			$post_id      = self::resolve_post_id( $country_cell );
			if ( ! $post_id ) {
				++$out['unknown_country'];
				++$out['rows_skipped'];
				continue;
			}

			$year_raw = $row[ $year_idx ] ?? '';
			$year     = self::parse_year( $year_raw );
			if ( $year <= 0 ) {
				++$out['rows_skipped'];
				continue;
			}

			foreach ( $metric_cols as $col_idx => $metric_slug ) {
				$slug = $metric_slug !== '' ? $metric_slug : $hint_slug;
				if ( $slug === '' ) {
					continue;
				}
				$cell = $row[ $col_idx ] ?? null;
				$val  = self::parse_numeric_value( $cell );
				if ( $val === null ) {
					continue;
				}

				$meta_key = self::META_PREFIX . sanitize_key( $slug );
				self::queue_year_value( $pending, $post_id, $meta_key, $year, $val );
				$touched[ $post_id ] = true;
				++$out['rows_written'];
				$out['metrics'][] = sanitize_key( $slug );
			}
		}

		self::flush_queued_year_values( $pending );

		$out['posts_touched'] = count( $touched );
		$out['metrics']       = array_values( array_unique( array_filter( $out['metrics'] ) ) );
		foreach ( $out['metrics'] as $slug ) {
			self::register_metric_slug( $slug );
		}

		if ( $out['rows_written'] > 0 ) {
			self::bump_import_revision();
		}

		return $out;
	}

	/**
	 * @param list<string> $header
	 * @param list<string> $lines
	 * @param array<string,int|string> $out
	 * @return array<string,mixed>|null
	 */
	private static function try_parse_wide_format( array $header, array $lines, string $hint_slug ): ?array {
		if ( count( $header ) < 3 ) {
			return null;
		}

		$out = array(
			'posts_touched'   => 0,
			'rows_written'    => 0,
			'rows_skipped'    => 0,
			'unknown_country' => 0,
			'metrics'         => array(),
		);

		$year_cols = array();
		for ( $i = 1, $c = count( $header ); $i < $c; $i++ ) {
			$y = self::parse_year( $header[ $i ] );
			if ( $y > 0 ) {
				$year_cols[ $i ] = $y;
			}
		}

		if ( count( $year_cols ) < 1 ) {
			return null;
		}

		$metric_slug = $hint_slug !== '' ? $hint_slug : 'value';
		$meta_key    = self::META_PREFIX . sanitize_key( $metric_slug );
		$touched     = array();
		$pending     = array();

		for ( $li = 1, $n = count( $lines ); $li < $n; $li++ ) {
			$line = trim( (string) $lines[ $li ] );
			if ( $line === '' ) {
				continue;
			}
			$row = str_getcsv( $line );
			if ( count( $row ) < 2 ) {
				++$out['rows_skipped'];
				continue;
			}

			$country_cell = trim( (string) ( $row[0] ?? '' ) );
			$post_id      = self::resolve_post_id( $country_cell );
			if ( ! $post_id ) {
				++$out['unknown_country'];
				++$out['rows_skipped'];
				continue;
			}

			foreach ( $year_cols as $col_idx => $year ) {
				$val = self::parse_numeric_value( $row[ $col_idx ] ?? null );
				if ( $val === null ) {
					continue;
				}
				self::queue_year_value( $pending, $post_id, $meta_key, $year, $val );
				$touched[ $post_id ] = true;
				++$out['rows_written'];
			}
		}

		if ( $out['rows_written'] === 0 ) {
			return null;
		}

		$out['posts_touched'] = count( $touched );
		$out['metrics']       = array( sanitize_key( $metric_slug ) );
		self::flush_queued_year_values( $pending );
		self::register_metric_slug( sanitize_key( $metric_slug ) );
		self::bump_import_revision();

		return $out;
	}

	/**
	 * @param list<string> $header
	 * @return list<string>
	 */
	private static function normalize_headers( array $header ): array {
		$h = array();
		foreach ( $header as $i => $name ) {
			$h[ $i ] = sanitize_key( strtolower( trim( (string) $name ) ) );
		}
		return $h;
	}

	/**
	 * @param list<string> $h normalized lowercase keys per index
	 * @param list<string> $candidates
	 */
	private static function find_header_index( array $h, array $candidates ): ?int {
		foreach ( $h as $i => $slug ) {
			if ( in_array( $slug, $candidates, true ) ) {
				return (int) $i;
			}
		}
		return null;
	}

	private static function parse_year( mixed $raw ): int {
		if ( $raw === null || $raw === '' ) {
			return 0;
		}
		if ( is_numeric( $raw ) ) {
			return (int) round( (float) $raw );
		}
		$s = trim( (string) $raw );
		if ( preg_match( '/^(\d{4})/', $s, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	private static function parse_numeric_value( mixed $cell ): ?float {
		if ( $cell === null ) {
			return null;
		}
		if ( is_int( $cell ) || is_float( $cell ) ) {
			$f = (float) $cell;
			return is_finite( $f ) ? $f : null;
		}
		$s = trim( (string) $cell );
		if ( $s === '' || ! is_numeric( $s ) ) {
			return null;
		}
		$f = (float) $s;
		return is_finite( $f ) ? $f : null;
	}

	private static function normalize_country_label( string $name ): string {
		$name = trim( $name );
		if ( function_exists( 'mb_strtolower' ) ) {
			$name = mb_strtolower( $name, 'UTF-8' );
		} else {
			$name = strtolower( $name );
		}
		return preg_replace( '/\s+/u', ' ', $name );
	}

	/**
	 * @return array<string,int>
	 */
	private static function load_name_map(): array {
		if ( self::$name_to_post !== null ) {
			return self::$name_to_post;
		}

		global $wpdb;
		$ptype = WorldStat_Country_CPT::SLUG;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS name_short
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND pm.meta_key = 'wsp_name_short' AND pm.meta_value <> ''",
				$ptype
			)
		);

		self::$name_to_post = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$k = self::normalize_country_label( (string) $r->name_short );
				if ( $k !== '' ) {
					self::$name_to_post[ $k ] = (int) $r->post_id;
				}
			}
		}

		return self::$name_to_post;
	}

	private static function find_post_by_iso3( string $iso3 ): int {
		$iso3 = strtoupper( trim( $iso3 ) );
		if ( strlen( $iso3 ) !== 3 ) {
			return 0;
		}

		global $wpdb;
		$pid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'wsp_iso_alpha3' AND pm.meta_value = %s
				   AND p.post_type = %s AND p.post_status = 'publish' LIMIT 1",
				$iso3,
				WorldStat_Country_CPT::SLUG
			)
		);
		return $pid;
	}

	private static function resolve_post_id( string $cell ): int {
		$cell = trim( $cell );
		if ( $cell === '' ) {
			return 0;
		}

		if ( preg_match( '/^[A-Za-z]{2}$/', $cell ) ) {
			$post = WorldStat_Country_CPT::get_by_code( $cell );
			return $post ? (int) $post->ID : 0;
		}

		if ( preg_match( '/^[A-Za-z]{3}$/', $cell ) ) {
			return self::find_post_by_iso3( $cell );
		}

		$map = self::load_name_map();
		$key = self::normalize_country_label( trim( $cell, " \t\n\r\0\x0B\"" ) );
		$key = self::apply_country_name_aliases( $key );

		return $map[ $key ] ?? 0;
	}

	/**
	 * Соответствие частых подписей в датасетах и wsp_name_short в каталоге.
	 *
	 * @param string $normalized_key Ключ после normalize_country_label().
	 */
	private static function apply_country_name_aliases( string $normalized_key ): string {
		$aliases = array(
			'korea, south'           => 'south korea',
			'korea, north'           => 'north korea',
			'russian federation'     => 'russia',
			'united states of america' => 'united states',
			'viet nam'               => 'vietnam',
		);
		return $aliases[ $normalized_key ] ?? $normalized_key;
	}

	private static function merge_year_value( int $post_id, string $meta_key, int $year, float $value ): void {
		$existing = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$clean = array();
		foreach ( $existing as $y => $v ) {
			$yi = (int) $y;
			if ( $yi > 0 && is_numeric( $v ) ) {
				$clean[ $yi ] = (float) $v;
			}
		}

		$clean[ $year ] = $value;
		ksort( $clean, SORT_NUMERIC );

		update_post_meta( $post_id, $meta_key, $clean );
	}

	/**
	 * Накопление импортируемых значений (чтобы писать в БД пакетно, а не по каждой ячейке).
	 *
	 * @param array<int,array<string,array<int,float>>> $pending
	 */
	private static function queue_year_value( array &$pending, int $post_id, string $meta_key, int $year, float $value ): void {
		if ( $post_id < 1 || $meta_key === '' || $year <= 0 ) {
			return;
		}
		if ( ! isset( $pending[ $post_id ] ) ) {
			$pending[ $post_id ] = array();
		}
		if ( ! isset( $pending[ $post_id ][ $meta_key ] ) ) {
			$pending[ $post_id ][ $meta_key ] = array();
		}
		$pending[ $post_id ][ $meta_key ][ $year ] = $value;
	}

	/**
	 * Сброс накопленных значений в post meta.
	 *
	 * @param array<int,array<string,array<int,float>>> $pending
	 */
	private static function flush_queued_year_values( array $pending ): void {
		foreach ( $pending as $post_id => $meta_map ) {
			$pid = (int) $post_id;
			if ( $pid < 1 || ! is_array( $meta_map ) ) {
				continue;
			}
			foreach ( $meta_map as $meta_key => $series ) {
				if ( ! is_array( $series ) || empty( $series ) ) {
					continue;
				}
				$existing = get_post_meta( $pid, (string) $meta_key, true );
				if ( ! is_array( $existing ) ) {
					$existing = array();
				}
				$clean = array();
				foreach ( $existing as $y => $v ) {
					$yi = (int) $y;
					if ( $yi > 0 && is_numeric( $v ) ) {
						$clean[ $yi ] = (float) $v;
					}
				}
				foreach ( $series as $year => $value ) {
					$yi = (int) $year;
					if ( $yi > 0 ) {
						$clean[ $yi ] = (float) $value;
					}
				}
				ksort( $clean, SORT_NUMERIC );
				update_post_meta( $pid, (string) $meta_key, $clean );
			}
		}
	}

	private static function register_metric_slug( string $slug ): void {
		if ( $slug === '' ) {
			return;
		}
		$list = get_option( self::OPTION_METRIC_SLUGS, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		if ( ! in_array( $slug, $list, true ) ) {
			$list[] = $slug;
			update_option( self::OPTION_METRIC_SLUGS, $list, false );
		}
	}

	private static function bump_import_revision(): void {
		update_option(
			self::OPTION_IMPORT_REVISION,
			(int) get_option( self::OPTION_IMPORT_REVISION, 0 ) + 1,
			false
		);
	}

	/**
	 * Удалить у всех постов стран мета wsp_metric_{slug} (при удалении датасета из админки).
	 *
	 * @param list<string> $slugs Слаги без префикса (как в JSON import_metric_slugs).
	 */
	public static function purge_imported_metrics_from_all_countries( array $slugs ): void {
		$slugs = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $slugs )
				)
			)
		);
		if ( empty( $slugs ) ) {
			return;
		}

		global $wpdb;
		$ptype = WorldStat_Country_CPT::SLUG;

		foreach ( $slugs as $slug ) {
			if ( $slug === '' ) {
				continue;
			}
			$meta_key = self::META_PREFIX . $slug;
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers from constants/sanitize_key.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE pm FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s AND pm.meta_key = %s",
					$ptype,
					$meta_key
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		WorldStat_Country_CPT::flush_code_cache();
		self::bump_import_revision();
	}

	public static function human_label_for_slug( string $slug ): string {
		return WorldStat_Data::resolve_metric_label( 'csv-country-meta', $slug, '' );
	}

	/**
	 * Регистрация расширения и провайдеров метрик (рейтинги, карта, обзор).
	 * Вкладка на странице страны отключена — данные CSV на вкладке «Обзор».
	 */
	public static function register_extension(): void {
		if ( ! class_exists( 'WorldStat_Extensions' ) ) {
			return;
		}

		WorldStat_Extensions::register(
			array(
				'id'          => 'csv-country-meta',
				'name'        => __( 'Показатели из CSV', 'flavor-worldstat' ),
				'version'     => '1.0.0',
				'description' => __( 'Временные ряды, импортированные из загруженных CSV, в мета полях стран.', 'flavor-worldstat' ),
			)
		);

		self::register_csv_metric_providers();
	}

	/**
	 * Контингентные ключи вида slug__suffix из «широких» CSV сохраняем как есть (sanitize при импорте).
	 * Провайдеры нужны каталогу, рейтингам и карте.
	 */
	private static function register_csv_metric_providers(): void {
		if ( ! function_exists( 'worldstat_register_provider' ) ) {
			return;
		}
		foreach ( self::merged_metric_slugs() as $slug ) {
			if ( $slug === '' ) {
				continue;
			}
			worldstat_register_provider(
				'csv-country-meta',
				(string) $slug,
				self::make_csv_meta_provider( (string) $slug ),
				array(
					'label'       => self::human_label_for_slug( (string) $slug ),
					'type'        => 'number',
					'unit'        => '',
					'description' => __( 'Ряд из импортированного CSV', 'flavor-worldstat' ),
					'level'       => 'country',
				)
			);
		}
	}

	/** @return list<string> */
	private static function merged_metric_slugs(): array {
		$rev       = (int) get_option( self::OPTION_IMPORT_REVISION, 0 );
		$cache_key = 'wsp_csv_slug_merge_' . (string) $rev;
		$cached    = wp_cache_get( $cache_key, 'worldstat' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$opt = get_option( self::OPTION_METRIC_SLUGS, array() );
		$opt = is_array( $opt )
			? array_values( array_unique( array_map( 'sanitize_key', $opt ) ) )
			: array();
		$db   = self::discover_metric_slugs_from_db();
		$merged = array_values( array_unique( array_merge( $opt, $db ) ) );
		wp_cache_set( $cache_key, $merged, 'worldstat', HOUR_IN_SECONDS );
		return $merged;
	}

	/** @return list<string> */
	private static function discover_metric_slugs_from_db(): array {
		global $wpdb;
		$ptype = WorldStat_Country_CPT::SLUG;
		$like  = $wpdb->esc_like( self::META_PREFIX ) . '%';
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key LIKE %s",
				$ptype,
				$like
			)
		);
		if ( empty( $keys ) ) {
			return array();
		}
		$plen = strlen( self::META_PREFIX );
		$out  = array();
		foreach ( (array) $keys as $mk ) {
			$slug = sanitize_key( substr( (string) $mk, $plen ) );
			if ( $slug !== '' ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return callable callable( string $iso2 ): float|null
	 */
	private static function make_csv_meta_provider( string $slug ): callable {
		return static function ( string $iso2 ) use ( $slug ) {
			$y = null;
			if ( class_exists( 'WorldStat_Data' ) ) {
				$y = WorldStat_Data::get_value_year_context();
			}
			return self::get_metric_value_for_iso2( $iso2, $slug, $y );
		};
	}

	/**
	 * Значение ряда wsp_metric_{slug} для ISO2 (последний год или указанный).
	 */
	public static function get_metric_value_for_iso2( string $iso2, string $slug, ?int $year = null ): ?float {
		$iso2 = strtoupper( trim( $iso2 ) );
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return null;
		}
		$post_id = WorldStat_Country_CPT::get_post_id_by_code( $iso2 );
		if ( $post_id < 1 ) {
			return null;
		}
		$data = get_post_meta( $post_id, self::META_PREFIX . $slug, true );
		return self::value_from_year_series_meta( $data, $year );
	}

	/**
	 * Одним запросом — значения метрики для списка ISO2 (рейтинги, тяжёлые страницы без N× get_post).
	 *
	 * @param list<string> $iso2_list
	 * @return array<string,float> ключ — ISO2 в верхнем регистре
	 */
	public static function get_metric_values_for_iso_list( array $iso2_list, string $slug, ?int $year = null ): array {
		$slug = sanitize_key( $slug );
		if ( $slug === '' || empty( $iso2_list ) ) {
			return array();
		}
		$code_map = WorldStat_Country_CPT::get_code_map();
		$post_ids = array();
		$iso_by_pid = array();
		foreach ( $iso2_list as $iso ) {
			$iu = strtoupper( trim( (string) $iso ) );
			if ( $iu === '' || ! isset( $code_map[ $iu ] ) ) {
				continue;
			}
			$pid = (int) $code_map[ $iu ];
			if ( $pid < 1 ) {
				continue;
			}
			$post_ids[ $pid ] = true;
			$iso_by_pid[ $pid ] = $iu;
		}
		$pids = array_keys( $post_ids );
		if ( empty( $pids ) ) {
			return array();
		}

		global $wpdb;
		$meta_key     = self::META_PREFIX . $slug;
		$placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
		$prepare_args = array_merge(
			array( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)", $meta_key ),
			array_map( 'intval', $pids )
		);
		$sql  = $wpdb->prepare( ...$prepare_args );
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$pid  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$iso2 = $iso_by_pid[ $pid ] ?? '';
			if ( $iso2 === '' ) {
				continue;
			}
			$data = maybe_unserialize( $row['meta_value'] ?? null );
			$fv   = self::value_from_year_series_meta( $data, $year );
			if ( $fv !== null ) {
				$out[ $iso2 ] = $fv;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $data Значение post meta ряда [ год => число ]
	 */
	private static function value_from_year_series_meta( $data, ?int $year ): ?float {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return null;
		}

		if ( $year !== null && $year > 0 ) {
			$raw = $data[ $year ] ?? $data[ (string) $year ] ?? null;
			if ( $raw !== null && is_numeric( $raw ) ) {
				$fv = (float) $raw;
				return is_finite( $fv ) ? $fv : null;
			}
			return null;
		}

		krsort( $data, SORT_NUMERIC );
		foreach ( $data as $y => $v ) {
			$yi = (int) $y;
			if ( $yi <= 0 || ! is_numeric( $v ) ) {
				continue;
			}
			$fv = (float) $v;
			if ( ! is_finite( $fv ) ) {
				continue;
			}
			return $fv;
		}
		return null;
	}

	/**
	 * Отсортированный по убыванию список годов, где у любой страны есть значение метрики.
	 *
	 * @return list<int>
	 */
	public static function get_years_union_for_slug( string $slug ): array {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return array();
		}
		$rev       = (int) get_option( self::OPTION_IMPORT_REVISION, 0 );
		$cache_key = 'wsp_csv_years_' . $slug . '_' . (string) $rev;
		$cached    = wp_cache_get( $cache_key, 'worldstat' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$years = array();

		global $wpdb;
		$meta_key = self::META_PREFIX . $slug;
		// Один запрос вместо N× get_post_meta по всем странам.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND p.post_status = %s AND pm.meta_key = %s",
				WorldStat_Country_CPT::SLUG,
				'publish',
				$meta_key
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row['meta_value'] ?? null );
			if ( ! is_array( $data ) || empty( $data ) ) {
				continue;
			}
			foreach ( $data as $y => $v ) {
				$yi = (int) $y;
				if ( $yi <= 0 || ! is_numeric( $v ) ) {
					continue;
				}
				$years[ $yi ] = true;
			}
		}

		$list = array_map( 'intval', array_keys( $years ) );
		rsort( $list, SORT_NUMERIC );
		wp_cache_set( $cache_key, $list, 'worldstat', HOUR_IN_SECONDS );
		return $list;
	}
}
