<?php
/**
 * Static-analysis stubs for the WordPress 7.0 AI Client + Connectors API.
 *
 * Used by PHPStan / IDEs when the installed wordpress-stubs version predates
 * WordPress 7.0. Not loaded at runtime.
 *
 * @see https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
 * @see https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/
 *
 * @package OpenSEO
 */

if ( false ) {
	/**
	 * Fluent builder for an AI Client prompt.
	 */
	class WP_AI_Client_Prompt_Builder {

		/**
		 * @param string $instruction System instruction.
		 */
		public function using_system_instruction( string $instruction ): self {}

		/**
		 * @param int $max Maximum tokens to generate.
		 */
		public function using_max_tokens( int $max ): self {}

		/**
		 * @param string ...$models Preferred model IDs, best first.
		 */
		public function using_model_preference( string ...$models ): self {}

		/**
		 * @param array<string, mixed> $schema JSON schema the response must match.
		 */
		public function as_json_response( array $schema ): self {}

		/**
		 * Whether a connector supporting text generation is configured.
		 */
		public function is_supported_for_text_generation(): bool {}

		/**
		 * Generate text. Returns the text, or WP_Error on failure.
		 *
		 * @return mixed
		 */
		public function generate_text() {}
	}

	/**
	 * Start an AI Client prompt.
	 *
	 * @param string $prompt Prompt text.
	 */
	function wp_ai_client_prompt( string $prompt = '' ): WP_AI_Client_Prompt_Builder {}

	/**
	 * Retrieve all registered connectors keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function wp_get_connectors(): array {}

	/**
	 * Retrieve a single connector's data, or null when unregistered.
	 *
	 * @param string $id Connector ID.
	 * @return array<string, mixed>|null
	 */
	function wp_get_connector( string $id ) {}

	/**
	 * Whether a connector is registered.
	 *
	 * @param string $id Connector ID.
	 */
	function wp_is_connector_registered( string $id ): bool {}
}
