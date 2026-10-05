// Formular laden, ausfüllen und absenden – auf demselben Weg wie ein Browser.
import crypto from 'node:crypto';

/** Zählt die führenden Null-Bits eines Hashes. */
function nullBits( buf ) {
	let n = 0;
	for ( const b of buf ) {
		if ( b === 0 ) { n += 8; continue; }
		for ( let i = 7; i >= 0; i-- ) { if ( b & ( 1 << i ) ) return n; n++; }
	}
	return n;
}

/**
 * Löst die Aufgabe des Eingabe-Nachweises wie assets/eingabe-nachweis.js:
 * eine Zahl, sodass SHA-256(Aufgabe.Salz.Zahl) mit den verlangten Null-Bits beginnt.
 */
export function nachweisLoesen( aufgabe ) {
	const bits = parseInt( String( aufgabe ).split( '.' )[ 2 ], 10 );
	const salz = crypto.randomBytes( 16 ).toString( 'hex' );
	for ( let zahl = 0; ; zahl++ ) {
		const h = crypto.createHash( 'sha256' ).update( `${ aufgabe }.${ salz }.${ zahl }` ).digest();
		if ( nullBits( h ) >= bits ) return `${ salz }.${ zahl }`;
	}
}

/** Liest alle Formularfelder aus dem gelieferten HTML. */
export function formularLesen( html, formId ) {
	const anfang = html.indexOf( `data-form-id="${ formId }"` );
	const suchraum = anfang === -1 ? html : html.slice( Math.max( 0, anfang - 4000 ) );
	const formAnfang = suchraum.indexOf( '<form' );
	const formEnde = suchraum.indexOf( '</form>', formAnfang );
	if ( formAnfang === -1 || formEnde === -1 ) {
		throw new Error( `Formular ${ formId } nicht im HTML gefunden.` );
	}
	const form = suchraum.slice( formAnfang, formEnde );

	const felder = {};
	const honigtopf = [];
	const dateiFelder = [];

	for ( const treffer of form.matchAll( /<input\b[^>]*>/g ) ) {
		const roh = treffer[ 0 ];
		const name = attribut( roh, 'name' );
		if ( ! name ) continue;
		const typ = ( attribut( roh, 'type' ) || 'text' ).toLowerCase();
		if ( typ === 'file' ) { dateiFelder.push( name ); continue; }
		if ( roh.includes( 'gfb-hp-field' ) ) { honigtopf.push( name ); felder[ name ] = ''; continue; }
		if ( typ === 'checkbox' || typ === 'radio' ) {
			if ( roh.includes( 'checked' ) ) felder[ name ] = attribut( roh, 'value' ) ?? '1';
			continue;
		}
		if ( typ === 'submit' ) continue;
		felder[ name ] = attribut( roh, 'value' ) ?? '';
	}
	for ( const treffer of form.matchAll( /<textarea\b[^>]*>([\s\S]*?)<\/textarea>/g ) ) {
		const name = attribut( treffer[ 0 ], 'name' );
		if ( name ) felder[ name ] = '';
	}
	for ( const treffer of form.matchAll( /<select\b[^>]*>[\s\S]*?<\/select>/g ) ) {
		const name = attribut( treffer[ 0 ], 'name' );
		if ( name ) felder[ name ] = '';
	}

	return {
		action: entschaerfen( attribut( form, 'action' ) || '' ),
		felder,
		honigtopf: honigtopf[ 0 ] || '',
		dateiFelder,
		html: form,
	};
}

function attribut( markup, name ) {
	const m = markup.match( new RegExp( `\\b${ name }="([^"]*)"` ) );
	return m ? entschaerfen( m[ 1 ] ) : null;
}

function entschaerfen( s ) {
	return s
		.replaceAll( '&amp;', '&' )
		.replaceAll( '&quot;', '"' )
		.replaceAll( '&#039;', "'" )
		.replaceAll( '&lt;', '<' )
		.replaceAll( '&gt;', '>' );
}

/** Lädt die Seite und liefert das vorbereitete Formular. */
export async function formularHolen( umgebung, pfad, formId ) {
	const antwort = await fetch( `${ umgebung.basis }${ pfad }` );
	const html = await antwort.text();
	if ( ! antwort.ok ) {
		throw new Error( `Seite ${ pfad } lieferte ${ antwort.status }.` );
	}
	return { ...formularLesen( html, formId ), seite: html };
}

/**
 * Sendet ein Formular ab und liefert den ausgewerteten Zustand.
 * Wartet vorgabegemäss die zwei Sekunden ab, die der Token verlangt.
 */
export async function absenden( umgebung, formular, werte = {}, optionen = {} ) {
	// 3,2 Sekunden: Der Eingabe-Nachweis verlangt mindestens 3 Sekunden, der Token 2.
	const { warten = 3200, dateien = {}, ohne = [], roh = {}, nachweis = true } = optionen;
	if ( warten ) await new Promise( ( r ) => setTimeout( r, warten ) );

	const daten = new FormData();
	const loesung = {};
	// Wie ein Mensch im Browser: Lösung zum Nachweis mitsenden. nachweis: false
	// sendet wie ein Bot ohne JavaScript, mit leerem Lösungsfeld.
	if ( nachweis && formular.felder.gfb_nw_aufgabe ) {
		loesung.gfb_nw_loesung = nachweisLoesen( formular.felder.gfb_nw_aufgabe );
	}
	const alle = { ...formular.felder, ...loesung, ...werte, ...roh };
	for ( const [ name, wert ] of Object.entries( alle ) ) {
		if ( ohne.includes( name ) ) continue;
		daten.append( name, wert );
	}
	for ( const [ name, datei ] of Object.entries( dateien ) ) {
		daten.append( name, new Blob( [ datei.inhalt ], { type: datei.typ || 'application/octet-stream' } ), datei.name );
	}

	const antwort = await fetch( formular.action, {
		method: 'POST',
		body: daten,
		redirect: 'manual',
	} );

	const ziel = antwort.headers.get( 'location' ) || '';
	const url = ziel ? new URL( ziel, umgebung.basis ) : null;
	return {
		status: antwort.status,
		ziel,
		zustand: url?.searchParams.get( 'gfb_status' ) || '',
		code: url?.searchParams.get( 'gfb_code' ) || '',
		meldung: url?.searchParams.get( 'gfb_detail' ) || url?.searchParams.get( 'gfb_msg' ) || '',
		anker: url?.hash || '',
		url,
	};
}

/** Kurzform: Seite laden, ausfüllen, absenden. */
export async function einsenden( umgebung, pfad, formId, werte, optionen ) {
	const formular = await formularHolen( umgebung, pfad, formId );
	return { formular, ergebnis: await absenden( umgebung, formular, werte, optionen ) };
}
