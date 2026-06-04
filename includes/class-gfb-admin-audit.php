<?php
/**
 * Admin-Unterseite: Audit-Log.
 *
 * Zeigt alle Audit-Einträge mit Pagination und enthält
 * die tamper-evidente Hash-Chain-Verifikation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Admin_Audit {

	const PAGE_SLUG     = 'gfb-audit';
	const PER_PAGE      = 50;
	const NONCE_VERIFY  = 'gfb_audit_verify';

	/**
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_post' ) );
	}

	/**
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			GFB_Admin_Submissions::PAGE_SLUG,
			__( 'Audit-Log', 'gutenberg-formbuilder' ),
			__( 'Audit-Log', 'gutenberg-formbuilder' ),
			GFB_Capabilities::CAP_VIEW_AUDIT,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * POST-Handler für die Hash-Chain-Verifikation.
	 *
	 * @return void
	 */
	public static function maybe_handle_post() {
		if ( empty( $_POST['gfb_audit_action'] ) ) {
			return;
		}
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_VIEW_AUDIT ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ), 403 );
		}
		check_admin_referer( self::NONCE_VERIFY );

		$action = sanitize_key( (string) $_POST['gfb_audit_action'] );
		if ( 'verify_chain' !== $action ) {
			return;
		}

		$res = GFB_Audit::verify_chain();
		GFB_Audit::record( 'audit_chain_verified', 'audit', '', $res );

		$message = $res['ok']
			? sprintf(
				__( 'ok:%d', 'gutenberg-formbuilder' ),
				(int) $res['total']
			)
			: sprintf(
				__( 'broken:%d', 'gutenberg-formbuilder' ),
				(int) $res['broken_at']
			);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'gfb_vr'   => rawurlencode( $message ),
					'paged'     => isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return void
	 */
	public static function render_page() {
		if ( ! GFB_Capabilities::user_can( GFB_Capabilities::CAP_VIEW_AUDIT ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'gutenberg-formbuilder' ), 403 );
		}

		$current_page = max( 1, absint( isset( $_GET['paged'] ) ? $_GET['paged'] : 1 ) );
		$result       = GFB_Audit::fetch( $current_page, self::PER_PAGE );
		$rows         = $result['rows'];
		$total        = (int) $result['total'];
		$total_pages  = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Audit-Log', 'gutenberg-formbuilder' ) . '</h1>';

		// Ergebnis der Integritätsprüfung anzeigen (aus GET-Parameter nach Redirect).
		$verify_result = isset( $_GET['gfb_vr'] ) ? sanitize_text_field( wp_unslash( $_GET['gfb_vr'] ) ) : '';
		if ( '' !== $verify_result ) {
			self::render_verify_result( $verify_result );
		}

		// Abschnitt: Integrität.
		echo '<h2>' . esc_html__( 'Integrität', 'gutenberg-formbuilder' ) . '</h2>';
		echo '<p>' . esc_html__( 'Prüft, ob die tamper-evidente Hash-Chain des Audit-Logs intakt ist. Jede Manipulation an Einträgen wäre erkennbar.', 'gutenberg-formbuilder' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_VERIFY );
		echo '<input type="hidden" name="gfb_audit_action" value="verify_chain" />';
		echo '<input type="hidden" name="paged" value="' . esc_attr( (string) $current_page ) . '" />';
		submit_button( __( 'Hash-Chain prüfen', 'gutenberg-formbuilder' ), 'secondary', 'submit', false );
		echo '</form>';

		// Abschnitt: Einträge-Tabelle.
		echo '<hr/>';
		echo '<h2>'
			. esc_html__( 'Einträge', 'gutenberg-formbuilder' )
			. ' <span style="font-size:0.8em;font-weight:400;color:#50575e">('
			. esc_html( number_format_i18n( $total ) )
			. ')</span></h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Noch keine Ereignisse aufgezeichnet.', 'gutenberg-formbuilder' ) . '</p>';
		} else {
			// Pagination oben.
			self::render_pagination( $current_page, $total_pages, $total );

			echo '<table class="widefat striped" style="margin-top:0.8rem">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Zeitpunkt', 'gutenberg-formbuilder' ) . '</th>';
			echo '<th>' . esc_html__( 'Akteur', 'gutenberg-formbuilder' ) . '</th>';
			echo '<th>' . esc_html__( 'Aktion', 'gutenberg-formbuilder' ) . '</th>';
			echo '<th>' . esc_html__( 'Ziel', 'gutenberg-formbuilder' ) . '</th>';
			echo '<th>' . esc_html__( 'Details', 'gutenberg-formbuilder' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';

				// Zeitpunkt.
				$ts = isset( $row->created_at ) ? (string) $row->created_at : '';
				echo '<td style="white-space:nowrap;font-size:0.9em">' . esc_html( $ts ) . '</td>';

				// Akteur.
				$actor_user  = isset( $row->actor_user ) ? (int) $row->actor_user : 0;
				$actor_login = isset( $row->actor_login ) ? (string) $row->actor_login : '';
				$actor_ip    = isset( $row->actor_ip ) ? (string) $row->actor_ip : '';
				echo '<td>';
				if ( 0 === $actor_user ) {
					echo '<span style="color:#50575e">' . esc_html__( 'nicht angemeldet', 'gutenberg-formbuilder' ) . '</span>';
				} else {
					echo '<strong>' . esc_html( $actor_login ) . '</strong>';
				}
				if ( '' !== $actor_ip ) {
					echo '<br><small style="color:#50575e">' . esc_html( $actor_ip ) . '</small>';
				}
				echo '</td>';

				// Aktion.
				$action_raw   = isset( $row->action ) ? (string) $row->action : '';
				$action_label = self::action_label( $action_raw );
				echo '<td>';
				echo '<strong>' . esc_html( $action_label ) . '</strong>';
				echo '<br><code style="font-size:11px;color:#646970">' . esc_html( $action_raw ) . '</code>';
				echo '</td>';

				// Ziel.
				$target_type = isset( $row->target_type ) ? (string) $row->target_type : '';
				$target_id   = isset( $row->target_id ) ? (string) $row->target_id : '';
				echo '<td style="font-size:0.9em">' . esc_html( self::format_target( $target_type, $target_id ) ) . '</td>';

				// Details.
				$context_raw = isset( $row->context_json ) ? (string) $row->context_json : '';
				echo '<td style="font-size:0.85em">' . self::render_context( $context_raw ) . '</td>';

				echo '</tr>';
			}

			echo '</tbody></table>';

			// Pagination unten.
			self::render_pagination( $current_page, $total_pages, $total );
		}

		echo '</div>';
	}

	/**
	 * Zeigt das Ergebnis der Hash-Chain-Prüfung als farbigen Banner an.
	 *
	 * @param string $verify_result Kodiertes Ergebnis aus GET-Parameter.
	 * @return void
	 */
	private static function render_verify_result( $verify_result ) {
		if ( str_starts_with( $verify_result, 'ok:' ) ) {
			$total = (int) substr( $verify_result, 3 );
			echo '<div style="background:#edfaef;border-left:4px solid #1a7f37;padding:0.8rem 1rem;margin:0.6rem 0;border-radius:3px">';
			echo '<p style="margin:0"><strong>&#10003; '
				. esc_html(
					sprintf(
						/* translators: %d: Anzahl Einträge */
						__( 'Kette intakt (%d Einträge)', 'gutenberg-formbuilder' ),
						$total
					)
				)
				. '</strong></p>';
			echo '</div>';
		} elseif ( str_starts_with( $verify_result, 'broken:' ) ) {
			$at = (int) substr( $verify_result, 7 );
			echo '<div style="background:#fcebec;border-left:4px solid #a32016;padding:0.8rem 1rem;margin:0.6rem 0;border-radius:3px">';
			echo '<p style="margin:0"><strong>&#10007; '
				. esc_html(
					sprintf(
						/* translators: %d: Eintragsnummer */
						__( 'Kette gebrochen bei Eintrag #%d', 'gutenberg-formbuilder' ),
						$at
					)
				)
				. '</strong></p>';
			echo '</div>';
		}
	}

	/**
	 * Einfache Pagination mit Zurück- und Weiter-Links.
	 *
	 * @param int $current_page   Aktuelle Seite.
	 * @param int $total_pages    Gesamtseitenanzahl.
	 * @param int $total          Gesamtanzahl Einträge.
	 * @return void
	 */
	private static function render_pagination( $current_page, $total_pages, $total ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );

		echo '<div class="tablenav" style="margin:0.5rem 0">';
		echo '<div class="tablenav-pages">';
		echo '<span class="displaying-num">'
			. esc_html(
				sprintf(
					/* translators: 1: aktuell, 2: gesamt */
					__( 'Seite %1$d von %2$d', 'gutenberg-formbuilder' ),
					$current_page,
					$total_pages
				)
			)
			. '</span> ';

		if ( $current_page > 1 ) {
			echo '<a class="button" href="'
				. esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) )
				. '">' . esc_html__( '&laquo; Zurück', 'gutenberg-formbuilder' ) . '</a> ';
		}
		if ( $current_page < $total_pages ) {
			echo '<a class="button" href="'
				. esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) )
				. '">' . esc_html__( 'Weiter &raquo;', 'gutenberg-formbuilder' ) . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Formatiert target_type und target_id als lesbaren String.
	 *
	 * @param string $type Zieltyp (z. B. 'submission', 'file', 'form', 'system', 'audit', 'config').
	 * @param string $id   Ziel-ID.
	 * @return string
	 */
	private static function format_target( $type, $id ) {
		if ( '' === $id || '0' === $id ) {
			switch ( $type ) {
				case 'system': return __( 'System', 'gutenberg-formbuilder' );
				case 'audit':  return __( 'Audit-Log', 'gutenberg-formbuilder' );
				case 'config': return __( 'Konfiguration', 'gutenberg-formbuilder' );
				case 'form':   return __( 'Formulare', 'gutenberg-formbuilder' );
				default:       return $type;
			}
		}
		switch ( $type ) {
			case 'submission': return sprintf( __( 'Einsendung #%s', 'gutenberg-formbuilder' ), $id );
			case 'file':       return sprintf( __( 'Datei #%s', 'gutenberg-formbuilder' ), $id );
			case 'form':       return sprintf( __( 'Formular %s', 'gutenberg-formbuilder' ), $id );
			default:           return $type . ' ' . $id;
		}
	}

	/**
	 * Rendert context_json als kompakte Schlüssel-Wert-Liste.
	 *
	 * @param string $json Raw-JSON-String.
	 * @return string Escaped HTML.
	 */
	private static function render_context( $json ) {
		if ( '' === $json ) {
			return '<span style="color:#50575e">–</span>';
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '<span style="color:#50575e">–</span>';
		}

		$parts = array();
		foreach ( $data as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$key_str = esc_html( (string) $key );
			if ( is_array( $value ) || is_object( $value ) ) {
				$val_str = esc_html( wp_json_encode( $value ) );
			} elseif ( is_bool( $value ) ) {
				$val_str = $value ? esc_html__( 'ja', 'gutenberg-formbuilder' ) : esc_html__( 'nein', 'gutenberg-formbuilder' );
			} else {
				$val_str = esc_html( (string) $value );
			}
			$parts[] = '<span style="color:#50575e">' . $key_str . ':</span> ' . $val_str;
		}

		if ( empty( $parts ) ) {
			return '<span style="color:#50575e">–</span>';
		}

		return implode( ' &nbsp;·&nbsp; ', $parts );
	}

	/**
	 * Mapping technischer Action-Strings auf verständliche deutsche Labels.
	 *
	 * @param string $action Technischer Action-String.
	 * @return string Deutsches Label.
	 */
	private static function action_label( $action ) {
		$map = array(
			'submission_insert'              => __( 'Anfrage eingegangen', 'gutenberg-formbuilder' ),
			'submission_decrypted'           => __( 'Anfrage entschlüsselt gelesen', 'gutenberg-formbuilder' ),
			'submission_deleted'             => __( 'Anfrage gelöscht', 'gutenberg-formbuilder' ),
			'submission_exported'            => __( 'Einsendungen exportiert', 'gutenberg-formbuilder' ),
			'submission_exported_decrypted'  => __( 'Export mit Entschlüsselung', 'gutenberg-formbuilder' ),
			'submission_export_denied'       => __( 'Export abgewiesen', 'gutenberg-formbuilder' ),
			'file_encrypted'                 => __( 'Datei verschlüsselt gespeichert', 'gutenberg-formbuilder' ),
			'file_download_ok'               => __( 'Datei heruntergeladen', 'gutenberg-formbuilder' ),
			'file_download_denied'           => __( 'Datei-Download abgewiesen', 'gutenberg-formbuilder' ),
			'file_download_failed'           => __( 'Datei-Download fehlgeschlagen', 'gutenberg-formbuilder' ),
			'file_deleted'                   => __( 'Datei gelöscht', 'gutenberg-formbuilder' ),
			'file_exported'                  => __( 'Datei exportiert', 'gutenberg-formbuilder' ),
			'file_av_infected'               => __( 'Datei als infiziert erkannt (ClamAV)', 'gutenberg-formbuilder' ),
			'settings_caps_saved'            => __( 'Berechtigungen geändert', 'gutenberg-formbuilder' ),
			'settings_clamav_saved'          => __( 'ClamAV-Einstellungen geändert', 'gutenberg-formbuilder' ),
			'settings_webkit_datetime_saved' => __( 'Formular-Einstellungen geändert', 'gutenberg-formbuilder' ),
			'audit_chain_verified'           => __( 'Audit-Integrität geprüft', 'gutenberg-formbuilder' ),
			'clamav_eicar_test'              => __( 'ClamAV-Selbsttest', 'gutenberg-formbuilder' ),
			'storage_reach_test'             => __( 'Speicher-Erreichbarkeitstest', 'gutenberg-formbuilder' ),
			'rewrap_batch'                   => __( 'Key-Rotation durchgeführt', 'gutenberg-formbuilder' ),
			'plugin_activated'               => __( 'Plugin aktiviert', 'gutenberg-formbuilder' ),
		);

		return isset( $map[ $action ] ) ? $map[ $action ] : $action;
	}
}
