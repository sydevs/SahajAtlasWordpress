<?php
/**
 * Renders the `builder-host` page the way a page builder does: the builder's own markup in place of
 * `post_content`, its shortcodes included.
 *
 * ⚠ The shortcode lives here and never in the page's `post_content`, and that is the whole fixture.
 * Beaver Builder keeps a text module's shortcode in post meta and swaps it in on `the_content`, so
 * the resolve on `template_redirect` scans the page and finds nothing — the page a Beaver Builder
 * site's editor was told "another atlas came first" on, with no other atlas on it.
 *
 * Priority 9, ahead of `do_shortcode()` at 11, as a builder's own filter runs.
 *
 * Only the render lane writes this as an mu-plugin.
 *
 * @package SahajAtlas
 */

add_filter( 'the_content', 'sahaj_fixture_builder_content', 9 );

/**
 * @param string $content The post content.
 * @return string
 */
function sahaj_fixture_builder_content( $content ) {
	if ( ! is_page( 'builder-host' ) ) {
		return $content;
	}

	return '<p>Built.</p>[sahaj_atlas atlas="/gb/london/1204"]';
}
