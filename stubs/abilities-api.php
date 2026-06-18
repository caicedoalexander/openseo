<?php
/**
 * Static-analysis stubs for the WordPress 7.0 Abilities API.
 *
 * Used by PHPStan / IDEs when the installed wordpress-stubs package predates
 * WordPress 7.0. Not loaded at runtime.
 *
 * @see https://developer.wordpress.org/apis/abilities-api/
 *
 * @package OpenSEO
 */

if ( false ) {
	/**
	 * Register an ability.
	 *
	 * @param string               $name Ability name (namespace/ability-name).
	 * @param array<string, mixed> $args Ability definition.
	 * @return mixed The registered ability object, or null on failure.
	 */
	function wp_register_ability( string $name, array $args ) {}

	/**
	 * Register an ability category.
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Category definition.
	 * @return mixed The registered category, or null on failure.
	 */
	function wp_register_ability_category( string $slug, array $args ) {}
}
