<?php
/**
 * Boots WordPress. Activates the plugin. Runs the suite. Recovers the output.
 *
 * ⚠ The `run-blueprint` `runPHP` step discards stdout. A test blueprint with only
 * `echo 'HELLO';` prints nothing. A fatal error shows only as `exit code 255`, with stdout and
 * stderr both empty. So this file buffers all output and writes it into the mounted plugin
 * directory instead. That mount is bidirectional. `tests/report.php` reads the file back on the
 * host. Without this step, the suite output is unreadable, pass or fail. A fatal during plugin
 * load looks the same as a fatal inside an assertion.
 *
 * @package SahajAtlas
 */

define( 'SAHAJ_ATLAS_TEST_OUT', '/wordpress/wp-content/plugins/sahaj-atlas/.test-output.txt' );

ini_set( 'display_errors', '1' );
ini_set( 'error_reporting', (string) E_ALL );

@unlink( SAHAJ_ATLAS_TEST_OUT );

ob_start();

// A fatal error never reaches the end of this file. The shutdown handler flushes output instead.
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
 * ⚠ The blueprint's own `activatePlugin` step activates the plugin, not this file. By the time
 * `wp-load.php` finishes, WordPress has already fired the `init` hook. A call to
 * `activate_plugin()` after that point comes too late. `sahaj_atlas_init()` never runs, and the
 * shortcode, block, and settings registration all stay silently absent. The suite then tests a
 * half-loaded plugin. Activation in an earlier blueprint step boots the plugin the way a real
 * page load does.
 */
if ( ! is_plugin_active( 'sahaj-atlas/sahaj-atlas.php' ) ) {
	echo "The plugin is not active — the blueprint's activatePlugin step did not run.\n";
	exit( 1 );
}

require __DIR__ . '/run.php';
