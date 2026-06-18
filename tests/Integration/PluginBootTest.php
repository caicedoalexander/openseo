<?php
/**
 * Integration tests that boot the plugin inside WordPress.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_UnitTestCase;

final class PluginBootTest extends WP_UnitTestCase {

	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'OPENSEO_VERSION' ) );
		$this->assertTrue( defined( 'OPENSEO_PLUGIN_FILE' ) );
	}

	public function test_meta_description_is_printed_on_wp_head(): void {
		update_option(
			'openseo_settings',
			array(
				'enable_meta_description'  => true,
				'default_meta_description' => 'Integration test description',
			)
		);

		ob_start();
		do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="description"', $output );
		$this->assertStringContainsString( 'Integration test description', $output );
	}

	public function test_meta_description_is_suppressed_when_disabled(): void {
		update_option(
			'openseo_settings',
			array(
				'enable_meta_description'  => false,
				'default_meta_description' => 'Should not appear',
			)
		);

		ob_start();
		do_action( 'wp_head' );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Should not appear', $output );
	}
}
