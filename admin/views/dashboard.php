<?php
/**
 * Admin Dashboard — stats, extensions, quick actions.
 *
 * Variables: $exts (array), $metrics (array), $count (object)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$total_countries = ( $count->publish ?? 0 );
$total_ext       = count( $exts );
$total_metrics   = count( $metrics );
$version         = WSP_VERSION;
$migrated_from   = get_option( 'wsp_migrated_from_wsc', '' );
?>
<div class="wrap wsp-admin-wrap">
    <h1 class="wsp-admin-title">
        <span class="dashicons dashicons-admin-site-alt3"></span>
        World Statistics Platform
        <span class="wsp-version-badge">v<?php echo esc_html( $version ); ?></span>
    </h1>

    <?php if ( $migrated_from ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>Данные успешно мигрированы из World Statistics Core v<?php echo esc_html( $migrated_from ); ?>.</p>
        </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="wsp-admin-cards">
        <div class="wsp-admin-card">
            <span class="dashicons dashicons-admin-site-alt3 wsp-card-icon"></span>
            <div class="wsp-card-number"><?php echo (int) $total_countries; ?></div>
            <div class="wsp-card-label">Стран</div>
        </div>
        <div class="wsp-admin-card">
            <span class="dashicons dashicons-admin-plugins wsp-card-icon"></span>
            <div class="wsp-card-number"><?php echo (int) $total_ext; ?></div>
            <div class="wsp-card-label">Расширений</div>
        </div>
        <div class="wsp-admin-card">
            <span class="dashicons dashicons-chart-bar wsp-card-icon"></span>
            <div class="wsp-card-number"><?php echo (int) $total_metrics; ?></div>
            <div class="wsp-card-label">Метрик</div>
        </div>
        <div class="wsp-admin-card">
            <span class="dashicons dashicons-calendar-alt wsp-card-icon"></span>
            <div class="wsp-card-number"><?php echo date_i18n( 'd.m.Y' ); ?></div>
            <div class="wsp-card-label">Обновлено</div>
        </div>
    </div>

    <!-- Active Extensions -->
    <div class="wsp-admin-section">
        <h2>Активные расширения</h2>
        <?php if ( empty( $exts ) ) : ?>
            <p class="wsp-muted">Нет установленных расширений. <a href="<?php echo admin_url( 'admin.php?page=worldstat-extensions' ); ?>">Управление расширениями &rarr;</a></p>
        <?php else : ?>
            <div class="wsp-ext-grid">
                <?php foreach ( $exts as $ext ) : ?>
                    <div class="wsp-ext-card">
                        <span class="dashicons <?php echo esc_attr( $ext['icon'] ); ?> wsp-ext-icon"></span>
                        <strong><?php echo esc_html( $ext['name'] ); ?></strong>
                        <span class="wsp-ext-version">v<?php echo esc_html( $ext['version'] ); ?></span>
                        <p class="wsp-ext-desc"><?php echo esc_html( $ext['description'] ); ?></p>
                        <span class="wsp-ext-author"><?php echo esc_html( $ext['author'] ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="wsp-admin-section">
        <h2>Быстрые действия</h2>
        <div class="wsp-quick-actions">
            <a href="<?php echo admin_url( 'edit.php?post_type=' . WorldStat_Country_CPT::SLUG ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-admin-site-alt3"></span> Управление странами
            </a>
            <a href="<?php echo admin_url( 'admin.php?page=worldstat-extensions' ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-admin-plugins"></span> Управление расширениями
            </a>
            <a href="<?php echo admin_url( 'admin.php?page=worldstat-settings' ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-admin-generic"></span> Настройки
            </a>
            <a href="<?php echo rest_url( 'worldstat/v1/countries' ); ?>" target="_blank" class="button button-secondary">
                <span class="dashicons dashicons-rest-api"></span> REST API
            </a>
        </div>
    </div>

    <?php if ( ! empty( $analysis_dups_total ) && (int) $analysis_dups_total > 1 ) : ?>
        <div class="wsp-admin-section">
            <h2>Анализ данных: очистка дублей</h2>
            <p class="wsp-muted">
                Найдено дубликатов страницы «Анализ данных» в количестве: <strong><?php echo (int) $analysis_dups_count; ?></strong>.
                Оставим каноническую страницу со slug <code>analysis-data</code> и удалим остальные.
            </p>
            <p>
                <?php
                $url = wp_nonce_url( admin_url( 'admin.php?wsp_cleanup_analysis=1' ), 'wsp_cleanup_analysis' );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="button button-primary">Очистить дубликаты</a>
            </p>
        </div>
    <?php endif; ?>

    <!-- API Info -->
    <div class="wsp-admin-section">
        <h2>REST API</h2>
        <table class="widefat striped">
            <thead><tr><th>Endpoint</th><th>Метод</th><th>Описание</th></tr></thead>
            <tbody>
                <tr><td><code>/worldstat/v1/countries</code></td><td>GET</td><td>Список всех стран</td></tr>
                <tr><td><code>/worldstat/v1/countries/{code}</code></td><td>GET</td><td>Данные страны по ISO2</td></tr>
                <tr><td><code>/worldstat/v1/countries/{code}/data</code></td><td>GET</td><td>Все данные расширений для страны</td></tr>
                <tr><td><code>/worldstat/v1/metrics</code></td><td>GET</td><td>Доступные метрики</td></tr>
                <tr><td><code>/worldstat/v1/compare</code></td><td>GET</td><td>Сравнение стран</td></tr>
                <tr><td><code>/worldstat/v1/extensions</code></td><td>GET</td><td>Активные расширения</td></tr>
                <tr><td><code>/worldstat/v1/tabs/{code}</code></td><td>GET</td><td>Вкладки для страны</td></tr>
                <tr><td><code>/worldstat/v1/map-layers</code></td><td>GET</td><td>Слои карты</td></tr>
            </tbody>
        </table>
    </div>

</div>
