<?php
/**
 * The status panel.
 *
 * ⚠ **This is the feature that decides whether the plugin is self-service or thirteen support
 * emails.** Every failure mode below is silent from the volunteer's side: a rejected key renders an
 * empty box, a mismatched canonical prefix degrades path routing to query routing with a console
 * message nobody opens, and an empty `allowedDomains` list *refuses* the embed report rather than
 * allowing everything. None of them produce a WordPress error. So the panel says out loud what the
 * browser only whispers.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/** How long a `clients/me` answer is cached. Long enough to survive a page refresh, short enough
 * that fixing a key shows up while the volunteer is still looking at the screen. */
define( 'SAHAJ_ATLAS_CHECK_TTL', 5 * MINUTE_IN_SECONDS );

/** Transient holding the last client record fetched. Keyed by the key, so changing it re-checks. */
define( 'SAHAJ_ATLAS_CHECK_TRANSIENT', 'sahaj_atlas_client_check' );

/**
 * Render the whole panel.
 */
function sahaj_atlas_render_diagnostics() {
	echo '<table class="widefat striped" style="max-width:60rem"><tbody>';

	foreach ( sahaj_atlas_checks() as $check ) {
		printf(
			'<tr><td style="width:1.5rem">%s</td><td style="width:14rem"><strong>%s</strong></td><td>%s</td></tr>',
			esc_html( sahaj_atlas_status_glyph( $check['status'] ) ),
			esc_html( $check['label'] ),
			wp_kses( $check['detail'], array( 'code' => array(), 'a' => array( 'href' => array() ), 'em' => array() ) )
		);
	}

	echo '</tbody></table>';
}

/**
 * A glyph per status. Text, not colour — the panel has to work for a colour-blind reader and in a
 * pasted support email.
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
 * The four checks, in the order a volunteer hits them.
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
 * Check 3 — is path routing actually live?
 *
 * Three conditions, and the plugin owns only the first two. Reported separately because "I turned
 * it on and nothing changed" is otherwise the whole experience.
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
 * ⚠ An **empty** `allowedDomains` refuses every host rather than allowing all of them. A volunteer
 * would experience that as a widget that loads and then shows nothing, with the reason only in a
 * console they will never open.
 *
 * @param array|WP_Error|null $client Result of the client read.
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_allowed_domains( $client ) {
	$label = __( 'This domain', 'sahaj-atlas' );

	if ( ! is_array( $client ) ) {
		return array(
			'status' => 'idle',
			'label'  => $label,
			'detail' => esc_html__( 'Not checked — the API key has to work first.', 'sahaj-atlas' ),
		);
	}

	$raw  = isset( $client['allowedDomains'] ) ? (string) $client['allowedDomains'] : '';
	$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $list ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__(
				'The Atlas server has no domains registered for your key, which means it will refuse every page. Ask the maintainers to add this site.',
				'sahaj-atlas'
			) . ' <code>' . esc_html( $host ) . '</code>',
		);
	}

	foreach ( $list as $allowed ) {
		$allowed = strtolower( ltrim( $allowed, '.' ) );

		if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
			return array(
				'status' => 'ok',
				'label'  => $label,
				'detail' => '<code>' . esc_html( $host ) . '</code>',
			);
		}
	}

	return array(
		'status' => 'fail',
		'label'  => $label,
		'detail' => sprintf(
			/* translators: 1: this site's host. 2: the domains registered with the Atlas server. */
			esc_html__( 'The Atlas server does not have %1$s registered. It has %2$s. Ask the maintainers to add this one.', 'sahaj-atlas' ),
			'<code>' . esc_html( $host ) . '</code>',
			'<code>' . esc_html( implode( ', ', $list ) ) . '</code>'
		),
	);
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
		// A transport failure is this site's network, not a verdict on the key — don't cache it as
		// one, or a blip pins "refused" on the panel for five minutes.
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

	// `clients/me` answers `{ user: {...} }`; older shapes returned the record directly.
	$record = is_array( $body ) && isset( $body['user'] ) && is_array( $body['user'] ) ? $body['user'] : $body;

	if ( ! is_array( $record ) ) {
		$message = __( 'The server sent something unexpected.', 'sahaj-atlas' );

		set_transient( $slot, array( 'error' => $message ), SAHAJ_ATLAS_CHECK_TTL );

		return new WP_Error( 'sahaj_atlas_client', $message );
	}

	set_transient( $slot, $record, SAHAJ_ATLAS_CHECK_TTL );

	return $record;
}
