<?php
/**
 * Tests the SEO takeover, on both routing shapes.
 *
 * The fetch itself is `wp_remote_get` and a transient, and is not covered here. Seeding the
 * transient exercises everything after it: the gate that decides whether this plugin owns the
 * page's metadata, the suppression of every other source, and the tags themselves.
 *
 * ⚠ Fixture pre-mortem. `$sahaj_seo_answer` assumes the endpoint's success body is an
 * `AtlasSeoResponse`: `type`, `id`, `route`, `locale`, `title`, `description`, `canonical`,
 * `alternates`, `openGraph`, `jsonLd`, `breadcrumbs`, `content`. Verified against
 * `src/endpoints/responseTypes.ts:149-188` in sydevs/SahajCloud, and the region content shape
 * against `AtlasSeoRegionContent` and `AtlasSeoEventCard` in the same file. A fixture invented from
 * this plugin's reader instead would only assert that the reader reads itself.
 *
 * @package SahajAtlas
 */

/**
 * Put the suite back where it found it, between cases.
 *
 * `sahaj_atlas_seo_boot()` is a one-way door by design — it hooks, and never unhooks. So a suite
 * that runs it more than once has to undo it, or the second case inherits the first one's verdict.
 */
function sahaj_seo_reset() {
	$GLOBALS['sahaj_atlas_seo'] = null;

	remove_action( 'wp_head', 'sahaj_atlas_seo_emit', 1 );
	remove_filter( 'pre_get_document_title', 'sahaj_atlas_seo_title' );
	remove_filter( 'sahaj_atlas_element_children', 'sahaj_atlas_seo_children' );
	remove_filter( 'wpseo_canonical', '__return_false' );
	remove_filter( 'aioseo_disable', '__return_true' );
	remove_filter( 'aioseo_disable_schema', '__return_true' );

	// Core's own emitters, at the priorities `wp-includes/default-filters.php` registers them with.
	add_action( 'wp_head', 'rel_canonical' );
	add_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 );

	unset( $_GET[ SAHAJ_ATLAS_QUERY_VAR ] );
	set_query_var( SAHAJ_ATLAS_ROUTE_VAR, '' );
}

/**
 * The `<head>` block this plugin emits for the current answer.
 *
 * @return string
 */
function sahaj_seo_head() {
	ob_start();

	sahaj_atlas_seo_emit();

	return (string) ob_get_clean();
}

update_option( 'permalink_structure', '/%postname%/' );
update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );
sahaj_mount_atlas_page_at( 'find-a-class' );

$sahaj_seo_route     = '/nl/amsterdam';
$sahaj_seo_canonical = home_url( '/find-a-class/' ) . '?atlas=' . $sahaj_seo_route;

$sahaj_seo_answer = array(
	'type'        => 'region',
	'id'          => 42,
	'route'       => $sahaj_seo_route,
	'locale'      => 'en',
	'title'       => 'Free meditation classes in Amsterdam',
	'description' => 'Weekly Sahaja Yoga meditation classes in Amsterdam, free to attend.',
	'canonical'   => $sahaj_seo_canonical,
	'alternates'  => array(
		array( 'hreflang' => 'en', 'href' => $sahaj_seo_canonical ),
		array( 'hreflang' => 'x-default', 'href' => $sahaj_seo_canonical ),
	),
	'openGraph'   => array(
		'og:title'     => 'Free meditation classes in Amsterdam',
		'twitter:card' => 'summary',
	),
	'jsonLd'      => '{"@context":"https://schema.org","@type":"Place","name":"Amsterdam"}',
	'breadcrumbs' => array(
		array( 'name' => 'Netherlands', 'route' => '/nl', 'url' => home_url( '/find-a-class/' ) . '?atlas=/nl' ),
	),
	'content'     => array(
		'name'       => 'Amsterdam',
		'subtitle'   => 'Netherlands',
		'level'      => 'city',
		'eventCount' => 1,
		'events'     => array(
			array(
				'id'       => 1204,
				'route'    => '/nl/amsterdam/1204',
				'url'      => home_url( '/find-a-class/' ) . '?atlas=/nl/amsterdam/1204',
				'title'    => 'Tuesday evening class',
				'schedule' => 'Every week on Tuesday at 7:00 PM',
				'address'  => 'Keizersgracht 1, Amsterdam',
				'online'   => false,
			),
		),
	),
);

