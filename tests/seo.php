<?php
/**
 * Tests the SEO takeover: both routing shapes, both sides of the page-description opt-out, and
 * the order the fetch and the suppression happen in.
 *
 * Two ways in, because the file asserts two different things. Seeding the transient exercises
 * everything after the fetch: the gate that decides whether this plugin owns the page's metadata,
 * the suppression of every other source, and the tags themselves. Stubbing `pre_http_request`
 * exercises the fetch itself — which route is asked about, and what happens when nothing answers.
 * Trap 16 lives in that second half: suppression happens only after a successful fetch, and every
 * way of getting it wrong looks identical from outside, as a page with no description either way.
 *
 * Both answers come from `tests/fixtures/seo-answer.php`, the one copy both render blueprints seed
 * from too. Its own docblock carries the fixture pre-mortem.
 *
 * @package SahajAtlas
 */

/**
 * Put the suite back where it found it, between cases.
 *
 * `sahaj_atlas_seo_boot()` is a one-way door by design — it hooks, and never unhooks. So a suite
 * that runs it more than once has to undo it, or the second case inherits the first one's verdict.
 * Only the state an assertion below reads is restored.
 */
function sahaj_seo_reset() {
	$GLOBALS['sahaj_atlas_seo'] = null;

	remove_action( 'wp_head', 'sahaj_atlas_seo_emit', 1 );
	remove_filter( 'pre_get_document_title', 'sahaj_atlas_seo_title' );
	remove_filter( 'sahaj_atlas_element_children', 'sahaj_atlas_seo_children' );
	remove_filter( 'wpseo_canonical', '__return_false' );

	// AIOSEO's own switches. An assertion below reads them to tell "this plugin took the page
	// over" from "it left the host's SEO plugin alone", so a case that inherited them from the
	// previous one would look suppressed whatever it did.
	remove_filter( 'aioseo_disable', '__return_true' );
	remove_filter( 'aioseo_disable_schema', '__return_true' );

	// Core's own emitters, at the priorities `wp-includes/default-filters.php` registers them with.
	add_action( 'wp_head', 'rel_canonical' );
	add_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 );

	sahaj_clear_query_route();
	set_query_var( SAHAJ_ATLAS_ROUTE_VAR, '' );
}

/** @return bool Did the last boot silence the host's SEO plugin? */
function sahaj_seo_suppressed() {
	return false !== has_filter( 'aioseo_disable', '__return_true' );
}

/** @return string A Dutch locale, for the slot-keying assertion below. */
function sahaj_seo_dutch() {
	return 'nl_NL';
}

/** @return string The head block the takeover would print. */
function sahaj_seo_head() {
	ob_start();
	sahaj_atlas_seo_emit();

	return (string) ob_get_clean();
}

/**
 * Assert that this request changed nothing: no takeover, and nobody silenced.
 *
 * @param string $label What was tried.
 */
function sahaj_seo_untouched( $label ) {
	sahaj_ok(
		$label,
		null === $GLOBALS['sahaj_atlas_seo'] && false !== has_action( 'wp_head', 'rel_canonical' )
	);
}

update_option( 'permalink_structure', '/%postname%/' );
update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );
sahaj_mount_atlas_page_at( 'find-a-class' );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

$sahaj_seo_fixture   = require __DIR__ . '/fixtures/seo-answer.php';
$sahaj_seo_route     = $sahaj_seo_fixture['route'];
$sahaj_seo_canonical = $sahaj_seo_fixture['canonical'];
$sahaj_seo_root      = $sahaj_seo_fixture['root'];

// An ordinary page, queried the way a visitor's request would be. `?atlas=` on it must change
// nothing at all.
$sahaj_seo_other_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'About us',
		'post_name'   => 'about-us-seo',
	)
);

$sahaj_seo_page_query  = sahaj_query_page();
$sahaj_seo_other_query = sahaj_query_page( $sahaj_seo_other_page );

$GLOBALS['wp_query'] = $sahaj_seo_page_query;

// The transient is seeded through the plugin's own key builder, not a copy of it. A hand-written
// key that drifts would make every assertion below fetch over the network and fail as a timeout,
// which reads as a broken endpoint rather than a broken fixture.
set_transient( sahaj_atlas_seo_slot( $sahaj_seo_route ), $sahaj_seo_fixture['answer'], MINUTE_IN_SECONDS );

