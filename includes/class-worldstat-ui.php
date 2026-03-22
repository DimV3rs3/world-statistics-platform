<?php
/**
 * UI Component Library — ready-made blocks for extensions.
 *
 * Every method accepts an array of options and echoes HTML by default.
 * Pass 'echo' => false to return the HTML instead.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_UI {

    private static bool $chart_enqueued = false;

    /**
     * Stats cards grid.
     *
     * @param array $items [ [ 'label', 'value', 'icon'?, 'change'? ], ... ]
     * @param array $opts  [ 'columns' => 4, 'echo' => true ]
     */
    public static function stats_grid( array $items, array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [ 'columns' => 4, 'echo' => true ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/stats-grid.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Chart (Chart.js wrapper).
     *
     * @param array $opts {
     *   @type string $type   'line'|'bar'|'pie'|'doughnut'|'area'|'scatter'
     *   @type string $title  Chart title.
     *   @type array  $labels X-axis labels.
     *   @type array  $datasets [ [ 'label', 'data' => [], 'color'? ], ... ]
     *   @type string $x_label
     *   @type string $y_label
     *   @type int    $height  Canvas height in px.
     * }
     */
    public static function chart( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'type' => 'line', 'title' => '', 'labels' => [], 'datasets' => [],
            'x_label' => '', 'y_label' => '', 'height' => 300, 'echo' => true,
        ] );

        self::enqueue_chartjs();

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/chart.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Data table.
     */
    public static function table( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'headers' => [], 'rows' => [], 'sortable' => true,
            'searchable' => false, 'exportable' => false, 'echo' => true,
        ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/data-table.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Mini map (single country or markers) with coordinate grid.
     *
     * @param array $opts {
     *   @type string $type               'markers'|'world'. Default 'markers'.
     *   @type array  $markers            Manual markers: [ [ 'lat', 'lng', 'title', 'color'?, 'radius'?, 'popup'? ], ... ]
     *   @type float  $lat                Center latitude. Default 0.
     *   @type float  $lng                Center longitude. Default 0.
     *   @type int    $zoom               Zoom level. Default 5.
     *   @type int    $height             Map height px. Default 300.
     *   @type bool   $grid               Show coordinate grid (graticule). Default true.
     *   @type int    $grid_interval      Grid line interval in degrees. Default 15.
     *   @type bool   $grid_labels        Show coordinate labels on grid. Default true.
     *   @type array  $marker_layers      Extension marker layer IDs to load. Empty = none, ['all'] = all registered.
     *   @type string $country            ISO2 code — filter extension markers to this country only.
     *   @type bool   $cluster            Enable marker clustering. Default false.
     *   @type bool   $layer_control      Show layer switcher. Default true.
     *   @type string $tile_style         Tile style: 'osm'|'carto-light'|'carto-dark'. Default 'carto-light'.
     *   @type bool   $echo               Echo or return HTML. Default true.
     * }
     */
    public static function map( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'type'          => 'markers',
            'markers'       => [],
            'lat'           => 0,
            'lng'           => 0,
            'zoom'          => 5,
            'height'        => 300,
            'grid'          => true,
            'grid_interval' => 15,
            'grid_labels'   => true,
            'marker_layers' => [],
            'country'       => '',
            'cluster'       => false,
            'layer_control' => true,
            'tile_style'    => 'carto-light',
            'echo'          => true,
        ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/mini-map.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Country comparison widget.
     */
    public static function comparison( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'countries' => [], 'metric' => '', 'metrics' => [],
            'chart_type' => 'bar', 'echo' => true,
        ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/comparison.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Timeline component.
     */
    public static function timeline( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'start' => 1990, 'end' => 2025, 'events' => [], 'echo' => true,
        ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/timeline.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Text block with metric highlights.
     */
    public static function text_block( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'content' => '', 'highlights' => [], 'echo' => true,
        ] );

        ob_start();
        include WSP_TEMPLATES_DIR . 'components/text-block.php';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /**
     * Section wrapper used by extensions.
     */
    public static function section( array $opts = [] ): string {
        $opts = wp_parse_args( $opts, [
            'title' => '', 'type' => 'custom', 'data' => [], 'content' => '', 'echo' => true,
        ] );

        ob_start();
        echo '<div class="wsp-section">';
        if ( $opts['title'] ) {
            echo '<h3 class="wsp-section-title">' . esc_html( $opts['title'] ) . '</h3>';
        }

        switch ( $opts['type'] ) {
            case 'stats_grid':
                self::stats_grid( $opts['data'], [ 'echo' => true ] );
                break;
            case 'chart':
                self::chart( $opts['data'] + [ 'echo' => true ] );
                break;
            case 'table':
                self::table( $opts['data'] + [ 'echo' => true ] );
                break;
            default:
                echo wp_kses_post( $opts['content'] );
        }

        echo '</div>';
        $html = ob_get_clean();

        if ( $opts['echo'] ) { echo $html; return ''; }
        return $html;
    }

    /* ── Internal ──────────────────────────────────── */

    private static function enqueue_chartjs(): void {
        if ( self::$chart_enqueued ) return;
        self::$chart_enqueued = true;

        wp_enqueue_script( 'chartjs', WSP_ASSETS_URL . 'vendor/chartjs/chart.umd.min.js', [], '4.4', true );
        wp_enqueue_script( 'worldstat-chart-builder', WSP_ASSETS_URL . 'js/chart-builder.js', [ 'chartjs' ], WSP_VERSION, true );
    }
}
