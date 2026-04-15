<?php
/** Country comparison widget. Variables: $opts (array). */
if ( ! defined( 'ABSPATH' ) ) exit;
$comp_id = 'wsp-comparison-' . wp_unique_id();
$countries = $opts['countries'] ?? [];
?>
<div class="wsp-comparison-wrap" id="<?php echo esc_attr( $comp_id ); ?>">
    <div style="text-align:right;margin-bottom:20px;">
        <label for="compare-global-year" style="font-weight:600;margin-right:8px;"><?php esc_html_e( 'Год:', 'flavor-worldstat' ); ?></label>
        <select id="compare-global-year" class="wsp-select" style="padding:6px 10px;font-size:14px;"></select>
    </div>
    <div class="wsp-comparison-cards">
    <?php foreach ( $countries as $iso2 ) :
        $c = WorldStat_Data::get_country( $iso2 );
        if ( ! $c ) continue;
    ?>
        <div class="wsp-comparison-card" data-iso2="<?php echo esc_attr( strtoupper( (string) $iso2 ) ); ?>">
            <div class="wsp-comparison-flag"><?php echo esc_html( $c['flag'] ?? '' ); ?></div>
            <h4 class="wsp-comparison-name"><?php echo esc_html( $c['name_short_ru'] ?: $c['title'] ); ?></h4>
            <ul class="wsp-comparison-metrics">
                <li><span>Население</span><strong><?php echo number_format( $c['population'] ?? 0, 0, '', ' ' ); ?></strong></li>
                <li><span>Площадь</span><strong><?php echo number_format( $c['area_km2'] ?? 0, 0, '', ' ' ); ?> км²</strong></li>
                <li><span>Столица</span><strong><?php echo esc_html( $c['capital_ru'] ?? '' ); ?></strong></li>
                <li><span>Леса (% от территории)</span><strong class="csv-forest">—</strong></li>
                <li><span>Население крупнейшего города</span><strong class="csv-largest-city">—</strong></li>
                <li><span>Урбанизированная площадь</span><strong class="csv-urban-area">—</strong></li>
                <?php
                // Extra metric from extension
                if ( ! empty( $opts['metric'] ) ) {
                    $parts = explode( '.', $opts['metric'], 2 );
                    if ( count( $parts ) === 2 ) {
                        $val = WorldStat_Data::get( $parts[0], $iso2, $parts[1] );
                        if ( $val !== null ) {
                            echo '<li><span>' . esc_html( $opts['metric'] ) . '</span><strong>' . esc_html( $val ) . '</strong></li>';
                        }
                    }
                }
                ?>
            </ul>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php
if ( class_exists( 'WorldStat_Data' ) && function_exists( 'worldstat_platform' ) ) {
    worldstat_platform()->data->render_compare_csv_js_for_cards( $countries );
}
?>
