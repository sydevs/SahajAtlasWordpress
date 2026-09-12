<?php
/**
 * Server-rendered metadata and body content for atlas routes.
 *
 * A deep atlas link, such as `/find-a-class/gb/london`, is a real URL that a crawler fetches.
 * Without this file, it returns the site's generic page title, the site's generic description,
 * and an empty `<sahaj-atlas>` element. Everything a search engine could index about a class in
 * London arrives later, from JavaScript, under a URL the crawler already judged.
 *
 * Strategy: take over, do not feed. On atlas routes only, this suppresses whichever SEO plugin is
 * active, and one code path emits everything instead.
 *
 * ⚠ This reasoning is not a preference, and it does not survive a "simplified" version with three
 * adapters. Feeding needs a different shape per plugin: Yoast's dozen per-property filters,
 * AIOSEO's single array filter, Rank Math's dynamic filter names. None of the three plugins emits
 * `hreflang` at all, so this plugin must print that itself regardless. Three of the nine surveyed
 * client sites run no SEO plugin, so the standalone emitter must exist anyway. The override costs
 * one emitter plus three one-line suppressions.
 *
 * ⚠ Known cost, accepted: the SEO plugin's own metabox and social preview show its stale idea of
 * these pages. That is cosmetic, and only an administrator ever sees it. The alternative is three
 * adapters that still cannot carry hreflang.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** How long one route's SEO answer is cached. The endpoint itself says `max-age=300`. */
define( 'SAHAJ_ATLAS_SEO_TTL', 5 * MINUTE_IN_SECONDS );

/**
 * The answer for this request, or null. Fetched once on `template_redirect`.
 *
 * @var array|null
 */
$GLOBALS['sahaj_atlas_seo'] = null;

/**
 * Fetch the answer and, if there is one, take the page over.
 *
 * ⚠ Suppression happens only after a successful fetch. Suppressing first, then finding the
 * endpoint unreachable, would leave the page with no metadata at all — strictly worse than the
 * generic metadata it replaces. A failed fetch is a no-op here, and the site's own SEO plugin
 * continues as if this plugin were not installed.
 */
function sahaj_atlas_seo_boot() {
	if ( is_admin() || ! sahaj_atlas_is_atlas_page() ) {
		return;
	}

	$route = sahaj_atlas_current_route();

	/*
	 * ⚠ This deliberately excludes the atlas root. The endpoint 404s an unresolvable route, and
	 * treats the root as one. A site's landing page is its own to describe, in its own language,
	 * and nothing in the atlas is localized. A sentence composed upstream would show English in a
	 * Dutch site's `<head>` — the one place a visitor cannot skip.
	 */
	if ( '' === $route || '/' === $route ) {
		return;
	}

	$answer = sahaj_atlas_seo_fetch( $route );

	if ( ! is_array( $answer ) ) {
		return;
	}

	$GLOBALS['sahaj_atlas_seo'] = $answer;

	sahaj_atlas_seo_suppress_others();

	add_action( 'wp_head', 'sahaj_atlas_seo_emit', 1 );
	add_filter( 'pre_get_document_title', 'sahaj_atlas_seo_title' );
	add_filter( 'sahaj_atlas_element_children', 'sahaj_atlas_seo_children' );
}

/**
 * Silence every other source of the tags we are about to emit.
 *
 * All four are the vendors' own documented switches.
 */
