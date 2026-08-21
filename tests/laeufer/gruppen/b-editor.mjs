// Gruppe B – Block-Editor. Der kritische Bereich bei neuen WordPress-Fassungen.
import { soll } from '../lib/pruefung.mjs';

const ERWARTETE_BLOECKE = [
	'gfb/form', 'gfb/form-success', 'gfb/field-submit', 'gfb/token', 'gfb/all-fields',
	'gfb/receipt-mail', 'gfb/doi-mail', 'gfb/confirm-button', 'gfb/confirm-status',
	'gfb/field-text', 'gfb/field-textarea', 'gfb/field-email', 'gfb/field-tel', 'gfb/field-url',
	'gfb/field-number', 'gfb/field-range', 'gfb/field-date', 'gfb/field-time', 'gfb/field-datetime',
	'gfb/field-select', 'gfb/field-radio', 'gfb/field-checkbox', 'gfb/field-file', 'gfb/field-hidden',
];

export default async function gruppeB( u, s, b ) {
	s.gruppe( 'B – Block-Editor' );
	const postId = u.aufbau.seiten.voll;
	let editor = null;

	await s.punkt( 'B1', 'Alle 24 Blöcke sind angemeldet', async () => {
		editor = await b.editor( postId );
		const angemeldet = await editor.seite.evaluate( () =>
			window.wp.blocks.getBlockTypes().map( ( t ) => t.name )
		);
		const fehlend = ERWARTETE_BLOECKE.filter( ( n ) => ! angemeldet.includes( n ) );
		return soll.wahr( fehlend.length === 0, `Nicht angemeldet: ${ fehlend.join( ', ' ) }` );
	} );

	await s.punkt( 'B2', 'Keine ungültigen Blöcke', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const befund = await editor.seite.evaluate( () => {
			const sammeln = ( bloecke, treffer = [] ) => {
				for ( const block of bloecke ) {
					if ( ! block.isValid ) treffer.push( block.name );
					if ( block.innerBlocks?.length ) sammeln( block.innerBlocks, treffer );
				}
				return treffer;
			};
			const alle = window.wp.data.select( 'core/block-editor' ).getBlocks();
			return { ungueltig: sammeln( alle ), anzahl: alle.length };
		} );
		if ( befund.anzahl === 0 ) return 'Der Editor zeigt gar keine Blöcke.';
		return soll.wahr( befund.ungueltig.length === 0, `Ungültig: ${ befund.ungueltig.join( ', ' ) }` );
	} );

	await s.punkt( 'B3', 'Plugin-Stile im Editor-Rahmen', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const geladen = await editor.seite.evaluate( () => {
			const rahmen = document.querySelector( 'iframe[name="editor-canvas"]' );
			const dok = rahmen ? rahmen.contentDocument : document;
			if ( ! dok ) return null;
			return Array.from( dok.querySelectorAll( 'link[rel="stylesheet"], style' ) )
				.some( ( e ) => /gfb|formbuilder/i.test( e.href || e.textContent || '' ) );
		} );
		if ( geladen === null ) return 'Der Editor-Rahmen war nicht erreichbar.';
		return soll.wahr( geladen, 'Im Editor-Rahmen ist kein Stil des Plugins geladen.' );
	} );

	await s.punkt( 'B4', 'Keine Fehler in der Browser-Konsole', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const eigene = editor.meldungen.filter( ( m ) => /gfb|formbuilder/i.test( m ) );
		return soll.wahr( eigene.length === 0, `Meldungen:\n${ eigene.slice( 0, 4 ).join( '\n' ) }` );
	} );

	await s.punkt( 'B5', 'Feldblöcke nur innerhalb des Formulars', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const erlaubt = await editor.seite.evaluate( () => {
			const auswahl = window.wp.data.select( 'core/block-editor' );
			// Auf oberster Ebene darf ein Feldblock nicht einfügbar sein.
			return auswahl.canInsertBlockType( 'gfb/field-text', '' );
		} );
		return soll.wahr( erlaubt === false, 'Ein Feldblock lässt sich ausserhalb des Formulars einfügen.' );
	} );

	await s.punkt( 'B6', 'Bestätigungs-Statusblock nur im Site-Editor', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const sichtbar = await editor.seite.evaluate( () => {
			const typ = window.wp.blocks.getBlockType( 'gfb/confirm-status' );
			return typ ? typ.supports?.inserter !== false : null;
		} );
		if ( sichtbar === null ) return 'Der Block gfb/confirm-status ist nicht angemeldet.';
		return soll.wahr( sichtbar === false, 'Der Block erscheint auch im Beitrags-Editor im Einfügen-Menü.' );
	} );

	await s.punkt( 'B7', 'Fremde Blöcke bleiben verfügbar', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		// Rückfall 2.11.2: eine globale Erlaubnisliste hätte fremde Blöcke gesperrt.
		const gesperrt = await editor.seite.evaluate( () => {
			const auswahl = window.wp.data.select( 'core/block-editor' );
			const pruefen = [ 'core/paragraph', 'core/heading', 'core/image', 'core/group', 'core/list' ];
			return pruefen.filter( ( n ) => ! auswahl.canInsertBlockType( n, '' ) );
		} );
		return soll.wahr( gesperrt.length === 0, `Gesperrte Fremdblöcke: ${ gesperrt.join( ', ' ) }` );
	} );

	await s.punkt( 'B11', 'Seitenleiste des Formularblocks', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const seite = editor.seite;
		await seite.evaluate( () => {
			const auswahl = window.wp.data.select( 'core/block-editor' );
			const form = auswahl.getBlocks().find( ( b ) => b.name === 'gfb/form' );
			if ( form ) window.wp.data.dispatch( 'core/block-editor' ).selectBlock( form.clientId );
		} );
		await seite.waitForTimeout( 1200 );
		const text = await seite.evaluate( () => {
			const leiste = document.querySelector( '.interface-interface-skeleton__sidebar, .editor-sidebar' );
			return leiste ? leiste.innerText : '';
		} );
		if ( ! text ) return 'Die Seitenleiste ist nicht sichtbar.';
		const gruppen = [ 'Benachrichtigung', 'Bestätigungsmail', 'Erscheinungsbild', 'Entwurf', 'Spam' ];
		const gefunden = gruppen.filter( ( g ) => new RegExp( g, 'i' ).test( text ) );
		return soll.wahr(
			gefunden.length >= 3,
			`Nur diese Einstellungsgruppen gefunden: ${ gefunden.join( ', ' ) || 'keine' }`
		);
	} );

	await s.punkt( 'B12', 'Editor-Ansicht als Beleg', async () => {
		if ( ! editor ) throw s.uebersprungen( 'Editor liess sich nicht öffnen.' );
		const datei = await b.bild( editor.seite, 'editor' );
		return soll.wahr( !! datei, 'Kein Bild erzeugt.' );
	} );

	await editor?.seite.close();
}
