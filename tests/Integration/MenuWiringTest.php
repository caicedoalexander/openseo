<?php
/**
 * Integration test: the OpenSEO menu and REST route are wired by the plugin.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Admin\Menu;
use WP_UnitTestCase;

final class MenuWiringTest extends WP_UnitTestCase {

	public function test_settings_route_is_live(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/openseo/v1/settings', $routes );
	}

	public function test_top_level_menu_is_registered(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		$menu = new Menu(
			array(
				'openseo-redirects' => '__return_true',
				'openseo-404s'      => '__return_true',
			)
		);
		$menu->add_menu();

		global $admin_page_hooks;
		$this->assertIsArray( $admin_page_hooks );
		$this->assertArrayHasKey( 'openseo', $admin_page_hooks );
	}

	public function test_legacy_settings_page_class_is_gone(): void {
		$this->assertFalse( class_exists( '\OpenSEO\Admin\SettingsPage', false ) );
	}
}
