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

printf(
	'<div %s>%s</div>',
	// `get_block_wrapper_attributes()` is what applies the alignment and any theme block styles.
	wp_kses_data( get_block_wrapper_attributes() ),
	// The markup is a bare `<sahaj-atlas>` element we built ourselves, with no host input in it —
	// the route is validated by `sahaj_atlas_clean_route()` and is not interpolated here.
	wp_kses( $sahaj_atlas_markup, array( 'sahaj-atlas' => array( 'style' => true ) ) )
);
