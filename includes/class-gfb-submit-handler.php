<?php
/**
 * Submit handling for Blitz & Donner Formular.
 *
 * Sicherheitsrelevant: Alle externen Eingaben (POST/FILES/SERVER) werden
 * über GFB_Security validiert. Anonymer Endpoint (admin_post_nopriv).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Submit_Handler {

	const STATUS_OK              = 'success';
	const STATUS_ERR_REQUEST     = 'err_request';
	const STATUS_ERR_NONCE       = 'err_nonce';
	const STATUS_ERR_TOKEN       = 'err_token';
	const STATUS_ERR_SPAM        = 'err_spam';
	const STATUS_ERR_RATE        = 'err_rate';
	const STATUS_ERR_SCHEMA      = 'err_schema';
	const STATUS_ERR_DUPLICATE   = 'err_duplicate';
	const STATUS_ERR_VALIDATION  = 'err_validation';
	const STATUS_ERR_FILE        = 'err_file';
	const STATUS_ERR_PERSIST     = 'err_persist';
	const STATUS_ERR_EXTERNAL    = 'err_external';
	const STATUS_ERR_CRYPTO      = 'err_crypto';
	const STATUS_ERR_VIRUS       = 'err_virus';
	const STATUS_ERR_CAPTCHA             = 'err_captcha';
	const STATUS_ERR_CAPTCHA_UNREACHABLE = 'err_captcha_unreachable';

	/**
	 * Create storage tables, directories and capability mapping on activation.
	 *
	 * @return void
	 */
	/**
	 * Submissions-Tabelle anlegen/aktualisieren (dbDelta).
	 *
	 * @return void
	 */
	public static function install_submissions_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'gfb_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		// Schema-Version 3: Spalten für Bestätigungsmail + Double-Opt-in
		// (docs/BESTAETIGUNGSMAIL-SPEC.md Abschnitt 4). confirm_token_hash hält
		// nur den gepfefferten HMAC des Tokens (indiziert, Lookup ohne LIKE).
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			post_id BIGINT UNSIGNED NOT NULL,
			form_id VARCHAR(190) NOT NULL,
			form_title VARCHAR(255) NOT NULL DEFAULT '',
			payload LONGTEXT NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			confirm_status VARCHAR(20) NOT NULL DEFAULT '',
			confirm_token_hash CHAR(64) NOT NULL DEFAULT '',
			confirm_expires_at DATETIME NULL DEFAULT NULL,
			confirm_used_at DATETIME NULL DEFAULT NULL,
			receipt_mail_status VARCHAR(20) NOT NULL DEFAULT '',
			receipt_mail_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			KEY form_lookup (post_id, form_id),
			KEY confirm_token (confirm_token_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Fügt neue Spalten nach Updates hinzu (ohne erneute Plugin-Aktivierung).
	 * Version 2: form_title. Version 3: Bestätigungsmail/DOI-Spalten.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_submissions_db() {
		$ver = (int) get_option( 'gfb_submissions_db_version', 1 );
		if ( $ver >= 3 ) {
			return;
		}
		self::install_submissions_table();
		update_option( 'gfb_submissions_db_version', 3 );
	}

	public static function activate() {
		self::install_submissions_table();
		update_option( 'gfb_submissions_db_version', 3 );

		// Begleit-Tabellen.
		GFB_File_Storage::install_table();
		GFB_Audit::install_table();

		// Privates Storage-Verzeichnis anlegen (mit .htaccess-Schutz).
		GFB_File_Storage::ensure_storage_directory();

		// Default-Capability-Zuordnung (Administrator bekommt alle Plugin-Caps).
		GFB_Capabilities::bootstrap_defaults();

		// Einmalige ClamAV-Default-Migration: Pflicht-Modus auf "optional" zurücksetzen,
		// wenn er nur durch den alten Default true war (kein Hoster-Setup vorhanden).
		GFB_Clamav::maybe_migrate_defaults();

		// Re-Wrap-Cron registrieren (rotation, idle).
		if ( ! wp_next_scheduled( 'gfb_rewrap_cron' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'gfb_rewrap_cron' );
		}

		// Retention-Cron der Bestätigungsmail (nie bestätigte Einsendungen,
		// abgelaufene Token, Gate-Zähler).
		if ( class_exists( 'GFB_Receipt_Mail' ) ) {
			GFB_Receipt_Mail::maybe_schedule_cron();
		}

		GFB_Audit::record( 'plugin_activated', 'system', '', array() );

		// Direkt nach Aktivierung den Storage-Erreichbarkeits-Test laufen lassen
		// und das Ergebnis als Transient cachen (Admin-Notice erscheint dann
		// beim ersten Wp-Admin-Aufruf).
		if ( class_exists( 'GFB_File_Storage' ) ) {
			$reach = GFB_File_Storage::public_reachability_test();
			set_transient( 'gfb_storage_reach', $reach, DAY_IN_SECONDS );
		}
	}

	/**
	 * Cron-Lauf: re-wrap einer Charge alter DEKs mit der aktiven KEK.
	 *
	 * @return void
	 */
	public static function cron_rewrap() {
		if ( ! GFB_Crypto::is_available() ) {
			return;
		}
		GFB_File_Storage::rewrap_batch( 100 );
	}

	/**
	 * Statusslug => i18n-Text. Verhindert, dass Angreifer beliebige Notices
	 * via URL platzieren können (H5).
	 *
	 * @return array<string,string>
	 */
	private static function status_messages() {
		return array(
			self::STATUS_OK             => __( 'Danke! Das Formular wurde erfolgreich gesendet.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_REQUEST    => __( 'Ungültige Formularanfrage.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_NONCE      => __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden und erneut absenden.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_TOKEN      => __( 'Sitzung abgelaufen. Bitte Seite neu laden und erneut absenden.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_SPAM       => __( 'Die Anfrage wurde als Spam erkannt.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_RATE       => __( 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_SCHEMA     => __( 'Formularschema nicht gefunden.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_DUPLICATE  => __( 'Doppelte technische Feldnamen im Formular. Bitte eines der betroffenen Felder duplizieren oder Label bzw. Platzhalter anpassen.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_VALIDATION => __( 'Das Formular wurde nicht übermittelt. Bitte prüfe die Hinweise und sende erneut.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_FILE       => __( 'Eine hochgeladene Datei wurde abgelehnt. Das Formular wurde in diesem Fall nicht übermittelt; es wurde kein neuer Eintrag gespeichert.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_PERSIST    => __( 'Speichern fehlgeschlagen. Bitte versuche es erneut.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_EXTERNAL   => __( 'Die Anfrage konnte nicht verarbeitet werden.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_CRYPTO     => __( 'Verschlüsselung ist auf diesem Server nicht eingerichtet. Bitte den Administrator kontaktieren.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_VIRUS      => __( 'Eine hochgeladene Datei wurde vom Virenscanner als schädlich erkannt.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_CAPTCHA             => __( 'Der Spam-Schutz wurde nicht bestätigt. Bitte schliesse die Spam-Prüfung im Formular ab und sende erneut.', 'gutenberg-formbuilder' ),
			self::STATUS_ERR_CAPTCHA_UNREACHABLE => __( 'Der Spam-Schutz ist derzeit nicht verfügbar. Bitte versuche es in einigen Minuten erneut.', 'gutenberg-formbuilder' ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function valid_status_slugs() {
		return array_keys( self::status_messages() );
	}

	/**
	 * Public Helper: Gibt den i18n-Text für einen Status-Slug zurück oder leer.
	 *
	 * @param string $slug Status-Slug.
	 * @return string
	 */
	public static function status_message_for( $slug ) {
		$map = self::status_messages();
		return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
	}

	/**
	 * Handle a form submission.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id    = isset( $_POST['gfb_post_id'] ) ? absint( wp_unslash( $_POST['gfb_post_id'] ) ) : 0;
		$form_id    = isset( $_POST['gfb_form_id'] ) ? sanitize_key( wp_unslash( $_POST['gfb_form_id'] ) ) : '';
		$instance   = isset( $_POST['gfb_instance_id'] ) ? sanitize_key( wp_unslash( $_POST['gfb_instance_id'] ) ) : '0';
		$ip_address = GFB_Security::get_client_ip();

		// Bugfix (21.07.2026): post_id 0 ist legitim – Formulare in Site-Editor-
		// Templates rendern ohne verwertbare Post-ID und senden explizit 0;
		// die Schema-Suche läuft dann im site-weiten Template-Modus. Pflicht
		// bleibt allein die form_id.
		if ( ! $form_id ) {
			GFB_Security::log_event( 'submit_invalid_request' );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_REQUEST );
		}

		// wp_verify_nonce statt check_admin_referer: letzteres killt den Request
		// mit dem WP-Default-403-HTML, sodass unser redirect_with_state nie
		// läuft. Wir wollen aber konsistent mit gfb_status/gfb_code zur
		// Form-Page zurück-redirecten (UX + auditable Reject-Pfade).
		$nonce_value = isset( $_POST['gfb_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gfb_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce_value, 'gfb_submit_' . $form_id . '_' . $post_id ) ) {
			GFB_Security::log_event( 'submit_nonce_fail', array( 'post_id' => $post_id, 'form_id' => $form_id ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_NONCE );
		}

		// HMAC-Token (H3) inkl. honeypot-Feldname-Bindung.
		$hp_field   = GFB_Security::honeypot_field_name( $post_id, $form_id, $instance );
		$token      = isset( $_POST['gfb_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gfb_token'] ) ) : '';
		if ( ! GFB_Security::verify_token( $token, $post_id, $form_id, $instance, $hp_field ) ) {
			GFB_Security::log_event( 'submit_token_fail', array( 'post_id' => $post_id, 'form_id' => $form_id ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_TOKEN );
		}

		// Honeypot (H1): per Form-Instanz dynamisch.
		if ( ! empty( $_POST[ $hp_field ] ) ) {
			GFB_Security::log_event( 'submit_honeypot_hit', array( 'post_id' => $post_id, 'form_id' => $form_id ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_SPAM );
		}

		if ( self::is_rate_limited( $form_id, $ip_address ) ) {
			GFB_Security::log_event( 'submit_rate_limited', array( 'form_id' => $form_id ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_RATE );
		}

		$form_attrs        = GFB_Plugin::get_form_block_attributes_from_post( $post_id, $form_id );
		$thank_you_page_id = isset( $form_attrs['thankYouPageId'] ) ? absint( $form_attrs['thankYouPageId'] ) : 0;

		// 5. Stufe der Abwehrkette: CAPTCHA (Friendly Captcha), NACH Rate-Limit,
		// VOR Schema-/Feldverarbeitung. Greift nur, wenn fuer dieses Formular
		// aktiv (global an + vollstaendig konfiguriert + captchaMode).
		self::maybe_enforce_captcha( $post_id, $form_id, $form_attrs, $ip_address );

		/** Nur aus Block-Attributen (nicht aus POST), damit der Name nicht manipulierbar ist. */
		$form_title_stored = '';
		if ( ! empty( $form_attrs['formTitle'] ) && is_string( $form_attrs['formTitle'] ) ) {
			$form_title_stored = mb_substr( sanitize_text_field( $form_attrs['formTitle'] ), 0, 255 );
		}

		$draft_key_redirect = '';
		if ( ! empty( $_POST['gfb_draft_key'] ) ) {
			$raw_dk = sanitize_text_field( wp_unslash( $_POST['gfb_draft_key'] ) );
			if ( preg_match( '/^[0-9]+:[a-z0-9_-]+:[a-z0-9_-]+$/i', $raw_dk ) ) {
				$draft_key_redirect = $raw_dk;
			}
		}

		$schema = GFB_Plugin::get_form_schema_from_post( $post_id, $form_id );
		if ( empty( $schema ) ) {
			GFB_Security::log_event( 'submit_schema_missing', array( 'post_id' => $post_id, 'form_id' => $form_id ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_SCHEMA );
		}

		$seen_names = array();
		foreach ( $schema as $field ) {
			$n = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $n ) {
				continue;
			}
			if ( isset( $seen_names[ $n ] ) ) {
				GFB_Security::log_event( 'submit_duplicate_field', array( 'name' => $n ) );
				self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_DUPLICATE );
			}
			$seen_names[ $n ] = true;
		}

		// Crypto-Status: Pflicht, sobald sensitive Felder ODER File-Felder im Schema vorkommen.
		$schema_has_files = false;
		$schema_has_sensitive = false;
		foreach ( $schema as $f ) {
			if ( ( $f['type'] ?? '' ) === 'file' ) {
				$schema_has_files = true;
			}
			if ( ! empty( $f['sensitive'] ) ) {
				$schema_has_sensitive = true;
			}
		}
		if ( ( $schema_has_files || $schema_has_sensitive ) && ! GFB_Crypto::is_available() ) {
			GFB_Security::log_event( 'submit_no_crypto', array( 'reason' => GFB_Crypto::status()['reason'] ?? '' ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_CRYPTO );
		}

		// ClamAV-Vorbedingung prüfen (nur falls File-Felder vorhanden).
		if ( $schema_has_files ) {
			$pre = GFB_Clamav::precondition_for_uploads();
			if ( is_wp_error( $pre ) ) {
				GFB_Security::log_event( 'submit_clamav_required' );
				self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_FILE, $pre->get_error_message() );
			}
		}

		$payload         = array();
		$pending_file_ids = array();
		$errors          = array();
		$plain_snapshot  = array();

		foreach ( $schema as $field ) {
			$res = self::process_field_value( $field, $pending_file_ids );
			if ( is_wp_error( $res ) ) {
				$errors[] = array(
					'code'    => $res->get_error_code(),
					'message' => $res->get_error_message(),
				);
				continue;
			}
			// Klartext-Snapshot VOR der Verschlüsselung – Grundlage für die
			// Bestätigungsmail (sauberer als Entschlüsseln am Sendepunkt).
			if ( is_string( $res ) ) {
				$plain_snapshot[ $field['name'] ] = $res;
			}
			// Sensitive Werte (string oder array) verschlüsseln.
			if ( ! empty( $field['sensitive'] ) && is_string( $res ) && '' !== $res && 'file' !== $field['type'] ) {
				try {
					$res = GFB_Crypto::encrypt_field( $res, 'field:' . $field['name'] );
				} catch ( \Throwable $e ) {
					GFB_Security::log_event( 'submit_field_encrypt_fail', array( 'field' => $field['name'] ) );
					self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_CRYPTO );
				}
			}
			$payload[ $field['name'] ] = $res;
		}

		if ( ! empty( $errors ) ) {
			// Bereits verschlüsselte Datei-Reihen wieder löschen, damit keine Waisen entstehen.
			foreach ( $pending_file_ids as $fid ) {
				GFB_File_Storage::delete( (int) $fid );
			}
			GFB_Security::log_event( 'submit_validation_errors', array( 'count' => count( $errors ) ) );
			self::redirect_with_state(
				$post_id,
				$form_id,
				self::STATUS_ERR_VALIDATION,
				self::join_validation_errors( $errors )
			);
		}

		/**
		 * Extra validation hook for submit button flow.
		 *
		 * Werte im $payload sind bereits getypt und sanitisiert; trotzdem MUESSEN
		 * eingehakte Callbacks bei Ausgaben in HTML/SQL/Mail nochmal passend
		 * escapen.
		 *
		 * @param null|string|WP_Error $error      Validation result from previous callbacks.
		 * @param array<string,mixed>  $payload    Validated payload (without _gfb_labels at this point).
		 * @param array<int,array>     $schema     Form schema used for validation.
		 * @param array<string,mixed>  $form_attrs gfb/form block attributes.
		 * @param int                  $post_id    Post ID.
		 * @param string               $form_id    Form ID.
		 */
		$external_validation = apply_filters( 'gfb_submit_button_validation', null, $payload, $schema, $form_attrs, $post_id, $form_id );
		if ( is_wp_error( $external_validation ) ) {
			$message = $external_validation->get_error_message();
			GFB_Security::log_event( 'submit_external_reject' );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_EXTERNAL, $message );
		}
		if ( is_string( $external_validation ) && '' !== trim( $external_validation ) ) {
			GFB_Security::log_event( 'submit_external_reject' );
			self::redirect_with_state(
				$post_id,
				$form_id,
				self::STATUS_ERR_EXTERNAL,
				sanitize_text_field( $external_validation )
			);
		}

		/**
		 * Fires after server-side validation has completed successfully.
		 * Werte sind bereits sanitisiert; bei eigener Persistenz/Mail nochmal
		 * passend escapen.
		 *
		 * @param array<string,mixed> $payload    Validated payload.
		 * @param array<int,array>    $schema     Form schema used for validation.
		 * @param array<string,mixed> $form_attrs gfb/form block attributes.
		 * @param int                 $post_id    Post ID.
		 * @param string              $form_id    Form ID.
		 */
		do_action( 'gfb_after_server_validation', $payload, $schema, $form_attrs, $post_id, $form_id );

		// Labels zum Zeitpunkt des Absendens (Snapshot für Backend, auch wenn sich das Formular später ändert).
		$label_snapshot = array();
		foreach ( $schema as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$label_snapshot[ $field['name'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['name'];
			}
		}
		$payload['_gfb_labels'] = $label_snapshot;

		// DSGVO-Suchindex: deterministischer, gepfefferter HMAC jedes E-Mail-Werts.
		// Damit finden Exporter/Eraser auch Einsendungen, deren E-Mail-Feld
		// verschlüsselt gespeichert ist (LIKE auf Ciphertext greift nicht).
		$email_hmacs = array();
		foreach ( $schema as $field ) {
			if ( 'email' !== ( $field['type'] ?? '' ) || empty( $field['name'] ) ) {
				continue;
			}
			$mail_value = isset( $plain_snapshot[ $field['name'] ] ) ? trim( (string) $plain_snapshot[ $field['name'] ] ) : '';
			if ( '' !== $mail_value && is_email( $mail_value ) ) {
				$email_hmacs[ GFB_Security::email_search_hash( $mail_value ) ] = true;
			}
		}
		if ( ! empty( $email_hmacs ) ) {
			$payload['_gfb_email_hmacs'] = array_keys( $email_hmacs );
		}

		global $wpdb;
		$table_name   = $wpdb->prefix . 'gfb_submissions';

		/**
		 * IP optional pseudonymisieren oder ganz weglassen (DSGVO).
		 *
		 * @param string $ip Roh-IP (kann leer sein).
		 */
		$ip_to_store = apply_filters( 'gfb_store_ip_pre', $ip_address );
		$ip_to_store = GFB_Security::maybe_pseudonymize_ip( (string) $ip_to_store );

		/**
		 * User-Agent optional weglassen.
		 *
		 * @param string $ua User-Agent.
		 */
		$ua_raw      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$ua_to_store = apply_filters( 'gfb_store_user_agent', $ua_raw );
		$ua_to_store = is_string( $ua_to_store ) ? mb_substr( sanitize_text_field( $ua_to_store ), 0, 1000 ) : '';

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'post_id'    => $post_id,
				'form_id'    => $form_id,
				'form_title' => $form_title_stored,
				'payload'    => wp_json_encode( $payload ),
				'ip_address' => (string) $ip_to_store,
				'user_agent' => $ua_to_store,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			GFB_Security::log_event( 'submit_persist_fail' );
			// Verschlüsselte Files können nicht "verwaiste" Speicher sein.
			foreach ( $pending_file_ids as $fid ) {
				GFB_File_Storage::delete( (int) $fid );
			}
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_PERSIST );
		}

		$submission_id = (int) $wpdb->insert_id;
		// Verschlüsselte Files mit dieser Submission verbinden.
		if ( ! empty( $pending_file_ids ) ) {
			GFB_File_Storage::attach_to_submission( $pending_file_ids, $submission_id );
		}
		// Audit (zentralisiert, getrennt vom optionalen 3rd-party-Hook).
		GFB_Audit::record(
			'submission_insert',
			'submission',
			(string) $submission_id,
			array(
				'form_id' => $form_id,
				'post_id' => $post_id,
				'files'   => count( $pending_file_ids ),
				'fields'  => count( $payload ) - 1, // ohne _gfb_labels
			)
		);

		/**
		 * Fires after submission values were stored in database.
		 * Werte sind bereits sanitisiert; bei eigener Verarbeitung passend escapen.
		 *
		 * @param int                 $submission_id Inserted row ID in `{prefix}gfb_submissions`.
		 * @param array<string,mixed> $payload       Stored payload (including _gfb_labels).
		 * @param array<string,mixed> $form_attrs    gfb/form block attributes.
		 * @param int                 $post_id       Post ID.
		 * @param string              $form_id       Form ID.
		 */
		do_action( 'gfb_after_submission_insert', $submission_id, $payload, $form_attrs, $post_id, $form_id );

		// DOI-Modus: Betreiber-Mail 1 trägt den Vermerk «unbestätigt eingegangen» (E9).
		$receipt_mode  = GFB_Receipt_Mail::mode_from_attrs( $form_attrs );
		$operator_note = '';
		if ( GFB_Receipt_Mail::MODE_DOI === $receipt_mode ) {
			$operator_note = sprintf(
				/* translators: %d: Nummer der Einsendung */
				__( 'Status: unbestätigt eingegangen – die absendende Person hat ihre E-Mail-Adresse für Eintrag #%d noch nicht bestätigt.', 'gutenberg-formbuilder' ),
				$submission_id
			);
		}
		self::send_notification_mail( $post_id, $form_id, $payload, $form_title_stored, $form_attrs, $operator_note );

		// Bestätigungsmail an die ausfüllende Person (Sofort-Modus oder Link-Mail).
		// Fehler brechen den Submit nie ab – die Einsendung ist gespeichert.
		GFB_Receipt_Mail::handle_after_submission(
			$submission_id,
			$post_id,
			$form_id,
			$form_attrs,
			$payload,
			$schema,
			$form_title_stored,
			$plain_snapshot
		);

		self::redirect_with_state(
			$post_id,
			$form_id,
			self::STATUS_OK,
			'',
			$draft_key_redirect,
			$thank_you_page_id
		);
	}

	/**
	 * WP_Error-Codes, bei denen eine Datei verworfen wurde und ein einheitlicher
	 * Hinweis (kein Speichern / gesamtes Formular nicht übermittelt) angehängt werden soll.
	 *
	 * @param string $code Fehlercode.
	 * @return bool
	 */
	private static function validation_error_code_is_file_rejection_with_global_hint( $code ) {
		return in_array(
			(string) $code,
			array(
				'gfb_file_ext',
				'gfb_file_accept',
				'gfb_file_mime',
				'gfb_file_invalid',
				'gfb_file_name',
				'gfb_size',
				'gfb_upload',
				'gfb_virus',
			),
			true
		);
	}

	/**
	 * Zusatztext: Datei nicht übernommen, Formular insgesamt nicht gesendet.
	 *
	 * @return string
	 */
	private static function file_rejection_form_not_sent_suffix() {
		return __(
			'Die Datei wurde nicht übernommen (nicht gespeichert) und zählt nicht zur Einsendung. Nach dem Neuladen der Seite ist die Auswahl im Datei-Feld leer — bitte wähle bei Bedarf eine zulässige Datei erneut. Das gesamte Formular wurde nicht übermittelt; es wurde kein neuer Eintrag gespeichert.',
			'gutenberg-formbuilder'
		);
	}

	/**
	 * Mehrere Validierungsfehler in einen kurzen, bereinigten Text giessen.
	 *
	 * @param array<int, string|array{code?:string, message?:string}> $errors Meldungen bzw. code+message.
	 * @return string
	 */
	private static function join_validation_errors( array $errors ) {
		$cleaned                = array();
		$append_file_reject_hint = false;
		foreach ( $errors as $err ) {
			$code = '';
			if ( is_array( $err ) && isset( $err['message'] ) ) {
				$code = isset( $err['code'] ) ? (string) $err['code'] : '';
				if ( self::validation_error_code_is_file_rejection_with_global_hint( $code ) ) {
					$append_file_reject_hint = true;
				}
				$err = (string) $err['message'];
			} elseif ( ! is_string( $err ) ) {
				continue;
			}
			$err = wp_strip_all_tags( (string) $err );
			$err = preg_replace( '/\s+/', ' ', $err );
			$err = trim( $err );
			if ( '' !== $err ) {
				$cleaned[] = mb_substr( $err, 0, 200 );
			}
			if ( count( $cleaned ) >= 8 ) {
				break;
			}
		}
		$out = implode( ' ', $cleaned );
		if ( $append_file_reject_hint ) {
			$out = trim( $out . ' ' . self::file_rejection_form_not_sent_suffix() );
		}
		return mb_substr( $out, 0, 500 );
	}

	/**
	 * Redirect mit ausschliesslich serverseitig festgelegten Status-Slugs.
	 * Optional zusätzliche „err_detail" mit kurzen Validierungshinweisen
	 * (begrenzt + sanitisiert).
	 *
	 * @param int    $post_id           Post id.
	 * @param string $form_id           Form id.
	 * @param string $status_slug       Eine der STATUS_*-Konstanten.
	 * @param string $detail            Optionaler Detailtext (nur für Validierungsfehler).
	 * @param string $draft_key         Optional. IndexedDB-Schlüssel für Entwurf-Löschung nach Redirect.
	 * @param int    $thank_you_page_id Optional. Bei success: Seiten-ID für Weiterleitung.
	 * @return void
	 */
	private static function redirect_with_state(
		$post_id,
		$form_id,
		$status_slug,
		$detail = '',
		$draft_key = '',
		$thank_you_page_id = 0
	) {
		if ( ! in_array( $status_slug, self::valid_status_slugs(), true ) ) {
			$status_slug = self::STATUS_ERR_REQUEST;
		}

		// Bugfix (21.07.2026): get_permalink() liefert bei ungültiger/fehlender
		// Post-ID false – add_query_arg landete dann auf der aktuellen
		// admin-post-URI und die Person sah eine leere Seite mit Query-Args.
		// Fallback-Kette: Referer (nur same-host via wp_validate_redirect,
		// gfb_*-Args gestrippt, nie admin-post) → Startseite.
		$target = '';
		if ( $post_id ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				$target = $permalink;
			}
		}
		if ( '' === $target ) {
			$referer = wp_get_referer();
			if ( $referer ) {
				$validated = wp_validate_redirect( $referer, '' );
				if ( is_string( $validated ) && '' !== $validated && false === strpos( $validated, 'admin-post.php' ) ) {
					$target = remove_query_arg(
						array( 'gfb_status', 'gfb_code', 'gfb_form', 'gfb_detail', 'gfb_draft_key' ),
						$validated
					);
				}
			}
		}
		if ( '' === $target ) {
			$target = home_url( '/' );
		}
		if ( self::STATUS_OK === $status_slug && $thank_you_page_id > 0 ) {
			$page = get_post( $thank_you_page_id );
			if ( $page instanceof WP_Post && is_post_publicly_viewable( $page ) ) {
				$permalink = get_permalink( $page );
				if ( $permalink ) {
					$target = $permalink;
				}
			}
		}

		// gfb_status: synchron zu altem Verhalten ("success"/"error") für
		// vorhandene CSS-Klassen; gfb_code hält den vollen Slug für feinere
		// Logik / Templates.
		$visible_status = ( self::STATUS_OK === $status_slug ) ? 'success' : 'error';

		$args = array(
			'gfb_status' => $visible_status,
			'gfb_code'   => $status_slug,
			'gfb_form'   => $form_id,
		);
		$detail_slugs = array(
			self::STATUS_ERR_VALIDATION,
			self::STATUS_ERR_FILE,
			self::STATUS_ERR_EXTERNAL,
			self::STATUS_ERR_CRYPTO,
		);
		if ( '' !== $detail && in_array( $status_slug, $detail_slugs, true ) ) {
			$args['gfb_detail'] = mb_substr( sanitize_text_field( $detail ), 0, 500 );
		}
		if ( '' !== $draft_key ) {
			$args['gfb_draft_key'] = $draft_key;
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/**
	 * Send e-mail notification according to gfb/form block settings.
	 *
	 * Härte: Subject CRLF-strip, body wp_strip_all_tags + Limit pro Wert,
	 * explizit text/plain-Header, Empfänger/Absender nur aus Block-Attributen.
	 *
	 * @param int                 $post_id    Post id.
	 * @param string              $form_id    Form id.
	 * @param array<string,mixed> $payload    Form data.
	 * @param string              $form_title Optional display name.
	 * @param array<string,mixed> $form_attrs gfb/form block attributes.
	 * @param string              $note       Optionaler Status-Vermerk (DOI: «unbestätigt eingegangen»).
	 * @return void
	 */
	private static function send_notification_mail( $post_id, $form_id, $payload, $form_title = '', array $form_attrs = array(), $note = '' ) {
		$enabled = ! empty( $form_attrs['emailNotificationEnabled'] );
		if ( ! $enabled ) {
			return;
		}

		$recipients = self::resolve_notification_recipients( $form_attrs );
		if ( empty( $recipients ) ) {
			return;
		}

		$labels = isset( $payload['_gfb_labels'] ) && is_array( $payload['_gfb_labels'] ) ? $payload['_gfb_labels'] : array();
		$subject = self::build_notification_subject( $post_id, $form_id, $form_title, $form_attrs, $payload, $labels );
		$body    = self::build_notification_body( $payload, $labels );
		if ( '' !== $note ) {
			$body = sanitize_text_field( $note ) . "\n\n" . $body;
		}
		$headers = self::build_notification_headers( $form_attrs, $payload, $labels );

		wp_mail( $recipients, $subject, $body, $headers );
	}

	/**
	 * Betreiber-Mail 2 im Double-Opt-in («jetzt bestätigt», E9). Öffentlicher
	 * Einstieg für GFB_Receipt_Mail; nutzt dieselbe Benachrichtigungs-Mechanik,
	 * vertrauliche Werte bleiben über format_notification_field_value()
	 * «[verschlüsselt]».
	 *
	 * @param int                 $post_id    Post id.
	 * @param string              $form_id    Form id.
	 * @param array<string,mixed> $payload    Gespeicherter Payload.
	 * @param string              $form_title Anzeigename.
	 * @param array<string,mixed> $form_attrs gfb/form block attributes.
	 * @param string              $note       Status-Vermerk.
	 * @return void
	 */
	public static function send_receipt_operator_mail( $post_id, $form_id, array $payload, $form_title, array $form_attrs, $note ) {
		self::send_notification_mail( $post_id, $form_id, $payload, $form_title, $form_attrs, $note );
	}

	/**
	 * @param array<string,mixed> $form_attrs Block attributes.
	 * @return string Comma-separated valid recipient list for wp_mail.
	 */
	private static function resolve_notification_recipients( array $form_attrs ) {
		$out  = array();
		$raw  = '';
		$list = $form_attrs['emailRecipients'] ?? '';
		if ( is_array( $list ) ) {
			$raw = implode( ',', array_map( 'strval', $list ) );
		} elseif ( is_string( $list ) ) {
			$raw = $list;
		}
		$raw = preg_replace( "/[\r\n]+/", ' ', (string) $raw );
		foreach ( preg_split( '/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			$email = sanitize_email( (string) $part );
			if ( '' !== $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		if ( empty( $out ) ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( '' !== $admin && is_email( $admin ) ) {
				$out[ strtolower( $admin ) ] = $admin;
			}
		}
		$out = array_slice( array_values( $out ), 0, 10 );

		return empty( $out ) ? '' : implode( ',', $out );
	}

	/**
	 * @param int                 $post_id    Post id.
	 * @param string              $form_id    Form id.
	 * @param string              $form_title Display name.
	 * @param array<string,mixed> $form_attrs Block attributes.
	 * @param array<string,mixed> $payload    Submission payload.
	 * @param array<string,string> $labels    Field labels.
	 * @return string
	 */
	private static function build_notification_subject( $post_id, $form_id, $form_title, array $form_attrs, array $payload, array $labels ) {
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$custom   = isset( $form_attrs['emailSubject'] ) ? trim( (string) $form_attrs['emailSubject'] ) : '';

		if ( '' !== $custom ) {
			$subject = self::replace_notification_placeholders( $custom, $payload, $labels );
		} else {
			$form_title = is_string( $form_title ) ? trim( $form_title ) : '';
			if ( '' !== $form_title ) {
				$subj_base = sprintf(
					/* translators: 1: sprechender Formularname, 2: technische Formular-ID, 3: Beitrags-ID */
					__( 'Neues Formular: %1$s (%2$s), Beitrag %3$d', 'gutenberg-formbuilder' ),
					$form_title,
					$form_id,
					$post_id
				);
			} else {
				$subj_base = sprintf(
					/* translators: 1: form id, 2: post id */
					__( 'Neues Formular (%1$s) auf Beitrag %2$d', 'gutenberg-formbuilder' ),
					$form_id,
					$post_id
				);
			}
			$subject = '[' . $blogname . '] ' . $subj_base;
		}

		$subject = preg_replace( "/[\r\n]+/", ' ', (string) $subject );
		$subject = wp_strip_all_tags( (string) $subject );

		return mb_substr( (string) $subject, 0, 250 );
	}

	/**
	 * @param array<string,mixed>  $payload Submission payload.
	 * @param array<string,string> $labels  Field labels.
	 * @return string
	 */
	private static function build_notification_body( array $payload, array $labels ) {
		$lines = array();
		foreach ( $payload as $key => $value ) {
			// Interne Schlüssel (_gfb_labels, _gfb_email_hmacs) nie in die Mail.
			if ( 0 === strpos( (string) $key, '_gfb' ) ) {
				continue;
			}
			$display_key = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$display_key = wp_strip_all_tags( (string) $display_key );
			$display_key = preg_replace( "/[\r\n]+/", ' ', (string) $display_key );
			$display_key = mb_substr( $display_key, 0, 120 );

			$value = self::format_notification_field_value( $value );
			$lines[] = $display_key . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param mixed $value Raw field value.
	 * @return string
	 */
	private static function format_notification_field_value( $value ) {
		if ( GFB_Crypto::is_field_envelope( $value ) ) {
			$value = '[verschlüsselt — bitte im Admin entschlüsseln]';
		} elseif ( is_array( $value ) && isset( $value['_ref'] ) && 0 === strpos( (string) $value['_ref'], 'gfb-file:' ) ) {
			$value = '[verschlüsselte Datei #' . (int) $value['file_id'] . ' — Download im Admin]';
		} elseif ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( "/[\r\n]+/", "\n", (string) $value );

		return mb_substr( (string) $value, 0, 1000 );
	}

	/**
	 * @param array<string,mixed>   $form_attrs Block attributes.
	 * @param array<string,mixed>   $payload    Submission payload.
	 * @param array<string,string>  $labels     Field labels.
	 * @return array<int,string>
	 */
	private static function build_notification_headers( array $form_attrs, array $payload, array $labels = array() ) {
		$headers    = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from_email = self::resolve_notification_from_email( $form_attrs, $payload );
		$from_name  = self::resolve_notification_from_name( $form_attrs, $payload, $labels );

		$from_mailbox = self::format_mailbox_header( $from_name, $from_email );
		if ( '' !== $from_mailbox ) {
			$headers[] = 'From: ' . $from_mailbox;
		}

		return $headers;
	}

	/** Absender-Modus: feste Adresse im Block (nicht aus POST). */
	const EMAIL_FROM_CUSTOM_SENDER = 'gfb_custom_sender';

	/**
	 * From-Adresse: eigene Adresse, E-Mail-Feld der Einsendung oder Admin-E-Mail.
	 *
	 * @param array<string,mixed> $form_attrs Block attributes.
	 * @param array<string,mixed> $payload    Submission payload.
	 * @return string
	 */
	private static function resolve_notification_from_email( array $form_attrs, array $payload ) {
		$field = isset( $form_attrs['emailFromField'] ) ? sanitize_key( (string) $form_attrs['emailFromField'] ) : '';
		if ( self::EMAIL_FROM_CUSTOM_SENDER === $field ) {
			$custom = isset( $form_attrs['emailFromCustom'] ) ? sanitize_email( (string) $form_attrs['emailFromCustom'] ) : '';
			if ( '' !== $custom && is_email( $custom ) ) {
				return $custom;
			}
		} elseif ( '' !== $field && isset( $payload[ $field ] ) ) {
			$value = $payload[ $field ];
			if ( ! GFB_Crypto::is_field_envelope( $value ) ) {
				$email = sanitize_email( (string) $value );
				if ( '' !== $email && is_email( $email ) ) {
					return $email;
				}
			}
		}

		$admin = sanitize_email( (string) get_option( 'admin_email' ) );

		return ( '' !== $admin && is_email( $admin ) ) ? $admin : '';
	}

	/**
	 * From-Anzeigename: optional mit Platzhaltern, sonst Seitentitel.
	 *
	 * @param array<string,mixed>  $form_attrs Block attributes.
	 * @param array<string,mixed>  $payload    Submission payload.
	 * @param array<string,string> $labels     Field labels.
	 * @return string
	 */
	private static function resolve_notification_from_name( array $form_attrs, array $payload, array $labels ) {
		$custom = isset( $form_attrs['emailFromName'] ) ? trim( (string) $form_attrs['emailFromName'] ) : '';
		if ( '' !== $custom ) {
			$name = self::replace_notification_placeholders( $custom, $payload, $labels );
			$name = wp_strip_all_tags( (string) $name );
			$name = preg_replace( "/[\r\n]+/", ' ', (string) $name );
			$name = trim( $name );
			if ( '' !== $name ) {
				return mb_substr( $name, 0, 120 );
			}
		}

		return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * @param string $name  Display name.
	 * @param string $email E-mail address.
	 * @return string
	 */
	private static function format_mailbox_header( $name, $email ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		$name = sanitize_text_field( (string) $name );
		$name = preg_replace( "/[\r\n]+/", ' ', $name );
		$name = trim( $name );
		if ( '' === $name ) {
			return $email;
		}

		return sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Ersetzt {{feldname}} und {{label_feldname}} in Betreff- und Absendernamen-Vorlagen.
	 *
	 * @param string               $template Subject template.
	 * @param array<string,mixed>  $payload  Submission payload.
	 * @param array<string,string> $labels   Field labels.
	 * @return string
	 */
	private static function replace_notification_placeholders( $template, array $payload, array $labels ) {
		$template = (string) $template;
		if ( '' === $template ) {
			return '';
		}
		return (string) preg_replace_callback(
			'/\{\{\s*(label_)?([a-z0-9_-]+)\s*\}\}/i',
			static function ( $matches ) use ( $payload, $labels ) {
				$is_label = ! empty( $matches[1] );
				$key      = sanitize_key( (string) $matches[2] );
				if ( '' === $key ) {
					return '';
				}
				if ( $is_label ) {
					if ( ! isset( $labels[ $key ] ) ) {
						return '';
					}
					$value = (string) $labels[ $key ];
				} elseif ( ! isset( $payload[ $key ] ) ) {
					return '';
				} else {
					$value = self::format_notification_field_value( $payload[ $key ] );
				}
				$value = preg_replace( "/[\r\n]+/", ' ', (string) $value );

				return mb_substr( (string) $value, 0, 120 );
			},
			$template
		);
	}

	/**
	 * Validate and sanitize one field.
	 *
	 * @param array<string,mixed> $field            Schema row.
	 * @param array<int,int>      $pending_file_ids Wird per Referenz gefüllt mit erfolgreichen Datei-IDs.
	 * @return string|array<string,mixed>|WP_Error  Wert (string), Datei-Referenz-Array oder Fehler.
	 */
	private static function process_field_value( array $field, array &$pending_file_ids = array() ) {
		$name     = $field['name'];
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];

		if ( 'file' === $type ) {
			$res = self::process_file_field( $field );
			if ( ! is_wp_error( $res ) && is_array( $res ) && isset( $res['file_id'] ) ) {
				$pending_file_ids[] = (int) $res['file_id'];
			}
			return $res;
		}

		// Hidden mit lockedValue (M5): Wert komplett serverseitig setzen,
		// Client-Eingaben ignorieren.
		if ( 'hidden' === $type && ! empty( $field['locked'] ) ) {
			return isset( $field['hidden_value'] ) ? (string) $field['hidden_value'] : '';
		}

		$raw = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

		if ( 'checkbox' === $type ) {
			$value = ! empty( $raw ) ? '1' : '0';
		} elseif ( 'textarea' === $type ) {
			$value = sanitize_textarea_field( (string) $raw );
		} elseif ( 'email' === $type ) {
			$value = sanitize_email( (string) $raw );
		} elseif ( 'hidden' === $type ) {
			$value = sanitize_text_field( (string) $raw );
			if ( isset( $field['hidden_value'] ) && (string) $field['hidden_value'] !== '' && (string) $field['hidden_value'] !== (string) $value ) {
				return new WP_Error( 'gfb_hidden', __( 'Ungültiges verstecktes Feld.', 'gutenberg-formbuilder' ) );
			}
		} else {
			$value = sanitize_text_field( (string) $raw );
		}

		if ( $required && '' === trim( (string) $value ) && 'checkbox' !== $type ) {
			return new WP_Error(
				'gfb_required',
				sprintf(
					/* translators: %s: field label */
					__( 'Bitte fülle das Feld "%s" aus.', 'gutenberg-formbuilder' ),
					$label
				)
			);
		}

		if ( 'checkbox' === $type && $required && '1' !== $value ) {
			return new WP_Error(
				'gfb_required',
				sprintf(
					/* translators: %s: field label */
					__( 'Bitte bestätige "%s".', 'gutenberg-formbuilder' ),
					$label
				)
			);
		}

		if ( 'email' === $type && '' !== $value && ! is_email( $value ) ) {
			return new WP_Error(
				'gfb_email',
				sprintf(
					/* translators: %s: field label */
					__( 'Das Feld "%s" enthält keine gültige E-Mail-Adresse.', 'gutenberg-formbuilder' ),
					$label
				)
			);
		}

		if ( 'url' === $type && '' !== $value ) {
			$url = esc_url_raw( $value );
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return new WP_Error(
					'gfb_url',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält keine gültige URL.', 'gutenberg-formbuilder' ),
						$label
					)
				);
			}
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new WP_Error(
					'gfb_url',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält keine gültige URL.', 'gutenberg-formbuilder' ),
						$label
					)
				);
			}
			$value = $url;
		}

		if ( 'number' === $type && '' !== $value ) {
			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					'gfb_number',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" muss eine Zahl sein.', 'gutenberg-formbuilder' ),
						$label
					)
				);
			}
			$num = floatval( $value );
			if ( isset( $field['min'] ) && '' !== $field['min'] && $num < floatval( $field['min'] ) ) {
				return new WP_Error( 'gfb_min', __( 'Zahl zu klein.', 'gutenberg-formbuilder' ) );
			}
			if ( isset( $field['max'] ) && '' !== $field['max'] && $num > floatval( $field['max'] ) ) {
				return new WP_Error( 'gfb_max', __( 'Zahl zu gross.', 'gutenberg-formbuilder' ) );
			}
			$value = (string) $num;
		}

		if ( 'range' === $type ) {
			$num = is_numeric( $value ) ? floatval( $value ) : floatval( $field['min'] ?? 0 );
			if ( isset( $field['min'] ) && $num < floatval( $field['min'] ) ) {
				$num = floatval( $field['min'] );
			}
			if ( isset( $field['max'] ) && $num > floatval( $field['max'] ) ) {
				$num = floatval( $field['max'] );
			}
			$value = (string) $num;
		}

		if ( 'date' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return new WP_Error( 'gfb_date', __( 'Ungültiges Datum.', 'gutenberg-formbuilder' ) );
			}
		}

		if ( 'time' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{2}:\d{2}/', $value ) ) {
				return new WP_Error( 'gfb_time', __( 'Ungültige Uhrzeit.', 'gutenberg-formbuilder' ) );
			}
		}

		if ( 'datetime' === $type && '' !== $value ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value ) ) {
				return new WP_Error( 'gfb_datetime', __( 'Ungültiges Datum/Uhrzeit.', 'gutenberg-formbuilder' ) );
			}
		}

		if ( 'tel' === $type && '' !== $value ) {
			// Nur Ziffern, +, -, Leerzeichen, Klammern, Punkte, Schrägstriche; max 40 Zeichen.
			if ( ! preg_match( '/^[\d\+\-\s\(\)\.\/]{1,40}$/', $value ) ) {
				return new WP_Error(
					'gfb_tel',
					sprintf(
						/* translators: %s: field label */
						__( 'Das Feld "%s" enthält eine ungültige Telefonnummer.', 'gutenberg-formbuilder' ),
						$label
					)
				);
			}
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) && '' !== $value ) {
			$opts = $field['options'];
			if ( ! in_array( $value, $opts, true ) ) {
				return new WP_Error( 'gfb_option', __( 'Ungültige Auswahl.', 'gutenberg-formbuilder' ) );
			}
		}

		// Generelles Längenlimit gegen Pufferaufblähung pro Feld.
		if ( is_string( $value ) && strlen( $value ) > 100000 ) {
			return new WP_Error( 'gfb_too_long', __( 'Eingabe zu lang.', 'gutenberg-formbuilder' ) );
		}

		return $value;
	}

	/**
	 * Datei-Upload-Pipeline (vorbildlich):
	 *   1) PHP-Upload-Errors / Grössenlimit
	 *   2) Static-Validation: Endung, Doppel-Endung, finfo-MIME, accept-Match (GFB_Security)
	 *   3) ClamAV-Scan auf $_FILES['tmp_name']
	 *   4) Verschlüsseln + in privaten Storage schreiben (GFB_File_Storage)
	 *   5) Externer Post-Check-Filter (Custom-AV, Mehr-Augen)
	 *   6) Rückgabe einer "gfb-file:<id>"-Referenz, NICHT einer URL
	 *
	 * @param array<string,mixed> $field Schema.
	 * @return array{file_id:int,storage_id:string,_ref:string}|string|WP_Error
	 */
	private static function process_file_field( array $field ) {
		$name     = $field['name'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];
		$max_mb   = isset( $field['max_size_mb'] ) ? (int) $field['max_size_mb'] : 8;
		$max_mb   = max( 1, $max_mb );
		$max_bytes = $max_mb * 1024 * 1024;
		$wp_max    = wp_max_upload_size();
		if ( $max_bytes > $wp_max ) {
			$max_bytes = $wp_max;
		}

		if ( empty( $_FILES[ $name ] ) || ( isset( $_FILES[ $name ]['error'] ) && UPLOAD_ERR_NO_FILE === (int) $_FILES[ $name ]['error'] ) ) {
			if ( $required ) {
				return new WP_Error(
					'gfb_file',
					sprintf(
						/* translators: %s: field label */
						__( 'Bitte wähle eine Datei für "%s".', 'gutenberg-formbuilder' ),
						$label
					)
				);
			}
			return '';
		}

		$file = $_FILES[ $name ];
		if ( is_array( $file['name'] ?? null ) ) {
			GFB_Security::log_event( 'file_reject_multi' );
			return new WP_Error( 'gfb_file', __( 'Mehrfach-Uploads sind nicht erlaubt.', 'gutenberg-formbuilder' ) );
		}
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			GFB_Security::log_event( 'file_reject_php_error', array( 'error' => (int) $file['error'] ) );
			return new WP_Error( 'gfb_upload', __( 'Datei-Upload fehlgeschlagen.', 'gutenberg-formbuilder' ) );
		}
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			GFB_Security::log_event( 'file_reject_too_large', array( 'size' => (int) $file['size'], 'limit' => (int) $max_bytes ) );
			return new WP_Error( 'gfb_size', __( 'Datei ist zu gross.', 'gutenberg-formbuilder' ) );
		}

		$accept     = isset( $field['accept'] ) ? (string) $field['accept'] : '';
		$validation = GFB_Security::validate_uploaded_file( $file, $accept );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$ext       = $validation['ext'];
		$real_mime = $validation['mime'];

		// ClamAV-Scan auf Tmp-Datei.
		$clamav_set = GFB_Clamav::get_settings();
		if ( 'disabled' !== $clamav_set['mode'] ) {
			$scan = GFB_Clamav::scan_file( (string) $file['tmp_name'] );
			if ( 'infected' === $scan['status'] ) {
				GFB_Security::log_event( 'file_reject_av_infected', array( 'sig' => sanitize_text_field( $scan['details'] ) ) );
				GFB_Audit::record(
					'file_av_infected',
					'system',
					'',
					array( 'sig' => $scan['details'], 'mime' => $real_mime )
				);
				return new WP_Error( 'gfb_virus', __( 'Diese Datei wurde vom Virenscanner als schädlich erkannt.', 'gutenberg-formbuilder' ) );
			}
			if ( 'unavailable' === $scan['status'] && ! empty( $clamav_set['require_for_uploads'] ) ) {
				GFB_Security::log_event( 'file_reject_av_unavailable', array( 'details' => sanitize_text_field( $scan['details'] ) ) );
				return new WP_Error( 'gfb_virus', __( 'Virenscan derzeit nicht verfügbar; Upload wird verweigert.', 'gutenberg-formbuilder' ) );
			}
		}

		$store = GFB_File_Storage::encrypt_and_store( $file, $name, $real_mime, $ext );
		if ( is_wp_error( $store ) ) {
			GFB_Security::log_event( 'file_store_fail', array( 'msg' => $store->get_error_message() ) );
			return $store;
		}

		/**
		 * Optionaler externer Post-Check (z. B. zusätzlicher AV, externer DLP-Hook).
		 * Wenn der Filter WP_Error zurückgibt, wird die Datei aus dem Storage gelöscht.
		 *
		 * @param null|WP_Error      $error      Default null = ok.
		 * @param int                $file_id    Storage-File-ID.
		 * @param string             $real_mime  finfo-MIME.
		 * @param array<string,mixed>$field      Schema-Feld.
		 */
		$post_check = apply_filters( 'gfb_uploaded_file_post_check', null, (int) $store['file_id'], $real_mime, $field );
		if ( is_wp_error( $post_check ) ) {
			GFB_File_Storage::delete( (int) $store['file_id'] );
			GFB_Security::log_event( 'file_reject_post_check', array( 'msg' => sanitize_text_field( $post_check->get_error_message() ) ) );
			return $post_check;
		}

		return array(
			'file_id'    => (int) $store['file_id'],
			'storage_id' => (string) $store['storage_id'],
			'_ref'       => 'gfb-file:' . (int) $store['file_id'],
		);
	}

	/**
	 * CAPTCHA-Stufe (Friendly Captcha). Prueft das vom Frontend gelieferte
	 * Token serverseitig. Beide Erzwingungsmodi verlangen grundsaetzlich ein
	 * bestandenes CAPTCHA – fehlendes oder ungueltiges Token wird in beiden
	 * Faellen abgelehnt. Der einzige Unterschied liegt beim nicht erreichbaren
	 * Anbieter:
	 *   - soft (Default, «Mit Ausnahme bei Serverausfall»): Ist Friendly
	 *     Captcha nicht erreichbar, laesst der Submit trotzdem durch
	 *     (Ausfallsicherung), damit eine seltene Stoerung die Formulare nicht
	 *     blockiert.
	 *   - strict («Streng»): Auch bei nicht erreichbarem Anbieter wird
	 *     abgelehnt (fail-closed).
	 *
	 * Greift nur, wenn CAPTCHA fuer dieses Formular aktiv und konfiguriert ist.
	 * Bei Abweisung wird ueber redirect_with_state(...) mit feldnaher,
	 * mehrsprachiger Meldung zurueckgeleitet. Das rohe Token wird nicht geloggt.
	 *
	 * @param int                 $post_id    Post-ID.
	 * @param string              $form_id    Form-ID.
	 * @param array<string,mixed> $form_attrs gfb/form-Block-Attribute.
	 * @param string              $ip_address Bereits ermittelte Client-IP.
	 * @return void
	 */
	private static function maybe_enforce_captcha( $post_id, $form_id, array $form_attrs, $ip_address ) {
		if ( ! class_exists( 'GFB_Captcha' ) || ! GFB_Captcha::is_active_for_form( $form_attrs ) ) {
			return;
		}

		$settings      = GFB_Captcha::get_settings();
		$strict        = ( 'strict' === $settings['mode'] );
		$response_field = GFB_Captcha::RESPONSE_FIELD;
		$token         = isset( $_POST[ $response_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $response_field ] ) ) : '';

		// Gemeinsamer Kontext fuer Events/Audit – ohne rohes Token, ohne Secret.
		$base_ctx = array(
			'form_id' => $form_id,
			'post_id' => $post_id,
			'mode'    => $strict ? 'strict' : 'soft',
		);

		// Kein Token vorhanden (Widget nicht geladen / nicht geloest / kein Skript).
		// Beide Modi lehnen ab – ein bestandenes CAPTCHA ist Pflicht.
		if ( '' === $token ) {
			GFB_Security::log_event( 'captcha_fail', array_merge( $base_ctx, array( 'detail' => 'no_token' ) ) );
			GFB_Audit::record( 'captcha_verify', 'security', '', array_merge( $base_ctx, array( 'result' => 'fail', 'detail' => 'no_token' ) ) );
			self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_CAPTCHA );
		}

		$verify = GFB_Captcha::verify( $token, $ip_address );
		$result = $verify['result'];
		$detail = $verify['detail'];

		if ( 'pass' === $result ) {
			GFB_Security::log_event( 'captcha_pass', $base_ctx );
			// Audit-Rechenschaft auch im Erfolgsfall (C6/E6/US-6): $base_ctx
			// enthaelt nur Form-/Modus-Status, kein rohes Token, kein Secret.
			GFB_Audit::record( 'captcha_verify', 'security', '', array_merge( $base_ctx, array( 'result' => 'pass' ) ) );
			// Tatsaechliches Verifikationsergebnis dieses Requests an die
			// Receipt-Engine durchreichen: Nur «pass» erlaubt den Sofort-Versand.
			GFB_Receipt_Mail::set_captcha_request_result( 'pass' );
			return;
		}

		if ( 'unreachable' === $result ) {
			GFB_Security::log_event( 'captcha_unreachable', array_merge( $base_ctx, array( 'detail' => $detail ) ) );
			GFB_Audit::record( 'captcha_verify', 'security', '', array_merge( $base_ctx, array( 'result' => 'unreachable', 'detail' => $detail ) ) );
			if ( $strict ) {
				// strict: fail-closed – auch bei gestoertem Anbieter abgelehnt.
				self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_CAPTCHA_UNREACHABLE );
			}
			// soft: Ausfallsicherung – Submit geht bei nicht erreichbarem
			// Anbieter ausnahmsweise ueber die uebrige Kette weiter. Die
			// Bestaetigungsmail im Sofort-Modus bleibt dabei fail-closed:
			// «unreachable» ist kein «pass» (Chloe M-1).
			GFB_Receipt_Mail::set_captcha_request_result( 'unreachable' );
			return;
		}

		// result === 'fail' (ungueltiges/abgelaufenes/dupliziertes Token).
		// Beide Modi lehnen ab – ein bestandenes CAPTCHA ist Pflicht.
		GFB_Security::log_event( 'captcha_fail', array_merge( $base_ctx, array( 'detail' => $detail ) ) );
		GFB_Audit::record( 'captcha_verify', 'security', '', array_merge( $base_ctx, array( 'result' => 'fail', 'detail' => $detail ) ) );
		self::redirect_with_state( $post_id, $form_id, self::STATUS_ERR_CAPTCHA );
	}

	/**
	 * Lightweight IP-based rate limit. Nutzt zentralen IP-Helper (H2).
	 *
	 * @param string $form_id    Form id.
	 * @param string $ip_address Bereits ermittelte Client-IP.
	 * @return bool
	 */
	private static function is_rate_limited( $form_id, $ip_address ) {
		if ( '' === $ip_address ) {
			return false;
		}

		$key        = 'gfb_rate_' . md5( $form_id . '|' . $ip_address );
		$window     = 10 * MINUTE_IN_SECONDS;
		$max_events = 5;
		/**
		 * Maximale Anzahl Submits pro IP/Form im Fenster (10 Min).
		 *
		 * @param int    $max_events Default 5.
		 * @param string $form_id    Form-ID.
		 */
		$max_events = (int) apply_filters( 'gfb_rate_limit_max', $max_events, $form_id );
		$max_events = max( 1, $max_events );

		$events     = get_transient( $key );

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
}
