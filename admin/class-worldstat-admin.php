<?php
/**
 * Admin panel — menus, dashboard, extensions manager, settings.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Admin {

    private WorldStat_Extensions $extensions;

    public function __construct( WorldStat_Extensions $extensions ) {
        $this->extensions = $extensions;

        // Priority 5 — register BEFORE extensions (default priority 10)
        add_action( 'admin_menu', [ $this, 'register_menus' ], 5 );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Custom columns for country list
        add_filter( 'manage_' . WorldStat_Country_CPT::SLUG . '_posts_columns',  [ $this, 'columns' ] );
        add_action( 'manage_' . WorldStat_Country_CPT::SLUG . '_posts_custom_column', [ $this, 'column_content' ], 10, 2 );
    }

    public function register_menus(): void {
        // Top-level menu
        add_menu_page(
            'World Statistics',
            'World Statistics',
            'manage_options',
            'worldstat',
            [ $this, 'page_dashboard' ],
            'dashicons-admin-site-alt3',
            30
        );

        // Dashboard sub-page
        add_submenu_page( 'worldstat', 'Dashboard', 'Dashboard', 'manage_options', 'worldstat', [ $this, 'page_dashboard' ] );

        // Countries
        add_submenu_page( 'worldstat', 'Страны', 'Страны', 'manage_options', 'edit.php?post_type=' . WorldStat_Country_CPT::SLUG );

        // Extensions Manager
        add_submenu_page( 'worldstat', 'Расширения', 'Расширения', 'manage_options', 'worldstat-extensions', [ $this, 'page_extensions' ] );

        // Settings
        add_submenu_page( 'worldstat', 'Настройки', 'Настройки', 'manage_options', 'worldstat-settings', [ $this, 'page_settings' ] );
    }

    public function register_settings(): void {
        register_setting( 'wsp_settings', 'wsp_map_on_front', [ 'type' => 'boolean', 'default' => true ] );
        register_setting( 'wsp_settings', 'wsp_countries_per_page', [ 'type' => 'integer', 'default' => 200 ] );
        register_setting( 'wsp_settings', 'wsp_enable_rest_public', [ 'type' => 'boolean', 'default' => true ] );
    }

    /* ═══════════════════════════════════════════════════════
       PAGES
    ═══════════════════════════════════════════════════════ */

    public function page_dashboard(): void {
        $exts    = $this->extensions->get_all();
        $metrics = $this->extensions->get_all_metrics();
        $count   = wp_count_posts( WorldStat_Country_CPT::SLUG );

        // Detect duplicates of analysis page by slug prefix (analysis-data*).
        global $wpdb;
        $slug = 'analysis-data';
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page'
                   AND post_name LIKE %s",
                $slug . '%'
            )
        );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        $analysis_dups_count = max( 0, count( $ids ) - 1 );
        $analysis_dups_total = count( $ids );

        include WSP_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_extensions(): void {
        $exts   = $this->extensions->get_all();
        $layers = $this->extensions->get_layers();
        $tabs   = $this->extensions->get_tabs();
        include WSP_PLUGIN_DIR . 'admin/views/extensions.php';
    }

    public function page_settings(): void {
        include WSP_PLUGIN_DIR . 'admin/views/settings.php';
    }

    /* ═══════════════════════════════════════════════════════
       CUSTOM COLUMNS
    ═══════════════════════════════════════════════════════ */

    public function columns( array $cols ): array {
        $new = [];
        $new['cb']         = $cols['cb'];
        $new['wsp_flag']   = '🏳';
        $new['title']      = $cols['title'];
        $new['wsp_iso']    = 'ISO';
        $new['wsp_capital']= 'Столица';
        $new['wsp_pop']    = 'Население';
        $new['wsp_area']   = 'Площадь';
        $new['wsp_region'] = 'Регион';
        $new['date']       = $cols['date'] ?? 'Дата';
        return $new;
    }

    public function column_content( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'wsp_flag':
                echo esc_html( get_post_meta( $post_id, 'wsp_flag', true ) );
                break;
            case 'wsp_iso':
                echo esc_html( get_post_meta( $post_id, 'wsp_iso_alpha2', true ) );
                echo ' / ';
                echo esc_html( get_post_meta( $post_id, 'wsp_iso_alpha3', true ) );
                break;
            case 'wsp_capital':
                echo esc_html( get_post_meta( $post_id, 'wsp_capital_ru', true ) );
                break;
            case 'wsp_pop':
                echo number_format( (int) get_post_meta( $post_id, 'wsp_population', true ), 0, '', ' ' );
                break;
            case 'wsp_area':
                echo number_format( (int) get_post_meta( $post_id, 'wsp_area_km2', true ), 0, '', ' ' ) . ' км²';
                break;
            case 'wsp_region':
                $terms = wp_get_post_terms( $post_id, WorldStat_Taxonomies::REGION );
                echo ( $terms && ! is_wp_error( $terms ) ) ? esc_html( $terms[0]->name ) : '—';
                break;
        }
    }
}
