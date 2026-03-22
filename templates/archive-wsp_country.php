<?php
/**
 * Countries archive — grid with filters.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$region_filter = sanitize_text_field( $_GET['region'] ?? '' );
$search_query  = sanitize_text_field( $_GET['q'] ?? '' );

$args = [
    'post_type'      => WorldStat_Country_CPT::SLUG,
    'posts_per_page' => 200,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'post_status'    => 'publish',
];

if ( $region_filter ) {
    $args['tax_query'] = [ [
        'taxonomy' => WorldStat_Taxonomies::REGION,
        'field'    => 'slug',
        'terms'    => $region_filter,
    ] ];
}

if ( $search_query ) {
    $args['s'] = $search_query;
}

$countries = new WP_Query( $args );
$regions   = get_terms( [ 'taxonomy' => WorldStat_Taxonomies::REGION, 'hide_empty' => true ] );
?>

<div class="wsp-archive-page">
    <div class="wsp-container">

        <h1 class="wsp-archive-title"><?php esc_html_e( 'Страны мира', 'flavor-worldstat' ); ?></h1>

        <!-- Filters -->
        <div class="wsp-archive-filters">
            <form method="get" class="wsp-filter-form">
                <div class="wsp-filter-group">
                    <input type="text" name="q" value="<?php echo esc_attr( $search_query ); ?>"
                           placeholder="<?php esc_attr_e( 'Поиск страны...', 'flavor-worldstat' ); ?>"
                           class="wsp-search-input" />
                </div>
                <div class="wsp-filter-group">
                    <select name="region" class="wsp-select">
                        <option value=""><?php esc_html_e( 'Все регионы', 'flavor-worldstat' ); ?></option>
                        <?php if ( ! is_wp_error( $regions ) ) : foreach ( $regions as $r ) : ?>
                            <option value="<?php echo esc_attr( $r->slug ); ?>" <?php selected( $region_filter, $r->slug ); ?>>
                                <?php echo esc_html( $r->name ); ?> (<?php echo (int) $r->count; ?>)
                            </option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>
                <button type="submit" class="wsp-btn"><?php esc_html_e( 'Фильтр', 'flavor-worldstat' ); ?></button>
            </form>
        </div>

        <!-- Countries Grid -->
        <?php if ( $countries->have_posts() ) : ?>
            <div class="wsp-countries-grid">
            <?php while ( $countries->have_posts() ) : $countries->the_post();
                $iso2  = get_post_meta( get_the_ID(), 'wsp_iso_alpha2', true );
                $flag  = get_post_meta( get_the_ID(), 'wsp_flag', true );
                $pop   = (int) get_post_meta( get_the_ID(), 'wsp_population', true );
                $cap   = get_post_meta( get_the_ID(), 'wsp_capital_ru', true );
            ?>
                <a href="<?php the_permalink(); ?>" class="wsp-country-card" data-iso2="<?php echo esc_attr( $iso2 ); ?>">
                    <span class="wsp-country-card-flag"><?php echo esc_html( $flag ); ?></span>
                    <h3 class="wsp-country-card-name"><?php the_title(); ?></h3>
                    <span class="wsp-country-card-capital"><?php echo esc_html( $cap ); ?></span>
                    <span class="wsp-country-card-pop"><?php echo number_format( $pop, 0, '', ' ' ); ?></span>
                </a>
            <?php endwhile; ?>
            </div>
        <?php else : ?>
            <p class="wsp-no-results"><?php esc_html_e( 'Стран не найдено.', 'flavor-worldstat' ); ?></p>
        <?php endif; wp_reset_postdata(); ?>

    </div>
</div>

<?php get_footer(); ?>
