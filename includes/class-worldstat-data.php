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

        $rows = $this->get_country_csv_rows( $iso3 );
        if ( empty( $rows ) ) {
            return;
        }

        echo '<section class="wsp-country-csv-block">';
        echo '<h3>' . esc_html__( 'Данные из CSV', 'flavor-worldstat' ) . '</h3>';
        echo '<div class="wsp-table-wrap"><table class="wsp-data-table">';
        echo '<thead><tr><th>' . esc_html__( 'Показатель', 'flavor-worldstat' ) . '</th><th>' . esc_html__( 'Год', 'flavor-worldstat' ) . '</th><th>' . esc_html__( 'Значение', 'flavor-worldstat' ) . '</th></tr></thead>';
        echo '<tbody>';

        foreach ( $rows as $i => $row ) {
            $metric_id = 'wsp-csv-metric-' . $i;
            echo '<tr>';
            echo '<td>' . esc_html( $row['label'] ) . '</td>';
            echo '<td>';
            $years_with_data = array_filter(
                $row['years'],
                static fn( $v, $y ) => is_numeric( $v ) && (int) $y > 0,
                ARRAY_FILTER_USE_BOTH
            );

            echo '<select class="wsp-csv-year-select" data-target="' . esc_attr( $metric_id ) . '">';
            foreach ( $years_with_data as $year => $value ) {
                $selected = ( (int) $year === (int) $row['year'] ) ? ' selected' : '';
                echo '<option value="' . esc_attr( (string) $year ) . '"' . $selected . '>' . esc_html( (string) $year ) . '</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '<td class="wsp-csv-value-cell" id="' . esc_attr( $metric_id ) . '" data-values=\'' . esc_attr( wp_json_encode( $years_with_data ) ) . '\'>'
                . esc_html( $this->format_csv_value( $row['value'] ) )
                . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</section>';
        $this->render_country_csv_styles();
        $this->render_country_csv_script();
    }

    /**
     * Get values from all configured CSV files for country ISO3.
     */
    private function get_country_csv_rows( string $iso3 ): array {
        if ( ! class_exists( 'WorldStat_Uploaded_Csv' ) ) {
            return [];
        }

        $rev = (int) get_option( 'wsp_csv_files_revision', 0 );
        $cache_key = 'wsp_csv_country_' . strtolower( $iso3 ) . '_r' . $rev;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && $this->is_valid_country_csv_cache( $cached ) ) {
            return $cached;
        }

        $rows = [];

        foreach ( WorldStat_Uploaded_Csv::list_files() as $file_row ) {
            $full_path = $file_row['path'] ?? '';
            if ( $full_path === '' || ! is_readable( $full_path ) ) {
                continue;
            }

            $series = $this->read_country_csv_series( $full_path, $iso3 );

            if ( empty( $series ) ) {
                continue;
            }

            krsort( $series, SORT_NUMERIC );
            $latest_year = (int) array_key_first( $series );

            $rows[] = [
                'label' => $this->label_from_uploaded_csv_name( $file_row['name'] ?? '' ),
                'year'  => $latest_year,
                'value' => (float) $series[ $latest_year ],
                'years' => $series,
            ];
        }

        set_transient( $cache_key, $rows, HOUR_IN_SECONDS * 6 );
        return $rows;
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
     * Read one CSV and return all year=>value rows for ISO3 country.
     */
    private function read_country_csv_series( string $file_path, string $iso3 ): array {
        if ( ! file_exists( $file_path ) ) {
            return [];
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return [];
        }

        $series = [];

        // Skip header
        fgetcsv( $handle );

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 3 ) {
                continue;
            }

            if ( strtoupper( trim( (string) $row[0] ) ) !== $iso3 ) {
                continue;
            }

            $year = (int) $row[1];
            $value = is_numeric( $row[2] ) ? (float) $row[2] : null;

            if ( null === $value ) {
                continue;
            }

            if ( $year > 0 ) {
                $series[ $year ] = $value;
            }
        }

        fclose( $handle );
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
     * Inline script for year selectors in CSV block.
     */
    private function render_country_csv_script(): void {
        ?>
        <script>
            (function() {
                if (window.wspCsvYearSelectorBound) {
                    return;
                }
                window.wspCsvYearSelectorBound = true;
                document.addEventListener('change', function(e) {
                    if (!e.target.classList.contains('wsp-csv-year-select')) {
                        return;
                    }
                    var targetId = e.target.getAttribute('data-target');
                    var valueCell = document.getElementById(targetId);
                    if (!valueCell) {
                        return;
                    }
                    var map = {};
                    try {
                        map = JSON.parse(valueCell.getAttribute('data-values') || '{}');
                    } catch (err) {
                        map = {};
                    }
                    var selectedYear = e.target.value;
                    if (map[selectedYear] === undefined) {
                        return;
                    }
                    var raw = Number(map[selectedYear]);
                    if (!Number.isFinite(raw)) {
                        return;
                    }
                    var formatted = Number.isInteger(raw)
                        ? raw.toLocaleString('ru-RU')
                        : raw.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    valueCell.textContent = formatted;
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
            .wsp-country-csv-block .wsp-csv-year-select {
                min-width: 88px !important;
                width: auto !important;
                max-width: none !important;
                display: inline-block !important;
                padding: 4px 8px !important;
                border: 1px solid #c6ccd2 !important;
                border-radius: 6px !important;
                background: #fff !important;
                color: #1f2933 !important;
                line-height: 1.2 !important;
                font-size: 14px !important;
                -webkit-appearance: menulist !important;
                -moz-appearance: menulist !important;
                appearance: menulist !important;
                background-image: none !important;
            }
            .wsp-country-csv-block .wsp-csv-value-cell {
                white-space: nowrap;
            }
        </style>
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
