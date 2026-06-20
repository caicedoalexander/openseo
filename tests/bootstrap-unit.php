<?php
/**
 * Bootstrap for isolated unit tests (Brain Monkey, no WordPress loaded).
 *
 * @package OpenSEO
 */

declare( strict_types=1 );

$openseo_autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $openseo_autoloader ) ) {
	fwrite( STDERR, "Run \"composer install\" before running the test suite.\n" );
	exit( 1 );
}

require_once $openseo_autoloader;

/*
 * Minimal polyfills so unit tests can exercise code that returns WP_Error or
 * type-hints WP_Post. WordPress is never loaded for unit tests; wordpress-stubs
 * only feeds PHPStan, not the runtime.
 */
/*
 * WordPress time constants used in production code (wp-settings.php defines them
 * before any plugin boots; they are never provided by the WP stubs for PHPStan).
 */
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public function __construct(
			private string $code = '',
			private string $message = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {

		public int $ID = 0;

		public string $post_title = '';

		public string $post_content = '';
	}
}
