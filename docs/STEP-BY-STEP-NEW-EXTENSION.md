# Пошаговый алгоритм создания плагина-расширения (на примере worldstat-cities)

Руководство для начинающих: как создать новый плагин-расширение для World Statistics Platform, ориентируясь на плагин **worldstat-cities**. В конце — как превратить копию worldstat-cities в новый плагин с другой тематикой.

---

## Что нужно перед началом

1. **Установлен и активирован основной плагин** `world-statistics-platform`. Без него расширение не будет работать.
2. **Базовое понимание WordPress:** что такое плагин, хук (`add_action`, `add_filter`), как подключаются файлы (`require_once`).
3. **Редактор кода** (например, VS Code или Cursor) и доступ к папке `wp-content/plugins/`.

Расширение **не создаёт своих таблиц в БД** — оно использует типы записей (CPT) и мета-поля WordPress (`wp_posts`, `wp_postmeta`). Если ваша тема — «города», вы можете хранить города как записи типа `wsp_city` с мета-полями (страна, координаты, население и т.д.).

---

## Часть 1. Алгоритм создания расширения с нуля

Ниже — пошаговый алгоритм. Каждый шаг пояснён и показан на примере worldstat-cities.

---

### Шаг 1. Создать папку и главный файл плагина

**Что делаем:** создаём папку плагина и один PHP-файл с заголовком плагина. WordPress по этому заголовку распознает плагин и покажет его в списке «Плагины».

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` (первые 30 строк).

**Действия:**

1. В папке `wp-content/plugins/` создайте папку с именем плагина, например: `worldstat-cities` или `worldstat-economy`. Имя папки обычно совпадает с именем главного файла (без `.php`).
2. В этой папке создайте файл с тем же именем и расширением `.php`: `worldstat-cities.php`.
3. В начале файла напишите **заголовок плагина** в формате комментария:

```php
<?php
/**
 * Plugin Name:       WorldStat — Cities Extension
 * Plugin URI:        https://example.com
 * Description:       Краткое описание того, что делает плагин.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform
 * Author:            Ваше имя
 * License:           GPL v2 or later
 * Text Domain:       worldstat-cities
 */
```

**Важно для расширений:** строка `Requires Plugins: world-statistics-platform` сообщает WordPress, что плагин зависит от основного. Пользователь не сможет активировать расширение без платформы.

4. Сразу после заголовка добавьте защиту от прямого вызова и константы:

```php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSCITIES_VERSION', '1.0.0' );
define( 'WSCITIES_FILE',    __FILE__ );
define( 'WSCITIES_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WSCITIES_URL',     plugin_dir_url( __FILE__ ) );
```

- `ABSPATH` — WordPress определяет эту константу при загрузке; проверка не даёт выполнить файл напрямую по URL.
- `WSCITIES_DIR` и `WSCITIES_URL` понадобятся для подключения других файлов и стилей/скриптов. В своём плагине замените префикс `WSCITIES` на свой (например, `WSECONOMY`).

---

### Шаг 2. Проверить наличие основного плагина

**Что делаем:** если платформа не активирована, не подключаем код расширения и показываем сообщение в админке. Иначе при активации расширения без платформы появятся фатальные ошибки.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` (блок «Check that the platform is active»).

**Действия:**

После констант добавьте:

```php
if ( ! class_exists( 'WorldStat_Core' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WorldStat Cities</strong> requires <strong>World Statistics Platform</strong>.</p></div>';
    } );
    return;
}
```

- `WorldStat_Core` — главный класс платформы; он создаётся при загрузке основного плагина.
- Если класса нет, мы вешаем вывод уведомления на `admin_notices` и выходим (`return`), то есть дальше ничего не подключаем.

В своём плагине замените текст «WorldStat Cities» на название вашего расширения.

---

### Шаг 3. Подключить классы расширения

