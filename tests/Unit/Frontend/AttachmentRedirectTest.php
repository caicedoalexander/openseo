<?php
/**
 * Unit tests for the attachment → parent redirect decision.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\AttachmentRedirect;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class AttachmentRedirectTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_attachment' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );
		Functions\when( 'home_url' )->justReturn( 'https://x.test/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module( array $stored ): AttachmentRedirect {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new AttachmentRedirect( new Options() );
	}

	public function test_should_redirect_when_attachment_and_enabled(): void {
		Functions\when( 'is_attachment' )->justReturn( true );

		$this->assertTrue( $this->module( array( 'attachment_redirect' => '1' ) )->should_redirect() );
	}

	public function test_no_redirect_when_disabled(): void {
		Functions\when( 'is_attachment' )->justReturn( true );

		$this->assertFalse( $this->module( array( 'attachment_redirect' => '' ) )->should_redirect() );
	}

	public function test_no_redirect_when_not_attachment(): void {
		$this->assertFalse( $this->module( array( 'attachment_redirect' => '1' ) )->should_redirect() );
	}

	public function test_target_is_parent_permalink(): void {
		Functions\when( 'get_post_field' )->justReturn( 42 ); // post_parent = 42
		Functions\when( 'get_permalink' )->alias(
			static fn( $id ) => 42 === $id ? 'https://x.test/parent/' : 'https://x.test/attachment/'
		);

		$this->assertSame( 'https://x.test/parent/', $this->module( array() )->target() );
	}

	public function test_target_falls_back_to_orphan_url(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 ); // no parent
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame(
			'https://x.test/orphan/',
			$this->module( array( 'attachment_redirect_orphan' => 'https://x.test/orphan/' ) )->target()
		);
	}

	public function test_target_falls_back_to_home_for_orphan_without_url(): void {
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame( 'https://x.test/', $this->module( array() )->target() );
	}

	public function test_target_anti_identity_falls_back_to_home(): void {
		Functions\when( 'get_post_field' )->justReturn( 10 ); // parent = the attachment itself
		Functions\when( 'get_permalink' )->justReturn( 'https://x.test/attachment/' );

		$this->assertSame( 'https://x.test/', $this->module( array() )->target() );
	}
}
