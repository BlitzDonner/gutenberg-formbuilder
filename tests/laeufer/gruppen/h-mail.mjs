// Gruppe H – Mailversand. Geprüft wird im Mailfänger des Containers.
import { soll } from '../lib/pruefung.mjs';
import { formularHolen, absenden } from '../lib/http.mjs';
import { steuern } from '../lib/wp.mjs';
import { Mailfaenger } from '../lib/mailpit.mjs';

// Das Widget legt dieses Feld im Browser an; im Skript setzen wir es selbst.
// Ob es gilt, entscheidet die nachgestellte Antwort des Anbieters.
const CAPTCHA = { 'frc-captcha-response': 'testlauf-loesung' };

function werte( zusatz = {} ) {
	const marke = Math.random().toString( 36 ).slice( 2, 10 );
	return {
		vorname: `Mira-${ marke }`,
		nachname: 'Beispiel',
		mail: `mira-${ marke }@example.test`,
		telefon: '+41 33 111 22 33',
		nachricht: `Mailprüfung ${ marke }`,
		zahl: '3',
		auswahl: 'Eins',
		anrede: 'Frau',
		zustimmung: '1',
		...zusatz,
	};
}

export default async function gruppeH( u, s ) {
	s.gruppe( 'H – Mailversand' );
	const post = u.post;

	let betriebsmails = [];
	await s.punkt( 'H1', 'Betriebsmail: Zustellung', async () => {
		await post.leeren();
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, werte() );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		betriebsmails = await post.warten( 1, 25 );
		if ( ! betriebsmails.length ) return 'Keine Betriebsmail angekommen.';
		const voll = await Promise.all( betriebsmails.map( ( m ) => post.mail( m.ID ) ) );
		const adressen = voll.flatMap( ( m ) => ( m.To || [] ).map( ( e ) => e.Address ) );
		const fehlend = [ 'betrieb1@example.test', 'betrieb2@example.test', 'betrieb3@example.test' ]
			.filter( ( a ) => ! adressen.includes( a ) );
		return soll.wahr( fehlend.length === 0, `Diese Empfänger fehlen: ${ fehlend.join( ', ' ) }` );
	} );

	await s.punkt( 'H2', 'Betriebsmail: Feldbezeichnungen', async () => {
		const alle = await post.alle();
		if ( ! alle.length ) throw new Error( 'Keine Mail im Fänger.' );
		const k = Mailfaenger.kurz( alle[ 0 ] );
		const inhalt = k.text + k.html;
		for ( const label of [ 'Vorname', 'Nachname', 'Nachricht' ] ) {
			const treffer = soll.enthaelt( inhalt, label, 'Mailinhalt' );
			if ( treffer !== true ) return treffer;
		}
		return soll.enthaeltNicht( inhalt, 'field-text', 'Mailinhalt' );
	} );

	await s.punkt( 'H3', 'Betriebsmail: vertrauliches Feld', async () => {
		const alle = await post.alle();
		const k = Mailfaenger.kurz( alle[ 0 ] );
		return soll.enthaeltNicht( k.text + k.html, '+41 33 111 22 33', 'Mailinhalt (Telefon ist vertraulich)' );
	} );

	await s.punkt( 'H4', 'Betriebsmail: hochgeladene Datei', async () => {
		const alle = await post.alle();
		const k = Mailfaenger.kurz( alle[ 0 ] );
		return soll.wahr( k.anhaenge.length === 0, `Anhänge gefunden: ${ k.anhaenge.join( ', ' ) }` );
	} );

	await s.punkt( 'H5', 'Betriebsmail: Antwort-Adresse', async () => {
		const alle = await post.alle();
		const k = Mailfaenger.kurz( alle[ 0 ] );
		return soll.enthaelt( k.antwortAn || k.text, '@example.test', 'Antwort-Adresse' );
	} );

	await s.punkt( 'H6', 'Sofort-Bestätigung: Zustellung', async () => {
		await steuern( u, { captcha: 'pass' } );
		await post.leeren();
		const eigene = werte();
		const f = await formularHolen( u, '/gfbt-instant/', 'gfbt_instant' );
		const e = await absenden( u, f, { ...eigene, ...CAPTCHA } );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const anPerson = await post.wartenAuf( ( k ) => k.an.includes( eigene.mail ), 1, 30 );
		return soll.wahr( anPerson.length === 1, `Mails an die ausfüllende Person: ${ anPerson.length }` );
	} );

	await s.punkt( 'H7', 'Sofort-Bestätigung ohne Spam-Schutz', async () => {
		await steuern( u, { captcha: '' } );
		await post.leeren();
		const eigene = werte();
		const f = await formularHolen( u, '/gfbt-instant/', 'gfbt_instant' );
		const e = await absenden( u, f, { ...eigene, ...CAPTCHA } );
		// Ohne Captcha-Nachweis darf keine Bestätigung an die Person gehen.
		const mails = await post.warten( 1, 8 );
		const voll = await Promise.all( mails.map( ( m ) => post.mail( m.ID ) ) );
		const anPerson = voll.map( Mailfaenger.kurz ).filter( ( k ) => k.an.includes( eigene.mail ) );
		if ( anPerson.length > 0 ) return 'Bestätigung ging trotz fehlendem Spam-Schutz raus.';
		return true;
	} );

	await s.punkt( 'H8', 'Sofort-Bestätigung, Prüfung nicht bestanden', async () => {
		await steuern( u, { captcha: 'fail' } );
		await post.leeren();
		const eigene = werte();
		const f = await formularHolen( u, '/gfbt-instant/', 'gfbt_instant' );
		const e = await absenden( u, f, { ...eigene, ...CAPTCHA } );
		return soll.wahr(
			e.zustand !== 'success',
			`Einsendung ging durch, obwohl der Spam-Schutz ablehnte (Zustand ${ e.zustand }).`
		);
	} );

	// Double-Opt-in, vollständiger Ablauf.
	let doiLink = '';
	await s.punkt( 'H13', 'Double-Opt-in: Link-Mail', async () => {
		await steuern( u, { captcha: 'pass' } );
		await post.leeren();
		const eigene = werte();
		const f = await formularHolen( u, '/gfbt-doi/', 'gfbt_doi' );
		const e = await absenden( u, f, { ...eigene, ...CAPTCHA } );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const gefunden = await post.wartenAuf( ( k ) => k.an.includes( eigene.mail ), 1, 30 );
		const anPerson = gefunden[ 0 ];
		if ( ! anPerson ) return 'Keine Mail an die ausfüllende Person.';
		const treffer = ( anPerson.text + ' ' + anPerson.html ).match(
			/https?:\/\/[^\s"'<>]*gfb-bestaetigung[^\s"'<>]*/
		);
		doiLink = treffer ? treffer[ 0 ].replaceAll( '&amp;', '&' ) : '';
		u.doiMail = anPerson;
		u.doiWerte = eigene;
		return soll.wahr( !! doiLink, 'Kein Bestätigungslink in der Mail gefunden.' );
	} );

	await s.punkt( 'H14', 'Double-Opt-in: Datensparsamkeit', async () => {
		if ( ! u.doiMail ) throw s.uebersprungen( 'H13 lieferte keine Mail.' );
		const inhalt = u.doiMail.text + u.doiMail.html;
		for ( const wert of [ u.doiWerte.vorname, u.doiWerte.telefon, u.doiWerte.nachricht ] ) {
			const treffer = soll.enthaeltNicht( inhalt, wert, 'Link-Mail' );
			if ( treffer !== true ) return treffer;
		}
		return true;
	} );

	await s.punkt( 'H15', 'Double-Opt-in: Linkziel', async () => {
		if ( ! doiLink ) throw s.uebersprungen( 'H13 lieferte keinen Link.' );
		return soll.enthaeltNicht( doiLink, '/wp-admin', 'Bestätigungslink' );
	} );

	await s.punkt( 'H16', 'Bestätigungsseite aufrufen', async () => {
		if ( ! doiLink ) throw s.uebersprungen( 'H13 lieferte keinen Link.' );
		const antwort = await fetch( doiLink );
		const html = await antwort.text();
		u.doiSeite = html;
		if ( ! antwort.ok ) return `Seite lieferte ${ antwort.status }.`;
		return soll.enthaeltNicht( html, u.doiWerte.telefon, 'Bestätigungsseite' );
	} );

	await s.punkt( 'H17', 'Bestätigen', async () => {
		if ( ! u.doiSeite ) throw s.uebersprungen( 'H16 lieferte keine Seite.' );
		const formular = u.doiSeite.slice( u.doiSeite.indexOf( '<form' ), u.doiSeite.indexOf( '</form>' ) + 7 );
		if ( ! formular.includes( '<form' ) ) return 'Kein Bestätigungsformular auf der Seite.';
		const daten = new URLSearchParams();
		for ( const treffer of formular.matchAll( /<input\b[^>]*>/g ) ) {
			const name = ( treffer[ 0 ].match( /\bname="([^"]*)"/ ) || [] )[ 1 ];
			const wert = ( treffer[ 0 ].match( /\bvalue="([^"]*)"/ ) || [] )[ 1 ] || '';
			if ( name ) daten.append( name, wert.replaceAll( '&amp;', '&' ) );
		}
		const ziel = ( formular.match( /\baction="([^"]*)"/ ) || [] )[ 1 ]?.replaceAll( '&amp;', '&' ) || doiLink;
		const antwort = await fetch( ziel, {
			method: 'POST',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: daten,
		} );
		const html = await antwort.text();
		u.doiBestaetigt = antwort.ok;
		return soll.wahr(
			antwort.ok && ! /nicht m(ö|oe)glich/i.test( html ),
			`Bestätigung lieferte ${ antwort.status }.`
		);
	} );

	await s.punkt( 'H18', 'Empfangsmail nach der Bestätigung', async () => {
		if ( ! u.doiBestaetigt ) throw s.uebersprungen( 'H17 hat nicht bestätigt.' );
		const anPerson = await post.wartenAuf( ( k ) => k.an.includes( u.doiWerte.mail ), 2, 30 );
		return soll.wahr( anPerson.length >= 2, `Mails an die Person: ${ anPerson.length }, erwartet 2.` );
	} );

	await s.punkt( 'H20', 'Link zweimal bestätigen', async () => {
		if ( ! doiLink ) throw s.uebersprungen( 'H13 lieferte keinen Link.' );
		const antwort = await fetch( doiLink );
		const html = await antwort.text();
		const formular = html.slice( html.indexOf( '<form' ), html.indexOf( '</form>' ) + 7 );
		if ( ! formular.includes( '<form' ) ) return true; // kein Formular mehr: bereits abgelehnt
		const daten = new URLSearchParams();
		for ( const treffer of formular.matchAll( /<input\b[^>]*>/g ) ) {
			const name = ( treffer[ 0 ].match( /\bname="([^"]*)"/ ) || [] )[ 1 ];
			const wert = ( treffer[ 0 ].match( /\bvalue="([^"]*)"/ ) || [] )[ 1 ] || '';
			if ( name ) daten.append( name, wert.replaceAll( '&amp;', '&' ) );
		}
		const zweite = await fetch( doiLink, {
			method: 'POST',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: daten,
		} );
		const text = await zweite.text();
		return soll.wahr(
			/nicht m(ö|oe)glich|abgelaufen|ung(ü|ue)ltig/i.test( text ),
			'Zweite Bestätigung wurde nicht abgelehnt.'
		);
	} );

	await s.punkt( 'H21', 'Token verändert', async () => {
		if ( ! doiLink ) throw s.uebersprungen( 'H13 lieferte keinen Link.' );
		const kaputt = doiLink.slice( 0, -1 ) + ( doiLink.endsWith( 'a' ) ? 'b' : 'a' );
		const antwort = await fetch( kaputt );
		const html = await antwort.text();
		return soll.enthaeltNicht( html, 'Adresse bestätigt', 'Antwort auf den verfälschten Link' );
	} );

	await s.punkt( 'H27', 'Zeilenumbruch im E-Mail-Feld', async () => {
		await post.leeren();
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, werte( { mail: 'test@example.test\nBcc: heimlich@example.test' } ) );
		const mails = await post.warten( 1, 10 );
		const voll = await Promise.all( mails.map( ( m ) => post.mail( m.ID ) ) );
		const heimlich = voll.some( ( m ) => JSON.stringify( m ).includes( 'heimlich@example.test' ) );
		return soll.wahr( ! heimlich, 'Eingeschmuggelte Empfängeradresse ist in der Mail gelandet.' );
	} );

	await s.punkt( 'H29', 'Umlaute und Emojis', async () => {
		await post.leeren();
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, werte( { vorname: 'Zoë Grüezi 🇨🇭', nachricht: 'Grüsse aus Thun – schön!' } ) );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const mails = await post.warten( 1, 20 );
		const voll = await Promise.all( mails.map( ( m ) => post.mail( m.ID ) ) );
		const inhalt = voll.map( ( m ) => Mailfaenger.kurz( m ) ).map( ( k ) => k.betreff + k.text + k.html ).join( '' );
		return soll.enthaelt( inhalt, 'Grüsse aus Thun', 'Mailinhalt' );
	} );

	await steuern( u, { captcha: '' } );
}