**Что делаем:** подключаем все PHP-файлы с логикой расширения (CPT, данные, вывод вкладки, админка и т.д.), чтобы их классы были доступны.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` (блок «Include files»).

**Действия:**

Используйте `require_once` и константу с путём к папке плагина:

```php
require_once WSCITIES_DIR . 'includes/class-cities-cpt.php';
require_once WSCITIES_DIR . 'includes/class-cities-data.php';
require_once WSCITIES_DIR . 'includes/class-cities-renderer.php';
require_once WSCITIES_DIR . 'includes/class-cities-admin.php';
require_once WSCITIES_DIR . 'includes/class-cities-importer.php';
```

Порядок может быть важен, если один класс использует другой (например, Renderer использует CPT и Data). В cities порядок такой: CPT → Importer → Data → Renderer → Admin.

Для минимального расширения без импорта и сложной админки достаточно: один класс CPT (если нужны свои записи), один класс Data (метрики и маркеры), один класс Renderer (вкладка страны). Админку и импорт можно добавить позже.

---

### Шаг 4. Зарегистрировать расширение на хуке worldstat_init

**Что делаем:** говорим платформе: «есть расширение с таким id, именем и версией». Регистрация возможна **только** внутри хука `worldstat_init` — после него платформа «закрывает» приём новых расширений.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — весь блок `add_action( 'worldstat_init', function () { ... } );`.

**Действия:**

Оберните всю регистрацию (расширение, метрики, вкладка, слои карты, маркеры) в один хук:

```php
add_action( 'worldstat_init', function () {

    WorldStat_Extensions::register( [
        'id'                => 'cities',
        'name'              => 'Cities & Urban Areas',
        'version'           => WSCITIES_VERSION,
        'author'            => 'World Statistics Team',
        'description'       => 'Данные о городах: население, площадь, плотность...',
        'icon'              => 'dashicons-building',
        'requires_platform' => '1.0.0',
    ] );

    // Сюда позже добавим провайдеры данных, вкладку, слои и маркеры (шаги 5–8).
} );
```

- **id** — уникальный короткий slug (латиница, цифры, подчёркивание). Он используется везде: в REST API (`/data/cities/...`), в вызовах `add_data_provider('cities', ...)`, `add_country_tab('cities', ...)` и т.д.
- **icon** — класс иконки Dashicons (например, `dashicons-building`, `dashicons-chart-line`). Будет отображаться во вкладке и в списке расширений.
- **requires_platform** — минимальная версия платформы; при меньшей версии расширение не зарегистрируется и выведется предупреждение.

---

### Шаг 5. Добавить провайдеры данных (метрики по странам)

**Что делаем:** объявляем метрики, которые расширение отдаёт по коду страны (например: количество городов, население крупнейшего города). Платформа будет вызывать ваши функции при запросе REST API или при сравнении стран.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — вызов `WorldStat_Extensions::add_data_provider('cities', [ 'metrics' => [ ... ] ])`; реализация — в `class-cities-data.php` (методы `get_cities_count`, `get_largest_city_population` и т.д.).

**Действия:**

1. В том же колбеке `worldstat_init` после `register()` добавьте:

```php
WorldStat_Extensions::add_data_provider( 'cities', [
    'metrics' => [
        'cities_count' => [
            'label'       => 'Количество городов',
            'type'        => 'integer',
            'unit'        => '',
            'description' => 'Число городов в базе для данной страны',
            'callback'    => [ 'WSCities_Data', 'get_cities_count' ],
        ],
        'largest_city_pop' => [
            'label'    => 'Крупнейший город (нас.)',
            'type'     => 'integer',
            'unit'     => 'чел.',
            'callback' => [ 'WSCities_Data', 'get_largest_city_population' ],
        ],
        // ... другие метрики
    ],
] );
```

2. Создайте класс данных (например, `WSCities_Data`) в файле `includes/class-cities-data.php`. Каждая метрика — это статический метод с сигнатурой `(string $iso2)` и возвращаемым значением (число, строка):

```php
public static function get_cities_count( string $iso2 ): int {
    return WSCities_CPT::count_cities_for_country( $iso2 );
}

