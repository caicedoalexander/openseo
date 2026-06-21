<?php
/**
 * 404 monitor admin list table.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\NotFound\Admin;

use OpenSEO\NotFound\LogRepository;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists logged 404s with a "create redirect" action per row.
 */
final class NotFoundListTable extends WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Initialise the list table with its data source.
	 *
	 * @param LogRepository $logs 404 log data access object.
	 */
	public function __construct( private readonly LogRepository $logs ) {
		parent::__construct(
			array(
				'singular' => 'notfound',
				'plural'   => 'notfounds',
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
			'url'        => __( 'URL', 'openseo' ),
			'hits'       => __( 'Hits', 'openseo' ),
			'last_seen'  => __( 'Last seen', 'openseo' ),
			'first_seen' => __( 'First seen', 'openseo' ),
		);
	}

	/**
	 * Fetch rows and configure pagination.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$paged  = $this->get_pagenum();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$this->items = $this->logs->all( self::PER_PAGE, $offset );

		$this->set_pagination_args(
			array(
				'total_items' => $this->logs->count_all(),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Render any column that has no dedicated handler.
	 *
	 * @param array<string, mixed> $item   Row.
	 * @param string               $column Column id.
	 */
	public function column_default( $item, $column ): string {
		return esc_html( (string) ( $item[ $column ] ?? '' ) );
	}

	/**
	 * URL cell with a "create redirect" row action.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function column_url( $item ): string {
		$create = add_query_arg(
			array(
				'page'   => 'openseo-redirects',
				'source' => rawurlencode( (string) $item['url'] ),
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'create' => sprintf( '<a href="%s">%s</a>', esc_url( $create ), esc_html__( 'Create redirect', 'openseo' ) ),
		);

		return esc_html( (string) $item['url'] ) . $this->row_actions( $actions );
	}
}
