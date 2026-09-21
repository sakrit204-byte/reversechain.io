<?php
/**
 * Append-only, hash-chained administrative audit trail.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Audit;

use RuntimeException;

/**
 * Writes and verifies the audit chain.
 *
 * The requirement [Master §16]:
 *   "A complete append-only and tamper-evident administrative audit trail is
 *    mandatory. Audit records must not be editable or removable through the
 *    standard administrative interface."
 *
 * "Tamper-evident" is a stronger claim than "we don't provide a delete button",
 * so it is built as four independent layers. Three of them hold even if this
 * class is bypassed entirely:
 *
 *   1. No update or delete path exists anywhere in the plugin.
 *   2. The runtime database user holds INSERT and SELECT only on the table.
 *   3. BEFORE UPDATE / BEFORE DELETE triggers raise SQLSTATE 45000, so even a
 *      privileged session with direct SQL access is refused.
 *   4. Every row commits to its predecessor:
 *
 *          row_hash = SHA256( prev_hash || canonical_json(payload) )
 *
 *      and a daily Merkle root over the chain is published on-chain. Removing
 *      or altering any historical row breaks every hash after it, and the
 *      break is provable against an anchor nobody controls.
 *
 * Correcting a mistake works the way an accounting ledger does: append a
 * compensating entry that references the original. Nothing is overwritten, so
 * the chain stays verifiable and the correction is itself part of the history.
 */
final class AuditLogger {

	/**
	 * Chain genesis.
	 *
	 * A fixed, published constant rather than a random seed, so an external
	 * auditor can recompute the entire chain from zero without having to trust
	 * us for a starting value.
	 */
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Fields committed to the hash, in a fixed order.
	 *
	 * Order is pinned here rather than taken from array order so that a future
	 * refactor cannot silently change historical hashes.
	 */
	private const HASHED_FIELDS = array(
		'occurred_at',
		'actor_user_id',
		'actor_label',
		'actor_roles',
		'actor_ip',
		'actor_user_agent',
		'request_id',
		'action',
		'entity_type',
		'entity_id',
		'entity_label',
		'field',
		'previous_value',
		'new_value',
		'reason',
		'severity',
	);

