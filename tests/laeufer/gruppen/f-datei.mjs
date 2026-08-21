// Gruppe F und G – Datei-Upload und Verschlüsselung.
//
// Aus dem Code gelesen: Datei- und Virenfehler kommen nicht als eigener Code
// zurück, sondern als err_validation mit Detailtext (class-gfb-submit-handler.php,
// Auswertung von $errors). Geprüft wird deshalb Code plus Meldung.
import { soll } from '../lib/pruefung.mjs';
import { formularHolen, absenden } from '../lib/http.mjs';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HIER = path.dirname( fileURLToPath( import.meta.url ) );
const DATEIEN = path.join( HIER, '..', '..', 'fixtures', 'dateien' );

async function datei( name, typ ) {
	return { name, typ, inhalt: await fs.readFile( path.join( DATEIEN, name ) ) };
}

function werte( zusatz = {} ) {
	const marke = Math.random().toString( 36 ).slice( 2, 10 );
	return {
		vorname: `Datei-${ marke }`,
		mail: `datei-${ marke }@example.test`,
		telefon: '+41 33 444 55 66',
		...zusatz,
	};
}

/** Sendet eine Datei am Formular «voll» und liefert das Ergebnis. */
async function hochladen( u, dateiname, typ = 'application/octet-stream' ) {
	const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
	return absenden( u, f, werte(), { dateien: { ausweis: await datei( dateiname, typ ) } } );
}

