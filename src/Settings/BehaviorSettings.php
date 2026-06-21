<?php
/**
 * Settings-API registration for the redirect/404 behavior toggles.
 *
 * These five keys live on the PHP Redirects/404 pages in Phase 1 (Phase 2 moves
 * them into React views). register_setting() keeps options.php working for them;
 * all writes still flow through Options::sanitize().
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the option + the redirect/404 toggle sections, and renders each
 * section as a small options.php form on its page.
 */
final class BehaviorSettings implements Hookable {

	/**
	 * Constructor.
	 *
	 * @param Options $options Typed settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register settings on admin_init.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option, the two sections, and their fields.
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

		add_settings_section( 'openseo_redirects', '', '__return_false', 'openseo_redirects' );
		$this->add_checkbox_field( 'redirects_auto_slug', __( 'Auto-redirect on slug change', 'openseo' ), 'openseo_redirects' );
		$this->add_checkbox_field( 'redirects_track_hits', __( 'Track redirect hits', 'openseo' ), 'openseo_redirects' );
		$this->add_select_field(
			'redirects_default_status',
			__( 'Default redirect type', 'openseo' ),
			'openseo_redirects',
			array(
				'301' => __( '301 — Permanent', 'openseo' ),
				'302' => __( '302 — Temporary', 'openseo' ),
				'307' => __( '307 — Temporary (preserve method)', 'openseo' ),
			)
		);

		add_settings_section( 'openseo_notfound', '', '__return_false', 'openseo_notfound' );
		$this->add_checkbox_field( 'notfound_monitor_enabled', __( 'Enable 404 monitor', 'openseo' ), 'openseo_notfound' );
		$this->add_text_field( 'notfound_retention_days', __( '404 retention (days)', 'openseo' ), 'openseo_notfound' );
	}

	/**
	 * Render the redirects toggle form (options.php).
	 */
	public function render_redirects_form(): void {
		$this->render_form( 'openseo_redirects' );
	}

	/**
	 * Render the 404 toggle form (options.php).
	 */
	public function render_notfound_form(): void {
		$this->render_form( 'openseo_notfound' );
	}

	/**
	 * Render one section as a self-contained options.php form.
	 *
	 * @param string $section Section id (also the do_settings_sections page).
	 */
	private function render_form( string $section ): void {
		echo '<form action="options.php" method="post">';
		settings_fields( Options::OPTION_GROUP );
		do_settings_sections( $section );
		submit_button();
		echo '</form>';
	}

	/**
	 * Register one text field bound to a single option key.
	 *
	 * @param string $key     Option key.
	 * @param string $label   Field label.
	 * @param string $section Section id.
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
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}

	/**
	 * Register one checkbox field (hidden companion guarantees the key is posted).
	 *
	 * @param string $key     Option key.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 */
	private function add_checkbox_field( string $key, string $label, string $section ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key ): void {
				printf(
					'<input type="hidden" name="%1$s[%2$s]" value="0" />'
					. '<input type="checkbox" id="openseo_%2$s" name="%1$s[%2$s]" value="1"%3$s />',
					esc_attr( Options::OPTION_KEY ),
					esc_attr( $key ),
					checked( '1', (string) $this->options->get( $key ), false )
				);
			},
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}

	/**
	 * Register one select field bound to a single option key.
	 *
	 * @param string                   $key     Option key.
	 * @param string                   $label   Field label.
	 * @param string                   $section Section id.
	 * @param array<array-key, string> $choices value => label map.
	 */
	private function add_select_field( string $key, string $label, string $section, array $choices ): void {
		add_settings_field(
			$key,
			$label,
			function () use ( $key, $choices ): void {
				$current = (string) $this->options->get( $key );

				printf(
					'<select id="openseo_%1$s" name="%2$s[%1$s]">',
					esc_attr( $key ),
					esc_attr( Options::OPTION_KEY )
				);

				foreach ( $choices as $value => $choice_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $value ),
						selected( $current, (string) $value, false ),
						esc_html( $choice_label )
					);
				}

				echo '</select>';
			},
			$section,
			$section,
			array( 'label_for' => 'openseo_' . $key )
		);
	}
}
