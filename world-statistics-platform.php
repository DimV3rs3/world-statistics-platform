<?php
/**
 * Plugin Name:       World Statistics Platform
 * Plugin URI:        https://worldstatistics.dev
 * Description:       Платформа мировой статистики с системой расширений. 195 стран, интерактивная карта, UI-компоненты, REST API, SDK для разработчиков.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            World Statistics Team
 * License:           GPL v2 or later
 * Text Domain:       flavor-worldstat
 *
 * @package WorldStatPlatform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─── Constants ─────────────────────────────────────────────── */
define( 'WSP_VERSION',       '1.0.0' );
define( 'WSP_PLUGIN_FILE',   __FILE__ );
define( 'WSP_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'WSP_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'WSP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WSP_DATA_DIR',      WSP_PLUGIN_DIR . 'data/' );
define( 'WSP_INCLUDES_DIR',  WSP_PLUGIN_DIR . 'includes/' );
define( 'WSP_TEMPLATES_DIR', WSP_PLUGIN_DIR . 'templates/' );
define( 'WSP_ASSETS_URL',    WSP_PLUGIN_URL . 'assets/' );
define( 'WSP_MIN_PHP',       '7.4' );

/* ─── PHP Version Check ────────────────────────────────────── */
if ( version_compare( PHP_VERSION, WSP_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', function () {
        printf(
            '<div class="notice notice-error"><p><strong>World Statistics Platform</strong> requires PHP %s+. You are running PHP %s.</p></div>',
            WSP_MIN_PHP,
            PHP_VERSION
        );
    } );
    return;
}

/* ─── Autoloader ────────────────────────────────────────────── */
$wsp_includes = [
    'includes/class-worldstat-core.php',
    'includes/class-worldstat-extensions.php',
    'includes/class-worldstat-data.php',
    'includes/class-worldstat-ui.php',
    'includes/class-worldstat-country-cpt.php',
    'includes/class-worldstat-taxonomies.php',
    'includes/class-worldstat-meta.php',
    'includes/class-worldstat-rest-api.php',
    'includes/class-worldstat-templates.php',
    'includes/class-worldstat-tabs.php',
    'includes/class-worldstat-map.php',
    'includes/class-worldstat-installer.php',
    'includes/class-worldstat-pages.php',
    'admin/class-worldstat-admin.php',
];

foreach ( $wsp_includes as $file ) {
    $path = WSP_PLUGIN_DIR . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

/* ─── Activation / Deactivation ─────────────────────────────── */
register_activation_hook( __FILE__, [ 'WorldStat_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WorldStat_Installer', 'deactivate' ] );

/* ─── Initialize Platform ───────────────────────────────────── */
function worldstat_platform(): WorldStat_Core {
    return WorldStat_Core::instance();
}

add_action( 'plugins_loaded', function () {
    worldstat_platform();
}, 5 );
