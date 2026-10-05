/**
 * The block's editor UI. Hand-written ES5, with no build step.
 *
 * ⚠ This block shows no live preview, on purpose. WordPress renders the editor canvas inside an
 * iframe. `customElements` registries are per document, so a custom element the parent admin
 * document defines never upgrades inside that iframe. The widget would sit there forever as an
 * inert, unknown element.
 *
 * `enqueue_block_assets` could boot the widget inside the iframe instead. That approach runs a
 * third-party widget on every editor load: its own network calls, global state, and analytics. A
 * placeholder is the honest choice.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'sahaj-atlas/embed', {
		edit: function ( props ) {
			var attributes = props.attributes;

			var instructions = attributes.atlas
				? __( 'Opens at: ', 'sahaj-atlas' ) + attributes.atlas
				: attributes.map
					? __( 'Shows the map of classes.', 'sahaj-atlas' )
					: __( 'Shows the list of classes. Set a place to open a country, a city or one class instead.', 'sahaj-atlas' );

			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Atlas', 'sahaj-atlas' ) },
						el( components.TextControl, {
							label: __( 'Open at (optional)', 'sahaj-atlas' ),
							help: __( 'A country or city such as /gb or /gb/london, one class such as /gb/london/1234, or /gb/london/1234/register for its sign-up form. You can also paste its address from sahajatlas.com.', 'sahaj-atlas' ),
							value: attributes.atlas || '',
							onChange: function ( value ) {
								props.setAttributes( { atlas: value } );
							}
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the map', 'sahaj-atlas' ),
							help: __( 'Give the map a full-width space. In a column narrower than 360px it shows a button that opens the map instead.', 'sahaj-atlas' ),
							checked: !! attributes.map,
							onChange: function ( value ) {
								props.setAttributes( { map: value } );
							}
						} )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( components.Placeholder, {
						icon: 'location-alt',
						label: __( 'Sahaj Atlas', 'sahaj-atlas' ),
						instructions: instructions
					} )
				)
			);
		},

		// This is a dynamic block. The server renders its output. The editor saves nothing into
		// post content.
		save: function () {
			return null;
		}
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n );
