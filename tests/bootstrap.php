<?php
/**
 * Boot WordPress, activate the plugin, run the suite — and get the output back out.
 *
 * ⚠ **`run-blueprint`'s `runPHP` step discards stdout.** Verified: a blueprint whose entire body is
 * `echo 'HELLO';` prints nothing, and a fatal surfaces only as `exit code 255` with both stdout and
 * stderr empty. So everything is buffered here and written into the mounted plugin directory, which
 * IS bidirectional, and `tests/report.php` reads it back on the host. Without this the suite is
 * unreadable whether it passes or fails, and a fatal during plugin load is indistinguishable from a
 * fatal in an assertion.
 *
 * @package SahajAtlas
 */

define( 'SAHAJ_ATLAS_TEST_OUT', '/wordpress/wp-content/plugins/sahaj-atlas/.test-output.txt' );

ini_set( 'display_errors', '1' );
ini_set( 'error_reporting', (string) E_ALL );

@unlink( SAHAJ_ATLAS_TEST_OUT );

ob_start();

// A fatal never reaches the end of this file, so the flush has to hang off shutdown.
register_shutdown_function(
	function () {
		$output = ob_get_level() ? (string) ob_get_clean() : '';
		$fatal  = error_get_last();

		if ( $fatal && in_array( $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			$output .= "\nFATAL: {$fatal['message']}\n   at {$fatal['file']}:{$fatal['line']}\n";
		}

		file_put_contents( SAHAJ_ATLAS_TEST_OUT, $output );
	}
);

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

/*
 * ⚠ The plugin is activated by the blueprint's own `activatePlugin` step, NOT here. Calling
 * `activate_plugin()` after `wp-load.php` loads the plugin's file when `init` has already fired,
 * so `sahaj_atlas_init()` never runs and every registration — the shortcode, the block, the
 * settings — is silently absent. The suite then tests a half-loaded plugin. Activating in an
 * earlier step means this request boots it the way a real page load does.
 */
if ( ! is_plugin_active( 'sahaj-atlas/sahaj-atlas.php' ) ) {
	echo "The plugin is not active — the blueprint's activatePlugin step did not run.\n";
	exit( 1 );
}

require __DIR__ . '/run.php';
