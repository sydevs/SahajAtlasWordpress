<?php
/**
 * This file renders the block's front-end output. It shares `sahaj_atlas_render_embed()` with the
 * shortcode.
 *
 * `block.json` references this file as `"render": "file:./render.php"` (WP 6.1+). This is why the
 * block needs no `render_callback`.
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
 * ⚠ This markup does NOT pass through `wp_kses`. Passing it through once broke the block silently.
 * `wp_kses` filters the `style` attribute with `safecss_filter_attr()`. That function's allowlist
 * has no `display` property. It turned `display:block;height:520px` into `height:520px`. A custom
 * element defaults to `display: inline`, which cannot take a height. The block then rendered an
 * unsized element: collapsed with no map, and a window-covering takeover with one. The shortcode
 * does not sanitize, so it stayed correct. Only the block broke.
 *
 * Sanitizing here buys nothing anyway. `sahaj_atlas_element_markup()` builds a fixed string from
 * one boolean. No attribute value comes from a caller. The route rides on the script URL, not on
 * the element. `wp_kses` belongs on content someone else authored.
 */
printf(
	'<div %s>%s</div>',
	// `get_block_wrapper_attributes()` applies alignment and theme block styles.
	wp_kses_data( get_block_wrapper_attributes() ),
	$sahaj_atlas_markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sahaj_atlas_element_markup() builds this string. See the comment above.
);
