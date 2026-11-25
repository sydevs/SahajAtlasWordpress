<?php
/**
 * Routing: reading the atlas route a request carries, in either shape.
 *
 * With `routing=path`, the widget puts its route in the pathname — `/find-a-class/gb/london`, not
 * `/find-a-class/?atlas=/gb/london`. A click inside the widget uses `pushState` and never reaches
 * the server. But a reload, a bookmark, or a shared link does reach the server, and that URL must
 * return the Atlas page.
 *
 * With `routing=query` the server has nothing to match — WordPress already served the page — so
 * this file only reads the parameter back. Both shapes end at `sahaj_atlas_current_route()`.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * Let `get_query_var()` see our route.
 */
function sahaj_atlas_register_route_var() {
	add_filter(
		'query_vars',
		function ( $vars ) {
			$vars[] = SAHAJ_ATLAS_ROUTE_VAR;

			return $vars;
		}
	);
}

/**
 * Route `/{atlas page}/anything/below` to the Atlas page.
 *
 * ⚠ This deliberately avoids `add_rewrite_rule()`. A rewrite rule bakes the page's slug into a
 * serialised blob in `wp_options`, so it needs flushing on activation, on a slug change, on
 * re-parenting, and on a permalink-structure change. Between those moments it is stale, and a
 * stale rule appears as a 404 on every deep link. Reading `get_page_uri()` on each request
 * removes this problem, rather than managing it. The audience is non-technical volunteers, who
 * rename a page with no reason to know that "re-save your permalinks" is a thing.
 *
 * ⚠ This matches on `$wp->request`, not the `pagename` query var. Under `/%postname%/`
 * permalinks, WordPress uses verbose page rules. It fails `get_page_by_path()` on a deep atlas
 * URL, and falls through to a post rule instead — so the query var present is `name`, not
 * `pagename`. Matching on `$wp->request` works the same way on every permalink structure.
 *
 * @param WP $wp The request, by reference.
 */
function sahaj_atlas_parse_request( $wp ) {
	/*
	 * ⚠ This runs before everything else, including the healthy-page check that reads the same
	 * request. The sitemap lives at the site root, not under the Atlas page, so rules about the
	 * atlas subtree must not filter it. `sahaj_atlas_maybe_serve_sitemap()` sends its own response
	 * and returns true. Nothing is left for WordPress to route.
	 */
	if ( sahaj_atlas_maybe_serve_sitemap( $wp ) ) {
		exit;
	}

	if ( ! sahaj_atlas_page_is_healthy() ) {
		return;
	}

	// Plain permalinks (`?p=123`) have no path to route into. `sahaj_atlas_path_routing_viable()`
	// tells the widget not to try. This is the matching half of that rule.
	if ( ! get_option( 'permalink_structure' ) ) {
		return;
	}

	if ( sahaj_atlas_page_is_front_page() ) {
		return;
	}

	$page_id = sahaj_atlas_page_id();
	$base    = get_page_uri( $page_id );

	if ( ! is_string( $base ) || '' === $base ) {
		return;
	}

	$path = trim( (string) $wp->request, '/' );
	$base = trim( $base, '/' );

	/*
	 * ⚠ `$wp->request` is the percent-encoded path, exactly as the browser sent it, while a slug
	 * keeps its native letters (`/bg/софия`, not `/bg/%D1%81...`). Both sides go through the same
	 * helper, so a page whose own slug is non-ASCII, and a client that writes its hex in the other
	 * case, still match — and what a segment may hold is spelled once. `get_page_by_path()` below
	 * takes the path as received, since core decodes and re-encodes it itself.
	 *
	 * ⚠ A refusal here is a 404, never a pass-through: an empty segment (`/nl//amsterdam`),
	 * invalid UTF-8, or a `%2F`, `%3F`, `%23`, `%5C` or control byte that would turn into path
	 * syntax once decoded. The shared URL contract never publishes such a URL — a blank region slug
	 * is a known data defect upstream. Claiming one would serve the atlas at an address nothing
	 * points at, under a route the widget cannot resolve, and the rest of the system already
	 * assumes the 404.
	 */
	$decoded_path = sahaj_atlas_decode_route_path( $path );
	$decoded_base = sahaj_atlas_decode_route_path( $base );

	// WordPress serves the page itself as usual. Only what is below it belongs to this plugin.
	if ( '' === $decoded_path || '' === $decoded_base || 0 !== strpos( $decoded_path, $decoded_base . '/' ) ) {
		return;
	}

	// A real page beneath the Atlas page belongs to whoever made it, not to us.
	if ( get_page_by_path( $path ) ) {
		return;
	}

	$route = (string) substr( $decoded_path, strlen( $decoded_base ) );

	$wp->query_vars = array(
		'page_id'             => $page_id,
		SAHAJ_ATLAS_ROUTE_VAR => $route,
	);
}

