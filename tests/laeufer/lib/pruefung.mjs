// Sammelt Prüfpunkte. Jeder Punkt trägt seine Nummer aus der abgenommenen Liste.
export class Sammler {
	constructor( kennung ) {
		this.kennung = kennung;
		this.punkte = [];
		this.laufendeGruppe = '';
		// Wird vor jedem Prüfpunkt aufgerufen, um Spuren des Vorgängers zu tilgen.
		this.vorPunkt = null;
	}

	gruppe( name ) {
		this.laufendeGruppe = name;
	}

	/**
	 * Führt einen Prüfpunkt aus. Die Funktion gibt true zurück oder wirft.
	 * Ein zurückgegebener String gilt als Fehlermeldung.
	 */
	async punkt( nr, titel, fn ) {
		const start = Date.now();
		if ( this.vorPunkt ) {
			await this.vorPunkt( nr );
		}
		let status = 'gruen';
		let meldung = '';
		try {
			const ergebnis = await fn();
			if ( ergebnis === false ) {
				status = 'rot';
				meldung = 'Erwartung nicht erfüllt.';
			} else if ( typeof ergebnis === 'string' && ergebnis ) {
				status = 'rot';
				meldung = ergebnis;
			}
		} catch ( fehler ) {
			if ( fehler && fehler.uebersprungen ) {
				status = 'grau';
				meldung = fehler.message;
			} else {
				status = 'rot';
				meldung = ( fehler && fehler.message ) || String( fehler );
			}
		}
		this.punkte.push( {
			nr,
			gruppe: this.laufendeGruppe,
			titel,
			status,
			meldung,
			dauerMs: Date.now() - start,
		} );
		const zeichen = { gruen: '·', rot: '✕', grau: '–' }[ status ];
		process.stdout.write(
			`${ zeichen } ${ this.kennung } ${ nr } ${ titel }${ status === 'rot' ? ` → ${ meldung }` : '' }\n`
		);
		return status;
	}

	uebersprungen( grund ) {
		const f = new Error( grund );
		f.uebersprungen = true;
		return f;
	}

	get zahlen() {
		const z = { gruen: 0, rot: 0, grau: 0 };
		for ( const p of this.punkte ) z[ p.status ]++;
		return z;
	}
}

/** Kleine Behauptungen, die sprechende Meldungen erzeugen. */
export const soll = {
	gleich( ist, erwartet, was = 'Wert' ) {
		if ( String( ist ) !== String( erwartet ) ) {
			return `${ was }: erwartet «${ erwartet }», bekommen «${ ist }».`;
		}
		return true;
	},
	enthaelt( heuhaufen, nadel, was = 'Text' ) {
		if ( ! String( heuhaufen ).includes( nadel ) ) {
			return `${ was } enthält «${ nadel }» nicht. Gefunden: ${ String( heuhaufen ).slice( 0, 200 ) }`;
		}
		return true;
	},
	enthaeltNicht( heuhaufen, nadel, was = 'Text' ) {
		if ( String( heuhaufen ).includes( nadel ) ) {
			return `${ was } enthält «${ nadel }», sollte aber nicht.`;
		}
		return true;
	},
	wahr( bedingung, meldung ) {
		return bedingung ? true : meldung;
	},
};
