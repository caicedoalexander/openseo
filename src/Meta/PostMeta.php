<?php
/**
 * Registers OpenSEO's per-entry meta keys.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the SEO override meta so the block editor can read/write it over REST.
 */
final class PostMeta implements Hookable {

	/**
	 * The meta keys OpenSEO stores per entry.
	 *
	 * @var string[]
	 */
	public const KEYS = array(
		'_openseo_title',
		'_openseo_description',
		'_openseo_robots_noindex',
		'_openseo_robots_nofollow',
		'_openseo_canonical',
		'_openseo_og_title',
		'_openseo_og_description',
		'_openseo_og_image',
		'_openseo_twitter_title',
		'_openseo_twitter_description',
		'_openseo_twitter_image',
	);

	/**
	 * Hook meta registration onto init (runs for admin, front, and REST requests).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
	}

	/**
	 * Register every key for every editor-backed public post type.
	 */
	public function register_meta(): void {
		$post_types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);

		foreach ( $post_types as $post_type ) {
			// The block editor only round-trips meta over REST when the post
			// type supports custom-fields; show_in_rest alone is not enough.
			if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
				add_post_type_support( $post_type, 'custom-fields' );
			}

			foreach ( self::KEYS as $key ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => array( $this, 'sanitize_value' ),
						'auth_callback'     => array( $this, 'can_edit' ),
					)
				);
			}
		}
	}

	/**
	 * Sanitize a stored meta value.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $meta_key Meta key being saved.
	 */
	public function sanitize_value( mixed $value, string $meta_key ): string {
		if ( '_openseo_canonical' === $meta_key || str_ends_with( $meta_key, '_image' ) ) {
			return esc_url_raw( (string) $value );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Authorize reading/writing the meta over REST.
	 *
	 * Loose parameter types on purpose: WordPress invokes this auth filter with
	 * different value shapes depending on context, so strict scalar hints here
	 * can fatal under declare(strict_types=1).
	 *
	 * @param mixed $allowed  WP-provided default (unused).
	 * @param mixed $meta_key Meta key (unused).
	 * @param mixed $post_id  Post being edited.
	 */
	public function can_edit( $allowed, $meta_key, $post_id ): bool {
		return current_user_can( 'edit_post', (int) $post_id );
	}
}