// An ordinary page, queried the same way a visitor's request would be. `?atlas=` on it is the case
// that must change nothing at all.
$sahaj_seo_other_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'About us',
		'post_name'   => 'about-us-seo',
	)
);

$sahaj_seo_page_query  = new WP_Query( array( 'page_id' => sahaj_atlas_page_id() ) );
$sahaj_seo_other_query = new WP_Query( array( 'page_id' => $sahaj_seo_other_page ) );

$GLOBALS['wp_query'] = $sahaj_seo_page_query;

// The transient is seeded through the plugin's own key builder, not a copy of it. A hand-written
// key that drifts would make every assertion below fetch over the network and fail as a timeout,
// which reads as a broken endpoint rather than a broken fixture.
set_transient( sahaj_atlas_seo_slot( $sahaj_seo_route ), $sahaj_seo_answer, MINUTE_IN_SECONDS );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A query-routed deep link is an atlas route' );

sahaj_seo_reset();

sahaj_set_query_route( $sahaj_seo_route );

sahaj_is( 'the route variable answers for `?atlas=` too', $sahaj_seo_route, sahaj_atlas_current_route() );
sahaj_is( 'though the path router claimed nothing', '', sahaj_atlas_path_route() );

/*
 * ⚠ The canonical redirect stays live here, and that is the point of keying it on the path route
 * alone. A query-routed URL is the page's own permalink plus a parameter, so there is nothing for
 * `redirect_canonical()` to strip. Returning `false` would switch a core behaviour off for every
 * ordinary page load that happens to carry `?atlas=`.
 */
