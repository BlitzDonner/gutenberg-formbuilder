<?php
/**
 * Zentrale Textverwaltung: jeder besucher- und mailsichtbare Satz des Plugins.
 *
 * Grundsatz (Entscheid Stefan, 27.07.2026): Es gibt im Plugin keinen Satz, den
 * die Betreiberin nicht ändern kann. Darum steht hier jeder Text, den eine
 * ausfüllende Person je zu sehen bekommt – im Formular, in Meldungen, in den
 * Mails und auf den Bestätigungsseiten. Die Backend-Oberfläche selbst bleibt
 * bewusst draussen: Sie richtet sich an die Betreiberin, dort genügt Übersetzung.
 *
 * Auflösung eines Textes: Betreiber-Wert (Option) → eingebauter Standard
 * (übersetzt) → Filter. Gespeichert wird nur, was wirklich abweicht.
 *
 * @package gutenberg-formbuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry und Auflösung aller besuchersichtbaren Texte.
 */
class GFB_Texts {

	/** Option mit den abweichenden Texten (nur überschriebene Schlüssel). */
	const OPTION = 'gfb_texts';

	/** Höchstlänge eines Betreiber-Textes. */
	const MAX_LEN = 600;

	/** Option mit der Anredeform der Standardtexte: sie (Vorgabe) oder du. */
	const OPTION_ANREDE = 'gfb_anrede';

	/** Option: wie die Website in Mails benannt wird (domain|title|custom). */
	const OPTION_SITE_LABEL_MODE = 'gfb_site_label_mode';

	/** Option: eigene Bezeichnung, wenn Modus custom. */
	const OPTION_SITE_LABEL_CUSTOM = 'gfb_site_label_custom';

	/**
	 * Bezeichnung der Website in Mails (Betreffzeilen, Datentabelle, Fusszeile,
	 * Absendername). Standard ist die vereinfachte Domain – Empfängerinnen
	 * erkennen «varellion.ch» zuverlässiger als einen Markennamen, der von der
	 * Adresse abweichen kann. Der Website-Titel bleibt als Alternative wählbar.
	 *
	 * @return string
	 */
	public static function site_label() {
		$mode = (string) get_option( self::OPTION_SITE_LABEL_MODE, 'domain' );

		if ( 'custom' === $mode ) {
			$custom = trim( (string) get_option( self::OPTION_SITE_LABEL_CUSTOM, '' ) );
			if ( '' !== $custom ) {
				return apply_filters( 'gfb_site_label', $custom, $mode );
			}
		}

		if ( 'title' === $mode ) {
			return apply_filters( 'gfb_site_label', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), $mode );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./i', '', $host ) : '';
		if ( '' === $host ) {
			$host = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		}

		/**
		 * Bezeichnung der Website in Mails übersteuern.
		 *
		 * @param string $label Ermittelte Bezeichnung.
		 * @param string $mode  domain|title|custom.
		 */
		return apply_filters( 'gfb_site_label', $host, $mode );
	}

	/**
	 * Gewählte Anredeform der Standardtexte. Betrifft nur die eingebauten
	 * Texte – ein eigener Text gilt unverändert, egal welche Form eingestellt
	 * ist. Texte ohne Anrede (etwa «Ungültiges Datum.») sind in beiden Formen
	 * identisch und führen darum keine zweite Fassung.
	 *
	 * @return string sie|du
	 */
	public static function anrede() {
		$value = (string) get_option( self::OPTION_ANREDE, 'sie' );
		return 'du' === $value ? 'du' : 'sie';
	}

