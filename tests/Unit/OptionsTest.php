<?php
/**
 * Unit tests for the Options value object.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_defaults_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertTrue( $options->get( 'enable_meta_description' ) );
		$this->assertSame( '', $options->get( 'default_meta_description' ) );
	}

	public function test_stored_values_override_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'default_meta_description' => 'Stored value' )
		);

		$options = new Options();

		$this->assertSame( 'Stored value', $options->get( 'default_meta_description' ) );
		// Untouched key still falls back to its default.
		$this->assertTrue( $options->get( 'enable_meta_description' ) );
	}

	public function test_sanitize_cleans_and_normalizes_input(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( wp_strip_all_tags_stub( (string) $value ) )
		);

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'enable_meta_description'  => '1',
				'default_meta_description' => '  <b>Hello</b> world  ',
				'ai_model'                 => 'claude-opus-4-8',
			)
		);

		$this->assertTrue( $clean['enable_meta_description'] );
		$this->assertSame( 'Hello world', $clean['default_meta_description'] );
		$this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
	}

	public function test_sanitize_handles_non_array_input(): void {
		$options = new Options();

		$clean = $options->sanitize( 'not-an-array' );

		$this->assertFalse( $clean['enable_meta_description'] );
		$this->assertSame( '', $clean['default_meta_description'] );
	}
}

/**
 * Minimal stand-in for wp_strip_all_tags used by the sanitize alias above.
 */
function wp_strip_all_tags_stub( string $value ): string {
	return wp_strip_tags_compat( $value );
}

/**
 * Strip tags without relying on WordPress being loaded.
 */
function wp_strip_tags_compat( string $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
}
