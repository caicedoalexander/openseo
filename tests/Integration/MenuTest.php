<?php
/**
 * Integration tests for the OpenSEO top-level admin menu (all-React).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Admin\Menu;
use WP_UnitTestCase;

final class MenuTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	public function test_registers_parent_and_all_submenus(): void {
		global $submenu;

		( new Menu() )->add_menu();

		$this->assertArrayHasKey( Menu::PARENT_SLUG, $submenu );
		$slugs = wp_list_pluck( $submenu[ Menu::PARENT_SLUG ], 2 );

		foreach (
			array(
				'openseo',
				'openseo-general',
				'openseo-titles',
				'openseo-social',
				'openseo-sitemaps',
				'openseo-schema',
				'openseo-redirects',
				'openseo-404s',
				'openseo-ai',
			) as $slug
		) {
			$this->assertContains( $slug, $slugs );
		}
	}

	public function test_all_screens_are_react(): void {
		$menu = new Menu();
		$menu->add_menu();

		// Every OpenSEO screen now mounts the React app.
		$this->assertSame( $menu->screen_hooks(), $menu->react_screen_hooks() );
	}

	public function test_dashboard_hook_is_the_top_level_hook(): void {
		$menu = new Menu();
		$menu->add_menu();

		$this->assertSame( 'toplevel_page_openseo', $menu->dashboard_hook() );
	}
}
