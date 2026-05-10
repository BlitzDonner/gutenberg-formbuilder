<?php
/**
 * ClamAV-Integration als Pflicht-Hook für Datei-Uploads.
 *
 * - Konfiguration in der Settings-Seite (Option `gfb_clamav_settings`).
 *   - mode: 'disabled' | 'binary' | 'socket'
 *   - binary_path: voller Pfad zu clamscan/clamdscan (mode=binary)
 *   - socket_path: Pfad zum clamd Unix-Socket (mode=socket)
 *   - timeout_sec: Sekunden (Default 30)
 *   - require_for_uploads: bool — wenn true und mode=disabled, werden Uploads
 *     hart abgewiesen, sobald ein Form ein File-Feld hat.
 *
 * - Die Klasse stellt scan_file($abs_path) bereit, was 'clean'|'infected'|'unavailable'
 *   zurückgibt; Submit-Handler entscheidet danach.
 *
 * - Selbsttest: scan_eicar() lädt das EICAR-Test-File (offizielles AV-Test-Muster)
 *   in tmp und scannt es; wenn das nicht als 'infected' erkannt wird, ist die
 *   Konfiguration kaputt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Clamav {

	const OPTION_KEY = 'gfb_clamav_settings';

	/**
	 * Migrations-Marker: einmalig auf den neuen sicheren Default
	 * (require_for_uploads = false) zwangsmigrieren, ohne bewusst gesetzte
	 * Werte später wieder zu überschreiben.
	 */
	const MIGRATION_OPTION_KEY = 'gfb_clamav_migration';

	/**
	 * @return array{mode:string,binary_path:string,socket_path:string,timeout_sec:int,require_for_uploads:bool}
	 */
	public static function get_settings() {
		$defaults = array(
			'mode'                => 'disabled',
			'binary_path'         => '',
			'socket_path'         => '',
			'timeout_sec'         => 30,
			// Standardmässig OPTIONAL: viele Hostings können ClamAV nicht
			// installieren. Admins, die strikten Modus wollen, können das
			// in den Einstellungen explizit aktivieren.
			'require_for_uploads' => false,
		);
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		return array_merge( $defaults, $opt );
	}

	/**
	 * Einmalige Migration: aeltere Installationen hatten require_for_uploads
	 * standardmässig auf true. Wenn der Admin nichts geändert hat (Modus
	 * ist 'disabled' und Flag ist auf true), nehmen wir die Pflicht herunter,
	 * damit Datei-Uploads weiter funktionieren.
	 *
	 * @return void
	 */
	public static function maybe_migrate_defaults() {
		if ( get_option( self::MIGRATION_OPTION_KEY ) === '2' ) {
			return;
		}
		$opt = get_option( self::OPTION_KEY, null );
		if ( is_array( $opt ) && isset( $opt['mode'] ) && 'disabled' === $opt['mode'] && ! empty( $opt['require_for_uploads'] ) ) {
			$opt['require_for_uploads'] = false;
			update_option( self::OPTION_KEY, $opt, false );
		}
		update_option( self::MIGRATION_OPTION_KEY, '2', false );
	}

	/**
	 * @param array<string,mixed> $values
	 * @return void
	 */
	public static function update_settings( array $values ) {
		$cur = self::get_settings();
		$new = array(
			'mode'                => in_array( $values['mode'] ?? '', array( 'disabled', 'binary', 'socket' ), true )
				? $values['mode']
				: $cur['mode'],
			'binary_path'         => self::sanitize_path( (string) ( $values['binary_path'] ?? $cur['binary_path'] ) ),
			'socket_path'         => self::sanitize_path( (string) ( $values['socket_path'] ?? $cur['socket_path'] ) ),
			'timeout_sec'         => max( 1, min( 600, (int) ( $values['timeout_sec'] ?? $cur['timeout_sec'] ) ) ),
			'require_for_uploads' => ! empty( $values['require_for_uploads'] ),
		);
		update_option( self::OPTION_KEY, $new, false );
	}

	/**
	 * Beschneidet/sanitisiert einen Dateipfad konservativ.
	 *
	 * @param string $p Pfad.
	 * @return string
	 */
	private static function sanitize_path( $p ) {
		$p = trim( $p );
		// Keine Steuerzeichen, keine Pipes/Backticks/Semikolons.
		if ( '' === $p ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x1F\x7F`;|&$\\\\<>\\n\\r]/', $p ) ) {
			return '';
		}
		return $p;
	}

	/**
	 * Erlaubt der Submit-Pipeline zu prüfen, ob Uploads überhaupt angenommen
	 * werden sollten (Pflicht-Modus + nicht konfiguriert -> nein).
	 *
	 * @return true|WP_Error
	 */
	public static function precondition_for_uploads() {
		$s = self::get_settings();
		if ( 'disabled' !== $s['mode'] ) {
			return true;
		}
		if ( ! $s['require_for_uploads'] ) {
			return true;
		}
		return new WP_Error(
			'gfb_clamav_required',
			__( 'Datei-Uploads sind aktuell blockiert: Die Option "Pflicht für Datei-Uploads" ist aktiv, aber kein ClamAV-Scanner eingerichtet. Lösung: in "Sicherheit & Einstellungen" entweder ClamAV korrekt konfigurieren ODER die Pflicht-Option deaktivieren.', 'gutenberg-formbuilder' )
		);
	}

	/**
	 * Scannt eine Datei.
	 *
	 * @param string $abs_path Absoluter Pfad.
	 * @return array{status:string,details:string} status: clean|infected|unavailable
	 */
	public static function scan_file( $abs_path ) {
		if ( ! is_readable( $abs_path ) ) {
			return array( 'status' => 'unavailable', 'details' => 'unreadable file' );
		}
		$s = self::get_settings();
		if ( 'disabled' === $s['mode'] ) {
			return array( 'status' => 'unavailable', 'details' => 'disabled' );
		}
		if ( 'binary' === $s['mode'] ) {
			return self::scan_via_binary( $abs_path, $s );
		}
		if ( 'socket' === $s['mode'] ) {
			return self::scan_via_socket( $abs_path, $s );
		}
		return array( 'status' => 'unavailable', 'details' => 'invalid mode' );
	}

	/**
	 * Sucht typische Installations-Pfade für clamdscan/clamscan und den
	 * clamd-Unix-Socket. Gibt nur wirklich vorhandene Pfade zurück.
	 *
	 * @return array{binaries:array<int,string>,sockets:array<int,string>}
	 */
	public static function detect_candidates() {
		$bin_candidates = array(
			'/usr/bin/clamdscan',
			'/usr/local/bin/clamdscan',
			'/opt/homebrew/bin/clamdscan',
			'/opt/homebrew/sbin/clamdscan',
			'/usr/bin/clamscan',
			'/usr/local/bin/clamscan',
			'/opt/homebrew/bin/clamscan',
			'/opt/homebrew/sbin/clamscan',
			'/opt/cpanel/ea-clamav/sbin/clamscan',
			'/opt/cpanel/ea-clamav/sbin/clamdscan',
		);
		$sock_candidates = array(
			'/var/run/clamd.scan/clamd.sock',
			'/var/run/clamav/clamd.ctl',
			'/var/run/clamd/clamd.sock',
			'/run/clamav/clamd.ctl',
			'/run/clamd.scan/clamd.sock',
			'/tmp/clamd.socket',
			'/usr/local/var/run/clamav/clamd.sock',
			'/opt/homebrew/var/run/clamav/clamd.sock',
		);

		$bins  = array();
		$socks = array();
		foreach ( $bin_candidates as $p ) {
			if ( @is_executable( $p ) ) {
				$bins[] = $p;
			}
		}
		foreach ( $sock_candidates as $p ) {
			if ( @file_exists( $p ) ) {
				$socks[] = $p;
			}
		}
		return array(
			'binaries' => $bins,
			'sockets'  => $socks,
		);
	}

	/**
	 * Loadtest: schreibt EICAR und scannt; muss 'infected' sein.
	 *
	 * @return array{status:string,details:string}
	 */
	public static function scan_eicar() {
		// Standard-EICAR-Testsignatur (offiziell), bewusst NICHT als ein
		// einziger String, damit Quell-Scanner sie nicht beim Lesen flaggen.
		$eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}' . '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$' . 'H+H*';
		$tmp   = wp_tempnam( 'gfb-eicar' );
		if ( ! $tmp ) {
			return array( 'status' => 'unavailable', 'details' => 'tmpfile failed' );
		}
		@file_put_contents( $tmp, $eicar );
		$res = self::scan_file( $tmp );
		@unlink( $tmp );
		return $res;
	}

	/**
	 * @param string $abs_path
	 * @param array  $s
	 * @return array{status:string,details:string}
	 */
	private static function scan_via_binary( $abs_path, array $s ) {
		$bin = $s['binary_path'];
		if ( '' === $bin || ! @is_executable( $bin ) ) {
			return array( 'status' => 'unavailable', 'details' => 'binary not executable' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return array( 'status' => 'unavailable', 'details' => 'proc_open disabled' );
		}

		$args     = array( $bin, '--no-summary', '--stdout', '--', $abs_path );
		$cmd_safe = self::build_cmd( $args );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = @proc_open( $cmd_safe, $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array( 'status' => 'unavailable', 'details' => 'proc_open failed' );
		}
		fclose( $pipes[0] );

		// Timeout-Handling.
		$start  = microtime( true );
		$stdout = '';
		$stderr = '';
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		while ( true ) {
			$status = proc_get_status( $proc );
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			if ( ! $status['running'] ) {
				break;
			}
			if ( ( microtime( true ) - $start ) > (float) $s['timeout_sec'] ) {
				proc_terminate( $proc, 9 );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $proc );
				return array( 'status' => 'unavailable', 'details' => 'timeout' );
			}
			usleep( 50000 );
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $proc );
		// clamscan: 0 = OK, 1 = infected, 2+ = error.
		if ( 1 === $exit ) {
			$line = trim( strtok( $stdout, "\n" ) );
			return array( 'status' => 'infected', 'details' => $line );
		}
		if ( 0 === $exit ) {
			return array( 'status' => 'clean', 'details' => '' );
		}
		return array(
			'status'  => 'unavailable',
			'details' => 'exit=' . (int) $exit . ' stderr=' . mb_substr( trim( $stderr ), 0, 200 ),
		);
	}

	/**
	 * @param string $abs_path
	 * @param array  $s
	 * @return array{status:string,details:string}
	 */
	private static function scan_via_socket( $abs_path, array $s ) {
		$sock_path = $s['socket_path'];
		if ( '' === $sock_path || ! @file_exists( $sock_path ) ) {
			return array( 'status' => 'unavailable', 'details' => 'socket missing' );
		}
		$sock = @stream_socket_client( 'unix://' . $sock_path, $errno, $errstr, (float) $s['timeout_sec'] );
		if ( ! $sock ) {
			return array( 'status' => 'unavailable', 'details' => 'connect: ' . $errstr );
		}
		stream_set_timeout( $sock, (int) $s['timeout_sec'] );
		// SCAN nimmt einen Dateipfad an; clamd muss Lesezugriff haben.
		fwrite( $sock, "nSCAN " . $abs_path . "\n" );
		$resp = '';
		while ( ! feof( $sock ) ) {
			$resp .= fread( $sock, 4096 );
		}
		fclose( $sock );
		$line = trim( $resp );
		if ( false !== strpos( $line, 'OK' ) && false === strpos( $line, 'FOUND' ) ) {
			return array( 'status' => 'clean', 'details' => $line );
		}
		if ( false !== strpos( $line, 'FOUND' ) ) {
			return array( 'status' => 'infected', 'details' => $line );
		}
		return array( 'status' => 'unavailable', 'details' => $line );
	}

	/**
	 * Baut einen platform-sicheren Shell-String aus einem args-Array.
	 *
	 * @param array<int,string> $args
	 * @return string
	 */
	private static function build_cmd( array $args ) {
		$out = '';
		foreach ( $args as $a ) {
			$out .= ( '' === $out ? '' : ' ' ) . escapeshellarg( (string) $a );
		}
		return $out;
	}
}
