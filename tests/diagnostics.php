<?php
/**
 * Checks that every diagnostics row says its verdict, instead of only drawing it.
 *
 * ⚠ A screen reader at its default punctuation level announces neither `!` nor `–`, so a warn row
 * and an idle row read as a label and a detail with no verdict at all (#89).
 *
 * ⚠ Fixture pre-mortem. Both transients below are seeded in the shape their readers decode: an
 * `error` key is what `sahaj_atlas_client_record()` and `sahaj_atlas_page_probe()` turn back into
 * a `WP_Error`. Read from `includes/diagnostics.php`, not from a sample answer. The seeding is
 * also what keeps this file off the network — both readers return before they fetch.
 *
 * @package SahajAtlas
 */

sahaj_group( 'Every diagnostics row names its verdict' );

set_transient( sahaj_atlas_client_slot( sahaj_atlas_api_key() ), array( 'error' => 'Key refused' ), SAHAJ_ATLAS_CHECK_TTL );
set_transient( SAHAJ_ATLAS_PROBE_TRANSIENT, array( 'error' => 'HTTP 500' ), SAHAJ_ATLAS_CHECK_TTL );

$sahaj_checks = sahaj_atlas_checks();

ob_start();
sahaj_atlas_render_diagnostics();
preg_match_all( '#<tr><td[^>]*>(.*?)</td>#', (string) ob_get_clean(), $sahaj_rows, PREG_SET_ORDER );

// First: a regex that matched nothing would pass everything below against an empty array.
sahaj_is( 'every check renders one row', count( $sahaj_checks ), count( $sahaj_rows ) );

/** Each status's verdict cell. One `printf` per check, in order, so the two lists line up. */
$sahaj_cells = array();

foreach ( $sahaj_checks as $sahaj_i => $sahaj_check ) {
	$sahaj_cells[ $sahaj_check['status'] ] = isset( $sahaj_rows[ $sahaj_i ] ) ? $sahaj_rows[ $sahaj_i ][1] : '';
}

$sahaj_covered = array_keys( $sahaj_cells );
sort( $sahaj_covered );

// Keyed by status, never by label, so a check that changes its verdict does not fail this file —
// but one render has to keep reaching all four statuses, or the assertions below go untested.
sahaj_is( 'and between them reach all four statuses', array( 'fail', 'idle', 'ok', 'warn' ), $sahaj_covered );

foreach ( array(
	'ok'   => '<span aria-hidden="true">✓</span><span class="screen-reader-text">Passed</span>',
	'warn' => '<span aria-hidden="true">!</span><span class="screen-reader-text">Needs attention</span>',
	'fail' => '<span aria-hidden="true">✗</span><span class="screen-reader-text">Failed</span>',
	'idle' => '<span aria-hidden="true">–</span><span class="screen-reader-text">Not checked yet</span>',
) as $sahaj_status => $sahaj_expected ) {
	// The whole cell, so a row losing the glyph, its `aria-hidden` or the word all fail here.
	sahaj_is(
		"the $sahaj_status row keeps the glyph, hides it, and names the verdict",
		$sahaj_expected,
		isset( $sahaj_cells[ $sahaj_status ] ) ? $sahaj_cells[ $sahaj_status ] : ''
	);
}

delete_transient( sahaj_atlas_client_slot( sahaj_atlas_api_key() ) );
delete_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );
