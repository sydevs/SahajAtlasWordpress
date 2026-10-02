<?php
/**
 * Hands the Atlas page to the theme's own page template, for a request carrying
 * `?sahaj_fixture_template=theme`.
 *
 * ⚠ Two mechanisms, one per theme kind, because the plugin has two template paths and each is taken
 * over differently on a real site. A classic theme loses the page to a later `template_include`
 * filter — what Elementor Canvas, Beaver Themer, Divi Theme Builder and every maintenance-mode
 * plugin do. A block theme loses it when the page's `_wp_page_template` meta stops naming the
 * plugin's template, which is what a volunteer switching the template in the Site Editor leaves
 * behind. Both land on the theme's `page.php` or `page.html`: header, content, footer.
 *
 * ⚠ Never both at once. `get_page_template()` on a block theme falls through to `index.php`, which
 * is not a page the fixture is meant to produce.
 *
 * Only the render lane writes this as an mu-plugin, alongside `tests/fixture-theme.php`.
 *
 * @package SahajAtlas
 */

add_action( 'after_setup_theme', 'sahaj_fixture_template_arm' );

/**
 * ⚠ Not at file scope: an mu-plugin loads before the plugin, so `SAHAJ_ATLAS_OPTION_PAGE` does not
 * exist yet, and `wp_is_block_theme()` has no theme to answer about.
 */
function sahaj_fixture_template_arm() {
	if ( ! isset( $_GET['sahaj_fixture_template'] ) || 'theme' !== $_GET['sahaj_fixture_template'] ) {
		return;
	}

	if ( wp_is_block_theme() ) {
		add_filter( 'get_post_metadata', 'sahaj_fixture_template_clear_meta', 10, 3 );

		return;
	}

	// ⚠ Priority 99, past the plugin's own filter at 10. Any lower and the plugin would run last
	// and win, so the fixture would quietly test the page it exists to displace.
	add_filter( 'template_include', 'sahaj_fixture_template_theme_page', 99 );
}

/**
 * @return string The theme's own page template.
 */
function sahaj_fixture_template_theme_page() {
	return get_page_template();
}

/**
 * @param mixed  $value     The short-circuit value.
 * @param int    $object_id The post being read.
 * @param string $meta_key  The key being read.
 * @return mixed
 */
function sahaj_fixture_template_clear_meta( $value, $object_id, $meta_key ) {
	if ( '_wp_page_template' !== $meta_key || (int) $object_id !== (int) get_option( SAHAJ_ATLAS_OPTION_PAGE, 0 ) ) {
		return $value;
	}

	return '';
}
