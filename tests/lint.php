<?php
/**
 * Runs a syntax check on every PHP file in the plugin.
 *
 * `@wp-playground/cli` runs PHP in WebAssembly and exposes no `php -l`. This file is the
 * equivalent. `token_get_all()` with `TOKEN_PARSE` runs the real parser. It throws a
 * `ParseError` on invalid syntax, without executing a single line.
 *
 * ⚠ An earlier version wrapped each file in `if (false) { ?> … <?php }` and used `eval()`. That
 * passed every file, including files with real syntax errors, because the wrapper left the file
 * body in HTML mode, where PHP treats it as inert text. Before you trust a green run, break one
 * file on purpose and confirm this tool then fails.
 *
 *   pnpm lint
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
