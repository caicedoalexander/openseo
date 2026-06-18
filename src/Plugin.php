<?php
/**
 * Main plugin orchestrator.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO;

use OpenSEO\Admin\Assets as AdminAssets;
use OpenSEO\Admin\SettingsPage;
use OpenSEO\Ai\Abilities;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Frontend\Head\Canonical;
use OpenSEO\Frontend\Head\Description;
use OpenSEO\Frontend\Head\HeadPrinter;
use OpenSEO\Frontend\Head\Robots;
use OpenSEO\Meta\PostMeta;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;

/**
 * Wires the plugin's modules into WordPress.
 *
 * Acts as a tiny composition root: it builds the modules and asks each one to
 * register its own hooks. Admin-only modules stay behind {@see is_admin()} to
 * avoid loading admin code on the front end.
 */
final class Plugin {

	/**
	 * Shared plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Retrieve the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use {@see Plugin::instance()} instead of constructing directly.
	 */
	private function __construct() {}

	/**
	 * Register every module's hooks exactly once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		foreach ( $this->modules() as $module ) {
			$module->register();
		}
	}

	/**
	 * Build the list of hookable modules for the current request.
	 *
	 * @return array<int, Hookable>
	 */
	private function modules(): array {
		$options   = new Options();
		$variables = new Variables( $options );
		$resolver  = new Resolver( $options, $variables );

		$modules = array(
			new PostMeta(),
			new HeadPrinter(
				array(
					new Description( $resolver ),
					new Robots( $resolver ),
					new Canonical( $resolver ),
				)
			),
			new Abilities(),
		);

		if ( is_admin() ) {
			$modules[] = new SettingsPage( $options );
			$modules[] = new AdminAssets();
		}

		return $modules;
	}
}
