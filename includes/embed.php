<?php
/**
 * The one embed on a page: which one wins, what its script URL is, and where its element goes.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * The embed chosen for this request, or null. Shape: array{map:bool, atlas:string, source:string}.
 *
 * @var array|null
 */
$GLOBALS['sahaj_atlas_active'] = null;

/** Whether the `<sahaj-atlas>` element has already been printed for this request. */
$GLOBALS['sahaj_atlas_printed'] = false;

/**
 * Decide the page's one embed and enqueue the widget for it.
 *
 * ⚠ This runs once, server-side, on `template_redirect`. The widget refuses a second copy
 * (`resolveElement()` in the loader), so two embeds on one page means one of them silently does
 * nothing. Deciding here, before `wp_head` and before any render callback runs, means the choice
 * comes from the whole post, not from whichever block happens to render first.
 */
function sahaj_atlas_resolve_and_enqueue() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}

	$key = sahaj_atlas_api_key();

	if ( '' === $key ) {
		return;
	}

	$active = sahaj_atlas_resolve_embed();

	if ( null === $active ) {
		return;
	}

	$GLOBALS['sahaj_atlas_active'] = $active;

	/*
	 * ⚠ Use `wp_enqueue_script_module`, never `wp_enqueue_script`. `auto.js` is a real ES module. Its
	 * first statement is a top-level `import`, which is a SyntaxError in a classic script. So
	 * `'strategy' => 'defer'` is not a fallback — it is a hard break.
	 *
	 * The version argument is `null` on purpose. `false` would make core append `?ver=<wp version>`
	 * to a URL whose query string is the widget's whole configuration. The widget origin already
	 * serves these files `must-revalidate`, so cache-busting is handled there.
	 */
	wp_enqueue_script_module( 'sahaj-atlas', sahaj_atlas_script_url( $active ), array(), null );
}

/**
 * Which embed this page has, in priority order: the Atlas page, then a block, then a shortcode.
 *
 * @return array|null
 */
function sahaj_atlas_resolve_embed() {
	if ( sahaj_atlas_is_atlas_page() ) {
		return array(
			'map'    => true,
			'atlas'  => '',
			'source' => 'page',
		);
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	if ( has_block( 'sahaj-atlas/embed', $post ) ) {
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$found = sahaj_atlas_find_block( $block );

			if ( null !== $found ) {
				return $found;
			}
		}
	}

	if ( has_shortcode( (string) $post->post_content, 'sahaj_atlas' ) ) {
		// The attributes are read again when the shortcode renders. This only confirms that one
		// exists, so the script is enqueued before `wp_head`.
		return sahaj_atlas_embed_from_shortcode( (string) $post->post_content );
	}

	return null;
}

/**
 * Depth-first search for our block, so one nested in a group or column still counts.
 *
 * @param array $block A parsed block.
 * @return array|null
 */
function sahaj_atlas_find_block( $block ) {
	if ( isset( $block['blockName'] ) && 'sahaj-atlas/embed' === $block['blockName'] ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		return sahaj_atlas_normalize_attrs( $attrs, 'block' );
	}

	if ( ! empty( $block['innerBlocks'] ) ) {
		foreach ( $block['innerBlocks'] as $inner ) {
			$found = sahaj_atlas_find_block( $inner );

			if ( null !== $found ) {
				return $found;
			}
		}
	}

	return null;
}

/**
 * Pull the first `[sahaj_atlas]`'s attributes out of post content.
 *
 * @param string $content Post content.
 * @return array|null
 */
function sahaj_atlas_embed_from_shortcode( $content ) {
	$pattern = get_shortcode_regex( array( 'sahaj_atlas' ) );

	if ( ! preg_match( '/' . $pattern . '/s', $content, $match ) ) {
		return null;
	}

	$attrs = shortcode_parse_atts( isset( $match[3] ) ? $match[3] : '' );

	return sahaj_atlas_normalize_attrs( is_array( $attrs ) ? $attrs : array(), 'shortcode' );
}

