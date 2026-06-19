<?php
/**
 * WordPress 7.0 Abilities API integration.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Ai;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;
use WP_Error;
use WP_Post;

/**
 * Registers OpenSEO abilities so AI agents and the editor invoke the same,
 * permission-checked text-generation logic through the WordPress AI Client.
 *
 * @see https://developer.wordpress.org/apis/abilities-api/
 */
final class Abilities implements Hookable {

	private const CATEGORY = 'openseo';

	private const MAX_TOKENS = 320;

	private const CONTENT_WORDS = 400;

	private const SUGGESTABLE_TYPES = array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'FAQPage', 'HowTo', 'Recipe', 'Product' );

	/**
	 * Constructor.
	 *
	 * @param Options $options Settings accessor (provides the optional model override).
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Register the Abilities API hooks: category first, then abilities.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 *
	 * Guarded with function_exists so the plugin degrades gracefully if the
	 * Abilities API is unavailable (WordPress < 7.0 without the feature plugin).
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'OpenSEO', 'openseo' ),
				'description' => __( 'SEO analysis and AI-assisted optimization abilities.', 'openseo' ),
			)
		);
	}

	/**
	 * Register the abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'openseo/generate-meta-description',
			array(
				'label'               => __( 'Generate meta description', 'openseo' ),
				'description'         => __( 'Generates an SEO meta description for a post using the site\'s configured AI connector. Consumes provider credits.', 'openseo' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->post_id_input_schema(),
				'output_schema'       => $this->output_schema( 'meta_description' ),
				'execute_callback'    => array( $this, 'generate_meta_description' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'meta'                => $this->ability_meta(),
			)
		);

		wp_register_ability(
			'openseo/generate-title',
			array(
				'label'               => __( 'Generate SEO title', 'openseo' ),
				'description'         => __( 'Generates an SEO title for a post using the site\'s configured AI connector. Consumes provider credits.', 'openseo' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->post_id_input_schema(),
				'output_schema'       => $this->output_schema( 'title' ),
				'execute_callback'    => array( $this, 'generate_title' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'meta'                => $this->ability_meta(),
			)
		);

		wp_register_ability(
			'openseo/suggest-schema-type',
			array(
				'label'               => __( 'Suggest schema type', 'openseo' ),
				'description'         => __( 'Analyzes a post and recommends the most fitting schema.org type. Read-only; consumes provider credits.', 'openseo' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->post_id_input_schema(),
				'output_schema'       => $this->suggestion_output_schema(),
				'execute_callback'    => array( $this, 'suggest_schema_type' ),
				'permission_callback' => array( $this, 'can_edit_post' ),
				'meta'                => $this->ability_meta(),
			)
		);
	}

	/**
	 * Shared ability meta.
	 *
	 * `show_in_rest` is load-bearing: without it the ability has no REST run
	 * endpoint and the editor's executeAbility() call fails. The annotations go
	 * under `meta.annotations` (a top-level `readonly` key is silently ignored);
	 * the abilities are not readonly — each call spends provider credits and is
	 * not idempotent, so clients must POST rather than treat them as safe GETs.
	 *
	 * @return array<string, mixed>
	 */
	private function ability_meta(): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			),
		);
	}

	/**
	 * Execute the "generate meta description" ability.
	 *
	 * @param array<string, mixed> $input Validated input matching the input schema.
	 * @return array{meta_description: string}|WP_Error
	 */
	public function generate_meta_description( array $input ): array|WP_Error {
		return $this->generate( $input, Prompts::system_meta_description(), 'meta_description' );
	}

	/**
	 * Execute the "generate title" ability.
	 *
	 * @param array<string, mixed> $input Validated input matching the input schema.
	 * @return array{title: string}|WP_Error
	 */
	public function generate_title( array $input ): array|WP_Error {
		return $this->generate( $input, Prompts::system_title(), 'title' );
	}

	/**
	 * Execute the "suggest schema type" ability.
	 *
	 * @param array<string, mixed> $input Validated input matching the input schema.
	 * @return array{type: string, reason: string}|WP_Error
	 */
	public function suggest_schema_type( array $input ): array|WP_Error {
		$generated = $this->request_generation( $input, Prompts::system_schema_type(), $this->suggestion_output_schema() );
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$decoded = json_decode( (string) $generated, true );
		$type    = is_array( $decoded ) && isset( $decoded['type'] ) && is_string( $decoded['type'] ) ? $decoded['type'] : '';
		$reason  = is_array( $decoded ) && isset( $decoded['reason'] ) && is_string( $decoded['reason'] ) ? $decoded['reason'] : '';

		if ( ! in_array( $type, self::SUGGESTABLE_TYPES, true ) ) {
			$type = 'Article';
		}

		return array(
			'type'   => sanitize_text_field( $type ),
			'reason' => sanitize_text_field( $reason ),
		);
	}

	/**
	 * Output schema for the schema-type recommendation.
	 *
	 * @return array<string, mixed>
	 */
	private function suggestion_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'   => array( 'type' => 'string' ),
				'reason' => array( 'type' => 'string' ),
			),
			'required'   => array( 'type', 'reason' ),
		);
	}

	/**
	 * Run the shared text-generation flow for an ability.
	 *
	 * @param array<string, mixed> $input         Ability input (expects post_id).
	 * @param string               $system        System instruction.
	 * @param array<string, mixed> $output_schema JSON schema for the response.
	 * @return string|WP_Error Raw generated text, or WP_Error on failure.
	 */
	private function request_generation( array $input, string $system, array $output_schema ): string|WP_Error {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'openseo_invalid_post',
				__( 'A valid post ID is required.', 'openseo' )
			);
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! Connector::is_text_generation_available() ) {
			return new WP_Error(
				'openseo_no_connector',
				__( 'No AI connector is configured. Add one under Settings → Connectors.', 'openseo' )
			);
		}

		$content = wp_trim_words( wp_strip_all_tags( $post->post_content ), self::CONTENT_WORDS, '' );

		$builder = wp_ai_client_prompt( Prompts::user_for_post( $post->post_title, $content ) )
			->using_system_instruction( $system )
			->using_max_tokens( self::MAX_TOKENS )
			->as_json_response( $output_schema );

		$model = (string) $this->options->get( 'ai_model' );
		if ( '' !== $model ) {
			$builder = $builder->using_model_preference( $model );
		}

		return $builder->generate_text();
	}

	/**
	 * Shared generation flow for the text abilities.
	 *
	 * @param array<string, mixed> $input      Ability input.
	 * @param string               $system     System instruction for the model.
	 * @param string               $output_key Output schema key to return.
	 * @return array<string, string>|WP_Error
	 */
	private function generate( array $input, string $system, string $output_key ): array|WP_Error {
		$generated = $this->request_generation( $input, $system, $this->output_schema( $output_key ) );
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$decoded = json_decode( (string) $generated, true );

		if ( is_array( $decoded ) ) {
			// Prefer the declared key; tolerate the model using a different key
			// by taking the first string value — never echo the raw JSON back.
			$candidate = $decoded[ $output_key ] ?? reset( $decoded );
			$value     = is_string( $candidate ) ? $candidate : '';
		} else {
			// Not JSON: treat the whole response as the suggestion.
			$value = (string) $generated;
		}

		return array( $output_key => sanitize_text_field( $value ) );
	}

	/**
	 * Authorization check for the abilities.
	 *
	 * @param array<string, mixed> $input Input passed to the ability.
	 */
	public function can_edit_post( array $input = array() ): bool {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		return $post_id > 0
			? current_user_can( 'edit_post', $post_id )
			: current_user_can( 'edit_posts' );
	}

	/**
	 * Input schema shared by the text abilities.
	 *
	 * @return array<string, mixed>
	 */
	private function post_id_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the post to summarize.', 'openseo' ),
				),
			),
			'required'   => array( 'post_id' ),
		);
	}

	/**
	 * Output schema with a single string property.
	 *
	 * @param string $key Output property name.
	 * @return array<string, mixed>
	 */
	private function output_schema( string $key ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				$key => array( 'type' => 'string' ),
			),
			'required'   => array( $key ),
		);
	}
}
