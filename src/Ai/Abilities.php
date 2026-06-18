<?php
/**
 * WordPress 7.0 Abilities API integration.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Ai;

use OpenSEO\Contracts\Hookable;
use WP_Error;
use WP_Post;

/**
 * Registers OpenSEO abilities so AI agents and the MCP adapter can discover and
 * invoke them in a structured, permission-checked way.
 *
 * @see https://developer.wordpress.org/apis/abilities-api/
 */
final class Abilities implements Hookable {

	private const CATEGORY = 'openseo';

	/**
	 * Register the Abilities API init hook.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category and abilities.
	 *
	 * Guarded with function_exists so the plugin degrades gracefully if the
	 * Abilities API is unavailable (WordPress < 7.0 without the feature plugin).
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => __( 'OpenSEO', 'openseo' ),
					'description' => __( 'SEO analysis and AI-assisted optimization abilities.', 'openseo' ),
				)
			);
		}

		wp_register_ability(
			'openseo/generate-meta-description',
			array(
				'label'               => __( 'Generate meta description', 'openseo' ),
				'description'         => __( 'Generates an SEO meta description for a given post using the site\'s configured AI connector.', 'openseo' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the post to summarize.', 'openseo' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'meta_description' => array(
							'type' => 'string',
						),
					),
				),
				'execute_callback'    => array( $this, 'generate_meta_description' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
			)
		);
	}

	/**
	 * Execute the "generate meta description" ability.
	 *
	 * @param array<string, mixed> $input Validated input matching the input schema.
	 * @return array{meta_description: string}|WP_Error
	 */
	public function generate_meta_description( array $input ): array|WP_Error {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'openseo_invalid_post',
				__( 'A valid post ID is required.', 'openseo' )
			);
		}

		/*
		 * TODO: route through the WP AI Client (Settings > Connectors) once the
		 * prompt strategy is finalized. Until then this deterministic fallback
		 * keeps the ability functional and testable.
		 */
		$description = wp_trim_words(
			wp_strip_all_tags( $post->post_content ),
			30,
			'…'
		);

		return array( 'meta_description' => $description );
	}

	/**
	 * Authorization check for the ability.
	 *
	 * @param array<string, mixed> $input Input passed to the ability.
	 */
	public function can_edit_post( array $input = array() ): bool {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		return $post_id > 0
			? current_user_can( 'edit_post', $post_id )
			: current_user_can( 'edit_posts' );
	}
}
