<?php
/**
 * The status panel.
 *
 * ⚠ This panel decides whether the plugin is self-service, or needs support emails from all
 * thirteen sites. Every failure below is silent to the volunteer, and none of them raises a
 * WordPress error.
 *
 * - A rejected key renders an empty box.
 * - A mismatched canonical prefix silently degrades path routing to query routing, with a console
 *   message nobody reads.
 * - An empty `allowedDomains` list refuses the embed report. It does not allow every origin.
 *
 * The panel makes these problems visible. The browser hides them silently.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** How long the plugin caches a `clients/me` answer. Long enough to survive a page refresh, and
 * short enough that a key fix appears while the volunteer still watches the screen. */
define( 'SAHAJ_ATLAS_CHECK_TTL', 5 * MINUTE_IN_SECONDS );

/** Transient holding the last client record fetched. Keyed by the key, so changing it re-checks. */
define( 'SAHAJ_ATLAS_CHECK_TRANSIENT', 'sahaj_atlas_client_check' );

function sahaj_atlas_render_diagnostics() {
	echo '<table class="widefat striped" style="max-width:60rem"><tbody>';

	foreach ( sahaj_atlas_checks() as $check ) {
		printf(
			'<tr><td style="width:1.5rem">%s</td><td style="width:14rem"><strong>%s</strong></td><td>%s</td></tr>',
			esc_html( sahaj_atlas_status_glyph( $check['status'] ) ),
			esc_html( $check['label'] ),
			// ⚠ `strong` belongs here. Two checks emit it around a name the volunteer is meant to
			// read, and without it `wp_kses` strips the tag and nothing says why the text is plain.
			wp_kses( $check['detail'], array( 'code' => array(), 'a' => array( 'href' => array() ), 'em' => array(), 'strong' => array() ) )
		);
	}

	echo '</tbody></table>';
}

/**
 * One glyph per status. The panel uses text, not colour, so it works for a colour-blind reader and
 * in a pasted support email.
 *
 * @param string $status One of ok|warn|fail|idle.
 * @return string
 */
function sahaj_atlas_status_glyph( $status ) {
	$map = array(
		'ok'   => '✓',
		'warn' => '!',
		'fail' => '✗',
		'idle' => '–',
	);

	return isset( $map[ $status ] ) ? $map[ $status ] : '–';
}

/**
 * The row a check shows before the key works.
 *
 * Two checks read the client record, and neither can say anything until it arrives. One spelling,
 * so a third one does not invent a second way of saying "ask me again later".
 *
 * @param string $label The check's own label.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_idle( $label ) {
	return array(
		'status' => 'idle',
		'label'  => $label,
		'detail' => esc_html__( 'Not checked — the API key has to work first.', 'sahaj-atlas' ),
	);
}

/**
 * The five checks, in the order a volunteer reaches them.
 *
 * @return array<int, array{status:string, label:string, detail:string}>
 */
function sahaj_atlas_checks() {
	$client = sahaj_atlas_client_record();

	return array(
		sahaj_atlas_check_key( $client ),
		sahaj_atlas_check_page(),
		sahaj_atlas_check_path_routing( $client ),
		sahaj_atlas_check_allowed_domains( $client ),
		sahaj_atlas_check_page_description( $client ),
	);
}

/**
 * Check 1 — is the key accepted?
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_key( $client ) {
	$label = __( 'API key', 'sahaj-atlas' );

	if ( '' === sahaj_atlas_api_key() ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'No key set. Ask the Sahaj Atlas maintainers for one — it is free and takes a day.', 'sahaj-atlas' ),
		);
	}

	if ( is_wp_error( $client ) ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %s: an error message from the server. */
				esc_html__( 'The Atlas server refused it: %s', 'sahaj-atlas' ),
				'<em>' . esc_html( $client->get_error_message() ) . '</em>'
			),
		);
	}

	$name = isset( $client['name'] ) ? (string) $client['name'] : '';

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => '' === $name
			? esc_html__( 'Accepted.', 'sahaj-atlas' )
			: sprintf(
				/* translators: %s: the name the Atlas server has for this site. */
				esc_html__( 'Accepted, as %s.', 'sahaj-atlas' ),
				'<strong>' . esc_html( $name ) . '</strong>'
			),
	);
}

