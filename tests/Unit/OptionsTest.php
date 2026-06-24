<?php
/**
 * Unit tests for the Options value object.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn(
			array( 'post' => $this->fake_type( 'post' ), 'page' => $this->fake_type( 'page' ) )
		);
		Functions\when( 'get_taxonomies' )->justReturn(
			array( 'category' => $this->fake_type( 'category' ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_type( string $name ): object {
		$labels       = new \stdClass();
		$labels->name = ucfirst( $name );
		$type         = new \stdClass();
		$type->name   = $name;
		$type->labels = $labels;
		return $type;
	}

	public function test_returns_on_page_defaults_when_nothing_is_stored(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '-', $options->get( 'title_separator' ) );
		$this->assertSame( '', $options->get( 'og_default_image' ) );
	}

	public function test_stored_values_override_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'title_separator' => '|' )
		);

		$options = new Options();

		$this->assertSame( '|', $options->get( 'title_separator' ) );
		// Untouched key still falls back to its default.
		$this->assertSame( '%sitename% %sep% %tagline%', $options->get( 'home_title' ) );
	}

	public function test_sanitize_cleans_and_normalizes_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $value ) => trim( wp_strip_tags_compat( (string) $value ) )
		);
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'title_separator'  => '  <b>|</b>  ',
				'home_title'       => '%sitename%',
				'home_description' => 'Home desc',
				'og_default_image' => 'https://example.com/og.png',
				'ai_model'         => 'claude-opus-4-8',
			)
		);

		$this->assertSame( '|', $clean['title_separator'] );
		$this->assertSame( 'https://example.com/og.png', $clean['og_default_image'] );
		$this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
	}

	public function test_sanitize_handles_non_array_input(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$clean = $options->sanitize( 'not-an-array' );

		$this->assertSame( '-', $clean['title_separator'] );
		$this->assertSame( '', $clean['og_default_image'] );
	}

	public function test_sanitize_preserves_keys_absent_from_a_partial_tab_submission(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// A previously saved value from another tab is currently stored.
		Functions\when( 'get_option' )->justReturn(
			array( 'home_title' => 'Stored home %sep% %tagline%' )
		);

		$options = new Options();

		// The AI tab posts only its own field.
		$clean = $options->sanitize( array( 'ai_model' => 'claude-opus-4-8' ) );

		$this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
		// The unrelated tab's saved value survives instead of resetting to default.
		$this->assertSame( 'Stored home %sep% %tagline%', $clean['home_title'] );
	}

	public function test_global_template_keys_are_retired(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = ( new Options() )->all();

		$this->assertArrayNotHasKey( 'title_template', $all );
		$this->assertArrayNotHasKey( 'description_template', $all );
	}

	public function test_sitemap_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '1', $options->get( 'sitemap_enabled' ) );
		$this->assertSame( '', $options->get( 'sitemap_include_authors' ) );
	}

	public function test_sanitize_normalizes_sitemap_checkboxes(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$on = $options->sanitize(
			array(
				'sitemap_enabled'         => '1',
				'sitemap_include_authors' => '1',
			)
		);
		$this->assertSame( '1', $on['sitemap_enabled'] );
		$this->assertSame( '1', $on['sitemap_include_authors'] );

		$off = $options->sanitize(
			array(
				'sitemap_enabled'         => '0',
				'sitemap_include_authors' => '0',
			)
		);
		$this->assertSame( '', $off['sitemap_enabled'] );
		$this->assertSame( '', $off['sitemap_include_authors'] );
	}

	public function test_schema_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( 'Organization', $options->get( 'schema_site_type' ) );
		$this->assertSame( '', $options->get( 'schema_site_name' ) );
		$this->assertSame( '', $options->get( 'schema_logo' ) );
		$this->assertSame( '›', $options->get( 'breadcrumb_separator' ) );
	}

	public function test_sanitize_normalizes_schema_fields(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize(
			array(
				'schema_site_type'     => 'Person',
				'schema_site_name'     => 'Jane Doe',
				'schema_logo'          => 'https://example.com/logo.png',
				'breadcrumb_separator' => '/',
			)
		);

		$this->assertSame( 'Person', $clean['schema_site_type'] );
		$this->assertSame( 'Jane Doe', $clean['schema_site_name'] );
		$this->assertSame( 'https://example.com/logo.png', $clean['schema_logo'] );
		$this->assertSame( '/', $clean['breadcrumb_separator'] );
	}

	public function test_sanitize_rejects_unknown_schema_site_type(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$options = new Options();

		$clean = $options->sanitize( array( 'schema_site_type' => 'Robot' ) );

		// Unknown value falls back to the default, never stored verbatim.
		$this->assertSame( 'Organization', $clean['schema_site_type'] );
	}

	public function test_defaults_include_redirect_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$options = new Options();

		$this->assertSame( '1', $options->get( 'redirects_auto_slug' ) );
		$this->assertSame( '1', $options->get( 'redirects_track_hits' ) );
		$this->assertSame( '301', $options->get( 'redirects_default_status' ) );
		$this->assertSame( '', $options->get( 'notfound_monitor_enabled' ) );
		$this->assertSame( '30', $options->get( 'notfound_retention_days' ) );
	}

	public function test_sanitize_clamps_retention_and_status(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		$options = new Options();

		$clean = $options->sanitize(
			array(
				'redirects_default_status' => '999',
				'notfound_retention_days'  => '0',
				'notfound_monitor_enabled' => '1',
			)
		);

		$this->assertSame( '301', $clean['redirects_default_status'] ); // Off-list resets.
		$this->assertSame( '1', $clean['notfound_retention_days'] );    // Clamped to minimum 1.
		$this->assertSame( '1', $clean['notfound_monitor_enabled'] );

		$valid = $options->sanitize( array( 'redirects_default_status' => '307' ) );
		$this->assertSame( '307', $valid['redirects_default_status'] );
	}

	public function test_defaults_include_empty_template_maps(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$all = ( new Options() )->all();

		$this->assertSame( array(), $all['post_types'] );
		$this->assertSame( array(), $all['taxonomies'] );
	}

	public function test_sanitize_stores_whitelisted_post_type_template(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'Custom %sitename%', 'description' => 'Desc' ) ) )
		);

		$this->assertSame(
			array( 'title' => 'Custom %sitename%', 'description' => 'Desc' ),
			$clean['post_types']['post']
		);
	}

	public function test_sanitize_rejects_unknown_slug(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'bogus' => array( 'title' => 'X', 'description' => 'Y' ) ) )
		);

		$this->assertArrayNotHasKey( 'bogus', $clean['post_types'] );
	}

	public function test_sanitize_preserves_unsent_slugs(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'page' => array( 'title' => 'Kept', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'New', 'description' => '' ) ) )
		);

		$this->assertSame( 'Kept', $clean['post_types']['page']['title'] );
		$this->assertSame( 'New', $clean['post_types']['post']['title'] );
	}

	public function test_sanitize_unsets_fully_empty_slug(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Old', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '' ) ) )
		);

		$this->assertArrayNotHasKey( 'post', $clean['post_types'] );
	}

	public function test_sanitize_ignores_missing_group(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'taxonomies' => array( 'category' => array( 'title' => 'Cat', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Submit only post_types; taxonomies must be preserved untouched.
		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'P', 'description' => '' ) ) )
		);

		$this->assertSame( 'Cat', $clean['taxonomies']['category']['title'] );
	}

	public function test_sanitize_per_field_merge_keeps_unsent_field(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Stored T', 'description' => 'Stored D' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Input submits only 'title' for 'post' — 'description' is absent from the field set.
		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => 'New T' ) ) )
		);

		$this->assertSame( 'New T', $clean['post_types']['post']['title'] );
		$this->assertSame( 'Stored D', $clean['post_types']['post']['description'] );
	}

	public function test_defaults_include_empty_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( array(), ( new Options() )->all()['robots'] );
	}

	public function test_sanitize_global_robots_whitelists_and_normalizes(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$clean = ( new Options() )->sanitize(
			array(
				'robots' => array(
					'noindex'             => '1',
					'nofollow'            => '',
					'noindex_empty_terms' => '1',
					'bogus'               => '1',
				),
			)
		);

		$this->assertSame( '1', $clean['robots']['noindex'] );
		$this->assertSame( '1', $clean['robots']['noindex_empty_terms'] );
		$this->assertArrayNotHasKey( 'nofollow', $clean['robots'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['robots'] );
	}

	public function test_sanitize_per_type_robots_tristate(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'post_types' => array(
					'post' => array(
						'title'  => '',
						'robots' => array( 'noindex' => 'on', 'nofollow' => 'off', 'bogus' => 'x', 'noarchive' => 'maybe' ),
					),
				),
			)
		);

		$this->assertSame( 'on', $clean['post_types']['post']['robots']['noindex'] );
		$this->assertSame( 'off', $clean['post_types']['post']['robots']['nofollow'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['post_types']['post']['robots'] );
		$this->assertArrayNotHasKey( 'noarchive', $clean['post_types']['post']['robots'] ); // 'maybe' invalid → dropped
	}

	public function test_sanitize_keeps_slug_with_only_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '', 'robots' => array( 'noindex' => 'on' ) ) ) )
		);

		$this->assertArrayHasKey( 'post', $clean['post_types'] );
		$this->assertSame( 'on', $clean['post_types']['post']['robots']['noindex'] );
	}

	public function test_sanitize_unsets_slug_when_all_three_empty(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'title' => 'Old', 'description' => '' ) ) )
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array( 'post_types' => array( 'post' => array( 'title' => '', 'description' => '', 'robots' => array() ) ) )
		);

		$this->assertArrayNotHasKey( 'post', $clean['post_types'] );
	}

	public function test_defaults_include_meta_global_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$options = new Options();

		$this->assertSame( '', $options->get( 'capitalize_titles' ) );
		$this->assertSame( 'summary_large_image', $options->get( 'twitter_card_type' ) );
		$this->assertSame(
			array( 'enabled' => '', 'value' => 'large' ),
			$options->get( 'advanced_robots' )['max_image_preview']
		);
	}

	public function test_sanitize_capitalize_titles_checkbox(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$on  = ( new Options() )->sanitize( array( 'capitalize_titles' => '1' ) );
		$off = ( new Options() )->sanitize( array( 'capitalize_titles' => '0' ) );

		$this->assertSame( '1', $on['capitalize_titles'] );
		$this->assertSame( '', $off['capitalize_titles'] );
	}

	public function test_sanitize_twitter_card_type_whitelist(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$valid   = ( new Options() )->sanitize( array( 'twitter_card_type' => 'summary' ) );
		$invalid = ( new Options() )->sanitize( array( 'twitter_card_type' => 'bogus' ) );

		$this->assertSame( 'summary', $valid['twitter_card_type'] );
		$this->assertSame( 'summary_large_image', $invalid['twitter_card_type'] );
	}

	public function test_sanitize_advanced_robots(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'advanced_robots' => array(
					'max_snippet'       => array( 'enabled' => '1', 'length' => '50' ),
					'max_video_preview' => array( 'enabled' => '', 'length' => '-5' ),
					'max_image_preview' => array( 'enabled' => '1', 'value' => 'bogus' ),
				),
			)
		);

		$this->assertSame( array( 'enabled' => '1', 'length' => '50' ), $clean['advanced_robots']['max_snippet'] );
		$this->assertSame( array( 'enabled' => '', 'length' => '-1' ), $clean['advanced_robots']['max_video_preview'] ); // -5 clamped to -1
		$this->assertSame( array( 'enabled' => '1', 'value' => 'large' ), $clean['advanced_robots']['max_image_preview'] ); // bogus → large
	}

	public function test_defaults_include_local_identity_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$o = new Options();

		$this->assertSame( '', $o->get( 'local_website_name' ) );
		$this->assertSame( '', $o->get( 'local_website_alternate_name' ) );
		$this->assertSame( '', $o->get( 'local_url' ) );
		$this->assertSame( '', $o->get( 'local_email' ) );
	}

	public function test_sanitize_local_text_and_url(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'local_website_name'           => 'My Brand',
				'local_website_alternate_name' => 'MB',
				'local_url'                    => 'https://example.com',
			)
		);

		$this->assertSame( 'My Brand', $clean['local_website_name'] );
		$this->assertSame( 'MB', $clean['local_website_alternate_name'] );
		$this->assertSame( 'https://example.com', $clean['local_url'] );
	}

	public function test_sanitize_local_email_valid_and_invalid(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'is_email' )->alias(
			static fn( $v ) => str_contains( (string) $v, '@' ) ? $v : false
		);

		$valid   = ( new Options() )->sanitize( array( 'local_email' => 'hi@example.com' ) );
		$invalid = ( new Options() )->sanitize( array( 'local_email' => 'not-an-email' ) );

		$this->assertSame( 'hi@example.com', $valid['local_email'] );
		$this->assertSame( '', $invalid['local_email'] );
	}

	public function test_defaults_include_local_business_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$o = new Options();

		$this->assertSame( '', $o->get( 'local_business_type' ) );
		$this->assertSame( array(), $o->get( 'local_opening_hours' ) );
		$this->assertSame(
			array( 'street' => '', 'locality' => '', 'region' => '', 'postal_code' => '', 'country' => '' ),
			$o->get( 'local_address' )
		);
	}

	public function test_sanitize_delegates_local_business_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'local_business_type' => 'Restaurant' ) );
		$this->assertSame( 'Restaurant', $clean['local_business_type'] );
	}

	public function test_sanitize_other_tab_does_not_wipe_local_keys(): void {
		// A previously saved local_business_type is stored; another tab posts only its own field.
		Functions\when( 'get_option' )->justReturn( array( 'local_business_type' => 'Store' ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'title_separator' => '|' ) );
		$this->assertSame( 'Store', $clean['local_business_type'] );
	}

	public function test_sanitize_keeps_separator_value_unchanged(): void {
		// Brain Monkey does not load WP, so sanitize_text_field is a passthrough here;
		// this asserts Options itself never mutilates a multibyte separator char.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize( array( 'title_separator' => '—' ) );

		$this->assertSame( '—', $clean['title_separator'] );
	}

	public function test_defaults_include_attachment_redirect_keys(): void {
		Functions\when( 'get_option' )->justReturn( false );

		$all = ( new Options() )->all();

		$this->assertSame( '1', $all['attachment_redirect'] );
		$this->assertSame( '', $all['attachment_redirect_orphan'] );
	}

	public function test_defaults_include_special_page_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$options = new Options();

		$this->assertSame( '1', $options->get( 'author_archives' ) );
		$this->assertSame( '1', $options->get( 'date_archives' ) );
		$this->assertSame( '1', $options->get( 'noindex_search' ) );
		$this->assertSame( '%name% %sep% %sitename%', $options->get( 'author_title' ) );
		$this->assertSame( 'Page Not Found %sep% %sitename%', $options->get( 'title_404' ) );
		$this->assertSame( '%search_query% %sep% %sitename%', $options->get( 'search_title' ) );
		$this->assertSame( '', $options->get( 'home_robots_custom' ) );
		$this->assertSame( array(), $options->get( 'home_robots' ) );
	}

	public function test_sanitize_handles_special_page_fields(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();

		$clean = ( new Options() )->sanitize(
			array(
				'home_robots_custom'         => '1',
				'home_robots'                => array( 'noindex' => '1', 'bogus' => '1', 'nofollow' => '' ),
				'home_og_title'              => 'Home OG',
				'home_og_description'        => 'Home OG desc',
				'home_og_image'              => 'https://example.com/og.png',
				'author_archives'            => '0',
				'author_title'               => '%name%',
				'author_robots_custom'       => '1',
				'author_robots'              => array( 'noindex' => '1' ),
				'date_archives'              => '0',
				'title_404'                  => '404 %sitename%',
				'search_title'               => '%search_query%',
				'noindex_search'             => '1',
				'noindex_paginated'          => '1',
				'noindex_paginated_singular' => '0',
				'noindex_password_protected' => '1',
			)
		);

		$this->assertSame( '1', $clean['home_robots_custom'] );
		$this->assertSame( array( 'noindex' => '1' ), $clean['home_robots'] );
		$this->assertSame( 'Home OG', $clean['home_og_title'] );
		$this->assertSame( 'Home OG desc', $clean['home_og_description'] );
		$this->assertSame( 'https://example.com/og.png', $clean['home_og_image'] );
		$this->assertSame( '', $clean['author_archives'] );
		$this->assertSame( '%name%', $clean['author_title'] );
		$this->assertSame( array( 'noindex' => '1' ), $clean['author_robots'] );
		$this->assertSame( '', $clean['date_archives'] );
		$this->assertSame( '1', $clean['noindex_search'] );
		$this->assertSame( '1', $clean['noindex_paginated'] );
		$this->assertSame( '', $clean['noindex_paginated_singular'] );
		$this->assertSame( '1', $clean['noindex_password_protected'] );
	}
}


/**
 * Strip tags without relying on WordPress being loaded.
 */
function wp_strip_tags_compat( string $value ): string {
	return trim( preg_replace( '/<[^>]*>/', '', $value ) ?? '' );
}
