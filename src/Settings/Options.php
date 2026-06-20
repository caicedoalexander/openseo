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
	 * Merged settings memoized for this request, or null until first read.
	 *
	 * Writes go through the Settings API (core update_option) or the Activator,
	 * never through this instance, so there is nothing to invalidate within a
	 * request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Default settings used as a base for reads and sanitization.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'title_separator'          => '-',
			'title_template'           => '%title% %sep% %sitename%',
			'description_template'     => '%excerpt%',
			'home_title'               => '%sitename% %sep% %tagline%',
			'home_description'         => '',
			'og_default_image'         => '',
			'sitemap_enabled'          => '1',
			'sitemap_include_authors'  => '',
			'schema_site_type'         => 'Organization',
			'schema_site_name'         => '',
			'schema_logo'              => '',
			'breadcrumb_separator'     => '›',
			'ai_model'                 => '',
			'redirects_auto_slug'      => '1',
			'redirects_default_status' => '301',
			'redirects_track_hits'     => '1',
			'notfound_monitor_enabled' => '',
			'notfound_retention_days'  => '30',
		);
	}

	/**
	 * Retrieve the full settings array merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->cache = array_merge( $this->defaults(), $stored );

		return $this->cache;
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
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled' ) as $key ) {
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

		if ( isset( $input['redirects_default_status'] ) ) {
			$status                            = sanitize_text_field( wp_unslash( $input['redirects_default_status'] ) );
			$clean['redirects_default_status'] = in_array( $status, array( '301', '302', '307' ), true ) ? $status : '301';
		}

		if ( isset( $input['notfound_retention_days'] ) ) {
			$days                             = absint( wp_unslash( $input['notfound_retention_days'] ) );
			$clean['notfound_retention_days'] = (string) max( 1, $days );
		}

		return $clean;
	}
}
