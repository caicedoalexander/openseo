<?php
/**
 * Immutable rendering context for template variables.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

use WP_Term;

/**
 * Carries the primitives a template needs (title, excerpt, term name/description,
 * date, modified date, author, primary category/tag, parent title, author name,
 * search query, and page label)
 * without retaining WP_Post/WP_Term, so Variables stays pure and unit-testable.
 * All WordPress reads happen in the factories.
 */
final class TemplateContext {

	/**
	 * Creates an immutable context with the given primitives.
	 *
	 * @param int    $post_id          Post ID (0 for non-post contexts).
	 * @param string $title            Post title.
	 * @param string $excerpt          Post excerpt.
	 * @param string $term_name        Term name.
	 * @param string $term_description Term description.
	 * @param string $date             Published date.
	 * @param string $modified         Last-modified date.
	 * @param string $author           Post author display name.
	 * @param string $category         Primary category name.
	 * @param string $tag              Primary tag name.
	 * @param string $parent_title     Parent entry title.
	 * @param string $name            Author display name (author archives).
	 * @param string $search_query    Raw search term (search results pages).
	 * @param string $page            "Page X of Y" label (paginated contexts).
	 */
	private function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $term_name,
		public readonly string $term_description,
		public readonly string $date = '',
		public readonly string $modified = '',
		public readonly string $author = '',
		public readonly string $category = '',
		public readonly string $tag = '',
		public readonly string $parent_title = '',
		public readonly string $name = '',
		public readonly string $search_query = '',
		public readonly string $page = '',
	) {}

	/**
	 * Context for a singular post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function for_post( int $post_id ): self {
		$categories = get_the_category( $post_id );
		$tags       = get_the_tags( $post_id );
		$parent     = (int) wp_get_post_parent_id( $post_id );

		// get_post_field stub returns int|string|int[]; is_scalar narrows away
		// the array branch so the (int) cast is PHPStan-level-6 clean (M3).
		$author    = get_post_field( 'post_author', $post_id );
		$author_id = is_scalar( $author ) ? (int) $author : 0;

		return new self(
			$post_id,
			(string) get_the_title( $post_id ),
			wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			'',
			'',
			(string) get_the_date( '', $post_id ),
			(string) get_the_modified_date( '', $post_id ),
			(string) get_the_author_meta( 'display_name', $author_id ),
			isset( $categories[0] ) ? (string) $categories[0]->name : '',
			is_array( $tags ) && isset( $tags[0] ) ? (string) $tags[0]->name : '',
			$parent > 0 ? (string) get_the_title( $parent ) : '',
		);
	}

	/**
	 * Context for a taxonomy term archive.
	 *
	 * @param WP_Term $term Queried term.
	 */
	public static function for_term( WP_Term $term ): self {
		return new self(
			0,
			'',
			'',
			(string) $term->name,
			wp_strip_all_tags( (string) $term->description ),
		);
	}

	/**
	 * Empty context (no post, no term).
	 */
	public static function none(): self {
		return new self( 0, '', '', '', '' );
	}

	/**
	 * Context for an author archive.
	 *
	 * @param int $author_id Queried author ID.
	 */
	public static function for_author( int $author_id ): self {
		return new self(
			0,
			'',
			'',
			'',
			'',
			name: (string) get_the_author_meta( 'display_name', $author_id ),
			page: self::current_page_label(),
		);
	}

	/**
	 * Context for a search results page. The query stays RAW; the Title
	 * presenter escapes it (the document <title> is not escaped by core).
	 */
	public static function for_search(): self {
		return new self(
			0,
			'',
			'',
			'',
			'',
			search_query: (string) get_search_query( false ),
			page: self::current_page_label(),
		);
	}

	/**
	 * Context for a paginated archive / posts homepage (only %page% applies).
	 */
	public static function for_archive(): self {
		return new self( 0, '', '', '', '', page: self::current_page_label() );
	}

	/**
	 * "Page X of Y" for a paginated request, or '' when on page 1 / unpaginated.
	 * Reads the archive page ('paged') first, then the in-post page ('page').
	 */
	private static function current_page_label(): string {
		$paged = (int) get_query_var( 'paged' );
		if ( 0 === $paged ) {
			$paged = (int) get_query_var( 'page' );
		}
		if ( $paged < 2 ) {
			return '';
		}

		$total = 0;
		if ( isset( $GLOBALS['wp_query'] ) && is_object( $GLOBALS['wp_query'] ) && isset( $GLOBALS['wp_query']->max_num_pages ) ) {
			$total = (int) $GLOBALS['wp_query']->max_num_pages;
		}

		if ( $total > 1 ) {
			/* translators: 1: current page number, 2: total pages. */
			return sprintf( __( 'Page %1$d of %2$d', 'openseo' ), $paged, $total );
		}

		/* translators: %d: current page number. */
		return sprintf( __( 'Page %d', 'openseo' ), $paged );
	}
}
