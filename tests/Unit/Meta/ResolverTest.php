<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\TemplateDefaults;
use OpenSEO\Meta\TypeTemplates;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class ResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'get_the_title' )->justReturn( '' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_the_date' )->justReturn( '' );
		Functions\when( 'get_the_modified_date' )->justReturn( '' );
		Functions\when( 'get_post_field' )->justReturn( 0 );
		Functions\when( 'get_the_author_meta' )->justReturn( '' );
		Functions\when( 'get_the_category' )->justReturn( array() );
		Functions\when( 'get_the_tags' )->justReturn( false );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
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

	public function test_title_prefers_per_entry_override_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_title' === $key ? 'Manual title' : ''
		);

		$this->assertSame( 'Manual title', $this->resolver()->title() );
	}

	public function test_title_falls_back_to_template_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );

		// Default template: "%title% %sep% %sitename%".
		$this->assertSame( 'Post Title - My Site', $this->resolver()->title() );
	}

	public function test_title_is_empty_on_unhandled_context(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame( '', $this->resolver()->title() );
	}

	public function test_robots_reflects_noindex_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_robots_noindex' === $key ? '1' : ''
		);

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_canonical_defaults_to_permalink_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/post/' );

		$this->assertSame( 'https://example.com/post/', $this->resolver()->canonical() );
	}

	// -----------------------------------------------------------------------
	// description()
	// -----------------------------------------------------------------------

	public function test_description_prefers_per_entry_override_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_description' === $key ? 'Custom desc' : ''
		);

		$this->assertSame( 'Custom desc', $this->resolver()->description() );
	}

	public function test_description_falls_back_to_template_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// Default description_template is '%excerpt%'; setUp mocks get_the_excerpt → ''.
		// wp_strip_all_tags is also mocked in setUp to return its arg → ''.

		$this->assertSame( '', $this->resolver()->description() );
	}

	public function test_description_returns_home_description_option_on_front_page(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		// Override get_option so Options returns a stored home_description.
		Functions\when( 'get_option' )->justReturn( array( 'home_description' => 'Site description' ) );

		$this->assertSame( 'Site description', $this->resolver()->description() );
	}

	public function test_description_falls_back_to_bloginfo_when_home_description_empty(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		// get_option returns empty array → home_description defaults to '' → fall back to bloginfo.
		// setUp already mocks get_bloginfo → 'My Site'.

		$this->assertSame( 'My Site', $this->resolver()->description() );
	}

	public function test_description_returns_empty_on_non_singular_non_front_page(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );

		$this->assertSame( '', $this->resolver()->description() );
	}

	// -----------------------------------------------------------------------
	// canonical()
	// -----------------------------------------------------------------------

	public function test_canonical_prefers_per_entry_override_on_singular(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_canonical' === $key ? 'https://example.com/custom/' : ''
		);

		$this->assertSame( 'https://example.com/custom/', $this->resolver()->canonical() );
	}

	public function test_canonical_returns_empty_on_non_singular(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$this->assertSame( '', $this->resolver()->canonical() );
	}

	// -----------------------------------------------------------------------
	// robots()
	// -----------------------------------------------------------------------

	public function test_robots_defaults_to_index_follow_on_non_singular(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$this->assertSame( 'index, follow', $this->resolver()->robots() );
	}

	public function test_robots_reflects_nofollow_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_robots_nofollow' === $key ? '1' : ''
		);

		$this->assertSame( 'index, nofollow', $this->resolver()->robots() );
	}

	public function test_robots_global_noindex_applies_when_no_overrides(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( array( 'robots' => array( 'noindex' => '1' ) ) );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	public function test_robots_per_type_overrides_global(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'robots'     => array( 'noindex' => '1' ),
				'post_types' => array( 'page' => array( 'robots' => array( 'noindex' => 'off' ) ) ),
			)
		);

		// Type forces index over a global noindex.
		$this->assertSame( 'index, follow', $this->resolver()->robots() );
	}

	public function test_robots_entry_off_overrides_type_noindex(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_robots_noindex' === $key ? 'off' : ''
		);
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'robots' => array( 'noindex' => 'on' ) ) ) )
		);

		$this->assertSame( 'index, follow', $this->resolver()->robots() );
	}

	public function test_robots_adds_extra_directives_from_type(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'post' => array( 'robots' => array( 'noarchive' => 'on', 'nosnippet' => 'on' ) ) ) )
		);

		$this->assertSame( 'index, follow, noarchive, nosnippet', $this->resolver()->robots() );
	}

	public function test_robots_taxonomy_empty_term_forces_noindex(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'robots' => array( 'noindex_empty_terms' => '1' ) ) );

		$term        = new WP_Term();
		$term->name  = 'Empty';
		$term->count = 0;
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$this->assertSame( 'noindex, follow', $this->resolver()->robots() );
	}

	// -----------------------------------------------------------------------
	// social_title() / social_description()
	// -----------------------------------------------------------------------

	public function test_social_title_prefers_og_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_og_title' === $key ? 'OG Title' : ''
		);

		$this->assertSame( 'OG Title', $this->resolver()->social_title() );
	}

	public function test_social_title_falls_back_to_resolved_title(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );

		// title() → template '%title% %sep% %sitename%' → 'Post Title - My Site'.
		$this->assertSame( 'Post Title - My Site', $this->resolver()->social_title() );
	}

	public function test_social_description_prefers_og_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_og_description' === $key ? 'OG Desc' : ''
		);

		$this->assertSame( 'OG Desc', $this->resolver()->social_description() );
	}

	public function test_social_description_falls_back_to_resolved_description(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// description() → template '%excerpt%' → wp_strip_all_tags(get_the_excerpt()) → ''.

		$this->assertSame( '', $this->resolver()->social_description() );
	}

	// -----------------------------------------------------------------------
	// social_image()
	// -----------------------------------------------------------------------

	public function test_social_image_prefers_og_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_og_image' === $key ? 'https://example.com/og.jpg' : ''
		);
		// get_the_post_thumbnail_url must be mocked so Brain Monkey does not fatal.
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );

		$this->assertSame( 'https://example.com/og.jpg', $this->resolver()->social_image() );
	}

	public function test_social_image_falls_back_to_featured_image(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.com/featured.jpg' );

		$this->assertSame( 'https://example.com/featured.jpg', $this->resolver()->social_image() );
	}

	public function test_social_image_falls_back_to_global_default_when_no_featured_image(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );
		// Store og_default_image in options.
		Functions\when( 'get_option' )->justReturn( array( 'og_default_image' => 'https://example.com/default.jpg' ) );

		$this->assertSame( 'https://example.com/default.jpg', $this->resolver()->social_image() );
	}

	public function test_social_image_returns_empty_when_non_singular_and_no_default(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		// og_default_image defaults to '' (empty stored options).

		$this->assertSame( '', $this->resolver()->social_image() );
	}

	// -----------------------------------------------------------------------
	// twitter_title() / twitter_description() / twitter_image()
	// -----------------------------------------------------------------------

	public function test_twitter_title_prefers_twitter_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_twitter_title' === $key ? 'TW Title' : ''
		);

		$this->assertSame( 'TW Title', $this->resolver()->twitter_title() );
	}

	public function test_twitter_title_falls_back_to_social_title(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );

		$this->assertSame( 'Post Title - My Site', $this->resolver()->twitter_title() );
	}

	public function test_twitter_description_prefers_twitter_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_twitter_description' === $key ? 'TW Desc' : ''
		);

		$this->assertSame( 'TW Desc', $this->resolver()->twitter_description() );
	}

	public function test_twitter_description_falls_back_to_social_description(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( '', $this->resolver()->twitter_description() );
	}

	public function test_twitter_image_prefers_twitter_override(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_openseo_twitter_image' === $key ? 'https://example.com/tw.jpg' : ''
		);
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( false );

		$this->assertSame( 'https://example.com/tw.jpg', $this->resolver()->twitter_image() );
	}

	public function test_twitter_image_falls_back_to_social_image(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_post_thumbnail_url' )->justReturn( 'https://example.com/featured.jpg' );

		$this->assertSame( 'https://example.com/featured.jpg', $this->resolver()->twitter_image() );
	}

	public function test_title_uses_stored_post_type_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'About' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn(
			array( 'post_types' => array( 'page' => array( 'title' => '%title% %sep% %sitename% PAGE' ) ) )
		);

		$this->assertSame( 'About - My Site PAGE', $this->resolver()->title() );
	}

	public function test_title_falls_back_to_singular_default_when_no_stored_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'Post Title' );
		Functions\when( 'get_the_excerpt' )->justReturn( '' );

		// Default singular title '%title% %sep% %sitename%'.
		$this->assertSame( 'Post Title - My Site', $this->resolver()->title() );
	}

	public function test_title_resolves_taxonomy_with_default(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_category' )->justReturn( true );

		$term       = new WP_Term();
		$term->name = 'News';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		// taxonomies map empty → default '%term% %sep% %sitename%'.
		$this->assertSame( 'News - My Site', $this->resolver()->title() );
	}

	public function test_description_resolves_taxonomy_template(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_tag' )->justReturn( true );

		$term              = new WP_Term();
		$term->name        = 'Tag';
		$term->description = 'Tag desc.';
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		// Default taxonomy description '%term_description%'.
		$this->assertSame( 'Tag desc.', $this->resolver()->description() );
	}

	// -----------------------------------------------------------------------
	// title() capitalization
	// -----------------------------------------------------------------------

	public function test_title_capitalizes_when_enabled(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'hello world' );
		Functions\when( 'get_option' )->justReturn( array( 'capitalize_titles' => '1' ) );

		// '%title% %sep% %sitename%' → 'hello world - My Site' → capitalized.
		$this->assertSame( 'Hello World - My Site', $this->resolver()->title() );
	}

	public function test_title_not_capitalized_by_default(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'get_queried_object_id' )->justReturn( 5 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_title' )->justReturn( 'hello world' );

		$this->assertSame( 'hello world - My Site', $this->resolver()->title() );
	}
}
