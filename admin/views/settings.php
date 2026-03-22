<?php
/**
 * Settings page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap wsp-admin-wrap">
    <h1 class="wsp-admin-title">
        <span class="dashicons dashicons-admin-generic"></span>
        Настройки платформы
    </h1>

    <form method="post" action="options.php">
        <?php settings_fields( 'wsp_settings' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="wsp_map_on_front">Карта на главной</label></th>
                <td>
                    <input type="checkbox" id="wsp_map_on_front" name="wsp_map_on_front" value="1"
                        <?php checked( get_option( 'wsp_map_on_front', true ) ); ?> />
                    <span class="description">Показывать интерактивную карту на главной странице</span>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wsp_countries_per_page">Стран на странице</label></th>
                <td>
                    <input type="number" id="wsp_countries_per_page" name="wsp_countries_per_page"
                           value="<?php echo (int) get_option( 'wsp_countries_per_page', 200 ); ?>"
                           min="10" max="500" class="small-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wsp_enable_rest_public">Публичный REST API</label></th>
                <td>
                    <input type="checkbox" id="wsp_enable_rest_public" name="wsp_enable_rest_public" value="1"
                        <?php checked( get_option( 'wsp_enable_rest_public', true ) ); ?> />
                    <span class="description">Разрешить доступ к REST API без авторизации</span>
                </td>
            </tr>
        </table>

        <?php submit_button( 'Сохранить настройки' ); ?>
    </form>

    <!-- System Info -->
    <div class="wsp-admin-section" style="margin-top:30px">
        <h2>Информация о системе</h2>
        <table class="widefat striped">
            <tbody>
                <tr><td><strong>Версия платформы</strong></td><td><?php echo esc_html( WSP_VERSION ); ?></td></tr>
                <tr><td><strong>PHP</strong></td><td><?php echo PHP_VERSION; ?></td></tr>
                <tr><td><strong>WordPress</strong></td><td><?php echo get_bloginfo( 'version' ); ?></td></tr>
                <tr>
                    <td><strong>Стран в базе</strong></td>
                    <td><?php echo (int) ( wp_count_posts( WorldStat_Country_CPT::SLUG )->publish ?? 0 ); ?></td>
                </tr>
                <tr>
                    <td><strong>Активных расширений</strong></td>
                    <td><?php echo worldstat_platform()->extensions->count(); ?></td>
                </tr>
                <tr>
                    <td><strong>Миграция из WSC</strong></td>
                    <td><?php
                        $m = get_option( 'wsp_migrated_from_wsc', '' );
                        echo $m ? 'Да (v' . esc_html( $m ) . ', ' . esc_html( get_option( 'wsp_migration_date', '' ) ) . ')' : 'Нет';
                    ?></td>
                </tr>
                <tr>
                    <td><strong>Путь к плагину</strong></td>
                    <td><code><?php echo esc_html( WSP_PLUGIN_DIR ); ?></code></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Danger Zone -->
    <div class="wsp-admin-section wsp-danger-zone" style="margin-top:30px">
        <h2 style="color:#d63638">Опасная зона</h2>
        <p class="description">Эти действия необратимы. Будьте осторожны.</p>
        <p>
            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=worldstat-settings&action=flush_cache' ), 'wsp_flush' ); ?>" class="button">
                Очистить кеш
            </a>
        </p>
    </div>
</div>
