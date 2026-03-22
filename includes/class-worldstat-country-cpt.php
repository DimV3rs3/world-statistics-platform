<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Country_CPT {

    const SLUG = 'wsp_country';

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
        // Resolve conflict: if the active theme also registers a 'country' CPT
        // with the same rewrite slug, we must unregister it so our wsp_country
        // rewrite rules work correctly.
        add_action( 'init', [ $this, 'resolve_slug_conflict' ], 100 );
    }

    public function register(): void {
        $labels = [
            'name'               => __( 'Страны', 'flavor-worldstat' ),
            'singular_name'      => __( 'Страна', 'flavor-worldstat' ),
            'all_items'          => __( 'Все страны', 'flavor-worldstat' ),
            'add_new_item'       => __( 'Добавить страну', 'flavor-worldstat' ),
            'edit_item'          => __( 'Редактировать страну', 'flavor-worldstat' ),
            'search_items'       => __( 'Поиск стран', 'flavor-worldstat' ),
            'not_found'          => __( 'Стран не найдено', 'flavor-worldstat' ),
        ];

        register_post_type( self::SLUG, [
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => false,
            'show_in_rest'       => true,
            'rest_base'          => 'countries',
            'rewrite'            => [ 'slug' => 'country', 'with_front' => false ],
            'has_archive'        => 'countries',
            'hierarchical'       => false,
            'menu_icon'          => 'dashicons-admin-site-alt3',
            'supports'           => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'capability_type'    => 'post',
            'taxonomies'         => [
                WorldStat_Taxonomies::REGION,
                WorldStat_Taxonomies::SUBREGION,
                WorldStat_Taxonomies::INCOME_GROUP,
            ],
        ] );

        do_action( 'worldstat_post_type_registered', self::SLUG );
    }

    /**
     * If the theme registers a 'country' CPT with rewrite slug 'country',
     * it conflicts with our wsp_country CPT (same rewrite slug).
     * Unregister the theme's CPT so our rewrite rules take effect.
     */
    public function resolve_slug_conflict(): void {
        // Only act if a separate 'country' CPT exists (not ours)
        if ( post_type_exists( 'country' ) && 'country' !== self::SLUG ) {
            unregister_post_type( 'country' );
        }
    }

    /**
     * Get a country post by ISO Alpha-2 code.
     */
    public static function get_by_code( string $iso2 ): ?WP_Post {
        $map = self::get_code_map();
        $iso2 = strtoupper( trim( $iso2 ) );
        if ( isset( $map[ $iso2 ] ) ) {
            return get_post( $map[ $iso2 ] );
        }
        return null;
    }

    /**
     * Build [iso2 => post_id] map (cached).
     */
    public static function get_code_map(): array {
        $cache = wp_cache_get( 'wsp_code_map', 'worldstat' );
        if ( is_array( $cache ) ) return $cache;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS code, pm.post_id
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'wsp_iso_alpha2'
               AND p.post_type = %s AND p.post_status = 'publish'",
            self::SLUG
        ) );

        $map = [];
        foreach ( $rows as $r ) {
            $map[ strtoupper( $r->code ) ] = (int) $r->post_id;
        }

        wp_cache_set( 'wsp_code_map', $map, 'worldstat', 3600 );
        return $map;
    }

    public static function flush_code_cache(): void {
        wp_cache_delete( 'wsp_code_map', 'worldstat' );
    }
}
