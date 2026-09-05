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

			var instructions = attributes.map
				? __(
						'Map mode fills the whole browser window and will cover this page. Use the Atlas page for the map.',
						'sahaj-atlas'
				  )
				: attributes.atlas
					? __( 'Opens at: ', 'sahaj-atlas' ) + attributes.atlas
					: __( 'Shows the list of classes. Set a route to open one class instead.', 'sahaj-atlas' );

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
							help: __( 'A route such as /in/pune/507, or /in/pune/507/register for its sign-up form.', 'sahaj-atlas' ),
							value: attributes.atlas || '',
							onChange: function ( value ) {
								props.setAttributes( { atlas: value } );
							}
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the map', 'sahaj-atlas' ),
							help: __( 'Off for an embed inside a page. The map always takes the whole window.', 'sahaj-atlas' ),
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
