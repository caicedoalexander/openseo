<?php
/**
 * Unit tests for the robots meta presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Robots;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class RobotsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_attr' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options  = new Options();
		$defaults = new TemplateDefaults();
		return new Resolver( $options, new Variables( $options ), $defaults, new TypeTemplates( $options, $defaults ) );
	}

	public function test_defaults_to_index_follow_when_not_singular(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		ob_start();
		( new Robots( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="robots"', $output );
		$this->assertStringContainsString( 'content="index, follow"', $output );
	}

	public function test_emits_noindex_nofollow_from_per_entry_meta(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		// Both _openseo_robots_noindex and _openseo_robots_nofollow are '1'.
		Functions\when( 'get_post_meta' )->justReturn( '1' );

		ob_start();
		( new Robots( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'content="noindex, nofollow"', $output );
	}
}
