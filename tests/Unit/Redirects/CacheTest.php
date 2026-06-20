<?php
/**
 * Unit tests for the redirect ruleset cache.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\Ruleset;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_get_returns_object_cache_hit_without_touching_lower_stores(): void {
		$ruleset = new Ruleset();
		Functions\expect( 'wp_cache_get' )->once()->andReturn( $ruleset );
		Functions\expect( 'get_transient' )->never();

		$cache = new Cache( new Repository() );

		$this->assertSame( $ruleset, $cache->get() );
	}

	public function test_get_warms_object_cache_from_transient(): void {
		$ruleset = new Ruleset();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\expect( 'get_transient' )->once()->andReturn( $ruleset );
		Functions\expect( 'wp_cache_set' )->once();

		$cache = new Cache( new Repository() );

		$this->assertSame( $ruleset, $cache->get() );
	}

	public function test_get_builds_from_repo_and_populates_both_stores_on_full_miss(): void {
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}

		// A minimal $wpdb so Repository::find_active_ruleset() returns an empty set.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			/**
			 * @param string $query  SQL query.
			 * @param string $output Output type.
			 * @return array<int, array<string, mixed>>
			 */
			public function get_results( $query, $output ): array {
				return array();
			}
		};

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'wp_cache_set' )->once();
		Functions\expect( 'set_transient' )->once();

		$cache  = new Cache( new Repository() );
		$result = $cache->get();

		$this->assertInstanceOf( Ruleset::class, $result );
	}

	public function test_flush_invalidates_both_object_cache_and_transients(): void {
		$cache_deletes     = 0;
		$transient_deletes = 0;
		Functions\when( 'wp_cache_delete' )->alias(
			static function () use ( &$cache_deletes ): void {
				++$cache_deletes;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$transient_deletes ): void {
				++$transient_deletes;
			}
		);

		$cache = new Cache( new Repository() );
		$cache->flush();

		// Dual-store invalidation: ruleset + count in the object cache, and both
		// transients.
		$this->assertSame( 2, $cache_deletes );
		$this->assertSame( 2, $transient_deletes );
	}

	public function test_is_degraded_true_above_threshold_from_cached_count(): void {
		Functions\when( 'wp_cache_get' )->justReturn( Cache::DEGRADE_THRESHOLD + 1 );

		$cache = new Cache( new Repository() );

		$this->assertTrue( $cache->is_degraded() );
	}

	public function test_is_degraded_false_at_threshold(): void {
		Functions\when( 'wp_cache_get' )->justReturn( Cache::DEGRADE_THRESHOLD );

		$cache = new Cache( new Repository() );

		$this->assertFalse( $cache->is_degraded() );
	}
}
