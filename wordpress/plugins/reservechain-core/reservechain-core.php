<?php
/**
 * Plugin Name:       ReserveChain Core
 * Plugin URI:        https://reservechain.io
 * Description:       Institutional core for ReserveChain: industrial-metal Asset Registry, Digital Asset Passports, evidence fingerprinting, tamper-evident audit trail, four-eyes editorial workflow, compliance controls, waitlist and the REST API used by the website and the iOS/Android apps.
 * Version:           0.9.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ReserveChain
 * License:           Proprietary — owned by ReserveChain
 * Text Domain:       reservechain
 */

defined( 'ABSPATH' ) || exit;

define( 'RC_VERSION', '0.9.0' );
define( 'RC_DB_VERSION', '3' );
define( 'RC_FILE', __FILE__ );
define( 'RC_DIR', plugin_dir_path( __FILE__ ) );
define( 'RC_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'RC_ENV' ) ) {
	define( 'RC_ENV', 'production' );
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'RC\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( array( 'RC\\', '_' ), array( '', '-' ), $class ) );
		$path = RC_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'RC\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RC\\Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'RC\\Plugin', 'boot' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once RC_DIR . 'includes/class-cli.php';
}
