<?php
/**
 * Eingabe-Nachweis: eigener Spam-Schutz ohne Fremddienst.
 *
 * Ablauf:
 * 1. Beim Rendern erzeugt der Server eine Aufgabe (Zeitstempel, 16
 *    Zufallsbytes) und signiert sie per HMAC zusammen mit Post-, Formular-
 *    und Instanz-ID.
 * 2. assets/eingabe-nachweis.js startet erst nach der ersten echten
 *    Interaktion (event.isTrusted) und sucht zu einem eigenen Zufallssalz
 *    eine Zahl, sodass SHA-256(Aufgabe.Salz.Zahl) mit einer festen Anzahl
 *    Null-Bits beginnt.
 * 3. Der Server prueft Signatur, Alter, Null-Bits und Wiederverwendung.
 *
 * Kein REST-Aufruf, kein admin-ajax, kein Fremddienst: Aufgabe und Loesung
 * reisen als versteckte Felder mit dem normalen Formular-POST.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Nachweis {

	/** Feldname der signierten Aufgabe. */
	const FIELD_AUFGABE = 'gfb_nw_aufgabe';

	/** Feldname der Loesung (Salz.Zahl). */
	const FIELD_LOESUNG = 'gfb_nw_loesung';

	/** Null-Bits, mit denen der Hash beginnen muss (Startwert). */
	const SCHWIERIGKEIT = 14;

	/** Mindestalter der Aufgabe beim Absenden in Sekunden. */
	const MIN_AGE = 3;

	/** Transient-Praefix fuer verbrauchte Loesungen. */
	const REPLAY_PREFIX = 'gfb_nw_';

	/**
	 * Ist der Eingabe-Nachweis aktiv? Standard: ja. Ausschalten nur per
	 * Filter fuer Notfaelle, bewusst ohne Schalter im Admin.
	 *
	 * @return bool
	 */
	public static function is_active() {
		/**
		 * Eingabe-Nachweis ein- oder ausschalten (Notfall-Schalter).
		 *
		 * @param bool $active Standard true.
		 */
		return (bool) apply_filters( 'gfb_nachweis_aktiv', true );
	}

	/**
	 * Wirksame Schwierigkeit in Null-Bits, begrenzt auf 8 bis 24.
	 *
	 * @return int
	 */
	public static function schwierigkeit() {
		/**
		 * Null-Bits der Rechenaufgabe. Jedes Bit verdoppelt die Rechenzeit.
		 *
		 * @param int $bits Standard 14.
		 */
		$bits = (int) apply_filters( 'gfb_nachweis_schwierigkeit', self::SCHWIERIGKEIT );
		return max( 8, min( 24, $bits ) );
	}

	/**
	 * HMAC-Schluessel, getrennt vom Anti-Replay-Token.
	 *
	 * @return string
	 */
	private static function key() {
		return wp_salt( 'auth' ) . '|gfb-eingabe-nachweis-v1';
	}

	/**
	 * Signatur-Payload.
	 *
	 * @return string
	 */
	private static function payload( $post_id, $form_id, $instance_id, $ts, $rand, $bits ) {
		return implode(
			'|',
			array(
				'gfb-nachweis-v1',
				(int) $post_id,
				(string) $form_id,
				(string) $instance_id,
				(int) $ts,
				(string) $rand,
				(int) $bits,
			)
		);
	}

	/**
	 * Erzeugt eine signierte Aufgabe im Format ts.rand.bits.sig.
	 *
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID.
	 * @param string $instance_id Instanz-ID.
	 * @return string
	 */
	public static function create( $post_id, $form_id, $instance_id ) {
		$ts   = time();
		$rand = bin2hex( random_bytes( 16 ) );
		$bits = self::schwierigkeit();
		$sig  = hash_hmac( 'sha256', self::payload( $post_id, $form_id, $instance_id, $ts, $rand, $bits ), self::key() );
		return $ts . '.' . $rand . '.' . $bits . '.' . $sig;
	}

	/**
	 * Versteckte Felder fuer das Formular-Markup.
	 *
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID.
	 * @param string $instance_id Instanz-ID.
	 * @return string HTML.
	 */
	public static function render_fields( $post_id, $form_id, $instance_id ) {
		return sprintf(
			'<input type="hidden" name="%1$s" value="%2$s" data-gfb-nw-aufgabe="1" /><input type="hidden" name="%3$s" value="" data-gfb-nw-loesung="1" />',
			esc_attr( self::FIELD_AUFGABE ),
			esc_attr( self::create( $post_id, $form_id, $instance_id ) ),
			esc_attr( self::FIELD_LOESUNG )
		);
	}

	/**
	 * Zaehlt die fuehrenden Null-Bits eines binaeren Hashes.
	 *
	 * @param string $bin Binaerer Hash.
	 * @return int
	 */
	public static function leading_zero_bits( $bin ) {
		$count = 0;
		$len   = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$byte = ord( $bin[ $i ] );
			if ( 0 === $byte ) {
				$count += 8;
				continue;
			}
			for ( $bit = 7; $bit >= 0; $bit-- ) {
				if ( $byte & ( 1 << $bit ) ) {
					return $count;
				}
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Prueft Aufgabe und Loesung.
	 *
	 * @param string $aufgabe     Feld gfb_nw_aufgabe.
	 * @param string $loesung     Feld gfb_nw_loesung (salz.zahl).
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID.
	 * @param string $instance_id Instanz-ID.
	 * @return string Leerer String bei Erfolg, sonst der Grund
	 *                (no_proof|bad_signature|too_fast|expired|bad_proof|replay).
	 */
	public static function verify( $aufgabe, $loesung, $post_id, $form_id, $instance_id ) {
		if ( '' === $aufgabe || '' === $loesung ) {
			return 'no_proof';
		}

		if ( ! preg_match( '/^(\d{1,12})\.([a-f0-9]{32})\.(\d{1,2})\.([a-f0-9]{64})$/', $aufgabe, $m ) ) {
			return 'bad_signature';
		}
		$ts   = (int) $m[1];
		$rand = $m[2];
		$bits = (int) $m[3];
		$sig  = $m[4];

		$expected = hash_hmac( 'sha256', self::payload( $post_id, $form_id, $instance_id, $ts, $rand, $bits ), self::key() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return 'bad_signature';
		}

		// Die signierte Schwierigkeit darf die aktuelle nicht unterschreiten
		// (sonst koennte eine alte, leichtere Aufgabe weiterleben).
		if ( $bits < self::schwierigkeit() ) {
			return 'bad_signature';
		}

		/**
		 * Mindestalter der Aufgabe beim Absenden in Sekunden.
		 *
		 * @param int $sekunden Standard 3.
		 */
		$min_age = max( 0, min( 60, (int) apply_filters( 'gfb_nachweis_mindestzeit', self::MIN_AGE ) ) );

		$age = time() - $ts;
		if ( $age < $min_age ) {
			return 'too_fast';
		}
		if ( $age > GFB_Security::TOKEN_TTL_SECONDS ) {
			return 'expired';
		}

		if ( ! preg_match( '/^([a-f0-9]{16,64})\.(\d{1,10})$/', $loesung ) ) {
			return 'bad_proof';
		}

		$hash = hash( 'sha256', $aufgabe . '.' . $loesung, true );
		if ( self::leading_zero_bits( $hash ) < $bits ) {
			return 'bad_proof';
		}

		// Wiederverwendung: dieselbe Kombination gilt nur einmal. Das Salz
		// pro Browser erlaubt mehreren Personen dieselbe Aufgabe aus einer
		// zwischengespeicherten Seite.
		$replay_key = self::REPLAY_PREFIX . substr( bin2hex( $hash ), 0, 40 );
		if ( false !== get_transient( $replay_key ) ) {
			return 'replay';
		}
		set_transient( $replay_key, 1, GFB_Security::TOKEN_TTL_SECONDS + 60 );

		return '';
	}

	/**
	 * Zahlen der letzten Tage aus dem Audit-Log fuer die Einstellungsseite.
	 *
	 * @param int $days Zeitraum in Tagen.
	 * @return array{pass:int,fail:int,reasons:array<string,int>}
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$out   = array(
			'pass'    => 0,
			'fail'    => 0,
			'reasons' => array(),
		);
		$table = $wpdb->prefix . 'gfb_audit';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return $out;
		}

		// created_at steht in Serverzeit (current_timestamp); der Vergleich
		// mit UTC kann um die Zeitzonen-Differenz abweichen, fuer eine
		// 30-Tage-Uebersicht genuegt das.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT context_json FROM {$table} WHERE action = %s AND created_at >= %s", 'nachweis_verify', $since ) );
		foreach ( (array) $rows as $json ) {
			$ctx = json_decode( (string) $json, true );
			if ( ! is_array( $ctx ) ) {
				continue;
			}
			if ( isset( $ctx['result'] ) && 'pass' === $ctx['result'] ) {
				++$out['pass'];
				continue;
			}
			++$out['fail'];
			$reason                    = isset( $ctx['detail'] ) ? sanitize_key( (string) $ctx['detail'] ) : 'unbekannt';
			$out['reasons'][ $reason ] = ( isset( $out['reasons'][ $reason ] ) ? $out['reasons'][ $reason ] : 0 ) + 1;
		}
		arsort( $out['reasons'] );
		return $out;
	}
}
