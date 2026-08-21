// Gruppe M – Umstieg von der Vorversion. Läuft nur in der Umgebung «umstieg»,
// weil dort das Plugin nicht fest eingehängt ist und WP-CLI Versionen einspielen kann.
import { soll } from '../lib/pruefung.mjs';
import { formularHolen, absenden } from '../lib/http.mjs';
import { seitenOhneBrowser } from '../lib/seiten.mjs';

export default async function gruppeM( u, s ) {
	if ( 'umstieg' !== u.kennung ) return;
	s.gruppe( 'M – Umstieg von der Vorversion' );

	let alteVersion = '';

	await s.punkt( 'M1', 'Vorversion installieren', async () => {
		await u.wp( 'plugin deactivate gutenberg-formbuilder' ).catch( () => {} );
		await u.wp( 'plugin install /gfb-pakete/vorversion.zip --force' );
		await u.wp( 'plugin activate gutenberg-formbuilder' );
		alteVersion = ( await u.wp( 'plugin get gutenberg-formbuilder --field=version' ) ).trim();
		await u.php( `
			GFB_Captcha::update_settings( array(
				'enabled'  => true,
				'site_key' => 'TESTLAUF-SITEKEY',
				'api_key'  => 'TESTLAUF-APIKEY',
			) );
			echo 'ok';
		` ).catch( () => {} );
		return soll.wahr( !! alteVersion, 'Die Vorversion liess sich nicht lesen.' );
	} );

	await s.punkt( 'M2', 'Daten unter der Vorversion anlegen', async () => {
		u.aufbau = { seiten: await seitenOhneBrowser( u ) };
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, {
			vorname: 'Umstieg',
			mail: 'umstieg@example.test',
			telefon: '+41 33 999 00 11',
			nachricht: 'Diese Einsendung muss den Wechsel überleben.',
		} );
		if ( e.zustand !== 'success' ) return `Einsendung scheiterte: ${ e.code }`;
		const anzahl = await u.php( `
			global $wpdb;
			echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gfb_submissions" );
		` );
		u.vorherAnzahl = parseInt( anzahl, 10 );
		return soll.wahr( u.vorherAnzahl > 0, 'Keine Einsendung angelegt.' );
	} );

	await s.punkt( 'M3', 'Wechsel auf die neue Fassung', async () => {
		await u.wp( 'plugin install /gfb-pakete/arbeitsstand.zip --force' );
		await u.wp( 'plugin activate gutenberg-formbuilder' ).catch( () => {} );
		const neu = ( await u.wp( 'plugin get gutenberg-formbuilder --field=version' ) ).trim();
		const antwort = await fetch( `${ u.basis }/gfbt-voll/` );
		if ( ! antwort.ok ) return `Die Seite antwortet nach dem Wechsel mit ${ antwort.status }.`;
		return soll.wahr(
			neu !== alteVersion,
			`Version vor dem Wechsel ${ alteVersion }, danach ${ neu } – es hat sich nichts geändert.`
		);
	} );

	await s.punkt( 'M4', 'Einsendungen nach dem Wechsel', async () => {
		const roh = await u.php( `
			global $wpdb;
			$zeile = $wpdb->get_row( "SELECT payload FROM {$wpdb->prefix}gfb_submissions ORDER BY id DESC LIMIT 1", ARRAY_A );
			echo (string) $zeile['payload'];
		` );
		return soll.enthaelt( roh, 'Umstieg', 'Einsendung nach dem Wechsel' );
	} );

	await s.punkt( 'M6', 'Tabellenschema', async () => {
		const roh = await u.php( `
			global $wpdb;
			$fehlt = array();
			foreach ( array( 'gfb_submissions', 'gfb_files', 'gfb_audit' ) as $t ) {
				$name = $wpdb->prefix . $t;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
					$fehlt[] = $t;
				}
			}
			echo empty( $fehlt ) ? 'alle' : implode( ',', $fehlt );
		` );
		return soll.gleich( roh.trim(), 'alle', 'Tabellen nach dem Wechsel' );
	} );

	await s.punkt( 'M7', 'Einstellungen bleiben', async () => {
		const roh = await u.php( `echo wp_json_encode( GFB_Captcha::get_settings() );` );
		return soll.enthaelt( roh, 'TESTLAUF-SITEKEY', 'Einstellungen nach dem Wechsel' );
	} );

	await s.punkt( 'M8', 'Prüfprotokoll ohne Bruch', async () => {
		const roh = await u.php( `
			if ( ! method_exists( 'GFB_Audit', 'verify_chain' ) ) {
				echo 'keine_pruefung';
			} else {
				$ergebnis = GFB_Audit::verify_chain();
				echo wp_json_encode( $ergebnis );
			}
		` );
		if ( roh.trim() === 'keine_pruefung' ) {
			throw s.uebersprungen( 'Das Plugin bietet keine Prüfung der Hash-Kette an.' );
		}
		return soll.enthaeltNicht( roh, '"broken":true', 'Prüfung der Hash-Kette' );
	} );

	await s.punkt( 'M5', 'Neue Einsendung nach dem Wechsel', async () => {
		const f = await formularHolen( u, '/gfbt-voll/', 'gfbt_voll' );
		const e = await absenden( u, f, {
			vorname: 'Nach dem Wechsel',
			mail: 'nachher@example.test',
		} );
		return soll.gleich( e.zustand, 'success', 'Zustand' );
	} );
}
