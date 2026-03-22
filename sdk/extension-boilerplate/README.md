# WorldStat Extension Boilerplate

Шаблон для создания расширений к платформе **World Statistics Platform**.

## Быстрый старт

1. Скопируйте эту папку в `wp-content/plugins/worldstat-your-extension/`
2. Переименуйте файлы и классы:
   - `worldstat-example-extension.php` → `worldstat-your-extension.php`
   - `WSE_` → `WSY_` (ваш уникальный префикс)
   - Обновите Plugin Name, описание и автора
3. Активируйте плагин

## Структура

```
worldstat-your-extension/
├── worldstat-your-extension.php    # Главный файл — регистрация
├── includes/
│   ├── class-extension.php          # Хуки WordPress, ассеты
│   ├── class-data-provider.php      # Данные: метрики, экспорт
│   └── class-renderer.php           # UI: вкладка страны, компоненты
├── templates/                       # (опционально) шаблоны
├── data/                            # (опционально) JSON/CSV файлы
├── assets/
│   ├── js/extension.js
│   └── css/extension.css
└── README.md
```

## API Платформы

### Регистрация расширения

```php
add_action('worldstat_init', function() {
    WorldStat_Extensions::register([
        'id'                => 'your-ext',
        'name'              => 'Your Extension',
        'version'           => '1.0.0',
        'author'            => 'Your Name',
        'requires_platform' => '1.0.0',
        'icon'              => 'dashicons-chart-bar',
        'description'       => 'Description...',
    ]);
});
```

### Data Provider

```php
WorldStat_Extensions::add_data_provider('your-ext', [
    'metrics' => [
        'my_metric' => [
            'label'    => 'My Metric',
            'type'     => 'number',      // number | integer | string
            'unit'     => 'units',
            'callback' => fn($iso2) => get_my_metric($iso2),
        ],
    ],
]);
```

### Country Page Tab

```php
WorldStat_Extensions::add_country_tab('your-ext', [
    'title'    => 'My Tab',
    'icon'     => 'dashicons-chart-bar',
    'callback' => 'my_render_tab',  // function($iso2_code)
    'priority' => 50,               // 0=first, 100=last
]);
```

### Map Layer

```php
WorldStat_Extensions::add_map_layer('your-ext', [
    'label'         => 'Population Density',
    'type'          => 'choropleth',
    'color_scale'   => ['#f0f0f0', '#003d99'],
    'data_callback' => fn() => ['RU' => 8.4, 'US' => 36.2, ...],
]);
```

### Data API (Чтение данных из других расширений)

```php
// Получить метрику
$pop = WorldStat_Data::get('core', 'RU', 'population');
$gdp = WorldStat_Data::get('economy', 'RU', 'gdp');

// Сравнить страны
$result = WorldStat_Data::compare([
    'countries' => ['RU', 'US', 'CN'],
    'metrics'   => ['core.population', 'economy.gdp'],
]);

// Все метрики
$all = WorldStat_Data::get_available_metrics();

// Данные для карты
$map = WorldStat_Data::get_for_map('demographics', 'density');
```

### UI Компоненты

```php
function my_render_tab($iso2) {
    // Сетка статистики
    WorldStat_UI::stats_grid([
        ['label' => 'Metric', 'value' => '42', 'icon' => 'chart-bar'],
    ]);

    // График
    WorldStat_UI::chart([
        'type'     => 'line',  // line|bar|pie|doughnut|area|scatter
        'title'    => 'Trend',
        'labels'   => ['2020', '2021', '2022'],
        'datasets' => [['label' => 'Value', 'data' => [10, 20, 30]]],
    ]);

    // Таблица
    WorldStat_UI::table([
        'headers'    => ['Col 1', 'Col 2'],
        'rows'       => [['A', 'B']],
        'sortable'   => true,
        'searchable' => true,
        'exportable' => true,
    ]);

    // Мини-карта
    WorldStat_UI::map([
        'type'    => 'markers',
        'lat'     => 55.75,
        'lng'     => 37.62,
        'zoom'    => 5,
        'markers' => [['lat' => 55.75, 'lng' => 37.62, 'title' => 'Moscow']],
    ]);

    // Сравнение
    WorldStat_UI::comparison([
        'countries' => ['RU', 'US'],
    ]);

    // Текст с подсветкой
    WorldStat_UI::text_block([
        'content'    => 'Population is {pop}',
        'highlights' => ['pop'],
    ]);
}
```

### Глобальные функции

```php
worldstat_get_data('ext_id', 'RU', 'metric');
worldstat_get_country('RU');
worldstat_get_countries(['region' => 'europe']);
worldstat_get_population('RU');
worldstat_compare_countries([...]);
worldstat_is_extension_active('demographics');
```

### REST API

| Endpoint | Method | Description |
|---|---|---|
| `/worldstat/v1/countries` | GET | Список стран |
| `/worldstat/v1/countries/{code}` | GET | Данные страны |
| `/worldstat/v1/countries/{code}/data` | GET | Все данные расширений |
| `/worldstat/v1/data/{ext}/{code}/{metric}` | GET | Конкретная метрика |
| `/worldstat/v1/metrics` | GET | Все метрики |
| `/worldstat/v1/compare?countries=RU,US&metrics=...` | GET | Сравнение |
| `/worldstat/v1/extensions` | GET | Активные расширения |
| `/worldstat/v1/tabs/{code}` | GET | Вкладки для страны |
| `/worldstat/v1/map-layers` | GET | Слои карты |

### Хуки платформы

```php
// Actions
do_action('worldstat_init', $platform);              // Регистрация
do_action('worldstat_loaded', $platform);             // Legacy
do_action('worldstat_activated');                      // При активации
do_action('worldstat_deactivated');                    // При деактивации
do_action('worldstat_extension_registered', $id, $config);
do_action('worldstat_before_country', $post_id, $iso2, $meta);
do_action('worldstat_after_country', $post_id, $iso2, $meta);
do_action('worldstat_country_sidebar', $post_id, $iso2, $meta);
do_action('worldstat_country_after_content', $post_id, $iso2, $meta);

// Filters
apply_filters('worldstat_is_platform_page', false);
apply_filters('worldstat_country_tabs', $tabs, $iso2);
apply_filters('worldstat_get_data', null, $ext_id, $country_code, $metric);
```

## JS API (Frontend)

```javascript
// Get data
WSPExtensions.getData('core', 'RU', 'population').then(function(res) {
    console.log(res.value);
});

// Events
WSPExtensions.on('tab:loaded', function(data) { ... });
WSPExtensions.emit('my:event', { foo: 'bar' });

// Utilities
WSPExtensions.formatNumber(1234567);  // "1 234 567"
```