// ⚠ The root answer is seeded under the bare view route as well as under `/`. The endpoint answers
// both with the same document, but the plugin asks about the route it was given and caches under
// it — so a fixture seeded under `/` alone would make the bare view route fetch over the network
// and fail as a timeout, which reads as a broken endpoint rather than a missing seed.
foreach ( array( $sahaj_seo_fixture['rootRoute'], '/search' ) as $sahaj_seo_root_route ) {
	set_transient( sahaj_atlas_seo_slot( $sahaj_seo_root_route ), $sahaj_seo_root, MINUTE_IN_SECONDS );
}

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A query-routed deep link is an atlas route' );

sahaj_seo_reset();
sahaj_set_query_route( $sahaj_seo_route );

sahaj_is( 'the route variable answers for `?atlas=` too', $sahaj_seo_route, sahaj_atlas_current_route() );
sahaj_is( 'though the path router claimed nothing', '', sahaj_atlas_path_route() );

/*
 * ⚠ The canonical redirect stays live here, and that is the point of keying it on the path route
 * alone. The contract publishes `/?p=42&atlas=…` mounts, and core's 301 to the pretty permalink is
 * what carries a query-routed visitor to the canonical URL, `?atlas=` intact. Returning `false`
 * would strand them on the mount. It would not disable the redirect on ordinary pages — the group
 * below pins that, since `?atlas=` is refused off the Atlas page.
 */
