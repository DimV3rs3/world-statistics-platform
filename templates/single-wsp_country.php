<?php
/**
 * Single Country page with tab system.
 *
 * @package WorldStatPlatform
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();   // ← это должно быть САМЫМ ПЕРВЫМ после if (!defined)
    

$platform = worldstat_platform();
$post_id  = get_the_ID();
$meta     = WorldStat_Meta::get_all_fields( $post_id );
$iso2     = $meta['iso_alpha2'] ?? '';

// Taxonomy data
$regions = wp_get_post_terms( $post_id, WorldStat_Taxonomies::REGION );
$subs    = wp_get_post_terms( $post_id, WorldStat_Taxonomies::SUBREGION );
$income  = wp_get_post_terms( $post_id, WorldStat_Taxonomies::INCOME_GROUP );

$meta['region']       = ( $regions && ! is_wp_error( $regions ) ) ? $regions[0]->name : '';
$meta['subregion']    = ( $subs && ! is_wp_error( $subs ) )       ? $subs[0]->name : '';
$meta['income_group'] = ( $income && ! is_wp_error( $income ) )   ? $income[0]->name : '';

$tabs = $platform->tabs->get_tabs_for_country( $iso2 );

do_action( 'worldstat_before_country', $post_id, $iso2, $meta );
?>

<div class="wsp-country-page">

    <!-- Hero Header -->
    <header class="wsp-country-hero">
        <div class="wsp-container">
            <div class="wsp-country-hero-inner">
                <span class="wsp-country-flag"><?php echo esc_html( $meta['flag'] ?? '' ); ?></span>
                <div class="wsp-country-hero-text">
                    <h1 class="wsp-country-title"><?php the_title(); ?></h1>
                    <p class="wsp-country-official"><?php echo esc_html( $meta['name_official_ru'] ?: $meta['name_official'] ?? '' ); ?></p>
                    <span class="wsp-country-code"><?php echo esc_html( $iso2 ); ?> / <?php echo esc_html( $meta['iso_alpha3'] ?? '' ); ?></span>
                    <?php if ( $meta['region'] ) : ?>
                        <span class="wsp-country-region"><?php echo esc_html( $meta['region'] ); ?> → <?php echo esc_html( $meta['subregion'] ?? '' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Tab System -->
    <div class="wsp-container">
        <?php
        $platform->tabs->render_tab_bar( $iso2, $tabs );

        // Core overview tab (active by default)
        $platform->tabs->render_overview_tab( $post_id, $meta );

        // Placeholders for extension tabs (loaded via AJAX)
        foreach ( $tabs as $tab ) {
            if ( $tab['is_core'] ) continue;
            echo '<div class="wsp-tab-panel" data-tab="' . esc_attr( $tab['id'] ) . '">';
            echo '<div class="wsp-tab-loading"><span class="spinner is-active"></span> Загрузка...</div>';
            echo '</div>';
        }

        $platform->tabs->close_tab_panels();
        ?>
    </div>

    <?php do_action( 'worldstat_country_sidebar', $post_id, $iso2, $meta ); ?>

</div><!-- .wsp-country-page -->

<?php
do_action( 'worldstat_after_country', $post_id, $iso2, $meta );

// ────────────────────────────────────────────────────────────────
// Подключение наших стилей и скриптов ТОЛЬКО на страницах стран
// ────────────────────────────────────────────────────────────────
if ( is_singular( 'wsp_country' ) ) {
    $plugin_root = content_url( 'plugins/world-statistics-platform' );

    ?>
    <link rel="stylesheet" href="<?php echo esc_url( $plugin_root . '/assets/css/ergonomics.min.css' ); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="<?php echo esc_url( $plugin_root . '/assets/js/ergonomics.min.js' ); ?>" defer></script>
    <?php
}

get_footer();