<?php
/**
 * Metrics catalog — searchable table of all platform metrics.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'wsp-analytics', WSP_ASSETS_URL . 'css/wsp-analytics.css', [], WSP_VERSION );
wp_enqueue_script( 'wsp-analytics', WSP_ASSETS_URL . 'js/wsp-analytics.js', [], WSP_VERSION, true );

get_header();

$all_metrics = WorldStat_Data::get_available_metrics_with_core();

$ext_labels = [ 'core' => 'Ключевые метрики' ];
if ( class_exists( 'WorldStat_Extensions' ) ) {
    $ext_instance = worldstat_platform()->extensions ?? null;
    if ( $ext_instance ) {
        foreach ( $ext_instance->get_all() as $ext_id => $info ) {
            $tabs = $ext_instance->get_tabs();
            $ext_labels[ $ext_id ] = $tabs[ $ext_id ]['title'] ?? $info['name'] ?? ucfirst( $ext_id );
        }
    }
}

$grouped = [];
$total = 0;
foreach ( $all_metrics as $key => $m ) {
    $ext = $m['extension'] ?? 'other';
    if ( ! isset( $grouped[ $ext ] ) ) {
        $grouped[ $ext ] = [ 'label' => $ext_labels[ $ext ] ?? ucfirst( $ext ), 'count' => 0, 'metrics' => [] ];
    }
    $grouped[ $ext ]['count']++;
    $grouped[ $ext ]['metrics'][ $key ] = $m;
    $total++;
}
?>

<div class="wsp-page">
    
        <h1 class="wsp-page__title"><?php esc_html_e( 'Каталог метрик', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle">
        <?php 
        printf( 
            esc_html__( 'Всего %s показателей из %s источников', 'flavor-worldstat' ), 
            '<strong style="color:#2563eb;">' . $total . '</strong>', 
            '<strong style="color:#2563eb;">' . count( $grouped ) . '</strong>' 
        ); 
        ?>
    </p>
    
    <!-- Фильтр по расширениям -->
    <div class="wsp-tags" id="wsp-catalog-filters">
        <span class="wsp-tag active" data-filter="all">Все <small>(<?php echo $total; ?>)</small></span>
        <?php foreach ( $grouped as $ext => $group ) : ?>
            <span class="wsp-tag" data-filter="<?php echo esc_attr( $ext ); ?>">
                <?php echo esc_html( $group['label'] ); ?> <small>(<?php echo $group['count']; ?>)</small>
            </span>
        <?php endforeach; ?>
    </div>

    <!-- Поиск -->
    <div style="margin-bottom: 20px;">
        <input type="text" id="wsp-catalog-search-input" class="wsp-input" placeholder="🔍 Поиск по названию...">
    </div>

    <!-- Таблица -->
    <div style="overflow-x: auto;">
        <table class="wsp-table" id="wsp-metrics-table">
            <thead>
                <tr>
                    <th data-sort="label">Метрика <span class="sort-arrow">▲</span></th>
                    <th data-sort="key">Ключ <span class="sort-arrow">▲</span></th>
                    <th data-sort="type">Тип <span class="sort-arrow">▲</span></th>
                    <th data-sort="level">Уровень <span class="sort-arrow">▲</span></th>
                    <th data-sort="unit">Ед. изм. <span class="sort-arrow">▲</span></th>
                    <th data-sort="source">Источник <span class="sort-arrow">▲</span></th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $grouped as $ext => $group ) : ?>
                    <?php foreach ( $group['metrics'] as $key => $m ) : 
                        $type = $m['type'] ?? 'string';
                        $level = $m['level'] ?? 'country';
                    ?>
                        <tr data-source="<?php echo esc_attr( $ext ); ?>" data-search="<?php echo esc_attr( mb_strtolower( $m['label'] . ' ' . $key ) ); ?>">
                            <td>
                                <div style="font-weight:600;color:#0f172a;"><?php echo esc_html( $m['label'] ); ?></div>
                                <?php if ( ! empty( $m['description'] ) ) : ?>
                                    <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?php echo esc_html( $m['description'] ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span style="font-family:monospace;font-size:11px;color:#94a3b8;"><?php echo esc_html( $key ); ?></span></td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;
                                    <?php echo in_array($type,['number','integer','float','double']) ? 'background:#e0f2fe;color:#0369a1;' : 'background:#fef3c7;color:#92400e;'; ?>">
                                    <?php echo esc_html($type); ?>
                                </span>
                            </td>
                            <td>
                                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:500;
                                    <?php echo $level==='country' ? 'background:#dcfce7;color:#166534;' : 'background:#f3e8ff;color:#6b21a8;'; ?>">
                                    <?php echo $level==='country' ? 'Страна' : 'Город'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $m['unit'] ?? '—' ); ?></td>
                            <td><?php echo esc_html( $group['label'] ); ?></td>
                            <td>
                                <?php if ( in_array($type,['number','integer','float','double']) && $level==='country' ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg('metric',$key, WorldStat_Pages::get_page_url('rankings')) ); ?>" 
                                       style="display:inline-block;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;background:#eff6ff;color:#2563eb;">
                                        📊 Рейтинг
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="wsp-catalog-no-results" class="wsp-empty" style="display:none;">Метрик не найдено по вашему запросу.</div>
</div>

<?php get_footer(); ?>