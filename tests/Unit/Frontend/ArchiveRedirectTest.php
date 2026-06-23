<?php
/**
 * Unit tests for the archive-disable redirect decision.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\ArchiveRedirect;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ArchiveRedirectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'is_date' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function redirect( array $stored ): ArchiveRedirect {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new ArchiveRedirect( new Options() );
	}

	public function test_redirects_author_when_archives_disabled(): void {
		Functions\when( 'is_author' )->justReturn( true );

		$this->assertTrue( $this->redirect( array( 'author_archives' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_author_when_archives_enabled(): void {
		Functions\when( 'is_author' )->justReturn( true );

		$this->assertFalse( $this->redirect( array( 'author_archives' => '1' ) )->should_redirect() );
	}

	public function test_redirects_date_when_archives_disabled(): void {
		Functions\when( 'is_date' )->justReturn( true );

		$this->assertTrue( $this->redirect( array( 'date_archives' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_on_a_normal_request(): void {
		$this->assertFalse( $this->redirect( array() )->should_redirect() );
	}
}
