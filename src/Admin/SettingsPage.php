<?php
/**
 * OpenSEO settings screen.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Ai\Connector;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Registers the tabbed settings page, its fields, and the single option.
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
	 * Register the option, sections, and fields with the Settings API.
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

		add_settings_section( 'openseo_general', __( 'General', 'openseo' ), '__return_false', self::MENU_SLUG );
		add_settings_section( 'openseo_titles', __( 'Titles & Meta', 'openseo' ), '__return_false', self::MENU_SLUG );
		add_settings_section( 'openseo_social', __( 'Social', 'openseo' ), '__return_false', self::MENU_SLUG );
		add_settings_section( 'openseo_ai', __( 'AI', 'openseo' ), array( $this, 'render_ai_intro' ), self::MENU_SLUG );

		$this->add_text_field( 'title_separator', __( 'Title separator', 'openseo' ), 'openseo_titles' );
		$this->add_text_field( 'title_template', __( 'Default title template', 'openseo' ), 'openseo_titles' );
		$this->add_text_field( 'description_template', __( 'Default description template', 'openseo' ), 'openseo_titles' );
		$this->add_text_field( 'home_title', __( 'Homepage title', 'openseo' ), 'openseo_titles' );
		$this->add_text_field( 'home_description', __( 'Homepage description', 'openseo' ), 'openseo_titles' );
		$this->add_text_field( 'og_default_image', __( 'Default social image URL', 'openseo' ), 'openseo_social' );
		$this->add_text_field( 'ai_model', __( 'AI model (optional override)', 'openseo' ), 'openseo_ai' );
	}

	/**
	 * Register one text field bound to a single option key.
	 *
	 * @param string $key     Option key name.
	 * @param string $label   Field label text.
	 * @param string $section Settings section ID.
	 */
	private function add_text_field( string $key, string $label, string $section ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key ): void {
				printf(
					'<input type="text" id="openseo_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" />',
					esc_attr( $key ),
					esc_attr( Options::OPTION_KEY ),
					esc_attr( (string) $this->options->get( $key ) )
				);
			},
			self::MENU_SLUG,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}

	/**
	 * Render the AI section intro: connector status + link.
	 */
	public function render_ai_intro(): void {
		$url = Connector::settings_url();

		if ( Connector::is_text_generation_available() ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'An AI connector is configured. The editor can generate titles and descriptions.', 'openseo' )
			);

			return;
		}

		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'No AI connector is configured.', 'openseo' ),
			esc_url( $url ),
			esc_html__( 'Settings → Connectors', 'openseo' )
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
}
