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

	public function test_schema_type_for_uses_stored_value(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'schema_type' => 'NewsArticle' ) ) )
		);

		$this->assertSame( 'NewsArticle', $this->type_templates()->schema_type_for( 'post' ) );
	}

	public function test_schema_type_for_falls_back_to_automatic_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'Article', $this->type_templates()->schema_type_for( 'post' ) );
		$this->assertSame( 'WebPage', $this->type_templates()->schema_type_for( 'page' ) );
		$this->assertSame( 'none', $this->type_templates()->schema_type_for( 'attachment' ) );
		$this->assertSame( 'none', $this->type_templates()->schema_type_for( 'book' ) );
	}

	public function test_og_image_for_returns_stored_or_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'og_image' => 'https://x.test/a.jpg' ) ) )
		);

		$this->assertSame( 'https://x.test/a.jpg', $this->type_templates()->og_image_for( 'post' ) );
		$this->assertSame( '', $this->type_templates()->og_image_for( 'page' ) );
	}
}
