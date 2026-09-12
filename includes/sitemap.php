<?php
/**
 * Telling search engines the atlas pages exist.
 *
 * `includes/seo.php` makes every atlas route render correctly once a crawler asks for it. Nothing
 * tells a crawler to ask, because the routes live behind a JavaScript widget, with no `<a href>`
 * trail into them from anywhere on the site. A sitemap is the entire discovery path.
 *
 * ⚠ This reads `loc`, and never composes it. `GET /api/atlas/sitemap` (SahajCloud#651) returns the
 * same `webUrl` that `/api/atlas/seo` returns as each page's `canonical` — byte-identical, and
 * asserted upstream. Composing these URLs here, from the route plus the page permalink plus the
 * routing mode, would be a second implementation of that rule, in PHP, free to disagree about
 * mount joining, trailing slashes, and query-vs-path. A sitemap's whole job is publishing URLs a
 * crawler will fetch, so a disagreement here means a set of 404s submitted on purpose.
 *
 * ## Why the plugin serves its own file
 *
 * The obvious design is one adapter per SEO plugin, each feeding URLs into that plugin's own
 * sitemap. ⚠ This plugin rejects that design, because it means four renderer APIs, and it can
 * verify none of them. Yoast wants a registered callback that writes XML through a global. AIOSEO
 * wants `stdClass` rows. Rank Math wants its own shape. Core wants a `WP_Sitemaps_Provider`
 * subclass. Three of those cannot be tested here, and integration code written from memory
 * against an API nobody has to hand is the kind that looks right and returns a 500 on somebody's
 * live site.
 *
 * So the plugin serves one sitemap, at `/sahaj-atlas-sitemap.xml`, built by code that is fully
 * covered. Each SEO system then only has to point at it, which is a string in every case.
 * `robots.txt` alone would very nearly do this job: every crawler reads the `Sitemap:` line, and
 * that line is one documented filter that works with each SEO plugin, and with none.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** The path our sitemap is served at, relative to the site root. */
define( 'SAHAJ_ATLAS_SITEMAP_PATH', 'sahaj-atlas-sitemap.xml' );

/** How long the fetched URL set is cached. Long, because ownership changes are a CMS edit, not traffic. */
define( 'SAHAJ_ATLAS_SITEMAP_TTL', 6 * HOUR_IN_SECONDS );

/** Transient holding the last fetched URL set. */
define( 'SAHAJ_ATLAS_SITEMAP_TRANSIENT', 'sahaj_atlas_sitemap_urls' );

/**
 * Runs on `init`.
 */
function sahaj_atlas_register_sitemap() {
	// Every `loc` points into the Atlas page, and the fetch needs a key.
	if ( ! sahaj_atlas_page_is_healthy() || '' === sahaj_atlas_api_key() ) {
		return;
	}

	add_filter( 'robots_txt', 'sahaj_atlas_robots_txt', 20, 2 );
	add_filter( 'wpseo_sitemap_index', 'sahaj_atlas_yoast_sitemap_index' );
	add_filter( 'rank_math/sitemap/index', 'sahaj_atlas_rank_math_sitemap_index' );
}

function sahaj_atlas_sitemap_url() {
	return home_url( '/' . SAHAJ_ATLAS_SITEMAP_PATH );
}

/**
 * Serve the sitemap. Called from `parse_request`, before the atlas route matching.
 *
 * @param WP $wp The request.
 * @return bool Whether this request was ours.
 */
function sahaj_atlas_maybe_serve_sitemap( $wp ) {
	if ( SAHAJ_ATLAS_SITEMAP_PATH !== trim( (string) $wp->request, '/' ) ) {
		return false;
	}

	if ( ! sahaj_atlas_page_is_healthy() || '' === sahaj_atlas_api_key() ) {
		return false;
	}

	$urls = sahaj_atlas_sitemap_urls();

	/*
	 * ⚠ An empty set returns a 404, never an empty `<urlset>`. Empty means the fetch failed, or
	 * this client owns nothing — both are temporary states. A valid but empty sitemap tells a
	 * crawler this plugin has affirmatively nothing, and that is not a claim to make about a
	 * subtree that usually holds hundreds of pages. A 404 brings the crawler back later.
	 */
	if ( ! $urls ) {
		return false;
	}

	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow', true );

	echo sahaj_atlas_sitemap_xml( $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below, every value escaped there.

	return true;
}

/**
 * @param array<int, array{loc:string, lastmod:string}> $urls The URLs.
 * @return string
 */
function sahaj_atlas_sitemap_xml( $urls ) {
	$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

	foreach ( $urls as $url ) {
		$xml .= "\t<url>\n\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";

		if ( '' !== $url['lastmod'] ) {
			$xml .= "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
		}

		$xml .= "\t</url>\n";
	}

	return $xml . '</urlset>' . "\n";
}

/**
 * The atlas URLs to publish.
 *
 * @param bool $force Skip the cache.
 * @return array<int, array{loc:string, lastmod:string}>
 */
function sahaj_atlas_sitemap_urls( $force = false ) {
	$cached = $force ? false : get_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		SAHAJ_ATLAS_API_ORIGIN . '/api/atlas/sitemap',
		array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'clients API-Key ' . sahaj_atlas_api_key(),
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		/*
		 * ⚠ Cache the failure briefly, never for the full window. Crawlers fetch a sitemap and
		 * retry it. With no caching, every request here becomes a new request to a flapping
		 * upstream. With the full window instead, one bad minute leaves the sitemap dead for six
		 * hours after the fix.
		 */
		set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, array(), 5 * MINUTE_IN_SECONDS );

		return array();
	}

	$urls = sahaj_atlas_sitemap_rows( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );

	set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, $urls, SAHAJ_ATLAS_SITEMAP_TTL );

	return $urls;
}

