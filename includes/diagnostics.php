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
 * - A non-empty `allowedDomains` list without this site's domain refuses the widget's requests.
 *   An empty list refuses nothing, the embed report included. Check 4 carries the server's rules.
 * - A template that is not this plugin's renders the Atlas page, and the element lands after the
 *   footer with no height. Nothing server-side can tell: the template meta still names ours.
 * - An optimiser rewrites the loader's script tag, and a module that cannot run logs one console
 *   error. Checks 6 and 7 read the live page back to see both.
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

/** Transient holding what the last loopback read of the Atlas page found. */
define( 'SAHAJ_ATLAS_PROBE_TRANSIENT', 'sahaj_atlas_page_probe' );

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
 * The seven checks, in the order a volunteer reaches them.
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
		sahaj_atlas_check_render(),
		sahaj_atlas_check_loader(),
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

	/*
	 * ⚠ The same refusal `sahaj_atlas_seo_boot()` makes, reported rather than hidden. Without this
	 * row the panel would say "Sahaj Atlas describes it" about a page Sahaj Atlas had just declined
	 * to describe — and the one person who could report the misconfiguration would never see it. A
	 * warning, not a failure: the host's own description is still there, exactly as before.
	 */
	if ( sahaj_atlas_seo_root_points_elsewhere( $answer ) ) {
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => esc_html__(
				'The Atlas server describes this page as belonging to another website, so Sahaj Atlas left your own description in place. Nothing is broken. Tell the Sahaj Atlas maintainers — your site is set up under the wrong address.',
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
 * Check 6 — which of the three prints rendered the element on the live page?
 *
 * ⚠ This is the one check that reads the rendered page instead of the database. Nothing
 * server-side can answer it: the page still carries this plugin's template meta while a later
 * `template_include` filter, a theme-builder layout, or a maintenance-mode plugin renders it
 * instead. The old check 2 called that page healthy.
 *
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_render() {
	$label  = __( 'Map placement', 'sahaj-atlas' );
	$probe  = sahaj_atlas_page_probe();
	$excuse = sahaj_atlas_probe_excuse( $label, $probe );

	if ( null !== $excuse ) {
		return $excuse;
	}

	if ( '' === $probe['render'] ) {
		/*
		 * ⚠ Two diagnoses, not one. The body class says the Atlas page itself answered this
		 * address. Without it, something else did — a maintenance-mode plugin, or a cache holding
		 * another page — and telling the volunteer the map is missing would send them looking in
		 * the wrong place entirely.
		 */
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => $probe['page']
				? esc_html__( 'Your Atlas page loads, but the map is not on it. Send this page\'s address to the Sahaj Atlas maintainers.', 'sahaj-atlas' )
				: esc_html__( 'Something else answers your Atlas page\'s address, so the map never renders. Check your caching and maintenance-mode plugins first.', 'sahaj-atlas' ),
		);
	}

	if ( 'footer' === $probe['render'] ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %s: the name of the plugin's page template, as the editor shows it. */
				esc_html__( 'The map renders below your footer, where it has no height. Set the Atlas page back to the %s template, or ask your page builder to render the page\'s content.', 'sahaj-atlas' ),
				'<strong>' . esc_html__( 'Sahaj Atlas (full screen)', 'sahaj-atlas' ) . '</strong>'
			),
		);
	}

	if ( 'content' === $probe['render'] ) {
		return array(
			'status' => 'ok',
			'label'  => $label,
			'detail' => esc_html__( 'In your page\'s content area, with your own footer below it. Another template renders this page, which is fine.', 'sahaj-atlas' ),
		);
	}

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => esc_html__( 'On this plugin\'s own full-screen template: your site header, then the map.', 'sahaj-atlas' ),
	);
}

/**
 * Check 7 — did the loader's script tag survive the page?
 *
 * ⚠ `auto.js` is a real ES module, so a classic `<script>` tag cannot run it at all: the page gets
 * a blank slot and one console error ("Cannot use import statement outside a module"). A module
 * cannot detect that from inside itself, which is why SahajAtlasWeb#239 handed the detection here.
 * An "HTML5 cleanup" snippet, a script optimiser, or any `wp_script_attributes` filter can do it.
 *
 * @return array{status:string, label:string, detail:string}
 */
function sahaj_atlas_check_loader() {
	$label  = __( 'Map script', 'sahaj-atlas' );
	$probe  = sahaj_atlas_page_probe();
	$excuse = sahaj_atlas_probe_excuse( $label, $probe );

	if ( null !== $excuse ) {
		return $excuse;
	}

	if ( ! $probe['loader'] ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'Your Atlas page carries no map script, so nothing can load the map. A script optimiser has most likely removed it.', 'sahaj-atlas' ),
		);
	}

	// ⚠ One message per fault, never a sentence assembled from fragments. A translator cannot
	// reorder clauses this plugin glued together, and 34 locales would each get the English shape.
	if ( ! $probe['module'] ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'Something on your site has changed the map script so the browser refuses to run it. Turn off JavaScript optimisation for your Atlas page.', 'sahaj-atlas' ),
		);
	}

	if ( $probe['async'] ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'Something on your site loads the map script asynchronously, which breaks it. Turn off JavaScript optimisation for your Atlas page.', 'sahaj-atlas' ),
		);
	}

	if ( ! $probe['keyed'] ) {
		return array(
			'status' => 'fail',
			'label'  => $label,
			'detail' => esc_html__( 'The map script\'s address has lost your API key, so the Atlas server will refuse it. Turn off JavaScript optimisation for your Atlas page.', 'sahaj-atlas' ),
		);
	}

	return array(
		'status' => 'ok',
		'label'  => $label,
		'detail' => esc_html__( 'Loads as a module, with your key.', 'sahaj-atlas' ),
	);
}

