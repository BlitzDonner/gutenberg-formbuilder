// Gruppe J – Rechte. Sechs eigene Berechtigungen, je mit und ohne.
import { soll } from '../lib/pruefung.mjs';

const RECHTE = [
	[ 'J1', 'Einsendungen sehen', 'gfb_view_submissions', '/wp-admin/admin.php?page=gfb-submissions' ],
	[ 'J5', 'Prüfprotokoll sehen', 'gfb_view_audit', '/wp-admin/admin.php?page=gfb-audit' ],
	[ 'J6', 'Einstellungen verwalten', 'gfb_manage_settings', '/wp-admin/admin.php?page=gfb-settings' ],
];

export default async function gruppeJ( u, s, b ) {
	s.gruppe( 'J – Rechte' );

	// Der Redakteur bekommt für diese Prüfung genau ein Recht: sehen.
	await u.php( `
		$person = get_user_by( 'login', 'redakteur' );
		$person->add_cap( 'gfb_view_submissions' );
		echo 'ok';
	` );

	const alsRedakteur = await b.alsPerson( 'redakteur', 'geheim' );
	const alsLeser = await b.alsPerson( 'leser', 'geheim' );

	async function seiteAls( kontext, pfad ) {
		const seite = await kontext.newPage();
		await seite.goto( `${ u.basis }${ pfad }`, { waitUntil: 'domcontentloaded' } );
		const text = await seite.locator( 'body' ).innerText();
		await seite.close();
		return text;
	}

	for ( const [ nr, titel, recht, pfad ] of RECHTE ) {
		await s.punkt( nr, `${ titel }: ohne Recht gesperrt`, async () => {
			const text = await seiteAls( alsLeser, pfad );
			return soll.wahr(
				/keine ausreichenden Rechte|nicht berechtigt|Sie haben keine|Sorry, you are not/i.test( text ),
				`Die Seite war ohne das Recht ${ recht } zugänglich.`
			);
		} );
	}

	await s.punkt( 'J1b', 'Einsendungen sehen: mit Recht offen', async () => {
		const text = await seiteAls( alsRedakteur, '/wp-admin/admin.php?page=gfb-submissions' );
		return soll.wahr(
			! /keine ausreichenden Rechte|Sorry, you are not/i.test( text ),
			'Mit dem Recht bleibt die Liste gesperrt.'
		);
	} );

	await s.punkt( 'J3', 'Vertrauliches entschlüsseln', async () => {
		const roh = await u.php( `
			$person = get_user_by( 'login', 'redakteur' );
			wp_set_current_user( $person->ID );
			echo current_user_can( 'gfb_decrypt_submissions' ) ? 'darf' : 'darf_nicht';
		` );
		return soll.gleich( roh.trim(), 'darf_nicht', 'Recht des Redakteurs' );
	} );

	await s.punkt( 'J4', 'Dateien herunterladen: ohne Recht abgelehnt', async () => {
		const dateiId = await u.php( `
			global $wpdb;
			echo (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}gfb_files ORDER BY id DESC LIMIT 1" );
		` );
		if ( ! parseInt( dateiId, 10 ) ) throw s.uebersprungen( 'Keine abgelegte Datei vorhanden.' );
		const seite = await alsRedakteur.newPage();
		const antwort = await seite.goto(
			`${ u.basis }/wp-admin/admin-post.php?action=gfb_download&file=${ dateiId.trim() }`,
			{ waitUntil: 'domcontentloaded' }
		);
		const text = await seite.locator( 'body' ).innerText().catch( () => '' );
		await seite.close();
		const abgelehnt = antwort.status() >= 400 || /keine|nicht berechtigt|Sorry/i.test( text );
		return soll.wahr( abgelehnt, `Antwort ${ antwort.status() }: ${ text.slice( 0, 120 ) }` );
	} );

	await s.punkt( 'J7', 'Redakteur ohne Rechte sieht kein Menü', async () => {
		const seite = await alsLeser.newPage();
		await seite.goto( `${ u.basis }/wp-admin/profile.php`, { waitUntil: 'domcontentloaded' } );
		const eintraege = await seite.evaluate( () =>
			Array.from( document.querySelectorAll( '#adminmenu a' ) )
				.map( ( a ) => a.getAttribute( 'href' ) || '' )
				.filter( ( h ) => h.includes( 'page=gfb-' ) )
		);
		await seite.close();
		return soll.wahr( eintraege.length === 0, `Sichtbare Menüpunkte: ${ eintraege.join( ', ' ) }` );
	} );

	await s.punkt( 'J9', 'Export ohne Recht', async () => {
		const seite = await alsLeser.newPage();
		const antwort = await seite.goto(
			`${ u.basis }/wp-admin/admin-post.php?action=gfb_export&form=gfbt_voll`,
			{ waitUntil: 'domcontentloaded' }
		);
		const text = await seite.locator( 'body' ).innerText().catch( () => '' );
		await seite.close();
		const abgelehnt = antwort.status() >= 400 || /keine|nicht berechtigt|Sorry/i.test( text );
		return soll.wahr( abgelehnt, `Antwort ${ antwort.status() }: ${ text.slice( 0, 120 ) }` );
	} );

	await s.punkt( 'J8', 'Rechte zuweisen', async () => {
		const vorher = await u.php( `
			$p = get_user_by( 'login', 'leser' );
			echo user_can( $p, 'gfb_view_submissions' ) ? 'ja' : 'nein';
		` );
		await u.php( `
			$p = get_user_by( 'login', 'leser' );
			$p->add_cap( 'gfb_view_submissions' );
			echo 'ok';
		` );
		const nachher = await u.php( `
			$p = get_user_by( 'login', 'leser' );
			echo user_can( $p, 'gfb_view_submissions' ) ? 'ja' : 'nein';
		` );
		await u.php( `
			$p = get_user_by( 'login', 'leser' );
			$p->remove_cap( 'gfb_view_submissions' );
			echo 'ok';
		` );
		return soll.wahr(
			vorher.trim() === 'nein' && nachher.trim() === 'ja',
			`Vorher ${ vorher.trim() }, nachher ${ nachher.trim() }.`
		);
	} );

	await alsRedakteur.close();
	await alsLeser.close();
}
