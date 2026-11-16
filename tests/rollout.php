<?php
/**
 * Staged rollout: which GitHub release a site is offered, and when.
 *
 * ⚠ The cases run through PUC's own `GitHubApi::getLatestRelease()` against a stubbed releases
 * list, with the configuration `sahaj_atlas_configure_release_api()` gives production. A test of
 * `sahaj_atlas_release_is_offered()` alone would stay green if PUC stopped calling the filter.
 *
 * Required from `tests/run.php`, which supplies `sahaj_is()` and `sahaj_ok()`.
 *
 * @package SahajAtlas
 */

sahaj_group( 'Staged rollout holds a release for a site that is not a canary' );

/**
 * A GitHub release, as the releases API lists one.
 *
 * @param string      $tag       Tag.
 * @param int|null    $age       Seconds since it was published, or null for no date.
 * @param string|null $body      Release notes.
 * @return object
 */
function sahaj_rollout_release( $tag, $age, $body = 'See readme.txt for the changelog.' ) {
	$release = (object) array(
		'tag_name'    => $tag,
		'draft'       => false,
		'prerelease'  => false,
		'zipball_url' => 'https://example.invalid/zip',
		'created_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() - 90 * DAY_IN_SECONDS ),
		'body'        => $body,
		'assets'      => array(
			(object) array(
				'name'                 => 'sahaj-atlas-' . ltrim( $tag, 'v' ) . '.zip',
				'url'                  => 'https://example.invalid/api',
				'browser_download_url' => 'https://example.invalid/' . $tag . '.zip',
				'download_count'       => 1,
			),
		),
	);

	if ( null !== $age ) {
		$release->published_at = is_int( $age ) ? gmdate( 'Y-m-d\TH:i:s\Z', time() - $age ) : $age;
	}

	return $release;
}

$GLOBALS['sahaj_rollout_releases'] = array();

/**
 * @param false|array|WP_Error $pre  Earlier answer.
 * @param array                $args Request arguments.
 * @param string               $url  URL.
 * @return false|array
 */
function sahaj_rollout_http_stub( $pre, $args, $url ) {
	if ( false === strpos( $url, 'api.github.com/repos/sydevs/SahajAtlasWordpress/releases' ) ) {
		return $pre;
	}

	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $GLOBALS['sahaj_rollout_releases'] ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter( 'pre_http_request', 'sahaj_rollout_http_stub', 10, 3 );

/**
 * The version a site would be offered, given the releases GitHub lists newest first.
 *
 * @param array $releases Releases.
 * @return string|null
 */
function sahaj_rollout_offered( $releases ) {
	$GLOBALS['sahaj_rollout_releases'] = $releases;

	$class = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::getLatestClassVersion( 'GitHubApi' );
	$api   = new $class( 'https://github.com/sydevs/SahajAtlasWordpress/' );
	sahaj_atlas_configure_release_api( $api );

	$reference = $api->getLatestRelease();

	return $reference ? $reference->version : null;
}

$sahaj_day    = DAY_IN_SECONDS;
$sahaj_fresh  = array( sahaj_rollout_release( 'v0.3.5', $sahaj_day ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) );
$sahaj_canary = false;

add_filter(
	'sahaj_atlas_is_canary',
	function () use ( &$sahaj_canary ) {
		return $sahaj_canary;
	}
);

sahaj_is( 'the hold defaults to three days', 3 * DAY_IN_SECONDS, sahaj_atlas_update_hold_seconds() );
sahaj_is( 'a site is not a canary by default', false, sahaj_atlas_is_canary() );

sahaj_is( 'a site waits: a day-old release is not offered, the one before it is', '0.3.4', sahaj_rollout_offered( $sahaj_fresh ) );

$sahaj_canary = true;
sahaj_is( 'a canary is offered a release the day it is published', '0.3.5', sahaj_rollout_offered( $sahaj_fresh ) );
$sahaj_canary = false;

sahaj_is(
	'a release past the hold is offered',
	'0.3.5',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', 4 * $sahaj_day ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'a release with no published date is offered',
	'0.3.5',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', null ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'a release whose date does not parse is offered',
	'0.3.5',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', 'not a date' ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'a release dated far in the future is offered',
	'0.3.5',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', -30 * $sahaj_day ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'[skip-hold] on a line of its own in the notes offers it at once',
	'0.3.5',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', 60, "Security fix.\r\n\r\n[skip-hold]\r\n" ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'the marker quoted inside a sentence does not',
	'0.3.4',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', 60, 'Put [skip-hold] in the notes to skip it.' ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'a release with no notes at all is held, not an error',
	'0.3.4',
	sahaj_rollout_offered( array( sahaj_rollout_release( 'v0.3.5', 60, null ), sahaj_rollout_release( 'v0.3.4', 30 * $sahaj_day ) ) )
);

sahaj_is(
	'a release without the zip is still never offered',
	null,
	sahaj_rollout_offered( array( (object) array_merge( (array) sahaj_rollout_release( 'v0.3.5', 4 * $sahaj_day ), array( 'assets' => array() ) ) ) )
);

// A hold of zero, or a value that is not a number, switches the hold off.
add_filter( 'sahaj_atlas_update_hold_days', '__return_zero' );
sahaj_is( 'a hold of zero days offers a fresh release', '0.3.5', sahaj_rollout_offered( $sahaj_fresh ) );
remove_filter( 'sahaj_atlas_update_hold_days', '__return_zero' );

add_filter(
	'sahaj_atlas_update_hold_days',
	function () {
		return 'three';
	},
	10
);
sahaj_is( 'a hold that is not a number offers a fresh release', '0.3.5', sahaj_rollout_offered( $sahaj_fresh ) );
remove_all_filters( 'sahaj_atlas_update_hold_days' );

// A logic error in the staging code is the same as no staging.
add_filter(
	'sahaj_atlas_update_hold_days',
	function () {
		throw new RuntimeException( 'boom' );
	}
);
sahaj_is( 'an error in the staging logic offers the release', '0.3.5', sahaj_rollout_offered( $sahaj_fresh ) );
remove_all_filters( 'sahaj_atlas_update_hold_days' );

add_filter(
	'sahaj_atlas_update_hold_days',
	function () {
		return 0.5;
	}
);
sahaj_is( 'hold days can be a fraction', 12 * HOUR_IN_SECONDS, sahaj_atlas_update_hold_seconds() );
remove_all_filters( 'sahaj_atlas_update_hold_days' );

remove_filter( 'pre_http_request', 'sahaj_rollout_http_stub', 10 );
