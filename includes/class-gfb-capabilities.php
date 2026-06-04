<?php
/**
 * Capability-Modell.
 *
 * Statt allen Plugin-Aktionen `manage_options` zu verlangen, vergibt das Plugin
 * eigene Capabilities. So können Site-Admins z. B. einen "Datenschutz-Beauftragten"
 * mit Lese-Zugriff auf Submissions ausstatten, ohne ihm WP-Settings-Rechte zu geben.
 *
 * Default-Mapping bei Aktivierung: alle Caps -> Rolle `administrator`.
 *
 * Die Caps sind:
 *   gfb_view_submissions   - Liste/Detail von Submissions sehen (verschlüsselt = maskiert)
 *   gfb_decrypt_submissions- Verschlüsselte Felder im Klartext sehen (separat zu view!)
 *   gfb_delete_submissions - Submissions löschen
 *   gfb_download_files     - Verschlüsselte Datei-Anhänge herunterladen (entschlüsselter Stream)
 *   gfb_view_audit         - Audit-Log lesen
 *   gfb_manage_settings    - Plugin-Einstellungen ändern (ClamAV, Caps-Zuweisung, Key-Rotation)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFB_Capabilities {

	const CAP_VIEW_SUBMISSIONS    = 'gfb_view_submissions';
	const CAP_DECRYPT_SUBMISSIONS = 'gfb_decrypt_submissions';
	const CAP_DELETE_SUBMISSIONS  = 'gfb_delete_submissions';
	const CAP_DOWNLOAD_FILES      = 'gfb_download_files';
	const CAP_VIEW_AUDIT          = 'gfb_view_audit';
	const CAP_MANAGE_SETTINGS     = 'gfb_manage_settings';

	/**
	 * @return array<int,string>
	 */
	public static function all_caps() {
		return array(
			self::CAP_VIEW_SUBMISSIONS,
			self::CAP_DECRYPT_SUBMISSIONS,
			self::CAP_DELETE_SUBMISSIONS,
			self::CAP_DOWNLOAD_FILES,
			self::CAP_VIEW_AUDIT,
			self::CAP_MANAGE_SETTINGS,
		);
	}

	/**
	 * Verständliche Beschriftung pro Berechtigung: Kurztitel und Beschreibung,
	 * damit im Admin klar ist, wer was tun darf (statt nur des technischen Namens).
	 *
	 * @param string $cap Capability-Slug.
	 * @return array{title:string,description:string}
	 */
	public static function cap_meta( $cap ) {
		$meta = array(
			self::CAP_VIEW_SUBMISSIONS => array(
				'title'       => __( 'Anfragen sehen', 'gutenberg-formbuilder' ),
				'description' => __( 'Anfragen in der Liste sehen (verschlüsselte Felder maskiert).', 'gutenberg-formbuilder' ),
			),
			self::CAP_DECRYPT_SUBMISSIONS => array(
				'title'       => __( 'Entschlüsseln', 'gutenberg-formbuilder' ),
				'description' => __( 'Vertrauliche Felder im Detail entschlüsselt lesen (Eintrag im Audit-Log).', 'gutenberg-formbuilder' ),
			),
			self::CAP_DELETE_SUBMISSIONS => array(
				'title'       => __( 'Löschen', 'gutenberg-formbuilder' ),
				'description' => __( 'Anfragen löschen (Eintrag im Audit-Log).', 'gutenberg-formbuilder' ),
			),
			self::CAP_DOWNLOAD_FILES => array(
				'title'       => __( 'Dateien herunterladen', 'gutenberg-formbuilder' ),
				'description' => __( 'Datei-Anhänge herunterladen (Eintrag im Audit-Log).', 'gutenberg-formbuilder' ),
			),
			self::CAP_VIEW_AUDIT => array(
				'title'       => __( 'Audit-Log', 'gutenberg-formbuilder' ),
				'description' => __( 'Audit-Log einsehen und Integrität prüfen.', 'gutenberg-formbuilder' ),
			),
			self::CAP_MANAGE_SETTINGS => array(
				'title'       => __( 'Einstellungen', 'gutenberg-formbuilder' ),
				'description' => __( 'Plugin-Einstellungen, Verschlüsselung, ClamAV und Berechtigungen ändern.', 'gutenberg-formbuilder' ),
			),
		);

		if ( isset( $meta[ $cap ] ) ) {
			return $meta[ $cap ];
		}
		return array( 'title' => $cap, 'description' => '' );
	}

	/**
	 * Bei Plugin-Aktivierung initial alle Caps an `administrator` haengen.
	 *
	 * @return void
	 */
	public static function bootstrap_defaults() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all_caps() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Bei Deaktivierung NICHT entfernen — sonst sind nach Re-Activate alle
	 * manuellen Zuweisungen weg. Bewusst nur in einer expliziten Cleanup-Routine
	 * (siehe README) anbieten.
	 *
	 * @return void
	 */
	public static function remove_all_caps() {
		global $wp_roles;
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return;
		}
		foreach ( $wp_roles->roles as $role_name => $_ ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Komfort-Check.
	 *
	 * @param string $cap        Eine der CAP_* Konstanten.
	 * @param int    $user_id    Optional. Standard: aktueller User.
	 * @return bool
	 */
	public static function user_can( $cap, $user_id = 0 ) {
		// Super-Admins und 'manage_options' duerfen alles (Bequemlichkeit).
		if ( $user_id > 0 ) {
			return user_can( $user_id, $cap ) || user_can( $user_id, 'manage_options' );
		}
		return current_user_can( $cap ) || current_user_can( 'manage_options' );
	}

	/**
	 * Liste aller Rollen mit der angegebenen Cap, für Anzeige in Settings.
	 *
	 * @param string $cap Cap.
	 * @return array<int,string> Rollen-Slugs.
	 */
	public static function roles_with_cap( $cap ) {
		global $wp_roles;
		$out = array();
		if ( ! ( $wp_roles instanceof WP_Roles ) ) {
			return $out;
		}
		foreach ( $wp_roles->roles as $slug => $data ) {
			if ( ! empty( $data['capabilities'][ $cap ] ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Setzt für eine Rolle eine Cap an/aus.
	 *
	 * @param string $role_slug Rollen-Slug.
	 * @param string $cap       Cap.
	 * @param bool   $enabled   true = add_cap, false = remove_cap.
	 * @return bool true wenn Änderung erfolgreich.
	 */
	public static function set_role_cap( $role_slug, $cap, $enabled ) {
		if ( ! in_array( $cap, self::all_caps(), true ) ) {
			return false;
		}
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}
		if ( $enabled ) {
			$role->add_cap( $cap );
		} else {
			$role->remove_cap( $cap );
		}
		return true;
	}
}
