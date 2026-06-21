<?php
/**
 * Integration tests for the redirect/404 behavior settings registration.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Settings\BehaviorSettings;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class BehaviorSettingsTest extends WP_UnitTestCase {

	public function test_option_is_registered_with_sanitizer(): void {
		( new BehaviorSettings( new Options() ) )->register_settings();

		$this->assertArrayHasKey( Options::OPTION_KEY, get_registered_settings() );
	}

	public function test_redirect_and_notfound_fields_register(): void {
		global $wp_settings_fields;

		( new BehaviorSettings( new Options() ) )->register_settings();

		$redirects = $wp_settings_fields['openseo_redirects']['openseo_redirects'] ?? array();
		$notfound  = $wp_settings_fields['openseo_notfound']['openseo_notfound'] ?? array();

		$this->assertArrayHasKey( 'redirects_auto_slug', $redirects );
		$this->assertArrayHasKey( 'redirects_default_status', $redirects );
		$this->assertArrayHasKey( 'notfound_monitor_enabled', $notfound );
		$this->assertArrayHasKey( 'notfound_retention_days', $notfound );
	}
}