public static function get_largest_city_population( string $iso2 ): int {
    $cities = WSCities_CPT::get_cities_for_country( $iso2 );
    return empty( $cities ) ? 0 : $cities[0]['pop_t3'];
}
```

- **type** — `integer`, `number` или `string`; влияет на отображение и REST.
- **callback** — массив `[ 'ИмяКласса', 'имя_метода' ]` или функция. Платформа вызовет `callback( $iso2 )` при запросе метрики для страны.

Если расширение не отдаёт метрики по странам (только вкладка и карта), шаг 5 можно пропустить.

---

### Шаг 6. Добавить вкладку на страницу страны

**Что делаем:** добавляем на страницу страны (например, `/country/russia/`) новую вкладку (например, «Города»). При первом клике по вкладке контент подгружается по AJAX; платформа вызывает ваш callback и подставляет полученный HTML.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — `add_country_tab('cities', ...)`; вывод контента — `class-cities-renderer.php`, метод `render_country_tab( $iso2 )`.

**Действия:**

1. В `worldstat_init` добавьте:

```php
WorldStat_Extensions::add_country_tab( 'cities', [
    'title'    => 'Города',
    'icon'     => 'dashicons-building',
    'callback' => [ 'WSCities_Renderer', 'render_country_tab' ],
    'priority' => 20,
] );
```

- **title** — подпись вкладки.
- **callback** — вызываемый код. Платформа передаёт ему один аргумент: ISO2 страны (например, `RU`).
- **priority** — порядок вкладок (меньше — левее). У «Обзор» 0, у «Города» 20.

2. Реализуйте метод вывода. В классе `WSCities_Renderer`:

```php
public static function render_country_tab( string $iso2 ): void {
    $cities = WSCities_CPT::get_cities_for_country( $iso2 );
    if ( empty( $cities ) ) {
        echo '<div class="wsp-notice"><p>Нет данных для этой страны.</p></div>';
        return;
    }
    // Дальше — вывод через WorldStat_UI::stats_grid(), ::chart(), ::table(), ::map()
}
```

Внутри callback можно использовать готовые компоненты платформы: `WorldStat_UI::stats_grid()`, `WorldStat_UI::chart()`, `WorldStat_UI::table()`, `WorldStat_UI::map()` — они дают единый стиль и не требуют своего HTML/CSS.

---

### Шаг 7. Добавить слой карты (хороплет, опционально)

**Что делаем:** если нужно раскрашивать страны на главной карте по значению (например, суммарное городское население по странам), регистрируем слой типа choropleth с callback, который возвращает массив ISO2 → значение.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — `add_map_layer('cities', ...)`; `class-cities-data.php` — метод `get_map_data()`.

**Действия:**

1. В `worldstat_init`:

```php
WorldStat_Extensions::add_map_layer( 'cities', [
    'label'         => 'Городское население',
    'type'          => 'choropleth',
    'color_scale'   => [ '#e0f2fe', '#0c4a6e' ],
    'data_callback' => [ 'WSCities_Data', 'get_map_data' ],
] );
```

2. В классе данных реализуйте метод без аргументов, возвращающий массив:

```php
public static function get_map_data(): array {
    global $wpdb;
    // Запрос: по каждой стране (wscity_country_iso2) сумма wscity_pop_t3
    $rows = $wpdb->get_results( "SELECT ..." );
    $result = [];
    foreach ( $rows as $r ) {
        $result[ strtoupper( $r->iso2 ) ] = (int) $r->total_pop;
    }
    return $result;
}
```

Если раскраска стран не нужна, шаг 7 можно пропустить.

---

### Шаг 8. Добавить маркеры на карте (точки по координатам)

**Что делаем:** показываем на карте точки с координатами (города, объекты). Платформа запрашивает маркеры через два callback: для всей карты и для одной страны (для мини-карты на странице страны).

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — `add_map_markers('cities', ...)`; `class-cities-data.php` — `get_all_city_markers()`, `get_country_city_markers( $iso2 )`, `build_markers()`.

**Действия:**

1. В `worldstat_init`:

```php
WorldStat_Extensions::add_map_markers( 'cities', [
    'label'            => 'Города мира',
    'icon'             => 'circle',
    'color'            => '#ef4444',
    'radius'           => 5,
    'data_callback'    => [ 'WSCities_Data', 'get_all_city_markers' ],
    'country_callback' => [ 'WSCities_Data', 'get_country_city_markers' ],
] );
```

- **data_callback** — вызывается без аргументов; возвращает массив маркеров для всей карты.
- **country_callback** — вызывается с одним аргументом `$iso2`; возвращает маркеры только для этой страны (используется в мини-карте на вкладке страны).
- **icon** — `circle`, `pin`, `square`, `diamond`.

2. Формат одного маркера в массиве:

```php
[
    'lat'     => 55.75,
    'lng'     => 37.62,
    'title'   => 'Москва',
    'value'   => '12.5 млн',
    'popup'   => '<strong>Москва</strong><br>Население: 12.5 млн',
    'radius'  => 8,
    'color'   => '#ef4444',
]
```

`value` и `popup` опциональны. Если не передать `radius`/`color`, возьмутся из настроек слоя.

В cities маркеры собираются из БД (записи типа `wsp_city` с мета `wscity_lat`, `wscity_lng`, `wscity_pop_t3` и т.д.), а массив формируется в общем методе `build_markers( $rows )`.

---

### Шаг 9. Зарегистрировать свой тип записей (CPT)

**Что делаем:** если расширение хранит свои сущности (города, объекты, события), регистрируем для них тип записи (Custom Post Type). Данные хранятся в `wp_posts`; дополнительные поля — в `wp_postmeta`.

**Где смотреть пример:**  
`worldstat-cities/includes/class-cities-cpt.php` — константа `SLUG`, массив `META_FIELDS`, методы `register()`, `register_meta()`, `get_cities_for_country()`.

**Действия:**

1. Создайте класс, например `WSCities_CPT`. В конструкторе повесьте на `init` регистрацию CPT и мета:

```php
add_action( 'init', [ $this, 'register' ] );
add_action( 'init', [ $this, 'register_meta' ] );
```

2. В `register()` вызовите `register_post_type()`:

```php
register_post_type( self::SLUG, [
    'labels'       => [ 'name' => 'Города', 'singular_name' => 'Город', ... ],
    'public'       => true,
    'show_ui'      => true,
    'show_in_menu' => false,
    'rewrite'      => [ 'slug' => 'city', 'with_front' => false ],
    'supports'     => [ 'title', 'custom-fields' ],
] );
```

`SLUG` в cities — `wsp_city`; URL одной записи будет вида `/city/moscow/`.

3. Опишите мета-поля в константе и зарегистрируйте их в `register_meta()` через `register_post_meta( self::SLUG, $key, [ 'type' => ..., 'single' => true, 'show_in_rest' => true ] )`.

4. Реализуйте методы выборки, например `get_cities_for_country( $iso2 )` — запрос к `$wpdb` по `post_type` и мета `wscity_country_iso2`.

5. В главном файле плагина после блока `worldstat_init` создайте экземпляр: `new WSCities_CPT();`.

Если расширение не хранит свои записи (только выводит данные из внешнего API или из опций), шаг 9 можно пропустить.

---

### Шаг 10. Подключить шаблон для одиночной записи (single)

**Что делаем:** чтобы при открытии одной записи вашего CPT (например, `/city/moscow/`) использовался ваш шаблон, подключаем его через фильтр платформы. Также говорим платформе, что страницы этого типа — «страницы платформы», чтобы подключались общие стили и скрипты.

**Где смотреть пример:**  
`worldstat-cities/worldstat-cities.php` — фильтры `worldstat_single_template` и `worldstat_extension_post_types`; шаблон — `templates/single-wsp_city.php`.

**Действия:**

1. Фильтр шаблона (в главном файле, вне `worldstat_init`):

```php
add_filter( 'worldstat_single_template', function ( string $template, string $post_type ): string {
    if ( $post_type === WSCities_CPT::SLUG ) {
        $path = WSCITIES_DIR . 'templates/single-wsp_city.php';
        if ( file_exists( $path ) ) return $path;
    }
    return $template;
}, 10, 2 );
```

2. Регистрация типа как «страницы платформы» (для CSS/JS):

```php
add_filter( 'worldstat_extension_post_types', function ( array $types ): array {
    $types[] = WSCities_CPT::SLUG;
    return $types;
} );
```

3. В папке `templates/` создайте файл, например `single-wsp_city.php`. В нём: `get_header()`, вывод заголовка и блоков из мета (население, площадь, карта и т.д.), `get_footer()`. Для вывода мини-карты можно вызвать `WorldStat_UI::map( [ 'marker_layers' => [ 'cities' ], 'country' => $iso2, 'tile_style' => 'countries', ... ] );`.

---

### Шаг 11. Админка (меню, импорт, колонки)

**Что делаем:** добавляем пункт меню в админке (например, под «World Statistics») и при необходимости страницу импорта данных (CSV) и колонки в списке записей CPT.

**Где смотреть пример:**  
`worldstat-cities/includes/class-cities-admin.php` — `register_menus()`, `render_import_page()`, AJAX-обработчики загрузки CSV; фильтры колонок для `edit.php?post_type=wsp_city`.

**Действия:**

1. Создайте класс, например `WSCities_Admin`. В конструкторе:
   - `add_action( 'admin_menu', [ $this, 'register_menus' ], 20 );`
   - при необходимости `add_action( 'admin_enqueue_scripts', ... )` и AJAX-действия для импорта.
2. В `register_menus()` вызовите `add_submenu_page( 'worldstat', 'Города — Импорт', 'Города', 'manage_options', 'worldstat-cities', [ $this, 'render_import_page' ] );`.
3. Импорт (если нужен) — отдельная логика: загрузка CSV, разбор, создание/обновление записей и мета. В cities это вынесено в `class-cities-importer.php` и вызывается из админки через AJAX.

Минимальное расширение может обойтись без своей админки (данные вносятся вручную через стандартный редактор записей CPT или импортируются один раз скриптом).

---

## Часть 2. Как модифицировать worldstat-cities в новый плагин

Ниже — практический способ получить новое расширение на основе worldstat-cities: копируем плагин, переименовываем идентификаторы и подстраиваем под новую тематику (например, «аэропорты», «университеты», «заводы»).

---

### Этап A. Копирование и переименование файлов

1. Скопируйте всю папку `worldstat-cities` в ту же папку `plugins/` и переименуйте копию, например в `worldstat-airports`.
2. В новой папке переименуйте главный файл: `worldstat-cities.php` → `worldstat-airports.php`.
3. Оставьте структуру папок: `includes/`, `templates/`, `assets/`.

Итог: у вас есть полная копия плагина с другим именем папки и главного файла.

---

### Этап B. Замена идентификаторов в главном файле

Откройте `worldstat-airports.php` и замените **везде**:

| Было (cities)           | Стало (airports)        |
|-------------------------|-------------------------|
| WorldStat — Cities Extension | WorldStat — Airports Extension |
| worldstat-cities        | worldstat-airports      |
| WSCITIES_               | WSAIRPORTS_             |
| WSCities_               | WSAirports_             |
| 'cities'                | 'airports'              |
| Cities & Urban Areas    | Airports & Transport    |
| Города                  | Аэропорты               |
| dashicons-building      | dashicons-airplane      |
| worldstat-cities        | worldstat-airports      |
| WSCities_CPT::SLUG      | WSAirports_CPT::SLUG    |
| WSCities_Data           | WSAirports_Data         |
| WSCities_Renderer       | WSAirports_Renderer     |
| single-wsp_city.php     | single-wsp_airport.php  (или оставить имя файла, но тогда в фильтре указать этот файл) |

Проверьте, что константы, вызовы `add_data_provider('airports', ...)`, `add_country_tab('airports', ...)`, `add_map_layer('airports', ...)`, `add_map_markers('airports', ...)` используют id `airports` и новые имена классов.

---

### Этап C. Переименование классов и файлов в includes/

1. **CPT**
   - Файл: `class-cities-cpt.php` → `class-airports-cpt.php`.
   - Класс: `WSCities_CPT` → `WSAirports_CPT`.
   - Константа: `SLUG` → например `'wsp_airport'` (тип записи в БД и в URL).
   - Массив `META_FIELDS`: замените ключи и комментарии под аэропорты (страна, широта, долгота, пассажиропоток, код IATA и т.д.). Префикс мета можно оставить `wsairport_` или свой.
   - В главном файле: `require_once ... class-airports-cpt.php` и `new WSAirports_CPT();`.

2. **Data**
   - Файл: `class-cities-data.php` → `class-airports-data.php`.
   - Класс: `WSCities_Data` → `WSAirports_Data`.
   - Все методы, которые вызываются из регистрации (метрики, `get_map_data`, `get_all_city_markers`, `get_country_city_markers`), переименуйте по смыслу (например, `get_all_airport_markers`, `get_country_airport_markers`).
   - Внутри замените обращение к CPT: `WSCities_CPT` → `WSAirports_CPT`, тип поста и имена мета — на новые (например, `wsp_airport`, `wsairport_country_iso2`, `wsairport_lat`, `wsairport_lng`).

3. **Renderer**
   - Файл: `class-cities-renderer.php` → `class-airports-renderer.php`.
   - Класс: `WSCities_Renderer` → `WSAirports_Renderer`.
   - Метод `render_country_tab( $iso2 )`: вместо городов подставляйте аэропорты — выборка через `WSAirports_CPT::get_airports_for_country( $iso2 )` (такой метод нужно добавить в CPT по аналогии с `get_cities_for_country`).
   - Вызовы `WorldStat_UI::stats_grid()`, `::chart()`, `::table()` заполните своими подписями и данными (количество аэропортов, крупнейший по пассажирам и т.д.).
   - Мини-карта: `WorldStat_UI::map( [ ..., 'marker_layers' => [ 'airports' ], 'country' => $iso2, 'tile_style' => 'countries' ] );`.

4. **Admin**
   - Файл: `class-cities-admin.php` → `class-airports-admin.php`.
   - Класс: `WSCities_Admin` → `WSAirports_Admin`.
   - Замените заголовки меню («Аэропорты», «Импорт аэропортов»), slug страницы (`worldstat-airports`), идентификаторы AJAX при необходимости (`wsairports_upload` и т.д.).
   - Колонки списка записей: фильтр `manage_<SLUG>_posts_columns` и вывод колонок — под поля аэропорта (страна, код IATA, пассажиры).

5. **Importer** (если оставляете импорт)
   - Файл: `class-cities-importer.php` → `class-airports-importer.php`.
   - Класс и все вызовы CPT/мета перевести на WSAirports_CPT и новые мета-ключи. Формат CSV задайте под свой источник данных.

В главном файле обновите все `require_once` на новые имена файлов и классов.

---

### Этап D. Шаблон single и фильтры

1. **Шаблон одиночной записи**
   - Скопируйте `templates/single-wsp_city.php` в `templates/single-wsp_airport.php` (или оставьте имя, но тогда в фильтре ниже укажите именно его).
   - В шаблоне замените: обращение к `WSCities_CPT::META_FIELDS` и мета — на `WSAirports_CPT` и свои ключи; заголовки и блоки контента — под аэропорт (название, страна, координаты, пассажиропоток, карта с одним маркером и т.д.).
   - Ссылку на страницу страны постройте через `WorldStat_Country_CPT::get_by_code( $iso2 )` и `get_permalink()`.

2. **Фильтр шаблона** (в главном файле)
   - Уже должно быть: для `post_type === WSAirports_CPT::SLUG` возвращать путь к `templates/single-wsp_airport.php`.

3. **Фильтр worldstat_extension_post_types**
   - Добавьте в массив `WSAirports_CPT::SLUG`, чтобы на странице одной записи аэропорта подключались стили/скрипты платформы.

---

### Этап E. Проверка и тестирование

1. В админке WordPress деактивируйте worldstat-cities, активируйте worldstat-airports. Убедитесь, что нет фатальных ошибок и что в меню «World Statistics» появился пункт нового расширения.
2. Откройте страницу любой страны: должна быть вкладка «Аэропорты» (или как вы назвали). При клике контент подгружается по AJAX; если данных ещё нет, можно вывести заглушку «Нет данных».
3. Если добавляли маркеры: на вкладке с мини-картой и `marker_layers => [ 'airports' ]` должны отображаться точки после того, как появятся записи типа `wsp_airport` с заполненными lat/lng.
4. Откройте одну запись аэропорта (если есть записи): URL вида `/airport/moscow-sheremetyevo/`, шаблон single с вашим контентом.

Рекомендуется делать замены по шагам (сначала только переименование и id, потом смена полей и логики), каждый раз проверяя работу в браузере и отсутствие ошибок PHP.

---

## Краткий чек-лист для нового расширения

- [ ] Папка и главный PHP-файл с заголовком плагина и `Requires Plugins: world-statistics-platform`.
- [ ] Проверка `class_exists( 'WorldStat_Core' )` и выход при отсутствии платформы.
- [ ] Константы (VERSION, DIR, URL) и подключение классов через `require_once`.
- [ ] В `worldstat_init`: `WorldStat_Extensions::register()` с уникальным `id`.
- [ ] При необходимости: `add_data_provider()` с метриками и классом Data с методами `( $iso2 )`.
- [ ] `add_country_tab()` с callback вывода (класс Renderer, метод с `$iso2`).
- [ ] При необходимости: `add_map_layer()` с `data_callback` (массив ISO2 => value).
- [ ] При необходимости: `add_map_markers()` с `data_callback` и `country_callback` (формат маркера: lat, lng, title, value, popup).
- [ ] При необходимости: свой CPT (класс CPT, register, register_meta, методы выборки), `new Your_CPT();` в главном файле.
- [ ] Фильтр `worldstat_single_template` для своего CPT и шаблон в `templates/`.
- [ ] Фильтр `worldstat_extension_post_types` с SLUG вашего CPT.
- [ ] При необходимости: админка (меню, импорт, колонки).

Используя этот алгоритм и модификацию worldstat-cities по этапам A–E, можно последовательно создать или переделать расширение под любую тематику (города, аэропорты, университеты, предприятия и т.д.) с отображением статистики на карте и на странице страны.
