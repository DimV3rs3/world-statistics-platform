<?php
/**
 * Загруженные CSV: хранение в таблице БД WordPress после очистки; временные файлы только для пайплайна.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Uploaded_Csv {

	public const SUBDIR = 'worldstat-csv';

	public const TABLE = 'wsp_csv_datasets';

	/** Показатели в духе справочника страны (население, площадь и т.д.) — мягкая очистка, без глобального IQR. */
	public const KIND_COUNTRY = 'country_core';

	/** Индикаторы для аналитики / индексов (дороги, урбанизация, леса и т.п.) — режим analytics с IQR. */
	public const KIND_INDICATOR = 'indicator';

	/**
	 * @return list<string>
	 */
	public static function dataset_kinds(): array {
		return array( self::KIND_COUNTRY, self::KIND_INDICATOR );
	}

	/**
	 * @return array<string, string> slug => label
	 */
	public static function dataset_kind_labels(): array {
		return array(
			self::KIND_COUNTRY  => __( 'Показатели страны', 'flavor-worldstat' ),
			self::KIND_INDICATOR => __( 'Индикаторы для расчётов', 'flavor-worldstat' ),
		);
	}

	public static function sanitize_dataset_kind( string $kind ): string {
		$kind = sanitize_key( $kind );
		return in_array( $kind, self::dataset_kinds(), true ) ? $kind : self::KIND_COUNTRY;
	}

	/**
	 * Имя таблицы с префиксом wp_.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function table_exists(): bool {
		global $wpdb;
		$t = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is escaped.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}

	/**
	 * Создание/обновление таблицы (dbDelta).
	 */
	public static function install_db(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_name varchar(255) NOT NULL,
			dataset_kind varchar(32) NOT NULL DEFAULT 'country_core',
			import_metric_slugs text NULL,
			body longtext NOT NULL,
			created_gmt datetime NOT NULL,
			updated_gmt datetime NOT NULL,
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY file_name (file_name),
			KEY author_id (author_id),
			KEY dataset_kind (dataset_kind)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Готово ли хранилище: таблица + каталог uploads для временных файлов очистки.
	 */
	public static function is_storage_ready(): bool {
		return self::table_exists() && self::get_dir() !== '';
	}

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

	/**
	 * Каталог только для временных файлов (incoming + результат очистки перед INSERT).
	 */
	public static function get_dir(): string {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}
		return trailingslashit( $upload['basedir'] ) . self::SUBDIR . '/';
	}

	public static function ensure_dir(): bool {
		$dir = self::get_dir();
		if ( $dir === '' ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
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
	 * Перенос старых *.csv из uploads/worldstat-csv/ в БД (один раз при активации/апгрейде).
	 */
	public static function migrate_legacy_files_from_disk(): void {
		if ( ! self::table_exists() ) {
			return;
		}
		$dir = self::get_dir();
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return;
		}
		$paths = glob( $dir . '*.csv' ) ?: array();
		if ( empty( $paths ) ) {
			return;
		}
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );
		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$name = basename( $path );
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_name = %s LIMIT 1", $name ) );
			if ( $exists > 0 ) {
				continue;
			}
			$body = file_get_contents( $path );
			if ( $body === false || $body === '' ) {
				continue;
			}
			$ok = $wpdb->insert(
				$table,
				array(
					'file_name'             => $name,
					'dataset_kind'          => self::KIND_COUNTRY,
					'import_metric_slugs'   => null,
					'body'                  => $body,
					'created_gmt'           => $now,
					'updated_gmt'           => $now,
					'author_id'             => 0,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
			if ( $ok ) {
				@unlink( $path );
			}
		}
		if ( count( $paths ) > 0 ) {
			self::bump_files_revision();
		}
	}

	/**
	 * @return list<array{id:int,name:string,dataset_kind:string,size:int,mtime:int}>
	 */
	public static function list_files(): array {
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix + constant.
		$rows = $wpdb->get_results(
			"SELECT id, file_name, dataset_kind, LENGTH(body) AS blen, UNIX_TIMESTAMP(updated_gmt) AS ts FROM {$table} ORDER BY updated_gmt DESC",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$kind = (string) ( $r['dataset_kind'] ?? self::KIND_COUNTRY );
			if ( ! in_array( $kind, self::dataset_kinds(), true ) ) {
				$kind = self::KIND_COUNTRY;
			}
			$out[] = array(
				'id'            => (int) ( $r['id'] ?? 0 ),
				'name'          => (string) ( $r['file_name'] ?? '' ),
				'dataset_kind'  => $kind,
				'size'          => (int) ( $r['blen'] ?? 0 ),
				'mtime'         => (int) ( $r['ts'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function get_body_by_id( int $id ): string {
		if ( $id < 1 || ! self::table_exists() ) {
			return '';
		}
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT body FROM {$table} WHERE id = %d", $id ) );
		return is_string( $row ) ? $row : '';
	}

	/**
	 * @param array<string,mixed> $file Single entry from $_FILES.
	 * @return string|\WP_Error Имя записи (file_name) при успехе.
	 */
	public static function save_upload( array $file, string $dataset_kind = self::KIND_COUNTRY ) {
		$dataset_kind = self::sanitize_dataset_kind( $dataset_kind );
		self::install_db();
		if ( ! self::table_exists() ) {
			return new \WP_Error( 'wsp_csv_db', __( 'Не удалось создать таблицу для CSV в базе данных.', 'flavor-worldstat' ) );
		}

		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'wsp_csv_dir', __( 'Не удалось подготовить каталог для временных файлов.', 'flavor-worldstat' ) );
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

		$dir          = self::get_dir();
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

		$name = $base . '.csv';
		$dest = $incoming_dir . 'out-' . wp_generate_password( 16, false, false ) . '.csv';

		global $wpdb;
		$table = self::table_name();
		$try   = 0;
		while ( $try < 20 ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE file_name = %s LIMIT 1", $name ) );
			if ( $dup < 1 ) {
				break;
			}
			$name = $base . '-' . wp_generate_password( 6, false, false ) . '.csv';
			++$try;
		}

		$cleaner = new WorldStat_Csv_Cleaner();
		$base_opts = array(
			'purpose' => 'platform',
		);
		if ( $dataset_kind === self::KIND_INDICATOR ) {
			$base_opts = array(
				'purpose' => 'analytics',
			);
		}
		$opts = apply_filters(
			'worldstat_csv_cleaner_options',
			$base_opts,
			$temp,
			$dest,
			$dataset_kind
		);
		$opts = is_array( $opts ) ? $opts : $base_opts;
		$result = $cleaner->process( $temp, $dest, $opts );

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

		if ( ! is_readable( $dest ) ) {
			return new \WP_Error( 'wsp_csv_clean', __( 'Обработанный файл не найден.', 'flavor-worldstat' ) );
		}

		$body = file_get_contents( $dest );
		@unlink( $dest );

		if ( $body === false ) {
			return new \WP_Error( 'wsp_csv_read', __( 'Не удалось прочитать результат очистки.', 'flavor-worldstat' ) );
		}

		$proc_log = ( ! empty( $result['log'] ) && is_array( $result['log'] ) ) ? $result['log'] : array();

		$imp = array(
			'posts_touched'   => 0,
			'rows_written'    => 0,
			'rows_skipped'    => 0,
			'unknown_country' => 0,
			'metrics'         => array(),
		);
		if ( class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) ) {
			$imp = WorldStat_Csv_Country_Meta_Importer::import_from_csv_string( $body, pathinfo( $name, PATHINFO_FILENAME ) );
			if ( ! empty( $imp['rows_written'] ) ) {
				$proc_log[] = sprintf(
					/* translators: 1: number of value cells written, 2: number of country posts, 3: comma-separated metric slugs */
					__( 'Импорт в мета стран: записей %1$d, постов %2$d; показатели: %3$s.', 'flavor-worldstat' ),
					(int) $imp['rows_written'],
					(int) $imp['posts_touched'],
					implode( ', ', $imp['metrics'] )
				);
			}
		}

		if ( ! empty( $proc_log ) ) {
			self::set_process_log_flash( $proc_log );
		}

		$import_slugs_json = null;
		if ( ! empty( $imp['metrics'] ) && is_array( $imp['metrics'] ) ) {
			$slugs = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $imp['metrics'] )
					)
				)
			);
			if ( ! empty( $slugs ) ) {
				$import_slugs_json = wp_json_encode( $slugs );
			}
		}

		$now = current_time( 'mysql', true );
		$ins = $wpdb->insert(
			$table,
			array(
				'file_name'           => $name,
				'dataset_kind'        => $dataset_kind,
				'import_metric_slugs' => $import_slugs_json,
				'body'                => $body,
				'created_gmt'         => $now,
				'updated_gmt'         => $now,
				'author_id'           => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $ins ) {
			return new \WP_Error(
				'wsp_csv_db',
				$wpdb->last_error
					? $wpdb->last_error
					: __( 'Не удалось сохранить CSV в базу данных (проверьте max_allowed_packet и права MySQL).', 'flavor-worldstat' )
			);
		}

		self::bump_files_revision();

		return $name;
	}

	/**
	 * @param string $basename Имя файла как в столбце file_name.
	 * @return true|\WP_Error
	 */
	public static function delete_file( string $basename ) {
		self::install_db();
		if ( ! self::table_exists() ) {
			return new \WP_Error( 'wsp_csv_db', __( 'Таблица CSV не найдена.', 'flavor-worldstat' ) );
		}

		$base = wp_basename( $basename );
		if ( $base === '' || false !== strpos( $base, '..' ) || preg_match( '#[/\\\\]#', $base ) ) {
			return new \WP_Error( 'wsp_csv_name', __( 'Некорректное имя файла.', 'flavor-worldstat' ) );
		}
		if ( ! preg_match( '/\.csv$/i', $base ) ) {
			return new \WP_Error( 'wsp_csv_name', __( 'Некорректное имя файла.', 'flavor-worldstat' ) );
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from prefix + constant.
		$slug_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT import_metric_slugs FROM {$table} WHERE file_name = %s LIMIT 1", $base ),
			ARRAY_A
		);

		if ( class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) && is_array( $slug_row ) && ! empty( $slug_row['import_metric_slugs'] ) ) {
			$slugs = json_decode( (string) $slug_row['import_metric_slugs'], true );
			if ( is_array( $slugs ) && ! empty( $slugs ) ) {
				WorldStat_Csv_Country_Meta_Importer::purge_imported_metrics_from_all_countries( $slugs );
			}
		}

		$del = $wpdb->delete( $table, array( 'file_name' => $base ), array( '%s' ) );

		if ( ! $del ) {
			return new \WP_Error( 'wsp_csv_missing', __( 'Запись не найдена.', 'flavor-worldstat' ) );
		}

		self::bump_files_revision();

		return true;
	}
}
