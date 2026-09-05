<?php
/**
 * Prints the suite's captured output on the host, and exits with its status.
 *
 * `pnpm test` runs this file immediately after the blueprint. `run-blueprint` discards the
 * guest's stdout — see `tests/bootstrap.php`.
 *
 * @package SahajAtlas
 */

$file = __DIR__ . '/../.test-output.txt';

if ( ! file_exists( $file ) ) {
	echo "No test output — the WordPress run produced nothing at all.\n";
	exit( 1 );
}

$output = (string) file_get_contents( $file );

unlink( $file );

echo $output;

exit( preg_match( '/^0 failure\(s\)$|, 0 failure\(s\)/m', $output ) ? 0 : 1 );
