<?php
/**
 * Admin: gespeicherte Formular-Einsendungen anzeigen und verwalten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Admin_Submissions {
	const PAGE_SLUG = 'gfb-submissions';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		// CSS aus der Plugin-Datei einlesen und inline ausgeben (kein HTTP-Request; bei falscher WP_CONTENT_URL/Plugins-URL kein 404).
		add_action( 'admin_head', array( __CLASS__, 'print_inline_admin_css' ), 5 );
		// Vor jeglicher Admin-Ausgabe (sonst scheitert wp_safe_redirect nach Löschen).
		add_action( 'load-toplevel_page_' . self::PAGE_SLUG, array( __CLASS__, 'handle_early_actions' ) );
	}

	/**
	 * Löschen per GET früh abwickeln, damit noch keine Header gesendet sind.
	 *
	 * @return void
	 */
	public static function handle_early_actions() {
		if ( ! isset( $_GET['action'], $_GET['submission'] ) || 'delete' !== $_GET['action'] ) {
			return;
		}
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_DELETE_SUBMISSIONS ) ) {
			return;
		}

		$id    = absint( $_GET['submission'] );
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! $id || ! wp_verify_nonce( $nonce, 'gfb_delete_' . $id ) ) {
			return;
		}

		// Erst Files (Storage + DB-Reihen) löschen, dann Submission.
		$files_deleted = GFB_File_Storage::delete_for_submission( $id );

		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );

		GFB_Audit::record(
			'submission_deleted',
			'submission',
			(string) $id,
			array( 'files' => $files_deleted )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Prüft, ob die aktuelle Anfrage die Seite „Formular-Einträge“ ist.
	 *
	 * Hinweis: In admin_init ist get_current_screen() oft noch nicht gesetzt – nur $_GET['page'] ist zuverlässig.
	 *
	 * @return bool
	 */
	private static function is_submissions_admin_screen() {
		return is_admin()
			&& isset( $_GET['page'] )
			&& self::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Liest admin-submissions.css vom Dateisystem und gibt sie als &lt;style&gt; aus.
	 *
	 * @return void
	 */
	public static function print_inline_admin_css() {
		if ( ! self::is_submissions_admin_screen() ) {
			return;
		}

		$path = GFB_PLUGIN_DIR . 'assets/admin-submissions.css';
		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokale Plugin-Datei.
		$css = file_get_contents( $path );
		if ( false === $css || '' === $css ) {
			return;
		}

		echo '<style id="gfb-admin-submissions-inline">' . "\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statisches CSS aus eigener Datei.
		echo $css;
		echo "\n" . '</style>' . "\n";
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Formular-Einträge', 'gutenberg-formbuilder' ),
			__( 'Formular-Einträge', 'gutenberg-formbuilder' ),
			GFB_Capabilities::CAP_VIEW_SUBMISSIONS,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-feedback',
			58
		);
	}

	/**
	 * @return string
	 */
	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gfb_submissions';
	}

	/**
	 * @return bool
	 */
	private static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is controlled.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_VIEW_SUBMISSIONS ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ) );
		}

		$submission_id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		if ( $submission_id ) {
			self::render_detail( $submission_id );
			return;
		}

		self::render_list();
	}

	/**
	 * @param int $submission_id Row id.
	 * @return void
	 */
	private static function render_detail( $submission_id ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			echo '<div class="wrap gfb-admin gfb-admin-submissions">';
			echo '<h1>' . esc_html__( 'Formular-Einträge', 'gutenberg-formbuilder' ) . '</h1>';
			self::render_missing_table_notice();
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $submission_id ) );
		if ( ! $row ) {
			echo '<div class="wrap gfb-admin gfb-admin-submissions">';
			echo '<h1>' . esc_html__( 'Formular-Einträge', 'gutenberg-formbuilder' ) . '</h1>';
			echo '<p class="gfb-admin-empty">' . esc_html__( 'Eintrag nicht gefunden.', 'gutenberg-formbuilder' ) . '</p>';
			echo '<p class="gfb-admin-back"><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">&larr; ' . esc_html__( 'Zurück zur Übersicht', 'gutenberg-formbuilder' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$payload = json_decode( (string) $row->payload, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$labels_snapshot = isset( $payload['_gfb_labels'] ) && is_array( $payload['_gfb_labels'] ) ? $payload['_gfb_labels'] : array();
		$labels            = self::field_labels_map( (int) $row->post_id, (string) $row->form_id );
		foreach ( $labels_snapshot as $k => $v ) {
			$labels[ $k ] = (string) $v;
		}

		$list_url = add_query_arg(
			array_merge(
				array( 'page' => self::PAGE_SLUG ),
				self::list_context_for_urls( self::parse_list_form_filter(), self::parse_list_sort() )
			),
			admin_url( 'admin.php' )
		);
		$post_title = get_the_title( (int) $row->post_id );
		if ( '' === $post_title ) {
			$post_title = sprintf( __( 'Beitrag #%d (nicht mehr vorhanden)', 'gutenberg-formbuilder' ), (int) $row->post_id );
		}

		echo '<div class="wrap gfb-admin gfb-admin-submissions">';
		echo '<h1>' . esc_html__( 'Eintrag', 'gutenberg-formbuilder' ) . ' #' . esc_html( (string) $row->id ) . '</h1>';
		echo '<p class="gfb-admin-back"><a href="' . esc_url( $list_url ) . '">&larr; ' . esc_html__( 'Zurück zur Übersicht', 'gutenberg-formbuilder' ) . '</a></p>';

		echo '<div class="gfb-admin-meta-card">';
		echo '<h2 class="gfb-admin-meta-heading">' . esc_html__( 'Metadaten', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<dl class="gfb-admin-meta-grid">';
		echo '<dt>' . esc_html__( 'Datum', 'gutenberg-formbuilder' ) . '</dt><dd>' . esc_html( self::format_datetime( $row->created_at ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Seite / Beitrag', 'gutenberg-formbuilder' ) . '</dt><dd>' . esc_html( $post_title ) . '</dd>';
		echo '<dt>' . esc_html__( 'Formular-ID', 'gutenberg-formbuilder' ) . '</dt><dd><code>' . esc_html( (string) $row->form_id ) . '</code></dd>';
		$form_title_meta = isset( $row->form_title ) ? trim( (string) $row->form_title ) : '';
		if ( '' !== $form_title_meta ) {
			echo '<dt>' . esc_html__( 'Formularname', 'gutenberg-formbuilder' ) . '</dt><dd>' . esc_html( $form_title_meta ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'IP-Adresse', 'gutenberg-formbuilder' ) . '</dt><dd>' . esc_html( (string) $row->ip_address ) . '</dd>';
		$ua = isset( $row->user_agent ) ? (string) $row->user_agent : '';
		if ( '' !== $ua ) {
			echo '<dt>' . esc_html__( 'User-Agent', 'gutenberg-formbuilder' ) . '</dt><dd>' . esc_html( $ua ) . '</dd>';
		}
		echo '</dl></div>';

		echo '<h2 class="gfb-admin-fields-heading">' . esc_html__( 'Übermittelte Felder', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<div class="gfb-admin-field-list">';

		$field_rows = array_filter(
			array_keys( $payload ),
			static function ( $key ) {
				return '_gfb_labels' !== $key;
			}
		);

		if ( empty( $field_rows ) ) {
			echo '<p class="gfb-admin-empty">' . esc_html__( 'Keine Felddaten.', 'gutenberg-formbuilder' ) . '</p>';
		} else {
			$can_decrypt = GFB_Capabilities::user_can( GFB_Capabilities::CAP_DECRYPT_SUBMISSIONS );
			$can_dl      = GFB_Capabilities::user_can( GFB_Capabilities::CAP_DOWNLOAD_FILES );
			$any_decrypt = false;
			foreach ( $field_rows as $key ) {
				$value = $payload[ $key ];
				$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
				echo '<div class="gfb-admin-field">';
				echo '<div class="gfb-admin-field-label">' . esc_html( $label ) . '</div>';
				echo '<div class="gfb-admin-field-value">' . self::format_field_value( $value, $key, $can_decrypt, $can_dl, $any_decrypt ) . '</div>';
				echo '</div>';
			}
			if ( $any_decrypt ) {
				GFB_Audit::record(
					'submission_decrypted',
					'submission',
					(string) $row->id,
					array( 'fields_decrypted' => true )
				);
			}
		}

		echo '</div>';

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'action'     => 'delete',
					'submission' => (int) $row->id,
				),
				admin_url( 'admin.php' )
			),
			'gfb_delete_' . (int) $row->id
		);
		echo '<div class="gfb-admin-actions"><a href="' . esc_url( $delete_url ) . '" class="button gfb-admin-btn-delete" onclick="return confirm(\'' . esc_js( __( 'Diesen Eintrag wirklich löschen?', 'gutenberg-formbuilder' ) ) . '\');">' . esc_html__( 'Eintrag löschen', 'gutenberg-formbuilder' ) . '</a></div>';

		echo '</div>';
	}

	/**
	 * @param mixed   $value         Field value (kann Envelope-Array, File-Ref-Array oder String sein).
	 * @param string  $field_name    Feldname (für AAD beim Entschlüsseln).
	 * @param bool    $can_decrypt   Darf entschlüsseln.
	 * @param bool    $can_download  Darf Datei-Anhänge herunterladen.
	 * @param bool    &$any_decrypt  Wird true gesetzt, sobald mindestens ein Wert entschlüsselt wurde (für Audit).
	 * @return string HTML (escaped).
	 */
	private static function format_field_value( $value, $field_name = '', $can_decrypt = false, $can_download = false, &$any_decrypt = false ) {
		// 1) Datei-Referenz?
		if ( is_array( $value ) && isset( $value['_ref'] ) && 0 === strpos( (string) $value['_ref'], 'gfb-file:' ) ) {
			$file_id = isset( $value['file_id'] ) ? (int) $value['file_id'] : (int) substr( $value['_ref'], strlen( 'gfb-file:' ) );
			$file    = $file_id > 0 ? GFB_File_Storage::get( $file_id ) : null;
			if ( ! $file ) {
				return '<em>' . esc_html__( '[Datei #', 'gutenberg-formbuilder' ) . esc_html( (string) $file_id ) . esc_html__( ' nicht gefunden]', 'gutenberg-formbuilder' ) . '</em>';
			}
			$meta = sprintf( '%s — %s — %s', $file->original_name, size_format( (int) $file->size_bytes ), $file->mime );
			$out  = '<div class="gfb-admin-file">';
			$out .= '<div><strong>' . esc_html( $file->original_name ) . '</strong>'
				. ' <span class="gfb-admin-file-meta">(' . esc_html( $meta ) . ')</span></div>';
			$out .= '<div class="gfb-admin-file-fingerprint"><code>sha256: ' . esc_html( $file->sha256_plain ) . '</code></div>';
			if ( $can_download ) {
				$out .= '<div><a class="button" href="' . esc_url( GFB_File_Storage::download_url( $file_id ) ) . '">'
					. esc_html__( 'Verschlüsselt herunterladen (Klartext-Stream)', 'gutenberg-formbuilder' )
					. '</a></div>';
			} else {
				$out .= '<div><em>' . esc_html__( 'Keine Berechtigung zum Download.', 'gutenberg-formbuilder' ) . '</em></div>';
			}
			$out .= '</div>';
			return $out;
		}

		// 2) Verschlüsseltes Feld-Envelope?
		if ( GFB_Crypto::is_field_envelope( $value ) ) {
			if ( ! $can_decrypt ) {
				return '<em class="gfb-admin-encrypted">' . esc_html__( '[verschlüsselt — keine Berechtigung zum Entschlüsseln]', 'gutenberg-formbuilder' ) . '</em>'
					. ' <small>(key #' . esc_html( (string) $value['key_id'] ) . ')</small>';
			}
			$plain = GFB_Crypto::decrypt_field( $value, 'field:' . (string) $field_name );
			if ( false === $plain ) {
				return '<em class="gfb-admin-encrypted">' . esc_html__( '[Entschlüsselung fehlgeschlagen — möglicherweise wurde der Schlüssel rotiert]', 'gutenberg-formbuilder' ) . '</em>';
			}
			$any_decrypt = true;
			return '<span class="gfb-admin-decrypted">' . nl2br( esc_html( (string) $plain ) ) . '</span>'
				. ' <small class="gfb-admin-decrypted-meta">' . esc_html__( '(entschlüsselt)', 'gutenberg-formbuilder' ) . '</small>';
		}

		// 3) Plain-Array (Mehrfachauswahl etc.)
		if ( is_array( $value ) ) {
			return '<pre class="gfb-admin-pre">' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		}

		$s = (string) $value;
		if ( preg_match( '#^https?://#i', $s ) ) {
			return '<a href="' . esc_url( $s ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $s ) . '</a>';
		}
		return nl2br( esc_html( $s ) );
	}

	/**
	 * @param int    $post_id Post id.
	 * @param string $form_id Form id.
	 * @return array<string,string> name => label
	 */
	private static function field_labels_map( $post_id, $form_id ) {
		$schema = GFB_Plugin::get_form_schema_from_post( $post_id, $form_id );
		$out    = array();
		foreach ( $schema as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$out[ $field['name'] ] = isset( $field['label'] ) ? (string) $field['label'] : (string) $field['name'];
			}
		}
		return $out;
	}

	/**
	 * @param string|null $mysql_datetime From DB.
	 * @return string
	 */
	private static function format_datetime( $mysql_datetime ) {
		if ( ! $mysql_datetime ) {
			return '';
		}
		$ts = strtotime( (string) $mysql_datetime );
		if ( false === $ts ) {
			return (string) $mysql_datetime;
		}
		return wp_date(
			__( 'd.m.Y H:i', 'gutenberg-formbuilder' ),
			$ts
		);
	}

	/**
	 * Sortierung der Eintragsliste (GET `gfb_sort`).
	 *
	 * @return string date_desc|date_asc|form_asc|form_desc
	 */
	private static function parse_list_sort() {
		if ( ! isset( $_GET['gfb_sort'] ) ) {
			return 'date_desc';
		}
		$s = sanitize_key( wp_unslash( $_GET['gfb_sort'] ) );
		$allowed = array( 'date_desc', 'date_asc', 'form_asc', 'form_desc' );
		return in_array( $s, $allowed, true ) ? $s : 'date_desc';
	}

	/**
	 * Filter nach Formular-ID (GET `gfb_filter_form_id`).
	 *
	 * @return string Leerstring = alle.
	 */
	private static function parse_list_form_filter() {
		if ( ! isset( $_GET['gfb_filter_form_id'] ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_GET['gfb_filter_form_id'] ) );
	}

	/**
	 * @param string $sort Wert von parse_list_sort().
	 * @return string Nur aus Whitelist — für ORDER BY interpolierbar.
	 */
	private static function order_sql_for_list_sort( $sort ) {
		switch ( $sort ) {
			case 'date_asc':
				return 'created_at ASC, id ASC';
			case 'form_asc':
				return 'form_title ASC, form_id ASC, created_at DESC, id DESC';
			case 'form_desc':
				return 'form_title DESC, form_id DESC, created_at DESC, id DESC';
			case 'date_desc':
			default:
				return 'created_at DESC, id DESC';
		}
	}

	/**
	 * Query-Parameter für Links (Filter + Sortierung), ohne `page`.
	 *
	 * @param string $filter_form_id sanitize_key.
	 * @param string $sort           parse_list_sort-Wert.
	 * @return array<string,string>
	 */
	private static function list_context_for_urls( $filter_form_id, $sort ) {
		$args = array();
		if ( '' !== $filter_form_id ) {
			$args['gfb_filter_form_id'] = $filter_form_id;
		}
		if ( 'date_desc' !== $sort ) {
			$args['gfb_sort'] = $sort;
		}
		return $args;
	}

	/**
	 * @return void
	 */
	private static function render_list() {
		global $wpdb;

		echo '<div class="wrap gfb-admin gfb-admin-submissions gfb-admin-list">';
		echo '<h1>' . esc_html__( 'Formular-Einträge', 'gutenberg-formbuilder' ) . '</h1>';
		echo '<p class="gfb-admin-lead">' . esc_html__(
			'Hier erscheinen alle erfolgreich abgeschickten Formulare (serverseitig in der Datenbank gespeichert).',
			'gutenberg-formbuilder'
		) . '</p>';

		if ( isset( $_GET['deleted'] ) && '1' === $_GET['deleted'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Eintrag wurde gelöscht.', 'gutenberg-formbuilder' ) . '</p></div>';
		}

		if ( ! self::table_exists() ) {
			self::render_missing_table_notice();
			echo '</div>';
			return;
		}

		$filter_form_id = self::parse_list_form_filter();
		$sort           = self::parse_list_sort();
		$order_sql      = self::order_sql_for_list_sort( $sort );

		$per_page = 20;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$table = self::table_name();

		if ( '' !== $filter_form_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %s", $filter_form_id ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $paged > $pages ) {
			$paged  = $pages;
			$offset = ( $paged - 1 ) * $per_page;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$form_groups = $wpdb->get_results( "SELECT form_id, MAX(form_title) AS form_title FROM {$table} GROUP BY form_id ORDER BY form_title ASC, form_id ASC" );
		if ( ! is_array( $form_groups ) ) {
			$form_groups = array();
		}

		$sql = "SELECT id, created_at, post_id, form_id, form_title, payload FROM {$table}";
		if ( '' !== $filter_form_id ) {
			$sql .= ' WHERE form_id = %s';
		}
		$sql .= " ORDER BY {$order_sql} LIMIT %d OFFSET %d";

		if ( '' !== $filter_form_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $order_sql nur aus Whitelist.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $filter_form_id, $per_page, $offset ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $per_page, $offset ) );
		}

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$list_ctx = self::list_context_for_urls( $filter_form_id, $sort );

		echo '<form method="get" class="gfb-admin-filters" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label class="gfb-admin-filter-label"><span class="screen-reader-text">' . esc_html__( 'Formular', 'gutenberg-formbuilder' ) . '</span>';
		echo '<select name="gfb_filter_form_id">';
		echo '<option value="">' . esc_html__( 'Alle Formulare', 'gutenberg-formbuilder' ) . '</option>';
		foreach ( $form_groups as $fg ) {
			$fid = isset( $fg->form_id ) ? (string) $fg->form_id : '';
			if ( '' === $fid ) {
				continue;
			}
			$flabel = isset( $fg->form_title ) ? trim( (string) $fg->form_title ) : '';
			$optext = '' !== $flabel ? $flabel . ' (' . $fid . ')' : $fid;
			echo '<option value="' . esc_attr( $fid ) . '"' . selected( $filter_form_id, $fid, false ) . '>' . esc_html( $optext ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label class="gfb-admin-filter-label"><span class="screen-reader-text">' . esc_html__( 'Sortierung', 'gutenberg-formbuilder' ) . '</span>';
		echo '<select name="gfb_sort">';
		$sort_opts = array(
			'date_desc' => __( 'Datum: neueste zuerst', 'gutenberg-formbuilder' ),
			'date_asc'  => __( 'Datum: älteste zuerst', 'gutenberg-formbuilder' ),
			'form_asc'  => __( 'Formular: Name A–Z', 'gutenberg-formbuilder' ),
			'form_desc' => __( 'Formular: Name Z–A', 'gutenberg-formbuilder' ),
		);
		foreach ( $sort_opts as $val => $lab ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $sort, $val, false ) . '>' . esc_html( $lab ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Anwenden', 'gutenberg-formbuilder' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( 0 === $total ) {
			if ( '' !== $filter_form_id ) {
				echo '<p class="gfb-admin-empty">' . esc_html__( 'Keine Einsendungen für dieses Formular.', 'gutenberg-formbuilder' ) . '</p>';
			} else {
				echo '<p class="gfb-admin-empty">' . esc_html__( 'Noch keine Einsendungen.', 'gutenberg-formbuilder' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		echo '<div class="gfb-admin-table-wrap">';
		echo '<table class="wp-list-table widefat fixed striped gfb-admin-table">';
		echo '<thead><tr>';
		echo '<th scope="col" class="manage-column" style="width:5rem">' . esc_html__( 'ID', 'gutenberg-formbuilder' ) . '</th>';
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Datum', 'gutenberg-formbuilder' ) . '</th>';
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Seite', 'gutenberg-formbuilder' ) . '</th>';
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Formular', 'gutenberg-formbuilder' ) . '</th>';
		echo '<th scope="col" class="manage-column">' . esc_html__( 'Absender', 'gutenberg-formbuilder' ) . '</th>';
		echo '<th scope="col" class="manage-column" style="width:8rem">' . esc_html__( 'Aktionen', 'gutenberg-formbuilder' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$detail_url = add_query_arg(
				array_merge(
					array(
						'page'        => self::PAGE_SLUG,
						'submission' => (int) $row->id,
					),
					$list_ctx
				),
				admin_url( 'admin.php' )
			);
			$post_title = get_the_title( (int) $row->post_id );
			if ( '' === $post_title ) {
				$post_title = '—';
			}
			$sender_cell = self::sender_column_from_payload( (string) $row->payload );
			$row_title = isset( $row->form_title ) ? trim( (string) $row->form_title ) : '';

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row->id ) . '</td>';
			echo '<td>' . esc_html( self::format_datetime( $row->created_at ) ) . '</td>';
			echo '<td>' . esc_html( $post_title ) . '</td>';
			echo '<td class="gfb-admin-cell-form">';
			if ( '' !== $row_title ) {
				echo esc_html( $row_title ) . '<br><small class="gfb-admin-form-id"><code>' . esc_html( (string) $row->form_id ) . '</code></small>';
			} else {
				echo '<code>' . esc_html( (string) $row->form_id ) . '</code>';
			}
			echo '</td>';
			echo '<td class="gfb-admin-cell-sender">' . wp_kses_post( $sender_cell ) . '</td>';
			echo '<td><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Ansehen', 'gutenberg-formbuilder' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		if ( $pages > 1 ) {
			$pag_base_args = array_merge(
				array(
					'page'  => self::PAGE_SLUG,
					'paged' => '%#%',
				),
				$list_ctx
			);
			$base = add_query_arg( $pag_base_args, admin_url( 'admin.php' ) );
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Technischer Feldname für Abgleich (Kleinschreibung, Bindestrich → Unterstrich).
	 *
	 * @param string $key Payload-Schlüssel.
	 * @return string
	 */
	private static function normalize_payload_field_key( $key ) {
		return strtolower( str_replace( array( '-', ' ' ), '_', (string) $key ) );
	}

	/**
	 * Skalar aus Payload-Zelle; keine Arrays/Dateien/Envelopes.
	 *
	 * @param mixed $value Payload-Wert.
	 * @return string Leer wenn nicht verwendbar.
	 */
	private static function scalar_payload_cell( $value ) {
		if ( GFB_Crypto::is_field_envelope( $value ) ) {
			return '';
		}
		if ( is_array( $value ) ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$s = trim( wp_strip_all_tags( (string) $value ) );
		return $s;
	}

	/**
	 * E-Mail-Adresse aus Payload (heuristisch nach Feldnamen und Inhalt).
	 *
	 * @param array<string,mixed> $data Payload (ohne Meta).
	 * @return array{0:string,1:string} Erkennungsart: 'plain'|'encrypted'|'none', Anzeigetext.
	 */
	private static function extract_sender_email_from_payload( array $data ) {
		$exact_keys = array(
			'email',
			'e_mail',
			'kontakt_email',
			'kontakt_e_mail',
			'absender',
			'absender_email',
			'contact_email',
			'user_email',
			'your_email',
			'email_address',
		);
		foreach ( $data as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$nk = self::normalize_payload_field_key( $key );
			if ( ! in_array( $nk, $exact_keys, true ) && false === strpos( $nk, 'email' ) && 'mail' !== $nk ) {
				continue;
			}
			if ( GFB_Crypto::is_field_envelope( $value ) ) {
				return array( 'encrypted', __( '[E-Mail verschlüsselt]', 'gutenberg-formbuilder' ) );
			}
			$s = self::scalar_payload_cell( $value );
			if ( '' !== $s && is_email( $s ) ) {
				return array( 'plain', $s );
			}
		}
		foreach ( $data as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			if ( GFB_Crypto::is_field_envelope( $value ) ) {
				continue;
			}
			$s = self::scalar_payload_cell( $value );
			if ( '' !== $s && is_email( $s ) ) {
				return array( 'plain', $s );
			}
		}
		return array( 'none', '' );
	}

	/**
	 * Vor- und Nachname aus Payload (heuristisch nach Feldnamen).
	 *
	 * @param array<string,mixed> $data Payload.
	 * @return string Anzeige „Vorname Nachname“ oder leer.
	 */
	private static function extract_sender_name_from_payload( array $data ) {
		$first_keys = array( 'vorname', 'firstname', 'first_name', 'fname', 'givenname', 'given_name' );
		$last_keys  = array( 'nachname', 'lastname', 'last_name', 'lname', 'surname', 'familyname', 'family_name' );
		$full_keys  = array( 'name', 'fullname', 'full_name', 'vollname', 'display_name', 'absendername' );

		$first = '';
		$last  = '';
		foreach ( $data as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$nk = self::normalize_payload_field_key( $key );
			if ( ! in_array( $nk, $first_keys, true ) ) {
				continue;
			}
			if ( GFB_Crypto::is_field_envelope( $value ) ) {
				return __( '[Name verschlüsselt]', 'gutenberg-formbuilder' );
			}
			$candidate = self::scalar_payload_cell( $value );
			if ( '' !== $candidate ) {
				$first = $candidate;
				break;
			}
		}
		foreach ( $data as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$nk = self::normalize_payload_field_key( $key );
			if ( ! in_array( $nk, $last_keys, true ) ) {
				continue;
			}
			if ( GFB_Crypto::is_field_envelope( $value ) ) {
				return __( '[Name verschlüsselt]', 'gutenberg-formbuilder' );
			}
			$candidate = self::scalar_payload_cell( $value );
			if ( '' !== $candidate ) {
				$last = $candidate;
				break;
			}
		}
		if ( '' !== $first || '' !== $last ) {
			return trim( $first . ' ' . $last );
		}
		foreach ( $data as $key => $value ) {
			if ( '_gfb_labels' === $key ) {
				continue;
			}
			$nk = self::normalize_payload_field_key( $key );
			if ( ! in_array( $nk, $full_keys, true ) ) {
				continue;
			}
			if ( GFB_Crypto::is_field_envelope( $value ) ) {
				return __( '[Name verschlüsselt]', 'gutenberg-formbuilder' );
			}
			return self::scalar_payload_cell( $value );
		}
		return '';
	}

	/**
	 * Tabellenzelle „Absender“: E-Mail-Zeile + optional Name.
	 *
	 * @param string $json Payload JSON.
	 * @return string Escaped HTML.
	 */
	private static function sender_column_from_payload( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		list( , $email_disp ) = self::extract_sender_email_from_payload( $data );
		$name_disp          = self::extract_sender_name_from_payload( $data );

		if ( '' === $email_disp ) {
			$email_disp = '—';
		}
		$email_disp = mb_strlen( $email_disp ) > 190 ? mb_substr( $email_disp, 0, 190 ) . '…' : $email_disp;
		$name_disp  = mb_strlen( $name_disp ) > 190 ? mb_substr( $name_disp, 0, 190 ) . '…' : $name_disp;

		$out = '<div class="gfb-admin-sender-email">' . esc_html( $email_disp ) . '</div>';
		if ( '' !== trim( $name_disp ) ) {
			$out .= '<div class="gfb-admin-sender-name">' . esc_html( $name_disp ) . '</div>';
		}
		return $out;
	}

	/**
	 * @return void
	 */
	private static function render_missing_table_notice() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'Die Datenbanktabelle für Einsendungen fehlt. Bitte Plugin einmal deaktivieren und wieder aktivieren (oder die Aktivierung erneut ausführen), damit die Tabelle angelegt wird.',
			'gutenberg-formbuilder'
		);
		echo '</p></div>';
	}
}
