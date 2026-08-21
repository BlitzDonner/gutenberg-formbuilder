// Formular laden, ausfüllen und absenden – auf demselben Weg wie ein Browser.

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
	const { warten = 2200, dateien = {}, ohne = [], roh = {} } = optionen;
	if ( warten ) await new Promise( ( r ) => setTimeout( r, warten ) );

	const daten = new FormData();
	const alle = { ...formular.felder, ...werte, ...roh };
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
