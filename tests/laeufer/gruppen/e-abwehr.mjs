// Gruppe E – Absenden und Abwehr. Jeder Fehlerzustand einzeln herbeigeführt.
import { soll } from '../lib/pruefung.mjs';
import { formularHolen, absenden } from '../lib/http.mjs';
import { steuern, einsendungenZaehlen, letzteEinsendung } from '../lib/wp.mjs';

const SEITE = '/gfbt-voll/';
const FORM = 'gfbt_voll';

/** Erzeugt eindeutige Werte, damit der Doppel-Schutz nicht dazwischenfunkt. */
function werte( zusatz = {} ) {
	const marke = Math.random().toString( 36 ).slice( 2, 10 );
	return {
		vorname: `Vera-${ marke }`,
		nachname: 'Muster',
		mail: `vera-${ marke }@example.test`,
		telefon: '+41 33 222 11 00',
		nachricht: `Testeinsendung ${ marke }`,
		zahl: '5',
		auswahl: 'Zwei',
		anrede: 'Frau',
		...zusatz,
	};
}

export default async function gruppeE( u, s ) {
	s.gruppe( 'E – Absenden und Abwehr' );

	// Häufigkeits-Schutz für die übrigen Punkte weit hochsetzen.
	await steuern( u, { rate_limit_max: 999 } );

	await s.punkt( 'E1', 'Vollständige Einsendung', async () => {
		const vorher = await einsendungenZaehlen( u, FORM );
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, werte() );
		const nachher = await einsendungenZaehlen( u, FORM );
		if ( e.zustand !== 'success' ) {
			return `Zustand «${ e.zustand }», Code «${ e.code }», erwartet success.`;
		}
		return soll.gleich( nachher, vorher + 1, 'Anzahl Einsendungen' );
	} );

	await s.punkt( 'E2', 'Ohne Sicherheitsschlüssel (Nonce)', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, werte(), { ohne: [ 'gfb_nonce' ] } );
		return soll.gleich( e.code, 'err_nonce', 'Fehlercode' );
	} );

	await s.punkt( 'E3', 'Gefälschter Token', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, { ...werte(), gfb_token: 'abc.def.ungueltig' } );
		return soll.gleich( e.code, 'err_token', 'Fehlercode' );
	} );

	await s.punkt( 'E4', 'Token älter als eine Stunde', async () => {
		throw s.uebersprungen( 'Braucht eine Wartezeit von einer Stunde; wird über den Kurzweg E3 mitgeprüft.' );
	} );

	await s.punkt( 'E5', 'Abgesendet in unter zwei Sekunden', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, werte(), { warten: 0 } );
		// Das Mindestalter gehört zur Tokenprüfung, deshalb err_token.
		return soll.wahr(
			[ 'err_token', 'err_spam' ].includes( e.code ),
			`Fehlercode «${ e.code }», erwartet err_token oder err_spam.`
		);
	} );

	await s.punkt( 'E6', 'Honigtopf-Feld befüllt', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const vorher = await einsendungenZaehlen( u, FORM );
		const e = await absenden( u, f, { ...werte(), [ f.honigtopf ]: 'Robotertext' } );
		const nachher = await einsendungenZaehlen( u, FORM );
		if ( e.code !== 'err_spam' ) return `Fehlercode «${ e.code }», erwartet err_spam.`;
		return soll.gleich( nachher, vorher, 'Anzahl Einsendungen (darf nicht steigen)' );
	} );

	await s.punkt( 'E7', 'Sechste Einsendung in zehn Minuten', async () => {
		await steuern( u, { rate_limit_max: 5 } );
		let letzterCode = '';
		for ( let i = 1; i <= 6; i++ ) {
			const f = await formularHolen( u, SEITE, FORM );
			const e = await absenden( u, f, werte(), { warten: i === 1 ? 2200 : 2100 } );
			letzterCode = e.code || e.zustand;
			if ( i < 6 && e.zustand !== 'success' ) {
				await steuern( u, { rate_limit_max: 999 } );
				return `Einsendung ${ i } wurde schon abgewiesen: ${ letzterCode }`;
			}
		}
		await steuern( u, { rate_limit_max: 999 } );
		return soll.gleich( letzterCode, 'err_rate', 'Fehlercode der sechsten Einsendung' );
	} );

	await s.punkt( 'E8', 'Formularschema fehlt', async () => {
		// Der echte Fall: Das Formular wird aus der Seite entfernt, während ein
		// Browser die alte Fassung noch offen hat.
		const f = await formularHolen( u, SEITE, FORM );
		// Ohne Formularblock fehlen die Blockattribute, also greift der globale
		// Spam-Schutz. Damit wirklich das Schema geprüft wird, lassen wir ihn passieren.
		await steuern( u, { captcha: 'pass' } );
		const gesichert = await u.php( `
			$id = (int) get_page_by_path( 'gfbt-voll' )->ID;
			$post = get_post( $id );
			update_option( 'gfbt_inhalt_sicherung', $post->post_content, false );
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( '<!-- wp:paragraph --><p>leer</p><!-- /wp:paragraph -->' ) ) );
			echo 'ok';
		` );
		try {
			const e = await absenden( u, f, { ...werte(), 'frc-captcha-response': 'testlauf-loesung' } );
			return soll.gleich( e.code, 'err_schema', 'Fehlercode' );
		} finally {
			await steuern( u, { captcha: '' } );
			await u.php( `
				$id = (int) get_page_by_path( 'gfbt-voll' )->ID;
				wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( get_option( 'gfbt_inhalt_sicherung' ) ) ) );
				delete_option( 'gfbt_inhalt_sicherung' );
				echo 'ok';
			` );
		}
	} );

	await s.punkt( 'E9', 'Zwei Felder mit gleichem Namen', async () => {
		// err_duplicate meldet ein fehlerhaftes Formular, nicht eine doppelte Einsendung:
		// zwei Felder mit demselben Namen würden einander überschreiben.
		const f = await formularHolen( u, '/gfbt-doppelt/', 'gfbt_doppelt' );
		const e = await absenden( u, f, { vorname: 'Doppel', mail: 'doppel@example.test' } );
		return soll.gleich( e.code, 'err_duplicate', 'Fehlercode' );
	} );

	await s.punkt( 'E11', 'Dieselbe Einsendung zweimal', async () => {
		// Befund: Das Plugin kennt keine Sperre gegen mehrfaches Absenden desselben
		// Inhalts. Der Punkt hält den Ist-Zustand fest.
		const gleich = werte();
		const f1 = await formularHolen( u, SEITE, FORM );
		const e1 = await absenden( u, f1, gleich );
		if ( e1.zustand !== 'success' ) return `Erste Einsendung scheiterte: ${ e1.code }`;
		const f2 = await formularHolen( u, SEITE, FORM );
		const e2 = await absenden( u, f2, gleich );
		return soll.wahr(
			e2.zustand === 'success',
			`Zweite gleiche Einsendung wurde abgewiesen (${ e2.code }) – bisher war das erlaubt.`
		);
	} );

	await s.punkt( 'E10', 'Pflichtfeld leer', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, { ...werte(), vorname: '' } );
		if ( e.code !== 'err_validation' ) return `Fehlercode «${ e.code }», erwartet err_validation.`;
		return soll.enthaelt( decodeURIComponent( e.meldung || '' ).toLowerCase(), 'vorname', 'Fehlermeldung' );
	} );

	await s.punkt( 'E12', 'Speichern schlägt fehl', async () => {
		await u.php( `
			global $wpdb;
			$wpdb->query( "RENAME TABLE {$wpdb->prefix}gfb_submissions TO {$wpdb->prefix}gfb_submissions_weg" );
			echo 'ok';
		` );
		try {
			const f = await formularHolen( u, SEITE, FORM );
			const e = await absenden( u, f, werte() );
			return soll.gleich( e.code, 'err_persist', 'Fehlercode' );
		} finally {
			await u.php( `
				global $wpdb;
				$wpdb->query( "RENAME TABLE {$wpdb->prefix}gfb_submissions_weg TO {$wpdb->prefix}gfb_submissions" );
				echo 'ok';
			` );
		}
	} );

	await s.punkt( 'E13', 'Fremdsystem lehnt ab', async () => {
		await steuern( u, { external: 'reject' } );
		try {
			const f = await formularHolen( u, SEITE, FORM );
			const e = await absenden( u, f, werte() );
			return soll.gleich( e.code, 'err_external', 'Fehlercode' );
		} finally {
			await steuern( u, { external: '' } );
		}
	} );

	await s.punkt( 'E16', 'Captcha nicht gelöst', async () => {
		const f = await formularHolen( u, '/gfbt-instant/', 'gfbt_instant' );
		const e = await absenden( u, f, werte() );
		return soll.wahr(
			[ 'err_captcha', 'err_captcha_unreachable' ].includes( e.code ),
			`Fehlercode «${ e.code }», erwartet err_captcha.`
		);
	} );

	await s.punkt( 'E18', 'Fehlerhafte Anfrage', async () => {
		const antwort = await fetch( `${ u.basis }/wp-admin/admin-post.php`, {
			method: 'POST',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: 'action=gfb_submit',
			redirect: 'manual',
		} );
		const ziel = antwort.headers.get( 'location' ) || '';
		return soll.wahr(
			ziel.includes( 'err_request' ) || ziel.includes( 'err_nonce' ) || antwort.status === 400,
			`Antwort ${ antwort.status }, Ziel «${ ziel }».`
		);
	} );

	await s.punkt( 'E19', 'Unbekanntes Feld mitgeschickt', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, { ...werte(), voellig_unbekannt: 'egal' } );
		return soll.gleich( e.zustand, 'success', 'Zustand' );
	} );

	await s.punkt( 'E20', 'Token an fremdes Formular', async () => {
		const fremd = await formularHolen( u, '/gfbt-farben/', 'gfbt_farben' );
		const eigen = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, eigen, { ...werte(), gfb_token: fremd.felder.gfb_token } );
		return soll.gleich( e.code, 'err_token', 'Fehlercode' );
	} );

	await s.punkt( 'E23', 'Angriffs-Eingabe wird gespeichert, nicht ausgeführt', async () => {
		const f = await formularHolen( u, SEITE, FORM );
		const e = await absenden( u, f, werte( { nachricht: '<script>alert(1)</script> und \' OR 1=1 --' } ) );
		if ( e.zustand !== 'success' ) return `Zustand «${ e.zustand }», erwartet success.`;
		const eintrag = await letzteEinsendung( u );
		return soll.enthaeltNicht( JSON.stringify( eintrag ), '<script>alert(1)</script>', 'Gespeicherter Wert' );
	} );
}
