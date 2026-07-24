<?php
/**
 * CAPTCHA-Integration (nur Friendly Captcha).
 *
 * Kapselt die gesamte CAPTCHA-Logik schlank in einer Klasse, analog zum
 * GFB_Clamav-Muster. Ein Anbieter, eine Klasse. Ein zweiter Anbieter liesse
 * sich später hinter render_widget()/verify() nachruesten, ohne den
 * Submit-Fluss in GFB_Submit_Handler::handle() umzubauen.
 *
 * Verantwortlich fuer:
 *  - get_settings()/update_settings(): Persistenz im Options-Blob
 *    `gfb_captcha_settings` (analog ClamAV-Settings-Muster).
 *  - is_active_for_form(): Wirksamkeit pro Formular (global + Block-Attribut).
 *  - render_widget(): serverseitiger Widget-Container im Formular (nur Site-Key).
 *  - verify(): serverseitige Token-Pruefung gegen den Friendly-Captcha
 *    siteverify-Endpoint (v2), eingehaengt als letzte Stufe der Abwehrkette.
 *
 * Datenschutz: nie eager laden, kein Vorab-Ping, nur Site-Key im Frontend,
 * Secret/API-Key bleibt serverseitig. Lazy-Load uebernimmt assets/captcha.js
 * (Skript erst nach erster Formular-Interaktion – verzoegertes Laden zur
 * Datensparsamkeit).
 *
 * siteverify-Quelle (verifiziert gegen die offizielle Anbieter-Doku):
 *   https://developer.friendlycaptcha.com/docs/v2/getting-started/verify
 *   Endpoint: POST https://global.frcapi.com/api/v2/captcha/siteverify
 *   Auth:     Header X-API-Key: <API-Key>
 *   Body:     JSON { response, sitekey } (sitekey optional, hier gebunden)
 *   Antwort:  JSON mit Feld `success` (bool). HTTP 200 heisst nur, dass die
 *             Verifikation lief – nicht, dass das Token gueltig war. Immer
 *             `success` auswerten.
 *   Widget (konsistent v2):
 *   https://developer.friendlycaptcha.com/docs/v2/getting-started/install
 *   Script: https://cdn.jsdelivr.net/npm/@friendlycaptcha/sdk@<ver>/site.min.js
 *   Element: <div class="frc-captcha" data-sitekey="<sitekey>">
 *   Response-Feld: das Widget legt automatisch `frc-captcha-response` ins Form.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Captcha {

	/**
	 * Options-Blob mit der gesamten CAPTCHA-Konfiguration.
	 */
	const OPTION_KEY = 'gfb_captcha_settings';

	/**
	 * Fester Anbieter-Slug. Es gibt nur einen Anbieter; das Feld dient allein
	 * der Vorbereitung eines spaeteren zweiten Anbieters, ohne hier zu verzweigen.
	 */
	const PROVIDER = 'friendly';

	/**
	 * Offizieller Friendly-Captcha siteverify-Endpoint (v2). Quelle siehe
	 * Klassen-Doc. Bewusst als Konstante, damit er an genau einer Stelle steht.
	 */
	const SITEVERIFY_URL = 'https://global.frcapi.com/api/v2/captcha/siteverify';

	/**
	 * Gepinnte SDK-Version fuer das Frontend-Widget (jsDelivr). Pinning statt
	 * @latest fuer reproduzierbares Verhalten.
	 */
	const SDK_VERSION = '1.0.0';

	/**
	 * Name des versteckten Response-Felds, das das Friendly-Captcha-Widget
	 * automatisch in das umgebende <form> legt (dokumentierter Standard).
	 */
	const RESPONSE_FIELD = 'frc-captcha-response';

	/**
	 * Liefert die vollstaendige siteverify-Skript-URL (CDN, gepinnt).
	 *
	 * @return string
	 */
	public static function widget_script_url() {
		return 'https://cdn.jsdelivr.net/npm/@friendlycaptcha/sdk@' . self::SDK_VERSION . '/site.min.js';
	}

	/**
	 * @return array{enabled:bool,site_key:string,api_key:string,mode:string,provider:string}
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'  => false,
			'site_key' => '',
			'api_key'  => '',
			// Erzwingungsmodus: 'soft' (Default) verlangt ein bestandenes Captcha,
			// laesst aber bei nicht erreichbarem Anbieter durch (Ausfallsicherung).
			// 'strict' lehnt auch bei gestoertem Anbieter ab (fail-closed).
			'mode'      => 'soft',
			'provider'  => self::PROVIDER,
			// Eigener Hinweistext unter dem Widget; leer = eingebauter
			// Standardtext (uebersetzt), siehe hint_text().
			'hint_text' => '',
		);
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$merged              = array_merge( $defaults, $opt );
		$merged['enabled']   = ! empty( $merged['enabled'] );
		$merged['provider']  = self::PROVIDER;
		$merged['mode']      = ( 'strict' === $merged['mode'] ) ? 'strict' : 'soft';
		$merged['hint_text'] = is_string( $merged['hint_text'] ) ? $merged['hint_text'] : '';
		return $merged;
	}

	/**
	 * Speichert die Konfiguration. Secrets werden getrimmt, nie geloggt.
	 *
	 * @param array<string,mixed> $values Rohwerte aus dem Admin-Formular.
	 * @return void
	 */
	public static function update_settings( array $values ) {
		$cur = self::get_settings();
		$new = array(
			'enabled'   => ! empty( $values['enabled'] ),
			'site_key'  => self::sanitize_key_string( (string) ( $values['site_key'] ?? $cur['site_key'] ) ),
			'api_key'   => self::sanitize_key_string( (string) ( $values['api_key'] ?? $cur['api_key'] ) ),
			'mode'      => ( ( $values['mode'] ?? $cur['mode'] ) === 'strict' ) ? 'strict' : 'soft',
			'provider'  => self::PROVIDER,
			'hint_text' => mb_substr( sanitize_text_field( (string) ( $values['hint_text'] ?? $cur['hint_text'] ) ), 0, 300 ),
		);
		update_option( self::OPTION_KEY, $new, false );
	}

	/**
	 * Konservative Saeuberung fuer Key-Strings (Site-Key/API-Key). Friendly
	 * Captcha nutzt URL-sichere Zeichen; wir lassen nur diese durch.
	 *
	 * @param string $v Rohwert.
	 * @return string
	 */
	private static function sanitize_key_string( $v ) {
		$v = trim( wp_strip_all_tags( (string) $v ) );
		if ( '' === $v ) {
			return '';
		}
		// Erlaubt: Buchstaben, Ziffern und . _ - (typische Key-/JWT-Zeichen).
		$v = preg_replace( '/[^A-Za-z0-9._\-]/', '', $v );
		return (string) mb_substr( (string) $v, 0, 512 );
	}

	/**
	 * Global aktiv UND Keys vollstaendig? Nur dann darf ein Widget erscheinen.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = self::get_settings();
		return $s['enabled'] && '' !== $s['site_key'] && '' !== $s['api_key'];
	}

	/**
	 * Beide Schluessel vollstaendig gesetzt – unabhaengig von der globalen
	 * Ja/Nein-Schaltung. Basis fuer das intuitive Override (captchaMode = on).
	 *
	 * @return bool
	 */
	public static function has_keys() {
		$s = self::get_settings();
		return '' !== $s['site_key'] && '' !== $s['api_key'];
	}

	/**
	 * Global aktiv, aber unvollstaendig konfiguriert (fuer A5-Warnung).
	 *
	 * @return bool
	 */
	public static function is_enabled_but_incomplete() {
		$s = self::get_settings();
		return $s['enabled'] && ( '' === $s['site_key'] || '' === $s['api_key'] );
	}

	/**
	 * Entscheidet die Wirksamkeit pro Formular (A11, intuitives Override):
	 *   - off     -> nie (vor jeder weiteren Pruefung).
	 *   - on      -> greift, sobald beide Schluessel gesetzt sind (has_keys()),
	 *                unabhaengig von der globalen Ja/Nein-Schaltung.
	 *   - inherit -> folgt der globalen Schaltung (is_configured(): global «Ja»
	 *                UND beide Schluessel gesetzt).
	 *
	 * Fehlen die Keys, greift nichts – auch nicht «on». Wahrheitstabelle:
	 *   global Ja  + Keys ja  : inherit ×, on ×
	 *   global Ja  + Keys nein: nichts
	 *   global Nein+ Keys ja  : on ×, inherit –
	 *   global Nein+ Keys nein: nichts
	 *
	 * @param array<string,mixed> $form_attrs gfb/form-Block-Attribute.
	 * @return bool
	 */
	public static function is_active_for_form( array $form_attrs ) {
		$mode = isset( $form_attrs['captchaMode'] ) ? (string) $form_attrs['captchaMode'] : 'inherit';
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'on' === $mode ) {
			// Intuitives Override: erzwingt CAPTCHA, sobald die Keys vollstaendig
			// sind – auch wenn die globale Schaltung auf «Nein» steht.
			return self::has_keys();
		}
		// 'inherit': folgt der globalen Schaltung inkl. vollstaendiger Keys.
		return self::is_configured();
	}

	/**
	 * Rendert den Widget-Container (serverseitig). Gibt nur den Site-Key aus,
	 * niemals das Secret. Das Skript wird NICHT hier geladen – das uebernimmt
	 * assets/captcha.js erst nach der ersten Formular-Interaktion (verzoegertes
	 * Laden zur Datensparsamkeit, kein eager).
	 *
	 * Wirksamkeit pro Formular entscheidet allein is_active_for_form() an der
	 * Aufrufstelle (render_form_block). render_widget() trifft KEINE eigene
	 * Wirksamkeitsentscheidung mehr – sonst divergierte die Logik (eine eigene
	 * is_configured()-Guard wuerde «global Nein + on» faelschlich blocken,
	 * obwohl is_active_for_form() das Formular korrekt erzwingt). Zulaessig
	 * bleibt nur eine technische Sicherung gegen leere Keys: ohne Site-Key
	 * gibt es kein sinnvolles Widget.
	 *
	 * @param string $instance_id Form-Instanz (fuer eindeutige IDs/aria).
	 * @return string HTML.
	 */
	/**
	 * Hinweistext unter dem Captcha-Widget: eigener Backend-Text (Einstellung
	 * hint_text) oder der eingebaute, übersetzte Standardtext. Ein eigener
	 * Text gilt unverändert für alle Sprachen.
	 *
	 * @param string $form_id Formular-ID (Kontext für den Filter).
	 * @return string Reiner Text (Escaping übernimmt die Ausgabestelle).
	 */
	public static function hint_text( $form_id = '' ) {
		$s    = self::get_settings();
		$text = '' !== trim( (string) $s['hint_text'] )
			? (string) $s['hint_text']
			: __( 'Bitte den Spam-Schutz abschliessen, bevor du das Formular absendest.', 'gutenberg-formbuilder' );
		/**
		 * Hinweistext unter dem Captcha (Code-Override).
		 *
		 * @param string $text    Text aus Einstellung bzw. Standard.
		 * @param string $form_id Formular-ID.
		 */
		return (string) apply_filters( 'gfb_captcha_hint_text', $text, $form_id );
	}

	public static function render_widget( $instance_id = '0', $form_id = '' ) {
		// Reine Keys-Sicherung (kein Wirksamkeits-Guard): ohne Site-Key laesst
		// sich kein Widget rendern. Die Wirksamkeit hat die Aufrufstelle bereits
		// ueber is_active_for_form() entschieden.
		if ( ! self::has_keys() ) {
			return '';
		}
		$s         = self::get_settings();
		$dom_id    = 'gfb-captcha-' . preg_replace( '/[^a-z0-9_\-]/i', '', (string) $instance_id );
		$label     = __( 'Spam-Schutz', 'gutenberg-formbuilder' );
		$hint      = self::hint_text( $form_id );

		ob_start();
		?>
		<div class="gfb-captcha-row" data-gfb-captcha="1">
			<span class="gfb-captcha-label" id="<?php echo esc_attr( $dom_id ); ?>-label"><?php echo esc_html( $label ); ?></span>
			<div
				class="frc-captcha gfb-captcha-widget"
				data-sitekey="<?php echo esc_attr( $s['site_key'] ); ?>"
				role="group"
				aria-labelledby="<?php echo esc_attr( $dom_id ); ?>-label"
			></div>
			<p class="gfb-captcha-hint" id="<?php echo esc_attr( $dom_id ); ?>-hint"><?php echo esc_html( $hint ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Serverseitige Token-Verifikation gegen den Friendly-Captcha
	 * siteverify-Endpoint (v2). Sendet ausschliesslich, was technisch nötig
	 * ist: das Token (`response`) und den Site-Key (`sitekey`, zur Bindung).
	 * Keine Formularinhalte. Timeout <= 5 s (D7).
	 *
	 * @param string $token      Vom Frontend geliefertes CAPTCHA-Token.
	 * @param string $ip_address Optionale Remote-IP (derzeit nicht uebertragen,
	 *                           da v2-siteverify sie nicht verlangt).
	 * @return array{result:string,detail:string} result: pass|fail|unreachable
	 */
	public static function verify( $token, $ip_address = '' ) {
		$s = self::get_settings();
		if ( '' === $s['api_key'] || '' === $s['site_key'] ) {
			// Ohne vollstaendige Keys gilt CAPTCHA als nicht anwendbar.
			return array( 'result' => 'unreachable', 'detail' => 'not_configured' );
		}
		$token = is_string( $token ) ? trim( $token ) : '';
		if ( '' === $token ) {
			return array( 'result' => 'fail', 'detail' => 'response_missing' );
		}

		$body = array(
			'response' => $token,
			'sitekey'  => $s['site_key'],
		);

		$response = wp_remote_post(
			self::SITEVERIFY_URL,
			array(
				'timeout' => 5,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'X-API-Key'    => $s['api_key'],
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'result' => 'unreachable', 'detail' => 'transport_error' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		// HTTP 200 heisst nur: Verifikation lief. Massgeblich ist `success`.
		if ( 200 === $code && is_array( $data ) ) {
			if ( ! empty( $data['success'] ) ) {
				return array( 'result' => 'pass', 'detail' => '' );
			}
			// Gueltige Antwort, aber Token ungueltig/abgelaufen/dupliziert.
			$err = '';
			if ( isset( $data['error']['error_code'] ) ) {
				$err = sanitize_key( (string) $data['error']['error_code'] );
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$err = sanitize_key( $data['error'] );
			}
			return array( 'result' => 'fail', 'detail' => $err !== '' ? $err : 'response_invalid' );
		}

		// 401/400: Server-Konfigurationsfehler (Key falsch o. Aehnliches) ODER
		// fachliche Ablehnung. 401 (auth) behandeln wir als unreachable, damit
		// ein Key-Fehler im soft-Modus nicht jeden Submit blockt; 400-Fachfehler
		// (response_*) zaehlen als fail.
		if ( 401 === $code ) {
			return array( 'result' => 'unreachable', 'detail' => 'auth_error' );
		}
		if ( 400 === $code && is_array( $data ) ) {
			$err = '';
			if ( isset( $data['error']['error_code'] ) ) {
				$err = sanitize_key( (string) $data['error']['error_code'] );
			}
			// sitekey_invalid ist ein Konfig-Fehler -> unreachable (nicht den
			// Endnutzer bestrafen). response_*-Fehler sind echte Token-Fehler.
			if ( 'sitekey_invalid' === $err ) {
				return array( 'result' => 'unreachable', 'detail' => $err );
			}
			return array( 'result' => 'fail', 'detail' => $err !== '' ? $err : 'bad_request' );
		}

		return array( 'result' => 'unreachable', 'detail' => 'http_' . $code );
	}

	/**
	 * Kopierbarer Datenschutz-Textbaustein für die Datenschutzerklärung
	 * (E-neu.1, löst die schlanke Drei-Punkte-Fassung aus E5 ab). Trägt alle
	 * elf Pflicht-Inhalte für den Standardbetrieb ohne Einwilligung (EN2).
	 *
	 * Platzhalter `[…]` (Firmenbezeichnung/Anschrift, konkrete Speicherdauer)
	 * bleiben bewusst leer – das Plugin erfindet weder Adresse noch Dauer (EN3).
	 * Sie werden vom Betreiber aus AVV bzw. Anbieter-Doku ergänzt.
	 *
	 * Unverbindliche Vorlage, keine Rechtsberatung.
	 *
	 * @return string Reiner Text (keine HTML-Tags).
	 */
	public static function privacy_text_snippet() {
		return __(
			"Spam-Schutz durch Friendly Captcha\n\n"
			. "Wir setzen auf unseren Formularen den Dienst Friendly Captcha ein, einen Dienst der Friendly Captcha GmbH, Deutschland. [Firmenbezeichnung und Adresse]\n\n"
			. "Zweck. Friendly Captcha schützt unsere Formulare vor Spam und automatisiertem Missbrauch (etwa durch Bots).\n\n"
			. "Verarbeitete Daten. Verarbeitet werden Ihre IP-Adresse sowie technische Angaben Ihres Geräts, die für den Berechnungsnachweis (Proof-of-Work) nötig sind. Friendly Captcha setzt dabei keine Cookies, nutzt kein Fingerprinting und kein Tracking.\n\n"
			. "Funktionsweise. Statt Ihr Verhalten zu beobachten, lässt Friendly Captcha Ihren Browser im Hintergrund eine kleine Rechenaufgabe lösen (Proof-of-Work). Diese Aufgabe ist für Menschen unbemerkbar, für massenhaft automatisierte Anfragen aber aufwendig – so werden Bots ausgebremst, ohne dass Sie verfolgt werden.\n\n"
			. "Rechtsgrundlage. Die Verarbeitung stützt sich auf unser berechtigtes Interesse am Schutz unserer Formulare vor Missbrauch (Art. 6 Abs. 1 lit. f DSGVO; für die Schweiz Art. 31 revDSG).\n\n"
			. "Speicherort. Die Verarbeitung erfolgt auf Servern in der Europäischen Union. Eine Übermittlung in Drittländer findet nicht statt.\n\n"
			. "Auftragsverarbeitung. Mit der Friendly Captcha GmbH besteht ein Auftragsverarbeitungsvertrag nach Art. 28 DSGVO (bzw. Art. 9 revDSG).\n\n"
			. "Speicherdauer. Die Daten werden nur zur Verifikation der Anfrage verarbeitet und nicht zur Profilbildung genutzt. [Konkrete Speicherdauer aus der Dokumentation von Friendly Captcha]\n\n"
			. "Widerspruchsrecht. Sie haben das Recht, aus Gründen, die sich aus Ihrer besonderen Situation ergeben, jederzeit gegen diese auf berechtigtem Interesse beruhende Verarbeitung Widerspruch einzulegen (Art. 21 DSGVO).\n\n"
			. "Ihre Rechte. Ihnen stehen die Rechte auf Auskunft, Berichtigung und Löschung zu sowie ein Beschwerderecht bei der zuständigen Aufsichtsbehörde.\n\n"
			. "(Unverbindliche Vorlage, keine Rechtsberatung. Bitte an Ihre konkrete Situation anpassen und im Zweifel rechtlich prüfen lassen.)",
			'gutenberg-formbuilder'
		);
	}

	/**
	 * Kopierbarer LIA-Textbaustein (E-neu.2): internes Dokument zur
	 * Interessenabwägung (Legitimate Interest Assessment). Hilft dem Betreiber,
	 * das berechtigte Interesse dokumentiert nachzuweisen (Rechenschaftspflicht).
	 * Trägt alle vier Punkte aus EN5.
	 *
	 * Platzhalter `[…]` (Datum, verantwortliche Person) bleiben leer und werden
	 * vom Betreiber ausgefüllt. Unverbindliche Vorlage, keine Rechtsberatung.
	 *
	 * @return string Reiner Text (keine HTML-Tags).
	 */
	public static function lia_text_snippet() {
		return __(
			"Interessenabwägung – Einsatz von Friendly Captcha\n\n"
			. "Internes Dokument zur Dokumentation des berechtigten Interesses (Art. 6 Abs. 1 lit. f DSGVO / Art. 31 revDSG).\n\n"
			. "1. Zweck / berechtigtes Interesse\n"
			. "Wir schützen unsere Web-Formulare vor Spam und automatisiertem Missbrauch. Funktionierende Formulare ohne Bot-Flut sind die Grundlage für die Kommunikation mit unseren Besucherinnen und Besuchern.\n\n"
			. "2. Erforderlichkeit / kein milderes Mittel\n"
			. "Reine serverseitige Massnahmen (Honeypot, Rate-Limit) fangen einfache Bots ab, aber nicht gezielten automatisierten Missbrauch. Ein Proof-of-Work-Captcha ohne Cookies und ohne Tracking ist das mildeste Mittel, das den Schutz spürbar erhöht, ohne das Verhalten der Besucher zu beobachten.\n\n"
			. "3. Interessenabwägung\n"
			. "Der Eingriff ist gering: Verarbeitet wird allein die IP-Adresse, es findet kein Profiling statt, die Verarbeitung erfolgt in der EU und nur kurz zur Verifikation. Besucherinnen und Besucher erwarten, dass Formulare gegen Spam geschützt sind. Dem geringen Eingriff steht ein legitimer Schutzbedarf gegenüber. Die Interessen der Betroffenen überwiegen nicht.\n\n"
			. "4. Ergebnis\n"
			. "Der Einsatz von Friendly Captcha auf berechtigtem Interesse ist zulässig.\n"
			. "Datum: [TT.MM.JJJJ]\n"
			. "Verantwortliche Person: [Name / Funktion]\n\n"
			. "(Unverbindliche Vorlage, keine Rechtsberatung. Bitte an Ihre konkrete Situation anpassen und im Zweifel rechtlich prüfen lassen.)",
			'gutenberg-formbuilder'
		);
	}
}
