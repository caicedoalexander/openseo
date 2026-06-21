<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class TypeTemplatesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function type_templates(): TypeTemplates {
		return new TypeTemplates( new Options(), new TemplateDefaults() );
	}

	public function test_stored_template_wins(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Stored %sitename%', 'description' => 'Stored desc' ) ) )
		);

		$this->assertSame( 'Stored %sitename%', $this->type_templates()->title_for( 'post' ) );
		$this->assertSame( 'Stored desc', $this->type_templates()->description_for( 'post' ) );
	}

	public function test_falls_back_to_singular_default_when_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '' ) ) )
		);

		$this->assertSame( '%title% %sep% %sitename%', $this->type_templates()->title_for( 'post' ) );
		$this->assertSame( '%excerpt%', $this->type_templates()->description_for( 'post' ) );
	}

	public function test_falls_back_for_unknown_type(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '%title% %sep% %sitename%', $this->type_templates()->title_for( 'book' ) );
		$this->assertSame( '%excerpt%', $this->type_templates()->description_for( 'book' ) );
	}
}