/**
 * Turn the endpoint's body into the rows we publish.
 *
 * This is separated from the fetch, so the rules below are testable with no network. They are the
 * whole of what this plugin decides about the answer.
 *
 * @param mixed $body Decoded response body.
 * @return array<int, array{loc:string, lastmod:string}>
 */
function sahaj_atlas_sitemap_rows( $body ) {
	$rows = ( is_array( $body ) && isset( $body['urls'] ) && is_array( $body['urls'] ) ) ? $body['urls'] : array();
	$urls = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['loc'] ) || ! is_string( $row['loc'] ) ) {
			continue;
		}

		/*
		 * ⚠ This publishes only URLs on this site. The endpoint answers what the client owns, and
		 * an owned subtree is not necessarily served from the domain asking. A mis-set
		 * `canonical.embed`, or one key shared between two sites, would otherwise publish somebody
		 * else's URLs. A sitemap that lists another domain is a cross-site claim, and search
		 * engines treat it as one.
		 */
		if ( ! sahaj_atlas_is_local_url( $row['loc'] ) ) {
			continue;
		}

		$urls[] = array(
			'loc'     => $row['loc'],
			'lastmod' => ( isset( $row['lastmod'] ) && is_string( $row['lastmod'] ) ) ? $row['lastmod'] : '',
		);
	}

	return $urls;
}

/**
 * Is this URL served by this site?
 *
 * Trap 15's one test, shared: `sahaj_atlas_seo_root_points_elsewhere()` asks it of the root view's
 * canonical. A second copy there would be free to disagree with this one about the same URL.
 *
 * @param string $url Absolute URL.
 * @return bool
 */
function sahaj_atlas_is_local_url( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$here = wp_parse_url( home_url(), PHP_URL_HOST );

	return is_string( $host ) && is_string( $here ) && strtolower( $host ) === strtolower( $here );
}

/**
 * Forget the cached URLs — the settings screen calls this, and so does a key change.
 */
function sahaj_atlas_flush_sitemap_cache() {
	delete_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT );
}

/**
 * Announce the sitemap in `robots.txt`.
 *
 * ⚠ This is the load-bearing line. Every crawler reads the `Sitemap:` line, so it makes the atlas
 * discoverable on a site with no SEO plugin, and on a site with any of them. The two index filters
 * below are a convenience for site owners who read their SEO plugin's report — they are not the
 * discovery path.
 *
 * @param string $output The robots.txt body.
 * @param bool   $public Whether the site is set to be indexed.
 * @return string
 */
function sahaj_atlas_robots_txt( $output, $public ) {
	// A site set to discourage search engines gets nothing added. That switch is the owner's answer.
	if ( ! $public ) {
		return $output;
	}

	return rtrim( (string) $output ) . "\nSitemap: " . esc_url_raw( sahaj_atlas_sitemap_url() ) . "\n";
}

/**
 * Add a line to Yoast's sitemap index pointing at ours.
 *
 * `wpseo_sitemap_index` appends raw XML to the index. Its documented contract is a string, and
 * that is why this adapter exists instead of a Yoast renderer.
 *
 * @param string $index The index XML so far.
 * @return string
 */
function sahaj_atlas_yoast_sitemap_index( $index ) {
	return $index . sahaj_atlas_sitemap_index_entry();
}

/**
 * The same, for Rank Math.
 *
 * Absent from all nine surveyed client sites, so it is the least-exercised line here — which is
 * exactly why it is the same one-string append as Yoast rather than a second integration.
 *
 * @param string $index The index XML so far.
 * @return string
 */
function sahaj_atlas_rank_math_sitemap_index( $index ) {
	return $index . sahaj_atlas_sitemap_index_entry();
}

/**
 * One `<sitemap>` entry pointing at our file, or an empty string when there is nothing to point at.
 *
 * @return string
 */
function sahaj_atlas_sitemap_index_entry() {
	$urls = sahaj_atlas_sitemap_urls();

	if ( ! $urls ) {
		return '';
	}

	$entry = "\t<sitemap>\n\t\t<loc>" . esc_url( sahaj_atlas_sitemap_url() ) . "</loc>\n";
	$mod   = sahaj_atlas_latest_lastmod( $urls );

	if ( '' !== $mod ) {
		$entry .= "\t\t<lastmod>" . esc_html( $mod ) . "</lastmod>\n";
	}

	return $entry . "\t</sitemap>\n";
}

/**
 * The most recent `lastmod` in a set, for an index entry.
 *
 * @param array $urls Rows of `loc` + `lastmod`.
 * @return string
 */
function sahaj_atlas_latest_lastmod( $urls ) {
	$latest = '';

	foreach ( $urls as $url ) {
		if ( '' !== $url['lastmod'] && $url['lastmod'] > $latest ) {
			$latest = $url['lastmod'];
		}
	}

	return $latest;
}
