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
		// Export (CSV oder ZIP; kein nopriv-Pendant).
		add_action( 'admin_post_gfb_export', array( __CLASS__, 'handle_export' ) );
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

		$payload_raw = json_decode( (string) $row->payload, true );
		if ( ! is_array( $payload_raw ) ) {
			$payload_raw = array();
		}

		$labels_snapshot = isset( $payload_raw['_gfb_labels'] ) && is_array( $payload_raw['_gfb_labels'] ) ? $payload_raw['_gfb_labels'] : array();
		$payload         = GFB_Plugin::get_submission_payload_for_row( $row );
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

		// Export-Box (nur für Nutzer mit gfb_view_submissions; Kein-Formular-Hinweis wenn nötig).
		if ( GFB_Capabilities::user_can( GFB_Capabilities::CAP_VIEW_SUBMISSIONS ) ) {
			self::render_export_box( $filter_form_id, $sort, $total );
		}

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
			$sender_cell = self::sender_column_from_payload( (string) $row->payload, (string) $row->form_id, (int) $row->post_id );
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
	private static function sender_column_from_payload( $json, $form_id = '', $post_id = 0 ) {
		if ( '' !== $form_id ) {
			$data = GFB_Plugin::get_submission_payload_for_row(
				array(
					'form_id'  => $form_id,
					'post_id'  => $post_id,
					'payload'  => (string) $json,
				)
			);
		} else {
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			unset( $data['_gfb_labels'] );
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

	/* ------------------------------------------------------------------ *
	 * CSV-Export
	 * ------------------------------------------------------------------ */

	/**
	 * Rendert die Export-Box unter der Filter-Zeile.
	 *
	 * @param string $filter_form_id Aktiver Formular-Filter (leer = alle).
	 * @param string $sort           Aktuelle Sortierung.
	 * @param int    $total          Anzahl Einträge im aktuellen Filter.
	 * @return void
	 */
	private static function render_export_box( $filter_form_id, $sort, $total ) {
		$can_decrypt = GFB_Capabilities::user_can( GFB_Capabilities::CAP_DECRYPT_SUBMISSIONS );
		$can_dl      = GFB_Capabilities::user_can( GFB_Capabilities::CAP_DOWNLOAD_FILES );

		echo '<div class="gfb-admin-export">';

		if ( '' === $filter_form_id ) {
			echo '<p class="gfb-admin-export-notice">'
				. esc_html__( 'Bitte zuerst ein Formular auswählen, um einen CSV-Export zu starten.', 'gutenberg-formbuilder' )
				. '</p>';
			echo '</div>';
			return;
		}

		// Form-Metadaten.
		global $wpdb;
		$table     = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$form_title_db = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(form_title) FROM {$table} WHERE form_id = %s", $filter_form_id ) );
		$form_label    = '' !== trim( $form_title_db ) ? trim( $form_title_db ) : $filter_form_id;

		// Post-ID für Schema-Lookup aus jüngster Submission.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_id_for_box = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE form_id = %s ORDER BY id DESC LIMIT 1", $filter_form_id ) );

		// Prüfe, ob das Formular Datei-Felder hat und ob ZipArchive verfügbar ist.
		$has_file_fields = $post_id_for_box > 0 && self::form_has_file_field( $post_id_for_box, $filter_form_id );
		$zip_available   = class_exists( 'ZipArchive' );
		$show_zip_btn    = $has_file_fields && $can_dl && $zip_available;

		/* translators: 1: Formularname, 2: Anzahl Einträge */
		$info_text = sprintf(
			esc_html__( 'CSV-Export · %1$s (%2$d %3$s)', 'gutenberg-formbuilder' ),
			esc_html( $form_label ),
			(int) $total,
			1 === (int) $total
				? esc_html__( 'Eintrag', 'gutenberg-formbuilder' )
				: esc_html__( 'Einträge', 'gutenberg-formbuilder' )
		);

		// Ein einziges Form für beide Export-Varianten.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gfb-admin-export-form">';

		echo '<div class="gfb-admin-export-info-group"><strong>' . $info_text . '</strong></div>';

		echo '<div class="gfb-admin-export-controls">';

		wp_nonce_field( 'gfb_export', '_gfb_export_nonce' );
		echo '<input type="hidden" name="action" value="gfb_export" />';
		echo '<input type="hidden" name="gfb_export_form_id" value="' . esc_attr( $filter_form_id ) . '" />';
		echo '<input type="hidden" name="gfb_export_sort" value="' . esc_attr( $sort ) . '" />';

		if ( $can_decrypt ) {
			echo '<label class="gfb-admin-export-decrypt-label">';
			echo '<input type="checkbox" name="gfb_export_decrypt" value="1" /> ';
			echo esc_html__( 'Felder entschlüsseln', 'gutenberg-formbuilder' );
			echo ' <span class="gfb-admin-export-log-hint">(' . esc_html__( 'protokolliert', 'gutenberg-formbuilder' ) . ')</span>';
			echo '</label>';
		}

		echo '<div class="gfb-admin-export-btn-group">';
		echo '<button type="submit" name="gfb_export_kind" value="csv" class="button button-secondary">'
			. esc_html__( 'Als CSV exportieren', 'gutenberg-formbuilder' )
			. '</button>';

		if ( $show_zip_btn ) {
			echo '<button type="submit" name="gfb_export_kind" value="zip" class="button button-primary">'
				. esc_html__( 'CSV + Dateien (ZIP)', 'gutenberg-formbuilder' )
				. '</button>';
		} elseif ( $has_file_fields && $can_dl && ! $zip_available ) {
			echo '<span class="gfb-admin-export-zip-unavailable">'
				. esc_html__( 'ZIP nicht verfügbar (PHP-ZipArchive fehlt)', 'gutenberg-formbuilder' )
				. '</span>';
		}

		echo '</div>';

		echo '</div>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * admin_post-Handler für Export (CSV oder ZIP). Gemeinsame Prüfkette,
	 * dann Verzweigung nach gfb_export_kind.
	 *
	 * Prüfreihenfolge: eingeloggt → gfb_view_submissions → Nonce → form_id → Tabelle.
	 * ZIP: zusätzlich gfb_download_files + ZipArchive.
	 *
	 * @return void
	 */
	public static function handle_export() {
		// 1) Eingeloggt?
		if ( ! is_user_logged_in() ) {
			GFB_Audit::record( 'submission_export_denied', 'form', '', array( 'reason' => 'not_logged_in' ) );
			wp_die(
				esc_html__( 'Anmeldung erforderlich.', 'gutenberg-formbuilder' ),
				esc_html__( 'Authentifizierung', 'gutenberg-formbuilder' ),
				array( 'response' => 401 )
			);
		}

		// 2) Cap gfb_view_submissions?
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_VIEW_SUBMISSIONS ) ) {
			GFB_Audit::record( 'submission_export_denied', 'form', '', array( 'reason' => 'capability' ) );
			wp_die(
				esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ),
				esc_html__( 'Berechtigung', 'gutenberg-formbuilder' ),
				array( 'response' => 403 )
			);
		}

		// 3) Nonce prüfen.
		if ( ! check_admin_referer( 'gfb_export', '_gfb_export_nonce' ) ) {
			GFB_Audit::record( 'submission_export_denied', 'form', '', array( 'reason' => 'nonce' ) );
			wp_die(
				esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'gutenberg-formbuilder' ),
				esc_html__( 'Nonce', 'gutenberg-formbuilder' ),
				array( 'response' => 403 )
			);
		}

		// 4) form_id aus POST lesen.
		$form_id = isset( $_POST['gfb_export_form_id'] ) ? sanitize_key( wp_unslash( $_POST['gfb_export_form_id'] ) ) : '';
		if ( '' === $form_id ) {
			GFB_Audit::record( 'submission_export_denied', 'form', '', array( 'reason' => 'no_form' ) );
			wp_die(
				esc_html__( 'Kein Formular gewählt.', 'gutenberg-formbuilder' ),
				esc_html__( 'Fehler', 'gutenberg-formbuilder' ),
				array( 'response' => 400 )
			);
		}

		// 5) Tabelle muss existieren.
		if ( ! self::table_exists() ) {
			GFB_Audit::record( 'submission_export_denied', 'form', $form_id, array( 'reason' => 'no_table' ) );
			wp_die(
				esc_html__( 'Datenbanktabelle nicht gefunden.', 'gutenberg-formbuilder' ),
				esc_html__( 'Fehler', 'gutenberg-formbuilder' ),
				array( 'response' => 500 )
			);
		}

		// 6) Export-Art bestimmen.
		$kind = sanitize_key( isset( $_POST['gfb_export_kind'] ) ? wp_unslash( $_POST['gfb_export_kind'] ) : 'csv' );
		if ( ! in_array( $kind, array( 'csv', 'zip' ), true ) ) {
			$kind = 'csv';
		}

		// 7) ZIP: zusätzliche Prüfungen.
		if ( 'zip' === $kind ) {
			if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_DOWNLOAD_FILES ) ) {
				GFB_Audit::record( 'submission_export_denied', 'form', $form_id, array( 'reason' => 'capability_download' ) );
				wp_die(
					esc_html__( 'Keine Berechtigung zum Datei-Download.', 'gutenberg-formbuilder' ),
					esc_html__( 'Berechtigung', 'gutenberg-formbuilder' ),
					array( 'response' => 403 )
				);
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				GFB_Audit::record( 'submission_export_denied', 'form', $form_id, array( 'reason' => 'no_ziparchive' ) );
				wp_die(
					esc_html__( 'ZIP-Export nicht verfügbar: PHP-ZipArchive fehlt auf diesem Server.', 'gutenberg-formbuilder' ),
					esc_html__( 'Fehler', 'gutenberg-formbuilder' ),
					array( 'response' => 500 )
				);
			}
		}

		// 8) Decrypt-Wunsch nur dann effektiv, wenn Cap gfb_decrypt_submissions vorhanden.
		$decrypt_requested = isset( $_POST['gfb_export_decrypt'] ) && '1' === $_POST['gfb_export_decrypt'];
		$can_decrypt       = GFB_Capabilities::user_can( GFB_Capabilities::CAP_DECRYPT_SUBMISSIONS );
		if ( $decrypt_requested && ! $can_decrypt ) {
			$decrypt_requested = false;
		}

		// Kein PHP-Timeout für grosse Exporte.
		set_time_limit( 0 );

		if ( 'zip' === $kind ) {
			self::stream_zip( $form_id, $decrypt_requested, $can_decrypt );
		} else {
			self::stream_csv( $form_id, $decrypt_requested, $can_decrypt );
		}
	}

	/**
	 * Streamt einen CSV-Export direkt auf php://output.
	 *
	 * @param string $form_id           Formular-ID.
	 * @param bool   $decrypt_requested Nutzer hat Decrypt-Option gewählt.
	 * @param bool   $can_decrypt       Nutzer hat Cap gfb_decrypt_submissions.
	 * @return void
	 */
	private static function stream_csv( $form_id, $decrypt_requested, $can_decrypt ) {
		// Sortierung aus POST (Whitelist).
		$sort_raw  = isset( $_POST['gfb_export_sort'] ) ? sanitize_key( wp_unslash( $_POST['gfb_export_sort'] ) ) : 'date_desc';
		$allowed   = array( 'date_desc', 'date_asc', 'form_asc', 'form_desc' );
		$sort      = in_array( $sort_raw, $allowed, true ) ? $sort_raw : 'date_desc';
		$order_sql = self::order_sql_for_list_sort( $sort );

		global $wpdb;
		$table = self::table_name();

		// Alle Datensätze des Formulars (kein LIMIT/OFFSET).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s ORDER BY {$order_sql}", $form_id ) );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$count = count( $rows );

		// Spaltendefinition aus Schema ermitteln.
		$post_id_for_schema = 0;
		if ( ! empty( $rows ) ) {
			$post_id_for_schema = (int) $rows[0]->post_id;
		}

		$schema_fields = array();
		if ( $post_id_for_schema > 0 ) {
			$schema = GFB_Plugin::get_form_schema_from_post( $post_id_for_schema, $form_id );
			foreach ( $schema as $field ) {
				$n = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
				if ( '' !== $n ) {
					$schema_fields[] = array(
						'name'  => $n,
						'label' => isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $n,
					);
				}
			}
		}

		// Fallback: Vereinigungsmenge aller Payload-Keys.
		if ( empty( $schema_fields ) ) {
			$union_keys = array();
			foreach ( $rows as $row ) {
				$payload = GFB_Plugin::get_submission_payload_for_row( $row );
				foreach ( array_keys( $payload ) as $k ) {
					if ( ! isset( $union_keys[ $k ] ) ) {
						$union_keys[ $k ] = true;
						$schema_fields[]  = array( 'name' => $k, 'label' => $k );
					}
				}
			}
		}

		// Meta-Spalten.
		$meta_cols = array(
			array( 'name' => 'id',         'label' => __( 'ID',            'gutenberg-formbuilder' ) ),
			array( 'name' => 'created_at', 'label' => __( 'Datum',         'gutenberg-formbuilder' ) ),
			array( 'name' => 'post_id',    'label' => __( 'Seiten-ID',     'gutenberg-formbuilder' ) ),
			array( 'name' => 'form_id',    'label' => __( 'Formular-ID',   'gutenberg-formbuilder' ) ),
			array( 'name' => 'form_title', 'label' => __( 'Formularname',  'gutenberg-formbuilder' ) ),
		);
		// $decrypt_mode: Berechtigung + Checkbox aktiv → IP-Spalte + Audit.
		$decrypt_mode = ( $can_decrypt && $decrypt_requested );
		if ( $decrypt_mode ) {
			$meta_cols[] = array( 'name' => 'ip_address', 'label' => __( 'IP-Adresse', 'gutenberg-formbuilder' ) );
		}

		// Kopfzeile.
		$header_row = array();
		foreach ( $meta_cols as $col ) {
			$header_row[] = self::csv_harden_cell( $col['label'] );
		}
		foreach ( $schema_fields as $col ) {
			$header_row[] = self::csv_harden_cell( $col['label'] );
		}

		// Download-Header senden.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		$ts       = current_time( 'Y-m-d-Hi' );
		$filename = sanitize_file_name( 'gfb-export-' . $form_id . '-' . $ts . '.csv' );
		$filename = str_replace( array( '"', "\r", "\n" ), '_', $filename );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// Stream auf php://output.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( 'php://output', 'w' );
		if ( false === $fh ) {
			exit;
		}

		// UTF-8-BOM.
		fwrite( $fh, "\xEF\xBB\xBF" );

		// Kopfzeile schreiben.
		if ( PHP_VERSION_ID >= 80100 ) {
			fputcsv( $fh, $header_row, ';', '"', '' );
		} else {
			fputcsv( $fh, $header_row, ';', '"' );
		}

		$fields_count = 0;

		foreach ( $rows as $row ) {
			$payload  = GFB_Plugin::get_submission_payload_for_row( $row );
			$data_row = array();

			$data_row[] = self::csv_harden_cell( (string) $row->id );
			$data_row[] = self::csv_harden_cell( (string) $row->created_at );
			$data_row[] = self::csv_harden_cell( (string) $row->post_id );
			$data_row[] = self::csv_harden_cell( (string) $row->form_id );
			$data_row[] = self::csv_harden_cell( isset( $row->form_title ) ? (string) $row->form_title : '' );
			if ( $decrypt_mode ) {
				$data_row[] = self::csv_harden_cell( isset( $row->ip_address ) ? (string) $row->ip_address : '' );
			}

			foreach ( $schema_fields as $col ) {
				$field_name = $col['name'];
				$value      = isset( $payload[ $field_name ] ) ? $payload[ $field_name ] : null;
				$cell       = self::csv_cell_for_value( $value, $field_name, $can_decrypt, $decrypt_requested, $fields_count );
				$data_row[] = self::csv_harden_cell( $cell );
			}

			if ( PHP_VERSION_ID >= 80100 ) {
				fputcsv( $fh, $data_row, ';', '"', '' );
			} else {
				fputcsv( $fh, $data_row, ';', '"' );
			}
		}

		// Audit-Einträge vor fclose/exit.
		GFB_Audit::record(
			'submission_exported',
			'form',
			$form_id,
			array(
				'count'            => $count,
				'decrypt_mode'     => $decrypt_mode,
				'fields_decrypted' => $fields_count,
				'format'           => 'csv',
			)
		);

		if ( $decrypt_mode ) {
			GFB_Audit::record(
				'submission_exported_decrypted',
				'form',
				$form_id,
				array(
					'fields_decrypted' => $fields_count,
					'format'           => 'csv',
				)
			);
		}

		fclose( $fh );
		exit;
	}

	/**
	 * Erzeugt den Zellenwert für ein Formularfeld im CSV (reiner Text, kein HTML).
	 *
	 * @param mixed  $value             Feldwert aus dem Payload (null wenn Feld fehlt).
	 * @param string $field_name        Feldname (für AAD beim Entschlüsseln).
	 * @param bool   $can_decrypt       Nutzer hat Cap gfb_decrypt_submissions.
	 * @param bool   $decrypt_requested Nutzer hat Decrypt-Option aktiviert.
	 * @param int    &$fields_count     Zähler für tatsächlich entschlüsselte Felder.
	 * @return string Zellenwert als reiner Text.
	 */
	private static function csv_cell_for_value( $value, $field_name, $can_decrypt, $decrypt_requested, &$fields_count ) {
		// Leerer / fehlender Wert.
		if ( null === $value || '' === $value ) {
			return '';
		}

		// 1) Datei-Referenz (Array mit _ref = 'gfb-file:…').
		if ( is_array( $value ) && isset( $value['_ref'] ) && 0 === strpos( (string) $value['_ref'], 'gfb-file:' ) ) {
			$file_id = isset( $value['file_id'] ) ? (int) $value['file_id'] : (int) substr( (string) $value['_ref'], strlen( 'gfb-file:' ) );
			$file    = $file_id > 0 ? GFB_File_Storage::get( $file_id ) : null;
			if ( ! $file ) {
				return '[Datei #' . $file_id . ' nicht gefunden]';
			}
			return $file->original_name . ' (' . size_format( (int) $file->size_bytes ) . ') sha256:' . $file->sha256_plain;
		}

		// 2) Verschlüsseltes Envelope.
		if ( GFB_Crypto::is_field_envelope( $value ) ) {
			if ( $decrypt_requested && $can_decrypt ) {
				$plain = GFB_Crypto::decrypt_field( $value, 'field:' . (string) $field_name );
				if ( false === $plain ) {
					return '[Entschlüsselung fehlgeschlagen]';
				}
				++$fields_count;
				return wp_strip_all_tags( (string) $plain );
			}
			return '[verschlüsselt]';
		}

		// 3) Array (Mehrfachauswahl).
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		// 4) Skalar.
		return wp_strip_all_tags( (string) $value );
	}

	/**
	 * CSV-Injection-Schutz: Stellt einem gefährlichen Zellenwert ein einfaches Hochkomma voran.
	 *
	 * Schützt vor Formelinjektionen in Excel, LibreOffice usw. Beginnt der Wert mit
	 * einem der Sonderzeichen = + - @ Tab oder Carriage-Return, wird ' vorangestellt.
	 *
	 * @param string $s Zellenwert als String.
	 * @return string Gehärteter Zellenwert.
	 */
	private static function csv_harden_cell( $s ) {
		if ( '' === $s ) {
			return '';
		}
		$first = $s[0];
		if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first
			|| "\t" === $first || "\r" === $first ) {
			return "'" . $s;
		}
		return $s;
	}

	/* ------------------------------------------------------------------ *
	 * ZIP-Export
	 * ------------------------------------------------------------------ */

	/**
	 * Prüft, ob mindestens ein Formular-Feld vom Typ «file» existiert.
	 *
	 * @param int    $post_id Post-ID (zum Auflösen des Schemas).
	 * @param string $form_id Formular-ID.
	 * @return bool
	 */
	private static function form_has_file_field( $post_id, $form_id ) {
		$schema = GFB_Plugin::get_form_schema_from_post( (int) $post_id, (string) $form_id );
		foreach ( $schema as $field ) {
			if ( isset( $field['type'] ) && 'file' === $field['type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bereinigt eine E-Mail-Adresse zu einem dateisystem-sicheren Ordnernamen.
	 *
	 * Erlaubt: A-Z a-z 0-9 . _ @ + -
	 * Alles andere wird durch _ ersetzt. Führende Punkte, .. sowie / und \ werden entfernt.
	 * Ergebnis wird auf 120 Zeichen begrenzt.
	 *
	 * @param string $email Roh-E-Mail.
	 * @return string Bereinigter Ordnername (niemals leer, da Fallback vor Aufruf greift).
	 */
	private static function sanitize_folder_name_from_email( $email ) {
		$name = preg_replace( '/[^A-Za-z0-9._@+\-]/', '_', (string) $email );
		$name = str_replace( array( '..', '/', '\\' ), '_', $name );
		$name = ltrim( $name, '.' );
		$name = trim( $name );
		if ( mb_strlen( $name ) > 120 ) {
			$name = mb_substr( $name, 0, 120 );
		}
		return $name;
	}

	/**
	 * Bestimmt den eindeutigen Ordnernamen für eine Einsendung im ZIP-Export.
	 *
	 * Logik:
	 * 1. E-Mail aus Payload extrahieren. Bei decrypt_mode: verschlüsselte E-Mail-Felder
	 *    entschlüsseln, bevor extract_sender_email_from_payload aufgerufen wird.
	 * 2. Ordnername = bereinigte E-Mail. Fallback: eintrag-<id>.
	 * 3. Eindeutigkeit im Export sicherstellen (Suffix -2, -3, …).
	 *
	 * @param array  $payload           Einsendungs-Payload (Envelopes ggf. noch verschlüsselt).
	 * @param int    $submission_id     Einsendungs-ID (Fallback).
	 * @param bool   $decrypt_mode      Decrypt-Modus aktiv.
	 * @param array  &$used_folder_names Set bereits vergebener Ordnernamen (über alle Einsendungen).
	 * @return string Eindeutiger Ordnername.
	 */
	private static function resolve_submission_folder_name( array $payload, $submission_id, $decrypt_mode, array &$used_folder_names ) {
		$lookup_payload = $payload;

		if ( $decrypt_mode ) {
			foreach ( $lookup_payload as $key => $value ) {
				if ( GFB_Crypto::is_field_envelope( $value ) ) {
					$plain = GFB_Crypto::decrypt_field( $value, 'field:' . (string) $key );
					if ( false !== $plain ) {
						$lookup_payload[ $key ] = $plain;
					}
				}
			}
		}

		list( $email_status, $email_raw ) = self::extract_sender_email_from_payload( $lookup_payload );

		if ( 'plain' === $email_status && '' !== $email_raw ) {
			$base = self::sanitize_folder_name_from_email( $email_raw );
		} else {
			$base = 'eintrag-' . (int) $submission_id;
		}

		if ( '' === $base ) {
			$base = 'eintrag-' . (int) $submission_id;
		}

		// Eindeutigkeit sichern.
		$candidate = $base;
		if ( ! isset( $used_folder_names[ $candidate ] ) ) {
			$used_folder_names[ $candidate ] = true;
			return $candidate;
		}

		$counter = 2;
		while ( true ) {
			$candidate = $base . '-' . $counter;
			if ( ! isset( $used_folder_names[ $candidate ] ) ) {
				$used_folder_names[ $candidate ] = true;
				return $candidate;
			}
			++$counter;
		}
	}

	/**
	 * Bestimmt den Dateinamen innerhalb des Einsendungs-Ordners im ZIP.
	 *
	 * Nur noch Originalname (kein Eintrag-/Feld-Präfix, da der Ordner die Einsendung
	 * bereits eindeutig identifiziert). Kollisionen werden per Suffix aufgelöst.
	 *
	 * @param string $original_name Originalname der Datei.
	 * @param int    $file_id       Datei-ID (für Fallback-Dateinamen).
	 * @param array  &$used_file_names Set bereits vergebener Dateinamen im selben Ordner.
	 * @return string Dateiname (ohne Ordnerpfad).
	 */
	private static function build_zip_file_name( $original_name, $file_id, array &$used_file_names ) {
		// Originalnamen bereinigen.
		$cleaned = sanitize_file_name( (string) $original_name );
		$cleaned = str_replace( array( '/', '\\', '..' ), '', $cleaned );
		$cleaned = trim( $cleaned );

		if ( '' === $cleaned ) {
			$cleaned = 'datei-' . (int) $file_id . '.bin';
		} else {
			// Länge auf 80 Zeichen begrenzen, Endung erhalten.
			if ( mb_strlen( $cleaned ) > 80 ) {
				$ext     = pathinfo( $cleaned, PATHINFO_EXTENSION );
				$base    = pathinfo( $cleaned, PATHINFO_FILENAME );
				$max     = 80 - ( '' !== $ext ? mb_strlen( $ext ) + 1 : 0 );
				$base    = mb_substr( $base, 0, max( 1, $max ) );
				$cleaned = '' !== $ext ? $base . '.' . $ext : $base;
			}
		}

		// Kollisionszähler innerhalb desselben Ordners.
		if ( ! isset( $used_file_names[ $cleaned ] ) ) {
			$used_file_names[ $cleaned ] = true;
			return $cleaned;
		}

		$ext     = pathinfo( $cleaned, PATHINFO_EXTENSION );
		$base    = pathinfo( $cleaned, PATHINFO_FILENAME );
		$counter = 2;
		while ( true ) {
			$new_name = '' !== $ext ? $base . '-' . $counter . '.' . $ext : $base . '-' . $counter;
			if ( ! isset( $used_file_names[ $new_name ] ) ) {
				$used_file_names[ $new_name ] = true;
				return $new_name;
			}
			++$counter;
		}
	}

	/**
	 * Erzeugt einen ZIP-Export (CSV + Dateien) und liefert ihn direkt aus.
	 *
	 * Wird von handle_export() aufgerufen; alle Berechtigungs- und ZipArchive-Prüfungen
	 * sind dort bereits abgeschlossen.
	 *
	 * @param string $form_id           Formular-ID.
	 * @param bool   $decrypt_requested Nutzer hat Decrypt-Option gewählt.
	 * @param bool   $can_decrypt       Nutzer hat Cap gfb_decrypt_submissions.
	 * @return void
	 */
	private static function stream_zip( $form_id, $decrypt_requested, $can_decrypt ) {
		$decrypt_mode = ( $can_decrypt && $decrypt_requested );

		// Sortierung aus POST (Whitelist).
		$sort_raw  = isset( $_POST['gfb_export_sort'] ) ? sanitize_key( wp_unslash( $_POST['gfb_export_sort'] ) ) : 'date_desc';
		$allowed   = array( 'date_desc', 'date_asc', 'form_asc', 'form_desc' );
		$sort      = in_array( $sort_raw, $allowed, true ) ? $sort_raw : 'date_desc';
		$order_sql = self::order_sql_for_list_sort( $sort );

		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s ORDER BY {$order_sql}", $form_id ) );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$count = count( $rows );

		// Schema ermitteln (Spaltenreihenfolge, Labels, Typen).
		$post_id_for_schema = 0;
		if ( ! empty( $rows ) ) {
			$post_id_for_schema = (int) $rows[0]->post_id;
		}
		$schema_fields = array();
		if ( $post_id_for_schema > 0 ) {
			$schema = GFB_Plugin::get_form_schema_from_post( $post_id_for_schema, $form_id );
			foreach ( $schema as $field ) {
				$n = isset( $field['name'] ) ? sanitize_key( (string) $field['name'] ) : '';
				if ( '' !== $n ) {
					$schema_fields[] = array(
						'name'  => $n,
						'label' => isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $n,
						'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
					);
				}
			}
		}
		if ( empty( $schema_fields ) ) {
			$union_keys = array();
			foreach ( $rows as $row ) {
				$payload = GFB_Plugin::get_submission_payload_for_row( $row );
				foreach ( array_keys( $payload ) as $k ) {
					if ( ! isset( $union_keys[ $k ] ) ) {
						$union_keys[ $k ]  = true;
						$schema_fields[]   = array( 'name' => $k, 'label' => $k, 'type' => '' );
					}
				}
			}
		}

		// Meta-Spalten (wie CSV-Export).
		$meta_cols = array(
			array( 'name' => 'id',         'label' => __( 'ID',            'gutenberg-formbuilder' ) ),
			array( 'name' => 'created_at', 'label' => __( 'Datum',         'gutenberg-formbuilder' ) ),
			array( 'name' => 'post_id',    'label' => __( 'Seiten-ID',     'gutenberg-formbuilder' ) ),
			array( 'name' => 'form_id',    'label' => __( 'Formular-ID',   'gutenberg-formbuilder' ) ),
			array( 'name' => 'form_title', 'label' => __( 'Formularname',  'gutenberg-formbuilder' ) ),
		);
		if ( $decrypt_mode ) {
			$meta_cols[] = array( 'name' => 'ip_address', 'label' => __( 'IP-Adresse', 'gutenberg-formbuilder' ) );
		}

		// Temp-Verzeichnis für ZIP: im privaten Storage-Root, nicht web-erreichbar.
		$tmp_dir = GFB_File_Storage::storage_root() . '/.tmp-export';
		wp_mkdir_p( $tmp_dir );
		$tmp_zip = $tmp_dir . '/gfb-zip-' . $form_id . '-' . wp_generate_password( 12, false, false ) . '.zip';

		// Aufräum-Funktion sofort nach Anlegen registrieren (auch bei Fatal/Timeout).
		register_shutdown_function(
			function () use ( $tmp_zip ) {
				if ( file_exists( $tmp_zip ) ) {
					@unlink( $tmp_zip );
				}
			}
		);

		// ZIP öffnen.
		$zip = new ZipArchive();
		$zip_result = $zip->open( $tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $zip_result ) {
			GFB_Audit::record( 'submission_export_denied', 'form', $form_id, array( 'reason' => 'zip_open_failed', 'code' => $zip_result ) );
			wp_die(
				esc_html__( 'ZIP-Datei konnte nicht erstellt werden.', 'gutenberg-formbuilder' ),
				esc_html__( 'Fehler', 'gutenberg-formbuilder' ),
				array( 'response' => 500 )
			);
		}
		@chmod( $tmp_zip, 0600 );

		// CSV-Puffer aufbauen (gleiche Logik wie CSV-Export).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$csv_fh = fopen( 'php://temp', 'r+' );
		if ( false === $csv_fh ) {
			$zip->close();
			wp_die( esc_html__( 'CSV-Puffer konnte nicht geöffnet werden.', 'gutenberg-formbuilder' ) );
		}

		// UTF-8-BOM.
		fwrite( $csv_fh, "\xEF\xBB\xBF" );

		// Kopfzeile (identisch zum CSV-Export).
		$header_row = array();
		foreach ( $meta_cols as $col ) {
			$header_row[] = self::csv_harden_cell( $col['label'] );
		}
		foreach ( $schema_fields as $col ) {
			$header_row[] = self::csv_harden_cell( $col['label'] );
		}
		if ( PHP_VERSION_ID >= 80100 ) {
			fputcsv( $csv_fh, $header_row, ';', '"', '' );
		} else {
			fputcsv( $csv_fh, $header_row, ';', '"' );
		}

		// Zähler für Audit.
		$fields_count      = 0;
		$files_included    = 0;
		$used_folder_names = array();

		// Datenzeilen aufbauen; Dateien parallel ins ZIP schreiben.
		foreach ( $rows as $row ) {
			$payload       = GFB_Plugin::get_submission_payload_for_row( $row );
			$submission_id = (int) $row->id;
			$data_row      = array();

			// Ordnername für Dateien dieser Einsendung bestimmen.
			$folder_name    = self::resolve_submission_folder_name( $payload, $submission_id, $decrypt_mode, $used_folder_names );
			$used_file_names = array();

			// Meta-Spalten.
			$data_row[] = self::csv_harden_cell( (string) $row->id );
			$data_row[] = self::csv_harden_cell( (string) $row->created_at );
			$data_row[] = self::csv_harden_cell( (string) $row->post_id );
			$data_row[] = self::csv_harden_cell( (string) $row->form_id );
			$data_row[] = self::csv_harden_cell( isset( $row->form_title ) ? (string) $row->form_title : '' );
			if ( $decrypt_mode ) {
				$data_row[] = self::csv_harden_cell( isset( $row->ip_address ) ? (string) $row->ip_address : '' );
			}

			// Schema-Felder.
			foreach ( $schema_fields as $col ) {
				$field_name = $col['name'];
				$value      = isset( $payload[ $field_name ] ) ? $payload[ $field_name ] : null;

				// Datei-Feld: im ZIP-Modus andere Zellen-Logik.
				if ( 'file' === $col['type']
					&& is_array( $value )
					&& isset( $value['_ref'] )
					&& 0 === strpos( (string) $value['_ref'], 'gfb-file:' )
				) {
					$file_id = isset( $value['file_id'] ) ? (int) $value['file_id'] : (int) substr( (string) $value['_ref'], strlen( 'gfb-file:' ) );
					$cell    = self::zip_cell_for_file( $file_id, $submission_id, $folder_name, $zip, $used_file_names, $files_included, $form_id );
					$data_row[] = self::csv_harden_cell( $cell );
					continue;
				}

				// Alle anderen Felder: reguläre CSV-Zellen-Logik.
				$data_row[] = self::csv_harden_cell(
					self::csv_cell_for_value( $value, $field_name, $can_decrypt, $decrypt_requested, $fields_count )
				);
			}

			if ( PHP_VERSION_ID >= 80100 ) {
				fputcsv( $csv_fh, $data_row, ';', '"', '' );
			} else {
				fputcsv( $csv_fh, $data_row, ';', '"' );
			}
		}

		// CSV-String aus Puffer lesen und ins ZIP schreiben.
		rewind( $csv_fh );
		$csv_string = stream_get_contents( $csv_fh );
		fclose( $csv_fh );
		$zip->addFromString( 'eintraege.csv', false !== $csv_string ? $csv_string : '' );

		$zip->close();

		// Dateiname des ZIPs.
		$form_title_db = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(form_title) FROM {$table} WHERE form_id = %s", $form_id ) );
		$form_slug     = '' !== trim( $form_title_db ) ? trim( $form_title_db ) : $form_id;
		$ts            = current_time( 'Y-m-d-Hi' );
		$zip_filename  = sanitize_file_name( 'export-' . $form_slug . '-' . $ts . '.zip' );
		$zip_filename  = str_replace( array( '"', "\r", "\n" ), '_', $zip_filename );

		// Audit-Einträge VOR Auslieferung schreiben.
		GFB_Audit::record(
			'submission_exported',
			'form',
			$form_id,
			array(
				'count'            => $count,
				'files_included'   => $files_included,
				'decrypt_mode'     => $decrypt_mode,
				'fields_decrypted' => $fields_count,
				'format'           => 'zip',
			)
		);
		if ( $decrypt_mode ) {
			GFB_Audit::record(
				'submission_exported_decrypted',
				'form',
				$form_id,
				array(
					'fields_decrypted' => $fields_count,
					'format'           => 'zip',
				)
			);
		}

		// ZIP ausliefern.
		$zip_size = file_exists( $tmp_zip ) ? filesize( $tmp_zip ) : 0;
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
		if ( $zip_size > 0 ) {
			header( 'Content-Length: ' . (int) $zip_size );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $tmp_zip );

		// Temp-Datei löschen (shutdown_function greift als Backup).
		if ( file_exists( $tmp_zip ) ) {
			@unlink( $tmp_zip );
		}
		exit;
	}

	/**
	 * Entschlüsselt eine Datei-Referenz, schreibt sie ins ZIP und gibt den ZIP-Pfad zurück.
	 *
	 * Gibt [Datei nicht gefunden] oder [Entschluesselung fehlgeschlagen] zurück, wenn
	 * etwas schief läuft. In beiden Fällen landet keine Datei im ZIP.
	 *
	 * @param int         $file_id         Datei-ID.
	 * @param int         $submission_id   Submission-ID (nur für Audit).
	 * @param string      $folder_name     Vorberechneter Ordnername dieser Einsendung.
	 * @param ZipArchive  $zip             Offenes ZipArchive-Objekt.
	 * @param array       &$used_file_names Set bereits vergebener Dateinamen in diesem Ordner.
	 * @param int         &$files_included Zähler erfolgreich hinzugefügter Dateien.
	 * @param string      $form_id         Formular-ID (für Audit).
	 * @return string Zellenwert für die CSV (relativer ZIP-Pfad oder Fehlertext).
	 */
	private static function zip_cell_for_file( $file_id, $submission_id, $folder_name, ZipArchive $zip, array &$used_file_names, &$files_included, $form_id ) {
		$file_id = (int) $file_id;
		if ( $file_id <= 0 ) {
			return '[Datei nicht gefunden]';
		}

		$file = GFB_File_Storage::get( $file_id );
		if ( ! $file ) {
			return '[Datei nicht gefunden]';
		}

		$plain = GFB_File_Storage::decrypt_to_string( $file );
		if ( is_wp_error( $plain ) ) {
			return '[Entschluesselung fehlgeschlagen]';
		}

		$file_name  = self::build_zip_file_name( (string) $file->original_name, $file_id, $used_file_names );
		$entry_path = 'dateien/' . $folder_name . '/' . $file_name;

		$zip->addFromString( $entry_path, $plain );

		// Klartext sofort aus dem Speicher entfernen.
		$plain = str_repeat( "\0", strlen( $plain ) );
		unset( $plain );

		// Audit-Eintrag pro Datei.
		GFB_Audit::record(
			'file_exported',
			'file',
			(string) $file_id,
			array(
				'sha256'        => (string) $file->sha256_plain,
				'form_id'       => $form_id,
				'submission_id' => $submission_id,
			)
		);

		++$files_included;
		return $entry_path;
	}
}
