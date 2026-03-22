<?php
/**
 * REST API — all endpoints under worldstat/v1.
 *
 * Endpoints:
 *   GET  /countries             — List all countries
 *   GET  /countries/{code}      — Single country by ISO2
 *   GET  /countries/{code}/data — All extension data for a country
 *   GET  /data/{ext_id}/{code}/{metric} — Specific metric
 *   GET  /metrics               — All available metrics
 *   GET  /compare               — Compare countries
 *   GET  /extensions            — Active extensions list
 *   GET  /tabs/{code}           — Tab list for a country
 *   POST /tabs/{code}/{tab_id}  — Tab HTML content (AJAX)
 *   GET  /map-layers            — Available map layers
 *   GET  /map-layers/{id}/data  — Data for a specific layer
 *   GET  /map-markers           — Available marker layers
 *   GET  /map-markers/{id}      — Marker data for a specific layer
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_REST_API {

    const NAMESPACE = 'worldstat/v1';

    private WorldStat_Extensions $extensions;
    private WorldStat_Tabs $tabs;

    public function __construct( WorldStat_Extensions $extensions, WorldStat_Tabs $tabs ) {
        $this->extensions = $extensions;
        $this->tabs       = $tabs;

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {

        // GET /countries
        register_rest_route( self::NAMESPACE, '/countries', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_countries' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'region'   => [ 'type' => 'string',  'default' => '' ],
                'per_page' => [ 'type' => 'integer', 'default' => 200 ],
                'orderby'  => [ 'type' => 'string',  'default' => 'title' ],
                'order'    => [ 'type' => 'string',  'default' => 'ASC' ],
            ],
        ] );

        // GET /countries/{code}
        register_rest_route( self::NAMESPACE, '/countries/(?P<code>[A-Za-z]{2})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_country' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /countries/{code}/data
        register_rest_route( self::NAMESPACE, '/countries/(?P<code>[A-Za-z]{2})/data', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_country_data' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /data/{ext_id}/{code}/{metric}
        register_rest_route( self::NAMESPACE, '/data/(?P<ext_id>[a-z0-9_-]+)/(?P<code>[A-Za-z]{2})/(?P<metric>[a-z0-9_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_metric' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /metrics
        register_rest_route( self::NAMESPACE, '/metrics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_metrics' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /compare?countries=RU,US&metrics=core.population
        register_rest_route( self::NAMESPACE, '/compare', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'compare' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'countries' => [ 'type' => 'string', 'required' => true ],
                'metrics'   => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        // GET /extensions
        register_rest_route( self::NAMESPACE, '/extensions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_extensions' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /tabs/{code}
        register_rest_route( self::NAMESPACE, '/tabs/(?P<code>[A-Za-z]{2})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_tabs' ],
            'permission_callback' => '__return_true',
        ] );

        // POST /tabs/{code}/{tab_id}
        register_rest_route( self::NAMESPACE, '/tabs/(?P<code>[A-Za-z]{2})/(?P<tab_id>[a-z0-9_-]+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'load_tab_content' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /map-layers
        register_rest_route( self::NAMESPACE, '/map-layers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_map_layers' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /map-layers/{id}/data
        register_rest_route( self::NAMESPACE, '/map-layers/(?P<id>[a-z0-9_-]+)/data', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_map_layer_data' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /map-markers — list registered marker layers
        register_rest_route( self::NAMESPACE, '/map-markers', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_marker_layers' ],
            'permission_callback' => '__return_true',
        ] );

        // GET /map-markers/{id} — get markers data for a layer
        register_rest_route( self::NAMESPACE, '/map-markers/(?P<id>[a-z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_marker_layer_data' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'country' => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        do_action( 'worldstat_rest_routes_registered', self::NAMESPACE );
    }

    /* ═══════════════════════════════════════════════════════
       CALLBACKS
    ═══════════════════════════════════════════════════════ */

    public function get_countries( WP_REST_Request $request ): WP_REST_Response {
        $data = WorldStat_Data::get_countries( [
            'region'   => $request->get_param( 'region' ),
            'per_page' => $request->get_param( 'per_page' ),
            'orderby'  => $request->get_param( 'orderby' ),
            'order'    => $request->get_param( 'order' ),
        ] );

        return new WP_REST_Response( $data, 200 );
    }

    public function get_country( WP_REST_Request $request ): WP_REST_Response {
        $code = strtoupper( $request->get_param( 'code' ) );
        $data = WorldStat_Data::get_country( $code );

        if ( ! $data ) {
            return new WP_REST_Response( [ 'message' => 'Country not found.' ], 404 );
        }

        return new WP_REST_Response( $data, 200 );
    }

    public function get_country_data( WP_REST_Request $request ): WP_REST_Response {
        $code = strtoupper( $request->get_param( 'code' ) );
        $country = WorldStat_Data::get_country( $code );

        if ( ! $country ) {
            return new WP_REST_Response( [ 'message' => 'Country not found.' ], 404 );
        }

        $all_metrics = WorldStat_Data::get_available_metrics();
        $ext_data = [];

        foreach ( $all_metrics as $key => $m ) {
            $val = WorldStat_Data::get( $m['extension'], $code, $m['metric'] );
            $ext_data[ $key ] = $val;
        }

        return new WP_REST_Response( [
            'country' => $country,
            'data'    => $ext_data,
        ], 200 );
    }

    public function get_metric( WP_REST_Request $request ): WP_REST_Response {
        $ext_id = $request->get_param( 'ext_id' );
        $code   = strtoupper( $request->get_param( 'code' ) );
        $metric = $request->get_param( 'metric' );

        $value = WorldStat_Data::get( $ext_id, $code, $metric );

        return new WP_REST_Response( [
            'extension' => $ext_id,
            'country'   => $code,
            'metric'    => $metric,
            'value'     => $value,
        ], 200 );
    }

    public function get_metrics(): WP_REST_Response {
        return new WP_REST_Response( WorldStat_Data::get_available_metrics(), 200 );
    }

    public function compare( WP_REST_Request $request ): WP_REST_Response {
        $countries = array_filter( array_map( 'trim', explode( ',', $request->get_param( 'countries' ) ) ) );
        $metrics   = array_filter( array_map( 'trim', explode( ',', $request->get_param( 'metrics' ) ) ) );

        $data = WorldStat_Data::compare( [
            'countries' => $countries,
            'metrics'   => $metrics,
        ] );

        return new WP_REST_Response( $data, 200 );
    }

    public function get_extensions(): WP_REST_Response {
        $exts = $this->extensions->get_all();
        $out  = [];

        foreach ( $exts as $id => $ext ) {
            $out[] = [
                'id'          => $id,
                'name'        => $ext['name'],
                'version'     => $ext['version'],
                'author'      => $ext['author'],
                'description' => $ext['description'],
                'icon'        => $ext['icon'],
                'metrics'     => count( array_filter(
                    $this->extensions->get_all_metrics(),
                    fn( $m ) => $m['extension'] === $id
                ) ),
            ];
        }

        return new WP_REST_Response( $out, 200 );
    }

    public function get_tabs( WP_REST_Request $request ): WP_REST_Response {
        $code = strtoupper( $request->get_param( 'code' ) );
        $tabs = $this->tabs->get_tabs_for_country( $code );
        return new WP_REST_Response( $tabs, 200 );
    }

    public function load_tab_content( WP_REST_Request $request ): WP_REST_Response {
        $code   = strtoupper( $request->get_param( 'code' ) );
        $tab_id = $request->get_param( 'tab_id' );

        $ext_tab = $this->extensions->get_tab( $tab_id );

        if ( ! $ext_tab || ! is_callable( $ext_tab['callback'] ) ) {
            return new WP_REST_Response( [ 'message' => 'Tab not found.' ], 404 );
        }

        ob_start();
        call_user_func( $ext_tab['callback'], $code );
        $html = ob_get_clean();

        return new WP_REST_Response( [ 'html' => $html ], 200 );
    }

    public function get_map_layers(): WP_REST_Response {
        $layers = $this->extensions->get_layers();
        $out = [];

        foreach ( $layers as $layer ) {
            $out[] = [
                'id'         => $layer['ext_id'] . '_' . sanitize_title( $layer['label'] ),
                'label'      => $layer['label'],
                'ext_id'     => $layer['ext_id'],
                'type'       => $layer['type'],
                'colorScale' => $layer['color_scale'],
            ];
        }

        return new WP_REST_Response( $out, 200 );
    }

    public function get_map_layer_data( WP_REST_Request $request ): WP_REST_Response {
        $layer_id = $request->get_param( 'id' );
        $map      = worldstat_platform()->map;
        $data     = $map->get_layer_data( $layer_id );

        return new WP_REST_Response( $data, 200 );
    }

    /* ═══════════════════════════════════════════════════════
       MAP MARKERS
    ═══════════════════════════════════════════════════════ */

    /**
     * GET /map-markers — list available marker layers.
     */
    public function get_marker_layers(): WP_REST_Response {
        $layers = $this->extensions->get_marker_layers();
        $out    = [];

        foreach ( $layers as $i => $layer ) {
            $out[] = [
                'id'     => $layer['ext_id'] . '_markers_' . $i,
                'ext_id' => $layer['ext_id'],
                'label'  => $layer['label'],
                'icon'   => $layer['icon'],
                'color'  => $layer['color'],
                'radius' => $layer['radius'],
            ];
        }

        return new WP_REST_Response( $out, 200 );
    }

    /**
     * GET /map-markers/{id} — marker data for a specific layer.
     *
     * Optional query param: ?country=RU — filter markers by country.
     */
    public function get_marker_layer_data( WP_REST_Request $request ): WP_REST_Response {
        $layer_id = $request->get_param( 'id' );
        $country  = strtoupper( $request->get_param( 'country' ) ?: '' );
        $map      = worldstat_platform()->map;
        $data     = $map->get_marker_data( $layer_id, $country );

        if ( null === $data ) {
            return new WP_REST_Response( [ 'message' => 'Marker layer not found.' ], 404 );
        }

        return new WP_REST_Response( $data, 200 );
    }
}
