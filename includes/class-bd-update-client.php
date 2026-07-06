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
 * - Ed25519-Signatur (PFLICHT): Jedes Paket muss eine gueltige Ed25519-Signatur
 *   mitliefern. Der Client prueft sie gegen die eingebettete Liste akzeptierter
 *   Public Keys (ACCEPTED_KEYS). Fehlt die Signatur, ist sie ungueltig oder fehlt
 *   libsodium, wird NICHT installiert. Das Plugin bleibt dabei voll funktions-
 *   faehig – verweigert wird nur das Update, kein Killswitch. Fehlt libsodium,
 *   zeigt der Client zusaetzlich eine Admin-Notice.
 *
 * Einbinden im Hauptplugin (Beispiel):
 *
 *   require_once __DIR__ . '/includes/class-bd-update-client.php';
 *   new BD_Update_Client( array(
 *       'plugin_file' => __FILE__,                 // Hauptdatei des Plugins
 *       'slug'        => 'gutenberg-formbuilder',  // Slug auf dem Server
 *       'server_url'  => 'https://plugins.blitzdonner.ch',
 *       'version'     => '2.6.3',                   // aktuelle Version
 *       'option_key'  => 'gfb_update_token',        // Einstellungs-Option (DB)
 *       'const_key'   => 'GFB_UPDATE_TOKEN',         // wp-config-Konstante (Vorrang)
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

	/**
	 * Akzeptierte Ed25519-Public-Keys (Hex), eingebettet und versioniert.
	 *
	 * Muss mit der Server-Liste (class-bdpus-keys.php) konsistent gehalten werden.
	 * Format: key_id => public_key_hex (key_id = erste 16 Hex des Public Keys).
	 */
	const ACCEPTED_KEYS = array(
		// Inhaber: Blitz & Donner / Stefan (Produktiv) – erzeugt 2026-06-21.
		'8337dfb76e01b82d' => '8337dfb76e01b82da07e39df711d72f758f33a372932db268bb505b0bdea4860',
		// Inhaber: Blitz & Donner / David – erzeugt 2026-06-26.
		'85044a46882e9338' => '85044a46882e93386304cb8329b2407ba7d7f970f727d99d4b546fc79391e2bd',
	);

	/**
	 * Pflicht-Schalter fuer die Signaturpruefung.
	 *
	 * true = Pflicht-Modus (aktiv): eine fehlende oder ungueltige Signatur
	 *        verhindert die Installation. Auch ein fehlendes libsodium verhindert
	 *        die Installation (zusaetzlich Admin-Notice). Das Plugin laeuft in
	 *        allen Faellen normal weiter – es wird nur das Update verweigert, es
	 *        gibt keinen Killswitch (GPL-Grenze, Harvey).
	 *
	 * Voraussetzung: Jede ausgelieferte Version muss bereits signiert sein.
	 */
	const REQUIRE_SIGNATURE = true;

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

		// Pflicht-Vorbedingung (Chloe): fehlt libsodium, koennen Updates nicht
		// geprueft und damit nicht installiert werden. Statt still zu blockieren,
		// macht eine Admin-Notice die Ursache sichtbar.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			add_action( 'admin_notices', array( $this, 'sodium_missing_notice' ) );
		}
	}

	/**
	 * Admin-Notice, wenn libsodium auf dem Server fehlt.
	 *
	 * Im Pflicht-Modus kann der Client ohne libsodium keine Signatur pruefen und
	 * verweigert daher die Installation. Diese Notice erklaert die Ursache, statt
	 * die Updates kommentarlos auszublenden. Das Plugin bleibt funktionsfaehig.
	 */
	public function sodium_missing_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'update-core', 'dashboard' ), true ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo '<strong>' . esc_html( $this->slug ) . ':</strong> ';
		echo esc_html__( 'Automatische Updates fuer dieses Plugin sind deaktiviert, weil die PHP-Erweiterung libsodium fehlt. Bitte den Hoster kontaktieren.', 'bd-update-client' );
		echo '</p></div>';
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
	/* Oeffentlicher Status fuer die Backend-Anzeige                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Liefert den aktuellen Update-/Lizenz-Status fuer eine Backend-Status-Box.
	 *
	 * Gibt KEIN Token zurueck – nur abgeleitete, anzeigbare Statusinformationen.
	 *
	 * @param bool $force_remote True erzwingt einen frischen Server-Check am
	 *                           Request-Cache vorbei (fuer «Token testen»).
	 *
	 * @return array {
	 *     @type string $code     Einer von: const_set | no_token | valid |
	 *                            forbidden | unreachable | no_update.
	 *     @type string $version  Installierte Version.
	 *     @type string $remote_version  Server-Version bei gueltigem Token, sonst ''.
	 *     @type bool   $by_const Token kommt aus der wp-config-Konstante.
	 * }
	 */
	public function status( $force_remote = false ) {
		$by_const = ( $this->const_key && defined( $this->const_key ) );

		$result = array(
			'code'           => 'no_token',
			'version'        => $this->version,
			'remote_version' => '',
			'by_const'       => $by_const,
		);

		$token = $this->get_token();
		if ( '' === $token ) {
			$result['code'] = $by_const ? 'const_set' : 'no_token';
			return $result;
		}

		if ( $force_remote ) {
			// Request-Cache verwerfen, damit ein echter Live-Check stattfindet.
			$this->remote = null;
		}

		$remote = $this->fetch_remote();

		if ( is_wp_error( $remote ) ) {
			switch ( $remote->get_error_code() ) {
				case 'forbidden':
					$result['code'] = 'forbidden';
					break;
				case 'no_update':
					// Token gueltig, aber keine neuere Version vorhanden.
					$result['code'] = 'valid';
					break;
				case 'no_token':
					$result['code'] = $by_const ? 'const_set' : 'no_token';
					break;
				default:
					$result['code'] = 'unreachable';
			}
			return $result;
		}

		// Gueltige Server-Antwort mit Versionsdaten.
		$result['code']           = 'valid';
		$result['remote_version'] = isset( $remote['version'] ) ? (string) $remote['version'] : '';
		return $result;
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

		// Ohne Token wird trotzdem angefragt: freie Plugins (free_updates auf
		// dem Server) liefern auch ohne Lizenz aus. Der Server entscheidet –
		// bei lizenzpflichtigen Plugins antwortet er mit 403.
		$token   = $this->get_token();
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $token ) {
			// SICHERHEIT: Token im Header, nie in der URL.
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$url = $this->server_url . '/bd-updater/check/' . rawurlencode( $this->slug );

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => $headers,
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
				// 'id' wird von der WP-Plugin-Liste fuer eigene Update-Provider
				// erwartet (column_auto_updates / filter_payload). Ohne id kann WP
				// das Item als nicht update-faehig behandeln.
				'id'           => $this->basename,
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
				// Ed25519-Signatur (base64) fuer guard_download(); Pflicht.
				'bd_signature' => isset( $remote['signature'] ) ? $remote['signature'] : '',
				'bd_key_id'    => isset( $remote['key_id'] ) ? $remote['key_id'] : '',
			);
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			// Kein Update: aus der Liste entfernen, falls vorhanden.
			unset( $transient->response[ $this->basename ] );
			$transient->no_update[ $this->basename ] = (object) array(
				'id'          => $this->basename,
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

		// Auch ohne Token herunterladen (freie Plugins) – der Server lehnt
		// lizenzpflichtige Downloads ohne Token mit 403 ab.
		$token = $this->get_token();

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

		// Ed25519-Signatur pruefen (Pflicht: muss vorhanden und gueltig sein).
		$sig_check = $this->verify_signature( $tmp );
		if ( is_wp_error( $sig_check ) ) {
			@unlink( $tmp );
			return $sig_check;
		}

		return $tmp;
	}

	/**
	 * Signatur der heruntergeladenen Datei gegen ACCEPTED_KEYS pruefen.
	 *
	 * Pflicht-Modus (REQUIRE_SIGNATURE = true, aktiv):
	 *   - Signatur fehlt                 -> WP_Error (Installation bricht ab).
	 *   - libsodium fehlt                -> WP_Error (zusaetzlich Admin-Notice).
	 *   - Signatur ungueltig/unbekannt   -> WP_Error.
	 *   - Signatur gueltig               -> true.
	 *
	 * In allen Fehlerfaellen wird nur das Update verweigert; das Plugin laeuft
	 * normal weiter (GPL-Grenze, kein Killswitch).
	 *
	 * @param string $file Pfad zur heruntergeladenen ZIP-Datei.
	 * @return true|WP_Error
	 */
	private function verify_signature( $file ) {
		$signature = $this->expected_signature();

		if ( '' === $signature ) {
			// Keine Signatur geliefert.
			if ( self::REQUIRE_SIGNATURE ) {
				return new WP_Error(
					'signature_required',
					__( 'Paket ist nicht signiert – Installation abgebrochen.', 'bd-update-client' )
				);
			}
			return true; // Pflicht-Modus aktiv: hier nie erreicht.
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			// libsodium fehlt. Im Pflicht-Modus blockieren (Admin-Notice zeigt
			// die Ursache, siehe sodium_missing_notice()).
			if ( self::REQUIRE_SIGNATURE ) {
				return new WP_Error(
					'no_sodium',
					__( 'Signaturpruefung nicht moeglich (libsodium fehlt) – Installation abgebrochen.', 'bd-update-client' )
				);
			}
			return true;
		}

		$sig_raw = base64_decode( $signature, true );
		if ( false === $sig_raw || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig_raw ) ) {
			return new WP_Error(
				'bad_signature',
				__( 'Signaturformat ungueltig – Installation abgebrochen.', 'bd-update-client' )
			);
		}

		$data = file_get_contents( $file );
		if ( false === $data ) {
			return new WP_Error(
				'file_unreadable',
				__( 'Paket konnte fuer die Signaturpruefung nicht gelesen werden.', 'bd-update-client' )
			);
		}

		foreach ( self::ACCEPTED_KEYS as $pub_hex ) {
			$pub_raw = @hex2bin( $pub_hex );
			if ( false === $pub_raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub_raw ) ) {
				continue;
			}
			if ( sodium_crypto_sign_verify_detached( $sig_raw, $data, $pub_raw ) ) {
				return true;
			}
		}

		return new WP_Error(
			'signature_mismatch',
			__( 'Signatur passt zu keinem vertrauten Schluessel – Installation abgebrochen.', 'bd-update-client' )
		);
	}

	/**
	 * Erwartete Signatur (base64) aus dem Update-Transient lesen.
	 */
	private function expected_signature() {
		$transient = get_site_transient( 'update_plugins' );
		if ( $transient && isset( $transient->response[ $this->basename ]->bd_signature ) ) {
			return (string) $transient->response[ $this->basename ]->bd_signature;
		}
		$remote = $this->fetch_remote();
		if ( ! is_wp_error( $remote ) && isset( $remote['signature'] ) ) {
			return (string) $remote['signature'];
		}
		return '';
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

		$headers = array();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post( $url, array(
			'timeout'  => 60,
			'stream'   => true,
			'filename' => wp_tempnam( $this->slug . '.zip' ),
			'headers'  => $headers,
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
