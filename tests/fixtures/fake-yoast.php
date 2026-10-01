<?php
/**
 * An SEO plugin that owns `<title>` the way Yoast does, for a request carrying
 * `?sahaj_fixture_seo_plugin=yoast`.
 *
 * The plugin claims to replace Yoast, All in One SEO and Rank Math on atlas routes. Nothing
 * verified that claim against a plugin that actually fights for the title, and the two ways it
 * loses are invisible from inside the plugin's own code: a filter registered after ours wins, and
 * a vendor that removes core's `<title>` printer leaves the page with none once we silence the
 * vendor.
 *
 * ⚠ Every line below is behind that request flag, `WPSEO_VERSION` included, the way
 * `tests/fixture-theme.php` gates its theme swap. One classic server then covers both a site with
 * no SEO plugin and a site with one. Defining the constant unconditionally would send every
 * request on that server down the Yoast suppression branch, and the lane's existing assertions
 * would quietly stop testing a site without a vendor.
 *
 * ⚠ Fixture pre-mortem. This assumes Yoast's front end registers, on `init`:
 * `pre_get_document_title` at priority **15** with a callback that takes **no argument**, so it
 * cannot pass an earlier value through; `wp_head` at 1 calling `do_action( 'wpseo_head' )`;
 * `wpseo_head` at **-9999**; and `remove_action()` for core's three title printers. Verified
 * against `register_hooks()`, `filter_title()` and `call_wpseo_head()` in
 * `src/integrations/front-end-integration.php` of Yoast SEO 28.6, and `Title_Presenter::get()` in
 * `src/presenters/title-presenter.php`. The priorities are the part this fixture exists to pin:
 * registered at 10, or passing `$title` through, it would pass against both defects.
 *
 * ⚠ Not the real plugin, deliberately. Installing `wordpress-seo` would make every run of this
 * lane fetch a zip from wordpress.org, which is the outbound request `tests/no-network.php`
 * exists to refuse, and would retest Yoast's indexable tables rather than our suppression. What
 * the plugin reacts to is the hook shape and `WPSEO_VERSION`, and both are here.
 *
 * ⚠ No `YoastSEO()` function. `sahaj_atlas_seo_suppress_others()` guards its
 * `Front_End_Integration` lookup with `function_exists()`, so that half is skipped here and
 * `remove_all_actions( 'wpseo_head' )` is what has to do the work — which is the half that runs
 * on a site where the lookup fails too.
 *
 * @package SahajAtlas
 */

/** What this fixture would put in `<title>` and the description, if it won. */
define( 'SAHAJ_ATLAS_FAKE_YOAST_TITLE', 'Find a class - Example Site' );
define( 'SAHAJ_ATLAS_FAKE_YOAST_DESCRIPTION', 'The description Example Site wrote for this page.' );

if ( isset( $_GET['sahaj_fixture_seo_plugin'] ) && 'yoast' === $_GET['sahaj_fixture_seo_plugin'] ) {
	define( 'WPSEO_VERSION', '28.6' );

	add_action( 'init', 'sahaj_atlas_fake_yoast_register' );
}

function sahaj_atlas_fake_yoast_register() {
	add_filter( 'pre_get_document_title', 'sahaj_atlas_fake_yoast_title', 15 );

	add_action( 'wp_head', 'sahaj_atlas_fake_yoast_call_head', 1 );
	add_action( 'wpseo_head', 'sahaj_atlas_fake_yoast_present_head', -9999 );

	remove_action( 'wp_head', '_wp_render_title_tag', 1 );
	remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
	remove_action( 'wp_head', 'gutenberg_render_title_tag', 1 );
	remove_action( 'wp_head', 'rel_canonical' );
}

/**
 * ⚠ No parameter, exactly as Yoast's `filter_title()` has none. A vendor that cannot see the
 * incoming value cannot be beaten by composing a better one — only by running after it.
 *
 * @return string
 */
function sahaj_atlas_fake_yoast_title() {
	return SAHAJ_ATLAS_FAKE_YOAST_TITLE;
}

function sahaj_atlas_fake_yoast_call_head() {
	do_action( 'wpseo_head' );
}

/** Everything this fixture emits, so an assertion can tell a silenced vendor from a live one. */
function sahaj_atlas_fake_yoast_present_head() {
	printf(
		"<title>%s</title>\n<meta name=\"description\" content=\"%s\" />\n",
		esc_html( SAHAJ_ATLAS_FAKE_YOAST_TITLE ),
		esc_attr( SAHAJ_ATLAS_FAKE_YOAST_DESCRIPTION )
	);
}
