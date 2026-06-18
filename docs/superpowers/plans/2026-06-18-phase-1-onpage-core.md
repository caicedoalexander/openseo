# Phase 1 — On-Page Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give OpenSEO real per-entry SEO: store overrides in `postmeta`, edit them in a Gutenberg panel, and emit a complete `<head>` (title with variables, description, robots, canonical, Open Graph, Twitter Cards).

**Architecture:** A `Meta` layer owns the data and the resolution cascade (per-entry override → content-type template → global fallback); a `Frontend\Head` layer turns resolved values into escaped tags; an `Admin\Editor` layer ships the React panel that reads/writes the registered meta through the REST API. Each unit is a `Hookable` registered in `Plugin::modules()`, matching the existing composition-root pattern. The legacy `Frontend\MetaTags` module is replaced.

**Tech Stack:** PHP 8.1, WordPress 7.0 (Settings API, `register_post_meta`, `pre_get_document_title`), Composer PSR-4 (`OpenSEO\` → `src/`), PHPUnit 9.6 + Brain Monkey (unit) / WP test suite (integration), `@wordpress/scripts` (webpack, Jest) for the JS panel.

## Global Constraints

- **Platform floors:** WordPress 7.0+, PHP 8.1+. Every PHP file starts with `declare( strict_types=1 );`.
- **Prefixes:** code uses `OpenSEO\` (namespace), constants `OPENSEO_*`, text domain `openseo`. Postmeta keys are prefixed `_openseo_`.
- **Single option key:** all settings live under `Options::OPTION_KEY` (`openseo_settings`). No new options.
- **Security:** sanitize on input (`sanitize_callback` per meta + `Options::sanitize()`), escape on output (`esc_attr`/`esc_url`/`esc_html`). Read explicit keys with `wp_unslash`; never process whole `$_POST`/`$_GET`. Meta `auth_callback` requires `edit_post`.
- **Style classes are `final`**, methods small, files focused. PHPCS = WordPress Coding Standards (`composer lint`); static analysis = PHPStan level 6 (`composer analyze`).
- **Keep all gates green before each commit:** `composer lint && composer analyze && composer test:unit`.
- **TDD:** write the failing test first, watch it fail, implement the minimum, watch it pass, commit.

---

## File Structure

**Created (PHP):**
- `src/Meta/Variables.php` — replaces `%title%`, `%sitename%`, `%tagline%`, `%sep%`, `%excerpt%`, `%currentyear%` in templates.
- `src/Meta/PostMeta.php` — `Hookable`; registers the `_openseo_*` meta keys on `init` with `show_in_rest`, `sanitize_callback`, `auth_callback`.
- `src/Meta/Resolver.php` — the resolution cascade; returns effective values for the queried object.
- `src/Frontend/Head/Presenter.php` — interface `output(): void`.
- `src/Frontend/Head/HeadPrinter.php` — `Hookable`; loops registered presenters on `wp_head`.
- `src/Frontend/Head/Title.php` — `Hookable`; filters `pre_get_document_title`.
- `src/Frontend/Head/Description.php`, `Robots.php`, `Canonical.php`, `OpenGraph.php`, `Twitter.php` — presenters.
- `src/Admin/Editor/EditorPanel.php` — `Hookable`; enqueues the editor bundle on `enqueue_block_editor_assets`.
- `assets/src/editor/index.js` — registers the document settings panel.
- `assets/src/editor/preview.js` — pure helper that builds the Google snippet preview (unit-tested).
- `assets/src/editor/preview.test.js` — Jest test for the helper.

**Modified:**
- `src/Settings/Options.php` — new on-page defaults + sanitize.
- `src/Plugin.php` — wire the new modules, drop `MetaTags`.
- `src/Admin/SettingsPage.php` — query-arg tabs (General · Titles & Meta · Social) + the new fields.
- `templates/admin/settings-page.php` — render the tab nav.
- `webpack.config.js` — add the `editor` entry.
- `tests/Unit/OptionsTest.php` — cover the new defaults/sanitize.
- `tests/Integration/PluginBootTest.php` — replace the old meta-description assertions.

**Deleted:**
- `src/Frontend/MetaTags.php` — superseded by `Frontend\Head\*`.

---

## Task 1: Expand `Options` with on-page defaults

**Files:**
- Modify: `src/Settings/Options.php`
- Test: `tests/Unit/OptionsTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: option keys read by later tasks — `title_separator` (string), `title_template` (string), `description_template` (string), `home_title` (string), `home_description` (string), `og_default_image` (string URL). Keeps `ai_model` (string) for Phase 2. **Removes** `enable_meta_description` and `default_meta_description`.

- [ ] **Step 1: Write the failing test**

Replace the four test method bodies in `tests/Unit/OptionsTest.php` with the new shape. **Keep intact** the `setUp`/`tearDown` methods AND the `wp_strip_all_tags_stub()` / `wp_strip_tags_compat()` helper functions at the bottom of the file — the `sanitize_text_field` alias below still calls `wp_strip_tags_compat()`, so deleting it breaks the test with an undefined-function fatal:

```php
public function test_returns_on_page_defaults_when_nothing_is_stored(): void {
    Functions\when( 'get_option' )->justReturn( array() );

    $options = new Options();

    $this->assertSame( '-', $options->get( 'title_separator' ) );
    $this->assertSame( '%title% %sep% %sitename%', $options->get( 'title_template' ) );
    $this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
    $this->assertSame( '', $options->get( 'og_default_image' ) );
}

public function test_stored_values_override_defaults(): void {
    Functions\when( 'get_option' )->justReturn(
        array( 'title_separator' => '|' )
    );

    $options = new Options();

    $this->assertSame( '|', $options->get( 'title_separator' ) );
    // Untouched key still falls back to its default.
    $this->assertSame( '%excerpt%', $options->get( 'description_template' ) );
}

public function test_sanitize_cleans_and_normalizes_input(): void {
    Functions\when( 'wp_unslash' )->returnArg();
    Functions\when( 'sanitize_text_field' )->alias(
        static fn( $value ) => trim( wp_strip_tags_compat( (string) $value ) )
    );
    Functions\when( 'esc_url_raw' )->returnArg();

    $options = new Options();

    $clean = $options->sanitize(
        array(
            'title_separator'      => '  <b>|</b>  ',
            'title_template'       => '%title% %sep% %sitename%',
            'description_template' => '%excerpt%',
            'home_title'           => '%sitename%',
            'home_description'     => 'Home desc',
            'og_default_image'     => 'https://example.com/og.png',
            'ai_model'             => 'claude-opus-4-8',
        )
    );

    $this->assertSame( '|', $clean['title_separator'] );
    $this->assertSame( '%title% %sep% %sitename%', $clean['title_template'] );
    $this->assertSame( 'https://example.com/og.png', $clean['og_default_image'] );
    $this->assertSame( 'claude-opus-4-8', $clean['ai_model'] );
}

public function test_sanitize_handles_non_array_input(): void {
    $options = new Options();

    $clean = $options->sanitize( 'not-an-array' );

    $this->assertSame( '-', $clean['title_separator'] );
    $this->assertSame( '', $clean['og_default_image'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL (defaults `title_separator` etc. do not exist yet).

- [ ] **Step 3: Implement the new defaults and sanitize**

Replace `defaults()` and `sanitize()` in `src/Settings/Options.php`:

```php
public function defaults(): array {
    return array(
        'title_separator'      => '-',
        'title_template'       => '%title% %sep% %sitename%',
        'description_template' => '%excerpt%',
        'home_title'           => '%sitename% %sep% %tagline%',
        'home_description'     => '',
        'og_default_image'     => '',
        'ai_model'             => '',
    );
}

public function sanitize( mixed $input ): array {
    $input = is_array( $input ) ? $input : array();
    $clean = $this->defaults();

    foreach ( array( 'title_separator', 'title_template', 'description_template', 'home_title', 'home_description', 'ai_model' ) as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $clean[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ) );
        }
    }

    if ( isset( $input['og_default_image'] ) ) {
        $clean['og_default_image'] = esc_url_raw( wp_unslash( $input['og_default_image'] ) );
    }

    return $clean;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Settings/Options.php tests/Unit/OptionsTest.php
