( function () {
	'use strict';

	var DB_NAME = 'gfbDraftsDB';
	var STORE_NAME = 'drafts';
	var DEFAULT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

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
			if ( field.type === 'checkbox' ) {
				values[ field.name ] = field.checked ? '1' : '0';
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

	function initForm( db, form ) {
		var key = form.getAttribute( 'data-gfb-key' );
		if ( ! key ) {
			return;
		}
		/** Nach manuellem Löschen kurz kein erneutes Speichern (sonst z. B. Safari: Alert → visibilitychange → Draft ist sofort wieder da). */
		var persistAllowed = true;
		var draftEnabledField = form.querySelector( 'input[name="gfb_draft_enabled"]' );
		var draftModeField = form.querySelector( 'input[name="gfb_draft_mode"]' );
		var draftTtlField = form.querySelector( 'input[name="gfb_draft_ttl_days"]' );
		var draftEnabled = ! draftEnabledField || draftEnabledField.value !== '0';
		var restoreMode = draftModeField ? draftModeField.value : 'prompt';
		var ttlDays = draftTtlField ? parseInt( draftTtlField.value, 10 ) : 7;
		var ttlMs = Number.isNaN( ttlDays ) || ttlDays < 1 ? DEFAULT_TTL_MS : ttlDays * 24 * 60 * 60 * 1000;
		var resetButton = form.querySelector( '.gfb-draft-reset-button' );
		var persistDebounceTimer = null;

		if ( shouldClearByUrl( key ) ) {
			removeDraft( db, key ).catch( function () {} );
		}

		if ( resetButton ) {
			resetButton.addEventListener( 'click', function () {
				window.clearTimeout( persistDebounceTimer );
				persistDebounceTimer = null;
				persistAllowed = false;
				removeDraft( db, key )
					.then( function () {
						window.alert( 'Lokaler Draft wurde gelöscht.' );
					} )
					.catch( function () {
						window.alert(
							'Der Draft konnte nicht gelöscht werden. Bitte Seite neu laden und erneut versuchen.'
						);
					} )
					.finally( function () {
						window.setTimeout( function () {
							persistAllowed = true;
						}, 100 );
					} );
			} );
		}

		if ( ! draftEnabled ) {
			removeDraft( db, key ).catch( function () {} );
			return;
		}

		getDraft( db, key )
			.then( function ( draft ) {
				if ( ! draft || ! draft.payload ) {
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
		 * schließen sich Transaktionen oft, bevor ein verzögerter Timeout läuft.
		 */
		function flushDraft() {
			if ( ! persistAllowed ) {
				return;
			}
			setDraft( db, key, collectValues( form ) ).catch( function () {} );
		}

		function schedulePersistDebounced() {
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

		form.addEventListener( 'submit', function () {
			// Draft wird final nach erfolgreichem Submit über URL-Status gelöscht.
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
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
			.catch( function () {} );
	} );
} )();
