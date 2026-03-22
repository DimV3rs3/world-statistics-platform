<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WorldStat_Taxonomies {

    const REGION       = 'wsp_region';
    const SUBREGION    = 'wsp_subregion';
    const INCOME_GROUP = 'wsp_income_group';

    public function __construct() {
        add_action( 'init', [ $this, 'register' ] );
    }

    public function register(): void {
        $cpt = WorldStat_Country_CPT::SLUG;

        register_taxonomy( self::REGION, $cpt, [
            'labels'       => [ 'name' => 'Регионы', 'singular_name' => 'Регион' ],
            'hierarchical' => true, 'public' => true, 'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'region', 'with_front' => false ],
        ] );

        register_taxonomy( self::SUBREGION, $cpt, [
            'labels'       => [ 'name' => 'Субрегионы', 'singular_name' => 'Субрегион' ],
            'hierarchical' => true, 'public' => true, 'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'subregion', 'with_front' => false ],
        ] );

        register_taxonomy( self::INCOME_GROUP, $cpt, [
            'labels'       => [ 'name' => 'Группы дохода', 'singular_name' => 'Группа дохода' ],
            'hierarchical' => true, 'public' => true, 'show_in_rest' => true,
            'rewrite'      => [ 'slug' => 'income-group', 'with_front' => false ],
        ] );

        do_action( 'worldstat_taxonomies_registered' );
    }

    public static function get_regions_data(): array {
        return [
            'africa'   => [
                'name' => 'Африка',
                'subs' => [
                    'northern-africa' => 'Северная Африка',
                    'western-africa'  => 'Западная Африка',
                    'middle-africa'   => 'Центральная Африка',
                    'eastern-africa'  => 'Восточная Африка',
                    'southern-africa' => 'Южная Африка',
                ],
            ],
            'americas' => [
                'name' => 'Америка',
                'subs' => [
                    'northern-america'  => 'Северная Америка',
                    'central-america'   => 'Центральная Америка',
                    'caribbean'         => 'Карибы',
                    'south-america'     => 'Южная Америка',
                ],
            ],
            'asia'     => [
                'name' => 'Азия',
                'subs' => [
                    'central-asia'    => 'Центральная Азия',
                    'eastern-asia'    => 'Восточная Азия',
                    'southern-asia'   => 'Южная Азия',
                    'south-eastern-asia' => 'Юго-Восточная Азия',
                    'western-asia'    => 'Западная Азия',
                ],
            ],
            'europe'   => [
                'name' => 'Европа',
                'subs' => [
                    'northern-europe' => 'Северная Европа',
                    'western-europe'  => 'Западная Европа',
                    'eastern-europe'  => 'Восточная Европа',
                    'southern-europe' => 'Южная Европа',
                ],
            ],
            'oceania'  => [
                'name' => 'Океания',
                'subs' => [
                    'australia-and-new-zealand' => 'Австралия и Новая Зеландия',
                    'melanesia'  => 'Меланезия',
                    'micronesia' => 'Микронезия',
                    'polynesia'  => 'Полинезия',
                ],
            ],
        ];
    }

    public static function get_income_groups_data(): array {
        return [
            'low'          => 'Низкий доход',
            'lower-middle' => 'Ниже среднего',
            'upper-middle' => 'Выше среднего',
            'high'         => 'Высокий доход',
        ];
    }
}
