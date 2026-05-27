<?php
/**
 * Примеры CSV для админки «Данные CSV» и «Переводы показателей».
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Csv_Samples {

	public const ACTION = 'wsp_csv_download_sample';

	/**
	 * @return list<string>
	 */
	public static function dataset_sample_kinds(): array {
		return array(
			WorldStat_Uploaded_Csv::KIND_COUNTRY,
			WorldStat_Uploaded_Csv::KIND_INDICATOR,
			WorldStat_Uploaded_Csv::KIND_COMBINED,
		);
	}

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * URL скачивания примера набора данных.
	 */
	public static function download_url( string $kind ): string {
		$kind = WorldStat_Uploaded_Csv::sanitize_dataset_kind( $kind );
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'kind'   => $kind,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $kind )
		);
	}

	/**
	 * URL примера файла переводов.
	 */
	public static function translations_download_url(): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'kind'   => 'translations',
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( 'translations' )
		);
	}

	/**
	 * @return array{filename:string, body:string, label:string}
	 */
	public static function get_sample( string $kind ): array {
		if ( $kind === 'translations' ) {
			return array(
				'filename' => 'wsp-sample-translations.csv',
				'label'    => __( 'Переводы показателей', 'flavor-worldstat' ),
				'body'     => self::build_translations_csv(),
			);
		}

		$kind = WorldStat_Uploaded_Csv::sanitize_dataset_kind( $kind );
		$labels = WorldStat_Uploaded_Csv::dataset_kind_labels();

		switch ( $kind ) {
			case WorldStat_Uploaded_Csv::KIND_INDICATOR:
				return array(
					'filename' => 'wsp-sample-indicators-for-calc.csv',
					'label'    => (string) ( $labels[ $kind ] ?? $kind ),
					'body'     => self::build_indicator_csv(),
				);
			case WorldStat_Uploaded_Csv::KIND_COMBINED:
				return array(
					'filename' => 'wsp-sample-country-and-calc.csv',
					'label'    => (string) ( $labels[ $kind ] ?? $kind ),
					'body'     => self::build_combined_csv(),
				);
			case WorldStat_Uploaded_Csv::KIND_COUNTRY:
			default:
				return array(
					'filename' => 'wsp-sample-country-indicators.csv',
					'label'    => (string) ( $labels[ WorldStat_Uploaded_Csv::KIND_COUNTRY ] ?? '' ),
					'body'     => self::build_country_csv(),
				);
		}
	}

	public static function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'flavor-worldstat' ), '', array( 'response' => 403 ) );
		}

		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		if ( $kind === '' ) {
			wp_die( esc_html__( 'Не указан тип примера.', 'flavor-worldstat' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::nonce_action( $kind ) );

		if ( $kind === 'translations' ) {
			$sample = self::get_sample( 'translations' );
		} elseif ( in_array( $kind, self::dataset_sample_kinds(), true ) ) {
			$sample = self::get_sample( $kind );
		} else {
			wp_die( esc_html__( 'Неизвестный тип примера.', 'flavor-worldstat' ), '', array( 'response' => 400 ) );
		}

		$filename = sanitize_file_name( (string) ( $sample['filename'] ?? 'sample.csv' ) );
		if ( ! preg_match( '/\.csv$/i', $filename ) ) {
			$filename .= '.csv';
		}

		$body = (string) ( $sample['body'] ?? '' );
		if ( $body === '' ) {
			wp_die( esc_html__( 'Пустой пример.', 'flavor-worldstat' ), '', array( 'response' => 500 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $body ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary CSV download.
		echo $body;
		exit;
	}

	private static function nonce_action( string $kind ): string {
		return self::ACTION . '_' . sanitize_key( $kind );
	}

	/**
	 * @param list<scalar|null> $fields
	 */
	private static function csv_line( array $fields ): string {
		$fh = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) {
			return implode( ',', array_map( 'strval', $fields ) );
		}
		fputcsv( $fh, $fields );
		rewind( $fh );
		$line = (string) stream_get_contents( $fh );
		fclose( $fh );
		return rtrim( $line, "\r\n" );
	}

	/**
	 * Демо-строки: ISO3 × годы (для наглядных графиков на сайте).
	 *
	 * @return list<array{iso:string, years:array<int, array<string, float|int>>}>
	 */
	private static function demo_rows(): array {
		return array(
			array(
				'iso'   => 'USA',
				'years' => array(
					2020 => array( 'road_length' => 6735021, 'railway_length' => 293564, 'population_total' => 331002651, 'surface_area_sqkm' => 9833517, 'pop_dens_km2' => 36.0, 'urban_pct' => 82.9, 'forest_pct' => 33.9, 'life_exp_years' => 78.5 ),
					2021 => array( 'road_length' => 6751000, 'railway_length' => 293800, 'population_total' => 332915073, 'surface_area_sqkm' => 9833517, 'pop_dens_km2' => 36.5, 'urban_pct' => 83.0, 'forest_pct' => 33.8, 'life_exp_years' => 78.6 ),
					2022 => array( 'road_length' => 6770000, 'railway_length' => 294000, 'population_total' => 333287557, 'surface_area_sqkm' => 9833517, 'pop_dens_km2' => 36.7, 'urban_pct' => 83.1, 'forest_pct' => 33.9, 'life_exp_years' => 78.8 ),
				),
			),
			array(
				'iso'   => 'DEU',
				'years' => array(
					2020 => array( 'road_length' => 830000, 'railway_length' => 38500, 'population_total' => 83240525, 'surface_area_sqkm' => 357114, 'pop_dens_km2' => 233.0, 'urban_pct' => 77.5, 'forest_pct' => 32.7, 'life_exp_years' => 81.2 ),
					2021 => array( 'road_length' => 832000, 'railway_length' => 38600, 'population_total' => 83155031, 'surface_area_sqkm' => 357114, 'pop_dens_km2' => 234.0, 'urban_pct' => 77.6, 'forest_pct' => 32.8, 'life_exp_years' => 81.3 ),
					2022 => array( 'road_length' => 834000, 'railway_length' => 38700, 'population_total' => 83797985, 'surface_area_sqkm' => 357114, 'pop_dens_km2' => 235.0, 'urban_pct' => 77.8, 'forest_pct' => 32.9, 'life_exp_years' => 81.4 ),
				),
			),
			array(
				'iso'   => 'RUS',
				'years' => array(
					2020 => array( 'road_length' => 1519000, 'railway_length' => 87100, 'population_total' => 146748590, 'surface_area_sqkm' => 17098246, 'pop_dens_km2' => 8.6, 'urban_pct' => 74.6, 'forest_pct' => 49.4, 'life_exp_years' => 72.6 ),
					2021 => array( 'road_length' => 1521000, 'railway_length' => 87200, 'population_total' => 146171015, 'surface_area_sqkm' => 17098246, 'pop_dens_km2' => 8.6, 'urban_pct' => 74.8, 'forest_pct' => 49.5, 'life_exp_years' => 72.7 ),
					2022 => array( 'road_length' => 1523000, 'railway_length' => 87300, 'population_total' => 145805947, 'surface_area_sqkm' => 17098246, 'pop_dens_km2' => 8.8, 'urban_pct' => 75.0, 'forest_pct' => 49.6, 'life_exp_years' => 72.9 ),
				),
			),
			array(
				'iso'   => 'ABW',
				'years' => array(
					2020 => array( 'road_length' => 1050, 'railway_length' => 0, 'population_total' => 106766, 'surface_area_sqkm' => 180, 'pop_dens_km2' => 593.0, 'urban_pct' => 44.2, 'forest_pct' => 2.0, 'life_exp_years' => 76.2 ),
					2021 => array( 'road_length' => 1060, 'railway_length' => 0, 'population_total' => 107195, 'surface_area_sqkm' => 180, 'pop_dens_km2' => 595.0, 'urban_pct' => 44.3, 'forest_pct' => 2.0, 'life_exp_years' => 76.3 ),
					2022 => array( 'road_length' => 1070, 'railway_length' => 0, 'population_total' => 107667, 'surface_area_sqkm' => 180, 'pop_dens_km2' => 598.0, 'urban_pct' => 44.5, 'forest_pct' => 2.1, 'life_exp_years' => 76.5 ),
				),
			),
		);
	}

	/**
	 * Показатели страны: длинный формат country_code + year + одна метрика (удобен для импорта в мета стран).
	 */
	private static function build_country_csv(): string {
		$lines   = array();
		$lines[] = self::csv_line( array( 'country_code', 'year', 'road_length' ) );
		foreach ( self::demo_rows() as $row ) {
			$iso = (string) $row['iso'];
			foreach ( $row['years'] as $year => $metrics ) {
				$lines[] = self::csv_line(
					array(
						$iso,
						(int) $year,
						(float) ( $metrics['road_length'] ?? 0 ),
					)
				);
			}
		}
		return self::with_utf8_bom( implode( "\r\n", $lines ) . "\r\n" );
	}

	/**
	 * Индикаторы для расчётов: широкий формат (несколько столбцов-показателей на строку country_code + year).
	 */
	private static function build_indicator_csv(): string {
		$cols    = array( 'country_code', 'year', 'pop_dens_km2', 'urban_pct', 'forest_pct', 'life_exp_years' );
		$lines   = array();
		$lines[] = self::csv_line( $cols );
		foreach ( self::demo_rows() as $row ) {
			$iso = (string) $row['iso'];
			foreach ( $row['years'] as $year => $metrics ) {
				$line = array( $iso, (int) $year );
				foreach ( array_slice( $cols, 2 ) as $col ) {
					$line[] = $metrics[ $col ] ?? '';
				}
				$lines[] = self::csv_line( $line );
			}
		}
		return self::with_utf8_bom( implode( "\r\n", $lines ) . "\r\n" );
	}

	/**
	 * Объединённый: справочные + расчётные столбцы в одном wide-файле.
	 */
	private static function build_combined_csv(): string {
		$cols    = array(
			'country_code',
			'year',
			'population_total',
			'surface_area_sqkm',
			'road_length',
			'railway_length',
			'pop_dens_km2',
			'urban_pct',
			'forest_pct',
			'life_exp_years',
		);
		$lines   = array();
		$lines[] = self::csv_line( $cols );
		foreach ( self::demo_rows() as $row ) {
			$iso = (string) $row['iso'];
			foreach ( $row['years'] as $year => $metrics ) {
				$line = array( $iso, (int) $year );
				foreach ( array_slice( $cols, 2 ) as $col ) {
					$line[] = $metrics[ $col ] ?? '';
				}
				$lines[] = self::csv_line( $line );
			}
		}
		return self::with_utf8_bom( implode( "\r\n", $lines ) . "\r\n" );
	}

	private static function build_translations_csv(): string {
		$map = array(
			'road_length'        => 'Дороги, км',
			'railway_length'     => 'Железные дороги, км',
			'population_total'   => 'Население',
			'surface_area_sqkm'  => 'Площадь территории, км²',
			'pop_dens_km2'       => 'Плотность населения, чел/км²',
			'urban_pct'          => 'Доля городского населения, %',
			'forest_pct'         => 'Леса, % территории',
			'life_exp_years'     => 'Ожидаемая продолжительность жизни, лет',
			'urban_share_percent'=> 'Доля городского населения, %',
		);
		$lines   = array();
		$lines[] = self::csv_line( array( 'key', 'label_ru' ) );
		foreach ( $map as $key => $label ) {
			$lines[] = self::csv_line( array( $key, $label ) );
		}
		return self::with_utf8_bom( implode( "\r\n", $lines ) . "\r\n" );
	}

	private static function with_utf8_bom( string $csv ): string {
		return "\xEF\xBB\xBF" . $csv;
	}
}

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WorldStat_Csv_Samples' ) ) {
			WorldStat_Csv_Samples::init();
		}
	},
	8
);
