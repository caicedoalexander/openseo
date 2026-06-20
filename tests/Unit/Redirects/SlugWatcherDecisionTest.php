<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SlugWatcherDecisionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function watcher(): SlugWatcher {
		Functions\when( 'get_option' )->justReturn( array() );

		$repo = new Repository();

		return new SlugWatcher( $repo, new Cache( $repo ), new Options() );
	}

	public function test_creates_when_published_permalink_changes(): void {
		$this->assertTrue( $this->watcher()->should_create( '/old', '/new', 'publish', true, false, false ) );
	}

	public function test_skips_when_disabled(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', false, false, false ) );
	}

	public function test_skips_draft_to_publish(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'draft', true, false, false ) );
	}

	public function test_skips_revisions_and_autosaves(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', true, true, false ) );
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', true, false, true ) );
	}

	public function test_skips_when_permalink_unchanged(): void {
		$this->assertFalse( $this->watcher()->should_create( '/same', '/same', 'publish', true, false, false ) );
	}
}
