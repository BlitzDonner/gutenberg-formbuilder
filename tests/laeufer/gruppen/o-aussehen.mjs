// Gruppe O – Aussehen. Gemessen wird die tatsächliche Darstellung, nicht das Bild.
import { soll } from '../lib/pruefung.mjs';

/** Rechnet «rgb(18, 52, 86)» in «#123456» um. */
function alsHex( wert ) {
	const t = String( wert ).match( /rgba?\(([^)]+)\)/ );
	if ( ! t ) return String( wert ).toLowerCase();
	const [ r, g, b ] = t[ 1 ].split( ',' ).map( ( z ) => parseInt( z.trim(), 10 ) );
	return '#' + [ r, g, b ].map( ( z ) => z.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

async function gemessen( seite ) {
	return seite.evaluate( () => {
		// Der Wrapper trägt die Farbvariablen, das Formular liegt darin.
		const huelle = document.querySelector( '#gfb-form-gfbt_farben' );
		const form = huelle ? huelle.querySelector( 'form' ) : null;
		if ( ! form ) return { fehler: 'Formular gfbt_farben nicht gefunden' };
		const label = form.querySelector( 'label' );
		// Das erste Textfeld im Markup ist der Honigtopf – er trägt keine Feldstile.
		const eingabe = form.querySelector( 'input[type="text"]:not(.gfb-hp-field)' );
		const knopf = form.querySelector( 'button[type="submit"]' );
		const stil = ( el ) => ( el ? window.getComputedStyle( el ) : null );
		const l = stil( label ), e = stil( eingabe ), k = stil( knopf );
		return {
			label: l?.color || '',
			text: e?.color || '',
			feldHintergrund: e?.backgroundColor || '',
			rahmen: e?.borderTopColor || '',
			knopfHintergrund: k?.backgroundColor || '',
			knopfText: k?.color || '',
		};
	} );
}

export default async function gruppeO( u, s, b ) {
	s.gruppe( 'O – Aussehen und Bedienung' );

	await s.punkt( 'O1', 'Farbwerte hell', async () => {
		const { seite } = await b.oeffnen( '/gfbt-farben/' );
		await seite.emulateMedia( { colorScheme: 'light' } );
		await seite.waitForTimeout( 300 );
		u.farbSeite = seite;
		const ist = await gemessen( seite );
		if ( ist.fehler ) return ist.fehler;
		const erwartet = {
			label: '#123456',
			text: '#222222',
			feldHintergrund: '#fafafa',
			rahmen: '#334455',
			knopfHintergrund: '#0b5fd0',
			knopfText: '#ffffff',
		};
		const abweichend = Object.entries( erwartet )
			.filter( ( [ k, v ] ) => alsHex( ist[ k ] ) !== v )
			.map( ( [ k, v ] ) => `${ k }: ${ alsHex( ist[ k ] ) } statt ${ v }` );
		return soll.wahr( abweichend.length === 0, abweichend.join( '; ' ) );
	} );

	await s.punkt( 'O2', 'Farbwerte dunkel', async () => {
		const seite = u.farbSeite;
		if ( ! seite ) throw s.uebersprungen( 'O1 lieferte keine Seite.' );
		await seite.emulateMedia( { colorScheme: 'dark' } );
		await seite.waitForTimeout( 400 );
		const ist = await gemessen( seite );
		if ( ist.fehler ) return ist.fehler;
		const erwartet = {
			label: '#aabbcc',
			text: '#eeeeee',
			feldHintergrund: '#1c1f25',
			rahmen: '#445566',
			knopfHintergrund: '#6ba4ff',
			knopfText: '#101010',
		};
		const abweichend = Object.entries( erwartet )
			.filter( ( [ k, v ] ) => alsHex( ist[ k ] ) !== v )
			.map( ( [ k, v ] ) => `${ k }: ${ alsHex( ist[ k ] ) } statt ${ v }` );
		await seite.emulateMedia( { colorScheme: 'light' } );
		return soll.wahr( abweichend.length === 0, abweichend.join( '; ' ) );
	} );

	await s.punkt( 'O6', 'Tastaturbedienung', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		// Vom ersten Feld aus tabben, sonst hängt der Fokus in der Admin-Leiste.
		await seite.focus( '#gfb-form-gfbt_voll form input[name="vorname"]' );
		const reihenfolge = [ 'input:vorname' ];
		for ( let i = 0; i < 7; i++ ) {
			await seite.keyboard.press( 'Tab' );
			reihenfolge.push(
				await seite.evaluate( () => {
					const a = document.activeElement;
					return a ? `${ a.tagName.toLowerCase() }:${ a.getAttribute( 'name' ) || a.type || '' }` : '';
				} )
			);
		}
		await seite.close();
		const felder = reihenfolge.filter( ( r ) => /input|select|textarea|button/.test( r ) );
		return soll.wahr( felder.length >= 4, `Mit der Tabulatortaste erreichbar: ${ reihenfolge.join( ' → ' ) }` );
	} );

	await s.punkt( 'O7', 'Schmales Fenster', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		await seite.setViewportSize( { width: 375, height: 812 } );
		await seite.waitForTimeout( 400 );
		const ueberlauf = await seite.evaluate( () => {
			const form = document.querySelector( 'form' );
			return form ? form.scrollWidth > window.innerWidth + 2 : null;
		} );
		await b.bild( seite, 'mobil' );
		await seite.close();
		if ( ueberlauf === null ) return 'Kein Formular auf der Seite.';
		return soll.wahr( ! ueberlauf, 'Das Formular ist breiter als der Bildschirm.' );
	} );

	await s.punkt( 'O8', 'Screenshots hell und dunkel', async () => {
		const seite = u.farbSeite;
		if ( ! seite ) throw s.uebersprungen( 'O1 lieferte keine Seite.' );
		await b.bild( seite, 'formular-hell' );
		await seite.emulateMedia( { colorScheme: 'dark' } );
		await seite.waitForTimeout( 300 );
		await b.bild( seite, 'formular-dunkel' );
		await seite.close();
		u.farbSeite = null;
		return true;
	} );

	s.gruppe( 'E – Absenden und Abwehr' );

	await s.punkt( 'E21', 'Doppelklick auf Absenden', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		// Die Navigation wird unterbunden, damit der Zustand direkt nach dem Klick
		// messbar bleibt. Der Zuhörer des Plugins läuft trotzdem: er hängt in der
		// Blasenphase, dieser hier in der Erfassungsphase.
		await seite.evaluate( () => {
			document.querySelectorAll( 'form' ).forEach( ( f ) =>
				f.addEventListener( 'submit', ( e ) => e.preventDefault(), true )
			);
		} );
		await seite.fill( 'input[name="vorname"]', 'Doppel' );
		await seite.fill( 'input[name="mail"]', `doppel-${ Date.now() }@example.test` );
		await seite.waitForTimeout( 2400 );
		await seite.locator( '#gfb-form-gfbt_voll form button[type="submit"]' ).first().click( { noWaitAfter: true } );
		await seite.waitForTimeout( 600 );
		const zustand = await seite.evaluate( () => {
			const knopf = document.querySelector( '#gfb-form-gfbt_voll form button[type="submit"]' );
			const ueberlagerung = document.querySelector( '.gfb-submit-overlay' );
			return {
				gesperrt: !! ( knopf && ( knopf.disabled || knopf.getAttribute( 'aria-disabled' ) === 'true' ) ),
				ueberlagerung: !! ueberlagerung,
				rolle: ueberlagerung ? ueberlagerung.getAttribute( 'role' ) : '',
			};
		} );
		await seite.close();
		return soll.wahr(
			zustand.gesperrt || zustand.ueberlagerung,
			'Nach dem Klick war weder der Knopf gesperrt noch eine Überlagerung sichtbar.'
		);
	} );

	await s.punkt( 'E22', 'Fehlermeldung im Frontend', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/?gfb_status=error&gfb_code=err_validation&gfb_form=gfbt_voll' );
		const meldung = await seite.evaluate( () => {
			const el = document.querySelector( '[role="alert"], .gfb-notice, .gfb-error, .gfb-message' );
			return el ? { text: el.innerText.trim(), rolle: el.getAttribute( 'role' ) } : null;
		} );
		await seite.close();
		if ( ! meldung ) return 'Keine sichtbare Fehlermeldung auf der Seite.';
		return soll.wahr( meldung.text.length > 0, 'Die Fehlermeldung ist leer.' );
	} );
}
