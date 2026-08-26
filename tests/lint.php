<?php
/**
 * Syntax check every PHP file in the plugin.
 *
 * `@wp-playground/cli` runs PHP in WebAssembly and exposes no `php -l`, so this is the equivalent:
 * `token_get_all()` with `TOKEN_PARSE` runs the real parser and throws `ParseError` on invalid
 * syntax, without executing a line.
 *
 * ⚠ An earlier version wrapped each file in `if (false) { ?> … <?php }` and eval'd it. That passed
 * everything, including files with deliberate syntax errors, because the wrapper left the body in
 * HTML mode where it is inert text. Verify this tool still fails on a broken file before trusting a
 * green run — `tests/lint-self-test.php` does exactly that.
 *
 *   npm run lint
 */
$root = '/plugin';
$skip = array( '/tests/', '/vendor/', '/node_modules/' );

$failed  = 0;
$checked = 0;

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

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

	++$checked;
	$short = str_replace( $root . '/', '', $path );

	try {
		token_get_all( file_get_contents( $path ), TOKEN_PARSE );
		echo "ok    $short\n";
	} catch ( ParseError $e ) {
		++$failed;
		echo "FAIL  $short — {$e->getMessage()} (line {$e->getLine()})\n";
	}
}

echo "\n$checked file(s) checked, $failed with syntax errors\n";
exit( $failed > 0 ? 1 : 0 );
