<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Ai;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Ai\Abilities;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;

final class AbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function abilities(): Abilities {
		return new Abilities( new Options() );
	}

	private function fake_post(): WP_Post {
		$post               = new WP_Post();
		$post->ID           = 7;
		$post->post_title   = 'A Post';
		$post->post_content = '<p>Body content.</p>';

		return $post;
	}

	public function test_registers_the_category(): void {
		$slug = '';
		Functions\when( 'wp_register_ability_category' )->alias(
			static function ( $name ) use ( &$slug ): void {
				$slug = $name;
			}
		);

		$this->abilities()->register_category();

		$this->assertSame( 'openseo', $slug );
	}

	public function test_registers_both_abilities_exposed_over_rest(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ): void {
				$registered[ $name ] = $args;
			}
		);

		$this->abilities()->register_abilities();

		$this->assertArrayHasKey( 'openseo/generate-meta-description', $registered );
		$this->assertArrayHasKey( 'openseo/generate-title', $registered );
		// Regression guard (C1/H1): without meta.show_in_rest the editor's
		// executeAbility call gets no REST route; annotations live under meta.
		$this->assertTrue( $registered['openseo/generate-meta-description']['meta']['show_in_rest'] );
		$this->assertFalse( $registered['openseo/generate-title']['meta']['annotations']['readonly'] );
	}

	public function test_invalid_post_returns_error(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$result = $this->abilities()->generate_meta_description( array( 'post_id' => 999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_invalid_post', $result->get_error_code() );
	}

	public function test_missing_connector_returns_error(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( false, '' )
		);

		$result = $this->abilities()->generate_meta_description( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'openseo_no_connector', $result->get_error_code() );
	}

	public function test_generate_meta_description_decodes_json_response(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static fn( $v ) => $v instanceof WP_Error
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, '{"meta_description":"Generated summary."}' )
		);

		$result = $this->abilities()->generate_meta_description( array( 'post_id' => 7 ) );

		$this->assertSame( array( 'meta_description' => 'Generated summary.' ), $result );
	}

	public function test_generate_title_tolerates_a_plain_text_response(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static fn( $v ) => $v instanceof WP_Error
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, 'Just a title' )
		);

		$result = $this->abilities()->generate_title( array( 'post_id' => 7 ) );

		$this->assertSame( array( 'title' => 'Just a title' ), $result );
	}

	public function test_falls_back_to_first_value_when_json_key_differs(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static fn( $v ) => $v instanceof WP_Error
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, '{"description":"Fallback value."}' )
		);

		$result = $this->abilities()->generate_meta_description( array( 'post_id' => 7 ) );

		// Wrong key → use the first string value, never the raw JSON.
		$this->assertSame( array( 'meta_description' => 'Fallback value.' ), $result );
	}

	public function test_propagates_generation_error(): void {
		Functions\when( 'get_post' )->justReturn( $this->fake_post() );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static fn( $v ) => $v instanceof WP_Error
		);
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakePromptBuilder( true, new WP_Error( 'provider_down', 'Provider error' ) )
		);

		$result = $this->abilities()->generate_meta_description( array( 'post_id' => 7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_down', $result->get_error_code() );
	}
}

/**
 * Chainable stand-in for WP_AI_Client_Prompt_Builder.
 */
final class FakePromptBuilder {

	/**
	 * @param bool                 $supported Whether text generation is available.
	 * @param string|\WP_Error     $text      Value generate_text() should return.
	 */
	public function __construct(
		private bool $supported,
		private string|\WP_Error $text
	) {}

	public function using_system_instruction( string $instruction ): self {
		return $this;
	}

	public function using_max_tokens( int $max ): self {
		return $this;
	}

	public function using_model_preference( string ...$models ): self {
		return $this;
	}

	public function as_json_response( array $schema ): self {
		return $this;
	}

	public function is_supported_for_text_generation(): bool {
		return $this->supported;
	}

	public function generate_text(): string|\WP_Error {
		return $this->text;
	}
}
