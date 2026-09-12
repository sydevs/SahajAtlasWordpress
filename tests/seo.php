<?php
/**
 * Tests the SEO takeover: which routes it claims, which side owns the Atlas page, and the order
 * the two things happen in.
 *
 * ⚠ Ordering is the whole point of this file. Trap 16 says suppression happens only after a
 * successful fetch, and every way of getting that wrong looks identical from the outside: a page
 * with no description either way. The assertions below therefore test the *suppression* as well as
 * the emission, on the failing paths as much as the working one.
 *
 * The endpoint is stubbed through `pre_http_request`. The fixture is an `AtlasSeoResponse` of
 * `type: 'root'`, verified against `src/endpoints/responseTypes.ts` and `buildRootSeo()` in
 * `src/endpoints/atlas/seo/seoDocument.ts` in sydevs/SahajCloud.
 *
 * @package SahajAtlas
 */

/** Every URL the stub was asked for since the last `sahaj_seo_stub()`. */
$GLOBALS['sahaj_seo_requests'] = array();
/** What the stub answers with: `array{status:int, body:mixed}`. */
$GLOBALS['sahaj_seo_answer'] = array( 'status' => 404, 'body' => array( 'errors' => array() ) );

/**
 * Stand in for `GET /api/atlas/seo`. Registered once, below.
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

add_filter( 'pre_http_request', 'sahaj_seo_http_stub', 10, 3 );

/**
 * Arm the stub, and forget every request made before now.
 *
 * @param mixed $body   The body to answer with.
 * @param int   $status The status to answer with.
 */
function sahaj_seo_stub( $body, $status = 200 ) {
	$GLOBALS['sahaj_seo_requests'] = array();
	$GLOBALS['sahaj_seo_answer']   = array( 'status' => $status, 'body' => $body );
}

/**
 * Put the request on the Atlas page, at one atlas route, and undo everything a previous boot did.
 *
 * ⚠ The undo half matters more than the setup half. `sahaj_atlas_seo_suppress_others()` adds
 * filters that never come off on a real request, because a real request ends. Leaving them on
 * between cases would make every later case look suppressed, including the ones asserting that
 * nothing was.
 *
 * @param string $route The atlas route, or `''` for the page itself.
 */
function sahaj_seo_request_for( $route ) {
	$GLOBALS['sahaj_atlas_seo'] = null;

	remove_action( 'wp_head', 'sahaj_atlas_seo_emit', 1 );
	remove_filter( 'pre_get_document_title', 'sahaj_atlas_seo_title' );
	remove_filter( 'sahaj_atlas_element_children', 'sahaj_atlas_seo_children' );
	remove_filter( 'aioseo_disable', '__return_true' );
	remove_filter( 'aioseo_disable_schema', '__return_true' );

	foreach ( array( '', '/', '/search', '/gb/london' ) as $known ) {
		delete_transient( sahaj_atlas_seo_cache_key( '' === $known ? '/' : $known ) );
	}

	$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => sahaj_atlas_page_id() ) );
	$GLOBALS['wp_query']->the_post();

	set_query_var( SAHAJ_ATLAS_ROUTE_VAR, $route );
}

/** @return bool Did the last boot silence the host's SEO plugin? */
function sahaj_seo_suppressed() {
	return false !== has_filter( 'aioseo_disable', '__return_true' );
}

/** @return string The head block the takeover would print. */
function sahaj_seo_head() {
	ob_start();
	sahaj_atlas_seo_emit();

	return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------------------------

$sahaj_seo_root = array(
	'type'        => 'root',
	'id'          => null,
	'route'       => '/',
	'locale'      => 'en',
	'title'       => 'Free meditation classes near you',
	'description' => 'Find a free Sahaja Yoga meditation class near you, anywhere in the world.',
	'canonical'   => 'https://example.org/find-a-class',
	'alternates'  => array(
		array( 'hreflang' => 'en', 'href' => 'https://example.org/find-a-class?locale=en' ),
		array( 'hreflang' => 'nl', 'href' => 'https://example.org/find-a-class?locale=nl' ),
		array( 'hreflang' => 'x-default', 'href' => 'https://example.org/find-a-class' ),
	),
	'openGraph'   => array(
		'og:title' => 'Free meditation classes near you',
		'og:type'  => 'website',
		'og:url'   => 'https://example.org/find-a-class',
	),
	// ⚠ Pre-escaped by the producer, for a `<script>` element nothing downstream sanitizes. The
	// `<` sequences below are what a `</script>` inside a name looks like on the wire.
	'jsonLd'      => '{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"Atlas </script>"}]}',
	'breadcrumbs' => array(),
	'content'     => array(
		'paragraphs' => array(
			'Sahaja Yoga meditation classes are always free.',
			'Every class is run by volunteers.',
		),
	),
);

$sahaj_seo_region = array(
	'type'        => 'region',
	'id'          => 42,
	'route'       => '/gb/london',
	'locale'      => 'en',
	'title'       => 'London',
	'description' => null,
	'canonical'   => 'https://example.org/find-a-class/gb/london',
	'alternates'  => array(),
	'openGraph'   => array( 'og:title' => 'London' ),
	'jsonLd'      => '{"@context":"https://schema.org","@graph":[]}',
	'breadcrumbs' => array(),
	'content'     => array( 'name' => 'London', 'subtitle' => '3 classes', 'events' => array() ),
);

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );
update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The root view is described, by default' );