	/**
	 * Per-request correlation id, shared by every entry written in one request.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Audit table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rc_audit_log';
	}

	/**
	 * Chain-head table name.
	 */
	private function head_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rc_audit_chain_head';
	}

	/**
	 * Append an entry to the chain.
	 *
	 * @param array<string,mixed> $entry {
	 *     Entry data. Only `action` and `entity_type` are required.
	 *
	 *     @type string      $action         create|update|state_change|publish|…
	 *     @type string      $entity_type    Registry entity or subsystem.
	 *     @type int|null    $entity_id      Affected record id.
	 *     @type string|null $entity_label   Human-readable label, denormalised
	 *                                       so history stays readable after the
	 *                                       source record changes.
	 *     @type string|null $field          Field name for a single-field change.
	 *     @type mixed       $previous_value Value before the change.
	 *     @type mixed       $new_value      Value after the change.
	 *     @type string|null $reason         Required for material changes.
	 *     @type string      $severity       info|notice|warning|critical.
	 * }
	 * @return int Inserted row id.
	 * @throws RuntimeException If the append fails.
	 */
	public function log( array $entry ): int {
		global $wpdb;

		if ( empty( $entry['action'] ) || empty( $entry['entity_type'] ) ) {
			throw new RuntimeException( 'Audit entries require an action and an entity_type.' );
		}

		$payload = $this->build_payload( $entry );

		$head_table  = $this->head_table();
		$audit_table = $this->table();

		$wpdb->query( 'START TRANSACTION' );

		try {
			/*
			 * Serialise chain appends.
			 *
			 * Reading the head, deriving the next hash and advancing the head
			 * must not interleave across concurrent requests or the chain
			 * forks. Locking this single row inside the same transaction as
			 * the insert makes the whole append atomic.
			 *
			 * A MySQL advisory lock (GET_LOCK) would not be safe here: under a
			 * connection pool the lock and the insert can land on different
			 * connections, and the lock would protect nothing.
			 */
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$head = $wpdb->get_row( "SELECT `head_hash`, `entry_count` FROM `{$head_table}` WHERE `id` = 1 FOR UPDATE", ARRAY_A );

			if ( null === $head ) {
				// First ever write, or a head row that was never seeded.
				$wpdb->insert(
					$head_table,
					array(
						'id'          => 1,
						'head_hash'   => self::GENESIS_HASH,
						'entry_count' => 0,
					),
					array( '%d', '%s', '%d' )
				);

				$prev_hash = self::GENESIS_HASH;
			} else {
				$prev_hash = (string) $head['head_hash'];
			}

			$row_hash = self::compute_hash( $prev_hash, $payload );

			$data = $payload + array(
				'prev_hash' => $prev_hash,
				'row_hash'  => $row_hash,
			);

			$inserted = $wpdb->insert( $audit_table, $data, $this->formats_for( $data ) );

			if ( false === $inserted ) {
				throw new RuntimeException( 'Audit append failed: ' . $wpdb->last_error );
			}

			$insert_id = (int) $wpdb->insert_id;

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$advanced = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `{$head_table}` SET `head_hash` = %s, `entry_count` = `entry_count` + 1 WHERE `id` = 1",
					$row_hash
				)
			);

			if ( false === $advanced ) {
				throw new RuntimeException( 'Audit chain head could not be advanced: ' . $wpdb->last_error );
			}

			$wpdb->query( 'COMMIT' );

			return $insert_id;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			throw new RuntimeException( 'Audit append failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Normalise a caller-supplied entry into the stored column set.
	 *
	 * @param array<string,mixed> $entry Raw entry.
	 * @return array<string,mixed>
	 */
	private function build_payload( array $entry ): array {
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;

		$actor_id    = ( $user && $user->ID ) ? (int) $user->ID : null;
		$actor_label = $entry['actor_label'] ?? ( $user && $user->ID ? (string) $user->user_login : 'system' );
		$actor_roles = ( $user && ! empty( $user->roles ) ) ? implode( ',', (array) $user->roles ) : null;

		return array(
			'occurred_at'      => $entry['occurred_at'] ?? gmdate( 'Y-m-d H:i:s.u' ),
			'actor_user_id'    => $entry['actor_user_id'] ?? $actor_id,
			'actor_label'      => $actor_label,
			'actor_roles'      => $actor_roles,
			'actor_ip'         => self::packed_ip(),
			'actor_user_agent' => self::user_agent(),
			'request_id'       => self::request_id(),
			'action'           => (string) $entry['action'],
			'entity_type'      => (string) $entry['entity_type'],
			'entity_id'        => isset( $entry['entity_id'] ) ? (int) $entry['entity_id'] : null,
			'entity_label'     => isset( $entry['entity_label'] ) ? (string) $entry['entity_label'] : null,
			'field'            => isset( $entry['field'] ) ? (string) $entry['field'] : null,
			'previous_value'   => self::encode_value( $entry['previous_value'] ?? null ),
			'new_value'        => self::encode_value( $entry['new_value'] ?? null ),
			'reason'           => isset( $entry['reason'] ) ? (string) $entry['reason'] : null,
			'severity'         => (string) ( $entry['severity'] ?? 'info' ),
		);
	}

	/**
	 * Derive a row hash from its predecessor and its payload.
	 *
	 * @param string              $prev_hash Predecessor hash.
	 * @param array<string,mixed> $payload   Entry payload.
	 */
	public static function compute_hash( string $prev_hash, array $payload ): string {
		$ordered = array();

		foreach ( self::HASHED_FIELDS as $field ) {
			$value = $payload[ $field ] ?? null;

			// Binary columns are hashed as hex so the digest stays text-safe
			// and reproducible by an auditor using plain SQL.
			if ( 'actor_ip' === $field && is_string( $value ) && '' !== $value ) {
				$value = bin2hex( $value );
			}

			$ordered[ $field ] = $value;
		}

		return hash( 'sha256', $prev_hash . self::canonical_json( $ordered ) );
	}

	/**
	 * Deterministic JSON encoding.
	 *
	 * Keys are sorted recursively so that two logically identical payloads
	 * always produce the same bytes, and therefore the same hash, regardless of
	 * PHP array ordering or version.
	 *
	 * @param mixed $value Value to encode.
	 */
	public static function canonical_json( mixed $value ): string {
		$normalised = self::normalise( $value );

		$json = wp_json_encode( $normalised, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $json ? '' : $json;
	}

	/**
	 * Recursively sort array keys.
	 *
	 * @param mixed $value Value to normalise.
	 * @return mixed
	 */
	private static function normalise( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_is_list( $value );

		$value = array_map( static fn ( $item ) => self::normalise( $item ), $value );

		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * Verify a contiguous span of the chain.
	 *
	 * Recomputes each row's hash from its stored payload and its predecessor,
	 * and reports the first divergence. This is what the admin "Verify
	 * integrity" action and the WP-CLI check run.
	 *
	 * @param int $from_id Inclusive lower bound.
	 * @param int $to_id   Inclusive upper bound; 0 means "to the end".
	 * @return array{ok:bool,checked:int,first_bad_id:int|null,message:string}
	 */
	public function verify( int $from_id = 1, int $to_id = 0 ): array {
		global $wpdb;

		$table = $this->table();

		$sql = $to_id > 0
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			? $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `id` BETWEEN %d AND %d ORDER BY `id` ASC", $from_id, $to_id )
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `id` >= %d ORDER BY `id` ASC", $from_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$checked  = 0;
		$expected = null;

		foreach ( (array) $rows as $row ) {
			// The first row examined takes its predecessor from the record
			// itself; every later row must chain from the one before it.
			if ( null !== $expected && $row['prev_hash'] !== $expected ) {
				return array(
					'ok'           => false,
					'checked'      => $checked,
					'first_bad_id' => (int) $row['id'],
					'message'      => sprintf(
						'Chain break at entry %d: prev_hash does not match the preceding entry. An intervening record was altered or removed.',
						(int) $row['id']
					),
				);
			}

			$recomputed = self::compute_hash( (string) $row['prev_hash'], $row );

			if ( ! hash_equals( (string) $row['row_hash'], $recomputed ) ) {
				return array(
					'ok'           => false,
					'checked'      => $checked,
					'first_bad_id' => (int) $row['id'],
					'message'      => sprintf(
						'Content mismatch at entry %d: the stored hash does not match the stored payload. This record was modified after it was written.',
						(int) $row['id']
					),
				);
			}

			$expected = (string) $row['row_hash'];
			$checked++;
		}

		return array(
			'ok'           => true,
			'checked'      => $checked,
			'first_bad_id' => null,
			'message'      => sprintf( 'Verified %d audit entries; the hash chain is intact.', $checked ),
		);
	}

	/**
	 * Encode a value for storage.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function encode_value( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return self::canonical_json( $value );
	}

	/**
	 * Per-request correlation id, so related entries can be grouped.
	 */
	private static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: bin2hex( random_bytes( 16 ) );
		}

		return self::$request_id;
	}

	/**
	 * Client IP in packed binary form, or null when unavailable.
	 *
	 * Stored packed rather than as text so IPv4 and IPv6 share one column and
	 * range queries stay possible.
	 */
	private static function packed_ip(): ?string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $raw ) {
			return null;
		}

		$packed = @inet_pton( $raw );

		return false === $packed ? null : $packed;
	}

	/**
	 * Truncated user agent string.
	 */
	private static function user_agent(): ?string {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return null;
		}

		$agent = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );

		return '' === $agent ? null : mb_substr( $agent, 0, 255 );
	}

	/**
	 * wpdb format specifiers matching a data array.
	 *
	 * @param array<string,mixed> $data Row data.
	 * @return string[]
	 */
	private function formats_for( array $data ): array {
		$integers = array( 'actor_user_id', 'entity_id' );

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $integers, true ) ? '%d' : '%s';
		}

		return $formats;
	}
}
