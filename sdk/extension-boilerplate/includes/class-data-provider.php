<?php
/**
 * Data Provider — supplies metrics to the platform.
 *
 * Methods here are called by the platform when data is requested
 * via WorldStat_Data::get() or the REST API.
 *
 * @package WorldStatExample
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSE_Data_Provider {

    /**
     * Get the example score for a country.
     *
     * This callback is registered in the main file via add_data_provider().
     * The platform calls it with the ISO2 country code.
     *
     * @param string $country_code ISO Alpha-2 code (e.g., 'RU').
     * @return mixed The metric value.
     */
    public static function get_score( string $country_code ) {
        // TODO: Replace with real data source (database, API, JSON file, etc.)
        //
        // Example: read from post meta
        // $post = WorldStat_Country_CPT::get_by_code( $country_code );
        // if ( ! $post ) return null;
        // return (float) get_post_meta( $post->ID, 'wse_example_score', true );

        // Demo: return a deterministic random value based on country code
        return crc32( $country_code ) % 100;
    }

    /**
     * Get data for all countries (used by map layer).
     *
     * Must return an associative array: [ 'ISO2' => numeric_value, ... ]
     *
     * @return array
     */
    public static function get_map_data(): array {
        $map     = WorldStat_Country_CPT::get_code_map();
        $result  = [];

        foreach ( $map as $iso2 => $post_id ) {
            $result[ $iso2 ] = self::get_score( $iso2 );
        }

        return $result;
    }

    /**
     * Export data handler (optional).
     *
     * @param string $format 'csv' | 'json'
     * @return void Sends output directly.
     */
    public static function export_data( string $format = 'csv' ): void {
        $map  = WorldStat_Country_CPT::get_code_map();
        $data = [];

        foreach ( $map as $iso2 => $post_id ) {
            $data[] = [
                'country' => $iso2,
                'score'   => self::get_score( $iso2 ),
            ];
        }

        if ( $format === 'json' ) {
            header( 'Content-Type: application/json' );
            echo wp_json_encode( $data );
            exit;
        }

        // Default: CSV
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="example-export.csv"' );
        $fp = fopen( 'php://output', 'w' );
        fputcsv( $fp, [ 'Country', 'Score' ] );
        foreach ( $data as $row ) {
            fputcsv( $fp, $row );
        }
        fclose( $fp );
        exit;
    }
}
