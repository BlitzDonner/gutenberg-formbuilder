// Gruppe I – Backend: Einträge-Liste, Ausgabewege, Einstellungsseiten.
import { soll } from '../lib/pruefung.mjs';

const LISTE = '/wp-admin/admin.php?page=gfb-submissions';

export default async function gruppeI( u, s, b ) {
	s.gruppe( 'I – Backend' );

	await s.punkt( 'I1', 'Einträge-Liste', async () => {
		const { seite, meldungen } = await b.oeffnen( LISTE );
		u.listeSeite = seite;
		const zeilen = await seite.locator( 'table tbody tr' ).count();
		const fehler = meldungen.filter( ( m ) => /gfb|formbuilder/i.test( m ) );
		if ( fehler.length ) return `Konsolenmeldung: ${ fehler[ 0 ] }`;
		return soll.wahr( zeilen > 0, 'Die Liste zeigt keine Einträge.' );
	} );

	await s.punkt( 'I2', 'Sortierung', async () => {
		const seite = u.listeSeite;
		if ( ! seite ) throw s.uebersprungen( 'I1 lieferte keine Seite.' );
		const sortierbar = await seite.locator( 'th a[href*="orderby"], th.sortable a' ).count();
		if ( ! sortierbar ) return 'Keine sortierbare Spalte gefunden.';
		const erste = seite.locator( 'th a[href*="orderby"], th.sortable a' ).first();
		await erste.click();
		await seite.waitForLoadState( 'domcontentloaded' );
		const zeilen = await seite.locator( 'table tbody tr' ).count();
		return soll.wahr( zeilen > 0, 'Nach dem Sortieren ist die Liste leer.' );
	} );

	await s.punkt( 'I3', 'Filter nach Formular', async () => {
		const { seite } = await b.oeffnen( `${ LISTE }&gfb_filter_form_id=gfbt_doi` );
		const text = await seite.locator( 'table' ).innerText().catch( () => '' );
		await seite.close();
		if ( ! text ) return 'Keine Tabelle nach dem Filtern.';
		return soll.enthaeltNicht( text, 'gfbt_voll', 'Gefilterte Liste' );
	} );

	await s.punkt( 'I4', 'Suche', async () => {
		const { seite } = await b.oeffnen( `${ LISTE }&s=Mira` );
		const zeilen = await seite.locator( 'table tbody tr' ).count();
		await seite.close();
		return soll.wahr( zeilen > 0, 'Die Suche nach einem bekannten Vornamen findet nichts.' );
	} );

	await s.punkt( 'I6', 'Detailansicht', async () => {
		const seite = u.listeSeite;
		if ( ! seite ) throw s.uebersprungen( 'I1 lieferte keine Seite.' );
		const link = seite.locator( 'table tbody tr a[href*="gfb_view"], table tbody tr a[href*="submission"]' ).first();
		if ( ! ( await link.count() ) ) throw s.uebersprungen( 'Kein Link zur Detailansicht gefunden.' );
		await link.click();
		await seite.waitForLoadState( 'domcontentloaded' );
		const text = await seite.locator( '#wpbody-content' ).innerText();
		return soll.enthaelt( text, 'Vorname', 'Detailansicht' );
	} );

	await s.punkt( 'I7', 'Angriffs-Eingabe in der Liste', async () => {
		const { seite } = await b.oeffnen( `${ LISTE }&s=script` );
		const ausgefuehrt = await seite.evaluate( () => window.__gfbXss === true );
		const roh = await seite.content();
		await seite.close();
		if ( ausgefuehrt ) return 'Eingeschleustes Skript wurde ausgeführt.';
		return soll.enthaeltNicht( roh, '<script>alert(1)</script>', 'Quelltext der Liste' );
	} );

	await s.punkt( 'I15', 'Bestätigungs-Ampel', async () => {
		const { seite } = await b.oeffnen( `${ LISTE }&gfb_filter_form_id=gfbt_doi` );
		const ampeln = await seite.evaluate( () =>
			Array.from( document.querySelectorAll( '[class*="doi"], [class*="ampel"], [title]' ) )
				.map( ( e ) => e.getAttribute( 'title' ) || e.innerText )
				.filter( ( t ) => t && /best(ä|ae)tig|abgelaufen|offen|kein/i.test( t ) )
		);
		await seite.close();
		return soll.wahr( ampeln.length > 0, 'Keine Bestätigungs-Ampel mit erklärendem Text gefunden.' );
	} );

	await s.punkt( 'I16', 'Prüfprotokoll-Seite', async () => {
		const { seite } = await b.oeffnen( '/wp-admin/admin.php?page=gfb-audit' );
		const text = await seite.locator( '#wpbody-content' ).innerText();
		await seite.close();
		if ( /keine Berechtigung|Sie haben nicht/i.test( text ) ) return 'Kein Zugriff auf das Prüfprotokoll.';
		return soll.wahr( text.length > 100, 'Die Seite ist leer.' );
	} );

	await s.punkt( 'I18', 'Einstellungsseite', async () => {
		const { seite, meldungen } = await b.oeffnen( '/wp-admin/admin.php?page=gfb-settings' );
		const karten = await seite.locator( 'form' ).count();
		const text = await seite.locator( '#wpbody-content' ).innerText();
		await b.bild( seite, 'einstellungen' );
		await seite.close();
		const fehler = meldungen.filter( ( m ) => /gfb|formbuilder/i.test( m ) );
		if ( fehler.length ) return `Konsolenmeldung: ${ fehler[ 0 ] }`;
		if ( karten === 0 ) return 'Keine Einstellungskarte auf der Seite.';
		return soll.wahr( text.length > 200, 'Die Einstellungsseite ist fast leer.' );
	} );

	await s.punkt( 'I19', 'Texte-Seite', async () => {
		const { seite } = await b.oeffnen( '/wp-admin/admin.php?page=gfb-texts' );
		const felder = await seite.locator( 'textarea, input[type="text"]' ).count();
		await seite.close();
		return soll.wahr( felder > 50, `Nur ${ felder } Textfelder gefunden, erwartet über 50.` );
	} );

	await s.punkt( 'I21', 'Menü und Unterseiten', async () => {
		const { seite } = await b.oeffnen( '/wp-admin/index.php' );
		const eintraege = await seite.evaluate( () =>
			Array.from( document.querySelectorAll( '#adminmenu a' ) )
				.map( ( a ) => a.getAttribute( 'href' ) || '' )
				.filter( ( h ) => h.includes( 'page=gfb-' ) )
		);
		await seite.close();
		return soll.wahr( eintraege.length >= 3, `Nur diese Menüpunkte gefunden: ${ eintraege.join( ', ' ) }` );
	} );

	await u.listeSeite?.close();
	u.listeSeite = null;
}
