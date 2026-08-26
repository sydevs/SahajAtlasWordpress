<?php
/**
 * The Atlas page, on a classic theme.
 *
 * The site header, then the atlas filling the rest of the screen, then nothing — no footer markup.
 *
 * ⚠ **`get_footer()` is absent and `wp_footer()` is not.** They are different functions and only
 * the first is optional here: `get_footer()` loads the theme's `footer.php` (the visible markup we
 * are dropping), while `wp_footer()` fires the hook that prints the admin bar, every other
 * plugin's scripts, and — on a classic theme — the widget's own script module, which core queues
 * for the footer. Skipping it would leave the atlas not loading at all.
 *
 * ⚠ **This file is never reached on a block theme** — `sahaj_atlas_template_include()` returns
 * early there — and that guard is what keeps `get_header()` away from one. On a block theme it
 * finds no `header.php`, falls through to `wp-includes/theme-compat/header.php`, and emits a whole
 * second 2010-era `<!DOCTYPE html><html><head>` plus a deprecation notice: silently malformed
 * rather than loudly broken. `tests/render.mjs` counts doctypes for exactly this.
 *
 * ⚠ **The theme's `header.php` usually opens wrappers it never gets to close**, because we skip
 * `footer.php`. Browsers close them at `</body>` and it has no visible effect — and unlike before
 * SahajAtlasWeb#170 it cannot hurt the map either, since a contained map establishes its own
 * containing block and is unaffected by a `transform` or `contain` on an ancestor.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/*
 * ⚠ A classic theme with no `header.php` at all would hit the same theme-compat fallback as a block
 * theme, so it gets our own minimal document instead. Rare, but the failure is a malformed page
 * rather than an error anybody would notice.
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
