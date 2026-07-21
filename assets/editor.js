( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useBlockPropsSave = useBlockProps.save;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var __experimentalNumberControl = wp.components.__experimentalNumberControl;
	var Notice = wp.components.Notice;
	var FormTokenField = wp.components.FormTokenField;
	var createBlock = wp.blocks.createBlock;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;
	var PanelColorGradientSettings =
		wp.blockEditor.PanelColorGradientSettings ||
		wp.blockEditor.__experimentalPanelColorGradientSettings ||
		null;
	/** Erkennt gespeicherte CSS-Verläufe (ein Wert pro Attribut). */
	var GFB_GRADIENT_VALUE_RE =
		/^(?:linear|radial|conic|repeating-linear|repeating-radial|repeating-conic)-gradient\s*\(/i;
	function gfbAttrLooksLikeCssGradient( raw ) {
		if ( raw == null || String( raw ).trim() === '' ) {
			return false;
		}
		return GFB_GRADIENT_VALUE_RE.test( String( raw ).trim() );
	}
	/**
	 * @param {Array<{label:string,value?:string,onChange:function(string):void}>} rows
	 * @return {Array<Record<string, unknown>>}
	 */
	function mapColorSettingsToGradientSettings( rows ) {
		return rows.map( function ( row ) {
			var raw = row.value != null ? String( row.value ).trim() : '';
			var isGrad = GFB_GRADIENT_VALUE_RE.test( raw );
			// ColorGradientControl ruft nach Farbwahl onColorChange( neu ) und direkt onGradientChange() ohne Arg (nur Verlauf-Slot leeren) — und umgekehrt. ToolsPanel „Zurücksetzen“ ruft dagegen nur einen der beiden ohne Arg auf → Wert leeren.
			var suppressNextNoArgGradient = false;
			var suppressNextNoArgColor = false;
			return {
				label: row.label,
				colorValue: isGrad ? undefined : raw || undefined,
				gradientValue: isGrad ? raw : undefined,
				onColorChange: function ( v ) {
					if ( arguments.length === 0 ) {
						if ( suppressNextNoArgColor ) {
							suppressNextNoArgColor = false;
							return;
						}
						row.onChange( '' );
						return;
					}
					suppressNextNoArgGradient = true;
					row.onChange( v == null ? '' : String( v ) );
					queueMicrotask( function () {
						suppressNextNoArgGradient = false;
					} );
				},
				onGradientChange: function ( v ) {
					if ( arguments.length === 0 ) {
						if ( suppressNextNoArgGradient ) {
							suppressNextNoArgGradient = false;
							return;
						}
						row.onChange( '' );
						return;
					}
					suppressNextNoArgColor = true;
					row.onChange( v == null ? '' : String( v ) );
					queueMicrotask( function () {
						suppressNextNoArgColor = false;
					} );
				},
				enableAlpha: true,
			};
		} );
	}
	/**
	 * Farbe + optional Verlauf (WP-Komponente) bzw. nur Farbe mit Alphakanal als Fallback.
	 *
	 * @param {string} title
	 * @param {Array<{label:string,value?:string,onChange:function(string):void}>} rows
	 * @param {{wrapInPanelBody?:boolean,panelBodyInitialOpen?:boolean}|undefined} options
	 *        wrapInPanelBody: z. B. Feld-Inspector — PanelBody mit initialOpen zuverlässig zugeklappt
	 *        (PanelColorGradientSettings basiert auf ToolsPanel und ignoriert initialOpen oft).
	 */
	function renderGfbColorPanel( title, rows, options ) {
		options = options || {};
		var usePanelBody = !! options.wrapInPanelBody;
		var panelBodyOpen =
			options.panelBodyInitialOpen !== undefined ? !! options.panelBodyInitialOpen : false;

		function innerColorPanel() {
			if ( PanelColorGradientSettings ) {
				// Theme kann color.customGradient / Paletten deaktivieren — dann wäre canChooseAGradient false und kein Verlauf-Tab. Für Formularfarben immer eigene Farben + Verläufe erlauben.
				var gradProps = {
					disableCustomColors: false,
					disableCustomGradients: false,
					settings: mapColorSettingsToGradientSettings( rows ),
				};
				if ( usePanelBody ) {
					gradProps.title = '';
					gradProps.initialOpen = true;
				} else {
					gradProps.title = title;
					gradProps.initialOpen = false;
				}
				return el( PanelColorGradientSettings, gradProps );
			}
			var colorProps = {
				colorSettings: rows.map( function ( r ) {
					return Object.assign( {}, r, { enableAlpha: true } );
				} ),
			};
			if ( usePanelBody ) {
				colorProps.title = '';
				colorProps.initialOpen = true;
			} else {
				colorProps.title = title;
				colorProps.initialOpen = false;
			}
			return el( PanelColorSettings, colorProps );
		}

		if ( usePanelBody ) {
			return el(
				PanelBody,
				{
					title: title,
					initialOpen: panelBodyOpen,
				},
				innerColorPanel()
			);
		}
		return innerColorPanel();
	}
	/**
	 * Hält formId pro Block-Instanz eindeutig: Duplizieren kopiert formId/blockInstanceId,
	 * daher Abgleich mit der stabilen Editor-clientId (neue formId bei neuer Instanz).
	 */
	function syncFormInstance( attributes, setAttributes, clientId ) {
		useEffect(
			function () {
				if ( attributes.blockInstanceId === clientId ) {
					return;
				}
				/* Alte Inhalte ohne blockInstanceId: formId behalten, nur Instanz binden. */
				if ( ! attributes.blockInstanceId && attributes.formId ) {
					setAttributes( { blockInstanceId: clientId } );
					return;
				}
				/* Kopie / Einfügen / neu: neue formId; Empfänger-Default nur hier (einmalig). */
				var generated = 'gfb_' + clientId.replace( /-/g, '' ).slice( 0, 12 );
				var patch = {
					blockInstanceId: clientId,
					formId: generated,
				};
				if ( gfbNormalizeEmailRecipientsArray( attributes.emailRecipients ).length === 0 ) {
					var admin = gfbGetDefaultAdminEmail();
					if ( admin ) {
						patch.emailRecipients = [ admin ];
					}
				}
				setAttributes( patch );
			},
			[ attributes.blockInstanceId, attributes.formId, attributes.emailRecipients, clientId, setAttributes ]
		);
	}

	/**
	 * Startwert für range: optional defaultValue, sonst Mitte (auf Schritt gerundet).
	 *
	 * @param {string} minStr
	 * @param {string} maxStr
	 * @param {string} stepStr
	 * @param {string|undefined} defaultStr
	 * @return {string}
	 */
	function computeRangeInitial( minStr, maxStr, stepStr, defaultStr ) {
		var min = parseFloat( minStr );
		var max = parseFloat( maxStr );
		var step = parseFloat( stepStr );
		if ( Number.isNaN( min ) ) {
			min = 0;
		}
		if ( Number.isNaN( max ) ) {
			max = 100;
		}
		if ( Number.isNaN( step ) || step <= 0 ) {
			step = 1;
		}
		if ( max < min ) {
			var t = min;
			min = max;
			max = t;
		}
		if ( defaultStr !== undefined && defaultStr !== null && String( defaultStr ).trim() !== '' ) {
			var d = parseFloat( defaultStr );
			if ( ! Number.isNaN( d ) ) {
				d = Math.min( max, Math.max( min, d ) );
				return snapRangeToStep( d, min, max, step );
			}
		}
		return snapRangeToStep( ( min + max ) / 2, min, max, step );
	}

	/**
	 * @param {number} value
	 * @param {number} min
	 * @param {number} max
	 * @param {number} step
	 * @return {string}
	 */
	function snapRangeToStep( value, min, max, step ) {
		var steps = Math.round( ( value - min ) / step );
		var v = min + steps * step;
		if ( v > max ) {
			v = max;
		}
		if ( v < min ) {
			v = min;
		}
		var stepStr = String( step );
		var dot = stepStr.indexOf( '.' );
		var decimals = dot >= 0 ? stepStr.length - dot - 1 : 0;
		if ( decimals > 0 ) {
			return v.toFixed( decimals );
		}
		return String( Math.round( v ) );
	}

	/** Frühere block.json-Defaults (alle Felder eines Typs hatten denselben Namen → nur ein Wert in PHP). */
	var GFB_LEGACY_NAMES_TEXT = [ 'textfeld' ];
	var GFB_LEGACY_NAMES_EMAIL = [ 'email' ];
	var GFB_LEGACY_NAMES_TEXTAREA = [ 'nachricht' ];
	var GFB_LEGACY_NAMES_SELECT = [ 'auswahl' ];
	var GFB_LEGACY_NAMES_CHECKBOX = [ 'zustimmung' ];
	var GFB_LEGACY_NAMES_NUMBER = [ 'zahl' ];
	var GFB_LEGACY_NAMES_TEL = [ 'telefon' ];
	var GFB_LEGACY_NAMES_URL = [ 'website' ];
	var GFB_LEGACY_NAMES_DATE = [ 'datum' ];
	var GFB_LEGACY_NAMES_TIME = [ 'uhrzeit' ];
	var GFB_LEGACY_NAMES_DATETIME = [ 'termin' ];
	var GFB_LEGACY_NAMES_RADIO = [ 'auswahl_radio' ];
	var GFB_LEGACY_NAMES_HIDDEN = [ 'referenz' ];
	var GFB_LEGACY_NAMES_RANGE = [ 'wert' ];
	var GFB_LEGACY_NAMES_FILE = [ 'datei' ];

	/** Reihenfolge im Formular-Inserter: Felder zuerst (Core nutzt die Reihenfolge von allowedBlocks). */
	/**
	 * Felder unter gfb/form für die Rückmeldungsfelder (ohne Rückmeldung, ohne Absenden).
	 *
	 * @param {Array} blocks
	 * @return {Array<{name:string,label:string}>}
	 */
	function gfbCollectFormFieldTokenRows( blocks ) {
		var rows = [];
		if ( ! blocks || ! blocks.length ) {
			return rows;
		}
		blocks.forEach( function ( b ) {
			if ( ! b || ! b.name ) {
				return;
			}
			if (
				b.name === 'gfb/form-success' ||
				b.name === 'gfb/receipt-mail' ||
				b.name === 'gfb/doi-mail'
			) {
				return;
			}
			if ( b.name.indexOf( 'gfb/field-' ) === 0 && b.name !== 'gfb/field-submit' ) {
				var attrs = b.attributes || {};
				var nm = attrs.name != null ? String( attrs.name ).trim() : '';
				if ( nm ) {
					rows.push( {
						name: nm,
						label: attrs.label != null ? String( attrs.label ) : '',
					} );
				}
				return;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				rows = rows.concat( gfbCollectFormFieldTokenRows( b.innerBlocks ) );
			}
		} );
		return rows;
	}

	/**
	 * E-Mail-Felder unter gfb/form (für Absender-Auswahl).
	 *
	 * @param {Array} blocks
	 * @return {Array<{name:string,label:string}>}
	 */
	function gfbCollectFormEmailFieldRows( blocks ) {
		var rows = [];
		if ( ! blocks || ! blocks.length ) {
			return rows;
		}
		blocks.forEach( function ( b ) {
			if ( ! b || ! b.name ) {
				return;
			}
			if (
				b.name === 'gfb/form-success' ||
				b.name === 'gfb/receipt-mail' ||
				b.name === 'gfb/doi-mail'
			) {
				return;
			}
			if ( b.name === 'gfb/field-email' ) {
				var attrs = b.attributes || {};
				var nm = attrs.name != null ? String( attrs.name ).trim() : '';
				if ( nm ) {
					rows.push( {
						name: nm,
						label: attrs.label != null ? String( attrs.label ) : '',
					} );
				}
				return;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				rows = rows.concat( gfbCollectFormEmailFieldRows( b.innerBlocks ) );
			}
		} );
		return rows;
	}

	/**
	 * @param {string} value
	 * @return {boolean}
	 */
	function gfbIsValidEmailToken( value ) {
		var s = value == null ? '' : String( value ).trim();
		if ( s === '' || s.length > 254 ) {
			return false;
		}
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( s );
	}

	/**
	 * @param {*} raw
	 * @return {Array<string>}
	 */
	function gfbNormalizeEmailRecipientsArray( raw ) {
		var parts = [];
		if ( Array.isArray( raw ) ) {
			parts = raw;
		} else if ( typeof raw === 'string' && raw.trim() !== '' ) {
			parts = raw.split( /\s*,\s*/ );
		}
		var seen = {};
		var out = [];
		parts.forEach( function ( part ) {
			var email = part == null ? '' : String( part ).trim();
			if ( ! gfbIsValidEmailToken( email ) ) {
				return;
			}
			var key = email.toLowerCase();
			if ( seen[ key ] ) {
				return;
			}
			seen[ key ] = true;
			out.push( email );
		} );
		return out;
	}

	/**
	 * @return {string}
	 */
	function gfbGetDefaultAdminEmail() {
		if ( typeof gfbEditorAssets === 'undefined' || ! gfbEditorAssets.adminEmail ) {
			return '';
		}
		var email = String( gfbEditorAssets.adminEmail ).trim();
		return gfbIsValidEmailToken( email ) ? email : '';
	}

	/**
	 * Nach Verlassen des Formularblocks: leeres Empfänger-Feld → Admin-E-Mail wieder setzen.
	 *
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {string} clientId
	 */
	function syncEmailRecipientsOnFormBlur( attributes, setAttributes, clientId ) {
		var formHasFocus = useSelect(
			function ( select ) {
				var blockEditor = select( 'core/block-editor' );
				return (
					blockEditor.isBlockSelected( clientId ) ||
					blockEditor.hasSelectedInnerBlock( clientId, true )
				);
			},
			[ clientId ]
		);
		var hadFocus = useRef( false );
		useEffect(
			function () {
				if ( hadFocus.current && ! formHasFocus ) {
					if ( gfbNormalizeEmailRecipientsArray( attributes.emailRecipients ).length === 0 ) {
						var admin = gfbGetDefaultAdminEmail();
						if ( admin ) {
							setAttributes( { emailRecipients: [ admin ] } );
						}
					}
				}
				hadFocus.current = formHasFocus;
			},
			[ formHasFocus, attributes.emailRecipients, setAttributes ]
		);
	}

	/**
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {Array<{name:string,label:string}>} emailFieldRows
	 * @return {*}
	 */
	var GFB_EMAIL_FROM_CUSTOM_SENDER = 'gfb_custom_sender';

	function renderEmailNotificationControls( attributes, setAttributes, emailFieldRows ) {
		var enabled = attributes.emailNotificationEnabled === true;
		var fromMode = attributes.emailFromField || '';
		var isCustomFrom = fromMode === GFB_EMAIL_FROM_CUSTOM_SENDER;

		var fromFieldOptions = [
			{
				label: __( 'Admin-E-Mail der Website', 'gutenberg-formbuilder' ),
				value: '',
			},
			{
				label: __( 'Eigene E-Mail-Adresse', 'gutenberg-formbuilder' ),
				value: GFB_EMAIL_FROM_CUSTOM_SENDER,
			},
		];
		emailFieldRows.forEach( function ( row ) {
			var optLabel = row.label ? row.label + ' (' + row.name + ')' : row.name;
			fromFieldOptions.push( { label: optLabel, value: row.name } );
		} );


		return el(
			PanelBody,
			{
				title: __( 'E-Mail-Benachrichtigung', 'gutenberg-formbuilder' ),
				initialOpen: false,
			},
			el( ToggleControl, {
				label: __( 'E-Mail nach Absenden senden', 'gutenberg-formbuilder' ),
				checked: enabled,
				onChange: function ( value ) {
					setAttributes( { emailNotificationEnabled: value } );
				},
			} ),
			enabled
				? el(
						'div',
						null,
						el( FormTokenField, {
							label: __( 'Empfänger', 'gutenberg-formbuilder' ),
							value: gfbNormalizeEmailRecipientsArray( attributes.emailRecipients ),
							placeholder: __( 'E-Mail-Adresse eingeben …', 'gutenberg-formbuilder' ),
							tokenizeOnSpace: true,
							__nextHasNoMarginBottom: true,
							__experimentalValidateInput: gfbIsValidEmailToken,
							messages: {
								__experimentalInvalid: __(
									'Keine gültige E-Mail-Adresse.',
									'gutenberg-formbuilder'
								),
							},
							onChange: function ( tokens ) {
								setAttributes( {
									emailRecipients: gfbNormalizeEmailRecipientsArray( tokens ),
								} );
							},
						} ),
						el(
							'p',
							{
								className: 'components-base-control__help',
								style: { marginTop: 0 },
							},
							__( 'Leer = Admin-E-Mail der Website.', 'gutenberg-formbuilder' )
						),
						el( TextControl, {
							label: __( 'Betreff', 'gutenberg-formbuilder' ),
							help: __(
								'Leer = Standardbetreff. Platzhalter: {{feldname}} und {{label_feldname}} (technischer Feldname).',
								'gutenberg-formbuilder'
							),
							value: attributes.emailSubject || '',
							onChange: function ( v ) {
								setAttributes( { emailSubject: v == null ? '' : String( v ) } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Absender-E-Mail', 'gutenberg-formbuilder' ),
							help: __(
								'Admin, feste Adresse oder Wert aus einem E-Mail-Feld der Einsendung (sonst Admin-E-Mail).',
								'gutenberg-formbuilder'
							),
							value: isCustomFrom ? GFB_EMAIL_FROM_CUSTOM_SENDER : fromMode,
							options: fromFieldOptions,
							onChange: function ( v ) {
								setAttributes( { emailFromField: v || '' } );
							},
						} ),
						isCustomFrom
							? el( TextControl, {
									label: __( 'Eigene Absender-E-Mail', 'gutenberg-formbuilder' ),
									type: 'email',
									help: __(
										'Feste From-Adresse für diese Benachrichtigung (nicht aus dem Formular). Ungültig oder leer → Admin-E-Mail.',
										'gutenberg-formbuilder'
									),
									value: attributes.emailFromCustom || '',
									onChange: function ( v ) {
										setAttributes( {
											emailFromCustom: v == null ? '' : String( v ).trim(),
										} );
									},
							  } )
							: null,
						el( TextControl, {
							label: __( 'Absendername', 'gutenberg-formbuilder' ),
							help: __(
								'Optional. Leer = Name der Website. Platzhalter: {{feldname}} und {{label_feldname}} (technischer Feldname).',
								'gutenberg-formbuilder'
							),
							value: attributes.emailFromName || '',
							onChange: function ( v ) {
								setAttributes( { emailFromName: v == null ? '' : String( v ) } );
							},
						} )
				  )
				: null
		);
	}

	/**
	 * Checkbox-Felder unter gfb/form (für das Einwilligungs-Feld der
	 * Integrations-Hooks), inkl. sensitive-Flag für die Editor-Warnung.
	 *
	 * @param {Array} blocks
	 * @return {Array<{name:string,label:string,sensitive:boolean}>}
	 */
	function gfbCollectFormCheckboxFieldRows( blocks ) {
		var rows = [];
		if ( ! blocks || ! blocks.length ) {
			return rows;
		}
		blocks.forEach( function ( b ) {
			if ( ! b || ! b.name ) {
				return;
			}
			if (
				b.name === 'gfb/form-success' ||
				b.name === 'gfb/receipt-mail' ||
				b.name === 'gfb/doi-mail'
			) {
				return;
			}
			if ( b.name === 'gfb/field-checkbox' ) {
				var attrs = b.attributes || {};
				var nm = attrs.name != null ? String( attrs.name ).trim() : '';
				if ( nm ) {
					rows.push( {
						name: nm,
						label: attrs.label != null ? String( attrs.label ) : '',
						sensitive: attrs.sensitive === true,
					} );
				}
				return;
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				rows = rows.concat( gfbCollectFormCheckboxFieldRows( b.innerBlocks ) );
			}
		} );
		return rows;
	}

	/**
	 * Inspector-Bereich «Datenweitergabe (Integrationen)» am gfb/form-Block:
	 * designiert das Einwilligungs-Feld für den Filter gfb_transfer_consent.
	 *
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {Array<{name:string,label:string,sensitive:boolean}>} checkboxRows
	 * @return {*}
	 */
	function renderConsentControls( attributes, setAttributes, checkboxRows ) {
		var options = [
			{
				label: __( '— kein Feld gewählt —', 'gutenberg-formbuilder' ),
				value: '',
			},
		];
		checkboxRows.forEach( function ( row ) {
			var optLabel = row.label ? row.label + ' (' + row.name + ')' : row.name;
			options.push( { label: optLabel, value: row.name } );
		} );

		var selected = null;
		checkboxRows.forEach( function ( row ) {
			if ( attributes.consentField && row.name === attributes.consentField ) {
				selected = row;
			}
		} );

		return el(
			PanelBody,
			{
				title: __( 'Datenweitergabe (Integrationen)', 'gutenberg-formbuilder' ),
				initialOpen: false,
			},
			el( SelectControl, {
				label: __( 'Einwilligungs-Feld für Datenweitergabe (optional)', 'gutenberg-formbuilder' ),
				help: __(
					'Nur für Dritt-Integrationen (Filter gfb_transfer_consent): Er liefert nur dann «ja», wenn dieses Kontrollkästchen in der Einsendung angekreuzt wurde. Ohne gewähltes Feld liefert er immer «nein» – Erlaubnis nie implizit.',
					'gutenberg-formbuilder'
				),
				value: attributes.consentField || '',
				options: options,
				onChange: function ( v ) {
					setAttributes( { consentField: v || '' } );
				},
			} ),
			selected && selected.sensitive
				? el(
						Notice,
						{ status: 'warning', isDismissible: false, key: 'consent-sensitive' },
						__(
							'Das Einwilligungs-Feld darf nicht als vertraulich markiert sein – vertraulich gespeicherte Werte werden für die Weitergabe-Abfrage nicht entschlüsselt, der Filter liefert dann immer «nein».',
							'gutenberg-formbuilder'
						)
				  )
				: null
		);
	}

	/**
	 * Rekursiv: enthält der Block-Baum ein vertraulich markiertes Feld?
	 *
	 * @param {Array} blocks
	 * @return {boolean}
	 */
	function gfbBlocksHaveSensitiveField( blocks ) {
		if ( ! blocks || ! blocks.length ) {
			return false;
		}
		for ( var i = 0; i < blocks.length; i++ ) {
			var b = blocks[ i ];
			if ( ! b || ! b.name ) {
				continue;
			}
			if (
				b.name.indexOf( 'gfb/field-' ) === 0 &&
				b.attributes &&
				b.attributes.sensitive === true
			) {
				return true;
			}
			if ( b.innerBlocks && gfbBlocksHaveSensitiveField( b.innerBlocks ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rekursiv: erster Block eines Typs im Baum (oder null).
	 *
	 * @param {Array} blocks
	 * @param {string} name
	 * @return {object|null}
	 */
	function gfbFindBlockOfType( blocks, name ) {
		if ( ! blocks || ! blocks.length ) {
			return null;
		}
		for ( var i = 0; i < blocks.length; i++ ) {
			var b = blocks[ i ];
			if ( ! b ) {
				continue;
			}
			if ( b.name === name ) {
				return b;
			}
			if ( b.innerBlocks ) {
				var found = gfbFindBlockOfType( b.innerBlocks, name );
				if ( found ) {
					return found;
				}
			}
		}
		return null;
	}

	/**
	 * Rekursiv: kommt {{bestaetigungslink}} in einem String-Attribut vor?
	 * (Pflicht-Platzhalter der Link-Mail; RichText-Werte werden über String()
	 * verglichen.)
	 *
	 * @param {Array} blocks
	 * @return {boolean}
	 */
	function gfbBlocksContainConfirmPlaceholder( blocks ) {
		if ( ! blocks || ! blocks.length ) {
			return false;
		}
		for ( var i = 0; i < blocks.length; i++ ) {
			var b = blocks[ i ];
			if ( ! b ) {
				continue;
			}
			var attrs = b.attributes || {};
			for ( var key in attrs ) {
				if ( ! Object.prototype.hasOwnProperty.call( attrs, key ) ) {
					continue;
				}
				var v = attrs[ key ];
				if ( v == null ) {
					continue;
				}
				var s = '';
				try {
					s = String( v );
				} catch ( err ) {
					s = '';
				}
				if ( s.indexOf( '{{bestaetigungslink}}' ) !== -1 ) {
					return true;
				}
			}
			if ( b.innerBlocks && gfbBlocksContainConfirmPlaceholder( b.innerBlocks ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sofort-Modus verlangt serverseitig ein erzwungenes Captcha (Blocker 2).
	 * Liefert true, wenn das Captcha für dieses Formular wirksam ist.
	 *
	 * @param {object} attributes gfb/form-Attribute.
	 * @return {boolean}
	 */
	function gfbCaptchaEffectiveForForm( attributes ) {
		var hasKeys =
			typeof gfbEditorAssets !== 'undefined' && gfbEditorAssets.captchaHasKeys === '1';
		var globalActive =
			typeof gfbEditorAssets !== 'undefined' && gfbEditorAssets.captchaGlobalActive === '1';
		var mode = attributes.captchaMode || 'inherit';
		if ( mode === 'off' ) {
			return false;
		}
		if ( mode === 'on' ) {
			return hasKeys;
		}
		return globalActive;
	}

	/**
	 * Inspector-Bereich «Bestätigungsmail an Absender/in» am gfb/form-Block.
	 *
	 * @param {object} attributes
	 * @param {function} setAttributes
	 * @param {Array<{name:string,label:string}>} emailFieldRows
	 * @param {{hasSensitive:boolean,hasReceiptBlock:boolean,hasDoiBlock:boolean}} info
	 * @return {*}
	 */
	function renderReceiptMailControls( attributes, setAttributes, emailFieldRows, info ) {
		var mode = attributes.receiptMode || 'none';
		var active = mode === 'instant' || mode === 'doi';

		var recipientOptions = [
			{
				label: __( '— E-Mail-Feld wählen —', 'gutenberg-formbuilder' ),
				value: '',
			},
		];
		emailFieldRows.forEach( function ( row ) {
			var optLabel = row.label ? row.label + ' (' + row.name + ')' : row.name;
			recipientOptions.push( { label: optLabel, value: row.name } );
		} );

		var notices = [];
		if ( active && ! emailFieldRows.length ) {
			notices.push(
				el(
					Notice,
					{ status: 'warning', isDismissible: false, key: 'no-email-field' },
					__(
						'Dieses Formular hat kein E-Mail-Feld. Ohne E-Mail-Feld wird keine Bestätigungsmail versendet; die Einsendung läuft normal.',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( active && emailFieldRows.length && ! attributes.receiptEmailField ) {
			notices.push(
				el(
					Notice,
					{ status: 'warning', isDismissible: false, key: 'no-recipient' },
					__(
						'Kein Empfänger-Feld gewählt – es wird keine Bestätigungsmail versendet; die Einsendung läuft normal.',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( mode === 'instant' && ! gfbCaptchaEffectiveForForm( attributes ) ) {
			notices.push(
				el(
					Notice,
					{ status: 'warning', isDismissible: false, key: 'captcha-required' },
					__(
						'Der Sofort-Modus versendet nur mit erzwungenem Captcha (Missbrauchsschutz). Captcha für dieses Formular auf «Immer an» stellen und die Schlüssel unter «Sicherheit & Einstellungen» hinterlegen – sonst unterdrückt der Server den Versand.',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( mode === 'instant' && info.hasSensitive ) {
			notices.push(
				el(
					Notice,
					{ status: 'info', isDismissible: false, key: 'sensitive-instant' },
					__(
						'Dieses Formular enthält vertrauliche Felder. Im Sofort-Modus stehen sie in der Mail nur als Hinweis «vertraulich gespeichert»; im Klartext erst nach bestätigter Adresse (Modus «mit Bestätigungslink»).',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( active && ! info.hasReceiptBlock ) {
			notices.push(
				el(
					Notice,
					{ status: 'info', isDismissible: false, key: 'no-receipt-block' },
					__(
						'Ohne den Block «Bestätigungsmail – Inhalt» im Formular wird die eingebaute Standard-Vorlage verwendet. Block einfügen, um die Mail zu gestalten.',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( mode === 'doi' && ! info.hasDoiBlock ) {
			notices.push(
				el(
					Notice,
					{ status: 'info', isDismissible: false, key: 'no-doi-block' },
					__(
						'Ohne den Block «Bestätigungslink-Mail – Inhalt» im Formular wird die eingebaute Standard-Vorlage der Link-Mail verwendet.',
						'gutenberg-formbuilder'
					)
				)
			);
		}
		if ( mode === 'doi' && attributes.emailNotificationEnabled !== true ) {
			notices.push(
				el(
					Notice,
					{ status: 'warning', isDismissible: false, key: 'doi-no-operator-mail' },
					__(
						'Die E-Mail-Benachrichtigung ist deaktiviert. Die beiden Betreiber-Mails des Bestätigungslink-Modus («unbestätigt eingegangen», «jetzt bestätigt») sind Teil der Betreiber-Benachrichtigung und entfallen dann – der Bestätigungslink an die ausfüllende Person funktioniert unabhängig davon.',
						'gutenberg-formbuilder'
					)
				)
			);
		}

		return el(
			PanelBody,
			{
				title: __( 'Bestätigungsmail an Absender/in', 'gutenberg-formbuilder' ),
				initialOpen: false,
			},
			el( SelectControl, {
				label: __( 'Modus', 'gutenberg-formbuilder' ),
				help: __(
					'Sofort: Quittung direkt nach dem Absenden. Mit Bestätigungslink: erst Link-Mail, die vollständige Quittung folgt nach dem Klick (Double-Opt-in).',
					'gutenberg-formbuilder'
				),
				value: mode,
				options: [
					{ label: __( 'Keine', 'gutenberg-formbuilder' ), value: 'none' },
					{ label: __( 'Sofort', 'gutenberg-formbuilder' ), value: 'instant' },
					{ label: __( 'Mit Bestätigungslink (Double-Opt-in)', 'gutenberg-formbuilder' ), value: 'doi' },
				],
				onChange: function ( v ) {
					setAttributes( { receiptMode: v === 'instant' || v === 'doi' ? v : 'none' } );
				},
			} ),
			active
				? el( SelectControl, {
						label: __( 'Empfänger (E-Mail-Feld des Formulars)', 'gutenberg-formbuilder' ),
						help: __(
							'Die Bestätigungsmail geht an die Adresse aus diesem Feld der Einsendung.',
							'gutenberg-formbuilder'
						),
						value: attributes.receiptEmailField || '',
						options: recipientOptions,
						onChange: function ( v ) {
							setAttributes( { receiptEmailField: v || '' } );
						},
				  } )
				: null,
			active
				? el( TextControl, {
						label: __( 'Betreff der Bestätigungsmail', 'gutenberg-formbuilder' ),
						help: __(
							'Leer = Standardbetreff. Platzhalter: {{feldname}} und {{label_feldname}}.',
							'gutenberg-formbuilder'
						),
						value: attributes.receiptSubject || '',
						onChange: function ( v ) {
							setAttributes( { receiptSubject: v == null ? '' : String( v ) } );
						},
				  } )
				: null,
			mode === 'doi'
				? el( TextControl, {
						label: __( 'Betreff der Link-Mail', 'gutenberg-formbuilder' ),
						help: __(
							'Leer = Standardbetreff der Bestätigungslink-Mail.',
							'gutenberg-formbuilder'
						),
						value: attributes.doiSubject || '',
						onChange: function ( v ) {
							setAttributes( { doiSubject: v == null ? '' : String( v ) } );
						},
				  } )
				: null,
			notices.length ? el( 'div', null, notices ) : null
		);
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @return {Array<{name:string,label:string}>}
	 */
	function gfbTokenRowsFromAncestorForm( select, clientId ) {
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! clientId ) {
			return [];
		}
		var parents = sel.getBlockParents( clientId, true );
		if ( ! parents || ! parents.length ) {
			return [];
		}
		for ( var i = 0; i < parents.length; i++ ) {
			var block = sel.getBlock( parents[ i ] );
			if ( block && block.name === 'gfb/form' ) {
				var raw = gfbCollectFormFieldTokenRows( block.innerBlocks || [] );
				var seen = {};
				var out = [];
				raw.forEach( function ( r ) {
					if ( seen[ r.name ] ) {
						return;
					}
					seen[ r.name ] = true;
					out.push( r );
				} );
				return out;
			}
		}
		return [];
	}

	var GFB_FIELD_BLOCKS_ORDERED = [
		'gfb/field-text',
		'gfb/field-email',
		'gfb/field-textarea',
		'gfb/field-select',
		'gfb/field-checkbox',
		'gfb/field-number',
		'gfb/field-tel',
		'gfb/field-url',
		'gfb/field-date',
		'gfb/field-time',
		'gfb/field-datetime',
		'gfb/field-radio',
		'gfb/field-range',
		'gfb/field-hidden',
		'gfb/field-file',
		'gfb/field-submit',
	];

	/**
	 * Umlaute und häufige Akzente für technische Namen (POST-Keys) in ASCII approximieren.
	 *
	 * @param {string} s
	 * @return {string}
	 */
	function gfbTransliterateForFieldSlug( s ) {
		var map = {
			ä: 'ae',
			ö: 'oe',
			ü: 'ue',
			Ä: 'ae',
			Ö: 'oe',
			Ü: 'ue',
			ß: 'ss',
			à: 'a',
			á: 'a',
			â: 'a',
			è: 'e',
			é: 'e',
			ê: 'e',
			ì: 'i',
			í: 'i',
			î: 'i',
			ò: 'o',
			ó: 'o',
			ô: 'o',
			ù: 'u',
			ú: 'u',
			û: 'u',
			ç: 'c',
			ñ: 'n',
		};
		var t = String( s );
		var out = '';
		for ( var i = 0; i < t.length; i++ ) {
			var c = t.charAt( i );
			var rep = map[ c ];
			out += typeof rep === 'string' ? rep : c;
		}
		return out;
	}

	/**
	 * Aus Label/Platzhalter einen stabilen Slug (a-z, 0-9, Unterstrich).
	 *
	 * @param {string} raw
	 * @return {string}
	 */
	function gfbSlugifyFieldBase( raw ) {
		var s = gfbTransliterateForFieldSlug( raw );
		s = s.replace( /<[^>]*>/g, '' );
		s = s.toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /_+/g, '_' ).replace( /^_|_$/g, '' );
		if ( s.length === 0 ) {
			s = 'feld';
		}
		if ( s.length > 48 ) {
			s = s.slice( 0, 48 ).replace( /_+$/g, '' );
		}
		return s;
	}

	/**
	 * @param {string} raw
	 * @return {string}
	 */
	function gfbSanitizeFieldNameInput( raw ) {
		return gfbSlugifyFieldBase( raw == null ? '' : String( raw ) );
	}

	/**
	 * Entfernt alten Auto-Suffix aus clientId (Migration).
	 *
	 * @param {string} name
	 * @return {string}
	 */
	function gfbStripLegacyClientIdSuffixFromFieldName( name ) {
		return String( name ).replace( /_[a-f0-9]{8,12}$/i, '' );
	}

	/**
	 * @param {string} labelTrim
	 * @param {string} placeholderTrim
	 * @param {string} fallbackPrefix
	 * @return {string}
	 */
	function gfbBaseFieldNameFromLabel( labelTrim, placeholderTrim, fallbackPrefix ) {
		var baseSource =
			labelTrim !== ''
				? labelTrim
				: placeholderTrim !== ''
					? placeholderTrim
					: fallbackPrefix;
		return gfbSanitizeFieldNameInput( baseSource );
	}

	/**
	 * @param {object} takenKeys Objekt mit belegten Namen als Keys.
	 * @param {string} base
	 * @return {string}
	 */
	function gfbEnsureUniqueFieldName( takenKeys, base ) {
		var b = base && String( base ).trim() !== '' ? String( base ).trim() : 'feld';
		if ( ! takenKeys[ b ] ) {
			return b;
		}
		var i = 2;
		while ( i < 100 ) {
			var candidate = b + '_' + i;
			if ( ! takenKeys[ candidate ] ) {
				return candidate;
			}
			i += 1;
		}
		return b + '_' + Date.now().toString( 36 ).slice( -6 );
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @return {string}
	 */
	function gfbFindAncestorFormClientId( select, clientId ) {
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! clientId ) {
			return '';
		}
		var parents = sel.getBlockParents( clientId, true );
		if ( ! parents || ! parents.length ) {
			return '';
		}
		for ( var i = 0; i < parents.length; i++ ) {
			var block = sel.getBlock( parents[ i ] );
			if ( block && block.name === 'gfb/form' ) {
				return parents[ i ];
			}
		}
		return '';
	}

	/**
	 * @param {*} select
	 * @param {string} formClientId
	 * @param {string} excludeClientId
	 * @return {object<string,boolean>}
	 */
	function gfbCollectFieldNamesInForm( select, formClientId, excludeClientId ) {
		var taken = {};
		var sel = select( 'core/block-editor' );
		if ( ! sel || ! formClientId ) {
			return taken;
		}
		function walk( blocks ) {
			if ( ! blocks || ! blocks.length ) {
				return;
			}
			blocks.forEach( function ( b ) {
				if ( ! b || ! b.name ) {
					return;
				}
				if ( b.name.indexOf( 'gfb/field-' ) === 0 && b.name !== 'gfb/field-submit' ) {
					if ( excludeClientId && b.clientId === excludeClientId ) {
						return;
					}
					var nm = b.attributes && b.attributes.name != null ? String( b.attributes.name ).trim() : '';
					if ( nm ) {
						taken[ nm ] = true;
					}
				}
				if ( b.innerBlocks && b.innerBlocks.length ) {
					walk( b.innerBlocks );
				}
			} );
		}
		var formBlock = sel.getBlock( formClientId );
		if ( formBlock ) {
			walk( formBlock.innerBlocks || [] );
		}
		return taken;
	}

	/**
	 * @param {*} select
	 * @param {string} clientId
	 * @param {string} fieldName
	 * @return {boolean}
	 */
	function gfbIsDuplicateFieldNameInForm( select, clientId, fieldName ) {
		var nm = fieldName == null ? '' : String( fieldName ).trim();
		if ( nm === '' ) {
			return false;
		}
		var formClientId = gfbFindAncestorFormClientId( select, clientId );
		if ( ! formClientId ) {
			return false;
		}
		var count = 0;
		var sel = select( 'core/block-editor' );
		function walk( blocks ) {
			if ( ! blocks || ! blocks.length ) {
				return;
			}
			blocks.forEach( function ( b ) {
				if ( ! b || ! b.name ) {
					return;
				}
				if ( b.name.indexOf( 'gfb/field-' ) === 0 && b.name !== 'gfb/field-submit' ) {
					var n = b.attributes && b.attributes.name != null ? String( b.attributes.name ).trim() : '';
					if ( n === nm ) {
						count += 1;
					}
				}
				if ( b.innerBlocks && b.innerBlocks.length ) {
					walk( b.innerBlocks );
				}
			} );
		}
		var formBlock = sel.getBlock( formClientId );
		if ( formBlock ) {
			walk( formBlock.innerBlocks || [] );
		}
		return count > 1;
	}

	/**
	 * Vergibt stabilen technischen Namen: leer/Legacy/Duplikat → einmalig; sonst unverändert.
	 *
	 * @param {Record<string, unknown>} attributes
	 * @param {function(Record<string, unknown>):void} setAttributes
	 * @param {string} clientId
	 * @param {boolean} includePlaceholder
	 * @param {string} fallbackPrefix
	 * @param {string[]} legacyNames
	 */
	function syncFieldNameBinding( attributes, setAttributes, clientId, includePlaceholder, fallbackPrefix, legacyNames ) {
		useEffect(
			function () {
				var bound = attributes.nameClientId != null ? String( attributes.nameClientId ) : '';
				var current = attributes.name != null ? String( attributes.name ).trim() : '';
				var legacy = legacyNames.indexOf( current ) !== -1;
				var needsBind = bound !== clientId || current === '' || legacy;
				if ( ! needsBind ) {
					return;
				}

				var select = wp.data.select;
				var formClientId = gfbFindAncestorFormClientId( select, clientId );
				if ( ! formClientId ) {
					return;
				}

				var lab = attributes.label != null ? String( attributes.label ).trim() : '';
				var ph = '';
				if ( includePlaceholder && attributes.placeholder != null ) {
					ph = String( attributes.placeholder ).trim();
				}

				var base;
				if ( current !== '' && ! legacy ) {
					base = gfbStripLegacyClientIdSuffixFromFieldName( current );
				} else {
					base = gfbBaseFieldNameFromLabel( lab, ph, fallbackPrefix );
				}

				var taken = gfbCollectFieldNamesInForm( select, formClientId, clientId );
				var unique = gfbEnsureUniqueFieldName( taken, base );
				setAttributes( { name: unique, nameClientId: clientId } );
			},
			[
				attributes.label,
				attributes.placeholder,
				attributes.name,
				attributes.nameClientId,
				clientId,
				setAttributes,
				includePlaceholder,
				fallbackPrefix,
				legacyNames,
			]
		);
	}

	/**
	 * Inspector: technischer Feldname + Duplikat-Hinweis innerhalb des Formulars.
	 *
	 * @param {object} props
	 * @return {*}
	 */
	function GfbFieldNameInspector( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var name = attributes.name != null ? String( attributes.name ).trim() : '';
		var isDuplicate = useSelect(
			function ( select ) {
				return gfbIsDuplicateFieldNameInForm( select, clientId, name );
			},
			[ clientId, name ]
		);

		return el(
			wp.element.Fragment,
			null,
			el( TextControl, {
				label: __( 'Eindeutiger Feldname', 'gutenberg-formbuilder' ),
				value: attributes.name || '',
				onChange: function ( value ) {
					var next = gfbSanitizeFieldNameInput( value );
					setAttributes( { name: next, nameClientId: clientId } );
				},
			} ),
			isDuplicate
				? el( Notice, {
						status: 'error',
						isDismissible: false,
				  }, __( 'Dieser Feldname existiert in diesem Formular bereits. Bitte anpassen, sonst schlägt das Absenden fehl.', 'gutenberg-formbuilder' ) )
				: null
		);
	}

	function buildFieldControls( attributes, setAttributes, includePlaceholder ) {
		var controls = [
			el(
				TextControl,
				{
					label: __( 'Label', 'gutenberg-formbuilder' ),
					value: attributes.label || '',
					onChange: function ( value ) {
						setAttributes( { label: value } );
					},
				}
			),
		];

		if ( includePlaceholder ) {
			controls.push(
				el( TextControl, {
					label: __( 'Platzhalter', 'gutenberg-formbuilder' ),
					value: attributes.placeholder || '',
					onChange: function ( value ) {
						setAttributes( { placeholder: value } );
					},
				} )
			);
		}

		controls.push(
			el( ToggleControl, {
				label: __( 'Pflichtfeld', 'gutenberg-formbuilder' ),
				checked: !! attributes.required,
				onChange: function ( value ) {
					setAttributes( { required: value } );
				},
			} )
		);

		// Sensitivität: verschlüsselt-im-Storage-Toggle. Server prüft das gleiche Flag.
		if ( typeof attributes.sensitive !== 'undefined' ) {
			controls.push(
				el(
					ToggleControl,
					{
						label: __( 'Vertraulich', 'gutenberg-formbuilder' ),
						help: __(
							'Feldwert kann nur mit entsprechender Berechtigung eingesehen werden. Das gilt nicht für den Versand per Mail, da ist alles zu sehen.',
							'gutenberg-formbuilder'
						),
						checked: !! attributes.sensitive,
						onChange: function ( value ) {
							setAttributes( { sensitive: !! value } );
						},
					}
				)
			);
		}

		return controls;
	}

	function buildMinMaxStepInspector( attributes, setAttributes, includeStep ) {
		var rows = [
			el( TextControl, {
				label: __( 'Min', 'gutenberg-formbuilder' ),
				value: attributes.min || '',
				onChange: function ( v ) {
					setAttributes( { min: v } );
				},
			} ),
			el( TextControl, {
				label: __( 'Max', 'gutenberg-formbuilder' ),
				value: attributes.max || '',
				onChange: function ( v ) {
					setAttributes( { max: v } );
				},
			} ),
		];
		if ( includeStep ) {
			rows.push(
				el( TextControl, {
					label: __( 'Schritt', 'gutenberg-formbuilder' ),
					value: attributes.step || '',
					onChange: function ( v ) {
						setAttributes( { step: v } );
					},
				} )
			);
		}
		return rows;
	}

	function buildDateMinMaxInspector( attributes, setAttributes ) {
		return [
			el( TextControl, {
				label: __( 'Frühestes Datum', 'gutenberg-formbuilder' ),
				value: attributes.min || '',
				onChange: function ( v ) {
					setAttributes( { min: v } );
				},
			} ),
			el( TextControl, {
				label: __( 'Spätestes Datum', 'gutenberg-formbuilder' ),
				value: attributes.max || '',
				onChange: function ( v ) {
					setAttributes( { max: v } );
				},
			} ),
		];
	}

	/**
	 * @param {Record<string, unknown>} attributes
	 * @param {function(Record<string, unknown>):void} setAttributes
	 * @param {string} help
	 */
	function buildOptionalDefaultValueControl( attributes, setAttributes, help ) {
		return el( TextControl, {
			label: __( 'Voreingestellter Wert (optional)', 'gutenberg-formbuilder' ),
			help: help,
			value: attributes.defaultValue != null ? String( attributes.defaultValue ) : '',
			onChange: function ( v ) {
				setAttributes( { defaultValue: v != null ? String( v ) : '' } );
			},
		} );
	}

	function formHasCustomColors( attrs ) {
		return !!(
			( attrs.colorLabel && String( attrs.colorLabel ).trim() ) ||
			( attrs.colorText && String( attrs.colorText ).trim() ) ||
			( attrs.colorPlaceholder && String( attrs.colorPlaceholder ).trim() ) ||
			( attrs.colorFieldBg && String( attrs.colorFieldBg ).trim() ) ||
			( attrs.colorBorder && String( attrs.colorBorder ).trim() ) ||
			( attrs.colorFocus && String( attrs.colorFocus ).trim() ) ||
			( attrs.colorButtonBg && String( attrs.colorButtonBg ).trim() ) ||
			( attrs.colorButtonText && String( attrs.colorButtonText ).trim() ) ||
			( attrs.darkColorLabel && String( attrs.darkColorLabel ).trim() ) ||
			( attrs.darkColorText && String( attrs.darkColorText ).trim() ) ||
			( attrs.darkColorPlaceholder && String( attrs.darkColorPlaceholder ).trim() ) ||
			( attrs.darkColorFieldBg && String( attrs.darkColorFieldBg ).trim() ) ||
			( attrs.darkColorBorder && String( attrs.darkColorBorder ).trim() ) ||
			( attrs.darkColorFocus && String( attrs.darkColorFocus ).trim() ) ||
			( attrs.darkColorButtonBg && String( attrs.darkColorButtonBg ).trim() ) ||
			( attrs.darkColorButtonText && String( attrs.darkColorButtonText ).trim() ) ||
			( attrs.colorFormShell && String( attrs.colorFormShell ).trim() ) ||
			( attrs.darkColorFormShell && String( attrs.darkColorFormShell ).trim() )
		);
	}

	/**
	 * Welche Palette im Editor für Feld-Overrides genutzt wird (bei „Automatisch“: System).
	 *
	 * @param {string|undefined} appearanceMode
	 * @return {'light'|'dark'}
	 */
	function resolveActivePalette( appearanceMode ) {
		var mode = appearanceMode || 'auto';
		if ( mode === 'light' ) {
			return 'light';
		}
		if ( mode === 'dark' ) {
			return 'dark';
		}
		if (
			typeof window !== 'undefined' &&
			window.matchMedia &&
			window.matchMedia( '(prefers-color-scheme: dark)' ).matches
		) {
			return 'dark';
		}
		return 'light';
	}

	function mergeFieldColorAttrs( fieldAttrs, ctx ) {
		ctx = ctx || {};
		var mode = ctx['gfb/appearanceMode'] || 'theme';
		function pickTheme( key, ctxLight, ctxDark ) {
			var fv = fieldAttrs[ key ];
			if ( fv && String( fv ).trim() !== '' ) {
				return fv;
			}
			var light = ctx[ ctxLight ];
			var dark = ctx[ ctxDark ];
			if ( light && String( light ).trim() !== '' ) {
				return light;
			}
			if ( dark && String( dark ).trim() !== '' ) {
				return dark;
			}
			return '';
		}
		if ( mode === 'theme' ) {
			return {
				colorLabel: pickTheme( 'colorLabel', 'gfb/colorLabel', 'gfb/darkColorLabel' ),
				colorText: pickTheme( 'colorText', 'gfb/colorText', 'gfb/darkColorText' ),
				colorPlaceholder: pickTheme( 'colorPlaceholder', 'gfb/colorPlaceholder', 'gfb/darkColorPlaceholder' ),
				colorFieldBg: pickTheme( 'colorFieldBg', 'gfb/colorFieldBg', 'gfb/darkColorFieldBg' ),
				colorBorder: pickTheme( 'colorBorder', 'gfb/colorBorder', 'gfb/darkColorBorder' ),
				colorFocus: pickTheme( 'colorFocus', 'gfb/colorFocus', 'gfb/darkColorFocus' ),
				colorButtonBg: pickTheme( 'colorButtonBg', 'gfb/colorButtonBg', 'gfb/darkColorButtonBg' ),
				colorButtonText: pickTheme( 'colorButtonText', 'gfb/colorButtonText', 'gfb/darkColorButtonText' ),
			};
		}
		var palette = resolveActivePalette( mode );
		function pick( key, ctxLight, ctxDark ) {
			var fv = fieldAttrs[ key ];
			if ( fv && String( fv ).trim() !== '' ) {
				return fv;
			}
			var light = ctx[ ctxLight ];
			var dark = ctx[ ctxDark ];
			if ( palette === 'dark' ) {
				if ( dark && String( dark ).trim() !== '' ) {
					return dark;
				}
				return light && String( light ).trim() !== '' ? light : '';
			}
			if ( light && String( light ).trim() !== '' ) {
				return light;
			}
			return dark && String( dark ).trim() !== '' ? dark : '';
		}
		return {
			colorLabel: pick( 'colorLabel', 'gfb/colorLabel', 'gfb/darkColorLabel' ),
			colorText: pick( 'colorText', 'gfb/colorText', 'gfb/darkColorText' ),
			colorPlaceholder: pick( 'colorPlaceholder', 'gfb/colorPlaceholder', 'gfb/darkColorPlaceholder' ),
			colorFieldBg: pick( 'colorFieldBg', 'gfb/colorFieldBg', 'gfb/darkColorFieldBg' ),
			colorBorder: pick( 'colorBorder', 'gfb/colorBorder', 'gfb/darkColorBorder' ),
			colorFocus: pick( 'colorFocus', 'gfb/colorFocus', 'gfb/darkColorFocus' ),
			colorButtonBg: pick( 'colorButtonBg', 'gfb/colorButtonBg', 'gfb/darkColorButtonBg' ),
			colorButtonText: pick( 'colorButtonText', 'gfb/colorButtonText', 'gfb/darkColorButtonText' ),
		};
	}

	function colorAttrsToStyleObject( attrs ) {
		var o = {};
		if ( attrs.colorLabel && String( attrs.colorLabel ).trim() !== '' ) {
			o['--gfb-label'] = attrs.colorLabel;
		}
		if ( attrs.colorText && String( attrs.colorText ).trim() !== '' ) {
			o['--gfb-text'] = attrs.colorText;
		}
		if ( attrs.colorPlaceholder && String( attrs.colorPlaceholder ).trim() !== '' ) {
			o['--gfb-placeholder'] = attrs.colorPlaceholder;
		}
		if ( attrs.colorFieldBg && String( attrs.colorFieldBg ).trim() !== '' ) {
			o['--gfb-bg'] = attrs.colorFieldBg;
		}
		if ( attrs.colorBorder && String( attrs.colorBorder ).trim() !== '' ) {
			o['--gfb-border'] = attrs.colorBorder;
		}
		if ( attrs.colorFocus && String( attrs.colorFocus ).trim() !== '' ) {
			o['--gfb-border-focus'] = attrs.colorFocus;
		}
		if ( attrs.colorButtonBg && String( attrs.colorButtonBg ).trim() !== '' ) {
			o['--gfb-submit-bg'] = attrs.colorButtonBg;
		}
		if ( attrs.colorButtonText && String( attrs.colorButtonText ).trim() !== '' ) {
			o['--gfb-submit-text'] = attrs.colorButtonText;
		}
		return Object.keys( o ).length ? o : undefined;
	}

	/**
	 * Inline-Styles für .gfb-form-wrapper (wie PHP): Hell- und Dunkel-Variablen.
	 *
	 * @param {Object} attrs Formular-Attribute.
	 * @return {Object|undefined}
	 */
	function formWrapperColorStyleObject( attrs ) {
		var o = {};
		var lightPairs = [
			[ 'colorLabel', '--gfb-light-label' ],
			[ 'colorText', '--gfb-light-text' ],
			[ 'colorPlaceholder', '--gfb-light-placeholder' ],
			[ 'colorFieldBg', '--gfb-light-bg' ],
			[ 'colorBorder', '--gfb-light-border' ],
			[ 'colorFocus', '--gfb-light-border-focus' ],
			[ 'colorButtonBg', '--gfb-light-submit-bg' ],
			[ 'colorButtonText', '--gfb-light-submit-text' ],
			[ 'colorFormShell', '--gfb-light-form-shell' ],
		];
		var darkPairs = [
			[ 'darkColorLabel', '--gfb-dark-label' ],
			[ 'darkColorText', '--gfb-dark-text' ],
			[ 'darkColorPlaceholder', '--gfb-dark-placeholder' ],
			[ 'darkColorFieldBg', '--gfb-dark-bg' ],
			[ 'darkColorBorder', '--gfb-dark-border' ],
			[ 'darkColorFocus', '--gfb-dark-border-focus' ],
			[ 'darkColorButtonBg', '--gfb-dark-submit-bg' ],
			[ 'darkColorButtonText', '--gfb-dark-submit-text' ],
			[ 'darkColorFormShell', '--gfb-dark-form-shell' ],
		];
		lightPairs.forEach( function ( pair ) {
			var v = attrs[ pair[ 0 ] ];
			if ( v && String( v ).trim() !== '' ) {
				o[ pair[ 1 ] ] = v;
			}
		} );
		darkPairs.forEach( function ( pair ) {
			var v = attrs[ pair[ 0 ] ];
			if ( v && String( v ).trim() !== '' ) {
				o[ pair[ 1 ] ] = v;
			}
		} );
		return Object.keys( o ).length ? o : undefined;
	}

	function buildMergedFieldColorStyle( fieldAttrs, ctx ) {
		return colorAttrsToStyleObject( mergeFieldColorAttrs( fieldAttrs, ctx ) );
	}

	function buildFieldColorOverrideStyle( fieldAttrs ) {
		return colorAttrsToStyleObject( {
			colorLabel: fieldAttrs.colorLabel || '',
			colorText: fieldAttrs.colorText || '',
			colorPlaceholder: fieldAttrs.colorPlaceholder || '',
			colorFieldBg: fieldAttrs.colorFieldBg || '',
			colorBorder: fieldAttrs.colorBorder || '',
			colorFocus: fieldAttrs.colorFocus || '',
			colorButtonBg: fieldAttrs.colorButtonBg || '',
			colorButtonText: fieldAttrs.colorButtonText || '',
		} );
	}

	/**
	 * @param {unknown} label
	 * @return {string}
	 */
	function gfbTrimmedFieldLabel( label ) {
		if ( label == null ) {
			return '';
		}
		return String( label ).trim();
	}

	/**
	 * Editor-Vorschau: kein Platzhalter-Label — nur rendern, wenn Text gesetzt.
	 *
	 * @param {string} tag
	 * @param {Record<string, unknown>|null|undefined} props
	 * @param {unknown} labelAttr
	 * @return {*|null}
	 */
	function gfbEditorLabelIfAny( tag, props, labelAttr, required ) {
		var t = gfbTrimmedFieldLabel( labelAttr );
		if ( ! t ) {
			return null;
		}
		if ( required ) {
			return el( tag, props || null, t, el( 'span', { className: 'gfb-required', 'aria-hidden': 'true' }, ' *' ) );
		}
		return el( tag, props || null, t );
	}

	/**
	 * @param {unknown} nameAttr
	 * @return {{ for: string }|null}
	 */
	function gfbLabelForProps( nameAttr ) {
		var n = nameAttr != null && String( nameAttr ).trim() !== '' ? String( nameAttr ).trim() : '';
		return n ? { for: n } : null;
	}

	/**
	 * Save-Markup: <label for> nur wenn nicht leer (nach trim).
	 *
	 * @param {string} forId
	 * @param {unknown} labelAttr
	 * @return {*|null}
	 */
	function gfbSaveLabelIfAny( forId, labelAttr ) {
		var t = gfbTrimmedFieldLabel( labelAttr );
		if ( ! t ) {
			return null;
		}
		return el( 'label', { for: forId }, t );
	}

	/**
	 * @param {Array<{ name?: string, attributes?: Record<string, unknown>, innerBlocks?: unknown[] }>|undefined} blocks
	 * @return {boolean}
	 */
	/** Irgendein gfb/form-Block → Editor-Stylesheet für Theme-Vorschau nötig. */
	function gfbEditorBlockTreeHasAnyGfbForm( blocks ) {
		if ( ! blocks || ! blocks.length ) {
			return false;
		}
		for ( var i = 0; i < blocks.length; i++ ) {
			var b = blocks[ i ];
			if ( b.name === 'gfb/form' ) {
				return true;
			}
			if ( b.innerBlocks && b.innerBlocks.length && gfbEditorBlockTreeHasAnyGfbForm( b.innerBlocks ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param {(doc: Document) => void} callback
	 */
	function gfbForEachEditorCanvasDocument( callback ) {
		var seen = [];
		function visit( doc ) {
			if ( ! doc || ! doc.head || seen.indexOf( doc ) !== -1 ) {
				return;
			}
			var hasCanvas =
				( doc.querySelector && doc.querySelector( '.editor-styles-wrapper' ) ) ||
				( doc.querySelector && doc.querySelector( '.editor-canvas__iframe-body' ) ) ||
				( doc.querySelector && doc.querySelector( '.is-root-container' ) );
			if ( hasCanvas ) {
				seen.push( doc );
				callback( doc );
			}
		}
		visit( document );
		var iframes = document.querySelectorAll( 'iframe' );
		for ( var j = 0; j < iframes.length; j++ ) {
			try {
				var idoc = iframes[ j ].contentDocument;
				visit( idoc );
			} catch ( err ) {
				/* Cross-Origin */
			}
		}
	}

	function gfbSyncEditorFormStylesheet() {
		var assets = typeof window !== 'undefined' ? window.gfbEditorAssets : null;
		var formBase =
			assets && assets.editorCanvasFormStylesUrl ? assets.editorCanvasFormStylesUrl : '';
		var chromeBase =
			assets && assets.editorChromeStylesUrl
				? assets.editorChromeStylesUrl
				: assets && assets.editorFormStylesUrl
					? assets.editorFormStylesUrl
					: '';
		if ( ! assets || ( ! formBase && ! chromeBase ) ) {
			return;
		}
		var needs = false;
		try {
			var sel = wp.data.select( 'core/block-editor' );
			if ( sel && typeof sel.getBlocks === 'function' ) {
				needs = gfbEditorBlockTreeHasAnyGfbForm( sel.getBlocks() );
			}
		} catch ( e0 ) {
			needs = false;
		}
		var ver = assets.version ? encodeURIComponent( String( assets.version ) ) : '';
		function withVer( base ) {
			if ( ! base ) {
				return '';
			}
			var sep = base.indexOf( '?' ) === -1 ? '?' : '&';
			return base + ( ver ? sep + 'ver=' + ver : '' );
		}
		var formHref = withVer( formBase );
		var chromeHref = withVer( chromeBase );
		gfbForEachEditorCanvasDocument( function ( doc ) {
			function upsertLink( id, href ) {
				if ( ! href ) {
					var old = doc.getElementById( id );
					if ( old && old.parentNode ) {
						old.parentNode.removeChild( old );
					}
					return;
				}
				if ( ! needs ) {
					var rm = doc.getElementById( id );
					if ( rm && rm.parentNode ) {
						rm.parentNode.removeChild( rm );
					}
					return;
				}
				var existing = doc.getElementById( id );
				if ( ! existing ) {
					var link = doc.createElement( 'link' );
					link.id = id;
					link.rel = 'stylesheet';
					link.href = href;
					doc.head.appendChild( link );
				} else if ( existing.getAttribute( 'href' ) !== href ) {
					existing.setAttribute( 'href', href );
				}
			}
			upsertLink( 'gfb-editor-canvas-form-stylesheet', formHref );
			upsertLink( 'gfb-editor-chrome-stylesheet', chromeHref );
			/* Alte Einbindung (ein Stylesheet) entfernen. */
			var legacy = doc.getElementById( 'gfb-editor-form-stylesheet' );
			if ( legacy && legacy.parentNode ) {
				legacy.parentNode.removeChild( legacy );
			}
		} );
	}

	function createFormColorSettings( attributes, setAttributes ) {
		var appearance = attributes.appearanceMode || 'theme';
		var rows = [
			{
				label: __( 'Label', 'gutenberg-formbuilder' ),
				value: attributes.colorLabel || '',
				onChange: function ( v ) {
					setAttributes( { colorLabel: v || '' } );
				},
			},
			{
				label: __( 'Eingabetext', 'gutenberg-formbuilder' ),
				value: attributes.colorText || '',
				onChange: function ( v ) {
					setAttributes( { colorText: v || '' } );
				},
			},
			{
				label: __( 'Platzhalter', 'gutenberg-formbuilder' ),
				value: attributes.colorPlaceholder || '',
				onChange: function ( v ) {
					setAttributes( { colorPlaceholder: v || '' } );
				},
			},
			{
				label: __( 'Feldhintergrund', 'gutenberg-formbuilder' ),
				value: attributes.colorFieldBg || '',
				onChange: function ( v ) {
					setAttributes( { colorFieldBg: v || '' } );
				},
			},
			{
				label: __( 'Rahmen', 'gutenberg-formbuilder' ),
				value: attributes.colorBorder || '',
				onChange: function ( v ) {
					setAttributes( { colorBorder: v || '' } );
				},
			},
			{
				label: __( 'Fokus (Rahmen, Schieberegler)', 'gutenberg-formbuilder' ),
				value: attributes.colorFocus || '',
				onChange: function ( v ) {
					setAttributes( { colorFocus: v || '' } );
				},
			},
			{
				label: __( 'Button-Hintergrund', 'gutenberg-formbuilder' ),
				value: attributes.colorButtonBg || '',
				onChange: function ( v ) {
					setAttributes( { colorButtonBg: v || '' } );
				},
			},
			{
				label: __( 'Button-Text', 'gutenberg-formbuilder' ),
				value: attributes.colorButtonText || '',
				onChange: function ( v ) {
					setAttributes( { colorButtonText: v || '' } );
				},
			},
		];
		if ( appearance !== 'theme' ) {
			rows.push( {
				label: __( 'Hintergrund Formularbereich', 'gutenberg-formbuilder' ),
				value: attributes.colorFormShell || '',
				onChange: function ( v ) {
					setAttributes( { colorFormShell: v || '' } );
				},
			} );
		}
		return rows;
	}

	function createDarkFormColorSettings( attributes, setAttributes ) {
		var appearance = attributes.appearanceMode || 'theme';
		var rows = [
			{
				label: __( 'Label', 'gutenberg-formbuilder' ),
				value: attributes.darkColorLabel || '',
				onChange: function ( v ) {
					setAttributes( { darkColorLabel: v || '' } );
				},
			},
			{
				label: __( 'Eingabetext', 'gutenberg-formbuilder' ),
				value: attributes.darkColorText || '',
				onChange: function ( v ) {
					setAttributes( { darkColorText: v || '' } );
				},
			},
			{
				label: __( 'Platzhalter', 'gutenberg-formbuilder' ),
				value: attributes.darkColorPlaceholder || '',
				onChange: function ( v ) {
					setAttributes( { darkColorPlaceholder: v || '' } );
				},
			},
			{
				label: __( 'Feldhintergrund', 'gutenberg-formbuilder' ),
				value: attributes.darkColorFieldBg || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFieldBg: v || '' } );
				},
			},
			{
				label: __( 'Rahmen', 'gutenberg-formbuilder' ),
				value: attributes.darkColorBorder || '',
				onChange: function ( v ) {
					setAttributes( { darkColorBorder: v || '' } );
				},
			},
			{
				label: __( 'Fokus (Rahmen, Schieberegler)', 'gutenberg-formbuilder' ),
				value: attributes.darkColorFocus || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFocus: v || '' } );
				},
			},
			{
				label: __( 'Button-Hintergrund', 'gutenberg-formbuilder' ),
				value: attributes.darkColorButtonBg || '',
				onChange: function ( v ) {
					setAttributes( { darkColorButtonBg: v || '' } );
				},
			},
			{
				label: __( 'Button-Text', 'gutenberg-formbuilder' ),
				value: attributes.darkColorButtonText || '',
				onChange: function ( v ) {
					setAttributes( { darkColorButtonText: v || '' } );
				},
			},
		];
		if ( appearance !== 'theme' ) {
			rows.push( {
				label: __( 'Hintergrund Formularbereich', 'gutenberg-formbuilder' ),
				value: attributes.darkColorFormShell || '',
				onChange: function ( v ) {
					setAttributes( { darkColorFormShell: v || '' } );
				},
			} );
		}
		return rows;
	}

	registerBlockType( 'gfb/form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var appearance = attributes.appearanceMode || 'theme';
			var wrapperClassNames = [ 'gfb-form-wrapper' ];
			if ( appearance === 'theme' && formHasCustomColors( attributes ) ) {
				wrapperClassNames.push( 'gfb-form-colors-custom' );
			}
			if ( appearance !== 'theme' ) {
				if ( gfbAttrLooksLikeCssGradient( attributes.colorFormShell ) ) {
					wrapperClassNames.push( 'gfb-form-shell-gradient--light' );
				}
				if ( gfbAttrLooksLikeCssGradient( attributes.darkColorFormShell ) ) {
					wrapperClassNames.push( 'gfb-form-shell-gradient--dark' );
				}
			}
			var formColorStyle = formWrapperColorStyleObject( attributes );
			var formBlockProps = useBlockProps( {
				className: wrapperClassNames.join( ' ' ),
				style: formColorStyle,
				'data-gfb-appearance': appearance,
			} );

			var allowedInnerBlocks = useSelect( function ( select ) {
				try {
					var types = select( 'core/blocks' ).getBlockTypes();
					if ( ! types || ! types.length ) {
						return true;
					}
					var names = types
						.map( function ( t ) {
							return t.name;
						} )
						.filter( function ( name ) {
							return name !== 'gfb/form';
						} );
					var fieldSet = {};
					GFB_FIELD_BLOCKS_ORDERED.forEach( function ( n ) {
						fieldSet[ n ] = true;
					} );
					var fieldsFirst = GFB_FIELD_BLOCKS_ORDERED.filter( function ( n ) {
						return names.indexOf( n ) !== -1;
					} );
					var rest = names
						.filter( function ( n ) {
							return ! fieldSet[ n ] && n !== 'gfb/form-success';
						} )
						.sort();
					return fieldsFirst.concat( [ 'gfb/form-success' ] ).concat( rest );
				} catch ( err ) {
					return true;
				}
			}, [] );

			var publishedPages = useSelect( function ( select ) {
				try {
					return select( 'core' ).getEntityRecords( 'postType', 'page', {
						per_page: 100,
						status: 'publish',
						orderby: 'title',
						order: 'asc',
					} );
				} catch ( err2 ) {
					return null;
				}
			}, [] );

			var emailFieldRows = useSelect(
				function ( select ) {
					try {
						var block = select( 'core/block-editor' ).getBlock( props.clientId );
						if ( ! block ) {
							return [];
						}
						var raw = gfbCollectFormEmailFieldRows( block.innerBlocks || [] );
						var seen = {};
						var out = [];
						raw.forEach( function ( r ) {
							if ( seen[ r.name ] ) {
								return;
							}
							seen[ r.name ] = true;
							out.push( r );
						} );
						return out;
					} catch ( err3 ) {
						return [];
					}
				},
				[ props.clientId ]
			);

			/* Checkbox-Felder für das Einwilligungs-Feld (Integrations-Hooks). */
			var checkboxFieldRows = useSelect(
				function ( select ) {
					try {
						var block = select( 'core/block-editor' ).getBlock( props.clientId );
						if ( ! block ) {
							return [];
						}
						var raw = gfbCollectFormCheckboxFieldRows( block.innerBlocks || [] );
						var seen = {};
						var out = [];
						raw.forEach( function ( r ) {
							if ( seen[ r.name ] ) {
								return;
							}
							seen[ r.name ] = true;
							out.push( r );
						} );
						return out;
					} catch ( errConsent ) {
						return [];
					}
				},
				[ props.clientId ]
			);

			/* Zustand für die Editor-Warnungen der Bestätigungsmail. */
			var receiptInfo = useSelect(
				function ( select ) {
					var fallback = {
						hasSensitive: false,
						hasReceiptBlock: false,
						hasDoiBlock: false,
					};
					try {
						var block = select( 'core/block-editor' ).getBlock( props.clientId );
						if ( ! block ) {
							return fallback;
						}
						var inner = block.innerBlocks || [];
						return {
							hasSensitive: gfbBlocksHaveSensitiveField( inner ),
							hasReceiptBlock: !! gfbFindBlockOfType( inner, 'gfb/receipt-mail' ),
							hasDoiBlock: !! gfbFindBlockOfType( inner, 'gfb/doi-mail' ),
						};
					} catch ( errReceipt ) {
						return fallback;
					}
				},
				[ props.clientId ]
			);

			var thankYouPageOptions = [
				{
					label: __( 'Formularseite mit Erfolgshinweis (Standard)', 'gutenberg-formbuilder' ),
					value: '',
				},
			];
			if ( publishedPages && Array.isArray( publishedPages ) ) {
				publishedPages.forEach( function ( p ) {
					var title = p.title && p.title.rendered ? p.title.rendered : '#' + String( p.id );
					thankYouPageOptions.push( { label: title, value: String( p.id ) } );
				} );
			}

			syncFormInstance( attributes, setAttributes, props.clientId );
			syncEmailRecipientsOnFormBlur( attributes, setAttributes, props.clientId );

			var innerBlocksProps = useInnerBlocksProps(
				{
					className: 'gfb-form-fields',
				},
				{
					allowedBlocks: allowedInnerBlocks,
					template: [
						[ 'gfb/field-text' ],
						[ 'gfb/field-email' ],
						[ 'gfb/field-submit' ],
					],
					templateLock: false,
				}
			);

			return el(
				'div',
				formBlockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Formular', 'gutenberg-formbuilder' ),
							initialOpen: false,
						},
						el(
							'p',
							{
								className: 'gfb-editor-sidebar-form-id',
								style: {
									marginTop: 0,
									marginBottom: 0,
									fontSize: '11px',
									lineHeight: 1.45,
								},
							},
							__( 'Form-ID', 'gutenberg-formbuilder' ),
							el( 'br', null ),
							el(
								'code',
								{
									style: {
										display: 'block',
										marginTop: '4px',
										fontSize: '11px',
										wordBreak: 'break-all',
									},
								},
								attributes.formId || '—'
							),
							el(
								'p',
								{
									className: 'components-base-control__help',
									style: { marginTop: '8px', marginBottom: 0 },
								},
								__(
									'Eindeutige Kennung dieses Formulars (bei Duplizieren des Formularblocks neu). Einsendungen und Payload-Auswertung werden immer dieser ID zugeordnet.',
									'gutenberg-formbuilder'
								)
							)
						)
					),
					el(
						PanelBody,
						{
							title: __( 'Formulareinstellungen', 'gutenberg-formbuilder' ),
							initialOpen: true,
						},
						el( TextControl, {
							label: __( 'Anzeigename (optional)', 'gutenberg-formbuilder' ),
							help: __( 'Nur für die Backend-Übersicht und den Standard-E-Mail-Betreff (wenn kein eigener Betreff gesetzt ist); wird nicht im Formular dargestellt.', 'gutenberg-formbuilder' ),
							value: attributes.formTitle || '',
							onChange: function ( v ) {
								setAttributes( { formTitle: v == null ? '' : String( v ) } );
							},
						} ),
						el( Notice, { status: 'info', isDismissible: false }, __( 'Feldwerte werden im Browser als lokale Entwürfe zwischengespeichert.', 'gutenberg-formbuilder' ) ),
						el( ToggleControl, {
							label: __( 'Lokale Entwurfsspeicherung aktivieren', 'gutenberg-formbuilder' ),
							checked: attributes.draftEnabled !== false,
							onChange: function ( value ) {
								setAttributes( { draftEnabled: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Wiederherstellung', 'gutenberg-formbuilder' ),
							value: attributes.restoreMode || 'auto',
							options: [
								{ label: __( 'Automatisch', 'gutenberg-formbuilder' ), value: 'auto' },
								{ label: __( 'Nachfragen', 'gutenberg-formbuilder' ), value: 'prompt' },
							],
							onChange: function ( value ) {
								setAttributes( { restoreMode: value } );
							},
							disabled: attributes.draftEnabled === false,
						} ),
						__experimentalNumberControl
							? el( __experimentalNumberControl, {
									label: __( 'Entwurf läuft ab nach (Tage)', 'gutenberg-formbuilder' ),
									value: attributes.draftTtlDays || 7,
									onChange: function ( value ) {
										var parsed = parseInt( value, 10 );
										if ( Number.isNaN( parsed ) ) {
											return;
										}
										if ( parsed < 1 ) {
											parsed = 1;
										}
										if ( parsed > 30 ) {
											parsed = 30;
										}
										setAttributes( { draftTtlDays: parsed } );
									},
									min: 1,
									max: 30,
									disabled: attributes.draftEnabled === false,
							  } )
							: null,
						el( ToggleControl, {
							label: __( 'Button „Entwurf löschen“ anzeigen', 'gutenberg-formbuilder' ),
							checked: attributes.showDraftReset !== false,
							onChange: function ( value ) {
								setAttributes( { showDraftReset: value } );
							},
							disabled: attributes.draftEnabled === false,
						} ),
						el( Notice, {
							status: 'info',
							isDismissible: false,
						}, __( 'Optional den Block „Rückmeldung“ einfügen: Bei Erfolg ohne Folgeseite erscheint sein Inhalt statt des Formulars. Platzhalter im Text: {{feldname}} (technischer Feldname).', 'gutenberg-formbuilder' ) ),
						el( SelectControl, {
							label: __( 'Folgeseite nach erfolgreichem Absenden', 'gutenberg-formbuilder' ),
							help: __( 'Öffentlich sichtbare Seite. Ohne Auswahl bleibt die Besucherin auf der Formularseite (Hinweis oben).', 'gutenberg-formbuilder' ),
							value: attributes.thankYouPageId ? String( attributes.thankYouPageId ) : '',
							options: thankYouPageOptions,
							onChange: function ( v ) {
								var n = parseInt( v, 10 );
								setAttributes( { thankYouPageId: v === '' || Number.isNaN( n ) ? 0 : n } );
							},
						} )
					),
					renderEmailNotificationControls( attributes, setAttributes, emailFieldRows || [] ),
					renderReceiptMailControls( attributes, setAttributes, emailFieldRows || [], receiptInfo ),
					renderConsentControls( attributes, setAttributes, checkboxFieldRows || [] ),
					el( PanelBody, {
						title: __( 'Spam-Schutz (CAPTCHA)', 'gutenberg-formbuilder' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'CAPTCHA für dieses Formular', 'gutenberg-formbuilder' ),
						help: __( 'Steuert, ob auf diesem Formular ein CAPTCHA erscheint. Voraussetzung: CAPTCHA ist unter «Sicherheit & Einstellungen» global aktiviert und konfiguriert.', 'gutenberg-formbuilder' ),
						value: attributes.captchaMode || 'inherit',
						options: [
							{ label: __( 'Von globaler Einstellung übernehmen', 'gutenberg-formbuilder' ), value: 'inherit' },
							{ label: __( 'Immer an', 'gutenberg-formbuilder' ), value: 'on' },
							{ label: __( 'Immer aus', 'gutenberg-formbuilder' ), value: 'off' },
						],
						onChange: function ( value ) {
							setAttributes( { captchaMode: value || 'inherit' } );
						},
					} ) ),
					el( PanelBody, {
						title: __( 'Erscheinungsbild', 'gutenberg-formbuilder' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'Farbmodus', 'gutenberg-formbuilder' ),
						value: appearance,
						help:
							appearance === 'theme'
								? __( 'Farben und Karte kommen vollständig aus dem Theme. Eigene Formularfarben gibt es nur in den Modi Hell, Dunkel oder Automatisch.', 'gutenberg-formbuilder' )
								: undefined,
						options: [
							{ label: __( 'Theme (Standard)', 'gutenberg-formbuilder' ), value: 'theme' },
							{ label: __( 'Automatisch (System)', 'gutenberg-formbuilder' ), value: 'auto' },
							{ label: __( 'Hell', 'gutenberg-formbuilder' ), value: 'light' },
							{ label: __( 'Dunkel', 'gutenberg-formbuilder' ), value: 'dark' },
						],
						onChange: function ( value ) {
							setAttributes( { appearanceMode: value || 'theme' } );
						},
					} ),
					appearance !== 'theme'
						? renderGfbColorPanel(
							__( 'Formularfarben (Hell)', 'gutenberg-formbuilder' ),
							createFormColorSettings( attributes, setAttributes )
						  )
						: null,
					appearance !== 'theme'
						? renderGfbColorPanel(
							__( 'Formularfarben (Dunkel)', 'gutenberg-formbuilder' ),
							createDarkFormColorSettings( attributes, setAttributes )
						  )
						: null
					)
				),
				el(
					'form',
					{
						className: 'gfb-form',
						onSubmit: function ( ev ) {
							ev.preventDefault();
						},
					},
					el( 'div', innerBlocksProps )
				)
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
		deprecated: [
			{
				attributes: {
					formID: { type: 'string' },
				},
				migrate: function ( oldAttributes ) {
					return {
						formId: oldAttributes.formID || '',
					};
				},
				save: function () {
					return el( InnerBlocks.Content );
				},
			},
		],
	} );

	registerBlockType( 'gfb/form-success', {
		supports: {
			innerBlocks: true,
		},
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'gfb-form-success-editor',
			} );
			var allowedSuccessInner = useSelect( function ( select ) {
				try {
					var types = select( 'core/blocks' ).getBlockTypes();
					if ( ! types || ! types.length ) {
						return true;
					}
					var names = types
						.map( function ( t ) {
							return t.name;
						} )
						.filter( function ( name ) {
							return (
								name !== 'gfb/form' &&
								name !== 'gfb/form-success' &&
								name.indexOf( 'gfb/field-' ) !== 0
							);
						} );
					return [ 'gfb/token' ].concat(
						names.filter( function ( n ) {
							return n !== 'gfb/token';
						} )
					);
				} catch ( err ) {
					return true;
				}
			}, [] );
			var innerBlocksProps = useInnerBlocksProps(
				{ className: 'gfb-form-success-editor__inner' },
				{
					allowedBlocks: allowedSuccessInner,
					templateLock: false,
				}
			);
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Rückmeldung', 'gutenberg-formbuilder' ),
							initialOpen: true,
						},
						el( Notice, { status: 'info', isDismissible: false }, __( 'Dieser Inhalt ersetzt nach erfolgreichem Absenden das Formular, sofern keine Folgeseite gewählt ist. Platzhalter: {{feldname}}; optional {{label_feldname}} (z. B. {{label_email}}).', 'gutenberg-formbuilder' ) )
					)
				),
				el( 'div', innerBlocksProps )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

	registerBlockType( 'gfb/token', {
		edit: function ( props ) {
			var clientId = props.clientId;
			var replaceBlocks = wp.data.useDispatch( 'core/block-editor' ).replaceBlocks;
			var rows = useSelect(
				function ( select ) {
					return gfbTokenRowsFromAncestorForm( select, clientId );
				},
				[ clientId ]
			);
			var blockProps = useBlockProps( {
				className: 'gfb-token-hint-editor',
			} );
			var selectOptions = [
				{
					label: __( 'Feldname wählen …', 'gutenberg-formbuilder' ),
					value: '',
				},
			].concat(
				rows.map( function ( row ) {
					var valueTok = '{{' + row.name + '}}';
					return { label: row.name, value: valueTok };
				} )
			);
			return el(
				'div',
				blockProps,
				el(
					'p',
					{ className: 'gfb-token-hint-editor__intro' },
					__(
						'Liste der technischen Feldnamen (POST-Name). Nach der Auswahl wird der Wert-Platzhalter {{feldname}} an dieser Stelle als Absatz eingefügt.',
						'gutenberg-formbuilder'
					)
				),
				! rows.length
					? el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__(
								'Keine Felder gefunden. Lege zuerst Felder im Formular ausserhalb dieser Rückmeldung an.',
								'gutenberg-formbuilder'
							)
					  )
					: el( SelectControl, {
							label: __( 'Feld → Wert-Platzhalter einfügen', 'gutenberg-formbuilder' ),
							value: '',
							options: selectOptions,
							onChange: function ( chosen ) {
								if ( ! chosen ) {
									return;
								}
								var para = createBlock( 'core/paragraph', { content: chosen } );
								replaceBlocks( [ clientId ], [ para ] );
							},
					  } )
			);
		},
		save: function () {
			return null;
		},
	} );

	/**
	 * Gemeinsame Registrierung der beiden Mail-Container (gfb/receipt-mail,
	 * gfb/doi-mail): InnerBlocks-Muster wie gfb/form-success, begrenztes
	 * Gutenberg-Set (E6), Frontend rendert '' über den PHP-render_callback.
	 *
	 * @param {string} blockName
	 * @param {{title:string,intro:string,allowed:Array<string>,template:Array,noticeCheck:function(Array):Array}} options
	 */
	function gfbRegisterMailRegionBlock( blockName, options ) {
		registerBlockType( blockName, {
			supports: {
				innerBlocks: true,
			},
			edit: function ( props ) {
				var blockProps = useBlockProps( {
					className: 'gfb-mail-region-editor',
				} );
				var innerBlocksState = useSelect(
					function ( select ) {
						try {
							var b = select( 'core/block-editor' ).getBlock( props.clientId );
							return b && b.innerBlocks ? b.innerBlocks : [];
						} catch ( err ) {
							return [];
						}
					},
					[ props.clientId ]
				);
				var innerBlocksProps = useInnerBlocksProps(
					{ className: 'gfb-mail-region-editor__inner' },
					{
						allowedBlocks: options.allowed,
						template: options.template,
						templateLock: false,
					}
				);
				var notices = options.noticeCheck( innerBlocksState );
				return el(
					'div',
					blockProps,
					el(
						'p',
						{ className: 'gfb-mail-region-editor__title' },
						options.title
					),
					el(
						'p',
						{ className: 'gfb-mail-region-editor__intro' },
						options.intro
					),
					notices.length ? el( 'div', null, notices ) : null,
					el( 'div', innerBlocksProps )
				);
			},
			save: function () {
				return el( InnerBlocks.Content );
			},
		} );
	}

	gfbRegisterMailRegionBlock( 'gfb/receipt-mail', {
		title: __( 'Bestätigungsmail – Inhalt (nur Editor, erscheint nie im Formular)', 'gutenberg-formbuilder' ),
		intro: __(
			'Inhalt der Bestätigungsmail an die ausfüllende Person. Platzhalter: {{feldname}} und {{label_feldname}}; der Block «Alle Feldwerte» fügt die Daten-Tabelle ein.',
			'gutenberg-formbuilder'
		),
		allowed: [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/image',
			'core/separator',
			'core/buttons',
			'gfb/all-fields',
		],
		template: [
			[ 'core/heading', { level: 2, content: __( 'Ihre Einsendung ist eingegangen', 'gutenberg-formbuilder' ) } ],
			[ 'core/paragraph', { content: __( 'Vielen Dank. Diese E-Mail bestätigt den Eingang Ihrer Angaben:', 'gutenberg-formbuilder' ) } ],
			[ 'gfb/all-fields' ],
		],
		noticeCheck: function ( innerBlocks ) {
			if ( innerBlocks.length && ! gfbFindBlockOfType( innerBlocks, 'gfb/all-fields' ) ) {
				return [
					el(
						Notice,
						{ status: 'info', isDismissible: false, key: 'no-all-fields' },
						__(
							'Ohne den Block «Alle Feldwerte» enthält die Mail keine automatische Daten-Tabelle – die Tabelle wird nicht angehängt (bewusste Gestaltungsfreiheit).',
							'gutenberg-formbuilder'
						)
					),
				];
			}
			return [];
		},
	} );

	gfbRegisterMailRegionBlock( 'gfb/doi-mail', {
		title: __( 'Bestätigungslink-Mail – Inhalt (nur Editor, erscheint nie im Formular)', 'gutenberg-formbuilder' ),
		intro: __(
			'Inhalt der Link-Mail im Modus «mit Bestätigungslink». Pflicht-Platzhalter: {{bestaetigungslink}}. Feldwerte erscheinen in dieser Mail nicht – die vollständige Quittung folgt erst nach dem Klick.',
			'gutenberg-formbuilder'
		),
		allowed: [
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/image',
			'core/separator',
			'core/buttons',
		],
		template: [
			[ 'core/heading', { level: 2, content: __( 'Bitte bestätigen Sie Ihre E-Mail-Adresse', 'gutenberg-formbuilder' ) } ],
			[ 'core/paragraph', { content: __( 'Ihre Einsendung ist eingegangen. Bitte bestätigen Sie mit einem Klick, dass dieses Postfach Ihnen gehört:', 'gutenberg-formbuilder' ) } ],
			[ 'core/paragraph', { content: '{{bestaetigungslink}}' } ],
			[ 'core/paragraph', { content: __( 'Der Link ist 7 Tage gültig und funktioniert nur einmal.', 'gutenberg-formbuilder' ) } ],
		],
		noticeCheck: function ( innerBlocks ) {
			if ( innerBlocks.length && ! gfbBlocksContainConfirmPlaceholder( innerBlocks ) ) {
				return [
					el(
						Notice,
						{ status: 'warning', isDismissible: false, key: 'no-confirm-link' },
						__(
							'Der Platzhalter {{bestaetigungslink}} fehlt. Die Mail geht nie ohne Link raus – der Server hängt ihn dann automatisch am Ende an.',
							'gutenberg-formbuilder'
						)
					),
				];
			}
			return [];
		},
	} );

	registerBlockType( 'gfb/confirm-status', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'gfb-confirm-status-editor',
			} );
			return el(
				'div',
				blockProps,
				el(
					'p',
					{ className: 'gfb-confirm-status-editor__label' },
					__( 'Bestätigungs-Status (dynamisch)', 'gutenberg-formbuilder' )
				),
				el(
					'p',
					{ className: 'gfb-confirm-status-editor__hint' },
					__(
						'Hier rendert der Server je nach Zustand den Bestätigungs-Knopf (Landeseite) oder die Ergebnis-Meldung (erfasst / abgelaufen-ungültig-benutzt). Feldwerte erscheinen nie. Der Block gehört in die Templates «Formular – Bestätigungsseite» und «Formular – Bestätigungs-Ergebnis».',
						'gutenberg-formbuilder'
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'gfb/all-fields', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'gfb-all-fields-editor',
			} );
			return el(
				'div',
				blockProps,
				el(
					'p',
					{ className: 'gfb-all-fields-editor__label' },
					__( 'Alle Feldwerte', 'gutenberg-formbuilder' )
				),
				el(
					'p',
					{ className: 'gfb-all-fields-editor__hint' },
					__(
						'Hier fügt der Server die Tabelle aller übermittelten Feldwerte ein. Vertrauliche Felder erscheinen im Sofort-Modus nur als «vertraulich gespeichert», Dateien als Dateiname mit Hinweis.',
						'gutenberg-formbuilder'
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'gfb/field-text', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'textfeld', GFB_LEGACY_NAMES_TEXT );

			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-text',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Textfeld', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'text',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-text',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'text',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-email', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'email', GFB_LEGACY_NAMES_EMAIL );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-email',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'E-Mail-Feld', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'email',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-email',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'email',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-textarea', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'nachricht', GFB_LEGACY_NAMES_TEXTAREA );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-textarea',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Textbereich', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'textarea', {
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-textarea',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'textarea', {
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-select', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'auswahl', GFB_LEGACY_NAMES_SELECT );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );

			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-select',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Auswahlfeld', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextareaControl, {
							label: __( 'Optionen (eine pro Zeile)', 'gutenberg-formbuilder' ),
							value: attributes.options || '',
							onChange: function ( value ) {
								setAttributes( { options: value } );
							},
						} )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el(
					'select',
					{
						disabled: true,
						id: attributes.name || undefined,
						name: attributes.name || undefined,
					},
					options.map( function ( option ) {
						return el( 'option', { value: option, key: option }, option );
					} )
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var options = ( a.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );

			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-select',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el(
					'select',
					{
						name: a.name,
						id: a.name,
						required: !! a.required,
					},
					options.map( function ( option ) {
						return el( 'option', { value: option, key: option }, option );
					} )
				)
			);
		},
	} );

	registerBlockType( 'gfb/field-checkbox', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'zustimmung', GFB_LEGACY_NAMES_CHECKBOX );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-checkbox',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Checkbox', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false )
					)
				),
				( function () {
					var lab = gfbTrimmedFieldLabel( attributes.label );
					var nm = attributes.name || '';
					var input = el( 'input', {
						type: 'checkbox',
						disabled: true,
						name: nm || undefined,
						id: nm || undefined,
						value: '1',
					} );
					if ( lab ) {
						if ( attributes.required ) {
							return el( 'label', { for: nm || undefined }, input, ' ', lab, el( 'span', { className: 'gfb-required', 'aria-hidden': 'true' }, ' *' ) );
						}
						return el( 'label', { for: nm || undefined }, input, ' ', lab );
					}
					return input;
				}() )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-checkbox',
				style: buildFieldColorOverrideStyle( a ),
			} );
			var lab = gfbTrimmedFieldLabel( a.label );
			var input = el( 'input', {
				type: 'checkbox',
				name: a.name,
				id: a.name,
				value: '1',
				required: !! a.required,
			} );
			return el(
				'div',
				saveProps,
				lab ? el( 'label', { for: a.name }, input, ' ', lab ) : input
			);
		},
	} );

	registerBlockType( 'gfb/field-submit', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-submit',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Absenden-Button', 'gutenberg-formbuilder' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Button-Text', 'gutenberg-formbuilder' ),
							value: attributes.label || '',
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
						} )
					)
				),
				el(
					'div',
					{ className: 'wp-block-button is-style-default' },
					el(
						'button',
						{
							type: 'submit',
							disabled: true,
							className: 'wp-block-button__link wp-element-button',
						},
						attributes.label || __( 'Formular absenden', 'gutenberg-formbuilder' )
					)
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-submit',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				el(
					'div',
					{ className: 'wp-block-button is-style-default' },
					el(
						'button',
						{
							type: 'submit',
							className: 'wp-block-button__link wp-element-button',
						},
						a.label || __( 'Formular absenden', 'gutenberg-formbuilder' )
					)
				)
			);
		},
	} );

	registerBlockType( 'gfb/field-number', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'zahl', GFB_LEGACY_NAMES_NUMBER );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-number',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Zahl', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true ),
						buildMinMaxStepInspector( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'number',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
					min: attributes.min || undefined,
					max: attributes.max || undefined,
					step: attributes.step || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-number',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'number',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					min: a.min || undefined,
					max: a.max || undefined,
					step: a.step || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-tel', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'telefon', GFB_LEGACY_NAMES_TEL );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-tel',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Telefon', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'tel',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
					autoComplete: 'tel',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-tel',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'tel',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
					autoComplete: 'tel',
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-url', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, true, 'website', GFB_LEGACY_NAMES_URL );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-url',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'URL', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, true )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'url',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					placeholder: attributes.placeholder || '',
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-url',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'url',
					name: a.name,
					id: a.name,
					placeholder: a.placeholder || '',
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-date', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'datum', GFB_LEGACY_NAMES_DATE );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-date',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datum', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildDateMinMaxInspector( attributes, setAttributes ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: JJJJ-MM-TT, z. B. 2026-05-10.',
								'gutenberg-formbuilder'
							)
						)
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'date',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					min: attributes.min || undefined,
					max: attributes.max || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-date',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'date',
					name: a.name,
					id: a.name,
					min: a.min || undefined,
					max: a.max || undefined,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-time', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'uhrzeit', GFB_LEGACY_NAMES_TIME );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-time',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Uhrzeit', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: HH:MM (24 Stunden), z. B. 09:30.',
								'gutenberg-formbuilder'
							)
						)
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'time',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-time',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'time',
					name: a.name,
					id: a.name,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-datetime', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'termin', GFB_LEGACY_NAMES_DATETIME );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-datetime',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datum und Uhrzeit', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildOptionalDefaultValueControl(
							attributes,
							setAttributes,
							__(
								'Leer lassen für keinen Startwert. Format: JJJJ-MM-TTTHH:MM (lokal), z. B. 2026-05-10T14:00.',
								'gutenberg-formbuilder'
							)
						)
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'datetime-local',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					defaultValue: attributes.defaultValue || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-datetime',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'datetime-local',
					name: a.name,
					id: a.name,
					value: a.defaultValue || undefined,
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-radio', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'radio', GFB_LEGACY_NAMES_RADIO );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );
			var radioLayout = attributes.optionsLayout === 'row' ? 'row' : 'column';
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-radio',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			var radioOptsClass =
				'gfb-radio-options' +
				( radioLayout === 'row' ? ' gfb-radio-options--row' : '' );
			var radioGroupLabelText = gfbTrimmedFieldLabel( attributes.label );
			var previewName = 'gfb-preview-' + String( attributes.name || 'radio' );
			var radioPreviewBody = [
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Radio-Auswahl', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextareaControl, {
							label: __( 'Optionen (eine pro Zeile)', 'gutenberg-formbuilder' ),
							value: attributes.options || '',
							onChange: function ( value ) {
								setAttributes( { options: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Anordnung der Optionen', 'gutenberg-formbuilder' ),
							value: radioLayout,
							options: [
								{ label: __( 'Untereinander', 'gutenberg-formbuilder' ), value: 'column' },
								{ label: __( 'Nebeneinander', 'gutenberg-formbuilder' ), value: 'row' },
							],
							onChange: function ( value ) {
								setAttributes( { optionsLayout: value === 'row' ? 'row' : 'column' } );
							},
						} )
					)
				),
			];
			if ( radioGroupLabelText ) {
				radioPreviewBody.push(
					attributes.required
						? el( 'legend', null, radioGroupLabelText, el( 'span', { className: 'gfb-required', 'aria-hidden': 'true' }, ' *' ) )
						: el( 'legend', null, radioGroupLabelText )
				);
			}
			radioPreviewBody.push(
				el(
					'div',
					{ className: radioOptsClass },
					options.map( function ( opt, idx ) {
						var rid = String( attributes.name || 'radio' ) + '_' + idx;
						return el(
							'div',
							{ key: opt, className: 'gfb-radio-row' },
							el( 'input', {
								type: 'radio',
								disabled: true,
								name: previewName,
								id: rid,
								value: opt,
							} ),
							' ',
							el( 'label', { for: rid }, opt )
						);
					} )
				)
			);
			return el( 'fieldset', blockProps, radioPreviewBody );
		},
		// Dynamischer Block: render_callback in PHP übernimmt das Frontend-HTML.
		// save() gibt null zurück; das frühere statische HTML lebt in deprecated[].
		save: function () {
			return null;
		},
		deprecated: [
			{
				// v2.6.4 und früher: save() erzeugte statisches HTML.
				// Split robuster gemacht (auch \r\n und Buchstabe n als Fallback).
				attributes: {
					label:         { type: 'string', default: 'Auswahl' },
					name:          { type: 'string', default: '' },
					nameClientId:  { type: 'string', default: '' },
					options:       { type: 'string', default: 'Option A\nOption B\nOption C' },
					required:      { type: 'boolean', default: false },
					optionsLayout: { type: 'string', default: 'column' },
					sensitive:     { type: 'boolean', default: false },
				},
				save: function ( props ) {
					var a = props.attributes;
					var options = ( a.options || '' )
						.split( /\r\n|\r|\n/ )
						.map( function ( item ) { return item.trim(); } )
						.filter( Boolean );
					var layout = a.optionsLayout === 'row' ? 'row' : 'column';
					var optionsWrapClass =
						'gfb-radio-options' + ( layout === 'row' ? ' gfb-radio-options--row' : '' );
					var saveProps = useBlockPropsSave( {
						className: 'gfb-field gfb-field-radio',
						style: buildFieldColorOverrideStyle( a ),
					} );
					var groupLab = gfbTrimmedFieldLabel( a.label );
					return el(
						'fieldset',
						saveProps,
						groupLab ? el( 'legend', null, groupLab ) : null,
						el(
							'div',
							{ className: optionsWrapClass },
							options.map( function ( opt, idx ) {
								var id = a.name + '_' + idx;
								return el(
									'div',
									{ key: opt, className: 'gfb-radio-row' },
									el( 'input', {
										type: 'radio',
										name: a.name,
										id: id,
										value: opt,
										required: !! a.required && idx === 0,
									} ),
									' ',
									el( 'label', { for: id }, opt )
								);
							} )
						)
					);
				},
			},
		],
	} );

	registerBlockType( 'gfb/field-hidden', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'hidden', GFB_LEGACY_NAMES_HIDDEN );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-hidden',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Verstecktes Feld', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						el( TextControl, {
							label: __( 'Label (Hinweis)', 'gutenberg-formbuilder' ),
							value: attributes.label || '',
							onChange: function ( value ) {
								setAttributes( { label: value } );
							},
							help: __(
								'Nur im Editor und in der Eintrags-Übersicht sichtbar; erscheint nicht im Formular.',
								'gutenberg-formbuilder'
							),
						} ),
						el( TextControl, {
							label: __( 'Wert', 'gutenberg-formbuilder' ),
							value: attributes.hiddenValue || '',
							onChange: function ( value ) {
								setAttributes( { hiddenValue: value } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Wert serverseitig erzwingen (empfohlen)', 'gutenberg-formbuilder' ),
							help: __(
								'Wenn aktiv, wird der oben gesetzte Wert beim Absenden serverseitig gesetzt. Werte aus dem Browser werden ignoriert (Schutz gegen Manipulation).',
								'gutenberg-formbuilder'
							),
							checked: !! attributes.lockedValue,
							onChange: function ( value ) {
								setAttributes( { lockedValue: !! value } );
							},
						} )
					)
				),
				el( 'input', {
					type: 'hidden',
					disabled: true,
					name: attributes.name || undefined,
					value: attributes.hiddenValue || '',
					'aria-label':
						gfbTrimmedFieldLabel( attributes.label ) ||
						__( 'Verstecktes Feld (nur technischer Wert)', 'gutenberg-formbuilder' ),
				} )
			);
		},
		// Dynamischer Block: render_callback in PHP übernimmt das Frontend-HTML.
		// save() gibt null zurück; das frühere statische HTML lebt in deprecated[].
		save: function () {
			return null;
		},
		deprecated: [
			{
				// v2.6.4 und früher: save() erzeugte statisches HTML.
				attributes: {
					label:        { type: 'string', default: '' },
					name:         { type: 'string', default: '' },
					nameClientId: { type: 'string', default: '' },
					hiddenValue:  { type: 'string', default: '' },
					lockedValue:  { type: 'boolean', default: false },
					sensitive:    { type: 'boolean', default: false },
				},
				save: function ( props ) {
					var a = props.attributes;
					var saveProps = useBlockPropsSave( {
						className: 'gfb-field gfb-field-hidden',
					} );
					return el(
						'div',
						saveProps,
						el( 'input', {
							type: 'hidden',
							name: a.name,
							value: a.hiddenValue || '',
						} )
					);
				},
			},
		],
	} );

	registerBlockType( 'gfb/field-range', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'wert', GFB_LEGACY_NAMES_RANGE );
			var min = attributes.min || '0';
			var max = attributes.max || '100';
			var step = attributes.step || '1';
			var def = attributes.defaultValue;
			var initial = computeRangeInitial( min, max, step, def );
			var state = useState( initial );
			var sliderVal = state[0];
			var setSliderVal = state[1];
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-range',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			useEffect(
				function () {
					setSliderVal( computeRangeInitial( min, max, step, def ) );
				},
				[ min, max, step, def ]
			);
			var rangeId =
				attributes.name && String( attributes.name ).trim() !== ''
					? String( attributes.name ).trim()
					: 'gfb-range-preview-' + props.clientId;
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Schieberegler', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						buildMinMaxStepInspector( attributes, setAttributes, true ),
						el( TextControl, {
							label: __( 'Startwert (optional)', 'gutenberg-formbuilder' ),
							value: attributes.defaultValue || '',
							onChange: function ( value ) {
								setAttributes( { defaultValue: value } );
							},
							help: __( 'Leer lassen für die Mitte zwischen Min und Max.', 'gutenberg-formbuilder' ),
						} )
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( rangeId ), attributes.label, attributes.required ),
				el(
					'div',
					{ className: 'gfb-range-row' },
					el( 'input', {
						id: rangeId,
						name: attributes.name || undefined,
						type: 'range',
						min: min,
						max: max,
						step: step,
						value: sliderVal,
						onChange: function ( ev ) {
							setSliderVal( ev.target.value );
						},
					} ),
					el( 'output', { className: 'gfb-range-value', htmlFor: rangeId }, sliderVal )
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var startVal = computeRangeInitial( a.min || '0', a.max || '100', a.step || '1', a.defaultValue );
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-range',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el(
					'div',
					{ className: 'gfb-range-row' },
					el( 'input', {
						type: 'range',
						name: a.name,
						id: a.name,
						min: a.min || '0',
						max: a.max || '100',
						step: a.step || '1',
						value: startVal,
						required: !! a.required,
					} ),
					el( 'output', { className: 'gfb-range-value', htmlFor: a.name }, startVal )
				)
			);
		},
	} );

	registerBlockType( 'gfb/field-file', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			syncFieldNameBinding( attributes, setAttributes, props.clientId, false, 'datei', GFB_LEGACY_NAMES_FILE );
			var blockProps = useBlockProps( {
				className: 'gfb-field gfb-field-file',
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Datei-Upload', 'gutenberg-formbuilder' ), initialOpen: true },
						el( GfbFieldNameInspector, {
							attributes: attributes,
							setAttributes: setAttributes,
							clientId: props.clientId,
						} ),
						buildFieldControls( attributes, setAttributes, false ),
						el( TextControl, {
							label: __( 'accept (z. B. .pdf,image/*)', 'gutenberg-formbuilder' ),
							value: attributes.accept || '',
							onChange: function ( value ) {
								setAttributes( { accept: value } );
							},
						} ),
						__experimentalNumberControl
							? el( __experimentalNumberControl, {
									label: __( 'Max. Grösse (MB)', 'gutenberg-formbuilder' ),
									value: attributes.maxSizeMb || 8,
									onChange: function ( value ) {
										var parsed = parseInt( value, 10 );
										if ( Number.isNaN( parsed ) ) {
											return;
										}
										setAttributes( { maxSizeMb: Math.min( 128, Math.max( 1, parsed ) ) } );
									},
									min: 1,
									max: 128,
							  } )
							: null
					)
				),
				gfbEditorLabelIfAny( 'label', gfbLabelForProps( attributes.name ), attributes.label, attributes.required ),
				el( 'input', {
					type: 'file',
					disabled: true,
					id: attributes.name || undefined,
					name: attributes.name || undefined,
					accept: attributes.accept || undefined,
				} )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			var saveProps = useBlockPropsSave( {
				className: 'gfb-field gfb-field-file',
				style: buildFieldColorOverrideStyle( a ),
			} );
			return el(
				'div',
				saveProps,
				gfbSaveLabelIfAny( a.name, a.label ),
				el( 'input', {
					type: 'file',
					name: a.name,
					id: a.name,
					required: !! a.required,
					accept: a.accept || undefined,
				} )
			);
		},
	} );

	if ( typeof window !== 'undefined' && wp && wp.data && wp.data.subscribe ) {
		var gfbStyleSyncTimer = null;
		wp.data.subscribe( function () {
			if ( gfbStyleSyncTimer ) {
				clearTimeout( gfbStyleSyncTimer );
			}
			gfbStyleSyncTimer = setTimeout( function () {
				gfbStyleSyncTimer = null;
				gfbSyncEditorFormStylesheet();
			}, 80 );
		} );
		if ( wp.domReady ) {
			wp.domReady( function () {
				setTimeout( gfbSyncEditorFormStylesheet, 150 );
				setTimeout( gfbSyncEditorFormStylesheet, 600 );
			} );
		}
	}
} )( window.wp );
