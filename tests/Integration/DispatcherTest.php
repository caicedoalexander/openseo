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
}
