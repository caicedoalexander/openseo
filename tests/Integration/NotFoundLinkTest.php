<?php
/**
 * Integration tests: 404 → create-redirect link points under the OpenSEO menu.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\NotFound\Admin\NotFoundListTable;
use OpenSEO\NotFound\LogRepository;
use WP_UnitTestCase;

final class NotFoundLinkTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// WP_List_Table::__construct() calls get_current_screen(); give it one.
		set_current_screen( 'admin.php' );
	}

	public function test_create_redirect_link_uses_admin_php_without_tab(): void {
		$table = new NotFoundListTable( new LogRepository() );

		$html = $table->column_url( array( 'url' => '/missing-page' ) );

		$this->assertStringContainsString( 'admin.php?page=openseo-redirects', $html );
		$this->assertStringContainsString( 'source=', $html );
		$this->assertStringNotContainsString( 'tools.php', $html );
		$this->assertStringNotContainsString( 'tab=', $html );
	}
}
