<?php
/**
 * OpenSEO top-level admin menu — the single registrar of all submenus.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the top-level menu and every submenu in one ordered pass, so the
 * order is deterministic (add_submenu_page $position is unreliable across
 * separately-hooked classes). React pages render a mount node; PHP pages
 * (Redirects/404) render via callbacks injected by the composition root.
 */
final class Menu implements Hookable {

	public const PARENT_SLUG = 'openseo';

	private const CAP = 'manage_options';

	private const ICON = 'dashicons-search';

	/**
	 * All OpenSEO screen hook suffixes (React + PHP).
	 *
	 * @var array<int, string>
	 */
	private array $screen_hooks = array();

	/**
	 * React-only screen hook suffixes.
	 *
	 * @var array<int, string>
	 */
	private array $react_hooks = array();

	/**
	 * Dashboard screen hook suffix (top-level page hook).
	 *
	 * @var string
	 */
	private string $dashboard_hook = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, callable> $php_pages Map of submenu slug => render callback.
	 */
	public function __construct( private readonly array $php_pages = array() ) {}

	/**
	 * Register the admin_menu hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Register the top-level menu and all submenus.
	 */
	public function add_menu(): void {
		$this->dashboard_hook = (string) add_menu_page(
			__( 'OpenSEO', 'openseo' ),
			__( 'OpenSEO', 'openseo' ),
			self::CAP,
			self::PARENT_SLUG,
			function (): void {
				$this->render_view( 'dashboard' );
			},
			self::ICON,
			58.9
		);

		$this->track( $this->dashboard_hook, true );

		foreach ( $this->pages() as $page ) {
			$is_react = isset( $page['view'] );
			$callback = $is_react
				? function () use ( $page ): void {
					$this->render_view( $page['view'] );
				}
				: ( $this->php_pages[ $page['slug'] ] ?? '__return_false' );

			$hook = (string) add_submenu_page(
				self::PARENT_SLUG,
				$page['title'],
				$page['title'],
				self::CAP,
				$page['slug'],
				$callback
			);

			$this->track( $hook, $is_react );
		}
	}

	/**
	 * Record a screen hook once. The Dashboard submenu reuses the parent slug, so
	 * its returned hook equals the top-level hook ('toplevel_page_openseo'); dedup
	 * avoids double-counting it in either list.
	 *
	 * @param string $hook     Hook suffix returned by add_*_page().
	 * @param bool   $is_react Whether the screen mounts the React app.
	 */
	private function track( string $hook, bool $is_react ): void {
		if ( '' === $hook ) {
			return;
		}
		if ( ! in_array( $hook, $this->screen_hooks, true ) ) {
			$this->screen_hooks[] = $hook;
		}
		if ( $is_react && ! in_array( $hook, $this->react_hooks, true ) ) {
			$this->react_hooks[] = $hook;
		}
	}

	/**
	 * Ordered page descriptors. React pages carry a 'view'; PHP pages do not.
	 *
	 * @return array<int, array{slug: string, title: string, view?: string}>
	 */
	private function pages(): array {
		return array(
			array(
				'slug'  => self::PARENT_SLUG,
				'title' => __( 'Dashboard', 'openseo' ),
				'view'  => 'dashboard',
			),
			array(
				'slug'  => 'openseo-general',
				'title' => __( 'General', 'openseo' ),
				'view'  => 'general',
			),
			array(
				'slug'  => 'openseo-titles',
				'title' => __( 'Titles & Meta', 'openseo' ),
				'view'  => 'titles',
			),
			array(
				'slug'  => 'openseo-social',
				'title' => __( 'Social', 'openseo' ),
				'view'  => 'social',
			),
			array(
				'slug'  => 'openseo-sitemaps',
				'title' => __( 'Sitemaps', 'openseo' ),
				'view'  => 'sitemaps',
			),
			array(
				'slug'  => 'openseo-schema',
				'title' => __( 'Schema', 'openseo' ),
				'view'  => 'schema',
			),
			array(
				'slug'  => 'openseo-redirects',
				'title' => __( 'Redirects', 'openseo' ),
			),
			array(
				'slug'  => 'openseo-404s',
				'title' => __( '404s', 'openseo' ),
			),
			array(
				'slug'  => 'openseo-ai',
				'title' => __( 'AI', 'openseo' ),
				'view'  => 'ai',
			),
		);
	}

	/**
	 * Render a React mount page.
	 *
	 * @param string $view View id passed to the React app.
	 */
	public function render_view( string $view ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$openseo_view = $view;
		require OPENSEO_PLUGIN_DIR . 'templates/admin/app-page.php';
	}

	/**
	 * All OpenSEO screen hook suffixes.
	 *
	 * @return array<int, string>
	 */
	public function screen_hooks(): array {
		return $this->screen_hooks;
	}

	/**
	 * React-only screen hook suffixes.
	 *
	 * @return array<int, string>
	 */
	public function react_screen_hooks(): array {
		return $this->react_hooks;
	}

	/**
	 * The dashboard (top-level) screen hook suffix.
	 */
	public function dashboard_hook(): string {
		return $this->dashboard_hook;
	}
}