function sahaj_atlas_seo_suppress_others() {
	// Yoast. ⚠ The priority is part of the API. Yoast adds `present_head` at -9999, and
	// `remove_action` matches only an identical callback and priority together.
	if ( class_exists( 'WPSEO_Frontend' ) || defined( 'WPSEO_VERSION' ) ) {
		add_action(
			'wp_head',
			function () {
				if ( function_exists( 'YoastSEO' ) ) {
					$front_end = YoastSEO()->classes->get( \Yoast\WP\SEO\Integrations\Front_End_Integration::class );

					remove_action( 'wpseo_head', array( $front_end, 'present_head' ), -9999 );
				}
			},
			0
		);

		remove_all_actions( 'wpseo_head' );
	}

	// All in One SEO.
	add_filter( 'aioseo_disable', '__return_true' );
	add_filter( 'aioseo_disable_schema', '__return_true' );

	// Rank Math. Absent from all nine surveyed sites. One line is cheaper than finding out.
	add_action( 'wp_head', function () { remove_all_actions( 'rank_math/head' ); }, 0 );

	// Core.
	remove_action( 'wp_head', 'rel_canonical' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	add_filter( 'wpseo_canonical', '__return_false' );
}

/**
 * `<title>` for an atlas route.
 *
 * @param string $title The title core or a plugin composed.
 * @return string
 */
function sahaj_atlas_seo_title( $title ) {
	$seo = $GLOBALS['sahaj_atlas_seo'];

	return ( is_array( $seo ) && ! empty( $seo['title'] ) ) ? (string) $seo['title'] : $title;
}

/**
 * Emit the whole head block.
 */
function sahaj_atlas_seo_emit() {
	$seo = $GLOBALS['sahaj_atlas_seo'];

	if ( ! is_array( $seo ) ) {
		return;
	}

	echo "\n<!-- Sahaj Atlas -->\n";

	if ( ! empty( $seo['description'] ) ) {
		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( (string) $seo['description'] ) );
	}

	/*
	 * ⚠ This emits `canonical` verbatim. It is the document's own `webUrl`, read from the CMS and
	 * never recomputed. A canonical composed here would be a second implementation, free to
	 * disagree with the one the rest of the system publishes — on the one tag whose only job is to
	 * be the single agreed address.
	 */
	if ( ! empty( $seo['canonical'] ) ) {
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( (string) $seo['canonical'] ) );
	}

	if ( ! empty( $seo['alternates'] ) && is_array( $seo['alternates'] ) ) {
		foreach ( $seo['alternates'] as $alternate ) {
			if ( empty( $alternate['hreflang'] ) || empty( $alternate['href'] ) ) {
				continue;
			}

			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( (string) $alternate['hreflang'] ),
				esc_url( (string) $alternate['href'] )
			);
		}
	}

	if ( ! empty( $seo['openGraph'] ) && is_array( $seo['openGraph'] ) ) {
		foreach ( $seo['openGraph'] as $property => $content ) {
			if ( '' === (string) $content ) {
				continue;
			}

			// `og:*` and `article:*` use the `property` attribute. `twitter:*` uses `name`. Every
			// consumer silently ignores the wrong attribute, which is the worst kind of wrong.
			$attribute = 0 === strpos( (string) $property, 'twitter:' ) ? 'name' : 'property';

			printf(
				'<meta %s="%s" content="%s" />' . "\n",
				esc_attr( $attribute ),
				esc_attr( (string) $property ),
				esc_attr( (string) $content )
			);
		}
	}

	/*
	 * ⚠ `jsonLd` arrives pre-escaped, and this echoes it raw. Do not wrap it in `esc_html`,
	 * `wp_json_encode`, or `wp_kses`. The producer escapes `<`, `>` and `&` as `<` and so on,
	 * because this value lands inside a `<script>` element on a page nothing else sanitizes. An
	 * HTML parser ends that element at the first `</script`, and starts a comment at `<!--`. Those
	 * escapes are valid JSON for the same characters, so the block still parses to the same value.
	 * Re-encoding here would double-escape it into invalid structured data. `esc_html` would turn
	 * it into `&quot;`-laden text a crawler cannot read.
	 */
	if ( ! empty( $seo['jsonLd'] ) ) {
		echo '<script type="application/ld+json">' . $seo['jsonLd'] . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the producer for this exact sink; see above.
	}

	echo "<!-- /Sahaj Atlas -->\n\n";
}

/**
 * The crawlable body content, rendered as children of `<sahaj-atlas>`.
 *
 * The widget replaces its own children when it boots. So this is what a crawler sees, and what a
 * visitor with no JavaScript sees, and nothing else ever renders it. No HTML crosses the wire — the
 * endpoint sends plain text per block. That matters, because this output never passes through
 * `wp_kses`.
 *
 * @param string $children Existing children.
 * @return string
 */
