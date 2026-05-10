<?php
/**
 * Verschlüsselter Datei-Speicher.
 *
 * - Dateien liegen nicht unter öffentlicher Medien-URL. Default-Pfad:
 *     wp-content/.gfb-private/gfb-encrypted/
 *   Per Filter `gfb_private_storage_dir` überschreibbar.
 *
 * - Dateinamen sind random-IDs (32 hex chars), KEINE Originalnamen.
 *
 * - Inhalt wird mit AES-256-GCM verschlüsselt; pro Datei eigener DEK.
 *   DEK wird mit der aktiven KEK (GFB_Crypto) gewrappt.
 *
 * - Metadaten in Tabelle wp_gfb_files:
 *     id            BIGINT
 *     submission_id BIGINT (Fremdschlüssel logisch zu wp_gfb_submissions)
 *     field_name    VARCHAR(190)
 *     original_name VARCHAR(255)
 *     mime          VARCHAR(120)
 *     size_bytes    BIGINT
 *     sha256_plain  CHAR(64)
 *     storage_id    CHAR(32)
 *     storage_path  VARCHAR(255)  (relativ zu Storage-Root)
 *     key_id        VARCHAR(32)   (Generation der KEK, mit der DEK gewrappt wurde)
 *     dek_iv        VARCHAR(32)   (base64)
 *     dek_tag       VARCHAR(32)   (base64)
 *     dek_ct        VARCHAR(128)  (base64)
 *     content_iv    VARCHAR(32)   (base64) — IV für den Inhalt
 *     content_tag   VARCHAR(32)   (base64) — GCM-Tag für den Inhalt
 *     created_at    DATETIME
 *
 * - Downloads laufen ausschliesslich über `?action=gfb_download&fid=ID&_wpnonce=...`
 *   im wp-admin-Kontext; Cap-Check (gfb_download_files), Audit-Log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_File_Storage {

	/**
	 * Default-Subdir innerhalb von WP_CONTENT_DIR.
	 *
	 * Beginnt bewusst mit einem Punkt: nahezu alle Webserver (Apache via mod_dir,
	 * Nginx mit der Standard-`location ~ /\\.`-Regel) blockieren Dotfiles und
	 * Dot-Verzeichnisse mit 403/404 - damit ist der Pfad bereits ohne weitere
	 * Konfiguration nicht öffentlich erreichbar. Plugin-`.htaccess` wirkt
	 * ergänzend nur auf Apache.
	 *
	 * Per Filter `gfb_private_storage_dir` überschreibbar (z. B. nach
	 * /var/lib/gfb-storage ausserhalb des Webroots).
	 */
	const SUBDIR = '.gfb-private/gfb-encrypted';

	/**
	 * Hooks initialisieren.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_gfb_download', array( __CLASS__, 'handle_download' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Schema
	 * ------------------------------------------------------------------ */

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gfb_files';
	}

	/**
	 * Tabelle anlegen / aktualisieren.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field_name VARCHAR(190) NOT NULL DEFAULT '',
			original_name VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(120) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sha256_plain CHAR(64) NOT NULL DEFAULT '',
			storage_id CHAR(32) NOT NULL DEFAULT '',
			storage_path VARCHAR(255) NOT NULL DEFAULT '',
			key_id VARCHAR(32) NOT NULL DEFAULT '',
			dek_iv VARCHAR(32) NOT NULL DEFAULT '',
			dek_tag VARCHAR(32) NOT NULL DEFAULT '',
			dek_ct VARCHAR(128) NOT NULL DEFAULT '',
			content_iv VARCHAR(32) NOT NULL DEFAULT '',
			content_tag VARCHAR(32) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY submission_idx (submission_id),
			KEY storage_idx (storage_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------ *
	 * Storage-Pfad
	 * ------------------------------------------------------------------ */

	/**
	 * Absoluter Storage-Root.
	 *
	 * @return string
	 */
	public static function storage_root() {
		$default = trailingslashit( WP_CONTENT_DIR ) . self::SUBDIR;
		/**
		 * Erlaubt Site-Owner, Storage z. B. nach /var/lib/gfb-storage zu legen.
		 *
		 * @param string $dir Standard: `WP_CONTENT_DIR` + `/.gfb-private/gfb-encrypted` (siehe SUBDIR).
		 */
		$dir = (string) apply_filters( 'gfb_private_storage_dir', $default );
		return rtrim( $dir, '/\\' );
	}

	/**
	 * Sicherheitsdateien (.htaccess + index.html + nginx.conf-Hint) anlegen.
	 *
	 * @return bool true wenn alles vorhanden / angelegt.
	 */
	public static function ensure_storage_directory() {
		$root = self::storage_root();
		if ( ! wp_mkdir_p( $root ) ) {
			return false;
		}

		$ht = $root . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			$rules  = "# Auto-generiert von Gutenberg Formbuilder. Sperrt jeglichen Direktzugriff.\n";
			$rules .= "Require all denied\n";
			$rules .= "Order allow,deny\n";
			$rules .= "Deny from all\n";
			$rules .= "Options -Indexes\n";
			@file_put_contents( $ht, $rules );
		}

		$index = $root . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}

		// Hinweis-Datei für Nginx-Admins.
		$readme = $root . '/README-NGINX.txt';
		if ( ! file_exists( $readme ) ) {
			$txt  = "Dieses Verzeichnis enthält VERSCHLUESSELTE Dateianhänge.\n";
			$txt .= "Es darf NIEMALS direkt vom Webserver ausgeliefert werden.\n\n";
			$txt .= "Nginx-Beispiel (im server-Block):\n\n";
			$txt .= "  location ^~ /wp-content/.gfb-private/ {\n";
			$txt .= "      deny all;\n";
			$txt .= "      return 403;\n";
			$txt .= "  }\n";
			@file_put_contents( $readme, $txt );
		}
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Encrypt + Persist
	 * ------------------------------------------------------------------ */

	/**
	 * Verschlüsselt eine hochgeladene Datei in den privaten Storage und legt
	 * eine Metadaten-Zeile in wp_gfb_files an. Der temporäre Upload wird
	 * gelöscht. Plaintext-Inhalt verlässt RAM nicht länger als nötig.
	 *
	 * Voraussetzungen:
	 *   - GFB_Crypto::is_available() == true
	 *   - Datei existiert (is_uploaded_file)
	 *   - Validation (Endung, Doppel-Endung, finfo, ggf. ClamAV) wurde bereits
	 *     vom Aufrufer durchgeführt
	 *
	 * @param array<string,mixed> $file_array $_FILES-Eintrag.
	 * @param string              $field_name Feldname (Form-Feld).
	 * @param string              $real_mime  finfo-detektierter MIME.
	 * @param string              $ext        validierte Endung (lowercase, ohne Punkt).
	 * @return array{file_id:int,storage_id:string}|WP_Error
	 */
	public static function encrypt_and_store( array $file_array, $field_name, $real_mime, $ext ) {
		if ( ! GFB_Crypto::is_available() ) {
			return new WP_Error( 'gfb_no_crypto', __( 'Verschlüsselung nicht konfiguriert.', 'gutenberg-formbuilder' ) );
		}
		$tmp = isset( $file_array['tmp_name'] ) ? (string) $file_array['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'gfb_no_upload', __( 'Kein gültiger Upload.', 'gutenberg-formbuilder' ) );
		}
		if ( ! self::ensure_storage_directory() ) {
			return new WP_Error( 'gfb_storage_dir', __( 'Storage-Verzeichnis konnte nicht angelegt werden.', 'gutenberg-formbuilder' ) );
		}

		$plain = @file_get_contents( $tmp );
		if ( false === $plain ) {
			return new WP_Error( 'gfb_read_fail', __( 'Datei konnte nicht gelesen werden.', 'gutenberg-formbuilder' ) );
		}
		$size_bytes = strlen( $plain );
		$sha256     = hash( 'sha256', $plain );

		// DEK + Wrap.
		try {
			$dek      = GFB_Crypto::generate_dek();
			$wrapped  = GFB_Crypto::wrap_dek( $dek );
			$enc      = GFB_Crypto::gcm_encrypt( $plain, $dek, 'gfb-file-v1' );
		} catch ( \Throwable $e ) {
			// Plaintext und DEK best effort wegwerfen.
			$plain = str_repeat( "\0", strlen( $plain ) );
			unset( $plain );
			if ( isset( $dek ) ) {
				$dek = str_repeat( "\0", strlen( $dek ) );
				unset( $dek );
			}
			return new WP_Error( 'gfb_crypto_fail', __( 'Verschlüsselung fehlgeschlagen.', 'gutenberg-formbuilder' ) );
		}
		// Plaintext und DEK ab sofort nicht mehr im Speicher halten.
		$plain = str_repeat( "\0", strlen( $plain ) );
		unset( $plain );
		$dek = str_repeat( "\0", strlen( $dek ) );
		unset( $dek );

		// Verzeichnisstruktur yyyy/mm und 32-hex storage-id.
		$storage_id = bin2hex( random_bytes( 16 ) );
		$year       = gmdate( 'Y' );
		$month      = gmdate( 'm' );
		$rel_dir    = $year . '/' . $month;
		$abs_dir    = self::storage_root() . '/' . $rel_dir;
		if ( ! wp_mkdir_p( $abs_dir ) ) {
			return new WP_Error( 'gfb_storage_dir', __( 'Storage-Unterordner konnte nicht angelegt werden.', 'gutenberg-formbuilder' ) );
		}
		$rel_path = $rel_dir . '/' . $storage_id . '.bin';
		$abs_path = $abs_dir . '/' . $storage_id . '.bin';

		// Schreibe nur den Ciphertext-Body. IV/Tag liegen in der Datenbank-Zeile.
		$bytes_written = @file_put_contents( $abs_path, $enc['ct'], LOCK_EX );
		if ( false === $bytes_written ) {
			return new WP_Error( 'gfb_storage_write', __( 'Storage-Schreibfehler.', 'gutenberg-formbuilder' ) );
		}
		// Berechtigungen: nur Owner lesen/schreiben.
		@chmod( $abs_path, 0600 );

		// DB-Eintrag.
		global $wpdb;
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'submission_id' => 0, // wird vom Submit-Handler nachgetragen
				'field_name'    => mb_substr( sanitize_key( $field_name ), 0, 190 ),
				'original_name' => mb_substr( sanitize_text_field( (string) ( $file_array['name'] ?? '' ) ), 0, 255 ),
				'mime'          => mb_substr( (string) $real_mime, 0, 120 ),
				'size_bytes'    => $size_bytes,
				'sha256_plain'  => $sha256,
				'storage_id'    => $storage_id,
				'storage_path'  => $rel_path,
				'key_id'        => $wrapped['key_id'],
				'dek_iv'        => $wrapped['iv_b64'],
				'dek_tag'       => $wrapped['tag_b64'],
				'dek_ct'        => $wrapped['ct_b64'],
				'content_iv'    => GFB_Crypto::b64_encode( $enc['iv'] ),
				'content_tag'   => GFB_Crypto::b64_encode( $enc['tag'] ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			@unlink( $abs_path );
			return new WP_Error( 'gfb_db_fail', __( 'Datei-Metadaten konnten nicht gespeichert werden.', 'gutenberg-formbuilder' ) );
		}
		$file_id = (int) $wpdb->insert_id;

		// Tmp-Upload entfernen (PHP hätte das beim Request-Ende sowieso getan,
		// aber wir überlassen das nicht dem Zufall).
		@unlink( $tmp );

		GFB_Audit::record(
			'file_encrypted',
			'file',
			(string) $file_id,
			array(
				'mime'       => $real_mime,
				'size'       => $size_bytes,
				'sha256'     => $sha256,
				'field_name' => $field_name,
			)
		);

		return array(
			'file_id'    => $file_id,
			'storage_id' => $storage_id,
		);
	}

	/**
	 * Verbindet bereits angelegte Datei-Reihen mit ihrer Submission-ID.
	 *
	 * @param array<int,int> $file_ids      Datei-IDs.
	 * @param int            $submission_id Submission-ID.
	 * @return void
	 */
	public static function attach_to_submission( array $file_ids, $submission_id ) {
		global $wpdb;
		$sid = (int) $submission_id;
		if ( $sid <= 0 || empty( $file_ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $file_ids ), '%d' ) );
		$args         = array_merge( array( $sid ), array_map( 'intval', $file_ids ) );
		$sql          = "UPDATE " . self::table_name() . " SET submission_id = %d WHERE id IN ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
		$wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/* ------------------------------------------------------------------ *
	 * Lookup / Decrypt
	 * ------------------------------------------------------------------ */

	/**
	 * Holt eine Datei-Zeile.
	 *
	 * @param int $id File-ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table_name() . " WHERE id = %d", $id ) );
	}

	/**
	 * Entschlüsselt eine Datei in den Speicher.
	 *
	 * @param object $row Datei-Zeile aus self::get().
	 * @return string|WP_Error Plaintext oder Fehler.
	 */
	public static function decrypt_to_string( $row ) {
		if ( ! is_object( $row ) ) {
			return new WP_Error( 'gfb_no_row', __( 'Datei nicht gefunden.', 'gutenberg-formbuilder' ) );
		}
		if ( ! GFB_Crypto::is_available() ) {
			return new WP_Error( 'gfb_no_crypto', __( 'Verschlüsselung nicht verfügbar.', 'gutenberg-formbuilder' ) );
		}

		$dek = GFB_Crypto::unwrap_dek( $row->key_id, $row->dek_iv, $row->dek_tag, $row->dek_ct );
		if ( false === $dek ) {
			return new WP_Error( 'gfb_unwrap', __( 'Schlüssel konnte nicht entwrappt werden.', 'gutenberg-formbuilder' ) );
		}
		$abs_path = self::storage_root() . '/' . $row->storage_path;
		$ct       = @file_get_contents( $abs_path );
		if ( false === $ct ) {
			$dek = str_repeat( "\0", strlen( $dek ) );
			unset( $dek );
			return new WP_Error( 'gfb_no_file', __( 'Datei nicht im Storage gefunden.', 'gutenberg-formbuilder' ) );
		}
		$iv  = GFB_Crypto::b64_decode( $row->content_iv );
		$tag = GFB_Crypto::b64_decode( $row->content_tag );
		$pt  = GFB_Crypto::gcm_decrypt( $ct, $dek, $iv, $tag, 'gfb-file-v1' );
		$dek = str_repeat( "\0", strlen( $dek ) );
		unset( $dek );
		if ( false === $pt ) {
			return new WP_Error( 'gfb_decrypt', __( 'Datei konnte nicht entschlüsselt werden (Tag-Mismatch).', 'gutenberg-formbuilder' ) );
		}
		return $pt;
	}

	/* ------------------------------------------------------------------ *
	 * Download-Endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Erzeugt eine signierte Download-URL für das wp-admin/admin-post.php.
	 *
	 * @param int $file_id Datei-ID.
	 * @return string
	 */
	public static function download_url( $file_id ) {
		$file_id = (int) $file_id;
		$nonce   = wp_create_nonce( 'gfb_download_' . $file_id );
		return add_query_arg(
			array(
				'action'   => 'gfb_download',
				'fid'      => $file_id,
				'_wpnonce' => $nonce,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin_post-Handler: prüft Cap + Nonce, streamt entschlüsselt aus.
	 *
	 * @return void
	 */
	public static function handle_download() {
		if ( ! is_user_logged_in() ) {
			GFB_Audit::record( 'file_download_denied', 'file', '', array( 'reason' => 'not_logged_in' ) );
			wp_die(
				esc_html__( 'Anmeldung erforderlich.', 'gutenberg-formbuilder' ),
				esc_html__( 'Authentifizierung', 'gutenberg-formbuilder' ),
				array( 'response' => 401 )
			);
		}
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_DOWNLOAD_FILES ) ) {
			GFB_Audit::record( 'file_download_denied', 'file', '', array( 'reason' => 'capability' ) );
			wp_die(
				esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ),
				esc_html__( 'Berechtigung', 'gutenberg-formbuilder' ),
				array( 'response' => 403 )
			);
		}
		$fid = isset( $_GET['fid'] ) ? absint( $_GET['fid'] ) : 0;
		if ( $fid <= 0 || ! check_admin_referer( 'gfb_download_' . $fid ) ) {
			GFB_Audit::record( 'file_download_denied', 'file', (string) $fid, array( 'reason' => 'nonce' ) );
			wp_die(
				esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'gutenberg-formbuilder' ),
				esc_html__( 'Nonce', 'gutenberg-formbuilder' ),
				array( 'response' => 403 )
			);
		}
		$row = self::get( $fid );
		if ( ! $row ) {
			GFB_Audit::record( 'file_download_denied', 'file', (string) $fid, array( 'reason' => 'not_found' ) );
			wp_die(
				esc_html__( 'Datei nicht gefunden.', 'gutenberg-formbuilder' ),
				esc_html__( 'Nicht gefunden', 'gutenberg-formbuilder' ),
				array( 'response' => 404 )
			);
		}
		$plain = self::decrypt_to_string( $row );
		if ( is_wp_error( $plain ) ) {
			GFB_Audit::record(
				'file_download_failed',
				'file',
				(string) $fid,
				array( 'msg' => $plain->get_error_message() )
			);
			wp_die(
				esc_html( $plain->get_error_message() ),
				esc_html__( 'Fehler', 'gutenberg-formbuilder' ),
				array( 'response' => 500 )
			);
		}

		GFB_Audit::record( 'file_download_ok', 'file', (string) $fid, array( 'size' => (int) $row->size_bytes ) );

		nocache_headers();
		// Strikte Header gegen Sniffing/XSS bei HTML-ähnlichen Inhalten.
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'' );
		// Erzwinge Download statt Inline-Anzeige (auch bei harmlosen MIME-Typen).
		header( 'Content-Type: application/octet-stream' );
		$filename = sanitize_file_name( (string) $row->original_name );
		if ( '' === $filename ) {
			$filename = 'datei.bin';
		}
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '_', $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $plain ) );

		echo $plain; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file content.
		// Best effort scrubbing.
		$plain = str_repeat( "\0", strlen( $plain ) );
		unset( $plain );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Löschen
	 * ------------------------------------------------------------------ */

	/**
	 * Löscht eine Datei-Reihe + die Storage-Datei (Best Effort).
	 *
	 * @param int $id Datei-ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
		$abs = self::storage_root() . '/' . $row->storage_path;
		if ( file_exists( $abs ) ) {
			@unlink( $abs );
		}
		GFB_Audit::record( 'file_deleted', 'file', (string) $id );
		return true;
	}

	/**
	 * Löscht alle Files einer Submission. Aufruf vom Admin-Löschen.
	 *
	 * @param int $submission_id Submission-ID.
	 * @return int Anzahl gelöschter Files.
	 */
	public static function delete_for_submission( $submission_id ) {
		global $wpdb;
		$sid = (int) $submission_id;
		if ( $sid <= 0 ) {
			return 0;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM " . self::table_name() . " WHERE submission_id = %d",
				$sid
			)
		);
		$count = 0;
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				if ( self::delete( (int) $id ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ *
	 * Key-Rotation Re-Wrap
	 * ------------------------------------------------------------------ */

	/**
	 * Verarbeitet einen Batch von Datei-DEKs, die nicht mit der aktiven KEK
	 * gewrappt sind, und re-wrappt sie. Datei-Inhalt bleibt unangetastet.
	 *
	 * @param int $batch Anzahl Files pro Lauf.
	 * @return array{processed:int,errors:int}
	 */
	public static function rewrap_batch( $batch = 50 ) {
		global $wpdb;
		$active = GFB_Crypto::active_key_id();
		if ( '' === $active ) {
			return array( 'processed' => 0, 'errors' => 0 );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, key_id, dek_iv, dek_tag, dek_ct FROM " . self::table_name() . " WHERE key_id <> %s LIMIT %d",
				$active,
				max( 1, (int) $batch )
			)
		);
		$processed = 0;
		$errors    = 0;
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$rew = GFB_Crypto::rewrap_dek( $row->key_id, $row->dek_iv, $row->dek_tag, $row->dek_ct );
				if ( false === $rew ) {
					++$errors;
					continue;
				}
				$wpdb->update(
					self::table_name(),
					array(
						'key_id'  => $rew['key_id'],
						'dek_iv'  => $rew['iv_b64'],
						'dek_tag' => $rew['tag_b64'],
						'dek_ct'  => $rew['ct_b64'],
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
				++$processed;
			}
		}
		if ( $processed > 0 || $errors > 0 ) {
			GFB_Audit::record(
				'rewrap_batch',
				'system',
				'',
				array( 'processed' => $processed, 'errors' => $errors, 'active_key' => $active )
			);
		}
		return array( 'processed' => $processed, 'errors' => $errors );
	}

	/**
	 * Pingt einen "Honeypot"-Pfad innerhalb des Storage-Roots über HTTP gegen
	 * die eigene Site und prüfte, dass der Webserver mit 403/404 antwortet.
	 *
	 * Schreibt VORUEBERGEHEND eine kanarienartige Datei `.gfb-public-check`,
	 * pingt sie, löscht sie wieder. Wird beim Aktivieren und auf Knopfdruck
	 * in der Settings-Seite ausgeführt.
	 *
	 * @return array{ok:bool,http_code:int,url:string,details:string}
	 */
	public static function public_reachability_test() {
		self::ensure_storage_directory();
		$root = self::storage_root();
		$canary_name = '.gfb-public-check-' . wp_generate_password( 12, false, false );
		$canary_abs  = $root . '/' . $canary_name;
		@file_put_contents( $canary_abs, 'gfb-public-check' );

		$content_url = trailingslashit( content_url() );
		$default     = trailingslashit( WP_CONTENT_DIR ) . self::SUBDIR;
		$root_norm   = wp_normalize_path( $root );
		$default_n   = wp_normalize_path( $default );
		// Wenn der Pfad ausserhalb von wp-content liegt (per Filter), können wir
		// keine HTTP-URL ableiten. In dem Fall: kein Test möglich -> ok=true.
		if ( 0 !== strpos( $root_norm, $default_n ) && 0 !== strpos( $root_norm, wp_normalize_path( WP_CONTENT_DIR ) ) ) {
			@unlink( $canary_abs );
			return array(
				'ok'        => true,
				'http_code' => 0,
				'url'       => '',
				'details'   => 'Der Speicherort liegt ausserhalb des Webverzeichnisses. Damit kann er per Definition nicht aus dem Internet erreicht werden – ein HTTP-Test ist hier nicht möglich (und nicht nötig).',
			);
		}
		$rel_from_content = ltrim( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', $root_norm ), '/' );
		$url              = $content_url . $rel_from_content . '/' . $canary_name;

		$resp = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'sslverify'           => false,
				'redirection'         => 0,
				'limit_response_size' => 256,
			)
		);
		@unlink( $canary_abs );
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'        => true, // Netz-Fehler != Zugriff
				'http_code' => 0,
				'url'       => $url,
				'details'   => 'Der Test konnte nicht durchgeführt werden. Der automatische Abruf der Test-URL ist fehlgeschlagen (' . $resp->get_error_message() . '). Meist liegt ein lokales Netzwerk- oder DNS-Problem vor. Das ist kein Sicherheitsrisiko. Wiederhole den Test später.',
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		// Wir akzeptieren alles AUSSER 2xx und Redirects auf den Inhalt.
		$ok = ( $code >= 400 && $code < 600 );
		if ( $ok ) {
			$details = sprintf(
				'Geprüft: hochgeladene Dateien sind aus dem Internet NICHT direkt erreichbar. Der Webserver hat den Test-Aufruf wie erwartet abgelehnt (Status %d). Alles in Ordnung – die Verschlüsselung wirkt zusätzlich.',
				$code
			);
		} else {
			$details = sprintf(
				'ACHTUNG: Der Webserver liefert Dateien aus dem privaten Speicher AUS (Status %d). Das bedeutet: jeder mit dem Link kommt an die (verschlüsselten) Rohdaten heran. Die Plugin-Verschlüsselung schützt den Inhalt zwar weiter, der Webserver muss aber so konfiguriert werden, dass das Verzeichnis nicht öffentlich ist. Siehe INSTALL.md (Apache/Nginx-Snippet) oder Hoster-Support kontaktieren.',
				$code
			);
		}
		return array(
			'ok'        => $ok,
			'http_code' => $code,
			'url'       => $url,
			'details'   => $details,
		);
	}

	/**
	 * Liefert Files einer Submission (für Anzeige im Detail).
	 *
	 * @param int $submission_id Submission-ID.
	 * @return array<int,object>
	 */
	public static function for_submission( $submission_id ) {
		global $wpdb;
		$sid = (int) $submission_id;
		if ( $sid <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM " . self::table_name() . " WHERE submission_id = %d ORDER BY id ASC",
				$sid
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}
