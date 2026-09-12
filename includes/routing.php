<?php
/**
 * Path routing: serving the Atlas page for everything beneath it.
 *
 * With `routing=path`, the widget puts its route in the pathname — `/find-a-class/gb/london`, not
 * `/find-a-class/?atlas=/gb/london`. A click inside the widget uses `pushState` and never reaches
 * the server. But a reload, a bookmark, or a shared link does reach the server, and that URL must
 * return the Atlas page.
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

	// WordPress serves the page itself as usual. Only what is below it belongs to this plugin.
	if ( 0 !== strpos( $path, $base . '/' ) ) {
		return;
	}

	// A real page beneath the Atlas page belongs to whoever made it, not to us.
	if ( get_page_by_path( $path ) ) {
		return;
	}

	$route = '/' . substr( $path, strlen( $base ) + 1 );

	/*
	 * ⚠ This refuses an empty segment (`/nl//amsterdam`) rather than pass it on. The shared URL
	 * contract never publishes such a URL — a blank region slug is a known data defect upstream.
	 * Claiming one here would serve the atlas at an address nothing points at, under a route the
	 * widget cannot resolve. A 404 is the honest answer, and the rest of the system already assumes
	 * it.
	 */
	if ( false !== strpos( $route, '//' ) ) {
		return;
	}

	$wp->query_vars = array(
		'page_id'             => $page_id,
		SAHAJ_ATLAS_ROUTE_VAR => $route,
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
 * ⚠ This asks for the path route alone, never `sahaj_atlas_current_route()`. A query-routed URL is
 * the page's own permalink plus a parameter, so core has nothing to strip and nothing to suppress.
 * Widening this filter to every atlas route would return `false` on ordinary page loads that happen
 * to carry `?atlas=`, disabling a core behaviour for no gain.
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
 * @return string
 */
function sahaj_atlas_query_route() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public URL parameter on a read-only request; validated by `sahaj_atlas_clean_route()`.
	$raw = isset( $_GET[ SAHAJ_ATLAS_QUERY_VAR ] ) ? wp_unslash( $_GET[ SAHAJ_ATLAS_QUERY_VAR ] ) : '';

	if ( ! is_string( $raw ) || '' === $raw || ! sahaj_atlas_is_atlas_page() ) {
		return '';
	}

	// The same sanitiser the block and shortcode attribute uses. A value it refuses leaves the route
	// empty, so the page falls back to the host's own metadata rather than emitting a wrong one.
	return sahaj_atlas_clean_route( $raw );
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
