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

	public function test_returns_on_page_defaults_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '-', $options->get( 'title_separator' ) );
		$this->assertSame( '%title% %sep% %sitename%', $options->get( 'title_template' ) );
		$this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
		$this->assertSame( '', $options->get( 'og_default_image' ) );
	}

	public function test_stored_values_override_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'title_separator' => '|' )
		);

		$options = new Options();

		$this->assertSame( '|', $options->get( 'title_separator' ) );
		// Untouched key still falls back to its default.
		$this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
	}

	public function test_sanitize_cleans_and_normalizes_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( wp_strip_tags_compat( (string) $value ) )
		);
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'title_separator'      => '  <b>|</b>  ',
				'title_template'       => '%title% %sep% %sitename%',
				'description_template' => '%excerpt%',
				'home_title'           => '%sitename%',
				'home_description'     => 'Home desc',
				'og_default_image'     => 'https://example.com/og.png',
				'ai_model'             => 'claude-opus-4-8',
			)
		);

		$this->assertSame( '|', $clean['title_separator'] );
		$this->assertSame( '%title% %sep% %sitename%', $clean['title_template'] );
		$this->assertSame( 'https://example.com/og.png', $clean['og_default_image'] );
		$this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
	}

	public function test_sanitize_handles_non_array_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$clean = $options->sanitize( 'not-an-array' );

		$this->assertSame( '-', $clean['title_separator'] );
		$this->assertSame( '', $clean['og_default_image'] );
	}

	public function test_sanitize_preserves_keys_absent_from_a_partial_tab_submission(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// A previously saved value from another tab is currently stored.
		Functions\when( 'get_option' )->justReturn(
			array( 'title_template' => 'Stored title %sep% %sitename%' )
		);

		$options = new Options();

		// The AI tab posts only its own field.
		$clean = $options->sanitize( array( 'ai_model' => 'claude-opus-4-8' ) );

		$this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
		// The unrelated tab's saved value survives instead of resetting to default.
		$this->assertSame( 'Stored title %sep% %sitename%', $clean['title_template'] );
	}

	public function test_sitemap_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '1', $options->get( 'sitemap_enabled' ) );
		$this->assertSame( '', $options->get( 'sitemap_include_authors' ) );
	}

	public function test_sanitize_normalizes_sitemap_checkboxes(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$on = $options->sanitize(
			array(
				'sitemap_enabled'         => '1',
				'sitemap_include_authors' => '1',
			)
		);
		$this->assertSame( '1', $on['sitemap_enabled'] );
		$this->assertSame( '1', $on['sitemap_include_authors'] );

		$off = $options->sanitize(
			array(
				'sitemap_enabled'         => '0',
				'sitemap_include_authors' => '0',
			)
		);
		$this->assertSame( '', $off['sitemap_enabled'] );
		$this->assertSame( '', $off['sitemap_include_authors'] );
	}
}


/**
 * Strip tags without relying on WordPress being loaded.
 */
function wp_strip_tags_compat( string $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
}
