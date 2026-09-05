<?php
/**
 * The Atlas page, for a classic theme.
 *
 * This prints the site header, then the atlas filling the rest of the screen, then nothing more.
 * It prints no footer markup.
 *
 * ⚠ `get_footer()` is absent here, but `wp_footer()` is not. These are different functions.
 * `get_footer()` only loads the theme's visible `footer.php` markup, which this page skips on
 * purpose. `wp_footer()` fires the hook that prints the admin bar, other plugins' scripts, and —
 * on a classic theme — the widget's own script module. Skipping `wp_footer()` would stop the
 * atlas from loading at all.
 *
 * ⚠ This file never runs on a block theme: `sahaj_atlas_template_include()` returns early there.
 * That guard keeps `get_header()` away from a block theme. A block theme has no `header.php`.
 * `get_header()` would then fall through to `wp-includes/theme-compat/header.php`. It would print
 * a second, 2010-era `<!DOCTYPE html>`. That failure is silent, not loud. `tests/render.mjs`
 * counts doctypes to catch it.
 *
 * ⚠ The theme's `header.php` usually opens wrappers. This page never closes them, since it skips
 * `footer.php`. Browsers close them at `</body>` with no visible effect. This also cannot harm the
 * map. A contained map sets its own containing block. A `transform` or `contain` style on an
 * ancestor cannot affect it.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/*
 * ⚠ A classic theme with no `header.php` at all would hit the same theme-compat fallback as a
 * block theme. This page then prints its own minimal document instead. This case is rare. Its
 * failure mode is a malformed page, not an error anyone would notice.
 */
$sahaj_atlas_has_header = '' !== locate_template( array( 'header.php' ) );

if ( $sahaj_atlas_has_header ) {
	get_header();
} else {
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php
	wp_body_open();
}

sahaj_atlas_render_element_once();

wp_footer();
?>
</body>
</html>
