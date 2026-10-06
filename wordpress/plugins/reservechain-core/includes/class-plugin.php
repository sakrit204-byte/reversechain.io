<?php
/**
 * Plugin bootstrap.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		if ( get_option( 'rc_db_version' ) !== RC_DB_VERSION ) {
			Install::upgrade();
		}

		I18n::init();
		Settings::init();
		Audit_Log::init();
		Workflow::init();
		Registry::init();
		Passport::init();
		Compliance::init();
		Waitlist::init();
		Auth::init();
		Rest::init();
		Security::init();
		Shortcodes::init();

		// Feature modules (each self-contained in its own class file; each owns its tables via `install()`).
		foreach ( array( 'Por', 'Redemption', 'Web3', 'Portal', 'Intake', 'Monitoring' ) as $module ) {
			$class = __NAMESPACE__ . '\\' . $module;
			if ( class_exists( $class ) ) {
				if ( method_exists( $class, 'install' ) && get_option( 'rc_module_db_' . strtolower( $module ) ) !== RC_VERSION . '-' . RC_DB_VERSION ) {
					$class::install();
					update_option( 'rc_module_db_' . strtolower( $module ), RC_VERSION . '-' . RC_DB_VERSION, false );
				}
				$class::init();
			}
		}

		if ( is_admin() ) {
			Admin::init();
		}

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'reservechain', false, dirname( plugin_basename( RC_FILE ) ) . '/languages' );
	}
}
