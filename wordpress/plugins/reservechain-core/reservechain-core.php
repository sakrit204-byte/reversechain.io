<?php
/**
 * Plugin Name:       ReserveChain Core
 * Plugin URI:        https://reservechain.io
 * Description:       Industrial-metals asset registry, Digital Asset Passports, Proof of Reserves, compliance controls and a tamper-evident audit trail for the ReserveChain.io pre-launch platform.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            ReserveChain.io
 * License:           Proprietary
 * Text Domain:       reservechain
 * Domain Path:       /languages
 *
 * @package ReserveChain\Core
 *
 * ---------------------------------------------------------------------------
 * This plugin owns the platform's data and rules. The theme renders; it never
 * decides what may be shown. That separation is deliberate: publication gating
 * ("no unapproved custody, laboratory, insurance or reserve claim may be
 * displayed") has to be enforced in one place that every surface — website,
 * REST API, mobile apps, exports — passes through.
 * ---------------------------------------------------------------------------
 */

declare( strict_types = 1 );

namespace ReserveChain\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION      = '0.1.0';
const PLUGIN_FILE  = __FILE__;
const MIN_PHP      = '8.2';
const MIN_WP       = '6.6';

define( 'RC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Fail loudly and early rather than half-booting on an unsupported stack.
 */
if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'ReserveChain Core requires PHP %1$s or newer. This server runs PHP %2$s.', 'reservechain' ),
						MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

$rc_autoloader = RC_PLUGIN_DIR . 'vendor/autoload.php';

if ( is_readable( $rc_autoloader ) ) {
	require_once $rc_autoloader;
} else {
	/**
	 * Minimal PSR-4 fallback.
	 *
	 * Composer is the supported path and CI enforces it, but a handover should
	 * never depend on the receiving team running `composer install` before the
	 * site will boot at all. This keeps the plugin loadable from a plain file
	 * copy, which matters for the disaster-recovery procedure.
	 */
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'ReserveChain\\Core\\';

			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$path     = RC_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/**
 * Activation: install schema, seed reference data, register capabilities.
 *
 * Migrations are idempotent and tracked, so activating an already-installed
 * plugin is a no-op rather than a destructive re-install.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		Plugin::instance()->activate();
	}
);

/**
 * Deactivation clears scheduled jobs only.
 *
 * It never drops tables. Registry records, consent evidence and the audit
 * trail must survive an accidental deactivation — an administrator toggling a
 * plugin is not an instruction to destroy the asset register.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		Plugin::instance()->deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);
