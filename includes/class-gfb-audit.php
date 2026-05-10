<?php
/**
 * Tamper-evident Audit-Log.
 *
 * Eigene Tabelle wp_gfb_audit. Jede Zeile referenziert den Hash der
 * vorhergehenden Zeile (wie eine kleine Mini-Blockchain), sodass nachträgliches
 * Löschen oder Ändern einer Zeile beim Verifizieren auffliegt.
 *
 * Spalten:
 *   id           BIGINT PK
 *   created_at   DATETIME
 *   actor_user   BIGINT (0 = anonym/system)
 *   actor_login  VARCHAR(60)
 *   actor_ip     VARCHAR(45)
 *   action       VARCHAR(64)   ('submission_insert', 'file_download', 'login_fail', ...)
 *   target_type  VARCHAR(32)   ('submission'|'file'|'system'|'user'|'config'|...)
 *   target_id    VARCHAR(64)
 *   context_json LONGTEXT      (JSON, sanitized)
 *   prev_hash    CHAR(64)      (sha256 hex der vorherigen Zeile, '' für erste)
 *   row_hash     CHAR(64)      (sha256 hex von prev_hash || canonical(this_row))
 *
 * Hash-Berechnung:
 *   canonical = id|created_at|actor_user|actor_login|actor_ip|action|target_type|target_id|context_json
 *   row_hash  = sha256( prev_hash . '|' . canonical )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Audit {

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'gfb_audit';
	}

	/**
	 * Tabelle anlegen / aktualisieren (idempotent via dbDelta).
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			actor_user BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_login VARCHAR(60) NOT NULL DEFAULT '',
			actor_ip VARCHAR(45) NOT NULL DEFAULT '',
			action VARCHAR(64) NOT NULL,
			target_type VARCHAR(32) NOT NULL DEFAULT '',
			target_id VARCHAR(64) NOT NULL DEFAULT '',
			context_json LONGTEXT NULL,
			prev_hash CHAR(64) NOT NULL DEFAULT '',
			row_hash CHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY action_idx (action),
			KEY target_idx (target_type, target_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Schreibt einen Audit-Eintrag (best effort: Crypto-/Hashfehler werden geloggt
	 * via gfb_security_event, aber lassen den Caller nicht crashen).
	 *
	 * @param string              $action      Action-Slug.
	 * @param string              $target_type Target-Typ.
	 * @param string              $target_id   Target-ID.
	 * @param array<string,mixed> $context     Kontext (wird sanitisiert).
	 * @return int Inserted-ID oder 0.
	 */
	public static function record( $action, $target_type = 'system', $target_id = '', array $context = array() ) {
		global $wpdb;
		$table = self::table_name();

		$user      = is_user_logged_in() ? wp_get_current_user() : null;
		$actor_id  = $user ? (int) $user->ID : 0;
		$actor_log = $user ? mb_substr( (string) $user->user_login, 0, 60 ) : '';
		$actor_ip  = class_exists( 'GFB_Security' ) ? GFB_Security::get_client_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '' );

		$action      = mb_substr( sanitize_key( (string) $action ), 0, 64 );
		$target_type = mb_substr( sanitize_key( (string) $target_type ), 0, 32 );
		$target_id   = mb_substr( sanitize_text_field( (string) $target_id ), 0, 64 );

		$context_clean = self::sanitize_context( $context );
		$context_json  = wp_json_encode( $context_clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $context_json ) {
			$context_json = '{}';
		}

		// Vorgänger-Hash.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev_hash = (string) $wpdb->get_var( "SELECT row_hash FROM {$table} ORDER BY id DESC LIMIT 1" );

		$created_at = current_time( 'mysql' );

		$canonical = implode(
			'|',
			array(
				$created_at,
				(string) $actor_id,
				$actor_log,
				(string) $actor_ip,
				$action,
				$target_type,
				$target_id,
				$context_json,
			)
		);
		$row_hash = hash( 'sha256', $prev_hash . '|' . $canonical );

		$ok = $wpdb->insert(
			$table,
			array(
				'created_at'   => $created_at,
				'actor_user'   => $actor_id,
				'actor_login'  => $actor_log,
				'actor_ip'     => (string) $actor_ip,
				'action'       => $action,
				'target_type'  => $target_type,
				'target_id'    => $target_id,
				'context_json' => $context_json,
				'prev_hash'    => $prev_hash,
				'row_hash'     => $row_hash,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Verifiziert die komplette Hash-Chain und gibt den ersten Bruch zurück.
	 *
	 * @return array{ok:bool,broken_at:?int,total:int}
	 */
	public static function verify_chain() {
		global $wpdb;
		$table = self::table_name();

		// Über Cursor-iteration in Chunks für grosse Tabellen.
		$last_id   = 0;
		$prev_hash = '';
		$total     = 0;
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, created_at, actor_user, actor_login, actor_ip, action, target_type, target_id, context_json, prev_hash, row_hash FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 500",
					$last_id
				)
			);
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				++$total;
				if ( $prev_hash !== (string) $row->prev_hash ) {
					return array(
						'ok'        => false,
						'broken_at' => (int) $row->id,
						'total'     => $total,
					);
				}
				$canonical = implode(
					'|',
					array(
						(string) $row->created_at,
						(string) $row->actor_user,
						(string) $row->actor_login,
						(string) $row->actor_ip,
						(string) $row->action,
						(string) $row->target_type,
						(string) $row->target_id,
						(string) $row->context_json,
					)
				);
				$expected = hash( 'sha256', $prev_hash . '|' . $canonical );
				if ( ! hash_equals( $expected, (string) $row->row_hash ) ) {
					return array(
						'ok'        => false,
						'broken_at' => (int) $row->id,
						'total'     => $total,
					);
				}
				$prev_hash = (string) $row->row_hash;
				$last_id   = (int) $row->id;
			}
		}
		return array(
			'ok'        => true,
			'broken_at' => null,
			'total'     => $total,
		);
	}

	/**
	 * Returns paginated rows for the admin UI.
	 *
	 * @param int $page    1-based.
	 * @param int $per_page Anzahl pro Seite.
	 * @return array{rows:array,total:int}
	 */
	public static function fetch( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table = self::table_name();
		$page  = max( 1, (int) $page );
		$pp    = max( 1, min( 200, (int) $per_page ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, created_at, actor_user, actor_login, actor_ip, action, target_type, target_id, context_json FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$pp,
				( $page - 1 ) * $pp
			)
		);
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function sanitize_context( array $context ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( '' === $k ) {
				continue;
			}
			if ( is_scalar( $value ) || is_null( $value ) ) {
				$out[ $k ] = is_string( $value ) ? mb_substr( wp_strip_all_tags( $value ), 0, 500 ) : $value;
			} elseif ( is_array( $value ) ) {
				$out[ $k ] = self::sanitize_context( $value );
			} else {
				$out[ $k ] = '[object]';
			}
		}
		return $out;
	}
}
