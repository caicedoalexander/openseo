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
	 *
	 * @var int
	 */
	private int $pending_hit = 0;

	/**
	 * Request path normalizer, built once per request (home_path is stable).
	 *
	 * @var Normalizer|null
	 */
	private ?Normalizer $normalizer = null;

	/**
	 * Constructor.
	 *
	 * @param Cache      $cache   Ruleset cache.
	 * @param Matcher    $matcher Ruleset matcher.
	 * @param Repository $repo    Redirect repository.
	 * @param Options    $options Plugin settings.
	 */
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

		// REQUEST_URI is used only for matching: resolve() normalizes it
		// (rawurldecode + slash/query handling) and any emitted target is escaped
		// at output (esc_url_raw / wp_safe_redirect). sanitize_text_field() would
		// corrupt valid paths (strip tags, collapse whitespace, decode entities)
		// without adding safety here, so only unslash.
		$raw_uri = isset( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized in resolve(); never echoed raw.
			: '';

		$result = $this->resolve( is_string( $raw_uri ) ? $raw_uri : '' );
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
	 *
	 * @param string $request_uri Raw request URI (may include query string).
	 */
	public function resolve( string $request_uri ): ?MatchResult {
		$this->normalizer ??= new Normalizer( $this->home_path() );
		$path               = $this->normalizer->normalize( $request_uri );

		if ( $this->cache->is_degraded() ) {
			// Exact rules win and are O(1) via an indexed lookup (mirrors the
			// Matcher: an exact self-loop means "no redirect", not "fall through").
			$rule = $this->repo->find_active_by_source( $path );
			if ( null !== $rule ) {
				return $this->matcher->is_self_loop( $rule->target, $path )
					? null
					: new MatchResult( $rule->id, $rule->target, $rule->status );
			}

			// No exact match: regex rules (normally few) are still evaluated so
			// they are not silently dropped above the threshold.
			return $this->matcher->match( $this->repo->find_active_regex_ruleset(), $path );
		}

		return $this->matcher->match( $this->cache->get(), $path );
	}

	/**
	 * Defer the hit write to shutdown so it never adds latency before the redirect.
	 *
	 * @param int $id Rule id to record.
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
	 *
	 * @param string $target Redirect target to inspect.
	 */
	private function is_external( string $target ): bool {
		$host = wp_parse_url( $target, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false; // Relative target → internal.
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return ! is_string( $home_host ) || $host !== $home_host;
	}
}
