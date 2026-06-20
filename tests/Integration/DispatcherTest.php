<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Dispatcher;
use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	private Dispatcher $dispatcher;
	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo       = new Repository();
		$this->dispatcher = new Dispatcher( new Cache( $this->repo ), new Matcher(), $this->repo, new Options() );
	}

	public function test_resolve_returns_match_for_exact_rule(): void {
		$this->repo->create(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$result = $this->dispatcher->resolve( '/old/?utm=x' );

		$this->assertNotNull( $result );
		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
	}

	public function test_resolve_returns_null_for_unknown_path(): void {
		$this->assertNull( $this->dispatcher->resolve( '/nothing' ) );
	}

	public function test_resolve_uses_degraded_path_when_cache_degraded(): void {
		$this->repo->create(
			array( 'source_path' => '/deg', 'target' => '/dest', 'status_code' => 301, 'is_regex' => false, 'enabled' => true )
		);

		// Seed the cached count above the degradation threshold so Cache::is_degraded() returns true.
		set_transient( 'openseo_redirects_count', Cache::DEGRADE_THRESHOLD + 1 );

		$cache      = new Cache( $this->repo );
		$dispatcher = new Dispatcher( $cache, new Matcher(), $this->repo, new Options() );
		$result     = $dispatcher->resolve( '/deg' );

		delete_transient( 'openseo_redirects_count' );

		$this->assertNotNull( $result );
		$this->assertSame( '/dest', $result->target );
	}

	public function test_resolve_degraded_self_loop_returns_null(): void {
		$this->repo->create(
			array( 'source_path' => '/loop', 'target' => '/loop', 'status_code' => 301, 'is_regex' => false, 'enabled' => true )
		);

		// Seed the cached count above the degradation threshold so Cache::is_degraded() returns true.
		set_transient( 'openseo_redirects_count', Cache::DEGRADE_THRESHOLD + 1 );

		$cache      = new Cache( $this->repo );
		$dispatcher = new Dispatcher( $cache, new Matcher(), $this->repo, new Options() );
		$result     = $dispatcher->resolve( '/loop' );

		delete_transient( 'openseo_redirects_count' );

		$this->assertNull( $result );
	}

	public function test_resolve_degraded_trailing_slash_self_loop_returns_null(): void {
		// A degraded-mode rule whose target differs from the source only by a
		// trailing slash must not redirect: it would loop (/x -> /x/ -> /x -> …)
		// because Dispatcher@5 exits before redirect_canonical@10 collapses it.
		$this->repo->create(
			array( 'source_path' => '/x', 'target' => '/x/', 'status_code' => 301, 'is_regex' => false, 'enabled' => true )
		);

		// Seed the cached count above the degradation threshold so Cache::is_degraded() returns true.
		set_transient( 'openseo_redirects_count', Cache::DEGRADE_THRESHOLD + 1 );

		$cache      = new Cache( $this->repo );
		$dispatcher = new Dispatcher( $cache, new Matcher(), $this->repo, new Options() );
		$result     = $dispatcher->resolve( '/x' );

		delete_transient( 'openseo_redirects_count' );

		$this->assertNull( $result );
	}
}
