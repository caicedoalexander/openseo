<?php
declare( strict_types=1 );

namespace OpenSEO\Tests\Integration;

use OpenSEO\Lifecycle\Schema;
use OpenSEO\Redirects\Cache;
use OpenSEO\Redirects\Repository;
use OpenSEO\Redirects\SlugWatcher;
use OpenSEO\Settings\Options;
use WP_UnitTestCase;

final class SlugWatcherTest extends WP_UnitTestCase {

	public function test_renaming_published_post_creates_redirect(): void {
		Schema::install();
		update_option( 'openseo_settings', array( 'redirects_auto_slug' => '1' ) );

		// The test bench starts with plain permalinks (?p=N); without a pretty
		// structure, old and new permalinks both normalize to '/' and no
		// redirect is ever created. set_permalink_structure() flushes rules.
		$this->set_permalink_structure( '/%postname%/' );

		$repo    = new Repository();
		$watcher = new SlugWatcher( $repo, new Cache( $repo ), new Options() );
		$watcher->register();

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'original-slug',
				'post_title'  => 'Original',
			)
		);

		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'renamed-slug',
			)
		);

		$this->assertTrue( $repo->exists_for_source( '/original-slug' ) );
	}
}
