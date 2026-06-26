( function () {
	'use strict';

	var DB_NAME = 'gfbDraftsDB';
	var STORE_NAME = 'drafts';
	var DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

	/**
	 * Plugin-Option: Safari/WebKit Text-Fallback für Datumsfelder (1/0).
	 * Formular-Attribut hat Vorrang; Config aus wp_localize_script (nur '1'/'0', kein bool).
	 *
	 * @param {HTMLFormElement|null|undefined} form
	 * @return {boolean}
	 */
	function gfbWebKitDateTimeFallbackEnabled( form ) {
		if ( form ) {
			var attr = form.getAttribute( 'data-gfb-webkit-datetime-fallback' );
			if ( attr === '0' ) {
				return false;
			}
			if ( attr === '1' ) {
				return true;
			}
		}
		var cfg =
			typeof window.gfbFrontendConfig !== 'undefined' ? window.gfbFrontendConfig : null;
		if ( cfg && Object.prototype.hasOwnProperty.call( cfg, 'webkitDateTimeFallback' ) ) {
			var v = cfg.webkitDateTimeFallback;
			return v === true || v === 1 || v === '1' || v === 'true';
		}
		return true;
	}

	/**
	 * Safari / reines WebKit: natives date/time/datetime-local ist oft fehleranfällig (12h-Segmente,
	 * Validierung). Blink- und Gecko-Browser bleiben bei nativen Pickern.
	 *
	 * @param {HTMLFormElement|null|undefined} form
	 * @return {boolean}
	 */
	function gfbWebKitNeedsPlainDateTimeInputs( form ) {
		if ( ! gfbWebKitDateTimeFallbackEnabled( form ) ) {
			return false;
		}
		var ua = typeof navigator !== 'undefined' ? navigator.userAgent || '' : '';
		if ( ! ua ) {
			return false;
		}
		if ( /Firefox\//i.test( ua ) ) {
			return false;
		}
		if ( /Chrome|Chromium|Edg|OPR|CriOS/i.test( ua ) ) {
			return false;
		}
		return /AppleWebKit/i.test( ua );
	}

	/**
	 * Hat der Server einen echten Voreinstellungs-Wert gesetzt (nicht Safaris „heute“-Anzeige)?
	 *
	 * @param {HTMLInputElement} el
	 * @return {boolean}
	 */
	function gfbDateTimeHasServerDefault( el ) {
		return el.getAttribute( 'data-gfb-has-default' ) === '1';
	}

	/**
	 * Ersetzt native Datums-/Zeit-Inputs durch formatierte Textfelder (nur WebKit-Pfad).
	 *
	 * @param {HTMLFormElement} form
	 * @return {void}
	 */
	function gfbUpgradeWebKitDateTimeInputs( form ) {
		if ( ! form || ! gfbWebKitNeedsPlainDateTimeInputs( form ) ) {
			return;
		}
		var sel = 'input[type="date"], input[type="time"], input[type="datetime-local"]';
		form.querySelectorAll( sel ).forEach( function ( el ) {
			var neo = document.createElement( 'input' );
			neo.type = 'text';
			neo.setAttribute( 'data-gfb-wk-fallback', '1' );
			neo.className = ( el.className ? el.className + ' ' : '' ) + 'gfb-input--webkit-fallback';
			neo.name = el.name;
			neo.id = el.id;
			if ( el.required ) {
				neo.required = true;
			}
			neo.setAttribute( 'autocomplete', 'off' );
			neo.setAttribute( 'spellcheck', 'false' );
			var t = el.type;
			var srcPattern = el.getAttribute( 'pattern' );
			var srcPlaceholder = ( el.getAttribute( 'placeholder' ) || '' ).trim();
			if ( t === 'date' ) {
				neo.setAttribute( 'data-gfb-datetime-kind', 'date' );
				neo.pattern = srcPattern || '\\d{4}-\\d{2}-\\d{2}';
				neo.placeholder = srcPlaceholder || 'YYYY-MM-DD';
				neo.maxLength = srcPlaceholder ? srcPlaceholder.length : 10;
				neo.inputMode = 'numeric';
				var dmin = el.getAttribute( 'min' );
				var dmax = el.getAttribute( 'max' );
				if ( dmin ) {
					neo.setAttribute( 'data-gfb-min', dmin );
				}
				if ( dmax ) {
					neo.setAttribute( 'data-gfb-max', dmax );
				}
			} else if ( t === 'time' ) {
				neo.setAttribute( 'data-gfb-datetime-kind', 'time' );
				neo.pattern = srcPattern || '([01]\\d|2[0-3]):[0-5]\\d';
				neo.placeholder = srcPlaceholder || 'HH:MM';
				neo.maxLength = srcPlaceholder ? srcPlaceholder.length : 5;
				neo.inputMode = 'numeric';
			} else {
				neo.setAttribute( 'data-gfb-datetime-kind', 'datetime' );
				neo.pattern = srcPattern || '\\d{4}-\\d{2}-\\d{2}T([01]\\d|2[0-3]):[0-5]\\d';
				neo.placeholder = srcPlaceholder || 'YYYY-MM-DDTHH:MM';
				neo.maxLength = srcPlaceholder ? srcPlaceholder.length : 16;
				neo.inputMode = 'text';
			}
			if ( neo.placeholder ) {
				neo.title = neo.placeholder;
			}
			var hasDefault = gfbDateTimeHasServerDefault( el );
			neo.setAttribute( 'data-gfb-has-default', hasDefault ? '1' : '0' );
			if ( hasDefault ) {
				neo.defaultValue = typeof el.defaultValue === 'string' ? el.defaultValue : '';
				neo.value = el.value || neo.defaultValue || '';
			} else {
				neo.defaultValue = '';
				neo.value = '';
			}
			el.parentNode.replaceChild( neo, el );
		} );
	}

	function openDb() {
		return new Promise( function ( resolve, reject ) {
			if ( ! window.indexedDB ) {
				reject( new Error( 'IndexedDB not supported' ) );
				return;
			}

			var request = window.indexedDB.open( DB_NAME, 1 );
			request.onupgradeneeded = function ( event ) {
				var db = event.target.result;
				if ( ! db.objectStoreNames.contains( STORE_NAME ) ) {
					db.createObjectStore( STORE_NAME, { keyPath: 'key' } );
				}
			};
			request.onsuccess = function () {
				resolve( request.result );
			};
			request.onerror = function () {
				reject( request.error );
			};
		} );
	}

	function txPromise( db, mode, operation ) {
		return new Promise( function ( resolve, reject ) {
			var tx = db.transaction( STORE_NAME, mode );
			var store = tx.objectStore( STORE_NAME );
			var req = operation( store );
			var result = null;
			req.onsuccess = function () {
				result = req.result;
			};
			req.onerror = function () {
				reject( req.error );
			};
			tx.oncomplete = function () {
				resolve( result );
			};
			tx.onerror = function () {
				reject( tx.error );
			};
			tx.onabort = function () {
				reject( tx.error || new Error( 'IndexedDB transaction aborted' ) );
			};
		} );
	}

	function getDraft( db, key ) {
		return txPromise( db, 'readonly', function ( store ) {
			return store.get( key );
		} );
	}

	function setDraft( db, key, payload ) {
		return txPromise( db, 'readwrite', function ( store ) {
			return store.put( {
				key: key,
				updatedAt: Date.now(),
				payload: payload,
			} );
		} );
	}

	function removeDraft( db, key ) {
		return txPromise( db, 'readwrite', function ( store ) {
			return store.delete( key );
		} );
	}

	function collectValues( form ) {
		var values = {};
		var fields = form.querySelectorAll( 'input[name], textarea[name], select[name]' );
		fields.forEach( function ( field ) {
			if ( field.type === 'password' || field.name === 'gfb_nonce' || field.name.indexOf( 'gfb_' ) === 0 ) {
				return;
			}
			if ( field.type === 'file' ) {
				values[ field.name ] = '[Datei]';
				return;
			}
			if ( field.type === 'checkbox' ) {
				values[ field.name ] = field.checked ? '1' : '0';
				return;
			}
			if ( field.type === 'radio' ) {
				if ( field.checked ) {
					values[ field.name ] = field.value;
				}
				return;
			}
			values[ field.name ] = field.value;
		} );
		return values;
	}

	function applyValues( form, values ) {
		var fields = form.querySelectorAll( 'input[name], textarea[name], select[name]' );
		fields.forEach( function ( field ) {
			if ( field.type === 'password' || field.name === 'gfb_nonce' || field.name.indexOf( 'gfb_' ) === 0 ) {
				return;
			}
			if ( typeof values[ field.name ] === 'undefined' ) {
				return;
			}
			if ( field.type === 'checkbox' ) {
				field.checked = values[ field.name ] === '1';
				return;
			}
			if ( field.type === 'radio' ) {
				field.checked = String( values[ field.name ] ) === String( field.value );
				return;
			}
			field.value = values[ field.name ];
			if ( field.type === 'range' ) {
				syncRangeOutput( field );
			}
		} );
	}

	/**
	 * Zeigt den aktuellen Zahlenwert neben dem Schieberegler (output-Element).
	 *
	 * @param {HTMLInputElement} rangeInput
	 */
	function syncRangeOutput( rangeInput ) {
		var wrap = rangeInput.closest( '.gfb-field-range' );
		if ( ! wrap ) {
			return;
		}
		var out = wrap.querySelector( 'output.gfb-range-value' );
		if ( out ) {
			out.textContent = rangeInput.value;
		}
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function initRangeValueDisplays( form ) {
		form.querySelectorAll( '.gfb-field-range input[type="range"]' ).forEach( function ( input ) {
			syncRangeOutput( input );
			input.addEventListener( 'input', function () {
				syncRangeOutput( input );
			} );
		} );
	}

	function shouldClearByUrl( key ) {
		var params = new URLSearchParams( window.location.search );
		var status = params.get( 'gfb_status' );
		var form = params.get( 'gfb_form' );
		if ( status !== 'success' || ! form ) {
			return false;
		}
		var parts = key.split( ':' );
		return parts.indexOf( form ) !== -1;
	}

	/**
	 * Cipher-Animation: morpht Klartext zeichenweise in Chiffre-Zeichen.
	 *
	 * @param {HTMLElement} cipherEl  – .gfb-submit-overlay__cipher
	 */
	function gfbRunCipherAnimation( cipherEl ) {
		var pool = [ '█', '▓', '▒', '░', '●', '◆', '■', '◼', '▪', '◈', '✦' ];
		var texts = [ 'Hallo Stefan', 'Formulardaten', 'Sichere Übertragung', 'Verschlüsselt' ];
		var textIndex = 0;
		var charIndex = 0;
		var currentText = texts[ 0 ];
		var chars = currentText.split( '' );
		var result = chars.slice(); /* Kopie, wird zeichenweise ersetzt */

		function randomChar() {
			return pool[ Math.floor( Math.random() * pool.length ) ];
		}

		function tick() {
			if ( charIndex < chars.length ) {
				result[ charIndex ] = randomChar();
				charIndex++;
				cipherEl.textContent = result.join( '' );
				var delay = 80 + Math.floor( Math.random() * 41 ); /* 80–120 ms */
				cipherEl._gfbTimer = setTimeout( tick, delay );
			} else {
				/* Alle Zeichen ersetzt – kurze Pause, dann nächster Klartext */
				cipherEl._gfbTimer = setTimeout( function () {
					textIndex = ( textIndex + 1 ) % texts.length;
					currentText = texts[ textIndex ];
					chars = currentText.split( '' );
					result = chars.slice();
					charIndex = 0;
					tick();
				}, 400 );
			}
		}

		tick();
	}

	/**
	 * Submit-Overlay einblenden und Button sperren.
	 * Wird im submit-EventListener vor dem Snapshot aufgerufen.
	 *
	 * @param {HTMLFormElement} form
	 */
	function gfbShowSubmitOverlay( form ) {
		/* Button sperren */
		var wrapper = form.closest( '.gfb-form-wrapper' );
		var submitBtn = wrapper
			? wrapper.querySelector( 'button[type="submit"]' )
			: form.querySelector( 'button[type="submit"]' );
		if ( ! submitBtn ) {
			/* Fallback: letzter Button im Formular */
			var allBtns = form.querySelectorAll( 'button' );
			submitBtn = allBtns[ allBtns.length - 1 ] || null;
		}
		if ( submitBtn ) {
			submitBtn.disabled = true;
			submitBtn.setAttribute( 'aria-disabled', 'true' );
		}

		if ( ! wrapper ) {
			return;
		}

		/* position: relative sicherstellen */
		var wrapperStyle = window.getComputedStyle( wrapper );
		if ( wrapperStyle.position === 'static' ) {
			wrapper.style.position = 'relative';
		}

		/* Overlay-DOM aufbauen */
		var overlay = document.createElement( 'div' );
		overlay.className = 'gfb-submit-overlay';
		overlay.setAttribute( 'role', 'alert' );
		overlay.setAttribute( 'aria-live', 'assertive' );

		var content = document.createElement( 'div' );
		content.className = 'gfb-submit-overlay__content';

		var cipher = document.createElement( 'div' );
		cipher.className = 'gfb-submit-overlay__cipher';
		cipher.setAttribute( 'aria-hidden', 'true' );

		var barTrack = document.createElement( 'div' );
		barTrack.className = 'gfb-submit-overlay__bar-track';

		var bar = document.createElement( 'div' );
		bar.className = 'gfb-submit-overlay__bar';
		barTrack.appendChild( bar );

		var message = document.createElement( 'p' );
		message.className = 'gfb-submit-overlay__message';
		message.textContent = 'Deine Daten werden verschlüsselt und sicher übermittelt \u2026';

		content.appendChild( cipher );
		content.appendChild( barTrack );
		content.appendChild( message );
		overlay.appendChild( content );
		wrapper.appendChild( overlay );

		/* Cipher-Animation starten */
		gfbRunCipherAnimation( cipher );
	}

	function initForm( db, form ) {
		var key = form.getAttribute( 'data-gfb-key' );
		if ( ! key ) {
			return;
		}
		gfbUpgradeWebKitDateTimeInputs( form );
		/** Nach manuellem Löschen kurz kein erneutes Speichern (sonst z. B. Safari: Alert → visibilitychange → Entwurf ist sofort wieder da). */
		var persistAllowed = true;
		/** Nach reset()/Löschen: Events vom Browser blockieren, die sonst sofort wieder speichern. */
		var draftSuppressUntil = 0;
		var draftEnabledField = form.querySelector( 'input[name="gfb_draft_enabled"]' );
		var draftModeField = form.querySelector( 'input[name="gfb_draft_mode"]' );
		var draftTtlField = form.querySelector( 'input[name="gfb_draft_ttl_days"]' );
		var draftEnabled = ! draftEnabledField || draftEnabledField.value !== '0';
		var restoreMode = draftModeField ? draftModeField.value : 'auto';
		var ttlDays = draftTtlField ? parseInt( draftTtlField.value, 10 ) : 7;
		var ttlMs = Number.isNaN( ttlDays ) || ttlDays < 1 ? DEFAULT_TTL_MS : ttlDays * 24 * 60 * 60 * 1000;
		var resetButton = form.querySelector( '.gfb-draft-reset-button' );
		var persistDebounceTimer = null;

		if ( shouldClearByUrl( key ) ) {
			removeDraft( db, key ).catch( function () {} );
		}

		if ( resetButton ) {
			resetButton.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				window.clearTimeout( persistDebounceTimer );
				persistDebounceTimer = null;
				persistAllowed = false;
				draftSuppressUntil = Date.now() + 1600;
				removeDraft( db, key )
					.then( function () {
						form.reset();
						/* reset() stellt HTML-Defaults wieder her; Datums-/Zeitfelder sollen wirklich leer sein (ohne versteckten Browser-/Entwurfswert). */
						form
							.querySelectorAll(
								'input[type="date"], input[type="time"], input[type="datetime-local"], input[data-gfb-wk-fallback="1"]'
							)
							.forEach( function ( el ) {
								el.value = '';
								if ( el.defaultValue !== undefined ) {
									el.defaultValue = '';
								}
							} );
						form.querySelectorAll( '.gfb-file-clear' ).forEach( function ( btn ) {
							btn.setAttribute( 'hidden', '' );
						} );
						initRangeValueDisplays( form );
						gfbStripSubmitStateFromUrlIfPresent();
						gfbDismissSubmitNoticeForForm( form );
						window.alert( 'Lokaler Entwurf wurde gelöscht.' );
					} )
					.catch( function () {
						window.alert(
							'Der Entwurf konnte nicht gelöscht werden. Bitte Seite neu laden und erneut versuchen.'
						);
					} )
					.finally( function () {
						window.setTimeout( function () {
							persistAllowed = true;
						}, 450 );
					} );
			} );
		}

		form.querySelectorAll( 'input[type="file"]' ).forEach( function ( fileInput ) {
			var clearBtn = fileInput.closest( '.gfb-field' )
				? fileInput.closest( '.gfb-field' ).querySelector( '.gfb-file-clear' )
				: null;
			if ( ! clearBtn ) {
				return;
			}
			fileInput.addEventListener( 'change', function () {
				if ( fileInput.files && fileInput.files.length > 0 ) {
					clearBtn.removeAttribute( 'hidden' );
				} else {
					clearBtn.setAttribute( 'hidden', '' );
				}
			} );
			clearBtn.addEventListener( 'click', function () {
				fileInput.value = '';
				fileInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				clearBtn.setAttribute( 'hidden', '' );
			} );
		} );

		/* Erfolgs-Platzhalter: Snapshot unabhängig von Entwürfen (auch bei draftEnabled: false). */
		form.addEventListener(
			'submit',
			function () {
				gfbShowSubmitOverlay( form );
				try {
					var sk = form.getAttribute( 'data-gfb-key' );
					if ( sk && window.sessionStorage ) {
						window.sessionStorage.setItem(
							'gfb_submit_snapshot:' + sk,
							JSON.stringify( collectValues( form ) )
						);
					}
				} catch ( snapErr ) {
					/* sessionStorage kann blockiert sein */
				}
			},
			true
		);

		if ( ! draftEnabled ) {
			removeDraft( db, key ).catch( function () {} );
			return;
		}

		getDraft( db, key )
			.then( function ( draft ) {
				if ( ! draft || ! draft.payload ) {
					return;
				}
				if (
					typeof draft.payload === 'object' &&
					draft.payload !== null &&
					! Array.isArray( draft.payload ) &&
					Object.keys( draft.payload ).length === 0
				) {
					return;
				}
				if ( ! draft.updatedAt || Date.now() - draft.updatedAt > ttlMs ) {
					removeDraft( db, key ).catch( function () {} );
					return;
				}

				if ( restoreMode === 'auto' ) {
					applyValues( form, draft.payload );
					return;
				}

				if ( window.confirm( 'Ein gespeicherter Entwurf wurde gefunden. Möchtest du ihn wiederherstellen?' ) ) {
					applyValues( form, draft.payload );
				}
			} )
			.catch( function () {} );

		/**
		 * Sofort speichern (ohne Debounce). Wichtig für Safari/WebKit: vor dem Entladen
		 * schliessen sich Transaktionen oft, bevor ein verzögerter Timeout läuft.
		 */
		function flushDraft() {
			if ( ! persistAllowed || Date.now() < draftSuppressUntil ) {
				return;
			}
			setDraft( db, key, collectValues( form ) ).catch( function () {} );
		}

		function schedulePersistDebounced() {
			if ( Date.now() < draftSuppressUntil ) {
				return;
			}
			window.clearTimeout( persistDebounceTimer );
			persistDebounceTimer = window.setTimeout( function () {
				persistDebounceTimer = null;
				flushDraft();
			}, 250 );
		}

		form.addEventListener( 'input', schedulePersistDebounced );
		form.addEventListener( 'change', schedulePersistDebounced );

		function onVisibilityChange() {
			if ( document.visibilityState === 'hidden' ) {
				flushDraft();
			}
		}

		document.addEventListener( 'visibilitychange', onVisibilityChange );
		window.addEventListener( 'pagehide', flushDraft );
		window.addEventListener( 'beforeunload', flushDraft );
	}

	/**
	 * Entfernt Submit-/Redirect-Parameter aus der Adresszeile, damit ein Reload die Hinweiszeile nicht erneut anzeigt.
	 *
	 * @return {boolean} true wenn die URL geändert wurde.
	 */
	/**
	 * Platzhalter {{feldname}} bzw. {{label_feldname}} im Erfolgsbereich (nur Textknoten).
	 *
	 * @param {string} s
	 * @param {Record<string,string>} values
	 * @param {Record<string,string>} labels
	 * @return {string}
	 */
	/**
	 * Objekt-Schlüssel auf Kleinbuchstaben normalisieren (HTML name / URL / JSON).
	 *
	 * @param {Record<string,string>} raw
	 * @return {Record<string,string>}
	 */
	function gfbNormalizeKeyMap( raw ) {
		var out = {};
		if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
			return out;
		}
		Object.keys( raw ).forEach( function ( k ) {
			out[ String( k ).toLowerCase() ] = raw[ k ];
		} );
		return out;
	}

	function gfbReplaceTokenPlaceholders( s, values, labels ) {
		return String( s ).replace(
			/\{\{label_([a-z0-9_-]+)\}\}|\{\{([a-z0-9_-]+)\}\}/gi,
			function ( _full, labelKey, plainKey ) {
				if ( labelKey ) {
					var lk = String( labelKey ).toLowerCase();
					return Object.prototype.hasOwnProperty.call( labels, lk )
						? String( labels[ lk ] != null ? labels[ lk ] : '' )
						: '';
				}
				if ( plainKey ) {
					var pk = String( plainKey ).toLowerCase();
					if ( Object.prototype.hasOwnProperty.call( values, pk ) ) {
						return String( values[ pk ] != null ? values[ pk ] : '' );
					}
				}
				return '';
			}
		);
	}

	/**
	 * @param {Element} root
	 * @param {Record<string,string>} values
	 * @param {Record<string,string>} labels
	 */
	function gfbApplyTokensToTextNodes( root, values, labels ) {
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT, null );
		var list = [];
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( ! node.nodeValue || node.nodeValue.indexOf( '{{' ) === -1 ) {
				continue;
			}
			var par = node.parentNode;
			if ( ! par || par.nodeName === 'SCRIPT' || par.nodeName === 'STYLE' ) {
				continue;
			}
			list.push( node );
		}
		list.forEach( function ( textNode ) {
			var next = gfbReplaceTokenPlaceholders( textNode.nodeValue, values, labels );
			if ( next !== textNode.nodeValue ) {
				textNode.nodeValue = next;
			}
		} );
	}

	/**
	 * Nach Redirect: Werte aus sessionStorage in Erfolgs-HTML einsetzen (gleiche Seite, ohne Folgeseite).
	 *
	 * @return {void}
	 */
	function gfbApplySubmitSnapshotTokens() {
		var sp = new URLSearchParams( window.location.search );
		if ( sp.get( 'gfb_status' ) !== 'success' ) {
			return;
		}
		var formId = sp.get( 'gfb_form' );
		if ( ! formId ) {
			return;
		}
		var roots = document.querySelectorAll( '.gfb-form-wrapper[data-gfb-await-tokens]' );
		if ( ! roots.length ) {
			return;
		}
		var formIdLc = String( formId ).toLowerCase();
		Array.prototype.forEach.call( roots, function ( wrap ) {
			var wrapFormId = wrap.getAttribute( 'data-gfb-form-id' );
			if ( String( wrapFormId || '' ).toLowerCase() !== formIdLc ) {
				return;
			}
			var labels = {};
			var lm = wrap.getAttribute( 'data-gfb-label-map' );
			if ( lm ) {
				try {
					var parsed = JSON.parse( lm );
					if ( parsed && typeof parsed === 'object' && ! Array.isArray( parsed ) ) {
						labels = gfbNormalizeKeyMap( parsed );
					}
				} catch ( parseErr ) {
					labels = {};
				}
			}
			var key = wrap.getAttribute( 'data-gfb-key' );
			if ( ! key || ! window.sessionStorage ) {
				return;
			}
			var raw = window.sessionStorage.getItem( 'gfb_submit_snapshot:' + key );
			var values = {};
			if ( raw ) {
				try {
					var vobj = JSON.parse( raw );
					if ( vobj && typeof vobj === 'object' && ! Array.isArray( vobj ) ) {
						values = gfbNormalizeKeyMap( vobj );
					}
				} catch ( jsonErr ) {
					values = {};
				}
			}
			gfbApplyTokensToTextNodes( wrap, values, labels );
			try {
				window.sessionStorage.removeItem( 'gfb_submit_snapshot:' + key );
			} catch ( rmErr ) {
				/* ignorieren */
			}
		} );
	}

	function gfbStripSubmitStateFromUrlIfPresent() {
		var params = new URLSearchParams( window.location.search );
		var keys = [ 'gfb_status', 'gfb_code', 'gfb_form', 'gfb_detail', 'gfb_draft_key' ];
		var changed = false;
		keys.forEach( function ( k ) {
			if ( params.has( k ) ) {
				params.delete( k );
				changed = true;
			}
		} );
		if ( ! changed ) {
			return false;
		}
		var qs = params.toString();
		var next = window.location.pathname + ( qs ? '?' + qs : '' ) + window.location.hash;
		var cur = window.location.pathname + window.location.search + window.location.hash;
		if ( next !== cur ) {
			window.history.replaceState( {}, document.title, next );
		}
		return true;
	}

	/**
	 * Entfernt die Erfolgs-/Fehler-Notice oberhalb des Formulars (z. B. nach „Entwurf löschen“).
	 *
	 * @param {HTMLFormElement} form
	 * @return {void}
	 */
	function gfbDismissSubmitNoticeForForm( form ) {
		var wrap = form.closest( '.gfb-form-wrapper' );
		if ( ! wrap ) {
			return;
		}
		var note = wrap.querySelector( '.gfb-notice' );
		if ( note && note.parentNode ) {
			note.parentNode.removeChild( note );
		}
	}

	function gfbInitFrontend() {
		/* Platzhalter zuerst: `gfb_status`/`gfb_form` stehen noch in der URL; kein Warten auf IndexedDB. */
		gfbApplySubmitSnapshotTokens();

		var sp = new URLSearchParams( window.location.search );
		if ( sp.get( 'gfb_status' ) === 'success' && sp.get( 'gfb_draft_key' ) ) {
			var dk = sp.get( 'gfb_draft_key' );
			if ( /^[0-9]+:[a-z0-9_-]+:[a-z0-9_-]+$/i.test( dk ) ) {
				openDb()
					.then( function ( db ) {
						return removeDraft( db, dk );
					} )
					.catch( function () {} );
			}
		}

		var forms = document.querySelectorAll( 'form.gfb-form[data-gfb-key]' );
		forms.forEach( function ( form ) {
			initRangeValueDisplays( form );
		} );

		openDb()
			.then( function ( db ) {
				forms.forEach( function ( form ) {
					initForm( db, form );
				} );
			} )
			.catch( function () {} )
			.finally( function () {
				/* Nach erstem Anzeigen: URL bereinigen, damit Reload / neuer Tab keinen festgefahrenen Hinweis erzeugt. */
				gfbStripSubmitStateFromUrlIfPresent();
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', gfbInitFrontend );
	} else {
		gfbInitFrontend();
	}
} )();