git commit -m "feat(meta): expand Options with on-page defaults"
```

---

## Task 2: `Variables` — template placeholder replacement

**Files:**
- Create: `src/Meta/Variables.php`
- Test: `tests/Unit/Meta/VariablesTest.php`

**Interfaces:**
- Consumes: `Options` (for `%sep%`).
- Produces: `final class Variables { public function __construct( Options $options ); public function replace( string $template, int $post_id = 0 ): string; }`. Supported tokens: `%title%`, `%sitename%`, `%tagline%`, `%sep%`, `%excerpt%`, `%currentyear%`. Collapses repeated whitespace and trims.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/VariablesTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class VariablesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->justReturn( array( 'title_separator' => '-' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_replaces_site_tokens(): void {
        Functions\when( 'get_bloginfo' )->alias(
            static fn( $key ) => 'name' === $key ? 'My Site' : 'My tagline'
        );

        $variables = new Variables( new Options() );

        $this->assertSame(
            'My Site - My tagline',
            $variables->replace( '%sitename% %sep% %tagline%' )
        );
    }

    public function test_replaces_post_tokens(): void {
        Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
        Functions\when( 'get_the_title' )->justReturn( 'Hello World' );
        Functions\when( 'get_the_excerpt' )->justReturn( 'A short summary.' );
        Functions\when( 'wp_strip_all_tags' )->returnArg();

        $variables = new Variables( new Options() );

        $this->assertSame(
            'Hello World - My Site',
            $variables->replace( '%title% %sep% %sitename%', 42 )
        );
        $this->assertSame( 'A short summary.', $variables->replace( '%excerpt%', 42 ) );
    }

    public function test_strips_separators_when_tokens_are_empty(): void {
        // Mock the full set of functions Variables touches when $post_id > 0,
        // otherwise Brain Monkey fatals on the first unmocked call.
        Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
        Functions\when( 'get_the_title' )->justReturn( '' );
        Functions\when( 'get_the_excerpt' )->justReturn( '' );
        Functions\when( 'wp_strip_all_tags' )->returnArg();

        $variables = new Variables( new Options() );

        // Empty %title% leaves no double spaces or dangling separator.
        $this->assertSame( '', $variables->replace( '%title% %sep%', 7 ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter VariablesTest`
Expected: FAIL with "Class OpenSEO\Meta\Variables not found".

- [ ] **Step 3: Implement `Variables`**

Create `src/Meta/Variables.php`:

```php
<?php
/**
 * Replaces title/description template variables.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * Turns a template like "%title% %sep% %sitename%" into a finished string.
 */
final class Variables {

    /**
     * @param Options $options Settings accessor (provides the separator).
     */
    public function __construct( private readonly Options $options ) {}

    /**
     * Replace every supported token in the template.
     *
     * @param string $template Template containing %tokens%.
     * @param int    $post_id  Post context for post-specific tokens (0 = none).
     */
    public function replace( string $template, int $post_id = 0 ): string {
        $replacements = array(
            '%sitename%'    => (string) get_bloginfo( 'name' ),
            '%tagline%'     => (string) get_bloginfo( 'description' ),
            '%sep%'         => (string) $this->options->get( 'title_separator' ),
            '%currentyear%' => gmdate( 'Y' ),
            '%title%'       => $post_id > 0 ? (string) get_the_title( $post_id ) : '',
            '%excerpt%'     => $post_id > 0 ? wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) : '',
        );

        $output = strtr( $template, $replacements );

        // Collapse whitespace left by empty tokens.
        $output = trim( (string) preg_replace( '/\s+/', ' ', $output ) );

        // Strip leading/trailing separators left dangling by empty tokens.
        // Treat the separator as a whole string (it may be multi-character),
        // not as a character set the way trim()'s charlist would.
        $separator = trim( (string) $this->options->get( 'title_separator' ) );

        if ( '' !== $separator ) {
            $quoted = preg_quote( $separator, '/' );
            $output = (string) preg_replace(
                '/^(?:' . $quoted . '\s*)+|(?:\s*' . $quoted . ')+$/',
                '',
                $output
            );
        }

        return trim( $output );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter VariablesTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Meta/Variables.php tests/Unit/Meta/VariablesTest.php
git commit -m "feat(meta): add template Variables resolver"
```

---

## Task 3: `PostMeta` — register the per-entry meta keys

**Files:**
- Create: `src/Meta/PostMeta.php`
- Modify: `src/Plugin.php` (register the module — see Task 5 for full wiring; here just add `PostMeta`)
- Test: `tests/Integration/MetaRegistrationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final class PostMeta implements Hookable { public const KEYS = array(...); public function register(): void; }`. Registers these keys for every public post type that supports the editor, each `show_in_rest => true`, single, `string` (booleans stored as `'0'`/`'1'`): `_openseo_title`, `_openseo_description`, `_openseo_robots_noindex`, `_openseo_robots_nofollow`, `_openseo_canonical`, `_openseo_og_title`, `_openseo_og_description`, `_openseo_og_image`, `_openseo_twitter_title`, `_openseo_twitter_description`, `_openseo_twitter_image`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/MetaRegistrationTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use WP_UnitTestCase;

final class MetaRegistrationTest extends WP_UnitTestCase {

