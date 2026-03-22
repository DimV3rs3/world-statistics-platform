<?php
/** Timeline component. Variables: $opts (array). */
if ( ! defined( 'ABSPATH' ) ) exit;
$events = $opts['events'] ?? [];
if ( empty( $events ) ) return;
?>
<div class="wsp-timeline">
    <div class="wsp-timeline-line"></div>
    <?php foreach ( $events as $e ) : ?>
        <div class="wsp-timeline-item">
            <div class="wsp-timeline-dot"></div>
            <div class="wsp-timeline-date"><?php echo esc_html( $e['date'] ?? $e['year'] ?? '' ); ?></div>
            <div class="wsp-timeline-body">
                <?php if ( ! empty( $e['title'] ) ) : ?>
                    <h5><?php echo esc_html( $e['title'] ); ?></h5>
                <?php endif; ?>
                <?php if ( ! empty( $e['text'] ) ) : ?>
                    <p><?php echo esc_html( $e['text'] ); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
