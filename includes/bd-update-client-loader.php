<?php
/**
 * Lader fuer den BD Update Client – die NEUESTE mitgelieferte Kopie gewinnt.
 *
 * Hintergrund (Befund 18.08.2026): Beim frueheren Muster
 * «class_exists-Guard + direktes require» definierte die ZUERST geladene
 * Plugin-Kopie die Klasse fuer die ganze Website. Eine einzige veraltete
 * Kopie (z.B. mit unvollstaendiger ACCEPTED_KEYS-Liste) blockierte damit
 * die Updates aller Blitz-&-Donner-Plugins derselben Installation.
 *
 * Neues Muster (identisch zum bdliz-Loader): Jedes Plugin registriert auf
 * Dateiebene die Version und den Pfad seiner Kopie; auf plugins_loaded
 * (Prioritaet 0) laedt dieser Lader die hoechste registrierte Version.
 * Die Instanziierung in den Plugins (Prioritaet 1) findet die Klasse dann
 * bereits vor. Ein class_exists-Fallback in den Plugins bleibt erlaubt –
 * im Mischbetrieb mit Alt-Plugins gewinnt trotzdem die neueste Kopie,
 * sobald EIN Plugin dieses Muster mitbringt.
 *
 * Einbindung in der Plugin-Hauptdatei (auf Dateiebene, nicht im Hook):
 *
 *   require_once __DIR__ . '/includes/bd-update-client-loader.php';
 *   bd_update_client_register(
 *       '3.0.0',                                           // Version DIESER Kopie (Stub-Header)
 *       __DIR__ . '/includes/class-bd-update-client.php'
 *   );
 *
 * @package bd-update-client
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bd_update_client_register' ) ) :

	$GLOBALS['bd_update_client_registry'] = array();

	/**
	 * Kopie des Update-Clients registrieren.
	 *
	 * @param string $version Version der mitgelieferten Kopie (siehe Stub-Header).
	 * @param string $path    Absoluter Pfad zur class-bd-update-client.php.
	 */
	function bd_update_client_register( $version, $path ) {
		$GLOBALS['bd_update_client_registry'][ (string) $version ] = (string) $path;
	}

	/**
	 * Hoechste registrierte Kopie laden – vor den Instanziierungen (Prio 1).
	 */
	function bd_update_client_boot() {
		$candidates = $GLOBALS['bd_update_client_registry'];
		if ( empty( $candidates ) ) {
			return;
		}
		uksort( $candidates, 'version_compare' );
		$path = end( $candidates );
		if ( ! class_exists( 'BD_Update_Client' ) && is_readable( $path ) ) {
			require_once $path;
		}
	}
	add_action( 'plugins_loaded', 'bd_update_client_boot', 0 );

endif;
