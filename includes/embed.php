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
	// ⚠ `is_feed()` too. A feed request is singular and reaches this far, and the element belongs
	// in a page body — `sahaj_atlas_content_element()` would otherwise spend the one print on a
	// feed item and leave the page itself empty.
	if ( is_admin() || is_feed() || ! is_singular() ) {
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

	/*
	 * ⚠ An editor canvas gets the static placeholder instead, and leaving this global null is what
	 * withholds the element the widget would otherwise mount — see
	 * `sahaj_atlas_editor_placeholder()`. The Atlas page is excluded on purpose: the widget there is
	 * the page, and that behaviour predates this guard.
	 */
	if ( 'page' !== $active['source'] && sahaj_atlas_editor_canvas() ) {
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
 * Which embed this page has, in priority order: the Atlas page, a Beaver Builder layout, a block,
 * then a shortcode.
 *
 * @return array|null
 */
function sahaj_atlas_resolve_embed() {
	if ( sahaj_atlas_is_atlas_page() ) {
		return array(
			'map'    => true,
			'atlas'  => sahaj_atlas_start_route(),
			'source' => 'page',
		);
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	/*
	 * ⚠ Ahead of both `post_content` scans, and decisive either way. `FLBuilder::render_content()`
	 * replaces `the_content` wholesale on a builder-enabled post, so a block or shortcode a
	 * conversion left behind is markup the page never serves. Resolving one would enqueue `auto.js`
	 * for an element nothing prints, and the visitor would get the script and no map.
	 */
	if ( sahaj_atlas_bb_enabled( $post ) ) {
		return sahaj_atlas_bb_embed( $post );
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
 * Whether a page builder is rendering this request into its own editor canvas.
 *
 * The widget cannot run usefully inside one — `blocks/embed/editor.js` explains why the block
 * editor shows a placeholder, and the reasoning is the same here. This names the rule, so a second
 * builder is one more term on this line rather than a second vendor check at each call site.
 *
 * @return bool
 */
function sahaj_atlas_editor_canvas() {
	return sahaj_atlas_bb_editing();
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
	// The map by default, for a shortcode and a block alike. `map="false"` asks for the list alone.
	$map = isset( $attrs['map'] ) ? $attrs['map'] : true;

	if ( is_string( $map ) ) {
		$map = ! in_array( strtolower( trim( $map ) ), array( '', '0', 'false', 'no' ), true );
	}

	$atlas = isset( $attrs['atlas'] ) ? sahaj_atlas_route_from_input( (string) $attrs['atlas'] ) : '';

	return array(
		'map'    => (bool) $map,
		'atlas'  => $atlas,
		'ratio'  => sahaj_atlas_clean_ratio( isset( $attrs['ratio'] ) ? (string) $attrs['ratio'] : '' ),
		'source' => $source,
	);
}

/**
 * An in-content embed's shape, as CSS `aspect-ratio` reads it: width, then height.
 *
 * `16:9`, `16/9` and `16x9` all mean sixteen wide for every nine tall. Anything else — empty, a
 * zero, a shape more extreme than 4:1 either way, or a value carrying more than two numbers — is
 * the default, 1:1 — a square. The value lands in a `style` attribute, so it is rebuilt from the two numbers
 * rather than passed through.
 *
 * @param string $value What the shortcode or block was given.
 * @return string `W/H`.
 */
function sahaj_atlas_clean_ratio( $value ) {
	if ( ! preg_match( '/^\s*(\d{1,4}(?:\.\d{1,2})?)\s*[:\/x]\s*(\d{1,4}(?:\.\d{1,2})?)\s*$/i', $value, $parts ) ) {
		return '1/1';
	}

	$width  = (float) $parts[1];
	$height = (float) $parts[2];

	if ( $width <= 0 || $height <= 0 || $width / $height > 4 || $height / $width > 4 ) {
		return '1/1';
	}

	return ( 0 + $parts[1] ) . '/' . ( 0 + $parts[2] );
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
 * A route from what a volunteer typed or pasted, or an empty string.
 *
 * The README tells volunteers to open their country on sahajatlas.com and paste the address, so
 * that address has to work. So do the same view's address on their own Atlas page, in either
 * routing shape, and a bare `gb/london`. Any other site's address names nothing here, and is
 * refused rather than read as a path.
 *
 * @param string $value A route, a slug path, or an address.
 * @return string
 */
function sahaj_atlas_route_from_input( $value ) {
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	if ( preg_match( '#^https?://#i', $value ) ) {
		parse_str( (string) wp_parse_url( $value, PHP_URL_QUERY ), $args );

		if ( isset( $args[ SAHAJ_ATLAS_ROUTE_PARAM ] ) && is_string( $args[ SAHAJ_ATLAS_ROUTE_PARAM ] ) ) {
			return sahaj_atlas_clean_route( $args[ SAHAJ_ATLAS_ROUTE_PARAM ] );
		}

		$host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
		$path = untrailingslashit( (string) wp_parse_url( $value, PHP_URL_PATH ) );

		if ( strtolower( (string) wp_parse_url( SAHAJ_ATLAS_WIDGET_ORIGIN, PHP_URL_HOST ) ) === $host ) {
			return sahaj_atlas_clean_route( $path );
		}

		$page = sahaj_atlas_page_id() ? get_permalink( sahaj_atlas_page_id() ) : '';
		$base = $page ? untrailingslashit( (string) wp_parse_url( $page, PHP_URL_PATH ) ) : '';

		if ( '' !== $base && strtolower( (string) wp_parse_url( $page, PHP_URL_HOST ) ) === $host && 0 === strpos( $path . '/', $base . '/' ) ) {
			return sahaj_atlas_clean_route( (string) substr( $path, strlen( $base ) ) );
		}

		return '';
	}

	if ( 0 !== strpos( $value, '/' ) ) {
		$value = '/' . $value;
	}

	return sahaj_atlas_clean_route( '/' === $value ? '' : untrailingslashit( $value ) );
}

/**
 * Where the Atlas page opens: the volunteer's chosen route, or an empty string for the widget's own
 * default — the client record's home region when SahajCloud has one, the world list otherwise.
 *
 * ⚠ It is the widget's `atlas` parameter, a default and never an override. A visitor whose address
 * already names a route gets that route. And the page's metadata stays the root view's: the page's
 * own address is still the root, and the start route has a canonical of its own.
 *
 * @return string
 */
function sahaj_atlas_start_route() {
	return sahaj_atlas_clean_route( (string) get_option( SAHAJ_ATLAS_OPTION_START_ROUTE, '' ) );
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

	// ⚠ An in-content map sits partway down a page the visitor must be able to scroll past. Without
	// this, one finger or the mouse wheel over the map pans and zooms it instead, and the page stops
	// scrolling there. With it, the map moves on two fingers or Ctrl/⌘ + wheel (SahajAtlasWeb#251).
	// Never on the Atlas page, which fills the screen and has nothing to scroll to.
	if ( 'page' !== $embed['source'] && ! empty( $embed['map'] ) ) {
		$args['gestures'] = 'cooperative';
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
 * `wp:sahaj-atlas/page`, whose callback is `sahaj_atlas_render_page_block()`. (The old
 * transform-ancestor argument for `wp_body_open` retired with the fixed overlay it protected.
 * `AGENTS.md`, "Traps already paid for", covers that inversion.)
 *
 * ⚠ Neither of those templates runs when something else claims the page, which is the common case
 * on this fleet rather than the exotic one. `sahaj_atlas_content_element()` and
 * `sahaj_atlas_render_element_fallback()` are the other two prints, in that order of preference.
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
	echo sahaj_atlas_page_element_markup( 'template' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below; children escaped in includes/seo.php.
}

/**
 * Print the element in the content area, for a template this plugin did not supply.
 *
 * A page builder's canvas template, a theme-builder layout, a maintenance-mode plugin, or a
 * volunteer switching the page's template all replace the plugin's template with one that renders
 * header, content and footer. The Atlas page's content is empty, so this is where the element
 * belongs: inside the page, above the theme's own footer, which stays.
 *
 * ⚠ Refuse every caller that is not rendering the page body. `wp_trim_excerpt()` runs the content
 * through this same filter, and an SEO plugin asks for an excerpt while `<head>` is being built —
 * either one would spend the single print on a string nobody renders, leaving the page with no
 * element at all and the `wp_footer` fallback no-opping behind it.
 *
 * ⚠ Late priority, after `wpautop()`. At the default priority `wpautop()` wraps the element in a
 * `<p>`, whose margins then push the contained map's bottom edge past the fold.
 *
 * @param string $content The post content.
 * @return string
 */
function sahaj_atlas_content_element( $content ) {
	if ( doing_filter( 'get_the_excerpt' ) || doing_action( 'wp_head' ) ) {
		return $content;
	}

	if ( ! sahaj_atlas_is_atlas_page() || (int) get_the_ID() !== sahaj_atlas_page_id() ) {
		return $content;
	}

	return $content . sahaj_atlas_page_element_markup( 'content' );
}

/**
 * Print the element at `wp_footer`, when no earlier print happened.
 *
 * ⚠ This lands after the theme's footer, where `assets/atlas-page.js` measures the element's top at
 * the whole document's height and the map computes to nothing. It is the last resort, for a
 * template that runs neither this plugin's template nor `the_content`, and the loopback check in
 * `includes/diagnostics.php` turns red on it rather than letting it pass as healthy.
 */
function sahaj_atlas_render_element_fallback() {
	echo sahaj_atlas_page_element_markup( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built below; children escaped in includes/seo.php.
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
	return sahaj_atlas_page_element_markup( 'template' );
}

/**
 * The Atlas page's element, once per request.
 *
 * @param string $render Which of the three prints is asking: `template`, `content` or `footer`.
 * @return string Empty after the first call, or when this page's embed is not the Atlas page's.
 */
function sahaj_atlas_page_element_markup( $render ) {
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

	/*
	 * ⚠ `data-sahaj-atlas-render` is read back by the loopback check in `includes/diagnostics.php`,
	 * which is the only way the admin learns that the footer fallback is carrying the page. Nothing
	 * in the page can be derived from instead: a theme's footer markup varies too much to locate,
	 * and the plugin knows which print ran only while it is rendering. The widget ignores it.
	 */
	return '<sahaj-atlas data-sahaj-atlas-render="' . esc_attr( $render ) . '">'
		. sahaj_atlas_element_children() . '</sahaj-atlas>';
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
	 * The box takes the column's full width at its ratio — square,
	 * unless the embed asks for another — which an `aspect-ratio` makes a definite height
	 * as surely as `height` does. It is capped at 80% of the screen, so a wide desktop column never
	 * gets a map taller than the window — which would put the mobile sheet's drag handle, and every
	 * way past the map, below the fold. A map embed sized like this is a contained map: it lives
	 * inside this box, in its own stacking context. Under the widget's 360×420 floors — a square
	 * in a column under 420px wide — it shows the compact card instead, whose button opens it
	 * full-screen. A square is the default: it clears that floor from 420px wide, where 4:3 needed
	 * 560px. A phone's column, around 350px, is under the 360px width floor either way.
	 */
	$ratio = isset( $embed['ratio'] ) ? sahaj_atlas_clean_ratio( $embed['ratio'] ) : '1/1';
	$style = ' style="display:block;width:100%;aspect-ratio:' . $ratio . ';max-height:80vh"';

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
	/*
	 * ⚠ The footer, never `<head>`. The script measures `<sahaj-atlas>` and observes `document.body`,
	 * and `<head>` has neither yet, so its first measure finds nothing and its observer never attaches
	 * (#41). Nothing here needs to run before the first paint — the stylesheet's `0px` fallback is
	 * what covers that.
	 */
	wp_enqueue_script( 'sahaj-atlas-page', SAHAJ_ATLAS_URL . 'assets/atlas-page.js', array(), SAHAJ_ATLAS_VERSION, true );
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

	/*
	 * ⚠ A `.mo` never reaches JavaScript. `wp.i18n.__()` reads a `.json` file, which core requests
	 * only for a handle that was named here. Without this call `blocks/embed/editor.js` renders
	 * English whatever translation is installed. `.github/scripts/i18n.sh` builds the `.json` files
	 * from the same `.po` the PHP side loads.
	 */
	wp_set_script_translations( 'sahaj-atlas-editor', 'sahaj-atlas', SAHAJ_ATLAS_DIR . 'languages' );

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