function sahaj_atlas_seo_children( $children ) {
	$seo = $GLOBALS['sahaj_atlas_seo'];

	if ( ! is_array( $seo ) || empty( $seo['content'] ) || ! is_array( $seo['content'] ) ) {
		return $children;
	}

	return 'event' === ( isset( $seo['type'] ) ? $seo['type'] : '' )
		? sahaj_atlas_seo_event_children( $seo['content'] )
		: sahaj_atlas_seo_region_children( $seo['content'] );
}

/**
 * @param array $content An `AtlasSeoEventContent`.
 * @return string
 */
function sahaj_atlas_seo_event_children( $content ) {
	$out = '<article>';

	$out .= '<h1>' . esc_html( (string) sahaj_atlas_seo_get( $content, 'title' ) ) . '</h1>';

	$schedule = isset( $content['schedule'] ) && is_array( $content['schedule'] ) ? $content['schedule'] : array();

	if ( ! empty( $schedule['oneLine'] ) ) {
		$out .= '<p>' . esc_html( (string) $schedule['oneLine'] ) . '</p>';
	}

	$address = isset( $content['address'] ) && is_array( $content['address'] ) ? $content['address'] : array();

	if ( ! empty( $address['oneLine'] ) ) {
		$out .= '<address>' . esc_html( (string) $address['oneLine'] ) . '</address>';
	}

	foreach ( sahaj_atlas_seo_list( $content, 'paragraphs' ) as $paragraph ) {
		$out .= '<p>' . esc_html( (string) $paragraph ) . '</p>';
	}

	foreach ( sahaj_atlas_seo_list( $content, 'images' ) as $image ) {
		if ( empty( $image['url'] ) ) {
			continue;
		}

		$out .= sprintf(
			'<img src="%s" alt="%s" loading="lazy" />',
			esc_url( (string) $image['url'] ),
			esc_attr( isset( $image['alt'] ) ? (string) $image['alt'] : '' )
		);
	}

	if ( ! empty( $content['website'] ) ) {
		$out .= '<p><a href="' . esc_url( (string) $content['website'] ) . '">' . esc_html( (string) $content['website'] ) . '</a></p>';
	}

	return $out . '</article>';
}

/**
 * @param array $content An `AtlasSeoRegionContent`.
 * @return string
 */
function sahaj_atlas_seo_region_children( $content ) {
	$out = '<section>';

	$out .= '<h1>' . esc_html( (string) sahaj_atlas_seo_get( $content, 'name' ) ) . '</h1>';

	if ( ! empty( $content['subtitle'] ) ) {
		$out .= '<p>' . esc_html( (string) $content['subtitle'] ) . '</p>';
	}

	$events = sahaj_atlas_seo_list( $content, 'events' );

	if ( $events ) {
		$out .= '<ul>';

		foreach ( $events as $event ) {
			$title = isset( $event['title'] ) ? (string) $event['title'] : '';

			if ( '' === $title ) {
				continue;
			}

			$label = ! empty( $event['url'] )
				? '<a href="' . esc_url( (string) $event['url'] ) . '">' . esc_html( $title ) . '</a>'
				: esc_html( $title );

			$facts = array_filter(
				array(
					isset( $event['schedule'] ) ? (string) $event['schedule'] : '',
					isset( $event['address'] ) ? (string) $event['address'] : '',
				)
			);

			$out .= '<li>' . $label;

			if ( $facts ) {
				$out .= ' — ' . esc_html( implode( ' · ', $facts ) );
			}

			$out .= '</li>';
		}

		$out .= '</ul>';
	}

	return $out . '</section>';
}

/**
 * @param array  $source Array to read.
 * @param string $key    Key to read.
 * @return string
 */
function sahaj_atlas_seo_get( $source, $key ) {
	return isset( $source[ $key ] ) ? (string) $source[ $key ] : '';
}

/**
 * @param array  $source Array to read.
 * @param string $key    Key holding a list.
 * @return array
 */
function sahaj_atlas_seo_list( $source, $key ) {
	return ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) ? $source[ $key ] : array();
}

