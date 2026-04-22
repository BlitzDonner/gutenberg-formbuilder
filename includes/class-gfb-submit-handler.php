<?php
/**
 * Submit handling for Gutenberg Formbuilder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Submit_Handler {
	/**
	 * Create storage table on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'gfb_submissions';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			post_id BIGINT UNSIGNED NOT NULL,
			form_id VARCHAR(190) NOT NULL,
			payload LONGTEXT NOT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent TEXT NULL,
			PRIMARY KEY (id),
			KEY form_lookup (post_id, form_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Handle a form submission.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id = isset( $_POST['gfb_post_id'] ) ? absint( wp_unslash( $_POST['gfb_post_id'] ) ) : 0;
		$form_id = isset( $_POST['gfb_form_id'] ) ? sanitize_key( wp_unslash( $_POST['gfb_form_id'] ) ) : '';
		$ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! $post_id || ! $form_id ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Ungültige Formularanfrage.', 'gutenberg-formbuilder' ) );
		}

		check_admin_referer( 'gfb_submit_' . $form_id . '_' . $post_id, 'gfb_nonce' );

		$form_attrs         = GFB_Plugin::get_form_block_attributes_from_post( $post_id, $form_id );
		$thank_you_page_id  = isset( $form_attrs['thankYouPageId'] ) ? absint( $form_attrs['thankYouPageId'] ) : 0;
		$draft_key_redirect = '';
		if ( ! empty( $_POST['gfb_draft_key'] ) ) {
			$raw_dk = sanitize_text_field( wp_unslash( $_POST['gfb_draft_key'] ) );
			if ( preg_match( '/^[0-9]+:[a-z0-9_-]+:[a-z0-9_-]+$/i', $raw_dk ) ) {
				$draft_key_redirect = $raw_dk;
			}
		}

		if ( ! empty( $_POST['gfb_hp_field'] ) ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Die Anfrage wurde als Spam erkannt.', 'gutenberg-formbuilder' ) );
		}

		$rendered_at = isset( $_POST['gfb_rendered_at'] ) ? absint( wp_unslash( $_POST['gfb_rendered_at'] ) ) : 0;
		$now         = time();
		if ( ! $rendered_at || ( $now - $rendered_at ) < 2 || ( $now - $rendered_at ) > DAY_IN_SECONDS * 2 ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Ungültige Formularzeit. Bitte versuche es erneut.', 'gutenberg-formbuilder' ) );
		}

		if ( self::is_rate_limited( $form_id, $ip_address ) ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Zu viele Anfragen. Bitte warte kurz und versuche es erneut.', 'gutenberg-formbuilder' ) );
		}

		$schema = GFB_Plugin::get_form_schema_from_post( $post_id, $form_id );
		if ( empty( $schema ) ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Formularschema nicht gefunden.', 'gutenberg-formbuilder' ) );
		}

		$seen_names = array();
		foreach ( $schema as $field ) {
			$n = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $n ) {
				continue;
			}
			if ( isset( $seen_names[ $n ] ) ) {
				self::redirect_with_state(
					$post_id,
					$form_id,
					'error',
					__( 'Doppelte technische Feldnamen im Formular. Bitte im Editor jedes Feld eindeutig benennen.', 'gutenberg-formbuilder' )
				);
			}
			$seen_names[ $n ] = true;
		}

		$payload = array();
		$errors  = array();

		foreach ( $schema as $field ) {
			$res = self::process_field_value( $field );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
				continue;
			}
			$payload[ $field['name'] ] = $res;
		}

		if ( ! empty( $errors ) ) {
			self::redirect_with_state( $post_id, $form_id, 'error', implode( ' ', $errors ) );
		}

		// Labels zum Zeitpunkt des Absendens (für Archiv/Backend, auch wenn sich das Formular später ändert).
		$label_snapshot = array();
		foreach ( $schema as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$label_snapshot[ $field['name'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['name'];
			}
		}
		$payload['_gfb_labels'] = $label_snapshot;

		global $wpdb;
		$table_name = $wpdb->prefix . 'gfb_submissions';
		$inserted = $wpdb->insert(
			$table_name,
			array(
				'post_id'    => $post_id,
				'form_id'    => $form_id,
				'payload'    => wp_json_encode( $payload ),
				'ip_address' => $ip_address,
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			self::redirect_with_state( $post_id, $form_id, 'error', __( 'Speichern fehlgeschlagen. Bitte versuche es erneut.', 'gutenberg-formbuilder' ) );
		}

		self::send_notification_mail( $post_id, $form_id, $payload );
		self::redirect_with_state(
			$post_id,
			$form_id,
			'success',
			__( 'Danke! Das Formular wurde erfolgreich gesendet.', 'gutenberg-formbuilder' ),
			$draft_key_redirect,
			$thank_you_page_id
		);
	}

	/**
	 * Redirect back to post (oder Folgeseite) mit Query-Parametern.
	 *
	 * @param int    $post_id Post id.
	 * @param string $form_id Form id.
	 * @param string $status Status key.
	 * @param string $message Message for UI.
	 * @param string $draft_key Optional. IndexedDB-Schlüssel für Draft-Löschung auf Folgeseiten.
	 * @param int    $thank_you_page_id Optional. Bei success: Seiten-ID für Weiterleitung (0 = Formularseite).
	 * @return void
	 */
	private static function redirect_with_state( $post_id, $form_id, $status, $message, $draft_key = '', $thank_you_page_id = 0 ) {
		$target = $post_id ? get_permalink( $post_id ) : home_url( '/' );
		if ( 'success' === $status && $thank_you_page_id > 0 ) {
			$page = get_post( $thank_you_page_id );
			if ( $page instanceof WP_Post && is_post_publicly_viewable( $page ) ) {
				$permalink = get_permalink( $page );
				if ( $permalink ) {
					$target = $permalink;
				}
			}
		}

		$args = array(
			'gfb_status' => $status,
			'gfb_form'   => $form_id,
			'gfb_msg'    => $message,
		);
		if ( '' !== $draft_key ) {
			$args['gfb_draft_key'] = $draft_key;
		}

		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	/**
	 * Send e-mail notification to admin.
	 *
	 * @param int    $post_id Post id.
	 * @param string $form_id Form id.
	 * @param array  $payload Form data.
	 * @return void
	 */
	private static function send_notification_mail( $post_id, $form_id, $payload ) {
		$to      = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: 1: form id, 2: post id */
			__( 'Neues Formular (%1$s) auf Beitrag %2$d', 'gutenberg-formbuilder' ),
			$form_id,
			$post_id
		);

		$labels = isset( $payload['_gfb_labels'] ) && is_array( $payload['_gfb_labels'] ) ? $payload['_gfb_labels'] : array();

		$lines = array();
		foreach ( $payload as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$display_key = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$lines[] = $display_key . ': ' . $value;
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Validate and sanitize one field.
	 *
	 * @param array<string,mixed> $field Schema row.
	 * @return string|WP_Error
	 */
	private static function process_field_value( array $field ) {
		$name     = $field['name'];
		$type     = $field['type'];
		$required = ! empty( $field['required'] );
		$label    = $field['label'];

		if ( 'file' === $type ) {
			return self::process_file_field( $field );
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
				return new WP_Error( 'gfb_max', __( 'Zahl zu groß.', 'gutenberg-formbuilder' ) );
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

		if ( in_array( $type, array( 'select', 'radio' ), true ) && ! empty( $field['options'] ) && '' !== $value ) {
			$opts = $field['options'];
			if ( ! in_array( $value, $opts, true ) ) {
				return new WP_Error( 'gfb_option', __( 'Ungültige Auswahl.', 'gutenberg-formbuilder' ) );
			}
		}

		return $value;
	}

	/**
	 * Handle file upload field.
	 *
	 * @param array<string,mixed> $field Schema.
	 * @return string|WP_Error URL or error.
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

		if ( empty( $_FILES[ $name ] ) || ( isset( $_FILES[ $name ]['error'] ) && 4 === (int) $_FILES[ $name ]['error'] ) ) {
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
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gfb_upload', __( 'Datei-Upload fehlgeschlagen.', 'gutenberg-formbuilder' ) );
		}

		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'gfb_size', __( 'Datei ist zu groß.', 'gutenberg-formbuilder' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
			)
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'gfb_upload', sanitize_text_field( $upload['error'] ) );
		}

		if ( empty( $upload['url'] ) ) {
			return new WP_Error( 'gfb_upload', __( 'Upload konnte nicht gespeichert werden.', 'gutenberg-formbuilder' ) );
		}

		if ( ! empty( $field['accept'] ) ) {
			$filename = isset( $upload['file'] ) ? $upload['file'] : '';
			$ok       = self::file_matches_accept( $filename, (string) $field['accept'] );
			if ( ! $ok ) {
				if ( ! empty( $upload['file'] ) && file_exists( $upload['file'] ) ) {
					wp_delete_file( $upload['file'] );
				}
				return new WP_Error( 'gfb_accept', __( 'Dateityp nicht erlaubt.', 'gutenberg-formbuilder' ) );
			}
		}

		return esc_url_raw( $upload['url'] );
	}

	/**
	 * Rough check against accept string (e.g. ".pdf,image/*").
	 *
	 * @param string $file_path Absolute path.
	 * @param string $accept    Accept attribute.
	 */
	private static function file_matches_accept( $file_path, $accept ) {
		if ( '' === $accept || '' === $file_path ) {
			return true;
		}
		$check = wp_check_filetype( $file_path );
		$ext   = isset( $check['ext'] ) ? strtolower( (string) $check['ext'] ) : '';
		$mime  = isset( $check['type'] ) ? (string) $check['type'] : '';

		$parts = array_map( 'trim', explode( ',', $accept ) );
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '.' === $part[0] ) {
				$want = strtolower( ltrim( $part, '.' ) );
				if ( $ext === $want ) {
					return true;
				}
				continue;
			}
			if ( false !== strpos( $part, '/*' ) ) {
				$main = str_replace( '/*', '', $part );
				if ( $mime && 0 === strpos( $mime, $main ) ) {
					return true;
				}
				continue;
			}
			if ( $mime && strtolower( $part ) === strtolower( $mime ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lightweight IP-based rate limit.
	 *
	 * @param string $form_id Form id.
	 * @param string $ip_address Ip address.
	 * @return bool
	 */
	private static function is_rate_limited( $form_id, $ip_address ) {
		if ( '' === $ip_address ) {
			return false;
		}

		$key        = 'gfb_rate_' . md5( $form_id . '|' . $ip_address );
		$window     = 10 * MINUTE_IN_SECONDS;
		$max_events = 5;
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
