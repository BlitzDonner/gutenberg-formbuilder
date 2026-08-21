// Steuerung der Testreihe: richtet jede Umgebung ein und führt die Gruppen aus.
import fs from 'node:fs/promises';
import path from 'node:path';
import { umgebungenLesen } from './lib/docker.mjs';
import { Sammler } from './lib/pruefung.mjs';
import { einrichten, protokollLeeren, steuerungZuruecksetzen, zaehlerLoeschen } from './lib/wp.mjs';
import { Mailfaenger } from './lib/mailpit.mjs';
import { berichtSchreiben } from './lib/bericht.mjs';

import { Browser } from './lib/browser.mjs';
import { seitenBauen, seitenOhneBrowser } from './lib/seiten.mjs';

import gruppeA from './gruppen/a-umgebung.mjs';
import gruppeB from './gruppen/b-editor.mjs';
import gruppeI from './gruppen/i-backend.mjs';
import gruppeO from './gruppen/o-aussehen.mjs';
import gruppeC from './gruppen/c-entwuerfe.mjs';
import gruppeJ from './gruppen/j-rechte.mjs';
import gruppeK from './gruppen/k-sprachen.mjs';
import gruppeM from './gruppen/m-umstieg.mjs';
import gruppeZ from './gruppen/z-aufraeumen.mjs';
import gruppeE from './gruppen/e-abwehr.mjs';
import gruppeH from './gruppen/h-mail.mjs';
import gruppeF from './gruppen/f-datei.mjs';

// { fn, browser: true } heisst: braucht eine echte Seite.
const GRUPPEN = [
	{ fn: gruppeA, browser: false },
	{ fn: gruppeE, browser: false },
	{ fn: gruppeH, browser: false },
	{ fn: gruppeF, browser: false },
	{ fn: gruppeB, browser: true },
	{ fn: gruppeI, browser: true },
	{ fn: gruppeO, browser: true },
	{ fn: gruppeC, browser: true },
	{ fn: gruppeJ, browser: true },
	{ fn: gruppeK, browser: false },
	// Muss zuletzt laufen: entfernt Tabellen und Dateien wirklich.
	{ fn: gruppeZ, browser: false },
];

const OHNE_BROWSER = process.env.GFB_OHNE_BROWSER === '1';

const datei = process.argv[ 2 ];
const ausgabe = process.env.GFB_AUSGABE || path.dirname( datei );
const umgebungen = umgebungenLesen( await fs.readFile( datei, 'utf8' ) );

console.log( `\nTestreihe – ${ umgebungen.length } Umgebung(en)\n` );

const ergebnisse = await Promise.all(
	umgebungen.map( async ( u ) => {
		const s = new Sammler( u.kennung );
		try {
			console.log( `▸ ${ u.kennung }: einrichten …` );
			await einrichten( u );
			await protokollLeeren( u );
			await steuerungZuruecksetzen( u );
			u.post = new Mailfaenger( u.mailpit );
			// E7 zählt selbst hoch und braucht den Zähler.
			s.vorPunkt = async ( nr ) => {
				if ( nr !== 'E7' ) await zaehlerLoeschen( u );
			};
			let browser = null;
			if ( ! OHNE_BROWSER && 'umstieg' !== u.kennung ) {
				browser = new Browser( u, ausgabe );
				await browser.starten();
			}

			// Die Seiten baut der Editor selbst, sonst wäre ihr Markup ungültig.
			// In der Umstiegs-Umgebung legt sie Gruppe M an, nach der Vorversion.
			if ( 'umstieg' !== u.kennung ) {
				u.aufbau = { seiten: browser ? await seitenBauen( u, browser ) : await seitenOhneBrowser( u ) };
			} else {
				u.aufbau = { seiten: {} };
			}
			console.log( `▸ ${ u.kennung }: bereit, ${ Object.keys( u.aufbau.seiten ).length - 1 } Testformulare\n` );
			try {
				if ( 'umstieg' === u.kennung ) {
					await gruppeM( u, s );
				} else {
					for ( const gruppe of GRUPPEN ) {
						if ( gruppe.browser && ! browser ) continue;
						await gruppe.fn( u, s, browser );
					}
				}
			} finally {
				await browser?.schliessen();
			}
		} catch ( fehler ) {
			s.punkte.push( {
				nr: '—',
				gruppe: 'Abbruch',
				titel: 'Umgebung nicht nutzbar',
				status: 'rot',
				meldung: fehler.message,
				dauerMs: 0,
			} );
			console.error( `✕ ${ u.kennung }: ${ fehler.message }` );
		}
		return { umgebung: u.kennung, abbild: u.abbild, wpVersion: u.wpVersion || '?', hinweis: u.hinweis || '', punkte: s.punkte, zahlen: s.zahlen };
	} )
);

await fs.writeFile( path.join( ausgabe, 'ergebnisse.json' ), JSON.stringify( ergebnisse, null, 2 ) );
const berichtPfad = await berichtSchreiben( ergebnisse, ausgabe );

console.log( '\n────────────────────────────' );
for ( const e of ergebnisse ) {
	console.log( `${ e.umgebung.padEnd( 8 ) } ${ e.zahlen.gruen } grün · ${ e.zahlen.rot } rot · ${ e.zahlen.grau } offen` );
}
console.log( `\nBericht: ${ berichtPfad }` );

const rot = ergebnisse.reduce( ( n, e ) => n + e.zahlen.rot, 0 );
process.exit( rot > 0 ? 1 : 0 );
