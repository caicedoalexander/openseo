<?php
/**
 * Creates a 301 when a published entry's permalink changes.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

namespace OpenSEO\Redirects;

use OpenSEO\Contracts\Hookable;
use OpenSEO\Settings\Options;
use WP_Post;

/**
 * Captures the old permalink before an update and, if a published entry's
 * permalink actually changed, records an automatic 301. The decision is a pure
 * method so its many guards are unit-testable without WordPress.
 */
final class SlugWatcher implements Hookable {

	/**
	 * Old normalized paths captured before update, keyed by post id.
	 *
	 * @var array<int, string>
	 */
	private array $old_paths = array();

	/**
	 * Constructor.
	 *
	 * @param Repository $repo    Redirect rule repository.
	 * @param Cache      $cache   Redirect rule cache.
	 * @param Options    $options Plugin options.
	 */
	public function __construct(
		private readonly Repository $repo,
		private readonly Cache $cache,
		private readonly Options $options,
	) {}

	/**
	 * Wire WordPress hooks.
	 */
	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'capture_old' ), 10, 1 );
		add_action( 'post_updated', array( $this, 'maybe_create' ), 10, 3 );
	}

	/**
	 * Snapshot the current (about-to-be-old) permalink path.
	 *
	 * @param int $post_id Post being updated.
	 */
	public function capture_old( int $post_id ): void {
		$this->old_paths[ $post_id ] = $this->path_of( get_permalink( $post_id ) );
	}

	/**
	 * On update, create a 301 if the decision passes.
	 *
	 * @param int     $post_id     Post id.
	 * @param WP_Post $post_after  Updated post.
	 * @param WP_Post $post_before Pre-update post.
	 */
	public function maybe_create( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		$old = $this->old_paths[ $post_id ] ?? '';
		$new = $this->path_of( get_permalink( $post_id ) );

		$decide = $this->should_create(
			$old,
			$new,
			(string) $post_before->post_status,
			'1' === (string) $this->options->get( 'redirects_auto_slug' ),
			(bool) wp_is_post_revision( $post_id ),
			defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE,
		);

		if ( ! $decide || $this->repo->exists_for_source( $old ) ) {
			return;
		}

		$this->repo->create(
			array(
				'source_path' => $old,
				'target'      => $new,
				'status_code' => 301,
				'is_regex'    => false,
				'enabled'     => true,
			)
		);
		$this->cache->flush();
	}

	/**
	 * Pure decision: should we create an auto-redirect?
	 *
	 * @param string $old_path      Normalized path before the update.
	 * @param string $new_path      Normalized path after the update.
	 * @param string $before_status Post status before the update.
	 * @param bool   $enabled       Whether auto-slug redirects are enabled.
	 * @param bool   $is_revision   Whether the post id belongs to a revision.
	 * @param bool   $is_autosave   Whether DOING_AUTOSAVE is set.
	 */
	public function should_create(
		string $old_path,
		string $new_path,
		string $before_status,
		bool $enabled,
		bool $is_revision,
		bool $is_autosave
	): bool {
		if ( ! $enabled || $is_revision || $is_autosave ) {
			return false;
		}
		if ( 'publish' !== $before_status ) {
			return false;
		}
		if ( '' === $old_path || '' === $new_path || $old_path === $new_path ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize a permalink to a comparable path.
	 *
	 * @param string|false $permalink Permalink URL.
	 */
	private function path_of( $permalink ): string {
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? rtrim( $home, '/' ) : '';

		return ( new Normalizer( $home ) )->normalize( is_string( $path ) ? $path : '' );
	}
}
