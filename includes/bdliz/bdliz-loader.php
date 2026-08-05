<?php
/**
 * bdliz-Loader – Registrierung des eingebetteten Lizenz-Moduls.
 *
 * Jedes Blitz-&-Donner-Plugin liefert dieselben zwei Dateien mit
 * (bdliz-loader.php + class-bdliz-module.php) und ruft bdliz_register() auf.
 * Die Funktionen definiert die zuerst geladene Kopie; alle weiteren Kopien
 * werden durch den function_exists-Schutz stumm. Auf plugins_loaded gewinnt
 * die NEUESTE registrierte Modul-Version (newest wins) – die Reihenfolge der
 * Plugin-Installation spielt keine Rolle.
 *
 * Einbindung in der Plugin-Hauptdatei:
 *
 *   require_once __DIR__ . '/includes/bdliz/bdliz-loader.php';
 *   bdliz_register(
 *       '1.0.0',                                          // Modul-Version dieser Kopie
 *       __DIR__ . '/includes/bdliz/class-bdliz-module.php',
 *       'mein-plugin-slug',                               // Slug auf dem Update-Server
 *       'Mein Plugin',                                    // Anzeigename
 *       'mein_plugin_license_token'                       // bisherige Einzel-Option (Migration), '' wenn keine
 *   );
 *
 * @package bdliz
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bdliz_register' ) ) :

	$GLOBALS['bdliz_registry'] = array(
		'candidates' => array(), // Modul-Kopien: version => Dateipfad.
		'plugins'    => array(), // slug => array( name, legacy_option ).
	);

	/**
	 * Modul-Kopie und Plugin registrieren.
	 *
	 * @param string $module_version SemVer der mitgelieferten Modul-Kopie.
	 * @param string $module_path    Absoluter Pfad zur class-bdliz-module.php.
	 * @param string $plugin_slug    Slug des Plugins auf dem Update-Server.
	 * @param string $plugin_name    Anzeigename des Plugins.
	 * @param string $legacy_option  Bisherige Einzel-Token-Option (fuer die Migration), '' wenn keine.
	 */
	function bdliz_register( $module_version, $module_path, $plugin_slug, $plugin_name, $legacy_option = '' ) {
		$GLOBALS['bdliz_registry']['candidates'][ (string) $module_version ] = (string) $module_path;
		$GLOBALS['bdliz_registry']['plugins'][ (string) $plugin_slug ]       = array(
			'name'          => (string) $plugin_name,
			'legacy_option' => (string) $legacy_option,
		);
	}

	/**
	 * Zentrale Token-Abfrage fuer die Update-Clients.
	 *
	 * Existiert immer, sobald ein Plugin den Loader geladen hat – auch vor dem
	 * Boot liefert sie einfach '' zurueck. Der Update-Client behandelt '' wie
	 * «kein Token» (Rueckfallebene: eigene Option).
	 *
	 * @param string $slug Plugin-Slug.
	 * @return string Token oder ''.
	 */
	function bdliz_get_token( $slug ) {
		if ( class_exists( 'BDLIZ_Module' ) && BDLIZ_Module::instance() ) {
			return BDLIZ_Module::instance()->token_for( (string) $slug );
		}
		return '';
	}

	/**
	 * Neueste registrierte Modul-Kopie laden und starten.
	 */
	function bdliz_boot() {
		$candidates = $GLOBALS['bdliz_registry']['candidates'];
		if ( empty( $candidates ) ) {
			return;
		}
		uksort( $candidates, 'version_compare' );
		$path = end( $candidates ); // Hoechste Version.
		if ( ! class_exists( 'BDLIZ_Module' ) && is_readable( $path ) ) {
			require_once $path;
		}
		if ( class_exists( 'BDLIZ_Module' ) ) {
			BDLIZ_Module::boot( $GLOBALS['bdliz_registry']['plugins'] );
		}
	}
	add_action( 'plugins_loaded', 'bdliz_boot', 1 );

endif;
