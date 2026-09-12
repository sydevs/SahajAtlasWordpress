<?php
/**
 * Asserts the shared canonical Atlas URL contract, from the serving side.
 *
 * `tests/atlas-url-contract.json` is byte-identical across three repos. SahajCloud builds these
 * URLs. SahajAtlasWeb parses them back into a view. This plugin is the third consumer, with the
 * opposite job: it never builds a canonical URL. `includes/seo.php` emits the server's own value,
 * unchanged, on purpose. But this plugin must answer the URLs the other two agree on. A canonical
 * URL that SahajCloud publishes and this plugin 404s is the same defect as a wrong canonical URL,
 * seen from the other side.
 *
 * Every `routing: "path"` case with a URL is replayed here as a request, and every `"query"` case
 * as the parameter it arrives in. Every case the contract refuses is asserted to stay unclaimed.
 *
 * @package SahajAtlas
 */

/**
 * Move the Atlas page to a given single-segment mount.
 *
 * @param string $slug Page slug, no slashes.
 */
function sahaj_mount_atlas_page_at( $slug ) {
	wp_update_post(
		array(
			'ID'        => sahaj_atlas_page_id(),
			'post_name' => $slug,
		)
	);

	clean_post_cache( sahaj_atlas_page_id() );
}

$sahaj_contract_file = __DIR__ . '/atlas-url-contract.json';
$sahaj_contract      = json_decode( (string) file_get_contents( $sahaj_contract_file ), true );

sahaj_group( 'The shared URL contract' );

sahaj_ok( 'the fixture parses', is_array( $sahaj_contract ) && ! empty( $sahaj_contract['cases'] ) );
sahaj_is( 'and is the version this suite was written against', 1, isset( $sahaj_contract['version'] ) ? $sahaj_contract['version'] : null );

update_option( 'permalink_structure', '/%postname%/' );

$sahaj_replayed = 0;
$sahaj_refused  = 0;

foreach ( $sahaj_contract['cases'] as $case ) {
	$target  = isset( $case['target'] ) ? $case['target'] : array();
	$routing = isset( $target['routing'] ) ? $target['routing'] : '';

	// Query-routing cases never reach the server as a path. They are replayed as parameters in
	// their own group below.
	if ( 'path' !== $routing ) {
		continue;
	}

	$mount = isset( $target['mount'] ) ? (string) $target['mount'] : '';
	$slug  = trim( $mount, '/' );

	// A root mount is the one path case this plugin does not serve. The assertion below covers
	// it separately.
	if ( '' === $slug ) {
		continue;
	}

	// An off-site or query-shaped mount is not this site at all.
	if ( false !== strpos( $slug, '://' ) || false !== strpos( $slug, '?' ) ) {
		continue;
	}

	sahaj_mount_atlas_page_at( $slug );

	$expected = isset( $case['expected'] ) ? $case['expected'] : null;
	$name     = isset( $case['name'] ) ? (string) $case['name'] : '(unnamed)';

	if ( null === $expected ) {
		/*
		 * ⚠ Not every refusal is this plugin's to mirror. An earlier version wrongly asserted
		 * that all of them were. The contract refuses cases for two different reasons.
		 *
		 * The first reason is the URL's shape. `/nl//amsterdam` has a blank segment. A crawler
		 * could really send a request like that, so refusing it is this plugin's job.
		 *
		 * The second reason is the owner record: it has no origin, or its input lacks a leading
		 * slash. Both are upstream data problems. Neither produces a malformed request, because
		 * the real request would be the ordinary `/map/nl/amsterdam`. Refusing to serve that
		 * would 404 a page that is fine.
		 *
		 * So this test checks only whether the malformation survives into the request path. It
		 * judges that with the contract's own `slugPattern`, not a second opinion written here.
		 */
		$bad = isset( $case['webPath'] ) ? (string) $case['webPath'] : '';

		if ( '' === $bad ) {
			continue;
		}

		$request = $slug . '/' . ltrim( $bad, '/' );

		if ( preg_match( '#' . $sahaj_contract['slugPattern'] . '#', '/' . $request ) ) {
			continue;
		}

		++$sahaj_refused;

		sahaj_is( "refuses what the contract refuses — $name", null, sahaj_route_for( $request ) );

		continue;
	}

	$path = (string) wp_parse_url( (string) $expected, PHP_URL_PATH );

	++$sahaj_replayed;

	sahaj_is(
		"serves the published canonical — $name",
		rtrim( (string) $case['webPath'], '/' ),
		rtrim( (string) sahaj_route_for( trim( $path, '/' ) ), '/' )
	);
}

