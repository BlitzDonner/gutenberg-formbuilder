<?php
/**
 * Plugin Name: Gutenberg Formbuilder
 * Description: Formular-Builder direkt im Gutenberg-Editor mit lokalen Entwürfen in IndexedDB.
 * Version: 0.7.12
 * Author: ClaudeStation
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: gutenberg-formbuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFB_PLUGIN_FILE', __FILE__ );
define( 'GFB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GFB_PLUGIN_VERSION', '0.7.12' );

require_once GFB_PLUGIN_DIR . 'includes/class-gfb-plugin.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-submit-handler.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-submissions.php';

register_activation_hook( __FILE__, array( 'GFB_Submit_Handler', 'activate' ) );

GFB_Plugin::boot();
