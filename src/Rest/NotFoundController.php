<?php
/**
 * REST controller for the 404 log (list, delete one, clear all).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\NotFound\LogRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes /openseo/v1/notfound. Auth: manage_options; nonce via apiFetch.
 */
final class NotFoundController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	/**
	 * Constructor.
	 *
	 * @param LogRepository $log 404 log data access.
	 */
	public function __construct( private readonly LogRepository $log ) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the collection (list/clear) and single-item (delete) routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/notfound',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/notfound/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * Capability gate.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /notfound — paginated list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'items' => $this->log->all( $per_page, $offset ),
				'total' => $this->log->count_all(),
			),
			200
		);
	}

	/**
	 * DELETE /notfound/<id> — delete one logged URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$this->log->delete( (int) $request['id'] );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * DELETE /notfound — clear the whole log.
	 */
	public function clear(): WP_REST_Response {
		$this->log->clear();

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}
}
