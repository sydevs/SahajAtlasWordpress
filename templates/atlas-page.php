<?php
/**
 * The Atlas page, on a classic theme.
 *
 * ⚠ **`get_footer()` is absent and `wp_footer()` is not.** They are different functions and only
 * the first is optional here: `get_footer()` loads the theme's `footer.php` (the visible markup we
 * are dropping), while `wp_footer()` fires the hook that prints the admin bar, every other
 * plugin's scripts, and — on a classic theme — the widget's own script module, which core queues
 * for the footer. Skipping it would leave the atlas not loading at all.
 *
 * ⚠ **`get_header()` is not called on a block theme**, and this file is never reached on one —
 * `sahaj_atlas_template_include()` returns early there. On a block theme `get_header()` finds no
 * `header.php`, falls through to `wp-includes/theme-compat/header.php`, and emits a whole second
 * 2010-era `<!DOCTYPE html><html><head>` plus a deprecation notice. It fails silently and
 * malformed rather than loudly.
 *
 * ⚠ The site header is deliberately absent for now — see the note in
 * `sahaj_atlas_register_page_template()` and SahajAtlasWeb#169.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

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

/*
 * A fallback for the handful of themes that never call `wp_body_open()` — it has been core since
 * WP 5.2, but a custom theme a volunteer inherited may predate it, and some page builders render
 * their own header markup. The function guards itself, so on a well-behaved theme this is a no-op
 * and the element has already been printed as a direct child of `<body>`, which is where it wants
 * to be.
 */
sahaj_atlas_render_element_once();

wp_footer();
?>
</body>
</html>
