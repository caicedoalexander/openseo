<?php
/**
 * Integration tests for the XML sitemap customizations.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\Options;
use WP_Sitemaps;
use WP_Sitemaps_Posts;
use WP_UnitTestCase;

final class SitemapTest extends WP_UnitTestCase {

	/**
	 * The booted plugin already registers the sitemap filters globally (proven by
	 * PluginBootTest::test_sitemap_filters_are_registered_after_boot), so the tests
	 * drive behavior through the live option instead of re-registering the module.
	 * setUp only resets the option to a known baseline (authors off, sitemap on).
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( Options::OPTION_KEY );
	}

	public function test_noindexed_post_is_excluded_from_post_url_list(): void {
		$visible = self::factory()->post->create();
		$hidden  = self::factory()->post->create();
		update_post_meta( $hidden, '_openseo_robots_noindex', '1' );

		$provider = new WP_Sitemaps_Posts();
		$locs     = wp_list_pluck( $provider->get_url_list( 'post' ), 'loc' );

		$this->assertContains( get_permalink( $visible ), $locs );
		$this->assertNotContains( get_permalink( $hidden ), $locs );
	}

	public function test_users_provider_is_removed_by_default(): void {
		$sitemaps = new WP_Sitemaps();
		$sitemaps->register_sitemaps();

		$providers = $sitemaps->registry->get_providers();
		$this->assertArrayNotHasKey( 'users', $providers );
		$this->assertArrayHasKey( 'posts', $providers );
	}

	public function test_users_provider_is_present_when_authors_enabled(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_include_authors' => '1' ) );

		$sitemaps = new WP_Sitemaps();
		$sitemaps->register_sitemaps();

		$this->assertArrayHasKey( 'users', $sitemaps->registry->get_providers() );
	}

	public function test_master_toggle_off_disables_sitemaps(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_enabled' => '' ) );

		$this->assertFalse( apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	public function test_master_toggle_on_keeps_core_value(): void {
		update_option( Options::OPTION_KEY, array( 'sitemap_enabled' => '1' ) );

		$this->assertTrue( apply_filters( 'wp_sitemaps_enabled', true ) );
	}
}
