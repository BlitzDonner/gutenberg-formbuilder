// Zugriff auf die Container einer Umgebung.
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ausfuehren = promisify( execFile );
const HIER = path.dirname( fileURLToPath( import.meta.url ) );
const COMPOSE = path.join( HIER, '..', '..', 'docker', 'compose.yml' );

export class Umgebung {
	constructor( { kennung, abbild, wpPort, mailpitPort } ) {
		this.kennung = kennung;
		this.abbild = abbild;
		this.basis = `http://localhost:${ wpPort }`;
		this.mailpit = `http://localhost:${ mailpitPort }`;
		this.projekt = `gfbtest-${ kennung }`;
	}

	async compose( ...args ) {
		const { stdout } = await ausfuehren(
			'docker',
			[ 'compose', '-p', this.projekt, '-f', COMPOSE, ...args ],
			{ maxBuffer: 32 * 1024 * 1024 }
		);
		return stdout;
	}

	/** Führt einen Befehl im WordPress-Container aus. */
	async imWp( befehl ) {
		return this.compose( 'exec', '-T', 'wp', 'bash', '-lc', befehl );
	}

	/** Führt WP-CLI im dafür vorgesehenen Container aus. Wirft bei Fehler. */
	async wp( args, { rohtext = false } = {} ) {
		const ausgabe = await this.compose(
			'exec', '-T', 'cli',
			'wp', '--path=/var/www/html', ...zerlegen( args )
		);
		return rohtext ? ausgabe : ausgabe.trim();
	}

	/** Führt PHP im WordPress-Kontext aus und gibt die Ausgabe zurück. */
	async php( code ) {
		const b64 = Buffer.from( `<?php\n${ code }`, 'utf8' ).toString( 'base64' );
		await this.compose(
			'exec', '-T', 'wp', 'bash', '-lc',
			`echo ${ b64 } | base64 -d > /var/www/html/gfb-eval.php`
		);
		try {
			return ( await this.compose(
				'exec', '-T', 'cli', 'wp', '--path=/var/www/html', 'eval-file', '/var/www/html/gfb-eval.php'
			) ).trim();
		} finally {
			await this.compose( 'exec', '-T', 'wp', 'rm', '-f', '/var/www/html/gfb-eval.php' ).catch( () => {} );
		}
	}

	/** Wartet, bis WordPress über HTTP antwortet. */
	async warten( sekunden = 120 ) {
		const ende = Date.now() + sekunden * 1000;
		while ( Date.now() < ende ) {
			try {
				const antwort = await fetch( this.basis, { redirect: 'manual' } );
				if ( antwort.status < 500 ) return true;
			} catch {
				/* noch nicht da */
			}
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
		}
		throw new Error( `${ this.kennung }: WordPress antwortet nach ${ sekunden } s nicht.` );
	}
}

/**
 * Zerlegt eine WP-CLI-Zeile in Argumente. Anführungszeichen halten zusammen,
 * auch mitten im Argument: --title="GFB Testreihe" bleibt ein Stück.
 */
function zerlegen( zeile ) {
	if ( Array.isArray( zeile ) ) return zeile;
	const teile = zeile.match( /(?:[^\s"']+|"[^"]*"|'[^']*')+/g ) || [];
	return teile.map( ( t ) => t.replace( /"([^"]*)"|'([^']*)'/g, ( _, a, b ) => a ?? b ) );
}

export function umgebungenLesen( zeilen ) {
	return zeilen
		.split( '\n' )
		.map( ( z ) => z.trim() )
		.filter( Boolean )
		.map( ( z ) => {
			const [ kennung, abbild, wpPort, mailpitPort ] = z.split( '|' );
			return new Umgebung( { kennung, abbild, wpPort, mailpitPort } );
		} );
}
