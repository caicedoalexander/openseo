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

	/**
	 * Constructor.
	 *
	 * @param LogRepository $logs    404 log repository.
	 * @param Options       $options Plugin settings.
	 */
	public function __construct(
		private readonly LogRepository $logs,
		private readonly Options $options,
	) {}

	/**
	 * Register WordPress hooks.
	 */
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
