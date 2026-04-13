<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Installer {

    public static function activate(): void {
        // 1. Register CPT and taxonomies so rewrite rules can be flushed
        ( new WorldStat_Country_CPT() )->register();
        ( new WorldStat_Taxonomies() )->register();
        ( new WorldStat_Meta() )->register();

        // 2. Migrate from old WSC plugin if data exists
        self::migrate_from_wsc();

        // 3. Create taxonomy terms
        self::create_taxonomy_terms();

        // 4. Create country posts (if fresh install)
        self::create_countries();

        // 5. Create pages
        WorldStat_Pages::create_pages();

        // 6. Flush rewrite rules
        flush_rewrite_rules();

        // 7. Set version
        update_option( 'wsp_version', WSP_VERSION );
        update_option( 'wsp_activated', time() );

        if ( class_exists( 'WorldStat_Uploaded_Csv' ) ) {
            WorldStat_Uploaded_Csv::install_db();
            WorldStat_Uploaded_Csv::ensure_dir();
            WorldStat_Uploaded_Csv::migrate_legacy_files_from_disk();
        }

        do_action( 'worldstat_activated' );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
        do_action( 'worldstat_deactivated' );
    }

    /* ═══════════════════════════════════════════════════════
       MIGRATION FROM world-statistics-core (wsc_ → wsp_)
    ═══════════════════════════════════════════════════════ */
    public static function migrate_from_wsc(): void {
        global $wpdb;

        // Check if there are wsc_ posts to migrate
        $wsc_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wsc_country'"
        );

        if ( $wsc_count === 0 ) return; // No old data — fresh install

        // 1. Migrate post type
        $wpdb->query(
            "UPDATE {$wpdb->posts} SET post_type = 'wsp_country' WHERE post_type = 'wsc_country'"
        );

        // 2. Migrate taxonomies
        $wpdb->query(
            "UPDATE {$wpdb->term_taxonomy} SET taxonomy = 'wsp_region' WHERE taxonomy = 'wsc_region'"
        );
        $wpdb->query(
            "UPDATE {$wpdb->term_taxonomy} SET taxonomy = 'wsp_subregion' WHERE taxonomy = 'wsc_subregion'"
        );
        $wpdb->query(
            "UPDATE {$wpdb->term_taxonomy} SET taxonomy = 'wsp_income_group' WHERE taxonomy = 'wsc_income_group'"
        );

        // 3. Migrate meta keys (wsc_ → wsp_)
        $wpdb->query(
            "UPDATE {$wpdb->postmeta} SET meta_key = REPLACE(meta_key, 'wsc_', 'wsp_')
             WHERE meta_key LIKE 'wsc_%'"
        );

        // 4. Migrate options
        $old_version = get_option( 'wsc_version' );
        if ( $old_version ) {
            update_option( 'wsp_migrated_from_wsc', $old_version );
            delete_option( 'wsc_version' );
            delete_option( 'wsc_activated' );
        }

        // 5. Migrate page option IDs
        $old_pages = get_option( 'wsc_pages', [] );
        if ( ! empty( $old_pages ) ) {
            update_option( 'wsp_pages', $old_pages );
            delete_option( 'wsc_pages' );
        }

        // Clean caches
        wp_cache_flush();

        // Log migration
        update_option( 'wsp_migration_date', current_time( 'mysql' ) );
        update_option( 'wsp_migration_count', $wsc_count );
    }

    /* ═══════════════════════════════════════════════════════
       CREATE TAXONOMY TERMS
    ═══════════════════════════════════════════════════════ */
    public static function create_taxonomy_terms(): void {
        $regions_data = WorldStat_Taxonomies::get_regions_data();

        foreach ( $regions_data as $slug => $region ) {
            if ( ! term_exists( $slug, WorldStat_Taxonomies::REGION ) ) {
                $parent = wp_insert_term( $region['name'], WorldStat_Taxonomies::REGION, [ 'slug' => $slug ] );
                $parent_id = is_array( $parent ) ? $parent['term_id'] : 0;

                if ( $parent_id && ! empty( $region['subs'] ) ) {
                    foreach ( $region['subs'] as $sub_slug => $sub_name ) {
                        if ( ! term_exists( $sub_slug, WorldStat_Taxonomies::SUBREGION ) ) {
                            wp_insert_term( $sub_name, WorldStat_Taxonomies::SUBREGION, [ 'slug' => $sub_slug ] );
                        }
                    }
                }
            }
        }

        foreach ( WorldStat_Taxonomies::get_income_groups_data() as $slug => $name ) {
            if ( ! term_exists( $slug, WorldStat_Taxonomies::INCOME_GROUP ) ) {
                wp_insert_term( $name, WorldStat_Taxonomies::INCOME_GROUP, [ 'slug' => $slug ] );
            }
        }
    }

    /* ═══════════════════════════════════════════════════════
       CREATE 195 COUNTRIES
    ═══════════════════════════════════════════════════════ */
    public static function create_countries(): void {
        // Skip if countries already exist (migration or re-activation)
        $existing = wp_count_posts( WorldStat_Country_CPT::SLUG );
        if ( ( $existing->publish ?? 0 ) > 50 ) return;

        $file = WSP_DATA_DIR . 'countries.json';
        if ( ! file_exists( $file ) ) return;

        $countries = json_decode( file_get_contents( $file ), true );
        if ( ! is_array( $countries ) ) return;

        // Use direct DB inserts for speed
        global $wpdb;
        $now     = current_time( 'mysql' );
        $now_gmt = current_time( 'mysql', true );
        $uid     = get_current_user_id() ?: 1;

        foreach ( $countries as $c ) {
            $iso2 = strtoupper( $c['iso2'] ?? '' );
            if ( ! $iso2 ) continue;

            // Check if already exists
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = %s AND pm.meta_key = 'wsp_iso_alpha2' AND pm.meta_value = %s
                 LIMIT 1",
                WorldStat_Country_CPT::SLUG, $iso2
            ) );
            if ( $exists ) continue;

            $title = $c['name_ru'] ?? $c['name_en'] ?? $iso2;
            $slug  = sanitize_title( $c['name_en'] ?? $iso2 );

            $wpdb->insert( $wpdb->posts, [
                'post_author'  => $uid,
                'post_date'    => $now,
                'post_date_gmt'=> $now_gmt,
                'post_content' => self::generate_country_content( $c ),
                'post_title'   => $title,
                'post_excerpt' => '',
                'post_status'  => 'publish',
                'comment_status'=> 'closed',
                'ping_status'  => 'closed',
                'post_password'=> '',
                'post_name'    => $slug,
                'to_ping'      => '',
                'pinged'       => '',
                'post_modified'=> $now,
                'post_modified_gmt' => $now_gmt,
                'post_content_filtered' => '',
                'post_parent'  => 0,
                'guid'         => '',
                'menu_order'   => 0,
                'post_type'    => WorldStat_Country_CPT::SLUG,
                'post_mime_type'=> '',
                'comment_count'=> 0,
            ] );

            $post_id = (int) $wpdb->insert_id;
            if ( ! $post_id ) continue;

            $wpdb->update( $wpdb->posts, [ 'guid' => home_url( '/?p=' . $post_id ) ], [ 'ID' => $post_id ] );

            // Bulk-insert meta
            $meta_map = [
                'wsp_iso_alpha2'       => $iso2,
                'wsp_iso_alpha3'       => strtoupper( $c['iso3'] ?? '' ),
                'wsp_iso_numeric'      => $c['numeric'] ?? '',
                'wsp_name_short'       => $c['name_en'] ?? '',
                'wsp_name_official'    => $c['name_official'] ?? '',
                'wsp_name_short_ru'    => $c['name_ru'] ?? '',
                'wsp_name_official_ru' => $c['name_official_ru'] ?? '',
                'wsp_capital_en'       => $c['capital_en'] ?? '',
                'wsp_capital_ru'       => $c['capital_ru'] ?? '',
                'wsp_area_km2'         => (int) ( $c['area'] ?? 0 ),
                'wsp_latitude'         => (float) ( $c['lat'] ?? 0 ),
                'wsp_longitude'        => (float) ( $c['lng'] ?? 0 ),
                'wsp_flag'             => $c['flag'] ?? '',
                'wsp_flag_url'         => '',
                'wsp_population'       => (int) ( $c['population'] ?? 0 ),
            ];

            $vals = [];
            $placeholders = [];
            foreach ( $meta_map as $k => $v ) {
                $vals[] = $post_id;
                $vals[] = $k;
                $vals[] = (string) $v;
                $placeholders[] = '(%d,%s,%s)';
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $placeholders ),
                $vals
            ) );

            // Assign taxonomies
            if ( ! empty( $c['region'] ) ) {
                wp_set_object_terms( $post_id, $c['region'], WorldStat_Taxonomies::REGION );
            }
            if ( ! empty( $c['subregion'] ) ) {
                wp_set_object_terms( $post_id, $c['subregion'], WorldStat_Taxonomies::SUBREGION );
            }
            if ( ! empty( $c['income'] ) ) {
                wp_set_object_terms( $post_id, $c['income'], WorldStat_Taxonomies::INCOME_GROUP );
            }
        }
    }

    private static function generate_country_content( array $c ): string {
        $name = $c['name_ru'] ?? $c['name_en'] ?? '';
        $off  = $c['name_official_ru'] ?? $c['name_official'] ?? '';
        $cap  = $c['capital_ru'] ?? $c['capital_en'] ?? '';
        $pop  = number_format( (int) ( $c['population'] ?? 0 ), 0, '', ' ' );
        $area = number_format( (int) ( $c['area'] ?? 0 ), 0, '', ' ' );

        return "<p><strong>{$name}</strong> ({$off}) — государство с населением {$pop} чел. "
             . "и площадью {$area} км². Столица: {$cap}.</p>";
    }
}
