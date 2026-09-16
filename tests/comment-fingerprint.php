<?php
/**
 * Prove a comment sweep changed comments and nothing else, for the PHP half.
 *
 * `comment-fingerprint.mjs` in `sydevs/claude-workflow` does this for the
 * TypeScript repos by going through the TypeScript compiler. PHP needs its own
 * lexer, and `token_get_all()` is it. The node tool shells out to this file for
 * `.php`, exactly as `pnpm lint` shells out to `tests/lint.php`.
 *
 * ⚠ This repo has no formatter, no phpcs and no ESLint. `pnpm lint` is a syntax
 * check. A comment edit that swallows a `?>` or breaks out of a heredoc produces
 * VALID PHP that behaves differently, and nothing else here would catch it. This
 * token stream is the only guard.
 *
 * ## What it does NOT prove
 *
 * The hash proves no code changed. It does not prove a surviving comment is
 * still true, and it cannot see a `translators:` comment that stayed in the file
 * but drifted away from its gettext call — so that adjacency is checked
 * separately below, and reported per file.
 *
 * ## Why a real lexer, not a regex
 *
 * `#` opens a comment, but `#[Attribute]` is PHP 8 attribute syntax, and a
 * regex that treats both alike deletes attributes silently. `?>` ends a `//`
 * comment. Heredoc and nowdoc bodies, `$var` interpolation inside double
 * quotes, and backtick shell-exec all contain characters that look like comment
 * syntax and are not. `T_INLINE_HTML` is output, so it is byte-significant and
 * is emitted verbatim.
 *
 *   wp-playground-cli php --mount .:/plugin -- /plugin/tests/comment-fingerprint.php
 *
 * Prints one TAB-separated line per file: path, hash, frozen count, warn count.
 * Exits 1 when a translators: comment lost its call.
 */

$root = getenv( 'FINGERPRINT_ROOT' ) ?: '/plugin';
$skip = array( '/tests/', '/vendor/', '/node_modules/', '/build/' );

/** Gettext calls whose preceding `translators:` comment WP-CLI extracts into the .pot. */
$gettext = '/\b(__|_e|_n|_x|_ex|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(/';

/** Comments that change what a tool does, or that WordPress core parses. Never deleted. */
$frozen = array(
	'/@package\s+SahajAtlas/',
	'/phpcs:(disable|enable|ignore|ignoreFile)/',
	'/@codingStandardsIgnore/',
	'/^\s*\*?\s*(Plugin Name|Plugin URI|Version|Requires at least|Requires PHP|Text Domain|Update URI|License)\s*:/m',
	'/\b(Copyright|SPDX-License-Identifier)\b/',
	'/translators\s*:/',
);

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$rows  = array();
$orphaned = 0;

foreach ( $files as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}

	$path = $file->getPathname();

	foreach ( $skip as $fragment ) {
		if ( false !== strpos( $path, $fragment ) ) {
			continue 2;
		}
	}

	$short  = str_replace( $root . '/', '', $path );
	$source = file_get_contents( $path );

	try {
		$tokens = token_get_all( $source, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		echo "PARSE\t$short\t{$e->getMessage()}\n";
		++$orphaned;
		continue;
	}

	$stream       = '';
	$frozen_count = 0;
	$warn_count   = 0;

	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) ) {
			$stream .= $token . "\x1f";
			continue;
		}

		list( $id, $text ) = $token;

		if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			foreach ( $frozen as $pattern ) {
				if ( preg_match( $pattern, $text ) ) {
					++$frozen_count;
					break;
				}
			}

			if ( false !== strpos( $text, "\xe2\x9a\xa0" ) ) {
				++$warn_count;
			}

			// A translators: comment only reaches the .pot while it still sits
			// immediately before its gettext call. Anything inserted between the
			// two drops the context for all 34 locales, with nothing to notice.
			if ( preg_match( '/^\s*\/[\/*]\s*translators\s*:/', $text ) ) {
				// Scan only as far as the first statement terminator. WP-CLI wants the
				// comment IMMEDIATELY before the call, so anything with its own `;` in
				// between has already broken the link — a character-count window is too
				// loose to see that, and let a wedged-in statement pass.
				$rest = '';
				for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
					$piece = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
					if ( ';' === $piece ) {
						break;
					}
					$rest .= $piece;
				}
				if ( ! preg_match( $gettext, $rest ) ) {
					echo "ORPHANED\t$short\ttranslators: comment has no gettext call after it\n";
					++$orphaned;
				}
			}
			continue;
		}

		if ( T_WHITESPACE === $id ) {
			continue;
		}

		// Output, not code. Byte-significant, so it goes in verbatim.
		$stream .= $id . ':' . $text . "\x1f";
	}

	$rows[] = sprintf( "%s\t%s\t%d\t%d", $short, hash( 'sha256', $stream ), $frozen_count, $warn_count );
}

sort( $rows );
foreach ( $rows as $row ) {
	echo $row . "\n";
}

echo sprintf( "\n%d file(s), %d orphaned translators comment(s)\n", count( $rows ), $orphaned );
exit( $orphaned > 0 ? 1 : 0 );
