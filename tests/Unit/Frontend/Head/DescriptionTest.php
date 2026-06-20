<?php
/**
 * Unit tests for the meta description presenter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Frontend\Head;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Frontend\Head\Description;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class DescriptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function resolver(): Resolver {
		$options = new Options();

		return new Resolver( $options, new Variables( $options ) );
	}

	public function test_outputs_nothing_when_the_description_is_empty(): void {
		// Not singular and not the front page → Resolver::description() returns ''.
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		ob_start();
		( new Description( $this->resolver() ) )->output();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_outputs_an_escaped_meta_tag_when_a_value_is_present(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( 'A "quoted" & <tagged> value' );
		Functions\when( 'esc_attr' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES )
		);

		ob_start();
		( new Description( $this->resolver() ) )->output();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="description"', $output );
		$this->assertStringContainsString( 'A &quot;quoted&quot; &amp; &lt;tagged&gt; value', $output );
	}
}
