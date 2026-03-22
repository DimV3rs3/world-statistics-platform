<?php
/** Text block with highlighted metrics. Variables: $opts (array). */
if ( ! defined( 'ABSPATH' ) ) exit;
$content    = $opts['content'] ?? '';
$highlights = $opts['highlights'] ?? [];

// Replace {metric} placeholders with highlighted spans
foreach ( $highlights as $key ) {
    $content = str_replace(
        '{' . $key . '}',
        '<span class="wsp-highlight" data-metric="' . esc_attr( $key ) . '">{' . $key . '}</span>',
        $content
    );
}
?>
<div class="wsp-text-block">
    <p><?php echo wp_kses_post( $content ); ?></p>
</div>