export default async function gruppeF( u, s ) {
	s.gruppe( 'F – Datei-Upload' );

	await s.punkt( 'F1', 'Echtes PDF', async () => {
		const e = await hochladen( u, 'ausweis.pdf', 'application/pdf' );
		if ( e.zustand !== 'success' ) {
			return `Abgewiesen mit «${ e.code }»: ${ decodeURIComponent( e.meldung || '' ) }`;
		}
		const anzahl = await u.php( `
			global $wpdb;
			echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gfb_files" );
		` );
		return soll.wahr( parseInt( anzahl, 10 ) > 0, 'Keine Datei in der Ablage.' );
	} );

	await s.punkt( 'F2', 'Echtes JPEG', async () => {
		const e = await hochladen( u, 'bild.jpg', 'image/jpeg' );
		return soll.gleich( e.zustand, 'success', 'Zustand' );
	} );

	await s.punkt( 'F3', 'PHP-Datei', async () => {
		const e = await hochladen( u, 'schad.php', 'application/x-php' );
		return soll.wahr( e.zustand === 'error', 'Eine PHP-Datei wurde angenommen.' );
	} );

	await s.punkt( 'F4', 'PHP-Inhalt mit Endung .pdf', async () => {
		const e = await hochladen( u, 'getarnt.pdf', 'application/pdf' );
		return soll.wahr( e.zustand === 'error', 'Eine getarnte PHP-Datei wurde angenommen.' );
	} );

	await s.punkt( 'F5', 'Doppelte Endung .pdf.php', async () => {
		const e = await hochladen( u, 'doppelt.pdf.php', 'application/pdf' );
		return soll.wahr( e.zustand === 'error', 'Eine Datei mit doppelter Endung wurde angenommen.' );
	} );

	await s.punkt( 'F6', 'Datei ohne Endung', async () => {
		const e = await hochladen( u, 'ohne-endung', 'text/plain' );
		return soll.wahr( e.zustand === 'error', 'Eine Datei ohne Endung wurde angenommen.' );
	} );

	await s.punkt( 'F8', 'Datei über der Grössengrenze', async () => {
		const e = await hochladen( u, 'gross.pdf', 'application/pdf' );
		if ( e.zustand !== 'error' ) return 'Eine 3 MB grosse Datei wurde trotz Grenze von 2 MB angenommen.';
		// PHP selbst bricht schon bei upload_max_filesize ab, dann meldet das
		// Plugin «Upload fehlgeschlagen» statt «zu gross». Beide Wege sind richtig.
		const meldung = decodeURIComponent( e.meldung || '' );
		return soll.wahr(
			/gross|fehlgeschlagen/i.test( meldung ),
			`Meldung: ${ meldung.slice( 0, 120 ) }`
		);
	} );

	await s.punkt( 'F10', 'Nur erlaubte Endungen', async () => {
		// Das Feld erlaubt .pdf, .jpg, .png – eine Textdatei gehört nicht dazu.
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, werte(), {
			dateien: { ausweis: { name: 'notiz.txt', typ: 'text/plain', inhalt: 'nur text' } },
		} );
		return soll.wahr( e.zustand === 'error', 'Eine nicht erlaubte Endung wurde angenommen.' );
	} );

	await s.punkt( 'F11', 'EICAR-Testdatei', async () => {
		const clamav = await u.php( `echo wp_json_encode( GFB_Clamav::get_settings() );` );
		if ( /"mode":"disabled"/.test( clamav ) ) {
			throw s.uebersprungen( 'Kein Virenscanner in dieser Umgebung eingerichtet.' );
		}
		const e = await hochladen( u, 'eicar.txt', 'text/plain' );
		return soll.wahr( e.zustand === 'error', 'Die Testdatei für Virenscanner wurde angenommen.' );
	} );

	await s.punkt( 'F14', 'Ablage der angenommenen Datei', async () => {
		const pfad = await u.php( `
			global $wpdb;
			$zeile = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}gfb_files ORDER BY id DESC LIMIT 1", ARRAY_A );
			echo wp_json_encode( $zeile ?: array() );
		` );
		const zeile = JSON.parse( pfad || '{}' );
		if ( ! zeile.id ) return 'Keine Datei in der Ablage.';
		const spalte = Object.keys( zeile ).find( ( k ) => /path|pfad|file/.test( k ) && typeof zeile[ k ] === 'string' );
		const roh = await u.imWp( 'ls -l $(find /var/www/html/wp-content/.gfb-private -type f | head -1) 2>/dev/null || echo fehlt' );
		if ( roh.includes( 'fehlt' ) ) return 'Im privaten Ordner liegt keine Datei.';
		const rechte = roh.trim().split( /\s+/ )[ 0 ];
		return soll.wahr(
			/^-rw-------/.test( rechte ),
			`Dateirechte: ${ rechte } (erwartet -rw-------), Spalte ${ spalte }`
		);
	} );

	await s.punkt( 'F15', 'Direktaufruf der abgelegten Datei', async () => {
		const relativ = await u.imWp(
			'find /var/www/html/wp-content/.gfb-private -type f | head -1 | sed "s|/var/www/html||"'
		);
		const pfad = relativ.trim();
		if ( ! pfad ) throw s.uebersprungen( 'Keine abgelegte Datei gefunden.' );
		const antwort = await fetch( `${ u.basis }${ pfad }` );
		return soll.wahr(
			antwort.status === 403 || antwort.status === 404,
			`HTTP-Status ${ antwort.status } beim Direktaufruf.`
		);
	} );

	await s.punkt( 'F16', 'Dateiname bleibt lesbar', async () => {
		const roh = await u.php( `
			global $wpdb;
			$zeile = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}gfb_files ORDER BY id DESC LIMIT 1", ARRAY_A );
			echo wp_json_encode( $zeile ?: array() );
		` );
		const zeile = JSON.parse( roh || '{}' );
		return soll.wahr(
			typeof zeile.original_name === 'string' && /\.[a-z0-9]{2,4}$/i.test( zeile.original_name ),
			`Gespeicherter Dateiname: ${ zeile.original_name || '(keiner)' }`
		);
	} );

	s.gruppe( 'G – Verschlüsselung' );

	await s.punkt( 'G1', 'Vertrauliches Feld in der Datenbank', async () => {
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const geheim = `+41 33 ${ Date.now().toString().slice( -6 ) }`;
		const e = await absenden( u, f, werte( { telefon: geheim } ) );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const treffer = await u.php( `
			global $wpdb;
			$roh   = (string) $wpdb->get_var( "SELECT payload FROM {$wpdb->prefix}gfb_submissions ORDER BY id DESC LIMIT 1" );
			$daten = json_decode( $roh, true );
			$wert  = isset( $daten['telefon'] ) ? $daten['telefon'] : null;
			if ( false !== strpos( $roh, '${ geheim }' ) ) {
				echo 'klartext';
			} elseif ( is_array( $wert ) && isset( $wert['ct'], $wert['iv'], $wert['key_id'] ) ) {
				echo 'huelle';
			} else {
				echo 'unbekannt: ' . substr( wp_json_encode( $wert ), 0, 80 );
			}
		` );
		return soll.gleich( treffer.trim(), 'huelle', 'Zustand in der Datenbank' );
	} );

	await s.punkt( 'G2', 'Entschlüsselung mit Recht', async () => {
		const roh = await u.php( `
			global $wpdb;
			$zeile = $wpdb->get_row( "SELECT payload FROM {$wpdb->prefix}gfb_submissions ORDER BY id DESC LIMIT 1", ARRAY_A );
			$daten = json_decode( (string) $zeile['payload'], true );
			$wert  = isset( $daten['telefon'] ) ? $daten['telefon'] : '';
			$klar  = GFB_Crypto::decrypt_field( $wert, 'field:telefon' );
			echo ( false === $klar ) ? 'ABGELEHNT' : $klar;
		` );
		return soll.enthaelt( roh, '+41', 'Entschlüsselter Wert' );
	} );

	await s.punkt( 'G8', 'Bindung an Feld und Formular', async () => {
		const roh = await u.php( `
			global $wpdb;
			$zeile = $wpdb->get_row( "SELECT payload FROM {$wpdb->prefix}gfb_submissions ORDER BY id DESC LIMIT 1", ARRAY_A );
			$daten = json_decode( (string) $zeile['payload'], true );
			$wert  = isset( $daten['telefon'] ) ? $daten['telefon'] : '';
			// decrypt_field wirft nicht, es liefert false.
			$klar  = GFB_Crypto::decrypt_field( $wert, 'field:ein_anderes_feld' );
			echo ( false === $klar ) ? 'abgelehnt' : 'ENTSCHLUESSELT: ' . $klar;
		` );
		return soll.gleich( roh.trim(), 'abgelehnt', 'Entschlüsselung mit falscher Bindung' );
	} );

	await s.punkt( 'G4', 'Datei-Hülle', async () => {
		// Über PHP statt über xxd: das Werkzeug fehlt im Container.
		const anfang = await u.php( `
			$treffer = glob( WP_CONTENT_DIR . '/.gfb-private/*/*/*' );
			if ( empty( $treffer ) ) { echo 'keine'; return; }
			echo bin2hex( (string) file_get_contents( $treffer[0], false, null, 0, 8 ) );
		` );
		if ( anfang.trim() === 'keine' ) throw s.uebersprungen( 'Keine abgelegte Datei gefunden.' );
		// «%PDF» wäre 25504446, «JFIF» stünde bei ffd8ff.
		if ( /^25504446/.test( anfang.trim() ) || /^ffd8ff/.test( anfang.trim() ) ) {
			return `Die Datei liegt im Klartext: beginnt mit ${ anfang.trim() }`;
		}
		return true;
	} );

	await s.punkt( 'G10', 'Ohne Hauptschlüssel', async () => {
		const zustand = await u.php( `
			echo wp_json_encode( array(
				'konstante' => defined( 'GFB_MASTER_KEYS' ) && '' !== (string) GFB_MASTER_KEYS,
				'bereit'    => class_exists( 'GFB_Crypto' ),
			) );
		` );
		const d = JSON.parse( zustand.trim() );
		return soll.wahr( d.konstante, 'Der Hauptschlüssel ist in dieser Umgebung nicht gesetzt.' );
	} );
}
