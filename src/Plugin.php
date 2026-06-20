<?php
/**
 * Main plugin orchestrator.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO;

use OpenSEO\Admin\Assets as AdminAssets;
use OpenSEO\Admin\Editor\EditorPanel;
use OpenSEO\Admin\SettingsPage;
use OpenSEO\Ai\Abilities;
use OpenSEO\Breadcrumbs\Block as BreadcrumbsBlock;
use OpenSEO\Breadcrumbs\Trail;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Frontend\Head\Canonical;
use OpenSEO\Frontend\Head\Description;
use OpenSEO\Frontend\Head\HeadPrinter;
use OpenSEO\Frontend\Head\OpenGraph;
use OpenSEO\Frontend\Head\Robots;
use OpenSEO\Frontend\Head\Title;
use OpenSEO\Frontend\Head\Twitter;
use OpenSEO\Meta\PostMeta;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Schema\Graph;
use OpenSEO\Schema\Pieces\Article;
use OpenSEO\Schema\Pieces\BreadcrumbList;
use OpenSEO\Schema\Pieces\Organization;
use OpenSEO\Schema\Pieces\Person;
use OpenSEO\Schema\Pieces\WebPage as WebPagePiece;
use OpenSEO\Schema\Pieces\WebSite as WebSitePiece;
use OpenSEO\Settings\Options;
use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Admin\RedirectsPage;
use OpenSEO\Redirects\Cache as RedirectsCache;
use OpenSEO\Redirects\Dispatcher;
use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Repository as RedirectsRepository;
use OpenSEO\NotFound\LogRepository as NotFoundLog;
use OpenSEO\NotFound\Monitor as NotFoundMonitor;
use OpenSEO\NotFound\Pruner as NotFoundPruner;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Sitemap\Sitemap;

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

		add_action(
			'admin_init',
			static function (): void {
				if ( Schema::current_version() !== Schema::VERSION ) {
					Schema::install();
				}
			}
		);
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

		$not_found_log   = new NotFoundLog();
		$redirects_repo  = new RedirectsRepository();
		$redirects_cache = new RedirectsCache( $redirects_repo );

		$trail = new Trail();

		$graph = new Graph(
			array(
				new WebSitePiece( $options ),
				new Organization( $options ),
				new Person( $options ),
				new WebPagePiece( $resolver ),
				new Article( $resolver, $options ),
				new BreadcrumbList( $trail ),
			)
		);

		$modules = array(
			new NotFoundMonitor( $not_found_log, $options ),
			new NotFoundPruner( $not_found_log, $options ),
			new Dispatcher( $redirects_cache, new Matcher(), $redirects_repo, $options ),
			new SlugWatcher( $redirects_repo, $redirects_cache, $options ),
			new PostMeta(),
			new Title( $resolver ),
			new HeadPrinter(
				array(
					new Description( $resolver ),
					new Robots( $resolver ),
					new Canonical( $resolver ),
					new OpenGraph( $resolver ),
					new Twitter( $resolver ),
				)
			),
			new Abilities( $options ),
			new Sitemap( $options ),
			$graph,
			new BreadcrumbsBlock( $options ),
		);

		if ( is_admin() ) {
			$modules[] = new SettingsPage( $options );
			$modules[] = new AdminAssets();
			$modules[] = new EditorPanel();
			$modules[] = new RedirectsPage( $redirects_repo, $redirects_cache, $options );
		}

		return $modules;
	}
}