sahaj_seo_stub( $sahaj_seo_root );
sahaj_seo_request_for( '' );
sahaj_atlas_seo_boot();

sahaj_is( 'the page itself is asked about as the root route', 1, count( $GLOBALS['sahaj_seo_requests'] ) );
// ⚠ `route=` with nothing after it is a 400 from the endpoint's own query schema, not a 404. The
// page itself has no route of its own, so this is the one place the plugin supplies one.
sahaj_ok(
	'and asked about as `/`, never as an empty route the endpoint would refuse',
	(bool) preg_match( '#[?&]route=/(&|$)#', (string) $GLOBALS['sahaj_seo_requests'][0] )
);

$sahaj_head = sahaj_seo_head();

sahaj_is( 'the title comes from the answer', 'Free meditation classes near you', sahaj_atlas_seo_title( 'Some theme title' ) );
sahaj_ok( 'the description is emitted', false !== strpos( $sahaj_head, 'Find a free Sahaja Yoga meditation class' ) );
sahaj_ok( 'the canonical is emitted', false !== strpos( $sahaj_head, '<link rel="canonical" href="https://example.org/find-a-class"' ) );
sahaj_is( 'one hreflang row per alternate', 3, substr_count( $sahaj_head, 'rel="alternate"' ) );
sahaj_ok( 'the Open Graph tags are emitted', false !== strpos( $sahaj_head, 'property="og:title"' ) );
sahaj_ok( 'and the JSON-LD block', false !== strpos( $sahaj_head, '<script type="application/ld+json">' ) );

/*
 * ⚠ The producer escaped `<` and `>` for this sink. Re-encoding here would double-escape the
 * block into structured data no crawler can read, and `esc_html` would turn it into `&quot;`-laden
 * text. Both failures still render a `<script>` tag, so only the payload itself shows them.
 */
sahaj_ok(
	'the JSON-LD is echoed exactly as it arrived',
	false !== strpos( $sahaj_head, '<script type="application/ld+json">' . $sahaj_seo_root['jsonLd'] . '</script>' )
);
sahaj_ok( 'and never re-escaped', false === strpos( $sahaj_head, '&quot;' ) && false === strpos( $sahaj_head, '\\\\u003c' ) );

$sahaj_children = sahaj_atlas_element_children();

sahaj_ok( 'the crawlable body carries the page title as its heading', false !== strpos( $sahaj_children, '<h1>Free meditation classes near you</h1>' ) );
sahaj_is( 'and one paragraph per block the operator wrote', 2, substr_count( $sahaj_children, '<p>' ) );

sahaj_ok( 'and the host\'s own SEO plugin is silenced', sahaj_seo_suppressed() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A bare view route is a view of the root, and gets the root\'s metadata' );

sahaj_seo_stub( $sahaj_seo_root );
sahaj_seo_request_for( '/search' );
sahaj_atlas_seo_boot();

