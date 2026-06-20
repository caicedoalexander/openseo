<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SlugWatcherTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();

		Schema::install();
		update_option( 'openseo_settings', array( 'redirects_auto_slug' => '1' ) );

		// The test bench starts with plain permalinks (?p=N); without a pretty
		// structure, old and new permalinks both normalize to '/' and no
		// redirect is ever created. set_permalink_structure() flushes rules.
		$this->set_permalink_structure( '/%postname%/' );

		$this->repo = new Repository();
		$watcher    = new SlugWatcher( $this->repo, new Cache( $this->repo ), new Options() );
		$watcher->register();
	}

	public function test_renaming_published_post_creates_redirect(): void {
		$post_id = $this->publish( 'original-slug' );
		$this->rename( $post_id, 'renamed-slug' );

		$this->assertTrue( $this->repo->exists_for_source( '/original-slug' ) );
	}

	public function test_unchanged_slug_creates_no_redirect(): void {
		$post_id = $this->publish( 'keep-slug' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'New Title',
			)
		);

		$this->assertFalse( $this->repo->exists_for_source( '/keep-slug' ) );
	}

	public function test_draft_rename_creates_no_redirect(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => 'draft-slug',
				'post_title'  => 'Draft',
			)
		);

		$this->rename( $post_id, 'draft-renamed' );

		$this->assertFalse( $this->repo->exists_for_source( '/draft-slug' ) );
	}

	public function test_rename_back_collapses_chain_without_loop(): void {
		$post_id = $this->publish( 'a' );

		$this->rename( $post_id, 'b' );
		$this->assertTrue( $this->repo->exists_for_source( '/a' ), 'forward rule /a should exist' );

		// Renaming /b back to /a must drop the prior /a → /b rule instead of
		// adding /b → /a, which would form an infinite redirect pair.
		$this->rename( $post_id, 'a' );

		$this->assertFalse( $this->repo->exists_for_source( '/a' ), '/a → /b must be collapsed' );
		$this->assertTrue( $this->repo->exists_for_source( '/b' ), '/b → /a should exist' );
	}

	public function test_existing_redirect_for_old_slug_is_not_duplicated(): void {
		$post_id = $this->publish( 'dup-slug' );

		// Pre-seed a manual redirect for the old slug before renaming.
		$this->repo->create(
			array( 'source_path' => '/dup-slug', 'target' => '/elsewhere', 'status_code' => 301, 'is_regex' => false, 'enabled' => true )
		);

		$this->rename( $post_id, 'dup-renamed' );

		// The duplicate guard (exists_for_source) must prevent a second /dup-slug rule.
		$rules = array_filter(
			$this->repo->all( 100, 0 ),
			static fn( array $row ): bool => '/dup-slug' === $row['source_path']
		);
		$this->assertCount( 1, $rules );
	}

	private function publish( string $slug ): int {
		return self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
			)
		);
	}

	private function rename( int $post_id, string $slug ): void {
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => $slug,
			)
		);
	}
}
