<?php
/**
 * Data panel — sandbox: countries × metrics matrix with CSV export.
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

$selected_countries = isset( $_GET['countries'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $_GET['countries'] ) ) : [];
$selected_metrics   = isset( $_GET['metrics'] )   ? array_filter( array_map( 'sanitize_text_field', (array) $_GET['metrics'] ) )   : [];
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
$total_pages = 0;
if ( ! empty( $selected_countries ) && ! empty( $selected_metrics ) ) {
	$compare_args = [
		'countries' => $selected_countries,
		'metrics'   => $selected_metrics,
	];
	if ( $panel_year > 0 ) {
		$compare_args['year'] = $panel_year;
	}
	$result = WorldStat_Data::compare( $compare_args );
    
    $table_data = [];
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
    
    $total_rows = count( $table_data );
    $total_pages = ceil( $total_rows / $per_page );
    $offset = ( $page - 1 ) * $per_page;
    $paged_data = array_slice( $table_data, $offset, $per_page );
}
?>

<div class="wsp-page">
    
    <h1 class="wsp-page__title">🧪 <?php esc_html_e( 'Песочница данных', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle"><?php esc_html_e( 'Матрица стран × метрик с экспортом данных', 'flavor-worldstat' ); ?></p>

    <form method="GET" action="" id="wsp-panel-form">
        <div class="wsp-card">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                
                <!-- СТРАНЫ -->
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div class="wsp-card__label" style="margin-bottom:0;">🌍 Страны</div>
                        <label style="display:flex;align-items:center;gap:4px;font-size:11px;color:#2563eb;cursor:pointer;font-weight:600;">
                            <input type="checkbox" id="wsp-all-countries" onchange="wspToggleAllCountries(this)" style="accent-color:#2563eb;"> Все
                        </label>
                    </div>
                    <div style="max-height:220px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:10px; padding:4px; background:#fff;">
                        <?php foreach ( $all_countries as $c ) : 
                            $checked = in_array( $c['iso2'], $selected_countries );
                        ?>
                            <label class="wsp-metric-item <?php echo $checked ? 'is-checked' : ''; ?>" style="padding:5px 10px;">
                                <input type="checkbox" name="countries[]" value="<?php echo esc_attr( $c['iso2'] ); ?>" class="wsp-cb-country" <?php checked( $checked ); ?>>
                                <span class="wsp-metric-item__label"><?php echo esc_html( $c['flag'] . ' ' . $c['title'] ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- МЕТРИКИ -->
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <div class="wsp-card__label" style="margin-bottom:0;">📊 Метрики</div>
                        <label style="display:flex;align-items:center;gap:4px;font-size:11px;color:#2563eb;cursor:pointer;font-weight:600;">
                            <input type="checkbox" id="wsp-all-metrics"  style="accent-color:#2563eb;"> Все
                        </label>
                    </div>
                    <div style="max-height:220px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:10px; padding:8px; background:#fff;">
                        <?php foreach ( $grouped_metrics as $ext => $group ) : ?>
                            <div style="display:flex; align-items:center; padding:4px 6px; border-bottom:1px solid #f1f5f9; margin-bottom:2px;">
                                <span style="font-weight:700;font-size:12px;color:#334155;flex:1;"><?php echo esc_html( $group['label'] ); ?></span>
                                <label style="display:flex;align-items:center;gap:3px;font-size:10px;color:#2563eb;cursor:pointer;font-weight:600;margin-right:4px;">
                                    <input type="checkbox" class="wsp-cb-group-all" data-group="<?php echo esc_attr( $ext ); ?>"  style="accent-color:#2563eb;width:12px;height:12px;"> Все
                                </label>
                            </div>
                            <?php foreach ( $group['metrics'] as $key => $m ) : 
                                $checked = in_array( $key, $selected_metrics );
                            ?>
                                <label class="wsp-metric-item <?php echo $checked ? 'is-checked' : ''; ?>" style="padding:4px 8px;">
                                    <input type="checkbox" name="metrics[]" value="<?php echo esc_attr( $key ); ?>" 
                                        class="wsp-cb-metric" data-group="<?php echo esc_attr( $ext ); ?>" <?php checked( $checked ); ?>>
                                    <span class="wsp-metric-item__label"><?php echo esc_html( $m['label'] ); ?></span>
                                    <?php if ( ! empty( $m['unit'] ) ) : ?>
                                        <span class="wsp-metric-item__unit"><?php echo esc_html( $m['unit'] ); ?></span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
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
            <button type="submit" class="wsp-btn wsp-btn--primary" style="margin-top:16px;">Построить</button>
        </div>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // Все страны
        var allCountries = document.getElementById('wsp-all-countries');
        if (allCountries) {
            allCountries.addEventListener('change', function() {
                document.querySelectorAll('.wsp-cb-country').forEach(function(c) {
                    c.checked = this.checked;
                    c.closest('.wsp-metric-item').classList.toggle('is-checked', this.checked);
                }.bind(this));
            });
        }

        // Все метрики
        var allMetrics = document.getElementById('wsp-all-metrics');
        if (allMetrics) {
            allMetrics.addEventListener('change', function() {
                document.querySelectorAll('.wsp-cb-metric').forEach(function(c) {
                    c.checked = this.checked;
                    c.closest('.wsp-metric-item').classList.toggle('is-checked', this.checked);
                }.bind(this));
                document.querySelectorAll('.wsp-cb-group-all').forEach(function(g) {
                    g.checked = this.checked;
                }.bind(this));
            });
        }

        // Группа метрик
        document.querySelectorAll('.wsp-cb-group-all').forEach(function(groupCb) {
            groupCb.addEventListener('change', function() {
                var group = this.getAttribute('data-group');
                document.querySelectorAll('.wsp-cb-metric[data-group="' + group + '"]').forEach(function(c) {
                    c.checked = this.checked;
                    c.closest('.wsp-metric-item').classList.toggle('is-checked', this.checked);
                }.bind(this));
            });
        });

    });
    </script>

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
                        <th>Страна</th>
                        <?php foreach ( $selected_metrics as $mk ) : 
                            $minfo = $all_metrics[ $mk ] ?? [];
                            $label = $minfo['label'] ?? explode('.', $mk)[1];
                            $unit  = $minfo['unit'] ?? '';
                        ?>
                            <th title="<?php echo esc_attr( $mk ); ?>">
                                <?php echo esc_html( $label ); ?>
                                <?php if ( $unit ) echo ' <small style="color:#94a3b8;">(' . esc_html( $unit ) . ')</small>'; ?>
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

    <?php elseif ( ! empty( $selected_countries ) && ! empty( $selected_metrics ) ) : ?>
        <div class="wsp-empty">Нет данных для построения таблицы.</div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>