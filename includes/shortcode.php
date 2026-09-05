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
 * Render `[sahaj_atlas atlas="/in/pune/507"]`.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function sahaj_atlas_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'map'   => 'false',
			'atlas' => '',
		),
		is_array( $atts ) ? $atts : array(),
		'sahaj_atlas'
	);

	return sahaj_atlas_render_embed( sahaj_atlas_normalize_attrs( $atts, 'shortcode' ) );
}

/**
 * The one renderer behind both the shortcode and the block.
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
