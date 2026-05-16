<?php
/**
 * Map explorer — interactive choropleth map with metric layers.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.css', [], '1.9' );
wp_enqueue_style( 'wsp-map-explorer', WSP_ASSETS_URL . 'css/map-explorer.css', [], WSP_VERSION );
wp_enqueue_style( 'wsp-analytics', WSP_ASSETS_URL . 'css/wsp-analytics.css', [], WSP_VERSION );

wp_enqueue_script( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.js', [], '1.9', true );
wp_enqueue_script( 'topojson-client', get_template_directory_uri() . '/assets/vendor/topojson/topojson-client.min.js', [], '3.1.0', true );
wp_enqueue_script( 'wsp-map-explorer', WSP_ASSETS_URL . 'js/map-explorer.js', [ 'leaflet', 'topojson-client' ], WSP_VERSION, true );

get_header();

$all_metrics = WorldStat_Data::get_available_metrics_with_core();

$ext_labels = [ 'core' => 'Ключевые метрики' ];

if ( class_exists( 'WorldStat_Extensions' ) ) {
    $ext = worldstat_platform()->extensions ?? null;
    if ( $ext ) {
        foreach ( $ext->get_all() as $id => $info ) {
            $tabs = $ext->get_tabs();
            $ext_labels[ $id ] = $tabs[ $id ]['title'] ?? $info['name'] ?? $id;
        }
    }
}

$grouped = [];
foreach ( $all_metrics as $k => $m ) {
    $eid = $m['extension'] ?? 'other';
    $grouped[ $eid ]['label'] = $ext_labels[ $eid ] ?? ucfirst( $eid );
    $grouped[ $eid ]['items'][ $k ] = $m;
}

$layers = [];
if ( class_exists( 'WorldStat_Extensions' ) ) {
    $layers = worldstat_platform()->extensions->get_layers();
}

$map_data = worldstat_platform()->map->get_country_map_data();

$cur_metric = $_GET['metric'] ?? '';
$cur_layer  = $_GET['layer'] ?? '';

$layer_data = [];
$layer_config = null;

if ( $cur_metric ) {
    $parts = explode( '.', $cur_metric, 2 );
    if ( count( $parts ) === 2 ) {
        $layer_data = WorldStat_Data::get_for_map( $parts[0], $parts[1] );
        $minfo = $all_metrics[ $cur_metric ] ?? [];
        $layer_config = [
            'label'       => $minfo['label'] ?? $parts[1],
            'color_scale' => [ '#22c55e', '#ef4444' ],
        ];
    }
} elseif ( $cur_layer ) {
    foreach ( $layers as $l ) {
        $id = $l['ext_id'] . '_' . sanitize_title( $l['label'] );
        if ( $id === $cur_layer && is_callable( $l['data_callback'] ) ) {
            $layer_config = $l;
            $layer_data = (array) call_user_func( $l['data_callback'] );
            break;
        }
    }
}

wp_localize_script( 'wsp-map-explorer', 'wspExplorerData', [
    'urls'        => $map_data['urls'] ?? [],
    'names'       => $map_data['names'] ?? [],
    'population'  => $map_data['population'] ?? [],
    'layerData'   => $layer_data,
    'layerConfig' => $layer_config ? [ 'label' => $layer_config['label'], 'color_scale' => $layer_config['color_scale'] ] : null,
] );
wp_add_inline_script( 'wsp-map-explorer', 'window.wspThemeUrl = ' . wp_json_encode( get_template_directory_uri() ) . ';', 'before' );
?>

<div class="wsp-page">

    <h1 class="wsp-page__title">🗺️ <?php esc_html_e( 'Картографический исследователь', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle"><?php esc_html_e( 'Хороплеты и слои данных по странам мира', 'flavor-worldstat' ); ?></p>

    <div class="wsp-card">
        <form method="GET" id="wsp-map-form" style="display:flex; gap:16px; flex-wrap:wrap; align-items:stretch;">
            <div style="flex:1; min-width:280px; position:relative;">
                <div class="wsp-card__label"><?php esc_html_e( 'Показатель', 'flavor-worldstat' ); ?></div>
                <div id="wsp-metric-dropdown-trigger" class="wsp-dropdown-trigger" onclick="wspToggleMapDropdown()">
                    <span id="wsp-metric-selected-label">
                        <?php
                        if ( $cur_metric && isset( $all_metrics[ $cur_metric ] ) ) {
                            echo esc_html( $all_metrics[ $cur_metric ]['label'] ?? $cur_metric );
                        } elseif ( $cur_layer && $layer_config ) {
                            echo esc_html( $layer_config['label'] ?? $cur_layer );
                        } else {
                            echo '🌍 ' . esc_html__( 'Регионы мира', 'flavor-worldstat' );
                        }
                        ?>
                    </span>
                    <span style="margin-left:auto;transition:transform 0.2s;" id="wsp-dropdown-arrow">▾</span>
                </div>
                <input type="hidden" name="metric" id="wsp-metric-input" value="<?php echo esc_attr( $cur_metric ); ?>">
                <div id="wsp-metric-dropdown" class="wsp-dropdown" style="display:none;">
                    <div class="wsp-dropdown__option <?php echo ! $cur_metric && ! $cur_layer ? 'selected' : ''; ?>"
                         data-value=""
                         data-label="🌍 <?php echo esc_attr__( 'Регионы мира', 'flavor-worldstat' ); ?>"
                         onclick="wspSelectMapMetric(this)">
                        🌍 <?php esc_html_e( 'Регионы мира', 'flavor-worldstat' ); ?>
                        <?php if ( ! $cur_metric && ! $cur_layer ) : ?><span class="wsp-metric-check">✓</span><?php endif; ?>
                    </div>
                    <?php foreach ( $grouped as $eid => $g ) : ?>
                        <div class="wsp-dropdown__group-header" onclick="wspToggleMapGroup(this)">
                            <span class="wsp-dropdown__group-arrow">▸</span>
                            <span style="flex:1;"><?php echo esc_html( $g['label'] ); ?></span>
                            <span style="font-size:11px;color:#94a3b8;">(<?php echo count( $g['items'] ); ?>)</span>
                        </div>
                        <div class="wsp-dropdown__group-body" style="display:none;">
                            <?php foreach ( $g['items'] as $k => $m ) : $sel = $cur_metric === $k; ?>
                                <div class="wsp-dropdown__option <?php echo $sel ? 'selected' : ''; ?>"
                                     data-value="<?php echo esc_attr( $k ); ?>"
                                     data-label="<?php echo esc_attr( $m['label'] ); ?>"
                                     onclick="wspSelectMapMetric(this)">
                                    <?php echo esc_html( $m['label'] ); ?>
                                    <?php if ( ! empty( $m['unit'] ) ) : ?>
                                        <span style="font-size:11px;color:#94a3b8;">(<?php echo esc_html( $m['unit'] ); ?>)</span>
                                    <?php endif; ?>
                                    <?php if ( $sel ) : ?><span class="wsp-metric-check">✓</span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="wsp-map-container">
        <div id="wsp-explorer-map"></div>
        <div id="wsp-map-tooltip" class="wsp-map-tooltip"></div>

        <?php if ( ( $cur_metric || $cur_layer ) && $layer_config ) : ?>
        <div class="wsp-map-legend visible" id="wsp-legend">
            <h4><?php echo esc_html( $layer_config['label'] ); ?></h4>
            <div class="wsp-map-legend__gradient"></div>
            <div class="wsp-map-legend__labels">
                <span><?php esc_html_e( 'Низкое', 'flavor-worldstat' ); ?></span>
                <span><?php esc_html_e( 'Высокое', 'flavor-worldstat' ); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
