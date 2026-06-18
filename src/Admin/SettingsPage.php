<?php
/**
 * OpenSEO settings screen.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Registers the settings page, its fields, and the Settings API option.
 *
 * The Settings API handles the nonce and the option save; we add the
 * capability check and per-field escaping/sanitization.
 */
final class SettingsPage implements Hookable {

	private const MENU_SLUG = 'openseo';

	/**
	 * Build the settings page module.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the settings menu and fields hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the OpenSEO page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'OpenSEO', 'openseo' ),
			__( 'OpenSEO', 'openseo' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option, section, and fields with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			Options::OPTION_GROUP,
			Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->options, 'sanitize' ),
				'default'           => $this->options->defaults(),
			)
		);

		add_settings_section(
			'openseo_general',
			__( 'General', 'openseo' ),
			'__return_false',
			self::MENU_SLUG
		);

		add_settings_field(
			'enable_meta_description',
			__( 'Output meta description', 'openseo' ),
			array( $this, 'render_checkbox' ),
			self::MENU_SLUG,
			'openseo_general',
			array( 'label_for' => 'openseo_enable_meta_description' )
		);

		add_settings_field(
			'default_meta_description',
			__( 'Default meta description', 'openseo' ),
			array( $this, 'render_textarea' ),
			self::MENU_SLUG,
			'openseo_general',
			array( 'label_for' => 'openseo_default_meta_description' )
		);
	}

	/**
	 * Render the settings page wrapper.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require OPENSEO_PLUGIN_DIR . 'templates/admin/settings-page.php';
	}

	/**
	 * Render the "enable meta description" checkbox field.
	 */
	public function render_checkbox(): void {
		$value = (bool) $this->options->get( 'enable_meta_description' );

		printf(
			'<input type="checkbox" id="openseo_enable_meta_description" name="%1$s[enable_meta_description]" value="1" %2$s />',
			esc_attr( Options::OPTION_KEY ),
			checked( $value, true, false )
		);
	}

	/**
	 * Render the default meta description textarea field.
	 */
	public function render_textarea(): void {
		$value = (string) $this->options->get( 'default_meta_description' );

		printf(
			'<textarea id="openseo_default_meta_description" name="%1$s[default_meta_description]" rows="3" class="large-text">%2$s</textarea>',
			esc_attr( Options::OPTION_KEY ),
			esc_textarea( $value )
		);
	}
}
