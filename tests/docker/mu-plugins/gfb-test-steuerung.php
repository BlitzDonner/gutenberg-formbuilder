<?php
/**
 * Plugin Name: GFB Testreihe – Steuerung
 * Description: Stellt fremde Dienste nach, die im Wegwerf-Container nicht erreichbar sind.
 *
 * Jeder Eingriff hängt an der Option gfb_test_steuerung und ist standardmässig aus.
 * Ohne gesetzten Schalter verhält sich WordPress wie ohne diese Datei. Der Code des
 * Plugins läuft in jedem Fall unverändert – nachgestellt wird nur die Antwort des
 * fremden Servers.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liest einen Schalter aus der Steuerungs-Option.
 *
 * @param string $name    Name des Schalters.
 * @param mixed  $vorgabe Rückgabe, wenn nichts gesetzt ist.
 * @return mixed
 */
function gfb_test_schalter( $name, $vorgabe = '' ) {
	$alle = get_option( 'gfb_test_steuerung', array() );
	return is_array( $alle ) && isset( $alle[ $name ] ) ? $alle[ $name ] : $vorgabe;
}

/**
 * Ablehnung durch ein Fremdsystem nachstellen (Prüfpunkt E13).
 */
add_filter(
	'gfb_submit_button_validation',
	static function ( $ergebnis ) {
		if ( 'reject' !== gfb_test_schalter( 'external' ) ) {
			return $ergebnis;
		}
		return new WP_Error( 'gfb_test_extern', 'Testlauf: Fremdsystem lehnt ab.' );
	},
	99
);

/**
 * Grenze des Häufigkeits-Schutzes senken, damit der Prüfpunkt schnell greift.
 */
add_filter(
	'gfb_rate_limit_max',
	static function ( $max ) {
		$wert = gfb_test_schalter( 'rate_limit_max' );
		return '' === $wert ? $max : (int) $wert;
	},
	99
);

/**
 * Eingabe-Nachweis ausschalten, für die Gegenprobe in Gruppe E.
 */
add_filter(
	'gfb_nachweis_aktiv',
	static function ( $aktiv ) {
		return 'aus' === gfb_test_schalter( 'nachweis' ) ? false : $aktiv;
	},
	99
);

/**
 * Mindestzeit des Eingabe-Nachweises anheben, für den Prüfpunkt «zu schnell».
 */
add_filter(
	'gfb_nachweis_mindestzeit',
	static function ( $sekunden ) {
		$wert = gfb_test_schalter( 'nachweis_mindestzeit' );
		return '' === $wert ? $sekunden : (int) $wert;
	},
	99
);

/**
 * Antwort des Update-Servers nachstellen (Gruppe N).
 */
add_filter(
	'pre_http_request',
	static function ( $vorab, $args, $url ) {
		if ( false === strpos( (string) $url, 'plugins.blitzdonner.ch' ) ) {
			return $vorab;
		}
		$antwort = gfb_test_schalter( 'update_antwort' );
		if ( '' === $antwort ) {
			// Ohne Schalter nie nach draussen telefonieren.
			return new WP_Error( 'http_request_failed', 'Testlauf: kein Zugriff auf den Update-Server.' );
		}
		return array(
			'headers'  => array(),
			'body'     => $antwort,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
