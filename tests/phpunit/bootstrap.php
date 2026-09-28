<?php
/**
 * PHPUnit bootstrap: installs a fresh WordPress (SQLite) with the plugin active and loads it.
 *
 * Set BORSFLOW_WP_DIR to choose where the throwaway site lives.
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test bootstrap.

$root   = dirname( __DIR__, 2 );
$target = getenv( 'BORSFLOW_WP_DIR' ) ?: sys_get_temp_dir() . '/borsflow-phpunit-wp';
$url    = 'http://borsflow.test';

passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/tests/bin/install-wp.php' ) . ' ' . escapeshellarg( $target ) . ' ' . escapeshellarg( $url ), $code );
if ( 0 !== $code ) {
	exit( $code );
}

require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$_SERVER['HTTP_HOST']   = 'borsflow.test';
$_SERVER['SERVER_NAME'] = 'borsflow.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

require $target . '/wp-load.php';

if ( ! class_exists( 'BorsFlow_Plugin' ) ) {
	fwrite( STDERR, "BorsFlow Forms did not load.\n" );
	exit( 1 );
}

require __DIR__ . '/class-borsflow-testcase.php';
