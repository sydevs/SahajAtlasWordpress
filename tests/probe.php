<?php
/**
 * Tests the diagnostics loopback checks: which print rendered the element, and whether the loader's
 * script tag survived the page.
 *
 * ⚠ Fixture pre-mortem. The pages below assume two shapes this plugin itself prints. The element's
 * `data-sahaj-atlas-render` attribute comes from `sahaj_atlas_page_element_markup()` in
 * `includes/embed.php`, and the loader tag is what `WP_Script_Modules::print_enqueued_script_modules()`
 * emits through `wp_print_script_tag()` for `wp_enqueue_script_module()`: `type`, then `src`, then
 * `id="<handle>-js-module"`. Both were read from those sources rather than from the reader under
 * test, which would otherwise only assert that the reader reads itself. The render lane renders the
 * real thing and asserts the attribute there too.
 *
 * @package SahajAtlas
 */

/**
 * A fetched Atlas page, assembled from the parts each case varies.
 *
 * @param string $element The `<sahaj-atlas>` tag, or '' for a page with none.
 * @param string $loader  The loader's script tag, or '' for a page with none.
 * @param bool   $ours    Whether the body carries the Atlas page's class.
 * @return string
 */
function sahaj_probe_page( $element, $loader = '', $ours = true ) {
	$body = $ours ? '<body class="page sahaj-atlas-page">' : '<body class="page maintenance">';

	return '<!doctype html><html><head></head>' . $body . '<header>Site</header>' . $element . $loader . '</body></html>';
}

/**
 * The loader tag core prints for `wp_enqueue_script_module()`.
 *
 * @param string $extra Attributes an optimiser added, or '' for the intact tag.
 * @param string $type  The `type` attribute, or '' to drop it the way an optimiser does.
 * @param string $src   The script address.
 * @return string
 */
function sahaj_probe_loader( $extra = '', $type = ' type="module"', $src = 'https://sahajatlas.com/auto.js?key=test-key-123&#038;routing=path' ) {
	return '<script' . $type . ' src="' . $src . '" id="sahaj-atlas-js-module"' . $extra . '></script>';
}

/** Every URL the stub was asked for since the last `sahaj_probe_stub()`. */
$GLOBALS['sahaj_probe_requests'] = array();
/** What the stub answers with: `array{status:int, body:string}`. */
$GLOBALS['sahaj_probe_answer'] = array( 'status' => 200, 'body' => '' );

/**
 * Stand in for the loopback fetch of the Atlas page.
 *
 * @param false|array $pre  Short-circuit value.
 * @param array       $args Request arguments.
 * @param string      $url  The URL requested.
 * @return array A `wp_remote_get` response.
 */
function sahaj_probe_http_stub( $pre, $args, $url ) {
	$GLOBALS['sahaj_probe_requests'][] = $url;
	$GLOBALS['sahaj_probe_args']       = $args;

	return array(
		'headers'  => array(),
		'body'     => $GLOBALS['sahaj_probe_answer']['body'],
		'response' => array( 'code' => $GLOBALS['sahaj_probe_answer']['status'], 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Arm the stub with one page, forget every earlier request, and drop the cached probe.
 *
 * @param string $body   The page to answer with.
 * @param int    $status The status to answer with.
 */
function sahaj_probe_stub( $body, $status = 200 ) {
	$GLOBALS['sahaj_probe_requests'] = array();
	$GLOBALS['sahaj_probe_answer']   = array( 'status' => $status, 'body' => $body );

	delete_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );
}

/** The two rows one probe answers for, label => check. */
$GLOBALS['sahaj_probe_rows'] = array(
	'Map placement' => 'sahaj_atlas_check_render',
	'Map script'    => 'sahaj_atlas_check_loader',
);

add_filter( 'pre_http_request', 'sahaj_probe_http_stub', 10, 3 );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The loopback probe reads the page a visitor gets' );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader() ) );

$sahaj_probe_render = sahaj_atlas_check_render();

sahaj_is( 'it asks for the Atlas page itself', (string) get_permalink( sahaj_atlas_page_id() ), isset( $GLOBALS['sahaj_probe_requests'][0] ) ? $GLOBALS['sahaj_probe_requests'][0] : '' );
sahaj_is( 'once', 1, count( $GLOBALS['sahaj_probe_requests'] ) );

// ⚠ Both rows come from one fetch. Two would double a 10-second timeout on a host that refuses
// loopback requests, on a settings screen a volunteer is watching.
sahaj_atlas_check_loader();

sahaj_is( 'and the second check reuses that answer', 1, count( $GLOBALS['sahaj_probe_requests'] ) );

// ⚠ As core's own Site Health loopback test does. A server that cannot verify its own host's
// certificate is common on this fleet, and the body is read only to report on our own markup.
sahaj_ok( 'without verifying this host\'s certificate against itself', isset( $GLOBALS['sahaj_probe_args']['sslverify'] ) && false === $GLOBALS['sahaj_probe_args']['sslverify'] );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The placement row names the print that rendered the element' );

sahaj_is( 'the plugin\'s own template is healthy', 'ok', $sahaj_probe_render['status'] );
sahaj_ok( 'and says so', false !== strpos( $sahaj_probe_render['detail'], 'full-screen' ) );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="content"></sahaj-atlas>', sahaj_probe_loader() ) );

$sahaj_probe_content = sahaj_atlas_check_render();

// ⚠ Green, not a warning. A page builder rendering the Atlas page with its own template and footer
// is the documented outcome now, not a degraded one — `readme.txt` says the same.
sahaj_is( 'the content area is healthy too', 'ok', $sahaj_probe_content['status'] );
sahaj_ok( 'and is named as the content area', false !== strpos( $sahaj_probe_content['detail'], 'content area' ) );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="footer"></sahaj-atlas>', sahaj_probe_loader() ) );

$sahaj_probe_footer = sahaj_atlas_check_render();

sahaj_is( 'the footer fallback is a failure', 'fail', $sahaj_probe_footer['status'] );
sahaj_ok( 'naming the footer', false !== strpos( $sahaj_probe_footer['detail'], 'footer' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'A page with no element at all gets the right diagnosis' );

sahaj_probe_stub( sahaj_probe_page( '', sahaj_probe_loader() ) );

$sahaj_probe_none = sahaj_atlas_check_render();

sahaj_is( 'an Atlas page with no element is a failure', 'fail', $sahaj_probe_none['status'] );
sahaj_ok( 'and the volunteer is sent to us', false !== strpos( $sahaj_probe_none['detail'], 'maintainers' ) );

/*
 * ⚠ The other diagnosis entirely. No body class means the Atlas page never rendered — a
 * maintenance-mode plugin or a cache answered instead — and telling the volunteer the map is
 * missing would send them looking in the wrong place.
 */
sahaj_probe_stub( sahaj_probe_page( '', sahaj_probe_loader(), false ) );

$sahaj_probe_stolen = sahaj_atlas_check_render();

sahaj_is( 'an address something else answers is a failure', 'fail', $sahaj_probe_stolen['status'] );
sahaj_ok( 'and names caching and maintenance-mode plugins', false !== strpos( $sahaj_probe_stolen['detail'], 'maintenance-mode' ) );
sahaj_ok( 'not us', false === strpos( $sahaj_probe_stolen['detail'], 'maintainers' ) );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'The loader row reads the printed script tag' );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader() ) );

