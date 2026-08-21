<?php
/**
 * Aufräumen beim Löschen des Plugins.
 *
 * Zwei Wege, gesteuert über die Einstellung «Beim Löschen alle Daten entfernen»
 * (Option gfb_uninstall_cleanup, von Haus aus aus):
 *
 *   aus  – nur die Einstellungen des Plugins verschwinden. Einsendungen,
 *          hochgeladene Dateien und das Prüfprotokoll bleiben unangetastet.
 *   an   – alles geht weg: Tabellen, Einstellungen, verschlüsselte Dateien,
 *          eigene Rechte und geplante Aufgaben.
 *
 * Ohne diesen Schutz würde ein versehentliches Löschen des Plugins die
 * Formulardaten einer Kundenseite mitnehmen.
 *
 * @package Gutenberg_Formbuilder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Räumt eine einzelne Website auf.
 *
 * @param bool $alles true = auch Daten entfernen.
 * @return void
 */
function gfb_uninstall_website( $alles ) {
	global $wpdb;

	// Geplante Aufgaben abmelden.
	foreach ( array( 'gfb_rewrap_cron', 'gfb_receipt_retention_cron' ) as $haken ) {
		$naechster = wp_next_scheduled( $haken );
		while ( $naechster ) {
			wp_unschedule_event( $naechster, $haken );
			$naechster = wp_next_scheduled( $haken );
		}
	}

	if ( $alles ) {
		// Verschlüsselte Dateien und ihr Verzeichnis.
		gfb_uninstall_verzeichnis_loeschen( WP_CONTENT_DIR . '/.gfb-private' );

		// Tabellen.
		foreach ( array( 'gfb_submissions', 'gfb_files', 'gfb_audit' ) as $tabelle ) {
			$name = $wpdb->prefix . $tabelle;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Tabellenname aus fester Liste.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}

		// Eigene Rechte aus allen Rollen.
		$rechte = array(
			'gfb_view_submissions',
			'gfb_decrypt_submissions',
			'gfb_delete_submissions',
			'gfb_download_files',
			'gfb_view_audit',
			'gfb_manage_settings',
		);
		$rollen = wp_roles();
		if ( $rollen instanceof WP_Roles ) {
			foreach ( array_keys( $rollen->roles ) as $rolle ) {
				$objekt = get_role( $rolle );
				if ( ! $objekt ) {
					continue;
				}
				foreach ( $rechte as $recht ) {
					$objekt->remove_cap( $recht );
				}
			}
		}
	}

	// Einstellungen, Zwischenspeicher und Zähler – in beiden Fällen.
	$namen = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		 WHERE option_name LIKE 'gfb\\_%'
		    OR option_name LIKE '\\_transient\\_gfb\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_gfb\\_%'"
	);
	foreach ( (array) $namen as $option ) {
		delete_option( $option );
	}
}

/**
 * Löscht ein Verzeichnis samt Inhalt.
 *
 * @param string $pfad Absoluter Pfad.
 * @return void
 */
function gfb_uninstall_verzeichnis_loeschen( $pfad ) {
	if ( ! is_dir( $pfad ) ) {
		return;
	}
	$eintraege = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $pfad, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $eintraege as $eintrag ) {
		if ( $eintrag->isDir() ) {
			@rmdir( $eintrag->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			@unlink( $eintrag->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir( $pfad ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

if ( is_multisite() ) {
	$websites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
	foreach ( $websites as $website_id ) {
		switch_to_blog( (int) $website_id );
		gfb_uninstall_website( (bool) get_option( 'gfb_uninstall_cleanup', false ) );
		restore_current_blog();
	}
} else {
	gfb_uninstall_website( (bool) get_option( 'gfb_uninstall_cleanup', false ) );
}
