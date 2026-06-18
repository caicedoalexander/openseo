<?php
/**
 * Bootstrap for WordPress integration tests.
 *
 * Loads the WordPress test suite and the plugin. The test suite path is read
 * from WP_TESTS_DIR; under wp-env it is provided automatically by
 * `wp-env run tests-cli ...`.
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

$openseo_composer = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_readable( $openseo_composer ) ) {
	require_once $openseo_composer;
}

$openseo_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $openseo_tests_dir || '' === $openseo_tests_dir ) {
	$openseo_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$openseo_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );

if ( false !== $openseo_polyfills && '' !== $openseo_polyfills ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $openseo_polyfills );
}

$openseo_functions = $openseo_tests_dir . '/includes/functions.php';

if ( ! is_readable( $openseo_functions ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$openseo_tests_dir}.\n" .
		"Run integration tests via \"npm run env:start\" then \"wp-env run tests-cli ...\",\n" .
		"or set WP_TESTS_DIR to a valid wordpress-tests-lib path.\n"
	);
	exit( 1 );
}

require_once $openseo_functions;

// Load the plugin into the test instance before WordPress finishes booting.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/openseo.php';
	}
);

require $openseo_tests_dir . '/includes/bootstrap.php';