/**
 * One shape for block attributes and shortcode attributes alike.
 *
 * In-content embeds default to `map=false`. An unbounded map fills the whole viewport, so a map
 * embed inside an article would cover the article. The Atlas page is the place for a map.
 *
 * @param array  $attrs  Raw attributes.
 * @param string $source Where they came from.
 * @return array
 */
function sahaj_atlas_normalize_attrs( $attrs, $source ) {
	$map = isset( $attrs['map'] ) ? $attrs['map'] : false;

	if ( is_string( $map ) ) {
		$map = ! in_array( strtolower( trim( $map ) ), array( '', '0', 'false', 'no' ), true );
	}

	$atlas = isset( $attrs['atlas'] ) ? sahaj_atlas_clean_route( (string) $attrs['atlas'] ) : '';

	return array(
		'map'    => (bool) $map,
		'atlas'  => $atlas,
		'source' => $source,
	);
}

/**
 * A route the widget will accept, or an empty string.
 *
 * The widget refuses anything that is not site-relative. Its own `safePath` rejects
 * protocol-relative `//evil.com`, and the tab, LF and CR forms the URL parser strips before
 * parsing. This function rejects the same shapes, so a bad value in a shortcode never reaches an
 * attribute at all.
 *
 * @param string $route Candidate route.
 * @return string
 */
function sahaj_atlas_clean_route( $route ) {
	$route = trim( $route );

	if ( '' === $route ) {
		return '';
	}

	// Strip the characters the WHATWG URL parser removes before parsing, so `/\tevil` cannot
	// become `//evil` downstream.
	$route = str_replace( array( "\t", "\n", "\r" ), '', $route );

	if ( 0 !== strpos( $route, '/' ) ) {
		return '';
	}

	if ( 0 === strpos( $route, '//' ) || 0 === strpos( $route, '/\\' ) ) {
		return '';
	}

	return $route;
}

/**
 * The widget's script URL, which is its entire configuration surface.
 *
 * @param array $embed The resolved embed.
 * @return string
 */
function sahaj_atlas_script_url( $embed ) {
	$args = array( 'key' => sahaj_atlas_api_key() );

	/*
	 * ⚠ Send `map=false`, never `map=true`. The widget's spelling rule turns a boolean off only for
	 * the exact strings `false` or `0`. Any other value, including a missing parameter, leaves it
	 * on. Sending the default back only adds noise to a URL a host can read.
	 */
	if ( empty( $embed['map'] ) ) {
		$args['map'] = 'false';
	}

	if ( '' !== $embed['atlas'] ) {
		$args['atlas'] = $embed['atlas'];
	}

	if ( 'page' === $embed['source'] && sahaj_atlas_path_routing_viable() ) {
		$args['routing'] = 'path';
	}

	/*
	 * `locale` is deliberately absent. The widget reads the page's `<html lang>` attribute, which
	 * WordPress already sets from the site language. A second source of truth here could only
	 * disagree with the page it sits on.
	 */
	return add_query_arg( $args, SAHAJ_ATLAS_WIDGET_ORIGIN . '/auto.js' );
}

