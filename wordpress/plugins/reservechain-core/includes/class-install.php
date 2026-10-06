<?php
/**
 * Installation, schema migrations, roles and the database-level immutability guarantees.
 *
 * Migrations are versioned (RC_DB_VERSION) and idempotent; each step is recorded in the audit trail.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Install {

	public const CAPS = array(
		'rc_manage_registry'   => 'Create and edit Asset Registry records',
		'rc_submit'            => 'Submit content for review',
		'rc_approve'           => 'Approve or reject content under review (four-eyes)',
		'rc_publish'           => 'Publish / unpublish approved content',
		'rc_archive'           => 'Archive content',
		'rc_manage_compliance' => 'Manage KYC/KYB/AML/sanctions and jurisdiction controls',
		'rc_manage_waitlist'   => 'View and export the waitlist',
		'rc_view_audit'        => 'View and verify the audit trail',
		'rc_anchor_audit'      => 'Anchor the audit chain head on-chain',
		'rc_manage_settings'   => 'Change site mode, modules and platform settings',
		'rc_authorize_modules' => 'Activate gated modules (wallet, purchase, PoR, redemption) with a written authorization reference',
	);

	public static function activate(): void {
		self::upgrade();
		Registry::register_post_types();
		Workflow::register_statuses();
		flush_rewrite_rules();
		Audit_Log::record( 'plugin.activated', 'system', 0, 'ReserveChain Core activated', array( 'version' => RC_VERSION ) );
	}

	public static function deactivate(): void {
		Audit_Log::record( 'plugin.deactivated', 'system', 0, 'ReserveChain Core deactivated', array() );
		flush_rewrite_rules();
	}

	public static function upgrade(): void {
		self::create_tables();
		self::create_triggers();
		self::create_roles();
		$from = get_option( 'rc_db_version', '0' );
		update_option( 'rc_db_version', RC_DB_VERSION, true );
		if ( $from !== RC_DB_VERSION ) {
			Audit_Log::record( 'system.migrated', 'system', 0, sprintf( 'Database schema migrated %s → %s', $from, RC_DB_VERSION ), array() );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		// Append-only, hash-chained audit trail. UPDATE/DELETE are rejected by triggers below.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}rc_audit_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME(6) NOT NULL,
				actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				actor_login VARCHAR(60) NOT NULL DEFAULT '',
				actor_role VARCHAR(191) NOT NULL DEFAULT '',
				ip_hash CHAR(64) NOT NULL DEFAULT '',
				action VARCHAR(64) NOT NULL,
				object_type VARCHAR(64) NOT NULL DEFAULT '',
				object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				summary VARCHAR(512) NOT NULL DEFAULT '',
				data LONGTEXT NULL,
				prev_hash CHAR(64) NOT NULL,
				row_hash CHAR(64) NOT NULL,
				PRIMARY KEY  (id),
				KEY action (action),
				KEY object (object_type, object_id),
				KEY actor_id (actor_id),
				UNIQUE KEY row_hash (row_hash)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}rc_audit_anchor (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				seq BIGINT UNSIGNED NOT NULL,
				chain_head CHAR(64) NOT NULL,
				network VARCHAR(32) NOT NULL DEFAULT '',
				tx_hash VARCHAR(80) NOT NULL DEFAULT '',
				anchored_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY seq (seq)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}rc_waitlist (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				name VARCHAR(191) NOT NULL,
				email VARCHAR(191) NOT NULL,
				email_hash CHAR(64) NOT NULL,
				country CHAR(2) NOT NULL,
				entity_type VARCHAR(20) NOT NULL DEFAULT 'individual',
				organisation VARCHAR(191) NOT NULL DEFAULT '',
				interest VARCHAR(64) NOT NULL DEFAULT '',
				first_name VARCHAR(100) NOT NULL DEFAULT '',
				last_name VARCHAR(100) NOT NULL DEFAULT '',
				buyer_interest TINYINT(1) NOT NULL DEFAULT 0,
				owner_interest TINYINT(1) NOT NULL DEFAULT 0,
				materials VARCHAR(20) NOT NULL DEFAULT '',
				interest_range VARCHAR(20) NOT NULL DEFAULT '',
				participation_type VARCHAR(30) NOT NULL DEFAULT '',
				consent_updates TINYINT(1) NOT NULL DEFAULT 0,
				nationality CHAR(2) NOT NULL DEFAULT '',
				current_location CHAR(2) NOT NULL DEFAULT '',
				campaign_source VARCHAR(100) NOT NULL DEFAULT '',
				notes TEXT NULL,
				assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				language CHAR(2) NOT NULL DEFAULT 'en',
				jurisdiction_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				status VARCHAR(30) NOT NULL DEFAULT 'pending_confirmation',
				general_updates_only TINYINT(1) NOT NULL DEFAULT 0,
				confirm_token_hash CHAR(64) NOT NULL DEFAULT '',
				confirmed_at DATETIME NULL,
				consent_version VARCHAR(32) NOT NULL DEFAULT '',
				consent_hash CHAR(64) NOT NULL DEFAULT '',
				source VARCHAR(20) NOT NULL DEFAULT 'web',
				ip_hash CHAR(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY email_hash (email_hash),
				KEY country (country),
				KEY status (status)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}rc_support (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				channel VARCHAR(20) NOT NULL DEFAULT 'web',
				name VARCHAR(191) NOT NULL DEFAULT '',
				email VARCHAR(191) NOT NULL DEFAULT '',
				subject VARCHAR(191) NOT NULL,
				message TEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}rc_notifications (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(191) NOT NULL,
				body TEXT NOT NULL,
				category VARCHAR(32) NOT NULL DEFAULT 'general',
				read_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) $charset;"
		);
	}

	/**
	 * Database-enforced immutability for the audit trail. Even a compromised application account
	 * cannot silently edit or remove history; tampering at a higher privilege breaks the hash chain
	 * and is detected by Audit_Log::verify() and by on-chain anchoring.
	 */
	public static function create_triggers(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rc_audit_log';
		$ok    = true;

		$wpdb->suppress_errors( true );
		foreach ( array( 'UPDATE', 'DELETE' ) as $op ) {
			$name = $wpdb->prefix . 'rc_audit_no_' . strtolower( $op );
			$wpdb->query( "DROP TRIGGER IF EXISTS `{$name}`" ); // phpcs:ignore
			$res = $wpdb->query(
				"CREATE TRIGGER `{$name}` BEFORE {$op} ON `{$table}` FOR EACH ROW
				 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rc_audit_log is append-only: {$op} is not permitted'" // phpcs:ignore
			);
			$ok = $ok && false !== $res;
		}
		$wpdb->suppress_errors( false );

		update_option( 'rc_audit_triggers', $ok ? 'active' : 'unavailable', false );
	}

	public static function triggers_active(): bool {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = %s',
				$wpdb->prefix . 'rc_audit_log'
			)
		);
		return $count >= 2;
	}

	/** Primitive capabilities generated by capability_type array( 'rc_entity', 'rc_entities' ). */
	public static function entity_caps(): array {
		return array( 'edit_rc_entities', 'edit_others_rc_entities', 'publish_rc_entities', 'read_private_rc_entities', 'delete_rc_entities', 'delete_others_rc_entities', 'delete_published_rc_entities', 'delete_private_rc_entities', 'edit_published_rc_entities', 'edit_private_rc_entities' );
	}

	public static function create_roles(): void {
		$entity_caps      = self::entity_caps();
		$read_only_entity = array( 'read', 'read_private_rc_entities', 'edit_rc_entities' );

		// Editors never receive publish_rc_entities: publishing happens only through the workflow (rc_publish).
		$editing = array_fill_keys( array_merge( array( 'read', 'upload_files', 'edit_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages' ), $entity_caps ), true );
		unset( $editing['publish_rc_entities'] );

		$roles = array(
			'rc_editor'             => array(
				'name' => 'RC Content Editor',
				'caps' => array_merge( array_fill_keys( array( 'read', 'upload_files', 'edit_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'rc_submit' ), true ) ),
			),
			'rc_registry_manager'   => array(
				'name' => 'RC Registry Manager',
				'caps' => array_merge( $editing, array( 'rc_manage_registry' => true, 'rc_submit' => true ) ),
			),
			'rc_reviewer'           => array(
				'name' => 'RC Reviewer',
				'caps' => array_merge( $editing, array( 'rc_approve' => true, 'rc_view_audit' => true ) ),
			),
			'rc_compliance_officer' => array(
				'name' => 'RC Compliance Officer',
				'caps' => array_merge( $editing, array( 'rc_approve' => true, 'rc_publish' => true, 'rc_archive' => true, 'rc_manage_compliance' => true, 'rc_manage_waitlist' => true, 'rc_view_audit' => true, 'list_users' => true, 'edit_users' => true ) ),
			),
			'rc_auditor'            => array(
				'name' => 'RC Auditor (read-only)',
				'caps' => array_merge( array_fill_keys( $read_only_entity, true ), array( 'rc_view_audit' => true ) ),
			),
		);

		foreach ( $roles as $slug => $role ) {
			$existing = get_role( $slug );
			if ( ! $existing ) {
				add_role( $slug, $role['name'], $role['caps'] );
				continue;
			}
			// Merge only: never strip capabilities granted later by modules or administrators.
			foreach ( $role['caps'] as $cap => $grant ) {
				if ( $grant && ! $existing->has_cap( $cap ) ) {
					$existing->add_cap( $cap );
				}
			}
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_merge( array_keys( self::CAPS ), $entity_caps ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
