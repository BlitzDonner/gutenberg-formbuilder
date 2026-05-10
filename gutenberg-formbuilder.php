<?php
/**
 * Plugin Name: Gutenberg Formbuilder
 * Description: Sicherheitszentrierter Formular-Builder für Gutenberg mit serverseitiger Verschlüsselung von Datei-Uploads und sensiblen Feldern (AES-256-GCM, Master-Key in wp-config.php), eigenem Capability-Modell, ClamAV-Integration, tamper-evident Audit-Log und privatem Storage ausserhalb der Web-Wurzel.
 * Version: 2.1.4
 * Author: ClaudeStation
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Text Domain: gutenberg-formbuilder
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFB_PLUGIN_FILE', __FILE__ );
define( 'GFB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GFB_PLUGIN_VERSION', '2.1.4' );

// Reihenfolge wichtig: Crypto + Capabilities + Audit zuerst, dann alles, was sie nutzt.
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-crypto.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-capabilities.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-audit.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-clamav.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-file-storage.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-security.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-field-renderer.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-plugin.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-submit-handler.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-submissions.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-settings.php';

/**
 * Lädt Übersetzungen gemäss WordPress-Locale (Einstellungen → Allgemein → Sprache der Website).
 * Mehrsprachige Plugins können den Filter `locale` setzen; dieser Hook läuft danach auf `init`.
 *
 * @return void
 */
function gfb_load_textdomain() {
	load_plugin_textdomain(
		'gutenberg-formbuilder',
		false,
		dirname( plugin_basename( GFB_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'gfb_load_textdomain', 1 );

register_activation_hook( __FILE__, array( 'GFB_Submit_Handler', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		$ts = wp_next_scheduled( 'gfb_rewrap_cron' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'gfb_rewrap_cron' );
		}
	}
);
add_action( 'gfb_rewrap_cron', array( 'GFB_Submit_Handler', 'cron_rewrap' ) );

GFB_Security::boot();
GFB_File_Storage::boot();
GFB_Plugin::boot();
GFB_Admin_Settings::boot();
