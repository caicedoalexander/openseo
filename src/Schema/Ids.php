<?php
/**
 * Stable @id values for the JSON-LD graph.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Schema;

/**
 * Single source of truth for every node @id, so pieces can reference each other
 * without coupling to sibling classes.
 */
final class Ids {

	/**
	 * Get the @id of the site-wide WebSite node.
	 */
	public static function website(): string {
		return home_url( '/#website' );
	}

	/**
	 * Get the @id of the Organization identity node.
	 */
	public static function organization(): string {
		return home_url( '/#organization' );
	}

	/**
	 * Get the @id of the Person identity node.
	 */
	public static function person(): string {
		return home_url( '/#person' );
	}

	/**
	 * Get the @id of the WebPage node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function webpage( string $url ): string {
		return $url . '#webpage';
	}

	/**
	 * Get the @id of the Article node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function article( string $url ): string {
		return $url . '#article';
	}

	/**
	 * Get the @id of the BreadcrumbList node for a given URL.
	 *
	 * @param string $url Canonical URL of the page.
	 */
	public static function breadcrumb( string $url ): string {
		return $url . '#breadcrumb';
	}

	/**
	 * Get the canonical URL of the current request (home on the front page).
	 */
	public static function current_url(): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}

		return (string) get_permalink( get_queried_object_id() );
	}
}
