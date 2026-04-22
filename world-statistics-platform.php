<?php
/**
 * Plugin Name:       World Statistics Platform
 * Plugin URI:        https://worldstatistics.dev
 * Description:       Платформа мировой статистики с системой расширений. 195 стран, интерактивная карта, UI-компоненты, REST API, SDK для разработчиков.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      8.0
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
define( 'WSP_MIN_PHP',       '8.0' );

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
    'includes/class-worldstat-analysis.php',
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
    'includes/class-worldstat-uploaded-csv.php',
    'includes/class-worldstat-csv-cleaner.php',
    'includes/class-worldstat-csv-country-meta-importer.php',
    'admin/class-worldstat-admin.php',
];

foreach ( $wsp_includes as $file ) {
    $path = WSP_PLUGIN_DIR . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

add_action( 'worldstat_init', array( 'WorldStat_Csv_Country_Meta_Importer', 'register_extension' ), 15 );

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

/** Таблица CSV и перенос старых файлов из uploads без повторной активации плагина. */
add_action(
    'plugins_loaded',
    static function () {
        if ( ! class_exists( 'WorldStat_Uploaded_Csv' ) ) {
            return;
        }
        WorldStat_Uploaded_Csv::install_db();
        WorldStat_Uploaded_Csv::ensure_dir();
        WorldStat_Uploaded_Csv::migrate_legacy_files_from_disk();
    },
    7
);

// Ensure pages exist, but after WP is fully loaded (avoid null $wp_rewrite on early calls).
add_action( 'wp_loaded', function () {
    if ( ! is_admin() ) return;
    if ( class_exists( 'WorldStat_Pages' ) ) {
        WorldStat_Pages::create_pages();
    }
}, 20 );

add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( empty( $_GET['wsp_cleanup_analysis'] ) ) return;
    if ( empty( $_GET['_wpnonce'] ) ) return;

    $nonce_ok = wp_verify_nonce( (string) $_GET['_wpnonce'], 'wsp_cleanup_analysis' );
    if ( ! $nonce_ok ) return;

    global $wpdb;
    $prefix = 'analysis-data';

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_name LIKE %s",
            $prefix . '%'
        )
    );
    $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
    if ( count( $ids ) <= 1 ) return;

    $canonical_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page'
               AND post_name = %s",
            $prefix
        )
    );
    $keep = ! empty( $canonical_ids ) ? (int) $canonical_ids[0] : min( $ids );
    $to_delete = array_values( array_filter( $ids, fn($id) => (int) $id !== (int) $keep ) );
    if ( empty( $to_delete ) ) return;

    $in = implode( ',', array_map( 'intval', $to_delete ) );

    $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );

    wp_cache_flush();
    wp_redirect( admin_url( 'admin.php?wsp_cleanup_analysis_done=1' ) );
    exit;
} );
