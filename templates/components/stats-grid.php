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
        <?php
        $badge_slug  = ! empty( $item['badge_slug'] ) ? sanitize_key( (string) $item['badge_slug'] ) : '';
        $badge_class = 'wsp-stat-badge' . ( $badge_slug !== '' ? ' wsp-stat-badge--' . $badge_slug : '' );
        $value_class = 'wsp-stat-value' . ( ! empty( $item['badge'] ) ? ' wsp-stat-value--with-badge' : '' );
        ?>
        <span class="<?php echo esc_attr( $value_class ); ?>">
            <?php echo esc_html( $item['value'] ?? '' ); ?>
            <?php if ( ! empty( $item['badge'] ) ) : ?>
                <span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( (string) $item['badge'] ); ?></span>
            <?php endif; ?>
        </span>
        <span class="wsp-stat-label"><?php echo esc_html( $item['label'] ?? '' ); ?></span>
        <?php if ( ! empty( $item['change'] ) ) : ?>
            <span class="wsp-stat-change <?php echo $change_class; ?>"><?php echo esc_html( $item['change'] ); ?></span>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