    public function test_seo_meta_keys_are_registered_for_posts(): void {
        $registered = get_registered_meta_keys( 'post', 'post' );

        $this->assertArrayHasKey( '_openseo_title', $registered );
        $this->assertArrayHasKey( '_openseo_description', $registered );
        $this->assertArrayHasKey( '_openseo_canonical', $registered );
        $this->assertTrue( $registered['_openseo_title']['show_in_rest'] );
    }

    public function test_meta_round_trips_through_storage(): void {
        $post_id = self::factory()->post->create();

        update_post_meta( $post_id, '_openseo_title', 'Custom title' );

        $this->assertSame( 'Custom title', get_post_meta( $post_id, '_openseo_title', true ) );
    }

    public function test_meta_round_trips_through_rest_like_the_editor(): void {
        $editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor_id );
        $post_id = self::factory()->post->create( array( 'post_author' => $editor_id ) );

        // Mirror what useEntityProp does: PUT the post with a meta payload.
        $request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
        $request->set_body_params(
            array( 'meta' => array( '_openseo_title' => 'Via REST' ) )
        );
        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Via REST', get_post_meta( $post_id, '_openseo_title', true ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run env:start` (once, if not running) then `npm run test:integration -- --filter MetaRegistrationTest`
Expected: FAIL (keys not registered).

- [ ] **Step 3: Implement `PostMeta`**

Create `src/Meta/PostMeta.php`:

```php
<?php
/**
 * Registers OpenSEO's per-entry meta keys.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Contracts\Hookable;

/**
 * Registers the SEO override meta so the block editor can read/write it over REST.
 */
final class PostMeta implements Hookable {

    /**
     * The meta keys OpenSEO stores per entry.
     *
     * @var string[]
     */
    public const KEYS = array(
        '_openseo_title',
        '_openseo_description',
        '_openseo_robots_noindex',
        '_openseo_robots_nofollow',
        '_openseo_canonical',
        '_openseo_og_title',
        '_openseo_og_description',
        '_openseo_og_image',
        '_openseo_twitter_title',
        '_openseo_twitter_description',
        '_openseo_twitter_image',
    );

    /**
     * Hook meta registration onto init (runs for admin, front, and REST requests).
     */
    public function register(): void {
        add_action( 'init', array( $this, 'register_meta' ) );
    }

    /**
     * Register every key for every editor-backed public post type.
     */
    public function register_meta(): void {
        $post_types = get_post_types(
            array(
                'public'       => true,
                'show_in_rest' => true,
            )
        );

        foreach ( $post_types as $post_type ) {
            // The block editor only round-trips meta over REST when the post
            // type supports custom-fields; show_in_rest alone is not enough.
            if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
                add_post_type_support( $post_type, 'custom-fields' );
            }

            foreach ( self::KEYS as $key ) {
                register_post_meta(
                    $post_type,
                    $key,
                    array(
                        'type'              => 'string',
                        'single'            => true,
                        'default'           => '',
                        'show_in_rest'      => true,
                        'sanitize_callback' => array( $this, 'sanitize_value' ),
                        'auth_callback'     => array( $this, 'can_edit' ),
                    )
                );
            }
        }
    }

    /**
     * Sanitize a stored meta value.
     *
     * @param mixed  $value    Raw value.
     * @param string $meta_key Meta key being saved.
     */
    public function sanitize_value( mixed $value, string $meta_key ): string {
        if ( '_openseo_canonical' === $meta_key || str_ends_with( $meta_key, '_image' ) ) {
            return esc_url_raw( (string) $value );
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * Authorize reading/writing the meta over REST.
     *
     * Loose parameter types on purpose: WordPress invokes this auth filter with
     * different value shapes depending on context, so strict scalar hints here
     * can fatal under declare(strict_types=1).
     *
     * @param mixed $allowed  WP-provided default (unused).
     * @param mixed $meta_key Meta key (unused).
     * @param mixed $post_id  Post being edited.
     */
    public function can_edit( $allowed, $meta_key, $post_id ): bool {
        return current_user_can( 'edit_post', (int) $post_id );
    }
}
```

- [ ] **Step 4: Add `PostMeta` to the module list**

In `src/Plugin.php`, add the import `use OpenSEO\Meta\PostMeta;` and add `new PostMeta(),` to the always-on `$modules` array (before `new Abilities()`).

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:integration -- --filter MetaRegistrationTest`
Expected: PASS.

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Meta/PostMeta.php src/Plugin.php tests/Integration/MetaRegistrationTest.php
git commit -m "feat(meta): register per-entry SEO meta keys"
```

---

## Task 4: `Resolver` — the resolution cascade

**Files:**
- Create: `src/Meta/Resolver.php`
- Test: `tests/Unit/Meta/ResolverTest.php`

**Interfaces:**
- Consumes: `Options`, `Variables`, and the `PostMeta::KEYS`.
- Produces: `final class Resolver { public function __construct( Options $options, Variables $variables ); public function title(): string; public function description(): string; public function robots(): string; public function canonical(): string; public function social_title(): string; public function social_description(): string; public function social_image(): string; public function twitter_title(): string; public function twitter_description(): string; public function twitter_image(): string; }`. Each returns the effective value, or `''` when OpenSEO has no opinion (caller leaves WordPress alone). `robots()` returns e.g. `"index, follow"` / `"noindex, follow"`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Meta/ResolverTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
        Functions\when( 'get_the_excerpt' )->justReturn( '' );
        Functions\when( 'wp_strip_all_tags' )->returnArg();
        Functions\when( 'is_front_page' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function resolver(): Resolver {
        $options = new Options();
        return new Resolver( $options, new Variables( $options ) );
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: FAIL with "Class OpenSEO\Meta\Resolver not found".

- [ ] **Step 3: Implement `Resolver`**

Create `src/Meta/Resolver.php`:

```php
<?php
/**
 * Resolves effective SEO values for the current request.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use OpenSEO\Settings\Options;

/**
 * Applies the cascade: per-entry override -> content-type template -> fallback.
 *
 * Returns '' whenever OpenSEO has no opinion, so callers can leave WordPress
 * defaults untouched instead of emitting empty tags.
 */
final class Resolver {

    /**
     * @param Options   $options   Settings accessor.
     * @param Variables $variables Template variable replacer.
     */
    public function __construct(
        private readonly Options $options,
        private readonly Variables $variables
    ) {}

    /**
     * Effective document title (empty = let WordPress decide).
     */
    public function title(): string {
        if ( is_singular() ) {
            $id = get_queried_object_id();

            $override = (string) get_post_meta( $id, '_openseo_title', true );
            if ( '' !== $override ) {
                return $override;
            }

            return $this->variables->replace( (string) $this->options->get( 'title_template' ), $id );
        }

        if ( is_front_page() ) {
            return $this->variables->replace( (string) $this->options->get( 'home_title' ) );
        }

        return '';
    }

    /**
     * Effective meta description (empty = print nothing).
     */
    public function description(): string {
        if ( is_singular() ) {
            $id = get_queried_object_id();

            $override = (string) get_post_meta( $id, '_openseo_description', true );
            if ( '' !== $override ) {
                return $override;
            }

            return $this->variables->replace( (string) $this->options->get( 'description_template' ), $id );
        }

        if ( is_front_page() ) {
            $home = (string) $this->options->get( 'home_description' );

            return '' !== $home ? $home : (string) get_bloginfo( 'description' );
        }

        return '';
    }

    /**
     * Effective robots directive, e.g. "index, follow".
     */
    public function robots(): string {
        $noindex  = false;
        $nofollow = false;

        if ( is_singular() ) {
            $id       = get_queried_object_id();
            $noindex  = '1' === (string) get_post_meta( $id, '_openseo_robots_noindex', true );
            $nofollow = '1' === (string) get_post_meta( $id, '_openseo_robots_nofollow', true );
        }

        return sprintf(
            '%s, %s',
            $noindex ? 'noindex' : 'index',
            $nofollow ? 'nofollow' : 'follow'
        );
    }

    /**
     * Effective canonical URL (empty = let WordPress decide).
     */
    public function canonical(): string {
        if ( ! is_singular() ) {
            return '';
        }

        $id       = get_queried_object_id();
        $override = (string) get_post_meta( $id, '_openseo_canonical', true );

        return '' !== $override ? $override : (string) get_permalink( $id );
    }

    /**
     * Open Graph title: og override -> resolved title.
     */
    public function social_title(): string {
        return $this->social_value( '_openseo_og_title', $this->title() );
    }

    /**
     * Open Graph description: og override -> resolved description.
     */
    public function social_description(): string {
        return $this->social_value( '_openseo_og_description', $this->description() );
    }

    /**
     * Social image: og override -> featured image -> global default.
     */
    public function social_image(): string {
        $override = $this->meta_value( '_openseo_og_image' );
        if ( '' !== $override ) {
            return $override;
        }

        if ( is_singular() ) {
            $featured = (string) get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
            if ( '' !== $featured ) {
                return $featured;
            }
        }

        return (string) $this->options->get( 'og_default_image' );
    }

    /**
     * Twitter title: twitter override -> social title.
     */
    public function twitter_title(): string {
        return $this->social_value( '_openseo_twitter_title', $this->social_title() );
    }

    /**
     * Twitter description: twitter override -> social description.
     */
    public function twitter_description(): string {
        return $this->social_value( '_openseo_twitter_description', $this->social_description() );
    }

    /**
     * Twitter image: twitter override -> social image.
     */
    public function twitter_image(): string {
        return $this->social_value( '_openseo_twitter_image', $this->social_image() );
    }

    /**
     * A per-entry override value, or '' when absent / not singular.
     */
    private function meta_value( string $key ): string {
        if ( ! is_singular() ) {
            return '';
        }

        return (string) get_post_meta( get_queried_object_id(), $key, true );
    }

    /**
     * Return the per-entry override for $key, else the supplied fallback.
     */
    private function social_value( string $key, string $fallback ): string {
        $override = $this->meta_value( $key );

        return '' !== $override ? $override : $fallback;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ResolverTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Meta/Resolver.php tests/Unit/Meta/ResolverTest.php
git commit -m "feat(meta): add SEO resolution cascade"
```

---

## Task 5: `HeadPrinter` + description/robots/canonical output (replace `MetaTags`)

**Files:**
- Create: `src/Frontend/Head/Presenter.php`, `src/Frontend/Head/HeadPrinter.php`, `src/Frontend/Head/Description.php`, `src/Frontend/Head/Robots.php`, `src/Frontend/Head/Canonical.php`
- Modify: `src/Plugin.php` (wire the head layer, drop `MetaTags`)
- Delete: `src/Frontend/MetaTags.php`
- Test: `tests/Integration/PluginBootTest.php` (replace old assertions)

**Interfaces:**
- Consumes: `Resolver` (Task 4).
- Produces: `interface Presenter { public function output(): void; }`; `final class HeadPrinter implements Hookable { public function __construct( array $presenters ); }` (registers `wp_head`, priority 1, loops `output()`); `Description`, `Robots`, `Canonical` each `implements Presenter` and is constructed with a `Resolver`.

- [ ] **Step 1: Write the failing integration test**

Replace the two `test_meta_description_*` methods in `tests/Integration/PluginBootTest.php` with:

```php
public function test_singular_head_outputs_description_robots_canonical(): void {
    $post_id = self::factory()->post->create(
        array(
            'post_title'   => 'Hello',
            'post_excerpt' => 'A summary for search engines.',
        )
    );
    $this->go_to( get_permalink( $post_id ) );

    ob_start();
    do_action( 'wp_head' );
    $output = (string) ob_get_clean();

    $this->assertStringContainsString( 'name="description"', $output );
    $this->assertStringContainsString( 'A summary for search engines.', $output );
    $this->assertStringContainsString( '<meta name="robots" content="index, follow"', $output );
    $this->assertStringContainsString( 'rel="canonical"', $output );
    // Exactly one canonical — the core's rel_canonical must be removed.
    $this->assertSame( 1, substr_count( $output, 'rel="canonical"' ) );
}

public function test_noindex_override_is_reflected_in_head(): void {
    $post_id = self::factory()->post->create();
    update_post_meta( $post_id, '_openseo_robots_noindex', '1' );
    $this->go_to( get_permalink( $post_id ) );

    ob_start();
    do_action( 'wp_head' );
    $output = (string) ob_get_clean();

    $this->assertStringContainsString( 'content="noindex, follow"', $output );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: FAIL (no robots/canonical output yet).

- [ ] **Step 3: Create the `Presenter` interface**

Create `src/Frontend/Head/Presenter.php`:

```php
<?php
/**
 * A unit of <head> output.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

/**
 * Renders one slice of the document head as escaped output.
 */
interface Presenter {

    /**
     * Echo the presenter's tag(s), already escaped. Print nothing when empty.
     */
    public function output(): void;
}
```

- [ ] **Step 4: Create the `HeadPrinter`**

Create `src/Frontend/Head/HeadPrinter.php`:

```php
<?php
/**
 * Orchestrates <head> presenters on wp_head.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Contracts\Hookable;

/**
 * Runs each registered presenter when WordPress prints the document head.
 */
final class HeadPrinter implements Hookable {

    /**
     * @param Presenter[] $presenters Ordered presenters to output.
     */
    public function __construct( private readonly array $presenters ) {}

    /**
     * Print OpenSEO's head tags early in wp_head.
     */
    public function register(): void {
        // OpenSEO emits its own canonical; drop the core's to avoid duplicates.
        remove_action( 'wp_head', 'rel_canonical' );
        add_action( 'wp_head', array( $this, 'print_head' ), 1 );
    }

    /**
     * Output each presenter in order.
     */
    public function print_head(): void {
        foreach ( $this->presenters as $presenter ) {
            $presenter->output();
        }
    }
}
```

- [ ] **Step 5: Create the three presenters**

Create `src/Frontend/Head/Description.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the meta description tag.
 */
final class Description implements Presenter {

    public function __construct( private readonly Resolver $resolver ) {}

    public function output(): void {
        $value = $this->resolver->description();

        if ( '' === $value ) {
            return;
        }

        printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $value ) );
    }
}
```

Create `src/Frontend/Head/Robots.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the robots meta tag.
 */
final class Robots implements Presenter {