/**
 * Check 2 — is there exactly one healthy Atlas page?
 *
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_page() {
	$label = __( 'Atlas page', 'sahaj-atlas' );
	$id    = sahaj_atlas_page_id();

	if ( ! $id ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'Not created yet. Use the button above.', 'sahaj-atlas' ),
		);
	}

	$post = get_post( $id );

	if ( ! $post || 'page' !== $post->post_type ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'The page this plugin created has been deleted. Create it again.', 'sahaj-atlas' ),
		);
	}

	if ( 'publish' !== $post->post_status ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %s: the post status, e.g. draft or trash. */
				esc_html__( 'The page exists but is %s, so visitors cannot see it.', 'sahaj-atlas' ),
				'<code>' . esc_html( $post->post_status ) . '</code>'
			),
		);
	}

	$strays = sahaj_atlas_stray_pages();

	if ( $strays ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %d: how many other pages use the Atlas template. */
				esc_html( _n(
					'Published — but %d other page also uses the Atlas template. Only one can be the atlas; change the others back to a normal template.',
					'Published — but %d other pages also use the Atlas template. Only one can be the atlas; change the others back to a normal template.',
					count( $strays ),
					'sahaj-atlas'
				) ),
				count( $strays )
			),
		);
	}

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => sprintf(
			'<a href="%s"><code>%s</code></a>',
			esc_url( (string) get_permalink( $id ) ),
			esc_html( (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH ) )
		),
	);
}

/**
 * Check 3: is path routing live? Three conditions decide this, and the plugin controls only the
 * first two. The check reports each condition on its own, so a volunteer never just sees silence
 * after turning the setting on.
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_path_routing( $client ) {
	$label = __( 'Clean URLs', 'sahaj-atlas' );

	if ( ! get_option( 'permalink_structure' ) ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %s: a link to the Permalinks settings screen. */
				esc_html__( 'Off, because this site uses plain permalinks. Choose any other option under %s and the atlas will use clean URLs.', 'sahaj-atlas' ),
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks', 'sahaj-atlas' ) . '</a>'
			),
		);
	}

	if ( sahaj_atlas_page_is_front_page() ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => esc_html__(
				'Off, because your atlas is the site\'s front page. Clean URLs need the atlas on a page of its own — anything else would mean the atlas answering every address on the site, including the ones that should show "not found". Give it its own page under Settings → Reading, or leave clean URLs off.',
				'sahaj-atlas'
			),
		);
	}

	$embed = sahaj_atlas_canonical_embed( $client );

	if ( '' === $embed ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => esc_html__(
				'Off, because the Atlas server does not yet know which page holds your atlas. Send the address below to the Sahaj Atlas maintainers and they will register it.',
				'sahaj-atlas'
			) . ' <code>' . esc_html( sahaj_atlas_mount_key() ) . '</code>',
		);
	}

	$ours = sahaj_atlas_mount_key();

	if ( untrailingslashit( $embed ) !== untrailingslashit( $ours ) ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: 1: the address registered with the Atlas server. 2: this site's actual address. */
				esc_html__( 'Off, because the Atlas server has %1$s registered but your atlas is at %2$s. Send the second address to the Sahaj Atlas maintainers.', 'sahaj-atlas' ),
				'<code>' . esc_html( $embed ) . '</code>',
				'<code>' . esc_html( $ours ) . '</code>'
			),
		);
	}

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => esc_html__( 'On. Links into the atlas are shareable and search engines can index them.', 'sahaj-atlas' ),
	);
}

/**
 * Check 4 — will this site's own domain be accepted?
 *
 * ⚠ This mirrors `parseAllowedDomains()` and `isHostAllowed()` in SahajCloud
 * (`src/plugins/usage/originEnforcement.ts`). Do not rederive these rules.
 *
 * The first version of this check guessed at all three rules, and got all three wrong. A wrong
 * check is worse than no check. A volunteer trusts this panel instead of emailing us, so a
 * confident wrong red sends them to us about a site that already works.
 *
 * - The list is newline-separated. It is a textarea, and a comma alone is not a separator. An
 *   earlier version split on commas only, read one real two-domain client as one impossible
 *   domain, and reported it unusable.
 * - An empty list allows every origin. This is the documented default, for backward compatibility.
 *   An earlier version refused everything instead, and told the volunteer to contact us. A design
 *   note once proposed that opposite behaviour, but nobody built it — the old check matched the
 *   unbuilt proposal, not the real server.
 * - `*.example.org` is a wildcard. It matches only subdomains, never the apex. A bare `example.org`
 *   matches only that exact host. An earlier version treated every entry as a suffix, so a bare
 *   apex entry silently allowed every subdomain too.
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_allowed_domains( $client ) {
	$label = __( 'This domain', 'sahaj-atlas' );
	$host  = sahaj_atlas_normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	if ( ! is_array( $client ) ) {
		return sahaj_atlas_check_idle( $label );
	}

	$patterns = sahaj_atlas_parse_allowed_domains( isset( $client['allowedDomains'] ) ? $client['allowedDomains'] : '' );

	if ( ! $patterns ) {
		return array(
			'status' => 'ok',
			'label'  => $label,
			'detail' => esc_html__( 'Your key has no domain restriction, so this site is accepted.', 'sahaj-atlas' ),
		);
	}

	if ( sahaj_atlas_is_host_allowed( $host, $patterns ) ) {
		return array(
			'status' => 'ok',
			'label'  => $label,
			'detail' => '<code>' . esc_html( $host ) . '</code>',
		);
	}

	return array(
		'status' => 'fail',
		'label'  => $label,
		'detail' => sprintf(
			/* translators: 1: this site's host. 2: the domains registered with the Atlas server. */
			esc_html__( 'The Atlas server does not have %1$s registered. It has %2$s. Ask the maintainers to add this one.', 'sahaj-atlas' ),
			'<code>' . esc_html( $host ) . '</code>',
			'<code>' . esc_html( implode( ', ', $patterns ) ) . '</code>'
		),
	);
}

