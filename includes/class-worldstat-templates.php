<?php
/**
 * Template loader — intercepts WP template hierarchy to use plugin templates
 * while allowing theme overrides in a "worldstat/" subfolder.
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Templates {

    public function __construct() {
        add_filter( 'single_template',  [ $this, 'single_template' ] );
        add_filter( 'archive_template', [ $this, 'archive_template' ] );
        add_filter( 'page_template',    [ $this, 'page_template' ] );
    }

    public function single_template( string $template ): string {
        if ( is_singular( WorldStat_Country_CPT::SLUG ) ) {
            $found = $this->locate( 'single-wsp_country.php' );
            if ( $found ) return $found;
        }

        // Allow extensions to register single templates via filter
        $custom = apply_filters( 'worldstat_single_template', '', get_post_type() );
        if ( $custom && file_exists( $custom ) ) {
            return $custom;
        }

        return $template;
    }

    public function archive_template( string $template ): string {
        if ( is_post_type_archive( WorldStat_Country_CPT::SLUG ) ) {
            $found = $this->locate( 'archive-wsp_country.php' );
            if ( $found ) return $found;
        }
        return $template;
    }

    public function page_template( string $template ): string {
        if ( ! is_page() ) return $template;

        $page_id = get_the_ID();
        $pages   = get_option( 'wsp_pages', [] );

        $map = [
            'countries'   => 'page-countries.php',
            'compare'     => 'page-compare.php',
            'data-themes' => 'page-data-themes.php',
        ];

        foreach ( $map as $key => $file ) {
            if ( ! empty( $pages[ $key ] ) && (int) $pages[ $key ] === $page_id ) {
                $found = $this->locate( $file );
                if ( $found ) return $found;
            }
        }

        return $template;
    }

    /**
     * Locate a template: theme > plugin.
     */
    public function locate( string $filename ): string {
        // 1. Theme override:  wp-content/themes/THEME/worldstat/filename.php
        $theme_path = get_stylesheet_directory() . '/worldstat/' . $filename;
        if ( file_exists( $theme_path ) ) return $theme_path;

        // 2. Plugin template
        $plugin_path = WSP_TEMPLATES_DIR . $filename;
        if ( file_exists( $plugin_path ) ) return $plugin_path;

        return '';
    }
}
