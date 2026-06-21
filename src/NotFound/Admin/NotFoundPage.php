<?php
/**
 * 404s page (OpenSEO → 404s). Submenu registered by Admin\Menu.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound\Admin;

use OpenSEO\NotFound\LogRepository;
use OpenSEO\Settings\BehaviorSettings;
use OpenSEO\Settings\Options;

/**
 * Renders the 404 monitor toggle form and the logged-404 list table.
 */
final class NotFoundPage {

	private const CAP = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param LogRepository    $log      404 log data access.
	 * @param Options          $options  Settings (reads the monitor toggle).
	 * @param BehaviorSettings $behavior Renders the 404 toggle form.
	 */
	public function __construct(
		private readonly LogRepository $log,
		private readonly Options $options,
		private readonly BehaviorSettings $behavior,
	) {}

	/**
	 * Render the 404s page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$openseo_options        = $this->options;
		$openseo_behavior       = $this->behavior;
		$openseo_notfound_table = new NotFoundListTable( $this->log );
		$openseo_notfound_table->prepare_items();

		require OPENSEO_PLUGIN_DIR . 'templates/admin/notfound-page.php';
	}
}
