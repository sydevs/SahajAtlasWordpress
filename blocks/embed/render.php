<?php
/**
 * Front-end output for the block. Shares `sahaj_atlas_render_embed()` with the shortcode.
 *
 * Referenced from `block.json` as `"render": "file:./render.php"` (WP 6.1+), which is why there is
 * no `render_callback` to wire up.
 *
 * @package SahajAtlas
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$sahaj_atlas_embed = sahaj_atlas_normalize_attrs(
	isset( $attributes ) && is_array( $attributes ) ? $attributes : array(),
	'block'
);

$sahaj_atlas_markup = sahaj_atlas_render_embed( $sahaj_atlas_embed );

if ( '' === $sahaj_atlas_markup ) {
	return;
}

/*
 * ⚠ **The markup is NOT passed through `wp_kses`, and running it through one silently broke the
 * block.** `wp_kses` filters a `style` attribute with `safecss_filter_attr()`, whose property
 * allowlist does not include `display` — so `display:block;height:520px` came out as
 * `height:520px`, and a custom element defaults to `display: inline`, which cannot take a height.
 * The block therefore rendered an unsized element: collapsed for a map-less embed, and a
 * window-covering takeover for a map one. The shortcode, which does not sanitize, was fine — so
 * the two paths disagreed and only the block was wrong.
 *
 * Sanitizing here was never buying anything either. `sahaj_atlas_element_markup()` builds a fixed
 * string from one boolean; no attribute value comes from a caller, and the route rides on the
 * script URL rather than on the element. `wp_kses` belongs on content somebody else authored.
 */
printf(
	'<div %s>%s</div>',
	// `get_block_wrapper_attributes()` is what applies the alignment and any theme block styles.
	wp_kses_data( get_block_wrapper_attributes() ),
	$sahaj_atlas_markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely by sahaj_atlas_element_markup(); see above.
);
