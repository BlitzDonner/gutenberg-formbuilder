<?php
/**
 * Bestätigungsmail an die ausfüllende Person (Autoresponder + Double-Opt-in).
 *
 * Umsetzung nach docs/BESTAETIGUNGSMAIL-SPEC.md:
 * - Gemeinsame Mail-Engine für Sofort-Modus (instant) und Double-Opt-in (doi).
 * - Eigener Block-zu-Mail-HTML-Übersetzer (Tabellenlayout, Inline-CSS) plus
 *   parallele Plaintext-Fassung; echtes Multipart über phpmailer_init/AltBody.
 * - From ist fest die betreiber-eigene Site-Adresse (noreply@site-domain,
 *   per Filter überschreibbar) – nie die feldgesteuerte emailFrom*-Logik.
 * - Mehrschichtiges, atomares Send-Gate (global + Per-IP + Per-Empfänger,
 *   fail-closed) gegen Missbrauch als Mail-Kanone.
 * - DOI-Token: random_bytes(32), gespeichert nur als gepfefferter HMAC-Hash;
 *   Einmalgebrauch als atomarer Compare-and-swap; datenfreie Landeseite,
 *   Bestätigung ausschliesslich per POST.
 * - Retention-Cron löscht nie bestätigte Einsendungen nach konfigurierbarer
 *   Frist (Default 45 Tage) und räumt abgelaufene Token + Gate-Zähler ab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Receipt_Mail {

	const MODE_NONE    = 'none';
	const MODE_INSTANT = 'instant';
	const MODE_DOI     = 'doi';

	/** Gültigkeit des Bestätigungslinks in Tagen (E8, fix). */
	const CONFIRM_TTL_DAYS = 7;

	/** Cron-Hook für Retention (nie bestätigte Einsendungen, abgelaufene Token, Gate-Zähler). */
	const CRON_HOOK = 'gfb_receipt_retention_cron';

	/** Präfix der atomaren Gate-Zähler in wp_options (autoload=no, Aufräumen im Cron). */
	const GATE_OPTION_PREFIX = 'gfb_rg_';

	/** Option: site-weites Branding der Person-Mails (Logo, Logo-Link, Footer-Identität). */
	const OPTION_BRANDING = 'gfb_receipt_branding';

	/**
	 * Tatsächliches Captcha-Verifikationsergebnis DIESES Requests
	 * ('' = nicht verifiziert, sonst pass|fail|unreachable). Gesetzt von
	 * GFB_Submit_Handler::maybe_enforce_captcha(); der Sofort-Versand verlangt
	 * fail-closed exakt «pass» – is_active_for_form() allein genügt nicht,
	 * weil der soft-Modus bei nicht erreichbarem Anbieter durchlässt (Chloe M-1).
	 *
	 * @var string
	 */
	private static $captcha_request_result = '';

	/**
	 * Render-Kontext des laufenden gfb_confirm-Requests (state, sid, token,
	 * receipt_result) für den dynamischen Block gfb/confirm-status. Nur
	 * innerhalb von output_confirm_page() gesetzt – ausserhalb rendert der
	 * Block ''. Enthält nie Feldwerte oder PII.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $confirm_ctx = null;

	/**
	 * Setzt das Captcha-Ergebnis des laufenden Requests.
	 *
	 * @param string $result pass|fail|unreachable.
	 * @return void
	 */
	public static function set_captcha_request_result( $result ) {
		$result = is_string( $result ) ? sanitize_key( $result ) : '';
		self::$captcha_request_result = in_array( $result, array( 'pass', 'fail', 'unreachable' ), true ) ? $result : '';
	}

	/**
	 * Hooks registrieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_gfb_confirm', array( __CLASS__, 'handle_confirm' ) );
		add_action( 'admin_post_nopriv_gfb_confirm', array( __CLASS__, 'handle_confirm' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_retention' ) );
		// Bestehende Installationen bekommen keinen Activation-Hook beim Update:
		// Cron-Plan deshalb auf init nachziehen (billiger Options-Read).
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ), 20 );
		// Landeseiten als Site-Editor-Templates (WP 6.7+; darunter greift die
		// eingebaute Minimalseite als Fallback).
		add_action( 'init', array( __CLASS__, 'register_confirm_templates' ), 20 );
		// gfb/confirm-status nur im Site Editor anbieten (in Beiträgen/Seiten sinnlos).
		add_filter( 'allowed_block_types_all', array( __CLASS__, 'restrict_confirm_status_inserter' ), 10, 2 );
		// Integrations-Hooks für Dritt-Plugins (z. B. CRM-Anbindung): geben nur
		// boolesche/Status-Werte heraus, nie Feldinhalte.
		add_filter( 'gfb_doi_cleared', array( __CLASS__, 'filter_doi_cleared' ), 10, 2 );
		add_filter( 'gfb_doi_status', array( __CLASS__, 'filter_doi_status' ), 10, 2 );
		add_filter( 'gfb_transfer_consent', array( __CLASS__, 'filter_transfer_consent' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ *
	 * Integrations-Hooks (docs/INTEGRATIONEN.md)
	 * ------------------------------------------------------------------ */

	/**
	 * Zeilen-Lookup für die Integrations-Filter (prepared, nur die nötigen
	 * Spalten – Feldinhalte verlassen die Filter nie).
	 *
	 * @param int $submission_id Zeilen-ID.
	 * @return object|null
	 */
	private static function submission_row_for_hooks( $submission_id ) {
		global $wpdb;
		$sid = absint( $submission_id );
		if ( $sid < 1 ) {
			return null;
		}
		$table = $wpdb->prefix . 'gfb_submissions';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, post_id, form_id, payload, confirm_status, confirm_expires_at FROM {$table} WHERE id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Filter gfb_doi_status: ''/none/pending/confirmed/expired.
	 * '' = unbekannte ID; sonst dieselbe Zustandslogik wie die DOI-Ampel
	 * (eine Quelle: GFB_Admin_Submissions::doi_status_for_row, UTC-Zeitpfad).
	 *
	 * @param mixed $value         Eingangswert (Default '').
	 * @param int   $submission_id Zeilen-ID.
	 * @return string
	 */
	public static function filter_doi_status( $value, $submission_id ) {
		$row = self::submission_row_for_hooks( $submission_id );
		if ( null === $row ) {
			return '';
		}
		return GFB_Admin_Submissions::doi_status_for_row(
			isset( $row->confirm_status ) ? (string) $row->confirm_status : '',
			isset( $row->confirm_expires_at ) ? (string) $row->confirm_expires_at : ''
		);
	}

	/**
	 * Filter gfb_doi_cleared: Ist die Übermittlung aus DOI-Sicht freigegeben?
	 * true = Formular ohne DOI-Modus (keine Bestätigungspflicht) ODER
	 * confirm_status «confirmed»; false bei pending/abgelaufen/unbekannter ID.
	 * Konservativer Randfall: DOI-Formular, dessen Token-Anlage scheiterte
	 * (Status leer, Modus doi) → false.
	 *
	 * @param mixed $value         Eingangswert (Default false).
	 * @param int   $submission_id Zeilen-ID.
	 * @return bool
	 */
	public static function filter_doi_cleared( $value, $submission_id ) {
		$row = self::submission_row_for_hooks( $submission_id );
		if ( null === $row ) {
			return false;
		}
		$status = isset( $row->confirm_status ) ? (string) $row->confirm_status : '';
		if ( 'confirmed' === $status ) {
			return true;
		}
		if ( '' !== $status ) {
			// pending – offen oder abgelaufen: nicht freigegeben.
			return false;
		}
		// Kein DOI-Status in der Zeile: massgeblich ist der Modus des Formulars.
		$attrs = GFB_Plugin::get_form_block_attributes_from_post( (int) $row->post_id, (string) $row->form_id );
		return self::MODE_DOI !== self::mode_from_attrs( is_array( $attrs ) ? $attrs : array() );
	}

	/**
	 * Filter gfb_transfer_consent: true NUR, wenn am Formular ein
	 * Einwilligungs-Feld (consentField, gfb/field-checkbox) designiert ist UND
	 * die Einsendung dort den Checkbox-Wahrwert «1» trägt. Kein designiertes
	 * Feld → false (Erlaubnis nie implizit). Vertraulich gespeicherte Werte
	 * (Envelope) werden für diese Abfrage NICHT entschlüsselt → false.
	 *
	 * @param mixed $value         Eingangswert (Default false).
	 * @param int   $submission_id Zeilen-ID.
	 * @return bool
	 */
	public static function filter_transfer_consent( $value, $submission_id ) {
		$row = self::submission_row_for_hooks( $submission_id );
		if ( null === $row ) {
			return false;
		}
		$attrs = GFB_Plugin::get_form_block_attributes_from_post( (int) $row->post_id, (string) $row->form_id );
		$field = ( is_array( $attrs ) && isset( $attrs['consentField'] ) ) ? sanitize_key( (string) $attrs['consentField'] ) : '';
		if ( '' === $field ) {
			return false;
		}
		$payload = json_decode( isset( $row->payload ) ? (string) $row->payload : '', true );
		if ( ! is_array( $payload ) || ! isset( $payload[ $field ] ) ) {
			return false;
		}
		$checkbox = $payload[ $field ];
		if ( GFB_Crypto::is_field_envelope( $checkbox ) ) {
			return false;
		}
		// Checkbox-Norm des Plugins: '1' = angekreuzt, '0' = nicht.
		return '1' === (string) $checkbox;
	}

	/**
	 * Täglichen Retention-Cron planen, falls noch nicht vorhanden.
	 *
	 * @return void
	 */
	public static function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Modus aus den gfb/form-Block-Attributen (nie aus POST).
	 *
	 * @param array<string,mixed> $form_attrs Block-Attribute.
	 * @return string none|instant|doi
	 */
	public static function mode_from_attrs( array $form_attrs ) {
		$mode = isset( $form_attrs['receiptMode'] ) ? sanitize_key( (string) $form_attrs['receiptMode'] ) : self::MODE_NONE;
		if ( in_array( $mode, array( self::MODE_INSTANT, self::MODE_DOI ), true ) ) {
			return $mode;
		}
		return self::MODE_NONE;
	}

	/**
	 * Betreiber-Defaults der offenen Parameter (Spec Abschnitt 11), per Filter
	 * überschreibbar.
	 *
	 * @return array{global:int,per_ip:int,per_recipient:int}
	 */
	public static function gate_limits() {
		$defaults = array(
			'global'        => 50, // Bestätigungsmails/Stunde/Site (fail-closed).
			'per_ip'        => 10, // pro Stunde und IP.
			'per_recipient' => 3,  // pro Stunde und normalisierter Empfängeradresse.
		);
		/**
		 * Send-Gate-Limits der Bestätigungsmail (pro Stunde).
		 *
		 * @param array{global:int,per_ip:int,per_recipient:int} $defaults Limits.
		 */
		$limits = apply_filters( 'gfb_receipt_gate_limits', $defaults );
		if ( ! is_array( $limits ) ) {
			$limits = $defaults;
		}
		foreach ( $defaults as $k => $v ) {
			$limits[ $k ] = isset( $limits[ $k ] ) ? max( 1, (int) $limits[ $k ] ) : $v;
		}
		return $limits;
	}

	/**
	 * Aufbewahrungsfrist nie bestätigter Einsendungen in Tagen (Default 45).
	 *
	 * @return int
	 */
	public static function retention_days() {
		/**
		 * Frist, nach der nie bestätigte DOI-Einsendungen gelöscht werden (Tage).
		 *
		 * @param int $days Default 45.
		 */
		return max( 1, (int) apply_filters( 'gfb_receipt_retention_days', 45 ) );
	}

	/* ------------------------------------------------------------------ *
	 * Einstieg nach gespeicherter Einsendung
	 * ------------------------------------------------------------------ */

	/**
	 * Wird von GFB_Submit_Handler::handle() NACH Persistenz + Betreiber-Mail
	 * aufgerufen. Entscheidet nach Modus und versendet Sofort-Quittung bzw.
	 * Link-Mail. Fehler brechen den Submit nie ab (Einsendung ist gespeichert).
	 *
	 * @param int                  $submission_id  Zeilen-ID.
	 * @param int                  $post_id        Post-ID.
	 * @param string               $form_id        Formular-ID.
	 * @param array<string,mixed>  $form_attrs     gfb/form-Block-Attribute.
	 * @param array<string,mixed>  $payload        Gespeicherter Payload (inkl. _gfb_labels).
	 * @param array<int,array>     $schema         Formularschema.
	 * @param string               $form_title     Anzeigename.
	 * @param array<string,string> $plain_snapshot Klartext-Werte vor Verschlüsselung.
	 * @return void
	 */
	public static function handle_after_submission( $submission_id, $post_id, $form_id, array $form_attrs, array $payload, array $schema, $form_title, array $plain_snapshot ) {
		$mode = self::mode_from_attrs( $form_attrs );
		if ( self::MODE_NONE === $mode ) {
			return;
		}

		$field = isset( $form_attrs['receiptEmailField'] ) ? sanitize_key( (string) $form_attrs['receiptEmailField'] ) : '';
		if ( '' === $field ) {
			self::audit_skip( $submission_id, $form_id, 'no_field' );
			return;
		}

		// Empfängeradresse aus dem Klartext-Snapshot (E-Mail-Felder können
		// vertraulich markiert sein und liegen im Payload dann verschlüsselt).
		$recipient = isset( $plain_snapshot[ $field ] ) ? sanitize_email( (string) $plain_snapshot[ $field ] ) : '';
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			self::audit_skip( $submission_id, $form_id, 'no_recipient' );
			return;
		}

		// Blocker 2: Sofort-Modus mit frei wählbarem Empfänger nur bei
		// erzwungenem Captcha (serverseitig, nicht nur Editor-Warnung).
		if ( self::MODE_INSTANT === $mode && ! GFB_Captcha::is_active_for_form( $form_attrs ) ) {
			self::audit_skip( $submission_id, $form_id, 'captcha_required' );
			return;
		}

		// Fail-closed (Chloe M-1): Der Sofort-Versand verlangt das TATSÄCHLICHE
		// Verifikationsergebnis «pass» dieses Requests. «unreachable» im
		// soft-Modus lässt zwar die Einsendung durch, aber keine Mail an einen
		// frei wählbaren Empfänger. Die Einsendung läuft normal weiter.
		if ( self::MODE_INSTANT === $mode && 'pass' !== self::$captcha_request_result ) {
			self::audit_skip( $submission_id, $form_id, 'captcha_unverified' );
			return;
		}

		// DOI: Status «pending» + Token VOR dem Send-Gate setzen (Spec Abschnitt 6,
		// Schritte 1–2). Blockt das Gate danach die Link-Mail, bleibt die
		// Einsendung unbestätigt und unterliegt der Retention.
		$token = '';
		if ( self::MODE_DOI === $mode ) {
			$token = self::start_doi( $submission_id );
			if ( '' === $token ) {
				self::audit_skip( $submission_id, $form_id, 'token_store_failed' );
				return;
			}
		}

		// Send-Gate: global + Per-IP + Per-Empfänger, atomar, fail-closed.
		$gate = self::gate_allows( $recipient );
		if ( true !== $gate ) {
			self::audit_skip( $submission_id, $form_id, 'gate_' . $gate );
			return;
		}

		$ctx = self::build_context(
			( self::MODE_DOI === $mode ) ? 'doi' : 'instant',
			$post_id,
			$form_id,
			$payload,
			$schema,
			$plain_snapshot
		);
		if ( self::MODE_DOI === $mode ) {
			$ctx['confirm_url'] = self::confirm_url( $submission_id, $token );
		}

		// Sprache zur Absendezeit (E10): determine_locale(), per Filter übersteuerbar.
		$switched = self::switch_mail_locale( $form_id, $post_id );

		if ( self::MODE_DOI === $mode ) {
			$region  = GFB_Plugin::get_form_mail_region_blocks( $post_id, $form_id, 'gfb/doi-mail' );
			$subject = self::build_subject(
				isset( $form_attrs['doiSubject'] ) ? (string) $form_attrs['doiSubject'] : '',
				__( 'Bitte bestätigen Sie Ihre E-Mail-Adresse', 'gutenberg-formbuilder' ),
				$ctx
			);
		} else {
			$region  = GFB_Plugin::get_form_mail_region_blocks( $post_id, $form_id, 'gfb/receipt-mail' );
			$subject = self::build_subject(
				isset( $form_attrs['receiptSubject'] ) ? (string) $form_attrs['receiptSubject'] : '',
				sprintf(
					/* translators: %s: Name der Website */
					__( 'Ihre Einsendung bei %s ist eingegangen', 'gutenberg-formbuilder' ),
					wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
				),
				$ctx
			);
		}

		$sent = self::send_mail( $recipient, $subject, $region, $ctx );

		if ( $switched ) {
			restore_previous_locale();
		}

		self::update_receipt_status( $submission_id, $sent ? 'handed_off' : 'handoff_failed' );
		GFB_Audit::record(
			$sent ? 'receipt_handed_off' : 'receipt_handoff_failed',
			'submission',
			(string) $submission_id,
			array(
				'form_id' => $form_id,
				'mode'    => $mode,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * DOI: Token + Bestätigungsroute
	 * ------------------------------------------------------------------ */

	/**
	 * Gepfefferter Hash eines Roh-Tokens (nur der Hash liegt in der DB).
	 *
	 * @param string $token Roh-Token (base64url).
	 * @return string 64 Hex-Zeichen.
	 */
	private static function token_hash( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) . '|gfb-confirm-v1' );
	}

	/**
	 * Setzt die Einsendung auf «pending», erzeugt den Token (CSPRNG) und
	 * speichert nur Hash + Ablauf. Gibt den Roh-Token zurück (einzige Stelle,
	 * an der er existiert – er wandert direkt in die Link-URL).
	 *
	 * @param int $submission_id Zeilen-ID.
	 * @return string Roh-Token oder leer bei Fehler.
	 */
	private static function start_doi( $submission_id ) {
		global $wpdb;
		try {
			$raw = random_bytes( 32 );
		} catch ( \Throwable $e ) {
			return '';
		}
		$token   = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::CONFIRM_TTL_DAYS * DAY_IN_SECONDS );

		$ok = $wpdb->update(
			$wpdb->prefix . 'gfb_submissions',
			array(
				'confirm_status'     => 'pending',
				'confirm_token_hash' => self::token_hash( $token ),
				'confirm_expires_at' => $expires,
			),
			array( 'id' => (int) $submission_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return ( false === $ok ) ? '' : $token;
	}

	/**
	 * Bestätigungslink. HTTPS wird erzwungen, sobald die Site HTTPS spricht.
	 *
	 * @param int    $submission_id Zeilen-ID.
	 * @param string $token         Roh-Token.
	 * @return string
	 */
	private static function confirm_url( $submission_id, $token ) {
		$url = add_query_arg(
			array(
				'action' => 'gfb_confirm',
				's'      => (int) $submission_id,
				't'      => rawurlencode( (string) $token ),
			),
			admin_url( 'admin-post.php' )
		);
		if ( 0 === strpos( home_url(), 'https://' ) ) {
			$url = set_url_scheme( $url, 'https' );
		}
		return $url;
	}

	/**
	 * Route admin_post(_nopriv)_gfb_confirm.
	 * GET: datenfreie, idempotente Landeseite (kein State-Wechsel).
	 * POST: atomarer Compare-and-swap, dann Voll-Quittung + Betreiber-Mail 2.
	 * Ausgabe über Site-Editor-Template (WP 6.7+) oder die Minimalseite (Fallback).
	 *
	 * @return void
	 */
	public static function handle_confirm() {
		$switched = self::switch_mail_locale( '', 0 );

		$sid   = isset( $_REQUEST['s'] ) ? absint( $_REQUEST['s'] ) : 0;
		$token = isset( $_REQUEST['t'] ) ? (string) wp_unslash( $_REQUEST['t'] ) : '';
		// Roh-Token nie in Logs; hier nur Formprüfung (base64url, begrenzte Länge).
		$token = preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $token ) ? $token : '';

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		$state          = 'rejected';
		$receipt_result = '';

		// IP-Drosselung des Endpunkts (Chloe N-1): 20 Versuche/10 Minuten/IP.
		// Überschritten → dieselbe uniforme neutrale Antwort (kein Oracle).
		if ( self::confirm_rate_limited( GFB_Security::get_client_ip() ) ) {
			GFB_Security::log_event( 'doi_rate_limited', array( 'sid' => $sid ) );
		} elseif ( 'POST' !== $method ) {
			// Landeseite: neutral, zeigt nie Feldwerte, wechselt keinen Zustand.
			$state = 'landing';
		} else {
			if ( $sid > 0 && '' !== $token && self::verify_confirm_nonce( $sid ) ) {
				$confirm = self::do_confirm( $sid, $token );
				if ( false !== $confirm ) {
					$state          = 'success';
					$receipt_result = $confirm;
				}
			}
			if ( 'success' === $state ) {
				GFB_Audit::record( 'doi_confirmed', 'submission', (string) $sid, array() );
			} else {
				// Uniforme neutrale Meldung (kein Oracle: ungültig, abgelaufen und
				// schon benutzt sind nicht unterscheidbar). Audit ohne Roh-Token.
				GFB_Security::log_event( 'doi_reject', array( 'sid' => $sid ) );
				GFB_Audit::record( 'doi_rejected', 'submission', (string) $sid, array() );
			}
		}

		self::output_confirm_page( $state, $sid, ( 'landing' === $state ) ? $token : '', $receipt_result );

		if ( $switched ) {
			restore_previous_locale();
		}
		exit;
	}

	/**
	 * Gibt die Bestätigungsseite aus: bevorzugt über das im Site Editor
	 * anpassbare Template (User-Customization aus der DB überlagert den
	 * Plugin-Default – get_block_template-Pfad), sonst über die Minimalseite.
	 * Setzt vorher die Sicherheits-Header passend zum Ausgabeweg.
	 *
	 * @param string $state          landing|success|rejected.
	 * @param int    $sid            Zeilen-ID.
	 * @param string $token          Roh-Token (nur landing).
	 * @param string $receipt_result Übergabe-Status (nur success).
	 * @return void
	 */
	private static function output_confirm_page( $state, $sid, $token, $receipt_result ) {
		$template_id = ( 'landing' === $state )
			? 'gutenberg-formbuilder//gfb-confirm'
			: 'gutenberg-formbuilder//gfb-confirm-result';
		$template    = self::resolve_confirm_template( $template_id );

		self::confirm_security_headers( null !== $template );

		self::$confirm_ctx = array(
			'state'          => (string) $state,
			'sid'            => (int) $sid,
			'token'          => (string) $token,
			'receipt_result' => (string) $receipt_result,
		);

		if ( null !== $template ) {
			self::render_confirm_template_shell( $template );
		} else {
			self::render_confirm_page( $state, $sid, $token, $receipt_result );
		}

		self::$confirm_ctx = null;
	}

	/**
	 * Löst das Bestätigungs-Template über die WP-API auf. get_block_template()
	 * liefert die im Site Editor gespeicherte User-Customization (wp_template-
	 * Post) mit Vorrang vor dem per register_block_template() registrierten
	 * Plugin-Default. Ohne WP 6.7 (kein register_block_template) → null,
	 * die Minimalseite bleibt der Fallback.
	 *
	 * @param string $template_id z. B. gutenberg-formbuilder//gfb-confirm.
	 * @return object|null Template-Objekt mit content oder null.
	 */
	private static function resolve_confirm_template( $template_id ) {
		if ( ! function_exists( 'register_block_template' ) || ! function_exists( 'get_block_template' ) ) {
			return null;
		}
		$template = get_block_template( $template_id, 'wp_template' );
		if ( $template && ! empty( $template->content ) ) {
			return $template;
		}
		return null;
	}

	/**
	 * Rendert das aufgelöste Template in einem minimalen Gerüst mit wp_head()/
	 * wp_footer(), damit Theme-CSS und Global Styles greifen. Wie in Cores
	 * template-canvas.php wird der Inhalt VOR dem Kopf gerendert, damit von
	 * Blöcken enqueuete Styles im wp_head landen.
	 *
	 * @param object $template Template-Objekt mit content.
	 * @return void
	 */
	private static function render_confirm_template_shell( $template ) {
		$content  = do_blocks( (string) $template->content );
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		echo '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( __( 'Bestätigung', 'gutenberg-formbuilder' ) . ' – ' . $blogname ) . '</title>';
		wp_head();
		echo '</head><body class="gfb-confirm-template-page">';
		echo '<div class="wp-site-blocks">';
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks() eines registrierten Templates; PII kommt hier by design nie vor.
		echo '</div>';
		wp_footer();
		echo '</body></html>';
	}

	/**
	 * Registriert die zwei Landeseiten als Plugin-Templates für den Site Editor
	 * (WP 6.7+). Darunter fehlt register_block_template() – dann bleibt die
	 * eingebaute Minimalseite aktiv (Requires-Version wird nicht angehoben).
	 *
	 * @return void
	 */
	public static function register_confirm_templates() {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}
		register_block_template(
			'gutenberg-formbuilder//gfb-confirm',
			array(
				'title'       => __( 'Formular – Bestätigungsseite', 'gutenberg-formbuilder' ),
				'description' => __( 'Landeseite des Bestätigungslinks (Double-Opt-in): neutraler Text und Bestätigungs-Knopf. Feldwerte stehen hier by design nie zur Verfügung.', 'gutenberg-formbuilder' ),
				'content'     => self::confirm_template_content( 'landing' ),
			)
		);
		register_block_template(
			'gutenberg-formbuilder//gfb-confirm-result',
			array(
				'title'       => __( 'Formular – Bestätigungs-Ergebnis', 'gutenberg-formbuilder' ),
				'description' => __( 'Ergebnisseite nach dem Bestätigungs-Klick: erfasst oder uniform neutral abgelehnt (abgelaufen/ungültig/benutzt). Feldwerte stehen hier by design nie zur Verfügung.', 'gutenberg-formbuilder' ),
				'content'     => self::confirm_template_content( 'result' ),
			)
		);
	}

	/**
	 * Plugin-Default-Inhalt der Templates: schlanke, gestaltbare Struktur aus
	 * Core-Blöcken plus gfb/confirm-status. Markup entspricht exakt dem
	 * save-Output der Core-Blöcke (Block-Validität im Site Editor).
	 *
	 * @param string $which landing|result.
	 * @return string Block-Markup.
	 */
	private static function confirm_template_content( $which ) {
		if ( 'landing' === $which ) {
			$heading = __( 'E-Mail-Adresse bestätigen', 'gutenberg-formbuilder' );
			$intro   = __( 'Sie sind dem Bestätigungslink aus unserer E-Mail gefolgt.', 'gutenberg-formbuilder' );
		} else {
			$heading = __( 'Bestätigung', 'gutenberg-formbuilder' );
			$intro   = __( 'Das Ergebnis Ihrer Bestätigung:', 'gutenberg-formbuilder' );
		}

		return '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->'
			. '<main class="wp-block-group">'
			. '<!-- wp:site-logo {"width":96} /-->'
			. '<!-- wp:heading {"level":1} -->'
			. '<h1 class="wp-block-heading">' . esc_html( $heading ) . '</h1>'
			. '<!-- /wp:heading -->'
			. '<!-- wp:paragraph -->'
			. '<p>' . esc_html( $intro ) . '</p>'
			. '<!-- /wp:paragraph -->'
			. '<!-- wp:gfb/confirm-status /-->'
			. '</main>'
			. '<!-- /wp:group -->';
	}

	/**
	 * gfb/confirm-status im Beitrags-/Seiten-Editor aus dem Inserter nehmen –
	 * der Block ist nur in den zwei Bestätigungs-Templates sinnvoll. Im Site
	 * Editor (Template-Bearbeitung, context->post leer) bleibt er verfügbar.
	 * Bewusste Decke: Ist $allowed noch true, wird die Liste aus der Registry
	 * materialisiert; danach registrierte Blöcke fehlen dann im Inserter dieses
	 * Requests (in der Praxis registriert alles auf init vor dem Editor-Load).
	 *
	 * @param bool|array<int,string>  $allowed Bisheriger Filterwert.
	 * @param WP_Block_Editor_Context $context Editor-Kontext.
	 * @return bool|array<int,string>
	 */
	public static function restrict_confirm_status_inserter( $allowed, $context ) {
		if ( ! ( $context instanceof WP_Block_Editor_Context ) || empty( $context->post ) ) {
			return $allowed;
		}
		if ( true === $allowed ) {
			$allowed = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		}
		if ( is_array( $allowed ) ) {
			$allowed = array_values( array_diff( $allowed, array( 'gfb/confirm-status' ) ) );
		}
		return $allowed;
	}

	/**
	 * render_callback von gfb/confirm-status: rendert den Zustand des laufenden
	 * Bestätigungs-Requests (Formular mit Knopf + Nonce bzw. Statusmeldung).
	 * Ausserhalb der gfb_confirm-Route – etwa in normalem Seiteninhalt – gibt
	 * der Block '' aus. Feldwerte oder PII erscheinen hier nie.
	 *
	 * @return string
	 */
	public static function render_confirm_status_block() {
		if ( ! is_array( self::$confirm_ctx ) ) {
			return '';
		}
		return '<div class="gfb-confirm-status">' . self::confirm_status_inner_html( self::$confirm_ctx ) . '</div>';
	}

	/**
	 * IP-Drosselung des gfb_confirm-Endpunkts nach dem Muster
	 * GFB_Submit_Handler::is_rate_limited(): Zeitstempel-Liste im Transient,
	 * 20 Versuche pro 10 Minuten und IP.
	 *
	 * @param string $ip_address Client-IP (leer = keine Drosselung möglich).
	 * @return bool True, wenn gedrosselt.
	 */
	private static function confirm_rate_limited( $ip_address ) {
		if ( '' === $ip_address ) {
			return false;
		}

		$key        = 'gfb_confirm_rate_' . md5( (string) $ip_address );
		$window     = 10 * MINUTE_IN_SECONDS;
		$max_events = 20;

		$events = get_transient( $key );
		if ( ! is_array( $events ) ) {
			$events = array();
		}

		$cutoff = time() - $window;
		$events = array_values(
			array_filter(
				$events,
				static function ( $ts ) use ( $cutoff ) {
					return (int) $ts >= $cutoff;
				}
			)
		);

		if ( count( $events ) >= $max_events ) {
			return true;
		}

		$events[] = time();
		set_transient( $key, $events, $window );
		return false;
	}

	/**
	 * Eigener, aktionsgebundener Nonce der Bestätigungsseite (CSRF-Schutz des
	 * POST-Knopfs; der eigentliche Berechtigungsnachweis ist der Token selbst).
	 *
	 * @param int $sid Zeilen-ID.
	 * @return bool
	 */
	private static function verify_confirm_nonce( $sid ) {
		$nonce = isset( $_POST['gfb_confirm_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gfb_confirm_nonce'] ) ) : '';
		return (bool) wp_verify_nonce( $nonce, 'gfb_confirm_' . (int) $sid );
	}

	/**
	 * Atomarer Compare-and-swap (Einmalgebrauch, Ablauf serverseitig absolut)
	 * plus Versand von Voll-Quittung und Betreiber-Mail 2 bei Erfolg.
	 *
	 * @param int    $sid   Zeilen-ID.
	 * @param string $token Roh-Token.
	 * @return false|string false bei Ablehnung; sonst der Übergabe-Status der
	 *                      Voll-Quittung (handed_off|failed|skipped) für die
	 *                      wahrheitsgemässe Erfolgsseite.
	 */
	private static function do_confirm( $sid, $token ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gfb_submissions';
		$hash  = self::token_hash( $token );

		// Vorprüfung in konstanter Zeit (hash_equals); massgeblich bleibt der CAS.
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT confirm_token_hash FROM {$table} WHERE id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_string( $stored ) || '' === $stored || ! hash_equals( $stored, $hash ) ) {
			return false;
		}

		// Kein read-then-write: Zustandswechsel ausschliesslich über diesen
		// atomaren UPDATE; affected_rows === 1 ist der einzige Erfolgsbeweis.
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table}
				 SET confirm_status = 'confirmed', confirm_used_at = UTC_TIMESTAMP(), confirm_token_hash = ''
				 WHERE id = %d AND confirm_token_hash = %s AND confirm_token_hash <> ''
				   AND confirm_used_at IS NULL AND confirm_expires_at > UTC_TIMESTAMP()",
				$sid,
				$hash
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		// Trigger-Zeitpunkt für aufgeschobene Übermittlungen (Dritt-Plugins):
		// feuert genau EINMAL – der atomare CAS lässt nur den ersten Versuch
		// hierher (affected_rows === 1). Nach dem Statuswechsel, vor der
		// Voll-Quittung.
		$row_info = $wpdb->get_row( $wpdb->prepare( "SELECT post_id, form_id FROM {$table} WHERE id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * Erfolgreiche DOI-Bestätigung einer Einsendung.
		 *
		 * @param int    $submission_id Zeilen-ID.
		 * @param string $form_id       Formular-ID.
		 * @param int    $post_id       Post-ID.
		 */
		do_action(
			'gfb_doi_confirmed',
			(int) $sid,
			$row_info ? sanitize_key( (string) $row_info->form_id ) : '',
			$row_info ? (int) $row_info->post_id : 0
		);

		// Voll-Quittung + Betreiber-Mail 2 (best effort, Bestätigung gilt bereits).
		return self::send_full_receipt_after_confirm( $sid );
	}

	/**
	 * Nach bestätigter Adresse: Voll-Quittung an die Person (vertrauliche Werte
	 * jetzt im Klartext, E1/E4) und Betreiber-Mail 2 «jetzt bestätigt» (E9).
	 *
	 * @param int $sid Zeilen-ID.
	 * @return string Übergabe-Status der Voll-Quittung: handed_off|failed|skipped.
	 */
	private static function send_full_receipt_after_confirm( $sid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gfb_submissions';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $sid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return 'skipped';
		}

		$post_id = (int) $row->post_id;
		$form_id = sanitize_key( (string) $row->form_id );
		$payload = json_decode( (string) $row->payload, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$form_attrs = GFB_Plugin::get_form_block_attributes_from_post( $post_id, $form_id );
		$schema     = GFB_Plugin::get_form_schema_from_post( $post_id, $form_id );
		$field      = isset( $form_attrs['receiptEmailField'] ) ? sanitize_key( (string) $form_attrs['receiptEmailField'] ) : '';

		$recipient = self::recipient_from_stored_payload( $payload, $field );
		if ( '' === $recipient ) {
			self::audit_skip( $sid, $form_id, 'no_recipient' );
			return 'skipped';
		}

		// Auch die Quittung läuft durch das Send-Gate (Deckelung, fail-closed).
		$gate = self::gate_allows( $recipient );
		if ( true !== $gate ) {
			self::audit_skip( $sid, $form_id, 'gate_' . $gate );
			return 'skipped';
		}

		$ctx = self::build_context( 'full', $post_id, $form_id, $payload, $schema, array() );

		$sent = false;
		$switched = self::switch_mail_locale( $form_id, $post_id );

		$region  = GFB_Plugin::get_form_mail_region_blocks( $post_id, $form_id, 'gfb/receipt-mail' );
		$subject = self::build_subject(
			isset( $form_attrs['receiptSubject'] ) ? (string) $form_attrs['receiptSubject'] : '',
			sprintf(
				/* translators: %s: Name der Website */
				__( 'Ihre Einsendung bei %s ist bestätigt', 'gutenberg-formbuilder' ),
				wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
			),
			$ctx
		);
		$sent = self::send_mail( $recipient, $subject, $region, $ctx );

		// Betreiber-Mail 2: gleiche Benachrichtigungs-Mechanik wie Mail 1,
		// vertrauliche Werte bleiben dort «[verschlüsselt]» (E9).
		GFB_Submit_Handler::send_receipt_operator_mail(
			$post_id,
			$form_id,
			$payload,
			isset( $row->form_title ) ? (string) $row->form_title : '',
			$form_attrs,
			sprintf(
				/* translators: %d: Nummer der Einsendung */
				__( 'Status: jetzt bestätigt – die absendende Person hat die Kontrolle über ihr Postfach für Eintrag #%d nachgewiesen.', 'gutenberg-formbuilder' ),
				$sid
			)
		);

		if ( $switched ) {
			restore_previous_locale();
		}

		self::update_receipt_status( $sid, $sent ? 'handed_off' : 'handoff_failed' );
		GFB_Audit::record(
			$sent ? 'receipt_handed_off' : 'receipt_handoff_failed',
			'submission',
			(string) $sid,
			array(
				'form_id' => $form_id,
				'mode'    => 'full',
			)
		);

		return $sent ? 'handed_off' : 'failed';
	}

	/**
	 * Empfängeradresse aus dem gespeicherten Payload; verschlüsselte E-Mail-Felder
	 * werden hier – nach nachgewiesener Postfachkontrolle – entschlüsselt.
	 *
	 * @param array<string,mixed> $payload Gespeicherter Payload.
	 * @param string              $field   Feldname des E-Mail-Felds.
	 * @return string Gültige Adresse oder leer.
	 */
	private static function recipient_from_stored_payload( array $payload, $field ) {
		if ( '' === $field || ! isset( $payload[ $field ] ) ) {
			return '';
		}
		$value = $payload[ $field ];
		if ( GFB_Crypto::is_field_envelope( $value ) ) {
			$plain = GFB_Crypto::decrypt_field( $value, 'field:' . $field );
			$value = ( false === $plain ) ? '' : $plain;
		}
		$email = sanitize_email( (string) $value );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/**
	 * CSP der Bestätigungsseite, abhängig vom Ausgabeweg.
	 *
	 * Abwägung (bewusst, siehe Spec «Landeseiten als Site-Editor-Templates»):
	 * Die Minimalseite behält das strikte default-src 'none' (nur das eigene
	 * Inline-Stylesheet). Der Template-Weg braucht Theme-CSS, Global Styles
	 * und Theme-Assets – die Policy öffnet deshalb auf self-only. KEINE
	 * Remote-Hosts: zusammen mit Referrer-Policy no-referrer ist damit ein
	 * Token-Leck über nachgeladene Fremd-Ressourcen ausgeschlossen. Setzt der
	 * Betreiber ein Remote-Bild ins Template, lädt es bewusst nicht.
	 *
	 * @param bool $template_mode True, wenn über das Site-Editor-Template gerendert wird.
	 * @return string Policy-String.
	 */
	public static function confirm_csp_policy( $template_mode ) {
		if ( $template_mode ) {
			return "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; script-src 'self'; form-action 'self'";
		}
		return "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'";
	}

	/**
	 * Sicherheits-Header der Bestätigungsseite (Muster handle_download).
	 * Cache-Control no-store, Referrer-Policy no-referrer, X-Robots-Tag noindex
	 * und nosniff bleiben in beiden Ausgabewegen identisch; nur die CSP hängt
	 * vom Weg ab (confirm_csp_policy).
	 *
	 * @param bool $template_mode True, wenn über das Site-Editor-Template gerendert wird.
	 * @return void
	 */
	private static function confirm_security_headers( $template_mode = false ) {
		nocache_headers();
		header( 'Cache-Control: no-store' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: ' . self::confirm_csp_policy( $template_mode ) );
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	/**
	 * Datenfreie Bestätigungsseite: nur Knopf und neutrale Statusmeldung,
	 * nie Feldwerte (E8, Review-Beschluss).
	 *
	 * @param string $state          landing|success|rejected.
	 * @param int    $sid            Zeilen-ID (für das POST-Formular).
	 * @param string $token          Roh-Token (nur im landing-Zustand, wandert ins Formular).
	 * @param string $receipt_result Übergabe-Status der Voll-Quittung (nur success):
	 *                               handed_off|failed|skipped – die Seite behauptet
	 *                               nie «zugestellt» (Spec Abschnitt 7).
	 * @return void
	 */
	private static function render_confirm_page( $state, $sid, $token, $receipt_result = '' ) {
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$title    = __( 'Bestätigung', 'gutenberg-formbuilder' );

		echo '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $title . ' – ' . $blogname ) . '</title>';
		echo '<style>body{margin:0;padding:2rem 1rem;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1b2627;display:flex;justify-content:center}main{max-width:30rem;width:100%;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:2rem;box-sizing:border-box}h1,h2{font-size:1.25rem;margin:0 0 .75rem}p{margin:0 0 1rem;line-height:1.55}button{display:inline-block;min-height:44px;min-width:44px;padding:.7rem 1.4rem;font-size:1rem;border:0;border-radius:6px;background:#1b2627;color:#fff;cursor:pointer}button:focus-visible{outline:3px solid #4f94d4;outline-offset:2px}.gfb-site,.gfb-confirm-status__note{font-size:.85rem;color:#50575e;margin-top:1.25rem}</style>';
		echo '</head><body><main>';

		// Zustands-Markup aus der geteilten Quelle (identisch mit dem Block
		// gfb/confirm-status im Template-Weg – ein Wortlaut, zwei Ausgabewege).
		if ( 'landing' === $state ) {
			echo '<h1>' . esc_html__( 'E-Mail-Adresse bestätigen', 'gutenberg-formbuilder' ) . '</h1>';
		}
		echo self::confirm_status_inner_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped intern
			array(
				'state'          => (string) $state,
				'sid'            => (int) $sid,
				'token'          => (string) $token,
				'receipt_result' => (string) $receipt_result,
			)
		);

		echo '<p class="gfb-site">' . esc_html( $blogname ) . '</p>';
		echo '</main></body></html>';
	}

	/**
	 * Geteiltes Zustands-Markup der Bestätigungsseite: Landeseite (Formular mit
	 * Knopf + eigenem Nonce) bzw. Ergebnis-Meldung nach den bestehenden
	 * Wortlaut-Regeln (nie «zugestellt»). Wird vom Block gfb/confirm-status im
	 * Template-Weg UND von der Minimalseite genutzt – Feldwerte oder PII
	 * kommen hier by design nie vor. Der Knopf trägt die Core-Button-Klassen
	 * (wp-block-button__link wp-element-button), damit im Template-Weg die
	 * Theme-/Global-Styles-Knopfgestaltung greift.
	 *
	 * @param array<string,mixed> $ctx state, sid, token, receipt_result.
	 * @return string HTML (escaped).
	 */
	private static function confirm_status_inner_html( array $ctx ) {
		$state = isset( $ctx['state'] ) ? (string) $ctx['state'] : 'rejected';
		$sid   = isset( $ctx['sid'] ) ? (int) $ctx['sid'] : 0;

		if ( 'landing' === $state ) {
			$out  = '<p class="gfb-confirm-status__text">' . esc_html__( 'Mit einem Klick auf den Knopf bestätigen Sie, dass dieses E-Mail-Postfach Ihnen gehört. Erst danach erhalten Sie die vollständige Eingangsbestätigung per E-Mail.', 'gutenberg-formbuilder' ) . '</p>';
			$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gfb-confirm-status__form">';
			$out .= '<input type="hidden" name="action" value="gfb_confirm" />';
			$out .= '<input type="hidden" name="s" value="' . esc_attr( (string) $sid ) . '" />';
			$out .= '<input type="hidden" name="t" value="' . esc_attr( isset( $ctx['token'] ) ? (string) $ctx['token'] : '' ) . '" />';
			$out .= '<input type="hidden" name="gfb_confirm_nonce" value="' . esc_attr( wp_create_nonce( 'gfb_confirm_' . $sid ) ) . '" />';
			$out .= '<div class="wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button gfb-confirm-status__button">' . esc_html__( 'Jetzt bestätigen', 'gutenberg-formbuilder' ) . '</button></div>';
			$out .= '</form>';
			$out .= '<p class="gfb-confirm-status__note">' . esc_html__( 'Sie haben dieses Formular nicht ausgefüllt? Dann schliessen Sie diese Seite einfach – ohne Bestätigung passiert nichts.', 'gutenberg-formbuilder' ) . '</p>';
			return $out;
		}

		if ( 'success' === $state ) {
			$out  = '<h2 class="gfb-confirm-status__title">' . esc_html__( 'Vielen Dank – Adresse bestätigt', 'gutenberg-formbuilder' ) . '</h2>';
			$out .= '<p>' . esc_html__( 'Ihre Bestätigung ist erfasst.', 'gutenberg-formbuilder' ) . '</p>';
			// Wahrheitsgemäss nach tatsächlichem Übergabe-Status – die Seite
			// behauptet nie «zugestellt» (Spec Abschnitt 7).
			if ( 'handed_off' === ( isset( $ctx['receipt_result'] ) ? (string) $ctx['receipt_result'] : '' ) ) {
				$out .= '<p>' . esc_html__( 'Die Quittung wurde an Ihren Mailserver übergeben.', 'gutenberg-formbuilder' ) . '</p>';
			} else {
				$out .= '<p>' . esc_html__( 'Der Seitenbetreiber hat Ihre Meldung erhalten.', 'gutenberg-formbuilder' ) . '</p>';
			}
			return $out;
		}

		return '<h2 class="gfb-confirm-status__title">' . esc_html__( 'Bestätigung nicht möglich', 'gutenberg-formbuilder' ) . '</h2>'
			. '<p>' . esc_html__( 'Dieser Bestätigungslink ist ungültig, abgelaufen oder wurde bereits verwendet.', 'gutenberg-formbuilder' ) . '</p>';
	}

	/* ------------------------------------------------------------------ *
	 * Send-Gate (atomar, fail-closed)
	 * ------------------------------------------------------------------ */

	/**
	 * Prüft alle drei Deckel. Rückgabe true oder der Name der gerissenen Stufe.
	 *
	 * @param string $recipient Empfängeradresse.
	 * @return true|string true oder global|ip|recipient|error.
	 */
	private static function gate_allows( $recipient ) {
		$limits = self::gate_limits();
		$bucket = gmdate( 'YmdH' );

		// Globaler Site-Deckel: fail-closed – schlägt der Zähler fehl, kein Versand.
		$global = self::gate_increment( self::GATE_OPTION_PREFIX . $bucket . '_g' );
		if ( false === $global ) {
			return 'error';
		}
		if ( $global > $limits['global'] ) {
			return 'global';
		}

		$ip = GFB_Security::get_client_ip();
		if ( '' !== $ip ) {
			$ip_key = self::GATE_OPTION_PREFIX . $bucket . '_i_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) . '|gfb-gate' ), 0, 32 );
			$count  = self::gate_increment( $ip_key );
			if ( false === $count || $count > $limits['per_ip'] ) {
				return ( false === $count ) ? 'error' : 'ip';
			}
		}

		// Per-Empfänger nur ergänzend (über +Tag/Punkt-Varianten umgehbar);
		// Adresse vorher trim/lower normalisiert.
		$norm    = strtolower( trim( (string) $recipient ) );
		$rc_key  = self::GATE_OPTION_PREFIX . $bucket . '_r_' . substr( hash_hmac( 'sha256', $norm, wp_salt( 'auth' ) . '|gfb-gate' ), 0, 32 );
		$rc      = self::gate_increment( $rc_key );
		if ( false === $rc || $rc > $limits['per_recipient'] ) {
			return ( false === $rc ) ? 'error' : 'recipient';
		}

		return true;
	}

	/**
	 * Atomarer Zähler in wp_options (INSERT … ON DUPLICATE KEY UPDATE), bewusst
	 * am Options-API vorbei (kein Cache, autoload=no). Aufräumen im Cron.
	 *
	 * @param string $key Options-Name.
	 * @return int|false Neuer Zählerstand oder false (fail-closed beim Aufrufer).
	 */
	private static function gate_increment( $key ) {
		global $wpdb;
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')
				 ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$key
			)
		);
		if ( false === $ok ) {
			return false;
		}
		$val = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		return ( null === $val ) ? false : (int) $val;
	}

	/* ------------------------------------------------------------------ *
	 * Mail-Engine: Kontext, Versand, Multipart
	 * ------------------------------------------------------------------ */

	/**
	 * Render-Kontext für den Mail-Übersetzer.
	 *
	 * @param string               $mode     instant|full|doi.
	 * @param int                  $post_id  Post-ID.
	 * @param string               $form_id  Formular-ID.
	 * @param array<string,mixed>  $payload  Payload (inkl. _gfb_labels).
	 * @param array<int,array>     $schema   Formularschema (kann leer sein).
	 * @param array<string,string> $snapshot Klartext-Snapshot (nur zur Submit-Zeit).
	 * @return array<string,mixed>
	 */
	private static function build_context( $mode, $post_id, $form_id, array $payload, array $schema, array $snapshot ) {
		$labels = isset( $payload['_gfb_labels'] ) && is_array( $payload['_gfb_labels'] ) ? $payload['_gfb_labels'] : array();

		$sensitive = array();
		$types     = array();
		foreach ( $schema as $field ) {
			$n = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $n ) {
				continue;
			}
			$sensitive[ $n ] = ! empty( $field['sensitive'] );
			$types[ $n ]     = isset( $field['type'] ) ? (string) $field['type'] : '';
		}

		return array(
			'mode'        => $mode,
			'post_id'     => (int) $post_id,
			'form_id'     => (string) $form_id,
			'payload'     => $payload,
			'snapshot'    => $snapshot,
			'labels'      => $labels,
			'sensitive'   => $sensitive,
			'types'       => $types,
			'confirm_url' => '',
		);
	}

	/**
	 * Locale zur Absendezeit setzen (Filter gfb_receipt_locale).
	 *
	 * @param string $form_id Formular-ID (Kontext für den Filter).
	 * @param int    $post_id Post-ID.
	 * @return bool Ob gewechselt wurde (dann restore_previous_locale nötig).
	 */
	private static function switch_mail_locale( $form_id, $post_id ) {
		/**
		 * Sprache der Bestätigungsmail/Bestätigungsseite.
		 *
		 * @param string $locale  Default: determine_locale() zur Absendezeit.
		 * @param string $form_id Formular-ID.
		 * @param int    $post_id Post-ID.
		 */
		$locale = apply_filters( 'gfb_receipt_locale', determine_locale(), $form_id, $post_id );
		if ( ! is_string( $locale ) || '' === $locale || get_locale() === $locale ) {
			return false;
		}
		return (bool) switch_to_locale( $locale );
	}

	/**
	 * Feste, betreiber-eigene Absenderadresse (Blocker 1): noreply@site-domain,
	 * per Filter überschreibbar. Nie die feldgesteuerte emailFrom*-Logik.
	 *
	 * @param string $form_id Formular-ID (Kontext für die Filter).
	 * @return array{email:string,name:string,return_path:string}
	 */
	private static function resolve_from( $form_id ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? strtolower( $host ) : 'localhost';
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		$default = 'noreply@' . $host;
		/**
		 * From-Adresse der Bestätigungsmail (betreiber-eigene, ausrichtbare Domain).
		 *
		 * @param string $default noreply@site-domain.
		 * @param string $form_id Formular-ID.
		 */
		$email = apply_filters( 'gfb_receipt_from', $default, $form_id );
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			// Fallback wie wp_mail-Default (wordpress@site-domain).
			$email = 'wordpress@' . $host;
		}

		/**
		 * Anzeigename der Bestätigungsmail.
		 *
		 * @param string $name    Default: Blogname.
		 * @param string $form_id Formular-ID.
		 */
		$name = apply_filters( 'gfb_receipt_from_name', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), $form_id );
		$name = trim( preg_replace( "/[\r\n]+/", ' ', (string) $name ) );
		$name = mb_substr( wp_strip_all_tags( $name ), 0, 120 );

		/**
		 * Return-Path (Envelope-Sender) – überwachtes Postfach der From-Domain
		 * für SPF-Alignment und Bounce-Sichtbarkeit.
		 *
		 * @param string $email   Default: die From-Adresse.
		 * @param string $form_id Formular-ID.
		 */
		$return_path = apply_filters( 'gfb_receipt_return_path', $email, $form_id );
		$return_path = sanitize_email( (string) $return_path );
		if ( '' === $return_path || ! is_email( $return_path ) ) {
			$return_path = $email;
		}

		return array(
			'email'       => $email,
			'name'        => $name,
			'return_path' => $return_path,
		);
	}

	/**
	 * Betreff: eigene Vorlage mit Platzhaltern oder Standard; wie die
	 * Betreiber-Mail bereinigt (Tags, CRLF, Länge).
	 *
	 * @param string              $template Eigener Betreff (kann leer sein).
	 * @param string              $default  Standardbetreff (bereits übersetzt).
	 * @param array<string,mixed> $ctx      Render-Kontext.
	 * @return string
	 */
	private static function build_subject( $template, $default, array $ctx ) {
		$subject = trim( (string) $template );
		if ( '' === $subject ) {
			$subject = $default;
		} else {
			$subject = self::replace_placeholders_text( $subject, $ctx );
		}
		$subject = preg_replace( "/[\r\n]+/", ' ', (string) $subject );
		$subject = wp_strip_all_tags( (string) $subject );
		return mb_substr( (string) $subject, 0, 250 );
	}

	/**
	 * Versand als echtes Multipart (HTML + gespiegelte Textfassung) über
	 * wp_mail + phpmailer_init (AltBody, Return-Path, Auto-Submitted,
	 * Message-ID mit From-Domain). Hook wird sofort danach entfernt.
	 *
	 * @param string                   $to      Empfänger.
	 * @param string                   $subject Betreff.
	 * @param array<int,array>|null    $region  Geparste Inner-Blocks des Regionblocks oder null (Standard-Vorlage).
	 * @param array<string,mixed>      $ctx     Render-Kontext.
	 * @return bool Übergabe an den Mailserver (nie «zugestellt»).
	 */
	private static function send_mail( $to, $subject, $region, array $ctx ) {
		$blocks = is_array( $region ) && ! empty( $region ) ? $region : self::default_region_blocks( $ctx['mode'] );

		// DOI-Fallback: Die Link-Mail geht nie ohne Link raus – fehlt der
		// Platzhalter in allen Blöcken, wird er serverseitig angehängt.
		if ( 'doi' === $ctx['mode'] && ! self::blocks_contain_confirm_placeholder( $blocks ) ) {
			$blocks[] = array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerBlocks' => array(),
				'innerHTML'   => '<p>{{bestaetigungslink}}</p>',
			);
		}

		$built = self::build_mail( $blocks, $ctx );
		$from  = self::resolve_from( $ctx['form_id'] );

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? strtolower( $host ) : 'localhost';

		$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
		$headers[] = 'From: ' . ( '' !== $from['name'] ? sprintf( '%s <%s>', $from['name'], $from['email'] ) : $from['email'] );

		$alt_body    = $built['text'];
		$return_path = $from['return_path'];
		$mailer_cb   = static function ( $phpmailer ) use ( $alt_body, $return_path, $host ) {
			// AltBody macht die Mail automatisch multipart/alternative.
			$phpmailer->AltBody = $alt_body;
			// Return-Path (Envelope-Sender): SPF-Alignment + Bounce-Sichtbarkeit.
			$phpmailer->Sender = $return_path;
			// RFC 3834 gegen Autoresponder-Schleifen.
			$phpmailer->addCustomHeader( 'Auto-Submitted', 'auto-generated' );
			// Message-ID mit der From-Domain.
			$phpmailer->MessageID = '<gfb-' . md5( uniqid( (string) wp_rand(), true ) ) . '@' . $host . '>';
		};

		add_action( 'phpmailer_init', $mailer_cb );
		$sent = wp_mail( $to, $subject, $built['html'], $headers );
		remove_action( 'phpmailer_init', $mailer_cb );

		return (bool) $sent;
	}

	/* ------------------------------------------------------------------ *
	 * Block-zu-Mail-Übersetzer (HTML-Tabellenlayout + Plaintext)
	 * ------------------------------------------------------------------ */

	/**
	 * Übersetzt die Regionblöcke in mail-sicheres HTML (Tabellenlayout,
	 * Inline-CSS) plus gespiegelte Textfassung. do_blocks() ist hier bewusst
	 * tabu (Web-Markup, in Outlook/Gmail unbrauchbar).
	 *
	 * @param array<int,array>    $blocks Geparste Blöcke.
	 * @param array<string,mixed> $ctx    Render-Kontext.
	 * @return array{html:string,text:string}
	 */
	private static function build_mail( array $blocks, array $ctx ) {
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$branding = self::branding();

		$text_lines = array();
		$inner_html = self::render_blocks_for_mail( $blocks, $ctx, $text_lines );

		// Site-weiter Kopfbereich (Logo): entfällt ohne konfiguriertes Logo
		// vollständig (kein Leerraum). In der Textfassung entfällt das Logo.
		$header_default = '';
		if ( '' !== $branding['logo_src'] ) {
			$logo_img = '<img src="' . esc_url( $branding['logo_src'] ) . '" alt="' . esc_attr( $blogname ) . '" width="200" style="display:block;max-width:200px;height:auto;border:0;" />';
			if ( '' !== $branding['logo_link'] ) {
				$logo_img = '<a href="' . esc_url( $branding['logo_link'] ) . '">' . $logo_img . '</a>';
			}
			$header_default = '<tr><td align="center" style="padding:0 0 24px;">' . $logo_img . '</td></tr>';
		}
		/**
		 * Kopfbereich der Person-Mails (gerenderter Default, kann leer sein).
		 * Rückgabe ersetzt den Bereich vollständig.
		 *
		 * @param string              $header_default Gerendertes Tabellenzeilen-HTML.
		 * @param array<string,mixed> $ctx            Render-Kontext (mode, form_id, post_id …).
		 */
		$header_html = apply_filters( 'gfb_receipt_mail_header_html', $header_default, $ctx );
		if ( is_string( $header_html ) && '' !== $header_html ) {
			$inner_html = $header_html . $inner_html;
		}

		// Fussbereich: zuerst die Absender-Identität aus dem Branding, darunter
		// der neutrale, wahrheitsgemässe Schluss-Satz pro Modus (E3, anpassbar).
		// Die Identität ist doppelt kses-gefiltert (Speichern + branding()).
		$footer = self::footer_text( $ctx );

		$footer_cell = '';
		if ( '' !== $branding['footer_html'] ) {
			$footer_cell .= '<div style="padding:0 0 10px;">' . $branding['footer_html'] . '</div>';
		}
		if ( '' !== $footer ) {
			$footer_cell .= '<div>' . esc_html( $footer ) . '</div>';
		}
		$footer_default = '';
		if ( '' !== $footer_cell ) {
			$footer_default = '<tr><td style="padding:18px 0 0;border-top:1px solid #e2e4e7;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6c7378;">' . $footer_cell . '</td></tr>';
		}
		/**
		 * Fussbereich der Person-Mails (gerenderter Default: Identität +
		 * Schluss-Satz). Rückgabe ersetzt den Bereich vollständig.
		 *
		 * @param string              $footer_default Gerendertes Tabellenzeilen-HTML.
		 * @param array<string,mixed> $ctx            Render-Kontext (mode, form_id, post_id …).
		 */
		$footer_area = apply_filters( 'gfb_receipt_mail_footer_html', $footer_default, $ctx );
		if ( is_string( $footer_area ) && '' !== $footer_area ) {
			$inner_html .= $footer_area;
		}

		// Textfassung spiegelt den Fussbereich: Identität als Klartext
		// (Links als «Text: URL»), darunter der Schluss-Satz.
		if ( '' !== $branding['footer_html'] ) {
			$text_lines[] = '';
			$text_lines[] = self::footer_identity_to_text( $branding['footer_html'] );
		}
		if ( '' !== $footer ) {
			$text_lines[] = '';
			$text_lines[] = $footer;
		}

		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( $blogname ) . '</title></head>'
			. '<body style="margin:0;padding:0;background-color:#f4f5f6;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f6;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;">'
			. '<tr><td style="padding:32px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
			. $inner_html
			. '</table>'
			. '</td></tr></table>'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;"><tr><td style="padding:14px 8px;font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#8a9095;" align="center">' . esc_html( $blogname ) . '</td></tr></table>'
			. '</td></tr></table></body></html>';

		$text = trim( implode( "\n", $text_lines ) ) . "\n\n-- \n" . $blogname . "\n";

		return array(
			'html' => $html,
			'text' => $text,
		);
	}

	/**
	 * Rekursiver Walk über die Blöcke; pro Core-Blocktyp inline-gestyltes
	 * Tabellen-HTML plus parallele Plaintext-Zeilen.
	 *
	 * @param array<int,array>    $blocks     Geparste Blöcke.
	 * @param array<string,mixed> $ctx        Render-Kontext.
	 * @param array<int,string>   $text_lines Plaintext-Sammler (per Referenz).
	 * @return string HTML (Tabellenzeilen).
	 */
	private static function render_blocks_for_mail( array $blocks, array $ctx, array &$text_lines ) {
		$html = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$inner = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';

			switch ( $name ) {
				case 'core/paragraph':
					$content = self::prepare_inline_content( self::strip_outer_tag( $inner, 'p' ), $ctx );
					if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
						$html        .= '<tr><td style="padding:0 0 14px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1b2627;">' . $content . '</td></tr>';
						$text_lines[] = self::inline_content_to_text( $content );
						$text_lines[] = '';
					}
					break;

				case 'core/heading':
					$level   = isset( $attrs['level'] ) ? max( 1, min( 6, (int) $attrs['level'] ) ) : 2;
					$size    = ( $level <= 2 ) ? 22 : ( ( 3 === $level ) ? 18 : 16 );
					$content = self::prepare_inline_content( self::strip_outer_tag( $inner, 'h[1-6]' ), $ctx );
					if ( '' !== trim( wp_strip_all_tags( $content ) ) ) {
						$html        .= '<tr><td style="padding:6px 0 12px;font-family:Helvetica,Arial,sans-serif;font-size:' . (int) $size . 'px;line-height:1.35;font-weight:bold;color:#1b2627;">' . $content . '</td></tr>';
						$text_lines[] = self::inline_content_to_text( $content );
						$text_lines[] = str_repeat( '-', min( 40, max( 3, mb_strlen( self::inline_content_to_text( $content ) ) ) ) );
						$text_lines[] = '';
					}
					break;

				case 'core/list':
					$ordered = ! empty( $attrs['ordered'] ) || 0 === strpos( trim( $inner ), '<ol' );
					$items   = self::list_items_from_block( $block );
					if ( ! empty( $items ) ) {
						$li_html = '';
						$idx     = 1;
						foreach ( $items as $item ) {
							$content  = self::prepare_inline_content( $item, $ctx );
							$li_html .= '<li style="margin:0 0 6px;">' . $content . '</li>';
							$text_lines[] = ( $ordered ? $idx . '. ' : '- ' ) . self::inline_content_to_text( $content );
							++$idx;
						}
						$tag   = $ordered ? 'ol' : 'ul';
						$html .= '<tr><td style="padding:0 0 14px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1b2627;"><' . $tag . ' style="margin:0;padding:0 0 0 22px;">' . $li_html . '</' . $tag . '></td></tr>';
						$text_lines[] = '';
					}
					break;

				case 'core/image':
					$img = self::image_from_inner_html( $inner, $attrs );
					if ( '' !== $img['src'] ) {
						$html .= '<tr><td style="padding:0 0 16px;" align="center"><img src="' . esc_url( $img['src'] ) . '" alt="' . esc_attr( $img['alt'] ) . '" width="' . (int) $img['width'] . '" style="display:block;max-width:100%;height:auto;border:0;" /></td></tr>';
						if ( '' !== $img['alt'] ) {
							$text_lines[] = '[' . $img['alt'] . ']';
							$text_lines[] = '';
						}
					}
					break;

				case 'core/separator':
					$html        .= '<tr><td style="padding:8px 0 20px;"><div style="border-top:1px solid #e2e4e7;font-size:0;line-height:0;">&nbsp;</div></td></tr>';
					$text_lines[] = '----------------------------------------';
					$text_lines[] = '';
					break;

				case 'core/buttons':
					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$html .= self::render_blocks_for_mail( $block['innerBlocks'], $ctx, $text_lines );
					}
					break;

				case 'core/button':
					$btn = self::button_from_inner_html( $inner, $ctx );
					if ( '' !== $btn['label'] ) {
						if ( '' !== $btn['href'] ) {
							$html        .= '<tr><td style="padding:6px 0 20px;" align="center"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="border-radius:6px;background-color:#1b2627;" align="center"><a href="' . esc_url( $btn['href'] ) . '" style="display:inline-block;padding:13px 28px;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">' . esc_html( $btn['label'] ) . '</a></td></tr></table></td></tr>';
							$text_lines[] = $btn['label'] . ': ' . $btn['href'];
						} else {
							$html        .= '<tr><td style="padding:0 0 14px;font-family:Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1b2627;">' . esc_html( $btn['label'] ) . '</td></tr>';
							$text_lines[] = $btn['label'];
						}
						$text_lines[] = '';
					}
					break;

				case 'gfb/all-fields':
					$html .= self::render_all_fields_table( $ctx, $text_lines );
					break;

				default:
					// Unbekannte Blöcke: nur in Kinder absteigen, kein Fremd-Markup
					// in die Mail übernehmen (mail-sichere Strenge).
					if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
						$html .= self::render_blocks_for_mail( $block['innerBlocks'], $ctx, $text_lines );
					}
					break;
			}
		}
		return $html;
	}

	/**
	 * Daten-Tabelle «Alle Feldwerte» inkl. neutralem Disclaimer über den Werten
	 * und E1-Hinweisen (vertraulich/Datei).
	 *
	 * @param array<string,mixed> $ctx        Render-Kontext.
	 * @param array<int,string>   $text_lines Plaintext-Sammler (per Referenz).
	 * @return string HTML.
	 */
	private static function render_all_fields_table( array $ctx, array &$text_lines ) {
		// In der Link-Mail (doi) gibt es nie Feldwerte – Voll-Quittung erst nach Klick.
		if ( 'doi' === $ctx['mode'] ) {
			return '';
		}

		$disclaimer = sprintf(
			/* translators: %s: Name der Website */
			__( 'Diese Angaben wurden über ein Formular auf %s übermittelt:', 'gutenberg-formbuilder' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		);

		$rows_html    = '';
		$text_lines[] = $disclaimer;
		foreach ( $ctx['payload'] as $key => $raw ) {
			if ( 0 === strpos( (string) $key, '_gfb' ) ) {
				continue;
			}
			$label = isset( $ctx['labels'][ $key ] ) ? (string) $ctx['labels'][ $key ] : (string) $key;
			$label = mb_substr( wp_strip_all_tags( $label ), 0, 120 );
			$value = self::field_value_for_mail( (string) $key, $ctx );

			$rows_html   .= '<tr>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #eceef0;font-family:Helvetica,Arial,sans-serif;font-size:13px;color:#50575e;vertical-align:top;white-space:nowrap;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #eceef0;font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#1b2627;vertical-align:top;">' . nl2br( esc_html( $value ) ) . '</td>'
				. '</tr>';
			$text_lines[] = $label . ': ' . str_replace( "\n", "\n  ", $value );
		}
		$text_lines[] = '';

		return '<tr><td style="padding:4px 0 6px;font-family:Helvetica,Arial,sans-serif;font-size:12px;line-height:1.5;color:#6c7378;">' . esc_html( $disclaimer ) . '</td></tr>'
			. '<tr><td style="padding:0 0 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e4e7;border-radius:6px;">' . $rows_html . '</table></td></tr>';
	}

	/**
	 * Feldwert für die Mail, nach Modus (E1):
	 * - instant: vertrauliche Werte nur als Hinweis «vertraulich gespeichert».
	 * - full (nach DOI-Klick): vertrauliche Werte entschlüsselt.
	 * - doi (Link-Mail): keine Feldwerte.
	 * Dateien nie als Anhang – nur Dateiname + «verschlüsselt gespeichert».
	 *
	 * @param string              $key Feldname.
	 * @param array<string,mixed> $ctx Render-Kontext.
	 * @return string Reiner Text (Escaping übernimmt die Einbaustelle).
	 */
	private static function field_value_for_mail( $key, array $ctx ) {
		if ( 'doi' === $ctx['mode'] ) {
			return '';
		}
		$value = isset( $ctx['payload'][ $key ] ) ? $ctx['payload'][ $key ] : '';

		// Datei-Referenz: Dateiname + Hinweis, nie Inhalt.
		if ( is_array( $value ) && isset( $value['_ref'] ) && 0 === strpos( (string) $value['_ref'], 'gfb-file:' ) ) {
			$file_id = isset( $value['file_id'] ) ? (int) $value['file_id'] : 0;
			$file    = $file_id > 0 ? GFB_File_Storage::get( $file_id ) : null;
			$fname   = $file ? (string) $file->original_name : sprintf( __( 'Datei #%d', 'gutenberg-formbuilder' ), $file_id );
			return $fname . ' (' . __( 'verschlüsselt gespeichert', 'gutenberg-formbuilder' ) . ')';
		}

		$is_sensitive = ! empty( $ctx['sensitive'][ $key ] ) || GFB_Crypto::is_field_envelope( $value );
		if ( $is_sensitive ) {
			if ( 'full' === $ctx['mode'] ) {
				if ( GFB_Crypto::is_field_envelope( $value ) ) {
					$plain = GFB_Crypto::decrypt_field( $value, 'field:' . $key );
					$value = ( false === $plain ) ? __( 'vertraulich gespeichert', 'gutenberg-formbuilder' ) : $plain;
				} elseif ( isset( $ctx['snapshot'][ $key ] ) ) {
					$value = $ctx['snapshot'][ $key ];
				}
			} else {
				return __( 'vertraulich gespeichert', 'gutenberg-formbuilder' );
			}
		}

		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$value = (string) $value;

		// Checkbox: «1»/«0» lesbar machen.
		if ( isset( $ctx['types'][ $key ] ) && 'checkbox' === $ctx['types'][ $key ] ) {
			$value = ( '1' === $value ) ? __( 'Ja', 'gutenberg-formbuilder' ) : __( 'Nein', 'gutenberg-formbuilder' );
		}

		$value = wp_strip_all_tags( $value );
		$value = preg_replace( "/\r\n?/", "\n", $value );
		return mb_substr( $value, 0, 1000 );
	}

	/**
	 * Neutraler Schluss-Satz pro Modus, wahrheitsgemäss (E3, Abschnitt 10);
	 * per Filter gfb_receipt_footer_text anpassbar.
	 *
	 * @param array<string,mixed> $ctx Render-Kontext.
	 * @return string
	 */
	private static function footer_text( array $ctx ) {
		if ( 'doi' === $ctx['mode'] ) {
			$text = __( 'Sie haben dieses Formular nicht ausgefüllt? Dann ignorieren Sie diese E-Mail. Ohne Bestätigung gilt Ihre Adresse nicht als bestätigt, und die unbestätigte Einsendung wird nach einer festen Frist automatisch gelöscht.', 'gutenberg-formbuilder' );
		} else {
			$text = __( 'Sie haben dieses Formular nicht ausgefüllt? Dann hat jemand Ihre E-Mail-Adresse eingetragen. Die Einsendung liegt beim Betreiber dieser Website; Sie können dort jederzeit die Löschung verlangen – antworten Sie dazu auf diese E-Mail oder nutzen Sie die Kontaktangaben der Website.', 'gutenberg-formbuilder' );
		}
		/**
		 * Neutraler Schluss-Satz der Bestätigungsmail.
		 *
		 * @param string $text    Standardtext (übersetzt).
		 * @param string $mode    instant|full|doi.
		 * @param string $form_id Formular-ID.
		 */
		$text = apply_filters( 'gfb_receipt_footer_text', $text, $ctx['mode'], $ctx['form_id'] );
		return trim( wp_strip_all_tags( (string) $text ) );
	}

	/**
	 * Sanitisiert die Branding-Einstellungen (beim Speichern UND beim Rendern –
	 * Defense in Depth). Logo nur als Attachment-ID oder http(s)-URL;
	 * data:-URIs werden bewusst abgelehnt (Gmail zeigt Base64-Bilder nicht an,
	 * Spam-Signal). Footer nur über die schmale kses-Allowlist.
	 *
	 * @param array<string,mixed> $raw Rohwerte.
	 * @return array{logo_id:int,logo_url:string,logo_link:string,footer_text:string}
	 */
	public static function sanitize_branding( array $raw ) {
		return array(
			'logo_id'     => isset( $raw['logo_id'] ) ? absint( $raw['logo_id'] ) : 0,
			'logo_url'    => self::sanitize_branding_url( isset( $raw['logo_url'] ) ? (string) $raw['logo_url'] : '' ),
			'logo_link'   => self::sanitize_branding_url( isset( $raw['logo_link'] ) ? (string) $raw['logo_link'] : '' ),
			'footer_text' => self::branding_footer_kses( isset( $raw['footer_text'] ) ? (string) $raw['footer_text'] : '' ),
		);
	}

	/**
	 * Footer-Identität: kses-Allowlist NUR a[href], br, strong, b, em –
	 * alles andere fliegt raus. Links http(s)/mailto (Kontaktadresse).
	 *
	 * @param string $html Rohtext des Betreibers.
	 * @return string Gefiltertes HTML.
	 */
	public static function branding_footer_kses( $html ) {
		$allowed = array(
			'a'      => array( 'href' => true ),
			'br'     => array(),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
		);
		$kses = wp_kses( (string) $html, $allowed, array( 'http', 'https', 'mailto' ) );
		// Sicherheitsnetz gegen on*-Handler (Muster kses_mail_inline).
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', (string) $kses );
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*\'[^\']*\'/i', '', (string) $kses );
		return trim( (string) $kses );
	}

	/**
	 * URL-Sanitizer des Brandings: ausschliesslich absolute http(s)-URLs,
	 * alles andere (data:, javascript:, relativ) → leer.
	 *
	 * @param string $url Roh-URL.
	 * @return string
	 */
	private static function sanitize_branding_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$clean  = esc_url_raw( $url, array( 'http', 'https' ) );
		$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
		return ( '' !== $clean && in_array( $scheme, array( 'http', 'https' ), true ) ) ? $clean : '';
	}

	/**
	 * Aufgelöstes Branding zur Renderzeit: Logo-Quelle (Attachment-ID hat
	 * Vorrang vor der URL-Fallback-Angabe), Logo-Link, kses-gefilterte
	 * Footer-Identität.
	 *
	 * @return array{logo_src:string,logo_link:string,footer_html:string}
	 */
	private static function branding() {
		$raw = get_option( self::OPTION_BRANDING, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$clean = self::sanitize_branding( $raw );

		$logo_src = '';
		if ( $clean['logo_id'] > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			$attachment_url = wp_get_attachment_image_url( $clean['logo_id'], 'medium' );
			if ( is_string( $attachment_url ) ) {
				$logo_src = self::sanitize_branding_url( $attachment_url );
			}
		}
		if ( '' === $logo_src ) {
			$logo_src = $clean['logo_url'];
		}

		return array(
			'logo_src'    => $logo_src,
			'logo_link'   => $clean['logo_link'],
			'footer_html' => $clean['footer_text'],
		);
	}

	/**
	 * Footer-Identität als Klartext für die AltBody-Fassung:
	 * <br> → Zeilenumbruch, Links als «Text: URL», Rest Tags weg.
	 *
	 * @param string $html kses-gefiltertes Footer-HTML.
	 * @return string
	 */
	private static function footer_identity_to_text( $html ) {
		$text = preg_replace( '/<br\s*\/?>/i', "\n", (string) $html );
		$text = preg_replace_callback(
			'/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
			static function ( $m ) {
				$href  = html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( (string) $m[2] ) );
				if ( '' === $label || $label === $href ) {
					return $href;
				}
				return $label . ': ' . $href;
			},
			$text
		);
		$text = wp_strip_all_tags( (string) $text );
		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Eingebaute Standard-Vorlage bei leerem/fehlendem Container (übersetzt).
	 *
	 * @param string $mode instant|full|doi.
	 * @return array<int,array> Geparste Blöcke im parse_blocks-Format.
	 */
	private static function default_region_blocks( $mode ) {
		$p = static function ( $html ) {
			return array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerBlocks' => array(),
				'innerHTML'   => '<p>' . $html . '</p>',
			);
		};

		if ( 'doi' === $mode ) {
			return array(
				array(
					'blockName'   => 'core/heading',
					'attrs'       => array( 'level' => 2 ),
					'innerBlocks' => array(),
					'innerHTML'   => '<h2>' . esc_html__( 'Bitte bestätigen Sie Ihre E-Mail-Adresse', 'gutenberg-formbuilder' ) . '</h2>',
				),
				$p( esc_html__( 'Ihre Einsendung ist eingegangen. Bitte bestätigen Sie mit einem Klick, dass dieses E-Mail-Postfach Ihnen gehört – erst danach erhalten Sie die vollständige Eingangsbestätigung.', 'gutenberg-formbuilder' ) ),
				$p( '{{bestaetigungslink}}' ),
				$p( esc_html__( 'Der Link ist 7 Tage gültig und funktioniert nur einmal.', 'gutenberg-formbuilder' ) ),
			);
		}

		return array(
			array(
				'blockName'   => 'core/heading',
				'attrs'       => array( 'level' => 2 ),
				'innerBlocks' => array(),
				'innerHTML'   => '<h2>' . esc_html__( 'Ihre Einsendung ist eingegangen', 'gutenberg-formbuilder' ) . '</h2>',
			),
			$p( esc_html__( 'Vielen Dank. Diese E-Mail bestätigt den Eingang Ihrer Angaben:', 'gutenberg-formbuilder' ) ),
			array(
				'blockName'   => 'gfb/all-fields',
				'attrs'       => array(),
				'innerBlocks' => array(),
				'innerHTML'   => '',
			),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Escaping-Schicht + Platzhalter
	 * ------------------------------------------------------------------ */

	/**
	 * Mail-sichere Inline-Allowlist: kein Script, kein Style-Attribut, kein
	 * Remote-CSS, kein Hintergrundbild. Links nur http(s)/mailto.
	 *
	 * @param string $html Roh-Inline-HTML aus dem Blockinhalt.
	 * @return string
	 */
	private static function kses_mail_inline( $html ) {
		$allowed = array(
			'a'      => array(
				'href' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'u'      => array(),
			's'      => array(),
			'br'     => array(),
			'code'   => array(),
			'mark'   => array(),
			'sub'    => array(),
			'sup'    => array(),
			'span'   => array(),
		);
		$kses = wp_kses( (string) $html, $allowed, array( 'http', 'https', 'mailto' ) );
		// Sicherheitsnetz gegen on*-Handler (Muster ksesed_inner_blocks_html).
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', (string) $kses );
		$kses = preg_replace( '/\s+on[a-z]+\s*=\s*\'[^\']*\'/i', '', (string) $kses );
		return (string) $kses;
	}

	/**
	 * Inline-Inhalt vorbereiten: erst KSES-Allowlist, dann Platzhalter NUR in
	 * Textknoten ersetzen (Platzhalter in href/src sind verboten – Tags mit
	 * Platzhaltern werden komplett entfernt).
	 *
	 * @param string              $content Inline-HTML.
	 * @param array<string,mixed> $ctx     Render-Kontext.
	 * @return string Sicheres HTML.
	 */
	private static function prepare_inline_content( $content, array $ctx ) {
		$content = self::kses_mail_inline( $content );
		$parts   = preg_split( '/(<[^>]*>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return '';
		}
		$out = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				// Tag-Teil: Platzhalter in Attributen (href/src) sind verboten.
				if ( false !== strpos( $part, '{{' ) ) {
					continue;
				}
				$out .= $part;
				continue;
			}
			$out .= self::replace_placeholders_html( $part, $ctx );
		}
		return $out;
	}

	/**
	 * Platzhalter in einem Textknoten ersetzen; Werte werden esc_html-escaped.
	 * Der Bestätigungslink wird als klickbarer Link eingesetzt.
	 *
	 * @param string              $text Textknoten (kann Entities enthalten).
	 * @param array<string,mixed> $ctx  Render-Kontext.
	 * @return string
	 */
	private static function replace_placeholders_html( $text, array $ctx ) {
		return (string) preg_replace_callback(
			'/\{\{\s*(label_)?([a-z0-9_-]+)\s*\}\}/i',
			static function ( $m ) use ( $ctx ) {
				$is_label = ! empty( $m[1] );
				$key      = sanitize_key( (string) $m[2] );
				if ( '' === $key ) {
					return '';
				}
				if ( ! $is_label && 'bestaetigungslink' === $key ) {
					if ( '' === $ctx['confirm_url'] ) {
						return '';
					}
					return '<a href="' . esc_url( $ctx['confirm_url'] ) . '" style="color:#1b2627;">' . esc_html( $ctx['confirm_url'] ) . '</a>';
				}
				if ( $is_label ) {
					return isset( $ctx['labels'][ $key ] ) ? esc_html( mb_substr( wp_strip_all_tags( (string) $ctx['labels'][ $key ] ), 0, 120 ) ) : '';
				}
				return esc_html( self::field_value_for_mail( $key, $ctx ) );
			},
			(string) $text
		);
	}

	/**
	 * Platzhalter-Ersetzung für reine Textkontexte (Betreff, Plaintext).
	 *
	 * @param string              $text Vorlage.
	 * @param array<string,mixed> $ctx  Render-Kontext.
	 * @return string
	 */
	private static function replace_placeholders_text( $text, array $ctx ) {
		return (string) preg_replace_callback(
			'/\{\{\s*(label_)?([a-z0-9_-]+)\s*\}\}/i',
			static function ( $m ) use ( $ctx ) {
				$is_label = ! empty( $m[1] );
				$key      = sanitize_key( (string) $m[2] );
				if ( '' === $key ) {
					return '';
				}
				if ( ! $is_label && 'bestaetigungslink' === $key ) {
					return (string) $ctx['confirm_url'];
				}
				if ( $is_label ) {
					return isset( $ctx['labels'][ $key ] ) ? mb_substr( wp_strip_all_tags( (string) $ctx['labels'][ $key ] ), 0, 120 ) : '';
				}
				return mb_substr( self::field_value_for_mail( $key, $ctx ), 0, 120 );
			},
			(string) $text
		);
	}

	/**
	 * HTML-Inline-Inhalt in Plaintext spiegeln (für die AltBody-Fassung).
	 *
	 * @param string $html Bereits vorbereiteter Inline-Inhalt.
	 * @return string
	 */
	private static function inline_content_to_text( $html ) {
		$text = preg_replace( '/<br\s*\/?>/i', "\n", (string) $html );
		// Link-URLs sichtbar machen, wenn der Linktext nicht schon die URL ist.
		$text = preg_replace_callback(
			'/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is',
			static function ( $m ) {
				$href  = html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( (string) $m[2] ) );
				if ( '' === $label || $label === $href ) {
					return $href;
				}
				return $label . ' (' . $href . ')';
			},
			$text
		);
		$text = wp_strip_all_tags( (string) $text );
		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Block-Parsing-Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Entfernt den äusseren Tag eines Block-innerHTML (z. B. <p>…</p>).
	 *
	 * @param string $html        innerHTML.
	 * @param string $tag_pattern Regex-Fragment des Tags (p, h[1-6], li).
	 * @return string
	 */
	private static function strip_outer_tag( $html, $tag_pattern ) {
		$html = trim( (string) $html );
		$html = preg_replace( '/^<' . $tag_pattern . '[^>]*>/i', '', $html );
		$html = preg_replace( '/<\/' . $tag_pattern . '>\s*$/i', '', (string) $html );
		return trim( (string) $html );
	}

	/**
	 * Listenpunkte eines core/list: moderne Fassung über innerBlocks
	 * (core/list-item), Altbestand über <li>-Parsing des innerHTML.
	 *
	 * @param array<string,mixed> $block Geparster Block.
	 * @return array<int,string> Inline-HTML pro Punkt.
	 */
	private static function list_items_from_block( array $block ) {
		$items = array();
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child ) {
				if ( ! is_array( $child ) || 'core/list-item' !== ( $child['blockName'] ?? '' ) ) {
					continue;
				}
				$items[] = self::strip_outer_tag( isset( $child['innerHTML'] ) ? (string) $child['innerHTML'] : '', 'li' );
			}
		}
		if ( empty( $items ) ) {
			$inner = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
			if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $inner, $m ) ) {
				$items = $m[1];
			}
		}
		return $items;
	}

	/**
	 * Bild aus dem innerHTML eines core/image: absolute http(s)-URL Pflicht.
	 *
	 * @param string              $inner innerHTML.
	 * @param array<string,mixed> $attrs Block-Attribute.
	 * @return array{src:string,alt:string,width:int}
	 */
	private static function image_from_inner_html( $inner, array $attrs ) {
		$src = '';
		$alt = '';
		if ( preg_match( '/<img[^>]*\ssrc="([^"]+)"/i', (string) $inner, $m ) ) {
			$candidate = html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' );
			// Platzhalter in src verboten; nur absolute http(s)-URLs.
			if ( false === strpos( $candidate, '{{' ) ) {
				$clean  = esc_url_raw( $candidate, array( 'http', 'https' ) );
				$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
				if ( '' !== $clean && in_array( $scheme, array( 'http', 'https' ), true ) ) {
					$src = $clean;
				}
			}
		}
		if ( preg_match( '/<img[^>]*\salt="([^"]*)"/i', (string) $inner, $m2 ) ) {
			$alt = wp_strip_all_tags( html_entity_decode( (string) $m2[1], ENT_QUOTES, 'UTF-8' ) );
		}
		$width = isset( $attrs['width'] ) ? (int) $attrs['width'] : 0;
		if ( $width < 1 || $width > 560 ) {
			$width = 280;
		}
		return array(
			'src'   => $src,
			'alt'   => mb_substr( $alt, 0, 200 ),
			'width' => $width,
		);
	}

	/**
	 * Knopf aus dem innerHTML eines core/button. href darf nur der Platzhalter
	 * {{bestaetigungslink}} oder eine http(s)-URL sein; sonst fällt der Link weg.
	 *
	 * @param string              $inner innerHTML.
	 * @param array<string,mixed> $ctx   Render-Kontext.
	 * @return array{label:string,href:string}
	 */
	private static function button_from_inner_html( $inner, array $ctx ) {
		$label = '';
		$href  = '';
		if ( preg_match( '/<a[^>]*>(.*?)<\/a>/is', (string) $inner, $m ) ) {
			$label = trim( wp_strip_all_tags( html_entity_decode( (string) $m[1], ENT_QUOTES, 'UTF-8' ) ) );
		}
		if ( '' === $label ) {
			$label = trim( wp_strip_all_tags( (string) $inner ) );
		}
		$label = mb_substr( self::replace_placeholders_text( $label, $ctx ), 0, 120 );

		if ( preg_match( '/<a[^>]*\shref="([^"]*)"/i', (string) $inner, $m2 ) ) {
			$candidate = trim( html_entity_decode( (string) $m2[1], ENT_QUOTES, 'UTF-8' ) );
			if ( preg_match( '/^\{\{\s*bestaetigungslink\s*\}\}$/i', $candidate ) ) {
				$href = (string) $ctx['confirm_url'];
			} elseif ( false === strpos( $candidate, '{{' ) ) {
				$clean  = esc_url_raw( $candidate, array( 'http', 'https' ) );
				$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
				if ( '' !== $clean && in_array( $scheme, array( 'http', 'https' ), true ) ) {
					$href = $clean;
				}
			}
		}
		return array(
			'label' => $label,
			'href'  => $href,
		);
	}

	/**
	 * Kommt {{bestaetigungslink}} irgendwo in den Blöcken vor (Text oder href)?
	 *
	 * @param array<int,array> $blocks Geparste Blöcke.
	 * @return bool
	 */
	private static function blocks_contain_confirm_placeholder( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$inner = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
			if ( false !== stripos( $inner, '{{bestaetigungslink}}' ) || preg_match( '/\{\{\s*bestaetigungslink\s*\}\}/i', $inner ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && self::blocks_contain_confirm_placeholder( $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Status, Audit, Retention
	 * ------------------------------------------------------------------ */

	/**
	 * Versandstatus pro Einsendung («an Mailserver übergeben», nie «zugestellt»).
	 *
	 * @param int    $submission_id Zeilen-ID.
	 * @param string $status        handed_off|handoff_failed.
	 * @return void
	 */
	private static function update_receipt_status( $submission_id, $status ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'gfb_submissions',
			array(
				'receipt_mail_status' => ( 'handed_off' === $status ) ? 'handed_off' : 'handoff_failed',
				'receipt_mail_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $submission_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Audit-Eintrag für übersprungenen Versand – nur ID + Grund, keine PII.
	 *
	 * @param int    $submission_id Zeilen-ID.
	 * @param string $form_id       Formular-ID.
	 * @param string $reason        Grund-Slug.
	 * @return void
	 */
	private static function audit_skip( $submission_id, $form_id, $reason ) {
		GFB_Audit::record(
			'receipt_skipped',
			'submission',
			(string) $submission_id,
			array(
				'form_id' => $form_id,
				'reason'  => $reason,
			)
		);
	}

	/**
	 * Retention-Cron (täglich):
	 * 1. Nie bestätigte Einsendungen nach Frist löschen (inkl. Dateien).
	 * 2. Abgelaufene Token-Hashes sofort entwerten (nutzlos nach Ablauf).
	 * 3. Alte Gate-Zähler aus wp_options entfernen.
	 *
	 * @return void
	 */
	public static function cron_retention() {
		global $wpdb;
		$table = $wpdb->prefix . 'gfb_submissions';
		$days  = self::retention_days();

		// 1) Nie bestätigte Einsendungen nach Frist löschen.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE confirm_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY id ASC LIMIT 200",
				$days
			)
		);
		if ( is_array( $ids ) && ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$sid = (int) $id;
				GFB_File_Storage::delete_for_submission( $sid );
				$wpdb->delete( $table, array( 'id' => $sid ), array( '%d' ) );
				GFB_Audit::record(
					'receipt_retention_delete',
					'submission',
					(string) $sid,
					array( 'days' => $days )
				);
			}
		}

		// 2) Abgelaufene Token entwerten (Hash + Ablauf leeren, Status bleibt sichtbar).
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET confirm_token_hash = '', confirm_expires_at = NULL
			 WHERE confirm_token_hash <> '' AND confirm_expires_at IS NOT NULL AND confirm_expires_at < UTC_TIMESTAMP()"
		);

		// 3) Gate-Zähler älter als zwei Stunden entfernen (Buckets sind lexikalisch sortierbar).
		$cutoff = self::GATE_OPTION_PREFIX . gmdate( 'YmdH', time() - 2 * HOUR_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
				$wpdb->esc_like( self::GATE_OPTION_PREFIX ) . '%',
				$cutoff
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Vorschau + Testmail (Admin-Karte «Bestätigungsmail an Absender/innen»)
	 * ------------------------------------------------------------------ */

	/**
	 * Demo-Kontext für Vorschau und Testmail: eingebaute Standard-Vorlage plus
	 * statische, übersetzte Dummy-Feldwerte – nie echte Einsendungsdaten.
	 *
	 * @return array{blocks:array<int,array>,ctx:array<string,mixed>}
	 */
	private static function preview_context() {
		$labels = array(
			'vorname' => __( 'Vorname', 'gutenberg-formbuilder' ),
			'email'   => __( 'E-Mail', 'gutenberg-formbuilder' ),
		);
		$payload = array(
			'vorname'     => __( 'Muster', 'gutenberg-formbuilder' ),
			'email'       => 'muster@example.com',
			'_gfb_labels' => $labels,
		);
		$schema  = array(
			array(
				'name'  => 'vorname',
				'type'  => 'text',
				'label' => $labels['vorname'],
			),
			array(
				'name'  => 'email',
				'type'  => 'email',
				'label' => $labels['email'],
			),
		);
		$snapshot = array(
			'vorname' => (string) $payload['vorname'],
			'email'   => 'muster@example.com',
		);
		return array(
			'blocks' => self::default_region_blocks( 'instant' ),
			'ctx'    => self::build_context( 'instant', 0, 'gfb_preview', $payload, $schema, $snapshot ),
		);
	}

	/**
	 * Serverseitig gerenderte Vorschau der Person-Mail mit dem GESPEICHERTEN
	 * Branding (Logo-Header, Beispielinhalt, Footer-Identität, Schluss-Satz).
	 * Gleiche Quelle wie die Testmail – Vorschau und Versand sind identisch.
	 *
	 * @return array{html:string,text:string}
	 */
	public static function preview_mail_parts() {
		$demo  = self::preview_context();
		$parts = self::build_mail( $demo['blocks'], $demo['ctx'] );
		// Defensive Garantie (Härtung 21.07.2026): Rückgabe ist IMMER ein
		// vollständiges array{html,text} mit Strings. Ohne gespeicherte
		// Branding-Option greifen die Defaults (Standard-Vorlage ohne
		// Logo-/Footer-Bereich) – der Erstaufruf-Pfad ist Harness-belegt.
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}
		$parts['html'] = isset( $parts['html'] ) && is_string( $parts['html'] ) ? $parts['html'] : '';
		$parts['text'] = isset( $parts['text'] ) && is_string( $parts['text'] ) ? $parts['text'] : '';
		return $parts;
	}

	/**
	 * Testmail über den ECHTEN Versandpfad der Engine (build_mail +
	 * phpmailer_init → Multipart, From/Return-Path/Header wie produktiv).
	 * Läuft bewusst NICHT durchs Send-Gate (Admin-Aktion, verbraucht keine
	 * Deckel), aber mit milder eigener Drosselung gegen Versehen/Schleifen.
	 * Audit ohne Empfängeradresse (PII-Regel: nur Status).
	 *
	 * @param string $recipient Empfängeradresse.
	 * @return bool|WP_Error True/False = Übergabe an den Mailserver; WP_Error bei Ablehnung.
	 */
	public static function send_test_mail( $recipient ) {
		$recipient = sanitize_email( (string) $recipient );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return new WP_Error( 'gfb_test_recipient', __( 'Keine gültige Empfängeradresse – es wurde keine Testmail versendet.', 'gutenberg-formbuilder' ) );
		}
		if ( self::test_mail_rate_limited() ) {
			return new WP_Error( 'gfb_test_rate', __( 'Zu viele Testmails – bitte kurz warten (höchstens 5 pro 10 Minuten).', 'gutenberg-formbuilder' ) );
		}

		$switched = self::switch_mail_locale( 'gfb_preview', 0 );

		$demo    = self::preview_context();
		$subject = '[Test] ' . sprintf(
			/* translators: %s: Name der Website */
			__( 'Ihre Einsendung bei %s ist eingegangen', 'gutenberg-formbuilder' ),
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		);
		$sent = self::send_mail( $recipient, $subject, $demo['blocks'], $demo['ctx'] );

		if ( $switched ) {
			restore_previous_locale();
		}

		GFB_Audit::record(
			'receipt_test_mail',
			'config',
			'',
			array( 'result' => $sent ? 'handed_off' : 'handoff_failed' )
		);

		return (bool) $sent;
	}

	/**
	 * Milde Site-weite Drosselung der Testmail: 5 pro 10 Minuten
	 * (Zeitstempel-Transient, Muster confirm_rate_limited).
	 *
	 * @return bool True, wenn gedrosselt.
	 */
	private static function test_mail_rate_limited() {
		$key        = 'gfb_receipt_testmail_rate';
		$window     = 10 * MINUTE_IN_SECONDS;
		$max_events = 5;

		$events = get_transient( $key );
		if ( ! is_array( $events ) ) {
			$events = array();
		}
		$cutoff = time() - $window;
		$events = array_values(
			array_filter(
				$events,
				static function ( $ts ) use ( $cutoff ) {
					return (int) $ts >= $cutoff;
				}
			)
		);
		if ( count( $events ) >= $max_events ) {
			return true;
		}
		$events[] = time();
		set_transient( $key, $events, $window );
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Datenschutz-Textbaustein
	 * ------------------------------------------------------------------ */

	/**
	 * Kopierbarer Textbaustein für die Datenschutzerklärung: Autoresponder,
	 * Double-Opt-in, SMTP-Auftragsbearbeiter, Aufbewahrungsfrist, Token.
	 * Unverbindliche Vorlage, keine Rechtsberatung.
	 *
	 * @return string Reiner Text (keine HTML-Tags).
	 */
	public static function privacy_text_snippet() {
		return __(
			"Eingangsbestätigung per E-Mail (Autoresponder)\n\n"
			. "Wenn Sie eines unserer Formulare absenden, senden wir Ihnen auf Wunsch des Formulars eine Eingangsbestätigung an die von Ihnen angegebene E-Mail-Adresse. Die Bestätigung enthält die von Ihnen gemachten Angaben; als vertraulich gekennzeichnete Angaben werden darin nur als Hinweis «vertraulich gespeichert» aufgeführt und erst nach bestätigter E-Mail-Adresse im Klartext zugestellt.\n\n"
			. "Bestätigungslink (Double-Opt-in). Bei Formularen mit Bestätigungslink erhalten Sie zunächst nur eine E-Mail mit einem Link. Mit dem Klick auf den Bestätigungsknopf weisen Sie die Kontrolle über Ihr Postfach nach; der Link ist 7 Tage gültig und funktioniert nur einmal. Der Klick belegt die Postfachkontrolle, er ist keine rechtsverbindliche Willenserklärung. Technisch speichern wir dafür keinen Klartext-Link, sondern nur einen kryptografischen Prüfwert (Hash).\n\n"
			. "Aufbewahrung unbestätigter Einsendungen. Einsendungen, deren E-Mail-Adresse nicht bestätigt wird, löschen wir automatisch nach [Frist gemäss Konfiguration, Standard: 45 Tage].\n\n"
			. "Rechtsgrundlage. Die Zustellung der Eingangsbestätigung stützt sich auf die Durchführung vorvertraglicher bzw. vertraglicher Massnahmen oder unser berechtigtes Interesse an einer nachvollziehbaren Kommunikation (Art. 6 Abs. 1 lit. b bzw. f DSGVO; für die Schweiz Art. 31 revDSG).\n\n"
			. "E-Mail-Versand als Auftragsbearbeitung. Für den Versand nutzen wir den E-Mail-Dienst unseres Hostings bzw. einen SMTP-Dienstleister: [Anbieter, Sitz]. Mit diesem besteht ein Auftragsverarbeitungsvertrag (Art. 28 DSGVO bzw. Art. 9 revDSG).\n\n"
			. "Missbrauchsmeldung. Haben Sie das Formular nicht selbst ausgefüllt, können Sie uns dies formlos melden; wir löschen die Einsendung.\n\n"
			. "(Unverbindliche Vorlage, keine Rechtsberatung. Bitte an Ihre konkrete Situation anpassen und im Zweifel rechtlich prüfen lassen.)",
			'gutenberg-formbuilder'
		);
	}
}
