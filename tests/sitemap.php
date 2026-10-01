<?php
/**
 * Tests the sitemap: what gets published, and what gets refused.
 *
 * This file does not cover the fetch itself — that is `wp_remote_get` and a transient. It covers
 * every rule the plugin applies to the answer, which is where the real decisions are.
 *
 * @package SahajAtlas
 */

sahaj_group( 'Sitemap rows: what survives the endpoint answer' );

$sahaj_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

$sahaj_body = array(
	'generated' => '2026-08-26T00:00:00.000Z',
	'urls'      => array(
		array( 'loc' => "https://$sahaj_host/find-a-class/gb", 'lastmod' => '2026-08-01T00:00:00.000Z', 'route' => '/gb' ),
		array( 'loc' => "https://$sahaj_host/find-a-class/gb/london", 'lastmod' => '2026-08-20T00:00:00.000Z', 'route' => '/gb/london' ),
		// ⚠ A URL on somebody else's domain. The endpoint answers with whatever the client record
		// owns. A key shared between two sites, or a mis-set `canonical.embed`, puts a foreign
		// host in the list. Publishing that URL makes a cross-site claim. Search engines treat it
		// as exactly that.
		array( 'loc' => 'https://someone-else.example/find-a-class/nl', 'lastmod' => '2026-08-02T00:00:00.000Z', 'route' => '/nl' ),
		array( 'loc' => '', 'lastmod' => '2026-08-03T00:00:00.000Z', 'route' => '/x' ),
		array( 'loc' => "https://$sahaj_host/find-a-class/gb/london/1204" ),
		'not an object',
	),
);

$sahaj_rows = sahaj_atlas_sitemap_rows( $sahaj_body );

sahaj_is( 'keeps only this site\'s URLs', 3, count( $sahaj_rows ) );
sahaj_is( 'in the order the server gave', "https://$sahaj_host/find-a-class/gb", $sahaj_rows[0]['loc'] );
sahaj_ok(
	'and never another domain',
	! preg_match( '/someone-else/', wp_json_encode( $sahaj_rows ) )
);
sahaj_is( 'a row with no lastmod still publishes', '', $sahaj_rows[2]['lastmod'] );
sahaj_is( 'a malformed body yields nothing', array(), sahaj_atlas_sitemap_rows( 'nonsense' ) );
sahaj_is( 'and so does a missing urls key', array(), sahaj_atlas_sitemap_rows( array( 'generated' => 'x' ) ) );

