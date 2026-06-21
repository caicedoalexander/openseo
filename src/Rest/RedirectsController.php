<?php
/**
 * REST controller for redirect rules (CRUD + bulk).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Rest;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\RuleValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes /openseo/v1/redirects. Writes flow through RuleValidator and flush the
 * ruleset cache. Auth: manage_options; nonce via apiFetch's X-WP-Nonce middleware.
 */
final class RedirectsController implements Hookable {

	public const REST_NAMESPACE = 'openseo/v1';

	/**
	 * Constructor.
	 *
	 * @param Repository    $repo      Redirect rule repository.
	 * @param Cache         $cache     Redirect ruleset cache.
	 * @param RuleValidator $validator Shared create/update validator.
	 */
	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly RuleValidator $validator,
	) {}

	/**
	 * Register the REST routes on rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the collection, bulk, and single-item routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects',
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
						'search'   => array(
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/redirects/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
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
	 * GET /redirects — paginated, optionally searched list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );
		$search   = (string) $request['search'];
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'items' => $this->repo->all( $per_page, $offset, $search ),
				'total' => $this->repo->count_all( $search ),
			),
			200
		);
	}

	/**
	 * POST /redirects — create a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data  = array(
			'source_path' => $request->get_param( 'source_path' ),
			'target'      => $request->get_param( 'target' ),
			'status_code' => $request->get_param( 'status_code' ),
			'is_regex'    => $request->get_param( 'is_regex' ),
		);
		$clean = $this->validator->validate( $data, 0 );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$id = $this->repo->create( $clean );
		if ( 0 === $id ) {
			return new WP_Error( 'openseo_create_failed', __( 'Could not save the redirect.', 'openseo' ), array( 'status' => 500 ) );
		}
		$this->cache->flush();

		return new WP_REST_Response( $this->repo->find( $id ), 201 );
	}

	/**
	 * PUT /redirects/<id> — edit a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( null === $this->repo->find( $id ) ) {
			return new WP_Error( 'openseo_not_found', __( 'Redirect not found.', 'openseo' ), array( 'status' => 404 ) );
		}

		$data  = array(
			'source_path' => $request->get_param( 'source_path' ),
			'target'      => $request->get_param( 'target' ),
			'status_code' => $request->get_param( 'status_code' ),
			'is_regex'    => $request->get_param( 'is_regex' ),
			'enabled'     => $request->get_param( 'enabled' ),
		);
		$clean = $this->validator->validate( $data, $id );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$this->repo->update( $id, $clean );
		$this->cache->flush();

		return new WP_REST_Response( $this->repo->find( $id ), 200 );
	}

	/**
	 * DELETE /redirects/<id> — delete a rule.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$this->repo->delete( (int) $request['id'] );
		$this->cache->flush();

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * POST /redirects/bulk — enable/disable/delete a set of ids (one flush).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action  = (string) ( $request->get_param( 'action' ) ?? '' );
		$raw_ids = $request->get_param( 'ids' );
		$ids     = is_array( $raw_ids ) ? array_values( array_filter( array_map( 'absint', $raw_ids ) ) ) : array();

		if ( ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) || array() === $ids ) {
			return new WP_Error( 'openseo_invalid', __( 'Invalid bulk action.', 'openseo' ), array( 'status' => 400 ) );
		}

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$this->repo->delete( $id );
			} else {
				$this->repo->set_enabled( $id, 'enable' === $action );
			}
		}
		$this->cache->flush();

		return new WP_REST_Response( array( 'affected' => count( $ids ) ), 200 );
	}
}
