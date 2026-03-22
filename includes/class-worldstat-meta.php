<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Meta {

    const FIELDS = [
        'wsp_iso_alpha2'      => [ 'type' => 'string',  'label' => 'ISO Alpha-2',       'rest' => true ],
        'wsp_iso_alpha3'      => [ 'type' => 'string',  'label' => 'ISO Alpha-3',       'rest' => true ],
        'wsp_iso_numeric'     => [ 'type' => 'string',  'label' => 'ISO Numeric',       'rest' => true ],
        'wsp_name_short'      => [ 'type' => 'string',  'label' => 'Name (EN)',         'rest' => true ],
        'wsp_name_official'   => [ 'type' => 'string',  'label' => 'Official Name (EN)','rest' => true ],
        'wsp_name_short_ru'   => [ 'type' => 'string',  'label' => 'Название (RU)',     'rest' => true ],
        'wsp_name_official_ru'=> [ 'type' => 'string',  'label' => 'Официальное (RU)',  'rest' => true ],
        'wsp_capital_en'      => [ 'type' => 'string',  'label' => 'Capital (EN)',      'rest' => true ],
        'wsp_capital_ru'      => [ 'type' => 'string',  'label' => 'Столица (RU)',      'rest' => true ],
        'wsp_area_km2'        => [ 'type' => 'integer', 'label' => 'Площадь (км²)',     'rest' => true ],
        'wsp_latitude'        => [ 'type' => 'number',  'label' => 'Широта',            'rest' => true ],
        'wsp_longitude'       => [ 'type' => 'number',  'label' => 'Долгота',           'rest' => true ],
        'wsp_flag'            => [ 'type' => 'string',  'label' => 'Флаг (emoji)',      'rest' => true ],
        'wsp_flag_url'        => [ 'type' => 'string',  'label' => 'Флаг (URL SVG)',    'rest' => true ],
        'wsp_population'      => [ 'type' => 'integer', 'label' => 'Население',         'rest' => true ],
    ];

    public function __construct() {
        add_action( 'init',          [ $this, 'register' ] );
        add_action( 'add_meta_boxes',[ $this, 'add_meta_box' ] );
        add_action( 'save_post_' . WorldStat_Country_CPT::SLUG, [ $this, 'save_meta' ], 10, 2 );
    }

    public function register(): void {
        foreach ( self::FIELDS as $key => $def ) {
            $schema = match ( $def['type'] ) {
                'integer' => 'integer',
                'number'  => 'number',
                default   => 'string',
            };
            register_post_meta( WorldStat_Country_CPT::SLUG, $key, [
                'type'              => $schema,
                'single'            => true,
                'show_in_rest'      => $def['rest'],
                'sanitize_callback' => match ( $def['type'] ) {
                    'integer' => [ $this, 'sanitize_integer' ],
                    'number'  => [ $this, 'sanitize_number' ],
                    default   => 'sanitize_text_field',
                },
            ] );
        }
    }

    public function sanitize_integer( $v ): int   { return (int) $v; }
    public function sanitize_number( $v ): float   { return (float) $v; }

    public function add_meta_box(): void {
        add_meta_box( 'wsp_country_meta', 'Данные страны', [ $this, 'render_meta_box' ], WorldStat_Country_CPT::SLUG, 'normal', 'high' );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'wsp_meta_save', '_wsp_meta_nonce' );
        echo '<table class="form-table wsp-meta-table">';
        foreach ( self::FIELDS as $key => $def ) {
            $val = get_post_meta( $post->ID, $key, true );
            printf(
                '<tr><th><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%1$s" value="%3$s" class="regular-text"></td></tr>',
                esc_attr( $key ),
                esc_html( $def['label'] ),
                esc_attr( $val )
            );
        }
        echo '</table>';
    }

    public function save_meta( int $post_id, WP_Post $post ): void {
        if ( ! isset( $_POST['_wsp_meta_nonce'] ) || ! wp_verify_nonce( $_POST['_wsp_meta_nonce'], 'wsp_meta_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        foreach ( self::FIELDS as $key => $def ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $val = sanitize_text_field( $_POST[ $key ] );
            if ( $def['type'] === 'integer' ) $val = (int) $val;
            elseif ( $def['type'] === 'number' ) $val = (float) $val;
            update_post_meta( $post_id, $key, $val );
        }

        WorldStat_Country_CPT::flush_code_cache();
    }

    public static function get_field( int $post_id, string $field ) {
        return get_post_meta( $post_id, $field, true );
    }

    public static function get_all_fields( int $post_id ): array {
        $out = [];
        foreach ( self::FIELDS as $key => $def ) {
            $raw = get_post_meta( $post_id, $key, true );
            $clean_key = str_replace( 'wsp_', '', $key );
            $out[ $clean_key ] = match ( $def['type'] ) {
                'integer' => (int) $raw,
                'number'  => (float) $raw,
                default   => (string) $raw,
            };
        }
        return $out;
    }
}