sahaj_is( 'an intact module tag is healthy', 'ok', sahaj_atlas_check_loader()['status'] );

// ⚠ The defect SahajAtlasWeb#239 handed here: `auto.js` opens with a top-level `import`, which is a
// SyntaxError in a classic script. The page gets a blank slot and one console error, and the module
// cannot report on itself from inside a tag that never ran.
sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader( '', '' ) ) );

sahaj_is( 'a tag stripped of type="module" is a failure', 'fail', sahaj_atlas_check_loader()['status'] );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader( ' async' ) ) );

sahaj_is( 'an asynchronous tag is a failure', 'fail', sahaj_atlas_check_loader()['status'] );

// ⚠ An optimiser's own marker attribute must not read as `async`. A confident wrong red sends a
// volunteer to us about a site that already works.
sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader( ' data-async-ignore="1"' ) ) );

sahaj_is( 'but data-async-ignore is not async', 'ok', sahaj_atlas_check_loader()['status'] );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>', sahaj_probe_loader( '', ' type="module"', 'https://sahajatlas.com/auto.js' ) ) );

sahaj_is( 'an address stripped of the key is a failure', 'fail', sahaj_atlas_check_loader()['status'] );

sahaj_probe_stub( sahaj_probe_page( '<sahaj-atlas data-sahaj-atlas-render="template"></sahaj-atlas>' ) );

sahaj_is( 'and no tag at all is a failure', 'fail', sahaj_atlas_check_loader()['status'] );

// ---------------------------------------------------------------------------------------------

sahaj_group( 'Neither row turns red on something the volunteer cannot act on' );

/*
 * ⚠ A warning, not a failure. This is the server failing to reach itself, which plenty of hosts
 * refuse outright, and it says nothing about the page a visitor gets.
 */
sahaj_probe_stub( 'Forbidden', 403 );

foreach ( $GLOBALS['sahaj_probe_rows'] as $sahaj_probe_label => $sahaj_probe_check ) {
	$sahaj_probe_unreachable = $sahaj_probe_check();

	sahaj_is( "$sahaj_probe_label: an unreachable page is a warning", 'warn', $sahaj_probe_unreachable['status'] );
	sahaj_ok( "$sahaj_probe_label: carrying what went wrong", false !== strpos( $sahaj_probe_unreachable['detail'], '403' ) );
}

// Nothing is fetched, and nothing is claimed, before the key works.
delete_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );
update_option( SAHAJ_ATLAS_OPTION_KEY, '' );

$GLOBALS['sahaj_probe_requests'] = array();

foreach ( $GLOBALS['sahaj_probe_rows'] as $sahaj_probe_label => $sahaj_probe_check ) {
	$sahaj_probe_idle = $sahaj_probe_check();

	sahaj_is( "$sahaj_probe_label: idle until the key works", 'idle', $sahaj_probe_idle['status'] );
	sahaj_is( "$sahaj_probe_label: under its own label", $sahaj_probe_label, $sahaj_probe_idle['label'] );
}

sahaj_is( 'and nothing was fetched', array(), $GLOBALS['sahaj_probe_requests'] );

update_option( SAHAJ_ATLAS_OPTION_KEY, 'test-key-123' );

remove_filter( 'pre_http_request', 'sahaj_probe_http_stub', 10 );
delete_transient( SAHAJ_ATLAS_PROBE_TRANSIENT );
