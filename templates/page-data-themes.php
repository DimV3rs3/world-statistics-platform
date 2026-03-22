<?php
/**
 * /data-themes page — lists active extensions and their data coverage.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$extensions = worldstat_platform()->extensions->get_all();
$metrics    = WorldStat_Data::get_available_metrics();
?>

<div class="wsp-themes-page">
    <div class="wsp-container">

        <h1 class="wsp-page-title"><?php esc_html_e( 'Тематические данные', 'flavor-worldstat' ); ?></h1>
        <p class="wsp-page-desc"><?php esc_html_e( 'Платформа мировой статистики включает следующие тематические модули:', 'flavor-worldstat' ); ?></p>

        <?php if ( empty( $extensions ) ) : ?>
            <div class="wsp-notice">
                <p><?php esc_html_e( 'Нет активных расширений. Установите расширения для добавления тематических данных.', 'flavor-worldstat' ); ?></p>
            </div>
        <?php else : ?>
            <div class="wsp-themes-grid">
            <?php foreach ( $extensions as $ext ) : ?>
                <div class="wsp-theme-card">
                    <div class="wsp-theme-icon">
                        <span class="dashicons <?php echo esc_attr( $ext['icon'] ); ?>"></span>
                    </div>
                    <h3><?php echo esc_html( $ext['name'] ); ?></h3>
                    <p><?php echo esc_html( $ext['description'] ); ?></p>
                    <span class="wsp-theme-version">v<?php echo esc_html( $ext['version'] ); ?></span>
                    <?php
                    // Count metrics for this extension
                    $count = 0;
                    foreach ( $metrics as $k => $m ) {
                        if ( $m['extension'] === $ext['id'] ) $count++;
                    }
                    ?>
                    <span class="wsp-theme-metrics"><?php printf( __( '%d метрик', 'flavor-worldstat' ), $count ); ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $metrics ) ) : ?>
            <h2><?php esc_html_e( 'Все доступные метрики', 'flavor-worldstat' ); ?></h2>
            <?php
            $headers = [ 'Метрика', 'Расширение', 'Тип', 'Единица' ];
            $rows = [];
            foreach ( $metrics as $key => $m ) {
                $rows[] = [ $m['label'], $m['extension'], $m['type'], $m['unit'] ];
            }
            WorldStat_UI::table( [
                'headers'    => $headers,
                'rows'       => $rows,
                'sortable'   => true,
                'searchable' => true,
            ] );
            ?>
        <?php endif; ?>

    </div>
</div>

<?php get_footer(); ?>
