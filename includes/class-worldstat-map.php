<?php
/**
 * Map System — integrates with the Ergonosphera theme SVG/Leaflet map.
 *
 * How it works:
 *   1. Theme enqueues 'ergo-svg-map' script (Leaflet + TopoJSON).
 *   2. This class detects it and injects `wscMapData` via wp_localize_script.
 *   3. Theme's svg-map.js checks wscMapData.active — if true, country clicks
 *      redirect to WSP country pages and tooltips show population + capital.
 *   4. Redirect handler: /country/?code=XX → proper WSP permalink.
 *   5. Extension layers for choropleth/heatmap are also supported.
 *
 * The variable name `wscMapData` is kept for backward compatibility with
 * the existing ergonosphera theme JS code.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Map {

    private WorldStat_Extensions $extensions;

    public function __construct( WorldStat_Extensions $extensions ) {
        $this->extensions = $extensions;

        // Inject data into the theme map script (high priority to run after theme enqueues)
        add_action( 'wp_enqueue_scripts', [ $this, 'inject_map_data' ], 99 );

        // Redirect handler for /country/?code=XX
        add_action( 'template_redirect', [ $this, 'handle_code_redirect' ] );

        // Shortcode for standalone map
        add_shortcode( 'worldstat_map', [ $this, 'shortcode' ] );

        // Flush cache when a country is saved
        add_action( 'save_post_' . WorldStat_Country_CPT::SLUG, [ __CLASS__, 'flush_cache' ] );
    }

    /* ═══════════════════════════════════════════════════════
       THEME MAP INTEGRATION (ergo-svg-map)
    ═══════════════════════════════════════════════════════ */

    /**
     * Inject wscMapData into the theme's 'ergo-svg-map' script.
     *
     * This is the primary integration point. The theme's svg-map.js
     * already contains logic to check wscMapData and use it for
     * country clicks and tooltip enrichment.
     */
    public function inject_map_data(): void {
        // Only if the theme's map script is enqueued or registered
        if ( ! wp_script_is( 'ergo-svg-map', 'enqueued' ) && ! wp_script_is( 'ergo-svg-map', 'registered' ) ) {
            return;
        }

        $data = $this->get_country_map_data();

        if ( empty( $data['urls'] ) ) {
            return;
        }

        // Use the same variable name 'wscMapData' that the theme expects
        wp_localize_script( 'ergo-svg-map', 'wscMapData', [
            'active'       => true,
            'urls'         => $data['urls'],        // { 'RU': '/country/russia/', ... }
            'population'   => $data['population'],  // { 'RU': 144236933, ... }
            'names'        => $data['names'],       // { 'RU': 'Россия', ... }
            'capitals'     => $data['capitals'],    // { 'RU': 'Москва', ... }
            'countriesUrl' => WorldStat_Pages::get_page_url( 'countries' ),
            'compareUrl'   => WorldStat_Pages::get_page_url( 'compare' ),
        ] );

        // Also enqueue the platform's map handler for layer switching & markers
        $layers        = $this->get_layers_config();
        $marker_layers = $this->get_marker_layers_config();

        if ( ! empty( $layers ) || ! empty( $marker_layers ) ) {
            wp_enqueue_script(
                'worldstat-map-handler',
                WSP_ASSETS_URL . 'js/map-handler.js',
                [ 'jquery', 'ergo-svg-map' ],
                WSP_VERSION,
                true
            );

            wp_localize_script( 'worldstat-map-handler', 'worldstatMap', [
                'countryUrls'  => $data['urls'],
                'restUrl'      => rest_url( 'worldstat/v1/' ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'layers'       => $layers,
                'markerLayers' => $marker_layers,
            ] );
        }
    }

    /**
     * Build maps of ISO2 → url, population, name, capital.
     * Cached via transient for performance.
     */
    public function get_country_map_data(): array {
        $cache_key = 'wsp_map_integration_data';
        $data = get_transient( $cache_key );

        if ( false !== $data && is_array( $data ) ) {
            return $data;
        }

        $data = [
            'urls'       => [],
            'population' => [],
            'names'      => [],
            'capitals'   => [],
        ];

        $posts = get_posts( [
            'post_type'      => WorldStat_Country_CPT::SLUG,
            'posts_per_page' => 200,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
        ] );

        foreach ( $posts as $post ) {
            $iso2 = get_post_meta( $post->ID, 'wsp_iso_alpha2', true );
            if ( ! $iso2 ) continue;

            $iso2 = strtoupper( $iso2 );
            $data['urls'][ $iso2 ]       = get_permalink( $post->ID );
            $data['population'][ $iso2 ] = (int) get_post_meta( $post->ID, 'wsp_population', true );
            $data['names'][ $iso2 ]      = $post->post_title;
            $data['capitals'][ $iso2 ]   = get_post_meta( $post->ID, 'wsp_capital_ru', true );
        }

        // Cache for 1 hour
        set_transient( $cache_key, $data, HOUR_IN_SECONDS );

        return $data;
    }

    /* ═══════════════════════════════════════════════════════
       REDIRECT HANDLER
    ═══════════════════════════════════════════════════════ */

    /**
     * Redirect /country/?code=XX to the proper WSP country page.
     * Handles both the theme's default URL format and legacy links.
     */
    public function handle_code_redirect(): void {
        if ( ! isset( $_GET['code'] ) ) {
            return;
        }

        // Only on relevant pages
        if ( ! is_post_type_archive( WorldStat_Country_CPT::SLUG ) && ! is_404() ) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if ( strpos( $request_uri, 'country' ) === false ) {
                return;
            }
        }

        $code = strtoupper( sanitize_text_field( $_GET['code'] ) );
        if ( strlen( $code ) !== 2 ) {
            return;
        }

        $post = WorldStat_Country_CPT::get_by_code( $code );
        if ( $post ) {
            wp_redirect( get_permalink( $post->ID ), 301 );
            exit;
        }
    }

    /* ═══════════════════════════════════════════════════════
       EXTENSION LAYERS
    ═══════════════════════════════════════════════════════ */

    /**
     * Get extension layers config for the JS handler.
     */
    private function get_layers_config(): array {
        $layers = [];
        foreach ( $this->extensions->get_layers() as $layer ) {
            $layers[] = [
                'id'         => $layer['ext_id'] . '_' . sanitize_title( $layer['label'] ),
                'label'      => $layer['label'],
                'ext_id'     => $layer['ext_id'],
                'type'       => $layer['type'],
                'colorScale' => $layer['color_scale'],
            ];
        }
        return $layers;
    }

    /**
     * Get map layer data server-side (called by REST API).
     */
    public function get_layer_data( string $layer_id ): array {
        $layers = $this->extensions->get_layers();

        foreach ( $layers as $layer ) {
            $id = $layer['ext_id'] . '_' . sanitize_title( $layer['label'] );
            if ( $id === $layer_id && is_callable( $layer['data_callback'] ) ) {
                return (array) call_user_func( $layer['data_callback'] );
            }
        }

        return [];
    }

    /* ═══════════════════════════════════════════════════════
       EXTENSION MARKER LAYERS
    ═══════════════════════════════════════════════════════ */

    /**
     * Get marker layers config for JS (meta only, no actual data).
     */
    private function get_marker_layers_config(): array {
        $out = [];
        foreach ( $this->extensions->get_marker_layers() as $i => $layer ) {
            $out[] = [
                'id'     => $layer['ext_id'] . '_markers_' . $i,
                'ext_id' => $layer['ext_id'],
                'label'  => $layer['label'],
                'icon'   => $layer['icon'],
                'color'  => $layer['color'],
                'radius' => (int) $layer['radius'],
            ];
        }
        return $out;
    }

    /**
     * Get marker data from an extension callback.
     * Called by REST API: GET /map-markers/{id}?country=XX
     *
     * @param string $layer_id  Composite layer ID: "{ext_id}_markers_{index}"
     * @param string $country   Optional ISO2 code to filter markers.
     * @return array|null        Array of markers or null if layer not found.
     */
    public function get_marker_data( string $layer_id, string $country = '' ): ?array {
        $marker_layers = $this->extensions->get_marker_layers();

        foreach ( $marker_layers as $i => $layer ) {
            $id = $layer['ext_id'] . '_markers_' . $i;
            if ( $id !== $layer_id ) continue;

            // If country filter and country_callback available — use it
            if ( $country && is_callable( $layer['country_callback'] ?? null ) ) {
                return (array) call_user_func( $layer['country_callback'], $country );
            }

            // Otherwise use the global data_callback
            if ( is_callable( $layer['data_callback'] ?? null ) ) {
                $markers = (array) call_user_func( $layer['data_callback'] );

                // Client-side country filtering if needed (markers should have 'country' key)
                if ( $country ) {
                    $markers = array_values( array_filter( $markers, function ( $m ) use ( $country ) {
                        return ( $m['country'] ?? '' ) === $country;
                    } ) );
                }

                return $markers;
            }

            return [];
        }

        return null;
    }

    /* ═══════════════════════════════════════════════════════
       SHORTCODE
    ═══════════════════════════════════════════════════════ */

    /**
     * Shortcode [worldstat_map height="500" layers="true" markers="all" grid="true" country="" style="carto-light"]
     * For pages that don't use the theme's map.
     */
    public function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'height'  => 500,
            'layers'  => 'true',
            'markers' => '',        // '' = none, 'all' = all layers, or comma-separated layer IDs
            'grid'    => 'true',
            'country' => '',
            'lat'     => 20,
            'lng'     => 0,
            'zoom'    => 2,
            'style'   => 'carto-light',
        ], $atts );

        $height      = (int) $atts['height'];
        $show_layers = filter_var( $atts['layers'], FILTER_VALIDATE_BOOLEAN );
        $show_grid   = filter_var( $atts['grid'], FILTER_VALIDATE_BOOLEAN );
        $layers      = $this->extensions->get_layers();

        // Parse marker layers
        $marker_layers = [];
        if ( $atts['markers'] ) {
            $marker_layers = $atts['markers'] === 'all'
                ? [ 'all' ]
                : array_filter( array_map( 'trim', explode( ',', $atts['markers'] ) ) );
        }

        // If markers requested — render Leaflet map with grid & markers
        if ( ! empty( $marker_layers ) || $show_grid ) {
            return WorldStat_UI::map( [
                'lat'           => (float) $atts['lat'],
                'lng'           => (float) $atts['lng'],
                'zoom'          => (int) $atts['zoom'],
                'height'        => $height,
                'grid'          => $show_grid,
                'grid_interval' => 15,
                'grid_labels'   => true,
                'marker_layers' => $marker_layers,
                'country'       => $atts['country'],
                'layer_control' => $show_layers,
                'tile_style'    => $atts['style'],
                'echo'          => false,
            ] );
        }

        // Fallback: SVG map with layer switcher
        ob_start();
        ?>
        <div class="wsp-map-container" style="height:<?php echo $height; ?>px">
            <?php if ( $show_layers && ! empty( $layers ) ) : ?>
                <div class="wsp-map-layer-switcher">
                    <label><?php esc_html_e( 'Слой:', 'flavor-worldstat' ); ?></label>
                    <select id="wsp-layer-select" class="wsp-select">
                        <option value=""><?php esc_html_e( 'Регионы (по умолчанию)', 'flavor-worldstat' ); ?></option>
                        <?php foreach ( $layers as $layer ) : ?>
                            <option value="<?php echo esc_attr( $layer['ext_id'] . '_' . sanitize_title( $layer['label'] ) ); ?>">
                                <?php echo esc_html( $layer['label'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div id="wsp-svg-map" class="wsp-svg-map"></div>
            <div class="wsp-map-tooltip" id="wsp-map-tooltip" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ═══════════════════════════════════════════════════════
       CACHE
    ═══════════════════════════════════════════════════════ */

    /**
     * Flush the map integration cache.
     */
    public static function flush_cache(): void {
        delete_transient( 'wsp_map_integration_data' );
    }
}
