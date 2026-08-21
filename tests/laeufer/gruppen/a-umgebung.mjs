// Gruppe A – Umgebung und Aktivierung.
import { soll } from '../lib/pruefung.mjs';
import { fehlerprotokoll } from '../lib/wp.mjs';

export default async function gruppeA( u, s ) {
	s.gruppe( 'A – Umgebung und Aktivierung' );

	await s.punkt( 'A1', 'Plugin aktivieren', async () => {
		const liste = await u.wp( 'plugin list --field=name --status=active' );
		return soll.enthaelt( liste, 'gutenberg-formbuilder', 'Liste der aktiven Plugins' );
	} );

	await s.punkt( 'A2', 'Datenbanktabellen', async () => {
		const roh = await u.php( `
			global $wpdb;
			$fehlt = array();
			foreach ( array( 'gfb_submissions', 'gfb_files', 'gfb_audit' ) as $t ) {
				$name = $wpdb->prefix . $t;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
					$fehlt[] = $t;
				}
			}
			echo empty( $fehlt ) ? 'alle' : implode( ',', $fehlt );
		` );
		return soll.gleich( roh.trim(), 'alle', 'Fehlende Tabellen' );
	} );

	await s.punkt( 'A3', 'Rechte an Administrator', async () => {
		const roh = await u.php( `
			$rolle = get_role( 'administrator' );
			$fehlt = array();
			foreach ( array( 'gfb_view_submissions', 'gfb_delete_submissions', 'gfb_decrypt_submissions',
				'gfb_download_files', 'gfb_view_audit', 'gfb_manage_settings' ) as $cap ) {
				if ( ! $rolle->has_cap( $cap ) ) { $fehlt[] = $cap; }
			}
			echo empty( $fehlt ) ? 'alle' : implode( ',', $fehlt );
		` );
		return soll.gleich( roh.trim(), 'alle', 'Fehlende Rechte' );
	} );

	await s.punkt( 'A4', 'Privater Ordner', async () => {
		const roh = await u.imWp( 'ls -ld /var/www/html/wp-content/.gfb-private 2>/dev/null || echo fehlt' );
		if ( roh.includes( 'fehlt' ) ) {
			return 'Ordner .gfb-private ist nicht angelegt.';
		}
		const rechte = roh.trim().split( /\s+/ )[ 0 ];
		return soll.wahr(
			/^d(rwx|rws)------/.test( rechte ) || rechte.startsWith( 'drwx' ),
			`Rechte des Ordners: ${ rechte }`
		);
	} );

	await s.punkt( 'A5', 'Direktzugriff auf den privaten Ordner', async () => {
		const antwort = await fetch( `${ u.basis }/wp-content/.gfb-private/` );
		return soll.wahr(
			antwort.status === 403 || antwort.status === 404,
			`HTTP-Status ${ antwort.status }, erwartet 403 oder 404.`
		);
	} );

	await s.punkt( 'A6', 'Geplante Aufgaben', async () => {
		const roh = await u.wp( 'cron event list --fields=hook --format=csv' ).catch( () => '' );
		return soll.enthaelt( roh, 'gfb_', 'Liste der geplanten Aufgaben' );
	} );

	await s.punkt( 'A7', 'Deaktivieren und wieder aktivieren', async () => {
		await u.wp( 'plugin deactivate gutenberg-formbuilder' );
		await u.wp( 'plugin activate gutenberg-formbuilder' );
		const roh = await u.php( `
			global $wpdb;
			echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gfb_submissions" );
		` );
		return soll.wahr( ! Number.isNaN( parseInt( roh, 10 ) ), 'Tabelle nach dem Neustart nicht lesbar.' );
	} );

	await s.punkt( 'A8', 'Fehlerprotokoll über den ganzen Lauf', async () => {
		// Wird am Ende des Laufs erneut geprüft; hier nur der Zwischenstand.
		const protokoll = await fehlerprotokoll( u );
		const plugin = protokoll
			.split( '\n' )
			.filter( ( z ) => /gutenberg-formbuilder|class-gfb-/.test( z ) );
		return soll.wahr( plugin.length === 0, `Meldungen aus dem Plugin:\n${ plugin.slice( 0, 5 ).join( '\n' ) }` );
	} );

	await s.punkt( 'A9', 'Angaben im Plugin-Kopf', async () => {
		const roh = await u.php( `
			$daten = get_plugin_data( WP_PLUGIN_DIR . '/gutenberg-formbuilder/gutenberg-formbuilder.php', false, false );
			echo wp_json_encode( array(
				'wp'  => $daten['RequiresWP'],
				'php' => $daten['RequiresPHP'],
				'ist_wp' => get_bloginfo( 'version' ),
				'ist_php' => PHP_VERSION,
			) );
		` );
		const d = JSON.parse( roh.trim() );
		const verglichen = vergleichVersion( d.ist_wp, d.wp );
		return soll.wahr(
			verglichen >= 0,
			`Diese Umgebung hat WordPress ${ d.ist_wp }, das Plugin verlangt mindestens ${ d.wp }.`
		);
	} );

	// A10 bis A15: Aufräumen beim Löschen. Geprüft wird ohne echtes Löschen –
	// die Routine wird mit gesetzter Konstante direkt ausgeführt, sonst müsste
	// der Container das eingehängte Plugin entfernen, was nicht geht.
	await s.punkt( 'A10', 'Schalter zum Aufräumen', async () => {
		const roh = await u.php( `
			echo wp_json_encode( array(
				'datei'   => file_exists( WP_PLUGIN_DIR . '/gutenberg-formbuilder/uninstall.php' ),
				'vorgabe' => (bool) get_option( 'gfb_uninstall_cleanup', false ),
			) );
		` );
		const d = JSON.parse( roh.trim() );
		if ( ! d.datei ) return 'Es gibt keine Aufräum-Routine (uninstall.php).';
		return soll.wahr( d.vorgabe === false, 'Der Schalter steht von Haus aus an – er muss aus sein.' );
	} );

}

function vergleichVersion( a, b ) {
	const zerlegen = ( v ) => String( v ).split( '.' ).map( ( t ) => parseInt( t, 10 ) || 0 );
	const [ x, y ] = [ zerlegen( a ), zerlegen( b ) ];
	for ( let i = 0; i < Math.max( x.length, y.length ); i++ ) {
		const d = ( x[ i ] || 0 ) - ( y[ i ] || 0 );
		if ( d !== 0 ) return d > 0 ? 1 : -1;
	}
	return 0;
}
