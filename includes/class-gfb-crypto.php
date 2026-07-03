<?php
/**
 * Crypto-Fundament für Blitz & Donner Formular.
 *
 * Architektur (envelope encryption):
 * - KEK ("Key Encryption Key"): kommt aus wp-config.php-Konstanten.
 *   GFB_MASTER_KEYS = "1:base64-32byte-key,2:base64-32byte-key"
 *   GFB_ACTIVE_KEY_ID = "1"     (eine der vorhandenen Key-IDs)
 *
 * - DEK ("Data Encryption Key"): pro Datei / Datensatz neu zufällig (32 Byte).
 *   - Inhalt wird mit DEK verschlüsselt (AES-256-GCM, 12-Byte IV).
 *   - DEK wird mit der KEK gewrappt (AES-256-GCM, eigener IV).
 *   - Datei + gewrappte DEK + Key-ID werden persistiert.
 *
 * - Vorteile gegenüber direkter KEK-Verschlüsselung:
 *   - Key-Rotation ohne Re-Encryption des grossen Inhalts (nur DEKs neu wrappen).
 *   - Mehrere KEK-Generationen lesbar; nur eine ist "active".
 *   - DEK liegt nie im Klartext auf Disk.
 *
 * - Sicherheitsannahmen:
 *   - KEK ist NICHT in der DB. Wer DB klaut, hat Ciphertext, aber keine Schlüssel.
 *   - KEK liegt im File-System als Konstante in wp-config.php (durch das Hosting
 *     ausserhalb der Web-Wurzel zu legen wird empfohlen).
 *   - Wer den Webserver-Prozess kompromittiert, kann entschlüsseln. Das ist im
 *     server-at-rest-Modell akzeptiert; für stärkeres Bedrohungsmodell wäre
 *     E2EE nötig (siehe SECURITY.md).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Crypto {

	const VERSION                 = 1;
	const ENVELOPE_VERSION_BYTE   = 0x01;
	const CIPHER                  = 'aes-256-gcm';
	const KEY_BYTES               = 32; // 256 bit
	const IV_BYTES                = 12; // 96 bit, GCM-empfohlen
	const TAG_BYTES               = 16; // 128 bit
	const FIELD_ENVELOPE_PREFIX   = 'gfb-enc:v1:';

	/**
	 * Cached, decoded key map: array<string, string> mit "key-id" => raw_key (binary, 32 byte).
	 *
	 * @var array<string,string>|null
	 */
	private static $key_cache = null;

	/**
	 * Cached active key id.
	 *
	 * @var string|null
	 */
	private static $active_id_cache = null;

	/* ------------------------------------------------------------------ *
	 * Verfügbarkeit / Status
	 * ------------------------------------------------------------------ */

	/**
	 * @return bool True, wenn alle Voraussetzungen für Crypto vorhanden sind.
	 */
	public static function is_available() {
		return self::status()['ok'];
	}

	/**
	 * Status-Diagnose, für Admin-Notice und Settings-UI.
	 *
	 * @return array{ok:bool,reason:string,active_id:string,key_count:int}
	 */
	public static function status() {
		$result = array(
			'ok'        => false,
			'reason'    => '',
			'active_id' => '',
			'key_count' => 0,
		);

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$result['reason'] = __( 'PHP-Erweiterung "openssl" ist nicht verfügbar.', 'gutenberg-formbuilder' );
			return $result;
		}
		if ( ! in_array( self::CIPHER, openssl_get_cipher_methods( true ), true ) ) {
			$result['reason'] = __( 'AES-256-GCM ist in dieser OpenSSL-Version nicht verfügbar.', 'gutenberg-formbuilder' );
			return $result;
		}
		if ( ! defined( 'GFB_MASTER_KEYS' ) || '' === (string) GFB_MASTER_KEYS ) {
			$result['reason'] = __( 'wp-config.php fehlt: define( "GFB_MASTER_KEYS", "1:<base64-key>" );', 'gutenberg-formbuilder' );
			return $result;
		}

		$keys = self::load_keys();
		if ( empty( $keys ) ) {
			$result['reason'] = __( 'GFB_MASTER_KEYS konnte nicht dekodiert werden (Format: "id:base64key,...").', 'gutenberg-formbuilder' );
			return $result;
		}

		$active = self::active_key_id();
		if ( '' === $active || ! isset( $keys[ $active ] ) ) {
			$result['reason'] = __( 'GFB_ACTIVE_KEY_ID zeigt nicht auf einen bekannten Schlüssel aus GFB_MASTER_KEYS.', 'gutenberg-formbuilder' );
			return $result;
		}

		$result['ok']        = true;
		$result['active_id'] = $active;
		$result['key_count'] = count( $keys );
		return $result;
	}

	/**
	 * Hilfsfunktion zum Bootstrap: erzeugt einen frischen 256-Bit-Key in base64 für
	 * Anzeige im Setup. Für den Admin als Vorschlag, NICHT automatisch gespeichert.
	 *
	 * @return string base64
	 */
	public static function suggest_new_key_b64() {
		return self::b64_encode( random_bytes( self::KEY_BYTES ) );
	}

	/* ------------------------------------------------------------------ *
	 * Key-Management
	 * ------------------------------------------------------------------ */

	/**
	 * Aktive Key-ID (für neue Verschlüsselungen).
	 *
	 * @return string
	 */
	public static function active_key_id() {
		if ( null !== self::$active_id_cache ) {
			return self::$active_id_cache;
		}
		if ( defined( 'GFB_ACTIVE_KEY_ID' ) && '' !== (string) GFB_ACTIVE_KEY_ID ) {
			self::$active_id_cache = (string) GFB_ACTIVE_KEY_ID;
			return self::$active_id_cache;
		}
		// Fallback: niedrigste Key-ID aus GFB_MASTER_KEYS.
		$keys = self::load_keys();
		if ( empty( $keys ) ) {
			self::$active_id_cache = '';
			return '';
		}
		$ids = array_keys( $keys );
		sort( $ids, SORT_NATURAL );
		self::$active_id_cache = (string) $ids[0];
		return self::$active_id_cache;
	}

	/**
	 * @return array<string,string> Map id => raw_key_bytes
	 */
	public static function load_keys() {
		if ( null !== self::$key_cache ) {
			return self::$key_cache;
		}
		self::$key_cache = array();
		if ( ! defined( 'GFB_MASTER_KEYS' ) ) {
			return self::$key_cache;
		}
		$raw = trim( (string) GFB_MASTER_KEYS );
		if ( '' === $raw ) {
			return self::$key_cache;
		}
		$parts = explode( ',', $raw );
		foreach ( $parts as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry || false === strpos( $entry, ':' ) ) {
				continue;
			}
			list( $id, $key_b64 ) = explode( ':', $entry, 2 );
			$id      = trim( $id );
			$key_b64 = trim( $key_b64 );
			if ( '' === $id || '' === $key_b64 ) {
				continue;
			}
			$key = self::b64_decode( $key_b64 );
			if ( false === $key || strlen( $key ) !== self::KEY_BYTES ) {
				continue;
			}
			self::$key_cache[ $id ] = $key;
		}
		return self::$key_cache;
	}

	/**
	 * Hard-Reset des Caches (nur für Tests / Re-Key-Cron).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$key_cache       = null;
		self::$active_id_cache = null;
	}

	/* ------------------------------------------------------------------ *
	 * Generische AES-256-GCM-Routine
	 * ------------------------------------------------------------------ */

	/**
	 * Verschlüsselt einen Plaintext-String mit der angegebenen Key-Bytes.
	 *
	 * @param string $plain Rohdaten.
	 * @param string $key   32 Byte Key.
	 * @param string $aad   Optional. Additional Authenticated Data (z. B. Datei-ID).
	 * @return array{iv:string,tag:string,ct:string} Binärstrings.
	 * @throws RuntimeException Wenn Verschlüsselung fehlschlägt.
	 */
	public static function gcm_encrypt( $plain, $key, $aad = '' ) {
		if ( strlen( $key ) !== self::KEY_BYTES ) {
			throw new RuntimeException( 'GFB_Crypto: invalid key size' );
		}
		$iv  = random_bytes( self::IV_BYTES );
		$tag = '';
		$ct  = openssl_encrypt(
			$plain,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			(string) $aad,
			self::TAG_BYTES
		);
		if ( false === $ct ) {
			throw new RuntimeException( 'GFB_Crypto: encryption failed' );
		}
		return array(
			'iv'  => $iv,
			'tag' => $tag,
			'ct'  => $ct,
		);
	}

	/**
	 * Entschlüsselt mit der angegebenen Key-Bytes.
	 *
	 * @param string $ct  Ciphertext.
	 * @param string $key 32 Byte Key.
	 * @param string $iv  12 Byte IV.
	 * @param string $tag 16 Byte Tag.
	 * @param string $aad Additional Authenticated Data (gleicher Wert wie beim Encrypt).
	 * @return string|false Plaintext oder false bei Fehler.
	 */
	public static function gcm_decrypt( $ct, $key, $iv, $tag, $aad = '' ) {
		if ( strlen( $key ) !== self::KEY_BYTES ) {
			return false;
		}
		if ( strlen( $iv ) !== self::IV_BYTES || strlen( $tag ) !== self::TAG_BYTES ) {
			return false;
		}
		return openssl_decrypt(
			$ct,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			(string) $aad
		);
	}

	/* ------------------------------------------------------------------ *
	 * DEK-Wrapping
	 * ------------------------------------------------------------------ */

	/**
	 * Erzeugt einen frischen DEK (Random 32 Byte).
	 *
	 * @return string Binary DEK.
	 */
	public static function generate_dek() {
		return random_bytes( self::KEY_BYTES );
	}

	/**
	 * Wrappt einen DEK mit der derzeit aktiven KEK.
	 *
	 * @param string $dek Binärer DEK.
	 * @return array{key_id:string,iv_b64:string,tag_b64:string,ct_b64:string}
	 * @throws RuntimeException Wenn keine aktive KEK vorhanden.
	 */
	public static function wrap_dek( $dek ) {
		$keys      = self::load_keys();
		$active_id = self::active_key_id();
		if ( '' === $active_id || ! isset( $keys[ $active_id ] ) ) {
			throw new RuntimeException( 'GFB_Crypto: no active KEK available' );
		}
		$enc = self::gcm_encrypt( $dek, $keys[ $active_id ], 'gfb-dek-wrap-v1|' . $active_id );
		return array(
			'key_id'  => $active_id,
			'iv_b64'  => self::b64_encode( $enc['iv'] ),
			'tag_b64' => self::b64_encode( $enc['tag'] ),
			'ct_b64'  => self::b64_encode( $enc['ct'] ),
		);
	}

	/**
	 * Entwrappt einen DEK mit der angegebenen KEK-Generation.
	 *
	 * @param string $key_id  Key-ID, mit der gewrappt wurde.
	 * @param string $iv_b64  Base64-IV.
	 * @param string $tag_b64 Base64-Tag.
	 * @param string $ct_b64  Base64-Ciphertext.
	 * @return string|false Binary DEK oder false.
	 */
	public static function unwrap_dek( $key_id, $iv_b64, $tag_b64, $ct_b64 ) {
		$keys = self::load_keys();
		if ( ! isset( $keys[ $key_id ] ) ) {
			return false;
		}
		$iv  = self::b64_decode( $iv_b64 );
		$tag = self::b64_decode( $tag_b64 );
		$ct  = self::b64_decode( $ct_b64 );
		if ( false === $iv || false === $tag || false === $ct ) {
			return false;
		}
		return self::gcm_decrypt( $ct, $keys[ $key_id ], $iv, $tag, 'gfb-dek-wrap-v1|' . $key_id );
	}

	/**
	 * Re-Wrap eines DEK mit der aktuell aktiven KEK (Key-Rotation).
	 *
	 * @param string $key_id_old Bisherige Key-ID.
	 * @param string $iv_b64     Base64-IV des alten Wraps.
	 * @param string $tag_b64    Base64-Tag des alten Wraps.
	 * @param string $ct_b64     Base64-Ciphertext des alten Wraps.
	 * @return array{key_id:string,iv_b64:string,tag_b64:string,ct_b64:string}|false
	 */
	public static function rewrap_dek( $key_id_old, $iv_b64, $tag_b64, $ct_b64 ) {
		$dek = self::unwrap_dek( $key_id_old, $iv_b64, $tag_b64, $ct_b64 );
		if ( false === $dek ) {
			return false;
		}
		try {
			return self::wrap_dek( $dek );
		} finally {
			// Best effort, gegen langzeit-Reste im Speicher.
			$dek = str_repeat( "\0", strlen( $dek ) );
			unset( $dek );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Field-Encryption (Strings) — für Submission-Payload
	 * ------------------------------------------------------------------ */

	/**
	 * Verschlüsselt einen Stringwert (z. B. Form-Feldwert) und gibt
	 * eine selbstbeschreibende Envelope-Struktur zurück.
	 *
	 * @param string $plain Plaintext.
	 * @param string $aad   AAD (z. B. "field:<name>"); bindet Verschlüsselung an Kontext.
	 * @return array{_enc:string,key_id:string,iv:string,tag:string,ct:string}
	 * @throws RuntimeException Wenn Crypto nicht verfügbar.
	 */
	public static function encrypt_field( $plain, $aad = '' ) {
		$keys      = self::load_keys();
		$active_id = self::active_key_id();
		if ( '' === $active_id || ! isset( $keys[ $active_id ] ) ) {
			throw new RuntimeException( 'GFB_Crypto: no active KEK available' );
		}
		// Felder direkt mit aktiver KEK verschlüsseln (kleine Mengen, keine
		// Performance-Einsparung durch DEK-Indirection).
		$enc = self::gcm_encrypt( (string) $plain, $keys[ $active_id ], 'gfb-field-v1|' . $active_id . '|' . $aad );
		return array(
			'_enc'   => 'v1',
			'key_id' => $active_id,
			'iv'     => self::b64_encode( $enc['iv'] ),
			'tag'    => self::b64_encode( $enc['tag'] ),
			'ct'     => self::b64_encode( $enc['ct'] ),
		);
	}

	/**
	 * Erkennt envelope und entschlüsselt; gibt Plaintext oder false zurück.
	 *
	 * @param mixed  $maybe_envelope Wert, evtl. ein Envelope-Array.
	 * @param string $aad            AAD wie beim Encrypt.
	 * @return string|false
	 */
	public static function decrypt_field( $maybe_envelope, $aad = '' ) {
		if ( ! self::is_field_envelope( $maybe_envelope ) ) {
			return false;
		}
		$keys = self::load_keys();
		$id   = (string) $maybe_envelope['key_id'];
		if ( ! isset( $keys[ $id ] ) ) {
			return false;
		}
		$iv  = self::b64_decode( (string) $maybe_envelope['iv'] );
		$tag = self::b64_decode( (string) $maybe_envelope['tag'] );
		$ct  = self::b64_decode( (string) $maybe_envelope['ct'] );
		if ( false === $iv || false === $tag || false === $ct ) {
			return false;
		}
		return self::gcm_decrypt( $ct, $keys[ $id ], $iv, $tag, 'gfb-field-v1|' . $id . '|' . $aad );
	}

	/**
	 * @param mixed $value Beliebig.
	 * @return bool
	 */
	public static function is_field_envelope( $value ) {
		return is_array( $value )
			&& isset( $value['_enc'], $value['key_id'], $value['iv'], $value['tag'], $value['ct'] )
			&& 'v1' === $value['_enc'];
	}

	/* ------------------------------------------------------------------ *
	 * URL-safe Base64
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $bin Binary.
	 * @return string base64 (Standard-Alphabet, mit Padding).
	 */
	public static function b64_encode( $bin ) {
		return base64_encode( (string) $bin );
	}

	/**
	 * @param string $b64 base64.
	 * @return string|false
	 */
	public static function b64_decode( $b64 ) {
		return base64_decode( (string) $b64, true );
	}
}
