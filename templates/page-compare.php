<?php
/**
 * /compare page — Country comparison tool.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$preselected = array_filter( array_map( 'sanitize_text_field', explode( ',', $_GET['c'] ?? '' ) ) );
$all_countries = WorldStat_Data::get_countries();
$metrics = WorldStat_Data::get_available_metrics();
?>

<div class="wsp-compare-page">
    <div class="wsp-container">

        <h1 class="wsp-page-title"><?php esc_html_e( 'Сравнение стран', 'flavor-worldstat' ); ?></h1>

        <div class="wsp-compare-selector">
            <form method="get" class="wsp-compare-form">
                <label><?php esc_html_e( 'Выберите страны (до 5):', 'flavor-worldstat' ); ?></label>
                <div class="wsp-compare-inputs" id="wsp-compare-inputs">
                    <?php
                    $slots = max( 2, count( $preselected ) );
                    for ( $i = 0; $i < $slots; $i++ ) :
                        $sel = $preselected[ $i ] ?? '';
                    ?>
                        <select name="country[]" class="wsp-select wsp-compare-select">
                            <option value=""><?php esc_html_e( '— Выберите —', 'flavor-worldstat' ); ?></option>
                            <?php foreach ( $all_countries as $c ) : ?>
                                <option value="<?php echo esc_attr( $c['iso2'] ); ?>" <?php selected( $sel, $c['iso2'] ); ?>>
                                    <?php echo esc_html( $c['flag'] . ' ' . $c['title'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endfor; ?>
                </div>
                <button type="button" class="wsp-btn wsp-btn-sm" id="wsp-compare-add"><?php esc_html_e( '+ Добавить', 'flavor-worldstat' ); ?></button>
                <button type="submit" class="wsp-btn"><?php esc_html_e( 'Сравнить', 'flavor-worldstat' ); ?></button>
            </form>
        </div>

        <?php if ( count( $preselected ) >= 2 ) :
            // Render comparison
            WorldStat_UI::comparison( [ 'countries' => $preselected, 'echo' => true ] );

            // Extension metrics table
            if ( ! empty( $metrics ) ) :
                $headers = [ 'Метрика' ];
                foreach ( $preselected as $code ) {
                    $c = WorldStat_Data::get_country( $code );
                    $headers[] = $c ? ( $c['flag'] . ' ' . ( $c['name_short_ru'] ?: $c['title'] ) ) : $code;
                }

                $rows = [];
                foreach ( $metrics as $key => $m ) {
                    $row = [ $m['label'] . ' (' . $m['unit'] . ')' ];
                    foreach ( $preselected as $code ) {
                        $parts = explode( '.', $key, 2 );
                        $val   = count( $parts ) === 2 ? WorldStat_Data::get( $parts[0], $code, $parts[1] ) : '';
                        $row[] = $val !== null ? $val : '—';
                    }
                    $rows[] = $row;
                }

                WorldStat_UI::table( [
                    'headers'    => $headers,
                    'rows'       => $rows,
                    'sortable'   => true,
                    'searchable' => true,
                ] );
            endif;
        endif;
        ?>

    </div>
</div>

<?php get_footer(); ?>
