<?php
/**
 * Redirects manager page (Tools → OpenSEO Redirects).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects\Admin;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Normalizer;
use OpenSEO\Redirects\Regex;
use OpenSEO\Redirects\Repository;
use OpenSEO\Settings\Options;

/**
 * Registers the Tools page, handles CRUD form submissions (nonce + capability),
 * and renders the redirects sub-tab.
 */
final class RedirectsPage implements Hookable {

	private const SLUG = 'openseo-redirects';

	private const CAP = 'manage_options';

	/**
	 * Constructor.
	 *
	 * @param Repository $repo    Redirect rule repository.
	 * @param Cache      $cache   Redirect ruleset cache.
	 * @param Options    $options Plugin settings.
	 */
	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly Options $options,
	) {}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_openseo_save_redirect', array( $this, 'handle_save' ) );
		add_action( 'admin_post_openseo_redirect_row_action', array( $this, 'handle_row_action' ) );
	}

	/**
	 * Register the Tools → OpenSEO Redirects menu page.
	 */
	public function add_page(): void {
		add_management_page(
			__( 'OpenSEO Redirects', 'openseo' ),
			__( 'OpenSEO Redirects', 'openseo' ),
			self::CAP,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the add/edit form POST.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'openseo' ) );
		}
		check_admin_referer( 'openseo_save_redirect' );

		$id       = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$is_regex = ! empty( $_POST['is_regex'] );
		$source   = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target   = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ), array( 'http', 'https' ) ) : '';
		$relative = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$status   = isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301;

		// Relative targets fail esc_url_raw; keep the sanitized relative path.
		if ( '' === $target && '' !== $relative ) {
			$target = $relative;
		}

		// Normalize exact sources; validate regex sources.
		if ( $is_regex ) {
			if ( ! Regex::is_valid( $source ) ) {
				$this->redirect_back( 'invalid_regex' );
			}
		} else {
			$source = ( new Normalizer() )->normalize( $source );
		}

		if ( '' === $source || ! in_array( $status, array( 301, 302, 307, 410 ), true ) ) {
			$this->redirect_back( 'invalid' );
		}
		if ( 410 !== $status && '' === $target ) {
			$this->redirect_back( 'invalid' );
		}

		// Reject a direct 2-rule cycle (source → target where target → source
		// already exists): the browser would bounce between them forever.
		if ( ! $is_regex && $this->creates_cycle( $id, $source, $target ) ) {
			$this->redirect_back( 'cycle' );
		}

		$data = array(
			'source_path' => $source,
			'target'      => 410 === $status ? '' : $target,
			'status_code' => $status,
			'is_regex'    => $is_regex,
			'enabled'     => true,
		);

		if ( $id > 0 ) {
			$this->repo->update( $id, $data );
		} else {
			$this->repo->create( $data );
		}

		$this->cache->flush();
		$this->redirect_back( 'saved' );
	}

	/**
	 * Handle enable/disable/delete row actions (GET with per-row nonce).
	 */
	public function handle_row_action(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'openseo' ) );
		}

		$action = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! in_array( $action, array( 'enable', 'disable', 'delete' ), true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'openseo' ) );
		}

		check_admin_referer( 'openseo_redirect_' . $action . '_' . $id );

		if ( 'delete' === $action ) {
			$this->repo->delete( $id );
		} elseif ( 'enable' === $action ) {
			$this->repo->set_enabled( $id, true );
		} elseif ( 'disable' === $action ) {
			$this->repo->set_enabled( $id, false );
		}

		$this->cache->flush();
		$this->redirect_back( $action );
	}

	/**
	 * Render the page (sub-tabs + active panel).
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'redirects';

		// Pre-fill source from a 404 "create redirect" link (re-normalized, never trusted).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET prefill only; the save POST is nonce-protected.
		$prefill = isset( $_GET['source'] ) ? ( new Normalizer() )->normalize( sanitize_text_field( wp_unslash( $_GET['source'] ) ) ) : '';

		// Inject the page's collaborators into the template (no `new` in the view).
		$openseo_repo    = $this->repo;
		$openseo_options = $this->options;

		require OPENSEO_PLUGIN_DIR . 'templates/admin/redirects-page.php';
	}

	/**
	 * Whether saving an exact internal rule (source → target) would form a direct
	 * 2-rule cycle with an existing active rule (target → source).
	 *
	 * Only internal, root-relative targets can cycle; the existing rule's target
	 * is normalized before comparing so a trailing-slash-only difference still
	 * counts. $source is already normalized by the caller.
	 *
	 * @param int    $id     Row id being saved (0 for a new rule), excluded from the lookup.
	 * @param string $source Normalized source path of the rule being saved.
	 * @param string $target Target of the rule being saved (stored verbatim).
	 */
	private function creates_cycle( int $id, string $source, string $target ): bool {
		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) ) {
			return false; // External / protocol-relative target cannot cycle internally.
		}

		$normalizer = new Normalizer();
		$back       = $this->repo->find_active_by_source( $normalizer->normalize( $target ) );

		if ( null === $back || $back->id === $id ) {
			return false;
		}

		return $normalizer->normalize( $back->target ) === $source;
	}

	/**
	 * Redirect back to the manager with a status flag.
	 *
	 * @param string $flag Query-string status flag appended to the redirect URL.
	 * @return never
	 */
	private function redirect_back( string $flag ): never {
		wp_safe_redirect( add_query_arg( 'openseo_msg', $flag, admin_url( 'tools.php?page=' . self::SLUG ) ) );
		exit;
	}
}
