<?php
/**
 * Country page tab system — core "Overview" tab + extension tabs loaded via AJAX.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Tabs {

    public function __construct() {
        add_action( 'wp_ajax_worldstat_load_tab',        [ $this, 'ajax_load_tab' ] );
        add_action( 'wp_ajax_nopriv_worldstat_load_tab', [ $this, 'ajax_load_tab' ] );
    }

    /**
     * Безопасное целое для карточек обзора (избегает TypeError в number_format).
     *
     * @param mixed $v
     */
    private static function overview_int( $v ): int {
        return is_numeric( $v ) ? (int) round( (float) $v ) : 0;
    }

    /**
     * Безопасная строка для карточек / esc_html.
     *
     * @param mixed $v
     */
    private static function overview_text( $v ): string {
        if ( null === $v || is_bool( $v ) ) {
            return '';
        }
        return is_scalar( $v ) ? trim( (string) $v ) : '';
    }

    /**
     * Get all tabs for a country, sorted by priority.
     *
     * @return array [ [ 'id', 'title', 'icon', 'priority', 'is_core' ], ... ]
     */
    public function get_tabs_for_country( string $iso2 ): array {
        $tabs = [];

        // Core "Overview" tab — always first
        $tabs[] = [
            'id'       => 'overview',
            'title'    => __( 'Обзор', 'flavor-worldstat' ),
            'icon'     => 'dashicons-info',
            'priority' => 0,
            'is_core'  => true,
        ];

        // Extension tabs (несколько вкладок на одно расширение, напр. ergonomics + compare).
        $ext_tabs = worldstat_platform()->extensions->get_tabs();
        $seen     = [];
        foreach ( $ext_tabs as $key => $config ) {
            if ( ! is_array( $config ) ) {
                continue;
            }
            $tab_id = isset( $config['id'] ) ? sanitize_key( (string) $config['id'] ) : sanitize_key( (string) $key );
            if ( $tab_id === '' || isset( $seen[ $tab_id ] ) ) {
                continue;
            }
            $seen[ $tab_id ] = true;
            $tabs[]          = [
                'id'       => $tab_id,
                'title'    => $config['title'] ?? $tab_id,
                'icon'     => $config['icon'] ?? 'dashicons-admin-plugins',
                'priority' => (int) ( $config['priority'] ?? 50 ),
                'is_core'  => false,
            ];
        }

        // Sort by priority
        usort( $tabs, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );

        return apply_filters( 'worldstat_country_tabs', $tabs, $iso2 );
    }

    /**
     * Render the tab bar HTML.
     */
    public function render_tab_bar( string $iso2, array $tabs ): void {
        echo '<div class="wsp-tabs" data-iso2="' . esc_attr( $iso2 ) . '">';
        echo '<nav class="wsp-tab-nav" role="tablist">';

        foreach ( $tabs as $i => $tab ) {
            $active = $i === 0 ? ' wsp-tab-active' : '';
            printf(
                '<button class="wsp-tab-btn%s" data-tab="%s" role="tab" aria-selected="%s">'
                . '<span class="dashicons %s"></span> %s</button>',
                $active,
                esc_attr( $tab['id'] ),
                $i === 0 ? 'true' : 'false',
                esc_attr( $tab['icon'] ),
                esc_html( $tab['title'] )
            );
        }

        echo '</nav>';
        echo '<div class="wsp-tab-panels">';
    }

    /**
     * Close tab panels container.
     */
    public function close_tab_panels(): void {
        echo '</div></div>'; // .wsp-tab-panels, .wsp-tabs
    }

    /**
     * Render the core "Overview" tab content.
     */
    public function render_overview_tab( int $post_id, array $meta ): void {
    echo '<div class="wsp-tab-panel wsp-tab-panel-active" data-tab="overview">';

    // Stats grid
    WorldStat_UI::stats_grid( [
        [ 'label' => 'Население', 'value' => number_format( self::overview_int( $meta['population'] ?? null ), 0, '', ' ' ), 'icon' => 'groups' ],
        [ 'label' => 'Площадь', 'value' => number_format( self::overview_int( $meta['area_km2'] ?? null ), 0, '', ' ' ) . ' км²', 'icon' => 'editor-expand' ],
        [ 'label' => 'Столица', 'value' => self::overview_text( $meta['capital_ru'] ?? null ), 'icon' => 'admin-home' ],
        [ 'label' => 'Субрегион', 'value' => self::overview_text( $meta['subregion'] ?? null ), 'icon' => 'admin-site-alt3' ],
    ] );

    // Country content
    echo '<div class="wsp-country-content">';
    the_content();
    echo '</div>';

    /**
     * Hook for extensions to add content to the Overview tab.
     */
    do_action( 'worldstat_country_after_content', $post_id, $meta['iso_alpha2'] ?? '', $meta );

    echo '</div>'; // .wsp-tab-panel
}

    /**
     * AJAX handler to load extension tab content.
     */
    public function ajax_load_tab(): void {
        $tab_id = sanitize_text_field( $_POST['tab'] ?? '' );
        $iso2   = sanitize_text_field( $_POST['iso2'] ?? '' );

        if ( ! $tab_id || ! $iso2 ) {
            if ( function_exists( 'worldstat_discard_ajax_output_buffer' ) ) {
                worldstat_discard_ajax_output_buffer();
            }
            wp_send_json_error( 'Missing parameters.' );
        }

        $ext_tab = worldstat_platform()->extensions->get_tab( $tab_id );

        if ( ! $ext_tab || ! is_callable( $ext_tab['callback'] ) ) {
            if ( function_exists( 'worldstat_discard_ajax_output_buffer' ) ) {
                worldstat_discard_ajax_output_buffer();
            }
            wp_send_json_error( 'Tab not found or not callable.' );
        }

        ob_start();
        call_user_func( $ext_tab['callback'], $iso2 );
        $html = ob_get_clean();

        if ( function_exists( 'worldstat_discard_ajax_output_buffer' ) ) {
            worldstat_discard_ajax_output_buffer();
        }
        wp_send_json_success( [ 'html' => $html ] );
    }
}
