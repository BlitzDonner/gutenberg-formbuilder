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
 * 5) Key-Rotation (Re-Wrap-Run starten)
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
			__( 'Sicherheit', 'gutenberg-formbuilder' ),
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
			echo '<div class="notice notice-error"><p><strong>Gutenberg Formbuilder:</strong> '
				. esc_html__( 'Verschlüsselung ist NICHT aktiv. Datei-Uploads sind blockiert, sensible Felder werden im Klartext gespeichert. Grund: ', 'gutenberg-formbuilder' )
				. esc_html( $status['reason'] )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">'
				. esc_html__( 'Jetzt einrichten', 'gutenberg-formbuilder' ) . '</a></p></div>';
		}
		$clamav_pre = GFB_Clamav::precondition_for_uploads();
		if ( is_wp_error( $clamav_pre ) ) {
			echo '<div class="notice notice-warning"><p><strong>Gutenberg Formbuilder:</strong> '
				. esc_html( $clamav_pre->get_error_message() ) . '</p></div>';
		}
		$reach = get_transient( 'gfb_storage_reach' );
		if ( is_array( $reach ) && empty( $reach['ok'] ) ) {
			echo '<div class="notice notice-error"><p><strong>Gutenberg Formbuilder — wichtiger Sicherheitshinweis:</strong> '
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
		if ( $page !== GFB_Admin_Submissions::PAGE_SLUG && $page !== self::PAGE_SLUG ) {
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

			default:
				$message = __( 'Unbekannte Aktion.', 'gutenberg-formbuilder' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'gfb_m' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Sicherheit & Einstellungen', 'gutenberg-formbuilder' ) . '</h1>';
		if ( '' !== $msg ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// 1) Verschlüsselung
		echo '<h2>' . esc_html__( 'Verschlüsselung', 'gutenberg-formbuilder' ) . '</h2>';
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

		// 2) ClamAV
		echo '<hr/><h2>' . esc_html__( 'ClamAV (Virenscan beim Upload)', 'gutenberg-formbuilder' ) . '</h2>';

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

		echo '<tr><th>' . esc_html__( 'Pfad zu clamscan/clamdscan', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="text" name="binary_path" value="' . esc_attr( $clamav_set['binary_path'] ) . '" class="regular-text" placeholder="/usr/bin/clamdscan" />'
			. '<p class="description">' . esc_html__( 'Vollständiger Pfad zur ausführbaren Datei. In einer SSH-Sitzung herausfinden mit: which clamdscan oder which clamscan. Auf Shared-Hosting ggf. der Support fragen.', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr><th>' . esc_html__( 'clamd-Socket-Pfad', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="text" name="socket_path" value="' . esc_attr( $clamav_set['socket_path'] ) . '" class="regular-text" placeholder="/var/run/clamd.scan/clamd.sock" />'
			. '<p class="description">' . esc_html__( 'Pfad zur clamd.sock-Datei. Typisch: /var/run/clamd.scan/clamd.sock (RHEL/CentOS) oder /var/run/clamav/clamd.ctl (Debian/Ubuntu).', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Timeout (Sek.)', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<input type="number" name="timeout_sec" value="' . esc_attr( (string) $clamav_set['timeout_sec'] ) . '" min="1" max="600" />'
			. '<p class="description">' . esc_html__( 'Wie lange soll auf das Scan-Ergebnis maximal gewartet werden? Standard: 30 Sekunden. Für sehr grosse Dateien ggf. erhöhen.', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Pflicht für Datei-Uploads', 'gutenberg-formbuilder' ) . '</th><td>'
			. '<label><input type="checkbox" name="require_for_uploads" value="1" '
			. checked( $clamav_set['require_for_uploads'], true, false )
			. ' /> ' . esc_html__( 'Strikter Modus (NICHT Standard): Wenn der Scanner nicht erreichbar ist, werden ALLE Datei-Uploads abgelehnt.', 'gutenberg-formbuilder' )
			. '</label>'
			. '<p class="description"><strong>' . esc_html__( 'Standardmässig deaktiviert.', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'Aktiviere diese Option NUR, wenn du oben einen Modus (Binary/Socket) eingerichtet und mit "Verbindung testen" erfolgreich geprüft hast. Sonst werden alle Datei-Uploads blockiert. Auf Hostings ohne ClamAV-Möglichkeit unbedingt deaktiviert lassen - die Datei-Verschlüsselung greift unabhängig davon.', 'gutenberg-formbuilder' ) . '</p>'
			. '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'ClamAV-Einstellungen speichern', 'gutenberg-formbuilder' ) );
		echo '</form>';

		echo '<form method="post" style="margin-top:0.5rem">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="eicar_test" />';
		submit_button( __( 'Verbindung testen (EICAR-Test)', 'gutenberg-formbuilder' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Erstellt das offizielle EICAR-Testmuster (eine harmlose Datei, die jeder Virenscanner als bekannten Test erkennt) und schickt sie an deine ClamAV-Konfiguration. Erwartet: "infected" - das bedeutet, der Scanner funktioniert.', 'gutenberg-formbuilder' ) . '</span>';
		echo '</form>';

		// 3) Capability-Matrix
		echo '<hr/><h2>' . esc_html__( 'Berechtigungen', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<p>' . esc_html__( 'Wer darf welche Aktion?', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="save_caps" />';
		global $wp_roles;
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Rolle', 'gutenberg-formbuilder' ) . '</th>';
		foreach ( GFB_Capabilities::all_caps() as $cap ) {
			echo '<th><code>' . esc_html( $cap ) . '</code></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			echo '<tr><td><strong>' . esc_html( $role_data['name'] ) . '</strong> <code>' . esc_html( $role_slug ) . '</code></td>';
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

		// 4) Audit-Verifikation
		echo '<hr/><h2>' . esc_html__( 'Audit-Log Integrität', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="verify_audit" />';
		submit_button( __( 'Hash-Chain prüfen', 'gutenberg-formbuilder' ), 'secondary' );
		echo '</form>';

		// 4b) Storage-Erreichbarkeits-Test (Webserver darf private Dateien nicht ausliefern)
		echo '<hr/><h2>' . esc_html__( 'Sind hochgeladene Dateien wirklich privat?', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<p>' . esc_html__( 'Dieser Selbsttest prüft, ob jemand mit einem direkten Link an die Dateien aus dem geschützten Speicher kommt. Erwartet wird: NEIN — der Webserver muss die Anfrage ablehnen.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<details style="margin:0 0 0.8rem"><summary style="cursor:pointer">'
			. esc_html__( 'Wie läuft der Test ab?', 'gutenberg-formbuilder' ) . '</summary>'
			. '<ol style="margin:0.4rem 0 0.6rem 1.4rem">'
			. '<li>' . esc_html__( 'Das Plugin legt kurz eine harmlose Test-Datei im privaten Speicher an.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Es ruft die zugehörige URL über das Internet ab — so, wie es ein Fremder tun könnte.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Antwortet der Webserver mit "verboten" (403) oder "nicht gefunden" (404), ist alles in Ordnung.', 'gutenberg-formbuilder' ) . '</li>'
			. '<li>' . esc_html__( 'Die Test-Datei wird sofort wieder gelöscht.', 'gutenberg-formbuilder' ) . '</li>'
			. '</ol></details>';

		$cached_reach = get_transient( 'gfb_storage_reach' );
		if ( is_array( $cached_reach ) ) {
			$is_ok    = ! empty( $cached_reach['ok'] );
			$bg       = $is_ok ? '#edfaef' : '#fcebec';
			$border   = $is_ok ? '#1a7f37' : '#a32016';
			$icon     = $is_ok ? '✓' : '✗';
			$headline = $is_ok ? __( 'Test bestanden — deine Dateien sind privat.', 'gutenberg-formbuilder' )
				: __( 'Achtung — Handlungsbedarf!', 'gutenberg-formbuilder' );

			echo '<div style="background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $border ) . ';padding:0.8rem 1rem;margin:0.6rem 0;border-radius:3px">';
			echo '<p style="margin:0;font-size:1.05em"><strong>' . esc_html( $icon . ' ' . $headline ) . '</strong></p>';
			echo '<p style="margin:0.4rem 0 0">' . esc_html( $cached_reach['details'] ) . '</p>';
			if ( ! empty( $cached_reach['url'] ) ) {
				echo '<p style="margin:0.6rem 0 0;font-size:0.92em;color:#50575e">';
				echo '<strong>' . esc_html__( 'Getestete URL:', 'gutenberg-formbuilder' ) . '</strong> ';
				echo '<code style="word-break:break-all">' . esc_html( $cached_reach['url'] ) . '</code>';
				echo ' — ' . esc_html( sprintf( __( 'Antwort vom Webserver: HTTP %d', 'gutenberg-formbuilder' ), (int) $cached_reach['http_code'] ) );
				echo '<br><em>' . esc_html__( '(Diese Test-Datei existiert nicht mehr — sie wurde nach dem Test wieder gelöscht.)', 'gutenberg-formbuilder' ) . '</em>';
				echo '</p>';
			}
			if ( ! $is_ok ) {
				echo '<p style="margin:0.6rem 0 0"><strong>' . esc_html__( 'Was tun?', 'gutenberg-formbuilder' ) . '</strong> '
					. esc_html__( 'Webserver-Konfiguration anpassen, sodass das Verzeichnis "wp-content/.gfb-private/" nicht aus dem Internet erreichbar ist. Konkrete Snippets für Apache und Nginx stehen in der Datei INSTALL.md des Plugins. Falls du keinen Server-Zugriff hast: dem Hoster diese Meldung weiterleiten.', 'gutenberg-formbuilder' ) . '</p>';
			}
			echo '</div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Noch nicht getestet. Klicke unten auf "Jetzt prüfen".', 'gutenberg-formbuilder' ) . '</p>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="storage_reach_test" />';
		submit_button( __( 'Jetzt prüfen', 'gutenberg-formbuilder' ), 'secondary' );
		echo ' <span class="description">' . esc_html__( 'Dauert nur wenige Sekunden. Kann beliebig oft wiederholt werden, z. B. nach Änderungen am Webserver.', 'gutenberg-formbuilder' ) . '</span>';
		echo '</form>';

		// 5) Key-Rotation
		echo '<hr/><h2>' . esc_html__( 'Key-Rotation', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<p>' . esc_html__( 'Nach dem Hinzufügen eines neuen Master-Keys (in wp-config.php) werden bestehende Datei-DEKs hier mit der neuen KEK reverpackt. Datei-Inhalte werden NICHT entschlüsselt.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( 'gfb_settings_action' );
		echo '<input type="hidden" name="gfb_settings_action" value="rewrap_now" />';
		submit_button( __( 'Re-Wrap-Lauf jetzt starten (max. 200 Dateien)', 'gutenberg-formbuilder' ), 'secondary' );
		echo '</form>';

		echo '</div>';
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
		echo '<p style="margin-top:0"><strong>' . esc_html__( 'Was ist das?', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'ClamAV ist ein kostenloser Open-Source-Virenscanner. Sobald aktiv, wird jede hochgeladene Datei vor dem Speichern auf Schadsoftware geprüft. Infizierte Dateien werden sofort verworfen.', 'gutenberg-formbuilder' )
			. '</p>';
		echo '<p style="margin:0.4rem 0 0"><strong>' . esc_html__( 'Optional.', 'gutenberg-formbuilder' ) . '</strong> '
			. esc_html__( 'ClamAV ist eine Zusatzschicht und nicht zwingend erforderlich - die Datei-Verschlüsselung des Plugins funktioniert auch ohne. Auf vielen Shared-Hostings ist ClamAV nicht installierbar; dann lass den Modus einfach auf "Deaktiviert" und die "Pflicht für Datei-Uploads" AUS.', 'gutenberg-formbuilder' )
			. '</p>';

		// Status-Box mit Auto-Detection
		echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:0.7rem 1rem;border-radius:4px;margin:0.6rem 0">';
		echo '<p style="margin:0 0 0.4rem"><strong>' . esc_html__( 'Status auf diesem Server:', 'gutenberg-formbuilder' ) . '</strong></p>';
		if ( $has_bin || $has_sock ) {
			echo '<p style="margin:0 0 0.3rem;color:#1a7f37">' . esc_html__( 'ClamAV scheint installiert zu sein. Erkannte Pfade:', 'gutenberg-formbuilder' ) . '</p>';
			echo '<ul style="margin:0 0 0 1.2rem;list-style:disc">';
			foreach ( $detected['binaries'] as $b ) {
				echo '<li><code>' . esc_html( $b ) . '</code> &nbsp; <span class="description">' . esc_html__( '(Binary - im Modus "clamscan/clamdscan-Binary" eintragen)', 'gutenberg-formbuilder' ) . '</span></li>';
			}
			foreach ( $detected['sockets'] as $s ) {
				echo '<li><code>' . esc_html( $s ) . '</code> &nbsp; <span class="description">' . esc_html__( '(Socket - im Modus "clamd-Unix-Socket" eintragen)', 'gutenberg-formbuilder' ) . '</span></li>';
			}
			echo '</ul>';
			echo '<p style="margin:0.5rem 0 0" class="description">' . esc_html__( 'Kopiere einen der Pfade unten in das passende Feld, wähle den passenden Modus, speichere und klicke "Verbindung testen".', 'gutenberg-formbuilder' ) . '</p>';
		} else {
			echo '<p style="margin:0;color:#a32016">' . esc_html__( 'Kein ClamAV gefunden. Du musst es zuerst installieren - siehe Anleitung unten.', 'gutenberg-formbuilder' ) . '</p>';
		}
		echo '</div>';

		// 3 Schritte
		echo '<p style="margin-bottom:0.3rem"><strong>' . esc_html__( 'In 3 Schritten zum Virenscan (nur wenn gewünscht):', 'gutenberg-formbuilder' ) . '</strong></p>';
		echo '<ol style="margin:0 0 0.8rem 1.4rem">';
		echo '<li>' . esc_html__( 'ClamAV auf dem Server installieren (siehe deine Plattform unten). Bei Managed-Hosting: Support kontaktieren - oft ist es dort nicht möglich, dann diesen Schritt überspringen.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( 'Hier den Modus wählen ("Binary" ist am robustesten) und den Pfad eintragen, danach speichern.', 'gutenberg-formbuilder' ) . '</li>';
		echo '<li>' . esc_html__( '"Verbindung testen (EICAR-Test)" klicken. Erst wenn "infected" gemeldet wird, kannst du optional zusätzlich "Pflicht für Datei-Uploads" aktivieren - vorher NICHT, sonst gehen alle Uploads verloren.', 'gutenberg-formbuilder' ) . '</li>';
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
		echo '<p class="description">' . esc_html__( 'Wenn dein Hoster ClamAV nicht anbietet: lass den Modus auf "Deaktiviert" und die "Pflicht für Datei-Uploads" AUS - dann gehen Uploads weiterhin durch, allerdings ohne Virenscan. Die Datei-Verschlüsselung bleibt davon unberührt.', 'gutenberg-formbuilder' ) . '</p>';
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

		echo '</div>';
	}
}
