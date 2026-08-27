<?php
/**
 * The `allowedDomains` check, against SahajCloud's actual rules.
 *
 * ⚠ Every case here is one the first version of this check got WRONG, because it was written from a
 * design note rather than from `src/plugins/usage/originEnforcement.ts`. The panel is what a
 * volunteer trusts instead of emailing us, so a confident wrong red is worse than no check at all.
 *
 * @package SahajAtlas
 */

sahaj_group( 'allowedDomains is newline-separated, not comma-separated' );

// The field is a textarea and this is the real shape — client 32 in production is
// `sahajayoga.fr\nyogaessonne.fr`. Splitting on commas alone read that as one impossible domain
// and told a working site it was unregistered.
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
 * ⚠ This is the documented backward-compatible default upstream, and the check used to claim the
 * exact opposite — a red "the server will refuse every page, ask the maintainers to add this site"
 * for a configuration that works. The programme notes proposed inverting it; that was a proposal,
 * never implemented, and this check was written against it.
 */
sahaj_is( 'an empty value yields no patterns', array(), sahaj_atlas_parse_allowed_domains( '' ) );
sahaj_is( 'and so does whitespace', array(), sahaj_atlas_parse_allowed_domains( "  \n " ) );

$sahaj_open = sahaj_atlas_check_allowed_domains( array( 'allowedDomains' => '' ) );

sahaj_is( 'so the panel reports it as fine, not as broken', 'ok', $sahaj_open['status'] );

sahaj_group( 'Wildcards match subdomains only; a bare host matches itself only' );

$sahaj_apex = array( 'example.org' );
$sahaj_star = array( '*.example.org' );

sahaj_ok( 'a bare host matches itself', sahaj_atlas_is_host_allowed( 'example.org', $sahaj_apex ) );

// ⚠ The old check suffix-matched EVERY entry, so a bare apex entry silently admitted every
// subdomain — it treated `example.org` as if the operator had written `*.example.org`, which is a
// different and broader permission than the one they granted. (It did dot-prefix the suffix, so
// the lookalike below was never open; only the widening was real. Both are asserted, because the
// next rewrite of this matcher will not know which half was the bug.)
sahaj_ok( 'but not a subdomain of it', ! sahaj_atlas_is_host_allowed( 'sub.example.org', $sahaj_apex ) );
sahaj_ok( 'and not a lookalike', ! sahaj_atlas_is_host_allowed( 'evil-example.org', $sahaj_apex ) );

sahaj_ok( 'a wildcard matches a subdomain', sahaj_atlas_is_host_allowed( 'sub.example.org', $sahaj_star ) );
sahaj_ok( 'and a deeper one', sahaj_atlas_is_host_allowed( 'a.b.example.org', $sahaj_star ) );

// The leading dot in the suffix is what makes this hold; without it `evil-example.org` ends with
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