    public function __construct( private readonly Resolver $resolver ) {}

    public function output(): void {
        printf( '<meta name="robots" content="%s" />' . "\n", esc_attr( $this->resolver->robots() ) );
    }
}
```

Create `src/Frontend/Head/Canonical.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs the canonical link tag.
 */
final class Canonical implements Presenter {

    public function __construct( private readonly Resolver $resolver ) {}

    public function output(): void {
        $value = $this->resolver->canonical();

        if ( '' === $value ) {
            return;
        }

        printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $value ) );
    }
}
```

- [ ] **Step 6: Wire the head layer and remove `MetaTags`**

Rewrite the imports and `modules()` body in `src/Plugin.php`:

```php
use OpenSEO\Admin\Assets as AdminAssets;
use OpenSEO\Admin\SettingsPage;
use OpenSEO\Ai\Abilities;
use OpenSEO\Contracts\Hookable;
use OpenSEO\Frontend\Head\Canonical;
use OpenSEO\Frontend\Head\Description;
use OpenSEO\Frontend\Head\HeadPrinter;
use OpenSEO\Frontend\Head\Robots;
use OpenSEO\Meta\PostMeta;
use OpenSEO\Meta\Resolver;
use OpenSEO\Meta\Variables;
use OpenSEO\Settings\Options;
```

```php
private function modules(): array {
    $options   = new Options();
    $variables = new Variables( $options );
    $resolver  = new Resolver( $options, $variables );

    $modules = array(
        new PostMeta(),
        new HeadPrinter(
            array(
                new Description( $resolver ),
                new Robots( $resolver ),
                new Canonical( $resolver ),
            )
        ),
        new Abilities(),
    );

    if ( is_admin() ) {
        $modules[] = new SettingsPage( $options );
        $modules[] = new AdminAssets();
    }

    return $modules;
}
```

Then delete the legacy module:

```bash
git rm src/Frontend/MetaTags.php
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: PASS.
Then `vendor/bin/phpunit` (full unit suite) — Expected: PASS (no remaining references to `MetaTags`/old options).

