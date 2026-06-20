<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Repository;
use WP_UnitTestCase;

final class RedirectsRepositoryTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new Repository();
	}

	public function test_create_find_and_ruleset_round_trip(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = $this->repo->find( $id );
		$this->assertSame( '/old', $row['source_path'] );

		$ruleset = $this->repo->find_active_ruleset();
		$this->assertSame( $id, $ruleset->exact( '/old' )->id );
		$this->assertTrue( $this->repo->exists_for_source( '/old' ) );
	}

	public function test_update_delete_and_record_hit(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/a',
				'target'      => '/b',
				'status_code' => 302,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$this->assertTrue( $this->repo->update( $id, array( 'target' => '/c' ) ) );
		$this->assertSame( '/c', $this->repo->find( $id )['target'] );

		$this->repo->record_hit( $id );
		$this->assertSame( '1', $this->repo->find( $id )['hits'] );

		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
	}

	public function test_find_active_by_source_skips_disabled(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/x',
				'target'      => '/y',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => false,
			)
		);

		$this->assertNull( $this->repo->find_active_by_source( '/x' ) );
		$this->assertTrue( $this->repo->set_enabled( $id, true ) );
		$this->assertSame( $id, $this->repo->find_active_by_source( '/x' )->id );
	}
}
