# OpenSEO Phase 5 — Redirects + 404 Monitor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a redirect engine + admin manager + auto-redirect-on-slug-change, plus an opt-in aggregated 404 monitor, to OpenSEO — the plugin's first custom DB tables.

**Architecture:** Pure, WordPress-free logic (`Normalizer`, `Regex`, `Ruleset`, `Matcher`, the `SlugWatcher` decision) is isolated for Brain Monkey unit tests; a Repository encapsulates all `$wpdb` access; a `Cache` serves a counter-free ruleset from object cache + transient; `Hookable` modules (`Dispatcher`, `SlugWatcher`, `Monitor`, `Pruner`, admin pages) wire WordPress. Two custom tables are created with `dbDelta()` behind a version gate.

**Tech Stack:** PHP 8.1+, WordPress 7.0+, `$wpdb`, `dbDelta()`, `WP_List_Table`, WP-Cron, PHPUnit 9.6 + Brain Monkey (unit), wp-env (integration).

## Global Constraints

- WordPress 7.0+, PHP 8.1+; `declare( strict_types=1 );` and `final` classes in every new file.
- PSR-4 `OpenSEO\` → `src/`; tests `OpenSEO\Tests\` → `tests/`.
- Prefixes `openseo` / `OpenSEO` / `OPENSEO`; text domain `openseo` (all user-facing strings translated).
- Security: sanitize on input, escape on output; nonce **+** `current_user_can('manage_options')` on every state change; read explicit `$_POST`/`$_GET` keys with `wp_unslash`; **all SQL via `$wpdb->prepare`**; table names from `$wpdb->prefix` only.
- Quality gates green before each commit: `composer lint` (PHPCS WPCS), `composer analyze` (PHPStan level 6, `--memory-limit=1G`), `composer test:unit` (Brain Monkey).
- All settings live under the single option key `openseo_settings` (`OpenSEO\Settings\Options`).
- Hook priorities are load-bearing: `Dispatcher` on `template_redirect` priority **5** (before core `redirect_canonical`@10); `Monitor` on `template_redirect` priority **99**.
- Multisite is out of scope (tables use `$wpdb->prefix`, correct for single-site).
- Status-code whitelist: `301, 302, 307, 410`. Default redirect status select offers `301, 302, 307` (410 is per-rule, never a default).

---

## File Structure

**New source files**
- `src/Lifecycle/Schema.php` — table SQL, `dbDelta()`, schema version.
- `src/Redirects/Redirect.php` — immutable matching DTO (no volatile counters).
- `src/Redirects/MatchResult.php` — immutable `{id, target, status}`.
- `src/Redirects/Normalizer.php` — pure request-path normalizer.
- `src/Redirects/Regex.php` — pure regex compile/validate/match/substitute (plugin-controlled delimiter).
- `src/Redirects/Ruleset.php` — pure exact-map + ordered regex list.
- `src/Redirects/Matcher.php` — pure matcher.
- `src/Redirects/Repository.php` — all `$wpdb` access for `openseo_redirects`.
- `src/Redirects/Cache.php` — ruleset cache (object cache + transient) + invalidation.
- `src/Redirects/Dispatcher.php` — Hookable, `template_redirect@5`.
- `src/Redirects/SlugWatcher.php` — Hookable, `pre_post_update` + `post_updated`.
- `src/Redirects/Admin/RedirectsListTable.php` — `WP_List_Table` for redirects.
- `src/Redirects/Admin/RedirectsPage.php` — Hookable admin: Tools page, sub-tabs, POST handling.
- `src/NotFound/LogRepository.php` — all `$wpdb` access for `openseo_404_logs` (aggregated upsert).
- `src/NotFound/Monitor.php` — Hookable, `template_redirect@99`.
- `src/NotFound/Pruner.php` — Hookable, daily cron retention.
- `src/NotFound/Admin/NotFoundListTable.php` — `WP_List_Table` for 404 log.

**Modified source files**
- `src/Settings/Options.php` — new defaults + per-key sanitize.
- `src/Admin/SettingsPage.php` — "Redirects" settings tab fields.
- `templates/admin/settings-page.php` — add `redirects` tab.
- `src/Lifecycle/Activator.php` — call `Schema::install()`.
- `src/Lifecycle/Deactivator.php` — clear `openseo_404_prune` cron.
- `src/Lifecycle/Uninstaller.php` — `DROP TABLE` both + delete `openseo_db_version`.
- `src/Plugin.php` — register new modules; version-gated `Schema::install()` on `admin_init`.

**New test files**
- Unit: `tests/Unit/Redirects/{NormalizerTest,RegexTest,RulesetTest,MatcherTest,RedirectTest,SlugWatcherDecisionTest}.php`
- Integration: `tests/Integration/{DbSchemaTest,RedirectsRepositoryTest,DispatcherTest,SlugWatcherTest,NotFoundTest}.php`

---

# Part A — Redirects

## Task 1: Schema + lifecycle (custom tables)

**Files:**
- Create: `src/Lifecycle/Schema.php`
- Modify: `src/Lifecycle/Activator.php`
- Modify: `src/Lifecycle/Deactivator.php`
- Modify: `src/Lifecycle/Uninstaller.php`
- Test: `tests/Integration/DbSchemaTest.php`

**Interfaces:**
- Produces: `Schema::VERSION` (string), `Schema::install(): void`, `Schema::current_version(): string`, `Schema::redirects_table(): string`, `Schema::logs_table(): string`.

- [ ] **Step 1: Write the Schema class**

```php
<?php
/**
 * Custom table schema and migrations.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

/**
 * Creates and upgrades OpenSEO's custom tables via dbDelta().
 */
final class Schema {

	/**
	 * Schema version. Bump when a table definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Option key holding the installed schema version.
	 */
	public const VERSION_OPTION = 'openseo_db_version';

	/**
	 * Fully-qualified redirects table name.
	 */
	public static function redirects_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'openseo_redirects';
	}

	/**
	 * Fully-qualified 404 logs table name.
	 */
	public static function logs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'openseo_404_logs';
	}

	/**
	 * Installed schema version, or '' when never installed.
	 */
	public static function current_version(): string {
		return (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Create or upgrade the tables. Idempotent (dbDelta diff).
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$redirects       = self::redirects_table();
		$logs            = self::logs_table();

		// dbDelta is whitespace- and format-sensitive: two spaces after
		// PRIMARY KEY, every index named, lowercase types, one field per line.
		$redirects_sql = "CREATE TABLE {$redirects} (
  id bigint(20) unsigned NOT NULL auto_increment,
  source_path varchar(255) NOT NULL default '',
  target varchar(2048) NOT NULL default '',
  status_code smallint(5) unsigned NOT NULL default 301,
  is_regex tinyint(1) NOT NULL default 0,
  enabled tinyint(1) NOT NULL default 1,
  hits bigint(20) unsigned NOT NULL default 0,
  last_accessed datetime default NULL,
  created_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY source_path (source_path(191)),
  KEY is_regex (is_regex)
) {$charset_collate};";

		$logs_sql = "CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL auto_increment,
  url text NOT NULL,
  url_hash char(32) NOT NULL default '',
  hits bigint(20) unsigned NOT NULL default 0,
  first_seen datetime NOT NULL default '0000-00-00 00:00:00',
  last_seen datetime NOT NULL default '0000-00-00 00:00:00',
  referrer varchar(255) default NULL,
  user_agent varchar(255) default NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash)
) {$charset_collate};";

		dbDelta( $redirects_sql );
		dbDelta( $logs_sql );

		update_option( self::VERSION_OPTION, self::VERSION );
	}
}
```

- [ ] **Step 2: Call Schema::install() from the Activator**

Modify `src/Lifecycle/Activator.php` — add the `use` and the install call:

```php
use OpenSEO\Lifecycle\Schema;
```

Inside `activate()`, after the option seeding block and before `update_option( 'openseo_version', ... )`:

```php
		Schema::install();
```

- [ ] **Step 3: Clear the prune cron on deactivation**

`src/Lifecycle/Deactivator.php` already clears `openseo_daily_scan`; add the new event in `deactivate()`:

```php
		wp_clear_scheduled_hook( 'openseo_404_prune' );
```

- [ ] **Step 4: Drop tables on uninstall**

Modify `src/Lifecycle/Uninstaller.php` `uninstall()`:

```php
		global $wpdb;

		// Table names are built from $wpdb->prefix (not user input); interpolation is safe.
		$redirects = $wpdb->prefix . 'openseo_redirects';
		$logs      = $wpdb->prefix . 'openseo_404_logs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$redirects}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$logs}" );
		// phpcs:enable

		delete_option( 'openseo_db_version' );
```

(Keep the existing `delete_option` calls for `openseo_settings` and `openseo_version`.)

- [ ] **Step 5: Write the integration test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use WP_UnitTestCase;

final class DbSchemaTest extends WP_UnitTestCase {

	public function test_install_creates_both_tables(): void {
		global $wpdb;

		Schema::install();

		$redirects = Schema::redirects_table();
		$logs      = Schema::logs_table();

		$this->assertSame( $redirects, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $redirects ) ) );
		$this->assertSame( $logs, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) ) );
		$this->assertSame( Schema::VERSION, Schema::current_version() );
	}

	public function test_install_is_idempotent(): void {
		Schema::install();
		Schema::install(); // Second run must not error.

		$this->assertSame( Schema::VERSION, Schema::current_version() );
	}
}
```

- [ ] **Step 6: Run integration test**

Run: `npm run env:start && npm run test:integration -- --filter DbSchemaTest`
Expected: PASS (both tables exist; idempotent).

- [ ] **Step 7: Verify gates and commit**

