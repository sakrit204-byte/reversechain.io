<?php
/**
 * Proof of Industrial Metal Reserves.
 *
 *  - Reconciliation engine: registry inventory (declared vs verified), custody, reserve eligibility,
 *    token supply (read on-chain when a testnet contract is configured), latest attestation, coverage.
 *  - Exception rules surface problems instead of smoothing them away.
 *  - Snapshots: canonical JSON + SHA-256 + Merkle root over unit fingerprints; four-eyes approval, then
 *    publication (only when the proof_of_reserves module is authorized). Every step is in the audit chain.
 *  - Attestation intake: upload + fingerprint + rc_reserve_report draft routed to the review queue.
 *  - Daily cron alerts when the exception set changes; weekly draft snapshot.
 *
 * Nothing here ever describes uploaded information as "independently verified" unless the reserve
 * report itself is approved with status verified.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Por {

	public const SEVERITY = array( 'critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Information' );
	private const STALE_ATTESTATION_DAYS = 90;
	private const STALE_VALUATION_DAYS   = 180;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rc_por_snapshots';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				program_id BIGINT UNSIGNED NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'draft',
				approved_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				approved_at DATETIME NULL,
				published_at DATETIME NULL,
				sha256 CHAR(64) NOT NULL,
				merkle_root CHAR(64) NOT NULL DEFAULT '',
				exceptions INT UNSIGNED NOT NULL DEFAULT 0,
				data LONGTEXT NOT NULL,
				note VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY program_id (program_id),
				KEY status (status)
			) {$wpdb->get_charset_collate()};"
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_rc_por', array( __CLASS__, 'handle' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'rc_por_history', array( __CLASS__, 'sc_history' ) );
		add_shortcode( 'rc_por_exceptions', array( __CLASS__, 'sc_exceptions' ) );
		add_action( 'rc_por_daily', array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( 'rc_por_daily' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'rc_por_daily' );
		}
	}

	/* =====================================================================
	   Reconciliation engine
	   ===================================================================== */

	public static function programs(): array {
		return get_posts( array( 'post_type' => 'rc_program', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
	}

	/** @return array full reconciliation for one program (deterministic, JSON-serialisable). */
	public static function reconcile( int $pid ): array {
		$units = get_posts( array( 'post_type' => Schema::PASSPORT_TYPES, 'post_status' => 'publish', 'posts_per_page' => 1000, 'meta_key' => '_rc_program', 'meta_value' => $pid, 'orderby' => 'ID', 'order' => 'ASC' ) ); // phpcs:ignore
		$m     = static fn( int $id, string $k ) => (string) get_post_meta( $id, '_rc_' . $k, true );

		$rows        = array();
		$fingerprint = array();
		$inv         = array(
			'units_total'      => 0,
			'by_type'          => array(),
			'declared_kg'      => 0.0,
			'verified_kg'      => 0.0,
			'units_verified'   => 0,
			'units_in_custody' => 0,
			'units_eligible'   => 0,
			'units_accepted'   => 0,
			'units_in_redemption' => 0,
		);
		$exceptions = array();
		$now        = time();

		foreach ( $units as $u ) {
			$pass   = Passport::build( $u->ID );
			$type   = $u->post_type;
			$no     = $m( $u->ID, 'record_no' );
			$ver    = $m( $u->ID, 'verification_status' ) ?: 'in_development';
			$cust   = $m( $u->ID, 'custody_status' ) ?: 'pending';
			$res    = $m( $u->ID, 'reserve_status' ) ?: 'pending';
			$red    = $m( $u->ID, 'redemption_status' ) ?: 'not_available';
			$kg     = (float) $m( $u->ID, 'net_weight' );
			$coas   = array_values( array_filter( $pass['evidence'], static fn( $e ) => 'rc_coa' === $e['type'] ) );

			++$inv['units_total'];
			$inv['by_type'][ $type ] = ( $inv['by_type'][ $type ] ?? 0 ) + 1;
			if ( 'rc_lot' === $type ) {
				$inv['declared_kg'] += $kg;
				if ( 'verified' === $ver ) {
					$inv['verified_kg'] += $kg;
				}
			}
			$inv['units_verified']   += 'verified' === $ver ? 1 : 0;
			$inv['units_in_custody'] += 'arranged' === $cust ? 1 : 0;
			$inv['units_eligible']   += in_array( $res, array( 'eligible_pending_approval', 'accepted' ), true ) ? 1 : 0;
			$inv['units_accepted']   += 'accepted' === $res ? 1 : 0;
			$inv['units_in_redemption'] += in_array( $red, array( 'requested', 'released' ), true ) ? 1 : 0;

			$fingerprint[] = $pass['record_fingerprint'];
			$rows[]        = array(
				'record_no'    => $no,
				'type'         => $type,
				'title'        => $u->post_title,
				'net_weight_kg' => $kg ?: null,
				'verification' => $ver,
				'custody'      => $cust,
				'reserve'      => $res,
				'redemption'   => $red,
				'coa'          => array_map( static fn( $c ) => $c['record_no'], $coas ),
				'documents'    => count( $pass['documents'] ),
				'merkle_root'  => $pass['merkle_root'],
			);

			// ---- exception rules (per unit) ----
			if ( ! $coas && 'rc_lot' === $type ) {
				$exceptions[] = self::exc( 'warning', 'no_coa', $no, __( 'No Certificate of Analysis linked to this lot.', 'reservechain' ) );
			}
			foreach ( $coas as $c ) {
				if ( 'owner_supplied' === get_post_meta( (int) $c['id'], '_rc_provenance', true ) && ! $c['inherited'] ) {
					$exceptions[] = self::exc( 'info', 'owner_supplied_only', $no, sprintf( __( 'Certificate %s is owner-supplied and not independently verified.', 'reservechain' ), $c['record_no'] ) );
				}
			}
			if ( in_array( $res, array( 'eligible_pending_approval', 'accepted' ), true ) && 'arranged' !== $cust ) {
				$exceptions[] = self::exc( 'critical', 'reserve_without_custody', $no, __( 'Unit is marked reserve-eligible but custody is not evidenced.', 'reservechain' ) );
			}
			if ( 'rc_lot' === $type && ! $kg ) {
				$exceptions[] = self::exc( 'warning', 'missing_weight', $no, __( 'Net weight not recorded.', 'reservechain' ) );
			}
			if ( 'accepted' === $res && in_array( $red, array( 'requested', 'released' ), true ) ) {
				$exceptions[] = self::exc( 'warning', 'redemption_overlap', $no, __( 'Unit is accepted into reserve while a redemption is open — risk of double counting.', 'reservechain' ) );
			}
			foreach ( $pass['documents'] as $doc ) {
				if ( empty( $doc['sha256'] ) ) {
					$exceptions[] = self::exc( 'critical', 'unfingerprinted_document', $no, sprintf( __( 'Document %s has no SHA-256 fingerprint.', 'reservechain' ), $doc['record_no'] ) );
				}
			}
			foreach ( $pass['evidence'] as $ev ) {
				if ( 'rc_insurance' === $ev['type'] ) {
					$end = (string) get_post_meta( (int) $ev['id'], '_rc_period_end', true );
					if ( $end && strtotime( $end ) < $now ) {
						$exceptions[] = self::exc( 'critical', 'insurance_expired', $no, sprintf( __( 'Insurance %s expired on %s.', 'reservechain' ), $ev['record_no'], $end ) );
					} elseif ( $end && strtotime( $end ) < $now + 30 * DAY_IN_SECONDS ) {
						$exceptions[] = self::exc( 'warning', 'insurance_expiring', $no, sprintf( __( 'Insurance %s expires on %s.', 'reservechain' ), $ev['record_no'], $end ) );
					}
				}
				if ( 'rc_valuation' === $ev['type'] && 'accepted' === $res ) {
					$vd = (string) get_post_meta( (int) $ev['id'], '_rc_valuation_date', true );
					if ( ! $vd || strtotime( $vd ) < $now - self::STALE_VALUATION_DAYS * DAY_IN_SECONDS ) {
						$exceptions[] = self::exc( 'warning', 'stale_valuation', $no, __( 'Valuation missing or older than 180 days for a reserve unit.', 'reservechain' ) );
					}
				}
			}
		}

		// ---- attestation ----
		$reports     = get_posts( array( 'post_type' => 'rc_reserve_report', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_rc_program', 'meta_value' => $pid, 'orderby' => 'date', 'order' => 'DESC' ) ); // phpcs:ignore
		$attestation = null;
		if ( $reports ) {
			$r           = $reports[0];
			$date        = $m( $r->ID, 'report_date' );
			$attestation = array(
				'record_no'  => $m( $r->ID, 'record_no' ),
				'date'       => $date ?: null,
				'attestor'   => $m( $r->ID, 'attestor' ) ?: null,
				'units'      => '' !== $m( $r->ID, 'reserve_units' ) ? (float) $m( $r->ID, 'reserve_units' ) : null,
				'verified'   => 'verified' === $m( $r->ID, 'verification_status' ),
				'stale'      => ! $date || strtotime( $date ) < $now - self::STALE_ATTESTATION_DAYS * DAY_IN_SECONDS,
				'onchain_tx' => $m( $r->ID, 'onchain_tx' ) ?: null,
			);
			if ( $attestation['stale'] ) {
				$exceptions[] = self::exc( 'warning', 'stale_attestation', $attestation['record_no'], __( 'Latest reserve attestation is older than 90 days or undated.', 'reservechain' ) );
			}
		} else {
			$exceptions[] = self::exc( 'info', 'no_attestation', get_post_meta( $pid, '_rc_record_no', true ), __( 'No reserve attestation has been published for this program.', 'reservechain' ) );
		}

		// ---- supply (on-chain, testnet only) ----
		$supply = self::supply( $pid );
		if ( $supply['issued'] > 0 && ! ( $attestation['verified'] ?? false ) ) {
			$exceptions[] = self::exc( 'critical', 'supply_without_attestation', get_post_meta( $pid, '_rc_record_no', true ), __( 'Tokens are outstanding but no verified attestation exists.', 'reservechain' ) );
		}

		// ---- coverage: only from a verified attestation, a known supply and a numeric approved ratio ----
		$ratio    = self::ratio( $pid );
		$coverage = array( 'status' => 'not_computed', 'value' => null, 'reason' => __( 'Computed only from a verified attestation, an approved asset-to-token ratio and a known token supply.', 'reservechain' ) );
		if ( ( $attestation['verified'] ?? false ) && null !== $attestation['units'] && $ratio && $supply['issued'] > 0 ) {
			$allowed = $attestation['units'] * $ratio;
			$coverage = array( 'status' => 'computed', 'value' => round( $allowed / $supply['issued'], 6 ), 'reason' => null );
			if ( $supply['issued'] > $allowed ) {
				$exceptions[] = self::exc( 'critical', 'supply_exceeds_reserve', get_post_meta( $pid, '_rc_record_no', true ), __( 'Token supply exceeds the attested reserve allowance.', 'reservechain' ) );
			}
		}

		usort( $exceptions, static fn( $a, $b ) => array_search( $a['severity'], array_keys( self::SEVERITY ), true ) <=> array_search( $b['severity'], array_keys( self::SEVERITY ), true ) );

		return array(
			'schema'       => 'reservechain.por/1.0',
			'program'      => array( 'id' => $pid, 'record_no' => get_post_meta( $pid, '_rc_record_no', true ), 'name' => get_the_title( $pid ), 'symbol' => get_post_meta( $pid, '_rc_symbol', true ) ),
			'generated_at' => gmdate( 'c' ),
			'inventory'    => $inv,
			'attestation'  => $attestation,
			'supply'       => $supply,
			'coverage'     => $coverage,
			'exceptions'   => $exceptions,
			'units'        => $rows,
			'units_merkle_root' => Passport::merkle_root( $fingerprint ),
			'audit_head'   => Audit_Log::head(),
			'statement'    => __( 'Declared quantities are owner-supplied and are not verified reserves. Coverage is never estimated.', 'reservechain' ),
		);
	}

	private static function exc( string $sev, string $code, string $ref, string $msg ): array {
		return array( 'severity' => $sev, 'code' => $code, 'ref' => $ref, 'message' => $msg );
	}

	/** Numeric asset-to-token ratio from the approved token program, or null (never assumed). */
	private static function ratio( int $pid ): ?float {
		$tp = Registry::referencing( $pid, array( 'rc_token_program' ) );
		if ( ! $tp || 'approved' !== get_post_meta( $tp[0]->ID, '_rc_token_state', true ) && 'published' !== get_post_meta( $tp[0]->ID, '_rc_token_state', true ) ) {
			return null;
		}
		$raw = (string) get_post_meta( $tp[0]->ID, '_rc_asset_to_token_ratio', true );
		return is_numeric( $raw ) && (float) $raw > 0 ? (float) $raw : null;
	}

	/** Token supply via JSON-RPC eth_call (testnet chain IDs only). */
	public static function supply( int $pid ): array {
		$out = array( 'status' => 'not_deployed', 'issued' => 0.0, 'contract' => null, 'chain_id' => null, 'read_at' => null );
		$tp  = Registry::referencing( $pid, array( 'rc_token_program' ) );
		$addr = $tp ? (string) get_post_meta( $tp[0]->ID, '_rc_contract_address', true ) : '';
		$net  = Settings::get( 'network', array() );
		$rpc  = (string) get_option( 'rc_web3_rpc_url', '' );
		if ( ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $addr ) ) {
			return $out;
		}
		$out['contract'] = $addr;
		$out['chain_id'] = (int) ( $net['chain_id'] ?? 0 );
		if ( ! $rpc || ! in_array( $out['chain_id'], array( 11155111, 80002, 31337 ), true ) ) {
			$out['status'] = 'rpc_not_configured';
			return $out;
		}
		$call = static function ( string $data ) use ( $rpc, $addr ) {
			$r = wp_remote_post( $rpc, array( 'timeout' => 8, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_call', 'params' => array( array( 'to' => $addr, 'data' => $data ), 'latest' ) ) ) ) );
			$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
			return is_array( $j ) && isset( $j['result'] ) ? $j['result'] : null;
		};
		$ts  = $call( '0x18160ddd' ); // totalSupply()
		$dec = $call( '0x313ce567' ); // decimals()
		if ( null === $ts ) {
			$out['status'] = 'rpc_error';
			return $out;
		}
		$decimals      = $dec ? hexdec( substr( $dec, -2 ) ) : 18;
		$out['issued'] = self::hex_to_float( $ts ) / pow( 10, $decimals );
		$out['status'] = 'read';
		$out['read_at'] = gmdate( 'c' );
		return $out;
	}

	private static function hex_to_float( string $hex ): float {
		$hex = ltrim( substr( $hex, 2 ), '0' );
		$v   = 0.0;
		foreach ( str_split( $hex ?: '0' ) as $c ) {
			$v = $v * 16 + hexdec( $c );
		}
		return $v;
	}

	/* =====================================================================
	   Snapshots
	   ===================================================================== */

	public static function canonical( array $data ): string {
		$sort = static function ( $v ) use ( &$sort ) {
			if ( is_array( $v ) ) {
				if ( ! array_is_list( $v ) ) {
					ksort( $v );
				}
				return array_map( $sort, $v );
			}
			return $v;
		};
		return (string) wp_json_encode( $sort( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function create_snapshot( int $pid, string $note = '' ): int {
		global $wpdb;
		$data = self::reconcile( $pid );
		$json = self::canonical( $data );
		$sha  = hash( 'sha256', $json );
		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql', true ),
				'program_id'  => $pid,
				'created_by'  => get_current_user_id(),
				'status'      => 'draft',
				'sha256'      => $sha,
				'merkle_root' => (string) $data['units_merkle_root'],
				'exceptions'  => count( $data['exceptions'] ),
				'data'        => $json,
				'note'        => mb_substr( sanitize_text_field( $note ), 0, 255 ),
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit_Log::record( 'por.snapshot_created', 'por_snapshot', $id, sprintf( 'Reserve snapshot #%d created for %s', $id, get_the_title( $pid ) ), array( 'sha256' => $sha, 'merkle_root' => $data['units_merkle_root'], 'exceptions' => count( $data['exceptions'] ) ) );
		return $id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/** Integrity check: stored JSON must still hash to the stored SHA-256. */
	public static function intact( array $row ): bool {
		return hash_equals( $row['sha256'], hash( 'sha256', $row['data'] ) );
	}

	public static function transition( int $id, string $action ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'rc_por', 'Snapshot not found.' );
		}
		if ( ! self::intact( $row ) ) {
			return new \WP_Error( 'rc_por', 'Snapshot content no longer matches its fingerprint.' );
		}
		$me = get_current_user_id();
		if ( 'approve' === $action ) {
			if ( 'draft' !== $row['status'] || ! current_user_can( 'rc_approve' ) ) {
				return new \WP_Error( 'rc_por', 'Only drafts can be approved, by a reviewer.' );
			}
			if ( (int) $row['created_by'] === $me ) {
				return new \WP_Error( 'rc_por', 'Four-eyes rule: the creator of a snapshot cannot approve it.' );
			}
			$wpdb->update( self::table(), array( 'status' => 'approved', 'approved_by' => $me, 'approved_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		} elseif ( 'publish' === $action ) {
			if ( 'approved' !== $row['status'] || ! current_user_can( 'rc_publish' ) ) {
				return new \WP_Error( 'rc_por', 'Only approved snapshots can be published, by a publisher.' );
			}
			if ( ! Settings::module_on( 'proof_of_reserves' ) ) {
				return new \WP_Error( 'rc_por', 'Publication is locked: the Proof of Reserves module has not been authorized.' );
			}
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'superseded' WHERE program_id = %d AND status = 'published'", (int) $row['program_id'] ) ); // phpcs:ignore
			$wpdb->update( self::table(), array( 'status' => 'published', 'published_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
		} elseif ( 'reject' === $action ) {
			if ( ! in_array( $row['status'], array( 'draft', 'approved' ), true ) || ! current_user_can( 'rc_approve' ) ) {
				return new \WP_Error( 'rc_por', 'Not allowed.' );
			}
			$wpdb->update( self::table(), array( 'status' => 'rejected' ), array( 'id' => $id ) );
		} else {
			return new \WP_Error( 'rc_por', 'Unknown action.' );
		}
		Audit_Log::record( 'por.snapshot_' . $action, 'por_snapshot', $id, sprintf( 'Reserve snapshot #%d %s', $id, $action ), array( 'sha256' => $row['sha256'] ) );
		return true;
	}

	public static function published( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, program_id, published_at, sha256, merkle_root, exceptions, status FROM ' . self::table() . " WHERE status IN ('published','superseded') ORDER BY published_at DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore
	}

	/* =====================================================================
	   Cron: alerts + weekly draft snapshot
	   ===================================================================== */

	public static function cron(): void {
		$sig = array();
		foreach ( self::programs() as $p ) {
			$r = self::reconcile( $p->ID );
			foreach ( $r['exceptions'] as $e ) {
				$sig[] = $e['severity'] . '|' . $e['code'] . '|' . $e['ref'];
			}
			if ( 1 === (int) gmdate( 'N' ) ) { // Mondays: weekly draft snapshot for review.
				wp_set_current_user( 0 );
				self::create_snapshot( $p->ID, 'Weekly automatic draft' );
			}
		}
		sort( $sig );
		$hash = hash( 'sha256', implode( "\n", $sig ) );
		if ( get_option( 'rc_por_exceptions_hash' ) !== $hash ) {
			update_option( 'rc_por_exceptions_hash', $hash, false );
			Audit_Log::record( 'por.exceptions_changed', 'por', 0, sprintf( 'Reconciliation exceptions changed (%d open)', count( $sig ) ), array( 'hash' => $hash ), 0 );
			$to = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
			wp_mail( $to, '[ReserveChain] Reconciliation exceptions changed', sprintf( "Open exceptions: %d\n\n%s\n\nReview: %s", count( $sig ), implode( "\n", $sig ), admin_url( 'admin.php?page=rc-por' ) ) );
		}
	}

	/* =====================================================================
	   REST
	   ===================================================================== */

	public static function routes(): void {
		$public = array( Rest::class, 'public_gate' );
		register_rest_route( Rest::NS, '/por', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'api_overview' ), 'permission_callback' => $public ) );
		register_rest_route( Rest::NS, '/por/snapshots', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'api_list' ), 'permission_callback' => $public ) );
		register_rest_route( Rest::NS, '/por/snapshots/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'api_get' ), 'permission_callback' => $public ) );
	}

	public static function api_overview(): array {
		$out = array( 'module_enabled' => Settings::module_on( 'proof_of_reserves' ), 'programs' => array() );
		foreach ( self::programs() as $p ) {
			$r      = self::reconcile( $p->ID );
			$counts = array_count_values( array_column( $r['exceptions'], 'severity' ) );
			$last   = array_values( array_filter( self::published( 20 ), static fn( $s ) => (int) $s['program_id'] === $p->ID && 'published' === $s['status'] ) );
			$out['programs'][] = array(
				'program'     => $r['program'],
				'inventory'   => $r['inventory'],
				'supply'      => array( 'status' => $r['supply']['status'], 'issued' => $r['supply']['issued'] ),
				'attestation' => $r['attestation'] ? array_intersect_key( $r['attestation'], array_flip( array( 'record_no', 'date', 'verified', 'stale' ) ) ) : null,
				'coverage'    => $r['coverage'],
				'exceptions'  => array( 'critical' => $counts['critical'] ?? 0, 'warning' => $counts['warning'] ?? 0, 'info' => $counts['info'] ?? 0 ),
				'latest_published' => $last ? array( 'id' => (int) $last[0]['id'], 'sha256' => $last[0]['sha256'], 'published_at' => $last[0]['published_at'] . 'Z' ) : null,
			);
		}
		return $out;
	}

	public static function api_list() {
		if ( ! Settings::module_on( 'proof_of_reserves' ) ) {
			return new \WP_Error( 'rc_module_inactive', 'Reserve reports are not published until the Proof of Reserves module is authorized.', array( 'status' => 403 ) );
		}
		return array_map( static fn( $s ) => array( 'id' => (int) $s['id'], 'program_id' => (int) $s['program_id'], 'published_at' => $s['published_at'] . 'Z', 'sha256' => $s['sha256'], 'merkle_root' => $s['merkle_root'], 'exceptions' => (int) $s['exceptions'], 'current' => 'published' === $s['status'] ), self::published() );
	}

	public static function api_get( \WP_REST_Request $r ) {
		$row = self::get( (int) $r['id'] );
		if ( ! $row || ! in_array( $row['status'], array( 'published', 'superseded' ), true ) || ! Settings::module_on( 'proof_of_reserves' ) ) {
			return new \WP_Error( 'rc_not_found', 'Snapshot not found.', array( 'status' => 404 ) );
		}
		return array( 'id' => (int) $row['id'], 'sha256' => $row['sha256'], 'intact' => self::intact( $row ), 'published_at' => $row['published_at'] . 'Z', 'snapshot' => json_decode( $row['data'], true ) );
	}

	/* =====================================================================
	   Shortcodes
	   ===================================================================== */

	public static function sc_exceptions( $atts ): string {
		$a     = shortcode_atts( array( 'program' => '' ), $atts );
		$progs = $a['program'] ? array_filter( array( get_page_by_path( sanitize_title( $a['program'] ), OBJECT, 'rc_program' ) ) ) : self::programs();
		$out   = '<div class="rc-exc">';
		foreach ( $progs as $p ) {
			$r = self::reconcile( $p->ID );
			$out .= '<div class="rc-exc__prog"><h3>' . esc_html( get_the_title( $p ) ) . '</h3>';
			if ( ! $r['exceptions'] ) {
				$out .= '<p class="rc-empty">' . esc_html__( 'No open reconciliation exceptions.', 'reservechain' ) . '</p>';
			}
			$out .= '<ul>';
			foreach ( $r['exceptions'] as $e ) {
				$out .= sprintf( '<li class="rc-exc__item rc-exc__item--%1$s"><span class="rc-exc__sev">%2$s</span><span class="rc-mono">%3$s</span> %4$s</li>', esc_attr( $e['severity'] ), esc_html( __( self::SEVERITY[ $e['severity'] ], 'reservechain' ) ), esc_html( $e['ref'] ), esc_html( $e['message'] ) ); // phpcs:ignore
			}
			$out .= '</ul></div>';
		}
		return $out . '<p class="rc-fine">' . esc_html__( 'Exceptions are computed from the registry in real time and are published rather than smoothed over.', 'reservechain' ) . '</p></div>';
	}

	public static function sc_history(): string {
		if ( ! Settings::module_on( 'proof_of_reserves' ) ) {
			return Shortcodes::module( array( 'module' => 'proof_of_reserves', 'title' => __( 'Published reserve reports', 'reservechain' ) ) );
		}
		$rows = self::published();
		if ( ! $rows ) {
			return '<p class="rc-empty">' . esc_html__( 'No reserve report has been published yet.', 'reservechain' ) . '</p>';
		}
		$out = '<div class="rc-table-wrap"><table class="rc-table"><thead><tr><th>' . esc_html__( 'Published', 'reservechain' ) . '</th><th>' . esc_html__( 'Program', 'reservechain' ) . '</th><th>SHA-256</th><th>' . esc_html__( 'Exceptions', 'reservechain' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			$out .= sprintf( '<tr><td>%s</td><td>%s</td><td><code class="rc-mono" data-copy>%s</code></td><td>%d</td><td><a href="%s">JSON</a></td></tr>', esc_html( substr( $s['published_at'], 0, 10 ) ), esc_html( get_the_title( (int) $s['program_id'] ) ), esc_html( substr( $s['sha256'], 0, 16 ) . '…' ), (int) $s['exceptions'], esc_url( rest_url( Rest::NS . '/por/snapshots/' . $s['id'] ) ) );
		}
		return $out . '</tbody></table></div>';
	}

	/* =====================================================================
	   Admin
	   ===================================================================== */

	public static function menu(): void {
		add_submenu_page( 'reservechain', 'Proof of Reserves', 'Proof of Reserves', 'edit_rc_entities', 'rc-por', array( __CLASS__, 'page' ) );
	}

	private static function url( string $do, array $extra = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'rc_por', 'do' => $do ), $extra ), admin_url( 'admin-post.php' ) ), 'rc_por_' . $do );
	}

	public static function page(): void {
		global $wpdb;
		$msg = get_transient( 'rc_por_msg_' . get_current_user_id() );
		delete_transient( 'rc_por_msg_' . get_current_user_id() );
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Proof of Reserves <small>reconciliation · exceptions · snapshots · attestations</small></h1>';
		if ( $msg ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
		}
		printf( '<p>Publication module: %s. Snapshots can be created and approved at any time; publication requires the authorized <code>proof_of_reserves</code> module.</p>', Settings::module_on( 'proof_of_reserves' ) ? '<span class="rc-pill rc-pill-verified">authorized</span>' : '<span class="rc-pill rc-pill-in_development">locked</span>' ); // phpcs:ignore

		foreach ( self::programs() as $p ) {
			$r   = self::reconcile( $p->ID );
			$inv = $r['inventory'];
			echo '<div class="rc-panel"><h2>' . esc_html( get_the_title( $p ) ) . ' <small><code>' . esc_html( $r['program']['record_no'] ) . '</code></small></h2>';
			echo '<div class="rc-cards">';
			$cards = array(
				'Registered units'      => $inv['units_total'],
				'Declared kg (owner)'   => rtrim( rtrim( number_format( $inv['declared_kg'], 3 ), '0' ), '.' ),
				'Verified kg'           => rtrim( rtrim( number_format( $inv['verified_kg'], 3 ), '0' ), '.' ),
				'Units in custody'      => $inv['units_in_custody'],
				'Reserve-eligible'      => $inv['units_eligible'],
				'Token supply'          => 'read' === $r['supply']['status'] ? $r['supply']['issued'] : $r['supply']['status'],
				'Coverage'              => 'computed' === $r['coverage']['status'] ? $r['coverage']['value'] : 'not computed',
				'Open exceptions'       => count( $r['exceptions'] ),
			);
			foreach ( $cards as $k => $v ) {
				printf( '<div class="rc-card"><span>%s</span><strong>%s</strong></div>', esc_html( $k ), esc_html( (string) $v ) );
			}
			echo '</div>';
			if ( $r['exceptions'] ) {
				echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Reference</th><th>Exception</th></tr></thead><tbody>';
				foreach ( $r['exceptions'] as $e ) {
					$cls = array( 'critical' => 'restricted', 'warning' => 'pending_verification', 'info' => 'proposed' )[ $e['severity'] ];
					printf( '<tr><td><span class="rc-pill rc-pill-%s">%s</span></td><td><code>%s</code></td><td>%s</td></tr>', esc_attr( $cls ), esc_html( self::SEVERITY[ $e['severity'] ] ), esc_html( $e['ref'] ), esc_html( $e['message'] ) );
				}
				echo '</tbody></table>';
			}
			printf( '<p><a class="button button-primary" href="%s">Create snapshot</a> <a class="button" href="%s">Download live reconciliation (JSON)</a> <a class="button" href="%s">Units (CSV)</a></p>', esc_url( self::url( 'snapshot', array( 'program' => $p->ID ) ) ), esc_url( self::url( 'live_json', array( 'program' => $p->ID ) ) ), esc_url( self::url( 'live_csv', array( 'program' => $p->ID ) ) ) );
			echo '</div>';
		}

		// Snapshot history.
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 50', ARRAY_A ); // phpcs:ignore
		echo '<div class="rc-panel"><h2>Snapshots</h2><table class="widefat striped"><thead><tr><th>#</th><th>Created</th><th>Program</th><th>Status</th><th>SHA-256</th><th>Merkle root (units)</th><th>Exceptions</th><th>Integrity</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $s ) {
			$u   = get_userdata( (int) $s['created_by'] );
			$act = '';
			if ( 'draft' === $s['status'] ) {
				$act .= '<a class="button button-small" href="' . esc_url( self::url( 'approve', array( 'id' => $s['id'] ) ) ) . '">Approve</a> <a class="button button-small" href="' . esc_url( self::url( 'reject', array( 'id' => $s['id'] ) ) ) . '">Reject</a> ';
			}
			if ( 'approved' === $s['status'] ) {
				$act .= '<a class="button button-small button-primary" href="' . esc_url( self::url( 'publish', array( 'id' => $s['id'] ) ) ) . '">Publish</a> ';
			}
			$act .= '<a class="button button-small" href="' . esc_url( self::url( 'export', array( 'id' => $s['id'] ) ) ) . '">JSON</a>';
			printf(
				'<tr><td>%d</td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td><td><code title="%s">%s…</code></td><td><code>%s…</code></td><td>%d</td><td>%s</td><td>%s</td></tr>',
				(int) $s['id'],
				esc_html( substr( $s['created_at'], 0, 16 ) ),
				esc_html( $u ? $u->user_login : 'system (cron)' ),
				esc_html( get_the_title( (int) $s['program_id'] ) ),
				esc_html( $s['status'] ),
				esc_attr( $s['sha256'] ),
				esc_html( substr( $s['sha256'], 0, 12 ) ),
				esc_html( substr( $s['merkle_root'], 0, 12 ) ),
				(int) $s['exceptions'],
				self::intact( $s ) ? '<span class="rc-pill rc-pill-verified">intact</span>' : '<span class="rc-pill rc-pill-restricted">MODIFIED</span>', // phpcs:ignore
				$act // phpcs:ignore
			);
		}
		echo '</tbody></table><p class="description">Four-eyes: the creator of a snapshot cannot approve it. Every action is recorded in the audit chain together with the snapshot SHA-256, so published reports are anchored whenever the audit chain is anchored on-chain.</p></div>';

		// Attestation intake.
		echo '<div class="rc-panel"><h2>Record a reserve attestation</h2><p class="description">Creates a Reserve Report draft linked to a fingerprinted document and submits it to the review queue. It is shown as verified only after an independent reviewer approves it with status “verified”.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_por"><input type="hidden" name="do" value="attest">';
		wp_nonce_field( 'rc_por_attest' );
		echo '<table class="form-table"><tr><th>Program</th><td><select name="program">';
		foreach ( self::programs() as $p ) {
			printf( '<option value="%d">%s</option>', (int) $p->ID, esc_html( get_the_title( $p ) ) );
		}
		echo '</select></td></tr><tr><th>Attestor</th><td><input name="attestor" class="regular-text" required></td></tr><tr><th>Report date</th><td><input type="date" name="report_date" required></td></tr><tr><th>Attested reserve units</th><td><input type="number" step="any" min="0" name="reserve_units"> <span class="description">leave empty if the report states none</span></td></tr><tr><th>Report document</th><td><input type="file" name="report_file" accept=".pdf,.png,.jpg,.jpeg" required></td></tr><tr><th>On-chain attestation tx (optional)</th><td><input name="onchain_tx" class="regular-text rc-mono" placeholder="0x…"></td></tr></table>';
		submit_button( 'Submit attestation for review' );
		echo '</form></div></div>';
	}

	public static function handle(): void {
		$do = sanitize_key( $_REQUEST['do'] ?? '' );
		check_admin_referer( 'rc_por_' . $do );
		$uid = get_current_user_id();
		$msg = '';
		switch ( $do ) {
			case 'snapshot':
				if ( ! current_user_can( 'rc_manage_registry' ) && ! current_user_can( 'rc_approve' ) ) {
					wp_die( 'Forbidden', 403 );
				}
				$id  = self::create_snapshot( absint( $_GET['program'] ?? 0 ), 'Manual snapshot' );
				$msg = sprintf( 'Snapshot #%d created as draft. A different reviewer must approve it.', $id );
				break;
			case 'approve':
			case 'publish':
			case 'reject':
				$res = self::transition( absint( $_GET['id'] ?? 0 ), $do );
				$msg = is_wp_error( $res ) ? $res->get_error_message() : 'Snapshot ' . $do . 'd.';
				break;
			case 'export':
			case 'live_json':
			case 'live_csv':
				if ( ! current_user_can( 'rc_view_audit' ) ) {
					wp_die( 'Forbidden', 403 );
				}
				nocache_headers();
				if ( 'export' === $do ) {
					$row = self::get( absint( $_GET['id'] ?? 0 ) );
					if ( ! $row ) {
						wp_die( 'Not found', 404 );
					}
					Audit_Log::record( 'por.snapshot_exported', 'por_snapshot', (int) $row['id'], 'Reserve snapshot exported' );
					header( 'Content-Type: application/json' );
					header( 'Content-Disposition: attachment; filename="reservechain-por-' . (int) $row['id'] . '-' . substr( $row['sha256'], 0, 12 ) . '.json"' );
					echo $row['data']; // phpcs:ignore -- canonical JSON exactly as hashed.
					exit;
				}
				$r = self::reconcile( absint( $_GET['program'] ?? 0 ) );
				Audit_Log::record( 'por.live_exported', 'por', 0, 'Live reconciliation exported (' . $do . ')' );
				if ( 'live_json' === $do ) {
					header( 'Content-Type: application/json' );
					header( 'Content-Disposition: attachment; filename="reservechain-reconciliation-' . gmdate( 'Ymd-His' ) . '.json"' );
					echo self::canonical( $r ); // phpcs:ignore
					exit;
				}
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="reservechain-units-' . gmdate( 'Ymd' ) . '.csv"' );
				$out = fopen( 'php://output', 'w' );
				fputcsv( $out, array( 'record_no', 'type', 'title', 'net_weight_kg', 'verification', 'custody', 'reserve', 'redemption', 'coa', 'documents', 'merkle_root' ) );
				foreach ( $r['units'] as $u ) {
					$u['coa'] = implode( ' ', $u['coa'] );
					fputcsv( $out, array_map( static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $u ) );
				}
				fclose( $out ); // phpcs:ignore
				exit;
			case 'attest':
				if ( ! current_user_can( 'rc_manage_registry' ) && ! current_user_can( 'rc_approve' ) ) {
					wp_die( 'Forbidden', 403 );
				}
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$att = media_handle_upload( 'report_file', 0 );
				if ( is_wp_error( $att ) ) {
					$msg = 'Upload rejected: ' . $att->get_error_message();
					break;
				}
				$pid   = absint( $_POST['program'] ?? 0 );
				$title = sprintf( 'Reserve attestation — %s — %s', get_the_title( $pid ), sanitize_text_field( wp_unslash( $_POST['report_date'] ?? '' ) ) );
				$res   = Workflow::bypass(
					static function () use ( $att, $pid, $title, $uid ) {
						$doc = wp_insert_post( array( 'post_type' => 'rc_document', 'post_title' => $title . ' (report)', 'post_status' => 'draft', 'post_author' => $uid ) );
						Registry::write_fields( $doc, array( 'file' => $att, 'doc_type' => 'reserve', 'issued_by' => sanitize_text_field( wp_unslash( $_POST['attestor'] ?? '' ) ), 'issue_date' => sanitize_text_field( wp_unslash( $_POST['report_date'] ?? '' ) ), 'verification_status' => 'pending_verification', 'public_library' => 'no' ) );
						Registry::ensure_record_no( $doc );
						$rep = wp_insert_post( array( 'post_type' => 'rc_reserve_report', 'post_title' => $title, 'post_status' => 'draft', 'post_author' => $uid ) );
						Registry::write_fields( $rep, array( 'program' => $pid, 'report_date' => sanitize_text_field( wp_unslash( $_POST['report_date'] ?? '' ) ), 'attestor' => sanitize_text_field( wp_unslash( $_POST['attestor'] ?? '' ) ), 'reserve_units' => sanitize_text_field( wp_unslash( $_POST['reserve_units'] ?? '' ) ), 'onchain_tx' => sanitize_text_field( wp_unslash( $_POST['onchain_tx'] ?? '' ) ), 'document' => $doc, 'verification_status' => 'pending_verification' ) );
						Registry::ensure_record_no( $rep );
						update_post_meta( $rep, '_edit_last', $uid );
						update_post_meta( $doc, '_edit_last', $uid );
						return array( $doc, $rep );
					}
				);
				Workflow::apply( 'submit', $res[1], 'Attestation submitted via Proof of Reserves intake.' );
				Workflow::apply( 'submit', $res[0], 'Attestation document submitted via Proof of Reserves intake.' );
				Audit_Log::record( 'por.attestation_submitted', 'rc_reserve_report', $res[1], 'Reserve attestation submitted for review', array( 'sha256' => get_post_meta( $res[0], '_rc_sha256', true ) ) );
				$msg = 'Attestation submitted to the review queue (' . get_post_meta( $res[1], '_rc_record_no', true ) . '). An independent reviewer must approve it.';
				break;
		}
		set_transient( 'rc_por_msg_' . $uid, $msg, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=rc-por' ) );
		exit;
	}
}
