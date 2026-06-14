/**
 * Friendly-Captcha Lazy-Loader (Datensparsamkeit by default).
 *
 * Laedt das offizielle Friendly-Captcha-SDK ERST, wenn der Nutzer zum ersten
 * Mal mit dem Formular interagiert (Feld-Fokus, Klick oder Eingabe). Das ist
 * reine Datensparsamkeit: kein Vorab-Aufruf an Friendly Captcha beim
 * Seitenaufbau (B2). Es gibt KEIN eager-Laden, KEINEN Vorab-Ping. Nur der
 * Site-Key steht im Markup (vom Server gesetzt). Das Secret bleibt
 * serverseitig.
 */
( function () {
	'use strict';

	var cfg = typeof window.gfbCaptchaConfig !== 'undefined' ? window.gfbCaptchaConfig : null;
	if ( ! cfg || ! cfg.scriptUrl ) {
		return;
	}

	var loaderState = 'idle'; // idle | loading | loaded
	var scriptPromise = null;

	/**
	 * Laedt das SDK-Modul genau einmal. Verwendet type="module", async/defer,
	 * exakt wie in der offiziellen Doku beschrieben.
	 *
	 * @return {Promise<void>}
	 */
	function loadSdk() {
		if ( scriptPromise ) {
			return scriptPromise;
		}
		loaderState = 'loading';
		scriptPromise = new Promise( function ( resolve, reject ) {
			var existing = document.querySelector( 'script[data-gfb-captcha-sdk="1"]' );
			if ( existing ) {
				loaderState = 'loaded';
				resolve();
				return;
			}
			var s = document.createElement( 'script' );
			s.type = 'module';
			s.async = true;
			s.defer = true;
			s.src = cfg.scriptUrl;
			s.setAttribute( 'data-gfb-captcha-sdk', '1' );
			s.onload = function () {
				loaderState = 'loaded';
				resolve();
			};
			s.onerror = function () {
				// Skript laedt nicht: im soft-Modus bleibt das Formular nutzbar
				// (serverseitiges Fallback). Wir reagieren hier nicht hart.
				loaderState = 'idle';
				reject( new Error( 'gfb-captcha-sdk-load-failed' ) );
			};
			document.head.appendChild( s );
		} );
		return scriptPromise;
	}

	/**
	 * Sammelt alle Widget-Container, die noch nicht freigeschaltet wurden.
	 *
	 * @return {Array<Element>}
	 */
	function pendingWidgets() {
		var nodes = document.querySelectorAll( '.gfb-captcha-row[data-gfb-captcha="1"]:not([data-gfb-captcha-armed="1"])' );
		return Array.prototype.slice.call( nodes );
	}

	/**
	 * Markiert die Widgets als scharf und laedt das SDK. Das site.min.js
	 * erzeugt fuer jedes .frc-captcha-Element automatisch ein Widget.
	 */
	function arm() {
		var rows = pendingWidgets();
		if ( rows.length === 0 ) {
			return;
		}
		rows.forEach( function ( row ) {
			row.setAttribute( 'data-gfb-captcha-armed', '1' );
		} );
		loadSdk().catch( function () {} );
	}

	/**
	 * Verdrahtet den verzoegerten Lade-Ausloeser pro Formular: das SDK laedt
	 * erst nach der ersten Formular-Interaktion (Feld-Fokus, Klick oder
	 * Eingabe). Vor diesem Punkt gibt es keinen Aufruf an Friendly Captcha.
	 *
	 * @param {HTMLFormElement} form Formular.
	 */
	function wireForm( form ) {
		var row = form.querySelector( '.gfb-captcha-row[data-gfb-captcha="1"]' );
		if ( ! row ) {
			return;
		}

		var triggered = false;
		function onFirstInteraction() {
			if ( triggered ) {
				return;
			}
			triggered = true;
			form.removeEventListener( 'focusin', onFirstInteraction );
			form.removeEventListener( 'pointerdown', onFirstInteraction );
			form.removeEventListener( 'input', onFirstInteraction );
			arm();
		}
		form.addEventListener( 'focusin', onFirstInteraction );
		form.addEventListener( 'pointerdown', onFirstInteraction );
		form.addEventListener( 'input', onFirstInteraction );
	}

	function init() {
		var forms = document.querySelectorAll( 'form.gfb-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			if ( form.querySelector( '.gfb-captcha-row[data-gfb-captcha="1"]' ) ) {
				wireForm( form );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
