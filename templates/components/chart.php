<?php
/** Chart.js component. Variables: $opts (array). AJAX-compatible. */
if ( ! defined( 'ABSPATH' ) ) exit;
$chart_id = 'wsp-chart-' . wp_unique_id();
$config = wp_json_encode( [
    'type'     => $opts['type'],
    'title'    => $opts['title'],
    'labels'   => $opts['labels'],
    'datasets' => $opts['datasets'],
    'xLabel'   => $opts['x_label'],
    'yLabel'   => $opts['y_label'],
] );
?>
<div class="wsp-chart-wrap">
    <?php if ( $opts['title'] ) : ?>
        <h4 class="wsp-chart-title"><?php echo esc_html( $opts['title'] ); ?></h4>
    <?php endif; ?>
    <div class="wsp-chart-canvas-wrap" style="position:relative;height:<?php echo (int) $opts['height']; ?>px;">
        <canvas id="<?php echo esc_attr( $chart_id ); ?>"></canvas>
    </div>
    <script>
    (function(){
        var id='<?php echo esc_js( $chart_id ); ?>',cfg=<?php echo $config; ?>;
        function tryRender(){
            if(typeof Chart!=='undefined'&&window.WSPChart){WSPChart.render(id,cfg);}
            else{setTimeout(tryRender,150);}
        }
        if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',tryRender);}
        else{tryRender();}
    })();
    </script>
</div>
