<?php
/**
 * ML для страницы страны: ряды показателей по годам.
 *
 * @package WorldStat
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WorldStat_Country_ML {

	/** @var list<string> */
	private const CLUSTER_COLORS = [ '#3366cc', '#dc3912', '#ff9900', '#109618', '#990099', '#0099c6' ];

	/** Конечный год линейного прогноза для регрессии и последующей классификации. */
	private const REGRESSION_FORECAST_END = 2050;

	/**
	 * @return array<string, string>
	 */
	public static function category_labels(): array {
		return [
			'all'            => __( 'Все темы', 'flavor-worldstat' ),
			'population'     => __( 'Население', 'flavor-worldstat' ),
			'health'         => __( 'Здоровье', 'flavor-worldstat' ),
			'urban'          => __( 'Города', 'flavor-worldstat' ),
			'territory'      => __( 'Территория и природа', 'flavor-worldstat' ),
			'infrastructure' => __( 'Инфраструктура', 'flavor-worldstat' ),
			'economy'        => __( 'Экономика', 'flavor-worldstat' ),
			'governance'     => __( 'Управление', 'flavor-worldstat' ),
			'other'          => __( 'Прочее', 'flavor-worldstat' ),
		];
	}

	/**
	 * @param list<array<string,mixed>> $grid_items
	 * @return array<string,mixed>|null
	 */
	public static function prepare( array $grid_items ): ?array {
		$metrics  = [];
		$year_set = [];

		foreach ( $grid_items as $item ) {
			$yd = $item['years_data'] ?? [];
			if ( ! is_array( $yd ) || count( $yd ) < 3 ) {
				continue;
			}
			$series = [];
			foreach ( $yd as $yk => $yv ) {
				$y = (int) $yk;
				if ( $y <= 0 || ! is_numeric( $yv ) || ! is_finite( (float) $yv ) ) {
					continue;
				}
				$series[ $y ] = (float) $yv;
				$year_set[ $y ] = true;
			}
			if ( count( $series ) < 3 ) {
				continue;
			}
			ksort( $series, SORT_NUMERIC );
			$slug      = sanitize_key( (string) ( $item['slug'] ?? '' ) );
			$raw_label = (string) ( $item['label'] ?? $slug );
			$label     = class_exists( 'WorldStat_Data' )
				? WorldStat_Data::resolve_metric_label( 'csv-country-meta', $slug, $raw_label )
				: $raw_label;
			$metrics[] = [
				'id'       => (string) ( $item['metric_id'] ?? $slug ),
				'slug'     => $slug,
				'label'    => $label !== '' ? $label : $slug,
				'category' => WorldStat_Data::metric_category_from_slug( $slug ),
				'series'   => $series,
			];
		}

		if ( count( $metrics ) < 5 ) {
			return null;
		}

		$years = array_keys( $year_set );
		sort( $years, SORT_NUMERIC );

		$common_years = [];
		foreach ( $years as $y ) {
			$c = 0;
			foreach ( $metrics as $m ) {
				if ( isset( $m['series'][ $y ] ) ) {
					++$c;
				}
			}
			if ( $c >= max( 3, (int) ceil( count( $metrics ) * 0.25 ) ) ) {
				$common_years[] = $y;
			}
		}

		if ( count( $common_years ) < 3 ) {
			$common_years = array_slice( $years, -min( 8, count( $years ) ) );
		}

		return [
			'metrics'      => $metrics,
			'years'        => $years,
			'common_years' => $common_years,
		];
	}

	/**
	 * Публичный API линейного тренда показателя по годам (сравнение стран, аналитика).
	 *
	 * @param array{label:string,series:array<int,float>,id?:string,slug?:string} $metric
	 * @return array<string,mixed>
	 */
	public static function regression_trend_for_metric( array $metric ): array {
		if ( empty( $metric['series'] ) || ! is_array( $metric['series'] ) ) {
			return [
				'ok'      => false,
				'message' => __( 'Нет ряда по годам.', 'flavor-worldstat' ),
			];
		}
		return self::regression_trend( $metric );
	}

	/**
	 * @param list<array<string,mixed>> $grid_items
	 * @param array<string,mixed>       $args metric_id, k_cluster, k_classify, cluster_category, cluster_metric_ids
	 * @return array<string,mixed>
	 */
	public static function analyze( array $grid_items, array $args ): array {
		$prep = self::prepare( $grid_items );
		if ( null === $prep ) {
			return [
				'ok'    => false,
				'error' => __( 'Недостаточно показателей с рядом ≥3 лет.', 'flavor-worldstat' ),
			];
		}

		$metric_id = sanitize_text_field( (string) ( $args['metric_id'] ?? '' ) );

		$metric = null;
		if ( $metric_id !== '' ) {
			foreach ( $prep['metrics'] as $m ) {
				if ( $m['id'] === $metric_id || $m['slug'] === $metric_id ) {
					$metric = $m;
					break;
				}
			}
			if ( null === $metric ) {
				return [
					'ok'    => false,
					'error' => __( 'Выбранный показатель не найден.', 'flavor-worldstat' ),
				];
			}
		}

		$k_cluster  = max( 2, min( 6, (int) ( $args['k_cluster'] ?? 3 ) ) );
		$k_classify = max( 2, min( 4, (int) ( $args['k_classify'] ?? 3 ) ) );

		$cluster_category = sanitize_key( (string) ( $args['cluster_category'] ?? 'all' ) );
		$cluster_ids      = array_map( 'sanitize_text_field', (array) ( $args['cluster_metric_ids'] ?? [] ) );
		$cluster_ids      = array_values( array_filter( $cluster_ids ) );

		$cluster_metrics = self::filter_metrics( $prep['metrics'], $cluster_category, $cluster_ids );
		$can_cluster     = count( $cluster_metrics ) >= $k_cluster;

		if ( null === $metric && ! $can_cluster ) {
			return [
				'ok'    => false,
				'error' => __( 'Выберите показатель и/или отметьте показатели для кластеризации.', 'flavor-worldstat' ),
			];
		}

		if ( ! empty( $cluster_ids ) && ! $can_cluster ) {
			return [
				'ok'    => false,
				'error' => __( 'Для кластеризации выберите больше показателей (или смените тему).', 'flavor-worldstat' ),
			];
		}

		$regression = [
			'ok'      => false,
			'message' => __( 'Выберите показатель в списке выше.', 'flavor-worldstat' ),
		];
		$classification = [
			'ok'      => false,
			'message' => __( 'Выберите показатель в списке выше.', 'flavor-worldstat' ),
		];
		$clustering = [
			'ok'      => false,
			'message' => __( 'Отметьте показатели для кластеризации.', 'flavor-worldstat' ),
		];

		if ( null !== $metric ) {
			$regression = self::regression_trend( $metric );
			$metric_for_classify = $metric;
			if ( ! empty( $regression['ok'] ) ) {
				$metric_for_classify = array_merge(
					$metric,
					[
						'series' => self::extend_series_to_forecast_year( $metric['series'], self::REGRESSION_FORECAST_END ),
					]
				);
			}
			$classification = self::classify_years_by_metric( $metric_for_classify, $k_classify );
		}
		if ( $can_cluster ) {
			$clustering = self::cluster_metrics( $prep, $cluster_metrics, $k_cluster, $cluster_category );
		}

		return [
			'ok'             => true,
			'metric'         => $metric,
			'metrics_count'  => count( $prep['metrics'] ),
			'cluster_count'  => count( $cluster_metrics ),
			'years_count'    => count( $prep['common_years'] ),
			'year_range'     => self::year_range_label( $prep['common_years'] ),
			'regression'     => $regression,
			'clustering'     => $clustering,
			'classification' => $classification,
		];
	}

	/**
	 * @param list<array<string,mixed>> $metrics
	 * @param list<string>              $ids
	 * @return list<array<string,mixed>>
	 */
	private static function filter_metrics( array $metrics, string $category, array $ids ): array {
		$out = $metrics;
		if ( $category !== '' && $category !== 'all' ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $m ) use ( $category ) {
						return ( $m['category'] ?? '' ) === $category;
					}
				)
			);
		}
		if ( ! empty( $ids ) ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $m ) use ( $ids ) {
						return in_array( $m['id'], $ids, true ) || in_array( $m['slug'], $ids, true );
					}
				)
			);
		}
		return $out;
	}

	/**
	 * Рекомендация k для k-means по отмеченным показателям (метод «локтя», как в эргономике).
	 *
	 * @param list<array<string,mixed>> $grid_items
	 * @param array<string,mixed>       $args cluster_category, cluster_metric_ids
	 * @return array{ok:bool,k?:int,k_max?:int,metrics_used?:int,message:string}
	 */
	public static function suggest_cluster_k( array $grid_items, array $args ): array {
		$prep = self::prepare( $grid_items );
		if ( null === $prep ) {
			return [
				'ok'      => false,
				'message' => __( 'Недостаточно показателей с рядом ≥3 лет.', 'flavor-worldstat' ),
			];
		}

		$cluster_category = sanitize_key( (string) ( $args['cluster_category'] ?? 'all' ) );
		$cluster_ids      = array_map( 'sanitize_text_field', (array) ( $args['cluster_metric_ids'] ?? [] ) );
		$cluster_ids      = array_values( array_filter( $cluster_ids ) );

		if ( empty( $cluster_ids ) ) {
			return [
				'ok'      => false,
				'message' => __( 'Отметьте показатели для кластеризации — тогда можно подобрать k.', 'flavor-worldstat' ),
			];
		}

		$cluster_metrics = self::filter_metrics( $prep['metrics'], $cluster_category, $cluster_ids );
		$built           = self::build_cluster_vectors( $prep, $cluster_metrics );
		$vectors         = $built['vectors'];
		$n               = count( $vectors );

		if ( $n < 3 ) {
			return [
				'ok'      => false,
				'message' => __( 'Для подбора k нужно минимум 3 показателя с полным рядом по общим годам.', 'flavor-worldstat' ),
			];
		}

		$k_max = min( 6, $n );
		$k     = self::optimal_k_elbow( $vectors, $k_max );

		return [
			'ok'           => true,
			'k'            => $k,
			'k_max'        => $k_max,
			'metrics_used' => $n,
			'message'      => sprintf(
				/* translators: 1: recommended k, 2: metrics count */
				__( 'Рекомендуется k = %1$d по %2$d показателям (метод «локтя» по профилям динамики).', 'flavor-worldstat' ),
				$k,
				$n
			),
		];
	}

	/**
	 * @param array<string,mixed>        $prep
	 * @param list<array<string,mixed>>  $metrics_subset
	 * @return array{vectors:list<list<float>>,names:list<string>}
	 */
	private static function build_cluster_vectors( array $prep, array $metrics_subset ): array {
		$years   = $prep['common_years'];
		$vectors = [];
		$names   = [];

		foreach ( $metrics_subset as $m ) {
			$raw = [];
			$ok  = true;
			foreach ( $years as $y ) {
				if ( ! isset( $m['series'][ $y ] ) ) {
					$ok = false;
					break;
				}
				$raw[] = (float) $m['series'][ $y ];
			}
			if ( ! $ok || count( $raw ) < 3 ) {
				continue;
			}
			$vectors[] = self::z_score_vector( $raw );
			$names[]   = (string) ( $m['label'] ?? '' );
		}

		return [
			'vectors' => $vectors,
			'names'   => $names,
		];
	}

	/**
	 * Подбор k по кривой WCSS (elbow), адаптация WSErgo_Macro_Cluster_Optimizer::optimal_k_elbow.
	 *
	 * @param list<list<float>> $X
	 */
	private static function optimal_k_elbow( array $X, int $k_cap ): int {
		$n     = count( $X );
		$k_min = 2;
		$k_max = min( max( $k_min, $k_cap ), min( 12, max( $k_min, (int) round( sqrt( $n ) ) ) ) );
		if ( $n <= $k_max ) {
			$k_max = max( $k_min, $n - 1 );
		}

		$wcss = [];
		for ( $k = $k_min; $k <= $k_max; $k++ ) {
			$labels     = self::kmeans( $X, $k, 45 )['labels'];
			$wcss[ $k ] = self::within_cluster_ss( $X, $labels, $k );
		}

		$best_k  = min( $k_cap, $k_max );
		$max_imp = 0.0;
		for ( $k = $k_min; $k < $k_max; $k++ ) {
			$imp = ( $wcss[ $k ] ?? 0.0 ) - ( $wcss[ $k + 1 ] ?? 0.0 );
			if ( $imp > $max_imp ) {
				$max_imp = $imp;
			}
		}
		if ( $max_imp < 1e-12 ) {
			return max( $k_min, min( $best_k, $k_cap ) );
		}
		$thresh = $max_imp * 0.12;
		for ( $k = $k_min; $k < $k_max; $k++ ) {
			$imp = ( $wcss[ $k ] ?? 0.0 ) - ( $wcss[ $k + 1 ] ?? 0.0 );
			if ( $imp < $thresh ) {
				return max( $k_min, min( $k, $k_cap ) );
			}
		}
		return max( $k_min, min( $best_k, $k_cap ) );
	}

	/**
	 * @param list<list<float>> $X
	 * @param list<int>         $labels
	 */
	private static function within_cluster_ss( array $X, array $labels, int $k ): float {
		$n = count( $X );
		$d = count( $X[0] ?? [] );
		if ( $n < 1 || $d < 1 ) {
			return 0.0;
		}
		$sums   = array_fill( 0, $k, array_fill( 0, $d, 0.0 ) );
		$counts = array_fill( 0, $k, 0 );
		for ( $i = 0; $i < $n; $i++ ) {
			$c = (int) ( $labels[ $i ] ?? 0 );
			if ( $c < 0 || $c >= $k ) {
				continue;
			}
			++$counts[ $c ];
			for ( $j = 0; $j < $d; $j++ ) {
				$sums[ $c ][ $j ] += $X[ $i ][ $j ];
			}
		}
		$wcss = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$c = (int) ( $labels[ $i ] ?? 0 );
			if ( $counts[ $c ] < 1 ) {
				continue;
			}
			for ( $j = 0; $j < $d; $j++ ) {
				$cent = $sums[ $c ][ $j ] / $counts[ $c ];
				$diff = $X[ $i ][ $j ] - $cent;
				$wcss += $diff * $diff;
			}
		}
		return $wcss;
	}

	/**
	 * @param array<string,mixed> $metric
	 * @return array<string,mixed>
	 */
	private static function regression_trend( array $metric ): array {
		$series = $metric['series'];
		$years  = array_keys( $series );
		sort( $years, SORT_NUMERIC );

		if ( count( $years ) < 4 ) {
			return [
				'ok'      => false,
				'message' => __( 'Для регрессии нужно минимум 4 года с данными.', 'flavor-worldstat' ),
			];
		}

		$xs = array_map( 'floatval', $years );
		$ys = [];
		foreach ( $years as $y ) {
			$ys[] = (float) $series[ $y ];
		}

		$fit = self::linear_fit( $xs, $ys );
		if ( null === $fit ) {
			return [ 'ok' => false, 'message' => __( 'Не удалось построить тренд.', 'flavor-worldstat' ) ];
		}

		$last_year     = (int) max( $years );
		$forecast_end  = self::REGRESSION_FORECAST_END;
		$forecast_val  = $fit['intercept'] + $fit['slope'] * (float) $forecast_end;

		$chart_years = $years;
		if ( $last_year < $forecast_end ) {
			for ( $y = $last_year + 1; $y <= $forecast_end; $y++ ) {
				$chart_years[] = $y;
			}
		}

		$actual_vals = [];
		$trend_vals  = [];
		foreach ( $chart_years as $y ) {
			$actual_vals[] = isset( $series[ $y ] ) ? (float) $series[ $y ] : null;
			$trend_vals[]  = round( $fit['intercept'] + $fit['slope'] * (float) $y, 4 );
		}

		$direction = __( 'стабильно', 'flavor-worldstat' );
		if ( abs( $fit['slope'] ) > 1e-9 ) {
			$rel = $fit['slope'] * (float) $last_year;
			if ( $rel > 0.01 ) {
				$direction = __( 'рост', 'flavor-worldstat' );
			} elseif ( $rel < -0.01 ) {
				$direction = __( 'снижение', 'flavor-worldstat' );
			}
		}

		return [
			'ok'          => true,
			'title'       => sprintf(
				/* translators: %s: metric label */
				__( 'Регрессия: %s', 'flavor-worldstat' ),
				$metric['label']
			),
			'description' => __( 'Линейный тренд выбранного показателя по годам.', 'flavor-worldstat' ),
			'stats'       => [
				'r2'        => round( $fit['r2'], 3 ),
				'slope'     => round( $fit['slope'], 6 ),
				'direction' => $direction,
				'forecast'  => [
					'year'  => $forecast_end,
					'value' => round( $forecast_val, 2 ),
				],
			],
			'chart'       => [
				'type'     => 'line',
				'labels'   => array_map( 'strval', $chart_years ),
				'datasets' => [
					[
						'label' => __( 'Факт', 'flavor-worldstat' ),
						'data'  => $actual_vals,
						'color' => '#3366cc',
					],
					[
						'label' => __( 'Тренд (OLS)', 'flavor-worldstat' ),
						'data'  => $trend_vals,
						'color' => '#dc3912',
					],
				],
				'x_label'  => __( 'Год', 'flavor-worldstat' ),
				'y_label'  => $metric['label'],
				'height'   => 280,
			],
		];
	}

	/**
	 * @param array<string,mixed>        $prep
	 * @param list<array<string,mixed>>  $metrics_subset
	 */
	private static function cluster_metrics( array $prep, array $metrics_subset, int $k, string $category ): array {
		$built   = self::build_cluster_vectors( $prep, $metrics_subset );
		$vectors = $built['vectors'];
		$names   = $built['names'];

		$n = count( $vectors );
		if ( $n < $k ) {
			return [
				'ok'      => false,
				'message' => __( 'Мало показателей с полным рядом по общим годам.', 'flavor-worldstat' ),
			];
		}

		$k     = max( 2, min( $k, $n ) );
		$km    = self::kmeans( $vectors, $k, 50 );
		$labels = $km['labels'];

		$groups = array_fill( 0, $k, [] );
		foreach ( $labels as $i => $lab ) {
			$groups[ (int) $lab ][] = $names[ $i ];
		}

		$cat_labels = self::category_labels();
		$scope      = $cat_labels[ $category ] ?? $cat_labels['all'];

		$size_labels = [];
		$size_data   = [];
		for ( $c = 0; $c < $k; ++$c ) {
			$size_labels[] = sprintf( __( 'Кластер %d', 'flavor-worldstat' ), $c + 1 );
			$size_data[]   = count( $groups[ $c ] );
		}

		$scatter_chart = self::build_cluster_scatter_chart( $vectors, $names, $labels, $k, $prep['common_years'] );

		return [
			'ok'          => true,
			'title'       => __( 'Кластеризация показателей', 'flavor-worldstat' ),
			'description' => sprintf(
				/* translators: 1: category scope, 2: number of metrics */
				__( 'Группы показателей со схожей динамикой (%1$s, %2$d показателей).', 'flavor-worldstat' ),
				$scope,
				$n
			),
			'groups'      => $groups,
			'charts'      => [
				[
					'type'     => 'bar',
					'title'    => __( 'Размер кластеров', 'flavor-worldstat' ),
					'labels'   => $size_labels,
					'datasets' => [
						[ 'label' => __( 'Показателей', 'flavor-worldstat' ), 'data' => $size_data, 'color' => '#3366cc' ],
					],
					'height'   => 240,
				],
				$scatter_chart,
			],
		];
	}

	/**
	 * Scatter: PCA по всем годам (2D), подписи точек — в tooltip.
	 *
	 * @param list<list<float>> $vectors
	 * @param list<string>      $names
	 * @param list<int>         $labels
	 * @param list<int>         $common_years
	 * @return array<string,mixed>
	 */
	private static function build_cluster_scatter_chart( array $vectors, array $names, array $labels, int $k, array $common_years ): array {
		$projection = self::profile_summary_scatter_projection( $vectors, $common_years );
		$coords     = self::soften_scatter_outliers( $projection['coords'] );
		$scatter_sets = [];
		for ( $c = 0; $c < $k; ++$c ) {
			$pts = [];
			foreach ( $labels as $i => $lab ) {
				if ( (int) $lab !== $c || ! isset( $coords[ $i ] ) ) {
					continue;
				}
				$pts[] = [
					'x'     => (float) $coords[ $i ]['x'],
					'y'     => (float) $coords[ $i ]['y'],
					'label' => (string) ( $names[ $i ] ?? '' ),
				];
			}
			if ( ! empty( $pts ) ) {
				$scatter_sets[] = [
					'label' => sprintf( __( 'Кластер %d', 'flavor-worldstat' ), $c + 1 ),
					'color' => self::CLUSTER_COLORS[ $c % count( self::CLUSTER_COLORS ) ],
					'data'  => $pts,
				];
			}
		}

		return [
			'type'        => 'scatter',
			'title'       => __( 'Профили показателей', 'flavor-worldstat' ),
			'subtitle'    => $projection['subtitle'] ?? '',
			'labels'      => [],
			'datasets'    => $scatter_sets,
			'x_label'     => $projection['x_label'],
			'y_label'     => $projection['y_label'],
			'height'      => 320,
		];
	}

	/**
	 * Две оси для scatter: z в последнем общем году и линейный тренд (не среднее z — оно ≈0 после нормализации).
	 *
	 * @param list<list<float>> $vectors
	 * @param list<int>         $years
	 * @return array{coords:list<array{x:float,y:float}>,x_label:string,y_label:string,subtitle:string}
	 */
	private static function profile_summary_scatter_projection( array $vectors, array $years ): array {
		$coords = [];
		$idx    = range( 0, max( 0, count( $vectors[0] ?? [] ) - 1 ) );
		$year_b = (string) ( $years[ count( $years ) - 1 ] ?? '' );

		foreach ( $vectors as $row ) {
			$n_row = count( $row );
			if ( $n_row < 1 ) {
				$coords[] = [ 'x' => 0.0, 'y' => 0.0 ];
				continue;
			}
			$z_last = (float) $row[ $n_row - 1 ];
			$slope  = 0.0;
			if ( $n_row >= 2 ) {
				$xs  = array_slice( $idx, 0, $n_row );
				$fit = self::linear_fit( array_map( 'floatval', $xs ), $row );
				if ( null !== $fit ) {
					$slope = (float) $fit['slope'];
				}
			}
			$coords[] = [
				'x' => round( $z_last, 3 ),
				'y' => round( $slope, 4 ),
			];
		}

		$year_a = (string) ( $years[0] ?? '' );

		return [
			'coords'   => $coords,
			'x_label'  => sprintf(
				/* translators: %s: year */
				__( 'Уровень z (%s)', 'flavor-worldstat' ),
				$year_b
			),
			'y_label'  => __( 'Тренд (наклон z)', 'flavor-worldstat' ),
			'subtitle' => sprintf(
				/* translators: 1: first year, 2: last year */
				__( 'Точка = показатель: насколько в %2$s он выше/ниже своей нормы (ось X) и растёт или падает по годам %1$s–%2$s (ось Y). Кластеры — по полному z-ряду.', 'flavor-worldstat' ),
				$year_a,
				$year_b
			),
		];
	}

	/**
	 * Сжимает экстремальные точки на графике (5–95 перцентиль), чтобы один выброс не ломал масштаб.
	 *
	 * @param list<array{x:float,y:float}> $coords
	 * @return list<array{x:float,y:float}>
	 */
	private static function soften_scatter_outliers( array $coords ): array {
		if ( count( $coords ) < 4 ) {
			return $coords;
		}
		$xs = array_column( $coords, 'x' );
		$ys = array_column( $coords, 'y' );
		$bx = self::percentile( $xs, 5 );
		$tx = self::percentile( $xs, 95 );
		$by = self::percentile( $ys, 5 );
		$ty = self::percentile( $ys, 95 );
		if ( abs( $tx - $bx ) < 1e-9 ) {
			$bx -= 0.5;
			$tx += 0.5;
		}
		if ( abs( $ty - $by ) < 1e-9 ) {
			$by -= 0.5;
			$ty += 0.5;
		}
		$out = [];
		foreach ( $coords as $pt ) {
			$out[] = [
				'x' => max( $bx, min( $tx, (float) $pt['x'] ) ),
				'y' => max( $by, min( $ty, (float) $pt['y'] ) ),
			];
		}
		return $out;
	}

	/**
	 * @param list<float> $values
	 */
	private static function percentile( array $values, float $pct ): float {
		$vals = array_values( $values );
		sort( $vals, SORT_NUMERIC );
		$n = count( $vals );
		if ( $n < 1 ) {
			return 0.0;
		}
		$idx = ( $pct / 100.0 ) * ( $n - 1 );
		$lo  = (int) floor( $idx );
		$hi  = (int) ceil( $idx );
		if ( $lo === $hi ) {
			return (float) $vals[ $lo ];
		}
		$w = $idx - $lo;
		return (float) ( $vals[ $lo ] * ( 1.0 - $w ) + $vals[ $hi ] * $w );
	}

	/**
	 * Классификация лет по уровню выбранного показателя.
	 *
	 * @param array<string,mixed> $metric
	 * @return array<string,mixed>
	 */
	private static function classify_years_by_metric( array $metric, int $k ): array {
		$series = $metric['series'];
		$years  = array_keys( $series );
		sort( $years, SORT_NUMERIC );

		if ( count( $years ) < 3 ) {
			return [
				'ok'      => false,
				'message' => __( 'Недостаточно лет с данными для классификации.', 'flavor-worldstat' ),
			];
		}

		$vectors = [];
		foreach ( $years as $y ) {
			$vectors[] = [ (float) $series[ $y ] ];
		}

		$k_eff  = max( 2, min( 4, $k, count( $vectors ) ) );
		$km     = self::kmeans( $vectors, $k_eff, 50 );
		$labels = $km['labels'];

		$cluster_mean = array_fill( 0, $k_eff, 0.0 );
		$cluster_cnt  = array_fill( 0, $k_eff, 0 );
		foreach ( $labels as $i => $lab ) {
			$c = (int) $lab;
			$cluster_mean[ $c ] += (float) $series[ $years[ $i ] ];
			++$cluster_cnt[ $c ];
		}
		for ( $c = 0; $c < $k_eff; ++$c ) {
			if ( $cluster_cnt[ $c ] > 0 ) {
				$cluster_mean[ $c ] /= (float) $cluster_cnt[ $c ];
			}
		}

		$order = range( 0, $k_eff - 1 );
		usort( $order, static fn( $a, $b ) => $cluster_mean[ $a ] <=> $cluster_mean[ $b ] );

		$period_names = [
			__( 'низкий уровень', 'flavor-worldstat' ),
			__( 'средний уровень', 'flavor-worldstat' ),
			__( 'высокий уровень', 'flavor-worldstat' ),
			__( 'очень высокий уровень', 'flavor-worldstat' ),
		];

		$rank_to_name = [];
		foreach ( $order as $rank => $cluster_id ) {
			$rank_to_name[ $cluster_id ] = $period_names[ min( $rank, count( $period_names ) - 1 ) ];
		}

		$timeline      = [];
		$period_counts = array_fill( 0, $k_eff, 0 );
		$bar_labels    = [];
		$bar_data      = [];

		foreach ( $labels as $i => $lab ) {
			$c = (int) $lab;
			++$period_counts[ $c ];
			$timeline[] = [
				'year'    => (int) $years[ $i ],
				'value'   => round( (float) $series[ $years[ $i ] ], 2 ),
				'period'  => $rank_to_name[ $c ] ?? ( __( 'уровень', 'flavor-worldstat' ) . ' ' . ( $c + 1 ) ),
				'cluster' => $c + 1,
			];
		}
		usort( $timeline, static fn( $a, $b ) => $a['year'] <=> $b['year'] );

		for ( $c = 0; $c < $k_eff; ++$c ) {
			$bar_labels[] = $rank_to_name[ $c ] ?? ( 'C' . ( $c + 1 ) );
			$bar_data[]   = $period_counts[ $c ];
		}

		$line_labels = array_map( 'strval', $years );
		$line_data   = [];
		foreach ( $years as $y ) {
			$line_data[] = (float) $series[ $y ];
		}

		return [
			'ok'          => true,
			'title'       => sprintf(
				/* translators: %s: metric label */
				__( 'Классификация лет: %s', 'flavor-worldstat' ),
				$metric['label']
			),
			'description' => __( 'Годы сгруппированы по уровню показателя (k-means); в ряд включён линейный прогноз до 2050 г.', 'flavor-worldstat' ),
			'timeline'    => $timeline,
			'charts'      => [
				[
					'type'     => 'line',
					'title'    => __( 'Динамика показателя', 'flavor-worldstat' ),
					'labels'   => $line_labels,
					'datasets' => [
						[
							'label' => $metric['label'],
							'data'  => $line_data,
							'color' => '#109618',
						],
					],
					'x_label'  => __( 'Год', 'flavor-worldstat' ),
					'y_label'  => $metric['label'],
					'height'   => 260,
				],
				[
					'type'     => 'bar',
					'title'    => __( 'Сколько лет в каждом уровне', 'flavor-worldstat' ),
					'labels'   => $bar_labels,
					'datasets' => [
						[ 'label' => __( 'Лет', 'flavor-worldstat' ), 'data' => $bar_data, 'color' => '#3366cc' ],
					],
					'height'   => 240,
				],
			],
		];
	}

	/**
	 * Дополняет ряд линейным прогнозом до целевого года (включительно).
	 *
	 * @param array<int|float|string, float> $series
	 * @return array<int, float>
	 */
	private static function extend_series_to_forecast_year( array $series, int $end_year ): array {
		$years = array_keys( $series );
		sort( $years, SORT_NUMERIC );

		if ( count( $years ) < 4 ) {
			return $series;
		}

		$xs = array_map( 'floatval', $years );
		$ys = [];
		foreach ( $years as $y ) {
			$ys[] = (float) $series[ $y ];
		}

		$fit = self::linear_fit( $xs, $ys );
		if ( null === $fit ) {
			return $series;
		}

		$extended = $series;
		$last     = (int) max( $years );
		for ( $y = $last + 1; $y <= $end_year; $y++ ) {
			$extended[ $y ] = round( $fit['intercept'] + $fit['slope'] * (float) $y, 4 );
		}

		return $extended;
	}

	/**
	 * @param list<float> $xs
	 * @param list<float> $ys
	 * @return array{slope:float,intercept:float,r2:float}|null
	 */
	private static function linear_fit( array $xs, array $ys ): ?array {
		$n = count( $xs );
		if ( $n < 2 || $n !== count( $ys ) ) {
			return null;
		}
		$sx = $sy = $sxx = $sxy = 0.0;
		for ( $i = 0; $i < $n; ++$i ) {
			$sx += $xs[ $i ];
			$sy += $ys[ $i ];
			$sxx += $xs[ $i ] * $xs[ $i ];
			$sxy += $xs[ $i ] * $ys[ $i ];
		}
		$den = $n * $sxx - $sx * $sx;
		if ( abs( $den ) < 1e-12 ) {
			return null;
		}
		$slope     = ( $n * $sxy - $sx * $sy ) / $den;
		$intercept = ( $sy - $slope * $sx ) / $n;

		$my     = $sy / $n;
		$ss_tot = 0.0;
		$ss_res = 0.0;
		for ( $i = 0; $i < $n; ++$i ) {
			$pred   = $intercept + $slope * $xs[ $i ];
			$ss_res += ( $ys[ $i ] - $pred ) ** 2;
			$ss_tot += ( $ys[ $i ] - $my ) ** 2;
		}
		$r2 = $ss_tot > 1e-12 ? max( 0.0, 1.0 - $ss_res / $ss_tot ) : 0.0;

		return [
			'slope'     => $slope,
			'intercept' => $intercept,
			'r2'        => $r2,
		];
	}

	/**
	 * @param list<float> $v
	 * @return list<float>
	 */
	private static function z_score_vector( array $v ): array {
		$n = count( $v );
		if ( $n === 0 ) {
			return [];
		}
		$mean = array_sum( $v ) / $n;
		$var  = 0.0;
		foreach ( $v as $x ) {
			$var += ( $x - $mean ) ** 2;
		}
		$std = sqrt( $var / max( 1, $n - 1 ) );
		if ( $std < 1e-12 ) {
			return array_fill( 0, $n, 0.0 );
		}
		$out = [];
		foreach ( $v as $x ) {
			$out[] = ( $x - $mean ) / $std;
		}
		return $out;
	}

	/**
	 * @param list<list<float>> $X
	 * @return array{labels:list<int>,centroids:list<list<float>>}
	 */
	private static function kmeans( array $X, int $k, int $max_iter ): array {
		$n = count( $X );
		$m = count( $X[0] ?? [] );
		$k = max( 2, min( $k, $n ) );

		$idx = range( 0, $n - 1 );
		mt_srand( crc32( wp_json_encode( [ $n, $k, $m ] ) ?: (string) $n ) );
		shuffle( $idx );

		$centroids = [];
		for ( $i = 0; $i < $k; ++$i ) {
			$centroids[] = $X[ $idx[ $i ] ];
		}

		$labels = array_fill( 0, $n, 0 );
		for ( $iter = 0; $iter < $max_iter; ++$iter ) {
			for ( $i = 0; $i < $n; ++$i ) {
				$best      = 0;
				$best_dist = INF;
				for ( $c = 0; $c < $k; ++$c ) {
					$dist = 0.0;
					for ( $j = 0; $j < $m; ++$j ) {
						$d = $X[ $i ][ $j ] - $centroids[ $c ][ $j ];
						$dist += $d * $d;
					}
					if ( $dist < $best_dist ) {
						$best_dist = $dist;
						$best      = $c;
					}
				}
				$labels[ $i ] = $best;
			}

			$new_centroids = array_fill( 0, $k, array_fill( 0, $m, 0.0 ) );
			$counts        = array_fill( 0, $k, 0 );
			for ( $i = 0; $i < $n; ++$i ) {
				$c = (int) $labels[ $i ];
				++$counts[ $c ];
				for ( $j = 0; $j < $m; ++$j ) {
					$new_centroids[ $c ][ $j ] += $X[ $i ][ $j ];
				}
			}
			for ( $c = 0; $c < $k; ++$c ) {
				if ( $counts[ $c ] === 0 ) {
					$new_centroids[ $c ] = $X[ $idx[ array_rand( $idx ) ] ];
					continue;
				}
				for ( $j = 0; $j < $m; ++$j ) {
					$new_centroids[ $c ][ $j ] /= (float) $counts[ $c ];
				}
			}
			$centroids = $new_centroids;
		}

		return [
			'labels'    => $labels,
			'centroids' => $centroids,
		];
	}

	/**
	 * @param list<int> $years
	 */
	private static function year_range_label( array $years ): string {
		if ( empty( $years ) ) {
			return '';
		}
		$min = min( $years );
		$max = max( $years );
		return $min === $max ? (string) $min : $min . '–' . $max;
	}
}