/**
 * Print `<sahaj-atlas></sahaj-atlas>` for the Atlas page, in the flow after the header.
 *
 * ⚠ Printing an explicit element stops the placement of core's script tag from mattering. Core
 * prints script modules at `wp_footer` on a classic theme, and in `<head>` on a block theme
 * (`WP_Script_Modules::add_hooks()`). The Atlas page template omits the footer markup, and the
 * loader refuses `<head>` outright — it logs "could not find a place to render" instead of
 * guessing. An element that already exists gets adopted wherever it sits.
 *
 * ⚠ Element placement is now load-bearing, though it was not before. A contained map draws inside
 * its own element box (SahajAtlasWeb#170), so both templates print it after the header, not at
 * `wp_body_open` — that would place the atlas above the header. Only the classic template calls
 * this function (`templates/atlas-page.php:52`). The block template instead renders
 * `wp:sahaj-atlas/page`, whose callback is `sahaj_atlas_render_page_block()`. `sahaj-atlas.php`
 * keeps a `wp_footer` hook at priority 1 as a last-resort fallback, for a theme that runs neither
 * template — it no-ops once the flow print has already happened. (The old transform-ancestor
 * argument for `wp_body_open` retired with the fixed overlay it protected. `AGENTS.md`, "Traps
 * already paid for", covers that inversion.)
 *
 * ⚠ The element is sized, and that is load-bearing — the opposite of what this file said before
 * #170. `display: block` plus a definite height opts into a contained map. Remove the sizing, and
 * the map reverts to `position: fixed; inset: 0`, covering the header this page renders. That is
 * why the page shipped with no header until #170. Which sizer applies depends on the path, and the
 * split is deliberate. The Atlas page's element carries no inline style. `assets/atlas-page.css`
 * sizes it instead (`body.sahaj-atlas-page sahaj-atlas`), so a host can override the height with
 * ordinary CSS, not `!important`. Only in-content embeds are sized inline, by
 * `sahaj_atlas_element_markup()`. `min-height` is not a height — see that function's note.
 */
function sahaj_atlas_render_element_once() {
	echo sahaj_atlas_page_element_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below; children escaped in includes/seo.php.
}

/**
 * Render the Atlas page's element as a block, for the block-theme template.
 *
 * ⚠ This block is registered in PHP, with no `block.json` and no editor script, so it never
 * appears in the inserter. It is an implementation detail of the registered page template, not
 * something a volunteer places. It exists because the template markup must go through
 * `sahaj_atlas_page_element_markup()`, not a literal `<sahaj-atlas></sahaj-atlas>` tag. The SEO
 * takeover renders crawlable content as the element's children, and static template markup cannot
 * carry that. A literal element would also miss the printed-once flag, and let the `wp_footer`
 * fallback print a second element, which the widget refuses.
 *
 * @return string
 */
function sahaj_atlas_render_page_block() {
	return sahaj_atlas_page_element_markup();
}

/**
 * The Atlas page's element, once per request.
 *
 * @return string Empty after the first call, or when this page's embed is not the Atlas page's.
 */
function sahaj_atlas_page_element_markup() {
	$active = $GLOBALS['sahaj_atlas_active'];

	if ( null === $active || 'page' !== $active['source'] || $GLOBALS['sahaj_atlas_printed'] ) {
		return '';
	}

	$GLOBALS['sahaj_atlas_printed'] = true;

	/*
	 * ⚠ No inline style here, and that is deliberate. `assets/atlas-page.css` sizes this element
	 * instead. The rule lives in a stylesheet, so a host can override it — a taller header, a fixed
	 * height, their own layout — with ordinary CSS. An inline style would need `!important` to
	 * override, on the one property that decides whether the map is contained at all.
	 */
	return '<sahaj-atlas>' . sahaj_atlas_element_children() . '</sahaj-atlas>';
}

/**
 * The crawlable content rendered inside the element, or an empty string.
 *
 * The widget replaces its own children the moment it boots. So this is what a crawler sees, and
 * what a visitor with no JavaScript sees, and nothing else ever renders it. This is a filter, not
 * a direct call, because Phase 1 ships without one: an install with no SEO takeover prints an
 * empty element, exactly as before.
 *
 * ⚠ Everything the filter returns is markup that never passes through `wp_kses`. Escaping is the
 * producer's job. `includes/seo.php` escapes each value itself, and nothing here can check that.
 *
 * @return string
 */
function sahaj_atlas_element_children() {
	return (string) apply_filters( 'sahaj_atlas_element_children', '' );
}

