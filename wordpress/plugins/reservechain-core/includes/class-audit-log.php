<?php
/**
 * Append-only, tamper-evident audit trail.
 *
 * Guarantees, layered:
 *  1. Application: no code path updates or deletes rows; there is no UI for it.
 *  2. Database:    BEFORE UPDATE / BEFORE DELETE triggers raise SQLSTATE 45000.
 *  3. Cryptographic: each row commits to the previous row (prev_hash) — any edit, insertion
 *     or removal anywhere in history breaks every subsequent hash and is detected by verify().
 *  4. External:    the chain head (seq + hash) can be anchored on a public blockchain via the
 *     AuditAnchor contract, so even a full database rewrite is detectable against the anchor.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Audit_Log {

	public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	private static bool $writing = false;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rc_audit_log';
	}

	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_change' ), 10, 3 );
		add_action( 'delete_user', array( __CLASS__, 'on_user_delete' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 10, 3 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_off' ) );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme' ) );
		add_action( 'add_attachment', array( __CLASS__, 'on_attachment' ) );
	}

	/**
	 * Append an entry. Serialised with a named MySQL lock so concurrent requests cannot fork the chain.
	 */
	public static function record( string $action, string $object_type = '', int $object_id = 0, string $summary = '', array $data = array(), ?int $actor_id = null ): ?string {
		global $wpdb;
		if ( self::$writing ) {
			return null;
		}
		self::$writing = true;

		$actor_id = $actor_id ?? get_current_user_id();
		$user     = $actor_id ? get_userdata( $actor_id ) : null;
		$login    = $user ? $user->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : ( $actor_id ? '' : 'system' ) );
		$role     = $user ? implode( ',', (array) $user->roles ) : '';
		$json     = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$summary  = mb_substr( $summary, 0, 512 );

		$locked = (int) $wpdb->get_var( "SELECT GET_LOCK('rc_audit_chain', 10)" );
		try {
			$prev       = $wpdb->get_var( 'SELECT row_hash FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1' ) ?: self::GENESIS; // phpcs:ignore
			$created_at = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' );
			$row        = array(
				'created_at'  => $created_at,
				'actor_id'    => (int) $actor_id,
				'actor_login' => $login,
				'actor_role'  => $role,
				'ip_hash'     => self::ip_hash(),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'summary'     => $summary,
				'data'        => $json,
				'prev_hash'   => $prev,
			);
			$row['row_hash'] = self::hash_row( $row );
			$wpdb->insert( self::table(), $row );
			return $row['row_hash'];
		} finally {
			if ( $locked ) {
				$wpdb->get_var( "SELECT RELEASE_LOCK('rc_audit_chain')" );
			}
			self::$writing = false;
		}
	}

	/** Canonical row hash. Field order and separator are part of the verification contract (see docs/CMS-STRUCTURE.md). */
	public static function hash_row( array $row ): string {
		$parts = array(
			$row['prev_hash'],
			$row['created_at'],
			(string) (int) $row['actor_id'],
			$row['actor_login'],
			$row['actor_role'],
			$row['ip_hash'],
			$row['action'],
			$row['object_type'],
			(string) (int) $row['object_id'],
			$row['summary'],
			(string) $row['data'],
		);
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	public static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'cli';
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	/**
	 * Walk the entire chain and recompute every hash.
	 *
	 * @return array{ok:bool,checked:int,head:string,seq:int,errors:array}
	 */
	public static function verify( int $limit_errors = 20 ): array {
		global $wpdb;
		$errors  = array();
		$prev    = self::GENESIS;
		$last_id = 0;
		$checked = 0;
		$expect  = null;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id > %d ORDER BY id ASC LIMIT 1000', $last_id ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $row ) {
				++$checked;
				if ( null !== $expect && (int) $row['id'] !== $expect ) {
					$errors[] = array( 'id' => (int) $row['id'], 'type' => 'gap', 'message' => sprintf( 'Sequence gap before #%d (expected #%d) — possible removal.', $row['id'], $expect ) );
				}
				if ( $row['prev_hash'] !== $prev ) {
					$errors[] = array( 'id' => (int) $row['id'], 'type' => 'link', 'message' => sprintf( 'Entry #%d does not link to the previous entry.', $row['id'] ) );
				}
				if ( self::hash_row( $row ) !== $row['row_hash'] ) {
					$errors[] = array( 'id' => (int) $row['id'], 'type' => 'content', 'message' => sprintf( 'Entry #%d content does not match its hash — modified.', $row['id'] ) );
				}
				$prev    = $row['row_hash'];
				$last_id = (int) $row['id'];
				$expect  = $last_id + 1;
				if ( count( $errors ) >= $limit_errors ) {
					break 2;
				}
			}
		} while ( count( $rows ) === 1000 );

		$result = array(
			'ok'      => empty( $errors ),
			'checked' => $checked,
			'head'    => $prev,
			'seq'     => $last_id,
			'errors'  => $errors,
		);
		update_option( 'rc_audit_last_verify', array_merge( $result, array( 'at' => time() ) ), false );
		return $result;
	}

	public static function head(): array {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT id, row_hash, created_at FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore
		return array(
			'seq'        => $row ? (int) $row['id'] : 0,
			'chain_head' => $row ? $row['row_hash'] : self::GENESIS,
			'at'         => $row ? $row['created_at'] . 'Z' : null,
		);
	}

	public static function latest_anchor(): ?array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}rc_audit_anchor ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public static function add_anchor( int $seq, string $head, string $network, string $tx ): bool {
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT row_hash FROM ' . self::table() . ' WHERE id = %d', $seq ) ); // phpcs:ignore
		if ( $stored !== $head ) {
			return false;
		}
		$wpdb->insert(
			$wpdb->prefix . 'rc_audit_anchor',
			array(
				'created_at'  => current_time( 'mysql', true ),
				'seq'         => $seq,
				'chain_head'  => $head,
				'network'     => sanitize_key( $network ),
				'tx_hash'     => sanitize_text_field( $tx ),
				'anchored_by' => get_current_user_id(),
			)
		);
		self::record( 'audit.anchored', 'audit', $seq, sprintf( 'Chain head #%d anchored on %s', $seq, $network ), array( 'head' => $head, 'tx' => $tx ) );
		return true;
	}

	/* ---------------------------------------------------------------- hooks */

	public static function on_transition( $new, $old, $post ): void {
		if ( $new === $old || 'auto-draft' === $new || wp_is_post_revision( $post ) || 'nav_menu_item' === $post->post_type || 'attachment' === $post->post_type ) {
			return;
		}
		self::record(
			'content.status',
			$post->post_type,
			(int) $post->ID,
			sprintf( '“%s” %s → %s', $post->post_title, $old, $new ),
			array( 'from' => $old, 'to' => $new )
		);
	}

	public static function on_delete( $post_id, $post = null ): void {
		$post = $post ?: get_post( $post_id );
		if ( ! $post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::record( 'content.deleted', $post->post_type, (int) $post_id, sprintf( 'Permanently deleted “%s”', $post->post_title ), array( 'status' => $post->post_status ) );
	}

	public static function on_login( $login, $user ): void {
		self::record( 'auth.login', 'user', (int) $user->ID, 'Signed in to administration', array( 'mfa' => (bool) get_user_meta( $user->ID, 'rc_mfa_enabled', true ) ), (int) $user->ID );
	}

	public static function on_login_failed( $login ): void {
		self::record( 'auth.login_failed', 'user', 0, 'Failed sign-in attempt', array( 'login_hash' => hash_hmac( 'sha256', (string) $login, wp_salt( 'auth' ) ) ), 0 );
	}

	public static function on_logout( $user_id = 0 ): void {
		self::record( 'auth.logout', 'user', (int) $user_id, 'Signed out', array(), (int) $user_id );
	}

	public static function on_user_register( $user_id ): void {
		self::record( 'user.created', 'user', (int) $user_id, 'User account created', array() );
	}

	public static function on_role_change( $user_id, $role, $old_roles ): void {
		self::record( 'user.role_changed', 'user', (int) $user_id, sprintf( 'Role changed to %s', $role ), array( 'from' => array_values( (array) $old_roles ), 'to' => $role ) );
	}

	public static function on_user_delete( $user_id ): void {
		self::record( 'user.deleted', 'user', (int) $user_id, 'User account deleted', array() );
	}

	public static function on_option( $option, $old, $new ): void {
		$watched = array( 'blogname', 'siteurl', 'home', 'users_can_register', 'default_role', 'admin_email' );
		if ( 0 !== strpos( $option, 'rc_settings' ) && ! in_array( $option, $watched, true ) ) {
			return;
		}
		$diff = array();
		if ( is_array( $old ) && is_array( $new ) ) {
			foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $k ) {
				if ( ( $old[ $k ] ?? null ) !== ( $new[ $k ] ?? null ) ) {
					$diff[ $k ] = array( 'from' => $old[ $k ] ?? null, 'to' => $new[ $k ] ?? null );
				}
			}
		} else {
			$diff = array( 'from' => $old, 'to' => $new );
		}
		if ( $diff ) {
			self::record( 'settings.changed', 'option', 0, sprintf( 'Setting “%s” changed', $option ), $diff );
		}
	}

	public static function on_plugin( $plugin ): void {
		self::record( 'system.plugin_activated', 'plugin', 0, $plugin );
	}

	public static function on_plugin_off( $plugin ): void {
		self::record( 'system.plugin_deactivated', 'plugin', 0, $plugin );
	}

	public static function on_theme( $name ): void {
		self::record( 'system.theme_switched', 'theme', 0, $name );
	}

	public static function on_attachment( $id ): void {
		$file = get_attached_file( $id );
		$hash = ( $file && is_readable( $file ) ) ? hash_file( 'sha256', $file ) : '';
		if ( $hash ) {
			update_post_meta( $id, '_rc_sha256', $hash );
		}
		self::record( 'media.uploaded', 'attachment', (int) $id, basename( (string) $file ), array( 'sha256' => $hash, 'mime' => get_post_mime_type( $id ) ) );
	}
}
