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
