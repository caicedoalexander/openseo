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
}