- [ ] **Step 8: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Frontend/Head src/Plugin.php tests/Integration/PluginBootTest.php
git commit -m "feat(head): output description, robots, canonical; replace MetaTags"
```

---

## Task 6: `Title` — filter `pre_get_document_title`

**Files:**
- Create: `src/Frontend/Head/Title.php`
- Modify: `src/Plugin.php` (register `Title`)
- Test: `tests/Integration/PluginBootTest.php` (add a title assertion)

**Interfaces:**
- Consumes: `Resolver`.
- Produces: `final class Title implements Hookable { public function __construct( Resolver $resolver ); public function filter_title( string $title ): string; }`. Registers `pre_get_document_title`; returns the resolved title when non-empty, else the unchanged `$title`.

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Integration/PluginBootTest.php`:

```php
public function test_singular_title_uses_template(): void {
    $post_id = self::factory()->post->create( array( 'post_title' => 'My Post' ) );
    $this->go_to( get_permalink( $post_id ) );

    $this->assertStringContainsString( 'My Post', wp_get_document_title() );
}

public function test_per_entry_title_override_wins(): void {
    $post_id = self::factory()->post->create( array( 'post_title' => 'My Post' ) );
    update_post_meta( $post_id, '_openseo_title', 'Overridden Title' );
    $this->go_to( get_permalink( $post_id ) );

    $this->assertSame( 'Overridden Title', wp_get_document_title() );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: FAIL (`test_per_entry_title_override_wins` — title not overridden).

- [ ] **Step 3: Implement `Title`**

Create `src/Frontend/Head/Title.php`:

```php
<?php
/**
 * Controls the document title.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Meta\Resolver;

/**
 * Short-circuits wp_title with OpenSEO's resolved title when it has one.
 */
final class Title implements Hookable {

    public function __construct( private readonly Resolver $resolver ) {}

    public function register(): void {
        add_filter( 'pre_get_document_title', array( $this, 'filter_title' ) );
    }

    /**
     * @param string $title Title WordPress would otherwise use.
     */
    public function filter_title( string $title ): string {
        $resolved = $this->resolver->title();

        return '' !== $resolved ? $resolved : $title;
    }
}
```

- [ ] **Step 4: Register `Title`**

In `src/Plugin.php`, add `use OpenSEO\Frontend\Head\Title;` and insert `new Title( $resolver ),` into the always-on `$modules` array (before `HeadPrinter` is fine).

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: PASS.

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Frontend/Head/Title.php src/Plugin.php tests/Integration/PluginBootTest.php
git commit -m "feat(head): control document title via pre_get_document_title"
```

---

## Task 7: `OpenGraph` + `Twitter` presenters

**Files:**
- Create: `src/Frontend/Head/OpenGraph.php`, `src/Frontend/Head/Twitter.php`
- Modify: `src/Plugin.php` (add both presenters to `HeadPrinter`)
- Test: `tests/Integration/PluginBootTest.php` (add social-tag assertions)

**Interfaces:**
- Consumes: `Resolver` (`social_*`, `twitter_*`).
- Produces: `final class OpenGraph implements Presenter` and `final class Twitter implements Presenter`, each constructed with a `Resolver`.

- [ ] **Step 1: Write the failing integration test**

Add to `tests/Integration/PluginBootTest.php`:

```php
public function test_singular_head_outputs_open_graph_and_twitter(): void {
    $post_id = self::factory()->post->create(
        array(
            'post_title'   => 'Social Post',
            'post_excerpt' => 'Shareable summary.',
        )
    );
    $this->go_to( get_permalink( $post_id ) );

    ob_start();
    do_action( 'wp_head' );
    $output = (string) ob_get_clean();

    $this->assertStringContainsString( 'property="og:title"', $output );
    $this->assertStringContainsString( 'property="og:type"', $output );
    $this->assertStringContainsString( 'name="twitter:card"', $output );
    $this->assertStringContainsString( 'Social Post', $output );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: FAIL (no og:/twitter: tags).

- [ ] **Step 3: Implement `OpenGraph`**

Create `src/Frontend/Head/OpenGraph.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs Open Graph tags for social sharing.
 */
final class OpenGraph implements Presenter {

