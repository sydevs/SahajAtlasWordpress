<?php
/**
 * `[sahaj_atlas]` — the in-content embed.
 *
 * This is the universal entry point. Every page builder in the fleet can insert a shortcode, and
 * none of them can insert a Gutenberg block. The block in `blocks/embed/` shares this renderer.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the shortcode. On `init`, with everything else.
 */
function sahaj_atlas_register_shortcode() {
	add_shortcode( 'sahaj_atlas', 'sahaj_atlas_shortcode' );
}

/**
 * Render `[sahaj_atlas]`, or `[sahaj_atlas atlas="/gb/london" map="false"]`.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sahaj_atlas_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'map'   => 'true',
			'atlas' => '',
			'ratio' => '',
		),
		is_array( $atts ) ? $atts : array(),
		'sahaj_atlas'
	);

	return sahaj_atlas_render_embed( sahaj_atlas_normalize_attrs( $atts, 'shortcode' ) );
}

/**
 * The one renderer behind the shortcode, the block and the Beaver Builder module.
 *
 * @param array $embed A normalized embed.
 * @return string
 */
function sahaj_atlas_render_embed( $embed ) {
	if ( '' === sahaj_atlas_api_key() ) {
		return sahaj_atlas_admin_only_notice(
			__( 'Sahaj Atlas: no API key has been set. Add one under Settings → Sahaj Atlas.', 'sahaj-atlas' )
		);
	}

	if ( sahaj_atlas_editor_canvas() ) {
		return sahaj_atlas_editor_placeholder( $embed );
	}

	$markup = sahaj_atlas_element_markup( $embed );

	if ( '' === $markup ) {
		// Something else on this page already claimed the one allowed widget.
		return sahaj_atlas_admin_only_notice(
			__( 'Sahaj Atlas: only one atlas can appear on a page, and another one on this page came first.', 'sahaj-atlas' )
		);
	}

	return $markup;
}

/**
 * The static placeholder a page builder's canvas shows in place of the widget.
 *
 * ⚠ The decision the block editor already took, for the reason `blocks/embed/editor.js` records:
 * the widget cannot upgrade inside an editor's iframe, and booting a third-party widget on every
 * editor load spends its network calls and analytics on nobody. The wording is that file's, word
 * for word, so a volunteer sees one placeholder whichever editor they opened — and the translation
 * template gains no near-duplicates, since identical msgids merge.
 *
 * @param array $embed A normalized embed.
 * @return string
 */
function sahaj_atlas_editor_placeholder( $embed ) {
	if ( '' !== $embed['atlas'] ) {
		$instructions = __( 'Opens at: ', 'sahaj-atlas' ) . $embed['atlas'];
	} elseif ( $embed['map'] ) {
		$instructions = __( 'Shows the map of classes.', 'sahaj-atlas' );
	} else {
		$instructions = __( 'Shows the list of classes. Set a place to open a country, a city or one class instead.', 'sahaj-atlas' );
	}

	return '<div class="sahaj-atlas-placeholder" style="padding:2em;text-align:center;border:1px dashed currentColor">'
		. '<strong>' . esc_html__( 'Sahaj Atlas', 'sahaj-atlas' ) . '</strong><br />'
		. esc_html( $instructions ) . '</div>';
}

/**
 * A message only an editor sees. Visitors get nothing rather than a broken box.
 *
 * @param string $message The message.
 * @return string
 */
function sahaj_atlas_admin_only_notice( $message ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}

	return '<p class="sahaj-atlas-notice"><em>' . esc_html( $message ) . '</em></p>';
}
