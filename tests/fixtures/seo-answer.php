<?php
/**
 * One `GET /api/atlas/seo` success body, for every lane that needs one.
 *
 * The PHP suite requires this file directly. Both render blueprints require it from the mounted
 * plugin, at `/wordpress/wp-content/plugins/sahaj-atlas/tests/fixtures/seo-answer.php`. One copy,
 * because three copies of an upstream response shape diverge the first time that shape changes —
 * and two of those copies would live inside JSON-escaped one-liners where a wrong key is invisible.
 *
 * ⚠ Fixture pre-mortem. This assumes the endpoint's success body is an `AtlasSeoResponse`:
 * `type`, `id`, `route`, `locale`, `title`, `description`, `canonical`, `alternates`, `openGraph`,
 * `jsonLd`, `breadcrumbs`, `content`. Verified against `src/endpoints/responseTypes.ts:149-188` in
 * sydevs/SahajCloud, and the region content against `AtlasSeoRegionContent` and `AtlasSeoEventCard`
 * in the same file. A fixture derived from this plugin's own reader instead would assert only that
 * the reader reads itself.
 *
 * ⚠ The canonical is deliberately a query-routed URL. That is the shape SahajCloud publishes for a
 * host that cannot path-route, and the whole point of what these lanes assert.
 *
 * @package SahajAtlas
 */

$sahaj_seo_fixture_url = home_url( '/find-a-class/' ) . '?atlas=';

return array(
	'route'     => '/nl/amsterdam',
	'canonical' => $sahaj_seo_fixture_url . '/nl/amsterdam',
	'answer'    => array(
		'type'        => 'region',
		'id'          => 42,
		'route'       => '/nl/amsterdam',
		'locale'      => 'en',
		'title'       => 'Free meditation classes in Amsterdam',
		'description' => 'Weekly Sahaja Yoga meditation classes in Amsterdam, free to attend.',
		'canonical'   => $sahaj_seo_fixture_url . '/nl/amsterdam',
		'alternates'  => array(
			array( 'hreflang' => 'en', 'href' => $sahaj_seo_fixture_url . '/nl/amsterdam' ),
			array( 'hreflang' => 'x-default', 'href' => $sahaj_seo_fixture_url . '/nl/amsterdam' ),
		),
		'openGraph'   => array(
			'og:title'     => 'Free meditation classes in Amsterdam',
			'twitter:card' => 'summary',
		),
		'jsonLd'      => '{"@context":"https://schema.org","@type":"Place","name":"Amsterdam"}',
		'breadcrumbs' => array(
			array( 'name' => 'Netherlands', 'route' => '/nl', 'url' => $sahaj_seo_fixture_url . '/nl' ),
		),
		'content'     => array(
			'name'       => 'Amsterdam',
			'subtitle'   => 'Netherlands',
			'level'      => 'city',
			'eventCount' => 1,
			'events'     => array(
				array(
					'id'       => 1204,
					'route'    => '/nl/amsterdam/1204',
					'url'      => $sahaj_seo_fixture_url . '/nl/amsterdam/1204',
					'title'    => 'Tuesday evening class',
					'schedule' => 'Every week on Tuesday at 7:00 PM',
					'address'  => 'Keizersgracht 1, Amsterdam',
					'online'   => false,
				),
			),
		),
	),
);
