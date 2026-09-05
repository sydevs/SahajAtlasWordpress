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

sahaj_atlas_flush_sitemap_cache();

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The sitemap path is not an atlas route' );

update_option( 'permalink_structure', '/%postname%/' );
sahaj_mount_atlas_page_at( 'find-a-class' );

sahaj_is(
	'a request for the sitemap is never claimed as a route',
	null,
	sahaj_route_for( SAHAJ_ATLAS_SITEMAP_PATH )
);