Run: `composer lint && composer analyze`
Expected: no errors.

```bash
git add src/Lifecycle/Schema.php src/Lifecycle/Activator.php src/Lifecycle/Deactivator.php src/Lifecycle/Uninstaller.php tests/Integration/DbSchemaTest.php
git commit -m "feat(redirects): add custom table schema + lifecycle"
```

---

## Task 2: Redirect + MatchResult DTOs

**Files:**
- Create: `src/Redirects/Redirect.php`
- Create: `src/Redirects/MatchResult.php`
- Test: `tests/Unit/Redirects/RedirectTest.php`

**Interfaces:**
- Produces: `new Redirect(int $id, string $source, string $target, int $status, bool $is_regex, bool $enabled)`; `new MatchResult(int $id, string $target, int $status)`. Both `readonly` public props.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\MatchResult;
use OpenSEO\Redirects\Redirect;
use PHPUnit\Framework\TestCase;

final class RedirectTest extends TestCase {

	public function test_redirect_exposes_readonly_fields(): void {
		$redirect = new Redirect( 7, '/old', '/new', 301, false, true );

		$this->assertSame( 7, $redirect->id );
		$this->assertSame( '/old', $redirect->source );
		$this->assertSame( '/new', $redirect->target );
		$this->assertSame( 301, $redirect->status );
		$this->assertFalse( $redirect->is_regex );
		$this->assertTrue( $redirect->enabled );
	}

	public function test_match_result_exposes_readonly_fields(): void {
		$result = new MatchResult( 7, '/new', 301 );

		$this->assertSame( 7, $result->id );
		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RedirectTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the DTOs**

`src/Redirects/Redirect.php`:

```php
<?php
/**
 * Immutable redirect rule (matching-relevant fields only).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Value object for one redirect rule. Volatile counters (hits, last_accessed)
 * are intentionally excluded so the cached Ruleset never needs invalidating
 * when a hit is recorded.
 */
final class Redirect {

	public function __construct(
		public readonly int $id,
		public readonly string $source,
		public readonly string $target,
		public readonly int $status,
		public readonly bool $is_regex,
		public readonly bool $enabled,
	) {}
}
```

`src/Redirects/MatchResult.php`:

```php
<?php
/**
 * Result of matching a request path against the ruleset.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Immutable outcome of a successful match.
 */
final class MatchResult {

	public function __construct(
		public readonly int $id,
		public readonly string $target,
		public readonly int $status,
	) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RedirectTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Redirects/Redirect.php src/Redirects/MatchResult.php tests/Unit/Redirects/RedirectTest.php
git commit -m "feat(redirects): add Redirect and MatchResult DTOs"
```

---

## Task 3: Normalizer (pure)

**Files:**
- Create: `src/Redirects/Normalizer.php`
- Test: `tests/Unit/Redirects/NormalizerTest.php`

**Interfaces:**
- Produces: `new Normalizer(string $home_path = '')`; `Normalizer::normalize(string $request_uri): string` — returns a path with a leading slash, query string stripped, trailing slash removed (except root `/`), home subdirectory removed.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase {

	public function test_strips_query_string_and_trailing_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/blog/post', $normalizer->normalize( '/blog/post/?utm=x' ) );
	}

	public function test_keeps_root_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/', $normalizer->normalize( '/?ref=1' ) );
	}

	public function test_decodes_and_adds_leading_slash(): void {
		$normalizer = new Normalizer();

		$this->assertSame( '/a b', $normalizer->normalize( 'a%20b' ) );
	}