/**
 * Read `GET /api/atlas/seo`, cached per route and locale.
 *
 * @param string $route The atlas route, e.g. `/gb/london`.
 * @return array|null
 */
function sahaj_atlas_seo_fetch( $route ) {
	$key = sahaj_atlas_api_key();

	if ( '' === $key ) {
		return null;
	}

	$locale = sahaj_atlas_seo_locale();
	$slot   = sahaj_atlas_seo_slot( $route, $locale );
	$cached = get_transient( $slot );

	if ( is_array( $cached ) ) {
		return isset( $cached['miss'] ) ? null : $cached;
	}

	$answer = sahaj_atlas_seo_request( $route, $locale );

	// ⚠ A 400 means the locale was refused, not the route. SahajCloud validates `locale` against
	// its own list. This plugin deliberately keeps no copy of that list — a hard-coded copy here
	// would drift the day a language is added there. One retry with no locale is cheaper than a
	// list, and it cannot go stale.
	if ( 400 === $answer['status'] && '' !== $locale ) {
		$answer = sahaj_atlas_seo_request( $route, '' );
	}

	if ( ! is_array( $answer['body'] ) ) {
		// Cache the miss too. A 404 is a permanent answer for a route that does not resolve.
		// Without this cache, every crawler hit on a dead deep link becomes a fresh upstream
		// request.
		set_transient( $slot, array( 'miss' => true ), SAHAJ_ATLAS_SEO_TTL );

		return null;
	}

	set_transient( $slot, $answer['body'], SAHAJ_ATLAS_SEO_TTL );

	return $answer['body'];
}

/**
 * The transient name holding one route's answer.
 *
 * The key is part of it: two sites sharing a database must not share an answer, and a key change is
 * a different client record. So is the locale, since the answer is written in it.
 *
 * @param string      $route  The atlas route.
 * @param string|null $locale Locale, or null for this request's own.
 * @return string
 */
function sahaj_atlas_seo_slot( $route, $locale = null ) {
	if ( null === $locale ) {
		$locale = sahaj_atlas_seo_locale();
	}

	return 'sahaj_atlas_seo_' . substr( md5( $route . '|' . $locale . '|' . sahaj_atlas_api_key() ), 0, 20 );
}

/**
 * One request. Separated so the locale retry above is a call rather than a copy.
 *
 * @param string $route  The atlas route.
 * @param string $locale Locale to ask for, or an empty string to let the server choose.
 * @return array{status:int, body:array|null}
 */
function sahaj_atlas_seo_request( $route, $locale ) {
	$args = array( 'route' => $route );

	if ( '' !== $locale ) {
		$args['locale'] = $locale;
	}

	$response = wp_remote_get(
		add_query_arg( $args, SAHAJ_ATLAS_API_ORIGIN . '/api/atlas/seo' ),
		array(
			'timeout' => 5,
			'headers' => array(
				'Authorization' => 'clients API-Key ' . sahaj_atlas_api_key(),
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return array( 'status' => 0, 'body' => null );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	return array(
		'status' => $status,
		'body'   => ( 200 === $status && is_array( $body ) ) ? $body : null,
	);
}

/**
 * The locale to request: the page's own language, in the shape SahajCloud uses.
 *
 * WordPress writes locales as `en_US` or `pt_BR`. SahajCloud writes them as `en-AU` or `pt-BR`. An
 * unknown locale gets refused with a 400, and `sahaj_atlas_seo_fetch()` retries the request
 * without one.
 *
 * @return string
 */
function sahaj_atlas_seo_locale() {
	$locale = str_replace( '_', '-', (string) determine_locale() );

	if ( ! preg_match( '/^([a-z]{2})(?:-([A-Za-z]{2}))?$/', $locale, $parts ) ) {
		return '';
	}

	// A bare language code is always safe to send. A regional tag is sent as-is, and retried with
	// no tag on a 400. This is how `en-GB` degrades to the server's default, instead of to the
	// wrong language.
	return isset( $parts[2] ) ? $parts[1] . '-' . strtoupper( $parts[2] ) : $parts[1];
}
