# World Statistics Platform — архитектура, логика и руководство по расширениям

Документ описывает основной плагин **world-statistics-platform**, расширение **worldstat-cities**, интеграцию с темой **ergonosphera** и пошаговую инструкцию по созданию новых плагинов-расширений для отображения статистики по странам на карте.

---

## Оглавление

1. [Общая архитектура](#1-общая-архитектура)
2. [Основной плагин: world-statistics-platform](#2-основной-плагин-world-statistics-platform) — директории, файлы, классы, вывод, БД
3. [Расширение: worldstat-cities](#3-расширение-worldstat-cities) — структура, регистрация, БД
4. [Тема Ergonosphera](#4-тема-ergonosphera-подключение-платформы-и-карта) — карта, wscMapData
5. [Пошаговая инструкция: новое расширение](#5-пошаговая-инструкция-новое-расширение-со-статистикой-на-карте)
6. [Хуки и фильтры](#6-краткая-справка-хуки-и-фильтры-платформы)
7. [Чек-лист нового расширения](#7-чек-лист-нового-расширения)

**Для начинающих:** подробный пошаговый алгоритм и модификация плагина worldstat-cities в новый плагин описаны в отдельном документе: [STEP-BY-STEP-NEW-EXTENSION.md](STEP-BY-STEP-NEW-EXTENSION.md).

---

## 1. Общая архитектура

```
┌─────────────────────────────────────────────────────────────────┐
│  Тема Ergonosphera                                                │
│  • Главная страница + карта мира (TopoJSON, Leaflet)              │
│  • Страницы стран/архивы (при наличии — подменяются платформой)   │
│  • Подключение: inc/plugin-api.php, inc/enqueue.php (ergo-svg-map)│
└──────────────────────────────┬──────────────────────────────────┘
                               │ wscMapData, worldstat_countries
┌──────────────────────────────▼──────────────────────────────────┐
│  Плагин World Statistics Platform (основной)                      │
│  • CPT wsp_country, таксономии, мета-поля                         │
│  • API расширений: регистрация, вкладки, слои карты, маркеры     │
│  • UI: карта, графики, таблицы, сравнение                        │
│  • REST API, шаблоны, AJAX-вкладки                                │
└──────────────────────────────┬──────────────────────────────────┘
                               │ worldstat_init, add_country_tab, add_map_markers
┌──────────────────────────────▼──────────────────────────────────┐
│  Плагин WorldStat Cities (расширение)                             │
│  • CPT wsp_city, мета (население, координаты, Blocks & Roads)   │
│  • Вкладка «Города» на странице страны                           │
│  • Слой маркеров городов на карте                                │
│  • Импорт CSV, админка                                            │
└─────────────────────────────────────────────────────────────────┘
```

- **Основной плагин** не хранит тематическую статистику (города, экономика и т.д.) — только страны и их базовые мета (население, площадь, столица, ISO).
- **Расширения** регистрируются на хуке `worldstat_init`, добавляют вкладки на страницу страны, слои/маркеры на карту и при необходимости свои CPT и таблицы/мета.
- **Тема** отображает карту мира на главной; платформа подставляет в скрипт карты данные `wscMapData` (URL страниц стран, население, столицы) и обрабатывает клик по стране (переход на `/country/{slug}/`).

---

## 2. Основной плагин: world-statistics-platform

### 2.1. Структура директорий и файлов

```
world-statistics-platform/
├── world-statistics-platform.php   # Точка входа: константы, автозагрузка, активация
├── includes/
│   ├── class-worldstat-core.php         # Ядро: синглтон, компоненты, is_wsp_page, enqueue
│   ├── class-worldstat-extensions.php   # Регистрация расширений, вкладки, слои, маркеры, провайдеры
│   ├── class-worldstat-country-cpt.php  # CPT wsp_country, slug country, get_by_code
│   ├── class-worldstat-taxonomies.php   # Регионы, субрегионы, группы дохода
│   ├── class-worldstat-meta.php         # Мета-поля стран (wsp_iso_alpha2, population, capital...)
│   ├── class-worldstat-data.php         # WorldStat_Data::get, compare, get_for_map, хелперы
│   ├── class-worldstat-ui.php           # WorldStat_UI:: stats_grid, chart, table, map, comparison...
│   ├── class-worldstat-templates.php    # Подключение шаблонов плагина/темы (single, archive, page)
│   ├── class-worldstat-tabs.php         # Вкладки страницы страны, AJAX загрузка контента вкладки
│   ├── class-worldstat-map.php          # Интеграция с картой темы (wscMapData), маркеры/слои, REST
│   ├── class-worldstat-rest-api.php     # REST worldstat/v1: countries, data, metrics, tabs, map-markers
│   ├── class-worldstat-pages.php        # Создание страниц (Страны мира, Сравнение, Тематические данные)
│   └── class-worldstat-installer.php    # Активация: миграция, таксономии, создание 195 стран, flush rules
├── templates/
│   ├── single-wsp_country.php      # Шаблон страницы одной страны (вкладки, обзор)
│   ├── archive-wsp_country.php     # Архив стран
│   ├── page-countries.php          # Страница «Страны мира»
│   ├── page-compare.php            # Сравнение стран
│   ├── page-data-themes.php        # Тематические данные
│   └── components/
│       ├── mini-map.php            # Мини-карта (тайлы или TopoJSON «countries» + сетка + маркеры)
│       ├── chart.php               # Обёртка Chart.js
│       ├── data-table.php          # Таблица с сортировкой/поиском
│       ├── stats-grid.php          # Сетка карточек статистики
│       ├── comparison.php          # Виджет сравнения
│       ├── timeline.php            # Временная шкала
│       └── text-block.php          # Текстовый блок
├── assets/
│   ├── css/platform.css, components.css
│   ├── js/platform.js              # Табы, AJAX загрузка вкладок
│   ├── js/chart-builder.js, map-handler.js, extensions-api.js
│   ├── admin/admin.css, admin.js
│   └── vendor/leaflet/, chartjs/
├── admin/
│   ├── class-worldstat-admin.php
│   └── views/settings.php, dashboard.php, extensions.php
├── data/
│   └── countries.json              # 195 стран: iso2, name_ru, capital, population, region...
├── sdk/
│   └── extension-boilerplate/      # Шаблон нового расширения
│       ├── worldstat-example-extension.php
│       ├── includes/class-extension.php, class-data-provider.php, class-renderer.php
│       └── README.md
└── docs/
    └── PLATFORM-AND-EXTENSIONS-GUIDE.md  # Этот файл
```

### 2.2. Классы и назначение

| Класс | Назначение |
|-------|------------|
| **WorldStat_Core** | Синглтон. Создаёт все компоненты, вешает `worldstat_init`/`worldstat_loaded`. Определяет `is_wsp_page()`, подключает стили/скрипты на страницах платформы и стран; на странице страны заранее подключает Chart.js, Leaflet, TopoJSON для AJAX-вкладок. |
| **WorldStat_Extensions** | Хранение зарегистрированных расширений, провайдеров метрик, вкладок страны, слоёв карты, маркеров, экспортов. Методы: `register()`, `add_data_provider()`, `add_country_tab()`, `add_map_layer()`, `add_map_markers()`, `add_export()`, геттеры и `call_provider()`. После `worldstat_init` регистрация блокируется. |
| **WorldStat_Country_CPT** | Регистрация CPT `wsp_country` (slug в URL: `country`), разрешение конфликта с CPT темы `country` через `unregister_post_type('country')`. `get_by_code(iso2)`, `get_code_map()` (iso2 → post_id). |
| **WorldStat_Taxonomies** | Таксономии: `wsp_region`, `wsp_subregion`, `wsp_income_group`. Данные регионов/субрегионов и групп дохода для терминов. |
| **WorldStat_Meta** | Мета-поля стран (wsp_iso_alpha2, wsp_population, wsp_capital_ru и т.д.), метабокс в админке, сохранение, `get_field`/`get_all_fields`. |
| **WorldStat_Data** | Статический API: `get(ext_id, code, metric)`, `compare()`, `get_available_metrics()`, `get_for_map()`. Для `core` читает мету страны; для расширений — вызов провайдера. Глобальные функции-обёртки. |
| **WorldStat_UI** | Компоненты вывода: `stats_grid()`, `chart()`, `table()`, `map()`, `comparison()`, `timeline()`, `text_block()`, `section()`. Подключают шаблоны из `templates/components/`. |
| **WorldStat_Templates** | Фильтры `single_template`, `archive_template`, `page_template`. Поиск шаблона: сначала тема `get_stylesheet_directory()/worldstat/`, затем плагин. Для single поддерживается фильтр `worldstat_single_template` (расширения подставляют шаблон для своего CPT). |
| **WorldStat_Tabs** | Список вкладок для страны (обзор + вкладки расширений), вывод панели вкладок, контент вкладки «Обзор», AJAX `worldstat_load_tab` (параметры `tab`, `iso2`). |
| **WorldStat_Map** | Интеграция с картой темы: при наличии скрипта `ergo-svg-map` передаёт `wscMapData` (urls, population, names, capitals). Редирект `/country/?code=XX`. Сбор конфигов слоёв и маркеров, `get_country_map_data()`, `get_marker_data(layer_id, country)`. Shortcode `[worldstat_map]`. |
| **WorldStat_REST_API** | Маршруты `worldstat/v1`: countries, countries/{code}, countries/{code}/data, data/{ext_id}/{code}/{metric}, metrics, compare, extensions, tabs/{code}, map-layers, map-markers. |
| **WorldStat_Pages** | Ключи страниц: countries, compare, data-themes. Создание при активации, `get_page_id()`, `get_page_url()`. |
| **WorldStat_Installer** | Активация: регистрация CPT/таксономий/мета, миграция с wsc_ на wsp_, создание терминов таксономий, создание 195 стран из `countries.json`, создание страниц, flush_rewrite_rules. |

### 2.3. Особенности вывода в WordPress

- **Страница одной страны**  
  URL: `/country/{slug}/`. Шаблон: `single-wsp_country.php`. Вывод: заголовок, панель вкладок (Обзор + вкладки расширений). Контент вкладки «Обзор» выводится сразу; остальные вкладки подгружаются по AJAX при клике (`action=worldstat_load_tab`, `tab`, `iso2`).

- **Архив стран**  
  URL: `/countries/` (has_archive). Шаблон: `archive-wsp_country.php`.

- **Страницы платформы**  
  Страницы «Страны мира», «Сравнение», «Тематические данные» создаются при активации; их ID хранятся в опции `wsp_pages`. Подключаются шаблоны `page-countries.php`, `page-compare.php`, `page-data-themes.php`.

- **Определение «страницы платформы»**  
  `WorldStat_Core::is_wsp_page()`: singular/archive для `wsp_country`, singular для типов из фильтра `worldstat_extension_post_types`, страницы по ID из `wsp_pages`, плюс фильтр `worldstat_is_platform_page`. На этих страницах подключаются CSS/JS платформы; на singular страны дополнительно Chart.js, Leaflet, TopoJSON.

- **Мини-карта в компонентах**  
  `WorldStat_UI::map()` подключает шаблон `mini-map.php`. Режимы: тайловые подложки (osm, carto-light, carto-dark) или `tile_style => 'countries'` — та же карта, что на главной (TopoJSON полигоны стран из темы + координатная сетка + маркеры расширений). Маркеры по слоям расширений отдаются через `WorldStat_Map::get_marker_data()`.

### 2.4. База данных и хранение данных

- **Отдельные таблицы не создаются.** Всё в стандартных таблицах WordPress: `wp_posts`, `wp_postmeta`, `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_options`.

- **Страны:** тип записи `wsp_country`. Мета: `wsp_iso_alpha2`, `wsp_iso_alpha3`, `wsp_iso_numeric`, `wsp_name_short`, `wsp_name_official`, `wsp_name_short_ru`, `wsp_name_official_ru`, `wsp_capital_en`, `wsp_capital_ru`, `wsp_area_km2`, `wsp_latitude`, `wsp_longitude`, `wsp_flag`, `wsp_flag_url`, `wsp_population`. Таксономии: `wsp_region`, `wsp_subregion`, `wsp_income_group`.

- **Опции:** `wsp_version`, `wsp_activated`, `wsp_pages` (массив page_id для countries, compare, data-themes), при миграции — `wsp_migrated_from_wsc`, `wsp_migration_date`, `wsp_migration_count`. Кэш карты: transient `wsp_map_integration_data` (urls, population, names, capitals по ISO2). Кэш кода страны: `wp_cache` ключ `wsp_code_map`.

- **Установка:** при активации плагина страны создаются из `data/countries.json` (если записей страны ещё мало); страницы создаются через `wp_insert_post`; rewrite rules обновляются.

---

## 3. Расширение: worldstat-cities

### 3.1. Структура

```
worldstat-cities/
├── worldstat-cities.php       # Проверка платформы, подключение классов, регистрация на worldstat_init
├── includes/
│   ├── class-cities-cpt.php       # CPT wsp_city, мета (страна, координаты, население, Blocks & Roads)
│   ├── class-cities-data.php      # Провайдеры метрик и данные для карты/маркеров
│   ├── class-cities-renderer.php  # Отрисовка вкладки «Города» (stats, карта, таблицы, графики)
│   ├── class-cities-importer.php  # Импорт CSV (Areas & Densities, Blocks & Roads)
│   └── class-cities-admin.php     # Админка: пункт меню, импорт
├── templates/
│   └── single-wsp_city.php    # Шаблон страницы одного города (карта, метрики)
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

### 3.2. Регистрация в платформе (worldstat-cities.php)

1. **Проверка зависимости:** наличие класса `WorldStat_Core`; иначе сообщение в admin и выход.
2. **На `worldstat_init`:**
   - `WorldStat_Extensions::register([ 'id' => 'cities', 'name' => '...', 'version', 'requires_platform' => '1.0.0', ... ])`.
   - `WorldStat_Extensions::add_data_provider('cities', [ 'metrics' => [ ... ] ])` — каждая метрика с `label`, `type`, `callback` (статический метод класса данных).
   - `WorldStat_Extensions::add_country_tab('cities', [ 'title' => 'Города', 'callback' => [ 'WSCities_Renderer', 'render_country_tab' ], 'priority' => 20 ])`.
   - `WorldStat_Extensions::add_map_layer('cities', [ 'label' => '...', 'type' => 'choropleth', 'data_callback' => ... ])`.
   - `WorldStat_Extensions::add_map_markers('cities', [ 'label' => '...', 'country_callback' => [ 'WSCities_Data', 'get_country_city_markers' ], 'data_callback' => ... ])`.
3. **Шаблон single города:** фильтр `worldstat_single_template`: для `wsp_city` возвращать `templates/single-wsp_city.php`.
4. **Тип поста как страница платформы:** фильтр `worldstat_extension_post_types` добавляет `WSCities_CPT::SLUG`, чтобы на странице города подключались CSS/JS платформы.

### 3.3. Классы расширения

- **WSCities_CPT:** регистрация `wsp_city`, мета (wscity_country_iso2, wscity_lat, wscity_lng, население по периодам, площадь, плотность, Blocks & Roads в JSON). Методы: `get_cities_for_country(iso2)`, `get_blocks_roads(post_id)`, `get_cities_with_blocks_roads(iso2)` и т.д.
- **WSCities_Data:** провайдеры метрик (количество городов, население крупнейшего, суммарное городское, средние по дорогам/кварталам); `get_map_data()` для слоя хороплета; `get_country_city_markers(iso2)` и `get_all_city_markers()` для маркеров на карте.
- **WSCities_Renderer:** `render_country_tab($iso2)` — вывод блока статистики, мини-карты с `tile_style => 'countries'` и слоем маркеров `cities`, таблиц и графиков по городам; ссылки на страницы городов через `get_permalink` для типа `wsp_city`.
- **WSCities_Importer, WSCities_Admin:** импорт CSV и интерфейс в админке.

### 3.4. База данных расширения

- Отдельных таблиц нет. CPT `wsp_city`, мета в `wp_postmeta`. Ключи мета перечислены в `WSCities_CPT::META_FIELDS` (страна, координаты, даты, население, площадь, плотность, фрагментация, Blocks & Roads в виде JSON).

---

## 4. Тема Ergonosphera: подключение платформы и карта

### 4.1. Файлы темы, связанные с платформой

- **inc/enqueue.php** — на главной подключается скрипт карты: `ergo-svg-map` (зависимости: leaflet, topojson-client). Платформа на `wp_enqueue_scripts` 99 добавляет к этому скрипту данные через `wp_localize_script('ergo-svg-map', 'wscMapData', ...)`.
- **assets/js/svg-map.js** — инициализация Leaflet, загрузка TopoJSON стран, раскраска по регионам. Если задан `wscMapData.active`, при клике по стране выполняется переход на `wscMapData.urls[a2]`; в тултипах показываются столица и население из `wscMapData`.
- **inc/plugin-api.php** — API темы для «тем данных»: фильтры `ergo_data_themes`, `ergo_country_data_tabs`, `ergo_map_data`, `ergo_map_legend` и др. Платформа расширения регистрирует вкладки и слои через свой API, а не через эти фильтры темы; карта на главной использует данные платформы через `wscMapData`.
- **front-page.php** — секция карты мира: контейнер `#ergo-world-map`, легенда регионов, при необходимости выбор темы данных. Скрипт `ergo-svg-map` рисует карту в этом контейнере.
- **archive-country.php, single-country.php** — шаблоны темы для типа `country`. Платформа регистрирует CPT `wsp_country` с rewrite slug `country` и в `resolve_slug_conflict()` снимает регистрацию CPT темы `country`, поэтому фактически используются шаблоны платформы (single-wsp_country, archive-wsp_country).

### 4.2. Данные карты (wscMapData)

Формируются в `WorldStat_Map::get_country_map_data()` (кэш — transient): для каждой опубликованной страны берутся permalink, население, название, столица по ISO2. В JS доступны: `wscMapData.active`, `wscMapData.urls`, `wscMapData.population`, `wscMapData.names`, `wscMapData.capitals`, `wscMapData.countriesUrl`, `wscMapData.compareUrl`.

---

## 5. Пошаговая инструкция: новое расширение со статистикой на карте

### Шаг 1. Создание структуры плагина

1. Создайте папку `wp-content/plugins/worldstat-{slug}/` (например, `worldstat-economy`).
2. Главный файл: `worldstat-{slug}.php` (Plugin Name, Description, Requires Plugins: world-statistics-platform).
3. В начале главного файла проверьте наличие класса `WorldStat_Core`; если его нет — выведите admin notice и не регистрируйте расширение.
4. Подключите классы расширения через `require_once`.

### Шаг 2. Регистрация расширения (на worldstat_init)

```php
add_action( 'worldstat_init', function () {
    WorldStat_Extensions::register( [
        'id'                => 'economy',           // уникальный slug
        'name'              => 'Economy & Trade',
        'version'           => '1.0.0',
        'requires_platform' => '1.0.0',
        'icon'              => 'dashicons-chart-line',
        'description'       => 'ВВП, торговля, показатели экономики.',
    ] );
} );
```

Регистрация возможна только до конца хука `worldstat_init` (после него расширения «закрыты»).

### Шаг 3. Провайдеры данных (метрики по странам)

Если расширение отдаёт метрики для REST и сравнения:

```php
WorldStat_Extensions::add_data_provider( 'economy', [
    'metrics' => [
        'gdp' => [
            'label'    => 'ВВП (млн USD)',
            'type'     => 'integer',
            'unit'     => 'млн USD',
            'callback' => [ 'WSEconomy_Data', 'get_gdp' ],
        ],
    ],
] );
```

Класс данных должен реализовать статические методы с сигнатурой `(string $iso2): value`.

### Шаг 4. Вкладка на странице страны

```php
WorldStat_Extensions::add_country_tab( 'economy', [
    'title'    => 'Экономика',
    'icon'     => 'dashicons-chart-line',
    'callback' => [ 'WSEconomy_Renderer', 'render_country_tab' ],
    'priority' => 30,
] );
```

Колбек получает один аргумент — ISO2 страны. Внутри можно вызывать `WorldStat_UI::stats_grid()`, `WorldStat_UI::chart()`, `WorldStat_UI::table()`, `WorldStat_UI::map()` и т.д. Контент вкладки подгружается по AJAX при первом открытии вкладки.

### Шаг 5. Слой карты (хороплет / заливка стран)

Если нужно раскрашивать страны по значению метрики на главной карте:

```php
WorldStat_Extensions::add_map_layer( 'economy', [
    'label'         => 'ВВП',
    'type'          => 'choropleth',
    'color_scale'   => [ '#f0f0f0', '#003d99' ],
    'data_callback' => [ 'WSEconomy_Data', 'get_map_data' ],
] );
```

`data_callback` без аргументов возвращает массив `[ 'RU' => value, 'US' => value, ... ]`.

### Шаг 6. Маркеры на карте (точки по координатам)

Если у вас есть объекты с координатами (города, объекты, события):

```php
WorldStat_Extensions::add_map_markers( 'economy', [
    'label'            => 'Ключевые объекты',
    'icon'             => 'circle',   // circle | pin | square | diamond
    'color'            => '#ef4444',
    'radius'           => 6,
    'data_callback'    => [ 'WSEconomy_Data', 'get_all_markers' ],       // все маркеры
    'country_callback' => [ 'WSEconomy_Data', 'get_country_markers' ],    // по стране (для мини-карты на странице страны)
] );
```

Формат одного маркера: `[ 'lat' => float, 'lng' => float, 'title' => string, 'value' => mixed, 'popup' => string ]`. Для мини-карты на странице страны платформа вызывает `country_callback( $iso2 )`, если он задан.

### Шаг 7. Собственный CPT и мета (при необходимости)

Если расширение хранит свои сущности (как города):

- Зарегистрируйте CPT в `init` (например, `wsp_economy_region`).
- Зарегистрируйте мета через `register_post_meta()`.
- Для одиночных страниц этого типа зарегистрируйте шаблон через фильтр `worldstat_single_template`:

```php
add_filter( 'worldstat_single_template', function ( $template, $post_type ) {
    if ( $post_type === WSEconomy_CPT::SLUG ) {
        $path = WSEconomy_DIR . 'templates/single-wsp_economy_region.php';
        return file_exists( $path ) ? $path : $template;
    }
    return $template;
}, 10, 2 );
```

- Чтобы на этих страницах подключались стили/скрипты платформы, добавьте тип в фильтр `worldstat_extension_post_types`.

### Шаг 8. Использование мини-карты во вкладке страны

Во вкладке можно вывести карту с вашей подложкой «страны» и своими маркерами:

```php
WorldStat_UI::map( [
    'lat'           => $center_lat,
    'lng'           => $center_lng,
    'zoom'          => 5,
    'height'        => 450,
    'grid'          => true,
    'grid_labels'   => true,
    'marker_layers' => [ 'economy' ],
    'country'       => $iso2,
    'layer_control' => true,
    'tile_style'    => 'countries',
] );
```

`tile_style => 'countries'` использует ту же карту, что и на главной (полигоны стран из TopoJSON темы + координатная сетка), и на неё накладываются маркеры выбранного слоя.

### Шаг 9. REST API

Данные расширения автоматически попадают в REST платформы:

- `GET /worldstat/v1/countries/{code}/data` — все данные по стране, включая метрики расширений.
- `GET /worldstat/v1/data/{ext_id}/{code}/{metric}` — одна метрика.
- `GET /worldstat/v1/map-markers` — список слоёв маркеров.
- `GET /worldstat/v1/map-markers/{id}?country=XX` — маркеры слоя (опционально по стране).

Дополнительные маршруты при необходимости можно зарегистрировать отдельно в теме или в своём плагине.

### Шаг 10. Шаблон из SDK

Можно взять за основу копию папки `world-statistics-platform/sdk/extension-boilerplate/`: переименовать файлы и классы (например, WSE_ → WSEconomy_), в главном файле заменить регистрацию на свою (id, name, метрики, вкладка, слои/маркеры). Подробности — в `sdk/extension-boilerplate/README.md`.

---

## 6. Краткая справка: хуки и фильтры платформы

**Действия:**

- `worldstat_init` — регистрация расширений, вкладок, слоёв, маркеров.
- `worldstat_loaded` — после полной инициализации.
- `worldstat_extension_registered` — после регистрации расширения (аргументы: id, config).
- `worldstat_country_after_content` — после контента вкладки «Обзор» на странице страны.
- `worldstat_activated`, `worldstat_deactivated` — при активации/деактивации платформы.

**Фильтры:**

- `worldstat_single_template` — подмена шаблона single для своего CPT (аргументы: текущий путь, post_type).
- `worldstat_extension_post_types` — массив типов записей, считающихся «страницами платформы» (для подключения CSS/JS).
- `worldstat_country_tabs` — список вкладок страницы страны.
- `worldstat_get_data` — подмена значения метрики (аргументы: null, ext_id, country_code, metric).
- `worldstat_is_platform_page` — добавить свою страницу в зону действия платформы.

---

## 7. Чек-лист нового расширения

- [ ] Папка плагина и главный файл с проверкой `WorldStat_Core`.
- [ ] Регистрация на `worldstat_init`: `WorldStat_Extensions::register()`.
- [ ] При необходимости: `add_data_provider()` с метриками и колбеками.
- [ ] Вкладка страны: `add_country_tab()` с callback, выводящим через `WorldStat_UI::*`.
- [ ] При необходимости: `add_map_layer()` с `data_callback` (ISO2 => value).
- [ ] При необходимости: `add_map_markers()` с `country_callback` и/или `data_callback`.
- [ ] При необходимости: свой CPT и мета, шаблон single через `worldstat_single_template`, тип в `worldstat_extension_post_types`.
- [ ] Мини-карта во вкладке: `WorldStat_UI::map()` с `marker_layers` и `tile_style => 'countries'` при желании использовать карту как на главной.
- [ ] Проверка на странице страны (вкладки, AJAX) и на главной (клик по стране, при наличии слоёв/маркеров).

Документ можно расширять: добавить примеры REST-запросов, описание форматов CSV импорта (как в cities) и т.д.
