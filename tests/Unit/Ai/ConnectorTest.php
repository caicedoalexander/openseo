<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Ai;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Ai\Connector;
use PHPUnit\Framework\TestCase;

final class ConnectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Runs isolated: once another test stubs wp_ai_client_prompt, Brain Monkey
	 * defines it globally for the rest of the process, so function_exists() would
	 * leak `true` here without a fresh process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_returns_false_when_ai_client_is_absent(): void {
		// wp_ai_client_prompt is never stubbed → function_exists() is false.
		$this->assertFalse( Connector::is_text_generation_available() );
	}

	public function test_settings_url_points_to_the_connectors_screen(): void {
		Functions\when( 'admin_url' )->returnArg();

		$this->assertStringContainsString( 'options-connectors.php', Connector::settings_url() );
	}

	public function test_returns_true_when_text_generation_is_supported(): void {
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakeConnectorBuilder( true )
		);

		$this->assertTrue( Connector::is_text_generation_available() );
	}

	public function test_returns_false_when_no_connector_supports_text(): void {
		Functions\when( 'wp_ai_client_prompt' )->justReturn(
			new FakeConnectorBuilder( false )
		);

		$this->assertFalse( Connector::is_text_generation_available() );
	}
}

/**
 * Minimal stand-in for WP_AI_Client_Prompt_Builder.
 */
final class FakeConnectorBuilder {

	public function __construct( private bool $supported ) {}

	public function is_supported_for_text_generation(): bool {
		return $this->supported;
	}
}