	/**
	 * Registry: Gruppen mit Titel, Beschreibung und Texten.
	 *
	 * Jeder Text: default (übersetzt), hint (wo er erscheint), optional
	 * placeholders (Pflicht-Platzhalter; beim Speichern geprüft) und
	 * multiline (mehrzeiliges Eingabefeld im Backend).
	 *
	 * @return array<string,array{label:string,description:string,texts:array<string,array<string,mixed>>}>
	 */
	public static function registry() {
		return array(

			'form' => array(
				'label'       => __( 'Formular', 'gutenberg-formbuilder' ),
				'description' => __( 'Texte im Formular selbst.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'submit_button'      => array(
						'default' => __( 'Formular absenden', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Absende-Knopf, wenn im Block keine eigene Beschriftung gesetzt ist.', 'gutenberg-formbuilder' ),
					),
					'encrypted_pill'     => array(
						'default' => __( 'verschlüsselt', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Kennzeichnung neben Feldern, die verschlüsselt gespeichert werden.', 'gutenberg-formbuilder' ),
					),
					'encrypted_pill_aria' => array(
						'default' => __( 'Wird verschlüsselt gespeichert', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Vorlesetext derselben Kennzeichnung für Screenreader.', 'gutenberg-formbuilder' ),
					),
					'file_hint'          => array(
						'default'      => __( 'Datei wird verschlüsselt gespeichert (max. %d MB).', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Hinweis unter Datei-Feldern. %d wird durch die erlaubte Grösse in MB ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%d' ),
					),
					'file_remove'        => array(
						'default' => __( 'Entfernen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Knopf, der eine gewählte Datei wieder abwählt.', 'gutenberg-formbuilder' ),
					),
					'captcha_label'      => array(
						'default' => __( 'Spam-Schutz', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Beschriftung über dem Captcha-Feld.', 'gutenberg-formbuilder' ),
					),
					'captcha_hint'       => array(
						'default' => __( 'Bitte schliessen Sie den Spam-Schutz ab, bevor Sie das Formular absenden.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Bitte den Spam-Schutz abschliessen, bevor du das Formular absendest.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Hinweis unter dem Captcha.', 'gutenberg-formbuilder' ),
					),
				),
			),

			'overlay' => array(
				'label'       => __( 'Overlays beim Absenden', 'gutenberg-formbuilder' ),
				'description' => __( 'Die Einblendungen während des Absendens und als Erfolgs-Quittung.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'sending'       => array(
						'default' => __( 'Ihre Daten werden verschlüsselt und sicher übermittelt …', 'gutenberg-formbuilder' ),
						'du'      => __( 'Deine Daten werden verschlüsselt und sicher übermittelt …', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Text während der Übermittlung.', 'gutenberg-formbuilder' ),
					),
					'success_title' => array(
						'default' => __( 'Erfolgreich übermittelt', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Titel der Erfolgs-Quittung nach dem Absenden.', 'gutenberg-formbuilder' ),
					),
					'success_text'  => array(
						'default' => __( 'Ihre Daten sind verschlüsselt übermittelt worden.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Deine Daten sind verschlüsselt übermittelt worden.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Text der Erfolgs-Quittung.', 'gutenberg-formbuilder' ),
					),
					'success_close' => array(
						'default' => __( 'Schliessen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Knopf, mit dem die Quittung geschlossen wird.', 'gutenberg-formbuilder' ),
					),
				),
			),

			'notice' => array(
				'label'       => __( 'Meldungen nach dem Absenden', 'gutenberg-formbuilder' ),
				'description' => __( 'Erfolgs- und Fehlermeldungen über dem Formular.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'success'                  => array(
						'default' => __( 'Danke! Das Formular wurde erfolgreich gesendet.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Bestätigung, wenn kein eigener Erfolgsbereich gestaltet ist.', 'gutenberg-formbuilder' ),
					),
					'generic_error'            => array(
						'default' => __( 'Beim Absenden ist ein Fehler aufgetreten.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Allgemeiner Fehlerhinweis über dem Formular.', 'gutenberg-formbuilder' ),
					),
					'err_request'              => array(
						'default' => __( 'Ungültige Formularanfrage.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Die Anfrage war unvollständig.', 'gutenberg-formbuilder' ),
					),
					'err_nonce'                => array(
						'default' => __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut absenden.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Sicherheitsschlüssel abgelaufen (Seite lag lange offen).', 'gutenberg-formbuilder' ),
					),
					'err_token'                => array(
						'default' => __( 'Sitzung abgelaufen. Bitte Seite neu laden und erneut absenden.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Formular-Kennung nicht mehr gültig.', 'gutenberg-formbuilder' ),
					),
					'err_spam'                 => array(
						'default' => __( 'Die Anfrage wurde als Spam erkannt.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Die Spam-Falle im Formular hat angeschlagen.', 'gutenberg-formbuilder' ),
					),
					'err_rate'                 => array(
						'default' => __( 'Zu viele Anfragen. Bitte warten Sie kurz und versuchen Sie es erneut.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Zu viele Absendeversuche in kurzer Zeit.', 'gutenberg-formbuilder' ),
					),
					'err_schema'               => array(
						'default' => __( 'Formularschema nicht gefunden.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Das Formular konnte serverseitig nicht gelesen werden.', 'gutenberg-formbuilder' ),
					),
					'err_duplicate'            => array(
						'default' => __( 'Doppelte technische Feldnamen im Formular. Bitte eines der betroffenen Felder duplizieren oder Label bzw. Platzhalter anpassen.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Zwei Felder tragen denselben technischen Namen.', 'gutenberg-formbuilder' ),
					),
					'err_validation'           => array(
						'default' => __( 'Das Formular wurde nicht übermittelt. Bitte prüfen Sie die Hinweise und senden Sie erneut.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Das Formular wurde nicht übermittelt. Bitte prüfe die Hinweise und sende erneut.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Sammelmeldung, wenn einzelne Felder beanstandet wurden.', 'gutenberg-formbuilder' ),
					),
					'err_file'                 => array(
						'default' => __( 'Eine hochgeladene Datei wurde abgelehnt. Das Formular wurde in diesem Fall nicht übermittelt; es wurde kein neuer Eintrag gespeichert.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Eine Datei wurde zurückgewiesen.', 'gutenberg-formbuilder' ),
					),
					'err_persist'              => array(
						'default' => __( 'Speichern fehlgeschlagen. Bitte versuchen Sie es erneut.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Speichern fehlgeschlagen. Bitte versuche es erneut.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Die Einsendung konnte nicht gespeichert werden.', 'gutenberg-formbuilder' ),
					),
					'err_external'             => array(
						'default' => __( 'Die Anfrage konnte nicht verarbeitet werden.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Eine zusätzliche Prüfung hat die Einsendung abgelehnt.', 'gutenberg-formbuilder' ),
					),
					'err_crypto'               => array(
						'default' => __( 'Verschlüsselung ist auf diesem Server nicht eingerichtet. Bitte den Administrator kontaktieren.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Die Verschlüsselung fehlt, obwohl das Formular sie braucht.', 'gutenberg-formbuilder' ),
					),
					'err_virus'                => array(
						'default' => __( 'Eine hochgeladene Datei wurde vom Virenscanner als schädlich erkannt.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Der Virenscanner hat angeschlagen.', 'gutenberg-formbuilder' ),
					),
					'err_captcha'              => array(
						'default' => __( 'Der Spam-Schutz wurde nicht bestätigt. Bitte schliessen Sie die Spam-Prüfung im Formular ab und senden Sie erneut.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Der Spam-Schutz wurde nicht bestätigt. Bitte schliesse die Spam-Prüfung im Formular ab und sende erneut.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Das Captcha wurde nicht gelöst.', 'gutenberg-formbuilder' ),
					),
					'err_captcha_unreachable'  => array(
						'default' => __( 'Der Spam-Schutz ist derzeit nicht verfügbar. Bitte versuchen Sie es in einigen Minuten erneut.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Der Spam-Schutz ist derzeit nicht verfügbar. Bitte versuche es in einigen Minuten erneut.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Der Captcha-Dienst antwortet nicht.', 'gutenberg-formbuilder' ),
					),
				),
			),

			'validation' => array(
				'label'       => __( 'Feldprüfung', 'gutenberg-formbuilder' ),
				'description' => __( 'Hinweise zu einzelnen Feldern. %s steht jeweils für die Feldbeschriftung.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'required'          => array(
						'default'      => __( 'Bitte füllen Sie das Feld "%s" aus.', 'gutenberg-formbuilder' ),
						'du'           => __( 'Bitte fülle das Feld "%s" aus.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Pflichtfeld leer gelassen.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'checkbox_required' => array(
						'default'      => __( 'Bitte bestätigen Sie "%s".', 'gutenberg-formbuilder' ),
						'du'           => __( 'Bitte bestätige "%s".', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Pflicht-Ankreuzfeld nicht angekreuzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'email_invalid'     => array(
						'default'      => __( 'Das Feld "%s" enthält keine gültige E-Mail-Adresse.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'E-Mail-Feld unplausibel.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'url_invalid'       => array(
						'default'      => __( 'Das Feld "%s" enthält keine gültige URL.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Web-Adresse unplausibel.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'number_invalid'    => array(
						'default'      => __( 'Das Feld "%s" muss eine Zahl sein.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Zahlenfeld enthält keine Zahl.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'tel_invalid'       => array(
						'default'      => __( 'Das Feld "%s" enthält eine ungültige Telefonnummer.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Telefonnummer unplausibel.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'file_required'     => array(
						'default'      => __( 'Bitte wählen Sie eine Datei für "%s".', 'gutenberg-formbuilder' ),
						'du'           => __( 'Bitte wähle eine Datei für "%s".', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Pflicht-Datei fehlt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'number_min'        => array(
						'default' => __( 'Zahl zu klein.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Wert unter dem erlaubten Minimum.', 'gutenberg-formbuilder' ),
					),
					'number_max'        => array(
						'default' => __( 'Zahl zu gross.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Wert über dem erlaubten Maximum.', 'gutenberg-formbuilder' ),
					),
					'date_invalid'      => array(
						'default' => __( 'Ungültiges Datum.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Datum nicht lesbar oder ausserhalb des erlaubten Bereichs.', 'gutenberg-formbuilder' ),
					),
					'time_invalid'      => array(
						'default' => __( 'Ungültige Uhrzeit.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Uhrzeit nicht lesbar.', 'gutenberg-formbuilder' ),
					),
					'datetime_invalid'  => array(
						'default' => __( 'Ungültiges Datum/Uhrzeit.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Datum mit Uhrzeit nicht lesbar.', 'gutenberg-formbuilder' ),
					),
					'option_invalid'    => array(
						'default' => __( 'Ungültige Auswahl.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Gewählte Option gehört nicht zum Formular.', 'gutenberg-formbuilder' ),
					),
					'too_long'          => array(
						'default' => __( 'Eingabe zu lang.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Text überschreitet die erlaubte Länge.', 'gutenberg-formbuilder' ),
					),
					'hidden_invalid'    => array(
						'default' => __( 'Ungültiges verstecktes Feld.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Ein verstecktes Feld wurde manipuliert.', 'gutenberg-formbuilder' ),
					),
					'file_multiple'     => array(
						'default' => __( 'Mehrfach-Uploads sind nicht erlaubt.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Mehr als eine Datei pro Feld gesendet.', 'gutenberg-formbuilder' ),
					),
					'file_upload_failed' => array(
						'default' => __( 'Datei-Upload fehlgeschlagen.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Die Übertragung der Datei brach ab.', 'gutenberg-formbuilder' ),
					),
					'file_too_large'    => array(
						'default' => __( 'Datei ist zu gross.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Datei überschreitet die erlaubte Grösse.', 'gutenberg-formbuilder' ),
					),
					'virus_found'       => array(
						'default' => __( 'Diese Datei wurde vom Virenscanner als schädlich erkannt.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Der Virenscanner hat die Datei abgelehnt.', 'gutenberg-formbuilder' ),
					),
					'virus_unavailable' => array(
						'default' => __( 'Virenscan derzeit nicht verfügbar; Upload wird verweigert.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Der Virenscanner antwortet nicht.', 'gutenberg-formbuilder' ),
					),
				),
			),

			'receipt' => array(
				'label'       => __( 'Bestätigungsmail an die ausfüllende Person', 'gutenberg-formbuilder' ),
				'description' => __( 'Betreffzeilen, Standard-Vorlagen und Bausteine der Mails. Der Mailtext pro Formular kommt weiterhin aus den Block-Containern.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'subject_receipt'          => array(
						'default'      => __( 'Ihre Einsendung bei %s ist eingegangen', 'gutenberg-formbuilder' ),
						'du'           => __( 'Deine Einsendung bei %s ist eingegangen', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Betreff der Bestätigungsmail. %s wird durch den Website-Namen ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'subject_doi'              => array(
						'default' => __( 'Bitte bestätigen Sie Ihre E-Mail-Adresse', 'gutenberg-formbuilder' ),
						'du'      => __( 'Bitte bestätige deine E-Mail-Adresse', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Betreff der Mail mit dem Bestätigungslink.', 'gutenberg-formbuilder' ),
					),
					'subject_confirmed'        => array(
						'default'      => __( 'Ihre Einsendung bei %s ist bestätigt', 'gutenberg-formbuilder' ),
						'du'           => __( 'Deine Einsendung bei %s ist bestätigt', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Betreff der Quittung nach dem Bestätigungsklick. %s wird durch den Website-Namen ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'table_intro'              => array(
						'default'      => __( 'Diese Angaben wurden über ein Formular auf %s übermittelt:', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Satz über der Tabelle mit den Feldwerten. %s wird durch den Website-Namen ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%s' ),
					),
					'value_confidential'       => array(
						'default' => __( 'vertraulich gespeichert', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Ersatztext für vertrauliche Werte, solange die Adresse unbestätigt ist.', 'gutenberg-formbuilder' ),
					),
					'value_file_encrypted'     => array(
						'default' => __( 'verschlüsselt gespeichert', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Zusatz hinter Dateinamen in der Tabelle.', 'gutenberg-formbuilder' ),
					),
					'value_file_fallback'      => array(
						'default'      => __( 'Datei #%d', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Ersatzname, wenn der Originalname fehlt. %d wird durch die Datei-Nummer ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%d' ),
					),
					'value_yes'                => array(
						'default' => __( 'Ja', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Angekreuztes Ankreuzfeld in der Tabelle.', 'gutenberg-formbuilder' ),
					),
					'value_no'                 => array(
						'default' => __( 'Nein', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Nicht angekreuztes Ankreuzfeld in der Tabelle.', 'gutenberg-formbuilder' ),
					),
					'footer_instant'           => array(
						'default'   => __( 'Sie haben dieses Formular nicht ausgefüllt? Dann hat jemand Ihre E-Mail-Adresse eingetragen. Die Einsendung liegt beim Betreiber dieser Website; Sie können dort jederzeit die Löschung verlangen – antworten Sie dazu auf diese E-Mail oder nutzen Sie die Kontaktangaben der Website.', 'gutenberg-formbuilder' ),
						'du'        => __( 'Du hast dieses Formular nicht ausgefüllt? Dann hat jemand deine E-Mail-Adresse eingetragen. Die Einsendung liegt beim Betreiber dieser Website; du kannst dort jederzeit die Löschung verlangen – antworte dazu auf diese E-Mail oder nutze die Kontaktangaben der Website.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Transparenz-Hinweis am Ende der Sofort-Bestätigung. Aus dem Datenschutz-Review: fälschlich adressierte Empfänger brauchen einen Löschweg – bitte nicht ersatzlos leeren.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
					'footer_doi'               => array(
						'default'   => __( 'Sie haben dieses Formular nicht ausgefüllt? Dann ignorieren Sie diese E-Mail. Ohne Bestätigung gilt Ihre Adresse nicht als bestätigt, und die unbestätigte Einsendung wird nach einer festen Frist automatisch gelöscht.', 'gutenberg-formbuilder' ),
						'du'        => __( 'Du hast dieses Formular nicht ausgefüllt? Dann ignoriere diese E-Mail. Ohne Bestätigung gilt deine Adresse nicht als bestätigt, und die unbestätigte Einsendung wird nach einer festen Frist automatisch gelöscht.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Derselbe Hinweis in der Mail mit dem Bestätigungslink.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
					'template_doi_heading'     => array(
						'default' => __( 'Bitte bestätigen Sie Ihre E-Mail-Adresse', 'gutenberg-formbuilder' ),
						'du'      => __( 'Bitte bestätige deine E-Mail-Adresse', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Überschrift der Standard-Vorlage für die Link-Mail (gilt, solange kein eigener Inhalt gestaltet ist).', 'gutenberg-formbuilder' ),
					),
					'template_doi_intro'       => array(
						'default'   => __( 'Ihre Einsendung ist eingegangen. Bitte bestätigen Sie mit einem Klick, dass dieses E-Mail-Postfach Ihnen gehört – erst danach erhalten Sie die vollständige Eingangsbestätigung.', 'gutenberg-formbuilder' ),
						'du'        => __( 'Deine Einsendung ist eingegangen. Bitte bestätige mit einem Klick, dass dieses E-Mail-Postfach dir gehört – erst danach erhältst du die vollständige Eingangsbestätigung.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Einleitung derselben Vorlage.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
					'template_doi_note'        => array(
						'default' => __( 'Der Link ist 7 Tage gültig und funktioniert nur einmal.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Schlusssatz derselben Vorlage.', 'gutenberg-formbuilder' ),
					),
					'template_receipt_heading' => array(
						'default' => __( 'Ihre Einsendung ist eingegangen', 'gutenberg-formbuilder' ),
						'du'      => __( 'Deine Einsendung ist eingegangen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Überschrift der Standard-Vorlage für die Bestätigungsmail.', 'gutenberg-formbuilder' ),
					),
					'template_receipt_intro'   => array(
						'default' => __( 'Vielen Dank. Diese E-Mail bestätigt den Eingang Ihrer Angaben:', 'gutenberg-formbuilder' ),
						'du'      => __( 'Vielen Dank. Diese E-Mail bestätigt den Eingang deiner Angaben:', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Einleitung derselben Vorlage.', 'gutenberg-formbuilder' ),
					),
					'confirm_button'           => array(
						'default' => __( 'Jetzt bestätigen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Knopf in der Link-Mail, wenn im Block keine eigene Beschriftung gesetzt ist.', 'gutenberg-formbuilder' ),
					),
				),
			),

			'operator' => array(
				'label'       => __( 'Benachrichtigung an den Betrieb', 'gutenberg-formbuilder' ),
				'description' => __( 'Mails an die eigene Adresse, nicht an die ausfüllende Person.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'subject_with_title'    => array(
						'default'      => __( 'Neues Formular: %1$s (%2$s), Beitrag %3$d', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Betreff mit Formularnamen. %1$s Name, %2$s Kennung, %3$d Beitrags-Nummer.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%1$s', '%2$s', '%3$d' ),
					),
					'subject_without_title' => array(
						'default'      => __( 'Neues Formular (%1$s) auf Beitrag %2$d', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Betreff ohne Formularnamen. %1$s Kennung, %2$d Beitrags-Nummer.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%1$s', '%2$d' ),
					),
					'note_doi_pending'      => array(
						'default'      => __( 'Status: unbestätigt eingegangen – die absendende Person hat ihre E-Mail-Adresse für Eintrag #%d noch nicht bestätigt.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Vermerk in der ersten Mail beim Bestätigungslink-Modus. %d wird durch die Nummer der Einsendung ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%d' ),
						'multiline'    => true,
					),
					'note_doi_confirmed'    => array(
						'default'      => __( 'Status: jetzt bestätigt – die absendende Person hat die Kontrolle über ihr Postfach für Eintrag #%d nachgewiesen.', 'gutenberg-formbuilder' ),
						'hint'         => __( 'Vermerk in der zweiten Mail nach dem Klick. %d wird durch die Nummer der Einsendung ersetzt.', 'gutenberg-formbuilder' ),
						'placeholders' => array( '%d' ),
						'multiline'    => true,
					),
				),
			),

			'confirm_page' => array(
				'label'       => __( 'Bestätigungsseiten', 'gutenberg-formbuilder' ),
				'description' => __( 'Die zwei Seiten, auf denen der Bestätigungslink landet.', 'gutenberg-formbuilder' ),
				'texts'       => array(
					'page_title'      => array(
						'default' => __( 'Bestätigung', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Titel im Browser-Reiter.', 'gutenberg-formbuilder' ),
					),
					'heading_landing' => array(
						'default' => __( 'E-Mail-Adresse bestätigen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Überschrift der Seite mit dem Bestätigungs-Knopf.', 'gutenberg-formbuilder' ),
					),
					'intro_landing'   => array(
						'default' => __( 'Sie sind dem Bestätigungslink aus unserer E-Mail gefolgt.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Du bist dem Bestätigungslink aus unserer E-Mail gefolgt.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Einleitung derselben Seite.', 'gutenberg-formbuilder' ),
					),
					'landing_text'    => array(
						'default'   => __( 'Mit einem Klick auf den Knopf bestätigen Sie, dass dieses E-Mail-Postfach Ihnen gehört. Erst danach erhalten Sie die vollständige Eingangsbestätigung per E-Mail.', 'gutenberg-formbuilder' ),
						'du'        => __( 'Mit einem Klick auf den Knopf bestätigst du, dass dieses E-Mail-Postfach dir gehört. Erst danach erhältst du die vollständige Eingangsbestätigung per E-Mail.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Erklärung über dem Knopf.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
					'landing_button'  => array(
						'default' => __( 'Jetzt bestätigen', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Der Bestätigungs-Knopf.', 'gutenberg-formbuilder' ),
					),
					'landing_note'    => array(
						'default'   => __( 'Sie haben dieses Formular nicht ausgefüllt? Dann schliessen Sie diese Seite einfach – ohne Bestätigung passiert nichts.', 'gutenberg-formbuilder' ),
						'du'        => __( 'Du hast dieses Formular nicht ausgefüllt? Dann schliesse diese Seite einfach – ohne Bestätigung passiert nichts.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Hinweis für fälschlich angeschriebene Personen.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
					'heading_result'  => array(
						'default' => __( 'Bestätigung', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Überschrift der Ergebnisseite.', 'gutenberg-formbuilder' ),
					),
					'intro_result'    => array(
						'default' => __( 'Das Ergebnis Ihrer Bestätigung:', 'gutenberg-formbuilder' ),
						'du'      => __( 'Das Ergebnis deiner Bestätigung:', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Einleitung der Ergebnisseite.', 'gutenberg-formbuilder' ),
					),
					'result_title'    => array(
						'default' => __( 'Vielen Dank – Adresse bestätigt', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Titel nach erfolgreicher Bestätigung.', 'gutenberg-formbuilder' ),
					),
					'result_recorded' => array(
						'default' => __( 'Ihre Bestätigung ist erfasst.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Deine Bestätigung ist erfasst.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Bestätigung des Klicks.', 'gutenberg-formbuilder' ),
					),
					'result_handed_off' => array(
						'default' => __( 'Die Quittung wurde an Ihren Mailserver übergeben.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Die Quittung wurde an deinen Mailserver übergeben.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Zusatz, wenn die Quittung verschickt werden konnte. Bewusst nicht «zugestellt» – das kann kein Mailversand garantieren.', 'gutenberg-formbuilder' ),
					),
					'result_no_mail'  => array(
						'default' => __( 'Der Seitenbetreiber hat Ihre Meldung erhalten.', 'gutenberg-formbuilder' ),
						'du'      => __( 'Der Seitenbetreiber hat deine Meldung erhalten.', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Zusatz, wenn keine Quittung verschickt wurde.', 'gutenberg-formbuilder' ),
					),
					'rejected_title'  => array(
						'default' => __( 'Bestätigung nicht möglich', 'gutenberg-formbuilder' ),
						'hint'    => __( 'Titel bei ungültigem Link.', 'gutenberg-formbuilder' ),
					),
					'rejected_text'   => array(
						'default'   => __( 'Dieser Bestätigungslink ist ungültig, abgelaufen oder wurde bereits verwendet.', 'gutenberg-formbuilder' ),
						'hint'      => __( 'Erklärung bei ungültigem Link. Bewusst für alle drei Fälle gleich – sonst verrät die Seite, welcher Fall vorliegt.', 'gutenberg-formbuilder' ),
						'multiline' => true,
					),
				),
			),
		);
	}

	/**
	 * Eingebauter Standardtext eines Schlüssels.
	 *
	 * @param string $key Schlüssel als gruppe.name.
	 * @return string Leer, wenn unbekannt.
	 */
	public static function default_for( $key, $anrede = null ) {
		$parts = explode( '.', (string) $key, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		$registry = self::registry();
		$meta     = isset( $registry[ $parts[0] ]['texts'][ $parts[1] ] )
			? $registry[ $parts[0] ]['texts'][ $parts[1] ]
			: null;
		if ( ! $meta ) {
			return '';
		}
		$anrede = null === $anrede ? self::anrede() : ( 'du' === $anrede ? 'du' : 'sie' );
		if ( 'du' === $anrede && ! empty( $meta['du'] ) ) {
			return (string) $meta['du'];
		}
		return (string) $meta['default'];
	}

	/**
	 * Gespeicherte Betreiber-Texte (nur abweichende Schlüssel).
	 *
	 * @return array<string,string>
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Wirksamer Text: Betreiber-Wert → Standard → Filter. Optionale Argumente
	 * werden per vsprintf eingesetzt; passt die Platzhalter-Zahl nicht (etwa
	 * weil ein Betreiber-Text sie entfernt hat), greift der Standard, damit die
	 * Ausgabe nie bricht.
	 *
	 * @param string           $key  Schlüssel als gruppe.name.
	 * @param array<int,mixed> $args Werte für die Platzhalter.
	 * @return string
	 */
	public static function get( $key, array $args = array() ) {
		$key     = (string) $key;
		$default = self::default_for( $key );
		$stored  = self::stored();
		$text    = isset( $stored[ $key ] ) && '' !== trim( (string) $stored[ $key ] )
			? (string) $stored[ $key ]
			: $default;

		/**
		 * Einzelnen Text übersteuern.
		 *
		 * @param string           $text Wirksamer Text (vor dem Einsetzen der Werte).
		 * @param string           $key  Schlüssel als gruppe.name.
		 * @param array<int,mixed> $args Werte für die Platzhalter.
		 */
		$text = (string) apply_filters( 'gfb_text', $text, $key, $args );

		if ( empty( $args ) ) {
			return $text;
		}
		$filled = @vsprintf( $text, $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- defekte Betreiber-Platzhalter dürfen die Seite nie brechen.
		if ( false === $filled ) {
			$filled = @vsprintf( $default, $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return false === $filled ? $default : $filled;
	}

	/**
	 * Prüft, ob ein Betreiber-Text alle Pflicht-Platzhalter seines Standards
	 * trägt. Fehlt einer, wäre die Ausgabe unvollständig (etwa ein Fehlertext
	 * ohne Feldnamen) – das Feld wird dann nicht gespeichert.
	 *
	 * @param string $key   Schlüssel als gruppe.name.
	 * @param string $value Betreiber-Text.
	 * @return bool
	 */
	public static function placeholders_ok( $key, $value ) {
		$parts = explode( '.', (string) $key, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		$registry = self::registry();
		$meta     = isset( $registry[ $parts[0] ]['texts'][ $parts[1] ] )
			? $registry[ $parts[0] ]['texts'][ $parts[1] ]
			: null;
		if ( ! $meta ) {
			return false;
		}

		// Jeder Pflicht-Platzhalter muss vorhanden sein – sonst fehlte im Text
		// etwa der Feldname.
		$needed = isset( $meta['placeholders'] ) ? (array) $meta['placeholders'] : array();
		foreach ( $needed as $placeholder ) {
			if ( false === strpos( $value, (string) $placeholder ) ) {
				return false;
			}
		}

		// Und es dürfen nie MEHR Platzhalter sein als im Standard: Die
		// Aufrufstellen liefern eine feste Zahl an Werten; ein zusätzliches
		// %s liesse sprintf() unter PHP 8 mit einem Fehler abbrechen.
		return self::count_placeholders( $value ) <= self::count_placeholders( (string) $meta['default'] );
	}

	/**
	 * Zählt echte sprintf-Direktiven (doppelte Prozentzeichen sind Text).
	 *
	 * @param string $text Zu prüfender Text.
	 * @return int
	 */
	private static function count_placeholders( $text ) {
		$without_escaped = str_replace( '%%', '', (string) $text );
		return (int) preg_match_all( '/%(?:\d+\$)?[+\- 0#]?[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/', $without_escaped );
	}

	/**
	 * Speichert die Betreiber-Texte. Leere Felder und Werte, die dem Standard
	 * entsprechen, werden nicht abgelegt – die Option enthält nur echte
	 * Abweichungen. Texte mit fehlenden Pflicht-Platzhaltern werden verworfen
	 * und in der Rückgabe gemeldet.
	 *
	 * @param array<string,string> $input Rohwerte, Schlüssel als gruppe.name.
	 * @return array<int,string> Schlüssel, die wegen fehlender Platzhalter abgelehnt wurden.
	 */
	public static function save( array $input ) {
		$registry = self::registry();
		$clean    = array();
		$rejected = array();

		foreach ( $registry as $group_key => $group ) {
			foreach ( $group['texts'] as $text_key => $meta ) {
				$key = $group_key . '.' . $text_key;
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}
				$value = sanitize_textarea_field( (string) $input[ $key ] );
				$value = mb_substr( $value, 0, self::MAX_LEN );
				if ( '' === trim( $value ) ) {
					continue;
				}
				// Wer den angezeigten Standard unverändert abschickt, will keinen
				// eigenen Text – in beiden Anredeformen.
				if ( $value === (string) $meta['default'] || ( ! empty( $meta['du'] ) && $value === (string) $meta['du'] ) ) {
					continue;
				}
				if ( ! self::placeholders_ok( $key, $value ) ) {
					$rejected[] = $key;
					continue;
				}
				$clean[ $key ] = $value;
			}
		}

		update_option( self::OPTION, $clean, false );
		return $rejected;
	}

	/**
	 * Einmalige Übernahme der früheren Einzeloptionen (2.10.x) in die Registry.
	 * Läuft nur, solange die Registry-Option noch nicht existiert – eingestellte
	 * Texte gehen beim Update nicht verloren.
	 *
	 * @return void
	 */
	public static function maybe_migrate_legacy_options() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		$migrated = array();

		$overlay = get_option( 'gfb_overlay_texts', array() );
		if ( is_array( $overlay ) ) {
			foreach ( array( 'sending', 'success_title', 'success_text', 'success_close' ) as $legacy_key ) {
				if ( ! empty( $overlay[ $legacy_key ] ) ) {
					$migrated[ 'overlay.' . $legacy_key ] = sanitize_textarea_field( (string) $overlay[ $legacy_key ] );
				}
			}
		}

		$captcha = get_option( 'gfb_captcha_settings', array() );
		if ( is_array( $captcha ) && ! empty( $captcha['hint_text'] ) ) {
			$migrated['form.captcha_hint'] = sanitize_textarea_field( (string) $captcha['hint_text'] );
		}

		update_option( self::OPTION, $migrated, false );
	}
}
