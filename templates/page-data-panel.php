<?php
/**
 * Data panel — sandbox: countries × metrics matrix with heatmap, bars, CSV export.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'wsp-analytics', WSP_ASSETS_URL . 'css/wsp-analytics.css', [], WSP_VERSION );
wp_enqueue_script( 'wsp-analytics', WSP_ASSETS_URL . 'js/wsp-analytics.js', [], WSP_VERSION, true );

get_header();

$all_countries = WorldStat_Data::get_countries();
$all_metrics   = WorldStat_Data::get_available_metrics_with_core();

$ext_labels = [ 'core' => 'Ключевые метрики' ];
if ( class_exists( 'WorldStat_Extensions' ) ) {
    $ext_instance = worldstat_platform()->extensions ?? null;
    if ( $ext_instance ) {
        foreach ( $ext_instance->get_all() as $ext_id => $info ) {
            $tabs = $ext_instance->get_tabs();
            $ext_labels[ $ext_id ] = $tabs[ $ext_id ]['title'] ?? $info['name'] ?? ucfirst( $ext_id );
        }
    }
}

$grouped_metrics = [];
foreach ( $all_metrics as $key => $m ) {
    $ext = $m['extension'] ?? 'other';
    if ( ! isset( $grouped_metrics[ $ext ] ) ) {
        $grouped_metrics[ $ext ] = [ 'label' => $ext_labels[ $ext ] ?? ucfirst( $ext ), 'metrics' => [] ];
    }
    $grouped_metrics[ $ext ]['metrics'][ $key ] = $m;
}

$selected_countries = isset( $_GET['countries'] ) ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_GET['countries'] ) ), 0, 5 ) : [];
$selected_metrics   = isset( $_GET['metrics'] )   ? array_slice( array_filter( array_map( 'sanitize_text_field', (array) $_GET['metrics'] ) ),   0, 10 ) : [];
$panel_year = isset( $_GET['panel_year'] ) ? max( 0, intval( $_GET['panel_year'] ) ) : 0;
if ( $panel_year > 0 && class_exists( 'WorldStat_Platform_Years' ) ) {
	$panel_year = WorldStat_Platform_Years::clamp( $panel_year );
}

$panel_csv_years_union = [];
if ( class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) ) {
	foreach ( $selected_metrics as $mid ) {
		$parts = explode( '.', (string) $mid, 2 );
		if ( count( $parts ) !== 2 || $parts[0] !== 'csv-country-meta' ) {
			continue;
		}
		foreach ( WorldStat_Csv_Country_Meta_Importer::get_years_union_for_slug( $parts[1] ) as $yy ) {
			$panel_csv_years_union[ (int) $yy ] = true;
		}
	}
}
$panel_csv_year_list = array_keys( $panel_csv_years_union );
rsort( $panel_csv_year_list, SORT_NUMERIC );

$per_page = 25;
$page = isset( $_GET['ppage'] ) ? max( 1, intval( $_GET['ppage'] ) ) : 1;

$paged_data = [];
$table_data = [];
$total_pages = 0;
$min_max = [];

if ( ! empty( $selected_countries ) && ! empty( $selected_metrics ) ) {
	$compare_args = [
		'countries' => $selected_countries,
		'metrics'   => $selected_metrics,
	];
	if ( $panel_year > 0 ) {
		$compare_args['year'] = $panel_year;
	}
	$result = WorldStat_Data::compare( $compare_args );

    foreach ( $result as $row ) {
        $code = $row['code'] ?? '';
        unset( $row['code'] );
        $country = WorldStat_Data::get_country( $code );
        $table_data[] = [
            'code' => $code,
            'flag' => $country['flag'] ?? '',
            'name' => $country['title'] ?? $code,
            'data' => $row,
        ];
    }

    foreach ( $selected_metrics as $mk ) {
        $vals = [];
        foreach ( $table_data as $td ) {
            $v = $td['data'][ $mk ] ?? null;
            if ( is_numeric( $v ) ) {
                $vals[] = (float) $v;
            }
        }
        if ( ! empty( $vals ) ) {
            $min_max[ $mk ] = [ 'min' => min( $vals ), 'max' => max( $vals ) ];
        }
    }

    $total_rows = count( $table_data );
    $total_pages = ceil( $total_rows / $per_page );
    $offset = ( $page - 1 ) * $per_page;
    $paged_data = array_slice( $table_data, $offset, $per_page );
}
?>

<div class="wsp-page">

    <h1 class="wsp-page__title">🧪 <?php esc_html_e( 'Песочница данных', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle"><?php esc_html_e( 'Матрица стран × метрик с экспортом данных, heatmap и графиками', 'flavor-worldstat' ); ?></p>

    <form method="GET" action="" id="wsp-panel-form">
        <div class="wsp-card">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

                <div>
                    <div class="wsp-panel-row">
                        <div class="wsp-card__label wsp-panel-label">
                            🌍 <?php esc_html_e( 'Страны', 'flavor-worldstat' ); ?>
                            <span class="wsp-panel-limits" id="panel-countries-count">0/5</span>
                        </div>
                    </div>
                    <div class="wsp-panel-scroll wsp-panel-scroll-countries">
                        <?php foreach ( $all_countries as $c ) :
                            $checked = in_array( $c['iso2'], $selected_countries, true );
                            $disabled = count( $selected_countries ) >= 5 && ! $checked;
                        ?>
                            <label class="wsp-metric-item <?php echo $checked ? 'is-checked' : ''; ?>" style="padding:5px 10px;<?php echo $disabled ? ' opacity:0.5;' : ''; ?>">
                                <input type="checkbox" name="countries[]" value="<?php echo esc_attr( $c['iso2'] ); ?>" class="wsp-cb-country" <?php checked( $checked ); ?> <?php disabled( $disabled ); ?>>
                                <span class="wsp-metric-item__label"><?php echo esc_html( $c['flag'] . ' ' . $c['title'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="wsp-panel-row">
                        <div class="wsp-card__label wsp-panel-label">
                            📊 <?php esc_html_e( 'Метрики', 'flavor-worldstat' ); ?>
                            <span class="wsp-panel-limits" id="panel-metrics-count">0/10</span>
                        </div>
                    </div>
                    <div class="wsp-panel-scroll wsp-panel-scroll-metrics">
                        <?php foreach ( $grouped_metrics as $ext => $group ) : ?>
                            <div class="wsp-panel-group-header" onclick="this.nextElementSibling.classList.toggle('open'); this.querySelector('.wsp-panel-group-arrow').classList.toggle('rotated')">
                                <span class="wsp-panel-group-arrow">▸</span>
                                <span class="wsp-panel-group-title"><?php echo esc_html( $group['label'] ); ?></span>
                            </div>
                            <div class="wsp-panel-group-metrics">
                                <?php foreach ( $group['metrics'] as $key => $m ) :
                                    $checked = in_array( $key, $selected_metrics, true );
                                    $disabled = count( $selected_metrics ) >= 10 && ! $checked;
                                ?>
                                    <label class="wsp-metric-item <?php echo $checked ? 'is-checked' : ''; ?>" style="padding:4px 8px;<?php echo $disabled ? ' opacity:0.5;' : ''; ?>">
                                        <input type="checkbox" name="metrics[]" value="<?php echo esc_attr( $key ); ?>"
                                            class="wsp-cb-metric" data-group="<?php echo esc_attr( $ext ); ?>" <?php checked( $checked ); ?> <?php disabled( $disabled ); ?>>
                                        <span class="wsp-metric-item__label"><?php echo esc_html( $m['label'] ); ?></span>
                                        <?php if ( ! empty( $m['unit'] ) ) : ?>
                                            <span class="wsp-metric-item__unit"><?php echo esc_html( $m['unit'] ); ?></span>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

			<?php if ( ! empty( $panel_csv_year_list ) ) : ?>
				<div class="wsp-card" style="margin-top:12px;padding:16px;">
					<label class="wsp-card__label" for="wsp-panel-year"><?php esc_html_e( 'Год для показателей из CSV', 'flavor-worldstat' ); ?></label>
					<select name="panel_year" id="wsp-panel-year" style="margin-top:6px;max-width:220px;height:42px;padding:0 12px;border:1px solid #e2e8f0;border-radius:10px;">
						<option value="0"><?php esc_html_e( 'Последний доступный', 'flavor-worldstat' ); ?></option>
						<?php foreach ( $panel_csv_year_list as $py ) : ?>
							<option value="<?php echo (int) $py; ?>" <?php selected( $panel_year, (int) $py ); ?>><?php echo (int) $py; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

            <button type="submit" class="wsp-btn wsp-btn--primary" style="margin-top:16px;"><?php esc_html_e( 'Построить', 'flavor-worldstat' ); ?></button>
            <button type="button" class="wsp-btn wsp-btn--dashed" onclick="wspResetAll()" style="padding:8px 16px;font-size:13px;margin-left:8px;"><?php esc_html_e( 'Сбросить всё', 'flavor-worldstat' ); ?></button>
        </div>
    </form>

    <?php if ( ! empty( $paged_data ) ) : ?>

        <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
            <button type="button" class="wsp-btn wsp-btn--dashed" onclick="wspExportCSV('wsp-panel-table', 'worldstat-data.csv')">
                📥 <?php esc_html_e( 'Экспорт CSV', 'flavor-worldstat' ); ?>
            </button>
        </div>

        <div style="overflow-x:auto;">
            <table class="wsp-table" id="wsp-panel-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Страна', 'flavor-worldstat' ); ?></th>
                        <?php foreach ( $selected_metrics as $mk ) :
                            $minfo = $all_metrics[ $mk ] ?? [];
                            $label = trim( $minfo['label'] ?? explode( '.', $mk )[1] );
                            $unit  = trim( $minfo['unit'] ?? '' );
                        ?>
                            <th title="<?php echo esc_attr( $mk ); ?>">
                                <?php echo esc_html( $label ); ?>
                                <?php if ( $unit ) : ?>
                                    <small>(<?php echo esc_html( $unit ); ?>)</small>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $paged_data as $row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $row['flag'] . ' ' . $row['name'] ); ?></strong></td>
                            <?php foreach ( $selected_metrics as $mk ) :
                                $val = $row['data'][ $mk ] ?? '—';
                            ?>
                                <td>
                                    <?php
                                    if ( is_numeric( $val ) ) {
                                        $f = (float) $val;
                                        echo abs( $f - round( $f ) ) < 0.00001
                                            ? number_format( $f, 0, '.', ' ' )
                                            : number_format( $f, abs( $f ) < 0.001 ? 4 : 2, '.', ' ' );
                                    } else {
                                        echo esc_html( $val ?: '—' );
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="wsp-pagination">
            <?php
            $base = remove_query_arg( 'ppage' );
            for ( $i = 1; $i <= $total_pages; $i++ ) :
                $url = add_query_arg( 'ppage', $i, $base );
            ?>
                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $min_max ) ) : ?>
        <div class="wsp-card" style="margin-top:30px;">
            <h3>🗺️ <?php esc_html_e( 'Нормализованное сравнение (0-100%)', 'flavor-worldstat' ); ?></h3>
            <div style="overflow-x:auto;">
                <table class="wsp-heatmap">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Страна', 'flavor-worldstat' ); ?></th>
                            <?php foreach ( $selected_metrics as $mk ) :
                                $minfo = $all_metrics[ $mk ] ?? [];
                                $label = $minfo['label'] ?? explode( '.', $mk )[1];
                            ?>
                                <th class="col-header"><?php echo esc_html( $label ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $table_data as $row ) : ?>
                            <tr>
                                <td class="row-label"><?php echo esc_html( $row['flag'] . ' ' . $row['name'] ); ?></td>
                                <?php foreach ( $selected_metrics as $mk ) :
                                    $val = $row['data'][ $mk ] ?? null;
                                    if ( $val !== null && isset( $min_max[ $mk ] ) && $min_max[ $mk ]['max'] > $min_max[ $mk ]['min'] ) {
                                        $pct = round( ( $val - $min_max[ $mk ]['min'] ) / ( $min_max[ $mk ]['max'] - $min_max[ $mk ]['min'] ) * 100 );
                                    } else {
                                        $pct = 0;
                                    }
                                    $intensity = $pct > 0 ? round( 100 + ( 255 - 100 ) * ( 1 - $pct / 100 ) ) : 255;
                                    $color = "rgb({$intensity}, {$intensity}, 255)";
                                ?>
                                    <td>
                                        <div class="cell" style="background:<?php echo esc_attr( $color ); ?>; padding:6px 8px;">
                                            <?php echo $val !== null ? esc_html( number_format( (float) $val, 0, '', ' ' ) ) : '—'; ?>
                                            <div style="font-size:9px;color:#94a3b8;"><?php echo (int) $pct; ?>%</div>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="button" class="wsp-btn wsp-btn--dashed" onclick="document.getElementById('wsp-bar-charts').style.display='block';this.style.display='none';" style="margin-top:12px;">
                📊 <?php esc_html_e( 'Показать графики', 'flavor-worldstat' ); ?>
            </button>
        </div>

        <div class="wsp-bar-charts" id="wsp-bar-charts">
            <?php foreach ( $selected_metrics as $mk ) :
                $minfo = $all_metrics[ $mk ] ?? [];
                $label = $minfo['label'] ?? explode( '.', $mk )[1];
                $unit  = $minfo['unit'] ?? '';
                $max_val = $min_max[ $mk ]['max'] ?? 1;
            ?>
                <div class="wsp-bar-chart">
                    <div class="wsp-bar-chart__title"><?php echo esc_html( $label ); ?><?php echo $unit ? ' (' . esc_html( $unit ) . ')' : ''; ?></div>
                    <?php foreach ( $table_data as $row ) :
                        $val = $row['data'][ $mk ] ?? null;
                        $bar_pct = $max_val > 0 && $val !== null ? round( $val / $max_val * 100 ) : 0;
                    ?>
                        <div class="wsp-bar-row">
                            <div class="wsp-bar-row__label"><?php echo esc_html( $row['flag'] . ' ' . $row['name'] ); ?></div>
                            <div class="wsp-bar-row__bar-wrap">
                                <div class="wsp-bar-row__bar" style="width:<?php echo (int) $bar_pct; ?>%">
                                    <?php echo $bar_pct > 15 ? esc_html( $row['name'] ) : ''; ?>
                                </div>
                            </div>
                            <div class="wsp-bar-row__value">
                                <?php echo $val !== null ? esc_html( number_format( (float) $val, 0, '', ' ' ) . ( $unit ? ' ' . $unit : '' ) ) : '—'; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    <?php elseif ( ! empty( $selected_countries ) && ! empty( $selected_metrics ) ) : ?>
        <div class="wsp-empty"><?php esc_html_e( 'Нет данных для построения таблицы.', 'flavor-worldstat' ); ?></div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
