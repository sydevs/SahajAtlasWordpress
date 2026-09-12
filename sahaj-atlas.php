<?php
/**
 * Plugin Name:       Sahaj Atlas
 * Plugin URI:        https://github.com/sydevs/SahajAtlasWordpress
 * Description:       Adds the Sahaj Atlas — a searchable map of free meditation classes — to your site.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Sahaja Yoga Developers
 * Author URI:        https://github.com/sydevs
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sahaj-atlas
 * Update URI:        https://github.com/sydevs/SahajAtlasWordpress
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * ⚠ This file holds only the header, constants, requires, and one `init` hook.
 *
 * WordPress 6.7 raises `_doing_it_wrong` for just-in-time textdomain loading. This means no
 * translated string may run before `init`. This rule applies at file scope, in an activation hook,
 * and on `plugins_loaded`. Every module below registers its hooks from `sahaj_atlas_init()` instead.
 */

define( 'SAHAJ_ATLAS_VERSION', '0.1.0' );
define( 'SAHAJ_ATLAS_FILE', __FILE__ );
define( 'SAHAJ_ATLAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAHAJ_ATLAS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The two origins this plugin talks to.
 *
 * A constant can override these for local development. A setting cannot, on purpose. A host that
 * can point the widget at another origin can be pointed at any origin. A `wp-config.php` line
 * always serves this need better than a setting does.
 */
defined( 'SAHAJ_ATLAS_WIDGET_ORIGIN' ) || define( 'SAHAJ_ATLAS_WIDGET_ORIGIN', 'https://sahajatlas.com' );
defined( 'SAHAJ_ATLAS_API_ORIGIN' ) || define( 'SAHAJ_ATLAS_API_ORIGIN', 'https://cloud.sydevelopers.com' );

/** Option names. Always use `get_option`, never `get_site_option`. At least one target site is multisite. */
define( 'SAHAJ_ATLAS_OPTION_KEY', 'sahaj_atlas_api_key' );
define( 'SAHAJ_ATLAS_OPTION_PAGE', 'sahaj_atlas_page_id' );
/**
 * ⚠ The second site setting, and the whole permitted surface. It exists for one use case: a host
 * that writes its own description for the Atlas page and needs a way to say so. See
 * `sahaj_atlas_seo_host_describes_root()`.
 */
define( 'SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT', 'sahaj_atlas_seo_root_opt_out' );

/** The query var the path router hands to the widget's page. */
define( 'SAHAJ_ATLAS_ROUTE_VAR', 'sahaj_atlas_route' );

require_once SAHAJ_ATLAS_DIR . 'includes/embed.php';
require_once SAHAJ_ATLAS_DIR . 'includes/page.php';
require_once SAHAJ_ATLAS_DIR . 'includes/routing.php';
require_once SAHAJ_ATLAS_DIR . 'includes/shortcode.php';
require_once SAHAJ_ATLAS_DIR . 'includes/settings.php';
require_once SAHAJ_ATLAS_DIR . 'includes/diagnostics.php';
require_once SAHAJ_ATLAS_DIR . 'includes/seo.php';
require_once SAHAJ_ATLAS_DIR . 'includes/sitemap.php';
require_once SAHAJ_ATLAS_DIR . 'includes/updates.php';

add_action( 'init', 'sahaj_atlas_init' );

/**
 * Everything that needs a translated string, a block, or a registered setting.
 */
function sahaj_atlas_init() {
	sahaj_atlas_register_block();
	sahaj_atlas_register_shortcode();
	sahaj_atlas_register_settings();
	sahaj_atlas_register_page_template();
	sahaj_atlas_register_route_var();
	sahaj_atlas_register_sitemap();
	sahaj_atlas_register_updates();
}

/** Hooks that must bind before `init` and produce no translated text. */
add_action( 'parse_request', 'sahaj_atlas_parse_request' );
add_filter( 'redirect_canonical', 'sahaj_atlas_suppress_canonical_redirect' );
add_action( 'template_redirect', 'sahaj_atlas_resolve_and_enqueue' );
// ⚠ This hook runs at priority 11, after the embed resolves at priority 10. This means the SEO
// takeover runs only on a page that already carries the widget. It also reads the route that the
// resolver already validated.
add_action( 'template_redirect', 'sahaj_atlas_seo_boot', 11 );
// ⚠ This uses `wp_footer`, not `wp_body_open`. Both templates now print the element in the normal
// page flow. A contained map draws where its element sits, so the element must come after the
// header. This hook is only a last-resort fallback. It does nothing if the flow print already
// happened.
add_action( 'wp_footer', 'sahaj_atlas_render_element_once', 1 );
add_action( 'wp_enqueue_scripts', 'sahaj_atlas_enqueue_page_assets' );
add_filter( 'body_class', 'sahaj_atlas_body_class' );
add_filter( 'template_include', 'sahaj_atlas_template_include' );
add_action( 'admin_menu', 'sahaj_atlas_admin_menu' );
add_action( 'admin_post_sahaj_atlas_create_page', 'sahaj_atlas_handle_create_page' );
add_action( 'admin_notices', 'sahaj_atlas_admin_notices' );
