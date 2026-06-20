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

	public function test_all_reads_the_stored_option_only_once_per_instance(): void {
		// all() is called on most reads (and on every wp_head presenter); the
		// stored option must be fetched and merged once per request, not on
		// every access.
		Functions\expect( 'get_option' )->once()->andReturn( array( 'title_separator' => '|' ) );

		$options = new Options();

		$this->assertSame( '|', $options->get( 'title_separator' ) );
		$this->assertSame( '|', $options->get( 'title_separator' ) );
		$options->all();
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

	public function test_schema_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( 'Organization', $options->get( 'schema_site_type' ) );
		$this->assertSame( '', $options->get( 'schema_site_name' ) );
		$this->assertSame( '', $options->get( 'schema_logo' ) );
		$this->assertSame( '›', $options->get( 'breadcrumb_separator' ) );
	}

	public function test_sanitize_normalizes_schema_fields(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'schema_site_type'     => 'Person',
				'schema_site_name'     => 'Jane Doe',
				'schema_logo'          => 'https://example.com/logo.png',
				'breadcrumb_separator' => '/',
			)
		);

		$this->assertSame( 'Person', $clean['schema_site_type'] );
		$this->assertSame( 'Jane Doe', $clean['schema_site_name'] );
		$this->assertSame( 'https://example.com/logo.png', $clean['schema_logo'] );
		$this->assertSame( '/', $clean['breadcrumb_separator'] );
	}

	public function test_sanitize_rejects_unknown_schema_site_type(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize( array( 'schema_site_type' => 'Robot' ) );

		// Unknown value falls back to the default, never stored verbatim.
		$this->assertSame( 'Organization', $clean['schema_site_type'] );
	}

	public function test_defaults_include_redirect_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$options = new Options();

		$this->assertSame( '1', $options->get( 'redirects_auto_slug' ) );
		$this->assertSame( '1', $options->get( 'redirects_track_hits' ) );
		$this->assertSame( '301', $options->get( 'redirects_default_status' ) );
		$this->assertSame( '', $options->get( 'notfound_monitor_enabled' ) );
		$this->assertSame( '30', $options->get( 'notfound_retention_days' ) );
	}

	public function test_sanitize_clamps_retention_and_status(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		$options = new Options();

		$clean = $options->sanitize(
			array(
				'redirects_default_status' => '999',
				'notfound_retention_days'  => '0',
				'notfound_monitor_enabled' => '1',
			)
		);

		$this->assertSame( '301', $clean['redirects_default_status'] ); // Off-list resets.
		$this->assertSame( '1', $clean['notfound_retention_days'] );    // Clamped to minimum 1.
		$this->assertSame( '1', $clean['notfound_monitor_enabled'] );

		$valid = $options->sanitize( array( 'redirects_default_status' => '307' ) );
		$this->assertSame( '307', $valid['redirects_default_status'] );
	}
}


/**
 * Strip tags without relying on WordPress being loaded.
 */
function wp_strip_tags_compat( string $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
}