	public function test_removes_home_subdirectory(): void {
		$normalizer = new Normalizer( '/wp' );

		$this->assertSame( '/about', $normalizer->normalize( '/wp/about/' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter NormalizerTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the Normalizer**

```php
<?php
/**
 * Normalizes a request URI into a comparable path.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure path normalizer (no WordPress). The home subdirectory is injected so the
 * class stays testable in isolation.
 */
final class Normalizer {

	/**
	 * @param string $home_path Path component of home_url() (e.g. '/wp'), or ''.
	 */
	public function __construct( private readonly string $home_path = '' ) {}

	/**
	 * Normalize a raw request URI to a comparable path.
	 *
	 * @param string $request_uri Raw REQUEST_URI.
	 */
	public function normalize( string $request_uri ): string {
		// Drop the query string and fragment.
		$path = (string) strtok( $request_uri, '?' );
		$path = (string) strtok( $path, '#' );
		$path = rawurldecode( $path );

		// Remove the home subdirectory prefix on subdir installs.
		if ( '' !== $this->home_path && str_starts_with( $path, $this->home_path ) ) {
			$path = substr( $path, strlen( $this->home_path ) );
		}

		// Exactly one leading slash; no trailing slash except for root.
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $path;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter NormalizerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Redirects/Normalizer.php tests/Unit/Redirects/NormalizerTest.php
git commit -m "feat(redirects): add pure request-path Normalizer"
```

---

## Task 4: Regex helper (pure)

**Files:**
- Create: `src/Redirects/Regex.php`
- Test: `tests/Unit/Redirects/RegexTest.php`

**Interfaces:**
- Produces: `Regex::is_valid(string $pattern): bool`; `Regex::match(string $pattern, string $path): ?array` (capture groups or null); `Regex::substitute(string $target, array $matches): string`; `Regex::MAX_LENGTH` (int). Plugin owns delimiter (`#`) and flags (`u`); the user supplies only the bare pattern.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Regex;
use PHPUnit\Framework\TestCase;

final class RegexTest extends TestCase {

	public function test_valid_pattern_passes(): void {
		$this->assertTrue( Regex::is_valid( '^/blog/(\d+)$' ) );
	}

	public function test_invalid_pattern_fails(): void {
		$this->assertFalse( Regex::is_valid( '^/blog/(\d+$' ) ); // Unbalanced paren.
	}

	public function test_overlong_pattern_is_invalid(): void {
		$this->assertFalse( Regex::is_valid( str_repeat( 'a', Regex::MAX_LENGTH + 1 ) ) );
	}

	public function test_match_returns_capture_groups(): void {
		$this->assertSame(
			array( '/blog/42', '42' ),
			Regex::match( '^/blog/(\d+)$', '/blog/42' )
		);
	}

	public function test_match_returns_null_on_no_match(): void {
		$this->assertNull( Regex::match( '^/blog/(\d+)$', '/about' ) );
	}

	public function test_substitute_replaces_numbered_groups(): void {
		$matches = array( '/blog/42', '42' );

		$this->assertSame( '/news/42', Regex::substitute( '/news/$1', $matches ) );
		$this->assertSame( '/news/42', Regex::substitute( '/news/${1}', $matches ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RegexTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the Regex helper**

```php
<?php
/**
 * Safe, plugin-controlled regex matching for redirect rules.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure regex utilities. The plugin owns the delimiter and flags; the user only
 * ever supplies the bare pattern, which bounds the ReDoS / injection surface.
 */
final class Regex {

	/**
	 * Delimiter the plugin wraps around every user pattern.
	 */
	private const DELIMITER = '#';

	/**
	 * Maximum accepted pattern length.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * Wrap a bare user pattern with the controlled delimiter and flags.
	 */
	private static function compile( string $pattern ): string {
		$escaped = str_replace( self::DELIMITER, '\\' . self::DELIMITER, $pattern );

		return self::DELIMITER . $escaped . self::DELIMITER . 'u';
	}

	/**
	 * Whether a bare pattern is within length and compiles cleanly.
	 */
	public static function is_valid( string $pattern ): bool {
		if ( '' === $pattern || strlen( $pattern ) > self::MAX_LENGTH ) {
			return false;
		}

		return false !== @preg_match( self::compile( $pattern ), '' );
	}

	/**
	 * Match a path; return capture groups (index 0 = full match) or null.
	 *
	 * @return array<int, string>|null
	 */
	public static function match( string $pattern, string $path ): ?array {
		$matches = array();
		$result  = @preg_match( self::compile( $pattern ), $path, $matches );

		return 1 === $result ? $matches : null;
	}

	/**
	 * Replace $1 / ${1} backreferences in a target with capture groups.
	 *
	 * @param array<int, string> $matches Capture groups from match().
	 */
	public static function substitute( string $target, array $matches ): string {
		return (string) preg_replace_callback(
			'/\$\{?(\d+)\}?/',
			static fn ( array $m ): string => $matches[ (int) $m[1] ] ?? '',
			$target
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RegexTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Redirects/Regex.php tests/Unit/Redirects/RegexTest.php
git commit -m "feat(redirects): add pure plugin-controlled Regex helper"
```

---

## Task 5: Ruleset (pure)

**Files:**
- Create: `src/Redirects/Ruleset.php`
- Test: `tests/Unit/Redirects/RulesetTest.php`

**Interfaces:**
- Produces: `Ruleset::add(Redirect $r): void` (ignores disabled); `Ruleset::exact(string $path): ?Redirect`; `Ruleset::regex_rules(): Redirect[]`; `Ruleset::count(): int`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\Ruleset;
use PHPUnit\Framework\TestCase;

final class RulesetTest extends TestCase {

	public function test_indexes_exact_and_regex_rules(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, '/old', '/new', 301, false, true ) );
		$ruleset->add( new Redirect( 2, '^/p/(\d+)$', '/post/$1', 301, true, true ) );

		$this->assertSame( 1, $ruleset->exact( '/old' )->id );
		$this->assertNull( $ruleset->exact( '/missing' ) );
		$this->assertCount( 1, $ruleset->regex_rules() );
		$this->assertSame( 2, $ruleset->count() );
	}

	public function test_ignores_disabled_rules(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, '/old', '/new', 301, false, false ) );

		$this->assertNull( $ruleset->exact( '/old' ) );
		$this->assertSame( 0, $ruleset->count() );
	}

	public function test_preserves_regex_insertion_order(): void {
		$ruleset = new Ruleset();
		$ruleset->add( new Redirect( 1, 'a', '/a', 301, true, true ) );
		$ruleset->add( new Redirect( 2, 'b', '/b', 301, true, true ) );

		$rules = $ruleset->regex_rules();
		$this->assertSame( 1, $rules[0]->id );
		$this->assertSame( 2, $rules[1]->id );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RulesetTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the Ruleset**

```php
<?php
/**
 * In-memory set of active redirect rules.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure container: O(1) exact-source map plus an ordered regex list. Disabled
 * rules are never added, so the Matcher never has to re-check enablement.
 */
final class Ruleset {

	/**
	 * @var array<string, Redirect> source path => rule
	 */
	private array $exact = array();

	/**
	 * @var Redirect[] ordered regex rules
	 */
	private array $regex = array();

	/**
	 * Add a rule (no-op if disabled).
	 */
	public function add( Redirect $rule ): void {
		if ( ! $rule->enabled ) {
			return;
		}

		if ( $rule->is_regex ) {
			$this->regex[] = $rule;

			return;
		}

		$this->exact[ $rule->source ] = $rule;
	}

	/**
	 * Exact rule for a path, or null.
	 */
	public function exact( string $path ): ?Redirect {
		return $this->exact[ $path ] ?? null;
	}

	/**
	 * Ordered regex rules.
	 *
	 * @return Redirect[]
	 */
	public function regex_rules(): array {
		return $this->regex;
	}

	/**
	 * Total active rule count.
	 */
	public function count(): int {
		return count( $this->exact ) + count( $this->regex );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RulesetTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Redirects/Ruleset.php tests/Unit/Redirects/RulesetTest.php
git commit -m "feat(redirects): add pure Ruleset container"
```

---

## Task 6: Matcher (pure)

**Files:**
- Create: `src/Redirects/Matcher.php`
- Test: `tests/Unit/Redirects/MatcherTest.php`

**Interfaces:**
- Consumes: `Ruleset`, `Redirect`, `MatchResult`, `Regex`.
- Produces: `Matcher::match(Ruleset $ruleset, string $path): ?MatchResult` — exact wins over regex; regex targets get `$1` substitution; returns null on no match or self-loop (target === path).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Redirect;
use OpenSEO\Redirects\Ruleset;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase {

	private function ruleset( Redirect ...$rules ): Ruleset {
		$ruleset = new Ruleset();
		foreach ( $rules as $rule ) {
			$ruleset->add( $rule );
		}

		return $ruleset;
	}

	public function test_exact_match_wins(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset(
			new Redirect( 1, '/old', '/new', 301, false, true ),
			new Redirect( 2, '^/old$', '/regex', 302, true, true ),
		);

		$result = $matcher->match( $ruleset, '/old' );

		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
		$this->assertSame( 1, $result->id );
	}

	public function test_regex_match_substitutes_groups(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 5, '^/p/(\d+)$', '/post/$1', 301, true, true ) );

		$result = $matcher->match( $ruleset, '/p/42' );

		$this->assertSame( '/post/42', $result->target );
		$this->assertSame( 5, $result->id );
	}

	public function test_returns_null_when_no_match(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '/old', '/new', 301, false, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/nope' ) );
	}

	public function test_returns_null_on_self_loop(): void {
		$matcher = new Matcher();
		$ruleset = $this->ruleset( new Redirect( 1, '/loop', '/loop', 301, false, true ) );

		$this->assertNull( $matcher->match( $ruleset, '/loop' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MatcherTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the Matcher**

```php
<?php
/**
 * Matches a normalized path against a ruleset.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Pure matcher: exact rules first (O(1)), then regex rules in order. The first
 * regex hit wins; capture groups are substituted into the target.
 */
final class Matcher {

	/**
	 * Find the first matching rule for a path.
	 */
	public function match( Ruleset $ruleset, string $path ): ?MatchResult {
		$exact = $ruleset->exact( $path );
		if ( null !== $exact ) {
			return $this->result( $exact, $path, null );
		}

		foreach ( $ruleset->regex_rules() as $rule ) {
			$matches = Regex::match( $rule->source, $path );
			if ( null !== $matches ) {
				return $this->result( $rule, $path, $matches );
			}
		}

		return null;
	}

	/**
	 * Build a MatchResult, applying regex substitution and the self-loop guard.
	 *
	 * @param array<int, string>|null $matches Regex capture groups, or null for exact rules.
	 */
	private function result( Redirect $rule, string $path, ?array $matches ): ?MatchResult {
		$target = null === $matches
			? $rule->target
			: Regex::substitute( $rule->target, $matches );

		// Anti-loop: never redirect a path to itself.
		if ( $target === $path ) {
			return null;
		}

		return new MatchResult( $rule->id, $target, $rule->status );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter MatcherTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Redirects/Matcher.php tests/Unit/Redirects/MatcherTest.php
git commit -m "feat(redirects): add pure Matcher"
```

---

## Task 7: Repository ($wpdb access)

**Files:**
- Create: `src/Redirects/Repository.php`
- Test: `tests/Integration/RedirectsRepositoryTest.php`

**Interfaces:**
- Consumes: `Schema`, `Redirect`, `Ruleset`.
- Produces:
  - `find_active_ruleset(): Ruleset`
  - `find_active_by_source(string $path): ?Redirect`
  - `find(int $id): ?array` (raw row as assoc array, or null)
  - `all(int $limit, int $offset, string $search = ''): array<int,array>` (rows for the list table)
  - `count_all(string $search = ''): int`
  - `count_active(): int`
  - `create(array $data): int` (returns insert id; 0 on failure)
  - `update(int $id, array $data): bool`
  - `delete(int $id): bool`
  - `set_enabled(int $id, bool $enabled): bool`
  - `record_hit(int $id): void`
  - `exists_for_source(string $path): bool`
  - `$data` keys: `source_path, target, status_code, is_regex, enabled`.

- [ ] **Step 1: Write the integration test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Repository;
use WP_UnitTestCase;

final class RedirectsRepositoryTest extends WP_UnitTestCase {

	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo = new Repository();
	}

	public function test_create_find_and_ruleset_round_trip(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = $this->repo->find( $id );
		$this->assertSame( '/old', $row['source_path'] );

		$ruleset = $this->repo->find_active_ruleset();
		$this->assertSame( $id, $ruleset->exact( '/old' )->id );
		$this->assertTrue( $this->repo->exists_for_source( '/old' ) );
	}

	public function test_update_delete_and_record_hit(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/a',
				'target'      => '/b',
				'status_code' => 302,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$this->assertTrue( $this->repo->update( $id, array( 'target' => '/c' ) ) );
		$this->assertSame( '/c', $this->repo->find( $id )['target'] );

		$this->repo->record_hit( $id );
		$this->assertSame( '1', $this->repo->find( $id )['hits'] );

		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
	}

	public function test_find_active_by_source_skips_disabled(): void {
		$id = $this->repo->create(
			array(
				'source_path' => '/x',
				'target'      => '/y',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => false,
			)
		);

		$this->assertNull( $this->repo->find_active_by_source( '/x' ) );
		$this->assertTrue( $this->repo->set_enabled( $id, true ) );
		$this->assertSame( $id, $this->repo->find_active_by_source( '/x' )->id );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter RedirectsRepositoryTest`
Expected: FAIL ("Class OpenSEO\\Redirects\\Repository not found").

- [ ] **Step 3: Write the Repository**

```php
<?php
/**
 * Data access for the redirects table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use OpenSEO\Lifecycle\Schema;

/**
 * Encapsulates every SQL statement touching {prefix}openseo_redirects. The
 * table name comes from $wpdb->prefix (never user input); all values are
 * parameterized with $wpdb->prepare.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class Repository {

	/**
	 * Build the active ruleset (enabled rules only).
	 */
	public function find_active_ruleset(): Ruleset {
		global $wpdb;

		$table = Schema::redirects_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source_path, target, status_code, is_regex, enabled FROM {$table} WHERE enabled = 1", ARRAY_A );

		$ruleset = new Ruleset();
		foreach ( (array) $rows as $row ) {
			$ruleset->add( $this->to_redirect( $row ) );
		}

		return $ruleset;
	}

	/**
	 * Find one active exact rule by source path (degraded-path lookup).
	 */
	public function find_active_by_source( string $path ): ?Redirect {
		global $wpdb;

		$table = Schema::redirects_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id, source_path, target, status_code, is_regex, enabled FROM {$table} WHERE source_path = %s AND is_regex = 0 AND enabled = 1 LIMIT 1", $path ),
			ARRAY_A
		);

		return null === $row ? null : $this->to_redirect( $row );
	}

	/**
	 * Fetch a raw row by id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Schema::redirects_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row;
	}

	/**
	 * Paginated rows for the list table, optionally filtered by source/target.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit, int $offset, string $search = '' ): array {
		global $wpdb;

		$table = Schema::redirects_table();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path LIKE %s OR target LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $like, $like, $limit, $offset );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count rows for pagination.
	 */
	public function count_all( string $search = '' ): int {
		global $wpdb;

		$table = Schema::redirects_table();

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source_path LIKE %s OR target LIKE %s", $like, $like );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT COUNT(*) FROM {$table}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Count enabled rules (used to decide cache degradation).
	 */
	public function count_active(): int {
		global $wpdb;

		$table = Schema::redirects_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE enabled = 1" );
	}

	/**
	 * Insert a rule. Returns the new id or 0 on failure.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			Schema::redirects_table(),
			array(
				'source_path' => (string) $data['source_path'],
				'target'      => (string) ( $data['target'] ?? '' ),
				'status_code' => (int) $data['status_code'],
				'is_regex'    => ! empty( $data['is_regex'] ) ? 1 : 0,
				'enabled'     => ! empty( $data['enabled'] ) ? 1 : 0,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update mutable fields of a rule.
	 *
	 * @param array<string, mixed> $data Subset of source_path/target/status_code/is_regex/enabled.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$fields  = array();
		$formats = array();
		$map     = array(
			'source_path' => '%s',
			'target'      => '%s',
			'status_code' => '%d',
			'is_regex'    => '%d',
			'enabled'     => '%d',
		);

		foreach ( $map as $key => $format ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			if ( '%d' === $format ) {
				$fields[ $key ] = in_array( $key, array( 'is_regex', 'enabled' ), true )
					? ( ! empty( $data[ $key ] ) ? 1 : 0 )
					: (int) $data[ $key ];
			} else {
				$fields[ $key ] = (string) $data[ $key ];
			}
			$formats[] = $format;
		}

		if ( array() === $fields ) {
			return false;
		}

		return false !== $wpdb->update( Schema::redirects_table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Enable or disable a rule.
	 */
	public function set_enabled( int $id, bool $enabled ): bool {
		return $this->update( $id, array( 'enabled' => $enabled ) );
	}

	/**
	 * Delete a rule.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::redirects_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Increment the hit counter and stamp last_accessed.
	 */
	public function record_hit( int $id ): void {
		global $wpdb;

		$table = Schema::redirects_table();
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_accessed = %s WHERE id = %d", current_time( 'mysql', true ), $id )
		);
	}

	/**
	 * Whether an exact rule already exists for a source path.
	 */
	public function exists_for_source( string $path ): bool {
		global $wpdb;

		$table = Schema::redirects_table();
		$found = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} WHERE source_path = %s AND is_regex = 0 LIMIT 1", $path )
		);

		return null !== $found;
	}

	/**
	 * Map a raw row to a Redirect DTO.
	 *
	 * @param array<string, mixed> $row
	 */
	private function to_redirect( array $row ): Redirect {
		return new Redirect(
			(int) $row['id'],
			(string) $row['source_path'],
			(string) $row['target'],
			(int) $row['status_code'],
			(bool) (int) $row['is_regex'],
			(bool) (int) $row['enabled'],
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:integration -- --filter RedirectsRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Verify gates and commit**

Run: `composer lint && composer analyze`
Expected: no errors.

```bash
git add src/Redirects/Repository.php tests/Integration/RedirectsRepositoryTest.php
git commit -m "feat(redirects): add Repository data layer"
```

---

## Task 8: Cache (ruleset cache + invalidation)

**Files:**
- Create: `src/Redirects/Cache.php`
- Test: covered indirectly by the Dispatcher integration test (Task 9); no isolated test (thin WP wrapper).

**Interfaces:**
- Consumes: `Repository`, `Ruleset`.
- Produces: `new Cache(Repository $repo)`; `Cache::get(): Ruleset`; `Cache::flush(): void`; `Cache::is_degraded(): bool`; `Cache::DEGRADE_THRESHOLD` (int).

- [ ] **Step 1: Write the Cache**

```php
<?php
/**
 * Caches the active redirect ruleset.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

/**
 * Serves the ruleset from the object cache when available, falling back to a
 * transient (the effective store on sites without a persistent object cache).
 * Above DEGRADE_THRESHOLD rules the Dispatcher should bypass this and do
 * indexed per-request lookups instead.
 */
final class Cache {

	private const GROUP = 'openseo_redirects';

	private const KEY = 'ruleset';

	private const COUNT_KEY = 'active_count';

	private const TRANSIENT = 'openseo_redirects_ruleset';

	private const COUNT_TRANSIENT = 'openseo_redirects_count';

	/**
	 * Above this many active rules, caching the whole set is wasteful.
	 */
	public const DEGRADE_THRESHOLD = 2000;

	public function __construct( private readonly Repository $repo ) {}

	/**
	 * Get the active ruleset, building and caching it on a miss.
	 */
	public function get(): Ruleset {
		$cached = wp_cache_get( self::KEY, self::GROUP );
		if ( $cached instanceof Ruleset ) {
			return $cached;
		}

		$stored = get_transient( self::TRANSIENT );
		if ( $stored instanceof Ruleset ) {
			wp_cache_set( self::KEY, $stored, self::GROUP );

			return $stored;
		}

		$ruleset = $this->repo->find_active_ruleset();
		wp_cache_set( self::KEY, $ruleset, self::GROUP );
		set_transient( self::TRANSIENT, $ruleset );

		return $ruleset;
	}

	/**
	 * Invalidate BOTH stores (ruleset and count). Called on every write.
	 */
	public function flush(): void {
		wp_cache_delete( self::KEY, self::GROUP );
		wp_cache_delete( self::COUNT_KEY, self::GROUP );
		delete_transient( self::TRANSIENT );
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Whether the active rule count exceeds the cache threshold. The count is
	 * cached (object cache → transient) so the hot path never issues a per-request
	 * COUNT(*); it is rebuilt only on a cache miss, like the ruleset itself.
	 */
	public function is_degraded(): bool {
		return $this->active_count() > self::DEGRADE_THRESHOLD;
	}

	/**
	 * Cached count of active rules.
	 */
	private function active_count(): int {
		$cached = wp_cache_get( self::COUNT_KEY, self::GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$stored = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $stored ) {
			wp_cache_set( self::COUNT_KEY, (int) $stored, self::GROUP );

			return (int) $stored;
		}

		$count = $this->repo->count_active();
		wp_cache_set( self::COUNT_KEY, $count, self::GROUP );
		set_transient( self::COUNT_TRANSIENT, $count );

		return $count;
	}
}
```

- [ ] **Step 2: Static analysis**

Run: `composer analyze`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/Redirects/Cache.php
git commit -m "feat(redirects): add ruleset Cache with dual-store invalidation"
```

---

## Task 9: Dispatcher (template_redirect@5)

**Files:**
- Create: `src/Redirects/Dispatcher.php`
- Test: `tests/Integration/DispatcherTest.php`

**Interfaces:**
- Consumes: `Normalizer`, `Cache`, `Matcher`, `Repository`, `OpenSEO\Settings\Options`, `OpenSEO\Contracts\Hookable`.
- Produces: `new Dispatcher(Cache $cache, Matcher $matcher, Repository $repo, Options $options)`; `register(): void`; `maybe_redirect(): void`; `resolve(string $request_uri): ?MatchResult` (testable seam).

- [ ] **Step 1: Write the integration test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Dispatcher;
use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class DispatcherTest extends WP_UnitTestCase {

	private Dispatcher $dispatcher;
	private Repository $repo;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->repo       = new Repository();
		$this->dispatcher = new Dispatcher( new Cache( $this->repo ), new Matcher(), $this->repo, new Options() );
	}

	public function test_resolve_returns_match_for_exact_rule(): void {
		$this->repo->create(
			array(
				'source_path' => '/old',
				'target'      => '/new',
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);

		$result = $this->dispatcher->resolve( '/old/?utm=x' );

		$this->assertNotNull( $result );
		$this->assertSame( '/new', $result->target );
		$this->assertSame( 301, $result->status );
	}

	public function test_resolve_returns_null_for_unknown_path(): void {
		$this->assertNull( $this->dispatcher->resolve( '/nothing' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter DispatcherTest`
Expected: FAIL ("Class OpenSEO\\Redirects\\Dispatcher not found").

- [ ] **Step 3: Write the Dispatcher**

```php
<?php
/**
 * Performs redirects on the front end.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Matches each front-end request against the cached ruleset and issues the
 * redirect, before core's redirect_canonical (priority 5 vs 10). The hit
 * counter write is deferred to shutdown so it never delays the redirect.
 */
final class Dispatcher implements Hookable {

	/**
	 * Status codes that redirect to a target.
	 *
	 * @var int[]
	 */
	private const REDIRECT_CODES = array( 301, 302, 307 );

	/**
	 * Rule id whose hit should be recorded on shutdown, or 0.
	 */
	private int $pending_hit = 0;

	public function __construct(
		private readonly Cache $cache,
		private readonly Matcher $matcher,
		private readonly Repository $repo,
		private readonly Options $options,
	) {}

	/**
	 * Hook early on template_redirect (before redirect_canonical at 10).
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 5 );
	}

	/**
	 * Resolve the current request and act on any match.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$result = $this->resolve( $request_uri );
		if ( null === $result ) {
			return;
		}

		$this->schedule_hit( $result->id );

		if ( 410 === $result->status ) {
			status_header( 410 );
			nocache_headers();
			return; // Let the theme render its "not found" body with a 410 status.
		}

		if ( in_array( $result->status, self::REDIRECT_CODES, true ) ) {
			$target = $result->target;
			if ( $this->is_external( $target ) ) {
				wp_redirect( esc_url_raw( $target ), $result->status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external targets are intentional.
			} else {
				wp_safe_redirect( $target, $result->status );
			}
			exit;
		}
	}

	/**
	 * Match a request URI against the ruleset. Testable without the request cycle.
	 */
	public function resolve( string $request_uri ): ?MatchResult {
		$normalizer = new Normalizer( $this->home_path() );
		$path       = $normalizer->normalize( $request_uri );

		if ( $this->cache->is_degraded() ) {
			$rule = $this->repo->find_active_by_source( $path );
			if ( null === $rule || $rule->target === $path ) {
				return null;
			}

			return new MatchResult( $rule->id, $rule->target, $rule->status );
		}

		return $this->matcher->match( $this->cache->get(), $path );
	}

	/**
	 * Defer the hit write to shutdown so it never adds latency before the redirect.
	 */
	private function schedule_hit( int $id ): void {
		if ( '1' !== (string) $this->options->get( 'redirects_track_hits' ) ) {
			return;
		}

		$this->pending_hit = $id;
		add_action( 'shutdown', array( $this, 'flush_hit' ) );
	}

	/**
	 * Write the deferred hit (runs on shutdown).
	 */
	public function flush_hit(): void {
		if ( $this->pending_hit > 0 ) {
			$this->repo->record_hit( $this->pending_hit );
			$this->pending_hit = 0;
		}
	}

	/**
	 * Path component of the home URL (for subdirectory installs).
	 */
	private function home_path(): string {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		return is_string( $path ) ? rtrim( $path, '/' ) : '';
	}

	/**
	 * Whether a target points to another host.
	 */
	private function is_external( string $target ): bool {
		$host = wp_parse_url( $target, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:integration -- --filter DispatcherTest`
Expected: PASS.

- [ ] **Step 5: Verify gates and commit**

Run: `composer lint && composer analyze`
Expected: no errors.

```bash
git add src/Redirects/Dispatcher.php tests/Integration/DispatcherTest.php
git commit -m "feat(redirects): add Dispatcher with deferred hit tracking"
```

---

## Task 10: Settings (Options + tab)

**Files:**
- Modify: `src/Settings/Options.php`
- Modify: `src/Admin/SettingsPage.php`
- Modify: `templates/admin/settings-page.php`
- Test: `tests/Unit/OptionsTest.php` (extend existing)

**Interfaces:**
- Produces new `openseo_settings` keys: `redirects_auto_slug` ('1'), `redirects_default_status` ('301'), `redirects_track_hits` ('1'), `notfound_monitor_enabled` (''), `notfound_retention_days` ('30').

- [ ] **Step 1: Write the failing test (extend OptionsTest)**

Add to `tests/Unit/OptionsTest.php`:

```php
	public function test_defaults_include_redirect_keys(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$options = new Options();

		$this->assertSame( '1', $options->get( 'redirects_auto_slug' ) );
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
	}
```

(Confirm `OptionsTest` already imports `Brain\Monkey\Functions` as `Functions`; the existing file does.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: FAIL (keys missing / not clamped).

- [ ] **Step 3: Add the defaults**

In `src/Settings/Options.php` `defaults()`, add to the returned array:

```php
			'redirects_auto_slug'      => '1',
			'redirects_default_status' => '301',
			'redirects_track_hits'     => '1',
			'notfound_monitor_enabled' => '',
			'notfound_retention_days'  => '30',
```

- [ ] **Step 4: Add the sanitization**

In `Options::sanitize()`, add the checkbox keys to the existing checkbox loop array:

```php
		foreach ( array( 'sitemap_enabled', 'sitemap_include_authors', 'redirects_auto_slug', 'redirects_track_hits', 'notfound_monitor_enabled' ) as $key ) {
```

Then add, before `return $clean;`:

```php
		if ( isset( $input['redirects_default_status'] ) ) {
			$status                              = sanitize_text_field( wp_unslash( $input['redirects_default_status'] ) );
			$clean['redirects_default_status'] = in_array( $status, array( '301', '302', '307' ), true ) ? $status : '301';
		}

		if ( isset( $input['notfound_retention_days'] ) ) {
			$days                              = absint( wp_unslash( $input['notfound_retention_days'] ) );
			$clean['notfound_retention_days'] = (string) max( 1, $days );
		}
```

- [ ] **Step 5: Register the settings tab**

In `src/Admin/SettingsPage.php` `register_settings()`, add the section and fields:

```php
		add_settings_section( 'openseo_redirects', __( 'Redirects', 'openseo' ), '__return_false', 'openseo_redirects' );

		$this->add_checkbox_field( 'redirects_auto_slug', __( 'Auto-redirect on slug change', 'openseo' ), 'openseo_redirects' );
		$this->add_checkbox_field( 'redirects_track_hits', __( 'Track redirect hits', 'openseo' ), 'openseo_redirects' );
		$this->add_select_field(
			'redirects_default_status',
			__( 'Default redirect type', 'openseo' ),
			'openseo_redirects',
			array(
				'301' => __( '301 — Permanent', 'openseo' ),
				'302' => __( '302 — Temporary', 'openseo' ),
				'307' => __( '307 — Temporary (preserve method)', 'openseo' ),
			)
		);
		$this->add_checkbox_field( 'notfound_monitor_enabled', __( 'Enable 404 monitor', 'openseo' ), 'openseo_redirects' );
		$this->add_text_field( 'notfound_retention_days', __( '404 retention (days)', 'openseo' ), 'openseo_redirects' );
```

- [ ] **Step 6: Add the tab to the template**

In `templates/admin/settings-page.php`, add to the `$openseo_tabs` array (before `'schema'` to keep grouping, order is cosmetic):

```php
	'redirects' => __( 'Redirects', 'openseo' ),
```

- [ ] **Step 7: Run unit tests**

Run: `vendor/bin/phpunit --filter OptionsTest`
Expected: PASS.

- [ ] **Step 8: Verify gates and commit**

Run: `composer lint && composer analyze && composer test:unit`
Expected: all green.

```bash
git add src/Settings/Options.php src/Admin/SettingsPage.php templates/admin/settings-page.php tests/Unit/OptionsTest.php
git commit -m "feat(redirects): add redirect settings tab and options"
```

---

## Task 11: Admin manager (list table + page)

**Files:**
- Create: `src/Redirects/Admin/RedirectsListTable.php`
- Create: `src/Redirects/Admin/RedirectsPage.php`
- Test: manual smoke test (admin UI); logic covered by Repository tests.

**Interfaces:**
- Consumes: `Repository`, `Cache`, `Regex`, `Normalizer`, `Options`, `Hookable`.
- Produces: `new RedirectsPage(Repository $repo, Cache $cache, Options $options)`; `register(): void` (adds the Tools page + form handler). Page slug: `openseo-redirects`. Sub-tabs: `redirects` (default), `notfound` (filled in Part B).

- [ ] **Step 1: Write the list table**

```php
<?php
/**
 * Redirects admin list table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects\Admin;

use OpenSEO\Redirects\Repository;
use WP_List_Table;

// WP_List_Table is a private core class not always autoloaded; require it.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists redirect rules with search, pagination, and row actions.
 */
final class RedirectsListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct( private readonly Repository $repo ) {
		parent::__construct(
			array(
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'source_path'   => __( 'Source', 'openseo' ),
			'target'        => __( 'Target', 'openseo' ),
			'status_code'   => __( 'Type', 'openseo' ),
			'enabled'       => __( 'Status', 'openseo' ),
			'hits'          => __( 'Hits', 'openseo' ),
			'last_accessed' => __( 'Last used', 'openseo' ),
		);
	}

	/**
	 * Build the items from the repository.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging/search.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$paged  = $this->get_pagenum();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$this->items = $this->repo->all( self::PER_PAGE, $offset, $search );

		$this->set_pagination_args(
			array(
				'total_items' => $this->repo->count_all( $search ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Default escaped cell renderer.
	 *
	 * @param array<string, mixed> $item   Row.
	 * @param string               $column Column id.
	 */
	public function column_default( $item, $column ): string {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Source cell with edit/delete/toggle row actions.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_source_path( $item ): string {
		$id          = (int) $item['id'];
		$action_base = admin_url( 'admin-post.php?action=openseo_redirect_row_action' );

		// Toggle/delete hit admin-post.php; edit-in-place is a documented follow-up,
		// so no "Edit" action is shown (no dead links).
		$toggle     = (int) $item['enabled'] === 1 ? 'disable' : 'enable';
		$toggle_url = wp_nonce_url( add_query_arg( array( 'do' => $toggle, 'id' => $id ), $action_base ), 'openseo_redirect_' . $toggle . '_' . $id );
		$delete_url = wp_nonce_url( add_query_arg( array( 'do' => 'delete', 'id' => $id ), $action_base ), 'openseo_redirect_delete_' . $id );

		$actions = array(
			'toggle' => sprintf( '<a href="%s">%s</a>', esc_url( $toggle_url ), 'disable' === $toggle ? esc_html__( 'Disable', 'openseo' ) : esc_html__( 'Enable', 'openseo' ) ),
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'%s\')">%s</a>', esc_url( $delete_url ), esc_js( __( 'Delete this redirect?', 'openseo' ) ), esc_html__( 'Delete', 'openseo' ) ),
		);

		$source = (int) $item['is_regex'] === 1
			? esc_html( $item['source_path'] ) . ' <em>(regex)</em>'
			: esc_html( $item['source_path'] );

		return $source . $this->row_actions( $actions );
	}

	/**
	 * Status badge.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_enabled( $item ): string {
		return (int) $item['enabled'] === 1
			? esc_html__( 'Enabled', 'openseo' )
			: esc_html__( 'Disabled', 'openseo' );
	}
}
```

- [ ] **Step 2: Write the admin page controller**

```php
<?php
/**
 * Redirects manager page (Tools → OpenSEO Redirects).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects\Admin;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Normalizer;
use OpenSEO\Redirects\Regex;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\Options;

/**
 * Registers the Tools page, handles CRUD form submissions (nonce + capability),
 * and renders the redirects sub-tab.
 */
final class RedirectsPage implements Hookable {

	private const SLUG = 'openseo-redirects';

	private const CAP = 'manage_options';

	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_openseo_save_redirect', array( $this, 'handle_save' ) );
		add_action( 'admin_post_openseo_redirect_row_action', array( $this, 'handle_row_action' ) );
	}

	public function add_page(): void {
		add_management_page(
			__( 'OpenSEO Redirects', 'openseo' ),
			__( 'OpenSEO Redirects', 'openseo' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the add/edit form POST.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'openseo' ) );
		}
		check_admin_referer( 'openseo_save_redirect' );

		$id       = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$is_regex = ! empty( $_POST['is_regex'] );
		$source   = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target   = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ), array( 'http', 'https' ) ) : '';
		$relative = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$status   = isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301;

		// Relative targets fail esc_url_raw; keep the sanitized relative path.
		if ( '' === $target && '' !== $relative ) {
			$target = $relative;
		}

		// Normalize exact sources; validate regex sources.
		if ( $is_regex ) {
			if ( ! Regex::is_valid( $source ) ) {
				$this->redirect_back( 'invalid_regex' );
			}
		} else {
			$source = ( new Normalizer() )->normalize( $source );
		}

		if ( '' === $source || ! in_array( $status, array( 301, 302, 307, 410 ), true ) ) {
			$this->redirect_back( 'invalid' );
		}
		if ( 410 !== $status && '' === $target ) {
			$this->redirect_back( 'invalid' );
		}

		$data = array(
			'source_path' => $source,
			'target'      => 410 === $status ? '' : $target,
			'status_code' => $status,
			'is_regex'    => $is_regex,
			'enabled'     => true,
		);

		if ( $id > 0 ) {
			$this->repo->update( $id, $data );
		} else {
			$this->repo->create( $data );
		}

		$this->cache->flush();
		$this->redirect_back( 'saved' );
	}

	/**
	 * Handle enable/disable/delete row actions (GET with per-row nonce).
	 */
	public function handle_row_action(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'openseo' ) );
		}

		$action = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		check_admin_referer( 'openseo_redirect_' . $action . '_' . $id );

		if ( 'delete' === $action ) {
			$this->repo->delete( $id );
		} elseif ( 'enable' === $action ) {
			$this->repo->set_enabled( $id, true );
		} elseif ( 'disable' === $action ) {
			$this->repo->set_enabled( $id, false );
		}

		$this->cache->flush();
		$this->redirect_back( $action );
	}

	/**
	 * Render the page (sub-tabs + active panel).
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'redirects';

		// Pre-fill source from a 404 "create redirect" link (re-normalized, never trusted).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET prefill only; the save POST is nonce-protected.
		$prefill = isset( $_GET['source'] ) ? ( new Normalizer() )->normalize( sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) : '';

		// Inject the page's collaborators into the template (no `new` in the view).
		$openseo_repo = $this->repo;

		require OPENSEO_PLUGIN_DIR . 'templates/admin/redirects-page.php';
	}

	/**
	 * Redirect back to the manager with a status flag.
	 */
	private function redirect_back( string $flag ): void {
		wp_safe_redirect( add_query_arg( 'openseo_msg', $flag, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}
}
```

- [ ] **Step 3: Write the page template**

Create `templates/admin/redirects-page.php`:

```php
<?php
/**
 * Redirects manager template.
 *
 * @package OpenSEO
 *
 * @var string                        $tab          Active sub-tab.
 * @var string                        $prefill      Pre-filled source path.
 * @var \OpenSEO\Redirects\Repository $openseo_repo Injected by the page controller.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'OpenSEO Redirects', 'openseo' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=openseo-redirects&tab=redirects' ) ); ?>" class="nav-tab <?php echo 'redirects' === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( 'Redirections', 'openseo' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=openseo-redirects&tab=notfound' ) ); ?>" class="nav-tab <?php echo 'notfound' === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html__( '404 Monitor', 'openseo' ); ?></a>
	</nav>

	<?php if ( 'redirects' === $tab ) : ?>
		<h2><?php echo esc_html__( 'Add redirect', 'openseo' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="openseo_save_redirect" />
			<?php wp_nonce_field( 'openseo_save_redirect' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="openseo_source"><?php echo esc_html__( 'Source path', 'openseo' ); ?></label></th>
					<td><input type="text" id="openseo_source" name="source_path" class="regular-text" value="<?php echo esc_attr( $prefill ); ?>" required /></td>
				</tr>
				<tr>
					<th><label for="openseo_target"><?php echo esc_html__( 'Target', 'openseo' ); ?></label></th>
					<td><input type="text" id="openseo_target" name="target" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="openseo_status"><?php echo esc_html__( 'Type', 'openseo' ); ?></label></th>
					<td>
						<select id="openseo_status" name="status_code">
							<option value="301">301</option>
							<option value="302">302</option>
							<option value="307">307</option>
							<option value="410">410</option>
						</select>
						<label><input type="checkbox" name="is_regex" value="1" /> <?php echo esc_html__( 'Regex', 'openseo' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save redirect', 'openseo' ) ); ?>
		</form>

		<?php
		$openseo_table = new \OpenSEO\Redirects\Admin\RedirectsListTable( $openseo_repo );
		$openseo_table->prepare_items();
		?>
		<form method="get">
			<input type="hidden" name="page" value="openseo-redirects" />
			<?php
			$openseo_table->search_box( __( 'Search', 'openseo' ), 'openseo-redirect' );
			$openseo_table->display();
			?>
		</form>
	<?php else : ?>
		<?php require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-panel.php'; ?>
	<?php endif; ?>
</div>
```

> Note: the `notfound-panel.php` include is created in Part B (Task 16). Until then, create a **lint-valid** placeholder at `templates/admin/notfound-panel.php` (PHPCS scans `templates/`), replaced in Task 16:
>
> ```php
> <?php
> /**
>  * 404 monitor panel placeholder (filled in Part B, Task 16).
>  *
>  * @package OpenSEO
>  */
>
> declare( strict_types=1 );
>
> defined( 'ABSPATH' ) || exit;
> ```

- [ ] **Step 4: Static analysis + lint**

Run: `composer lint && composer analyze`
Expected: no errors.

- [ ] **Step 5: Manual smoke test**

Run: `npm run env:start`, log in at http://localhost:8888/wp-admin (admin/password), open **Tools → OpenSEO Redirects**, add a redirect `/old → /new`, visit `http://localhost:8888/old`, confirm a 301 to `/new`. Toggle and delete from the list.

- [ ] **Step 6: Commit**

```bash
git add src/Redirects/Admin/ templates/admin/redirects-page.php templates/admin/notfound-panel.php
git commit -m "feat(redirects): add admin manager (list table + CRUD page)"
```

---

## Task 12: SlugWatcher (auto-redirect on slug change)

**Files:**
- Create: `src/Redirects/SlugWatcher.php`
- Test: `tests/Unit/Redirects/SlugWatcherDecisionTest.php`, `tests/Integration/SlugWatcherTest.php`

**Interfaces:**
- Consumes: `Repository`, `Cache`, `Options`, `Hookable`.
- Produces: `new SlugWatcher(Repository $repo, Cache $cache, Options $options)`; `register(): void`; `should_create(string $old_path, string $new_path, string $before_status, bool $enabled, bool $is_revision, bool $is_autosave): bool` (pure decision).

- [ ] **Step 1: Write the failing unit test for the decision**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Unit\Redirects;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Settings\Options;
use PHPUnit\Framework\TestCase;

final class SlugWatcherDecisionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function watcher(): SlugWatcher {
		Functions\when( 'get_option' )->justReturn( array() );

		return new SlugWatcher( $this->createMock( Repository::class ), $this->createMock( Cache::class ), new Options() );
	}

	public function test_creates_when_published_permalink_changes(): void {
		$this->assertTrue( $this->watcher()->should_create( '/old', '/new', 'publish', true, false, false ) );
	}

	public function test_skips_when_disabled(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', false, false, false ) );
	}

	public function test_skips_draft_to_publish(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'draft', true, false, false ) );
	}

	public function test_skips_revisions_and_autosaves(): void {
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', true, true, false ) );
		$this->assertFalse( $this->watcher()->should_create( '/old', '/new', 'publish', true, false, true ) );
	}

	public function test_skips_when_permalink_unchanged(): void {
		$this->assertFalse( $this->watcher()->should_create( '/same', '/same', 'publish', true, false, false ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SlugWatcherDecisionTest`
Expected: FAIL ("Class ... not found").

- [ ] **Step 3: Write the SlugWatcher**

```php
<?php
/**
 * Creates a 301 when a published entry's permalink changes.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;
use WP_Post;

/**
 * Captures the old permalink before an update and, if a published entry's
 * permalink actually changed, records an automatic 301. The decision is a pure
 * method so its many guards are unit-testable without WordPress.
 */
final class SlugWatcher implements Hookable {

	/**
	 * Old normalized paths captured before update, keyed by post id.
	 *
	 * @var array<int, string>
	 */
	private array $old_paths = array();

	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'capture_old' ), 10, 1 );
		add_action( 'post_updated', array( $this, 'maybe_create' ), 10, 3 );
	}

	/**
	 * Snapshot the current (about-to-be-old) permalink path.
	 *
	 * @param int $post_id Post being updated.
	 */
	public function capture_old( int $post_id ): void {
		$this->old_paths[ $post_id ] = $this->path_of( get_permalink( $post_id ) );
	}

	/**
	 * On update, create a 301 if the decision passes.
	 *
	 * @param int     $post_id     Post id.
	 * @param WP_Post $post_after  Updated post.
	 * @param WP_Post $post_before Pre-update post.
	 */
	public function maybe_create( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		$old = $this->old_paths[ $post_id ] ?? '';
		$new = $this->path_of( get_permalink( $post_id ) );

		$decide = $this->should_create(
			$old,
			$new,
			(string) $post_before->post_status,
			'1' === (string) $this->options->get( 'redirects_auto_slug' ),
			(bool) wp_is_post_revision( $post_id ),
			defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE,
		);

		if ( ! $decide || $this->repo->exists_for_source( $old ) ) {
			return;
		}

		$this->repo->create(
			array(
				'source_path' => $old,
				'target'      => $new,
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);
		$this->cache->flush();
	}

	/**
	 * Pure decision: should we create an auto-redirect?
	 */
	public function should_create(
		string $old_path,
		string $new_path,
		string $before_status,
		bool $enabled,
		bool $is_revision,
		bool $is_autosave
	): bool {
		if ( ! $enabled || $is_revision || $is_autosave ) {
			return false;
		}
		if ( 'publish' !== $before_status ) {
			return false;
		}
		if ( '' === $old_path || '' === $new_path || $old_path === $new_path ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize a permalink to a comparable path.
	 *
	 * @param string|false $permalink Permalink URL.
	 */
	private function path_of( $permalink ): string {
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? rtrim( $home, '/' ) : '';

		return ( new Normalizer( $home ) )->normalize( is_string( $path ) ? $path : '' );
	}
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `vendor/bin/phpunit --filter SlugWatcherDecisionTest`
Expected: PASS.

- [ ] **Step 5: Write the integration test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SlugWatcherTest extends WP_UnitTestCase {

	public function test_renaming_published_post_creates_redirect(): void {
		Schema::install();
		update_option( 'openseo_settings', array( 'redirects_auto_slug' => '1' ) );

		// The test bench starts with plain permalinks (?p=N); without a pretty
		// structure, old and new permalinks both normalize to '/' and no
		// redirect is ever created. set_permalink_structure() flushes rules.
		$this->set_permalink_structure( '/%postname%/' );

		$repo    = new Repository();
		$watcher = new SlugWatcher( $repo, new Cache( $repo ), new Options() );
		$watcher->register();

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'original-slug',
				'post_title'  => 'Original',
			)
		);

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'renamed-slug',
			)
		);

		$this->assertTrue( $repo->exists_for_source( '/original-slug' ) );
	}
}
```

- [ ] **Step 6: Run integration test**

Run: `npm run test:integration -- --filter SlugWatcherTest`
Expected: PASS.

- [ ] **Step 7: Verify gates and commit**

Run: `composer lint && composer analyze && composer test:unit`
Expected: all green.

```bash
git add src/Redirects/SlugWatcher.php tests/Unit/Redirects/SlugWatcherDecisionTest.php tests/Integration/SlugWatcherTest.php
git commit -m "feat(redirects): auto-redirect on published slug change"
```

---

## Task 13: Register redirect modules + upgrade gate

**Files:**
- Modify: `src/Plugin.php`
- Test: `tests/Integration/PluginBootTest.php` (extend existing).

**Interfaces:**
- Consumes: all Part A modules + `Schema`.

- [ ] **Step 1: Register the modules and upgrade check**

In `src/Plugin.php`, add the `use` statements:

```php
use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Admin\RedirectsPage;
use OpenSEO\Redirects\Cache as RedirectsCache;
use OpenSEO\Redirects\Dispatcher;
use OpenSEO\Redirects\Matcher;
use OpenSEO\Redirects\Repository as RedirectsRepository;
use OpenSEO\Redirects\SlugWatcher;
```

In `modules()`, after the existing `$resolver` / `$trail` setup, build the redirect collaborators:

```php
		$redirects_repo  = new RedirectsRepository();
		$redirects_cache = new RedirectsCache( $redirects_repo );
```

Add to the `$modules` array (front, always):

```php
			new Dispatcher( $redirects_cache, new Matcher(), $redirects_repo, $options ),
			new SlugWatcher( $redirects_repo, $redirects_cache, $options ),
```

Add to the `is_admin()` block:

```php
			$modules[] = new RedirectsPage( $redirects_repo, $redirects_cache, $options );
```

- [ ] **Step 2: Add the version-gated upgrade check in boot()**

In `Plugin::boot()`, after the module loop, register the upgrade gate:

```php
		add_action(
			'admin_init',
			static function (): void {
				if ( Schema::current_version() !== Schema::VERSION ) {
					Schema::install();
				}
			}
		);
```

- [ ] **Step 3: Extend the boot test**

Add to `tests/Integration/PluginBootTest.php` a check that the dispatcher is hooked at the exact priority 5 (asserting the priority, not just "some callback"):

```php
	public function test_dispatcher_registered_at_priority_5(): void {
		global $wp_filter;

		$this->assertArrayHasKey( 'template_redirect', $wp_filter );
		$priorities = array_keys( $wp_filter['template_redirect']->callbacks );
		$this->assertContains( 5, $priorities, 'Dispatcher must run before redirect_canonical@10.' );
	}
```

> Dependency note: Part B (Task 16) also edits `Plugin.php` to register `Monitor`/`Pruner`. Part B must run after this task — `Monitor@99` and the `openseo_404_prune` cron are asserted by a sibling test added in Task 16, Step 4.

- [ ] **Step 4: Run tests + gates**

Run: `composer test:unit && composer lint && composer analyze`
Then: `npm run test:integration -- --filter PluginBootTest`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php tests/Integration/PluginBootTest.php
git commit -m "feat(redirects): register modules and schema upgrade gate"
```

---

# Part B — 404 Monitor

## Task 14: LogRepository (aggregated upsert)

**Files:**
- Create: `src/NotFound/LogRepository.php`
- Test: `tests/Integration/NotFoundTest.php`

**Interfaces:**
- Consumes: `Schema`.
- Produces:
  - `record(string $url, string $referrer = '', string $user_agent = ''): void`
  - `all(int $limit, int $offset): array<int,array>`
  - `count_all(): int`
  - `delete(int $id): bool`
  - `clear(): void`
  - `prune(int $days): int` (rows deleted)

- [ ] **Step 1: Write the integration test**

```php
<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\NotFound\LogRepository;
use WP_UnitTestCase;

final class NotFoundTest extends WP_UnitTestCase {

	private LogRepository $logs;

	public function set_up(): void {
		parent::set_up();
		Schema::install();
		$this->logs = new LogRepository();
	}

	public function test_record_aggregates_by_url(): void {
		$this->logs->record( '/missing', 'https://ref', 'UA' );
		$first = $this->logs->all( 10, 0 )[0];

		$this->logs->record( '/missing', 'https://ref2', 'UA2' );
		$second = $this->logs->all( 10, 0 )[0];

		$this->assertCount( 1, $this->logs->all( 10, 0 ) );
		$this->assertSame( '2', $second['hits'] );
		$this->assertSame( '/missing', $second['url'] );
		// The upsert must NOT touch first_seen; it must update referrer/UA.
		$this->assertSame( $first['first_seen'], $second['first_seen'] );
		$this->assertSame( 'https://ref2', $second['referrer'] );
	}

	public function test_prune_removes_old_rows(): void {
		global $wpdb;
		$this->logs->record( '/old' );
		// Backdate last_seen beyond the retention window.
		$wpdb->query( "UPDATE " . Schema::logs_table() . " SET last_seen = '2000-01-01 00:00:00'" );

		$this->assertSame( 1, $this->logs->prune( 30 ) );
		$this->assertSame( 0, $this->logs->count_all() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test:integration -- --filter NotFoundTest`
Expected: FAIL ("Class OpenSEO\\NotFound\\LogRepository not found").

- [ ] **Step 3: Write the LogRepository**

```php
<?php
/**
 * Data access for the 404 logs table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

use OpenSEO\Lifecycle\Schema;

/**
 * Encapsulates the aggregated 404 log. record() is the only raw SQL in the
 * plugin (an ON DUPLICATE KEY upsert that $wpdb->insert cannot express); every
 * value is parameterized and url_hash is computed in PHP.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class LogRepository {

	/**
	 * Upsert a 404 hit, aggregating by URL.
	 */
	public function record( string $url, string $referrer = '', string $user_agent = '' ): void {
		global $wpdb;

		$url        = $this->trim( $url, 2048 );
		$url_hash   = md5( $url );
		$referrer   = '' === $referrer ? null : $this->trim( $referrer, 255 );
		$user_agent = '' === $user_agent ? null : $this->trim( $user_agent, 255 );
		// UTC, so prune()'s gmdate() cutoff compares correctly on any timezone.
		$now        = current_time( 'mysql', true );
		$table      = Schema::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (url, url_hash, hits, first_seen, last_seen, referrer, user_agent)
				VALUES (%s, %s, 1, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = VALUES(referrer), user_agent = VALUES(user_agent)",
				$url,
				$url_hash,
				$now,
				$now,
				$referrer,
				$user_agent
			)
		);
	}

	/**
	 * Paginated rows, newest activity first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all( int $limit, int $offset ): array {
		global $wpdb;

		$table = Schema::logs_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d OFFSET %d", $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Total logged URLs.
	 */
	public function count_all(): int {
		global $wpdb;

		$table = Schema::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete one logged URL.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( Schema::logs_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Empty the log.
	 */
	public function clear(): void {
		global $wpdb;

		$table = Schema::logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Delete rows whose last_seen is older than $days. Returns rows removed.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$table  = Schema::logs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
	}

	/**
	 * Sanitize + truncate a value to a column width.
	 */
	private function trim( string $value, int $length ): string {
		return substr( sanitize_text_field( $value ), 0, $length );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test:integration -- --filter NotFoundTest`
Expected: PASS.

- [ ] **Step 5: Verify gates and commit**

Run: `composer lint && composer analyze`
Expected: no errors.

```bash
git add src/NotFound/LogRepository.php tests/Integration/NotFoundTest.php
git commit -m "feat(404): add aggregated LogRepository upsert"
```

---

## Task 15: Monitor + Pruner

**Files:**
- Create: `src/NotFound/Monitor.php`
- Create: `src/NotFound/Pruner.php`
- Test: covered by `NotFoundTest` (record) + manual smoke; cron scheduling asserted in boot test.

**Interfaces:**
- Consumes: `LogRepository`, `Options`, `Hookable`.
- Produces: `new Monitor(LogRepository $logs, Options $options)`; `new Pruner(LogRepository $logs, Options $options)`; both `register()`. Cron hook name: `openseo_404_prune`.

- [ ] **Step 1: Write the Monitor**

```php
<?php
/**
 * Logs front-end 404s when the monitor is enabled.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Records real 404s on template_redirect at priority 99 — after the Dispatcher
 * (priority 5) has already exited on any match, so only genuine 404s arrive.
 */
final class Monitor implements Hookable {

	public function __construct(
		private readonly LogRepository $logs,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 99 );
	}

	/**
	 * Log the current request if it is a 404 and the monitor is on.
	 */
	public function maybe_log(): void {
		if ( is_admin() || ! is_404() ) {
			return;
		}
		if ( '1' !== (string) $this->options->get( 'notfound_monitor_enabled' ) ) {
			return;
		}

		$url        = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$referrer   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( '' === $url ) {
			return;
		}

		$this->logs->record( $url, $referrer, $user_agent );
	}
}
```

- [ ] **Step 2: Write the Pruner**

```php
<?php
/**
 * Schedules and runs 404 log retention.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Ensures a daily cron event prunes 404 rows older than the retention setting.
 */
final class Pruner implements Hookable {

	private const HOOK = 'openseo_404_prune';

	public function __construct(
		private readonly LogRepository $logs,
		private readonly Options $options,
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule the daily event once.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Prune old rows to the configured retention window.
	 */
	public function run(): void {
		$days = (int) $this->options->get( 'notfound_retention_days' );
		$this->logs->prune( max( 1, $days ) );
	}
}
```

- [ ] **Step 3: Static analysis + lint**

Run: `composer lint && composer analyze`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/NotFound/Monitor.php src/NotFound/Pruner.php
git commit -m "feat(404): add Monitor and retention Pruner"
```

---

## Task 16: 404 admin panel + create-from-404

**Files:**
- Create: `src/NotFound/Admin/NotFoundListTable.php`
- Modify: `templates/admin/notfound-panel.php` (replace the Part A placeholder)
- Test: manual smoke test.

**Interfaces:**
- Consumes: `LogRepository`.
- Produces: `new NotFoundListTable(LogRepository $logs)`; standard `WP_List_Table` API. A "Create redirect" link per row → `tools.php?page=openseo-redirects&tab=redirects&source=<url>`.

- [ ] **Step 1: Write the 404 list table**

```php
<?php
/**
 * 404 monitor admin list table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound\Admin;

use OpenSEO\NotFound\LogRepository;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists logged 404s with a "create redirect" action per row.
 */
final class NotFoundListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct( private readonly LogRepository $logs ) {
		parent::__construct(
			array(
				'singular' => 'notfound',
				'plural'   => 'notfounds',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'url'        => __( 'URL', 'openseo' ),
			'hits'       => __( 'Hits', 'openseo' ),
			'last_seen'  => __( 'Last seen', 'openseo' ),
			'first_seen' => __( 'First seen', 'openseo' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$paged  = $this->get_pagenum();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$this->items = $this->logs->all( self::PER_PAGE, $offset );

		$this->set_pagination_args(
			array(
				'total_items' => $this->logs->count_all(),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * @param array<string, mixed> $item   Row.
	 * @param string               $column Column id.
	 */
	public function column_default( $item, $column ): string {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * URL cell with a "create redirect" row action.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_url( $item ): string {
		$create = add_query_arg(
			array(
				'page'   => 'openseo-redirects',
				'tab'    => 'redirects',
				'source' => rawurlencode( (string) $item['url'] ),
			),
			admin_url( 'tools.php' )
		);

		$actions = array(
			'create' => sprintf( '<a href="%s">%s</a>', esc_url( $create ), esc_html__( 'Create redirect', 'openseo' ) ),
		);

		return esc_html( (string) $item['url'] ) . $this->row_actions( $actions );
	}
}
```

- [ ] **Step 2: Fill in the 404 panel template**

Replace `templates/admin/notfound-panel.php` with:

```php
<?php
/**
 * 404 monitor panel (sub-tab of the redirects manager).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// The 404 panel self-constructs its (stateless) collaborators on purpose: this
// keeps the Part A RedirectsPage constructor free of the Part B LogRepository,
// so redirects work even if the 404 monitor is never built.
$openseo_logs  = new \OpenSEO\NotFound\LogRepository();
$openseo_table = new \OpenSEO\NotFound\Admin\NotFoundListTable( $openseo_logs );
$openseo_table->prepare_items();
?>
<h2><?php echo esc_html__( '404 Monitor', 'openseo' ); ?></h2>
<?php if ( '1' !== (string) ( new \OpenSEO\Settings\Options() )->get( 'notfound_monitor_enabled' ) ) : ?>
	<p>
		<?php echo esc_html__( 'The 404 monitor is off.', 'openseo' ); ?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=openseo&tab=redirects' ) ); ?>"><?php echo esc_html__( 'Enable it in Settings → OpenSEO → Redirects.', 'openseo' ); ?></a>
	</p>
<?php endif; ?>
<form method="get">
	<input type="hidden" name="page" value="openseo-redirects" />
	<input type="hidden" name="tab" value="notfound" />
	<?php $openseo_table->display(); ?>
</form>
```

- [ ] **Step 3: Register Monitor + Pruner (and confirm RedirectsPage renders the panel)**

In `src/Plugin.php`, add `use` lines:

```php
use OpenSEO\NotFound\LogRepository;
use OpenSEO\NotFound\Monitor;
use OpenSEO\NotFound\Pruner;
```

Build the log repo near the redirect collaborators:

```php
		$logs = new LogRepository();
```

Add to the front `$modules` array:

```php
			new Monitor( $logs, $options ),
			new Pruner( $logs, $options ),
```

- [ ] **Step 4: Assert Monitor@99 and the prune cron in the boot test**

Add to `tests/Integration/PluginBootTest.php`:

```php
	public function test_monitor_registered_at_priority_99_and_cron_scheduled(): void {
		global $wp_filter;

		$priorities = array_keys( $wp_filter['template_redirect']->callbacks );
		$this->assertContains( 99, $priorities, 'Monitor must run after the Dispatcher.' );
		$this->assertNotFalse( wp_next_scheduled( 'openseo_404_prune' ) );
	}
```

Run: `npm run test:integration -- --filter PluginBootTest`
Expected: PASS.

- [ ] **Step 5: Static analysis + lint**

Run: `composer lint && composer analyze`
Expected: no errors.

- [ ] **Step 6: Manual smoke test**

`npm run env:start`; enable the monitor in **Settings → OpenSEO → Redirects**; visit a non-existent URL (e.g. `/does-not-exist`); open **Tools → OpenSEO Redirects → 404 Monitor** and confirm the row with hits; click **Create redirect** and confirm the source is pre-filled. Disable the monitor and confirm new 404s stop logging.

- [ ] **Step 7: Commit**

```bash
git add src/NotFound/Admin/ templates/admin/notfound-panel.php src/Plugin.php
git commit -m "feat(404): add monitor admin panel and create-from-404"
```

---

## Task 17: Documentation

**Files:**
- Modify: `CLAUDE.md` (architecture section — add `Redirects/`, `NotFound/`, `Lifecycle/Schema`)
- Modify: `NOTES.md` (add a Phase 5 section mirroring the existing phase notes)

- [ ] **Step 1: Update CLAUDE.md**

Add a bullet under "Key modules" describing `Redirects/` (Dispatcher@5, Matcher/Ruleset/Normalizer/Regex pure, Repository, Cache dual-store, SlugWatcher, admin manager under Tools) and `NotFound/` (Monitor@99 opt-in aggregated, Pruner cron, LogRepository upsert), and note `Lifecycle/Schema` creates the first custom tables via `dbDelta` behind a version gate.

- [ ] **Step 2: Update NOTES.md**

Add a "### Redirecciones + 404 (Fase 5)" subsection under section 5 describing: las dos tablas propias, el motor cacheado, el auto-slug on por defecto, el monitor opt-in, y el smoke test manual (crear redirect `/old → /new`, renombrar slug publicado, activar monitor y visitar URL inexistente).

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md NOTES.md
git commit -m "docs: document Phase 5 redirects + 404 monitor"
```

---

## Self-Review (completed by plan author)

**Spec coverage:** redirects engine (Tasks 3–9), cache (8), manager (11), auto-slug (12), 404 monitor (14–16), tables/lifecycle (1, 13), settings (10), docs (17) — every §3–§9 spec item maps to a task. Hook priorities (5/99), deferred `record_hit`, dual-store cache invalidation, prepared upsert, named `dbDelta` indexes, regex controlled delimiter, sanitize-on-store, and the `WP_List_Table` `require_once` are all implemented in the corresponding tasks.

**Placeholder scan:** the only intentional placeholder is `templates/admin/notfound-panel.php` (created empty in Task 11, filled in Task 16) — called out explicitly in both tasks so out-of-order execution is safe.

**Type consistency:** `Redirect`, `MatchResult`, `Ruleset` (`exact`/`regex_rules`/`count`), `Matcher::match`, `Repository` method names/signatures, `Cache` (`get`/`flush`/`is_degraded`), and `LogRepository` names are used identically across producing and consuming tasks.
