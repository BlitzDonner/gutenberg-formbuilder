<?php
/**
 * Sicherheits-Helfer für das Gutenberg-Formbuilder-Plugin.
 *
 * Bündelt:
 * - Client-IP-Ermittlung mit Trusted-Proxy-Liste
 * - HMAC-signierte Anti-Replay-Token
 * - Pro-Instanz-Honeypot-Feldname (deterministisch, daher serverseitig wieder prüfbar)
 * - Datei-Upload-Validation (Endungen, Doppel-Endungen, Magic-Bytes via finfo)
 * - Eigene Upload-Verzeichnisse mit .htaccess-Schutz
 * - DSGVO: IP-Pseudonymisierung, Personal-Data-Exporter/Eraser
 * - Einheitlicher Security-Event-Hook für Logging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Security {

	const TOKEN_TTL_SECONDS = 3600;
	const TOKEN_MIN_AGE     = 2;
	const UPLOAD_SUBDIR     = 'gfb';

	/**
	 * Hooks initialisieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_personal_data_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_personal_data_eraser' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Security-Event-Logger
	 * ------------------------------------------------------------------ */

	/**
	 * Einheitliche Schnittstelle für Logging/Monitoring.
	 *
	 * @param string              $type    Stabiler Event-Slug (z. B. file_reject_double_ext).
	 * @param array<string,mixed> $context Beliebige Zusatzinformationen (KEINE Roh-Werte aus User-Input).
	 * @return void
	 */
	public static function log_event( $type, array $context = array() ) {
		$context = array_merge(
			array(
				'ip'   => self::get_client_ip(),
				'time' => time(),
			),
			$context
		);

		/**
		 * Fires for every security-relevant event in the plugin.
		 *
		 * @param string              $type    Event slug.
		 * @param array<string,mixed> $context Sanitized context.
		 */
		do_action( 'gfb_security_event', $type, $context );
	}

	/* ------------------------------------------------------------------ *
	 * Client-IP / Rate-Limit-Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Ermittelt die Client-IP unter Berücksichtigung einer optionalen
	 * Trusted-Proxy-Liste. Standard: ausschliesslich REMOTE_ADDR.
	 *
	 * Konfiguration:
	 *   add_filter( 'gfb_trusted_proxies', function () {
	 *       return array( '10.0.0.0/8', '172.16.0.0/12' );
	 *   } );
	 *
	 * @return string IPv4/IPv6-Adresse oder leerer String.
	 */
	public static function get_client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		/**
		 * Liste vertrauenswürdiger Reverse-Proxies (CIDR oder einzelne IPs).
		 *
		 * @param array<int,string> $proxies CIDR/IP-Liste.
		 */
		$trusted = apply_filters( 'gfb_trusted_proxies', array() );
		if ( empty( $trusted ) || '' === $remote ) {
			return $remote;
		}

		if ( ! self::ip_in_any_range( $remote, $trusted ) ) {
			return $remote;
		}

		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
		if ( '' === $xff ) {
			return $remote;
		}

		$candidates = array_map( 'trim', explode( ',', $xff ) );
		// Linkster Eintrag = Original-Client; nimm den ersten validen.
		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return $remote;
	}

	/**
	 * Pseudonymisiert IPs (IPv4: /24, IPv6: /64) für DSGVO-konformes Logging,
	 * falls per Filter aktiviert.
	 *
	 * @param string $ip Eingangs-IP.
	 * @return string Pseudonymisierte oder unveränderte IP.
	 */
	public static function maybe_pseudonymize_ip( $ip ) {
		if ( '' === $ip ) {
			return $ip;
		}
		/**
		 * Soll die IP-Adresse vor Persistierung pseudonymisiert werden?
		 *
		 * @param bool $pseudonymize Default false.
		 */
		if ( ! apply_filters( 'gfb_pseudonymize_ip', false ) ) {
			return $ip;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts                                       = explode( '.', $ip );
			$parts[ count( $parts ) - 1 ] = '0';
			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip );
			if ( false === $packed || strlen( $packed ) < 8 ) {
				return $ip;
			}
			// Erste 8 Bytes (=/64) behalten, Rest auf 0.
			$packed = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
			$res    = @inet_ntop( $packed );
			return false === $res ? $ip : $res;
		}

		return $ip;
	}

	/**
	 * Ist eine IP-Adresse in irgendeinem CIDR-/IP-Bereich?
	 *
	 * @param string            $ip      IP.
	 * @param array<int,string> $ranges  CIDR-Liste oder einzelne IPs.
	 * @return bool
	 */
	private static function ip_in_any_range( $ip, array $ranges ) {
		foreach ( $ranges as $range ) {
			$range = trim( (string) $range );
			if ( '' === $range ) {
				continue;
			}
			if ( false === strpos( $range, '/' ) ) {
				if ( $range === $ip ) {
					return true;
				}
				continue;
			}
			if ( self::ip_in_cidr( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IPv4/IPv6-CIDR-Match.
	 *
	 * @param string $ip   IP-Adresse.
	 * @param string $cidr CIDR-Notation.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $mask_bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );
		$mask_bits                  = (int) $mask_bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bytes_full   = (int) floor( $mask_bits / 8 );
		$bits_partial = $mask_bits % 8;

		if ( $bytes_full > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $bytes_full ) ) {
			return false;
		}

		if ( 0 === $bits_partial ) {
			return true;
		}

		$mask_byte = chr( ( 0xFF << ( 8 - $bits_partial ) ) & 0xFF );
		return ( $ip_bin[ $bytes_full ] & $mask_byte ) === ( $subnet_bin[ $bytes_full ] & $mask_byte );
	}

	/* ------------------------------------------------------------------ *
	 * Anti-Replay-HMAC-Token + Honeypot
	 * ------------------------------------------------------------------ */

	/**
	 * Schlüssel für HMAC. Nutzt wp_salt('auth') als Stamm und einen statischen
	 * Plugin-Suffix; NICHT dieselbe Rolle wie Nonces, daher trennbar.
	 *
	 * @return string
	 */
	private static function token_key() {
		return wp_salt( 'auth' ) . '|gfb-anti-replay-v1';
	}

	/**
	 * Erzeugt einen Token, der den Render-Zeitpunkt + Form-Identität signiert.
	 *
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID (sanitize_key).
	 * @param string $instance_id Block-Instanz-ID.
	 * @param string $hp_field    Honeypot-Feldname.
	 * @return string Token im Format ts.b64sig.
	 */
	public static function create_token( $post_id, $form_id, $instance_id, $hp_field ) {
		$ts      = time();
		$payload = self::token_payload( $post_id, $form_id, $instance_id, $hp_field, $ts );
		$sig     = hash_hmac( 'sha256', $payload, self::token_key(), true );
		return $ts . '.' . rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
	}

	/**
	 * Verifiziert einen Token.
	 *
	 * @param string $token       Erhaltener Token.
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID.
	 * @param string $instance_id Block-Instanz-ID.
	 * @param string $hp_field    Honeypot-Feldname.
	 * @return bool
	 */
	public static function verify_token( $token, $post_id, $form_id, $instance_id, $hp_field ) {
		if ( ! is_string( $token ) || '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}
		list( $ts_str, $sig_b64 ) = explode( '.', $token, 2 );
		$ts                       = (int) $ts_str;
		if ( $ts <= 0 ) {
			return false;
		}
		$age = time() - $ts;
		if ( $age < self::TOKEN_MIN_AGE || $age > self::TOKEN_TTL_SECONDS ) {
			return false;
		}
		$sig = base64_decode( strtr( $sig_b64, '-_', '+/' ), true );
		if ( false === $sig ) {
			return false;
		}
		$expected = hash_hmac(
			'sha256',
			self::token_payload( $post_id, $form_id, $instance_id, $hp_field, $ts ),
			self::token_key(),
			true
		);
		return hash_equals( $expected, $sig );
	}

	/**
	 * @return string
	 */
	private static function token_payload( $post_id, $form_id, $instance_id, $hp_field, $ts ) {
		return implode(
			'|',
			array(
				'gfb-token-v1',
				(int) $post_id,
				(string) $form_id,
				(string) $instance_id,
				(string) $hp_field,
				(int) $ts,
			)
		);
	}

	/**
	 * Deterministischer Honeypot-Feldname pro Form-Instanz.
	 *
	 * Vorteile gegenüber konstanten Namen:
	 * - Bots können nicht generisch alle gleich-benamten Felder überspringen.
	 * - Vorteile gegenüber random-pro-Render: serverseitig ohne Session prüfbar.
	 *
	 * @param int    $post_id     Post-ID.
	 * @param string $form_id     Form-ID.
	 * @param string $instance_id Instanz-ID.
	 * @return string Feldname (nur a-z0-9_).
	 */
	public static function honeypot_field_name( $post_id, $form_id, $instance_id ) {
		$hash = hash_hmac(
			'sha256',
			'gfb-honeypot-v1|' . (int) $post_id . '|' . $form_id . '|' . $instance_id,
			self::token_key()
		);
		return 'gfb_h_' . substr( $hash, 0, 12 );
	}

	/* ------------------------------------------------------------------ *
	 * Datei-Upload-Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Default-Whitelist für File-Uploads. Bewusst konservativ: kein SVG, keine
	 * HTML/JS-Formate, keine Office-Makros können rein, weil text/plain durch
	 * MIME-Sniffing oft mehrdeutig ist.
	 *
	 * Pro Endung das per finfo akzeptable Set an realen MIME-Typen.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function default_allowed_mimes_map() {
		$map = array(
			'pdf'  => array( 'application/pdf' ),
			'png'  => array( 'image/png' ),
			'jpg'  => array( 'image/jpeg' ),
			'jpeg' => array( 'image/jpeg' ),
			'gif'  => array( 'image/gif' ),
			'webp' => array( 'image/webp' ),
			'doc'  => array( 'application/msword', 'application/vnd.ms-office', 'application/octet-stream' ),
			'docx' => array(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/zip',
				'application/octet-stream',
			),
			'xls'  => array( 'application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream' ),
			'xlsx' => array(
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/zip',
				'application/octet-stream',
			),
			'ppt'  => array( 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream' ),
			'pptx' => array(
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'application/zip',
				'application/octet-stream',
			),
			'txt'  => array( 'text/plain' ),
			'csv'  => array( 'text/csv', 'text/plain', 'application/csv' ),
			'zip'  => array( 'application/zip', 'application/octet-stream' ),
		);

		/**
		 * Erlaubte Datei-Endungen plus zugehörige reale MIME-Typen.
		 * Schlüssel = lowercased Endung ohne Punkt; Werte = Liste valider MIME-Strings.
		 *
		 * Beispiel zum Erweitern (z. B. für Audio):
		 *   add_filter( 'gfb_allowed_mimes', function ( $map ) {
		 *       $map['mp3'] = array( 'audio/mpeg' );
		 *       return $map;
		 *   } );
		 *
		 * @param array<string,array<int,string>> $map Ext => Liste MIME-Strings.
		 */
		return apply_filters( 'gfb_allowed_mimes', $map );
	}

	/**
	 * Endungen, deren Vorkommen IRGENDWO im Original-Filename die Datei
	 * sofort ablehnt (Polyglot-/Doppel-Endung-Schutz).
	 *
	 * @return array<int,string>
	 */
	public static function blocked_extension_tokens() {
		$blocked = array(
			'php', 'phtml', 'phps', 'phar', 'pht', 'php3', 'php4', 'php5', 'php7',
			'pl', 'py', 'cgi', 'sh', 'asp', 'aspx', 'jsp', 'jspx', 'cer',
			'exe', 'msi', 'dll', 'bat', 'cmd', 'com', 'vbs', 'wsf', 'jar',
			'js', 'mjs', 'svg', 'svgz', 'htm', 'html', 'shtml', 'xhtml', 'xht',
			'htaccess', 'htpasswd',
		);

		/**
		 * Token, deren Auftauchen im Filename die Datei ablehnt.
		 *
		 * @param array<int,string> $blocked Liste lowercased Endungstoken.
		 */
		return apply_filters( 'gfb_blocked_extension_tokens', $blocked );
	}

	/**
	 * Vollständige Validierung einer hochgeladenen Datei (nutzt finfo).
	 *
	 * Reihenfolge: Filename-Form, Doppel-Endung, Endung in Whitelist, real MIME via finfo,
	 * MIME passt zu Endung, accept-Prüfung. Fehler liefern WP_Error mit i18n-Texten.
	 *
	 * @param array<string,mixed> $file_array Eintrag aus $_FILES.
	 * @param string              $accept     Block-Attribut "accept" (kann leer sein).
	 * @return array{ext:string,mime:string}|WP_Error Erlaubte Endung + sicherer MIME.
	 */
	public static function validate_uploaded_file( array $file_array, $accept = '' ) {
		$original = isset( $file_array['name'] ) ? (string) $file_array['name'] : '';
		$tmp      = isset( $file_array['tmp_name'] ) ? (string) $file_array['tmp_name'] : '';

		if ( '' === $original || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::log_event( 'file_reject_no_upload' );
			return new WP_Error( 'gfb_file_invalid', __( 'Ungültiger Datei-Upload.', 'gutenberg-formbuilder' ) );
		}

		$basename = wp_basename( $original );

		// Verbiete Steuerzeichen, Null-Bytes, Backslash-Pfadtrenner.
		if ( preg_match( '/[\x00-\x1F\x7F]|\\\\/', $basename ) ) {
			self::log_event( 'file_reject_control_chars' );
			return new WP_Error( 'gfb_file_name', __( 'Ungültiger Dateiname.', 'gutenberg-formbuilder' ) );
		}

		$lower = strtolower( $basename );

		// Doppel-Endungs-/Polyglot-Schutz: jedes verbotene Token irgendwo in den Endungs-Segmenten.
		$segments = explode( '.', $lower );
		array_shift( $segments ); // erstes Element ist Basisname, ohne Endung
		$blocked = self::blocked_extension_tokens();
		foreach ( $segments as $seg ) {
			if ( in_array( $seg, $blocked, true ) ) {
				self::log_event(
					'file_reject_double_ext',
					array( 'ext' => $seg )
				);
				return new WP_Error( 'gfb_file_ext', __( 'Dieser Dateityp ist nicht erlaubt.', 'gutenberg-formbuilder' ) );
			}
		}

		$ext_index = strrpos( $lower, '.' );
		if ( false === $ext_index || $ext_index === strlen( $lower ) - 1 ) {
			self::log_event( 'file_reject_no_ext' );
			return new WP_Error( 'gfb_file_ext', __( 'Dateien ohne Endung sind nicht erlaubt.', 'gutenberg-formbuilder' ) );
		}
		$ext = substr( $lower, $ext_index + 1 );

		$allowed_map = self::default_allowed_mimes_map();
		if ( ! isset( $allowed_map[ $ext ] ) ) {
			self::log_event( 'file_reject_ext_not_whitelisted', array( 'ext' => $ext ) );
			return new WP_Error( 'gfb_file_ext', __( 'Dieser Dateityp ist nicht erlaubt.', 'gutenberg-formbuilder' ) );
		}

		// finfo MIME aus Inhalt (Magic Bytes).
		$detected = '';
		if ( function_exists( 'finfo_open' ) ) {
			$f = @finfo_open( FILEINFO_MIME_TYPE );
			if ( $f ) {
				$detected = (string) @finfo_file( $f, $tmp );
				@finfo_close( $f );
			}
		}
		if ( '' === $detected && function_exists( 'mime_content_type' ) ) {
			$detected = (string) @mime_content_type( $tmp );
		}
		if ( '' === $detected ) {
			self::log_event( 'file_reject_no_finfo', array( 'ext' => $ext ) );
			return new WP_Error( 'gfb_file_mime', __( 'Dateityp konnte nicht verifiziert werden.', 'gutenberg-formbuilder' ) );
		}

		$detected_lower = strtolower( $detected );
		$valid_mimes    = array_map( 'strtolower', $allowed_map[ $ext ] );
		if ( ! in_array( $detected_lower, $valid_mimes, true ) ) {
			self::log_event(
				'file_reject_mime_mismatch',
				array(
					'ext'      => $ext,
					'detected' => $detected_lower,
				)
			);
			return new WP_Error( 'gfb_file_mime', __( 'Dateiinhalt passt nicht zur Endung.', 'gutenberg-formbuilder' ) );
		}

		// accept-Prüfung VOR Upload (sowohl Endung als auch MIME).
		if ( '' !== $accept && ! self::accept_matches( $accept, $ext, $detected_lower ) ) {
			self::log_event(
				'file_reject_accept_mismatch',
				array(
					'ext'      => $ext,
					'detected' => $detected_lower,
				)
			);
			return new WP_Error( 'gfb_file_accept', __( 'Dateityp ist für dieses Feld nicht erlaubt.', 'gutenberg-formbuilder' ) );
		}

		return array(
			'ext'  => $ext,
			'mime' => $detected_lower,
		);
	}

	/**
	 * Prüft accept-String (z. B. ".pdf,image/*") gegen ein Tupel (ext, mime).
	 *
	 * @param string $accept    Accept-Attribut.
	 * @param string $ext       Endung ohne Punkt (lowercased).
	 * @param string $mime      Realer MIME-Typ (lowercased).
	 * @return bool
	 */
	public static function accept_matches( $accept, $ext, $mime ) {
		$parts = array_filter( array_map( 'trim', explode( ',', $accept ) ) );
		if ( empty( $parts ) ) {
			return true;
		}
		foreach ( $parts as $part ) {
			$p = strtolower( $part );
			if ( '' === $p ) {
				continue;
			}
			if ( '.' === $p[0] ) {
				if ( ltrim( $p, '.' ) === $ext ) {
					return true;
				}
				continue;
			}
			if ( false !== strpos( $p, '/*' ) ) {
				$prefix = str_replace( '/*', '/', $p );
				if ( $mime && 0 === strpos( $mime, $prefix ) ) {
					return true;
				}
				continue;
			}
			if ( $mime === $p ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Liefert (und legt bei Bedarf an) das Plugin-eigene Upload-Verzeichnis,
	 * inkl. .htaccess + index.html zur Absicherung. Nutzbar als upload_dir-Filter.
	 *
	 * @param array<string,mixed> $dirs Default upload_dir-Array.
	 * @return array<string,mixed>
	 */
	public static function filter_upload_dir( $dirs ) {
		$sub = '/' . self::UPLOAD_SUBDIR . ( isset( $dirs['subdir'] ) ? $dirs['subdir'] : '' );
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		$dirs['subdir'] = $sub;

		// Sicherheits-Dateien einmalig anlegen (idempotent).
		self::ensure_upload_security_files( $dirs['basedir'] . '/' . self::UPLOAD_SUBDIR );
		return $dirs;
	}

	/**
	 * Legt .htaccess + index.html in (und über) dem Upload-Verzeichnis an.
	 *
	 * @param string $base Absoluter Pfad (z. B. wp-content/uploads/gfb).
	 * @return void
	 */
	public static function ensure_upload_security_files( $base ) {
		if ( ! wp_mkdir_p( $base ) ) {
			return;
		}

		$ht = $base . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			$rules  = "# Auto-generiert von Blitz & Donner Formular. Nicht ändern.\n";
			$rules .= "Options -Indexes\n";
			$rules .= "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n";
			$rules .= "<IfModule mod_php8.c>\nphp_flag engine off\n</IfModule>\n";
			$rules .= "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n";
			$rules .= "<FilesMatch \"\\.(php|phtml|phps|phar|pht|php3|php4|php5|php7|pl|py|cgi|sh|asp|aspx|jsp|jspx|exe|cer)$\">\n";
			$rules .= "    Require all denied\n";
			$rules .= "</FilesMatch>\n";
			$rules .= "AddType text/plain .html .htm .shtml .svg .svgz .xml .js .mjs\n";
			@file_put_contents( $ht, $rules );
		}

		$index = $base . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
	}

	/**
	 * Erzeugt einen unique_filename_callback, der den endgültigen Dateinamen
	 * komplett unter Plugin-Kontrolle hält: gfb_<random>.<ext>.
	 *
	 * @param string $ext Validierte Endung (ohne Punkt).
	 * @return callable
	 */
	public static function unique_filename_callback( $ext ) {
		return static function ( $dir, $name, $ext_unused ) use ( $ext ) {
			$random = wp_generate_password( 16, false, false );
			$candidate = 'gfb_' . $random . '.' . $ext;
			$try = $candidate;
			$i   = 1;
			while ( file_exists( trailingslashit( $dir ) . $try ) ) {
				$try = 'gfb_' . $random . '_' . $i . '.' . $ext;
				++$i;
				if ( $i > 50 ) {
					break;
				}
			}
			return $try;
		};
	}

	/* ------------------------------------------------------------------ *
	 * DSGVO: Personal Data
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_personal_data_exporter( $exporters ) {
		$exporters['gutenberg-formbuilder'] = array(
			'exporter_friendly_name' => __( 'Blitz & Donner Formular', 'gutenberg-formbuilder' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_personal_data_eraser( $erasers ) {
		$erasers['gutenberg-formbuilder'] = array(
			'eraser_friendly_name' => __( 'Blitz & Donner Formular', 'gutenberg-formbuilder' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Exportiert Submissions, in denen die E-Mail-Adresse vorkommt.
	 *
	 * @param string $email_address E-Mail.
	 * @param int    $page          1-basiert.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gfb_submissions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, created_at, post_id, form_id, form_title, payload, ip_address, user_agent FROM {$table} WHERE payload LIKE %s ORDER BY id ASC LIMIT 200 OFFSET %d",
				'%' . $wpdb->esc_like( (string) $email_address ) . '%',
				( max( 1, (int) $page ) - 1 ) * 200
			)
		);

		$data = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) $row->payload, true );
				if ( ! is_array( $payload ) ) {
					$payload = array();
				}
				$items = array(
					array(
						'name'  => __( 'Datum', 'gutenberg-formbuilder' ),
						'value' => (string) $row->created_at,
					),
					array(
						'name'  => __( 'Formular-ID', 'gutenberg-formbuilder' ),
						'value' => (string) $row->form_id,
					),
					array(
						'name'  => __( 'Formularname', 'gutenberg-formbuilder' ),
						'value' => isset( $row->form_title ) ? (string) $row->form_title : '',
					),
					array(
						'name'  => __( 'Beitrag', 'gutenberg-formbuilder' ),
						'value' => (string) $row->post_id,
					),
					array(
						'name'  => __( 'IP-Adresse', 'gutenberg-formbuilder' ),
						'value' => (string) $row->ip_address,
					),
					array(
						'name'  => __( 'User-Agent', 'gutenberg-formbuilder' ),
						'value' => (string) $row->user_agent,
					),
				);
				foreach ( $payload as $k => $v ) {
					if ( '_gfb_labels' === $k ) {
						continue;
					}
					$items[] = array(
						'name'  => (string) $k,
						'value' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ),
					);
				}
				$data[] = array(
					'group_id'    => 'gfb-submissions',
					'group_label' => __( 'Formular-Einsendungen', 'gutenberg-formbuilder' ),
					'item_id'     => 'gfb-submission-' . (int) $row->id,
					'data'        => $items,
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Löscht alle Submissions, in deren payload die E-Mail vorkommt.
	 *
	 * @param string $email_address E-Mail.
	 * @return array{items_removed:int,items_retained:int,messages:array<int,string>,done:bool}
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gfb_submissions';
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE payload LIKE %s ORDER BY id ASC LIMIT 200",
				'%' . $wpdb->esc_like( (string) $email_address ) . '%'
			)
		);

		$removed = 0;
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$ok = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
				if ( false !== $ok ) {
					++$removed;
				}
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
