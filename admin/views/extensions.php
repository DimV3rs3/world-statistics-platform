<?php
/**
 * Extensions Manager page.
 *
 * Variables: $exts (array), $layers (array), $tabs (array)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap wsp-admin-wrap">
    <h1 class="wsp-admin-title">
        <span class="dashicons dashicons-admin-plugins"></span>
        Управление расширениями
    </h1>

    <!-- Tab Nav -->
    <h2 class="nav-tab-wrapper">
        <a href="#installed" class="nav-tab nav-tab-active" data-tab="installed">Установлено (<?php echo count( $exts ); ?>)</a>
        <a href="#info"      class="nav-tab" data-tab="info">Информация для разработчиков</a>
    </h2>

    <!-- Installed Extensions -->
    <div class="wsp-admin-tab-panel" id="panel-installed">
        <?php if ( empty( $exts ) ) : ?>
            <div class="wsp-empty-state">
                <span class="dashicons dashicons-admin-plugins wsp-empty-icon"></span>
                <h3>Нет активных расширений</h3>
                <p>Расширения регистрируются автоматически при активации через стандартные WordPress плагины.</p>
                <p>Используйте SDK для создания собственных расширений.</p>
            </div>
        <?php else : ?>
            <table class="widefat striped wsp-ext-table">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Расширение</th>
                        <th>Версия</th>
                        <th>Автор</th>
                        <th>Вкладка</th>
                        <th>Слои карты</th>
                        <th>Описание</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $exts as $id => $ext ) :
                        $tab_labels = [];
                        if ( isset( $tabs[ $id ]['title'] ) ) {
                            $tab_labels[] = (string) $tabs[ $id ]['title'];
                        }
                        foreach ( $tabs as $tab_key => $tab_cfg ) {
                            if ( ! is_array( $tab_cfg ) || $tab_key === $id ) {
                                continue;
                            }
                            $tid = (string) ( $tab_cfg['id'] ?? $tab_key );
                            if ( $tid === $tab_key ) {
                                $tab_labels[] = (string) ( $tab_cfg['title'] ?? $tab_key );
                            }
                        }
                        $tab_labels = array_values( array_unique( array_filter( $tab_labels ) ) );
                        $ext_layers = array_filter( $layers, fn( $l ) => $l['ext_id'] === $id );
                    ?>
                        <tr>
                            <td><span class="dashicons <?php echo esc_attr( $ext['icon'] ); ?>"></span></td>
                            <td><strong><?php echo esc_html( $ext['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $ext['version'] ); ?></td>
                            <td><?php echo esc_html( $ext['author'] ); ?></td>
                            <td><?php
                            if ( ! empty( $tab_labels ) ) {
                                echo esc_html( implode( ', ', $tab_labels ) );
                            } else {
                                echo '—';
                            }
                            ?></td>
                            <td><?php echo count( $ext_layers ); ?></td>
                            <td><?php echo esc_html( $ext['description'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Developer Info -->
    <div class="wsp-admin-tab-panel" id="panel-info" style="display:none">
        <div class="wsp-dev-info">
            <h3>Как создать расширение</h3>
            <p>Расширения — это стандартные WordPress плагины, которые используют API платформы World Statistics.</p>

            <h4>1. Структура расширения</h4>
            <pre><code>worldstat-{your-extension}/
├── worldstat-{your-extension}.php    # Главный файл
├── includes/
│   ├── class-extension.php            # Регистрация
│   ├── class-data-provider.php        # Данные
│   └── class-renderer.php             # UI
├── templates/
│   └── country-tab.php                # Вкладка
└── assets/
    ├── js/extension.js
    └── css/extension.css</code></pre>

            <h4>2. Регистрация расширения</h4>
            <pre><code>add_action('worldstat_init', function() {
    WorldStat_Extensions::register([
        'id'               => 'my-extension',
        'name'             => 'My Extension',
        'version'          => '1.0.0',
        'author'           => 'Your Name',
        'requires_platform'=> '1.0.0',
        'icon'             => 'dashicons-chart-bar',
        'description'      => 'Description here',
    ]);

    // Добавить вкладку на страницу страны
    WorldStat_Extensions::add_country_tab('my-extension', [
        'title'    => 'My Tab',
        'icon'     => 'dashicons-chart-bar',
        'callback' => 'my_render_tab',
        'priority' => 50,
    ]);

    // Добавить метрику данных
    WorldStat_Extensions::add_data_provider('my-extension', [
        'metrics' => [
            'my_metric' => [
                'label'    => 'My Metric',
                'type'     => 'number',
                'unit'     => 'units',
                'callback' => fn($code) => get_my_data($code),
            ],
        ],
    ]);
});</code></pre>

            <h4>3. UI Компоненты</h4>
            <pre><code>function my_render_tab($country_code) {
    $data = get_my_data($country_code);

    WorldStat_UI::stats_grid([
        ['label' => 'Metric 1', 'value' => $data['metric1']],
        ['label' => 'Metric 2', 'value' => $data['metric2']],
    ]);

    WorldStat_UI::chart([
        'type'     => 'bar',
        'title'    => 'Chart Title',
        'labels'   => ['2020', '2021', '2022'],
        'datasets' => [['label' => 'Values', 'data' => [10, 20, 30]]],
    ]);

    WorldStat_UI::table([
        'headers' => ['Column 1', 'Column 2'],
        'rows'    => [['A', 'B'], ['C', 'D']],
        'sortable'=> true,
    ]);
}</code></pre>

            <h4>4. Data API</h4>
            <pre><code>// Получить данные из другого расширения
$pop = WorldStat_Data::get('core', 'RU', 'population');
$gdp = WorldStat_Data::get('economy', 'RU', 'gdp');

// Сравнить страны
$comparison = WorldStat_Data::compare([
    'countries' => ['RU', 'US', 'CN'],
    'metrics'   => ['core.population', 'economy.gdp'],
]);</code></pre>

            <p>Подробнее: см. папку <code>sdk/extension-boilerplate/</code> в плагине платформы.</p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    $('.nav-tab').on('click',function(e){
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.wsp-admin-tab-panel').hide();
        $('#panel-'+tab).show();
    });
});
</script>
