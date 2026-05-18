<?php
/**
 * Блок аналитики на странице страны.
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

		self::enqueue_assets( $post_id );

		$cat_labels = WorldStat_Country_ML::category_labels();

		echo '<section class="wsp-country-analytics" id="wsp-country-analytics" aria-labelledby="wsp-country-analytics-title">';
		echo '<header class="wsp-country-analytics__head">';
		echo '<h3 id="wsp-country-analytics-title" class="wsp-country-analytics__title">';
		echo esc_html__( 'Аналитика показателей', 'flavor-worldstat' );
		echo '</h3>';
		echo '<p class="wsp-country-analytics__desc">';
		echo esc_html__(
			'Регрессия строит линейный тренд до 2050 г.; классификация лет учитывает факт и прогноз. Кластеризация — по отмеченным показателям и теме.',
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

		echo '<div class="wsp-country-analytics__controls wsp-country-analytics__controls--grid">';

		echo '<div class="wsp-ca-control wsp-ca-control--metric">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-metric">';
		echo esc_html__( 'Показатель (регрессия и классификация)', 'flavor-worldstat' );
		echo '</label>';
		echo '<select id="wsp-country-analysis-metric" class="wsp-select">';
		echo '<option value="">' . esc_html__( '— выберите показатель —', 'flavor-worldstat' ) . '</option>';
		foreach ( $chartable as $item ) {
			$mid = (string) ( $item['metric_id'] ?? $item['slug'] ?? '' );
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $mid ),
				esc_html( (string) ( $item['label'] ?? $mid ) )
			);
		}
		echo '</select>';
		echo '</div>';

		echo '<div class="wsp-ca-control wsp-ca-control--narrow">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-k-classify">';
		echo esc_html__( 'k — периоды (классификация)', 'flavor-worldstat' );
		echo '</label>';
		echo '<input type="number" id="wsp-country-analysis-k-classify" class="wsp-input" min="2" max="4" value="3" />';
		echo '</div>';

		echo '<div class="wsp-ca-control wsp-ca-control--narrow">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-k-cluster">';
		echo esc_html__( 'k — кластеры показателей', 'flavor-worldstat' );
		echo '</label>';
		echo '<input type="number" id="wsp-country-analysis-k-cluster" class="wsp-input" min="2" max="6" value="3" />';
		echo '</div>';

		echo '<div class="wsp-ca-control wsp-ca-control--theme">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-cluster-cat">';
		echo esc_html__( 'Тема для кластеризации', 'flavor-worldstat' );
		echo '</label>';
		echo '<select id="wsp-country-analysis-cluster-cat" class="wsp-select">';
		foreach ( $cat_labels as $cid => $clabel ) {
			printf( '<option value="%s">%s</option>', esc_attr( $cid ), esc_html( $clabel ) );
		}
		echo '</select>';
		echo '</div>';

		echo '<div class="wsp-ca-control wsp-ca-control--actions">';
		echo '<button type="button" class="wsp-btn wsp-btn-primary" id="wsp-country-analysis-run">';
		echo esc_html__( 'Рассчитать', 'flavor-worldstat' );
		echo '</button>';
		echo '<span class="wsp-country-analytics__status" id="wsp-country-analysis-status" aria-live="polite"></span>';
		echo '</div>';

		echo '</div>';

		echo '<div class="wsp-country-analytics__cluster-pick">';
		echo '<div class="wsp-country-analytics__cluster-pick-head">';
		echo '<span class="wsp-country-analytics__field">' . esc_html__( 'Показатели для кластеризации', 'flavor-worldstat' ) . '</span>';
		echo '<button type="button" class="wsp-btn-link" id="wsp-cluster-select-visible">' . esc_html__( 'Выбрать все', 'flavor-worldstat' ) . '</button>';
		echo '<button type="button" class="wsp-btn-link" id="wsp-cluster-clear-visible">' . esc_html__( 'Снять все', 'flavor-worldstat' ) . '</button>';
		echo '</div>';
		echo '<div class="wsp-ca-metric-picks" id="wsp-cluster-metric-picks">';
		foreach ( $chartable as $item ) {
			$mid  = (string) ( $item['metric_id'] ?? $item['slug'] ?? '' );
			$slug = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$cat  = WorldStat_Data::metric_category_from_slug( $slug );
			printf(
				'<label class="wsp-ca-metric-pick" data-category="%s">'
				. '<input type="checkbox" name="cluster_metrics[]" value="%s" /> '
				. '<span>%s</span></label>',
				esc_attr( $cat ),
				esc_attr( $mid ),
				esc_html( (string) ( $item['label'] ?? $mid ) )
			);
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="wsp-country-analytics__results" id="wsp-country-analysis-results">';
		echo '<p class="wsp-muted">' . esc_html__( 'Выберите параметры и нажмите «Рассчитать». Анализ не запускается автоматически.', 'flavor-worldstat' ) . '</p>';
		echo '</div>';
		echo '</section>';
	}

	public static function ajax_run(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( (string) $_POST['nonce'], 'wp_rest' ) ) {
			self::send_json_error( [ 'message' => __( 'Недействительный nonce.', 'flavor-worldstat' ) ] );
		}

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $post_id < 1 || get_post_type( $post_id ) !== WorldStat_Country_CPT::SLUG ) {
			self::send_json_error( [ 'message' => __( 'Страна не найдена.', 'flavor-worldstat' ) ] );
		}

		$data = worldstat_platform()->data ?? null;
		if ( ! $data instanceof WorldStat_Data ) {
			self::send_json_error( [ 'message' => __( 'Модуль данных недоступен.', 'flavor-worldstat' ) ] );
		}

		$grid_items = $data->get_country_grid_items( $post_id );

		$cluster_ids = [];
		if ( isset( $_POST['cluster_metrics'] ) && is_array( $_POST['cluster_metrics'] ) ) {
			$cluster_ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['cluster_metrics'] ) );
		}

		$result = WorldStat_Country_ML::analyze(
			$grid_items,
			[
				'metric_id'          => sanitize_text_field( (string) ( $_POST['metric_id'] ?? '' ) ),
				'k_cluster'          => (int) ( $_POST['k_cluster'] ?? 3 ),
				'k_classify'         => (int) ( $_POST['k_classify'] ?? 3 ),
				'cluster_category'   => sanitize_key( (string) ( $_POST['cluster_category'] ?? 'all' ) ),
				'cluster_metric_ids' => $cluster_ids,
			]
		);

		if ( empty( $result['ok'] ) ) {
			self::send_json_error( [ 'message' => (string) ( $result['error'] ?? __( 'Анализ недоступен.', 'flavor-worldstat' ) ) ] );
		}

		self::send_json_success( $result );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function send_json_success( array $data ): void {
		if ( function_exists( 'worldstat_discard_ajax_output_buffer' ) ) {
			worldstat_discard_ajax_output_buffer();
		}
		wp_send_json_success( $data );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private static function send_json_error( array $data ): void {
		if ( function_exists( 'worldstat_discard_ajax_output_buffer' ) ) {
			worldstat_discard_ajax_output_buffer();
		}
		wp_send_json_error( $data );
	}

	private static function enqueue_assets( int $post_id ): void {
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
				'autoRun' => false,
				'i18n'    => [
					'running'       => __( 'Расчёт…', 'flavor-worldstat' ),
					'error'         => __( 'Ошибка', 'flavor-worldstat' ),
					'done'          => __( 'Готово', 'flavor-worldstat' ),
					'pickMetric'    => __( 'Выберите показатель.', 'flavor-worldstat' ),
					'pickCluster'   => __( 'Отметьте хотя бы один показатель для кластеризации.', 'flavor-worldstat' ),
					'pickEither'    => __( 'Выберите показатель и/или отметьте показатели для кластеризации.', 'flavor-worldstat' ),
					'r2'            => __( 'R²', 'flavor-worldstat' ),
					'trend'         => __( 'Тренд', 'flavor-worldstat' ),
					'forecast'      => __( 'Прогноз', 'flavor-worldstat' ),
					'year'          => __( 'год', 'flavor-worldstat' ),
					'value'         => __( 'Значение', 'flavor-worldstat' ),
					'cluster'       => __( 'Кластер', 'flavor-worldstat' ),
					'years'         => __( 'Год', 'flavor-worldstat' ),
					'period'        => __( 'Уровень', 'flavor-worldstat' ),
					'networkError'  => __( 'Ошибка сети. Попробуйте снова.', 'flavor-worldstat' ),
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
