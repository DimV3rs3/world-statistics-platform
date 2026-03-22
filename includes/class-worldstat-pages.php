<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Pages {

    const PAGES = [
        'countries'   => [ 'title' => 'Страны мира',          'slug' => 'countries',   'content' => '[worldstat_countries_grid]' ],
        'compare'     => [ 'title' => 'Сравнение стран',      'slug' => 'compare',     'content' => '[worldstat_compare]' ],
        'data-themes' => [ 'title' => 'Тематические данные',  'slug' => 'data-themes', 'content' => '[worldstat_themes]' ],
    ];

    public function __construct() {
        // No hooks needed — pages created on activation
    }

    public static function create_pages(): void {
        $pages = get_option( 'wsp_pages', [] );

        foreach ( self::PAGES as $key => $def ) {
            if ( ! empty( $pages[ $key ] ) && get_post_status( $pages[ $key ] ) === 'publish' ) {
                continue;
            }

            $id = wp_insert_post( [
                'post_type'    => 'page',
                'post_title'   => $def['title'],
                'post_name'    => $def['slug'],
                'post_content' => $def['content'],
                'post_status'  => 'publish',
            ] );

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
