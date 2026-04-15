<?php
/** Stats grid component. Variables: $items (array), $opts (array). */
if ( ! defined( 'ABSPATH' ) ) exit;
$cols = $opts['columns'] ?? 4;
?>
<div class="wsp-stats-grid" style="--wsp-grid-cols:<?php echo (int) $cols; ?>">
<?php foreach ( $items as $item ) :
    $change_class = '';
    if ( isset( $item['change'] ) ) {
        $change_class = str_starts_with( $item['change'], '+' ) ? 'wsp-change-up' : ( str_starts_with( $item['change'], '-' ) ? 'wsp-change-down' : '' );
    }
?>
    <div class="wsp-stat-card" data-metric-id="<?php echo esc_attr( $item['metric_id'] ?? '' ); ?>">
        <?php if ( ! empty( $item['icon'] ) ) : ?>
            <span class="wsp-stat-icon dashicons dashicons-<?php echo esc_attr( $item['icon'] ); ?>"></span>
        <?php endif; ?>
        <span class="wsp-stat-value"><?php echo esc_html( $item['value'] ?? '' ); ?></span>
        <span class="wsp-stat-label"><?php echo esc_html( $item['label'] ?? '' ); ?></span>
        <?php if ( ! empty( $item['change'] ) ) : ?>
            <span class="wsp-stat-change <?php echo $change_class; ?>"><?php echo esc_html( $item['change'] ); ?></span>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
