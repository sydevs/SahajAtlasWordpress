<?php
/**
 * Path routing: serving the Atlas page for everything beneath it.
 *
 * With `routing=path` the widget puts its route in the pathname —
 * `/find-a-class/gb/london` rather than `/find-a-class/?atlas=/gb/london`. In-widget clicks are
 * `pushState` and never reach the server, but a reload, a bookmark or a shared link does, and that
 * URL has to return the Atlas page.
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
 * ⚠ **Deliberately not `add_rewrite_rule()`.** A rewrite rule bakes the page's slug into a
 * serialised blob in `wp_options`, so it needs flushing on activation, on a slug change, on
 * re-parenting and on a permalink-structure change — and between those moments it is stale, which
 * shows up as a 404 on every deep link. Reading `get_page_uri()` per request removes the problem
 * rather than managing it. The audience is non-technical volunteers who will rename a page and have
 * no reason to know that "re-save your permalinks" is a thing.
 *
 * ⚠ **`$wp->request`, not the `pagename` query var.** Under `/%postname%/` permalinks WordPress
 * uses verbose page rules, fails `get_page_by_path()` on a deep atlas URL and falls through to a
 * *post* rule — so the query var present is `name`, not `pagename`. Matching on `$wp->request`
 * is the same on every permalink structure.
 *
 * @param WP $wp The request, by reference.
 */
function sahaj_atlas_parse_request( $wp ) {
	if ( ! sahaj_atlas_page_is_healthy() ) {
		return;
	}

	// Plain permalinks (`?p=123`) have no path to route into. The widget is told not to try, in
	// `sahaj_atlas_path_routing_viable()`; this is the matching half.
	if ( ! get_option( 'permalink_structure' ) ) {
		return;
	}

	$page_id = sahaj_atlas_page_id();
	$base    = get_page_uri( $page_id );

	if ( ! is_string( $base ) || '' === $base ) {
		return;
	}

	$path = trim( (string) $wp->request, '/' );
	$base = trim( $base, '/' );

	// The page itself is served by WordPress as usual; only what is *below* it is ours.
	if ( 0 !== strpos( $path, $base . '/' ) ) {
		return;
	}

	// A real page beneath the Atlas page belongs to whoever made it, not to us.
	if ( get_page_by_path( $path ) ) {
		return;
	}

	$wp->query_vars = array(
		'page_id'              => $page_id,
		SAHAJ_ATLAS_ROUTE_VAR  => '/' . substr( $path, strlen( $base ) + 1 ),
	);
}

/**
 * Stop WordPress redirecting a deep atlas URL back to the page root.
 *
 * ⚠ Without this, path routing looks like it works and then does not: `redirect_canonical()` sees
 * `is_page()` with a URL that is not the page's permalink and issues a 301 to the permalink, so
 * every shared deep link lands on the root view. It costs one filter and is invisible until
 * somebody follows a link.
 *
 * @param string|false $redirect The URL core wants to redirect to.
 * @return string|false
 */
function sahaj_atlas_suppress_canonical_redirect( $redirect ) {
	return sahaj_atlas_current_route() ? false : $redirect;
}

/**
 * The atlas route this request is for, or an empty string.
 *
 * @return string
 */
function sahaj_atlas_current_route() {
	$route = get_query_var( SAHAJ_ATLAS_ROUTE_VAR );

	return is_string( $route ) ? $route : '';
}

/**
 * Should the widget be told to use path routing?
 *
 * Two things must hold, and the plugin only owns one of them:
 *
 * 1. **The server serves the subtree** — that is `sahaj_atlas_parse_request()` above, and it needs
 *    pretty permalinks to have a path to work with.
 * 2. **The client record names this page** as its canonical embed, in SahajCloud. The prefix comes
 *    from there rather than from here, deliberately: it is the same value canonical URLs are
 *    composed from, and a second copy on a script tag could disagree with the one a canonical was
 *    built from.
 *
 * The widget checks the second itself and falls back to query routing with a console message when
 * it is missing. This function only asks whether it is worth claiming — sending `routing=path` from
 * a site with plain permalinks would advertise a mode we cannot serve.
 *
 * @return bool
 */
function sahaj_atlas_path_routing_viable() {
	return sahaj_atlas_page_is_healthy() && (bool) get_option( 'permalink_structure' );
}
