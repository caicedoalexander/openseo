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
			'title_separator'         => '-',
			'title_template'          => '%title% %sep% %sitename%',
			'description_template'    => '%excerpt%',
			'home_title'              => '%sitename% %sep% %tagline%',
			'home_description'        => '',
			'og_default_image'        => '',
			'sitemap_enabled'         => '1',
			'sitemap_include_authors' => '',
			'schema_site_type'        => 'Organization',
			'schema_site_name'        => '',
			'schema_logo'             => '',
			'breadcrumb_separator'    => '›',
			'ai_model'                => '',
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
		// Merge over the currently stored values, not the defaults: each settings
		// tab posts only its own fields, so keys absent from this submission must
		// keep their saved value instead of resetting to default.
		$clean = $this->all();

		foreach ( array( 'title_separator', 'title_template', 'description_template', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		// Checkboxes: a hidden companion field guarantees the key is present (0 or
		// 1) when its tab is submitted, so an explicit '1' check turns it on/off.
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = '1' === $input[ $key ] ? '1' : '';
			}
		}

		// Whitelisted single-choice value: anything off-list resets to the default.
		if ( isset( $input['schema_site_type'] ) ) {
			$type                      = sanitize_text_field( wp_unslash( $input['schema_site_type'] ) );
			$clean['schema_site_type'] = in_array( $type, array( 'Organization', 'Person' ), true )
				? $type
				: 'Organization';
		}

		foreach ( array( 'og_default_image', 'schema_logo' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = esc_url_raw( wp_unslash( $input[ $key ] ) );
			}
		}

		return $clean;
	}
}
