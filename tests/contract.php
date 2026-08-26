<?php
/**
 * The shared canonical-Atlas-URL contract, asserted from the serving side.
 *
 * `tests/atlas-url-contract.json` is byte-identical in SahajCloud (which BUILDS these URLs) and
 * SahajAtlasWeb (which parses them back into a view). This plugin is the third consumer, and its
 * relationship to the file is the inverse of theirs: it never composes a canonical — `includes/seo.php`
 * emits the server's verbatim, deliberately — but it is what has to **answer** the URLs the other two
 * agree on. A canonical SahajCloud publishes and this plugin 404s is the same defect as a canonical
 * built wrong, arriving from the other direction.
 *
 * So every `routing: "path"` case with a URL is replayed as a request, and every case the contract
 * refuses is asserted never to be claimed.
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

	// Query-routing cases put the route in `?atlas=`, which never reaches the server as a path —
	// there is nothing for this plugin to serve, and the widget's own suite covers the parsing.
	if ( 'path' !== $routing ) {
		continue;
	}

	$mount = isset( $target['mount'] ) ? (string) $target['mount'] : '';
	$slug  = trim( $mount, '/' );

	// A root mount is the one path case this plugin does not serve. Asserted below, on its own.
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
		 * ⚠ **Not every refusal is this plugin's to mirror, and asserting that they all are was
		 * wrong.** The contract refuses for two different reasons. Some are about the URL's SHAPE
		 * (`/nl//amsterdam` has a blank segment) — those survive into a request a crawler could
		 * really send, and refusing them is ours. The rest are about the OWNER RECORD being
		 * incomplete (no origin) or the input not being slash-prefixed — upstream data problems
		 * that never produce a malformed request, because the request would be the perfectly
		 * ordinary `/map/nl/amsterdam`. Refusing to SERVE that would 404 a page that is fine.
		 *
		 * So the test is whether the malformation survives into the request path, judged by the
		 * contract's own `slugPattern` rather than by a second opinion written here.
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
 * ⚠ The contract publishes `https://sahajayoga.nl/nl/amsterdam` for a root mount — an atlas that IS
 * the site's front page. This plugin deliberately does not serve that, and the reason is that
 * serving it correctly is indistinguishable from serving it catastrophically: with no path prefix,
 * "everything below the atlas page" is every URL on the site, so the matcher would have to claim
 * each one that no post, page, category, tag, feed or archive answered. Half of those are resolved
 * *after* `parse_request`, so the plugin cannot know — it would turn the site's 404 page into the
 * atlas.
 *
 * Refusing is therefore correct, but only if it is VISIBLE: silently falling back to query routing
 * while SahajCloud publishes path canonicals means every canonical 404s. The diagnostics panel says
 * so, which is the half that makes this a supported limitation rather than a bug.
 */
sahaj_mount_atlas_page_at( 'find-a-class' );

$sahaj_front = (int) get_option( 'page_on_front' );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', sahaj_atlas_page_id() );
clean_post_cache( sahaj_atlas_page_id() );

sahaj_is( 'a front-page atlas claims no route at the site root', null, sahaj_route_for( 'nl/amsterdam' ) );

// ⚠ And not under its old slug either. WordPress serves a front page at `/` and redirects its
// permalink there, so a deep link under the slug is dead whichever end refuses it — but only this
// assertion distinguishes "the front-page check fired" from "the prefix simply did not match",
// which is what the line above passes on with no front-page handling at all.
sahaj_is( 'nor under the slug it still has', null, sahaj_route_for( 'find-a-class/nl/amsterdam' ) );
sahaj_ok( 'and path routing reports itself unavailable', ! sahaj_atlas_path_routing_viable() );

update_option( 'show_on_front', 'posts' );
update_option( 'page_on_front', $sahaj_front );
clean_post_cache( sahaj_atlas_page_id() );

sahaj_ok( 'which is restored once it is no longer the front page', sahaj_atlas_path_routing_viable() );
