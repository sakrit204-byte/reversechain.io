<?php
/**
 * WP-CLI commands: `wp rc <command>`.
 *
 *   wp rc seed [--pages-only]          Seed demonstration data / resync pages from seed/pages.
 *   wp rc audit-verify                 Recompute the full audit hash chain.
 *   wp rc audit-head                   Print the current chain head (for anchoring).
 *   wp rc tamper-test                  Prove the database rejects UPDATE/DELETE on the audit trail.
 *   wp rc import-doc <file> --title=<t> --type=<type> [--private] [--version=<v>]
 *   wp rc passport <passport_no>       Print a passport as JSON.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class CLI {

	/**
	 * Seed demonstration data.
	 *
	 * [--pages-only]
	 * : Only create missing website pages and menus.
	 *
	 * [--force]
	 * : Overwrite existing pages (EN/ES/IT) and rebuild menus from seed files. Discards CMS edits to those pages.
	 */
	public function seed( $args, $assoc ): void {
		Seed::run( ! empty( $assoc['pages-only'] ), ! empty( $assoc['force'] ) );
		\WP_CLI::success( 'Seed complete.' );
	}

	/**
	 * Verify the full audit chain.
	 *
	 * @subcommand audit-verify
	 */
	public function audit_verify(): void {
		$r = Audit_Log::verify();
		Audit_Log::record( 'audit.verified', 'audit', $r['seq'], $r['ok'] ? sprintf( 'Chain verified intact (%d entries) via CLI', $r['checked'] ) : 'Chain verification FAILED via CLI', array( 'errors' => count( $r['errors'] ) ) );
		foreach ( $r['errors'] as $e ) {
			\WP_CLI::warning( $e['message'] );
		}
		$r['ok'] ? \WP_CLI::success( sprintf( 'Chain intact: %d entries, head %s', $r['checked'], $r['head'] ) ) : \WP_CLI::error( 'Chain integrity FAILURE' );
	}

	/**
	 * Print the chain head.
	 *
	 * @subcommand audit-head
	 */
	public function audit_head(): void {
		\WP_CLI::line( wp_json_encode( Audit_Log::head() ) );
	}

	/**
	 * Demonstrate database-level immutability of the audit trail.
	 *
	 * @subcommand tamper-test
	 */
	public function tamper_test(): void {
		global $wpdb;
		$t = Audit_Log::table();
		$wpdb->suppress_errors( true );
		$u = $wpdb->query( "UPDATE $t SET summary = 'tampered' WHERE id = 1" ); // phpcs:ignore
		$ue = $wpdb->last_error;
		$d = $wpdb->query( "DELETE FROM $t WHERE id = 1" ); // phpcs:ignore
		$de = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		\WP_CLI::log( 'UPDATE → ' . ( false === $u ? 'REJECTED: ' . $ue : 'allowed (triggers missing!)' ) );
		\WP_CLI::log( 'DELETE → ' . ( false === $d ? 'REJECTED: ' . $de : 'allowed (triggers missing!)' ) );
		Audit_Log::record( 'audit.tamper_test', 'audit', 0, 'Immutability self-test executed', array( 'update_blocked' => false === $u, 'delete_blocked' => false === $d ) );
		( false === $u && false === $d ) ? \WP_CLI::success( 'Audit trail is immutable at database level.' ) : \WP_CLI::error( 'Immutability triggers are not active.' );
	}

	/**
	 * Register a document (e.g. the whitepaper PDF) with its SHA-256 fingerprint.
	 *
	 * <file>
	 * : Path to the file.
	 *
	 * --title=<title>
	 * : Document title.
	 *
	 * [--type=<type>]
	 * : Document type key.
	 * ---
	 * default: other
	 * ---
	 *
	 * [--version=<version>]
	 * : Version label.
	 * ---
	 * default: v1.0
	 * ---
	 *
	 * [--private]
	 * : Do not list in the public library.
	 *
	 * @subcommand import-doc
	 */
	public function import_doc( $args, $assoc ): void {
		if ( ! is_readable( $args[0] ) ) {
			\WP_CLI::error( 'File not readable.' );
		}
		$id = Seed::import_document( $args[0], $assoc['title'], $assoc['type'] ?? 'other', empty( $assoc['private'] ), $assoc['version'] ?? 'v1.0' );
		$id ? \WP_CLI::success( sprintf( 'Registered %s — SHA-256 %s', get_post_meta( $id, '_rc_record_no', true ), get_post_meta( $id, '_rc_sha256', true ) ) ) : \WP_CLI::error( 'Import failed.' );
	}

	/**
	 * Print a passport.
	 *
	 * <passport_no>
	 * : e.g. RC-CU-LOT-000001
	 */
	public function passport( $args ): void {
		$post = Registry::find_by_record_no( $args[0] );
		$post ? \WP_CLI::line( wp_json_encode( Passport::build( $post->ID ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) : \WP_CLI::error( 'Not found.' );
	}
}

\WP_CLI::add_command( 'rc', CLI::class );
