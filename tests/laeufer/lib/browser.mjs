// Browser-Zugriff für die Prüfungen, die eine echte Seite brauchen.
import { chromium } from 'playwright';
import path from 'node:path';
import fs from 'node:fs/promises';

export class Browser {
	constructor( umgebung, ausgabe ) {
		this.u = umgebung;
		this.ausgabe = path.join( ausgabe, 'bilder' );
		this.browser = null;
		this.kontext = null;
	}

	async starten() {
		await fs.mkdir( this.ausgabe, { recursive: true } );
		this.browser = await chromium.launch();
		this.kontext = await this.browser.newContext( {
			viewport: { width: 1280, height: 900 },
			locale: 'de-CH',
		} );
		await this.anmelden();
	}

	async anmelden() {
		// Der erste Aufruf nach dem Start kann langsam sein: einmal wiederholen.
		for ( let versuch = 1; versuch <= 2; versuch++ ) {
			const seite = await this.kontext.newPage();
			try {
				await seite.goto( `${ this.u.basis }/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 60000 } );
				await seite.fill( '#user_login', 'chef' );
				await seite.fill( '#user_pass', 'chef-geheim' );
				await Promise.all( [
					seite.waitForURL( /wp-admin/, { timeout: 60000 } ),
					seite.click( '#wp-submit' ),
				] );
				await seite.close();
				return;
			} catch ( fehler ) {
				await seite.close().catch( () => {} );
				if ( versuch === 2 ) throw fehler;
				await new Promise( ( r ) => setTimeout( r, 3000 ) );
			}
		}
	}

	/**
	 * Öffnet eine Seite und sammelt dabei alles, was die Konsole meldet.
	 *
	 * @param {string} pfad Pfad ab der Wurzel.
	 * @return {Promise<{seite:object,meldungen:string[]}>}
	 */
	async oeffnen( pfad, { warten = 'domcontentloaded' } = {} ) {
		const seite = await this.kontext.newPage();
		const meldungen = [];
		seite.on( 'console', ( m ) => {
			if ( [ 'error', 'warning' ].includes( m.type() ) ) {
				meldungen.push( `${ m.type() }: ${ m.text() }` );
			}
		} );
		seite.on( 'pageerror', ( f ) => meldungen.push( `pageerror: ${ f.message }` ) );
		await seite.goto( `${ this.u.basis }${ pfad }`, { waitUntil: warten, timeout: 60000 } );
		return { seite, meldungen };
	}

	/** Öffnet den Block-Editor für einen Beitrag und wartet, bis er steht. */
	async editor( postId ) {
		const { seite, meldungen } = await this.oeffnen( `/wp-admin/post.php?post=${ postId }&action=edit` );
		await seite.waitForFunction(
			() => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ),
			{ timeout: 60000 }
		);
		// Warten, bis der Inhalt geladen ist.
		await seite.waitForFunction(
			() => window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0,
			{ timeout: 60000 }
		).catch( () => {} );
		await seite.waitForTimeout( 1500 );
		return { seite, meldungen };
	}

	/**
	 * Öffnet eine zweite Sitzung als andere Person. Für die Rechteprüfung:
	 * nur so zeigt sich, ob die Admin-Seiten wirklich sperren.
	 *
	 * @param {string} benutzer Anmeldename.
	 * @param {string} passwort Kennwort.
	 * @return {Promise<object>} Browser-Kontext.
	 */
	async alsPerson( benutzer, passwort ) {
		const kontext = await this.browser.newContext( { viewport: { width: 1280, height: 900 }, locale: 'de-CH' } );
		const seite = await kontext.newPage();
		await seite.goto( `${ this.u.basis }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await seite.fill( '#user_login', benutzer );
		await seite.fill( '#user_pass', passwort );
		await Promise.all( [
			seite.waitForURL( /wp-admin|wp-login/, { timeout: 30000 } ),
			seite.click( '#wp-submit' ),
		] );
		await seite.close();
		return kontext;
	}

	/** Legt ein Bild im Berichtsordner ab und liefert den Dateinamen. */
	async bild( seite, name ) {
		const datei = path.join( this.ausgabe, `${ this.u.kennung }-${ name }.png` );
		await seite.screenshot( { path: datei, fullPage: false } );
		return path.basename( datei );
	}

	async schliessen() {
		await this.kontext?.close();
		await this.browser?.close();
	}
}
