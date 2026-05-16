<?php
/**
 * Блок аналитики на странице страны (собственные алгоритмы под ряды по годам).
 *
 * @package WorldStat
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Country_Analysis {

	public static function init(): void {
		add_action( 'wp_ajax_worldstat_run_country_analysis', [ self::class, 'ajax_run' ] );
		add_action( 'wp_ajax_nopriv_worldstat_run_country_analysis', [ self::class, 'ajax_run' ] );
	}

	/**
	 * @param list<array<string,mixed>> $grid_items
	 */
	public static function render_panel( int $post_id, array $grid_items ): void {
		$prep = WorldStat_Country_ML::prepare( $grid_items );
		if ( null === $prep ) {
			return;
		}

		$chartable = array_filter(
			$grid_items,
			static function ( $item ) {
				$yd = $item['years_data'] ?? [];
				return is_array( $yd ) && count( $yd ) >= 3;
			}
		);
		if ( count( $chartable ) < 1 ) {
			return;
		}

		self::enqueue_assets( $post_id, $chartable );

		$default_id = (string) ( $chartable[0]['metric_id'] ?? $chartable[0]['slug'] ?? '' );

		echo '<section class="wsp-country-analytics" id="wsp-country-analytics" aria-labelledby="wsp-country-analytics-title">';
		echo '<header class="wsp-country-analytics__head">';
		echo '<h3 id="wsp-country-analytics-title" class="wsp-country-analytics__title">';
		echo esc_html__( 'Аналитика показателей', 'flavor-worldstat' );
		echo '</h3>';
		echo '<p class="wsp-country-analytics__desc">';
		echo esc_html__(
			'Регрессия тренда, кластеризация показателей и классификация лет по профилю данных — только CSV-метрики этой страны.',
			'flavor-worldstat'
		);
		echo '</p>';
		echo '<p class="wsp-muted wsp-country-analytics__meta">';
		echo esc_html( sprintf(
			/* translators: 1: metrics count, 2: year range */
			__( '%1$d показателей · общие годы %2$s', 'flavor-worldstat' ),
			count( $prep['metrics'] ),
			self::format_year_range( $prep['common_years'] )
		) );
		echo '</p>';
		echo '</header>';

		echo '<div class="wsp-country-analytics__controls">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-metric">';
		echo esc_html__( 'Показатель для тренда', 'flavor-worldstat' );
		echo '</label>';
		echo '<select id="wsp-country-analysis-metric" class="wsp-select">';
		foreach ( $chartable as $item ) {
			$mid = (string) ( $item['metric_id'] ?? $item['slug'] ?? '' );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $mid ),
				selected( $default_id, $mid, false ),
				esc_html( (string) ( $item['label'] ?? $mid ) )
			);
		}
		echo '</select>';

		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-k">';
		echo esc_html__( 'Число групп (k)', 'flavor-worldstat' );
		echo '</label>';
		echo '<input type="number" id="wsp-country-analysis-k" class="wsp-input" min="2" max="6" value="3" />';

		echo '<button type="button" class="wsp-btn wsp-btn-primary" id="wsp-country-analysis-run">';
		echo esc_html__( 'Рассчитать', 'flavor-worldstat' );
		echo '</button>';
		echo '<span class="wsp-country-analytics__status" id="wsp-country-analysis-status" aria-live="polite"></span>';
		echo '</div>';

		echo '<div class="wsp-country-analytics__results" id="wsp-country-analysis-results">';
		echo '<p class="wsp-muted">' . esc_html__( 'Загрузка…', 'flavor-worldstat' ) . '</p>';
		echo '</div>';
		echo '</section>';
	}

	public static function ajax_run(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( (string) $_POST['nonce'], 'wp_rest' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недействительный nonce.', 'flavor-worldstat' ) ], 403 );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $post_id < 1 || get_post_type( $post_id ) !== WorldStat_Country_CPT::SLUG ) {
			wp_send_json_error( [ 'message' => __( 'Страна не найдена.', 'flavor-worldstat' ) ], 404 );
		}

		$data = worldstat_platform()->data ?? null;
		if ( ! $data instanceof WorldStat_Data ) {
			wp_send_json_error( [ 'message' => __( 'Модуль данных недоступен.', 'flavor-worldstat' ) ], 500 );
		}

		$grid_items = $data->get_country_grid_items( $post_id );
		$metric_id  = sanitize_text_field( (string) ( $_POST['metric_id'] ?? '' ) );
		$k          = max( 2, min( 6, (int) ( $_POST['k'] ?? 3 ) ) );

		$result = WorldStat_Country_ML::analyze( $grid_items, $metric_id, $k );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => (string) ( $result['error'] ?? __( 'Анализ недоступен.', 'flavor-worldstat' ) ) ] );
		}

		wp_send_json_success( $result );
	}

	/**
	 * @param list<array<string,mixed>> $chartable
	 */
	private static function enqueue_assets( int $post_id, array $chartable ): void {
		WorldStat_UI::enqueue_chart_scripts();

		wp_enqueue_style(
			'worldstat-country-analytics',
			WSP_ASSETS_URL . 'css/country-analytics.css',
			[],
			WSP_VERSION
		);

		wp_enqueue_script(
			'worldstat-country-analysis',
			WSP_ASSETS_URL . 'js/country-analysis.js',
			[ 'jquery', 'worldstat-chart-builder' ],
			WSP_VERSION,
			true
		);

		wp_localize_script(
			'worldstat-country-analysis',
			'wspCountryAnalysis',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'postId'  => $post_id,
				'autoRun' => true,
				'i18n'    => [
					'running'  => __( 'Расчёт…', 'flavor-worldstat' ),
					'error'    => __( 'Ошибка', 'flavor-worldstat' ),
					'done'     => __( 'Готово', 'flavor-worldstat' ),
					'r2'       => __( 'R²', 'flavor-worldstat' ),
					'trend'    => __( 'Тренд', 'flavor-worldstat' ),
					'forecast' => __( 'Прогноз', 'flavor-worldstat' ),
					'year'     => __( 'год', 'flavor-worldstat' ),
					'metrics'  => __( 'Показатели', 'flavor-worldstat' ),
					'years'    => __( 'Годы', 'flavor-worldstat' ),
				],
			]
		);
	}

	/**
	 * @param list<int> $years
	 */
	private static function format_year_range( array $years ): string {
		if ( empty( $years ) ) {
			return '';
		}
		$min = min( $years );
		$max = max( $years );
		return $min === $max ? (string) $min : $min . '–' . $max;
	}
}

WorldStat_Country_Analysis::init();
