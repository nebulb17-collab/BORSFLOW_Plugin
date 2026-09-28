<?php
/**
 * Build a throwaway WordPress site on SQLite with BorsFlow Forms active.
 *
 * Used by the PHPUnit bootstrap and the Playwright runner. Needs `composer install`.
 *
 * Usage: php tests/bin/install-wp.php <target dir> [site url]
 *
 * @package BorsFlow_Forms
 */

// phpcs:disable -- test tooling, runs outside WordPress.

$plugin_dir = dirname( __DIR__, 2 );
$target     = rtrim( $argv[1] ?? '', '/' );
$url        = rtrim( $argv[2] ?? 'http://127.0.0.1:8899', '/' );

if ( '' === $target ) {
	fwrite( STDERR, "Usage: php tests/bin/install-wp.php <target dir> [site url]\n" );
	exit( 1 );
}

$core = $plugin_dir . '/vendor/johnpbloch/wordpress-core';
$db   = $plugin_dir . '/vendor/aaemnnosttv/wp-sqlite-db/src/db.php';
if ( ! is_dir( $core ) || ! is_file( $db ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}

// Fresh copy every time: tests must not depend on leftovers.
if ( is_dir( $target ) ) {
	exec( 'rm -rf ' . escapeshellarg( $target ) );
}
exec( 'cp -R ' . escapeshellarg( $core ) . ' ' . escapeshellarg( $target ), $out, $code );
if ( 0 !== $code ) {
	fwrite( STDERR, "Could not copy WordPress core.\n" );
	exit( 1 );
}
copy( $db, $target . '/wp-content/db.php' );
@mkdir( $target . '/wp-content/plugins', 0777, true );
symlink( $plugin_dir, $target . '/wp-content/plugins/borsflow-forms' );

// Mail goes to a JSON-lines log instead of sendmail.
@mkdir( $target . '/wp-content/mu-plugins', 0777, true );
file_put_contents(
	$target . '/wp-content/mu-plugins/borsflow-test-mail.php',
	"<?php\nadd_filter( 'pre_wp_mail', function ( \$null, \$atts ) {\n\tfile_put_contents( WP_CONTENT_DIR . '/mail.log', json_encode( \$atts ) . \"\\n\", FILE_APPEND );\n\treturn true;\n}, 10, 2 );\n"
);

$salts = '';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
	$salts .= "define( '$k', '" . bin2hex( random_bytes( 16 ) ) . "' );\n";
}
file_put_contents(
	$target . '/wp-config.php',
	"<?php\n" .
	"define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );\n" .
	"define( 'DB_CHARSET', 'utf8' ); define( 'DB_COLLATE', '' );\n" .
	"define( 'DB_DIR', __DIR__ . '/wp-content/database/' );\n" .
	"\$table_prefix = 'wp_';\n" . $salts .
	"define( 'WP_HOME', '$url' ); define( 'WP_SITEURL', '$url' );\n" .
	"define( 'WP_DEBUG', true ); define( 'WP_DEBUG_DISPLAY', false ); define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );\n" .
	"define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );\n" .
	"define( 'WP_ACCESSIBLE_HOSTS', '127.0.0.1,localhost' );\n" .
	"define( 'WP_ENVIRONMENT_TYPE', 'local' );\n" .
	"if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }\n" .
	"require_once ABSPATH . 'wp-settings.php';\n"
);

// Install and activate in a child process so the caller gets a clean runtime.
$script = <<<'PHP'
<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = parse_url( getenv( 'BF_URL' ), PHP_URL_HOST ) . ( parse_url( getenv( 'BF_URL' ), PHP_URL_PORT ) ? ':' . parse_url( getenv( 'BF_URL' ), PHP_URL_PORT ) : '' );
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BF_TARGET' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install( 'BorsFlow Test', 'admin', 'admin@example.com', true, '', 'admin' );
update_option( 'permalink_structure', '' );
PHP;
$activate = <<<'PHP'
<?php
$_SERVER['HTTP_HOST'] = parse_url( getenv( 'BF_URL' ), PHP_URL_HOST ) . ( parse_url( getenv( 'BF_URL' ), PHP_URL_PORT ) ? ':' . parse_url( getenv( 'BF_URL' ), PHP_URL_PORT ) : '' );
require getenv( 'BF_TARGET' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'borsflow-forms/borsflow-forms.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}
PHP;

foreach ( array( 'install' => $script, 'activate' => $activate ) as $name => $code_str ) {
	$file = $target . "/bf-$name.php";
	file_put_contents( $file, $code_str );
	$cmd = sprintf( 'BF_TARGET=%s BF_URL=%s %s %s 2>&1', escapeshellarg( $target ), escapeshellarg( $url ), escapeshellarg( PHP_BINARY ), escapeshellarg( $file ) );
	exec( $cmd, $lines, $code );
	unlink( $file );
	if ( 0 !== $code ) {
		fwrite( STDERR, "WordPress $name failed:\n" . implode( "\n", $lines ) . "\n" );
		exit( 1 );
	}
}

echo "WordPress ready at $target ($url)\n";