sahaj_ok( 'replayed the path cases the contract publishes', $sahaj_replayed >= 3 );
sahaj_ok( 'and the ones it refuses', $sahaj_refused >= 1 );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A root mount is refused, not mis-served' );

/*
 * ⚠ The contract publishes `https://sahajayoga.nl/nl/amsterdam` for a root mount: an atlas that
 * IS the site's front page. This plugin deliberately refuses to serve that case.
 *
 * The reason: serving it correctly is impossible to tell apart from serving it catastrophically.
 * With no path prefix, "everything below the atlas page" means every URL on the site. The route
 * matcher would have to claim every URL that no post, page, category, tag, feed, or archive
 * answers. WordPress resolves half of those only after `parse_request`, so the plugin cannot
 * know about them in time. It would turn the site's own 404 page into the atlas.
 *
 * Refusing is correct, but only if it is visible. A silent fallback to query routing, while
 * SahajCloud still publishes path canonicals, makes every canonical URL 404. The diagnostics
 * panel reports this, which turns it from a bug into a supported limitation.
 */
sahaj_mount_atlas_page_at( 'find-a-class' );

$sahaj_front = (int) get_option( 'page_on_front' );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', sahaj_atlas_page_id() );
clean_post_cache( sahaj_atlas_page_id() );

sahaj_is( 'a front-page atlas claims no route at the site root', null, sahaj_route_for( 'nl/amsterdam' ) );

// ⚠ Not under its old slug either. WordPress serves the front page at `/` and redirects the old
// permalink there, so a deep link under the old slug is dead either way. But only this assertion
// tells "the front-page check fired" apart from "the prefix simply did not match" — the line
// above would pass that second case too, even with no front-page handling at all.
sahaj_is( 'nor under the slug it still has', null, sahaj_route_for( 'find-a-class/nl/amsterdam' ) );
sahaj_ok( 'and path routing reports itself unavailable', ! sahaj_atlas_path_routing_viable() );

update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', $sahaj_front );
clean_post_cache( sahaj_atlas_page_id() );

sahaj_ok( 'which is restored once it is no longer the front page', sahaj_atlas_path_routing_viable() );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Query routing answers the same contract' );

/*
 * ⚠ The path cases above are replayed as requests. These are replayed as parameters, because that
 * is how the route arrives — the server sees the page's own permalink either way. Both halves
 * matter equally: a canonical URL SahajCloud publishes and this plugin renders as a generic page is
 * the same defect as one it 404s, seen from the other side.
 *
 * Query routing is permanent for the hosts on it, not a waiting room. A site with plain permalinks,
 * an atlas at its front page, or a server SahajCloud cannot probe stays here for good.
 */
sahaj_is(
	'the plugin reads the parameter the contract names',
	isset( $sahaj_contract['queryParam'] ) ? $sahaj_contract['queryParam'] : null,
	SAHAJ_ATLAS_QUERY_VAR
);

$sahaj_previous_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$GLOBALS['wp_query']  = new WP_Query( array( 'page_id' => sahaj_atlas_page_id() ) );

$sahaj_query_replayed = 0;

foreach ( $sahaj_contract['cases'] as $case ) {
	$target  = isset( $case['target'] ) ? $case['target'] : array();
	$routing = isset( $target['routing'] ) ? $target['routing'] : '';

	if ( 'query' !== $routing || empty( $case['expected'] ) ) {
		continue;
	}

	parse_str( (string) wp_parse_url( (string) $case['expected'], PHP_URL_QUERY ), $sahaj_args );

	if ( ! isset( $sahaj_args[ SAHAJ_ATLAS_QUERY_VAR ] ) ) {
		continue;
	}

	sahaj_set_query_route( $sahaj_args[ SAHAJ_ATLAS_QUERY_VAR ] );

	++$sahaj_query_replayed;

	sahaj_is(
		'reads the published route — ' . ( isset( $case['name'] ) ? (string) $case['name'] : '(unnamed)' ),
		(string) $case['webPath'],
		sahaj_atlas_current_route()
	);
}

unset( $_GET[ SAHAJ_ATLAS_QUERY_VAR ] );

sahaj_ok( 'replayed the query cases the contract publishes', $sahaj_query_replayed >= 3 );

$GLOBALS['wp_query'] = $sahaj_previous_query;
