<?php
/**
 * Methodology — data sources, calculation methods, and limitations.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

wp_enqueue_style( 'wsp-analytics', WSP_ASSETS_URL . 'css/wsp-analytics.css', [], WSP_VERSION );

get_header();
?>

<div class="wsp-page">
    
    <h1 class="wsp-page__title"><?php esc_html_e( 'Методология и источники данных', 'flavor-worldstat' ); ?></h1>
    <p class="wsp-page__subtitle"><?php esc_html_e( 'Как формируются показатели, откуда берутся данные и как часто обновляются', 'flavor-worldstat' ); ?></p>

    <div class="wsp-card">
        <h2 style="font-size:1.3rem;font-weight:700;color:#0f172a;margin:0 0 16px;">📐 Общие принципы расчёта</h2>
        <div style="font-size:15px;line-height:1.8;color:#475569;">
            <?php 
            $page = get_page_by_path( 'methodology' );
            if ( $page && $page->post_content ) {
                echo wp_kses_post( wpautop( $page->post_content ) );
            } else {
                echo '<p>' . esc_html__( 'Все метрики платформы рассчитываются на основе официальных статистических данных, предоставляемых национальными агентствами, международными организациями и авторитетными исследовательскими центрами.', 'flavor-worldstat' ) . '</p>';
                echo '<p>' . esc_html__( 'Числовые показатели проходят нормализацию и валидацию. Производные метрики (на душу населения, плотность, индексы) вычисляются по единым формулам, закреплённым в коде платформы.', 'flavor-worldstat' ) . '</p>';
            }
            ?>
        </div>
    </div>

    <!-- ИСТОЧНИКИ ПО РАСШИРЕНИЯМ -->
    <div class="wsp-card">
        <h2 style="font-size:1.3rem;font-weight:700;color:#0f172a;margin:0 0 16px;">📚 Источники по расширениям</h2>
        <div style="font-size:15px;line-height:1.8;color:#475569;">
            <p><?php esc_html_e( 'Каждое расширение платформы использует собственные источники данных. Ниже перечислены основные из них.', 'flavor-worldstat' ); ?></p>
        </div>

        <?php
        if ( class_exists( 'WorldStat_Extensions' ) ) {
            $ext = worldstat_platform()->extensions ?? null;
            if ( $ext ) {
                foreach ( $ext->get_all() as $ext_id => $info ) {
                    $tabs = $ext->get_tabs();
                    $label = $tabs[ $ext_id ]['title'] ?? $info['name'] ?? $ext_id;
                    $desc  = $info['description'] ?? '';
                    
                    // Контент от расширения
                    ob_start();
                    do_action( "worldstat_methodology_{$ext_id}" );
                    $custom = trim( ob_get_clean() );
                ?>
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-top:12px;">
                        <h3 style="font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 6px;">
                            <?php echo esc_html( $label ); ?>
                        </h3>
                        
                        <?php if ( $desc ) : ?>
                            <p style="font-size:14px;color:#64748b;margin:0 0 8px;"><?php echo esc_html( $desc ); ?></p>
                        <?php endif; ?>
                        
                        <?php if ( $custom ) : ?>
                            <div style="font-size:14px;line-height:1.7;color:#475569;">
                                <?php echo $custom; ?>
                            </div>
                        <?php else : ?>
                            <p style="font-size:13px;color:#94a3b8;margin:0;">
                                <?php esc_html_e( 'Подробное описание источников, методов сбора и формул доступно в документации расширения.', 'flavor-worldstat' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php
                }
            }
        }
        ?>
    </div>

    <div class="wsp-card">
        <h2 style="font-size:1.3rem;font-weight:700;color:#0f172a;margin:0 0 16px;">🔄 Периодичность обновления</h2>
        <div style="font-size:15px;line-height:1.8;color:#475569;">
            <p><?php esc_html_e( 'Данные обновляются в соответствии с графиком публикации первоисточников:', 'flavor-worldstat' ); ?></p>
            <ul style="padding-left:20px;display:flex;flex-direction:column;gap:8px;">
                <li><strong><?php esc_html_e( 'Ключевые метрики (core)', 'flavor-worldstat' ); ?></strong> — <?php esc_html_e( 'ежегодно, по мере публикации данных ООН, Всемирного банка, ЦРУ', 'flavor-worldstat' ); ?></li>
                <li><strong><?php esc_html_e( 'Расширения', 'flavor-worldstat' ); ?></strong> — <?php esc_html_e( 'по расписанию, заданному разработчиком расширения', 'flavor-worldstat' ); ?></li>
                <li><strong><?php esc_html_e( 'Пользовательские CSV', 'flavor-worldstat' ); ?></strong> — <?php esc_html_e( 'обновляются вручную администратором платформы', 'flavor-worldstat' ); ?></li>
            </ul>
        </div>
    </div>

    <div class="wsp-card">
        <h2 style="font-size:1.3rem;font-weight:700;color:#0f172a;margin:0 0 16px;">⚠️ Ограничения и допущения</h2>
        <div style="font-size:15px;line-height:1.8;color:#475569;">
            <p><?php esc_html_e( 'При интерпретации данных просим учитывать:', 'flavor-worldstat' ); ?></p>
            <ul style="padding-left:20px;display:flex;flex-direction:column;gap:8px;">
                <li><?php esc_html_e( 'Данные отражают последний доступный год публикации, который может различаться для разных стран', 'flavor-worldstat' ); ?></li>
                <li><?php esc_html_e( 'Производные показатели (на душу населения, плотность) используют демографические данные за тот же период', 'flavor-worldstat' ); ?></li>
                <li><?php esc_html_e( 'Для стран с ограниченной статистикой часть показателей может отсутствовать', 'flavor-worldstat' ); ?></li>
                <li><?php esc_html_e( 'Сравнение показателей между странами требует учёта различий в методологии национальных статистических служб', 'flavor-worldstat' ); ?></li>
            </ul>
        </div>
    </div>

    <?php do_action( 'worldstat_methodology_page' ); ?>

</div>

<?php get_footer(); ?>