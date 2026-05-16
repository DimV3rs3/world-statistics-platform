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
			$metrics[] = [
				'id'       => (string) ( $item['metric_id'] ?? $slug ),
				'slug'     => $slug,
				'label'    => (string) ( $item['label'] ?? $slug ),
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
			$regression     = self::regression_trend( $metric );
			$classification = self::classify_years_by_metric( $metric, $k_classify );
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

		$trend_vals = [];
		foreach ( $years as $y ) {
			$trend_vals[] = round( $fit['intercept'] + $fit['slope'] * (float) $y, 4 );
		}

		$last_year     = (int) max( $years );
		$forecast_year = $last_year + 1;
		$forecast_val  = $fit['intercept'] + $fit['slope'] * (float) $forecast_year;

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
					'year'  => $forecast_year,
					'value' => round( $forecast_val, 2 ),
				],
			],
			'chart'       => [
				'type'     => 'line',
				'labels'   => array_map( 'strval', $years ),
				'datasets' => [
					[
						'label' => __( 'Факт', 'flavor-worldstat' ),
						'data'  => $ys,
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
			$names[]   = $m['label'];
		}

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

		$scatter_sets = [];
		for ( $c = 0; $c < $k; ++$c ) {
			$pts = [];
			foreach ( $labels as $i => $lab ) {
				if ( (int) $lab !== $c ) {
					continue;
				}
				$pts[] = [
					'x' => (float) $vectors[ $i ][0],
					'y' => (float) ( $vectors[ $i ][1] ?? $vectors[ $i ][0] ),
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

		$year_a = (string) ( $years[0] ?? '' );
		$year_b = (string) ( $years[1] ?? $year_a );

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
				[
					'type'     => 'scatter',
					'title'    => __( 'Профили показателей', 'flavor-worldstat' ),
					'labels'   => [],
					'datasets' => $scatter_sets,
					'x_label'  => sprintf( __( 'z(%s)', 'flavor-worldstat' ), $year_a ),
					'y_label'  => sprintf( __( 'z(%s)', 'flavor-worldstat' ), $year_b ),
					'height'   => 300,
				],
			],
		];
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
			'description' => __( 'Годы сгруппированы по уровню выбранного показателя (k-means по значению).', 'flavor-worldstat' ),
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
