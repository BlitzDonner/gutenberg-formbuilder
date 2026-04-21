( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var __ = wp.i18n.__;
	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var useSelect = wp.data.useSelect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var __experimentalNumberControl = wp.components.__experimentalNumberControl;
	var Notice = wp.components.Notice;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;

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
			( attrs.darkColorButtonText && String( attrs.darkColorButtonText ).trim() )
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
		var palette = resolveActivePalette( ctx['gfb/appearanceMode'] );
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

	function createFormColorSettings( attributes, setAttributes ) {
		return [
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
	}

	function createDarkFormColorSettings( attributes, setAttributes ) {
		return [
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
	}

	function renderFieldColorOverrideControls( attributes, setAttributes ) {
		return el( PanelColorSettings, {
			title: __( 'Farben (Feld überschreiben)', 'gutenberg-formbuilder' ),
			initialOpen: false,
			colorSettings: createFormColorSettings( attributes, setAttributes ),
		} );
	}

	/** Nur Absenden-Block: Button- und Fokus-Overrides ohne die übrigen Feld-Farben. */
	function renderSubmitButtonColorOverrideControls( attributes, setAttributes ) {
		return el( PanelColorSettings, {
			title: __( 'Button & Fokus (Feld überschreiben)', 'gutenberg-formbuilder' ),
			initialOpen: false,
			colorSettings: [
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
			],
		} );
	}

	registerBlockType( 'gfb/form', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var formClassName =
				'gfb-editor-form' + ( formHasCustomColors( attributes ) ? ' gfb-form-colors-custom' : '' );
			var appearance = attributes.appearanceMode || 'auto';
			var formColorStyle = formWrapperColorStyleObject( attributes );

			var allowedInnerBlocks = useSelect( function ( select ) {
				try {
					var types = select( 'core/blocks' ).getBlockTypes();
					if ( ! types || ! types.length ) {
						return true;
					}
					return types
						.map( function ( t ) {
							return t.name;
						} )
						.filter( function ( name ) {
							return name !== 'gfb/form';
						} );
				} catch ( err ) {
					return true;
				}
			}, [] );

			syncFormInstance( attributes, setAttributes, props.clientId );

			return el(
				'div',
				{
					className: formClassName,
					style: formColorStyle,
					'data-gfb-appearance': appearance,
				},
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
						} )
					),
					el( PanelBody, {
						title: __( 'Erscheinungsbild', 'gutenberg-formbuilder' ),
						initialOpen: false,
					},
					el( SelectControl, {
						label: __( 'Farbmodus', 'gutenberg-formbuilder' ),
						value: appearance,
						options: [
							{ label: __( 'Automatisch (System)', 'gutenberg-formbuilder' ), value: 'auto' },
							{ label: __( 'Hell', 'gutenberg-formbuilder' ), value: 'light' },
							{ label: __( 'Dunkel', 'gutenberg-formbuilder' ), value: 'dark' },
						],
						onChange: function ( value ) {
							setAttributes( { appearanceMode: value || 'auto' } );
						},
					} )
					),
					el( PanelColorSettings, {
						title: __( 'Formularfarben (Hell)', 'gutenberg-formbuilder' ),
						initialOpen: false,
						colorSettings: createFormColorSettings( attributes, setAttributes ),
					} ),
					el( PanelColorSettings, {
						title: __( 'Formularfarben (Dunkel)', 'gutenberg-formbuilder' ),
						initialOpen: false,
						colorSettings: createDarkFormColorSettings( attributes, setAttributes ),
					} )
				),
				el( InnerBlocks, {
					allowedBlocks: allowedInnerBlocks,
					template: [
						[ 'gfb/field-text' ],
						[ 'gfb/field-email' ],
						[ 'gfb/field-submit' ],
					],
					templateLock: false,
				} )
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

			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Textfeld', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'text', disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-text', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'E-Mail', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'email', disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-email', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Nachricht', 'gutenberg-formbuilder' ) ),
				el( 'textarea', { disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-textarea', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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

			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Auswahl', 'gutenberg-formbuilder' ) ),
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

			return el(
				'div',
				{ className: 'gfb-field gfb-field-select', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el(
					'label',
					null,
					el( 'input', { type: 'checkbox', disabled: true } ),
					' ',
					attributes.label || __( 'Ich stimme zu', 'gutenberg-formbuilder' )
				)
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-checkbox', style: buildFieldColorOverrideStyle( a ) },
				el(
					'label',
					{ for: a.name },
					el( 'input', { type: 'checkbox', name: a.name, id: a.name, value: '1', required: !! a.required } ),
					' ',
					a.label
				)
			);
		},
	} );

	registerBlockType( 'gfb/field-submit', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
			return el(
				'div',
				{ className: 'gfb-field gfb-field-submit', style: buildFieldColorOverrideStyle( a ) },
				el( 'button', { type: 'submit' }, a.label || __( 'Formular absenden', 'gutenberg-formbuilder' ) )
			);
		},
	} );

	registerBlockType( 'gfb/field-number', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'zahl', GFB_LEGACY_NAMES_NUMBER );
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Zahl', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'number', disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-number', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Telefon', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'tel', disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-tel', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Website', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'url', disabled: true, placeholder: attributes.placeholder || '' } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-url', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Datum', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'date', disabled: true } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-date', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Uhrzeit', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'time', disabled: true } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-time', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
				el( 'input', { type: 'time', name: a.name, id: a.name, required: !! a.required } )
			);
		},
	} );

	registerBlockType( 'gfb/field-datetime', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'termin', GFB_LEGACY_NAMES_DATETIME );
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Termin', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'datetime-local', disabled: true } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-datetime', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
						} )
					),
					renderFieldColorOverrideControls( attributes, setAttributes )
				),
				el( 'div', { className: 'gfb-radio-group-label' }, attributes.label || __( 'Auswahl', 'gutenberg-formbuilder' ) ),
				el(
					'div',
					{ className: 'gfb-editor-radio-options' },
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
		},
		save: function ( props ) {
			var a = props.attributes;
			var options = ( a.options || '' )
				.split( /\n/ )
				.map( function ( item ) {
					return item.trim();
				} )
				.filter( Boolean );
			return el(
				'fieldset',
				{ className: 'gfb-field gfb-field-radio', style: buildFieldColorOverrideStyle( a ) },
				el( 'legend', null, a.label ),
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
			);
		},
	} );

	registerBlockType( 'gfb/field-hidden', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			ensureFieldName( attributes, setAttributes, props.clientId, 'hidden', GFB_LEGACY_NAMES_HIDDEN );
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Verstecktes Feld', 'gutenberg-formbuilder' ), initialOpen: true },
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
				el( 'p', null, __( 'Verstecktes Feld (nur technischer Wert)', 'gutenberg-formbuilder' ) )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el( 'input', {
				type: 'hidden',
				name: a.name,
				value: a.hiddenValue || '',
			} );
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
			useEffect(
				function () {
					setSliderVal( computeRangeInitial( min, max, step, def ) );
				},
				[ min, max, step, def ]
			);
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', { for: 'gfb-range-preview-' + props.clientId }, attributes.label || __( 'Wert', 'gutenberg-formbuilder' ) ),
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
			return el(
				'div',
				{ className: 'gfb-field gfb-field-range', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
			return el(
				'div',
				{ className: 'gfb-editor-field', style: buildMergedFieldColorStyle( attributes, props.context || {} ) },
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
				el( 'label', null, attributes.label || __( 'Datei', 'gutenberg-formbuilder' ) ),
				el( 'input', { type: 'file', disabled: true } )
			);
		},
		save: function ( props ) {
			var a = props.attributes;
			return el(
				'div',
				{ className: 'gfb-field gfb-field-file', style: buildFieldColorOverrideStyle( a ) },
				el( 'label', { for: a.name }, a.label ),
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
} )( window.wp );
