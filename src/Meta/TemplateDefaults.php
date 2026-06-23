<?php
/**
 * Per-surface default title/description templates.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Pure source of the default templates used when a content type or taxonomy
 * has no stored template. No WordPress dependency, so it is unit-testable and
 * is the single source of truth shared by the Resolver and the admin bootstrap.
 */
final class TemplateDefaults {

	/**
	 * Default title template for singular content (posts, pages, CPTs).
	 */
	public function singular_title(): string {
		return '%title% %sep% %sitename%';
	}

	/**
	 * Default description template for singular content.
	 */
	public function singular_description(): string {
		return '%excerpt%';
	}

	/**
	 * Default title template for taxonomy term archives.
	 */
	public function taxonomy_title(): string {
		return '%term% %sep% %sitename%';
	}

	/**
	 * Default description template for taxonomy term archives.
	 */
	public function taxonomy_description(): string {
		return '%term_description%';
	}

	/**
	 * Default title template for author archives.
	 */
	public function author_title(): string {
		return '%name% %sep% %sitename%';
	}

	/**
	 * Default title template for search results.
	 */
	public function search_title(): string {
		return '%search_query% %sep% %sitename%';
	}

	/**
	 * Default title template for 404 pages.
	 */
	public function not_found_title(): string {
		return 'Page Not Found %sep% %sitename%';
	}
}
