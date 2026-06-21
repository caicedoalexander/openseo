<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Lifecycle;

use OpenSEO\Lifecycle\SettingsMigrations;
use PHPUnit\Framework\TestCase;

final class SettingsMigrationsTest extends TestCase {

	public function test_copies_customized_global_title_to_post_and_page(): void {
		$out = SettingsMigrations::migrate_array(
			array( 'title_template' => 'Custom %title%' )
		);

		$this->assertSame( 'Custom %title%', $out['post_types']['post']['title'] );
		$this->assertSame( 'Custom %title%', $out['post_types']['page']['title'] );
		$this->assertArrayNotHasKey( 'title_template', $out );
	}

	public function test_copies_only_customized_field(): void {
		$out = SettingsMigrations::migrate_array(
			array(
				'title_template'       => '%title% %sep% %sitename%', // default → not copied
				'description_template' => 'Custom desc',             // customized → copied
			)
		);

		// Only description was customized: post/page get description, not title.
		$this->assertSame( 'Custom desc', $out['post_types']['post']['description'] );
		$this->assertSame( 'Custom desc', $out['post_types']['page']['description'] );
		$this->assertArrayNotHasKey( 'title', $out['post_types']['post'] );
	}

	public function test_default_templates_produce_no_post_types(): void {
		$out = SettingsMigrations::migrate_array(
			array(
				'title_template'       => '%title% %sep% %sitename%',
				'description_template' => '%excerpt%',
			)
		);

		$this->assertArrayNotHasKey( 'post_types', $out );
		$this->assertArrayNotHasKey( 'title_template', $out );
		$this->assertArrayNotHasKey( 'description_template', $out );
	}

	public function test_is_idempotent(): void {
		$once  = SettingsMigrations::migrate_array( array( 'title_template' => 'Custom' ) );
		$twice = SettingsMigrations::migrate_array( $once );

		$this->assertSame( $once, $twice );
	}
}
