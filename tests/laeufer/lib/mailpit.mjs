// Zugriff auf den Mailfänger.
export class Mailfaenger {
	constructor( basis ) {
		this.basis = basis;
	}

	async leeren() {
		await fetch( `${ this.basis }/api/v1/messages`, { method: 'DELETE' } );
	}

	async liste() {
		const antwort = await fetch( `${ this.basis }/api/v1/messages?limit=200` );
		const daten = await antwort.json();
		return daten.messages || [];
	}

	/** Wartet, bis mindestens die erwartete Anzahl Mails da ist. */
	async warten( anzahl = 1, sekunden = 20 ) {
		const ende = Date.now() + sekunden * 1000;
		let letzte = [];
		while ( Date.now() < ende ) {
			letzte = await this.liste();
			if ( letzte.length >= anzahl ) return letzte;
			await new Promise( ( r ) => setTimeout( r, 400 ) );
		}
		return letzte;
	}

	/**
	 * Wartet, bis genügend Mails eine Bedingung erfüllen. Zählt nicht bloss
	 * Mails, sondern prüft sie – sonst zählt eine Betriebsmail als Bestätigung.
	 *
	 * @param {(kurz:object)=>boolean} pruefung Prüft eine einzelne Mail.
	 * @param {number}                 anzahl   Erwartete Treffer.
	 * @param {number}                 sekunden Geduld.
	 * @return {Promise<object[]>} Die passenden Mails.
	 */
	async wartenAuf( pruefung, anzahl = 1, sekunden = 25 ) {
		const ende = Date.now() + sekunden * 1000;
		let treffer = [];
		while ( Date.now() < ende ) {
			const alle = await this.alle();
			treffer = alle.map( Mailfaenger.kurz ).filter( pruefung );
			if ( treffer.length >= anzahl ) return treffer;
			await new Promise( ( r ) => setTimeout( r, 600 ) );
		}
		return treffer;
	}

	/** Holt eine Mail vollständig: Kopfzeilen, Text, HTML, Anhänge. */
	async mail( id ) {
		const antwort = await fetch( `${ this.basis }/api/v1/message/${ id }` );
		return antwort.json();
	}

	/** Alle Mails vollständig, jüngste zuerst. */
	async alle() {
		const liste = await this.liste();
		return Promise.all( liste.map( ( m ) => this.mail( m.ID ) ) );
	}

	/** Bequemer Zugriff: Empfänger, Betreff, Text einer Mail. */
	static kurz( mail ) {
		return {
			an: ( mail.To || [] ).map( ( e ) => e.Address ).join( ', ' ),
			von: mail.From?.Address || '',
			antwortAn: ( mail.ReplyTo || [] ).map( ( e ) => e.Address ).join( ', ' ),
			betreff: mail.Subject || '',
			text: mail.Text || '',
			html: mail.HTML || '',
			anhaenge: ( mail.Attachments || [] ).map( ( a ) => a.FileName ),
		};
	}
}