sahaj_ok(
	'the route is sent verbatim, not normalized here',
	(bool) preg_match( '#[?&]route=/search(&|$)#', (string) $GLOBALS['sahaj_seo_requests'][0] )
);
sahaj_is( 'and the answer describes the page', 'Free meditation classes near you', sahaj_atlas_seo_title( 'Some theme title' ) );
sahaj_ok( 'and takes the page over', sahaj_seo_suppressed() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The opt-out hands the Atlas page back' );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '1' );

sahaj_seo_stub( $sahaj_seo_root );
sahaj_seo_request_for( '' );
sahaj_atlas_seo_boot();

sahaj_is( 'nothing is fetched for the page itself', array(), $GLOBALS['sahaj_seo_requests'] );
sahaj_is( 'nothing is emitted', '', sahaj_seo_head() );
sahaj_is( 'the theme keeps its own title', 'Some theme title', sahaj_atlas_seo_title( 'Some theme title' ) );
sahaj_is( 'the element keeps its own children', '', sahaj_atlas_element_children() );
sahaj_ok( 'and the host\'s SEO plugin is left alone', ! sahaj_seo_suppressed() );

sahaj_seo_stub( $sahaj_seo_root );
sahaj_seo_request_for( '/search' );
sahaj_atlas_seo_boot();

/*
 * ⚠ A bare view route is the same page, so the opt-out has to cover it too — and the plugin learns
 * which routes those are from `type` in the answer, never from a copy of the endpoint's own
 * segment list. That copy would drift the day a view is added upstream.
 */
sahaj_ok( 'a bare view route is opted out as well', ! sahaj_seo_suppressed() );
sahaj_is( 'and emits nothing either', '', sahaj_seo_head() );

sahaj_seo_stub( $sahaj_seo_region );
sahaj_seo_request_for( '/gb/london' );
sahaj_atlas_seo_boot();

// The opt-out is about the one page a host's SEO plugin has ever seen. `/gb/london` is an address
// it has never heard of, and would describe as the Atlas page — so there is nothing to hand back.
sahaj_is( 'a region route is still described', 'London', sahaj_atlas_seo_title( 'Some theme title' ) );
sahaj_ok( 'and still taken over', sahaj_seo_suppressed() );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A failed fetch changes nothing at all (trap 16)' );

sahaj_seo_stub( array( 'errors' => array( array( 'message' => 'Not found' ) ) ), 404 );
sahaj_seo_request_for( '' );
sahaj_atlas_seo_boot();

/*
 * ⚠ Suppressing first and fetching second leaves the page with no metadata at all — strictly worse
 * than the generic metadata it replaces, and invisible to everyone but a crawler. The emission
 * assertion alone would pass against that defect. This pair is what catches it.
 */
sahaj_ok( 'the host\'s SEO plugin is not silenced', ! sahaj_seo_suppressed() );
sahaj_is( 'and nothing is emitted', '', sahaj_seo_head() );
sahaj_is( 'and the theme keeps its title', 'Some theme title', sahaj_atlas_seo_title( 'Some theme title' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'What the root\'s crawlable body renders' );

sahaj_seo_stub(
	array_merge( $sahaj_seo_root, array( 'description' => null, 'content' => array( 'paragraphs' => array() ) ) )
);
sahaj_seo_request_for( '' );
sahaj_atlas_seo_boot();

// A locale nobody has written copy for returns `description: null`, which is the documented answer
// rather than a failure. The title is mandatory markup, so it is always there.
$sahaj_bare = sahaj_atlas_element_children();

sahaj_ok( 'a description-less root still renders its heading', false !== strpos( $sahaj_bare, '<h1>Free meditation classes near you</h1>' ) );
sahaj_is( 'and no empty paragraph', 0, substr_count( $sahaj_bare, '<p>' ) );
sahaj_is( 'and emits no empty description tag', 0, substr_count( sahaj_seo_head(), 'name="description"' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The cache is keyed by what the answer depends on' );

sahaj_ok( 'a different route gets a different slot', sahaj_atlas_seo_cache_key( '/' ) !== sahaj_atlas_seo_cache_key( '/search' ) );
sahaj_ok( 'and a different locale too', sahaj_atlas_seo_cache_key( '/', 'en' ) !== sahaj_atlas_seo_cache_key( '/', 'nl' ) );

$sahaj_slot_before = sahaj_atlas_seo_cache_key( '/', 'en' );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'another-key-456' );

// ⚠ Two clients on one server answer differently for the same route. A slot that ignored the key
// would serve one site's atlas description on another's page.
sahaj_ok( 'and a different key, so one client never reads another\'s answer', $sahaj_slot_before !== sahaj_atlas_seo_cache_key( '/', 'en' ) );

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
delete_transient( sahaj_atlas_seo_cache_key( '/' ) );

$sahaj_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

sahaj_is( 'ours, when we supply it', 'ok', $sahaj_check['status'] );
sahaj_ok( 'and it shows the title a visitor would see', false !== strpos( $sahaj_check['detail'], 'Free meditation classes near you' ) );

sahaj_seo_stub( array( 'errors' => array() ), 404 );
delete_transient( sahaj_atlas_seo_cache_key( '/' ) );

$sahaj_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

// ⚠ A warning, not a failure. Nothing is broken: the host's own metadata is still there, because
// suppression never happened. A red row here would send a volunteer to us about a working site.
sahaj_is( 'a silent endpoint is a warning, not a failure', 'warn', $sahaj_check['status'] );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '1' );

$sahaj_check = sahaj_atlas_check_page_description( array( 'name' => 'Test client' ) );

sahaj_is( 'theirs, when they asked for it', 'idle', $sahaj_check['status'] );
sahaj_ok( 'and it says so in words', false !== strpos( $sahaj_check['detail'], 'SEO plugin' ) );

update_option( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT, '' );
sahaj_seo_request_for( '' );
remove_filter( 'pre_http_request', 'sahaj_seo_http_stub', 10 );
