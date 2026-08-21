// Gruppe A, zweiter Teil – Aufräumen beim Löschen des Plugins.
//
// Läuft als letzte Gruppe: die Prüfung entfernt Tabellen und Dateien wirklich.
// Anschliessend stellt A15 den Ausgangszustand wieder her.
import { soll } from '../lib/pruefung.mjs';

export default async function gruppeZ( u, s ) {
	s.gruppe( 'A – Aufräumen beim Löschen' );

	// WordPress deaktiviert ein Plugin, bevor es die Aufräum-Routine ruft.
	// Bleibt es aktiv, legt es Tabelle und Einstellungen sofort wieder an.
	await u.wp( 'plugin deactivate gutenberg-formbuilder' );

	await s.punkt( 'A11', 'Plugin löschen, Schalter aus', async () => {
		const roh = await u.php( `
			global $wpdb;
			update_option( 'gfb_uninstall_cleanup', '', false );
			$vorher = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gfb_submissions" );
			define( 'WP_UNINSTALL_PLUGIN', 'gutenberg-formbuilder/gutenberg-formbuilder.php' );
			include WP_PLUGIN_DIR . '/gutenberg-formbuilder/uninstall.php';
			$tabelle = $wpdb->prefix . 'gfb_submissions';
			$da      = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tabelle ) ) === $tabelle );
			echo wp_json_encode( array(
				'tabelle_da' => $da,
				'eintraege'  => $da ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabelle}" ) : 0,
				'vorher'     => $vorher,
				'texte_weg'  => ( false === get_option( 'gfb_texts', false ) ),
			) );
		` );
		const d = JSON.parse( roh.trim() );
		if ( ! d.tabelle_da ) return 'Die Tabelle wurde entfernt, obwohl der Schalter aus war.';
		if ( d.eintraege !== d.vorher ) return `Einträge vorher ${ d.vorher }, danach ${ d.eintraege }.`;
		return soll.wahr( d.texte_weg, 'Die Einstellungen blieben stehen, obwohl sie weg sollten.' );
	} );

	await s.punkt( 'A13', 'Schalter an: Dateien', async () => {
		const roh = await u.php( `
			update_option( 'gfb_uninstall_cleanup', '1', false );
			$ordner = WP_CONTENT_DIR . '/.gfb-private';
			if ( ! is_dir( $ordner ) ) { wp_mkdir_p( $ordner . '/2026/08' ); }
			file_put_contents( $ordner . '/2026/08/pruefdatei.bin', 'inhalt' );
			define( 'WP_UNINSTALL_PLUGIN', 'gutenberg-formbuilder/gutenberg-formbuilder.php' );
			include WP_PLUGIN_DIR . '/gutenberg-formbuilder/uninstall.php';
			echo wp_json_encode( array( 'ordner_weg' => ! is_dir( $ordner ) ) );
		` );
		const d = JSON.parse( roh.trim() );
		u.aufgeraeumt = true;
		return soll.wahr( d.ordner_weg, 'Der private Ordner blieb stehen.' );
	} );

	await s.punkt( 'A12', 'Schalter an: Tabellen', async () => {
		if ( ! u.aufgeraeumt ) throw s.uebersprungen( 'A13 lief nicht.' );
		const roh = await u.php( `
			global $wpdb;
			$offen = array();
			foreach ( array( 'gfb_submissions', 'gfb_files', 'gfb_audit' ) as $t ) {
				$name = $wpdb->prefix . $t;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
					$offen[] = $t;
				}
			}
			echo empty( $offen ) ? 'alle_weg' : implode( ',', $offen );
		` );
		return soll.gleich( roh.trim(), 'alle_weg', 'Verbliebene Tabellen' );
	} );

	await s.punkt( 'A14', 'Schalter an: Reste', async () => {
		if ( ! u.aufgeraeumt ) throw s.uebersprungen( 'A13 lief nicht.' );
		const roh = await u.php( `
			global $wpdb;
			$optionen = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'gfb\\_%'"
			);
			$rolle  = get_role( 'administrator' );
			$rechte = $rolle && $rolle->has_cap( 'gfb_view_submissions' ) ? 'da' : 'weg';
			$cron   = wp_next_scheduled( 'gfb_rewrap_cron' ) ? 'da' : 'weg';
			echo wp_json_encode( array( 'optionen' => $optionen, 'rechte' => $rechte, 'cron' => $cron ) );
		` );
		const d = JSON.parse( roh.trim() );
		const offen = [];
		if ( d.optionen > 0 ) offen.push( `${ d.optionen } Einstellungen` );
		if ( d.rechte === 'da' ) offen.push( 'Rechte' );
		if ( d.cron === 'da' ) offen.push( 'geplante Aufgabe' );
		return soll.wahr( offen.length === 0, `Übrig geblieben: ${ offen.join( ', ' ) }` );
	} );

	await s.punkt( 'A15', 'Löschen und neu installieren', async () => {
		if ( ! u.aufgeraeumt ) throw s.uebersprungen( 'A13 lief nicht.' );
		// Frischinstallation: das Aktivieren baut Tabellen und Rechte neu auf.
		await u.wp( 'plugin activate gutenberg-formbuilder' );
		const roh = await u.php( `
			global $wpdb;
			$fehlt = array();
			foreach ( array( 'gfb_submissions', 'gfb_files', 'gfb_audit' ) as $t ) {
				$name = $wpdb->prefix . $t;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
					$fehlt[] = $t;
				}
			}
			$rolle = get_role( 'administrator' );
			echo wp_json_encode( array(
				'fehlt'  => $fehlt,
				'rechte' => $rolle && $rolle->has_cap( 'gfb_view_submissions' ),
			) );
		` );
		const d = JSON.parse( roh.trim() );
		if ( d.fehlt.length ) return `Nach der Neuinstallation fehlen: ${ d.fehlt.join( ', ' ) }`;
		return soll.wahr( d.rechte, 'Die Rechte wurden nicht neu vergeben.' );
	} );
}
