<?php
/**
 * Renderer — produces UI for the country page tab.
 *
 * Uses WorldStat_UI components provided by the platform.
 *
 * @package WorldStatExample
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSE_Renderer {

    /**
     * Render the extension's tab on a country page.
     *
     * This callback is registered via WorldStat_Extensions::add_country_tab().
     * The platform calls it with the ISO2 country code.
     *
     * @param string $country_code ISO Alpha-2 code.
     */
    public static function render_country_tab( string $country_code ): void {
        // Get data from our own provider
        $score = WSE_Data_Provider::get_score( $country_code );

        // Get core data (population) from the platform
        $population = WorldStat_Data::get( 'core', $country_code, 'population' );

        // ── Stats Grid ─────────────────────────────────
        WorldStat_UI::stats_grid( [
            [ 'label' => 'Example Score', 'value' => $score . ' pts', 'icon' => 'lightbulb' ],
            [ 'label' => 'Population',    'value' => number_format( (int) $population, 0, '', ' ' ), 'icon' => 'groups' ],
        ], [ 'columns' => 2 ] );

        // ── Chart ──────────────────────────────────────
        WorldStat_UI::chart( [
            'type'     => 'bar',
            'title'    => 'Example Score Comparison',
            'labels'   => [ $country_code, 'US', 'DE', 'CN', 'JP' ],
            'datasets' => [
                [
                    'label' => 'Score',
                    'data'  => [
                        $score,
                        WSE_Data_Provider::get_score( 'US' ),
                        WSE_Data_Provider::get_score( 'DE' ),
                        WSE_Data_Provider::get_score( 'CN' ),
                        WSE_Data_Provider::get_score( 'JP' ),
                    ],
                ],
            ],
        ] );

        // ── Data Table ─────────────────────────────────
        $nearby = self::get_neighboring_scores( $country_code );
        if ( ! empty( $nearby ) ) {
            WorldStat_UI::table( [
                'headers'    => [ 'Страна', 'Score' ],
                'rows'       => array_map( fn( $r ) => [ $r['code'], $r['score'] ], $nearby ),
                'sortable'   => true,
                'searchable' => true,
            ] );
        }

        // ── Text Block ─────────────────────────────────
        WorldStat_UI::text_block( [
            'content'    => sprintf(
                'The example score for %s is {score}. This is a demonstration of the WorldStat UI components.',
                $country_code
            ),
            'highlights' => [ 'score' ],
        ] );
    }

    /**
     * Helper: get a few neighboring scores for comparison.
     */
    private static function get_neighboring_scores( string $code ): array {
        $codes = [ 'RU', 'US', 'DE', 'CN', 'JP', 'BR', 'IN', 'AU', 'ZA', 'EG' ];
        $result = [];

        foreach ( $codes as $c ) {
            $result[] = [ 'code' => $c, 'score' => WSE_Data_Provider::get_score( $c ) ];
        }

        return $result;
    }
}
