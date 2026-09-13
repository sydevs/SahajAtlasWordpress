<?php
/**
 * The settings screen: two fields, one button, and the status panel.
 *
 * ⚠ These two settings are the whole permitted surface. Every option is something to document,
 * migrate, support, and get wrong, and one non-technical volunteer installs this plugin per site.
 * The locale comes from the page's `<html lang>` attribute. The routing prefix and the brand come
 * from the client record. The map flag is set per embed. Adding a setting needs a use case
 * somebody actually encountered. The second one below has one: a host that wrote its own
 * description for the Atlas page, and no way to keep it once this plugin started supplying one.
 *
 * @package SahajAtlas
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the two options. On `init` — `register_setting()` takes translated labels.
 */
function sahaj_atlas_register_settings() {
	register_setting(
		'sahaj_atlas',
		SAHAJ_ATLAS_OPTION_KEY,
		array(
			'type'              => 'string',
			'label'             => __( 'Sahaj Atlas API key', 'sahaj-atlas' ),
			'sanitize_callback' => 'sahaj_atlas_sanitize_key',
			'default'           => '',
			// ⚠ Use `get_option`, never `get_site_option`. At least one target site is multisite,
			// and two sites sharing one key would report as one embed.
			'show_in_rest'      => false,
		)
	);

	register_setting(
		'sahaj_atlas',
		SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT,
		array(
			'type'              => 'string',
			'label'             => __( 'Let my SEO plugin describe the Atlas page', 'sahaj-atlas' ),
			'sanitize_callback' => 'sahaj_atlas_sanitize_checkbox',
			'default'           => '',
			// ⚠ Per site, like the key. On a multisite network one site may write its own atlas
			// description while the next one wants ours.
			'show_in_rest'      => false,
		)
	);
}

/**
 * A key is an opaque token from SahajCloud. Keep it to the characters one can contain.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function sahaj_atlas_sanitize_key( $value ) {
	return preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $value );
}

/**
 * A checkbox stores `1` or an empty string, and nothing else ever reaches the option.
 *
 * ⚠ An unchecked box posts nothing at all, and `options.php` then hands this callback `null`. The
 * form below also posts a hidden `0` before the box, so the value is never absent. Both halves
 * matter: the hidden field keeps the option honest when the form is submitted, and this callback
 * keeps it honest when anything else writes it.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function sahaj_atlas_sanitize_checkbox( $value ) {
	return '1' === (string) $value ? '1' : '';
}

/**
 * Add the settings page under Settings.
 */
function sahaj_atlas_admin_menu() {
	add_options_page(
		__( 'Sahaj Atlas', 'sahaj-atlas' ),
		__( 'Sahaj Atlas', 'sahaj-atlas' ),
		'manage_options',
		'sahaj-atlas',
		'sahaj_atlas_settings_page'
	);
}

function sahaj_atlas_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$key       = sahaj_atlas_api_key();
	$page_id   = sahaj_atlas_page_id();
	$has_page  = sahaj_atlas_page_is_healthy();
	$their_seo = sahaj_atlas_seo_host_describes_root();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Sahaj Atlas', 'sahaj-atlas' ); ?></h1>

		<form action="options.php" method="post">
			<?php settings_fields( 'sahaj_atlas' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="sahaj-atlas-key"><?php echo esc_html__( 'API key', 'sahaj-atlas' ); ?></label>
					</th>
					<td>
						<input
							id="sahaj-atlas-key"
							name="<?php echo esc_attr( SAHAJ_ATLAS_OPTION_KEY ); ?>"
							type="text"
							class="regular-text code"
							value="<?php echo esc_attr( $key ); ?>"
							autocomplete="off"
						/>
						<p class="description">
							<?php
							echo esc_html__(
								'Ask the Sahaj Atlas maintainers for a key. It is free, it is not a secret, and one key covers this whole site.',
								'sahaj-atlas'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Page description', 'sahaj-atlas' ); ?></th>
					<td>
						<?php
						/*
						 * ⚠ The hidden field is what makes "unchecked" arrive at all. An unchecked
						 * box posts nothing, so without this line the box cannot be turned off
						 * again once it has been turned on.
						 */
						?>
						<input type="hidden" name="<?php echo esc_attr( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT ); ?>" value="0" />
						<label for="sahaj-atlas-their-seo">
							<input
								id="sahaj-atlas-their-seo"
								name="<?php echo esc_attr( SAHAJ_ATLAS_OPTION_SEO_ROOT_OPT_OUT ); ?>"
								type="checkbox"
								value="1"
								<?php checked( $their_seo ); ?>
							/>
							<?php echo esc_html__( 'Let my SEO plugin describe the Atlas page', 'sahaj-atlas' ); ?>
						</label>
						<p class="description">
							<?php
							echo esc_html__(
								'Normally Sahaj Atlas writes the title and description for your Atlas page, in each visitor\'s own language. Tick this only if you have written your own and want to keep it. Pages for a country, a city or a class are always described by Sahaj Atlas — your SEO plugin has never seen those addresses.',
								'sahaj-atlas'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr />

		<h2><?php echo esc_html__( 'The Atlas page', 'sahaj-atlas' ); ?></h2>
		<?php if ( $has_page ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: the page's title, linked to its editor. */
					esc_html__( 'Your atlas lives on %s.', 'sahaj-atlas' ),
					'<a href="' . esc_url( (string) get_edit_post_link( $page_id ) ) . '"><strong>'
						. esc_html( (string) get_the_title( $page_id ) ) . '</strong></a>'
				);
				?>
				<a href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" class="button">
					<?php echo esc_html__( 'View it', 'sahaj-atlas' ); ?>
				</a>
			</p>
			<p class="description">
				<?php echo esc_html__( 'Add it to your site menu so visitors can find it. You can rename it freely — the atlas follows.', 'sahaj-atlas' ); ?>
			</p>
		<?php else : ?>
			<p><?php echo esc_html__( 'You do not have an Atlas page yet.', 'sahaj-atlas' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="sahaj_atlas_create_page" />
				<?php wp_nonce_field( 'sahaj_atlas_create_page' ); ?>
				<?php submit_button( __( 'Create the Atlas page', 'sahaj-atlas' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<hr />

		<h2><?php echo esc_html__( 'Status', 'sahaj-atlas' ); ?></h2>
		<?php sahaj_atlas_render_diagnostics(); ?>
	</div>
	<?php
}

/**
 * Handle the create-page button.
 */
function sahaj_atlas_handle_create_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'sahaj-atlas' ) );
	}

	check_admin_referer( 'sahaj_atlas_create_page' );

	$result = sahaj_atlas_create_page();
	$status = is_wp_error( $result ) ? 'error' : 'created';

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'             => 'sahaj-atlas',
				'sahaj_atlas_page' => $status,
			),
			admin_url( 'options-general.php' )
		)
	);

	exit;
}

/**
 * Nudge an admin who installed the plugin and stopped there.
 *
 * This stays deliberately quiet. It shows only on the plugin's own screens and the Plugins list,
 * and only for the one failure that makes everything else pointless.
 */
function sahaj_atlas_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$here   = $screen ? $screen->id : '';

	if ( ! in_array( $here, array( 'plugins', 'settings_page_sahaj-atlas', 'dashboard' ), true ) ) {
		return;
	}

	if ( '' !== sahaj_atlas_api_key() ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'Sahaj Atlas needs an API key before it can show anything.', 'sahaj-atlas' ),
		esc_url( admin_url( 'options-general.php?page=sahaj-atlas' ) ),
		esc_html__( 'Add it now', 'sahaj-atlas' )
	);
}
