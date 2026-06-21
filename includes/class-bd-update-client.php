<?php
/**
 * BD Update Client – einbettbarer Update-Client fuer Agentur-Plugins.
 *
 * Diese Klasse klinkt ein beliebiges Plugin in die WordPress-Standard-Update-API
 * ein und bezieht Updates vom Self-hosted Server (plugins.blitzdonner.ch).
 *
 * GPL-GRENZE (Harvey, zwingend):
 * Bei abgelaufenem oder gesperrtem Token verweigert dieser Client NUR die
 * Updates und zeigt einen Hinweis. Er deaktiviert das Plugin NIEMALS und schaltet
 * keine Funktion ab. Es gibt keinen Killswitch. Das Plugin bleibt unter der GPL
 * voll funktionsfaehig.
 *
 * SICHERHEIT:
 * - Token wird per Authorization-Header (Bearer) gesendet, nie als URL-Parameter.
 * - Nach dem Download prueft der Client die SHA-256-Pruefsumme. Bei Abweichung
 *   wird NICHT installiert.
 *
 * Einbinden im Hauptplugin (Beispiel):
 *
 *   require_once __DIR__ . '/includes/class-bd-update-client.php';
 *   new BD_Update_Client( array(
 *       'plugin_file' => __FILE__,                 // Hauptdatei des Plugins
 *       'slug'        => 'gutenberg-formbuilder',  // Slug auf dem Server
 *       'server_url'  => 'https://plugins.blitzdonner.ch',
 *       'version'     => '2.6.3',                   // aktuelle Version
 *       'option_key'  => 'gfb_license_token',       // Einstellungs-Option
 *       'const_key'   => 'GFB_LICENSE_TOKEN',       // optionale wp-config-Konstante
 *   ) );
 *
 * @package bd-update-client
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BD_Update_Client' ) ) :

class BD_Update_Client {

	/** @var string Absolute Hauptdatei des Plugins. */
	private $plugin_file;

	/** @var string plugin_basename (z.B. gutenberg-formbuilder/gutenberg-formbuilder.php). */
	private $basename;

	/** @var string Slug auf dem Update-Server. */
	private $slug;

	/** @var string Basis-URL des Servers (ohne abschliessenden Slash). */
	private $server_url;

	/** @var string Aktuell installierte Version. */
	private $version;

	/** @var string Option-Key fuer das Token. */
	private $option_key;

	/** @var string Optionaler wp-config-Konstantenname fuer das Token. */
	private $const_key;

	/** @var array|null Cache der Server-Antwort innerhalb eines Requests. */
	private $remote = null;

	public function __construct( $args ) {
		$this->plugin_file = $args['plugin_file'];
		$this->basename    = plugin_basename( $this->plugin_file );
		$this->slug        = $args['slug'];
		$this->server_url  = rtrim( $args['server_url'], '/' );
		$this->version     = $args['version'];
		$this->option_key  = isset( $args['option_key'] ) ? $args['option_key'] : '';
		$this->const_key   = isset( $args['const_key'] ) ? $args['const_key'] : '';

		// In die Update-Pruefung einklinken.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Plugin-Detail-Popup (View details).
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );

		// SHA-256-Pruefung beim Download.
		add_filter( 'upgrader_pre_download', array( $this, 'guard_download' ), 10, 4 );

		// Hinweis bei abgelaufenem/gesperrtem Token (KEIN Killswitch).
		add_action( 'admin_notices', array( $this, 'maybe_license_notice' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Token-Quelle                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Token aus wp-config-Konstante ODER Einstellungs-Option lesen.
	 * Die Konstante hat Vorrang.
	 */
	private function get_token() {
		if ( $this->const_key && defined( $this->const_key ) ) {
			return (string) constant( $this->const_key );
		}
		if ( $this->option_key ) {
			return (string) get_option( $this->option_key, '' );
		}
		return '';
	}

	private function current_host() {
		$parts = wp_parse_url( home_url() );
		return isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	}

	/* --------------------------------------------------------------------- */
	/* Server-Abfrage                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Update-Check beim Server. Ergebnis wird im Objekt gecacht.
	 *
	 * @return array|WP_Error array mit Server-Daten oder WP_Error.
	 */
	private function fetch_remote() {
		if ( null !== $this->remote ) {
			return $this->remote;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			$this->remote = new WP_Error( 'no_token', __( 'Kein Lizenz-Token hinterlegt.', 'bd-update-client' ) );
			return $this->remote;
		}

		$url = $this->server_url . '/bd-updater/check/' . rawurlencode( $this->slug );

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				// SICHERHEIT: Token im Header, nie in der URL.
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			'body'    => array(
				'domain' => $this->current_host(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->remote = $response;
			return $this->remote;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 403 === $code ) {
			// Token ungueltig/gesperrt/abgelaufen oder Domain nicht erlaubt.
			$this->remote = new WP_Error( 'forbidden', __( 'Lizenz abgelaufen oder ungueltig – keine Updates.', 'bd-update-client' ) );
			return $this->remote;
		}

		if ( 200 !== $code || ! is_array( $body ) ) {
			$this->remote = new WP_Error( 'bad_response', __( 'Update-Server nicht erreichbar.', 'bd-update-client' ) );
			return $this->remote;
		}

		if ( isset( $body['error'] ) ) {
			// z.B. no_active_version: kein Fehler im engeren Sinn, aber kein Update.
			$this->remote = new WP_Error( 'no_update', $body['error'] );
			return $this->remote;
		}

		$this->remote = $body;
		return $this->remote;
	}

	/* --------------------------------------------------------------------- */
	/* WP-Update-API                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * In den Update-Transient einklinken.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->fetch_remote();
		if ( is_wp_error( $remote ) ) {
			// Kein Update anbieten; Plugin laeuft normal weiter (GPL-Grenze).
			return $transient;
		}

		// Nur anbieten, wenn die Server-Version neuer ist.
		if ( version_compare( $remote['version'], $this->version, '>' ) ) {
			$item = array(
				'slug'         => $this->slug,
				'plugin'       => $this->basename,
				'new_version'  => $remote['version'],
				'url'          => $this->server_url,
				// Download-URL zeigt auf den Endpunkt; Token kommt per Header
				// in guard_download() bzw. ueber den Authorization-Header.
				'package'      => $this->server_url . '/bd-updater/download/' . rawurlencode( $this->slug ),
				'requires'     => isset( $remote['requires'] ) ? $remote['requires'] : '',
				'requires_php' => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '',
				// Eigene Pruefsumme fuer guard_download().
				'bd_sha256'    => isset( $remote['sha256'] ) ? $remote['sha256'] : '',
			);
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			// Kein Update: aus der Liste entfernen, falls vorhanden.
			unset( $transient->response[ $this->basename ] );
			$transient->no_update[ $this->basename ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'url'         => $this->server_url,
				'package'     => '',
			);
		}

		return $transient;
	}

	/**
	 * Detail-Popup mit Changelog.
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$remote = $this->fetch_remote();
		if ( is_wp_error( $remote ) ) {
			return $result;
		}

		return (object) array(
			'name'          => isset( $remote['name'] ) ? $remote['name'] : $this->slug,
			'slug'          => $this->slug,
			'version'       => $remote['version'],
			'requires'      => isset( $remote['requires'] ) ? $remote['requires'] : '',
			'requires_php'  => isset( $remote['requires_php'] ) ? $remote['requires_php'] : '',
			'download_link' => $this->server_url . '/bd-updater/download/' . rawurlencode( $this->slug ),
			'sections'      => isset( $remote['sections'] ) ? $remote['sections'] : array( 'changelog' => '' ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Sicherer Download mit SHA-256-Pruefung                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Eigener Download-Hook: laedt das Paket mit Token-Header herunter und
	 * prueft die SHA-256-Pruefsumme. Bei Abweichung wird NICHT installiert.
	 *
	 * @param bool|WP_Error $reply       Standard false (kein Eingriff).
	 * @param string        $package     Paket-URL.
	 * @param WP_Upgrader   $upgrader    Upgrader-Instanz.
	 * @param array         $hook_extra  Kontext (enthaelt 'plugin').
	 *
	 * @return bool|string|WP_Error Pfad zur Datei, false (kein Eingriff) oder WP_Error.
	 */
	public function guard_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		// Nur fuer unser Plugin eingreifen.
		if ( strpos( (string) $package, $this->server_url . '/bd-updater/download/' . $this->slug ) !== 0 ) {
			return $reply;
		}

		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error( 'no_token', __( 'Kein Lizenz-Token hinterlegt – Download nicht moeglich.', 'bd-update-client' ) );
		}

		// Erwartete Pruefsumme aus dem Update-Transient holen.
		$expected = $this->expected_sha256();

		// Datei mit Authorization-Header herunterladen.
		$tmp = $this->download_with_header( $package, $token );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// SHA-256 pruefen.
		if ( $expected ) {
			$actual = hash_file( 'sha256', $tmp );
			if ( ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) {
				@unlink( $tmp );
				return new WP_Error(
					'checksum_mismatch',
					__( 'Pruefsumme stimmt nicht ueberein – Installation abgebrochen.', 'bd-update-client' )
				);
			}
		}

		return $tmp;
	}

	/**
	 * Erwartete Pruefsumme aus dem Update-Transient lesen.
	 */
	private function expected_sha256() {
		$transient = get_site_transient( 'update_plugins' );
		if ( $transient && isset( $transient->response[ $this->basename ]->bd_sha256 ) ) {
			return (string) $transient->response[ $this->basename ]->bd_sha256;
		}
		// Fallback: frische Server-Antwort.
		$remote = $this->fetch_remote();
		if ( ! is_wp_error( $remote ) && isset( $remote['sha256'] ) ) {
			return (string) $remote['sha256'];
		}
		return '';
	}

	/**
	 * ZIP mit Authorization-Header herunterladen und in eine Temp-Datei legen.
	 */
	private function download_with_header( $url, $token ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$response = wp_remote_post( $url, array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => wp_tempnam( $this->slug . '.zip' ),
			'headers'  => array(
				'Authorization' => 'Bearer ' . $token,
			),
			'body'     => array(
				'domain' => $this->current_host(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$file = $response['filename'];

		if ( 200 !== $code ) {
			@unlink( $file );
			if ( 403 === $code ) {
				return new WP_Error( 'forbidden', __( 'Lizenz abgelaufen oder ungueltig – keine Updates.', 'bd-update-client' ) );
			}
			return new WP_Error( 'download_failed', __( 'Download fehlgeschlagen.', 'bd-update-client' ) );
		}

		return $file;
	}

	/* --------------------------------------------------------------------- */
	/* Hinweis ohne Killswitch (GPL-Grenze)                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Zeigt einen Admin-Hinweis, wenn die Lizenz abgelaufen/gesperrt ist.
	 * Das Plugin bleibt voll funktionsfaehig – es werden nur keine Updates
	 * angeboten.
	 */
	public function maybe_license_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		// Nur auf relevanten Seiten zeigen.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'update-core', 'dashboard' ), true ) ) {
			return;
		}

		$remote = $this->fetch_remote();
		if ( is_wp_error( $remote ) && in_array( $remote->get_error_code(), array( 'forbidden', 'no_token' ), true ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>' . esc_html( $this->slug ) . ':</strong> ';
			echo esc_html__( 'Lizenz abgelaufen – keine Updates. Das Plugin funktioniert weiterhin normal.', 'bd-update-client' );
			echo '</p></div>';
		}
	}
}

endif;
