<?php
/**
 * Plugin Name:       WorldStat — Example Extension
 * Plugin URI:        https://worldstatistics.dev/extensions/example
 * Description:       Example extension demonstrating the World Statistics Platform SDK.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  world-statistics-platform
 * Author:            Your Name
 * License:           GPL v2 or later
 * Text Domain:       worldstat-example
 *
 * @package WorldStatExample
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSE_VERSION', '1.0.0' );
define( 'WSE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WSE_URL',     plugin_dir_url( __FILE__ ) );

/*
 * Verify that the World Statistics Platform is active.
 */
if ( ! class_exists( 'WorldStat_Core' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>WorldStat Example Extension</strong> requires <strong>World Statistics Platform</strong> to be installed and activated.';
        echo '</p></div>';
    } );
    return;
}

/*
 * Include extension files.
 */
require_once WSE_DIR . 'includes/class-extension.php';
require_once WSE_DIR . 'includes/class-data-provider.php';
require_once WSE_DIR . 'includes/class-renderer.php';

/*
 * Register the extension on the platform's init hook.
 * Everything MUST happen inside 'worldstat_init'.
 */
add_action( 'worldstat_init', function () {

    // 1. Register the extension
    WorldStat_Extensions::register( [
        'id'                => 'example',
        'name'              => 'Example Statistics',
        'version'           => WSE_VERSION,
        'author'            => 'Your Name',
        'description'       => 'A minimal example showing how to build an extension.',
        'icon'              => 'dashicons-lightbulb',
        'requires_platform' => '1.0.0',
        'depends'           => [],  // IDs of other required extensions
    ] );

    // 2. Register data provider (metrics this extension provides)
    WorldStat_Extensions::add_data_provider( 'example', [
        'metrics' => [
            'example_score' => [
                'label'       => 'Example Score',
                'type'        => 'number',
                'unit'        => 'points',
                'description' => 'A hypothetical score for demonstration.',
                'callback'    => [ 'WSE_Data_Provider', 'get_score' ],
            ],
        ],
    ] );

    // 3. Register a country page tab
    WorldStat_Extensions::add_country_tab( 'example', [
        'title'    => 'Example',
        'icon'     => 'dashicons-lightbulb',
        'callback' => [ 'WSE_Renderer', 'render_country_tab' ],
        'priority' => 100,
    ] );

    // 4. (Optional) Register a map layer
    WorldStat_Extensions::add_map_layer( 'example', [
        'label'         => 'Example Score',
        'type'          => 'choropleth',
        'color_scale'   => [ '#eef2ff', '#4338ca' ],
        'data_callback' => [ 'WSE_Data_Provider', 'get_map_data' ],
    ] );

    // 5. (Optional) Register export
    WorldStat_Extensions::add_export( 'example', [
        'formats'  => [ 'csv', 'json' ],
        'callback' => [ 'WSE_Data_Provider', 'export_data' ],
    ] );

} );
