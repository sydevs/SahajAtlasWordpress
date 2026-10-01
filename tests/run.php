<?php
/**
 * Integration tests: a real WordPress, booted by `@wp-playground/cli`, with the plugin active.
 *
 * ⚠ These are not unit tests, on purpose. Everything worth testing here is an agreement with
 * WordPress itself:
 *
 * - Does `parse_request` fire for a deep URL.
 * - Does a page template survive `template_include`.
 * - Does `add_query_arg` escape what this plugin hands it.
 *
 * A mock of WordPress would only assert the mock. This suite covers three things: route matching
 * (the one piece of real logic here, and it fails silently in production), attribute escaping
 * (cheap, and security-relevant), and whether the plugin loads at all.
 *
 *   pnpm test
 */

/** @var int */
$sahaj_failures = 0;
/** @var int */
$sahaj_assertions = 0;

/**
 * @param string $label What is being asserted.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual Actual value.
 */
function sahaj_is( $label, $expected, $actual ) {
	global $sahaj_failures, $sahaj_assertions;

	++$sahaj_assertions;

	if ( $expected === $actual ) {
		echo "  ok    $label\n";

		return;
	}

	++$sahaj_failures;

	echo "  FAIL  $label\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * @param string $label What is being asserted.
 * @param bool   $condition The condition.
 */
function sahaj_ok( $label, $condition ) {
	sahaj_is( $label, true, (bool) $condition );
}

/**
 * @param string $group Heading.
 */
function sahaj_group( $group ) {
	echo "\n$group\n";
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The plugin loaded' );

sahaj_ok( 'is active', is_plugin_active( 'sahaj-atlas/sahaj-atlas.php' ) );
sahaj_is( 'registered the shortcode', true, shortcode_exists( 'sahaj_atlas' ) );
sahaj_ok( 'registered the block', WP_Block_Type_Registry::get_instance()->is_registered( 'sahaj-atlas/embed' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A route the widget would refuse never reaches an attribute' );

// Each of these is a shape the widget's own `safePath` rejects. `/\evil.com` matters because a
// browser normalizes the backslash to a slash. The tab, LF, and CR forms matter because the
// WHATWG URL parser strips those characters before parsing. So `/<TAB>/evil.com` parses as
// `//evil.com`.
foreach ( array(
	'//evil.com',
	'/\\evil.com',
	"/\t/evil.com",
	"/\n/evil.com",
	"/\r/evil.com",
	'https://evil.com',
	'javascript:alert(1)',
	'gb/london',
) as $hostile ) {
	sahaj_is(
		'refuses ' . str_replace( array( "\t", "\n", "\r" ), array( '\t', '\n', '\r' ), $hostile ),
		'',
		sahaj_atlas_clean_route( $hostile )
	);
}

foreach ( array( '/gb/london', '/in/pune/507', '/in/pune/507/register', '/' ) as $good ) {
	sahaj_is( "accepts $good", $good, sahaj_atlas_clean_route( $good ) );
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The script URL is the whole configuration surface' );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

$url = sahaj_atlas_script_url( array( 'map' => true, 'atlas' => '', 'source' => 'page' ) );

sahaj_ok( 'points at auto.js on the widget origin', 0 === strpos( $url, SAHAJ_ATLAS_WIDGET_ORIGIN . '/auto.js?' ) );
sahaj_ok( 'carries the key', false !== strpos( $url, 'key=test-key-123' ) );
sahaj_ok( 'never sends map=true — absent means on', false === strpos( $url, 'map=true' ) );
sahaj_ok( 'never sends a locale — the page\'s <html lang> is the source', false === strpos( $url, 'locale=' ) );

$off = sahaj_atlas_script_url( array( 'map' => false, 'atlas' => '/gb/london', 'source' => 'shortcode' ) );

sahaj_ok( 'sends map=false when the map is off', false !== strpos( $off, 'map=false' ) );
sahaj_ok( 'sends the route', false !== strpos( $off, 'atlas=' ) );
sahaj_ok( 'never claims path routing for an in-content embed', false === strpos( $off, 'routing=path' ) );

// A key is sanitized on save, but the URL builder is its own sink too. This test checks the
// escaping, not the sanitizer. Removing either one makes this test fail.
update_option( SAHAJ_ATLAS_OPTION_KEY, 'a"b<c>&d' );

$nasty = sahaj_atlas_script_url( array( 'map' => true, 'atlas' => '', 'source' => 'page' ) );

sahaj_ok(
	'a key with markup characters cannot break out of the URL: ' . $nasty,
	! preg_match( '/[<>"\']/', $nasty )
);

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The element markup escapes everything that reaches it' );

$GLOBALS['sahaj_atlas_active']  = array( 'map' => false, 'atlas' => '/gb/london', 'source' => 'shortcode' );
$GLOBALS['sahaj_atlas_printed'] = false;

$markup = sahaj_atlas_element_markup( $GLOBALS['sahaj_atlas_active'] );

sahaj_ok( 'renders one element', 1 === substr_count( $markup, '<sahaj-atlas' ) );

/*
 * ⚠ This assertion has two halves: `height`, never `min-height`, and `display: block`.
 *
 * The widget fills its element with `height: 100%`, which needs a definite height to resolve
 * against. `min-height` sizes the element on screen but leaves the widget nothing to fill. So the
 * widget refuses the box and falls back to covering the whole browser window
 * (SahajAtlasWeb#170). The plugin once shipped `min-height`, and this same assertion passed
 * against it. The test was pinning the defect, not catching it.
 *
 * `display: block` matters just as much. A custom element defaults to `display: inline`, and an
 * inline element cannot take a height at all. A height rule with no `display: block` is not a
 * size.
 */
sahaj_ok( 'a map-less embed is given a definite height', (bool) preg_match( '/[^-]height:\s*\d/', $markup ) );
sahaj_ok( 'and not merely a min-height', false === strpos( $markup, 'min-height' ) );
sahaj_ok( 'and display:block, without which a height does nothing', false !== strpos( $markup, 'display:block' ) );

sahaj_is( 'and never a second one for the same request', '', sahaj_atlas_element_markup( $GLOBALS['sahaj_atlas_active'] ) );

$GLOBALS['sahaj_atlas_printed'] = false;
$GLOBALS['sahaj_atlas_active']  = array( 'map' => true, 'atlas' => '', 'source' => 'shortcode' );

// An in-content map embed is sized too. That sizing is what makes it a contained map, not a
// takeover of the article around it. Before #170, a map could only cover the whole window. So
// this element deliberately carried no CSS at all.
sahaj_ok(
	'an in-content map embed is contained, not a takeover',
	(bool) preg_match( '/[^-]height:\s*\d/', sahaj_atlas_element_markup( $GLOBALS['sahaj_atlas_active'] ) )
);

$GLOBALS['sahaj_atlas_printed'] = false;
$GLOBALS['sahaj_atlas_active']  = array( 'map' => true, 'atlas' => '', 'source' => 'page' );

/*
 * ⚠ The Atlas page's element carries no inline style. That is not a leftover of the old rule.
 * `assets/atlas-page.css` sizes it instead, so a host can override the height with ordinary CSS.
 * An inline style would need `!important` to beat, on the one property that decides whether the
 * map is contained at all.
 */
sahaj_is( 'the Atlas page element is sized by the stylesheet, not inline', '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_atlas_page_element_markup( 'template' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The Atlas page' );

$page_id = sahaj_atlas_create_page();

sahaj_ok( 'is created', is_int( $page_id ) && $page_id > 0 );
sahaj_is( 'is remembered', $page_id, sahaj_atlas_page_id() );
sahaj_ok( 'is healthy', sahaj_atlas_page_is_healthy() );
sahaj_ok( 'is recognised as ours', sahaj_atlas_is_atlas_page( $page_id ) );
sahaj_is( 'carries our template', SAHAJ_ATLAS_TEMPLATE . '.php', get_post_meta( $page_id, '_wp_page_template', true ) );
sahaj_is( 'is the only one', array(), sahaj_atlas_stray_pages() );

// `body_class` is filtered globally. It must add the class on our page, and on no other.
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_id ) );
$GLOBALS['wp_query']->the_post();

sahaj_ok( 'adds a body class on the Atlas page', in_array( 'sahaj-atlas-page', sahaj_atlas_body_class( array( 'page' ) ), true ) );

wp_reset_postdata();
$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'page', 'post__not_in' => array( $page_id ) ) );

sahaj_ok( 'and not on any other page', ! in_array( 'sahaj-atlas-page', sahaj_atlas_body_class( array( 'page' ) ), true ) );

// Creating the page twice must not make a second page. A volunteer will press the button again.
$again = sahaj_atlas_create_page();

sahaj_is( 'pressing create twice reuses the page', $page_id, is_wp_error( $again ) ? $page_id : $again );
sahaj_is( 'so there is still only one', array(), sahaj_atlas_stray_pages() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The Atlas page asks a hero theme for its hero-less header' );

/** @return string A child theme's slug. */
function sahaj_as_child_theme() {
	return 'mesmerize-child';
}

sahaj_is( 'an unlisted theme keeps its default header.php', null, sahaj_atlas_header_name() );

foreach ( array( 'mesmerize', 'mesmerize-pro' ) as $sahaj_theme ) {
	$sahaj_as_theme = function () use ( $sahaj_theme ) {
		return $sahaj_theme;
	};

	add_filter( 'template', $sahaj_as_theme );
	add_filter( 'stylesheet', $sahaj_as_theme );
	sahaj_is( "$sahaj_theme gets header-small.php, which has no hero", 'small', sahaj_atlas_header_name() );
	remove_filter( 'stylesheet', $sahaj_as_theme );

	// A child theme may have customised `header.php`. The parent's variant would drop that.
	add_filter( 'stylesheet', 'sahaj_as_child_theme' );
	sahaj_is( "a child of $sahaj_theme keeps today's header", null, sahaj_atlas_header_name() );
	remove_filter( 'stylesheet', 'sahaj_as_child_theme' );
	remove_filter( 'template', $sahaj_as_theme );
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Path routing matches the subtree and nothing else' );

update_option( 'permalink_structure', '/%postname%/' );

$base = get_page_uri( sahaj_atlas_page_id() );

sahaj_ok( 'the page has a path to route under', is_string( $base ) && '' !== $base );

/**
 * Drive `sahaj_atlas_parse_request()` with a request path and report the route it claimed.
 *
 * @param string $path Request path, no leading slash.
 * @return string|null The claimed route, or null when the plugin did not claim the request.
 */
function sahaj_route_for( $path ) {
	$wp              = new WP();
	$wp->request     = $path;
	$wp->query_vars  = array();

	sahaj_atlas_parse_request( $wp );

	return isset( $wp->query_vars[ SAHAJ_ATLAS_ROUTE_VAR ] ) ? $wp->query_vars[ SAHAJ_ATLAS_ROUTE_VAR ] : null;
}

/**
 * Put a route in `?atlas=`, the way a browser would deliver it.
 *
 * ⚠ `$_GET` is already slashed by the time any plugin reads it. `wp_magic_quotes()` runs on every
 * request, before `init`, so the plugin unslashes — and a fixture that assigns a raw value tests a
 * request WordPress never delivers. `/\evil.com` is where this shows: unslashing a raw value eats
 * the backslash and the hostile shape sails through as `/evil.com`. Every query-routing case here
 * goes through this function for that reason.
 *
 * @param mixed $value The value as a browser would send it.
 */
function sahaj_set_query_route( $value ) {
	$_GET[ SAHAJ_ATLAS_ROUTE_PARAM ] = wp_slash( $value );
}

/**
 * Take the route back out again. The parameter is written and cleared in one place only.
 */
function sahaj_clear_query_route() {
	unset( $_GET[ SAHAJ_ATLAS_ROUTE_PARAM ] );
}

/**
 * Make the Atlas page the request WordPress thinks it is serving.
 *
 * `sahaj_atlas_is_atlas_page()` reads `is_page()` and `get_queried_object_id()` off the main query,
 * so every query-routing assertion needs this first. One spelling, since a second one that forgets
 * part of the setup shows up as a takeover that silently does not run.
 *
 * @param int|null $page_id Page to query, or null for the Atlas page.
 * @return WP_Query
 */
function sahaj_query_page( $page_id = null ) {
	return new WP_Query( array( 'page_id' => null === $page_id ? sahaj_atlas_page_id() : $page_id ) );
}

sahaj_is( 'a deep link is claimed', '/gb/london', sahaj_route_for( "$base/gb/london" ) );
sahaj_is( 'a deeper one too', '/in/pune/507/register', sahaj_route_for( "$base/in/pune/507/register" ) );
sahaj_is( 'the page itself is left to WordPress', null, sahaj_route_for( $base ) );
sahaj_is( 'an unrelated page is left alone', null, sahaj_route_for( 'about-us' ) );
sahaj_is( 'a page that merely starts with the same letters is left alone', null, sahaj_route_for( $base . '-archive/gb' ) );

// A real page below the Atlas page belongs to whoever made it.
$child = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Directions',
		'post_name'   => 'directions',
		'post_parent' => sahaj_atlas_page_id(),
	)
);

sahaj_is( 'a real child page wins over the route', null, sahaj_route_for( "$base/directions" ) );

wp_delete_post( $child, true );

sahaj_is( 'and once it is gone the route is claimed again', '/directions', sahaj_route_for( "$base/directions" ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Path routing needs pretty permalinks, and says so rather than half-working' );

sahaj_ok( 'viable with pretty permalinks', sahaj_atlas_path_routing_viable() );

update_option( 'permalink_structure', '' );

sahaj_ok( 'not viable with plain permalinks', ! sahaj_atlas_path_routing_viable() );
sahaj_is( 'and no route is claimed', null, sahaj_route_for( "$base/gb/london" ) );

$plain = sahaj_atlas_script_url( array( 'map' => true, 'atlas' => '', 'source' => 'page' ) );

sahaj_ok( 'so the widget is never told to use a mode this site cannot serve', false === strpos( $plain, 'routing=path' ) );

update_option( 'permalink_structure', '/%postname%/' );

$pretty = sahaj_atlas_script_url( array( 'map' => true, 'atlas' => '', 'source' => 'page' ) );

sahaj_ok( 'and is told once it can', false !== strpos( $pretty, 'routing=path' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The canonical redirect is suppressed only for our routes' );

sahaj_is( 'a normal request still redirects', 'https://example.com/x', sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

set_query_var( SAHAJ_ATLAS_ROUTE_VAR, '/gb/london' );

sahaj_is( 'a deep atlas link does not', false, sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

set_query_var( SAHAJ_ATLAS_ROUTE_VAR, '' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The mount key matches what SahajCloud stores' );

$mount = sahaj_atlas_mount_key();

sahaj_ok( 'has no scheme', false === strpos( $mount, '://' ) );
sahaj_ok( 'has no trailing slash', '/' !== substr( $mount, -1 ) );
sahaj_ok( 'names the host', 0 === strpos( $mount, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
sahaj_ok( 'and the page path', false !== strpos( $mount, (string) get_page_uri( sahaj_atlas_page_id() ) ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The Atlas page assets' );

$sahaj_asset_query   = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$GLOBALS['wp_query'] = sahaj_query_page();

sahaj_atlas_enqueue_page_assets();

sahaj_ok( 'the measurement script is enqueued', wp_script_is( 'sahaj-atlas-page', 'enqueued' ) );

// ⚠ Group 1 is the footer. The `why` lives at the enqueue, in `includes/embed.php` (#41).
sahaj_is( 'in the footer, not `<head>`', 1, wp_scripts()->get_data( 'sahaj-atlas-page', 'group' ) );

$GLOBALS['wp_query'] = $sahaj_asset_query;

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The element prints once, by the best path still available' );

/**
 * Run `the_content` the way a theme's page template does, with the print flag reset.
 *
 * @return string What the filter chain returned.
 */
function sahaj_content_print() {
	$GLOBALS['sahaj_atlas_printed'] = false;

	return (string) apply_filters( 'the_content', '' );
}

/** Ask for the content inside `<head>`, the way an SEO plugin building a description does. */
function sahaj_content_in_head() {
	$GLOBALS['sahaj_head_content'] = sahaj_content_print();
}

$sahaj_print_query   = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$GLOBALS['wp_query'] = sahaj_query_page();
$GLOBALS['wp_query']->the_post();

$GLOBALS['sahaj_atlas_active'] = array( 'map' => true, 'atlas' => '', 'source' => 'page' );

// A page builder's canvas template, or the theme's own `page.php`, renders the content area. The
// element belongs there, above the footer the theme is about to print.
$sahaj_printed_content = sahaj_content_print();

sahaj_ok( 'the content area prints it', false !== strpos( $sahaj_printed_content, 'data-sahaj-atlas-render="content"' ) );
sahaj_ok( 'exactly once', 1 === substr_count( $sahaj_printed_content, '<sahaj-atlas' ) );

/*
 * ⚠ `wpautop()` must not reach it. A `<p>` around the element adds a margin above and below, and
 * the contained map is sized to the viewport less its own top — so the margins push its bottom
 * edge, where the mobile drag handle lives, below the fold.
 */
sahaj_ok( 'and not wrapped in a paragraph', ! preg_match( '#<p>\s*<sahaj-atlas#', $sahaj_printed_content ) );

sahaj_is( 'so the footer fallback has nothing left to print', '', sahaj_atlas_page_element_markup( 'footer' ) );

// The last resort still renders the widget, for a template that runs neither path.
$GLOBALS['sahaj_atlas_printed'] = false;

sahaj_ok(
	'the footer fallback prints when nothing earlier did',
	false !== strpos( sahaj_atlas_page_element_markup( 'footer' ), 'data-sahaj-atlas-render="footer"' )
);

/*
 * ⚠ Two callers run `the_content` without rendering the page body, and either one would spend the
 * single print on a string nobody shows — leaving the page empty and the footer fallback no-opping
 * behind it. `wp_trim_excerpt()` is the first: `get_the_excerpt()` on a page with no excerpt of its
 * own runs the content through this filter to build one.
 */
$GLOBALS['sahaj_atlas_printed'] = false;
$sahaj_excerpt                  = (string) get_the_excerpt( sahaj_atlas_page_id() );

// ⚠ The flag, not just the string. Asserting on the excerpt alone passes while the print flag is
// already spent, which is exactly the state this case exists to rule out.
sahaj_ok(
	'an excerpt never spends the print',
	false === strpos( $sahaj_excerpt, '<sahaj-atlas' ) && ! $GLOBALS['sahaj_atlas_printed']
);

// The second is an SEO plugin asking for a description while `<head>` is being built.
$GLOBALS['sahaj_head_content'] = '';

add_action( 'wp_head', 'sahaj_content_in_head', 0 );
ob_start();
wp_head();
ob_end_clean();
remove_action( 'wp_head', 'sahaj_content_in_head', 0 );

sahaj_ok( 'nor does a request for the content inside <head>', false === strpos( (string) $GLOBALS['sahaj_head_content'], '<sahaj-atlas' ) );

// And the filter is global, so it must add nothing to any other page's content.
$sahaj_other_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Elsewhere',
		'post_name'   => 'elsewhere',
	)
);

$GLOBALS['wp_query'] = sahaj_query_page( $sahaj_other_page );
$GLOBALS['wp_query']->the_post();

sahaj_is( 'and no other page gets an element', '', sahaj_content_print() );

wp_reset_postdata();
wp_delete_post( $sahaj_other_page, true );
$GLOBALS['wp_query']            = $sahaj_print_query;
$GLOBALS['sahaj_atlas_printed'] = false;
$GLOBALS['sahaj_atlas_active']  = null;

// ---------------------------------------------------------------------------------------------

require __DIR__ . '/contract.php';
require __DIR__ . '/sitemap.php';
require __DIR__ . '/domains.php';
require __DIR__ . '/seo.php';

// ---------------------------------------------------------------------------------------------

require __DIR__ . '/probe.php';

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Nothing reached the network' );

/*
 * ⚠ Last, because it reports on every lane above. `tests/no-network.php` refuses an unstubbed
 * outbound request, and a refusal alone is silent — the plugin treats an unreachable endpoint as
 * a miss and carries on. This is the line that turns it into a failure instead. Until #28 the
 * suite called production SahajCloud with a fake key, and every call filed a Sentry event there.
 */
// ⚠ First, because an empty log proves nothing on its own. A blueprint that stopped writing the
// mu-plugin would leave the suite calling the real endpoint again, and reading a file nobody
// writes reports that as a pass.
sahaj_ok( 'the refusal is armed at all', false !== has_filter( 'pre_http_request', 'sahaj_atlas_test_refuse_http' ) );

$sahaj_escaped = defined( 'SAHAJ_ATLAS_NETWORK_LOG' ) && file_exists( SAHAJ_ATLAS_NETWORK_LOG )
	? trim( (string) file_get_contents( SAHAJ_ATLAS_NETWORK_LOG ) )
	: '';

sahaj_is( 'every request a lane made, that lane stubbed', '', $sahaj_escaped );

// ---------------------------------------------------------------------------------------------

echo "\n$sahaj_assertions assertion(s), $sahaj_failures failure(s)\n";
exit( $sahaj_failures > 0 ? 1 : 0 );
