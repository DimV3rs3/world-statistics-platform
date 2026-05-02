<?php
/**
 * Map explorer — interactive choropleth map with metric layers.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.css', [], '1.9' );
wp_enqueue_style( 'wsp-map-explorer', WSP_ASSETS_URL . 'css/map-explorer.css', [], WSP_VERSION );

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

// Группировка метрик
$grouped = [];
foreach ( $all_metrics as $k => $m ) {
    $eid = $m['extension'] ?? 'other';
    $grouped[ $eid ]['label'] = $ext_labels[ $eid ] ?? ucfirst( $eid );
    $grouped[ $eid ]['items'][ $k ] = $m;
}

// Слои расширений
$layers = [];
if ( class_exists( 'WorldStat_Extensions' ) ) {
    $layers = worldstat_platform()->extensions->get_layers();
}

// Данные карты
$map_data = worldstat_platform()->map->get_country_map_data();

// Текущий выбор
$cur_metric = $_GET['metric'] ?? '';
$cur_layer  = $_GET['layer'] ?? '';

// Данные слоя/метрики
$layer_data = [];
$layer_config = null;
if ( $cur_metric ) {
    // Прямая метрика
    $parts = explode( '.', $cur_metric, 2 );
    if ( count( $parts ) === 2 ) {
        $layer_data = WorldStat_Data::get_for_map( $parts[0], $parts[1] );
        $minfo = $all_metrics[ $cur_metric ] ?? [];
        $layer_config = [
            'label'      => $minfo['label'] ?? $parts[1],
            'color_scale' => [ '#22c55e', '#ef4444' ],
        ];
    }
} elseif ( $cur_layer ) {
    // Слой расширения
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

<div class="wsp-map-explorer-wrapper">
    
    <div class="wsp-map-explorer-header">
        <h1>🗺️ <?php esc_html_e( 'Картографический исследователь', 'flavor-worldstat' ); ?></h1>
        <p><?php esc_html_e( 'Хороплеты и слои данных по странам мира', 'flavor-worldstat' ); ?></p>

        <div class="wsp-map-explorer-controls">
            
            <?php if ( $cur_metric ) : ?>
                <span class="wsp-metric-badge">
                    <?php 
                    $minfo = $all_metrics[ $cur_metric ] ?? [];
                    echo esc_html( $minfo['label'] ?? $cur_metric ); 
                    if ( ! empty( $minfo['unit'] ) ) echo ' <span class="unit">(' . esc_html( $minfo['unit'] ) . ')</span>';
                    ?>
                </span>
                <a href="<?php echo esc_url( remove_query_arg( 'metric' ) ); ?>" class="wsp-reset-btn">
                    ← <?php esc_html_e( 'Регионы мира', 'flavor-worldstat' ); ?>
                </a>
            <?php else : ?>
                <select onchange="wspSwitchMetric(this.value)" class="wsp-select">
                    <option value="">🌍 <?php esc_html_e( 'Регионы мира', 'flavor-worldstat' ); ?></option>
                    <?php foreach ( $grouped as $eid => $g ) : ?>
                        <optgroup label="<?php echo esc_attr( $g['label'] ); ?>">
                            <?php foreach ( $g['items'] as $k => $m ) : ?>
                                <option value="<?php echo esc_attr( $k ); ?>">
                                    <?php echo esc_html( $m['label'] ); ?>
                                    <?php echo !empty($m['unit']) ? '(' . esc_html($m['unit']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

        </div>
    </div>

    <div class="wsp-map-container">
        <div id="wsp-explorer-map"></div>
        <div id="wsp-map-tooltip" class="wsp-map-tooltip"></div>
        
        <?php if ( $cur_metric && $layer_config ) : ?>
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