/**
 * The row both loopback checks show when the probe has nothing to report.
 *
 * One spelling for both, the same reason `sahaj_atlas_check_idle()` has one: a second way of
 * saying "there is nothing to read yet" is a second thing to keep true.
 *
 * @param string              $label The check's own label.
 * @param array|WP_Error|null $probe Result of the probe.
 * @return array{status:string, label:string, detail:string}|null Null once the probe has an answer.
 */
function sahaj_atlas_probe_excuse( $label, $probe ) {
	if ( null === $probe ) {
		return array(
			'status' => 'idle',
			'label'  => $label,
			'detail' => esc_html__( 'Not checked — the API key and the Atlas page come first.', 'sahaj-atlas' ),
		);
	}

	if ( is_wp_error( $probe ) ) {
		/*
		 * ⚠ A warning, not a failure. This is the server failing to reach itself, which many hosts
		 * refuse outright, and it says nothing about the page a visitor gets. A red row a volunteer
		 * cannot act on is the shape that sends them to us about a site that already works.
		 */
		return array(
			'status' => 'warn',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %s: an error message from the request. */
				esc_html__( 'This server could not load your Atlas page to check it: %s', 'sahaj-atlas' ),
				'<em>' . esc_html( $probe->get_error_message() ) . '</em>'
			),
		);
	}

	return null;
}

/**
 * Read the Atlas page back as a visitor receives it, cached.
 *
 * @param bool $force Skip the cache.
 * @return array|WP_Error|null Null when there is nothing to probe yet.
 */
function sahaj_atlas_page_probe( $force = false ) {
	if ( '' === sahaj_atlas_api_key() || ! sahaj_atlas_page_is_healthy() ) {
		return null;
	}

	$cached = $force ? false : get_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );

	if ( is_array( $cached ) ) {
		return isset( $cached['error'] )
			? new WP_Error( 'sahaj_atlas_probe', (string) $cached['error'] )
			: $cached;
	}

	$response = wp_remote_get(
		(string) get_permalink( sahaj_atlas_page_id() ),
		array(
			'timeout'     => 10,
			'redirection' => 3,
			/*
			 * ⚠ `sslverify` off, as core's own Site Health loopback test does. A server that cannot
			 * verify its own host's certificate is common on this fleet, and the body is read only
			 * to report on the markup this plugin printed into it.
			 */
			'sslverify'   => false,
			'headers'     => array( 'Accept' => 'text/html' ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Not cached, for the same reason a transport failure on the client read is not: one blip
		// would pin an unactionable warning on the panel for five minutes.
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		/* translators: %d: an HTTP status code. */
		$probe = array( 'error' => sprintf( __( 'HTTP %d', 'sahaj-atlas' ), $code ) );
	} else {
		$probe = sahaj_atlas_read_page( (string) wp_remote_retrieve_body( $response ) );
	}

	set_transient( SAHAJ_ATLAS_PROBE_TRANSIENT, $probe, SAHAJ_ATLAS_CHECK_TTL );

	return isset( $probe['error'] ) ? new WP_Error( 'sahaj_atlas_probe', (string) $probe['error'] ) : $probe;
}

/**
 * What the Atlas page's own HTML says about itself.
 *
 * @param string $html The page as a visitor received it.
 * @return array{page:bool, render:string, loader:bool, module:bool, async:bool, keyed:bool}
 */
function sahaj_atlas_read_page( $html ) {
	preg_match( '/<sahaj-atlas\b[^>]*>/', $html, $element );
	preg_match( '/data-sahaj-atlas-render="([a-z]+)"/', isset( $element[0] ) ? $element[0] : '', $render );
	preg_match( '#<script\b[^>]*\bsrc="[^"]*/auto\.js[^"]*"[^>]*>#', $html, $loader );

	$tag = isset( $loader[0] ) ? $loader[0] : '';

	return array(
		'page'   => (bool) preg_match( '/<body[^>]*\bclass="[^"]*\bsahaj-atlas-page\b/', $html ),
		'render' => isset( $render[1] ) ? $render[1] : '',
		// ⚠ A flag, never the tag. The tag carries the API key in its `src`, and this array is
		// written to a transient — the key is already an option, and twice is once too many.
		'loader' => '' !== $tag,
		'module' => (bool) preg_match( '/\btype=(["\'])module\1/', $tag ),
		// ⚠ Whitespace before it, or `data-async` from an optimiser's own marker reads as `async`
		// and reports a working page broken.
		'async'  => (bool) preg_match( '/\sasync[\s=>]/', $tag ),
		'keyed'  => false !== strpos( $tag, 'key=' ),
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