sahaj_is( 'the newest lastmod wins for the index entry', '2026-08-20T00:00:00.000Z', sahaj_atlas_latest_lastmod( $sahaj_rows ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The sitemap document' );

$sahaj_xml = sahaj_atlas_sitemap_xml( $sahaj_rows );

sahaj_ok( 'declares itself XML', 0 === strpos( $sahaj_xml, '<?xml version="1.0" encoding="UTF-8"?>' ) );
sahaj_ok( 'uses the sitemaps namespace', false !== strpos( $sahaj_xml, 'http://www.sitemaps.org/schemas/sitemap/0.9' ) );
sahaj_is( 'has one <url> per row', 3, substr_count( $sahaj_xml, '<url>' ) );
sahaj_is( 'and one <lastmod> per row that had one', 2, substr_count( $sahaj_xml, '<lastmod>' ) );
sahaj_ok( 'and parses', false !== simplexml_load_string( $sahaj_xml ) );

// A `&` in a query string is the realistic case. A query-routing canonical looks like
// `…/find-a-class/?atlas=/gb/london`. A second parameter adds another `&`. Raw, that is invalid
// XML. The whole document then fails to parse, taking every other URL down with it.
$sahaj_amp = sahaj_atlas_sitemap_xml(
	array( array( 'loc' => "https://$sahaj_host/find-a-class/?atlas=/gb/london&locale=fr", 'lastmod' => '' ) )
);

sahaj_ok( 'an ampersand in a URL is escaped', false === strpos( $sahaj_amp, 'london&locale' ) );
sahaj_ok( 'so the document still parses', false !== simplexml_load_string( $sahaj_amp ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Discovery' );

$sahaj_robots = sahaj_atlas_robots_txt( "User-agent: *\nDisallow:\n", true );

sahaj_ok( 'robots.txt announces the sitemap', false !== strpos( $sahaj_robots, 'Sitemap: ' ) );
sahaj_ok( 'at our own path', false !== strpos( $sahaj_robots, SAHAJ_ATLAS_SITEMAP_PATH ) );
sahaj_ok( 'keeping what was already there', 0 === strpos( $sahaj_robots, 'User-agent: *' ) );

// ⚠ `blog_public = 0` means the site owner said "do not index me". Adding a sitemap line there
// would override that decision, in the one file that exists to express it.
sahaj_is( 'and says nothing when the site asks not to be indexed', "User-agent: *\nDisallow:\n", sahaj_atlas_robots_txt( "User-agent: *\nDisallow:\n", false ) );

/*
 * ⚠ With nothing to publish, an SEO plugin's index must gain nothing at all. An index entry that
 * points at a 404ing sitemap is a broken link handed straight to a crawler.
 * `sahaj_atlas_maybe_serve_sitemap()` returns 404 for an empty set, and a site sits in that state
 * for the five minutes after any failed fetch.
 */
sahaj_atlas_flush_sitemap_cache();
set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, array(), MINUTE_IN_SECONDS );

sahaj_is( 'an empty set adds no index line at all', '<sitemapindex>', sahaj_atlas_yoast_sitemap_index( '<sitemapindex>' ) );

// The Yoast and Rank Math adapters both do the same one-string append, on purpose. See the
// module docblock for why neither one has its own renderer.
set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, $sahaj_rows, MINUTE_IN_SECONDS );

$sahaj_index = sahaj_atlas_yoast_sitemap_index( '<sitemapindex>' );

sahaj_ok( 'an SEO plugin index gains a line', false !== strpos( $sahaj_index, SAHAJ_ATLAS_SITEMAP_PATH ) );
sahaj_ok( 'carrying the newest lastmod', false !== strpos( $sahaj_index, '2026-08-20' ) );
sahaj_ok( 'keeping what was there', 0 === strpos( $sahaj_index, '<sitemapindex>' ) );
sahaj_is( 'and Rank Math gets the identical entry', $sahaj_index, sahaj_atlas_rank_math_sitemap_index( '<sitemapindex>' ) );

/*
 * ⚠ An empty set, not a flushed cache. The group below runs `parse_request`, and the sitemap
 * answers that hook first — with no cached URLs it fetches the live endpoint to decide (#28).
 * Empty is the state a failed fetch leaves behind anyway, so the assertion reads the same world.
 */
set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, array(), MINUTE_IN_SECONDS );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The sitemap path is not an atlas route' );

update_option( 'permalink_structure', '/%postname%/' );
sahaj_mount_atlas_page_at( 'find-a-class' );

sahaj_is(
	'a request for the sitemap is never claimed as a route',
	null,
	sahaj_route_for( SAHAJ_ATLAS_SITEMAP_PATH )
);

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The panel names a site whose `Sitemap:` line cannot exist' );

/**
 * Move the whole site into a subfolder, the way a host that installed WordPress under `/test/`
 * has it.
 *
 * ⚠ A filter, not `update_option( 'home', … )`. The lane's WordPress pins its own address through
 * `pre_option_home`, so writing the option changes nothing a later `home_url()` call reads, and
 * every assertion below passes against the unmoved site. `home_url` is also exactly the one value
 * the check reads, and rewriting only the authority keeps the sitemap path the site would really
 * serve.
 *
 * @param string $url A URL built from the home address.
 * @return string
 */
function sahaj_sitemap_subfolder( $url ) {
	return (string) preg_replace( '#^(https?://[^/]+)#', '$1/test', (string) $url, 1 );
}

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blog_public', '1' );

$sahaj_origin    = untrailingslashit( home_url() );
$sahaj_discovery = sahaj_atlas_check_sitemap_discovery();

sahaj_is( 'a root install with pretty permalinks is listed', 'ok', $sahaj_discovery['status'] );
sahaj_ok( 'and the row names the file', false !== strpos( $sahaj_discovery['detail'], SAHAJ_ATLAS_SITEMAP_PATH ) );

/*
 * ⚠ `WP_Rewrite::rewrite_rules()` gates the `robots.txt` rule on an empty `home_url()` path
 * (`wp-includes/class-wp-rewrite.php:1285`, WordPress 6.7.9), so a subfolder install serves its
 * robots.txt at an address no crawler reads. shrimataji.org runs in `/test/`. The row has to name
 * the file at the top of the domain *and* the line to paste into it, because a volunteer can
 * derive neither.
 */
add_filter( 'home_url', 'sahaj_sitemap_subfolder' );

$sahaj_discovery = sahaj_atlas_check_sitemap_discovery();

remove_filter( 'home_url', 'sahaj_sitemap_subfolder' );

sahaj_is( 'a subfolder install is a warning', 'warn', $sahaj_discovery['status'] );
sahaj_ok(
	'naming the robots.txt a crawler actually reads',
	false !== strpos( $sahaj_discovery['detail'], $sahaj_origin . '/robots.txt' )
);
sahaj_ok(
	'and the line to paste, pointing into the subfolder',
	false !== strpos( $sahaj_discovery['detail'], 'Sitemap: ' . $sahaj_origin . '/test/' . SAHAJ_ATLAS_SITEMAP_PATH )
);

// ⚠ Plain permalinks lose both files at once: `rewrite_rules()` returns no rules at all, so the
// sitemap path 404s as well. The one branch that is a failure rather than a warning, because
// there is no line a volunteer could paste that would be served.
update_option( 'permalink_structure', '' );

$sahaj_discovery = sahaj_atlas_check_sitemap_discovery();

sahaj_is( 'plain permalinks are a failure', 'fail', $sahaj_discovery['status'] );
sahaj_ok( 'and the row names the one screen that fixes it', false !== strpos( $sahaj_discovery['detail'], 'options-permalink.php' ) );
sahaj_ok( 'never offering a line that could not be served anyway', false === strpos( $sahaj_discovery['detail'], 'Sitemap: ' ) );

update_option( 'permalink_structure', '/%postname%/' );

// A site that asked not to be indexed gets no argument about it. `sahaj_atlas_robots_txt()`
// honours that switch, so the row reports the same answer rather than a second opinion.
update_option( 'blog_public', '0' );

sahaj_is( 'a site that asks not to be indexed is not a warning', 'idle', sahaj_atlas_check_sitemap_discovery()['status'] );

update_option( 'blog_public', '1' );

// No key means there is no sitemap to announce, and checks 1 and 2 already name why.
update_option( SAHAJ_ATLAS_OPTION_KEY, '' );

sahaj_is( 'and nothing at all until there is a key', 'idle', sahaj_atlas_check_sitemap_discovery()['status'] );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The published address follows the permalink shape' );

/**
 * Set the permalink structure the way a real request has it.
 *
 * ⚠ `update_option()` alone is not enough. `$wp_rewrite->permalink_structure` is read once during
 * setup and never re-read, so `using_index_permalinks()` keeps answering for the structure the
 * instance booted with — and every assertion below would pass against the wrong shape. A real
 * request and the Permalinks screen both reach `WP_Rewrite::init()`, so a fixture has to as well.
 *
 * @param string $structure A permalink structure, or '' for plain.
 */
function sahaj_set_permalink_structure( $structure ) {
	global $wp_rewrite;

	update_option( 'permalink_structure', $structure );
	$wp_rewrite->init();
}

// A non-empty set, so every refusal below is the permalink shape and never the empty-set guard.
set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, $sahaj_rows, MINUTE_IN_SECONDS );

