<?php
/**
 * Extension bootstrap class — loaded by the main file.
 *
 * Use this class to hook into WordPress events, register post types,
 * enqueue assets specific to this extension, etc.
 *
 * @package WorldStatExample
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WSE_Extension {

    public function __construct() {
        // Example: enqueue extension-specific assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        // Only on country pages
        if ( ! is_singular( 'wsp_country' ) ) return;

        // Uncomment and create these files if needed:
        // wp_enqueue_style( 'wse-style',  WSE_URL . 'assets/css/extension.css', [], WSE_VERSION );
        // wp_enqueue_script( 'wse-script', WSE_URL . 'assets/js/extension.js', [ 'jquery' ], WSE_VERSION, true );
    }
}
