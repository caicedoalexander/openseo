<?php
/**
 * Customizes WordPress core's native XML sitemap.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Sitemap;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Tunes the native WP_Sitemaps output through core's filters: a master on/off
 * switch, author-sitemap removal, and exclusion of noindexed entries. OpenSEO
 * never prints its own XML — core handles rendering, pagination, and escaping.
 */
final class Sitemap implements Hookable {

	/**
	 * Per-entry meta key set when an entry is marked noindex.
	 */
	private const NOINDEX_META_KEY = '_openseo_robots_noindex';

	/**
	 * Initialize the module with the settings accessor.
	 *
	 * @param Options $options Settings accessor.
	 */
	public function __construct( private readonly Options $options ) {}

	/**
	 * Hook OpenSEO's adjustments onto core's sitemap filters.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_enabled', array( $this, 'is_enabled' ) );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'exclude_noindex' ) );
	}

	/**
	 * Force the sitemap off when the master toggle is disabled.
	 *
	 * Loosely typed: WordPress passes whatever the previous filter returned, so a
	 * strict scalar hint could fatal under strict_types.
	 *
	 * @param mixed $core_enabled Whether core currently considers sitemaps enabled.
	 */
	public function is_enabled( $core_enabled ): bool {
		if ( '1' !== (string) $this->options->get( 'sitemap_enabled' ) ) {
			return false;
		}

		return (bool) $core_enabled;
	}

	/**
	 * Drop the authors ("users") provider unless the setting opts in.
	 *
	 * @param mixed $provider Provider instance core is about to register.
	 * @param mixed $name     Provider name ("posts" | "taxonomies" | "users").
	 * @return mixed The provider, or false to skip registering it.
	 */
	public function filter_provider( $provider, $name ) {
		if ( 'users' === $name && '1' !== (string) $this->options->get( 'sitemap_include_authors' ) ) {
			return false;
		}

		return $provider;
	}

	/**
	 * Exclude noindexed entries from the posts sub-sitemap.
	 *
	 * The OR clause keeps entries WITHOUT the meta (the majority) and entries
	 * whose value is not exactly '1'; only '1' is excluded. Any meta_query already
	 * present is preserved under an AND relation.
	 *
	 * @param mixed $args WP_Query args core will run for the post type.
	 * @return array<string, mixed> Args with the noindex exclusion merged in.
	 */
	public function exclude_noindex( $args ): array {
		$args = is_array( $args ) ? $args : array();

		$exclusion = array(
			'relation' => 'OR',
			array(
				'key'     => self::NOINDEX_META_KEY,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::NOINDEX_META_KEY,
				'value'   => '1',
				'compare' => '!=',
			),
		);

		if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = array(
				'relation' => 'AND',
				$args['meta_query'],
				$exclusion,
			);
		} else {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $exclusion;
		}

		return $args;
	}
}
