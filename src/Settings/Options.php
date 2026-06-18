<?php
/**
 * Typed access to the plugin's stored options.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Settings;

/**
 * Reads, writes, and sanitizes the single options array used by OpenSEO.
 *
 * Storing all settings under one option key keeps the options table tidy and
 * makes activation seeding and uninstall cleanup trivial.
 */
final class Options {

	public const OPTION_KEY = 'openseo_settings';

	public const OPTION_GROUP = 'openseo';

	/**
	 * Default settings used as a base for reads and sanitization.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'enable_meta_description'  => true,
			'default_meta_description' => '',
			'ai_model'                 => '',
		);
	}

	/**
	 * Retrieve the full settings array merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $this->defaults(), $stored );
	}

	/**
	 * Retrieve a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed Setting value, or null when unknown.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Sanitize incoming settings from the Settings API.
	 *
	 * @param mixed $input Raw value submitted from the settings form.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = $this->defaults();

		$clean['enable_meta_description'] = ! empty( $input['enable_meta_description'] );

		$clean['default_meta_description'] = isset( $input['default_meta_description'] )
			? sanitize_text_field( wp_unslash( $input['default_meta_description'] ) )
			: '';

		$clean['ai_model'] = isset( $input['ai_model'] )
			? sanitize_text_field( wp_unslash( $input['ai_model'] ) )
			: '';

		return $clean;
	}
}
