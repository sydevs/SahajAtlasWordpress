<?php
/**
 * Checks that every diagnostics row says its verdict, instead of only drawing it.
 *
 * ⚠ A screen reader at its default punctuation level announces neither `!` nor `–`, so a warn row
 * and an idle row read as a label and a detail with no verdict at all (#89).
 *
 * ⚠ Fixture pre-mortem. Both transients below are seeded in the shape their readers decode:
 * `sahaj_atlas_client_record()` and `sahaj_atlas_page_probe()` each turn an `error` key back into
 * a `WP_Error`, and cache nothing else. Read from `includes/diagnostics.php`, not from a sample
 * answer. Seeding them is also what keeps this file off the network: both readers return before
 * they fetch.
 *
 * @package SahajAtlas
 */

/**
 * The verdict cell of each panel row, keyed by the row's label.
 *
 * @return array<string, string>
 */
function sahaj_diagnostics_cells() {
	ob_start();
	sahaj_atlas_render_diagnostics();
	$panel = (string) ob_get_clean();

	preg_match_all( '#<tr><td[^>]*>(.*?)</td><td[^>]*><strong>(.*?)</strong></td>#', $panel, $rows, PREG_SET_ORDER );

	$cells = array();

	foreach ( $rows as $row ) {
		$cells[ $row[2] ] = $row[1];
	}

	return $cells;
}

sahaj_group( 'Every diagnostics row names its verdict' );

set_transient( sahaj_atlas_client_slot( sahaj_atlas_api_key() ), array( 'error' => 'Key refused' ), SAHAJ_ATLAS_CHECK_TTL );
set_transient( SAHAJ_ATLAS_PROBE_TRANSIENT, array( 'error' => 'HTTP 500' ), SAHAJ_ATLAS_CHECK_TTL );

$sahaj_cells = sahaj_diagnostics_cells();

sahaj_is( 'every check still renders one row', 8, count( $sahaj_cells ) );

// Both halves in one assertion: a cell that loses either the glyph or the word fails here, and the
// expected string is what the panel should look like to a sighted reader and to a screen reader.
sahaj_is(
	'a warn row keeps the glyph, hides it, and names the verdict',
	'<span aria-hidden="true">!</span><span class="screen-reader-text">Needs attention</span>',
	isset( $sahaj_cells['Map placement'] ) ? $sahaj_cells['Map placement'] : ''
);

sahaj_is(
	'and an idle row does the same',
	'<span aria-hidden="true">–</span><span class="screen-reader-text">Not checked yet</span>',
	isset( $sahaj_cells['This domain'] ) ? $sahaj_cells['This domain'] : ''
);

// The two rows above are the ones a reader loses entirely. This catches a status whose word nobody
// added, which would otherwise only show up on a site in that state.
sahaj_is(
	'and so does every other row',
	count( $sahaj_cells ),
	count( preg_grep( '#<span class="screen-reader-text">\w#', $sahaj_cells ) )
);

delete_transient( sahaj_atlas_client_slot( sahaj_atlas_api_key() ) );
delete_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );
