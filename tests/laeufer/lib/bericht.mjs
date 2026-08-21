// Erzeugt den HTML-Bericht: eine Zeile je Prüfpunkt, eine Spalte je Umgebung.
import fs from 'node:fs/promises';
import path from 'node:path';

const FARBE = { gruen: 'gruen', rot: 'rot', grau: 'grau' };
const ZEICHEN = { gruen: 'bestanden', rot: 'Fehler', grau: 'offen' };

function schuetzen( s ) {
	return String( s ?? '' )
		.replaceAll( '&', '&amp;' )
		.replaceAll( '<', '&lt;' )
		.replaceAll( '>', '&gt;' );
}

export async function berichtSchreiben( ergebnisse, ordner ) {
	const umgebungen = ergebnisse.map( ( e ) => e.umgebung );

	// Alle Prüfpunkte über alle Umgebungen sammeln, Reihenfolge der ersten Umgebung führt.
	const reihen = new Map();
	for ( const e of ergebnisse ) {
		for ( const p of e.punkte ) {
			const schluessel = `${ p.gruppe }|${ p.nr }`;
			if ( ! reihen.has( schluessel ) ) {
				reihen.set( schluessel, { nr: p.nr, gruppe: p.gruppe, titel: p.titel, zellen: {} } );
			}
			reihen.get( schluessel ).zellen[ e.umgebung ] = p;
		}
	}

	const nachGruppe = new Map();
	for ( const r of reihen.values() ) {
		if ( ! nachGruppe.has( r.gruppe ) ) nachGruppe.set( r.gruppe, [] );
		nachGruppe.get( r.gruppe ).push( r );
	}

	const gesamt = { gruen: 0, rot: 0, grau: 0 };
	for ( const e of ergebnisse ) {
		gesamt.gruen += e.zahlen.gruen;
		gesamt.rot += e.zahlen.rot;
		gesamt.grau += e.zahlen.grau;
	}

	const zeit = new Date().toLocaleString( 'de-CH', { timeZone: 'Europe/Zurich' } );

	let html = `<title>Testreihe Formular</title>
<style>
:root { --grund:#fff; --text:#16181d; --gedaempft:#5b6270; --linie:#e3e6ec; --karte:#f7f8fa;
  --gruen:#0d7a3f; --rot:#c02626; --grau:#8a8f99; --gruen-bg:#e8f6ed; --rot-bg:#fdeaea; --grau-bg:#f0f1f4; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {
  --grund:#14161a; --text:#e8eaee; --gedaempft:#a0a7b4; --linie:#2a2f38; --karte:#1c1f25;
  --gruen:#5fd3ac; --rot:#ff7b72; --grau:#8a8f99; --gruen-bg:#12301f; --rot-bg:#3a1b1b; --grau-bg:#23262d; } }
:root[data-theme="dark"] { --grund:#14161a; --text:#e8eaee; --gedaempft:#a0a7b4; --linie:#2a2f38; --karte:#1c1f25;
  --gruen:#5fd3ac; --rot:#ff7b72; --grau:#8a8f99; --gruen-bg:#12301f; --rot-bg:#3a1b1b; --grau-bg:#23262d; }
*{box-sizing:border-box} body{background:var(--grund);color:var(--text);margin:0;padding:0 1.2rem 5rem;
  font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5;-webkit-font-smoothing:antialiased}
.bahn{max-width:70rem;margin:0 auto}
header{padding:3rem 0 1.5rem;border-bottom:2px solid var(--text);margin-bottom:1.5rem}
h1{margin:0 0 .3rem;font-size:clamp(1.6rem,4vw,2.2rem)} .unter{color:var(--gedaempft);margin:0}
.zahlen{display:flex;gap:1.8rem;flex-wrap:wrap;margin-top:1.4rem}
.zahl b{display:block;font-size:1.9rem;line-height:1.1} .zahl span{color:var(--gedaempft);font-size:.85rem}
h2{font-size:1.15rem;margin:2.2rem 0 .5rem;padding-top:.7rem;border-top:1px solid var(--linie)}
.rahmen{overflow-x:auto} table{border-collapse:collapse;width:100%;font-size:.92rem;min-width:44rem}
th,td{text-align:left;padding:.45rem .6rem;border-bottom:1px solid var(--linie);vertical-align:top}
th{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--gedaempft)}
td.nr{color:var(--gedaempft);white-space:nowrap;width:3rem;font-variant-numeric:tabular-nums}
td.zelle{width:9rem}
.pille{display:inline-block;font-size:.75rem;font-weight:600;padding:.1rem .5rem;border-radius:999px}
.gruen{color:var(--gruen);background:var(--gruen-bg)} .rot{color:var(--rot);background:var(--rot-bg)}
.grau{color:var(--grau);background:var(--grau-bg)}
.grund{display:block;color:var(--gedaempft);font-size:.8rem;margin-top:.2rem;white-space:pre-wrap}
.schalter{position:fixed;top:1rem;right:1rem;background:var(--karte);color:var(--text);border:1px solid var(--linie);
  border-radius:999px;padding:.45rem 1rem;cursor:pointer;font:inherit;font-size:.85rem}
.bilder{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:1rem;margin-bottom:1rem}
.bilder figure{margin:0} .bilder img{width:100%;border:1px solid var(--linie);border-radius:6px;display:block}
.bilder figcaption{color:var(--gedaempft);font-size:.8rem;margin-top:.3rem}
footer{margin-top:3rem;padding-top:1rem;border-top:1px solid var(--linie);color:var(--gedaempft);font-size:.88rem}
@media(max-width:640px){.schalter{position:static;margin-top:1rem}}
</style>
<button class="schalter" onclick="var r=document.documentElement;r.dataset.theme=r.dataset.theme==='dark'?'light':'dark'">Hell / Dunkel</button>
<div class="bahn">
<header>
<h1>Testreihe Blitz &amp; Donner Formular</h1>
<p class="unter">${ schuetzen( zeit ) } · ${ ergebnisse.map( ( e ) => `${ e.umgebung }: WordPress ${ e.wpVersion || '?' }` ).join( ' · ' ) }</p>
<div class="zahlen">
<div class="zahl"><b>${ gesamt.gruen }</b><span>bestanden</span></div>
<div class="zahl"><b>${ gesamt.rot }</b><span>Fehler</span></div>
<div class="zahl"><b>${ gesamt.grau }</b><span>offen</span></div>
<div class="zahl"><b>${ reihen.size }</b><span>Prüfpunkte je Umgebung</span></div>
</div>
</header>\n`;

	for ( const [ gruppe, zeilen ] of nachGruppe ) {
		html += `<h2>${ schuetzen( gruppe ) }</h2><div class="rahmen"><table><thead><tr><th>Nr.</th><th>Prüfpunkt</th>`;
		for ( const um of umgebungen ) html += `<th>${ schuetzen( um ) }</th>`;
		html += '</tr></thead><tbody>';
		for ( const z of zeilen ) {
			html += `<tr><td class="nr">${ schuetzen( z.nr ) }</td><td>${ schuetzen( z.titel ) }</td>`;
			for ( const um of umgebungen ) {
				const p = z.zellen[ um ];
				if ( ! p ) {
					html += '<td class="zelle"><span class="pille grau">nicht gelaufen</span></td>';
					continue;
				}
				html += `<td class="zelle"><span class="pille ${ FARBE[ p.status ] }">${ ZEICHEN[ p.status ] }</span>`;
				if ( p.meldung ) html += `<span class="grund">${ schuetzen( p.meldung ) }</span>`;
				html += '</td>';
			}
			html += '</tr>';
		}
		html += '</tbody></table></div>';
	}

	// Screenshots ans Ende, nach Umgebung gruppiert.
	const bilder = await fs.readdir( path.join( ordner, 'bilder' ) ).catch( () => [] );
	if ( bilder.length ) {
		html += '<h2>Belege</h2><div class="rahmen">';
		for ( const um of umgebungen ) {
			const eigene = bilder.filter( ( b ) => b.startsWith( `${ um }-` ) ).sort();
			if ( ! eigene.length ) continue;
			html += `<h3 style="margin:1.2rem 0 .5rem;font-size:1rem">${ schuetzen( um ) }</h3><div class="bilder">`;
			for ( const b of eigene ) {
				const titel = b.replace( `${ um }-`, '' ).replace( '.png', '' );
				html += `<figure><a href="bilder/${ b }" target="_blank" rel="noopener">` +
					`<img src="bilder/${ b }" alt="${ schuetzen( titel ) }" loading="lazy"></a>` +
					`<figcaption>${ schuetzen( titel ) }</figcaption></figure>`;
			}
			html += '</div>';
		}
		html += '</div>';
	}

	html += `<footer>Erzeugt von tests/lauf.sh. Rohdaten in ergebnisse.json im selben Ordner.</footer></div>`;

	const ziel = path.join( ordner, 'bericht.html' );
	await fs.writeFile( ziel, html );
	return ziel;
}
