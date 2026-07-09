<?php
/**
 * Plugin Name: Blitz & Donner Formular
 * Description: Sicherheitszentrierter Formular-Builder für den Block-Editor mit serverseitiger Verschlüsselung von Datei-Uploads und sensiblen Feldern (AES-256-GCM, Master-Key in wp-config.php), eigenem Capability-Modell, ClamAV-Integration, tamper-evident Audit-Log und privatem Storage ausserhalb der Web-Wurzel.
 * Version: 2.9.6
 * Plugin URI: https://plugins.blitzdonner.ch
 * Author: Blitz & Donner
 * Author URI: https://plugins.blitzdonner.ch
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
define( 'GFB_PLUGIN_VERSION', '2.9.6' );

// Reihenfolge wichtig: Crypto + Capabilities + Audit zuerst, dann alles, was sie nutzt.
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-crypto.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-capabilities.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-audit.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-clamav.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-captcha.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-file-storage.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-security.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-field-renderer.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-plugin.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-submit-handler.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-submissions.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-settings.php';
require_once GFB_PLUGIN_DIR . 'includes/class-gfb-admin-audit.php';

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
GFB_Admin_Audit::boot();

/**
 * BD Update Client: bezieht Updates vom Self-hosted Server plugins.blitzdonner.ch.
 *
 * Das Token kommt entweder aus der wp-config-Konstante GFB_UPDATE_TOKEN
 * (empfohlen, mit Vorrang) ODER aus der im Backend hinterlegten Option
 * gfb_update_token. In der Kundeninstallation lässt sich die Konstante in
 * wp-config.php setzen:
 *   define( 'GFB_UPDATE_TOKEN', 'xxxxxxxx' );
 * Kein Token im Plugin-Code. Ohne gültiges Token werden nur keine Updates
 * angeboten – das Plugin bleibt voll funktionsfähig (GPL-Grenze, kein Killswitch).
 */
// Frueh auf plugins_loaded instanziieren: Der Filter
// pre_set_site_transient_update_plugins muss registriert sein, BEVOR WordPress
// den update_plugins-Transient befuellt (auch im Cron-Loopback). Sonst fehlt
// das Plugin im Transient, WP setzt update-supported = false und blendet die
// Auto-Update-Schaltung in der Plugin-Liste aus.
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'BD_Update_Client' ) ) {
		require_once GFB_PLUGIN_DIR . 'includes/class-bd-update-client.php';
	}

	$GLOBALS['gfb_update_client'] = new BD_Update_Client( array(
		'plugin_file' => GFB_PLUGIN_FILE,
		'slug'        => 'gutenberg-formbuilder',
		'server_url'  => 'https://plugins.blitzdonner.ch',
		'version'     => GFB_PLUGIN_VERSION,
		'option_key'  => 'gfb_update_token',
		'const_key'   => 'GFB_UPDATE_TOKEN',
	) );
}, 1 );

/**
 * Zugriff auf die laufende Update-Client-Instanz (z.B. für die Status-Box
 * im Backend). Gibt null zurück, falls der Client noch nicht initialisiert ist.
 *
 * @return BD_Update_Client|null
 */
function gfb_update_client() {
	return isset( $GLOBALS['gfb_update_client'] ) && $GLOBALS['gfb_update_client'] instanceof BD_Update_Client
		? $GLOBALS['gfb_update_client']
		: null;
}
