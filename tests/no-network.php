<?php
/**
 * Refuses every outbound HTTP request no lane stubbed, and records the ones it refused.
 *
 * ⚠ Every lane used to reach production SahajCloud on every run, from CI runners and from
 * developer machines, with a fake key that filed a Sentry event there each time (#28). A stub one
 * file installs cannot prevent that, because the next lane to add a fetch escapes again. So the
 * refusal is the default here and a stub is the exception.
 *
 * ⚠ Loaded as an mu-plugin by each lane's blueprint, which is what makes the origin below stick:
 * mu-plugins run before regular plugins, so this `define()` wins the `defined()` guard in
 * `sahaj-atlas.php`. Requiring it any later would leave the production origin compiled in.
 *
 * @package SahajAtlas
 */

/** Where a refused request is recorded. Both `tests/run.php` and `tests/render.mjs` read it. */
define( 'SAHAJ_ATLAS_NETWORK_LOG', '/wordpress/wp-content/plugins/sahaj-atlas/.test-network.txt' );

// ⚠ Create it empty, so a reader can tell "nothing was refused" from "this file never loaded".
// `FILE_APPEND` is what makes that safe to repeat: a render lane loads this on every request, and
// truncating would drop what the earlier ones recorded.
file_put_contents( SAHAJ_ATLAS_NETWORK_LOG, '', FILE_APPEND );

// `.invalid` is reserved by RFC 2606 and resolves nowhere, so a path that slips past the filter
// below still cannot reach a real host.
defined( 'SAHAJ_ATLAS_API_ORIGIN' ) || define( 'SAHAJ_ATLAS_API_ORIGIN', 'https://api.sahaj-atlas.invalid' );

/*
 * ⚠ Cron spawns a loopback request to `wp-cron.php`, and the job that answers it asks
 * api.wordpress.org for updates. That is a second way out of the instance, and a page load is
 * enough to trigger it.
 */
defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );

/**
 * @param false|array|WP_Error $pre  What an earlier filter answered with, or `false` for nothing.
 * @param array                $args Request arguments.
 * @param string               $url  The URL requested.
 * @return array|WP_Error
 */
function sahaj_atlas_test_refuse_http( $pre, $args, $url ) {
	// A lane that stubbed this request has already answered it. Only an unstubbed call is a leak.
	if ( false !== $pre ) {
		return $pre;
	}

	file_put_contents( SAHAJ_ATLAS_NETWORK_LOG, $url . "\n", FILE_APPEND );

	return new WP_Error( 'sahaj_atlas_test_network_blocked', "Refused an outbound request to $url." );
}

// ⚠ Last in the chain, never first. `WP_Http::request()` passes each filter's return value to the
// next one, so a refusal at priority 10 would be overwritten by a lane's own stub at the same
// priority — and recorded as a leak the lane had in fact covered.
add_filter( 'pre_http_request', 'sahaj_atlas_test_refuse_http', PHP_INT_MAX, 3 );