sahaj_is( 'and the canonical redirect is left alone', 'https://example.com/x', sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

sahaj_atlas_seo_boot();

sahaj_ok( 'the takeover runs', is_array( $GLOBALS['sahaj_atlas_seo'] ) );
sahaj_is( 'and carries the route\'s own title', 'Free meditation classes in Amsterdam', sahaj_atlas_seo_title( 'Find a class — Example' ) );

// ⚠ Two canonicals is worse than none. The page must carry ours, and nobody else's.
sahaj_ok( 'core\'s rel_canonical is silenced', false === has_action( 'wp_head', 'rel_canonical' ) );
sahaj_ok( 'and its shortlink with it', false === has_action( 'wp_head', 'wp_shortlink_wp_head' ) );
sahaj_ok( 'and Yoast is told to emit none', false !== has_filter( 'wpseo_canonical', '__return_false' ) );

$sahaj_seo_head = sahaj_seo_head();

sahaj_is( 'exactly one canonical is emitted', 1, substr_count( $sahaj_seo_head, '<link rel="canonical"' ) );
sahaj_ok( 'and it is the one SahajCloud published', false !== strpos( $sahaj_seo_head, 'href="' . esc_url( $sahaj_seo_canonical ) . '"' ), $sahaj_seo_head );
sahaj_ok( 'the description is the route\'s own', false !== strpos( $sahaj_seo_head, 'Weekly Sahaja Yoga meditation classes in Amsterdam' ) );
sahaj_ok( 'hreflang rows are emitted', 2 === substr_count( $sahaj_seo_head, '<link rel="alternate" hreflang=' ) );
sahaj_ok( 'og:* uses property, twitter:* uses name', false !== strpos( $sahaj_seo_head, 'property="og:title"' ) && false !== strpos( $sahaj_seo_head, 'name="twitter:card"' ) );

// ⚠ `jsonLd` arrives pre-escaped and is echoed raw. Re-encoding it would double-escape it into
// structured data no crawler can read.
sahaj_ok( 'the JSON-LD block is emitted verbatim', false !== strpos( $sahaj_seo_head, '{"@context":"https://schema.org","@type":"Place","name":"Amsterdam"}' ) );

$sahaj_seo_children = apply_filters( 'sahaj_atlas_element_children', '' );

sahaj_ok( 'the crawlable body names the region', false !== strpos( $sahaj_seo_children, '<h1>Amsterdam</h1>' ), $sahaj_seo_children );
sahaj_ok( 'and lists its classes', false !== strpos( $sahaj_seo_children, 'Tuesday evening class' ), $sahaj_seo_children );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Path routing is unchanged by any of this' );

sahaj_seo_reset();
set_query_var( SAHAJ_ATLAS_ROUTE_VAR, $sahaj_seo_route );

sahaj_is( 'the path route is still the route', $sahaj_seo_route, sahaj_atlas_current_route() );
sahaj_is( 'the canonical redirect is still suppressed', false, sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

// ⚠ The path route wins over a parameter, rather than being overridden by one. A crafted `?atlas=`
// on a path-routed deep link must not repoint the page's canonical.
sahaj_set_query_route( '/gb/london' );

sahaj_is( 'and a parameter cannot override it', $sahaj_seo_route, sahaj_atlas_current_route() );

unset( $_GET[ SAHAJ_ATLAS_QUERY_VAR ] );

sahaj_atlas_seo_boot();

sahaj_ok( 'the takeover still runs on a path-routed link', is_array( $GLOBALS['sahaj_atlas_seo'] ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The atlas root stays the host\'s own page' );

foreach ( array( '/', '' ) as $sahaj_root ) {
	sahaj_seo_reset();

	sahaj_set_query_route( $sahaj_root );

	sahaj_atlas_seo_boot();

	$label = '' === $sahaj_root ? 'an empty parameter' : 'the root route';

	sahaj_ok( "$label triggers no takeover", null === $GLOBALS['sahaj_atlas_seo'] );
	sahaj_ok( "and $label suppresses nothing", false !== has_action( 'wp_head', 'rel_canonical' ) );
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A value the sanitiser refuses is not a route' );

// The same shapes `sahaj_atlas_clean_route()` refuses in a shortcode attribute. Here they arrive
// from a URL anyone can compose, which is the sharper version of the same problem.
foreach ( array( '//evil.com', '/\\evil.com', "/\t/evil.com", 'https://evil.com', 'gb/london' ) as $sahaj_hostile ) {
	sahaj_seo_reset();

	sahaj_set_query_route( $sahaj_hostile );

	sahaj_is( 'refuses ' . str_replace( "\t", '\t', $sahaj_hostile ), '', sahaj_atlas_current_route() );

	sahaj_atlas_seo_boot();

	sahaj_ok( 'and leaves the host\'s own metadata in place', null === $GLOBALS['sahaj_atlas_seo'] && false !== has_action( 'wp_head', 'rel_canonical' ) );
}

// An array value. `?atlas[]=/nl/amsterdam` makes `$_GET['atlas']` an array, and a bare string cast
// on one is a PHP notice plus the literal word `Array`.
sahaj_seo_reset();

sahaj_set_query_route( array( $sahaj_seo_route ) );

sahaj_is( 'refuses an array parameter', '', sahaj_atlas_current_route() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Another page is unaffected by ?atlas=' );

sahaj_seo_reset();

$GLOBALS['wp_query'] = $sahaj_seo_other_query;

sahaj_set_query_route( $sahaj_seo_route );

sahaj_is( 'no route is claimed off the Atlas page', '', sahaj_atlas_current_route() );

sahaj_atlas_seo_boot();

sahaj_ok( 'so no takeover, and no suppression', null === $GLOBALS['sahaj_atlas_seo'] && false !== has_action( 'wp_head', 'rel_canonical' ) );
sahaj_is( 'and its own canonical redirect still runs', 'https://example.com/x', sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

sahaj_seo_reset();
delete_transient( sahaj_atlas_seo_slot( $sahaj_seo_route ) );
wp_delete_post( $sahaj_seo_other_page, true );

$GLOBALS['wp_query'] = $sahaj_seo_page_query;
