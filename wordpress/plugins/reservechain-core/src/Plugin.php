<?php
/**
 * Plugin bootstrap and service container.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core;

use ReserveChain\Core\Audit\AuditLogger;
use ReserveChain\Core\Content\WorkflowStates;
use ReserveChain\Core\Database\Migrator;
use RuntimeException;
use Throwable;

/**
 * Single entry point for the platform.
 *
 * Deliberately thin: it wires components together and owns nothing else, so a
 * replacement developer can read one file and see the whole surface area of
 * the plugin.
 */
final class Plugin {

	/**
	 * Option holding the installed schema version.
	 */
	private const VERSION_OPTION = 'reservechain_core_version';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit = null;

	/**
	 * Migration runner.
	 *
	 * @var Migrator|null
	 */
	private ?Migrator $migrator = null;

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Audit logger accessor.
	 */
	public function audit(): AuditLogger {
		if ( null === $this->audit ) {
			$this->audit = new AuditLogger();
		}

		return $this->audit;
	}

	/**
	 * Migrator accessor.
	 */
	public function migrator(): Migrator {
		if ( null === $this->migrator ) {
			$this->migrator = new Migrator();
		}

		return $this->migrator;
	}

	/**
	 * Runtime boot. Runs on every request.
	 */
	public function boot(): void {
		// Translations must load on `init`, not earlier. WordPress 6.7 warns
		// when a text domain is requested during `plugins_loaded`, because
		// locale determination is not complete at that point.
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'reservechain', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
			},
			0
		);

		// The six publication states the brief mandates, registered as real
		// WordPress post statuses so editors work in the native editor.
		add_action( 'init', array( WorkflowStates::class, 'register' ), 1 );

		add_action( 'init', array( $this, 'maybe_upgrade' ), 2 );
		add_action( 'admin_notices', array( $this, 'render_migration_drift_notice' ) );

		// Registry-backed blocks. Server-rendered so the publication gate runs
		// before anything is serialised: an unapproved status never reaches the
		// browser, rather than being hidden once it gets there.
		Blocks\Blocks::register();

		// A dedicated block category keeps platform blocks distinguishable from
		// core blocks in the inserter.
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );

		// Operational commands. Registered early and unconditionally under CLI
		// so that `wp reservechain migrate` works even when the schema is
		// behind the code — which is exactly when an operator needs it.
		Cli\Commands::register();
	}

	/**
	 * Add the platform's own block category to the inserter.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function register_block_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'reservechain',
				'title' => __( 'ReserveChain', 'reservechain' ),
				'icon'  => null,
			)
		);

		return $categories;
	}

	/**
	 * Apply pending migrations after a code deployment.
	 *
	 * Runs only when the stored version differs from the shipped one, so the
	 * common request path costs a single option read.
	 */
	public function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( VERSION === $installed ) {
			return;
		}

		try {
			$applied = $this->migrator()->migrate();

			update_option( self::VERSION_OPTION, VERSION, false );

			if ( ! empty( $applied ) ) {
				$this->audit()->log(
					array(
						'action'       => 'schema_migrated',
						'entity_type'  => 'system',
						'entity_label' => 'ReserveChain Core ' . VERSION,
						'new_value'    => $applied,
						'reason'       => 'Automatic migration on version change.',
						'severity'     => 'notice',
					)
				);
			}
		} catch ( Throwable $e ) {
			// Never leave a half-migrated site silently running: surface it.
			set_transient( 'reservechain_migration_error', $e->getMessage(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Warn administrators when an applied migration file has been edited.
	 */
	public function render_migration_drift_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$error = get_transient( 'reservechain_migration_error' );

		if ( is_string( $error ) && '' !== $error ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'ReserveChain migration failed:', 'reservechain' ),
				esc_html( $error )
			);
		}

		$drift = $this->migrator()->drift();

		if ( empty( $drift ) ) {
			return;
		}

		$names = implode( ', ', array_column( $drift, 'filename' ) );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'ReserveChain schema drift detected:', 'reservechain' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated migration filenames */
					__( 'these migration files changed after they were applied: %s. Environments that ran the earlier version have diverged. Add a new migration instead of editing an applied one.', 'reservechain' ),
					$names
				)
			)
		);
	}

	/**
	 * Activation: install the schema and record the version.
	 *
	 * @throws RuntimeException When migrations cannot be applied.
	 */
	public function activate(): void {
		$this->migrator()->migrate();

		update_option( self::VERSION_OPTION, VERSION, false );

		$this->audit()->log(
			array(
				'action'       => 'plugin_activated',
				'entity_type'  => 'system',
				'entity_label' => 'ReserveChain Core ' . VERSION,
				'severity'     => 'notice',
			)
		);
	}

	/**
	 * Deactivation: stop scheduled work only.
	 *
	 * Tables are never dropped here. Registry records, consent evidence and the
	 * audit trail must survive an accidental deactivation — toggling a plugin
	 * is not an instruction to destroy the asset register.
	 */
	public function deactivate(): void {
		foreach ( array( 'reservechain_reconcile', 'reservechain_anchor_audit', 'reservechain_document_alerts' ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );

			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		$this->audit()->log(
			array(
				'action'       => 'plugin_deactivated',
				'entity_type'  => 'system',
				'entity_label' => 'ReserveChain Core ' . VERSION,
				'severity'     => 'warning',
			)
		);
	}
}
