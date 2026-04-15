<?php
/**
 * Cross-extension Data API + global helper functions.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Data {

    private WorldStat_Extensions $extensions;

    public function __construct( WorldStat_Extensions $extensions ) {
        $this->extensions = $extensions;
        $this->register_global_functions();
        add_action( 'worldstat_country_after_content', [ $this, 'render_country_csv_block' ], 20, 3 );
    }

    /* ═══════════════════════════════════════════════════════
       STATIC API (WorldStat_Data::get, ::compare, etc.)
    ═══════════════════════════════════════════════════════ */

    /**
     * Get a metric value for a country from a specific extension.
     */
    public static function get( string $ext_id, string $country_code, string $metric ) {
        $platform = worldstat_platform();

        // Core data handled directly
        if ( $ext_id === 'core' ) {
            return self::get_core_data( $country_code, $metric );
        }

        return $platform->extensions->call_provider( $ext_id, $country_code, $metric );
    }

    /**
     * Compare countries across multiple metrics.
     */
    public static function compare( array $args ): array {
        $countries = $args['countries'] ?? [];
        $metrics   = $args['metrics'] ?? [];
        $result    = [];

        foreach ( $countries as $code ) {
            $row = [ 'code' => strtoupper( $code ) ];
            foreach ( $metrics as $metric_key ) {
                $parts = explode( '.', $metric_key, 2 );
                if ( count( $parts ) === 2 ) {
                    $row[ $metric_key ] = self::get( $parts[0], $code, $parts[1] );
                }
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Get all available metrics across all extensions.
     */
    public static function get_available_metrics(): array {
        return worldstat_platform()->extensions->get_all_metrics();
    }

    /**
     * Get data for all countries for a specific metric (for map coloring).
     */
    public static function get_for_map( string $ext_id, string $metric ): array {
        $map  = WorldStat_Country_CPT::get_code_map();
        $data = [];

        foreach ( $map as $iso2 => $post_id ) {
            $val = self::get( $ext_id, $iso2, $metric );
            if ( $val !== null ) {
                $data[ $iso2 ] = $val;
            }
        }

        return $data;
    }

    /**
     * Get a full country data array by ISO2 code.
     */
    public static function get_country( string $iso2 ): ?array {
        $post = WorldStat_Country_CPT::get_by_code( $iso2 );
        if ( ! $post ) return null;

        $meta = WorldStat_Meta::get_all_fields( $post->ID );
        $meta['id']    = $post->ID;
        $meta['title'] = $post->post_title;
        $meta['url']   = get_permalink( $post->ID );

        // Taxonomies
        $regions = wp_get_post_terms( $post->ID, WorldStat_Taxonomies::REGION );
        $subs    = wp_get_post_terms( $post->ID, WorldStat_Taxonomies::SUBREGION );
        $income  = wp_get_post_terms( $post->ID, WorldStat_Taxonomies::INCOME_GROUP );

        $meta['region']       = ( $regions && ! is_wp_error( $regions ) ) ? $regions[0]->name : '';
        $meta['subregion']    = ( $subs && ! is_wp_error( $subs ) )       ? $subs[0]->name : '';
        $meta['income_group'] = ( $income && ! is_wp_error( $income ) )   ? $income[0]->name : '';

        return $meta;
    }

    /**
     * Get all countries (lightweight list).
     */
    public static function get_countries( array $args = [] ): array {
        $defaults = [ 'region' => '', 'orderby' => 'title', 'order' => 'ASC', 'per_page' => 200 ];
        $args = wp_parse_args( $args, $defaults );

        $query_args = [
            'post_type'      => WorldStat_Country_CPT::SLUG,
            'posts_per_page' => $args['per_page'],
            'post_status'    => 'publish',
            'orderby'        => $args['orderby'],
            'order'          => $args['order'],
        ];

        if ( $args['region'] ) {
            $query_args['tax_query'] = [ [
                'taxonomy' => WorldStat_Taxonomies::REGION,
                'field'    => 'slug',
                'terms'    => $args['region'],
            ] ];
        }

        $posts = get_posts( $query_args );
        $out   = [];

        foreach ( $posts as $p ) {
            $iso2 = get_post_meta( $p->ID, 'wsp_iso_alpha2', true );
            $out[] = [
                'id'         => $p->ID,
                'title'      => $p->post_title,
                'iso2'       => $iso2,
                'iso3'       => get_post_meta( $p->ID, 'wsp_iso_alpha3', true ),
                'flag'       => get_post_meta( $p->ID, 'wsp_flag', true ),
                'population' => (int) get_post_meta( $p->ID, 'wsp_population', true ),
                'url'        => get_permalink( $p->ID ),
            ];
        }

        return $out;
    }

    /* ═══════════════════════════════════════════════════════
       CORE DATA PROVIDER
    ═══════════════════════════════════════════════════════ */

    private static function get_core_data( string $code, string $metric ) {
        $post = WorldStat_Country_CPT::get_by_code( $code );
        if ( ! $post ) return null;

        $key = 'wsp_' . $metric;
        if ( array_key_exists( $key, WorldStat_Meta::FIELDS ) ) {
            $raw = get_post_meta( $post->ID, $key, true );
            $type = WorldStat_Meta::FIELDS[ $key ]['type'] ?? 'string';
            return match ( $type ) {
                'integer' => (int) $raw,
                'number'  => (float) $raw,
                default   => (string) $raw,
            };
        }

        return null;
    }

    /* ═══════════════════════════════════════════════════════
       GLOBAL HELPER FUNCTIONS
    ═══════════════════════════════════════════════════════ */

    private function register_global_functions(): void {
        if ( function_exists( 'worldstat_get_data' ) ) return;

        /* These are defined once and delegate to the static API */
    }

    /**
     * Render CSV metrics block on single country page.
     */
    public function render_country_csv_block( int $post_id, string $iso2, array $meta ): void {
        $iso3 = strtoupper( (string) ( $meta['iso_alpha3'] ?? '' ) );
        if ( strlen( $iso3 ) !== 3 ) {
            return;
        }

        $rows = $this->get_country_csv_rows( $iso3, $post_id );
        if ( empty( $rows ) ) {
            return;
        }

        $all_years = [];
        foreach ( $rows as $row ) {
            if ( ! empty( $row['years'] ) && is_array( $row['years'] ) ) {
                $all_years = array_merge( $all_years, array_keys( $row['years'] ) );
            }
        }
        $all_years = array_values( array_unique( array_map( 'intval', $all_years ) ) );
        rsort( $all_years, SORT_NUMERIC );
        if ( empty( $all_years ) ) {
            return;
        }

        static $metric_icons = [
            'population_total'        => [ 'label' => 'Население', 'icon' => 'groups' ],
            'urban_land_area_sqkm'    => [ 'label' => 'Площадь урбанизированных территорий', 'icon' => 'building' ],
            'largest_city_population' => [ 'label' => 'Население крупнейшего города', 'icon' => 'admin-home' ],
            'forest_percentage'       => [ 'label' => 'Леса (% от территории)', 'icon' => 'tree' ],
        ];

        $grid_items = [];
        foreach ( $rows as $index => $row ) {
            $slug  = $this->normalize_metric_slug(
                (string) ( $row['slug'] ?? '' ),
                (string) ( $row['label'] ?? '' )
            );
            $label = trim( (string) ( $row['label'] ?? '' ) );
            if ( $slug === '' || $slug === 'value' || $label === '' || ! isset( $row['value'] ) ) {
                continue;
            }

            $nice = $metric_icons[ $slug ] ?? [
                'label' => $this->humanize_label( $label ),
                'icon'  => 'chart-bar',
            ];
            $grid_items[] = [
                'label'      => $nice['label'],
                'value'      => $this->format_csv_value( (float) $row['value'] ),
                'icon'       => $nice['icon'],
                'years_data' => is_array( $row['years'] ?? null ) ? $row['years'] : [],
                'metric_id'  => 'csv-metric-' . $index,
            ];
        }
        if ( empty( $grid_items ) ) {
            return;
        }

        echo '<section class="wsp-country-csv-block">';
        echo '<div class="wsp-csv-year-header">';
        echo '<h3>' . esc_html__( 'Данные из загруженных CSV', 'flavor-worldstat' ) . '</h3>';
        echo '<div class="wsp-csv-year-selector">';
        echo '<label for="global-csv-year">' . esc_html__( 'Год:', 'flavor-worldstat' ) . '</label>';
        echo '<select id="global-csv-year" class="wsp-select">';
        foreach ( $all_years as $y ) {
            echo '<option value="' . esc_attr( (string) $y ) . '">' . esc_html( (string) $y ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';
        WorldStat_UI::stats_grid( $grid_items, [ 'columns' => 4 ] );
        echo '</section>';
        $this->render_country_csv_styles();
        $this->render_global_year_script( $grid_items );
    }

    /**
     * Get values from all configured CSV files for country ISO3.
     */
    /**
     * @param int $post_id ID поста страны (для мета wsp_metric_* после импорта CSV).
     */
    private function get_country_csv_rows( string $iso3, int $post_id = 0 ): array {
        if ( ! class_exists( 'WorldStat_Uploaded_Csv' ) ) {
            return $post_id > 0 ? $this->get_country_meta_metric_rows( $post_id ) : [];
        }

        $rev  = (int) get_option( 'wsp_csv_files_revision', 0 );
        $mrev = class_exists( 'WorldStat_Csv_Country_Meta_Importer' )
            ? (int) get_option( WorldStat_Csv_Country_Meta_Importer::OPTION_IMPORT_REVISION, 0 )
            : 0;
        $cache_key = 'wsp_csv_country_' . strtolower( $iso3 ) . '_r' . $rev . '_m' . $mrev;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && $this->is_valid_country_csv_cache( $cached ) ) {
            return $cached;
        }

        $rows_by_slug = [];

        foreach ( WorldStat_Uploaded_Csv::list_files() as $file_row ) {
            $kind = (string) ( $file_row['dataset_kind'] ?? WorldStat_Uploaded_Csv::KIND_COUNTRY );
            if ( $kind !== WorldStat_Uploaded_Csv::KIND_COUNTRY ) {
                continue;
            }

            $id = (int) ( $file_row['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }

            $csv_body = WorldStat_Uploaded_Csv::get_body_by_id( $id );
            if ( $csv_body === '' ) {
                continue;
            }

            $series = $this->read_country_csv_series_from_string( $csv_body, $iso3 );

            if ( empty( $series ) ) {
                continue;
            }

            krsort( $series, SORT_NUMERIC );
            $latest_year = (int) array_key_first( $series );

            $label = $this->label_from_uploaded_csv_name( $file_row['name'] ?? '' );
            $slug  = $this->normalize_metric_slug( (string) ( $file_row['name'] ?? '' ), $label );
            $this->upsert_country_csv_row( $rows_by_slug, $slug, $label, $series );
        }

        if ( $post_id > 0 ) {
            foreach ( $this->get_country_meta_metric_rows( $post_id ) as $row ) {
                $slug = $this->normalize_metric_slug(
                    (string) ( $row['slug'] ?? '' ),
                    (string) ( $row['label'] ?? '' )
                );
                $years = is_array( $row['years'] ?? null ) ? (array) $row['years'] : [];
                $this->upsert_country_csv_row( $rows_by_slug, $slug, (string) ( $row['label'] ?? '' ), $years );
            }
        }

        $rows = array_values( $rows_by_slug );
        set_transient( $cache_key, $rows, HOUR_IN_SECONDS * 6 );
        return $rows;
    }

    /**
     * Ряды из post meta (импорт CSV): ключ wsp_metric_{slug} → [ год => значение ].
     *
     * @return list<array{label:string,year:int,value:float,years:array<int,float>}>
     */
    private function get_country_meta_metric_rows( int $post_id ): array {
        if ( $post_id < 1 || ! class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) ) {
            return [];
        }

        global $wpdb;
        $prefix = WorldStat_Csv_Country_Meta_Importer::META_PREFIX;
        $like   = $wpdb->esc_like( $prefix ) . '%';
        $keys   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s ORDER BY meta_key ASC",
                $post_id,
                $like
            )
        );

        if ( empty( $keys ) ) {
            return [];
        }

        $out = [];
        foreach ( $keys as $meta_key ) {
            $data = get_post_meta( $post_id, $meta_key, true );
            if ( ! is_array( $data ) || empty( $data ) ) {
                continue;
            }

            $series = [];
            foreach ( $data as $y => $v ) {
                $yi = (int) $y;
                if ( $yi <= 0 || ! is_numeric( $v ) ) {
                    continue;
                }
                $fv = (float) $v;
                if ( ! is_finite( $fv ) ) {
                    continue;
                }
                $series[ $yi ] = $fv;
            }

            if ( empty( $series ) ) {
                continue;
            }

            krsort( $series, SORT_NUMERIC );
            $latest_year = (int) array_key_first( $series );
            $slug        = substr( (string) $meta_key, strlen( $prefix ) );

            $out[] = [
                'label' => WorldStat_Csv_Country_Meta_Importer::human_label_for_slug( $slug ),
                'year'  => $latest_year,
                'value' => (float) $series[ $latest_year ],
                'years' => $series,
                'slug'  => $slug,
            ];
        }

        return $out;
    }

    /**
     * Нормализованный slug метрики для дедупликации между источниками.
     */
    private function normalize_metric_slug( string $raw_slug, string $label = '' ): string {
        $base = $raw_slug !== '' ? $raw_slug : $label;
        $base = strtolower( preg_replace( '/\.csv$/i', '', trim( $base ) ) );
        $base = preg_replace( '/[^a-z0-9]+/i', '_', $base );
        $base = trim( (string) $base, '_' );
        return sanitize_key( $base );
    }

    /**
     * Upsert строки метрики по slug + merge рядов по годам.
     *
     * @param array<string,array<string,mixed>> $rows_by_slug
     * @param array<int,float|int|string>       $series
     */
    private function upsert_country_csv_row( array &$rows_by_slug, string $slug, string $label, array $series ): void {
        if ( $slug === '' || $slug === 'value' ) {
            return;
        }

        $clean_series = [];
        foreach ( $series as $y => $v ) {
            $yi = (int) $y;
            if ( $yi <= 0 || ! is_numeric( $v ) ) {
                continue;
            }
            $fv = (float) $v;
            if ( ! is_finite( $fv ) ) {
                continue;
            }
            $clean_series[ $yi ] = $fv;
        }

        if ( empty( $clean_series ) ) {
            return;
        }

        if ( isset( $rows_by_slug[ $slug ] ) ) {
            $existing_years = (array) ( $rows_by_slug[ $slug ]['years'] ?? [] );
            $clean_series   = array_replace( $existing_years, $clean_series );
        }

        krsort( $clean_series, SORT_NUMERIC );
        $latest_year = (int) array_key_first( $clean_series );
        $base_label  = trim( $label ) !== '' ? $label : ( $rows_by_slug[ $slug ]['label'] ?? $slug );

        $rows_by_slug[ $slug ] = [
            'label' => (string) $base_label,
            'slug'  => $slug,
            'year'  => $latest_year,
            'value' => (float) $clean_series[ $latest_year ],
            'years' => $clean_series,
        ];
    }

    /**
     * Human label for a metric row from uploaded filename.
     */
    private function label_from_uploaded_csv_name( string $filename ): string {
        $base = basename( $filename, '.csv' );
        $base = str_replace( [ '_', '-' ], ' ', $base );
        return $base !== '' ? $base : $filename;
    }

    /**
     * Validate cached CSV rows structure to avoid stale old-format cache.
     */
    private function is_valid_country_csv_cache( array $rows ): bool {
        if ( empty( $rows ) ) {
            return true;
        }

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                return false;
            }

            if ( ! isset( $row['label'], $row['year'], $row['value'], $row['years'] ) ) {
                return false;
            }

            if ( ! is_array( $row['years'] ) || empty( $row['years'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Разбор CSV-текста (ISO3, год, значение): первая непустая строка — заголовок.
     *
     * @return array<int,float>
     */
    private function read_country_csv_series_from_string( string $csv_body, string $iso3 ): array {
        $series     = [];
        $skip_first = true;

        foreach ( preg_split( "/\r\n|\n|\r/", $csv_body ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }

            $row = str_getcsv( $line );
            if ( $skip_first ) {
                $skip_first = false;
                continue;
            }

            if ( count( $row ) < 3 ) {
                continue;
            }

            if ( strtoupper( trim( (string) $row[0] ) ) !== $iso3 ) {
                continue;
            }

            $year  = (int) $row[1];
            $value = is_numeric( $row[2] ) ? (float) $row[2] : null;

            if ( null === $value || $year <= 0 ) {
                continue;
            }

            $series[ $year ] = $value;
        }

        return $series;
    }

    /**
     * Format numeric CSV value for human-readable output.
     */
    private function format_csv_value( float $value ): string {
        $precision = abs( $value - round( $value ) ) < 0.00001 ? 0 : 2;
        return number_format( $value, $precision, '.', ' ' );
    }

    /**
     * Красивое название для метрики, если нет в маппинге.
     */
    private function humanize_label( string $label ): string {
        $label = str_replace( [ '_', '-' ], ' ', trim( $label ) );
        return ucwords( strtolower( $label ) );
    }

    /**
     * JS для общего выбора года на странице страны.
     *
     * @param array<int,array<string,mixed>> $grid_items
     */
    private function render_global_year_script( array $grid_items ): void {
        ?>
        <script>
            (function() {
                if (window.wspCsvGlobalSelectorBound) {
                    return;
                }
                window.wspCsvGlobalSelectorBound = true;
                document.addEventListener('DOMContentLoaded', function () {
                    var select = document.getElementById('global-csv-year');
                    if (!select) {
                        return;
                    }

                    var dataMap = {};
                    <?php foreach ( $grid_items as $item ) : ?>
                        dataMap['<?php echo esc_js( (string) ( $item['metric_id'] ?? '' ) ); ?>'] = <?php echo wp_json_encode( $item['years_data'] ?? [] ); ?>;
                    <?php endforeach; ?>

                    function formatNumber(value) {
                        var raw = Number(value);
                        if (!Number.isFinite(raw)) {
                            return '—';
                        }
                        return Number.isInteger(raw)
                            ? raw.toLocaleString('ru-RU')
                            : raw.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }

                    function applyYear(year) {
                        document.querySelectorAll('.wsp-country-csv-block .wsp-stat-card').forEach(function (card) {
                            var metricId = card.getAttribute('data-metric-id');
                            if (!metricId || !dataMap[metricId]) {
                                return;
                            }
                            var value = dataMap[metricId][year];
                            if (value === undefined) {
                                return;
                            }
                            var valueEl = card.querySelector('.wsp-stat-value');
                            if (valueEl) {
                                valueEl.textContent = formatNumber(value);
                            }
                        });
                    }

                    select.addEventListener('change', function () {
                        applyYear(String(select.value));
                    });

                    if (select.value !== '') {
                        applyYear(String(select.value));
                    }
                });
            })();
        </script>
        <?php
    }

    /**
     * Defensive styles so theme/global select styles don't break year dropdown.
     */
    private function render_country_csv_styles(): void {
        ?>
        <style>
            .wsp-country-csv-block .wsp-csv-year-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 12px;
            }
            .wsp-country-csv-block .wsp-csv-year-selector {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 15px;
            }
            .wsp-country-csv-block .wsp-select {
                padding: 8px 14px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                background: #fff;
                font-size: 15px;
                color: #1f2937;
                cursor: pointer;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                transition: all 0.2s ease;
            }
            .wsp-country-csv-block .wsp-select:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59,130,246,0.2);
            }
        </style>
        <?php
    }

    /**
     * JS для страницы сравнения: заполнение CSV-метрик в карточках + глобальный выбор года.
     *
     * @param array<int,string> $countries
     */
    public function render_compare_csv_js_for_cards( array $countries ): void {
        $metrics  = [ 'forest_percentage', 'largest_city_population', 'urban_land_area_sqkm' ];
        $all_data = [];
        $all_years = [];

        foreach ( $countries as $iso2_raw ) {
            $iso2 = strtoupper( sanitize_text_field( (string) $iso2_raw ) );
            if ( strlen( $iso2 ) !== 2 ) {
                continue;
            }
            $post = WorldStat_Country_CPT::get_by_code( $iso2 );
            if ( ! $post ) {
                continue;
            }
            $iso3 = strtoupper( (string) get_post_meta( $post->ID, 'wsp_iso_alpha3', true ) );
            if ( strlen( $iso3 ) !== 3 ) {
                continue;
            }

            $rows = $this->get_country_csv_rows( $iso3, (int) $post->ID );
            if ( empty( $rows ) ) {
                continue;
            }

            $all_data[ $iso2 ] = [];
            foreach ( $rows as $row ) {
                $slug = $this->normalize_metric_slug(
                    (string) ( $row['slug'] ?? '' ),
                    (string) ( $row['label'] ?? '' )
                );
                if ( ! in_array( $slug, $metrics, true ) ) {
                    continue;
                }
                $years = is_array( $row['years'] ?? null ) ? (array) $row['years'] : [];
                $all_data[ $iso2 ][ $slug ] = $years;
                $all_years = array_merge( $all_years, array_keys( $years ) );
            }
        }

        $all_years = array_values( array_unique( array_map( 'intval', $all_years ) ) );
        rsort( $all_years, SORT_NUMERIC );
        ?>
        <script>
            (function() {
                if (window.wspCompareCsvCardsBound) {
                    return;
                }
                window.wspCompareCsvCardsBound = true;

                document.addEventListener('DOMContentLoaded', function () {
                    var select = document.getElementById('compare-global-year');
                    if (!select) {
                        return;
                    }

                    var csvData = <?php echo wp_json_encode( $all_data ); ?> || {};
                    var years = <?php echo wp_json_encode( $all_years ); ?> || [];
                    if (!years.length) {
                        return;
                    }

                    select.innerHTML = '';
                    years.forEach(function (year) {
                        var option = document.createElement('option');
                        option.value = String(year);
                        option.textContent = String(year);
                        select.appendChild(option);
                    });

                    function formatNumber(value, decimals) {
                        var n = Number(value);
                        if (!Number.isFinite(n)) {
                            return '—';
                        }
                        return n.toLocaleString('ru-RU', {
                            minimumFractionDigits: decimals,
                            maximumFractionDigits: decimals
                        });
                    }

                    function updateCards(year) {
                        document.querySelectorAll('.wsp-comparison-card[data-iso2]').forEach(function (card) {
                            var iso2 = (card.getAttribute('data-iso2') || '').toUpperCase();
                            var data = csvData[iso2] || {};

                            var forestEl = card.querySelector('.csv-forest');
                            if (forestEl) {
                                forestEl.textContent = data.forest_percentage && data.forest_percentage[year] !== undefined
                                    ? formatNumber(data.forest_percentage[year], 2)
                                    : '—';
                            }

                            var largestEl = card.querySelector('.csv-largest-city');
                            if (largestEl) {
                                largestEl.textContent = data.largest_city_population && data.largest_city_population[year] !== undefined
                                    ? formatNumber(data.largest_city_population[year], 0)
                                    : '—';
                            }

                            var urbanEl = card.querySelector('.csv-urban-area');
                            if (urbanEl) {
                                urbanEl.textContent = data.urban_land_area_sqkm && data.urban_land_area_sqkm[year] !== undefined
                                    ? formatNumber(data.urban_land_area_sqkm[year], 2)
                                    : '—';
                            }
                        });
                    }

                    select.addEventListener('change', function () {
                        updateCards(String(select.value));
                    });

                    select.value = String(years[0]);
                    updateCards(String(select.value));
                });
            })();
        </script>
        <?php
    }
}

/* ─── Global Functions ──────────────────────────────────── */

if ( ! function_exists( 'worldstat_get_data' ) ) {
    function worldstat_get_data( string $ext_id, string $country_code, string $metric ) {
        return WorldStat_Data::get( $ext_id, $country_code, $metric );
    }
}

if ( ! function_exists( 'worldstat_get_country' ) ) {
    function worldstat_get_country( string $iso2 ): ?array {
        return WorldStat_Data::get_country( $iso2 );
    }
}

if ( ! function_exists( 'worldstat_get_countries' ) ) {
    function worldstat_get_countries( array $args = [] ): array {
        return WorldStat_Data::get_countries( $args );
    }
}

if ( ! function_exists( 'worldstat_get_population' ) ) {
    function worldstat_get_population( string $iso2 ): int {
        return (int) WorldStat_Data::get( 'core', $iso2, 'population' );
    }
}

if ( ! function_exists( 'worldstat_compare_countries' ) ) {
    function worldstat_compare_countries( array $args ): array {
        return WorldStat_Data::compare( $args );
    }
}

if ( ! function_exists( 'worldstat_register_plugin' ) ) {
    function worldstat_register_plugin( string $id, array $config ): bool {
        $config['id'] = $id;
        return WorldStat_Extensions::register( $config );
    }
}

if ( ! function_exists( 'worldstat_register_provider' ) ) {
    function worldstat_register_provider( string $ext_id, string $metric, callable $callback, array $meta = [] ): void {
        $meta['callback'] = $callback;
        WorldStat_Extensions::add_data_provider( $ext_id, [ 'metrics' => [ $metric => $meta ] ] );
    }
}

if ( ! function_exists( 'worldstat_is_extension_active' ) ) {
    function worldstat_is_extension_active( string $id ): bool {
        return worldstat_platform()->extensions->is_registered( $id );
    }
}
