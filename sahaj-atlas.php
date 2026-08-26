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
 * ⚠ This file holds NOTHING but the header, constants, requires and one `init` hook.
 *
 * WordPress 6.7 made just-in-time textdomain loading raise `_doing_it_wrong`, so no translated
 * string may be produced before `init` — not at file scope, not in an activation hook, not on
 * `plugins_loaded`. Every module below therefore registers its hooks from `sahaj_atlas_init()`.
 */

define( 'SAHAJ_ATLAS_VERSION', '0.1.0' );
define( 'SAHAJ_ATLAS_FILE', __FILE__ );
define( 'SAHAJ_ATLAS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAHAJ_ATLAS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The two origins the plugin talks to.
 *
 * Overridable by constant for local development, deliberately NOT by a setting: a host who can
 * point the widget at another origin can be pointed at anyone's, and there is no case for it that
 * a `wp-config.php` line does not serve better.
 */
defined( 'SAHAJ_ATLAS_WIDGET_ORIGIN' ) || define( 'SAHAJ_ATLAS_WIDGET_ORIGIN', 'https://sahajatlas.com' );
defined( 'SAHAJ_ATLAS_API_ORIGIN' ) || define( 'SAHAJ_ATLAS_API_ORIGIN', 'https://cloud.sydevelopers.com' );

/** Option names. Plain `get_option`, never `get_site_option` — at least one target site is multisite. */
define( 'SAHAJ_ATLAS_OPTION_KEY', 'sahaj_atlas_api_key' );
define( 'SAHAJ_ATLAS_OPTION_PAGE', 'sahaj_atlas_page_id' );

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
// ⚠ After the embed is resolved (priority 10): the SEO takeover only runs on a page that actually
// carries the widget, and it reads the route the resolver has already validated.
add_action( 'template_redirect', 'sahaj_atlas_seo_boot', 11 );
// ⚠ `wp_footer`, not `wp_body_open` — a LAST-RESORT fallback now that both templates print the
// element in the flow (a contained map draws where its element sits, so it must come after the
// header). It no-ops when the flow print already happened.
add_action( 'wp_footer', 'sahaj_atlas_render_element_once', 1 );
add_action( 'wp_enqueue_scripts', 'sahaj_atlas_enqueue_page_assets' );
add_filter( 'body_class', 'sahaj_atlas_body_class' );
add_filter( 'template_include', 'sahaj_atlas_template_include' );
add_action( 'admin_menu', 'sahaj_atlas_admin_menu' );
add_action( 'admin_post_sahaj_atlas_create_page', 'sahaj_atlas_handle_create_page' );
add_action( 'admin_notices', 'sahaj_atlas_admin_notices' );
