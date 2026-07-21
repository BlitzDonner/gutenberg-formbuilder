<?php
/**
 * Plugin-Admin-Seite: Sicherheits- und Einstellungsbereich.
 *
 * Untermenü der Formular-Einträge; eigener Capability-Check
 * (gfb_manage_settings, fallback manage_options).
 *
 * Bereiche:
 * 1) Verschlüsselungs-Status (KEK aus wp-config, Anzahl Keys, aktive Key-ID, Self-Test)
 * 2) ClamAV (Mode, Pfad/Socket, Timeout, EICAR-Test, Pflicht für Uploads)
 * 3) Capability-Mapping (Tabelle Rollen x Plugin-Caps mit Toggles)
 * 4) Audit-Verifikation (Hash-Chain prüfen)
 * 5) Formular (Frontend): Safari/WebKit Datums-Text-Fallback
 * 6) Key-Rotation (Re-Wrap-Run starten)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Admin_Settings {

	const PAGE_SLUG = 'gfb-settings';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_post' ) );
		add_action( 'admin_init', array( 'GFB_Clamav', 'maybe_migrate_defaults' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_global_notices' ) );
		add_action( 'send_headers', array( __CLASS__, 'maybe_send_admin_csp' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_send_admin_csp' ) );
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			GFB_Admin_Submissions::PAGE_SLUG,
			__( 'Sicherheit & Einstellungen', 'gutenberg-formbuilder' ),
			__( 'Sicherheit & Einstellungen', 'gutenberg-formbuilder' ),
			GFB_Capabilities::CAP_MANAGE_SETTINGS,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Globaler Hinweis im Admin, wenn Crypto fehlt.
	 *
	 * @return void
	 */
	public static function render_global_notices() {
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			return;
		}
		$status = GFB_Crypto::status();
		if ( ! $status['ok'] ) {
			echo '<div class="notice notice-error"><p><strong>Blitz & Donner Formular:</strong> '
				. esc_html__( 'Verschlüsselung ist NICHT aktiv. Datei-Uploads sind blockiert, sensible Felder werden im Klartext gespeichert. Grund: ', 'gutenberg-formbuilder' )
				. esc_html( $status['reason'] )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
				. esc_html__( 'Jetzt einrichten', 'gutenberg-formbuilder' ) . '</a></p></div>';
		}
		$clamav_pre = GFB_Clamav::precondition_for_uploads();
		if ( is_wp_error( $clamav_pre ) ) {
			echo '<div class="notice notice-warning"><p><strong>Blitz & Donner Formular:</strong> '
				. esc_html( $clamav_pre->get_error_message() ) . '</p></div>';
		}
		$reach = get_transient( 'gfb_storage_reach' );
		if ( is_array( $reach ) && empty( $reach['ok'] ) ) {
			echo '<div class="notice notice-error"><p><strong>Blitz & Donner Formular – wichtiger Sicherheitshinweis:</strong> '
				. esc_html__( 'Hochgeladene Dateien könnten direkt aus dem Internet abrufbar sein. Die Plugin-Verschlüsselung schützt den Inhalt zwar weiterhin, aber dein Webserver sollte das Verzeichnis komplett blockieren. ', 'gutenberg-formbuilder' )
				. '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
				. esc_html__( 'Details und Anleitung ansehen', 'gutenberg-formbuilder' ) . '</a></p></div>';
		}
	}

	/**
	 * Streng moderater CSP-Header NUR für Plugin-Admin-Seiten.
	 * Blockt Inline-Scripte ausserhalb von WP-Core würde Editoren brechen,
	 * darum: Plugin-Pages bekommen `script-src 'self' 'unsafe-inline'`,
	 * aber alles andere ist eingeschränkt.
	 *
	 * @return void
	 */
	public static function maybe_send_admin_csp() {
		if ( headers_sent() || ! is_admin() ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== GFB_Admin_Submissions::PAGE_SLUG && $page !== self::PAGE_SLUG && $page !== GFB_Admin_Audit::PAGE_SLUG ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header(
			"Content-Security-Policy: default-src 'self'; "
			. "img-src 'self' data: blob:; "
			. "style-src 'self' 'unsafe-inline'; "
			. "script-src 'self' 'unsafe-inline'; "
			. "font-src 'self' data:; "
			. "object-src 'none'; "
			. "frame-ancestors 'self'; "
			. "base-uri 'self';"
		);
	}

	/**
	 * @return void
	 */
	public static function maybe_handle_post() {
		if ( empty( $_POST['gfb_settings_action'] ) ) {
			return;
		}
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ), 403 );
		}
		check_admin_referer( 'gfb_settings_action' );

		$action  = sanitize_key( (string) $_POST['gfb_settings_action'] );
		$message = '';

		switch ( $action ) {
			case 'save_clamav':
				GFB_Clamav::update_settings(
					array(
						'mode'                => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'disabled',
						'binary_path'         => isset( $_POST['binary_path'] ) ? wp_unslash( $_POST['binary_path'] ) : '',
						'socket_path'         => isset( $_POST['socket_path'] ) ? wp_unslash( $_POST['socket_path'] ) : '',
						'timeout_sec'         => isset( $_POST['timeout_sec'] ) ? (int) $_POST['timeout_sec'] : 30,
						'require_for_uploads' => ! empty( $_POST['require_for_uploads'] ),
					)
				);
				GFB_Audit::record( 'settings_clamav_saved', 'config', '', array() );
				$message = __( 'ClamAV-Einstellungen gespeichert.', 'gutenberg-formbuilder' );
				break;

			case 'eicar_test':
				$res     = GFB_Clamav::scan_eicar();
				$message = sprintf(
					/* translators: 1: status, 2: details */
					__( 'EICAR-Test: %1$s (%2$s)', 'gutenberg-formbuilder' ),
					$res['status'],
					$res['details']
				);
				GFB_Audit::record( 'clamav_eicar_test', 'system', '', $res );
				break;

			case 'save_captcha':
				$prev          = GFB_Captcha::get_settings();
				$new_mode      = isset( $_POST['captcha_mode'] ) && 'strict' === sanitize_key( wp_unslash( $_POST['captcha_mode'] ) ) ? 'strict' : 'soft';
				$message       = __( 'CAPTCHA-Einstellungen gespeichert.', 'gutenberg-formbuilder' );

				// Expliziter Vergleich gegen das Radio-Wertschema (value="1"/"0").
				$enabled = ( '1' === ( isset( $_POST['captcha_enabled'] ) ? sanitize_key( wp_unslash( $_POST['captcha_enabled'] ) ) : '' ) );

				// API-Key (Secret) wird leer gerendert. Leer abgeschickt =
				// bestehenden Wert beibehalten (nicht versehentlich loeschen).
				$api_key_in = isset( $_POST['captcha_api_key'] ) ? trim( (string) wp_unslash( $_POST['captcha_api_key'] ) ) : '';
				$api_key_to_store = ( '' === $api_key_in ) ? $prev['api_key'] : $api_key_in;

				GFB_Captcha::update_settings(
					array(
						'enabled'  => $enabled,
						'site_key' => isset( $_POST['captcha_site_key'] ) ? wp_unslash( $_POST['captcha_site_key'] ) : '',
						'api_key'  => $api_key_to_store,
						'mode'     => $new_mode,
					)
				);

				// Audit ohne Secret im Klartext: nur Status-Flags, keine Keys.
				$saved = GFB_Captcha::get_settings();
				GFB_Audit::record(
					'settings_captcha_saved',
					'config',
					'',
					array(
						'enabled'      => $saved['enabled'] ? 'yes' : 'no',
						'mode'         => $saved['mode'],
						'site_key_set' => '' !== $saved['site_key'] ? 'yes' : 'no',
						'api_key_set'  => '' !== $saved['api_key'] ? 'yes' : 'no',
					)
				);
				break;

			case 'save_license':
				// Konstanten-Vorrang serverseitig hart durchsetzen: Ist die
				// wp-config-Konstante gesetzt, ignorieren wir jede Eingabe und
				// fassen den DB-Wert NICHT an. Der POST darf den Wert nicht ueberschreiben.
				if ( defined( 'GFB_UPDATE_TOKEN' ) ) {
					$message = __( 'Lizenz-Token wird per wp-config gesetzt (Vorrang). Eingaben im Backend werden ignoriert.', 'gutenberg-formbuilder' );
					break;
				}

				// Token wird leer gerendert. Leer abgeschickt = bestehenden Wert
				// beibehalten (analog captcha_api_key), nicht versehentlich loeschen.
				$prev_token = (string) get_option( 'gfb_update_token', '' );
				$token_in   = isset( $_POST['gfb_update_token'] ) ? trim( (string) wp_unslash( $_POST['gfb_update_token'] ) ) : '';

				// Konservative Sanitisierung: nur sichere Token-Zeichen, Laenge cappen.
				$token_in = preg_replace( '/[^A-Za-z0-9._\-]/', '', $token_in );
				if ( strlen( $token_in ) > 512 ) {
					$token_in = substr( $token_in, 0, 512 );
				}

				$token_to_store = ( '' === $token_in ) ? $prev_token : $token_in;

				// autoload=false: Token nicht in den globalen Options-Cache laden.
				update_option( 'gfb_update_token', $token_to_store, false );

				// Audit ohne Klartext: nur das Gesetzt-Flag.
				GFB_Audit::record(
					'settings_license_saved',
					'config',
					'',
					array( 'token_set' => '' !== $token_to_store ? 'yes' : 'no' )
				);
				$message = __( 'Lizenz-Token gespeichert.', 'gutenberg-formbuilder' );
				break;

			case 'test_license':
				$client = function_exists( 'gfb_update_client' ) ? gfb_update_client() : null;
				if ( ! $client ) {
					$message = __( 'Update-Client nicht verfügbar.', 'gutenberg-formbuilder' );
					break;
				}
				// Live-Check am Transient-Takt vorbei (Token kommt aus DB/Konstante,
				// nie aus der URL; der Client sendet es per Bearer-Header).
				$st      = $client->status( true );
				$message = self::license_status_message( $st );
				GFB_Audit::record(
					'settings_license_tested',
					'config',
					'',
					array( 'result' => $st['code'] )
				);
				break;

			case 'save_caps':
				$matrix = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array();
				global $wp_roles;
				if ( $wp_roles instanceof WP_Roles ) {
					foreach ( $wp_roles->roles as $role_slug => $_d ) {
						foreach ( GFB_Capabilities::all_caps() as $cap ) {
							$enabled = ! empty( $matrix[ $role_slug ][ $cap ] );
							GFB_Capabilities::set_role_cap( $role_slug, $cap, $enabled );
						}
					}
				}
				GFB_Audit::record( 'settings_caps_saved', 'config', '', array() );
				$message = __( 'Capability-Zuordnung gespeichert.', 'gutenberg-formbuilder' );
				break;

			case 'verify_audit':
				$res     = GFB_Audit::verify_chain();
				$message = $res['ok']
					? sprintf( __( 'Audit-Hash-Chain ok über %d Einträge.', 'gutenberg-formbuilder' ), (int) $res['total'] )
					: sprintf( __( 'Audit-Chain GEBROCHEN bei Eintrag #%d (von %d geprüft).', 'gutenberg-formbuilder' ), (int) $res['broken_at'], (int) $res['total'] );
				GFB_Audit::record( 'audit_chain_verified', 'audit', '', $res );
				break;

			case 'rewrap_now':
				$res = GFB_File_Storage::rewrap_batch( 200 );
				$message = sprintf(
					/* translators: 1: anzahl, 2: errors */
					__( 'Re-Wrap: %1$d Dateien neu verschlüsselt, %2$d Fehler.', 'gutenberg-formbuilder' ),
					(int) $res['processed'],
					(int) $res['errors']
				);
				break;

			case 'storage_reach_test':
				$r = GFB_File_Storage::public_reachability_test();
				set_transient( 'gfb_storage_reach', $r, HOUR_IN_SECONDS );
				$message = ( $r['ok']
						? __( 'Test bestanden. ', 'gutenberg-formbuilder' )
						: __( 'Problem gefunden! ', 'gutenberg-formbuilder' ) )
					. $r['details'];
				GFB_Audit::record( 'storage_reach_test', 'system', '', $r );
				break;

			case 'save_webkit_datetime':
				$enabled = ! empty( $_POST['webkit_datetime_fallback'] );
				update_option( GFB_Plugin::OPTION_WEBKIT_DATETIME_FALLBACK, $enabled ? '1' : '0', false );
				GFB_Audit::record(
					'settings_webkit_datetime_saved',
					'config',
					'',
					array( 'enabled' => $enabled )
				);
				$message = __( 'Formular-Einstellungen gespeichert.', 'gutenberg-formbuilder' );
				break;

			default:
				$message = __( 'Unbekannte Aktion.', 'gutenberg-formbuilder' );
		}

		// Zurück zur Karte, aus der die Aktion kam (Anker), statt an den Seitenanfang.
		$anchors = array(
			'save_clamav'          => 'gfb-clamav',
			'eicar_test'           => 'gfb-clamav',
			'save_license'         => 'gfb-lizenz',
			'test_license'         => 'gfb-lizenz',
			'save_captcha'         => 'gfb-captcha',
			'save_caps'            => 'gfb-berechtigungen',
			'storage_reach_test'   => 'gfb-privatsphaere',
			'save_webkit_datetime' => 'gfb-frontend',
			'rewrap_now'           => 'gfb-rotation',
		);
		$fragment = isset( $anchors[ $action ] ) ? '#' . $anchors[ $action ] : '';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'gfb_m' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			) . $fragment
		);
		exit;
	}

	/**
	 * Baut die Status-Etikette für die Titelzeile einer zugeklappten Karte.
	 *
	 * @param string $state on|off|warn|neutral (Farbe).
	 * @param string $text  Sichtbarer Kurztext.
	 * @return string HTML (escaped).
	 */
	private static function summary_badge( $state, $text ) {
		$allowed = array( 'on', 'off', 'warn', 'neutral' );
		if ( ! in_array( $state, $allowed, true ) ) {
			$state = 'neutral';
		}
		return '<span class="gfb-card-badge gfb-card-badge--' . esc_attr( $state ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ), 403 );
		}
		$status      = GFB_Crypto::status();
		$clamav_set  = GFB_Clamav::get_settings();
		$msg         = isset( $_GET['gfb_m'] ) ? sanitize_text_field( wp_unslash( $_GET['gfb_m'] ) ) : '';

		echo '<div class="wrap gfb-admin gfb-settings">';
		echo '<h1>' . esc_html__( 'Sicherheit & Einstellungen', 'gutenberg-formbuilder' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info" id="gfb-settings-notice"><p>' . esc_html( $msg ) . '</p></div>';
			// Kommt die Rückleitung mit Anker (#gfb-…), wandert die Meldung in die
			// Ziel-Karte, damit sie beim Ankersprung sichtbar ist. Ohne JavaScript
			// bleibt sie einfach oben stehen.
			echo "<script>document.addEventListener('DOMContentLoaded',function(){var h=window.location.hash;if(!h||h.indexOf('#gfb-')!==0){return;}var card=document.getElementById(h.substring(1));var note=document.getElementById('gfb-settings-notice');if(card&&note){card.open=true;note.style.margin='0.6rem 0 1rem';var s=card.querySelector('summary');card.insertBefore(note,s?s.nextSibling:card.firstChild);card.scrollIntoView();}});</script>";
		}

		// 1) Verschlüsselung (erste Karte; jede weitere Sektion schliesst die vorherige Karte).
		$enc_badge = $status['ok']
			? self::summary_badge( 'on', __( 'Aktiv', 'gutenberg-formbuilder' ) )
			: self::summary_badge( 'off', __( 'Nicht aktiv', 'gutenberg-formbuilder' ) );
		echo '<details class="gfb-settings-card" id="gfb-verschluesselung"><summary><h2>' . esc_html__( 'Verschlüsselung', 'gutenberg-formbuilder' ) . '</h2>' . $enc_badge . '</summary>';
		if ( $status['ok'] ) {
			echo '<p style="color:#1a7f37">' . esc_html__( 'Aktiv.', 'gutenberg-formbuilder' )
				. ' ' . sprintf( esc_html__( 'Aktive Schlüssel-ID: %s. Bekannte Schlüssel: %d.', 'gutenberg-formbuilder' ), esc_html( $status['active_id'] ), (int) $status['key_count'] )
				. '</p>';
		} else {
			echo '<p style="color:#a32016"><strong>' . esc_html__( 'NICHT aktiv.', 'gutenberg-formbuilder' ) . '</strong> '
				. esc_html( $status['reason'] ) . '</p>';
			echo '<p>' . esc_html__( 'Vorgehen: in wp-config.php diese Konstanten hinzufügen (oberhalb der "/* That\'s all" Zeile):', 'gutenberg-formbuilder' ) . '</p>';
			$suggested = GFB_Crypto::suggest_new_key_b64();
			echo '<pre style="padding:1rem;background:#1c1c1e;color:#fafafa;border-radius:6px;overflow:auto">'
				. "define( 'GFB_MASTER_KEYS', '1:" . esc_html( $suggested ) . "' );\n"
				. "define( 'GFB_ACTIVE_KEY_ID', '1' );"
				. '</pre>';
			echo '<p><em>' . esc_html__( 'Der vorgeschlagene Schlüssel wurde frisch erzeugt. Speichere ihn an einem sicheren Ort. Geht der Schlüssel verloren, sind verschlüsselte Daten unwiederbringlich verloren.', 'gutenberg-formbuilder' ) . '</em></p>';
		}

		// 1b) Lizenz / Updates – Token fuer den Auto-Update-Bezug.
		self::render_license_section();

		// 2) ClamAV
		$clamav_badge = ( 'disabled' === $clamav_set['mode'] )
			? self::summary_badge( 'neutral', __( 'Deaktiviert', 'gutenberg-formbuilder' ) )
			: self::summary_badge( 'on', __( 'Aktiv', 'gutenberg-formbuilder' ) );
		echo '</details><details class="gfb-settings-card" id="gfb-clamav"><summary><h2>' . esc_html__( 'ClamAV (Virenscan beim Upload)', 'gutenberg-formbuilder' ) . '</h2>' . $clamav_badge . '</summary>';

		self::render_clamav_help_box( $clamav_set );

		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_clamav" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th>' . esc_html__( 'Modus', 'gutenberg-formbuilder' ) . '</th><td>';
		$mode_labels = array(
			'disabled' => array(
				'label' => __( 'Deaktiviert', 'gutenberg-formbuilder' ),
				'help'  => __( 'Es findet kein Virenscan statt. Nur wählen, wenn du Datei-Uploads gar nicht nutzt oder einen externen Scanner per Filter angebunden hast.', 'gutenberg-formbuilder' ),
			),
			'binary' => array(
				'label' => __( 'clamscan / clamdscan (Binary)', 'gutenberg-formbuilder' ),
				'help'  => __( 'Empfohlen, wenn ClamAV als ausführbares Programm vorhanden ist (Standard auf den meisten Linux-Servern und macOS via Homebrew). Schneller: clamdscan (läuft gegen den ClamAV-Daemon) statt clamscan (startet bei jedem Scan neu).', 'gutenberg-formbuilder' ),
			),
			'socket' => array(
				'label' => __( 'clamd-Unix-Socket', 'gutenberg-formbuilder' ),
				'help'  => __( 'Schnellste Variante: Plugin spricht direkt mit dem ClamAV-Daemon über einen Unix-Socket. Voraussetzung: clamd läuft und der Socket-Pfad ist für den Webserver-User lesbar.', 'gutenberg-formbuilder' ),
			),
		);
		foreach ( $mode_labels as $val => $info ) {
			$id = 'gfb_clamav_mode_' . $val;
			echo '<div style="margin-bottom:0.4rem">';
			echo '<label><input type="radio" name="mode" value="' . esc_attr( $val ) . '" id="' . esc_attr( $id ) . '" '
				. checked( $clamav_set['mode'], $val, false ) . ' /> <strong>' . esc_html( $info['label'] ) . '</strong></label>';
			echo '<div class="description" style="margin-left:1.6rem">' . esc_html( $info['help'] ) . '</div>';
			echo '</div>';
		}
		echo '</td></tr>';

		echo '<tr class="gfb-clamav-config-row"><th>' . esc_html__( 'Pfad zu clamscan/clamdscan', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="text" name="binary_path" value="' . esc_attr( $clamav_set['binary_path'] ) . '" class="regular-text" placeholder="/usr/bin/clamdscan" />'
			. '<p class="description">' . esc_html__( 'Vollständiger Pfad zur ausführbaren Datei (ermitteln mit «which clamdscan» oder «which clamscan»).', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr class="gfb-clamav-config-row"><th>' . esc_html__( 'clamd-Socket-Pfad', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="text" name="socket_path" value="' . esc_attr( $clamav_set['socket_path'] ) . '" class="regular-text" placeholder="/var/run/clamd.scan/clamd.sock" />'
			. '<p class="description">' . esc_html__( 'Pfad zur clamd.sock-Datei. Typisch: /var/run/clamd.scan/clamd.sock (RHEL/CentOS) oder /var/run/clamav/clamd.ctl (Debian/Ubuntu).', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr class="gfb-clamav-config-row"><th>' . esc_html__( 'Timeout (Sek.)', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="number" name="timeout_sec" value="' . esc_attr( (string) $clamav_set['timeout_sec'] ) . '" min="1" max="600" />'
			. '<p class="description">' . esc_html__( 'Maximale Wartezeit auf das Scan-Ergebnis. Standard: 30 Sekunden.', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr class="gfb-clamav-config-row"><th>' . esc_html__( 'Pflicht für Datei-Uploads', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<label><input type="checkbox" name="require_for_uploads" value="1" '
			. checked( $clamav_set['require_for_uploads'], true, false )
			. ' /> ' . esc_html__( 'Strikter Modus (NICHT Standard): Wenn der Scanner nicht erreichbar ist, werden ALLE Datei-Uploads abgelehnt.', 'gutenberg-formbuilder' )
			. '</label>'
			. '<p class="description"><strong>' . esc_html__( 'Standardmässig deaktiviert.', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'Nur aktivieren, wenn ein Modus eingerichtet und mit «Verbindung testen» geprüft ist – sonst werden alle Datei-Uploads blockiert. Die Datei-Verschlüsselung greift unabhängig davon.', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '</tbody></table>';

		// Bedingte Sichtbarkeit der Konfigurationsfelder: Bei Modus "Deaktiviert"
		// blendet das Skript die mit .gfb-clamav-config-row markierten Zeilen aus.
		// Das Markup bleibt standardmässig sichtbar (kein hidden im PHP), damit ein
		// Betreiber ohne JavaScript nicht ausgesperrt wird; die Felder bleiben immer
		// im DOM, sodass das Speichern der Werte unverändert funktioniert.
		echo "<script>(function(){var rows=document.querySelectorAll('.gfb-clamav-config-row');var radios=document.querySelectorAll('input[name=\"mode\"]');function sync(){var mode='disabled';for(var i=0;i<radios.length;i++){if(radios[i].checked){mode=radios[i].value;break;}}var hide=(mode==='disabled');for(var j=0;j<rows.length;j++){if(hide){rows[j].setAttribute('hidden','hidden');}else{rows[j].removeAttribute('hidden');}}}for(var k=0;k<radios.length;k++){radios[k].addEventListener('change',sync);}sync();})();</script>";
		submit_button( __( 'ClamAV-Einstellungen speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';

		echo '<form method="post" style="margin-top:0.5rem">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="eicar_test" />';
		submit_button( __( 'Verbindung testen (EICAR-Test)', 'gutenberg-formbuilder' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Schickt das offizielle EICAR-Testmuster (eine harmlose Test-Datei) an deine ClamAV-Konfiguration. Erwartet: «infected» – dann funktioniert der Scanner.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</form>';

		// 2b) Spam-Schutz (CAPTCHA) – Friendly Captcha
		self::render_captcha_section();

		// 2c) Bestätigungsmail (Autoresponder + Double-Opt-in): Zustellbarkeit + Datenschutz
		self::render_receipt_mail_section();

		// 3) Capability-Matrix
		echo '</details><details class="gfb-settings-card" id="gfb-berechtigungen"><summary><h2>' . esc_html__( 'Berechtigungen', 'gutenberg-formbuilder' ) . '</h2></summary>';
		echo '<p>' . esc_html__( 'Legt fest, welche WordPress-Rolle welche Aktion mit den Formular-Einträgen ausführen darf.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_caps" />';
		global $wp_roles;
		echo '<table class="widefat striped gfb-caps-table"><thead><tr><th class="gfb-caps-role-col">' . esc_html__( 'Rolle', 'gutenberg-formbuilder' ) . '</th>';
		foreach ( GFB_Capabilities::all_caps() as $cap ) {
			$meta = GFB_Capabilities::cap_meta( $cap );
			echo '<th>'
				. '<span class="gfb-caps-title">' . esc_html( $meta['title'] ) . '</span>'
				. '<span class="gfb-caps-desc">' . esc_html( $meta['description'] ) . '</span>'
				. '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			echo '<tr><td class="gfb-caps-role-cell"><strong>' . esc_html( $role_data['name'] ) . '</strong></td>';
			foreach ( GFB_Capabilities::all_caps() as $cap ) {
				$has = ! empty( $role_data['capabilities'][ $cap ] );
				echo '<td><input type="checkbox" name="caps[' . esc_attr( $role_slug ) . '][' . esc_attr( $cap ) . ']" value="1" '
					. checked( $has, true, false ) . ' /></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Berechtigungen speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';

		// Die frühere Audit-Log-Karte (nur ein Verweis) ist entfernt –
		// das Audit-Log hat einen eigenen Submenü-Punkt.

		// 4b) Storage-Erreichbarkeits-Test (Webserver darf private Dateien nicht ausliefern)
		$reach_for_badge = get_transient( 'gfb_storage_reach' );
		if ( is_array( $reach_for_badge ) ) {
			$reach_badge = ! empty( $reach_for_badge['ok'] )
				? self::summary_badge( 'on', __( 'Test bestanden', 'gutenberg-formbuilder' ) )
				: self::summary_badge( 'off', __( 'Handlungsbedarf', 'gutenberg-formbuilder' ) );
		} else {
			$reach_badge = self::summary_badge( 'neutral', __( 'Nicht getestet', 'gutenberg-formbuilder' ) );
		}
		echo '</details><details class="gfb-settings-card" id="gfb-privatsphaere"><summary><h2>' . esc_html__( 'Sind hochgeladene Dateien wirklich privat?', 'gutenberg-formbuilder' ) . '</h2>' . $reach_badge . '</summary>';
		echo '<p>' . esc_html__( 'Dieser Selbsttest prüft, ob jemand mit einem direkten Link an die Dateien aus dem geschützten Speicher kommt. Erwartet wird: NEIN – der Webserver muss die Anfrage ablehnen.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<details style="margin:0 0 0.8rem"><summary style="cursor:pointer">'
			. esc_html__( 'Wie läuft der Test ab?', 'gutenberg-formbuilder' ) . '</summary>'
			. '<ol style="margin:0.4rem 0 0.6rem 1.4rem">'
			. '<li>' . esc_html__( 'Das Plugin legt kurz eine harmlose Test-Datei im privaten Speicher an.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Es ruft die zugehörige URL über das Internet ab – so, wie es ein Fremder tun könnte.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Antwortet der Webserver mit "verboten" (403) oder "nicht gefunden" (404), ist alles in Ordnung.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Die Test-Datei wird sofort wieder gelöscht.', 'gutenberg-formbuilder' ) . '</li>'
			. '</ol></details>';

		$cached_reach = get_transient( 'gfb_storage_reach' );
		if ( is_array( $cached_reach ) ) {
			$is_ok    = ! empty( $cached_reach['ok'] );
			$bg       = $is_ok ? '#edfaef' : '#fcebec';
			$border   = $is_ok ? '#1a7f37' : '#a32016';
			$icon     = $is_ok ? '✓' : '✗';
			$headline = $is_ok ? __( 'Test bestanden – deine Dateien sind privat.', 'gutenberg-formbuilder' )
				: __( 'Achtung – Handlungsbedarf!', 'gutenberg-formbuilder' );

			echo '<div style="background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $border ) . ';padding:0.8rem 1rem;margin:0.6rem 0;border-radius:3px">';
			echo '<p style="margin:0;font-size:1.05em"><strong>' . esc_html( $icon . ' ' . $headline ) . '</strong></p>';
			echo '<p style="margin:0.4rem 0 0">' . esc_html( $cached_reach['details'] ) . '</p>';
			if ( ! empty( $cached_reach['url'] ) ) {
				echo '<p style="margin:0.6rem 0 0;font-size:0.92em;color:#50575e">';
				echo '<strong>' . esc_html__( 'Getestete URL:', 'gutenberg-formbuilder' ) . '</strong> ';
				echo '<code style="word-break:break-all">' . esc_html( $cached_reach['url'] ) . '</code>';
				echo ' – ' . esc_html( sprintf( __( 'Antwort vom Webserver: HTTP %d', 'gutenberg-formbuilder' ), (int) $cached_reach['http_code'] ) );
				echo '<br><em>' . esc_html__( '(Diese Test-Datei existiert nicht mehr – sie wurde nach dem Test wieder gelöscht.)', 'gutenberg-formbuilder' ) . '</em>';
				echo '</p>';
			}
			if ( ! $is_ok ) {
				echo '<p style="margin:0.6rem 0 0"><strong>' . esc_html__( 'Was tun?', 'gutenberg-formbuilder' ) . '</strong> '
					. esc_html__( 'Webserver-Konfiguration anpassen, sodass das Verzeichnis "wp-content/.gfb-private/" nicht aus dem Internet erreichbar ist. Konkrete Snippets für Apache und Nginx stehen in der Datei INSTALL.md des Plugins. Falls du keinen Server-Zugriff hast: dem Hoster diese Meldung weiterleiten.', 'gutenberg-formbuilder' ) . '</p>';
			}
			echo '</div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Noch nicht getestet.', 'gutenberg-formbuilder' ) . '</p>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="storage_reach_test" />';
		submit_button( __( 'Jetzt prüfen', 'gutenberg-formbuilder' ), 'secondary' );
		echo '<p class="description">' . esc_html__( 'Kann jederzeit wiederholt werden, z. B. nach Änderungen am Webserver.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</form>';

		// Formular / Frontend
		$webkit_fallback = GFB_Plugin::is_webkit_datetime_fallback_enabled();
		$webkit_badge    = $webkit_fallback
			? self::summary_badge( 'on', __( 'Fallback aktiv', 'gutenberg-formbuilder' ) )
			: self::summary_badge( 'neutral', __( 'Native Picker', 'gutenberg-formbuilder' ) );
		echo '</details><details class="gfb-settings-card" id="gfb-frontend"><summary><h2>' . esc_html__( 'Formular (Frontend)', 'gutenberg-formbuilder' ) . '</h2>' . $webkit_badge . '</summary>';
		echo '<p>' . esc_html__( 'Steuert das Verhalten von Datums-, Zeit- und Datum/Uhrzeit-Feldern im Browser.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_webkit_datetime" />';
		echo '<p><label><input type="checkbox" name="webkit_datetime_fallback" value="1" ' . checked( $webkit_fallback, true, false ) . ' /> ';
		echo esc_html__( 'Safari/WebKit: Datums- und Zeitfelder als Text mit Musterprüfung (statt nativem Picker)', 'gutenberg-formbuilder' );
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'Aktiviert (Standard): Safari erhält Texteingaben mit Musterprüfung nach den WordPress-Datumsformaten – stabiler bei 12-Stunden-Format und Validierung. Deaktiviert: native Browser-Picker bleiben erhalten.', 'gutenberg-formbuilder' ) . '</p>';
		submit_button( __( 'Speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';

		// 5) Key-Rotation
		echo '</details><details class="gfb-settings-card" id="gfb-rotation"><summary><h2>' . esc_html__( 'Key-Rotation', 'gutenberg-formbuilder' ) . '</h2></summary>';
		echo '<p>' . esc_html__( 'Nach dem Hinzufügen eines neuen Master-Keys in wp-config.php verpackt dieser Lauf die Schlüssel bestehender Dateien neu. Datei-Inhalte werden dabei nicht entschlüsselt.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="rewrap_now" />';
		submit_button( __( 'Re-Wrap-Lauf jetzt starten (max. 200 Dateien)', 'gutenberg-formbuilder' ), 'secondary' );
		echo '</form>';

		echo '</details>'; // letzte .gfb-settings-card

		// Aufklapp-Zustand der Karten im Browser merken (localStorage) und
		// beim Anker-Aufruf (#gfb-…) die Ziel-Karte öffnen. Ohne JavaScript
		// sind alle Karten zuklappbar, starten aber zugeklappt.
		echo "<script>(function(){var KEY='gfbSettingsOpen';var cards=document.querySelectorAll('details.gfb-settings-card');var saved=[];try{saved=JSON.parse(localStorage.getItem(KEY)||'[]');}catch(e){}for(var i=0;i<cards.length;i++){if(saved.indexOf(cards[i].id)!==-1){cards[i].open=true;}cards[i].addEventListener('toggle',function(){var open=[];for(var j=0;j<cards.length;j++){if(cards[j].open){open.push(cards[j].id);}}try{localStorage.setItem(KEY,JSON.stringify(open));}catch(e){}});}var h=window.location.hash;if(h&&h.indexOf('#gfb-')===0){var t=document.getElementById(h.substring(1));if(t){t.open=true;}}})();</script>";
		echo '</div>'; // .wrap
	}

	/**
	 * Rendert den Abschnitt «Lizenz / Updates».
	 *
	 * Erlaubt das Hinterlegen des Update-Lizenz-Tokens im Backend (Option
	 * gfb_update_token), als kundenfreundliche Alternative zur wp-config-
	 * Konstante GFB_UPDATE_TOKEN. Die Konstante hat Vorrang: Ist sie gesetzt,
	 * wird das Feld deaktiviert und ein POST aendert den DB-Wert nicht.
	 *
	 * Das Token wird als password-Feld behandelt, nie im Klartext gerendert
	 * (gleiches Muster wie der Captcha-API-Key).
	 *
	 * @return void
	 */
	private static function render_license_section() {
		$by_const  = defined( 'GFB_UPDATE_TOKEN' );
		$has_token = '' !== (string) get_option( 'gfb_update_token', '' );

		// Status-Box aus dem laufenden Update-Client ableiten.
		$client = function_exists( 'gfb_update_client' ) ? gfb_update_client() : null;
		$status = $client ? $client->status( false ) : array(
			'code'           => $by_const ? 'const_set' : ( $has_token ? 'valid' : 'no_token' ),
			'version'        => defined( 'GFB_PLUGIN_VERSION' ) ? GFB_PLUGIN_VERSION : '',
			'remote_version' => '',
			'by_const'       => $by_const,
		);

		$code = isset( $status['code'] ) ? $status['code'] : 'no_token';
		switch ( $code ) {
			case 'valid':
				$license_badge = self::summary_badge( 'on', __( 'Token gültig', 'gutenberg-formbuilder' ) );
				break;
			case 'const_set':
				$license_badge = self::summary_badge( 'on', __( 'Per wp-config', 'gutenberg-formbuilder' ) );
				break;
			case 'forbidden':
				$license_badge = self::summary_badge( 'off', __( 'Token ungültig', 'gutenberg-formbuilder' ) );
				break;
			case 'unreachable':
				$license_badge = self::summary_badge( 'warn', __( 'Server nicht erreichbar', 'gutenberg-formbuilder' ) );
				break;
			default:
				$license_badge = self::summary_badge( 'neutral', __( 'Kein Token', 'gutenberg-formbuilder' ) );
		}
		echo '</details><details class="gfb-settings-card" id="gfb-lizenz"><summary><h2>' . esc_html__( 'Lizenz / Updates', 'gutenberg-formbuilder' ) . '</h2>' . $license_badge . '</summary>';
		echo '<p>' . esc_html__( 'Mit einem gültigen Lizenz-Token bezieht das Plugin automatische Updates von plugins.blitzdonner.ch. Ohne Token läuft es normal weiter, nur ohne Update-Angebote.', 'gutenberg-formbuilder' ) . '</p>';

		// Status-Box oberhalb des Felds.
		self::render_license_status_box( $status );

		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_license" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gfb_update_token">' . esc_html__( 'Lizenz-Token', 'gutenberg-formbuilder' ) . '</label></th><td>';

		if ( $by_const ) {
			// Konstante gesetzt: Feld deaktiviert, Eingaben werden serverseitig ignoriert.
			echo '<input type="password" id="gfb_update_token" name="gfb_update_token" value="" class="regular-text" autocomplete="off" spellcheck="false" disabled="disabled" placeholder="' . esc_attr__( 'per wp-config gesetzt', 'gutenberg-formbuilder' ) . '" />';
			echo '<p class="description"><strong>' . esc_html__( 'Das Token ist per wp-config (Konstante GFB_UPDATE_TOKEN) gesetzt und hat Vorrang.', 'gutenberg-formbuilder' ) . '</strong> '
				. esc_html__( 'Eingaben in diesem Feld werden ignoriert. Um das Token zu ändern, passe die Konstante in der wp-config.php an.', 'gutenberg-formbuilder' ) . '</p>';
		} else {
			// Token nie im Klartext rendern; Platzhalter signalisiert «gespeichert».
			$ph = $has_token
				? esc_attr__( '•••••••••• (gespeichert – leer lassen, um beizubehalten)', 'gutenberg-formbuilder' )
				: '';
			echo '<input type="password" id="gfb_update_token" name="gfb_update_token" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="' . $ph . '" />';
			echo '<p class="description">' . esc_html__( 'Bleibt serverseitig und wird nie im Klartext angezeigt.', 'gutenberg-formbuilder' );
			if ( $has_token ) {
				echo ' ' . esc_html__( 'Aktuell gesetzt – zum Ändern neuen Wert eintragen, sonst leer lassen.', 'gutenberg-formbuilder' );
			}
			echo ' ' . esc_html__( 'Empfehlung: das Token stattdessen als Konstante GFB_UPDATE_TOKEN in der wp-config.php setzen – das ist robuster und hat Vorrang.', 'gutenberg-formbuilder' );
			echo '</p>';
		}

		echo '</td></tr>';
		echo '</tbody></table>';

		if ( ! $by_const ) {
			submit_button( __( 'Lizenz-Token speichern', 'gutenberg-formbuilder' ) );
		}
		echo '</form>';

		// «Token testen» – Live-Check am Transient-Takt vorbei.
		echo '<form method="post" style="margin-top:0.5rem">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="test_license" />';
		submit_button( __( 'Token testen', 'gutenberg-formbuilder' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Prüft das hinterlegte Token sofort gegen den Update-Server, unabhängig vom üblichen Update-Takt.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Rendert die farbige Status-Box für die Lizenz-Sektion.
	 *
	 * Zustaende: const_set (per wp-config), no_token (kein Token),
	 * valid (gueltig, mit installierter Version), forbidden (abgelaufen/ungueltig),
	 * unreachable (Server nicht erreichbar).
	 *
	 * @param array $status Ergebnis von BD_Update_Client::status().
	 * @return void
	 */
	private static function render_license_status_box( $status ) {
		$code    = isset( $status['code'] ) ? $status['code'] : 'no_token';
		$version = isset( $status['version'] ) ? (string) $status['version'] : '';
		$remote  = isset( $status['remote_version'] ) ? (string) $status['remote_version'] : '';

		// Farbschema je Zustand (gleiche Palette wie der Storage-Reach-Block).
		switch ( $code ) {
			case 'valid':
				$bg = '#edfaef'; $border = '#1a7f37'; $icon = '✓';
				$head = __( 'Token gültig – automatische Updates aktiv.', 'gutenberg-formbuilder' );
				$body = '' !== $remote && version_compare( $remote, $version, '>' )
					? sprintf( __( 'Installiert: Version %1$s. Auf dem Server verfügbar: Version %2$s.', 'gutenberg-formbuilder' ), $version, $remote )
					: sprintf( __( 'Installierte Version: %s. Aktuell – kein Update verfügbar.', 'gutenberg-formbuilder' ), $version );
				break;
			case 'const_set':
				$bg = '#eef4fb'; $border = '#2271b1'; $icon = 'ℹ';
				$head = __( 'Token per wp-config gesetzt (Vorrang).', 'gutenberg-formbuilder' );
				$body = __( 'Das Token stammt aus der Konstante GFB_UPDATE_TOKEN. Eingaben im Backend werden ignoriert.', 'gutenberg-formbuilder' );
				break;
			case 'forbidden':
				$bg = '#fcebec'; $border = '#a32016'; $icon = '✗';
				$head = __( 'Token abgelaufen oder ungültig – keine Updates.', 'gutenberg-formbuilder' );
				$body = __( 'Der Server hat das Token abgelehnt. Das Plugin funktioniert weiterhin normal, es werden nur keine Updates angeboten.', 'gutenberg-formbuilder' );
				break;
			case 'unreachable':
				$bg = '#fcf9e8'; $border = '#dba617'; $icon = '!';
				$head = __( 'Update-Server nicht erreichbar.', 'gutenberg-formbuilder' );
				$body = __( 'Der Server konnte gerade nicht erreicht werden. Das ist meist vorübergehend – später erneut «Token testen».', 'gutenberg-formbuilder' );
				break;
			case 'no_token':
			default:
				$bg = '#f6f7f7'; $border = '#646970'; $icon = 'ℹ';
				$head = __( 'Kein Token hinterlegt.', 'gutenberg-formbuilder' );
				$body = __( 'Trage unten ein Lizenz-Token ein, um automatische Updates zu aktivieren. Ohne Token läuft das Plugin normal weiter.', 'gutenberg-formbuilder' );
				break;
		}

		echo '<div style="background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $border ) . ';padding:0.8rem 1rem;margin:0.6rem 0;border-radius:3px">';
		echo '<p style="margin:0;font-size:1.05em"><strong>' . esc_html( $icon . ' ' . $head ) . '</strong></p>';
		echo '<p style="margin:0.4rem 0 0">' . esc_html( $body ) . '</p>';
		echo '</div>';
	}

	/**
	 * Kurze Klartext-Statusmeldung (für die Redirect-Notiz nach «Token testen»).
	 *
	 * @param array $status Ergebnis von BD_Update_Client::status().
	 * @return string
	 */
	private static function license_status_message( $status ) {
		$code = isset( $status['code'] ) ? $status['code'] : 'no_token';
		switch ( $code ) {
			case 'valid':
				return __( 'Token testen: Token gültig – automatische Updates aktiv.', 'gutenberg-formbuilder' );
			case 'const_set':
				return __( 'Token testen: Token per wp-config gesetzt (Vorrang).', 'gutenberg-formbuilder' );
			case 'forbidden':
				return __( 'Token testen: Token abgelaufen oder ungültig – keine Updates.', 'gutenberg-formbuilder' );
			case 'unreachable':
				return __( 'Token testen: Update-Server nicht erreichbar – bitte später erneut versuchen.', 'gutenberg-formbuilder' );
			case 'no_token':
			default:
				return __( 'Token testen: Kein Token hinterlegt.', 'gutenberg-formbuilder' );
		}
	}

	/**
	 * Rendert den Abschnitt «Spam-Schutz (CAPTCHA) – Friendly Captcha»
	 * (zwischen ClamAV und Berechtigungen). Ein Anbieter, keine Anbieterwahl.
	 * Gibt nur den Site-Key/API-Key-Eingabe aus; der API-Key wird als
	 * password-Feld behandelt und beim Rendern nicht echo't (nur «gesetzt»-Status).
	 *
	 * @return void
	 */
	private static function render_captcha_section() {
		$s          = GFB_Captcha::get_settings();
		$incomplete = GFB_Captcha::is_enabled_but_incomplete();

		if ( ! empty( $s['enabled'] ) ) {
			$captcha_badge = $incomplete
				? self::summary_badge( 'warn', __( 'Unvollständig konfiguriert', 'gutenberg-formbuilder' ) )
				: self::summary_badge( 'on', __( 'Aktiv', 'gutenberg-formbuilder' ) );
		} else {
			$captcha_badge = self::summary_badge( 'neutral', __( 'Deaktiviert', 'gutenberg-formbuilder' ) );
		}
		echo '</details><details class="gfb-settings-card" id="gfb-captcha"><summary><h2>' . esc_html__( 'Spam-Schutz (CAPTCHA) – Friendly Captcha', 'gutenberg-formbuilder' ) . '</h2>' . $captcha_badge . '</summary>';

		// A5: nicht-blockierende Warnung bei aktiv + unvollstaendig.
		if ( $incomplete ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'CAPTCHA ist aktiv, aber unvollständig konfiguriert – es wird vorerst kein Widget angezeigt. Bitte Site-Key und API-Key eintragen.', 'gutenberg-formbuilder' )
				. '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_captcha" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		// CAPTCHA aktiv (global) – Radio Nein/Ja.
		echo '<tr><th scope="row">' . esc_html__( 'CAPTCHA aktiv (global)', 'gutenberg-formbuilder' ) . '</th><td>';
		echo '<fieldset>';
		echo '<label style="margin-right:1.5rem;"><input type="radio" name="captcha_enabled" value="0" ' . checked( $s['enabled'], false, false ) . ' /> ' . esc_html__( 'Nein', 'gutenberg-formbuilder' ) . '</label>';
		echo '<label><input type="radio" name="captcha_enabled" value="1" ' . checked( $s['enabled'], true, false ) . ' /> ' . esc_html__( 'Ja', 'gutenberg-formbuilder' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Steuert, ob auf Formularen ein CAPTCHA erscheinen kann. Pro Formular zusätzlich im Block überschreibbar.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</fieldset></td></tr>';

		// Anbieter (nur Beschriftung, kein Auswahl-Steuerelement).
		echo '<tr><th scope="row">' . esc_html__( 'Anbieter', 'gutenberg-formbuilder' ) . '</th><td>';
		echo '<strong>' . esc_html__( 'Friendly Captcha', 'gutenberg-formbuilder' ) . '</strong> '
			. '<span class="description">' . esc_html__( '(EU, Proof-of-Work, kein Drittlandtransfer)', 'gutenberg-formbuilder' ) . '</span>';
		echo '</td></tr>';

		// Site-Key.
		echo '<tr><th scope="row"><label for="gfb_captcha_site_key">' . esc_html__( 'Site-Key', 'gutenberg-formbuilder' ) . '</label></th><td>';
		echo '<input type="text" id="gfb_captcha_site_key" name="captcha_site_key" value="' . esc_attr( $s['site_key'] ) . '" class="regular-text" autocomplete="off" spellcheck="false" />';
		echo '</td></tr>';

		// API-Key (Secret) – wird nie im Klartext zurueckgegeben; Platzhalter zeigt nur den Status.
		echo '<tr><th scope="row"><label for="gfb_captcha_api_key">' . esc_html__( 'API-Key (Secret)', 'gutenberg-formbuilder' ) . '</label></th><td>';
		$api_set     = '' !== $s['api_key'];
		$api_ph      = $api_set
			? esc_attr__( '•••••••••• (gespeichert – leer lassen, um beizubehalten)', 'gutenberg-formbuilder' )
			: '';
		// Feld leer rendern (Secret nie ausgeben). Leeres Absenden behaelt den alten Wert über update_settings nicht automatisch –
		// daher: wenn gesetzt und leer gesendet, übernimmt der POST-Handler den Wert aus dem Feld; um versehentliches Loeschen zu vermeiden,
		// füllt der Nutzer das Feld nur bei Aenderung. Hinweis im description-Text.
		echo '<input type="password" id="gfb_captcha_api_key" name="captcha_api_key" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="' . $api_ph . '" />';
		echo '<p class="description">' . esc_html__( 'Bleibt serverseitig und wird nie im Klartext angezeigt.', 'gutenberg-formbuilder' );
		if ( $api_set ) {
			echo ' ' . esc_html__( 'Aktuell gesetzt – zum Ändern neuen Wert eintragen, sonst leer lassen.', 'gutenberg-formbuilder' );
		}
		echo '</p></td></tr>';

		echo '</tbody></table>';

		// Anleitung: Schlüssel beim Anbieter erstellen (eingeklappt, gleiches Muster
		// wie der Datenschutz-Block; Schritte verifiziert gegen die offizielle Doku).
		echo '<details><summary>' . esc_html__( 'Wie erhalte ich Site-Key und API-Key?', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<ol style="margin:0.5rem 0 0.4rem 1.4rem">';
		echo '<li>' . sprintf(
			/* translators: %s: Link auf friendlycaptcha.com */
			esc_html__( 'Konto bei %s erstellen (Gratis-Plan verfügbar) und anmelden.', 'gutenberg-formbuilder' ),
			'<a href="https://friendlycaptcha.com" target="_blank" rel="noopener noreferrer">friendlycaptcha.com</a>'
		) . '</li>';
		echo '<li>' . sprintf(
			/* translators: %s: Link auf die Applications-Seite im Dashboard */
			esc_html__( 'Im Dashboard unter %s mit «+ New Application» eine Anwendung anlegen. Der Site-Key erscheint unter dem Anwendungsnamen und beginnt immer mit «FC».', 'gutenberg-formbuilder' ),
			'<a href="https://app.friendlycaptcha.eu/dashboard/accounts/-/apps" target="_blank" rel="noopener noreferrer">Applications</a>'
		) . '</li>';
		echo '<li>' . sprintf(
			/* translators: %s: Link auf die API-Keys-Seite im Dashboard */
			esc_html__( 'Unter %s einen neuen API-Key erstellen – das ist das Secret für die serverseitige Prüfung.', 'gutenberg-formbuilder' ),
			'<a href="https://app.friendlycaptcha.eu/dashboard/accounts/-/keys" target="_blank" rel="noopener noreferrer">API Keys</a>'
		) . '</li>';
		echo '<li>' . esc_html__( 'Beide Werte hier eintragen und speichern.', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ol>';
		echo '<p class="description" style="margin:0 0 0.2rem">' . esc_html__( 'Der API-Key wird nur einmal angezeigt – direkt nach dem Erstellen kopieren.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</details>';

		// Datenschutz-Hinweisblock (schlank, informativ – nicht als Warnblock).
		self::render_captcha_privacy_box();

		// Erzwingungsmodus.
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Erzwingung', 'gutenberg-formbuilder' ) . '</th><td>';
		echo '<fieldset>';
		echo '<label style="display:block;margin-bottom:.4rem;"><input type="radio" name="captcha_mode" value="soft" ' . checked( $s['mode'], 'soft', false ) . ' /> <strong>' . esc_html__( 'Mit Ausnahme bei Serverausfall', 'gutenberg-formbuilder' ) . '</strong></label>';
		echo '<p class="description" style="margin:0 0 .6rem 1.8rem;">' . esc_html__( 'Das Formular verlangt ein bestandenes Captcha. Nur wenn Friendly Captcha einmal nicht erreichbar ist, lässt sich das Formular trotzdem absenden – damit eine seltene Störung beim Dienst Ihre Formulare nicht blockiert.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<label style="display:block;margin-bottom:.4rem;"><input type="radio" name="captcha_mode" value="strict" ' . checked( $s['mode'], 'strict', false ) . ' /> <strong>' . esc_html__( 'Streng', 'gutenberg-formbuilder' ) . '</strong></label>';
		echo '<p class="description" style="margin:0 0 .6rem 1.8rem;">' . esc_html__( 'Ohne bestandenes Captcha wird nicht abgesendet – auch dann nicht, wenn Friendly Captcha gerade gestört ist.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</fieldset></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'CAPTCHA-Einstellungen speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';
	}

	/**
	 * Informativer Datenschutz-Hinweis (E-neu.3, erweitert E4) plus zwei
	 * kopierbare Textbausteine: den vollstaendigen Datenschutz-Baustein
	 * (E-neu.1) und die LIA-Vorlage (E-neu.2).
	 *
	 * UX-Ziel: Der Abschnitt bleibt uebersichtlich. Der gesamte Bereich steckt
	 * in einem uebergeordneten, standardmaessig eingeklappten Akkordeon (natives
	 * <details>); sichtbar bleibt nur die Zusammenfassungszeile «Datenschutz-
	 * Hinweise und Textbausteine anzeigen». Die beiden langen Rechtstexte
	 * stecken zusaetzlich in je einem verschachtelten, ebenfalls eingeklappten
	 * <details>. Die sichtbaren Labels sind laienverstaendlich (Klartext statt
	 * Fachjargon, Fachbegriffe mit Klammer-Erklaerung).
	 *
	 * Der Block ist informativ dargestellt, kein nicht-wegklickbarer Warnblock
	 * (EN6). Beide Bausteine sind per Copy-Button in die Zwischenablage
	 * kopierbar (EN1, EN4); ein Textfeld bleibt als Fallback erhalten.
	 *
	 * @return void
	 */
	private static function render_captcha_privacy_box() {
		// Uebergeordnetes, standardmaessig eingeklapptes Akkordeon (natives
		// <details>, gleiches Muster wie der ClamAV-Hilfe-Block). Sichtbar bleibt
		// nur die kurze Zusammenfassungszeile; der Hinweisblock und beide
		// Textbausteine erscheinen erst beim Aufklappen. Das spart Platz auf der
		// Einstellungsseite.
		echo '<details class="gfb-captcha-privacy" style="margin:.5rem 0 1rem;max-width:46rem;border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;">';
		echo '<summary style="cursor:pointer;padding:.6rem .9rem;font-weight:600;">'
			. esc_html__( 'Datenschutz-Hinweise und Textbausteine anzeigen', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="padding:0 1.2rem 1rem;">';
		echo '<p style="margin-top:.6rem;"><strong>ℹ ' . esc_html__( 'Was Sie zum Datenschutz wissen sollten', 'gutenberg-formbuilder' ) . '</strong></p>';

		// Sechs Punkte (a)–(f) aus E-neu.3, laienverstaendlich formuliert.
		echo '<ul style="margin:.2rem 0 .6rem 1.2rem;list-style:disc;">';
		echo '<li>' . esc_html__( 'Die IP-Adresse wird nur in der EU verarbeitet, nichts geht ins Ausland.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Kein Tracking: keine Cookies, kein Wiedererkennen des Geräts. Stattdessen löst Ihr Browser im Hintergrund eine kleine Rechenaufgabe («Proof-of-Work»). Das bremst Bots, ohne Sie zu beobachten.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Pflicht: Sie müssen mit Friendly Captcha einen Auftragsverarbeitungsvertrag (AVV) abschliessen. Diesen erhalten Sie direkt bei Friendly Captcha. Ohne AVV ist der Einsatz nicht rechtmässig.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Im Normalbetrieb müssen Besucher nicht zustimmen (berechtigtes Interesse). Dafür muss Ihre Datenschutzerklärung den vollständigen Textbaustein unten enthalten.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Empfehlung: Halten Sie den internen Vermerk zur Rechtsgrundlage (Interessenabwägung) ausgefüllt bereit – als Nachweis bei einer Prüfung.', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ul>';

		// Baustein 1: vollstaendiger Datenschutz-Textbaustein (E-neu.1).
		$privacy = GFB_Captcha::privacy_text_snippet();
		self::render_captcha_snippet_block(
			'gfb-captcha-snippet',
			__( 'Textbaustein für Ihre Datenschutzerklärung (öffentlich) anzeigen', 'gutenberg-formbuilder' ),
			__( 'Diesen Text kopieren Sie in Ihre öffentliche Datenschutzerklärung auf der Website.', 'gutenberg-formbuilder' ),
			$privacy,
			14,
			__( 'Vor dem Veröffentlichen die beiden Platzhalter in eckigen Klammern ersetzen: «[Firmenbezeichnung und Adresse]» aus Ihrem Auftragsverarbeitungsvertrag, «[Konkrete Speicherdauer aus der Dokumentation von Friendly Captcha]» aus der Anbieter-Dokumentation.', 'gutenberg-formbuilder' )
		);

		// Baustein 2: LIA-Vorlage (E-neu.2).
		$lia = GFB_Captcha::lia_text_snippet();
		self::render_captcha_snippet_block(
			'gfb-captcha-lia',
			__( 'Internes Dokument zur Rechtsgrundlage (Interessenabwägung) anzeigen', 'gutenberg-formbuilder' ),
			__( 'Kurzes internes Dokument als Nachweis, warum der Spam-Schutz erlaubt ist. Bleibt bei Ihnen, nicht öffentlich.', 'gutenberg-formbuilder' ),
			$lia,
			16
		);

		// Ein gemeinsamer, abhaengigkeitsfreier Toggle/Copy-Handler fuer beide
		// Bausteine. <details> uebernimmt das Auf-/Zuklappen nativ; das Skript
		// kuemmert sich nur um den Copy-Button. CSP der Plugin-Seiten erlaubt
		// 'unsafe-inline'.
		echo "<script>(function(){var bs=document.querySelectorAll('.gfb-captcha-copy');for(var i=0;i<bs.length;i++){(function(b){b.addEventListener('click',function(){var ta=document.getElementById(b.getAttribute('data-target'));if(!ta)return;ta.removeAttribute('hidden');ta.focus();ta.select();var done=function(){var o=b.textContent;b.textContent=b.getAttribute('data-done');setTimeout(function(){b.textContent=o;},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(ta.value).then(done,function(){try{document.execCommand('copy');done();}catch(e){}});}else{try{document.execCommand('copy');done();}catch(e){}}});})(bs[i]);}})();</script>";
		echo '</div></details>';
	}

	/**
	 * Rendert einen aufklappbaren, kopierbaren Textbaustein-Block. Sichtbar ist
	 * nur die kurze Zusammenfassungszeile (<summary>); der lange Rechtstext und
	 * der Copy-Button erscheinen erst beim Aufklappen. Standardzustand:
	 * eingeklappt (kein `open`-Attribut).
	 *
	 * @param string $id      Eindeutige DOM-Id-Basis für Textarea/Target.
	 * @param string $summary Kurze, laienverständliche Zusammenfassungszeile.
	 * @param string $hint    Ein-Satz-Erklärung unter der Zeile.
	 * @param string $text    Reiner Baustein-Text (wird escaped ausgegeben).
	 * @param int    $rows    Höhe des Textfelds in Zeilen.
	 * @param string $note    Optionaler Ausfüll-Hinweis über dem Textfeld
	 *                        (steht bewusst ausserhalb des kopierbaren Texts).
	 * @return void
	 */
	private static function render_captcha_snippet_block( $id, $summary, $hint, $text, $rows, $note = '' ) {
		echo '<details style="margin:.5rem 0 0;border:1px solid #dcdcde;border-radius:5px;background:#fff;">';
		echo '<summary style="cursor:pointer;padding:.55rem .75rem;font-weight:600;">' . esc_html( $summary ) . '</summary>';
		echo '<div style="padding:0 .75rem .75rem;">';
		echo '<p class="description" style="margin:.3rem 0 .5rem;">' . esc_html( $hint ) . '</p>';
		echo '<p style="margin:0 0 .4rem;"><button type="button" class="button button-secondary gfb-captcha-copy" data-target="' . esc_attr( $id ) . '" data-done="' . esc_attr__( 'Kopiert!', 'gutenberg-formbuilder' ) . '">'
			. esc_html__( 'In die Zwischenablage kopieren', 'gutenberg-formbuilder' ) . '</button></p>';
		if ( '' !== $note ) {
			echo '<p class="description" style="margin:0 0 .4rem;padding:.4rem .6rem;background:#fcf9e8;border-left:3px solid #dba617;border-radius:3px;"><strong>'
				. esc_html__( 'Vor dem Einfügen ausfüllen:', 'gutenberg-formbuilder' ) . '</strong> ' . esc_html( $note ) . '</p>';
		}
		echo '<textarea id="' . esc_attr( $id ) . '" readonly rows="' . (int) $rows . '" class="large-text code" onclick="this.select();" style="width:100%;">' . esc_textarea( $text ) . '</textarea>';
		echo '<p class="description" style="margin:.2rem 0 0;"><em>' . esc_html__( '(unverbindliche Vorlage, keine Rechtsberatung)', 'gutenberg-formbuilder' ) . '</em></p>';
		echo '</div></details>';
	}

	/**
	 * Karte «Bestätigungsmail»: Betreiber-Hinweise zu Zustellbarkeit
	 * (SPF/DKIM/DMARC, Return-Path, Bounces, Freemail), Auftragsbearbeitung
	 * (AVV), Beweiswert des DOI-Klicks und kopierbarer Datenschutz-Baustein.
	 * Reine Informations-Karte ohne POST-Aktionen; die Konfiguration liegt am
	 * Formular-Block, die technischen Defaults an Filtern (gfb_receipt_*).
	 *
	 * @return void
	 */
	private static function render_receipt_mail_section() {
		echo '</details><details class="gfb-settings-card" id="gfb-bestaetigungsmail"><summary><h2>' . esc_html__( 'Bestätigungsmail an Absender/innen', 'gutenberg-formbuilder' ) . '</h2></summary>';

		echo '<p>' . esc_html__( 'Formulare können der ausfüllenden Person eine Eingangsbestätigung senden – sofort oder erst nach Klick auf einen Bestätigungslink (Double-Opt-in). Der Modus wird pro Formular am Block eingestellt (Bereich «Bestätigungsmail an Absender/in»).', 'gutenberg-formbuilder' ) . '</p>';

		echo '<p style="margin:0 0 .4rem;"><strong>' . esc_html__( 'Zuverlässige Zustellung ist Betreiber-Aufgabe', 'gutenberg-formbuilder' ) . '</strong></p>';
		echo '<ul style="margin:.2rem 0 .8rem 1.2rem;list-style:disc;">';
		echo '<li>' . esc_html__( 'Die Mails werden mit fester Absenderadresse noreply@ihrer-domain über den Mailweg der Website verschickt (wp_mail). Für zuverlässige Zustellung braucht die Domain ein SMTP-Setup mit SPF, DKIM und DMARC – ohne diese Nachweise landen Autoresponder oft im Spam.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Der Return-Path (Rückläufer-Adresse) wird auf die Absenderadresse gesetzt. Richten Sie dieses Postfach ein oder leiten Sie es um, damit Bounces (unzustellbare Mails) sichtbar werden.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Keine Freemail-Domain (gmail.com, gmx.ch usw.) als Absenderadresse verwenden – deren DMARC-Richtlinien lassen Fremdversand scheitern.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Der Status in den Formular-Einträgen bedeutet «an Mailserver übergeben» – nicht «zugestellt». Der einzige positive Zustellnachweis ist der Klick auf den Bestätigungslink.', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ul>';

		echo '<p style="margin:0 0 .4rem;"><strong>' . esc_html__( 'Missbrauchsschutz (automatisch aktiv)', 'gutenberg-formbuilder' ) . '</strong></p>';
		echo '<ul style="margin:.2rem 0 .8rem 1.2rem;list-style:disc;">';
		echo '<li>' . esc_html__( 'Der Sofort-Modus versendet nur, wenn für das Formular ein Captcha erzwungen ist – sonst wäre das Formular als anonyme Mail-Kanone missbrauchbar.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Ein mehrschichtiges Sende-Limit begrenzt den Versand: 50 Bestätigungsmails pro Stunde und Website, 10 pro Stunde und IP-Adresse, 3 pro Stunde und Empfängeradresse (Filter gfb_receipt_gate_limits).', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Nie bestätigte Einsendungen im Link-Modus werden nach 45 Tagen automatisch gelöscht (Filter gfb_receipt_retention_days).', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ul>';

		echo '<p style="margin:0 0 .4rem;"><strong>' . esc_html__( 'Datenschutz und Recht', 'gutenberg-formbuilder' ) . '</strong></p>';
		echo '<ul style="margin:.2rem 0 .8rem 1.2rem;list-style:disc;">';
		echo '<li>' . esc_html__( 'Wird ein externer SMTP-Dienst genutzt, ist er Auftragsbearbeiter (Art. 9 revDSG, Art. 28 DSGVO): AVV abschliessen, Serverstandort und TLS prüfen, EU-/CH-Anbieter bevorzugen.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Der Klick auf den Bestätigungslink belegt die Kontrolle über das Postfach – er ist keine rechtsverbindliche Willenserklärung. Für bindende Erklärungen geeignetere Mittel verwenden.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Vertrauliche Felder stehen im Sofort-Modus nur als Hinweis «vertraulich gespeichert» in der Mail; im Klartext erst nach bestätigter Adresse (Double-Opt-in).', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ul>';

		self::render_captcha_snippet_block(
			'gfb-receipt-privacy-snippet',
			__( 'Textbaustein für Ihre Datenschutzerklärung (Bestätigungsmail) anzeigen', 'gutenberg-formbuilder' ),
			__( 'Diesen Text kopieren Sie in Ihre öffentliche Datenschutzerklärung, wenn Sie die Bestätigungsmail einsetzen.', 'gutenberg-formbuilder' ),
			GFB_Receipt_Mail::privacy_text_snippet(),
			14,
			__( 'Vor dem Veröffentlichen die Platzhalter in eckigen Klammern ersetzen: Aufbewahrungsfrist gemäss Ihrer Konfiguration sowie Anbieter und Sitz Ihres SMTP-Dienstes.', 'gutenberg-formbuilder' )
		);

		// Eigener Copy-Handler: das Skript der CAPTCHA-Karte läuft beim Parsen
		// und erreicht diesen später gerenderten Button nicht mehr.
		echo "<script>(function(){var b=document.querySelector('#gfb-bestaetigungsmail .gfb-captcha-copy');if(!b)return;b.addEventListener('click',function(){var ta=document.getElementById(b.getAttribute('data-target'));if(!ta)return;ta.removeAttribute('hidden');ta.focus();ta.select();var done=function(){var o=b.textContent;b.textContent=b.getAttribute('data-done');setTimeout(function(){b.textContent=o;},1500);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(ta.value).then(done,function(){try{document.execCommand('copy');done();}catch(e){}});}else{try{document.execCommand('copy');done();}catch(e){}}});})();</script>";
	}

	/**
	 * Kundenfreundliche Anleitungs-Box für ClamAV: erklärt was, warum und wie -
	 * inkl. Auto-Detection vorhandener Pfade und plattformspezifischer
	 * Installations-Snippets.
	 *
	 * @param array $clamav_set Aktuelle ClamAV-Settings.
	 * @return void
	 */
	private static function render_clamav_help_box( $clamav_set ) {
		$detected = GFB_Clamav::detect_candidates();
		$has_bin  = ! empty( $detected['binaries'] );
		$has_sock = ! empty( $detected['sockets'] );

		echo '<div class="notice notice-info inline" style="padding:1rem 1.2rem;margin:0 0 1rem">';

		// Status-Box mit Auto-Detection – bleibt sichtbar ausserhalb des Akkordeons,
		// weil sie handlungsrelevant ist und wenig Platz kostet.
		echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:0.7rem 1rem;border-radius:4px;margin:0 0 0.6rem">';
		echo '<p style="margin:0 0 0.4rem"><strong>' . esc_html__( 'Status auf diesem Server:', 'gutenberg-formbuilder' ) . '</strong></p>';
		if ( $has_bin || $has_sock ) {
			echo '<p style="margin:0 0 0.3rem;color:#1a7f37">' . esc_html__( 'ClamAV scheint installiert zu sein. Erkannte Pfade:', 'gutenberg-formbuilder' ) . '</p>';
			echo '<ul style="margin:0 0 0 1.2rem;list-style:disc">';
			foreach ( $detected['binaries'] as $b ) {
				echo '<li><code>' . esc_html( $b ) . '</code> &nbsp; <span class="description">' . esc_html__( '(Binary – im Modus "clamscan/clamdscan-Binary" eintragen)', 'gutenberg-formbuilder' ) . '</span></li>';
			}
			foreach ( $detected['sockets'] as $s ) {
				echo '<li><code>' . esc_html( $s ) . '</code> &nbsp; <span class="description">' . esc_html__( '(Socket – im Modus "clamd-Unix-Socket" eintragen)', 'gutenberg-formbuilder' ) . '</span></li>';
			}
			echo '</ul>';
			echo '<p style="margin:0.5rem 0 0" class="description">' . esc_html__( 'Kopiere einen der Pfade in das passende Feld, wähle den passenden Modus, speichere und klicke "Verbindung testen".', 'gutenberg-formbuilder' ) . '</p>';
		} else {
			echo '<p style="margin:0;color:#a32016">' . esc_html__( 'Kein ClamAV gefunden. Du musst es zuerst installieren – siehe Anleitung unten.', 'gutenberg-formbuilder' ) . '</p>';
		}
		echo '</div>';

		// Erklärung, Schritte und alle Anleitungen stecken in einem übergeordneten,
		// standardmässig eingeklappten Akkordeon (natives <details>, gleiches Muster
		// wie die CAPTCHA-Datenschutz-Bausteine). Sichtbar bleibt nur die kurze
		// Zusammenfassungszeile; das spart auf der Einstellungsseite viel Platz.
		echo '<details style="margin:0;border:1px solid #dcdcde;border-radius:5px;background:#fff;">';
		echo '<summary style="cursor:pointer;padding:.55rem .75rem;font-weight:600;">'
			. esc_html__( 'Hilfe und Installationsanleitung für ClamAV anzeigen', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="padding:0 .75rem .75rem;">';

		echo '<p style="margin:.5rem 0 0"><strong>' . esc_html__( 'Was ist das?', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'ClamAV ist ein kostenloser Open-Source-Virenscanner. Sobald aktiv, wird jede hochgeladene Datei vor dem Speichern auf Schadsoftware geprüft. Infizierte Dateien werden sofort verworfen.', 'gutenberg-formbuilder' )
			. '</p>';
		echo '<p style="margin:0.4rem 0 0"><strong>' . esc_html__( 'Optional.', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'ClamAV ist eine Zusatzschicht und nicht zwingend erforderlich – die Datei-Verschlüsselung des Plugins funktioniert auch ohne. Auf vielen Shared-Hostings ist ClamAV nicht installierbar; dann lass den Modus einfach auf "Deaktiviert" und die "Pflicht für Datei-Uploads" AUS.', 'gutenberg-formbuilder' )
			. '</p>';

		// 3 Schritte
		echo '<p style="margin-bottom:0.3rem"><strong>' . esc_html__( 'In 3 Schritten zum Virenscan (nur wenn gewünscht):', 'gutenberg-formbuilder' ) . '</strong></p>';
		echo '<ol style="margin:0 0 0.8rem 1.4rem">';
		echo '<li>' . esc_html__( 'ClamAV auf dem Server installieren (siehe deine Plattform unten). Bei Managed-Hosting: Support kontaktieren – oft ist es dort nicht möglich, dann diesen Schritt überspringen.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Hier den Modus wählen ("Binary" ist am robustesten) und den Pfad eintragen, danach speichern.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( '"Verbindung testen (EICAR-Test)" klicken. Erst wenn "infected" gemeldet wird, kannst du optional zusätzlich "Pflicht für Datei-Uploads" aktivieren – vorher NICHT, sonst gehen alle Uploads verloren.', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ol>';

		// Plattform-Anleitungen (Details/Summary)
		echo '<details style="margin-top:0.6rem"><summary style="cursor:pointer;font-weight:600">'
			. esc_html__( 'Anleitung: Linux (Debian / Ubuntu)', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="margin:0.5rem 0 0 0.5rem">';
		echo '<p style="margin:0">' . esc_html__( 'Per SSH als root oder mit sudo:', 'gutenberg-formbuilder' ) . '</p>';
		echo '<pre style="background:#1d2327;color:#fff;padding:0.7rem;border-radius:4px;overflow:auto">'
			. "sudo apt update\n"
			. "sudo apt install clamav clamav-daemon\n"
			. "sudo systemctl enable --now clamav-daemon clamav-freshclam\n"
			. "which clamdscan   # zeigt den Binary-Pfad, meist /usr/bin/clamdscan"
			. '</pre>';
		echo '<p class="description">' . esc_html__( 'Diesen Pfad oben unter "Pfad zu clamscan/clamdscan" eintragen und Modus auf "Binary" setzen. Socket-Pfad (falls Modus "Socket") ist meist /var/run/clamav/clamd.ctl.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</div></details>';

		echo '<details style="margin-top:0.4rem"><summary style="cursor:pointer;font-weight:600">'
			. esc_html__( 'Anleitung: Linux (RHEL / CentOS / AlmaLinux / Rocky)', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="margin:0.5rem 0 0 0.5rem">';
		echo '<pre style="background:#1d2327;color:#fff;padding:0.7rem;border-radius:4px;overflow:auto">'
			. "sudo dnf install -y epel-release\n"
			. "sudo dnf install -y clamav clamav-update clamd\n"
			. "sudo systemctl enable --now clamd@scan\n"
			. "which clamdscan   # meist /usr/bin/clamdscan"
			. '</pre>';
		echo '<p class="description">' . esc_html__( 'Socket-Pfad (falls Modus "Socket") ist meist /var/run/clamd.scan/clamd.sock. SELinux beachten: ggf. "setsebool -P antivirus_can_scan_system 1" und sicherstellen, dass der Webserver-User (z.B. apache, nginx) den Socket lesen darf.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</div></details>';

		echo '<details style="margin-top:0.4rem"><summary style="cursor:pointer;font-weight:600">'
			. esc_html__( 'Anleitung: macOS (Entwicklung mit Homebrew)', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="margin:0.5rem 0 0 0.5rem">';
		echo '<pre style="background:#1d2327;color:#fff;padding:0.7rem;border-radius:4px;overflow:auto">'
			. "brew install clamav\n"
			. "cp \"$(brew --prefix)/etc/clamav/freshclam.conf.sample\" \"$(brew --prefix)/etc/clamav/freshclam.conf\"\n"
			. "# In freshclam.conf die Zeile 'Example' auskommentieren, dann:\n"
			. "freshclam              # Virendatenbank laden\n"
			. "which clamscan         # meist /opt/homebrew/bin/clamscan"
			. '</pre>';
		echo '<p class="description">' . esc_html__( 'Nur für lokale Entwicklung gedacht. In Produktion immer einen richtigen Linux-Server verwenden.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</div></details>';

		echo '<details style="margin-top:0.4rem"><summary style="cursor:pointer;font-weight:600">'
			. esc_html__( 'Managed-Hosting / Shared-Hosting (Plesk, cPanel, Strato, IONOS, ...)', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="margin:0.5rem 0 0 0.5rem">';
		echo '<p>' . esc_html__( 'Auf Shared-Hosting hast du in der Regel keinen Root-Zugriff. Schreibe deinem Hoster diese kurze Anfrage:', 'gutenberg-formbuilder' ) . '</p>';
		echo '<blockquote style="background:#f6f7f7;border-left:4px solid #2271b1;padding:0.8rem 1rem;margin:0.5rem 0;font-style:italic">'
			. esc_html__( 'Ich nutze ein WordPress-Plugin, das Datei-Uploads vor dem Speichern mit ClamAV prüfen soll. Bitte teilt mir mit: (1) ist clamscan oder clamdscan auf meinem Tarif verfügbar, (2) wenn ja, welcher Pfad und ggf. welcher Socket-Pfad, (3) falls nicht: kann ClamAV optional aktiviert werden? Vielen Dank.', 'gutenberg-formbuilder' )
			. '</blockquote>';
		echo '<p class="description">' . esc_html__( 'Wenn dein Hoster ClamAV nicht anbietet: lass den Modus auf "Deaktiviert" und die "Pflicht für Datei-Uploads" AUS – dann gehen Uploads weiterhin durch, allerdings ohne Virenscan. Die Datei-Verschlüsselung bleibt davon unberührt.', 'gutenberg-formbuilder' ) . '</p>';
		echo '</div></details>';

		echo '<details style="margin-top:0.4rem"><summary style="cursor:pointer;font-weight:600">'
			. esc_html__( 'Hilfe bei Fehlern (Verbindung schlägt fehl)', 'gutenberg-formbuilder' ) . '</summary>';
		echo '<div style="margin:0.5rem 0 0 0.5rem">';
		echo '<ul style="margin:0 0 0 1.2rem;list-style:disc">';
		echo '<li>' . esc_html__( '"Permission denied" beim Socket: der Webserver-User (www-data, apache, nginx) muss Lese-/Schreibrechte auf den Socket-Pfad haben. Prüfe mit: ls -l /var/run/clamav/clamd.ctl', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( '"clamdscan: command not found" im Binary-Modus: prüfe den Pfad mit "which clamdscan" und trage den vollständigen Pfad ein.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Scan dauert sehr lange: "Timeout (Sek.)" hochsetzen oder den Daemon-Modus (Socket bzw. clamdscan) verwenden statt clamscan.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Virendatenbank veraltet: regelmässige Updates sicherstellen ("freshclam" bzw. systemd-Service "clamav-freshclam").', 'gutenberg-formbuilder' ) . '</li>';
		echo '</ul>';
		echo '</div></details>';

		// Schliesst das übergeordnete Hilfe-Akkordeon (innerer Padding-Container + <details>).
		echo '</div></details>';

		echo '</div>';
	}
}
