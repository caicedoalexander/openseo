<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Admin\SettingsPage;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

	public function test_setting_is_registered_with_sanitizer(): void {
		$page = new SettingsPage( new Options() );
		$page->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Options::OPTION_KEY, $registered );
	}

	public function test_titles_fields_are_registered(): void {
		global $wp_settings_fields;

		$page = new SettingsPage( new Options() );
		$page->register_settings();

		$this->assertArrayHasKey( 'openseo', $wp_settings_fields );
		$section_fields = $wp_settings_fields['openseo']['openseo_titles'] ?? array();
		$this->assertArrayHasKey( 'title_template', $section_fields );
	}

	public function test_ai_section_and_model_field_register(): void {
		global $wp_settings_fields;

		$page = new SettingsPage( new Options() );
		$page->register_settings();

		$section_fields = $wp_settings_fields['openseo']['openseo_ai'] ?? array();
		$this->assertArrayHasKey( 'ai_model', $section_fields );
	}
}
