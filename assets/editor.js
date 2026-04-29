( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
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
	 */
	function renderGfbColorPanel( title, rows ) {
		if ( PanelColorGradientSettings ) {
			// Theme kann color.customGradient / Paletten deaktivieren — dann wäre canChooseAGradient false und kein Verlauf-Tab. Für Formularfarben immer eigene Farben + Verläufe erlauben.
			return el( PanelColorGradientSettings, {
				title: title,
				initialOpen: false,
				disableCustomColors: false,
				disableCustomGradients: false,
				settings: mapColorSettingsToGradientSettings( rows ),
			} );
		}
		return el( PanelColorSettings, {
			title: title,
			initialOpen: false,
			colorSettings: rows.map( function ( r ) {
				return Object.assign( {}, r, { enableAlpha: true } );
			} ),
		} );
	}
	/**
	 * Hält formId pro Block-Instanz eindeutig: Duplizieren kopiert formId,
	 * daher Abgleich mit der stabilen Editor-clientId.
	 */
	function syncFormInstance( attributes, setAttributes, clientId ) {
		useEffect(
			function () {
				if ( attributes.blockInstanceId === clientId ) {
					return;
				}
				if ( ! attributes.blockInstanceId && attributes.formId ) {
					setAttributes( { blockInstanceId: clientId } );
					return;
				}
				var generated = 'gfb_' + clientId.replace( /-/g, '' ).slice( 0, 12 );
				setAttributes( {
					blockInstanceId: clientId,
					formId: generated,
				} );
			},
			[ attributes.blockInstanceId, attributes.formId, clientId, setAttributes ]
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

	function ensureFieldName( attributes, setAttributes, clientId, fallbackPrefix, legacyNames ) {
		useEffect(
			function () {
				var n = attributes.name != null ? String( attributes.name ).trim() : '';
				var mustGenerate = n === '' || legacyNames.indexOf( n ) !== -1;
				if ( ! mustGenerate ) {
					return;
				}
				var generated = fallbackPrefix + '_' + clientId.replace( /-/g, '' ).slice( 0, 10 );
				setAttributes( { name: generated } );
			},
			[ attributes.name, clientId, setAttributes, fallbackPrefix, legacyNames ]
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
			el(
				TextControl,
				{
					label: __( 'Feldname (technisch)', 'gutenberg-formbuilder' ),
					value: attributes.name || '',
					onChange: function ( value ) {
						var normalized = value.toLowerCase().replace( /[^a-z0-9_]/g, '_' );
						setAttributes( { name: normalized } );
					},
					help: __( 'Nur a-z, 0-9 und _.', 'gutenberg-formbuilder' ),
				}
			),
			el(
				ToggleControl,
				{
					label: __( 'Pflichtfeld', 'gutenberg-formbuilder' ),
					checked: !! attributes.required,
					onChange: function ( value ) {
						setAttributes( { required: value } );
					},
				}
			),
		];

		if ( includePlaceholder ) {
			controls.splice(
				2,
				0,
				el(
					TextControl,
					{
						label: __( 'Placeholder', 'gutenberg-formbuilder' ),
						value: attributes.placeholder || '',
						onChange: function ( value ) {
							setAttributes( { placeholder: value } );
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
	 * Inline-Styles für .gfb-editor-form / PHP: Hell- und Dunkel-Variablen.
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
	 * @param {Record<string, unknown>|undefined} ctx
	 * @return {string|undefined}
	 */
	function gfbEditorFieldClassName( ctx ) {
		return 'gfb-editor-field';
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
	function gfbEditorLabelIfAny( tag, props, labelAttr ) {
		var t = gfbTrimmedFieldLabel( labelAttr );
		if ( ! t ) {
			return null;
		}
		return el( tag, props || null, t );
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
		if ( ! assets || ! assets.editorFormStylesUrl ) {
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
		var sep = assets.editorFormStylesUrl.indexOf( '?' ) === -1 ? '?' : '&';
		var href =
			assets.editorFormStylesUrl +
			( assets.version ? sep + 'ver=' + encodeURIComponent( String( assets.version ) ) : '' );
		gfbForEachEditorCanvasDocument( function ( doc ) {
			var existing = doc.getElementById( 'gfb-editor-form-stylesheet' );
			if ( needs ) {
				if ( ! existing ) {
					var link = doc.createElement( 'link' );
					link.id = 'gfb-editor-form-stylesheet';
					link.rel = 'stylesheet';
					link.href = href;
					doc.head.appendChild( link );
				} else if ( existing.getAttribute( 'href' ) !== href ) {
					existing.setAttribute( 'href', href );
				}
			} else if ( existing && existing.parentNode ) {
				existing.parentNode.removeChild( existing );
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

	function renderFieldColorOverrideControls( attributes, setAttributes ) {
		return renderGfbColorPanel(
			__( 'Farben (Feld überschreiben)', 'gutenberg-formbuilder' ),
			createFormColorSettings( attributes, setAttributes )
		);
	}

	/** Nur Absenden-Block: Button- und Fokus-Overrides ohne die übrigen Feld-Farben. */
	function renderSubmitButtonColorOverrideControls( attributes, setAttributes ) {
		return renderGfbColorPanel( __( 'Button & Fokus (Feld überschreiben)', 'gutenberg-formbuilder' ), [
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
		] );
	}

	registerBlockType( 'gfb/form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var appearance = attributes.appearanceMode || 'theme';
			var formShellGradClass = '';
			if ( appearance !== 'theme' ) {
				if ( gfbAttrLooksLikeCssGradient( attributes.colorFormShell ) ) {
					formShellGradClass += ' gfb-form-shell-gradient--light';
				}
				if ( gfbAttrLooksLikeCssGradient( attributes.darkColorFormShell ) ) {
					formShellGradClass += ' gfb-form-shell-gradient--dark';
				}
			}
			var formClassName =
				'gfb-editor-form' +
				( appearance === 'theme'
					? ''
					: ( formHasCustomColors( attributes ) ? ' gfb-form-colors-custom' : '' ) + formShellGradClass );
			var formColorStyle = formWrapperColorStyleObject( attributes );
			var formBlockProps = useBlockProps( {
				className: formClassName || undefined,
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
							return ! fieldSet[ n ];
						} )
						.sort();
					return fieldsFirst.concat( rest );
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
									color: '#757575',
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
										color: '#1e1e1e',
									},
								},
								attributes.formId || '—'
							)
						)
					),
					el(
						PanelBody,
						{
							title: __( 'Formulareinstellungen', 'gutenberg-formbuilder' ),
							initialOpen: true,
						},
						el( Notice, { status: 'info', isDismissible: false }, __( 'Feldwerte werden im Browser lokal zwischengespeichert.', 'gutenberg-formbuilder' ) ),
						el( ToggleControl, {
							label: __( 'Lokale Draftspeicherung aktivieren', 'gutenberg-formbuilder' ),
							checked: attributes.draftEnabled !== false,
							onChange: function ( value ) {
								setAttributes( { draftEnabled: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Wiederherstellung', 'gutenberg-formbuilder' ),
							value: attributes.restoreMode || 'prompt',
							options: [
								{ label: __( 'Nachfragen', 'gutenberg-formbuilder' ), value: 'prompt' },
								{ label: __( 'Automatisch', 'gutenberg-formbuilder' ), value: 'auto' },
							],
							onChange: function ( value ) {
								setAttributes( { restoreMode: value } );
							},
							disabled: attributes.draftEnabled === false,
						} ),
						__experimentalNumberControl
							? el( __experimentalNumberControl, {
									label: __( 'Draft-Ablauf in Tagen', 'gutenberg-formbuilder' ),
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
							label: __( 'Button „Draft löschen“ anzeigen', 'gutenberg-formbuilder' ),
							checked: attributes.showDraftReset !== false,
							onChange: function ( value ) {
								setAttributes( { showDraftReset: value } );
							},
							disabled: attributes.draftEnabled === false,
						} ),
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
					el( PanelBody, {
						title: __( 'Erscheinungsbild', 'gutenberg-formbuilder' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'Farbmodus', 'gutenberg-formbuilder' ),
						value: appearance,
						help:
							appearance === 'theme'
								? __( 'Farben und Karte kommen aus dem Theme; gesetzte Formularfarben überschreiben nur die betreffenden Werte.', 'gutenberg-formbuilder' )
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
					renderGfbColorPanel(
						__( 'Formularfarben (Hell)', 'gutenberg-formbuilder' ),
						createFormColorSettings( attributes, setAttributes )
					),
					renderGfbColorPanel(
						__( 'Formularfarben (Dunkel)', 'gutenberg-formbuilder' ),
						createDarkFormColorSettings( attributes, setAttributes )
					)
					)
				),
				el( 'div', innerBlocksProps )
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

	registerBlockType( 'gfb/field-text', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'textfeld', GFB_LEGACY_NAMES_TEXT );

			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'text', disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'email', GFB_LEGACY_NAMES_EMAIL );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'email', disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'nachricht', GFB_LEGACY_NAMES_TEXTAREA );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'textarea', { disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'auswahl', GFB_LEGACY_NAMES_SELECT );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );

			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, false ),
						el( TextareaControl, {
							label: __( 'Optionen (eine pro Zeile)', 'gutenberg-formbuilder' ),
							value: attributes.options || '',
							onChange: function ( value ) {
								setAttributes( { options: value } );
							},
						} )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el(
					'select',
					{ disabled: true },
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'zustimmung', GFB_LEGACY_NAMES_CHECKBOX );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, false )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				( function () {
					var lab = gfbTrimmedFieldLabel( attributes.label );
					var input = el( 'input', { type: 'checkbox', disabled: true } );
					if ( lab ) {
						return el( 'label', null, input, ' ', lab );
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
				className: gfbEditorFieldClassName( props.context || {} ),
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
					),
					renderSubmitButtonColorOverrideControls( attributes, setAttributes )
				),
				el( 'button', { type: 'button', disabled: true }, attributes.label || __( 'Formular absenden', 'gutenberg-formbuilder' ) )
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
				el( 'button', { type: 'submit' }, a.label || __( 'Formular absenden', 'gutenberg-formbuilder' ) )
			);
		},
	} );

	registerBlockType( 'gfb/field-number', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'zahl', GFB_LEGACY_NAMES_NUMBER );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true ),
						buildMinMaxStepInspector( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'number', disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'telefon', GFB_LEGACY_NAMES_TEL );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'tel', disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'website', GFB_LEGACY_NAMES_URL );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, true )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'url', disabled: true, placeholder: attributes.placeholder || '' } )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'datum', GFB_LEGACY_NAMES_DATE );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, false ),
						buildDateMinMaxInspector( attributes, setAttributes )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'date', disabled: true } )
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
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-time', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'uhrzeit', GFB_LEGACY_NAMES_TIME );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, false )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'time', disabled: true } )
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
				el( 'input', { type: 'time', name: a.name, id: a.name, required: !! a.required } )
			);
		},
	} );

	registerBlockType( 'gfb/field-datetime', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'termin', GFB_LEGACY_NAMES_DATETIME );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
						buildFieldControls( attributes, setAttributes, false )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'datetime-local', disabled: true } )
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
					required: !! a.required,
				} )
			);
		},
	} );

	registerBlockType( 'gfb/field-radio', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'radio', GFB_LEGACY_NAMES_RADIO );
			var options = ( attributes.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );
			var radioLayout = attributes.optionsLayout === 'row' ? 'row' : 'column';
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			var radioOptsClass =
				'gfb-editor-radio-options' +
				( radioLayout === 'row' ? ' gfb-editor-radio-options--row' : '' );
			var radioGroupLabelText = gfbTrimmedFieldLabel( attributes.label );
			var radioPreviewBody = [
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Radio-Auswahl', 'gutenberg-formbuilder' ), initialOpen: true },
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
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
			];
			if ( radioGroupLabelText ) {
				radioPreviewBody.push(
					el( 'div', { className: 'gfb-radio-group-label' }, radioGroupLabelText )
				);
			}
			radioPreviewBody.push(
				el(
					'div',
					{ className: radioOptsClass },
					options.map( function ( opt ) {
						return el(
							'label',
							{ key: opt, style: { display: 'block' } },
							el( 'input', { type: 'radio', disabled: true, name: 'preview-' + attributes.name } ),
							' ',
							opt
						);
					} )
				)
			);
			return el( 'div', blockProps, radioPreviewBody );
		},
		save: function ( props ) {
			var a = props.attributes;
			var options = ( a.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
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
	} );

	registerBlockType( 'gfb/field-hidden', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'hidden', GFB_LEGACY_NAMES_HIDDEN );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
							label: __( 'Feldname (technisch)', 'gutenberg-formbuilder' ),
							value: attributes.name || '',
							onChange: function ( value ) {
								var normalized = value.toLowerCase().replace( /[^a-z0-9_]/g, '_' );
								setAttributes( { name: normalized } );
							},
						} ),
						el( TextControl, {
							label: __( 'Wert', 'gutenberg-formbuilder' ),
							value: attributes.hiddenValue || '',
							onChange: function ( value ) {
								setAttributes( { hiddenValue: value } );
							},
						} )
					)
				),
				el(
					'p',
					{ className: 'gfb-editor-hidden-field-hint' },
					gfbTrimmedFieldLabel( attributes.label ) ||
						__( 'Verstecktes Feld (nur technischer Wert)', 'gutenberg-formbuilder' )
				)
			);
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
	} );

	registerBlockType( 'gfb/field-range', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'wert', GFB_LEGACY_NAMES_RANGE );
			var min = attributes.min || '0';
			var max = attributes.max || '100';
			var step = attributes.step || '1';
			var def = attributes.defaultValue;
			var initial = computeRangeInitial( min, max, step, def );
			var state = useState( initial );
			var sliderVal = state[0];
			var setSliderVal = state[1];
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
				style: buildMergedFieldColorStyle( attributes, props.context || {} ),
			} );
			useEffect(
				function () {
					setSliderVal( computeRangeInitial( min, max, step, def ) );
				},
				[ min, max, step, def ]
			);
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Schieberegler', 'gutenberg-formbuilder' ), initialOpen: true },
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
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', { for: 'gfb-range-preview-' + props.clientId }, attributes.label ),
				el(
					'div',
					{ className: 'gfb-range-row' },
					el( 'input', {
						id: 'gfb-range-preview-' + props.clientId,
						type: 'range',
						min: min,
						max: max,
						step: step,
						value: sliderVal,
						onChange: function ( ev ) {
							setSliderVal( ev.target.value );
						},
					} ),
					el( 'output', { className: 'gfb-range-value', htmlFor: 'gfb-range-preview-' + props.clientId }, sliderVal )
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
			ensureFieldName( attributes, setAttributes, props.clientId, 'datei', GFB_LEGACY_NAMES_FILE );
			var blockProps = useBlockProps( {
				className: gfbEditorFieldClassName( props.context || {} ),
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
									label: __( 'Max. Größe (MB)', 'gutenberg-formbuilder' ),
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
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				gfbEditorLabelIfAny( 'label', null, attributes.label ),
				el( 'input', { type: 'file', disabled: true } )
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
