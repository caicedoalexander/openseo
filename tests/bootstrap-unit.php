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

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {

		public int $term_id = 0;

		public string $name = '';

		public string $description = '';

		public string $taxonomy = '';
	}
}