/**
 * The element for an in-content embed, printed where the block or shortcode sits.
 *
 * @param array $embed The embed being rendered.
 * @return string
 */
function sahaj_atlas_element_markup( $embed ) {
	$active = $GLOBALS['sahaj_atlas_active'];

	// Print nothing if this is not the embed that won, or one is already rendered. The widget
	// would refuse a second element anyway, and an empty box confuses people less than a broken one.
	if ( null === $active || $GLOBALS['sahaj_atlas_printed'] || $active['source'] !== $embed['source'] ) {
		return '';
	}

	$GLOBALS['sahaj_atlas_printed'] = true;

	/*
	 * ⚠ Use `height`, not `min-height`. This was a real defect until SahajAtlasWeb#170 wrote the
	 * rule down. The widget fills its element with `height: 100%`, which needs a definite height
	 * to resolve against. `min-height: 640px` sizes the element on screen, but leaves the widget
	 * nothing to fill. So the widget refuses the box, logs a console message, and reverts to
	 * covering the whole browser window — an in-content embed then overtakes the article it sits
	 * in. The old comment here justified `min-height` as a way to let a theme grow the box. That
	 * choice bought a takeover instead.
	 *
	 * Both modes are sized now. A map embed with a height is a contained map. It lives inside this
	 * box, in its own stacking context, and is never asked the compact-card question at all.
	 */
	$style = ' style="display:block;height:' . ( empty( $embed['map'] ) ? '640px' : '520px' ) . '"';

	return '<sahaj-atlas' . $style . '>' . sahaj_atlas_element_children() . '</sahaj-atlas>';
}

/**
 * Load the Atlas page's layout, and only there.
 *
 * ⚠ This runs on `wp_enqueue_scripts`, gated to the Atlas page only. The stylesheet gives
 * `<sahaj-atlas>` a height, which makes the map a contained one. Loading it anywhere else would
 * box in a map that should fill the window.
 */
function sahaj_atlas_enqueue_page_assets() {
	if ( ! sahaj_atlas_is_atlas_page() ) {
		return;
	}

	wp_enqueue_style( 'sahaj-atlas-page', SAHAJ_ATLAS_URL . 'assets/atlas-page.css', array(), SAHAJ_ATLAS_VERSION );
	wp_enqueue_script( 'sahaj-atlas-page', SAHAJ_ATLAS_URL . 'assets/atlas-page.js', array(), SAHAJ_ATLAS_VERSION, false );
}

function sahaj_atlas_api_key() {
	return trim( (string) get_option( SAHAJ_ATLAS_OPTION_KEY, '' ) );
}

/**
 * Register the block, and the editor assets it names.
 *
 * ⚠ `block.json` references the script by handle, not by a `file:` path. The script itself is
 * registered here, in PHP. With a `file:` path, core looks for an `index.asset.php` dependency
 * manifest — the file a webpack build normally produces. When that file is missing, core silently
 * registers the script with no dependencies, so the editor script can run before `wp-blocks`
 * exists. Naming a handle we registered ourselves lets this plugin ship with no build step, and
 * still declare what it needs.
 */
function sahaj_atlas_register_block() {
	wp_register_script(
		'sahaj-atlas-editor',
		SAHAJ_ATLAS_URL . 'blocks/embed/editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		SAHAJ_ATLAS_VERSION,
		true
	);

	wp_register_style(
		'sahaj-atlas-editor-style',
		SAHAJ_ATLAS_URL . 'blocks/embed/editor.css',
		array(),
		SAHAJ_ATLAS_VERSION
	);

	register_block_type( SAHAJ_ATLAS_DIR . 'blocks/embed' );

	// The template-only element block. See `sahaj_atlas_render_page_block()` for why it exists and
	// why it is invisible to the editor.
	register_block_type(
		'sahaj-atlas/page',
		array(
			'render_callback' => 'sahaj_atlas_render_page_block',
			'supports'        => array( 'inserter' => false ),
		)
	);
}
