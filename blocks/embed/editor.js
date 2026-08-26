/**
 * The block's editor UI — hand-written ES5, no build step.
 *
 * ⚠ There is deliberately no live preview. WordPress renders the editor canvas in an iframe, and
 * `customElements` registries are per-document, so a custom element defined by a script in the
 * parent admin document never upgrades inside the canvas. The widget would sit there as an inert
 * unknown element forever. Booting it inside the iframe is possible via `enqueue_block_assets`, but
 * that means running a third-party widget — network calls, global state, analytics — on every
 * editor load. A placeholder is the honest answer.
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

		// Dynamic: the server renders it, so nothing is saved into post content.
		save: function () {
			return null;
		}
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n );
