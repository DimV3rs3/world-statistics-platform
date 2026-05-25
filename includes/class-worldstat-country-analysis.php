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

		echo '<section class="wsp-country-analytics" id="wsp-country-analytics" aria-labelledby="wsp-country-analytics-title">';
		echo '<header class="wsp-country-analytics__head">';
		echo '<h3 id="wsp-country-analytics-title" class="wsp-country-analytics__title">';
		echo esc_html__( 'Аналитика показателей', 'flavor-worldstat' );
		echo '</h3>';
		echo '<p class="wsp-country-analytics__desc">';
		echo esc_html__(
			'Регрессия строит линейный тренд показателя до 2050 г. Классификация — по уровням эргономичности страны (сводный E и оси F, Cm, H, A, S, Ct). Сравнение стран по регрессии и рейтингу — на вкладке «Сравнение».',
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

		self::render_ergo_classification_block( $post_id );

		echo '<div class="wsp-country-analytics__controls">';
		echo '<div class="wsp-ca-settings">';

		echo '<div class="wsp-ca-settings__section">';
		echo '<span class="wsp-ca-settings__legend">' . esc_html__( 'Регрессия и классификация', 'flavor-worldstat' ) . '</span>';
		echo '<div class="wsp-ca-settings__row wsp-ca-settings__row--regression">';

		echo '<div class="wsp-ca-control wsp-ca-control--metric">';
		echo '<label class="wsp-country-analytics__field" for="wsp-country-analysis-metric">';
		echo esc_html__( 'Показатель', 'flavor-worldstat' );
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

		echo '</div></div>';

		echo '<div class="wsp-ca-settings__actions">';
		echo '<button type="button" class="wsp-btn wsp-btn-primary" id="wsp-country-analysis-run">';
		echo esc_html__( 'Рассчитать', 'flavor-worldstat' );
		echo '</button>';
		echo '<span class="wsp-country-analytics__status" id="wsp-country-analysis-status" aria-live="polite"></span>';
		echo '</div>';

		echo '</div></div>';

		echo '<div class="wsp-country-analytics__results" id="wsp-country-analysis-results">';
		echo '<p class="wsp-muted">' . esc_html__( 'Ниже после «Рассчитать» появится регрессия выбранного показателя. Классификация эргономичности — в блоке выше.', 'flavor-worldstat' ) . '</p>';
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

		$result = WorldStat_Country_ML::analyze(
			$grid_items,
			[
				'metric_id'  => sanitize_text_field( (string) ( $_POST['metric_id'] ?? '' ) ),
				'k_classify' => 0,
			]
		);

		if ( empty( $result['ok'] ) ) {
			self::send_json_error( [ 'message' => (string) ( $result['error'] ?? __( 'Анализ недоступен.', 'flavor-worldstat' ) ) ] );
		}

		unset( $result['clustering'] );
		$result['classification'] = self::build_ergo_classification_payload( $post_id );

		self::send_json_success( $result );
	}

	/**
	 * Классификация по индексам эргономичности (плагин ergonomics), без k-means по годам.
	 *
	 * @return array<string, mixed>
	 */
	private static function build_ergo_classification_payload( int $post_id ): array {
		$title = __( 'Классификация по уровням эргономичности', 'flavor-worldstat' );
		$desc  = __( 'Итог согласован с взвешенным баллом шести критериев (веса E) и их уровнями. По осям — динамическая шкала min–max выборки.', 'flavor-worldstat' );

		if ( ! class_exists( 'WSErgo_Tier_Classifier' ) || ! class_exists( 'WSErgo_Settings' ) ) {
			return [
				'ok'          => false,
				'title'       => $title,
				'description' => $desc,
				'message'     => __( 'Модуль эргономичности не активен.', 'flavor-worldstat' ),
			];
		}
		if ( WSErgo_Settings::get_country_index_source() !== 'macro_datasets' ) {
			return [
				'ok'          => false,
				'title'       => $title,
				'description' => $desc,
				'message'     => __( 'Классификация по осям доступна при расчёте индекса страны из CSV платформы.', 'flavor-worldstat' ),
			];
		}

		$iso2 = self::resolve_country_iso2( $post_id );
		if ( $iso2 === '' ) {
			return [
				'ok'          => false,
				'title'       => $title,
				'description' => $desc,
				'message'     => __( 'Не удалось определить код страны.', 'flavor-worldstat' ),
			];
		}

		$tier = WSErgo_Tier_Classifier::get_tier_for_iso2( $iso2 );
		if ( ! is_array( $tier ) || empty( $tier['label'] ) || ( $tier['label'] ?? '' ) === '—' ) {
			return [
				'ok'          => false,
				'title'       => $title,
				'description' => $desc,
				'message'     => __( 'Нет рассчитанных индексов эргономичности для этой страны.', 'flavor-worldstat' ),
			];
		}

		$axis_labels = WSErgo_Tier_Classifier::axis_labels_ru();
		$axes_rows   = [];
		foreach ( WSErgo_Tier_Classifier::SCORE_KEYS as $k ) {
			if ( ! isset( $tier['axes'][ $k ] ) ) {
				continue;
			}
			$axis_tier = isset( $tier['axis_tiers'][ $k ]['label'] ) ? (string) $tier['axis_tiers'][ $k ]['label'] : '';
			$axes_rows[] = [
				'axis'  => (string) ( $axis_labels[ $k ] ?? $k ),
				'score' => (string) $tier['axes'][ $k ],
				'tier'  => $axis_tier,
			];
		}

		$compare_url = '';
		if ( class_exists( 'WSCities_CPT' ) && method_exists( 'WSCities_CPT', 'get_country_tab_url' ) ) {
			$compare_url = WSCities_CPT::get_country_tab_url( $iso2, 'compare' );
		}

		return [
			'ok'           => true,
			'title'        => $title,
			'description'  => $desc,
			'tier_label'   => (string) ( $tier['label'] ?? '' ),
			'tier_slug'    => sanitize_key( (string) ( $tier['slug'] ?? '' ) ),
			'composite'    => isset( $tier['composite'] ) ? (string) $tier['composite'] : '',
			'tier_reason'  => (string) ( $tier['tier_reason'] ?? '' ),
			'cluster_label'=> (string) ( $tier['cluster_label'] ?? '' ),
			'axes'         => $axes_rows,
			'compare_url'  => $compare_url,
			'compare_hint' => __( 'Сравнение с другими странами (регрессия и рейтинг) — на вкладке «Сравнение».', 'flavor-worldstat' ),
		];
	}

	/**
	 * Постоянный блок классификации в «Аналитике показателей».
	 */
	private static function render_ergo_classification_block( int $post_id ): void {
		$payload = self::build_ergo_classification_payload( $post_id );
		echo '<div class="wsp-country-analytics__ergo" id="wsp-country-analytics-ergo">';
		if ( empty( $payload['ok'] ) ) {
			$msg = isset( $payload['message'] ) ? (string) $payload['message'] : '';
			echo '<p class="wsp-muted">' . esc_html( $msg !== '' ? $msg : __( 'Классификация эргономичности недоступна.', 'flavor-worldstat' ) ) . '</p>';
			echo '</div>';
			return;
		}
		$slug = sanitize_key( (string) ( $payload['tier_slug'] ?? '' ) );
		echo '<h4 class="wsp-ca-block__title" style="margin:0 0 8px;">' . esc_html( (string) ( $payload['title'] ?? '' ) ) . '</h4>';
		if ( ! empty( $payload['description'] ) ) {
			echo '<p class="wsp-muted" style="margin:0 0 10px;">' . esc_html( (string) $payload['description'] ) . '</p>';
		}
		echo '<p class="wsp-ca-ergo-tier"><span class="wsergo-tier-badge wsergo-tier-badge--' . esc_attr( $slug ) . '">'
			. esc_html( (string) ( $payload['tier_label'] ?? '' ) ) . '</span>';
		if ( ! empty( $payload['composite'] ) ) {
			echo ' <span class="wsp-muted">(' . esc_html__( 'Взвешенный балл', 'flavor-worldstat' ) . ': '
				. esc_html( (string) $payload['composite'] ) . ')</span>';
		}
		if ( ! empty( $payload['tier_reason'] ) ) {
			echo '<p class="wsp-muted" style="margin:6px 0 0;">' . esc_html( (string) $payload['tier_reason'] ) . '</p>';
		}
		if ( ! empty( $payload['cluster_label'] ) ) {
			echo '<p class="wsp-muted" style="margin:4px 0 0;">' . esc_html__( 'Профиль', 'flavor-worldstat' ) . ': '
				. esc_html( (string) $payload['cluster_label'] ) . '</p>';
		}
		echo '</p>';
		$axes = isset( $payload['axes'] ) && is_array( $payload['axes'] ) ? $payload['axes'] : array();
		if ( ! empty( $axes ) ) {
			echo '<table class="wsp-ca-timeline"><thead><tr><th>' . esc_html__( 'Ось', 'flavor-worldstat' )
				. '</th><th>' . esc_html__( 'Балл 0–100', 'flavor-worldstat' )
				. '</th><th>' . esc_html__( 'Уровень', 'flavor-worldstat' ) . '</th></tr></thead><tbody>';
			foreach ( $axes as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				echo '<tr><td>' . esc_html( (string) ( $row['axis'] ?? '' ) ) . '</td><td>'
					. esc_html( (string) ( $row['score'] ?? '' ) ) . '</td><td>'
					. esc_html( (string) ( $row['tier'] ?? '—' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $payload['compare_hint'] ) ) {
			echo '<p class="wsp-muted" style="margin:12px 0 0;">' . esc_html( (string) $payload['compare_hint'] );
			if ( ! empty( $payload['compare_url'] ) ) {
				echo ' <button type="button" class="wsp-btn-link" data-wsp-country-tab="compare">'
					. esc_html__( 'Открыть вкладку «Сравнение»', 'flavor-worldstat' ) . '</button>';
			}
			echo '</p>';
		}
		echo '</div>';
	}

	private static function resolve_country_iso2( int $post_id ): string {
		if ( class_exists( 'WorldStat_Country_CPT' ) && method_exists( 'WorldStat_Country_CPT', 'get_iso2_for_post' ) ) {
			return strtoupper( (string) WorldStat_Country_CPT::get_iso2_for_post( $post_id ) );
		}
		$candidates = [ 'wsp_country_iso2', 'country_iso2', 'iso2' ];
		foreach ( $candidates as $key ) {
			$v = strtoupper( sanitize_text_field( (string) get_post_meta( $post_id, $key, true ) ) );
			if ( strlen( $v ) === 2 ) {
				return $v;
			}
		}
		return '';
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

		if ( class_exists( 'WSErgo_Country_Renderer' ) ) {
			WSErgo_Country_Renderer::enqueue_public_assets();
		}

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
					'r2'            => __( 'R²', 'flavor-worldstat' ),
					'trend'         => __( 'Тренд', 'flavor-worldstat' ),
					'forecast'      => __( 'Прогноз', 'flavor-worldstat' ),
					'year'          => __( 'год', 'flavor-worldstat' ),
					'value'         => __( 'Значение', 'flavor-worldstat' ),
					'years'         => __( 'Год', 'flavor-worldstat' ),
					'period'        => __( 'Уровень', 'flavor-worldstat' ),
					'axis'          => __( 'Ось', 'flavor-worldstat' ),
					'score'         => __( 'Балл 0–100', 'flavor-worldstat' ),
					'composite'     => __( 'Сводный балл', 'flavor-worldstat' ),
					'compare'       => __( 'Сравнение стран', 'flavor-worldstat' ),
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
