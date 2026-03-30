<?php
/**
 * Platform Core — singleton, boots all components.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class WorldStat_Core {

    private static ?WorldStat_Core $instance = null;

    /* Component references */
    public WorldStat_Country_CPT  $cpt;
    public WorldStat_Taxonomies   $taxonomies;
    public WorldStat_Meta         $meta;
    public WorldStat_Extensions   $extensions;
    public WorldStat_Data         $data;
    public WorldStat_Tabs         $tabs;
    public WorldStat_UI           $ui;
    public WorldStat_REST_API     $rest_api;
    public WorldStat_Templates    $templates;
    public WorldStat_Map          $map;
    public WorldStat_Pages        $pages;
    public ?WorldStat_Admin       $admin = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();

            /*
             * Fire init AFTER the instance is assigned so that extension
             * callbacks that call worldstat_platform() get the existing
             * instance instead of triggering infinite recursion.
             */
            do_action( 'worldstat_init', self::$instance );
            do_action( 'worldstat_loaded', self::$instance );
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }

    private function init_components(): void {
        $this->cpt        = new WorldStat_Country_CPT();
        $this->taxonomies = new WorldStat_Taxonomies();
        $this->meta       = new WorldStat_Meta();
        $this->extensions = new WorldStat_Extensions();
        $this->tabs       = new WorldStat_Tabs();
        $this->data       = new WorldStat_Data( $this->extensions );
        $this->ui         = new WorldStat_UI();
        $this->rest_api   = new WorldStat_REST_API( $this->extensions, $this->tabs );
        $this->templates  = new WorldStat_Templates();
        $this->map        = new WorldStat_Map( $this->extensions );
        $this->pages      = new WorldStat_Pages();

        if ( is_admin() ) {
            $this->admin = new WorldStat_Admin( $this->extensions );
        }
    }

    private function register_hooks(): void {
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_public_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'init',                  [ $this, 'load_textdomain' ] );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'flavor-worldstat', false, dirname( WSP_PLUGIN_BASENAME ) . '/languages' );
    }

    public function enqueue_public_assets(): void {
        if ( ! $this->is_wsp_page() ) return;

        wp_enqueue_style( 'worldstat-platform', WSP_ASSETS_URL . 'css/platform.css', [], WSP_VERSION );
        wp_enqueue_style( 'worldstat-components', WSP_ASSETS_URL . 'css/components.css', [], WSP_VERSION );
        wp_enqueue_script( 'worldstat-platform', WSP_ASSETS_URL . 'js/platform.js', [ 'jquery' ], WSP_VERSION, true );

        wp_localize_script( 'worldstat-platform', 'worldstatPlatform', [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'restUrl'  => rest_url( 'worldstat/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'analysisUrl' => WorldStat_Pages::get_page_url( 'analysis' ),
            'version'  => WSP_VERSION,
        ] );

        // Pre-enqueue Chart.js on platform pages that can render charts via AJAX.
        $analysis_page_id = WorldStat_Pages::get_page_id( 'analysis' );
        $is_analysis = ( $analysis_page_id && is_page( $analysis_page_id ) );
        if ( ! $is_analysis && is_page() ) {
            $slug = (string) get_post_field( 'post_name', get_queried_object_id() );
            $is_analysis = ( $slug === 'analysis-data' );
        }
        if ( $is_analysis ) {
            wp_enqueue_script( 'chartjs', WSP_ASSETS_URL . 'vendor/chartjs/chart.umd.min.js', [], '4.4', true );
            wp_enqueue_script( 'worldstat-chart-builder', WSP_ASSETS_URL . 'js/chart-builder.js', [ 'chartjs' ], WSP_VERSION, true );
        }

        // Pre-enqueue Leaflet & TopoJSON on country pages so AJAX-loaded tabs can use them
        if ( is_singular( WorldStat_Country_CPT::SLUG ) ) {
            // Chart.js + builder (local assets for offline support)
            wp_enqueue_script( 'chartjs', WSP_ASSETS_URL . 'vendor/chartjs/chart.umd.min.js', [], '4.4', true );
            wp_enqueue_script( 'worldstat-chart-builder', WSP_ASSETS_URL . 'js/chart-builder.js', [ 'chartjs' ], WSP_VERSION, true );

            // Leaflet (local assets for offline support)
            wp_enqueue_style( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.css', [], '1.9' );
            wp_enqueue_script( 'leaflet', WSP_ASSETS_URL . 'vendor/leaflet/leaflet.js', [], '1.9', true );

            // TopoJSON client (needed for "countries" style map in AJAX tabs)
            wp_enqueue_script( 'topojson-client', get_template_directory_uri() . '/assets/vendor/topojson/topojson-client.min.js', [], '3.1.0', true );
        }
    }

    public function enqueue_admin_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        $is_wsp = strpos( $hook, 'worldstat' ) !== false
               || ( $screen->post_type ?? '' ) === WorldStat_Country_CPT::SLUG;

        if ( $is_wsp ) {
            wp_enqueue_style( 'worldstat-admin', WSP_ASSETS_URL . 'admin/admin.css', [], WSP_VERSION );
            wp_enqueue_script( 'worldstat-admin', WSP_ASSETS_URL . 'admin/admin.js', [ 'jquery' ], WSP_VERSION, true );
        }
    }

    public function is_wsp_page(): bool {
        if ( is_singular( WorldStat_Country_CPT::SLUG ) ) return true;
        if ( is_post_type_archive( WorldStat_Country_CPT::SLUG ) ) return true;

        // Extension post types (e.g., wsp_city)
        if ( is_singular() ) {
            $ext_types = (array) apply_filters( 'worldstat_extension_post_types', [] );
            if ( in_array( get_post_type(), $ext_types, true ) ) return true;
        }

        $page_ids = array_filter( [
            WorldStat_Pages::get_page_id( 'countries' ),
            WorldStat_Pages::get_page_id( 'compare' ),
            WorldStat_Pages::get_page_id( 'data-themes' ),
            WorldStat_Pages::get_page_id( 'analysis' ),
        ] );

        if ( is_page( $page_ids ) ) return true;

        // Fallback: analysis page by slug, even if option IDs are out of sync.
        if ( is_page() ) {
            $slug = (string) get_post_field( 'post_name', get_queried_object_id() );
            if ( $slug === 'analysis-data' ) return true;
        }

        return (bool) apply_filters( 'worldstat_is_platform_page', false );
    }

    /**
     * Get countries data from JSON file.
     */
    public function get_countries_data(): array {
        $file = WSP_DATA_DIR . 'countries.json';
        if ( ! file_exists( $file ) ) return [];

        $json = file_get_contents( $file );
        $data = json_decode( $json, true );

        return is_array( $data ) ? $data : [];
    }

    private function __clone() {}
    public function __wakeup() { throw new \Exception( 'Cannot unserialize singleton.' ); }
}
