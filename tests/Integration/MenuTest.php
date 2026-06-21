<?php
/**
 * Integration tests for the OpenSEO top-level admin menu.
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

	private function build(): Menu {
		return new Menu(
			array(
				'openseo-redirects' => '__return_true',
				'openseo-404s'      => '__return_true',
			)
		);
	}

	public function test_registers_parent_and_all_submenus(): void {
		global $submenu;

		$this->build()->add_menu();

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

	public function test_php_pages_are_excluded_from_react_hooks(): void {
		$menu = $this->build();
		$menu->add_menu();

		$this->assertNotEmpty( $menu->react_screen_hooks() );
		// Every screen has a hook; the two PHP pages are not React hooks.
		$this->assertGreaterThan(
			count( $menu->react_screen_hooks() ),
			count( $menu->screen_hooks() )
		);
	}

	public function test_dashboard_hook_is_the_top_level_hook(): void {
		$menu = $this->build();
		$menu->add_menu();

		// Asset gating + the dashboard counters bootstrap depend on this exact value.
		$this->assertSame( 'toplevel_page_openseo', $menu->dashboard_hook() );
		// The Dashboard submenu reuses the parent slug, so it is not double-counted.
		$this->assertSame(
			1,
			count( array_keys( $menu->react_screen_hooks(), 'toplevel_page_openseo', true ) )
		);
	}
}
