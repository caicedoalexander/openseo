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
			'title_separator'              => '-',
			'home_title'                   => '%sitename% %sep% %tagline%',
			'home_description'             => '',
			'og_default_image'             => '',
			'sitemap_enabled'              => '1',
			'sitemap_include_authors'      => '',
			'schema_site_type'             => 'Organization',
			'schema_site_name'             => '',
			'schema_logo'                  => '',
			'breadcrumb_separator'         => '›',
			'ai_model'                     => '',
			'redirects_auto_slug'          => '1',
			'redirects_default_status'     => '301',
			'redirects_track_hits'         => '1',
			'notfound_monitor_enabled'     => '',
			'notfound_retention_days'      => '30',
			'post_types'                   => array(),
			'taxonomies'                   => array(),
			'robots'                       => array(),
			'capitalize_titles'            => '',
			'twitter_card_type'            => 'summary_large_image',
			'advanced_robots'              => array(
				'max_snippet'       => array(
					'enabled' => '',
					'length'  => '-1',
				),
				'max_video_preview' => array(
					'enabled' => '',
					'length'  => '-1',
				),
				'max_image_preview' => array(
					'enabled' => '',
					'value'   => 'large',
				),
			),
			'local_website_name'           => '',
			'local_website_alternate_name' => '',
			'local_url'                    => '',
			'local_email'                  => '',
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

		foreach ( array( 'title_separator', 'home_title', 'home_description', 'schema_site_name', 'breadcrumb_separator', 'ai_model', 'local_website_name', 'local_website_alternate_name' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		// Checkboxes: a hidden companion field guarantees the key is present (0 or
		// 1) when its tab is submitted, so an explicit '1' check turns it on/off.
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled', 'capitalize_titles' ) as $key ) {
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

		if ( isset( $input['twitter_card_type'] ) ) {
			$card                       = sanitize_text_field( wp_unslash( $input['twitter_card_type'] ) );
			$clean['twitter_card_type'] = in_array( $card, array( 'summary_large_image', 'summary' ), true ) ? $card : 'summary_large_image';
		}

		foreach ( array( 'og_default_image', 'schema_logo', 'local_url' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = esc_url_raw( wp_unslash( $input[ $key ] ) );
			}
		}

		if ( isset( $input['local_email'] ) ) {
			$email                = sanitize_email( wp_unslash( $input['local_email'] ) );
			$clean['local_email'] = is_email( $email ) ? $email : '';
		}

		if ( isset( $input['redirects_default_status'] ) ) {
			$status                            = sanitize_text_field( wp_unslash( $input['redirects_default_status'] ) );
			$clean['redirects_default_status'] = in_array( $status, array( '301', '302', '307' ), true ) ? $status : '301';
		}

		if ( isset( $input['notfound_retention_days'] ) ) {
			$days                             = absint( wp_unslash( $input['notfound_retention_days'] ) );
			$clean['notfound_retention_days'] = (string) max( 1, $days );
		}

		if ( isset( $input['robots'] ) && is_array( $input['robots'] ) ) {
			$allowed_global = array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'noindex_empty_terms' );
			$robots         = array();
			foreach ( $allowed_global as $directive ) {
				if ( '1' === (string) ( $input['robots'][ $directive ] ?? '' ) ) {
					$robots[ $directive ] = '1';
				}
			}
			$clean['robots'] = $robots;
		}

		if ( isset( $input['post_types'] ) || isset( $input['taxonomies'] ) ) {
			$content_types = new ContentTypes();

			if ( isset( $input['post_types'] ) ) {
				$clean['post_types'] = $this->sanitize_template_map(
					$input['post_types'],
					is_array( $clean['post_types'] ?? null ) ? $clean['post_types'] : array(),
					$content_types->post_type_slugs()
				);
			}

			if ( isset( $input['taxonomies'] ) ) {
				$clean['taxonomies'] = $this->sanitize_template_map(
					$input['taxonomies'],
					is_array( $clean['taxonomies'] ?? null ) ? $clean['taxonomies'] : array(),
					$content_types->taxonomy_slugs()
				);
			}
		}

		if ( isset( $input['advanced_robots'] ) && is_array( $input['advanced_robots'] ) ) {
			$adv                      = $input['advanced_robots'];
			$clean['advanced_robots'] = array(
				'max_snippet'       => $this->sanitize_advanced_length( $adv['max_snippet'] ?? null ),
				'max_video_preview' => $this->sanitize_advanced_length( $adv['max_video_preview'] ?? null ),
				'max_image_preview' => $this->sanitize_advanced_image( $adv['max_image_preview'] ?? null ),
			);
		}

		return $clean;
	}

	/**
	 * Sanitize one nested template map (post_types or taxonomies) slug-by-slug.
	 *
	 * Conservation of unsent slugs comes from $current already holding the stored
	 * map (sanitize() starts from all()); this is NOT a PHP deep merge. Per slug:
	 * whitelist, merge per field, and unset when all three fields end up empty.
	 *
	 * @param mixed                                                                              $input_map Raw submitted map for the group.
	 * @param array<string, array{title:string,description:string,robots?:array<string,string>}> $current   Stored map for this group.
	 * @param array<int, string>                                                                 $allowed   Whitelisted slugs.
	 * @return array<string, array{title:string,description:string,robots?:array<string,string>}>
	 */
	private function sanitize_template_map( mixed $input_map, array $current, array $allowed ): array {
		if ( ! is_array( $input_map ) ) {
			return $current;
		}

		foreach ( $input_map as $slug => $fields ) {
			$slug = (string) $slug;

			if ( ! in_array( $slug, $allowed, true ) || ! is_array( $fields ) ) {
				continue;
			}

			$title = array_key_exists( 'title', $fields )
				? sanitize_text_field( wp_unslash( (string) $fields['title'] ) )
				: (string) ( $current[ $slug ]['title'] ?? '' );

			$description = array_key_exists( 'description', $fields )
				? sanitize_textarea_field( wp_unslash( (string) $fields['description'] ) )
				: (string) ( $current[ $slug ]['description'] ?? '' );

			if ( array_key_exists( 'robots', $fields ) && is_array( $fields['robots'] ) ) {
				$robots = array();
				foreach ( array( 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ) as $directive ) {
					$value = (string) ( $fields['robots'][ $directive ] ?? '' );
					if ( 'on' === $value || 'off' === $value ) {
						$robots[ $directive ] = $value;
					}
				}
			} else {
				$robots = is_array( $current[ $slug ]['robots'] ?? null ) ? $current[ $slug ]['robots'] : array();
			}

			if ( '' === $title && '' === $description && empty( $robots ) ) {
				unset( $current[ $slug ] );
				continue;
			}

			$entry = array(
				'title'       => $title,
				'description' => $description,
			);
			if ( ! empty( $robots ) ) {
				$entry['robots'] = $robots;
			}

			$current[ $slug ] = $entry;
		}

		return $current;
	}

	/**
	 * Sanitize one length-based advanced robots block (max-snippet / max-video-preview).
	 *
	 * @param mixed $block Raw block ({ enabled, length }).
	 * @return array{enabled:string,length:string}
	 */
	private function sanitize_advanced_length( mixed $block ): array {
		$block   = is_array( $block ) ? $block : array();
		$enabled = '1' === (string) ( $block['enabled'] ?? '' ) ? '1' : '';
		$length  = isset( $block['length'] ) ? (int) wp_unslash( $block['length'] ) : -1;
		if ( $length < -1 ) {
			$length = -1;
		}

		return array(
			'enabled' => $enabled,
			'length'  => (string) $length,
		);
	}

	/**
	 * Sanitize the image-preview advanced robots block (max-image-preview).
	 *
	 * @param mixed $block Raw block ({ enabled, value }).
	 * @return array{enabled:string,value:string}
	 */
	private function sanitize_advanced_image( mixed $block ): array {
		$block   = is_array( $block ) ? $block : array();
		$enabled = '1' === (string) ( $block['enabled'] ?? '' ) ? '1' : '';
		$value   = sanitize_text_field( wp_unslash( (string) ( $block['value'] ?? 'large' ) ) );
		if ( ! in_array( $value, array( 'large', 'standard', 'none' ), true ) ) {
			$value = 'large';
		}

		return array(
			'enabled' => $enabled,
			'value'   => $value,
		);
	}
}