sahaj_set_permalink_structure( '/%postname%/' );

sahaj_is( 'mod_rewrite permalinks publish the bare path', home_url( '/' . SAHAJ_ATLAS_SITEMAP_PATH ), sahaj_atlas_sitemap_url() );

/*
 * ⚠ `/index.php/%postname%/` is what WordPress offers when mod_rewrite is unavailable. The bare
 * path reaches the filesystem there and 404s; only the prefixed one reaches `parse_request`. Every
 * address the plugin publishes comes from one composer, so all four follow it at once.
 */
sahaj_set_permalink_structure( '/index.php/%postname%/' );

$sahaj_prefixed = 'index.php/' . SAHAJ_ATLAS_SITEMAP_PATH;

sahaj_is( 'an index.php site publishes the prefixed path', home_url( '/' . $sahaj_prefixed ), sahaj_atlas_sitemap_url() );
sahaj_ok( 'robots.txt announces that address', false !== strpos( sahaj_atlas_robots_txt( '', true ), $sahaj_prefixed ) );
sahaj_ok( 'the SEO-plugin index entry carries it too', false !== strpos( sahaj_atlas_yoast_sitemap_index( '' ), $sahaj_prefixed ) );
sahaj_ok(
	'and the panel row names it instead of the bare path',
	false !== strpos( sahaj_atlas_check_sitemap_discovery()['detail'], '<code>/' . $sahaj_prefixed . '</code>' )
);

