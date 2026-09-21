<?php
/**
 * WP-CLI commands.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Cli;

use ReserveChain\Core\Plugin;
use ReserveChain\Core\Seed\RegistrySeeder;
use Throwable;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Operational commands for the ReserveChain platform.
 *
 * These exist because the handover terms require that ReserveChain staff or a
 * replacement developer can operate, maintain, deploy, back up and restore the
 * platform without the original developer. Anything an operator needs to do
 * routinely — migrate, verify the audit chain, inspect activation state — has
 * to be runnable from a shell and scriptable in a cron job, not buried behind
 * a button only we know about.
 *
 * ## EXAMPLES
 *
 *     wp reservechain status
 *     wp reservechain migrate
 *     wp reservechain seed --registry
 *     wp reservechain audit verify
 */
final class Commands {

	/**
	 * Register the command namespace.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'reservechain', self::class );
	}

	/**
	 * Show platform status: schema version, migrations, modes, pending owner input.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp reservechain status
	 *     wp reservechain status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		global $wpdb;

		unset( $args );

		$migrator = Plugin::instance()->migrator();
		$prefix   = $wpdb->prefix;

		$count = static function ( string $table ) use ( $wpdb, $prefix ): int {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}{$table}`" );
		};

		$rows = array(
			array(
				'item'  => 'Applied migrations',
				'value' => (string) count( $migrator->applied() ),
			),
			array(
				'item'  => 'Pending migrations',
				'value' => (string) count( $migrator->pending() ),
			),
			array(
				'item'  => 'Schema drift',
				'value' => empty( $migrator->drift() ) ? 'none' : implode( ', ', array_column( $migrator->drift(), 'filename' ) ),
			),
			array(
				'item'  => 'Active website modes',
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'value' => (string) $wpdb->get_var( "SELECT GROUP_CONCAT(`mode_key` ORDER BY `sort_order`) FROM `{$prefix}rc_site_modes` WHERE `is_active` = 1" ),
			),
			array(
				'item'  => 'Modules active / total',
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'value' => $wpdb->get_var( "SELECT CONCAT(SUM(`state`='active'), ' / ', COUNT(*)) FROM `{$prefix}rc_module_flags`" ),
			),
			array(
				'item'  => 'Owner inputs pending',
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'value' => $wpdb->get_var( "SELECT CONCAT(SUM(`status`='pending'), ' of ', COUNT(*)) FROM `{$prefix}rc_owner_inputs`" ),
			),
			array(
				'item'  => 'Settings awaiting owner value',
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				'value' => $wpdb->get_var( "SELECT CONCAT(SUM(`value_json` IS NULL), ' of ', COUNT(*)) FROM `{$prefix}rc_settings`" ),
			),
			array( 'item' => 'Asset programmes', 'value' => (string) $count( 'rc_asset_programs' ) ),
			array( 'item' => 'Lots',             'value' => (string) $count( 'rc_lots' ) ),
			array( 'item' => 'Units',            'value' => (string) $count( 'rc_units' ) ),
			array( 'item' => 'Certificates',     'value' => (string) $count( 'rc_certificates' ) ),
			array( 'item' => 'Audit entries',    'value' => (string) $count( 'rc_audit_log' ) ),
		);

		Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'item', 'value' ) );
	}

	/**
	 * Apply pending database migrations.
	 *
	 * Idempotent: an already-applied file is never re-run. Reports schema drift
	 * if a file changed after it was applied.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List what would be applied without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp reservechain migrate
	 *     wp reservechain migrate --dry-run
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		unset( $args );

		$migrator = Plugin::instance()->migrator();
		$pending  = $migrator->pending();
		$drift    = $migrator->drift();

		foreach ( $drift as $item ) {
			WP_CLI::warning(
				sprintf(
					'%s changed after it was applied. Environments that ran the earlier version have diverged; add a new migration rather than editing an applied one.',
					$item['filename']
				)
			);
		}

		if ( empty( $pending ) ) {
			WP_CLI::success( 'Schema is up to date. No pending migrations.' );
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			foreach ( $pending as $path ) {
				WP_CLI::line( 'would apply: ' . basename( $path ) );
			}

			WP_CLI::success( sprintf( '%d migration(s) pending.', count( $pending ) ) );
			return;
		}

		try {
			$applied = $migrator->migrate();
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( $applied as $item ) {
			WP_CLI::line( sprintf( 'applied %s (%d statements, %d ms)', $item['filename'], $item['statements'], $item['duration_ms'] ) );
		}

		WP_CLI::success( sprintf( 'Applied %d migration(s).', count( $applied ) ) );
	}

	/**
	 * Load registry content.
	 *
	 * Loads the Certificates of Analysis supplied with the brief as records in
	 * `under_review`, plus the illustrative public template. Nothing is
	 * invented: where the supplied evidence is silent, the field stays empty.
	 *
	 * ## OPTIONS
	 *
	 * [--registry]
	 * : Load the supplied evidence and the illustrative template.
	 *
	 * ## EXAMPLES
	 *
	 *     wp reservechain seed --registry
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function seed( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! isset( $assoc_args['registry'] ) ) {
			WP_CLI::error( 'Specify what to seed, e.g. --registry' );
			return;
		}

		try {
			$summary = ( new RegistrySeeder() )->run();
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( $summary as $key => $value ) {
			WP_CLI::line( sprintf( '%-14s %d', $key, $value ) );
		}

		WP_CLI::success( 'Registry seeded. Supplied certificates are in "under_review"; publication requires approval.' );
	}

	/**
	 * Audit-trail operations.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : verify — recompute the hash chain and report the first divergence.
	 *
	 * [--from=<id>]
	 * : First entry to check. Default 1.
	 *
	 * [--to=<id>]
	 * : Last entry to check. Default: the end of the chain.
	 *
	 * ## EXAMPLES
	 *
	 *     wp reservechain audit verify
	 *     wp reservechain audit verify --from=1000
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 */
	public function audit( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		if ( 'verify' !== $action ) {
			WP_CLI::error( sprintf( 'Unknown audit action "%s". Supported: verify', $action ) );
			return;
		}

		$result = Plugin::instance()->audit()->verify(
			(int) ( $assoc_args['from'] ?? 1 ),
			(int) ( $assoc_args['to'] ?? 0 )
		);

		if ( $result['ok'] ) {
			WP_CLI::success( $result['message'] );
			return;
		}

		WP_CLI::error( $result['message'] );
	}
}