/**
 * Check 5 — which side describes the Atlas page?
 *
 * ⚠ A silent takeover is the shape that generates a support request nobody local can answer: a
 * volunteer sees their own description replaced, and the plugin that replaced it says nothing. So
 * this check names the side that owns it, either way, and shows the title a visitor would get.
 *
 * ⚠ The read is `sahaj_atlas_seo_fetch()`, the same call the front end makes, so a failure here is
 * the failure a visitor gets rather than a second opinion about it. It asks in the admin's own
 * language, which is not always the site's — the panel is for the person reading it, and the cache
 * slot is keyed per locale, so this warms the admin's own slot rather than the visitor's.
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_page_description( $client ) {
	$label = __( 'Page description', 'sahaj-atlas' );

	if ( sahaj_atlas_seo_host_describes_root() ) {
		return array(
			'status' => 'idle',
			'label'  => $label,
			'detail' => esc_html__(
				'Your own SEO plugin describes the Atlas page, because you ticked the box above. Pages for a country, a city or a class are still described by Sahaj Atlas.',
				'sahaj-atlas'
			),
		);
	}

	if ( ! is_array( $client ) ) {
		return sahaj_atlas_check_idle( $label );
	}

	$answer = sahaj_atlas_seo_fetch( '/' );
	$title  = is_array( $answer ) ? sahaj_atlas_seo_get( $answer, 'title' ) : '';

	if ( '' === $title ) {
		/*
		 * ⚠ This is a warning, not a failure. Nothing is broken or missing: the plugin suppresses
		 * the host's SEO plugin only after a successful fetch, so a page nobody upstream describes
		 * keeps whatever described it before this plugin was installed.
		 */
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => esc_html__(
				'The Atlas server has nothing to say about this page yet, so whatever describes your pages now stays in place. Nothing is broken. Tell the Sahaj Atlas maintainers if it stays this way.',
				'sahaj-atlas'
			),
		);
	}

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => sprintf(
			/* translators: %s: the page title the Atlas server supplies. */
			esc_html__( 'Sahaj Atlas describes it, in each visitor\'s own language, as %s.', 'sahaj-atlas' ),
			'<strong>' . esc_html( $title ) . '</strong>'
		),
	);
}

/**
 * Normalize one entry or host into a comparable bare host, or `''` if it is not one.
 *
 * @param string $value A host, URL, or allowlist entry.
 * @return string
 */
function sahaj_atlas_normalize_host( $value ) {
	$value = strtolower( trim( (string) $value ) );

	if ( '' === $value ) {
		return '';
	}

	$wildcard = 0 === strpos( $value, '*.' );

	if ( $wildcard ) {
		$value = substr( $value, 2 );
	}

	// An entry may be a full URL. This strips everything after the authority.
	$value = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $value );
	$value = preg_replace( '#[/?\#].*$#', '', (string) $value );

	// This strips the port. The server compares hostnames with no port.
	$value = preg_replace( '#:\d+$#', '', (string) $value );

	// This removes a trailing dot from a fully qualified domain.
	$value = rtrim( (string) $value, '.' );

	if ( '' === $value || false !== strpos( $value, '*' ) ) {
		return '';
	}

	return $wildcard ? '*.' . $value : $value;
}

/**
 * Parse the newline-separated `allowedDomains` textarea into host patterns.
 *
 * @param mixed $raw The stored value.
 * @return string[]
 */
