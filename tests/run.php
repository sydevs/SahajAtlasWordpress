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
sahaj_is( 'the Atlas page element is sized by the stylesheet, not inline', '<sahaj-atlas></sahaj-atlas>', sahaj_atlas_page_element_markup() );

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

require __DIR__ . '/contract.php';
require __DIR__ . '/sitemap.php';
require __DIR__ . '/domains.php';
require __DIR__ . '/seo.php';

// ---------------------------------------------------------------------------------------------

echo "\n$sahaj_assertions assertion(s), $sahaj_failures failure(s)\n";
exit( $sahaj_failures > 0 ? 1 : 0 );
