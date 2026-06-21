<?php
/**
 * One-time settings migrations gated by a version option.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Lifecycle;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;

/**
 * Migrates legacy settings shapes. Runs on `init` (front + admin) so the
 * front-end never serves a default where a customized value should be, gated by
 * an option separate from the table schema version.
 */
final class SettingsMigrations implements Hookable {

	public const VERSION = '1';

	public const VERSION_OPTION = 'openseo_settings_version';

	private const OLD_TITLE_DEFAULT = '%title% %sep% %sitename%';

	private const OLD_DESCRIPTION_DEFAULT = '%excerpt%';

	/**
	 * Hook the migration runner.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_migrate' ) );
	}

	/**
	 * Run pending migrations once, then mark the version.
	 */
	public function maybe_migrate(): void {
		if ( (string) get_option( self::VERSION_OPTION, '' ) === self::VERSION ) {
			return;
		}

		$stored = get_option( Options::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$migrated = self::migrate_array( $stored );

		if ( $migrated !== $stored ) {
			update_option( Options::OPTION_KEY, $migrated );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Pure transform: copy a customized global template to post/page, drop the
	 * legacy keys. Idempotent. No WordPress calls so it is unit-testable.
	 *
	 * @param array<string, mixed> $stored Raw stored settings array.
	 * @return array<string, mixed>
	 */
	public static function migrate_array( array $stored ): array {
		$title = isset( $stored['title_template'] ) ? (string) $stored['title_template'] : '';
		if ( '' !== $title && self::OLD_TITLE_DEFAULT !== $title ) {
			$stored['post_types']['post']['title'] = $title;
			$stored['post_types']['page']['title'] = $title;
		}

		$description = isset( $stored['description_template'] ) ? (string) $stored['description_template'] : '';
		if ( '' !== $description && self::OLD_DESCRIPTION_DEFAULT !== $description ) {
			$stored['post_types']['post']['description'] = $description;
			$stored['post_types']['page']['description'] = $description;
		}

		unset( $stored['title_template'], $stored['description_template'] );

		return $stored;
	}
}