/*
 * ⚠ The serve guard needs no branch for this shape, which is the whole reason one line fixes it.
 * WordPress strips its index file before setting `$wp->request`, so the handler compares
 * `sahaj-atlas-sitemap.xml` on both shapes. Measured against a real request for
 * `/index.php/sahaj-atlas-sitemap.xml`: this handler answered it.
 */
$sahaj_serve_wp          = new WP();
$sahaj_serve_wp->request = SAHAJ_ATLAS_SITEMAP_PATH;

ob_start();
$sahaj_served = sahaj_atlas_maybe_serve_sitemap( $sahaj_serve_wp );
$sahaj_serve_body = (string) ob_get_clean();

sahaj_ok( 'and the request for it is still served', $sahaj_served );
sahaj_ok( 'with the sitemap document', false !== strpos( $sahaj_serve_body, '<urlset' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Clean URLs are not what breaks on an index.php site' );

/*
 * ⚠ The row reporting `ok` here looks like the wrong claim and is not. Path routing genuinely
 * works on this shape: `get_page_uri()` has no index file in it and `WP::parse_request()` strips
 * the one in the request, so the two agree. Reaching for `using_mod_rewrite_permalinks()` in this
 * row, or in `sahaj_atlas_path_routing_viable()`, would switch a working feature off and send a
 * volunteer to a settings screen they have no reason to visit.
 */
sahaj_set_permalink_structure( '/index.php/%postname%/' );

sahaj_ok( 'the registered mount carries the index.php segment', false !== strpos( sahaj_atlas_mount_key(), '/index.php/' ) );
sahaj_is( 'a deep link still resolves', '/gb/london', sahaj_route_for( 'find-a-class/gb/london' ) );
sahaj_is(
	'and the Clean URLs row reports it on',
	'ok',
	sahaj_atlas_check_path_routing(
		array( 'canonical' => array( 'enabled' => true, 'embed' => sahaj_atlas_mount_key() ) )
	)['status']
);

// Leave the suite on the shape the files after this one were written against.
sahaj_set_permalink_structure( '/%postname%/' );
set_transient( SAHAJ_ATLAS_SITEMAP_TRANSIENT, array(), MINUTE_IN_SECONDS );
