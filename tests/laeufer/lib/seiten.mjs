// Legt die Testseiten an. Der Editor baut das Blockmarkup selbst – nur so ist es gültig.
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HIER = path.dirname( fileURLToPath( import.meta.url ) );
const SPEZIFIKATION = path.join( HIER, '..', '..', 'fixtures', 'formulare.json' );

export async function spezifikationLesen() {
	return JSON.parse( await fs.readFile( SPEZIFIKATION, 'utf8' ) );
}

/**
 * Legt leere Seiten an und lässt den Editor die Blöcke hineinschreiben.
 *
 * Feldblöcke speichern echtes HTML. Von Hand geschriebenes Markup gilt dem
 * Editor deshalb als ungültig, auch wenn das Frontend es richtig rendert.
 * Diese Seiten entstehen darum auf demselben Weg wie bei einer Redaktion.
 *
 * @param {object} u Umgebung.
 * @param {object} b Browser.
 * @return {Promise<object>} Kennung → Beitrags-ID.
 */
export async function seitenBauen( u, b ) {
	const spez = await spezifikationLesen();
	const seiten = {};

	// Reste früherer Läufe entfernen: sonst hängt WordPress «-2» an den Slug
	// und die Prüfungen öffnen die alte Seite.
	await u.php( `
		foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
			if ( 0 === strpos( $p->post_name, 'gfbt-' ) ) {
				wp_delete_post( $p->ID, true );
			}
		}
		echo 'aufgeraeumt';
	` );

	// Danke-Seite zuerst, ihre ID wird als Attribut gebraucht.
	const dankeId = parseInt(
		await u.wp( 'post create --post_type=page --post_title=Danke --post_name=gfbt-danke --post_status=publish --porcelain' ),
		10
	);
	seiten.dankeZiel = dankeId;

	for ( const formular of spez.formulare ) {
		const felder = formular.felder || spez.felder;
		const attrs = { ...formular.attrs };
		if ( formular.dankeSeite ) {
			attrs.thankYouPageId = dankeId;
		}

		const id = parseInt(
			await u.wp(
				`post create --post_type=page --post_title="${ formular.titel }" ` +
				`--post_name=${ formular.slug } --post_status=publish --porcelain`
			),
			10
		);

		const { seite } = await b.editor( id );
		const ergebnis = await seite.evaluate(
			async ( { attrs: formAttrs, felder: felderSpez } ) => {
				const { createBlock } = window.wp.blocks;
				const bauen = ( f ) =>
					createBlock( f.name, f.attrs, ( f.inner || [] ).map( bauen ) );
				const innere = felderSpez.map( bauen );
				const form = createBlock( 'gfb/form', formAttrs, innere );
				window.wp.data.dispatch( 'core/block-editor' ).resetBlocks( [ form ] );
				await window.wp.data.dispatch( 'core/editor' ).savePost();
				const auswahl = window.wp.data.select( 'core/block-editor' );
				const ungueltig = [];
				const pruefen = ( bloecke ) => {
					for ( const block of bloecke ) {
						if ( ! block.isValid ) ungueltig.push( block.name );
						if ( block.innerBlocks?.length ) pruefen( block.innerBlocks );
					}
				};
				pruefen( auswahl.getBlocks() );
				return { ungueltig, anzahl: auswahl.getBlocks()[ 0 ]?.innerBlocks.length || 0 };
			},
			{ attrs, felder }
		);
		await seite.waitForTimeout( 800 );
		await seite.close();

		if ( ergebnis.ungueltig.length ) {
			throw new Error(
				`Seite ${ formular.slug }: der Editor meldet ungültige Blöcke (${ ergebnis.ungueltig.join( ', ' ) }).`
			);
		}
		seiten[ formular.kennung ] = id;
	}

	return seiten;
}

/** Rückfall ohne Browser: Markup aus Blockkommentaren, im Editor ungültig. */
export async function seitenOhneBrowser( u ) {
	const roh = await u.wp( 'eval-file /gfb-fixtures/formulare.php' );
	return JSON.parse( roh.trim().split( '\n' ).pop() ).seiten;
}
