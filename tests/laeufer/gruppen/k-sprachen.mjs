// Gruppe K, L und N – Sprachen, Datenschutz-Werkzeuge, Update-Client.
import { soll } from '../lib/pruefung.mjs';
import { formularHolen, absenden } from '../lib/http.mjs';
import { steuern } from '../lib/wp.mjs';

const SPRACHEN = [
	[ 'K1', 'Deutsch (Schweiz)', 'de_CH', [ 'Bitte', 'Feld' ] ],
	[ 'K2', 'Englisch', 'en_US', [ 'Please', 'field' ] ],
	[ 'K3', 'Französisch', 'fr_FR', [ 'Veuillez', 'champ' ] ],
	[ 'K4', 'Italienisch', 'it_IT', [ 'Compila', 'campo' ] ],
];

export default async function gruppeK( u, s ) {
	s.gruppe( 'K – Sprachen' );

	const vorher = ( await u.wp( 'option get WPLANG' ).catch( () => '' ) ).trim();

	for ( const [ nr, titel, kennung, woerter ] of SPRACHEN ) {
		await s.punkt( nr, titel, async () => {
			if ( 'de_CH' !== kennung ) {
				const geladen = await u.wp( `language core install ${ kennung }` ).catch( () => '' );
				if ( /konnte nicht|could not|Error/i.test( geladen ) ) {
					throw s.uebersprungen( `Sprachpaket ${ kennung } liess sich nicht laden.` );
				}
			}
			await u.wp( `site switch-language ${ kennung }` ).catch( async () => {
				await u.wp( `option update WPLANG ${ 'en_US' === kennung ? '' : kennung }` );
			} );

			// Eine Einsendung mit leerem Pflichtfeld erzwingt eine übersetzte Meldung.
			const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
			const e = await absenden( u, f, { vorname: '', mail: 'sprache@example.test' } );
			const meldung = decodeURIComponent( e.meldung || '' );
			if ( ! meldung ) return 'Keine Fehlermeldung erhalten.';
			const treffer = woerter.some( ( w ) => new RegExp( w, 'i' ).test( meldung ) );
			return soll.wahr( treffer, `Meldung in ${ kennung }: «${ meldung.slice( 0, 100 ) }»` );
		} );
	}

	await s.punkt( 'K8', 'Sprachangabe im Formular', async () => {
		await u.wp( 'site switch-language de_CH' ).catch( () => {} );
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const treffer = f.html.match( /lang="([^"]+)"/ );
		if ( ! treffer ) throw s.uebersprungen( 'Das Formular trägt keine Sprachangabe.' );
		return soll.wahr(
			/^[a-z]{2}-[A-Z]{2}$/.test( treffer[ 1 ] ),
			`Sprachkennung «${ treffer[ 1 ] }», erwartet die Form de-CH.`
		);
	} );

	// Sprache zurückstellen, damit die folgenden Gruppen deutsche Texte sehen.
	await u.wp( `site switch-language ${ vorher || 'de_CH' }` ).catch( () => {} );

	s.gruppe( 'L – Datenschutz-Werkzeuge' );

	let adresse = '';

	await s.punkt( 'L1', 'Datenauskunft', async () => {
		adresse = `auskunft-${ Date.now() }@example.test`;
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, {
			vorname: 'Auskunft',
			mail: adresse,
			telefon: '+41 33 777 88 99',
			nachricht: 'Bitte um Auskunft',
		} );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const roh = await u.php( `
			$ergebnis = GFB_Security::export_personal_data( '${ adresse }', 1 );
			echo wp_json_encode( $ergebnis );
		` );
		return soll.enthaelt( roh, 'Auskunft', 'Auskunftsdaten' );
	} );

	await s.punkt( 'L2', 'Auskunft und Verschlüsselung', async () => {
		if ( ! adresse ) throw s.uebersprungen( 'L1 lieferte keine Adresse.' );
		const roh = await u.php( `
			$ergebnis = GFB_Security::export_personal_data( '${ adresse }', 1 );
			echo wp_json_encode( $ergebnis );
		` );
		// Das vertrauliche Telefonfeld darf in der Auskunft nicht als Hülle stehen.
		if ( /"ct":/.test( roh ) ) return 'Die Auskunft enthält die verschlüsselte Hülle statt des Klartexts.';
		return soll.enthaelt( roh, '777 88 99', 'Auskunftsdaten' );
	} );

	await s.punkt( 'L3', 'Löschung', async () => {
		if ( ! adresse ) throw s.uebersprungen( 'L1 lieferte keine Adresse.' );
		const roh = await u.php( `
			$ergebnis = GFB_Security::erase_personal_data( '${ adresse }', 1 );
			echo wp_json_encode( $ergebnis );
		` );
		const danach = await u.php( `
			global $wpdb;
			echo (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}gfb_submissions WHERE payload LIKE %s",
				'%' . $wpdb->esc_like( '${ adresse }' ) . '%'
			) );
		` );
		if ( parseInt( danach, 10 ) > 0 ) return `Nach der Löschung sind noch ${ danach.trim() } Einträge da.`;
		return soll.enthaelt( roh, 'items_removed', 'Rückmeldung der Löschung' );
	} );

	await s.punkt( 'L5', 'Vermerk im Prüfprotokoll', async () => {
		const roh = await u.php( `
			global $wpdb;
			$zeilen = $wpdb->get_col( "SELECT action FROM {$wpdb->prefix}gfb_audit ORDER BY id DESC LIMIT 20" );
			echo implode( ',', $zeilen );
		` );
		return soll.wahr(
			/delete|erase|privacy|export/i.test( roh ),
			`Letzte Einträge im Prüfprotokoll: ${ roh.slice( 0, 160 ) }`
		);
	} );

	s.gruppe( 'N – Lizenz und Update-Client' );

	await s.punkt( 'N1', 'Neue Version erkennen', async () => {
		await steuern( u, {
			update_antwort: JSON.stringify( {
				version: '99.0.0',
				signature: 'testlauf',
				requires: '6.6',
				tested: '7.2',
			} ),
		} );
		await u.wp( 'transient delete update_plugins --network' ).catch( () => {} );
		await u.php( `delete_site_transient( 'update_plugins' ); echo 'ok';` );
		const roh = await u.php( `
			// Zwei Durchgänge: WordPress 6.6 füllt die Liste der geprüften
			// Plugins erst im zweiten, vorher steigt jeder Update-Client aus.
			wp_update_plugins();
			wp_update_plugins();
			$t = get_site_transient( 'update_plugins' );
			$treffer = array();
			foreach ( (array) ( $t->response ?? array() ) as $datei => $eintrag ) {
				if ( false !== strpos( $datei, 'gutenberg-formbuilder' ) ) {
					$treffer[] = $eintrag->new_version ?? '?';
				}
			}
			echo empty( $treffer ) ? 'keins' : implode( ',', $treffer );
		` );
		return soll.gleich( roh.trim(), '99.0.0', 'Angebotene Version' );
	} );

	await s.punkt( 'N4', 'Ohne Antwort des Servers kein Update', async () => {
		await steuern( u, { update_antwort: '' } );
		await u.php( `delete_site_transient( 'update_plugins' ); echo 'ok';` );
		const roh = await u.php( `
			wp_update_plugins();
			$t = get_site_transient( 'update_plugins' );
			$treffer = array();
			foreach ( (array) ( $t->response ?? array() ) as $datei => $eintrag ) {
				if ( false !== strpos( $datei, 'gutenberg-formbuilder' ) ) {
					$treffer[] = $eintrag->new_version ?? '?';
				}
			}
			echo empty( $treffer ) ? 'keins' : implode( ',', $treffer );
		` );
		return soll.gleich( roh.trim(), 'keins', 'Angebotene Version ohne Serverantwort' );
	} );

	await s.punkt( 'N5', 'Kein Fremd-Update von WordPress.org', async () => {
		const roh = await u.php( `
			$daten = get_plugin_data( WP_PLUGIN_DIR . '/gutenberg-formbuilder/gutenberg-formbuilder.php', false, false );
			echo isset( $daten['UpdateURI'] ) ? $daten['UpdateURI'] : '';
		` );
		return soll.enthaelt( roh, 'blitzdonner', 'Angabe «Update URI» im Plugin-Kopf' );
	} );

	await s.punkt( 'N6', 'Kein Zugriff nach draussen ohne Schalter', async () => {
		// Die Teststeuerung blockt Anfragen an den Update-Server, wenn keine
		// Antwort hinterlegt ist. Bleibt der Vermerk aus, hat etwas telefoniert.
		const roh = await u.php( `
			$antwort = wp_remote_get( 'https://plugins.blitzdonner.ch/bd-updater/check/gutenberg-formbuilder' );
			echo is_wp_error( $antwort ) ? 'geblockt' : 'durchgelassen';
		` );
		return soll.gleich( roh.trim(), 'geblockt', 'Zugriff auf den Update-Server' );
	} );
}