    public function __construct( private readonly Resolver $resolver ) {}

    public function output(): void {
        $tags = array(
            'og:type'        => is_singular() ? 'article' : 'website',
            'og:title'       => $this->resolver->social_title(),
            'og:description' => $this->resolver->social_description(),
            'og:url'         => $this->resolver->canonical(),
            'og:image'       => $this->resolver->social_image(),
        );

        foreach ( $tags as $property => $value ) {
            if ( '' === $value ) {
                continue;
            }

            // URL-valued properties get esc_url (validates the protocol);
            // text-valued ones get esc_attr.
            $is_url = in_array( $property, array( 'og:url', 'og:image' ), true );

            printf(
                '<meta property="%s" content="%s" />' . "\n",
                esc_attr( $property ),
                $is_url ? esc_url( $value ) : esc_attr( $value )
            );
        }
    }
}
```

- [ ] **Step 4: Implement `Twitter`**

Create `src/Frontend/Head/Twitter.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Frontend\Head;

use OpenSEO\Meta\Resolver;

/**
 * Outputs Twitter Card tags.
 */
final class Twitter implements Presenter {

    public function __construct( private readonly Resolver $resolver ) {}

    public function output(): void {
        $image = $this->resolver->twitter_image();

        $tags = array(
            'twitter:card'        => '' !== $image ? 'summary_large_image' : 'summary',
            'twitter:title'       => $this->resolver->twitter_title(),
            'twitter:description' => $this->resolver->twitter_description(),
            'twitter:image'       => $image,
        );

        foreach ( $tags as $name => $value ) {
            if ( '' === $value ) {
                continue;
            }

            printf(
                '<meta name="%s" content="%s" />' . "\n",
                esc_attr( $name ),
                'twitter:image' === $name ? esc_url( $value ) : esc_attr( $value )
            );
        }
    }
}
```

- [ ] **Step 5: Add both to the `HeadPrinter`**

In `src/Plugin.php`, add the imports `use OpenSEO\Frontend\Head\OpenGraph;` and `use OpenSEO\Frontend\Head\Twitter;`, then append `new OpenGraph( $resolver ),` and `new Twitter( $resolver ),` to the presenter array passed to `HeadPrinter`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: PASS.

- [ ] **Step 7: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Frontend/Head/OpenGraph.php src/Frontend/Head/Twitter.php src/Plugin.php tests/Integration/PluginBootTest.php
git commit -m "feat(head): output Open Graph and Twitter Card tags"
```

---

## Task 8: Snippet-preview helper (JS, pure + tested)

**Files:**
- Create: `assets/src/editor/preview.js`, `assets/src/editor/preview.test.js`

**Interfaces:**
- Consumes: nothing.
- Produces: `export function buildSnippetPreview({ title, description, separator, siteName })` returning `{ title: string, description: string }` — applies the title template fallback (`title || ''` joined with `siteName` via `separator`) and truncates the description to 160 chars with an ellipsis. This is the one piece of editor logic worth unit-testing in isolation; the React panel (Task 9) imports it.

- [ ] **Step 1: Write the failing test**

Create `assets/src/editor/preview.test.js`:

```javascript
import { buildSnippetPreview } from './preview';

describe( 'buildSnippetPreview', () => {
	it( 'joins title and site name with the separator', () => {
		const result = buildSnippetPreview( {
			title: 'My Post',
			description: 'Short.',
			separator: '-',
			siteName: 'My Site',
		} );

		expect( result.title ).toBe( 'My Post - My Site' );
		expect( result.description ).toBe( 'Short.' );
	} );

	it( 'truncates long descriptions to 160 characters with an ellipsis', () => {
		const long = 'a'.repeat( 200 );

		const result = buildSnippetPreview( {
			title: '',
			description: long,
			separator: '-',
			siteName: 'Site',
		} );

		expect( result.description.length ).toBe( 161 ); // 160 chars + ellipsis
		expect( result.description.endsWith( '…' ) ).toBe( true );
	} );
} );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:js -- preview`
Expected: FAIL (Cannot find module './preview').

- [ ] **Step 3: Implement the helper**

Create `assets/src/editor/preview.js`:

```javascript
const MAX_DESCRIPTION = 160;

/**
 * @param {{ title: string, description: string, separator: string, siteName: string }} input
 * @returns {{ title: string, description: string }}
 */
export function buildSnippetPreview( { title, description, separator, siteName } ) {
	const fullTitle = title
		? `${ title } ${ separator } ${ siteName }`
		: siteName;

	const trimmed =
		description.length > MAX_DESCRIPTION
			? `${ description.slice( 0, MAX_DESCRIPTION ) }…`
			: description;

	return { title: fullTitle, description: trimmed };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:js -- preview`
Expected: PASS.

- [ ] **Step 5: Lint, commit**

```bash
npm run lint:js
git add assets/src/editor/preview.js assets/src/editor/preview.test.js
git commit -m "feat(editor): add tested snippet-preview helper"
```

---

## Task 9: Editor panel (React UI + enqueue)

**Files:**
- Create: `assets/src/editor/index.js`, `src/Admin/Editor/EditorPanel.php`
- Modify: `webpack.config.js` (add the `editor` entry), `src/Plugin.php` (register `EditorPanel`)
- Test: manual verification in `wp-env` (the build output and enqueue) — no automated test for the React tree; the testable logic lives in Task 8.

**Interfaces:**
- Consumes: `PostMeta::KEYS` (read/written via `useEntityProp` meta), `buildSnippetPreview` (Task 8).
- Produces: a registered editor plugin `openseo-editor` rendering a `PluginDocumentSettingPanel`; `final class EditorPanel implements Hookable` enqueueing `assets/build/editor.js` on `enqueue_block_editor_assets`.

- [ ] **Step 1: Add the webpack entry**

In `webpack.config.js`, extend `entry`:

```javascript
	entry: {
		'admin-settings': path.resolve(
			process.cwd(),
			'assets/src/admin/index.js'
		),
		editor: path.resolve( process.cwd(), 'assets/src/editor/index.js' ),
	},
```

- [ ] **Step 2: Write the React panel**

Create `assets/src/editor/index.js`:

```javascript
import { registerPlugin } from '@wordpress/plugins';
// WP 7.0: PluginDocumentSettingPanel lives in @wordpress/editor
// (it was removed from @wordpress/edit-post).
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import {
	TextControl,
	TextareaControl,
	ToggleControl,
	TabPanel,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function useMeta( key ) {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const value = meta?.[ key ] ?? '';
	const update = ( next ) => setMeta( { ...meta, [ key ]: next } );

	return [ value, update ];
}

function GeneralTab() {
	const [ title, setTitle ] = useMeta( '_openseo_title' );
	const [ description, setDescription ] = useMeta( '_openseo_description' );

	return (
		<>
			<TextControl
				label={ __( 'SEO title', 'openseo' ) }
				value={ title }
				onChange={ setTitle }
				help={ `${ title.length } / 60` }
			/>
			<TextareaControl
				label={ __( 'Meta description', 'openseo' ) }
				value={ description }
				onChange={ setDescription }
				help={ `${ description.length } / 160` }
			/>
		</>
	);
}

function SocialTab() {
	const [ ogTitle, setOgTitle ] = useMeta( '_openseo_og_title' );
	const [ ogDescription, setOgDescription ] = useMeta(
		'_openseo_og_description'
	);
	const [ ogImage, setOgImage ] = useMeta( '_openseo_og_image' );

	return (
		<>
			<TextControl
				label={ __( 'Social title', 'openseo' ) }
				value={ ogTitle }
				onChange={ setOgTitle }
			/>
			<TextareaControl
				label={ __( 'Social description', 'openseo' ) }
				value={ ogDescription }
				onChange={ setOgDescription }
			/>
			<TextControl
				label={ __( 'Social image URL', 'openseo' ) }
				value={ ogImage }
				onChange={ setOgImage }
			/>
		</>
	);
}

function AdvancedTab() {
	const [ noindex, setNoindex ] = useMeta( '_openseo_robots_noindex' );
	const [ nofollow, setNofollow ] = useMeta( '_openseo_robots_nofollow' );
	const [ canonical, setCanonical ] = useMeta( '_openseo_canonical' );

	return (
		<>
			<ToggleControl
				label={ __( 'No index', 'openseo' ) }
				checked={ noindex === '1' }
				onChange={ ( on ) => setNoindex( on ? '1' : '' ) }
			/>
			<ToggleControl
				label={ __( 'No follow', 'openseo' ) }
				checked={ nofollow === '1' }
				onChange={ ( on ) => setNofollow( on ? '1' : '' ) }
			/>
			<TextControl
				label={ __( 'Canonical URL', 'openseo' ) }
				value={ canonical }
				onChange={ setCanonical }
			/>
		</>
	);
}

const TABS = [
	{ name: 'general', title: __( 'General', 'openseo' ) },
	{ name: 'social', title: __( 'Social', 'openseo' ) },
	{ name: 'advanced', title: __( 'Advanced', 'openseo' ) },
];

function OpenSeoPanel() {
	return (
		<PluginDocumentSettingPanel
			name="openseo-panel"
			title={ __( 'OpenSEO', 'openseo' ) }
		>
			<TabPanel tabs={ TABS }>
				{ ( tab ) => {
					if ( tab.name === 'social' ) {
						return <SocialTab />;
					}
					if ( tab.name === 'advanced' ) {
						return <AdvancedTab />;
					}
					return <GeneralTab />;
				} }
			</TabPanel>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'openseo-editor', { render: OpenSeoPanel } );
```

- [ ] **Step 3: Build the assets**

Run: `npm run build`
Expected: `assets/build/editor.js` and `assets/build/editor.asset.php` are generated.

- [ ] **Step 4: Implement the enqueue module**

Create `src/Admin/Editor/EditorPanel.php`:

```php
<?php
/**
 * Loads the OpenSEO block-editor panel.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin\Editor;

use OpenSEO\Contracts\Hookable;

/**
 * Enqueues the compiled editor bundle so the SEO document panel appears.
 */
final class EditorPanel implements Hookable {

    private const HANDLE = 'openseo-editor';

    public function register(): void {
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
    }

    public function enqueue(): void {
        $asset_path = OPENSEO_PLUGIN_DIR . 'assets/build/editor.asset.php';

        if ( ! is_readable( $asset_path ) ) {
            return;
        }

        $asset = require $asset_path;

        wp_enqueue_script(
            self::HANDLE,
            OPENSEO_PLUGIN_URL . 'assets/build/editor.js',
            $asset['dependencies'] ?? array(),
            $asset['version'] ?? OPENSEO_VERSION,
            true
        );

        wp_set_script_translations( self::HANDLE, 'openseo' );
    }
}
```

- [ ] **Step 5: Register `EditorPanel`**

In `src/Plugin.php`, add `use OpenSEO\Admin\Editor\EditorPanel;` and, inside the `is_admin()` block, add `$modules[] = new EditorPanel();`.

- [ ] **Step 6: Manually verify in wp-env**

```bash
npm run env:start
```
Open `http://localhost:8888/wp-admin/post-new.php?post_type=post` (admin/password). Confirm the **OpenSEO** panel appears in the document sidebar with General/Social/Advanced tabs, type a title/description, publish, then check the front end source for the matching `<title>`, `<meta name="description">`, and `og:` tags.

- [ ] **Step 7: Lint, analyze, commit**

```bash
composer lint && composer analyze && npm run lint:js
git add webpack.config.js assets/src/editor/index.js src/Admin/Editor/EditorPanel.php src/Plugin.php
git commit -m "feat(editor): add Gutenberg SEO document panel"
```

> Note: `assets/build/` is git-ignored (built during release), so it is not committed here — that matches the existing `admin-settings` setup.

---

## Task 10: Settings page — tabs and on-page defaults

**Files:**
- Modify: `src/Admin/SettingsPage.php`, `templates/admin/settings-page.php`
- Test: `tests/Integration/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Options` defaults (Task 1).
- Produces: a tabbed settings screen (`?tab=general|titles|social`) registering fields for `title_separator`, `title_template`, `description_template`, `home_title`, `home_description`, `og_default_image`. The single registered setting stays `Options::OPTION_KEY` with `Options::sanitize` (so all tabs save through one form).

- [ ] **Step 1: Write the failing integration test**

