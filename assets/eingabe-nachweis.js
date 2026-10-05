/**
 * Eingabe-Nachweis: eigener Spam-Schutz ohne Fremddienst.
 *
 * Startet erst nach der ersten echten Interaktion im Formular (focusin,
 * pointerdown, keydown, input; jeweils event.isTrusted). Danach sucht das
 * Skript zu einem eigenen Zufallssalz eine Zahl, sodass
 * SHA-256(Aufgabe + "." + Salz + "." + Zahl) mit der vom Server signierten
 * Anzahl Null-Bits beginnt, und schreibt «Salz.Zahl» ins versteckte Feld.
 *
 * SHA-256 laeuft synchron in reinem JavaScript statt ueber crypto.subtle:
 * subtle.digest ist pro Aufruf asynchron und fuer zehntausende kleine
 * Hashes um ein Vielfaches langsamer; zudem fehlt es ausserhalb von HTTPS.
 * Der konstante Anfang (Aufgabe.Salz.) wird einmal vorgerechnet.
 *
 * Kein Netzwerkaufruf. Die Person sieht nichts; sendet sie ab, bevor die
 * Rechnung fertig ist, wartet das Skript und sendet dann selbst.
 */
( function () {
	'use strict';

	var K = new Uint32Array( [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
	] );
	var H0 = [ 0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19 ];
	var W = new Uint32Array( 64 );

	/**
	 * Verarbeitet einen 64-Byte-Block ab bytes[ off ] in den Zustand h.
	 *
	 * @param {Uint32Array} h     Zustand (8 Woerter), wird veraendert.
	 * @param {Uint8Array}  bytes Daten.
	 * @param {number}      off   Startindex.
	 */
	function compress( h, bytes, off ) {
		var i, t1, t2, s0, s1;
		for ( i = 0; i < 16; i++ ) {
			var j = off + i * 4;
			W[ i ] = ( bytes[ j ] << 24 ) | ( bytes[ j + 1 ] << 16 ) | ( bytes[ j + 2 ] << 8 ) | bytes[ j + 3 ];
		}
		for ( i = 16; i < 64; i++ ) {
			var w15 = W[ i - 15 ];
			var w2 = W[ i - 2 ];
			s0 = ( ( w15 >>> 7 ) | ( w15 << 25 ) ) ^ ( ( w15 >>> 18 ) | ( w15 << 14 ) ) ^ ( w15 >>> 3 );
			s1 = ( ( w2 >>> 17 ) | ( w2 << 15 ) ) ^ ( ( w2 >>> 19 ) | ( w2 << 13 ) ) ^ ( w2 >>> 10 );
			W[ i ] = ( W[ i - 16 ] + s0 + W[ i - 7 ] + s1 ) | 0;
		}
		var a = h[ 0 ], b = h[ 1 ], c = h[ 2 ], d = h[ 3 ], e = h[ 4 ], f = h[ 5 ], g = h[ 6 ], hh = h[ 7 ];
		for ( i = 0; i < 64; i++ ) {
			s1 = ( ( e >>> 6 ) | ( e << 26 ) ) ^ ( ( e >>> 11 ) | ( e << 21 ) ) ^ ( ( e >>> 25 ) | ( e << 7 ) );
			t1 = ( hh + s1 + ( ( e & f ) ^ ( ~e & g ) ) + K[ i ] + W[ i ] ) | 0;
			s0 = ( ( a >>> 2 ) | ( a << 30 ) ) ^ ( ( a >>> 13 ) | ( a << 19 ) ) ^ ( ( a >>> 22 ) | ( a << 10 ) );
			t2 = ( s0 + ( ( a & b ) ^ ( a & c ) ^ ( b & c ) ) ) | 0;
			hh = g;
			g = f;
			f = e;
			e = ( d + t1 ) | 0;
			d = c;
			c = b;
			b = a;
			a = ( t1 + t2 ) | 0;
		}
		h[ 0 ] = ( h[ 0 ] + a ) | 0;
		h[ 1 ] = ( h[ 1 ] + b ) | 0;
		h[ 2 ] = ( h[ 2 ] + c ) | 0;
		h[ 3 ] = ( h[ 3 ] + d ) | 0;
		h[ 4 ] = ( h[ 4 ] + e ) | 0;
		h[ 5 ] = ( h[ 5 ] + f ) | 0;
		h[ 6 ] = ( h[ 6 ] + g ) | 0;
		h[ 7 ] = ( h[ 7 ] + hh ) | 0;
	}

	/** ASCII-Text in Bytes (Aufgabe und Salz bestehen nur aus ASCII). */
	function ascii( s ) {
		var out = new Uint8Array( s.length );
		for ( var i = 0; i < s.length; i++ ) {
			out[ i ] = s.charCodeAt( i ) & 0xff;
		}
		return out;
	}

	/** 16 Zufallsbytes als Hex. */
	function salz() {
		var b = new Uint8Array( 16 );
		var c = window.crypto || window.msCrypto;
		if ( c && c.getRandomValues ) {
			c.getRandomValues( b );
		} else {
			for ( var i = 0; i < 16; i++ ) {
				b[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		var hex = '';
		for ( var j = 0; j < 16; j++ ) {
			hex += ( b[ j ] < 16 ? '0' : '' ) + b[ j ].toString( 16 );
		}
		return hex;
	}

	/**
	 * Bereitet die Suche vor: Mittelzustand ueber die vollen 64-Byte-Bloecke
	 * des konstanten Anfangs, Rest fuer den variablen Teil.
	 */
	function vorbereiten( prefix ) {
		var bytes = ascii( prefix );
		var full = Math.floor( bytes.length / 64 ) * 64;
		var mid = new Uint32Array( H0 );
		for ( var off = 0; off < full; off += 64 ) {
			compress( mid, bytes, off );
		}
		return {
			mid: mid,
			rest: bytes.subarray( full ),
			total: bytes.length,
		};
	}

	/**
	 * Erstes Hash-Wort von SHA-256( prefix + nonce ). Mehr braucht die
	 * Pruefung nicht, weil hoechstens 24 Null-Bits verlangt werden.
	 */
	var buf = new Uint8Array( 192 );
	var state = new Uint32Array( 8 );
	function erstesWort( vb, nonce ) {
		var digits = String( nonce );
		var n = 0;
		var i;
		for ( i = 0; i < vb.rest.length; i++ ) {
			buf[ n++ ] = vb.rest[ i ];
		}
		for ( i = 0; i < digits.length; i++ ) {
			buf[ n++ ] = digits.charCodeAt( i );
		}
		var msgLen = vb.total + digits.length;
		buf[ n++ ] = 0x80;
		var blocks = n + 8 <= 64 ? 1 : 2;
		var end = blocks * 64;
		while ( n < end - 8 ) {
			buf[ n++ ] = 0;
		}
		var bitLen = msgLen * 8;
		buf[ n++ ] = 0;
		buf[ n++ ] = 0;
		buf[ n++ ] = 0;
		buf[ n++ ] = 0;
		buf[ n++ ] = ( bitLen >>> 24 ) & 0xff;
		buf[ n++ ] = ( bitLen >>> 16 ) & 0xff;
		buf[ n++ ] = ( bitLen >>> 8 ) & 0xff;
		buf[ n++ ] = bitLen & 0xff;
		state.set( vb.mid );
		for ( i = 0; i < blocks; i++ ) {
			compress( state, buf, i * 64 );
		}
		return state[ 0 ] >>> 0;
	}

	/**
	 * Sucht die Loesung in Zeitscheiben von rund 12 ms, damit die Seite
	 * bedienbar bleibt.
	 *
	 * @return {Promise<string>} «Salz.Zahl»
	 */
	function loesen( aufgabe, bits ) {
		return new Promise( function ( resolve ) {
			var s = salz();
			var vb = vorbereiten( aufgabe + '.' + s + '.' );
			var shift = 32 - bits;
			var nonce = 0;
			function scheibe() {
				var bis = Date.now() + 12;
				do {
					for ( var k = 0; k < 500; k++ ) {
						if ( ( erstesWort( vb, nonce ) >>> shift ) === 0 ) {
							resolve( s + '.' + nonce );
							return;
						}
						nonce++;
					}
				} while ( Date.now() < bis );
				window.setTimeout( scheibe, 0 );
			}
			scheibe();
		} );
	}

	/**
	 * Verdrahtet ein Formular.
	 *
	 * @param {HTMLFormElement} form
	 */
	function verdrahten( form ) {
		var aufgabeFeld = form.querySelector( 'input[data-gfb-nw-aufgabe="1"]' );
		var loesungFeld = form.querySelector( 'input[data-gfb-nw-loesung="1"]' );
		if ( ! aufgabeFeld || ! loesungFeld ) {
			return;
		}
		var teile = String( aufgabeFeld.value ).split( '.' );
		var bits = parseInt( teile[ 2 ], 10 );
		if ( teile.length !== 4 || ! ( bits >= 8 && bits <= 24 ) ) {
			return;
		}

		// Der Server verlangt mindestens 3 Sekunden zwischen Rendern und
		// Absenden. Wer schneller absendet (etwa mit Autofill), wartet kurz,
		// statt abgewiesen zu werden. Gemessen ab dem Laden der Seite; die
		// Aufgabe ist immer aelter, die Rechnung damit auf der sicheren Seite.
		var geladen = Date.now();
		var MINDESTZEIT = 3300;

		var arbeit = null;
		function starten() {
			if ( ! arbeit ) {
				arbeit = loesen( aufgabeFeld.value, bits ).then( function ( wert ) {
					loesungFeld.value = wert;
					var rest = MINDESTZEIT - ( Date.now() - geladen );
					return new Promise( function ( resolve ) {
						window.setTimeout( function () {
							resolve( wert );
						}, rest > 0 ? rest : 0 );
					} );
				} );
			}
			return arbeit;
		}

		var ereignisse = [ 'focusin', 'pointerdown', 'keydown', 'input' ];
		function beiInteraktion( ev ) {
			if ( ! ev.isTrusted ) {
				return;
			}
			ereignisse.forEach( function ( typ ) {
				form.removeEventListener( typ, beiInteraktion, true );
			} );
			starten();
		}
		ereignisse.forEach( function ( typ ) {
			form.addEventListener( typ, beiInteraktion, true );
		} );

		form.gfbNachweis = {
			bereit: function () {
				return '' !== loesungFeld.value && Date.now() - geladen >= MINDESTZEIT;
			},
			gestartet: function () {
				return null !== arbeit;
			},
			starten: starten,
		};
	}

	/**
	 * Faengt das Absenden ab, solange die Loesung fehlt. Der Listener sitzt
	 * im Capture-Weg auf document und laeuft vor den Listenern am Formular.
	 * Er verhindert nur das Absenden; die uebrigen Listener laufen weiter,
	 * damit das Sende-Overlay aus frontend.js sofort erscheint und der Knopf
	 * gesperrt ist. Ist die Loesung da, sendet das Skript per nativem
	 * submit(), das kein zweites submit-Ereignis ausloest.
	 */
	document.addEventListener(
		'submit',
		function ( ev ) {
			var form = ev.target;
			if ( ! form || ! form.gfbNachweis || form.gfbNachweis.bereit() ) {
				return;
			}
			// Ein Absenden, das kein Mensch ausgeloest hat, startet nichts.
			if ( ! form.gfbNachweis.gestartet() && ! ev.isTrusted ) {
				return;
			}
			ev.preventDefault();
			if ( form.gfbNachweisWartet ) {
				return;
			}
			form.gfbNachweisWartet = true;
			form.gfbNachweis.starten().then( function () {
				HTMLFormElement.prototype.submit.call( form );
			} );
		},
		true
	);

	function init() {
		var forms = document.querySelectorAll( 'form.gfb-form' );
		Array.prototype.forEach.call( forms, verdrahten );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
