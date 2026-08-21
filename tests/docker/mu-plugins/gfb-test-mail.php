<?php
/**
 * Plugin Name: GFB Testreihe – Mailweg
 * Description: Leitet jede Mail an den Mailfänger im Container oder an den echten SMTP-Server.
 *
 * Zwei Ziele, gesteuert über die Umgebungsvariable GFB_MAIL_ZIEL:
 *   mailpit  – SMTP an mailpit:1025, ohne Anmeldung. Vorgabe.
 *   echt     – SMTP an GFB_SMTP_HOST mit Anmeldung, für die Zustellprüfung am Schluss.
 *
 * Der Weg über phpmailer_init lässt den Plugin-Code unangetastet: Absender,
 * Antwort-Adresse, Betreff und Inhalt stammen weiterhin aus dem Plugin.
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress bildet seine Absenderadresse aus dem Hostnamen: im Container also
 * «wordpress@localhost». Eine Domain ohne Punkt lehnt PHPMailer ab, damit
 * scheitert jede Mail, die keinen eigenen Absender mitbringt. Deshalb bekommt
 * nur dieser Standardfall eine gültige Domain. Setzt das Plugin selbst einen
 * Absender, bleibt er unangetastet.
 */
add_filter(
	'wp_mail_from',
	static function ( $adresse ) {
		return str_ends_with( (string) $adresse, '@localhost' ) ? 'wordpress@gfb-testlauf.test' : $adresse;
	},
	1
);

/**
 * Derselbe Grund gilt für den Rückweg (Return-Path): Das Plugin leitet ihn aus
 * dem Hostnamen ab, «localhost» ist dafür keine gültige Domain. Der offizielle
 * Filter des Plugins setzt hier eine mit Punkt. Damit ist der Rückweg im
 * Testlauf gesetzt, aber nicht inhaltlich geprüft.
 */
add_filter(
	'gfb_receipt_return_path',
	static function ( $adresse ) {
		return str_ends_with( (string) $adresse, '@localhost' ) ? 'wordpress@gfb-testlauf.test' : $adresse;
	},
	1
);

add_action(
	'phpmailer_init',
	static function ( $mailer ) {
		$ziel = getenv( 'GFB_MAIL_ZIEL' ) ?: 'mailpit';

		if ( 'echt' === $ziel ) {
			$host = (string) getenv( 'GFB_SMTP_HOST' );
			if ( '' === $host ) {
				return;
			}
			$mailer->isSMTP();
			$mailer->Host       = $host;
			$mailer->Port       = 465;
			$mailer->SMTPSecure = 'ssl';
			$mailer->SMTPAuth   = true;
			$mailer->Username   = (string) getenv( 'GFB_SMTP_USER' );
			$mailer->Password   = (string) getenv( 'GFB_SMTP_PASS' );
			$mailer->Timeout    = 20;
			return;
		}

		$mailer->isSMTP();
		$mailer->Host       = 'mailpit';
		$mailer->Port       = 1025;
		$mailer->SMTPAuth   = false;
		$mailer->SMTPSecure = '';
		$mailer->SMTPAutoTLS = false;
		$mailer->Timeout    = 10;
	}
);

/**
 * Jeder Fehlversuch landet im Protokoll, sonst schluckt WordPress ihn stumm.
 */
add_action(
	'wp_mail_failed',
	static function ( $error ) {
		error_log( 'GFB-TEST Mailfehler: ' . $error->get_error_message() );
	}
);
