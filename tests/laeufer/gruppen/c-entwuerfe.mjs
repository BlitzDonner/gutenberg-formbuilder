// Gruppe C und D – Verhalten im Browser: Entwürfe, Felder, Erfolgsbereich.
import { soll } from '../lib/pruefung.mjs';

/** Liest alle Entwürfe aus der Datenbank des Browsers. */
async function entwuerfeLesen( seite ) {
	return seite.evaluate( async () => {
		const db = await new Promise( ( fertig, fehler ) => {
			const anfrage = window.indexedDB.open( 'gfbDraftsDB', 1 );
			anfrage.onsuccess = () => fertig( anfrage.result );
			anfrage.onerror = () => fehler( anfrage.error );
			anfrage.onupgradeneeded = () => fertig( null );
		} ).catch( () => null );
		if ( ! db || ! db.objectStoreNames.contains( 'drafts' ) ) return [];
		return new Promise( ( fertig ) => {
			const laden = db.transaction( 'drafts', 'readonly' ).objectStore( 'drafts' ).getAll();
			laden.onsuccess = () => fertig( laden.result || [] );
			laden.onerror = () => fertig( [] );
		} );
	} );
}

export default async function gruppeC( u, s, b ) {
	s.gruppe( 'C – Einstellungen des Formularblocks' );

	await s.punkt( 'C2', 'Entwurf speichern ein', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		await seite.fill( 'input[name="vorname"]', 'Entwurfstest' );
		await seite.locator( 'textarea[name="nachricht"]' ).fill( 'Halb geschrieben' );
		await seite.waitForTimeout( 2500 );
		const entwuerfe = await entwuerfeLesen( seite );
		u.entwurfSeite = seite;
		const inhalt = JSON.stringify( entwuerfe );
		return soll.enthaelt( inhalt, 'Entwurfstest', 'Gespeicherter Entwurf' );
	} );

	await s.punkt( 'C5', 'Wiederherstellung', async () => {
		const seite = u.entwurfSeite;
		if ( ! seite ) throw s.uebersprungen( 'C2 lieferte keine Seite.' );
		await seite.reload( { waitUntil: 'domcontentloaded' } );
		await seite.waitForTimeout( 1500 );
		const wert = await seite.inputValue( 'input[name="vorname"]' );
		return soll.gleich( wert, 'Entwurfstest', 'Wiederhergestellter Wert' );
	} );

	await s.punkt( 'C6', 'Zurücksetzen-Schaltfläche', async () => {
		const seite = u.entwurfSeite;
		if ( ! seite ) throw s.uebersprungen( 'C2 lieferte keine Seite.' );
		const knopf = seite.locator( '.gfb-draft-reset-button' ).first();
		if ( ! ( await knopf.count() ) ) return 'Keine Zurücksetzen-Schaltfläche im Formular.';
		seite.once( 'dialog', ( d ) => d.accept() );
		await knopf.click();
		await seite.waitForTimeout( 1200 );
		const wert = await seite.inputValue( 'input[name="vorname"]' );
		return soll.gleich( wert, '', 'Feld nach dem Zurücksetzen' );
	} );

	await s.punkt( 'C7', 'Dateifelder im Entwurf', async () => {
		const seite = u.entwurfSeite;
		if ( ! seite ) throw s.uebersprungen( 'C2 lieferte keine Seite.' );
		const entwuerfe = await entwuerfeLesen( seite );
		await seite.close();
		u.entwurfSeite = null;
		const werte = entwuerfe.flatMap( ( e ) => Object.keys( e.values || e.data || {} ) );
		return soll.wahr(
			! werte.includes( 'ausweis' ),
			`Im Entwurf gespeicherte Felder: ${ werte.join( ', ' ) || JSON.stringify( entwuerfe ).slice( 0, 200 ) }`
		);
	} );

	await s.punkt( 'C3', 'Entwurf speichern aus', async () => {
		const { seite } = await b.oeffnen( '/gfbt-danke-formular/' );
		await seite.fill( 'input[name="vorname"]', 'Ohne Entwurf' );
		await seite.waitForTimeout( 2500 );
		const entwuerfe = await entwuerfeLesen( seite );
		await seite.close();
		return soll.enthaeltNicht( JSON.stringify( entwuerfe ), 'Ohne Entwurf', 'Entwurfsspeicher' );
	} );

	s.gruppe( 'D – Feldtypen' );

	await s.punkt( 'D10', 'Schieberegler', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		u.feldSeite = seite;
		const regler = await seite.evaluate( () => {
			const el = document.querySelector( 'input[name="regler"]' );
			return el ? { wert: el.value, min: el.min, max: el.max, schritt: el.step } : null;
		} );
		if ( ! regler ) return 'Kein Schieberegler im Formular.';
		const abweichung = [];
		if ( regler.wert !== '20' ) abweichung.push( `Vorgabe ${ regler.wert } statt 20` );
		if ( regler.min !== '0' ) abweichung.push( `kleinster Wert ${ regler.min } statt 0` );
		if ( regler.max !== '100' ) abweichung.push( `grösster Wert ${ regler.max } statt 100` );
		if ( regler.schritt !== '5' ) abweichung.push( `Schrittweite ${ regler.schritt } statt 5` );
		return soll.wahr( abweichung.length === 0, abweichung.join( ', ' ) );
	} );

	await s.punkt( 'D17', 'Auswahlknöpfe', async () => {
		const seite = u.feldSeite;
		if ( ! seite ) throw s.uebersprungen( 'D10 lieferte keine Seite.' );
		const befund = await seite.evaluate( () => {
			const knoepfe = Array.from( document.querySelectorAll( 'input[name="anrede"]' ) );
			if ( ! knoepfe.length ) return null;
			const erste = knoepfe[ 0 ].closest( 'label, div' );
			const zweite = knoepfe[ 1 ]?.closest( 'label, div' );
			const a = erste?.getBoundingClientRect();
			const c = zweite?.getBoundingClientRect();
			return {
				werte: knoepfe.map( ( k ) => k.value ),
				nebeneinander: a && c ? Math.abs( a.top - c.top ) < 8 : null,
			};
		} );
		if ( ! befund ) return 'Keine Auswahlknöpfe im Formular.';
		if ( befund.werte.length !== 3 ) return `Nur ${ befund.werte.length } Knöpfe: ${ befund.werte.join( ', ' ) }`;
		return soll.wahr( befund.nebeneinander !== false, 'Anordnung «Zeile» wirkt nicht, die Knöpfe stehen untereinander.' );
	} );

	await s.punkt( 'D25', 'Pflichtstern', async () => {
		const seite = u.feldSeite;
		if ( ! seite ) throw s.uebersprungen( 'D10 lieferte keine Seite.' );
		const mitStern = await seite.evaluate( () => {
			const feld = document.querySelector( 'input[name="vorname"]' );
			const huelle = feld?.closest( '.gfb-field' );
			return huelle ? /\*/.test( huelle.innerText ) : null;
		} );
		if ( mitStern === null ) return 'Pflichtfeld nicht gefunden.';
		return soll.wahr( mitStern, 'Das Pflichtfeld trägt keinen Stern.' );
	} );

	await s.punkt( 'D26', 'Beschriftung für Screenreader', async () => {
		const seite = u.feldSeite;
		if ( ! seite ) throw s.uebersprungen( 'D10 lieferte keine Seite.' );
		const ohne = await seite.evaluate( () => {
			const felder = Array.from(
				document.querySelectorAll( '#gfb-form-gfbt_voll input:not([type="hidden"]):not(.gfb-hp-field), #gfb-form-gfbt_voll select, #gfb-form-gfbt_voll textarea' )
			);
			return felder
				.filter( ( f ) => {
					if ( f.getAttribute( 'aria-label' ) || f.getAttribute( 'aria-labelledby' ) ) return false;
					const id = f.getAttribute( 'id' );
					return ! ( id && document.querySelector( `label[for="${ id }"]` ) );
				} )
				.map( ( f ) => f.getAttribute( 'name' ) || f.type );
		} );
		await seite.close();
		u.feldSeite = null;
		return soll.wahr( ohne.length === 0, `Ohne Beschriftung: ${ ohne.join( ', ' ) }` );
	} );

	await s.punkt( 'D24', 'Erfolgsbereich', async () => {
		const { seite } = await b.oeffnen( '/gfbt-voll/' );
		await seite.fill( 'input[name="vorname"]', 'Erfolgsfall' );
		await seite.fill( 'input[name="mail"]', `erfolg-${ Date.now() }@example.test` );
		await seite.waitForTimeout( 2400 );
		await Promise.all( [
			seite.waitForURL( /gfb_status=success/, { timeout: 20000 } ).catch( () => {} ),
			seite.locator( '#gfb-form-gfbt_voll form button[type="submit"]' ).first().click(),
		] );
		// Die Erfolgsmeldung steht im Erfolgsbereich; die Abfrage in der Adresse
		// räumt das Plugin gleich danach selbst weg.
		const bereich = seite.locator( '#gfb-erfolg-gfbt_voll' );
		await bereich.waitFor( { state: 'attached', timeout: 15000 } ).catch( () => {} );
		const text = await bereich.innerText().catch( () => '' );
		u.erfolgSeite = seite;
		return soll.wahr( text.trim().length > 0, 'Der Erfolgsbereich ist leer oder fehlt.' );
	} );

	await s.punkt( 'C9', 'Adresse nach dem Absenden', async () => {
		const seite = u.erfolgSeite;
		if ( ! seite ) throw s.uebersprungen( 'D24 lieferte keine Seite.' );
		await seite.waitForTimeout( 1500 );
		const adresse = seite.url();
		await seite.close();
		u.erfolgSeite = null;
		// Ohne Aufräumen würde ein Neuladen die Erfolgsmeldung wiederholen.
		return soll.enthaeltNicht( adresse, 'gfb_status=', 'Adresse nach dem Absenden' );
	} );
}
