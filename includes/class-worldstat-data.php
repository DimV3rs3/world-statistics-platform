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
