<?php
/**
 * Cross-extension Data API + global helper functions.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Data {

    private WorldStat_Extensions $extensions;

    /** @var int|null Год для метрик с рядами (импорт CSV → post meta). null — последний доступный год. */
    private static ?int $value_year_context = null;

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
     *
     * Для метрик CSV (csv-country-meta) можно передать `year` (целое > 0) — берётся значение за год, иначе последний доступный год.
     */
    public static function compare( array $args ): array {
        $countries = $args['countries'] ?? [];
        $metrics   = $args['metrics'] ?? [];
        $year_arg  = isset( $args['year'] ) ? (int) $args['year'] : 0;
        $saved     = self::$value_year_context;
        if ( $year_arg > 0 ) {
            self::$value_year_context = $year_arg;
        }

        try {
            $result = [];

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
        } finally {
            self::$value_year_context = $saved;
        }
    }

    /**
     * Get all available metrics across all extensions.
     */
    public static function get_available_metrics(): array {
        return worldstat_platform()->extensions->get_all_metrics();
    }

    /**
     * Показатели расширений + числовые поля страны (core.*) для каталога, карты и песочницы.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_available_metrics_with_core(): array {
        $metrics = self::get_available_metrics();
        $skip    = [
            'wsp_iso_alpha2', 'wsp_iso_alpha3', 'wsp_iso_numeric',
            'wsp_flag', 'wsp_flag_url', 'wsp_latitude', 'wsp_longitude',
        ];
        foreach ( WorldStat_Meta::FIELDS as $field_key => $def ) {
            if ( in_array( $field_key, $skip, true ) ) {
                continue;
            }
            $type = strtolower( (string) ( $def['type'] ?? 'string' ) );
            if ( ! in_array( $type, [ 'integer', 'number', 'float', 'double' ], true ) ) {
                continue;
            }
            $slug = str_replace( 'wsp_', '', $field_key );
            $key  = 'core.' . $slug;
            if ( isset( $metrics[ $key ] ) ) {
                continue;
            }
            $metrics[ $key ] = [
                'extension'     => 'core',
                'metric'        => $slug,
                'label'         => $def['label'] ?? $slug,
                'type'          => $def['type'],
                'unit'          => '',
                'description'   => '',
                'level'         => 'country',
            ];
        }
        return $metrics;
    }

    public static function set_value_year_context( ?int $year ): void {
        self::$value_year_context = ( $year !== null && $year > 0 ) ? $year : null;
    }

    public static function get_value_year_context(): ?int {
        return self::$value_year_context;
    }

    public static function reset_value_year_context(): void {
        self::$value_year_context = null;
    }

    /**
     * Get data for all countries for a specific metric (for map coloring).
     */
    public static function get_for_map( string $ext_id, string $metric ): array {
        $map = WorldStat_Country_CPT::get_code_map();

        if ( $ext_id === 'csv-country-meta' && class_exists( 'WorldStat_Csv_Country_Meta_Importer' ) ) {
            $year_ctx = self::get_value_year_context();
            return WorldStat_Csv_Country_Meta_Importer::get_metric_values_for_iso_list( array_keys( $map ), $metric, $year_ctx );
        }

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

        // PHP 8.3+: static-переменную нельзя инициализировать вызовами вроде __() — только «константные» выражения.
        static $metric_icons = null;
        if ( null === $metric_icons ) {
            $metric_icons = [
                'population_total'           => [ 'label' => __( 'Население', 'flavor-worldstat' ), 'icon' => 'groups' ],
                'population_density_per_km2' => [ 'label' => __( 'Плотность населения на км²', 'flavor-worldstat' ), 'icon' => 'chart-bar' ],
                'surface_area_sqkm'          => [ 'label' => __( 'Площадь территории, км²', 'flavor-worldstat' ), 'icon' => 'editor-expand' ],
                'urban_share_percent'        => [ 'label' => __( 'Доля городского населения, %', 'flavor-worldstat' ), 'icon' => 'admin-multisite' ],
                'urban_land_area_sqkm'       => [ 'label' => __( 'Площадь урбанизированных территорий, км²', 'flavor-worldstat' ), 'icon' => 'building' ],
                'largest_city_population'    => [ 'label' => __( 'Население крупнейшего города', 'flavor-worldstat' ), 'icon' => 'admin-home' ],
                'forest_percentage'          => [ 'label' => __( 'Леса (% от территории)', 'flavor-worldstat' ), 'icon' => 'chart-area' ],
                'railway_length'             => [ 'label' => __( 'Железные дороги, км', 'flavor-worldstat' ), 'icon' => 'migrate' ],
                'road_length'                => [ 'label' => __( 'Дороги, км', 'flavor-worldstat' ), 'icon' => 'car' ],
            ];
        }

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
        $this->render_country_csv_charts( $grid_items );
        echo '</section>';
        $this->render_country_csv_styles();
        $this->render_global_year_script( $grid_items );
    }

    /**
     * Линейные графики по годам для метрик с ≥2 точками (Chart.js / WSPChart).
     *
     * @param array<int,array<string,mixed>> $grid_items
     */
    private function render_country_csv_charts( array $grid_items ): void {
        $specs   = [];
        $chart_i = 0;
        foreach ( $grid_items as $item ) {
            $years = isset( $item['years_data'] ) && is_array( $item['years_data'] ) ? $item['years_data'] : [];
            if ( count( $years ) < 2 ) {
                continue;
            }
            if ( count( $specs ) >= 12 ) {
                break;
            }
            ksort( $years, SORT_NUMERIC );
            $labels = array_map( 'strval', array_keys( $years ) );
            $data    = array_values( $years );
            $data    = array_map(
                static function ( $v ) {
                    if ( ! is_numeric( $v ) ) {
                        return null;
                    }
                    $f = (float) $v;
                    return is_finite( $f ) ? $f : null;
                },
                $data
            );
            ++$chart_i;
            $cid = 'wsp-csv-chart-' . $chart_i;
            $specs[] = [
                'canvasId' => $cid,
                'title'    => (string) ( $item['label'] ?? '' ),
                'labels'   => $labels,
                'data'     => $data,
            ];
        }
        if ( empty( $specs ) ) {
            return;
        }

        WorldStat_UI::enqueue_chart_scripts();

        $json = wp_json_encode( $specs, JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            return;
        }

        $total_metrics = count( $grid_items );
        $shown         = count( $specs );
        ?>
        <div class="wsp-csv-charts-wrap">
            <h4 class="wsp-csv-charts-title"><?php esc_html_e( 'Динамика по годам', 'flavor-worldstat' ); ?></h4>
            <?php if ( $total_metrics > $shown ) : ?>
                <p class="wsp-csv-charts-note"><?php echo esc_html( sprintf( __( 'Показаны графики для %1$d из %2$d показателей (есть минимум две точки по годам).', 'flavor-worldstat' ), $shown, $total_metrics ) ); ?></p>
            <?php endif; ?>
            <div class="wsp-csv-charts-grid">
                <?php foreach ( $specs as $spec ) : ?>
                    <div class="wsp-csv-chart-card">
                        <h5 class="wsp-csv-chart-card__title"><?php echo esc_html( $spec['title'] ); ?></h5>
                        <div class="wsp-csv-chart-card__canvas" style="position:relative;height:220px;">
                            <canvas id="<?php echo esc_attr( $spec['canvasId'] ); ?>"></canvas>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function(){
            var specs = <?php echo $json; ?>;
            function paint(){
                if (typeof Chart === 'undefined' || !window.WSPChart || !specs || !specs.length) {
                    return false;
                }
                specs.forEach(function(s){
                    if (!s.canvasId || !s.labels || !s.data) return;
                    var color = '#2563eb';
                    window.WSPChart.render(s.canvasId, {
                        type: 'line',
                        title: '',
                        labels: s.labels,
                        datasets: [ { label: s.title, data: s.data, color: color } ],
                        xLabel: <?php echo wp_json_encode( __( 'Год', 'flavor-worldstat' ) ); ?>,
                        yLabel: '',
                        legend: false
                    });
                });
                return true;
            }
            var tryPaintAttempts = 0;
            var tryPaintMax = 80;
            function tryPaint(){
                if (paint()) return;
                tryPaintAttempts++;
                if (tryPaintAttempts >= tryPaintMax) {
                    return;
                }
                setTimeout(tryPaint, 120);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', tryPaint);
            } else {
                tryPaint();
            }
        })();
        </script>
        <?php
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
            if ( ! WorldStat_Uploaded_Csv::is_country_display_kind( $kind ) ) {
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
                        <?php
                        $mid = (string) ( $item['metric_id'] ?? '' );
                        $yd  = isset( $item['years_data'] ) && is_array( $item['years_data'] ) ? $item['years_data'] : [];
                        $yd_clean = [];
                        foreach ( $yd as $yk => $yv ) {
                            $yi = (int) $yk;
                            if ( $yi <= 0 || ! is_numeric( $yv ) ) {
                                continue;
                            }
                            $fv = (float) $yv;
                            if ( ! is_finite( $fv ) ) {
                                continue;
                            }
                            $yd_clean[ (string) $yi ] = $fv;
                        }
                        $yd_json = wp_json_encode( $yd_clean, JSON_UNESCAPED_UNICODE );
                        if ( false === $yd_json ) {
                            $yd_json = '{}';
                        }
                        ?>
                        dataMap['<?php echo esc_js( $mid ); ?>'] = <?php echo $yd_json; ?>;
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
            .wsp-csv-charts-wrap {
                margin-top: 28px;
                padding-top: 20px;
                border-top: 1px solid #e5e7eb;
            }
            .wsp-csv-charts-title {
                margin: 0 0 8px;
                font-size: 1.15rem;
                color: #111827;
            }
            .wsp-csv-charts-note {
                margin: 0 0 16px;
                font-size: 0.9rem;
                color: #6b7280;
            }
            .wsp-csv-charts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 18px;
            }
            .wsp-csv-chart-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 14px 14px 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            }
            .wsp-csv-chart-card__title {
                margin: 0 0 8px;
                font-size: 0.95rem;
                font-weight: 600;
                color: #374151;
                line-height: 1.3;
            }
            .wsp-csv-chart-card__canvas {
                width: 100%;
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