function sahaj_atlas_parse_allowed_domains( $raw ) {
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return array();
	}

	$entries  = preg_split( '/[\r\n,]+/', $raw );
	$patterns = array();

	foreach ( (array) $entries as $entry ) {
		$host = sahaj_atlas_normalize_host( $entry );

		if ( '' !== $host ) {
			$patterns[] = $host;
		}
	}

	return $patterns;
}

/**
 * @param string   $host     A normalized bare host.
 * @param string[] $patterns Normalized patterns.
 * @return bool
 */
function sahaj_atlas_is_host_allowed( $host, $patterns ) {
	if ( '' === $host ) {
		return false;
	}

	foreach ( $patterns as $pattern ) {
		if ( 0 === strpos( $pattern, '*.' ) ) {
			// ⚠ The leading dot stops suffix injection. `evil-example.org` must not match
			// `*.example.org`. The apex itself never matches a wildcard.
			$suffix = substr( $pattern, 1 );

			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}

			continue;
		}

		if ( $host === $pattern ) {
			return true;
		}
	}

	return false;
}

/**
 * The mount key this site presents: scheme-less `host/path`, matching the widget's own
 * `mountPrefix()` — the value that goes in the client record's `canonical.embed`.
 *
 * @return string
 */
function sahaj_atlas_mount_key() {
	$permalink = sahaj_atlas_page_id() ? (string) get_permalink( sahaj_atlas_page_id() ) : home_url( '/' );
	$parts     = wp_parse_url( $permalink );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	$host = strtolower( (string) $parts['host'] );

	if ( ! empty( $parts['port'] ) ) {
		$host .= ':' . (int) $parts['port'];
	}

	$path = isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '';

	return $host . $path;
}

/**
 * The `canonical.embed` the Atlas server has on file, or an empty string.
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return string
 */
function sahaj_atlas_canonical_embed( $client ) {
	if ( ! is_array( $client ) || empty( $client['canonical'] ) || ! is_array( $client['canonical'] ) ) {
		return '';
	}

	$canonical = $client['canonical'];

	if ( empty( $canonical['enabled'] ) || empty( $canonical['embed'] ) ) {
		return '';
	}

	return strtolower( trim( (string) $canonical['embed'] ) );
}

/**
 * Read `GET /api/clients/me`, cached.
 *
 * @param bool $force Skip the cache.
 * @return array|WP_Error|null Null when there is no key to try.
 */
function sahaj_atlas_client_record( $force = false ) {
	$key = sahaj_atlas_api_key();

	if ( '' === $key ) {
		return null;
	}

	$slot   = SAHAJ_ATLAS_CHECK_TRANSIENT . '_' . substr( md5( $key ), 0, 12 );
	$cached = $force ? false : get_transient( $slot );

	if ( is_array( $cached ) ) {
		return isset( $cached['error'] )
			? new WP_Error( 'sahaj_atlas_client', (string) $cached['error'] )
			: $cached;
	}

	$response = wp_remote_get(
		SAHAJ_ATLAS_API_ORIGIN . '/api/clients/me?depth=0',
		array(
			'timeout' => 8,
			'headers' => array(
				'Authorization' => 'clients API-Key ' . $key,
				'Accept'        => 'application/json',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		// A transport failure means a problem with this site's network. It is not a verdict on the
		// key. Do not cache it as a refusal, or a single blip pins "refused" on the panel for five
		// minutes.
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$message = is_array( $body ) && ! empty( $body['errors'][0]['message'] )
			? (string) $body['errors'][0]['message']
			/* translators: %d: an HTTP status code. */
			: sprintf( __( 'HTTP %d', 'sahaj-atlas' ), $code );

		set_transient( $slot, array( 'error' => $message ), SAHAJ_ATLAS_CHECK_TTL );

		return new WP_Error( 'sahaj_atlas_client', $message );
	}

	// `clients/me` answers `{ user: {...} }`. Older shapes returned the record directly.
	$record = is_array( $body ) && isset( $body['user'] ) && is_array( $body['user'] ) ? $body['user'] : $body;

	if ( ! is_array( $record ) ) {
		$message = __( 'The server sent something unexpected.', 'sahaj-atlas' );

		set_transient( $slot, array( 'error' => $message ), SAHAJ_ATLAS_CHECK_TTL );

		return new WP_Error( 'sahaj_atlas_client', $message );
	}

	set_transient( $slot, $record, SAHAJ_ATLAS_CHECK_TTL );

	return $record;
}