Create `tests/Integration/SettingsPageTest.php`:

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Admin\SettingsPage;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SettingsPageTest extends WP_UnitTestCase {

    public function test_setting_is_registered_with_sanitizer(): void {
        $page = new SettingsPage( new Options() );
        $page->register();
        do_action( 'admin_init' );

        $registered = get_registered_settings();

        $this->assertArrayHasKey( Options::OPTION_KEY, $registered );
    }

    public function test_titles_fields_are_registered(): void {
        global $wp_settings_fields;

        $page = new SettingsPage( new Options() );
        $page->register();
        do_action( 'admin_init' );

        $this->assertArrayHasKey( 'openseo', $wp_settings_fields );
        $section_fields = $wp_settings_fields['openseo']['openseo_titles'] ?? array();
        $this->assertArrayHasKey( 'title_template', $section_fields );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter SettingsPageTest`
Expected: FAIL (`openseo_titles` section / `title_template` field do not exist).

- [ ] **Step 3: Rewrite `SettingsPage`**

Replace `src/Admin/SettingsPage.php` with the tabbed version:

```php
<?php
/**
 * OpenSEO settings screen.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Admin;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Registers the tabbed settings page, its fields, and the single option.
 */
final class SettingsPage implements Hookable {

    private const MENU_SLUG = 'openseo';

    /**
     * @param Options $options Settings accessor.
     */
    public function __construct( private readonly Options $options ) {}

    public function register(): void {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_menu(): void {
        add_options_page(
            __( 'OpenSEO', 'openseo' ),
            __( 'OpenSEO', 'openseo' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function register_settings(): void {
        register_setting(
            Options::OPTION_GROUP,
            Options::OPTION_KEY,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this->options, 'sanitize' ),
                'default'           => $this->options->defaults(),
            )
        );

        add_settings_section( 'openseo_general', __( 'General', 'openseo' ), '__return_false', self::MENU_SLUG );
        add_settings_section( 'openseo_titles', __( 'Titles & Meta', 'openseo' ), '__return_false', self::MENU_SLUG );
        add_settings_section( 'openseo_social', __( 'Social', 'openseo' ), '__return_false', self::MENU_SLUG );

        $this->add_text_field( 'title_separator', __( 'Title separator', 'openseo' ), 'openseo_titles' );
        $this->add_text_field( 'title_template', __( 'Default title template', 'openseo' ), 'openseo_titles' );
        $this->add_text_field( 'description_template', __( 'Default description template', 'openseo' ), 'openseo_titles' );
        $this->add_text_field( 'home_title', __( 'Homepage title', 'openseo' ), 'openseo_titles' );
        $this->add_text_field( 'home_description', __( 'Homepage description', 'openseo' ), 'openseo_titles' );
        $this->add_text_field( 'og_default_image', __( 'Default social image URL', 'openseo' ), 'openseo_social' );
    }

    /**
     * Register one text field bound to a single option key.
     */
    private function add_text_field( string $key, string $label, string $section ): void {
        add_settings_field(
            $key,
            $label,
            function () use ( $key ): void {
                printf(
                    '<input type="text" id="openseo_%1$s" name="%2$s[%1$s]" value="%3$s" class="regular-text" />',
                    esc_attr( $key ),
                    esc_attr( Options::OPTION_KEY ),
                    esc_attr( (string) $this->options->get( $key ) )
                );
            },
            self::MENU_SLUG,
            $section,
            array( 'label_for' => 'openseo_' . $key )
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        require OPENSEO_PLUGIN_DIR . 'templates/admin/settings-page.php';
    }
}
```

- [ ] **Step 4: Update the template with tab navigation**

Replace `templates/admin/settings-page.php`:

```php
<?php
/**
 * Settings page template.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$openseo_tabs = array(
    'general' => __( 'General', 'openseo' ),
    'titles'  => __( 'Titles & Meta', 'openseo' ),
    'social'  => __( 'Social', 'openseo' ),
);

// Read-only tab selector; the form posts to options.php and saves all sections.
$openseo_active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
if ( ! isset( $openseo_tabs[ $openseo_active ] ) ) {
    $openseo_active = 'general';
}
?>
<div class="wrap openseo-settings">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <nav class="nav-tab-wrapper">
        <?php foreach ( $openseo_tabs as $openseo_slug => $openseo_label ) : ?>
            <a
                href="<?php echo esc_url( add_query_arg( array( 'page' => 'openseo', 'tab' => $openseo_slug ), admin_url( 'options-general.php' ) ) ); ?>"
                class="nav-tab <?php echo $openseo_active === $openseo_slug ? 'nav-tab-active' : ''; ?>"
            >
                <?php echo esc_html( $openseo_label ); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form action="options.php" method="post">
        <?php
        settings_fields( \OpenSEO\Settings\Options::OPTION_GROUP );
        do_settings_sections( 'openseo' );
        submit_button();
        ?>
    </form>
</div>
```

> The form renders all sections (the Settings API saves them together under one key). The tab nav groups them visually; refining per-tab section display is a follow-up — keep it simple now.

- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test:integration -- --filter SettingsPageTest`
Expected: PASS.

- [ ] **Step 6: Lint, analyze, commit**

```bash
composer lint && composer analyze
git add src/Admin/SettingsPage.php templates/admin/settings-page.php tests/Integration/SettingsPageTest.php
git commit -m "feat(admin): tabbed settings with on-page defaults"
```

---

## Final verification

- [ ] **Run the full PHP suite + gates**

```bash
composer check          # lint + analyze + unit
npm run test:integration
npm run test:js
npm run build
```
Expected: all green; `assets/build/editor.js` builds without errors.

- [ ] **Smoke-test the whole flow in wp-env**

Create a post, fill the OpenSEO panel (title, description, social image, noindex toggle), publish, and confirm the front-end `<head>` reflects every field; toggle noindex and confirm `content="noindex, follow"`.

- [ ] **Sync the docs that referenced `MetaTags`**

`README.md` (architecture tree) and `CLAUDE.md` (key modules) still describe `Frontend/MetaTags.php`. Update both to reference the new `Frontend\Head\*` layer and `Meta\*`, then commit:

```bash
git add README.md CLAUDE.md
git commit -m "docs: replace MetaTags with Frontend\\Head + Meta layers"
```

---

## Self-Review

**1. Spec coverage**

| Spec item (design §) | Task |
|---|---|
| `Meta\PostMeta` (register_post_meta, show_in_rest, sanitize, auth) | 3 |
| `Meta\Resolver` (cascade) | 4 |
| `Meta\Variables` (token replacement) | 2 |
| `Frontend\Head\HeadPrinter` + presenters (Title/Description/Robots/Canonical/OpenGraph/Twitter) | 5, 6, 7 |
| `Admin\Editor` panel (registerPlugin + PluginDocumentSettingPanel, useEntityProp) | 8, 9 |
| `Settings\Options` expanded defaults + tabbed SettingsPage | 1, 10 |
| Replace `Frontend\MetaTags` | 5 |
| Title via `pre_get_document_title` (no duplicate tag) | 6 |
| Cascade: override → template → fallback | 4 |
| IA (Phase 2) | Out of scope — not in this plan, by design |

No gaps for Phase 1.

**2. Placeholder scan:** No "TBD"/"add validation"/"similar to Task N". Every code step shows complete code. The one "follow-up" note (per-tab section display in Task 10) ships a working simple version now, not a placeholder.

**3. Type consistency:** `Resolver` method names (`title`, `description`, `robots`, `canonical`, `social_title`, `social_description`, `social_image`, `twitter_title`, `twitter_description`, `twitter_image`) are defined in Task 4 and consumed verbatim in Tasks 5–7. `Presenter::output()` is defined in Task 5 and implemented by Description/Robots/Canonical (5), OpenGraph/Twitter (7). `PostMeta::KEYS` (Task 3) match the meta keys read in `Resolver` (Task 4) and the JS panel (Task 9). `Variables::replace( string, int )` (Task 2) is called with `(template, $id)` in Task 4.