/**
 * Decode a percent-encoded request path below the Atlas page into a route, or refuse it.
 *
 * Slugs keep their native letters, so the route is raw Unicode (`/bg/софия`) while the request
 * carries it encoded (`/bg/%D1%81%D0%BE%D1%84%D0%B8%D1%8F`). Decoding is per segment, which is what
 * the shared URL contract asks of a parser. A segment that is empty, is not valid UTF-8, or decodes
 * to a control character, `/`, `\`, `?` or `#` refuses the whole path, and the answer is an empty
 * string. Nothing here encodes: `sahaj_atlas_seo_request()` encodes the route once, on the way out.
 *
 * @param string $path Encoded path, e.g. `bg/%D1%81%D0%BE%D1%84%D0%B8%D1%8F`.
 * @return string The route with a leading slash, or an empty string.
 */
function sahaj_atlas_decode_route_path( $path ) {
	$segments = array();

	// Exactly one leading slash is optional. A second one is an empty segment, and refuses.
	if ( 0 === strpos( $path, '/' ) ) {
		$path = (string) substr( $path, 1 );
	}

	foreach ( explode( '/', $path ) as $segment ) {
		$segment = rawurldecode( $segment );

		// `preg_match()` with `/u` returns false on invalid UTF-8, so only an exact 0 passes.
		if ( '' === $segment || 0 !== preg_match( '/[\x00-\x1f\x7f\/\\\\?#]/u', $segment ) ) {
			return '';
		}

		$segments[] = $segment;
	}

	return '/' . implode( '/', $segments );
}

/**
 * Percent-encode the non-ASCII bytes below a URL's authority, and nothing else.
 *
 * A canonical, an alternate or a sitemap `<loc>` must be the encoded form a crawler can compare
 * (`/bg/%D1%81...`), never raw letters.
 *
 * ⚠ Not every answer arrives that way, so this is not dead weight. A document's `webUrl` is
 * `base . encodeURI(path)`, but the **root** view's canonical is not a `webUrl`: SahajCloud
 * composes it as `origin . mount` straight from the client record, in `canonicalMountUrl()`, with
 * no `encodeURI`. An Atlas page at `/карта` therefore answers with raw letters in its canonical,
 * its hreflang rows and its `og:url`, and `esc_url()` passes bytes ≥ 0x80 through untouched.
 *
 * ⚠ The authority is left alone. A non-ASCII host is an IDN, whose only valid wire form is
 * punycode — percent-encoding its bytes would publish an address no resolver can use.
 *
 * `%` is ASCII and is left alone, which is what keeps a second pass from double-encoding. This is
 * a guard against an answer that arrives raw, never a second composer of the URL.
 *
 * @param string $url A URL.
 * @return string
 */
function sahaj_atlas_encode_url( $url ) {
	$mark  = strpos( $url, '://' );
	$start = false === $mark ? 0 : (int) strpos( $url . '/', '/', $mark + 3 );

	return substr( $url, 0, $start ) . (string) preg_replace_callback(
		'/[\x80-\xff]+/',
		function ( $match ) {
			return rawurlencode( $match[0] );
		},
		(string) substr( $url, $start )
	);
}

/**
 * Stop WordPress redirecting a deep atlas URL back to the page root.
 *
 * ⚠ Without this, path routing looks like it works, then fails. `redirect_canonical()` sees
 * `is_page()` with a URL that is not the page's permalink, and issues a 301 back to the permalink.
 * So every shared deep link lands on the root view instead. This costs one filter, and stays
 * invisible until somebody follows a link.
 *
 * ⚠ This asks for the path route alone, never `sahaj_atlas_current_route()`. On a query-routed URL
 * core's redirect is the one we want. The shared contract publishes `/?p=42&atlas=…` and
 * `/index.php?page_id=7&atlas=…` mounts, and `redirect_canonical()` 301s each to the page's pretty
 * permalink with `?atlas=` intact — `atlas` is no registered query var, so core does not consume it.
 * Widening this filter would strand those visitors on the non-canonical mount.
 *
 * ⚠ The reason is that mount shape, not a site-wide cost. Widening this filter would not disable
 * the redirect across the site: `sahaj_atlas_query_route()` refuses off the Atlas page, so an
 * ordinary page load carrying `?atlas=` never reaches a non-empty route. `tests/seo.php` pins both
 * directions. Do not restate the wider claim — it reads plausible and is wrong.
 *
 * @param string|false $redirect The URL core wants to redirect to.
 * @return string|false
 */
function sahaj_atlas_suppress_canonical_redirect( $redirect ) {
	return sahaj_atlas_path_route() ? false : $redirect;
}

/**
 * The atlas route this request is for, whichever routing mode carried it, or an empty string.
 *
 * @return string
 */
function sahaj_atlas_current_route() {
	$route = sahaj_atlas_path_route();

	return '' !== $route ? $route : sahaj_atlas_query_route();
}

