<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Pages {

    const PAGES = [
        'countries'       => [ 'title' => 'Страны мира',          'slug' => 'countries',   'content' => '[worldstat_countries_grid]' ],
        'compare'         => [ 'title' => 'Сравнение стран',      'slug' => 'compare',     'content' => '[worldstat_compare]' ],
        'data-themes'     => [ 'title' => 'Тематические данные',  'slug' => 'data-themes', 'content' => '[worldstat_themes]' ],
        'analysis'        => [ 'title' => 'Анализ данных',        'slug' => 'analysis-data', 'content' => '' ],
        'rankings'        => [ 'title' => 'Рейтинги стран',       'slug' => 'rankings',    'content' => '' ],
        'map-explorer'    => [ 'title' => 'Карта мира',           'slug' => 'map-explorer','content' => '' ],
        'metrics-catalog' => [ 'title' => 'Каталог метрик',       'slug' => 'metrics-catalog', 'content' => '' ],
        'data-panel'      => [ 'title' => 'Песочница данных',     'slug' => 'data-panel', 'content' => '' ],
        'methodology'     => [ 'title' => 'Методология и источники', 'slug' => 'methodology', 'content' => '' ],
    ];

    public function __construct() {
        // No hooks needed — pages created on activation
    }

    public static function create_pages(): void {
        $pages = get_option( 'wsp_pages', [] );

        foreach ( self::PAGES as $key => $def ) {
            $id = ! empty( $pages[ $key ] ) ? (int) $pages[ $key ] : 0;
            if ( $id && get_post_status( $id ) === 'publish' ) {
                continue;
            }

            // Fallback: reuse existing page by slug to avoid duplicates.
            if ( ! $id ) {
                $found = get_posts( [
                    'post_type'      => 'page',
                    'name'           => (string) ( $def['slug'] ?? '' ),
                    'post_status'    => 'any',
                    'numberposts'    => 1,
                    'fields'         => 'ids',
                    'suppress_filters' => true,
                ] );
                if ( ! empty( $found[0] ) ) {
                    $id = (int) $found[0];
                }
            }

            if ( ! $id ) {
                $id = wp_insert_post( [
                    'post_type'    => 'page',
                    'post_title'   => $def['title'],
                    'post_name'    => $def['slug'],
                    'post_content' => $def['content'],
                    'post_status'  => 'publish',
                ] );
            } else {
                // Ensure correct published status.
                wp_update_post( [
                    'ID'           => $id,
                    'post_title'  => $def['title'],
                    'post_content'=> $def['content'],
                    'post_status' => 'publish',
                ], true );
            }

            if ( $id && ! is_wp_error( $id ) ) {
                $pages[ $key ] = $id;
            }
        }

        update_option( 'wsp_pages', $pages );
    }

    public static function get_page_id( string $key ): int {
        $pages = get_option( 'wsp_pages', [] );
        return (int) ( $pages[ $key ] ?? 0 );
    }

    public static function get_page_url( string $key ): string {
        $id = self::get_page_id( $key );
        return $id ? get_permalink( $id ) : '';
    }
}
