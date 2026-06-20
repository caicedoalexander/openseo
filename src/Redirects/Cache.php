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

	/**
	 * Constructor.
	 *
	 * @param Repository $repo Redirect rule repository.
	 */
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
