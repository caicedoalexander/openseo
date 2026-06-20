<?php
/**
 * Redirects admin list table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects\Admin;

use OpenSEO\Redirects\Repository;
use WP_List_Table;

// WP_List_Table is a private core class not always autoloaded; require it.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists redirect rules with search, pagination, and row actions.
 */
final class RedirectsListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param Repository $repo Redirect rule repository.
	 */
	public function __construct( private readonly Repository $repo ) {
		parent::__construct(
			array(
				'singular' => 'redirect',
				'plural'   => 'redirects',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'source_path'   => __( 'Source', 'openseo' ),
			'target'        => __( 'Target', 'openseo' ),
			'status_code'   => __( 'Type', 'openseo' ),
			'enabled'       => __( 'Status', 'openseo' ),
			'hits'          => __( 'Hits', 'openseo' ),
			'last_accessed' => __( 'Last used', 'openseo' ),
		);
	}

	/**
	 * Build the items from the repository.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging/search.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$paged  = $this->get_pagenum();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$this->items = $this->repo->all( self::PER_PAGE, $offset, $search );

		$this->set_pagination_args(
			array(
				'total_items' => $this->repo->count_all( $search ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Default escaped cell renderer.
	 *
	 * @param array<string, mixed> $item   Row.
	 * @param string               $column Column id.
	 */
	public function column_default( $item, $column ): string {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * Source cell with edit/delete/toggle row actions.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_source_path( $item ): string {
		$id          = (int) $item['id'];
		$action_base = admin_url( 'admin-post.php?action=openseo_redirect_row_action' );

		// Toggle/delete hit admin-post.php; edit-in-place is a documented follow-up,
		// so no "Edit" action is shown (no dead links).
		$toggle     = 1 === (int) $item['enabled'] ? 'disable' : 'enable';
		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'do' => $toggle,
					'id' => $id,
				),
				$action_base
			),
			'openseo_redirect_' . $toggle . '_' . $id
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'do' => 'delete',
					'id' => $id,
				),
				$action_base
			),
			'openseo_redirect_delete_' . $id
		);

		$actions = array(
			'toggle' => sprintf( '<a href="%s">%s</a>', esc_url( $toggle_url ), 'disable' === $toggle ? esc_html__( 'Disable', 'openseo' ) : esc_html__( 'Enable', 'openseo' ) ),
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'%s\')">%s</a>', esc_url( $delete_url ), esc_js( __( 'Delete this redirect?', 'openseo' ) ), esc_html__( 'Delete', 'openseo' ) ),
		);

		$source = 1 === (int) $item['is_regex']
			? esc_html( $item['source_path'] ) . ' <em>' . esc_html__( 'regex', 'openseo' ) . '</em>'
			: esc_html( $item['source_path'] );

		return $source . $this->row_actions( $actions );
	}

	/**
	 * Status badge.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_enabled( $item ): string {
		return 1 === (int) $item['enabled']
			? esc_html__( 'Enabled', 'openseo' )
			: esc_html__( 'Disabled', 'openseo' );
	}
}
