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

        // Extension tabs
        $ext_tabs = worldstat_platform()->extensions->get_tabs();
        foreach ( $ext_tabs as $ext_id => $config ) {
            $tabs[] = [
                'id'       => $ext_id,
                'title'    => $config['title'],
                'icon'     => $config['icon'],
                'priority' => $config['priority'],
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
        [ 'label' => 'Население',       'value' => number_format( $meta['population'] ?? 0, 0, '', ' ' ), 'icon' => 'groups' ],
        [ 'label' => 'Площадь',          'value' => number_format( $meta['area_km2'] ?? 0, 0, '', ' ' ) . ' км²', 'icon' => 'editor-expand' ],
        [ 'label' => 'Столица',          'value' => $meta['capital_ru'] ?? '', 'icon' => 'admin-home' ],
        [ 'label' => 'Субрегион',        'value' => $meta['subregion'] ?? '',  'icon' => 'admin-site-alt3' ],
    ] );

    // Country content
    echo '<div class="wsp-country-content">';
    the_content();
    echo '</div>';

    /**
     * Hook for extensions to add content to the Overview tab.
     */
    do_action( 'worldstat_country_after_content', $post_id, $meta['iso_alpha2'] ?? '', $meta );

    // ────────────────────────────────────────────────
    // Добавляем блок «Эргономичность» как дополнение
    // ────────────────────────────────────────────────
    ?>
         <div class="ergo-layout">
            <!-- Вертикальное меню слева -->
            <div class="ergo-sidebar">
                <button class="ergo-vertical-btn active" data-target="roads">
                    <i class="fas fa-road"></i> Дорожная сеть
                </button>
                <button class="ergo-vertical-btn" data-target="urban">
                    <i class="fas fa-building"></i> Городские зоны
                </button>
                <button class="ergo-vertical-btn" data-target="green">
                    <i class="fas fa-tree"></i> Зелёные зоны
                </button>
                <button class="ergo-vertical-btn" data-target="biodiversity">
                    <i class="fas fa-paw"></i> Биоразнообразие
                </button>
                <button class="ergo-vertical-btn" data-target="industry">
                    <i class="fas fa-industry"></i> Промышленность
                </button>
                <button class="ergo-vertical-btn" data-target="tech">
                    <i class="fas fa-microchip"></i> Технологии
                </button>
            </div>

            <!-- Правая панель с информацией и графиками -->
            <div class="ergo-content" id="ergoContent">
                <!-- Контент подгружается через JavaScript -->
            </div>
        </div>
    <?php
    // ────────────────────────────────────────────────

    echo '</div>'; // .wsp-tab-panel
}

    /**
     * AJAX handler to load extension tab content.
     */
    public function ajax_load_tab(): void {
        $tab_id = sanitize_text_field( $_POST['tab'] ?? '' );
        $iso2   = sanitize_text_field( $_POST['iso2'] ?? '' );

        if ( ! $tab_id || ! $iso2 ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        $ext_tab = worldstat_platform()->extensions->get_tab( $tab_id );

        if ( ! $ext_tab || ! is_callable( $ext_tab['callback'] ) ) {
            wp_send_json_error( 'Tab not found or not callable.' );
        }

        ob_start();
        call_user_func( $ext_tab['callback'], $iso2 );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }
}