sahaj_is( 'and the canonical redirect is left alone', 'https://example.com/x', sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

sahaj_atlas_seo_boot();

sahaj_ok( 'the takeover runs', is_array( $GLOBALS['sahaj_atlas_seo'] ) );
sahaj_is( 'and carries the route\'s own title', 'Free meditation classes in Amsterdam', sahaj_atlas_seo_title( 'Find a class — Example' ) );

// ⚠ Two canonicals is worse than none. The page must carry ours, and nobody else's.
sahaj_ok( 'core\'s rel_canonical is silenced', false === has_action( 'wp_head', 'rel_canonical' ) );
sahaj_ok( 'and its shortlink with it', false === has_action( 'wp_head', 'wp_shortlink_wp_head' ) );
sahaj_ok( 'and Yoast is told to emit none', false !== has_filter( 'wpseo_canonical', '__return_false' ) );

ob_start();
sahaj_atlas_seo_emit();
$sahaj_seo_head = (string) ob_get_clean();

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

sahaj_clear_query_route();
sahaj_atlas_seo_boot();

sahaj_ok( 'the takeover still runs on a path-routed link', is_array( $GLOBALS['sahaj_atlas_seo'] ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The atlas root is described too, and by the same route' );

/*
 * ⚠ This inverts what this suite asserted before #16. The root used to be excluded here, because
 * the endpoint 404d it. SahajCloud#739 answers it, so the page a host links from its own nav now
 * carries metadata of its own — and the page itself, the root route and an empty parameter are all
 * the same view, so all three resolve to `/`.
 */
foreach ( array( '/' => 'the root route', '' => 'an empty parameter' ) as $sahaj_root => $sahaj_label ) {
	sahaj_seo_reset();
	sahaj_set_query_route( $sahaj_root );

	sahaj_atlas_seo_boot();

	sahaj_ok( "$sahaj_label is described by the atlas", is_array( $GLOBALS['sahaj_atlas_seo'] ) );
	sahaj_is( "and $sahaj_label carries the root's own title", 'Free meditation classes near you', sahaj_atlas_seo_title( 'Find a class — Example' ) );
}

// The page itself, with no parameter at all. This is the request every visitor who clicks the
// host's own nav link makes, and the one the settings-screen button creates a page for.
sahaj_seo_reset();
sahaj_atlas_seo_boot();

sahaj_ok( 'the page itself is described as well', is_array( $GLOBALS['sahaj_atlas_seo'] ) );
sahaj_ok( 'and core\'s own canonical is silenced for it', false === has_action( 'wp_head', 'rel_canonical' ) );

$sahaj_seo_head = sahaj_seo_head();

sahaj_ok( 'the description is the root\'s own', false !== strpos( $sahaj_seo_head, 'Find a free meditation class near you.' ) );
sahaj_ok( 'the canonical is the one SahajCloud published for the mount', false !== strpos( $sahaj_seo_head, 'href="' . esc_url( $sahaj_seo_fixture['rootCanonical'] ) . '"' ) );
sahaj_is( 'one hreflang row per alternate', 2, substr_count( $sahaj_seo_head, 'rel="alternate" hreflang=' ) );
sahaj_ok( 'the Open Graph tags are emitted', false !== strpos( $sahaj_seo_head, 'property="og:title"' ) );

$sahaj_seo_children = sahaj_atlas_element_children();

// ⚠ The root names no document, so it has no name of its own to render. Its heading is the page
// title the operator wrote, which lives at the top level of the answer beside every other route's.
sahaj_ok( 'the crawlable body carries the page title as its heading', false !== strpos( $sahaj_seo_children, '<h1>Free meditation classes near you</h1>' ) );
sahaj_is( 'and one paragraph per block the operator wrote', 1, substr_count( $sahaj_seo_children, '<p>' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A bare view route is a view of the root' );

/*
 * ⚠ `/search`, `/calendar`, `/filters`, `/online` and `/share` answer with the root document, and
 * the plugin learns that from `type` in the answer rather than from a copy of the endpoint's own
 * segment list. A copy here drifts the day a view is added upstream.
 */
sahaj_seo_reset();
sahaj_set_query_route( '/search' );

sahaj_atlas_seo_boot();

sahaj_is( 'it carries the root\'s metadata', 'Free meditation classes near you', sahaj_atlas_seo_title( 'Find a class — Example' ) );
sahaj_ok( 'and takes the page over', sahaj_seo_suppressed() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The opt-out hands the Atlas page back' );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '1' );

foreach ( array( '' => 'the page itself', '/search' => 'a bare view route' ) as $sahaj_root => $sahaj_label ) {
	sahaj_seo_reset();

	if ( '' !== $sahaj_root ) {
		sahaj_set_query_route( $sahaj_root );
	}

	sahaj_atlas_seo_boot();
	sahaj_seo_untouched( "$sahaj_label is left to the host's own SEO plugin" );

	sahaj_is( "and $sahaj_label emits nothing of ours", '', sahaj_seo_head() );
	sahaj_is( "and $sahaj_label keeps the theme's title", 'Find a class — Example', sahaj_atlas_seo_title( 'Find a class — Example' ) );
	sahaj_is( "and $sahaj_label keeps the element's own children", '', sahaj_atlas_element_children() );
}

// The opt-out is about the one page a host's SEO plugin has ever seen. `/nl/amsterdam` is an
// address it has never heard of, and would describe as the Atlas page — so there is nothing to
// hand back there.
sahaj_seo_reset();
sahaj_set_query_route( $sahaj_seo_route );

sahaj_atlas_seo_boot();

sahaj_is( 'a region route is described whatever the checkbox says', 'Free meditation classes in Amsterdam', sahaj_atlas_seo_title( 'Find a class — Example' ) );
sahaj_ok( 'and still taken over', sahaj_seo_suppressed() );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A value the sanitiser refuses is not a route' );

// The same shapes `sahaj_atlas_clean_route()` refuses in a shortcode attribute. Here they arrive
// from a URL anyone can compose, which is the sharper version of the same problem.
foreach ( array( '//evil.com', '/\\evil.com', "/\t/evil.com", 'https://evil.com', 'gb/london' ) as $sahaj_hostile ) {
	sahaj_seo_reset();
	sahaj_set_query_route( $sahaj_hostile );

	$sahaj_label = str_replace( "\t", '\t', $sahaj_hostile );

	sahaj_is( "refuses $sahaj_label", '', sahaj_atlas_current_route() );

	sahaj_atlas_seo_boot();

	/*
	 * ⚠ A refused parameter falls back to the root view, because that is what the page is — and
	 * the assertion that matters is what it does *not* do. The crafted value must never reach the
	 * endpoint or the canonical: a page claiming `//evil.com` as its own canonical is the whole
	 * point of refusing the value in the first place.
	 */
	sahaj_is( "and $sahaj_label is described as the root instead", 'Free meditation classes near you', sahaj_atlas_seo_title( 'Find a class — Example' ) );
	sahaj_ok( "and $sahaj_label never reaches the canonical", false === strpos( sahaj_seo_head(), 'evil.com' ) );
}

// An array value. `?atlas[]=/nl/amsterdam` makes the parameter an array, and a bare string cast on
// one is a PHP notice plus the literal word `Array`.
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
sahaj_seo_untouched( 'so no takeover, and no suppression' );

sahaj_is( 'and its own canonical redirect still runs', 'https://example.com/x', sahaj_atlas_suppress_canonical_redirect( 'https://example.com/x' ) );

sahaj_seo_reset();
delete_transient( sahaj_atlas_seo_slot( $sahaj_seo_route ) );
wp_delete_post( $sahaj_seo_other_page, true );

$GLOBALS['wp_query'] = $sahaj_seo_page_query;

// ---------------------------------------------------------------------------------------------
// Everything below asserts the fetch itself, so the transient is out of the way and
// `pre_http_request` answers instead.

/** Every URL the stub was asked for since the last `sahaj_seo_stub()`. */
$GLOBALS['sahaj_seo_requests'] = array();
/** What the stub answers with: `array{status:int, body:mixed}`. */
$GLOBALS['sahaj_seo_answer'] = array( 'status' => 404, 'body' => array( 'errors' => array() ) );

/**
 * Stand in for `GET /api/atlas/seo`.
 *
 * @param false|array $pre  Short-circuit value.
 * @param array       $args Request arguments.
 * @param string      $url  The URL requested.
 * @return array A `wp_remote_get` response.
 */
function sahaj_seo_http_stub( $pre, $args, $url ) {
	$GLOBALS['sahaj_seo_requests'][] = $url;

	$answer = $GLOBALS['sahaj_seo_answer'];

	return array(
		'headers'  => array(),
		'body'     => is_string( $answer['body'] ) ? $answer['body'] : wp_json_encode( $answer['body'] ),
		'response' => array( 'code' => $answer['status'], 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Arm the stub, forget every request made before now, and drop every cached answer.
 *
 * @param mixed $body   The body to answer with.
 * @param int   $status The status to answer with.
 */
function sahaj_seo_stub( $body, $status = 200 ) {
	$GLOBALS['sahaj_seo_requests'] = array();
	$GLOBALS['sahaj_seo_answer']   = array( 'status' => $status, 'body' => $body );

	foreach ( array( '/', '/search', '/nl/amsterdam' ) as $slot ) {
		delete_transient( sahaj_atlas_seo_slot( $slot ) );
	}
}

add_filter( 'pre_http_request', 'sahaj_seo_http_stub', 10, 3 );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The route the page itself asks about' );

sahaj_seo_reset();
sahaj_seo_stub( $sahaj_seo_root );
sahaj_atlas_seo_boot();

sahaj_is( 'the page itself asks once', 1, count( $GLOBALS['sahaj_seo_requests'] ) );

// ⚠ `route=` with nothing after it is a 400 from the endpoint's own query schema, not a 404. The
// page itself has no route of its own, so this is the one place the plugin supplies one.
sahaj_ok(
	'and asks as `/`, never as the empty route the endpoint refuses',
	(bool) preg_match( '#[?&]route=/(&|$)#', (string) $GLOBALS['sahaj_seo_requests'][0] )
);

sahaj_seo_reset();
sahaj_seo_stub( $sahaj_seo_root );
sahaj_set_query_route( '/search' );
sahaj_atlas_seo_boot();

sahaj_ok(
	'a bare view route is sent verbatim, never normalized here',
	(bool) preg_match( '#[?&]route=/search(&|$)#', (string) $GLOBALS['sahaj_seo_requests'][0] )
);

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The opt-out costs no round trip' );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '1' );

sahaj_seo_reset();
sahaj_seo_stub( $sahaj_seo_root );
sahaj_atlas_seo_boot();

// The Atlas page is the busiest page this plugin touches. A host that already said "leave my
// description alone" must not pay an upstream round trip on it to be told so again.
sahaj_is( 'nothing is fetched for a page the host describes', array(), $GLOBALS['sahaj_seo_requests'] );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A failed fetch changes nothing at all (trap 16)' );

sahaj_seo_reset();
sahaj_seo_stub( array( 'errors' => array( array( 'message' => 'Not found' ) ) ), 404 );
sahaj_atlas_seo_boot();

/*
 * ⚠ Suppressing first and fetching second leaves the page with no metadata at all — strictly worse
 * than the generic metadata it replaces, and invisible to everyone but a crawler. An emission
 * assertion alone passes against that defect. This pair is what catches it.
 */
sahaj_ok( 'the host\'s SEO plugin is not silenced', ! sahaj_seo_suppressed() );
sahaj_ok( 'core\'s own canonical still runs', false !== has_action( 'wp_head', 'rel_canonical' ) );
sahaj_is( 'and nothing of ours is emitted', '', sahaj_seo_head() );
sahaj_is( 'and the theme keeps its title', 'Find a class — Example', sahaj_atlas_seo_title( 'Find a class — Example' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'What the root\'s crawlable body renders' );

// A locale nobody has written copy for answers `description: null` and no paragraphs, which is the
// documented answer rather than a failure. The title is always there.
sahaj_seo_reset();
sahaj_seo_stub(
	array_merge( $sahaj_seo_root, array( 'description' => null, 'content' => array( 'paragraphs' => array() ) ) )
);
sahaj_atlas_seo_boot();

$sahaj_seo_bare = sahaj_atlas_element_children();

sahaj_ok( 'a description-less root still renders its heading', false !== strpos( $sahaj_seo_bare, '<h1>Free meditation classes near you</h1>' ) );
sahaj_is( 'and no empty paragraph', 0, substr_count( $sahaj_seo_bare, '<p>' ) );
sahaj_is( 'and emits no empty description tag', 0, substr_count( sahaj_seo_head(), 'name="description"' ) );

// ⚠ `jsonLd` arrives escaped for a `<script>` element nothing downstream sanitizes — `<` on the
// wire is `<`. Re-encoding it here double-escapes it into structured data no crawler reads,
// and `esc_html` turns it into `&quot;`-laden text. Both still render a script tag, so only the
// payload shows the failure.
$sahaj_seo_escaped = '{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Atlas </script>"}]}';

sahaj_seo_reset();
sahaj_seo_stub( array_merge( $sahaj_seo_root, array( 'jsonLd' => $sahaj_seo_escaped ) ) );
sahaj_atlas_seo_boot();

$sahaj_seo_head = sahaj_seo_head();

sahaj_ok( 'the JSON-LD is echoed exactly as it arrived', false !== strpos( $sahaj_seo_head, '<script type="application/ld+json">' . $sahaj_seo_escaped . '</script>' ) );
sahaj_ok( 'and never re-escaped', false === strpos( $sahaj_seo_head, '&quot;' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The cache is keyed by what the answer depends on' );

sahaj_ok( 'a different route gets a different slot', sahaj_atlas_seo_slot( '/' ) !== sahaj_atlas_seo_slot( '/search' ) );

$sahaj_seo_slot_before = sahaj_atlas_seo_slot( '/' );

add_filter( 'determine_locale', 'sahaj_seo_dutch' );

// ⚠ The root's copy is written per locale upstream. A slot that ignored the locale would serve a
// Dutch visitor the English description the first English visitor cached.
sahaj_ok( 'and a different locale too', $sahaj_seo_slot_before !== sahaj_atlas_seo_slot( '/' ) );

remove_filter( 'determine_locale', 'sahaj_seo_dutch' );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'another-key-456' );

// ⚠ Two clients on one server answer differently for the same route. A slot that ignored the key
// would serve one site's atlas description on another's page.
sahaj_ok( 'and a different key, so one client never reads another\'s answer', $sahaj_seo_slot_before !== sahaj_atlas_seo_slot( '/' ) );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The checkbox stores one of two values, whatever it is handed' );

sahaj_is( 'a ticked box stores 1', '1', sahaj_atlas_sanitize_checkbox( '1' ) );
sahaj_is( 'the hidden field stores nothing', '', sahaj_atlas_sanitize_checkbox( '0' ) );

// ⚠ `options.php` hands the callback `null` when a field posts nothing at all. An unticked box
// posts nothing, so this is the value that arrives whenever the hidden field is missing.
sahaj_is( 'and so does an absent field', '', sahaj_atlas_sanitize_checkbox( null ) );
sahaj_is( 'and anything else', '', sahaj_atlas_sanitize_checkbox( 'yes please' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The status panel names the side that owns the page description' );

sahaj_seo_stub( $sahaj_seo_root );

$sahaj_seo_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

sahaj_is( 'ours, when we supply it', 'ok', $sahaj_seo_check['status'] );
sahaj_ok( 'and it shows the title a visitor would see', false !== strpos( $sahaj_seo_check['detail'], 'Free meditation classes near you' ) );

sahaj_seo_stub( array( 'errors' => array() ), 404 );

$sahaj_seo_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

// ⚠ A warning, not a failure. Nothing is broken: the host's own metadata is still there, because
// suppression never happened. A red row here would send a volunteer to us about a working site.
sahaj_is( 'a silent endpoint is a warning, not a failure', 'warn', $sahaj_seo_check['status'] );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '1' );

$sahaj_seo_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

sahaj_is( 'theirs, when they asked for it', 'idle', $sahaj_seo_check['status'] );
sahaj_ok( 'and it says so in words', false !== strpos( $sahaj_seo_check['detail'], 'SEO plugin' ) );

// A key that does not work yet is not a verdict about the description either way.
update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

$sahaj_seo_check = sahaj_atlas_check_page_description( new WP_Error( 'http', 'unreachable' ) );

sahaj_is( 'and nothing at all, until the key works', 'idle', $sahaj_seo_check['status'] );

// ---------------------------------------------------------------------------------------------

sahaj_seo_reset();
sahaj_seo_stub( array( 'errors' => array() ), 404 );
remove_filter( 'pre_http_request', 'sahaj_seo_http_stub', 10 );
