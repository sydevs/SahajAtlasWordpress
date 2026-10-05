<?php
/**
 * Tests where the Atlas page opens: reading what a volunteer pasted, checking it on save, and
 * handing it to the widget.
 *
 * @package SahajAtlas
 */

require_once ABSPATH . 'wp-admin/includes/template.php';

/** What the SahajCloud stub answers with: `array{status:int, body:string}`. */
$GLOBALS['sahaj_start_answer'] = array( 'status' => 200, 'body' => '{"type":"region","title":"United Kingdom"}' );
/** Every URL the stub was asked for since the last `sahaj_start_stub()`. */
$GLOBALS['sahaj_start_requests'] = array();

/**
 * Stand in for `GET /api/atlas/seo`, the check a save runs.
 *
 * @param false|array $pre  Short-circuit value.
 * @param array       $args Request arguments.
 * @param string      $url  The URL requested.
 * @return array A `wp_remote_get` response.
 */
function sahaj_start_http_stub( $pre, $args, $url ) {
	$GLOBALS['sahaj_start_requests'][] = $url;

	return array(
		'headers'  => array(),
		'body'     => $GLOBALS['sahaj_start_answer']['body'],
		'response' => array( 'code' => $GLOBALS['sahaj_start_answer']['status'], 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Arm the stub with one answer, and forget earlier requests and messages.
 *
 * @param int $status The status SahajCloud answers with.
 */
function sahaj_start_stub( $status ) {
	$GLOBALS['sahaj_start_answer']   = array(
		'status' => $status,
		'body'   => 200 === $status ? '{"type":"region","title":"United Kingdom"}' : '{"errors":[{"message":"That route does not name a region or an event."}]}',
	);
	$GLOBALS['sahaj_start_requests'] = array();
	$GLOBALS['wp_settings_errors']   = array();
}

/**
 * The messages the last save left under the field, as `type: message`.
 *
 * @return string[]
 */
function sahaj_start_messages() {
	return array_map(
		function ( $error ) {
			return $error['type'] . ': ' . $error['message'];
		},
		get_settings_errors( SAHAJ_ATLAS_OPTION_START_ROUTE )
	);
}

add_filter( 'pre_http_request', 'sahaj_start_http_stub', 10, 3 );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

$sahaj_start_structure = (string) get_option( 'permalink_structure' );
sahaj_set_permalink_structure( '/%postname%/' );

$sahaj_start_page = untrailingslashit( (string) get_permalink( sahaj_atlas_page_id() ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A pasted place becomes a route' );

sahaj_is( 'a route stays a route', '/gb', sahaj_atlas_route_from_input( '/gb' ) );
sahaj_is( 'a bare slug path gains its slash, and loses a trailing one', '/gb/london', sahaj_atlas_route_from_input( 'gb/london/' ) );
sahaj_is( 'a sahajatlas.com address is read as its path', '/gb/london', sahaj_atlas_route_from_input( 'https://sahajatlas.com/gb/london' ) );
sahaj_is( 'and its home page as no route', '', sahaj_atlas_route_from_input( 'https://sahajatlas.com/' ) );
sahaj_is( 'this site\'s Atlas page, path-routed', '/gb/london', sahaj_atlas_route_from_input( "$sahaj_start_page/gb/london/" ) );
sahaj_is( 'this site\'s Atlas page, query-routed', '/gb', sahaj_atlas_route_from_input( "$sahaj_start_page/?atlas=/gb" ) );
sahaj_is( 'the Atlas page itself is no route', '', sahaj_atlas_route_from_input( $sahaj_start_page ) );
sahaj_is( 'another site\'s address names nothing', '', sahaj_atlas_route_from_input( 'https://example.org/gb' ) );
sahaj_is( 'another page on this site names nothing', '', sahaj_atlas_route_from_input( home_url( '/about/gb' ) ) );
sahaj_is( 'a protocol-relative path is refused', '', sahaj_atlas_route_from_input( '//evil.example' ) );
sahaj_is( 'and so is one smuggled through sahajatlas.com', '', sahaj_atlas_route_from_input( 'https://sahajatlas.com//evil.example' ) );
sahaj_is( 'and through an atlas parameter', '', sahaj_atlas_route_from_input( 'https://example.org/?atlas=//evil.example' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Saving checks the place with SahajCloud' );

sahaj_start_stub( 200 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, 'https://sahajatlas.com/gb' );

sahaj_is( 'a place the atlas knows is stored as its route', '/gb', get_option( SAHAJ_ATLAS_OPTION_START_ROUTE ) );
sahaj_is( 'it was asked about once, despite core sanitising a new option twice', 1, count( $GLOBALS['sahaj_start_requests'] ) );
sahaj_ok( 'about that route', false !== strpos( urldecode( (string) end( $GLOBALS['sahaj_start_requests'] ) ), 'route=/gb' ) );
sahaj_is( 'and no message is left', array(), sahaj_start_messages() );

sahaj_start_stub( 404 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '/zz' );

sahaj_is( 'a place the atlas does not know keeps the previous route', '/gb', get_option( SAHAJ_ATLAS_OPTION_START_ROUTE ) );
sahaj_ok( 'and says so', 1 === count( sahaj_start_messages() ) && 0 === strpos( sahaj_start_messages()[0], 'error: The atlas has no country, city or class at /zz' ) );

sahaj_start_stub( 200 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, 'https://example.org/somewhere' );

sahaj_is( 'an address that names no place keeps the previous route', '/gb', get_option( SAHAJ_ATLAS_OPTION_START_ROUTE ) );
sahaj_is( 'without asking SahajCloud', array(), $GLOBALS['sahaj_start_requests'] );
sahaj_ok( 'and says so', 1 === count( sahaj_start_messages() ) && 0 === strpos( sahaj_start_messages()[0], 'error: That address is not a place in the atlas' ) );

sahaj_start_stub( 503 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, 'gb/london' );

sahaj_is( 'an unreachable check still saves', '/gb/london', get_option( SAHAJ_ATLAS_OPTION_START_ROUTE ) );
sahaj_ok( 'with a warning', 1 === count( sahaj_start_messages() ) && 0 === strpos( sahaj_start_messages()[0], 'warning: Saved /gb/london' ) );

sahaj_start_stub( 200 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '' );

sahaj_is( 'an empty field clears it', '', get_option( SAHAJ_ATLAS_OPTION_START_ROUTE ) );
sahaj_is( 'without asking SahajCloud', array(), $GLOBALS['sahaj_start_requests'] );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The Atlas page opens there' );

$sahaj_start_query    = $GLOBALS['wp_query'];
$GLOBALS['wp_query'] = sahaj_query_page();

$sahaj_start_embed = sahaj_atlas_resolve_embed();

sahaj_is( 'with no route set, the page embed names none', '', $sahaj_start_embed['atlas'] );
sahaj_ok( 'and its script carries no atlas parameter', false === strpos( sahaj_atlas_script_url( $sahaj_start_embed ), 'atlas=' ) );

sahaj_start_stub( 200 );
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '/gb/london' );

$sahaj_start_embed = sahaj_atlas_resolve_embed();

sahaj_is( 'with one set, the page embed opens there', '/gb/london', $sahaj_start_embed['atlas'] );
sahaj_ok( 'through the script\'s atlas parameter', false !== strpos( urldecode( sahaj_atlas_script_url( $sahaj_start_embed ) ), 'atlas=/gb/london' ) );

// ⚠ A row written some other way than this screen must still never reach the script unchecked.
update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '//evil.example' );
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->options, array( 'option_value' => '//evil.example' ), array( 'option_name' => SAHAJ_ATLAS_OPTION_START_ROUTE ) );
wp_cache_delete( SAHAJ_ATLAS_OPTION_START_ROUTE, 'options' );
wp_cache_delete( 'alloptions', 'options' );

sahaj_is( 'a stored value the sanitiser would refuse is dropped on read', '', sahaj_atlas_start_route() );

update_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '' );
$GLOBALS['wp_query'] = $sahaj_start_query;

sahaj_group( 'A shortcode takes the same addresses' );

sahaj_is( 'a sahajatlas.com address', '/gb', sahaj_atlas_normalize_attrs( array( 'atlas' => 'https://sahajatlas.com/gb' ), 'shortcode' )['atlas'] );
sahaj_is( 'a route, as before', '/in/pune/507/register', sahaj_atlas_normalize_attrs( array( 'atlas' => '/in/pune/507/register' ), 'shortcode' )['atlas'] );

remove_filter( 'pre_http_request', 'sahaj_start_http_stub', 10 );
$GLOBALS['wp_settings_errors'] = array();
sahaj_set_permalink_structure( $sahaj_start_structure );