/**
 * The route `sahaj_atlas_parse_request()` claimed, or an empty string.
 *
 * @return string
 */
function sahaj_atlas_path_route() {
	$route = get_query_var( SAHAJ_ATLAS_ROUTE_VAR );

	return is_string( $route ) ? $route : '';
}

/**
 * The route carried in `?atlas=`, or an empty string.
 *
 * ⚠ Query routing is a supported, permanent shape, not a transitional one. A host whose server
 * cannot path-route — plain permalinks, an atlas at the front page, a server SahajCloud cannot
 * probe — stays on it for good, and SahajCloud publishes real canonical URLs for those routes. Read
 * here, so `sahaj_atlas_current_route()` answers for both shapes and the SEO takeover follows for
 * free.
 *
 * ⚠ The Atlas page check is load-bearing. `?atlas=` is a parameter anyone can append to any URL on
 * the site, and this function feeds `sahaj_atlas_seo_boot()`. Without the check, an arbitrary page
 * could be talked into claiming an atlas route's canonical as its own.
 *
 * ⚠ This reads `$_GET` rather than registering `atlas` through `query_vars`, and that is a
 * deliberate exception to preferring a core API. A registered var is filled from `$_POST` before
 * `$_GET`, so the route would become accepted from a request body. It would still need the Atlas
 * page check above, since `get_query_var()` answers on every page. It would claim the bare name
 * `atlas` as a public query var across the host's whole site. And `sahaj_atlas_parse_request()`
 * replaces `$wp->query_vars` wholesale, so it would be discarded on the path-routed requests.
 *
 * @return string
 */
function sahaj_atlas_query_route() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a public URL parameter on a read-only request, sanitized below once the cheap guards pass.
	$raw = isset( $_GET[ SAHAJ_ATLAS_ROUTE_PARAM ] ) ? $_GET[ SAHAJ_ATLAS_ROUTE_PARAM ] : '';

	if ( ! is_string( $raw ) || '' === $raw || ! sahaj_atlas_is_atlas_page() ) {
		return '';
	}

	// The same sanitiser the block and shortcode attribute uses. A value it refuses leaves the route
	// empty, and an empty route on the Atlas page is its root view — so the page describes itself,
	// never the crafted value. The refused string reaches neither the endpoint nor the canonical.
	//
	// ⚠ `wp_unslash()` is not optional. `wp_magic_quotes()` slashes every request variable before any
	// plugin reads one, so `/\evil.com` arrives as `/\\evil.com` and would walk past the sanitiser's
	// backslash check unrecognised.
	//
	// ⚠ The widget nests its own view state in the value (`?atlas=/nl/amsterdam%3Fcenter%3D4.9,52.3`),
	// which decodes to a route with a `?` in it. The endpoint 404s that, so only the path is the route.
	$raw = wp_unslash( $raw );

	return sahaj_atlas_clean_route( substr( $raw, 0, strcspn( $raw, '?#' ) ) );
}

/**
 * Should the widget use path routing?
 *
 * Two conditions must hold, and this plugin controls only the first:
 *
 * 1. The server serves the subtree. That is `sahaj_atlas_parse_request()` above, and it needs
 *    pretty permalinks to have a path to work with.
 * 2. The client record in SahajCloud names this page as its canonical embed. The prefix comes from
 *    there on purpose, since canonical URLs are composed from that same value — a second copy on a
 *    script tag could disagree with it.
 *
 * The widget checks the second condition itself, and defaults to query routing with a console
 * message when the condition fails. This function only asks whether path routing is worth
 * claiming. Sending `routing=path` from a site with plain permalinks would advertise a mode this
 * plugin cannot serve.
 *
 * @return bool
 */
function sahaj_atlas_path_routing_viable() {
	return sahaj_atlas_page_is_healthy()
		&& (bool) get_option( 'permalink_structure' )
		&& ! sahaj_atlas_page_is_front_page();
}

/**
 * Is the Atlas page this site's front page?
 *
 * ⚠ This is the one path-routing setup this plugin refuses, and refusing is correct. The shared
 * URL contract can publish `https://example.org/nl/amsterdam` for a root mount, so SahajCloud can
 * be configured to emit this shape. Serving it would mean claiming every URL on the site that no
 * post, page, category, tag, feed or archive answers. Half of those resolve after `parse_request`,
 * so the plugin cannot know which ones. It would turn the site's own 404 page into the atlas.
 *
 * Refusing is safe only because it is visible. The widget defaults to query routing, and the
 * diagnostics panel explains why. A silent fallback here would leave every canonical URL that
 * SahajCloud publishes pointing at a 404.
 *
 * @return bool
 */
function sahaj_atlas_page_is_front_page() {
	$id = sahaj_atlas_page_id();

	return $id > 0 && 'page' === get_option( 'show_on_front' ) && $id === (int) get_option( 'page_on_front' );
}
