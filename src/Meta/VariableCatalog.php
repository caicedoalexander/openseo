<?php
/**
 * Catalog of template variables with metadata for the admin variable inserter.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Meta;

/**
 * Lists the template tokens with a human label, a description, and a scope
 * (global | singular | taxonomy) so the editor UI can offer the right tokens
 * per context. The set of tokens must match what Variables::replace() expands;
 * a unit test enforces that invariant (anti-drift).
 */
final class VariableCatalog {

	/**
	 * All known variables.
	 *
	 * @return array<int, array{token:string, label:string, description:string, scope:string}>
	 */
	public function all(): array {
		return array(
			array(
				'token'       => '%sitename%',
				'label'       => __( 'Site title', 'openseo' ),
				'description' => __( "Your site's name", 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%tagline%',
				'label'       => __( 'Tagline', 'openseo' ),
				'description' => __( "Your site's tagline", 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%sep%',
				'label'       => __( 'Separator', 'openseo' ),
				'description' => __( 'The separator character', 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%currentyear%',
				'label'       => __( 'Current year', 'openseo' ),
				'description' => __( 'The current year', 'openseo' ),
				'scope'       => 'global',
			),
			array(
				'token'       => '%title%',
				'label'       => __( 'Title', 'openseo' ),
				'description' => __( 'The entry title', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%excerpt%',
				'label'       => __( 'Excerpt', 'openseo' ),
				'description' => __( 'The entry excerpt', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%date%',
				'label'       => __( 'Published date', 'openseo' ),
				'description' => __( 'The entry publication date', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%modified%',
				'label'       => __( 'Modified date', 'openseo' ),
				'description' => __( 'The entry last-modified date', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%author%',
				'label'       => __( 'Author', 'openseo' ),
				'description' => __( "The entry author's name", 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%category%',
				'label'       => __( 'Category', 'openseo' ),
				'description' => __( 'The first category of the entry', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%tag%',
				'label'       => __( 'Tag', 'openseo' ),
				'description' => __( 'The first tag of the entry', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%parent_title%',
				'label'       => __( 'Parent title', 'openseo' ),
				'description' => __( 'The title of the parent entry', 'openseo' ),
				'scope'       => 'singular',
			),
			array(
				'token'       => '%term%',
				'label'       => __( 'Term name', 'openseo' ),
				'description' => __( 'The taxonomy term name', 'openseo' ),
				'scope'       => 'taxonomy',
			),
			array(
				'token'       => '%term_description%',
				'label'       => __( 'Term description', 'openseo' ),
				'description' => __( 'The taxonomy term description', 'openseo' ),
				'scope'       => 'taxonomy',
			),
		);
	}
}
