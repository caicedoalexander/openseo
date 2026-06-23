<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use OpenSEO\Meta\TemplateDefaults;
use PHPUnit\Framework\TestCase;

final class TemplateDefaultsTest extends TestCase {

	public function test_singular_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%title% %sep% %sitename%', $d->singular_title() );
		$this->assertSame( '%excerpt%', $d->singular_description() );
	}

	public function test_taxonomy_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%term% %sep% %sitename%', $d->taxonomy_title() );
		$this->assertSame( '%term_description%', $d->taxonomy_description() );
	}

	public function test_special_page_defaults(): void {
		$d = new TemplateDefaults();
		$this->assertSame( '%name% %sep% %sitename%', $d->author_title() );
		$this->assertSame( '%search_query% %sep% %sitename%', $d->search_title() );
		$this->assertSame( 'Page Not Found %sep% %sitename%', $d->not_found_title() );
	}
}
