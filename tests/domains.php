<?php
/**
 * Checks `allowedDomains` against SahajCloud's actual rules.
 *
 * ⚠ The first version of this check got every case here wrong. It was written from a design
 * note, not from `src/plugins/usage/originEnforcement.ts`. A volunteer trusts this panel instead
 * of emailing us. A confident wrong red result is worse than no check at all.
 *
 * @package SahajAtlas
 */

sahaj_group( 'allowedDomains is newline-separated, not comma-separated' );

// The field is a textarea, so this is its real shape. Client 32 in production holds
// `sahajayoga.fr\nyogaessonne.fr`. Splitting on commas alone read that as one impossible domain.
// It told a working site that it was unregistered.
sahaj_is(
	'a two-line list parses as two domains',
	array( 'sahajayoga.fr', 'yogaessonne.fr' ),
	sahaj_atlas_parse_allowed_domains( "sahajayoga.fr\nyogaessonne.fr" )
);
sahaj_is(
	'carriage returns too',
	array( 'a.example', 'b.example' ),
	sahaj_atlas_parse_allowed_domains( "a.example\r\nb.example" )
);
sahaj_is(
	'and commas are still tolerated, as upstream tolerates them',
	array( 'a.example', 'b.example' ),
	sahaj_atlas_parse_allowed_domains( 'a.example, b.example' )
);
sahaj_is( 'blank lines are dropped', array( 'a.example' ), sahaj_atlas_parse_allowed_domains( "\n\na.example\n\n" ) );

sahaj_group( 'Entries are normalized the way the server normalizes them' );

sahaj_is( 'a URL becomes its host', 'example.org', sahaj_atlas_normalize_host( 'https://example.org/some/path' ) );
sahaj_is( 'a port is stripped', 'example.org', sahaj_atlas_normalize_host( 'example.org:8443' ) );
sahaj_is( 'case is folded', 'example.org', sahaj_atlas_normalize_host( 'Example.ORG' ) );
sahaj_is( 'a trailing dot is dropped', 'example.org', sahaj_atlas_normalize_host( 'example.org.' ) );
sahaj_is( 'a leading wildcard label is kept', '*.example.org', sahaj_atlas_normalize_host( '*.example.org' ) );
sahaj_is( 'a malformed wildcard is refused', '', sahaj_atlas_normalize_host( 'a.*.org' ) );
sahaj_is( 'and a bare star is nothing', '', sahaj_atlas_normalize_host( '*' ) );

sahaj_group( 'An EMPTY list allows every origin' );

/*
 * ⚠ An empty list is the documented, backward-compatible default upstream. An earlier version of
 * this check claimed the exact opposite: a red "the server will refuse every page, ask the
 * maintainers to add this site" message for a configuration that actually works. Some programme
 * notes had proposed inverting the default. That proposal was never implemented, but this check
 * was written against it anyway.
 */
sahaj_is( 'an empty value yields no patterns', array(), sahaj_atlas_parse_allowed_domains( '' ) );
sahaj_is( 'and so does whitespace', array(), sahaj_atlas_parse_allowed_domains( "  \n " ) );

$sahaj_open = sahaj_atlas_check_allowed_domains( array( 'allowedDomains' => '' ) );

sahaj_is( 'so the panel reports it as fine, not as broken', 'ok', $sahaj_open['status'] );

sahaj_group( 'Wildcards match subdomains only; a bare host matches itself only' );

$sahaj_apex = array( 'example.org' );
$sahaj_star = array( '*.example.org' );

sahaj_ok( 'a bare host matches itself', sahaj_atlas_is_host_allowed( 'example.org', $sahaj_apex ) );

// ⚠ The old check suffix-matched every entry. A bare apex entry then silently admitted every
// subdomain, as if the operator had written `*.example.org` instead of `example.org` — a wider
// permission than they granted. The old check did dot-prefix the suffix, so the lookalike below
// was never open. Only the widening was a real bug. Both cases are asserted here, because the
// next rewrite of this matcher will not know which half was the bug.
sahaj_ok( 'but not a subdomain of it', ! sahaj_atlas_is_host_allowed( 'sub.example.org', $sahaj_apex ) );
sahaj_ok( 'and not a lookalike', ! sahaj_atlas_is_host_allowed( 'evil-example.org', $sahaj_apex ) );

sahaj_ok( 'a wildcard matches a subdomain', sahaj_atlas_is_host_allowed( 'sub.example.org', $sahaj_star ) );
sahaj_ok( 'and a deeper one', sahaj_atlas_is_host_allowed( 'a.b.example.org', $sahaj_star ) );

// The leading dot in the suffix makes this hold. Without it, `evil-example.org` ends with
// `example.org` and passes.
sahaj_ok( 'but never a lookalike', ! sahaj_atlas_is_host_allowed( 'evil-example.org', $sahaj_star ) );
sahaj_ok( 'and not the apex itself', ! sahaj_atlas_is_host_allowed( 'example.org', $sahaj_star ) );
sahaj_ok( 'an unknown host is refused', ! sahaj_atlas_is_host_allowed( 'elsewhere.example', $sahaj_apex ) );
sahaj_ok( 'and so is nothing at all', ! sahaj_atlas_is_host_allowed( '', $sahaj_apex ) );

sahaj_group( 'The check reads this site against the list' );

$sahaj_here = (string) wp_parse_url( home_url(), PHP_URL_HOST );

$sahaj_hit = sahaj_atlas_check_allowed_domains( array( 'allowedDomains' => "elsewhere.example\n$sahaj_here" ) );

sahaj_is( 'a listed host passes', 'ok', $sahaj_hit['status'] );

$sahaj_miss = sahaj_atlas_check_allowed_domains( array( 'allowedDomains' => 'elsewhere.example' ) );

sahaj_is( 'an unlisted one fails', 'fail', $sahaj_miss['status'] );
sahaj_ok( 'and the message names this site', false !== strpos( $sahaj_miss['detail'], $sahaj_here ) );
