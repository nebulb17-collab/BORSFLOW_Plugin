<?php
/**
 * Plugin Name:       BorsFlow Forms
 * Plugin URI:        https://borsflow.com/wordpress
 * Description:       Build custom contact forms, collect submissions in wp-admin, and sync every lead to BorsFlow CRM.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            BorsFlow
 * Author URI:        https://borsflow.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       borsflow-forms
 * Domain Path:       /languages
 *
 * @package BorsFlow_Forms
 */

defined( 'ABSPATH' ) || exit;

define( 'BORSFLOW_VERSION', '1.0.0' );
define( 'BORSFLOW_DB_VERSION', '1.0.0' );
define( 'BORSFLOW_FILE', __FILE__ );
define( 'BORSFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'BORSFLOW_URL', plugin_dir_url( __FILE__ ) );

/**
 * Class autoloader: BorsFlow_Foo_Bar => includes/{,admin/,integrations/}class-borsflow-foo-bar.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'BorsFlow_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		foreach ( array( 'includes/', 'includes/admin/', 'includes/integrations/' ) as $dir ) {
			$path = BORSFLOW_DIR . $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, array( 'BorsFlow_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BorsFlow_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BorsFlow_Plugin', 'instance' ) );
