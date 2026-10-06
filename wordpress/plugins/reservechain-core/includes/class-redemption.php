<?php
/**
 * Physical-redemption workflow (gated module `redemption` — INACTIVE until written authorization).
 *
 * Off-chain mirror of contracts/RedemptionManager.sol:
 *
 *   requested → compliance_review → approved → tokens_burned → custody_released → in_logistics → delivered
 *   side exits: rejected (staff, before burn) · cancelled (requester, before approval) · on_hold (staff, resumable)
 *
 * On-chain `request()` escrows tokens (Pending); `approve()` burns the escrow; `reject()` / `cancel()` return it
 * while still Pending. Off-chain we therefore allow reject/cancel only before `tokens_burned`, and the burn step
 * must carry the testnet burn transaction hash as evidence.
 *
 * Rules enforced here:
 *  - Capability check + four-eyes on every transition (approver ≠ requester ≠ preparing operator; burn recorder ≠
 *    approver; delivery confirmer ≠ dispatcher; hold releaser ≠ hold placer). Approval binds to a fingerprint of
 *    what was approved (units, lot, amount, program, delivery method, address hash).
 *  - Compliance::eligibility() is snapshotted at request AND at approval; restricted jurisdiction or any required
 *    KYC/KYB/AML/sanctions check not approved blocks the step.
 *  - Selected containers / coils / lot are locked against double selection and their `redemption_status`
 *    follows the workflow (requested → released → delivered; restored on reject / cancel), with audit diffs.
 *  - Thresholds and fees are never invented: read from the token program (`redemption_min`) or settings
 *    (`redemption_min`, `redemption_fee_schedule`); when missing they display as "to be determined" and a
 *    live (non-test) request is refused.
 *  - Delivery address is encrypted at rest (Auth::encrypt) and only its SHA-256 reaches the audit trail.
 *  - Staff may create clearly labelled TEST redemptions in RC_ENV=development while the module is off.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Redemption {

	public const TYPE   = 'rc_redemption';
	public const MODULE = 'redemption';
	public const PAGE   = 'rc-redemptions';

	public const OPEN_STATES     = array( 'requested', 'compliance_review', 'approved', 'tokens_burned', 'custody_released', 'in_logistics', 'on_hold' );
	public const PRE_BURN_STATES = array( 'requested', 'compliance_review', 'approved' );
	public const TESTNET_CHAINS  = array( 11155111, 80002, 31337 );
	public const UNIT_TYPES      = array( 'rc_container', 'rc_coil' );

	/** action => [ from states, to state ('@prev' = state before hold), capability ('@requester' = requester only) ] */
	public const TRANSITIONS = array(
		'start_review'     => array( array( 'requested' ), 'compliance_review', 'rc_manage_registry' ),
		'approve'          => array( array( 'compliance_review' ), 'approved', 'rc_approve' ),
		'record_burn'      => array( array( 'approved' ), 'tokens_burned', 'rc_manage_registry' ),
		'release_custody'  => array( array( 'tokens_burned' ), 'custody_released', 'rc_manage_registry' ),
		'dispatch'         => array( array( 'custody_released' ), 'in_logistics', 'rc_manage_registry' ),
		'confirm_delivery' => array( array( 'in_logistics' ), 'delivered', 'rc_manage_registry' ),
		'reject'           => array( array( 'requested', 'compliance_review', 'approved', 'on_hold' ), 'rejected', 'rc_approve' ),
		'cancel'           => array( array( 'requested', 'compliance_review' ), 'cancelled', '@requester' ),
		'hold'             => array( array( 'requested', 'compliance_review', 'approved', 'tokens_burned', 'custody_released', 'in_logistics' ), 'on_hold', 'rc_manage_compliance' ),
		'resume'           => array( array( 'on_hold' ), '@prev', 'rc_approve' ),
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'hide_registry_entry' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rc_rdm_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_rc_rdm_units', array( __CLASS__, 'handle_units' ) );
		add_action( 'admin_post_rc_rdm_create_test', array( __CLASS__, 'handle_create_test' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_core_screens' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_core_screens' ) );
		add_action( 'load-edit.php', array( __CLASS__, 'redirect_core_screens' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'guard_direct_save' ), 1 );
	}

	/** No own tables: records are rc_redemption posts. Existing capabilities are reused (no new caps). */
	public static function install(): void {
		Audit_Log::record( 'module.installed', 'module', 0, 'Redemption workflow module installed (inactive until authorized)', array( 'module' => self::MODULE ) );
	}

	/* ================================================================== labels / settings */

	public static function state_labels(): array {
		return array(
			'requested'         => __( 'Requested', 'reservechain' ),
			'compliance_review' => __( 'Compliance review', 'reservechain' ),
			'approved'          => __( 'Approved', 'reservechain' ),
			'tokens_burned'     => __( 'Tokens burned', 'reservechain' ),
			'custody_released'  => __( 'Custody released', 'reservechain' ),
			'in_logistics'      => __( 'In logistics / customs', 'reservechain' ),
			'delivered'         => __( 'Delivered (completed)', 'reservechain' ),
			'on_hold'           => __( 'On hold', 'reservechain' ),
			'rejected'          => __( 'Rejected', 'reservechain' ),
			'cancelled'         => __( 'Cancelled', 'reservechain' ),
		);
	}

	public static function action_labels(): array {
		return array(
			'request'          => __( 'Redemption requested', 'reservechain' ),
			'start_review'     => __( 'Start compliance review', 'reservechain' ),
			'approve'          => __( 'Approve redemption', 'reservechain' ),
			'record_burn'      => __( 'Record token burn', 'reservechain' ),
			'release_custody'  => __( 'Release from custody', 'reservechain' ),
			'dispatch'         => __( 'Hand over to logistics', 'reservechain' ),
			'confirm_delivery' => __( 'Confirm delivery', 'reservechain' ),
			'reject'           => __( 'Reject', 'reservechain' ),
			'cancel'           => __( 'Cancel request', 'reservechain' ),
			'hold'             => __( 'Place on hold', 'reservechain' ),
			'resume'           => __( 'Release hold', 'reservechain' ),
			'units'            => __( 'Unit selection changed', 'reservechain' ),
		);
	}

	public static function tbd(): string {
		return __( 'To be determined — subject to written approval', 'reservechain' );
	}

	/** Redemption minimum: token program field first, then settings. Null = not determined (never defaulted). */
	public static function minimum( int $token_program ): ?string {
		$v = $token_program ? (string) get_post_meta( $token_program, Schema::meta_key( 'redemption_min' ), true ) : '';
		if ( '' === trim( $v ) ) {
			$v = (string) Settings::get( 'redemption_min', '' );
		}
		return '' === trim( $v ) ? null : $v;
	}

	/** Fee schedule text from settings. Null = not determined. */
	public static function fee_schedule(): ?string {
		$v = trim( (string) Settings::get( 'redemption_fee_schedule', '' ) );
		return '' === $v ? null : $v;
	}

	public static function is_live(): bool {
		return Settings::module_on( self::MODULE ) && 'redemption' === Settings::get( 'site_mode' );
	}

	public static function inactive_message(): string {
		return __( 'Physical redemption is not available. The module is inactive pending written authorization and final approval.', 'reservechain' );
	}

	/* ================================================================== record helpers */

	private static function m( int $id, string $key ) {
		return get_post_meta( $id, Schema::meta_key( $key ), true );
	}

	private static function set( int $id, string $key, $value ): void {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $id, Schema::meta_key( $key ) );
		} else {
			update_post_meta( $id, Schema::meta_key( $key ), $value );
		}
	}

	private static function set_multi( int $id, string $key, array $values ): void {
		delete_post_meta( $id, Schema::meta_key( $key ) );
		foreach ( array_values( array_unique( array_map( 'intval', $values ) ) ) as $v ) {
			if ( $v ) {
				add_post_meta( $id, Schema::meta_key( $key ), $v );
			}
		}
	}

	private static function multi( int $id, string $key ): array {
		return array_values( array_filter( array_map( 'intval', get_post_meta( $id, Schema::meta_key( $key ), false ) ) ) );
	}

	public static function state( int $id ): string {
		return (string) self::m( $id, 'redemption_state' );
	}

	public static function requester( int $id ): int {
		return (int) self::m( $id, 'requester' );
	}

	public static function is_test( int $id ): bool {
		return (bool) get_post_meta( $id, '_rc_rdm_test', true );
	}

	public static function get( int $id ): ?\WP_Post {
		$p = get_post( $id );
		return ( $p && self::TYPE === $p->post_type ) ? $p : null;
	}

	public static function record_no( int $id ): string {
		return (string) get_post_meta( $id, '_rc_record_no', true );
	}

	private static function units( int $id ): array {
		return self::multi( $id, 'units' );
	}

	private static function history( int $id ): array {
		return array_values( array_filter( (array) get_post_meta( $id, '_rc_rdm_history', true ), 'is_array' ) );
	}

	private static function user_label( int $uid ): string {
		$u = $uid ? get_userdata( $uid ) : null;
		return $u ? $u->display_name . ' (' . $u->user_login . ')' : __( 'system', 'reservechain' );
	}

	public static function address_hash( string $address ): string {
		return hash( 'sha256', strtolower( preg_replace( '/\s+/', ' ', trim( $address ) ) ) );
	}

	/** SHA-256 binding of everything an approval covers. */
	public static function fingerprint( int $id ): string {
		$units = self::units( $id );
		sort( $units );
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'requester'       => self::requester( $id ),
					'token_program'   => (int) self::m( $id, 'token_program' ),
					'amount'          => (string) self::m( $id, 'amount' ),
					'units'           => $units,
					'lot'             => (int) self::m( $id, 'lot' ),
					'delivery_method' => (string) self::m( $id, 'delivery_method' ),
					'address_hash'    => (string) get_post_meta( $id, '_rc_rdm_address_hash', true ),
				)
			)
		);
	}

	/* ================================================================== compliance */

	/** @return true|\WP_Error */
	private static function compliance_gate( array $elig ) {
		if ( 'restricted' === $elig['jurisdiction'] ) {
			return new \WP_Error( 'rc_restricted', __( 'Redemption is not available in the requester’s jurisdiction.', 'reservechain' ), array( 'status' => 403 ) );
		}
		if ( 'eligible_subject_to_final_approval' !== $elig['overall'] ) {
			$missing = array();
			foreach ( array_keys( Compliance::CHECKS ) as $c ) {
				if ( ! in_array( $elig[ $c ] ?? '', array( 'approved', 'not_applicable' ), true ) ) {
					$missing[] = strtoupper( $c ) . ': ' . ( $elig[ $c ] ?? '—' );
				}
			}
			if ( 'eligible' !== $elig['jurisdiction'] ) {
				$missing[] = __( 'jurisdiction', 'reservechain' ) . ': ' . $elig['jurisdiction'];
			}
			/* translators: %s: list of incomplete checks */
			return new \WP_Error( 'rc_not_eligible', sprintf( __( 'Compliance checks are not complete (%s). KYC/KYB, AML and sanctions screening must be approved.', 'reservechain' ), implode( ', ', $missing ) ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function snapshot_compliance( int $id, string $stage, array $elig ): void {
		$snaps           = (array) get_post_meta( $id, '_rc_rdm_compliance', true );
		$elig['at']      = gmdate( 'c' );
		$elig['by']      = get_current_user_id();
		$snaps[ $stage ] = $elig;
		update_post_meta( $id, '_rc_rdm_compliance', $snaps );
	}

	/* ================================================================== unit selection */

	/** Containers, coils and batches that belong to a lot (directly or through a batch). */
	private static function lot_children( int $lot ): array {
		$q = static fn( array $types, string $key, array $values ) => $values ? get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'rc_review', 'rc_approved', 'rc_unpublished', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => Schema::meta_key( $key ), 'value' => $values, 'compare' => 'IN' ) ), // phpcs:ignore
			)
		) : array();
		$batches = array_map( 'intval', $q( array( 'rc_batch' ), 'lot', array( $lot ) ) );
		$direct  = array_map( 'intval', $q( self::UNIT_TYPES, 'lot', array( $lot ) ) );
		$via     = array_map( 'intval', $q( array( 'rc_container' ), 'batch', $batches ) );
		return array_values( array_unique( array_merge( $batches, $direct, $via ) ) );
	}

	/** Open redemptions (other than $except) that already hold $post_id. */
	private static function holders( int $post_id, int $except = 0 ): array {
		return array_values(
			array_diff(
				array_map(
					'intval',
					get_posts(
						array(
							'post_type'      => self::TYPE,
							'post_status'    => array( 'private', 'draft', 'publish' ),
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'meta_query'     => array( // phpcs:ignore
								array( 'key' => '_rc_rdm_affected', 'value' => $post_id, 'compare' => '=' ),
								array( 'key' => Schema::meta_key( 'redemption_state' ), 'value' => self::OPEN_STATES, 'compare' => 'IN' ),
							),
						)
					)
				),
				array( $except )
			)
		);
	}

	/**
	 * Validate a selection and return every affected record id (units + lot + lot children).
	 *
	 * @return array|\WP_Error
	 */
	public static function validate_selection( int $token_program, array $units, int $lot, int $except = 0 ) {
		$program = (int) get_post_meta( $token_program, Schema::meta_key( 'program' ), true );
		if ( ! $program ) {
			return new \WP_Error( 'rc_invalid', __( 'The token program is not linked to a metal program.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$units    = array_values( array_unique( array_filter( array_map( 'intval', $units ) ) ) );
		$affected = array();
		foreach ( $units as $u ) {
			$p = get_post( $u );
			if ( ! $p || ! in_array( $p->post_type, self::UNIT_TYPES, true ) || 'trash' === $p->post_status ) {
				/* translators: %d: record id */
				return new \WP_Error( 'rc_invalid', sprintf( __( 'Unit %d is not a container or coil record.', 'reservechain' ), $u ), array( 'status' => 422 ) );
			}
			$affected[] = $u;
		}
		if ( $lot ) {
			$p = get_post( $lot );
			if ( ! $p || 'rc_lot' !== $p->post_type || 'trash' === $p->post_status ) {
				return new \WP_Error( 'rc_invalid', __( 'The selected lot does not exist.', 'reservechain' ), array( 'status' => 422 ) );
			}
			$affected = array_merge( $affected, array( $lot ), self::lot_children( $lot ) );
		}
		$affected = array_values( array_unique( $affected ) );
		if ( ! $units && ! $lot ) {
			return array();
		}
		foreach ( $affected as $a ) {
			$no = (string) get_post_meta( $a, '_rc_record_no', true );
			if ( (int) get_post_meta( $a, Schema::meta_key( 'program' ), true ) !== $program ) {
				/* translators: %s: record number */
				return new \WP_Error( 'rc_wrong_program', sprintf( __( '%s does not belong to the token program’s metal program.', 'reservechain' ), $no ), array( 'status' => 422 ) );
			}
			$status = (string) get_post_meta( $a, Schema::meta_key( 'redemption_status' ), true ) ?: 'not_available';
			if ( ! in_array( $status, array( 'not_available', 'eligible' ), true ) && ! ( $except && in_array( $a, self::affected( $except ), true ) ) ) {
				/* translators: 1: record number 2: redemption status */
				return new \WP_Error( 'rc_unit_unavailable', sprintf( __( '%1$s is not available for redemption (status: %2$s).', 'reservechain' ), $no, $status ), array( 'status' => 409 ) );
			}
			$held = self::holders( $a, $except );
			if ( $held ) {
				/* translators: 1: unit record number 2: redemption record number */
				return new \WP_Error( 'rc_unit_taken', sprintf( __( '%1$s is already selected in open redemption %2$s.', 'reservechain' ), $no, self::record_no( $held[0] ) ), array( 'status' => 409 ) );
			}
		}
		return $affected;
	}

	private static function affected( int $id ): array {
		return array_values( array_unique( array_map( 'intval', get_post_meta( $id, '_rc_rdm_affected', false ) ) ) );
	}

	private static function set_unit_status( int $id, int $unit, string $to ): void {
		$key  = Schema::meta_key( 'redemption_status' );
		$from = (string) get_post_meta( $unit, $key, true ) ?: 'not_available';
		if ( $from === $to ) {
			return;
		}
		update_post_meta( $unit, $key, $to );
		Audit_Log::record(
			'registry.updated',
			(string) get_post_type( $unit ),
			$unit,
			sprintf( '%s redemption status %s → %s (redemption %s)', get_post_meta( $unit, '_rc_record_no', true ), $from, $to, self::record_no( $id ) ),
			array( 'redemption_status' => array( 'from' => $from, 'to' => $to ), 'redemption' => self::record_no( $id ), 'redemption_id' => $id )
		);
	}

	/** Reserve a validated selection: mark records `requested`, remember previous statuses. */
	private static function reserve( int $id, array $units, int $lot, array $affected ): void {
		$prev = (array) get_post_meta( $id, '_rc_rdm_unit_prev', true );
		foreach ( $affected as $a ) {
			if ( ! isset( $prev[ $a ] ) ) {
				$prev[ $a ] = (string) get_post_meta( $a, Schema::meta_key( 'redemption_status' ), true ) ?: 'not_available';
			}
			add_post_meta( $id, '_rc_rdm_affected', $a );
			self::set_unit_status( $id, $a, 'requested' );
		}
		update_post_meta( $id, '_rc_rdm_unit_prev', $prev );
		self::set_multi( $id, 'units', $units );
		self::set( $id, 'lot', $lot ?: '' );
	}

	/** Undo a reservation: restore previous statuses for records still marked `requested`. */
	private static function unreserve( int $id ): void {
		$prev = (array) get_post_meta( $id, '_rc_rdm_unit_prev', true );
		foreach ( self::affected( $id ) as $a ) {
			if ( 'requested' === (string) get_post_meta( $a, Schema::meta_key( 'redemption_status' ), true ) ) {
				self::set_unit_status( $id, $a, $prev[ $a ] ?? 'not_available' );
			}
		}
		delete_post_meta( $id, '_rc_rdm_affected' );
	}

	/**
	 * Change the unit / lot selection before approval (operator).
	 *
	 * @return true|\WP_Error
	 */
	public static function select_units( int $id, array $units, int $lot ) {
		$post = self::get( $id );
		if ( ! $post ) {
			return new \WP_Error( 'rc_not_found', __( 'Redemption not found.', 'reservechain' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'rc_manage_registry' ) ) {
			return new \WP_Error( 'rc_forbidden', __( 'Your role does not permit this action.', 'reservechain' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( self::state( $id ), array( 'requested', 'compliance_review' ), true ) ) {
			return new \WP_Error( 'rc_state', __( 'Units can only be changed before approval.', 'reservechain' ), array( 'status' => 409 ) );
		}
		if ( get_current_user_id() === self::requester( $id ) ) {
			return new \WP_Error( 'rc_four_eyes', __( 'Four-eyes rule: the requester cannot prepare their own redemption.', 'reservechain' ), array( 'status' => 403 ) );
		}
		$gate = self::module_gate( $id, 'units' );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		return self::locked(
			'rc_rdm_units',
			static function () use ( $id, $units, $lot ) {
				$before_units = self::units( $id );
				$before_lot   = (int) self::m( $id, 'lot' );
				$affected     = self::validate_selection( (int) self::m( $id, 'token_program' ), $units, $lot, $id );
				if ( is_wp_error( $affected ) ) {
					return $affected;
				}
				self::unreserve( $id );
				self::reserve( $id, $units, $lot, $affected );
				update_post_meta( $id, '_rc_rdm_prepared_by', get_current_user_id() );
				self::log(
					$id,
					'units',
					self::state( $id ),
					self::state( $id ),
					'',
					array(
						'units' => array( 'from' => self::record_nos( $before_units ), 'to' => self::record_nos( self::units( $id ) ) ),
						'lot'   => array( 'from' => $before_lot ? self::record_no_of( $before_lot ) : null, 'to' => $lot ? self::record_no_of( $lot ) : null ),
					)
				);
				return true;
			}
		);
	}

	private static function record_no_of( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_rc_record_no', true );
	}

	private static function record_nos( array $ids ): array {
		return array_map( array( __CLASS__, 'record_no_of' ), $ids );
	}

	/* ================================================================== create */

	/**
	 * Create a redemption request.
	 *
	 * @param array $a { requester, token_program, amount, units[], lot, delivery_method, delivery_address, note, channel }
	 * @return int|\WP_Error
	 */
	public static function create( array $a, bool $test = false ) {
		$creator = get_current_user_id();
		if ( $test ) {
			if ( 'development' !== RC_ENV || ! current_user_can( 'rc_manage_registry' ) ) {
				return new \WP_Error( 'rc_forbidden', __( 'Test redemptions can only be created by staff in the development environment.', 'reservechain' ), array( 'status' => 403 ) );
			}
		} elseif ( ! self::is_live() ) {
			return new \WP_Error( 'rc_module_inactive', self::inactive_message(), array( 'status' => 403 ) );
		}

		$requester = (int) ( $a['requester'] ?? 0 );
		if ( ! $requester || ! get_userdata( $requester ) ) {
			return new \WP_Error( 'rc_invalid', __( 'Unknown requester.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$tp = (int) ( $a['token_program'] ?? 0 );
		$tpp = $tp ? get_post( $tp ) : null;
		if ( ! $tpp || 'rc_token_program' !== $tpp->post_type || 'trash' === $tpp->post_status || ( ! $test && 'publish' !== $tpp->post_status ) ) {
			return new \WP_Error( 'rc_invalid', __( 'Unknown token program.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$amount = trim( (string) ( $a['amount'] ?? '' ) );
		if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
			return new \WP_Error( 'rc_invalid', __( 'Enter a token amount greater than zero.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$amount = (string) ( 0 + $amount );
		$min    = self::minimum( $tp );
		$fees   = self::fee_schedule();
		if ( ! $test && ( null === $min || null === $fees ) ) {
			return new \WP_Error( 'rc_terms_undetermined', __( 'Redemption minimum and fees are not yet determined (subject to written approval). Requests cannot be accepted.', 'reservechain' ), array( 'status' => 403 ) );
		}
		if ( null !== $min && (float) $amount < (float) $min ) {
			/* translators: %s: minimum amount */
			return new \WP_Error( 'rc_below_minimum', sprintf( __( 'The amount is below the redemption minimum (%s).', 'reservechain' ), $min ), array( 'status' => 422 ) );
		}
		$method = sanitize_key( (string) ( $a['delivery_method'] ?? '' ) );
		if ( ! in_array( $method, array( 'collection', 'delivery' ), true ) ) {
			return new \WP_Error( 'rc_invalid', __( 'Choose collection or delivery.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$address = trim( sanitize_textarea_field( (string) ( $a['delivery_address'] ?? '' ) ) );
		if ( 'delivery' === $method && mb_strlen( $address ) < 10 ) {
			return new \WP_Error( 'rc_invalid', __( 'A delivery address is required for delivery.', 'reservechain' ), array( 'status' => 422 ) );
		}
		if ( mb_strlen( $address ) > 1000 ) {
			return new \WP_Error( 'rc_invalid', __( 'The delivery address is too long.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$units = array_map( 'intval', (array) ( $a['units'] ?? array() ) );
		$lot   = (int) ( $a['lot'] ?? 0 );
		if ( ! $test && 'api' === ( $a['channel'] ?? '' ) && ( $units || $lot ) && ! Settings::module_on( 'unit_selection' ) ) {
			return new \WP_Error( 'rc_module_inactive', __( 'Container / coil selection is not available; units are allocated by operations.', 'reservechain' ), array( 'status' => 403 ) );
		}

		$elig = Compliance::eligibility( $requester );
		$ok   = self::compliance_gate( $elig );
		if ( is_wp_error( $ok ) ) {
			Audit_Log::record( 'redemption.blocked', self::TYPE, 0, 'Redemption request blocked by compliance re-check', array( 'requester' => $requester, 'overall' => $elig['overall'], 'jurisdiction' => $elig['jurisdiction'], 'test' => $test ) );
			return $ok;
		}

		return self::locked(
			'rc_rdm_units',
			static function () use ( $requester, $tp, $amount, $method, $address, $units, $lot, $test, $creator, $elig, $a, $min, $fees ) {
				$affected = self::validate_selection( $tp, $units, $lot );
				if ( is_wp_error( $affected ) ) {
					return $affected;
				}
				$id = Workflow::bypass(
					static fn() => wp_insert_post(
						array(
							'post_type'   => self::TYPE,
							'post_status' => 'private',
							'post_title'  => ( $test ? '[TEST] ' : '' ) . 'Redemption request',
							'post_author' => $requester,
						),
						true
					)
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$no = Registry::ensure_record_no( $id );
				Workflow::bypass( static fn() => wp_update_post( array( 'ID' => $id, 'post_title' => ( $test ? '[TEST] ' : '' ) . 'Redemption ' . $no ) ) );

				self::set( $id, 'requester', (string) $requester );
				self::set( $id, 'token_program', $tp );
				self::set( $id, 'amount', $amount );
				self::set( $id, 'delivery_method', $method );
				self::set( $id, 'redemption_state', 'requested' );
				update_post_meta( $id, '_rc_rdm_created_by', $creator );
				update_post_meta( $id, '_rc_rdm_channel', sanitize_key( (string) ( $a['channel'] ?? 'admin' ) ) );
				update_post_meta( $id, '_rc_rdm_terms', array( 'minimum' => $min, 'fee_schedule' => $fees ) );
				if ( $test ) {
					update_post_meta( $id, '_rc_rdm_test', 1 );
				}
				if ( '' !== $address ) {
					update_post_meta( $id, '_rc_rdm_address_enc', Auth::encrypt( $address ) );
					update_post_meta( $id, '_rc_rdm_address_hash', self::address_hash( $address ) );
				}
				self::snapshot_compliance( $id, 'request', $elig );
				if ( $affected ) {
					self::reserve( $id, $units, $lot, $affected );
				}
				self::log(
					$id,
					'request',
					'',
					'requested',
					sanitize_textarea_field( (string) ( $a['note'] ?? '' ) ),
					array(
						'units'            => self::record_nos( $units ),
						'lot'              => $lot ? self::record_no_of( $lot ) : null,
						'amount'           => $amount,
						'token_program'    => self::record_no_of( $tp ),
						'delivery_method'  => $method,
						'address_sha256'   => $address ? self::address_hash( $address ) : null,
						'compliance'       => $elig['overall'],
						'minimum'          => $min,
						'fees_determined'  => null !== $fees,
					)
				);
				return $id;
			}
		);
	}

	/* ================================================================== transitions */

	/** Inactive-module gate for non-protective steps. @return true|\WP_Error */
	private static function module_gate( int $id, string $action ) {
		if ( self::is_test( $id ) ) {
			return 'development' === RC_ENV ? true : new \WP_Error( 'rc_module_inactive', __( 'Test redemptions can only be processed in the development environment.', 'reservechain' ), array( 'status' => 403 ) );
		}
		if ( in_array( $action, array( 'cancel', 'reject', 'hold' ), true ) ) {
			return true; // Protective exits stay available (mirrors cancel/reject while paused on-chain).
		}
		if ( ! Settings::module_on( self::MODULE ) ) {
			return new \WP_Error( 'rc_module_inactive', self::inactive_message(), array( 'status' => 403 ) );
		}
		if ( in_array( $action, array( 'dispatch', 'confirm_delivery' ), true ) && ! Settings::module_on( 'logistics' ) ) {
			return new \WP_Error( 'rc_module_inactive', __( 'The logistics, customs & delivery module is inactive.', 'reservechain' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function can( int $id, string $action, ?string &$why = null, ?int $actor = null ): bool {
		$actor = $actor ?? get_current_user_id();
		if ( ! isset( self::TRANSITIONS[ $action ] ) || ! self::get( $id ) ) {
			$why = __( 'Unknown action.', 'reservechain' );
			return false;
		}
		list( $from, , $cap ) = self::TRANSITIONS[ $action ];
		$state                = self::state( $id );
		if ( ! in_array( $state, $from, true ) ) {
			$why = __( 'Not available from the current state.', 'reservechain' );
			return false;
		}
		$requester = self::requester( $id );
		$creator   = (int) get_post_meta( $id, '_rc_rdm_created_by', true );
		if ( '@requester' === $cap ) {
			if ( $actor !== $requester ) {
				$why = __( 'Only the requester can cancel a request.', 'reservechain' );
				return false;
			}
		} else {
			$has = user_can( $actor, $cap ) || ( 'hold' === $action && user_can( $actor, 'rc_approve' ) );
			if ( ! $has ) {
				$why = __( 'Your role does not permit this action.', 'reservechain' );
				return false;
			}
			if ( $actor === $requester || ( 'approve' === $action && $actor === $creator ) ) {
				$why = __( 'Four-eyes rule: the requester cannot act on their own redemption.', 'reservechain' );
				return false;
			}
		}
		if ( 'reject' === $action && 'on_hold' === $state && ! in_array( (string) get_post_meta( $id, '_rc_rdm_hold_from', true ), self::PRE_BURN_STATES, true ) ) {
			$why = __( 'Tokens have already been burned; the redemption can no longer be rejected.', 'reservechain' );
			return false;
		}
		$prepared = (int) get_post_meta( $id, '_rc_rdm_prepared_by', true );
		$approver = (int) get_post_meta( $id, '_rc_rdm_approved_by', true );
		$rules    = array(
			'approve'          => array( $prepared, __( 'Four-eyes rule: the operator who prepared this redemption cannot approve it.', 'reservechain' ) ),
			'record_burn'      => array( $approver, __( 'Four-eyes rule: the approver cannot also record the burn.', 'reservechain' ) ),
			'confirm_delivery' => array( (int) get_post_meta( $id, '_rc_rdm_dispatched_by', true ), __( 'Four-eyes rule: the operator who dispatched the units cannot confirm delivery.', 'reservechain' ) ),
			'resume'           => array( (int) get_post_meta( $id, '_rc_rdm_held_by', true ), __( 'Four-eyes rule: the person who placed the hold cannot release it.', 'reservechain' ) ),
		);
		if ( isset( $rules[ $action ] ) && $rules[ $action ][0] && $rules[ $action ][0] === $actor ) {
			$why = $rules[ $action ][1];
			return false;
		}
		if ( in_array( $action, array( 'record_burn', 'release_custody', 'dispatch', 'confirm_delivery' ), true ) && get_post_meta( $id, '_rc_rdm_approved_fp', true ) !== self::fingerprint( $id ) ) {
			$why = __( 'The redemption differs from what was approved. It must be re-approved.', 'reservechain' );
			return false;
		}
		$gate = self::module_gate( $id, $action );
		if ( is_wp_error( $gate ) ) {
			$why = $gate->get_error_message();
			return false;
		}
		return true;
	}

	/** Validate an rc_document used as evidence. @return array|\WP_Error */
	private static function evidence_doc( $doc_id, string $label ) {
		$doc_id = absint( $doc_id );
		$p      = $doc_id ? get_post( $doc_id ) : null;
		if ( ! $p || 'rc_document' !== $p->post_type || 'trash' === $p->post_status ) {
			/* translators: %s: evidence label */
			return new \WP_Error( 'rc_evidence', sprintf( __( 'Evidence required: %s (registry document).', 'reservechain' ), $label ), array( 'status' => 422 ) );
		}
		$sha = (string) get_post_meta( $doc_id, '_rc_sha256', true );
		if ( '' === $sha ) {
			/* translators: %s: document record number */
			return new \WP_Error( 'rc_evidence', sprintf( __( 'Document %s has no file fingerprint (SHA-256). Attach the file first.', 'reservechain' ), self::record_no_of( $doc_id ) ), array( 'status' => 422 ) );
		}
		return array( 'id' => $doc_id, 'record_no' => self::record_no_of( $doc_id ), 'sha256' => $sha );
	}

	/**
	 * Apply a transition.
	 *
	 * @param array $in reason, burn_tx, onchain_request_id, release_document, carrier, tracking_ref, customs_documents[], delivery_document
	 * @return true|\WP_Error
	 */
	public static function apply( int $id, string $action, array $in = array() ) {
		return self::locked(
			'rc_rdm_' . $id,
			static function () use ( $id, $action, $in ) {
				$why = '';
				if ( ! self::can( $id, $action, $why ) ) {
					Audit_Log::record( 'redemption.denied', self::TYPE, $id, sprintf( '%s: %s refused — %s', self::record_no( $id ), $action, $why ), array( 'action' => $action ) );
					return new \WP_Error( 'rc_denied', $why, array( 'status' => 403 ) );
				}
				$from     = self::state( $id );
				$to       = self::TRANSITIONS[ $action ][1];
				$reason   = trim( sanitize_textarea_field( (string) ( $in['reason'] ?? '' ) ) );
				$evidence = array();
				$me       = get_current_user_id();

				if ( in_array( $action, array( 'reject', 'hold' ), true ) && mb_strlen( $reason ) < 5 ) {
					return new \WP_Error( 'rc_invalid', __( 'A reason is required.', 'reservechain' ), array( 'status' => 422 ) );
				}

				switch ( $action ) {
					case 'start_review':
						if ( ! self::units( $id ) && ! self::m( $id, 'lot' ) ) {
							return new \WP_Error( 'rc_evidence', __( 'Select the containers / coils or the lot before starting the review.', 'reservechain' ), array( 'status' => 422 ) );
						}
						update_post_meta( $id, '_rc_rdm_prepared_by', $me );
						break;

					case 'approve':
						$elig = Compliance::eligibility( self::requester( $id ) );
						self::snapshot_compliance( $id, 'approval', $elig );
						$ok = self::compliance_gate( $elig );
						if ( is_wp_error( $ok ) ) {
							Audit_Log::record( 'redemption.blocked', self::TYPE, $id, self::record_no( $id ) . ': approval blocked by compliance re-check', array( 'overall' => $elig['overall'], 'jurisdiction' => $elig['jurisdiction'] ) );
							return $ok;
						}
						if ( ! self::is_test( $id ) && ( null === self::minimum( (int) self::m( $id, 'token_program' ) ) || null === self::fee_schedule() ) ) {
							return new \WP_Error( 'rc_terms_undetermined', __( 'Redemption minimum and fees are not yet determined (subject to written approval).', 'reservechain' ), array( 'status' => 403 ) );
						}
						if ( ! self::units( $id ) && ! self::m( $id, 'lot' ) ) {
							return new \WP_Error( 'rc_evidence', __( 'No containers / coils selected.', 'reservechain' ), array( 'status' => 422 ) );
						}
						$fp = self::fingerprint( $id );
						update_post_meta( $id, '_rc_rdm_approved_fp', $fp );
						update_post_meta( $id, '_rc_rdm_approved_by', $me );
						$evidence = array( 'fingerprint' => $fp, 'compliance' => $elig['overall'] );
						break;

					case 'record_burn':
						$tx = strtolower( trim( (string) ( $in['burn_tx'] ?? '' ) ) );
						if ( ! preg_match( '/^0x[0-9a-f]{64}$/', $tx ) ) {
							return new \WP_Error( 'rc_evidence', __( 'Evidence required: burn transaction hash (0x + 64 hex characters).', 'reservechain' ), array( 'status' => 422 ) );
						}
						$net   = (array) Settings::get( 'network' );
						$chain = (int) ( $net['chain_id'] ?? 0 );
						if ( ! in_array( $chain, self::TESTNET_CHAINS, true ) ) {
							return new \WP_Error( 'rc_mainnet', __( 'Only testnet networks are permitted.', 'reservechain' ), array( 'status' => 403 ) );
						}
						$dupe = get_posts( array( 'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft', 'publish' ), 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => Schema::meta_key( 'burn_tx' ), 'meta_value' => $tx, 'post__not_in' => array( $id ) ) ); // phpcs:ignore
						if ( $dupe ) {
							return new \WP_Error( 'rc_evidence', __( 'This burn transaction is already recorded on another redemption.', 'reservechain' ), array( 'status' => 409 ) );
						}
						self::set( $id, 'burn_tx', $tx );
						$req_id = sanitize_text_field( (string) ( $in['onchain_request_id'] ?? '' ) );
						if ( '' !== $req_id ) {
							self::set( $id, 'onchain_request_id', $req_id );
						}
						update_post_meta( $id, '_rc_rdm_burn_chain', $chain );
						$evidence = array( 'burn_tx' => $tx, 'chain_id' => $chain, 'onchain_request_id' => $req_id ?: null );
						break;

					case 'release_custody':
						$doc = self::evidence_doc( $in['release_document'] ?? 0, __( 'custody release document', 'reservechain' ) );
						if ( is_wp_error( $doc ) ) {
							return $doc;
						}
						self::set( $id, 'release_document', $doc['id'] );
						$evidence = array( 'release_document' => $doc );
						break;

					case 'dispatch':
						$carrier  = sanitize_text_field( (string) ( $in['carrier'] ?? '' ) );
						$tracking = sanitize_text_field( (string) ( $in['tracking_ref'] ?? '' ) );
						if ( '' === $carrier || '' === $tracking ) {
							return new \WP_Error( 'rc_evidence', __( 'Evidence required: carrier (or collecting party) and tracking / collection reference.', 'reservechain' ), array( 'status' => 422 ) );
						}
						$docs = array();
						foreach ( array_filter( array_map( 'absint', (array) ( $in['customs_documents'] ?? array() ) ) ) as $d ) {
							$doc = self::evidence_doc( $d, __( 'customs / logistics document', 'reservechain' ) );
							if ( is_wp_error( $doc ) ) {
								return $doc;
							}
							$docs[] = $doc;
						}
						if ( ! $docs ) {
							return new \WP_Error( 'rc_evidence', __( 'Evidence required: at least one customs / logistics document.', 'reservechain' ), array( 'status' => 422 ) );
						}
						self::set( $id, 'carrier', $carrier );
						self::set( $id, 'tracking_ref', $tracking );
						self::set_multi( $id, 'customs_documents', wp_list_pluck( $docs, 'id' ) );
						update_post_meta( $id, '_rc_rdm_dispatched_by', $me );
						$evidence = array( 'carrier' => $carrier, 'tracking_ref' => $tracking, 'customs_documents' => $docs );
						break;

					case 'confirm_delivery':
						$doc = self::evidence_doc( $in['delivery_document'] ?? 0, __( 'delivery confirmation document', 'reservechain' ) );
						if ( is_wp_error( $doc ) ) {
							return $doc;
						}
						self::set( $id, 'delivery_document', $doc['id'] );
						$evidence = array( 'delivery_document' => $doc );
						break;

					case 'hold':
						update_post_meta( $id, '_rc_rdm_hold_from', $from );
						update_post_meta( $id, '_rc_rdm_held_by', $me );
						break;

					case 'resume':
						$to = (string) get_post_meta( $id, '_rc_rdm_hold_from', true );
						if ( ! in_array( $to, self::OPEN_STATES, true ) || 'on_hold' === $to ) {
							return new \WP_Error( 'rc_state', __( 'Cannot determine the state to resume.', 'reservechain' ), array( 'status' => 409 ) );
						}
						delete_post_meta( $id, '_rc_rdm_hold_from' );
						delete_post_meta( $id, '_rc_rdm_held_by' );
						break;
				}

				self::set( $id, 'redemption_state', $to );

				// Unit status follows the workflow.
				if ( 'release_custody' === $action ) {
					foreach ( self::affected( $id ) as $u ) {
						self::set_unit_status( $id, $u, 'released' );
					}
				} elseif ( 'confirm_delivery' === $action ) {
					foreach ( self::affected( $id ) as $u ) {
						self::set_unit_status( $id, $u, 'delivered' );
					}
				} elseif ( in_array( $action, array( 'reject', 'cancel' ), true ) ) {
					self::unreserve( $id );
				}

				self::log( $id, $action, $from, $to, $reason, $evidence );
				return true;
			}
		);
	}

	/** History + audit + requester notification for a state change. */
	private static function log( int $id, string $action, string $from, string $to, string $reason = '', array $evidence = array() ): void {
		$history   = self::history( $id );
		$history[] = array(
			'at'       => gmdate( 'c' ),
			'user'     => get_current_user_id(),
			'action'   => $action,
			'from'     => $from,
			'to'       => $to,
			'reason'   => $reason,
			'evidence' => $evidence,
			'fp'       => self::fingerprint( $id ),
		);
		update_post_meta( $id, '_rc_rdm_history', $history );

		$no   = self::record_no( $id );
		$test = self::is_test( $id );
		Audit_Log::record(
			'redemption.' . $action,
			self::TYPE,
			$id,
			sprintf( '%s%s: %s → %s', $test ? '[TEST] ' : '', $no, $from ?: '—', $to ),
			array(
				'reason'      => $reason,
				'evidence'    => $evidence,
				'test'        => $test,
				'fingerprint' => self::fingerprint( $id ),
			)
		);

		if ( $from !== $to ) {
			$labels = self::state_labels();
			/* translators: 1: test prefix 2: redemption record number 3: state label */
			$title = sprintf( __( '%1$sRedemption %2$s: %3$s', 'reservechain' ), $test ? '[Test] ' : '', $no, $labels[ $to ] ?? $to );
			/* translators: %s: state label */
			$body = sprintf( __( 'Your redemption request is now: %s.', 'reservechain' ), $labels[ $to ] ?? $to );
			if ( $reason && in_array( $action, array( 'reject', 'cancel', 'hold' ), true ) ) {
				$body .= ' ' . __( 'Reason:', 'reservechain' ) . ' ' . $reason;
			}
			Notifications::push( self::requester( $id ), $title, $body, 'redemption' );
		}
	}

	/** Serialise a critical section with a MySQL named lock. */
	private static function locked( string $name, callable $fn ) {
		global $wpdb;
		$got = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) );
		if ( ! $got ) {
			return new \WP_Error( 'rc_busy', __( 'Another change is in progress. Please retry.', 'reservechain' ), array( 'status' => 409 ) );
		}
		try {
			return $fn();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/* ================================================================== REST */

	public static function routes(): void {
		$bearer = array( Rest::class, 'bearer_gate' );
		register_rest_route( Rest::NS, '/me/redemptions', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ), 'permission_callback' => $bearer ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create' ), 'permission_callback' => $bearer ),
		) );
		register_rest_route( Rest::NS, '/me/redemptions/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $bearer ) );
		register_rest_route( Rest::NS, '/me/redemptions/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_cancel' ), 'permission_callback' => $bearer ) );
	}

	private static function rest_error( \WP_Error $e ): \WP_REST_Response {
		$data = $e->get_error_data();
		return new \WP_REST_Response( array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message() ), (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ) );
	}

	private static function own( int $id ): ?\WP_Post {
		$p = self::get( $id );
		return ( $p && self::requester( $id ) === get_current_user_id() && get_current_user_id() > 0 ) ? $p : null;
	}

	public static function rest_list(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'private', 'draft', 'publish' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => Schema::meta_key( 'requester' ), // phpcs:ignore
				'meta_value'     => (string) get_current_user_id(), // phpcs:ignore
			)
		);
		return array(
			'enabled' => self::is_live(),
			'reason'  => self::is_live() ? null : self::inactive_message(),
			'items'   => array_map( array( __CLASS__, 'payload' ), array_map( 'intval', $ids ) ),
		);
	}

	public static function rest_get( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! self::own( $id ) ) {
			return new \WP_REST_Response( array( 'code' => 'rc_not_found', 'message' => __( 'Redemption not found.', 'reservechain' ) ), 404 );
		}
		return self::payload( $id, true );
	}

	public static function rest_create( \WP_REST_Request $r ) {
		if ( ! self::is_live() ) {
			return self::rest_error( new \WP_Error( 'rc_module_inactive', self::inactive_message(), array( 'status' => 403 ) ) );
		}
		$uid = get_current_user_id();
		if ( ! Security::rate_limit( 'rdm_create', 5, HOUR_IN_SECONDS, 'u' . $uid ) ) {
			return self::rest_error( new \WP_Error( 'rc_rate_limited', __( 'Too many requests.', 'reservechain' ), array( 'status' => 429 ) ) );
		}
		$p  = (array) $r->get_json_params();
		$id = self::create(
			array(
				'requester'        => $uid,
				'token_program'    => absint( $p['token_program'] ?? 0 ),
				'amount'           => (string) ( $p['amount'] ?? '' ),
				'units'            => array_map( 'absint', (array) ( $p['units'] ?? array() ) ),
				'lot'              => absint( $p['lot'] ?? 0 ),
				'delivery_method'  => (string) ( $p['delivery_method'] ?? '' ),
				'delivery_address' => (string) ( $p['delivery_address'] ?? '' ),
				'note'             => (string) ( $p['note'] ?? '' ),
				'channel'          => 'api',
			)
		);
		if ( is_wp_error( $id ) ) {
			return self::rest_error( $id );
		}
		return new \WP_REST_Response( self::payload( $id, true ), 201 );
	}

	public static function rest_cancel( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! self::own( $id ) ) {
			return new \WP_REST_Response( array( 'code' => 'rc_not_found', 'message' => __( 'Redemption not found.', 'reservechain' ) ), 404 );
		}
		$p   = (array) $r->get_json_params();
		$res = self::apply( $id, 'cancel', array( 'reason' => (string) ( $p['reason'] ?? '' ) ) );
		return is_wp_error( $res ) ? self::rest_error( $res ) : self::payload( $id, true );
	}

	/** Requester-facing payload: never contains staff identities or other users' data. */
	public static function payload( int $id, bool $full = false ): array {
		$labels = self::state_labels();
		$state  = self::state( $id );
		$tp     = (int) self::m( $id, 'token_program' );
		$terms  = (array) get_post_meta( $id, '_rc_rdm_terms', true );
		$out    = array(
			'id'              => $id,
			'record_no'       => self::record_no( $id ),
			'test'            => self::is_test( $id ),
			'state'           => $state,
			'state_label'     => $labels[ $state ] ?? $state,
			'token_program'   => $tp ? array( 'id' => $tp, 'record_no' => self::record_no_of( $tp ), 'name' => get_the_title( $tp ) ) : null,
			'amount'          => (string) self::m( $id, 'amount' ),
			'delivery_method' => (string) self::m( $id, 'delivery_method' ),
			'created_at'      => get_post_time( 'c', true, $id ),
			'can_cancel'      => in_array( $state, self::TRANSITIONS['cancel'][0], true ),
		);
		if ( ! $full ) {
			return $out;
		}
		$net = (array) Settings::get( 'network' );
		$tx  = (string) self::m( $id, 'burn_tx' );
		$out['minimum']              = $terms['minimum'] ?? self::minimum( $tp ) ?? self::tbd();
		$out['fees']                 = $terms['fee_schedule'] ?? self::fee_schedule() ?? self::tbd();
		$out['delivery_address_set'] = (bool) get_post_meta( $id, '_rc_rdm_address_enc', true );
		$out['units']                = array_map(
			static fn( $u ) => array(
				'record_no'         => self::record_no_of( $u ),
				'type'              => get_post_type( $u ),
				'title'             => get_the_title( $u ),
				'redemption_status' => (string) get_post_meta( $u, Schema::meta_key( 'redemption_status' ), true ) ?: 'not_available',
			),
			self::units( $id )
		);
		$lot                         = (int) self::m( $id, 'lot' );
		$out['lot']                  = $lot ? array( 'record_no' => self::record_no_of( $lot ), 'title' => get_the_title( $lot ) ) : null;
		$out['burn_tx']              = $tx ?: null;
		$out['burn_tx_url']          = $tx && ! empty( $net['explorer'] ) ? rtrim( $net['explorer'], '/' ) . '/tx/' . $tx : null;
		$out['carrier']              = (string) self::m( $id, 'carrier' ) ?: null;
		$out['tracking_ref']         = (string) self::m( $id, 'tracking_ref' ) ?: null;
		$out['history']              = array_map(
			static fn( $h ) => array(
				'at'     => $h['at'],
				'action' => $h['action'],
				'from'   => $h['from'] ?: null,
				'to'     => $h['to'],
				'reason' => in_array( $h['action'], array( 'reject', 'cancel', 'hold' ), true ) ? ( $h['reason'] ?: null ) : null,
			),
			self::history( $id )
		);
		$out['notice']               = self::is_test( $id ) ? __( 'Test — module inactive', 'reservechain' ) : null;
		return $out;
	}

	/* ================================================================== admin */

	public static function can_view(): bool {
		foreach ( array( 'rc_manage_registry', 'rc_approve', 'rc_manage_compliance', 'rc_view_audit' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	private static function can_see_address(): bool {
		return current_user_can( 'rc_manage_registry' ) || current_user_can( 'rc_manage_compliance' );
	}

	public static function menu(): void {
		add_submenu_page( 'reservechain', __( 'Redemptions', 'reservechain' ), __( 'Redemptions', 'reservechain' ), 'edit_rc_entities', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function hide_registry_entry(): void {
		remove_submenu_page( 'reservechain-registry', 'edit.php?post_type=' . self::TYPE );
	}

	/** The generic registry editor would bypass the workflow — send staff to the Redemptions screen instead. */
	public static function redirect_core_screens(): void {
		$type = isset( $_GET['post'] ) ? get_post_type( absint( $_GET['post'] ) ) : sanitize_key( $_GET['post_type'] ?? '' ); // phpcs:ignore
		if ( self::TYPE !== $type ) {
			return;
		}
		$id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	/** Ignore registry-field writes posted to an rc_redemption from generic screens. */
	public static function guard_direct_save(): void {
		if ( isset( $_POST['rc_field'] ) ) { // phpcs:ignore
			unset( $_POST['rc_field'], $_POST['rc_fields_nonce'] ); // phpcs:ignore
		}
	}

	public static function url( int $id = 0, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $id ? array( 'id' => $id ) : array(), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'rc-redemption-admin', RC_URL . 'assets/redemption-admin.css', array(), RC_VERSION );
		wp_enqueue_script( 'rc-redemption-admin', RC_URL . 'assets/redemption-admin.js', array(), RC_VERSION, true );
		wp_localize_script( 'rc-redemption-admin', 'rcRedemption', array( 'confirm' => __( 'Confirm this redemption step? It will be recorded in the audit trail.', 'reservechain' ) ) );
	}

	private static function notice(): void {
		$key = 'rc_rdm_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msg[0] ), esc_html( $msg[1] ) );
		}
	}

	private static function flash( $result, string $ok ): void {
		set_transient( 'rc_rdm_notice_' . get_current_user_id(), is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', $ok ), 60 );
	}

	private static function pill( string $state ): string {
		$labels = self::state_labels();
		return '<span class="rc-pill rc-rdm-pill rc-rdm-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? $state ) . '</span>';
	}

	private static function test_badge(): string {
		return '<span class="rc-rdm-test">' . esc_html__( 'Test — module inactive', 'reservechain' ) . '</span>';
	}

	public static function page(): void {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'Your role does not permit this action.', 'reservechain' ) );
		}
		echo '<div class="wrap rc-wrap rc-rdm">';
		self::notice();
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore
		if ( $id && self::get( $id ) ) {
			self::render_detail( $id );
		} else {
			self::render_queue();
		}
		echo '</div>';
	}

	private static function status_banner(): void {
		$live = self::is_live();
		echo '<div class="rc-panel rc-rdm-status ' . ( $live ? 'is-live' : 'is-off' ) . '"><p><strong>' . esc_html__( 'Module status:', 'reservechain' ) . '</strong> ';
		if ( $live ) {
			echo esc_html__( 'Active — requests are accepted through the authenticated API.', 'reservechain' );
		} else {
			echo esc_html__( 'Inactive — requests are refused until the module is authorized and the site is in Redemption mode.', 'reservechain' );
		}
		echo '</p><p class="description">' . esc_html__( 'Redemption minimum:', 'reservechain' ) . ' <em>' . esc_html( (string) Settings::get( 'redemption_min', '' ) ?: __( 'per token program', 'reservechain' ) ) . '</em> · ' . esc_html__( 'Fees:', 'reservechain' ) . ' <em>' . esc_html( self::fee_schedule() ?? self::tbd() ) . '</em></p></div>';
	}

	private static function render_queue(): void {
		echo '<h1 class="rc-h1"><span class="rc-mark">RDM</span> ' . esc_html__( 'Redemptions', 'reservechain' ) . '</h1>';
		self::status_banner();

		$ids = array_map(
			'intval',
			get_posts( array( 'post_type' => self::TYPE, 'post_status' => array( 'private', 'draft', 'publish' ), 'posts_per_page' => 500, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) )
		);
		$groups = array_fill_keys( array_keys( self::state_labels() ), array() );
		foreach ( $ids as $id ) {
			$groups[ self::state( $id ) ?: 'requested' ][] = $id;
		}
		echo '<div class="rc-cards">';
		foreach ( self::state_labels() as $s => $l ) {
			printf( '<a class="rc-card rc-rdm-card" href="#rdm-%1$s"><span>%2$s</span><strong>%3$d</strong></a>', esc_attr( $s ), esc_html( $l ), count( $groups[ $s ] ) );
		}
		echo '</div>';

		foreach ( $groups as $state => $list ) {
			if ( ! $list ) {
				continue;
			}
			echo '<div class="rc-panel" id="rdm-' . esc_attr( $state ) . '"><h2>' . self::pill( $state ) . ' <small>(' . count( $list ) . ')</small></h2>'; // phpcs:ignore
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Record no.', 'reservechain' ) . '</th><th>' . esc_html__( 'Requester', 'reservechain' ) . '</th><th>' . esc_html__( 'Token program', 'reservechain' ) . '</th><th>' . esc_html__( 'Amount', 'reservechain' ) . '</th><th>' . esc_html__( 'Units', 'reservechain' ) . '</th><th>' . esc_html__( 'Requested', 'reservechain' ) . '</th><th>' . esc_html__( 'Next steps', 'reservechain' ) . '</th></tr></thead><tbody>';
			foreach ( $list as $id ) {
				$next = array();
				foreach ( self::TRANSITIONS as $a => $def ) {
					if ( in_array( $state, $def[0], true ) && self::can( $id, $a ) ) {
						$next[] = self::action_labels()[ $a ];
					}
				}
				$tp = (int) self::m( $id, 'token_program' );
				printf(
					'<tr><td><a href="%s"><code>%s</code></a> %s</td><td>%s</td><td>%s</td><td>%s</td><td>%d%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( self::url( $id ) ),
					esc_html( self::record_no( $id ) ),
					self::is_test( $id ) ? self::test_badge() : '', // phpcs:ignore
					esc_html( self::user_label( self::requester( $id ) ) ),
					esc_html( $tp ? self::record_no_of( $tp ) : '—' ),
					esc_html( (string) self::m( $id, 'amount' ) ),
					count( self::units( $id ) ),
					self::m( $id, 'lot' ) ? ' + ' . esc_html__( 'lot', 'reservechain' ) : '',
					esc_html( get_post_time( 'Y-m-d H:i', true, $id ) . ' UTC' ),
					esc_html( $next ? implode( ' · ', $next ) : '—' )
				);
			}
			echo '</tbody></table></div>';
		}
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No redemption requests.', 'reservechain' ) . '</p>';
		}
		self::render_create_test();
	}

	/** Program-scoped unit options (coils / containers / lots) with availability markers. */
	private static function unit_options( int $token_program, array $selected, int $except = 0, string $types = 'units' ): string {
		$program = $token_program ? (int) get_post_meta( $token_program, Schema::meta_key( 'program' ), true ) : 0;
		$args    = array(
			'post_type'      => 'units' === $types ? self::UNIT_TYPES : array( 'rc_lot' ),
			'post_status'    => array( 'publish', 'draft', 'rc_review', 'rc_approved' ),
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $program ) {
			$args['meta_query'] = array( array( 'key' => Schema::meta_key( 'program' ), 'value' => $program ) ); // phpcs:ignore
		}
		$html = '';
		foreach ( get_posts( $args ) as $p ) {
			$status = (string) get_post_meta( $p->ID, Schema::meta_key( 'redemption_status' ), true ) ?: 'not_available';
			$held   = self::holders( $p->ID, $except );
			$sel    = in_array( $p->ID, $selected, true );
			$taken  = ! $sel && ( $held || ! in_array( $status, array( 'not_available', 'eligible' ), true ) );
			$html  .= sprintf(
				'<option value="%d"%s%s>%s · %s%s</option>',
				(int) $p->ID,
				$sel ? ' selected' : '',
				$taken ? ' disabled' : '',
				esc_html( self::record_no_of( $p->ID ) ),
				esc_html( $p->post_title ),
				$taken ? ' — ' . esc_html( $held ? sprintf( /* translators: %s: redemption record number */ __( 'in %s', 'reservechain' ), self::record_no( $held[0] ) ) : $status ) : ''
			);
		}
		return $html;
	}

	private static function render_create_test(): void {
		if ( 'development' !== RC_ENV || ! current_user_can( 'rc_manage_registry' ) ) {
			return;
		}
		$tps = get_posts( array( 'post_type' => 'rc_token_program', 'post_status' => array( 'publish', 'draft', 'rc_review', 'rc_approved' ), 'posts_per_page' => 100 ) );
		echo '<div class="rc-panel rc-rdm-testform"><h2>' . esc_html__( 'Create a test redemption', 'reservechain' ) . ' ' . self::test_badge() . '</h2>'; // phpcs:ignore
		echo '<p class="description">' . esc_html__( 'Development environment only. Test records are labelled everywhere, run through the full workflow and compliance checks, and never involve real tokens or assets.', 'reservechain' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rc-rdm-form">';
		wp_nonce_field( 'rc_rdm_create_test' );
		echo '<input type="hidden" name="action" value="rc_rdm_create_test">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="rdm_req">' . esc_html__( 'Requester (login or email)', 'reservechain' ) . '</label></th><td><input id="rdm_req" name="requester" class="regular-text" required></td></tr>';
		echo '<tr><th><label for="rdm_tp">' . esc_html__( 'Token program', 'reservechain' ) . '</label></th><td><select id="rdm_tp" name="token_program" required>';
		foreach ( $tps as $tp ) {
			printf( '<option value="%d">%s · %s</option>', (int) $tp->ID, esc_html( self::record_no_of( $tp->ID ) ), esc_html( $tp->post_title ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="rdm_units">' . esc_html__( 'Containers / coils', 'reservechain' ) . '</label></th><td><select id="rdm_units" name="units[]" multiple size="8" class="rc-rdm-multi">' . self::unit_options( 0, array() ) . '</select><p class="description">' . esc_html__( 'Must belong to the token program’s metal program. Units already in an open redemption are disabled.', 'reservechain' ) . '</p></td></tr>'; // phpcs:ignore
		echo '<tr><th><label for="rdm_lot">' . esc_html__( 'Or a whole lot', 'reservechain' ) . '</label></th><td><select id="rdm_lot" name="lot"><option value="">— ' . esc_html__( 'none', 'reservechain' ) . ' —</option>' . self::unit_options( 0, array(), 0, 'lots' ) . '</select></td></tr>'; // phpcs:ignore
		echo '<tr><th><label for="rdm_amt">' . esc_html__( 'Token amount', 'reservechain' ) . '</label></th><td><input id="rdm_amt" name="amount" type="number" step="any" min="0" required></td></tr>';
		echo '<tr><th>' . esc_html__( 'Delivery method', 'reservechain' ) . '</th><td><label><input type="radio" name="delivery_method" value="collection" checked> ' . esc_html__( 'Collection', 'reservechain' ) . '</label> &nbsp; <label><input type="radio" name="delivery_method" value="delivery"> ' . esc_html__( 'Delivery', 'reservechain' ) . '</label></td></tr>';
		echo '<tr><th><label for="rdm_addr">' . esc_html__( 'Delivery address', 'reservechain' ) . '</label></th><td><textarea id="rdm_addr" name="delivery_address" rows="3" class="large-text"></textarea><p class="description">' . esc_html__( 'Stored encrypted. Only its fingerprint is written to the audit trail.', 'reservechain' ) . '</p></td></tr>';
		echo '</table><p><button class="button button-primary">' . esc_html__( 'Create test redemption', 'reservechain' ) . '</button></p></form></div>';
	}

	private static function render_detail( int $id ): void {
		$state = self::state( $id );
		$tp    = (int) self::m( $id, 'token_program' );
		echo '<p><a href="' . esc_url( self::url() ) . '">← ' . esc_html__( 'All redemptions', 'reservechain' ) . '</a></p>';
		echo '<h1 class="rc-h1"><span class="rc-mark">RDM</span> ' . esc_html( self::record_no( $id ) ) . ' ' . self::pill( $state ) . ( self::is_test( $id ) ? ' ' . self::test_badge() : '' ) . '</h1>'; // phpcs:ignore

		echo '<div class="rc-grid2"><div>';
		// Summary.
		$terms = (array) get_post_meta( $id, '_rc_rdm_terms', true );
		$rows  = array(
			__( 'Requester', 'reservechain' )       => self::user_label( self::requester( $id ) ),
			__( 'Created by', 'reservechain' )      => self::user_label( (int) get_post_meta( $id, '_rc_rdm_created_by', true ) ),
			__( 'Prepared by', 'reservechain' )     => self::user_label( (int) get_post_meta( $id, '_rc_rdm_prepared_by', true ) ),
			__( 'Approved by', 'reservechain' )     => self::user_label( (int) get_post_meta( $id, '_rc_rdm_approved_by', true ) ),
			__( 'Token program', 'reservechain' )   => $tp ? self::record_no_of( $tp ) . ' · ' . get_the_title( $tp ) : '—',
			__( 'Token amount', 'reservechain' )    => (string) self::m( $id, 'amount' ),
			__( 'Redemption minimum', 'reservechain' ) => (string) ( $terms['minimum'] ?? self::minimum( $tp ) ?? self::tbd() ),
			__( 'Fees', 'reservechain' )            => (string) ( $terms['fee_schedule'] ?? self::fee_schedule() ?? self::tbd() ),
			__( 'Delivery method', 'reservechain' ) => (string) self::m( $id, 'delivery_method' ),
			__( 'Approval fingerprint', 'reservechain' ) => (string) get_post_meta( $id, '_rc_rdm_approved_fp', true ) ?: '—',
		);
		echo '<div class="rc-panel"><h2>' . esc_html__( 'Summary', 'reservechain' ) . '</h2><table class="widefat rc-rdm-kv"><tbody>';
		foreach ( $rows as $k => $v ) {
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $k ), esc_html( $v ) );
		}
		$enc = (string) get_post_meta( $id, '_rc_rdm_address_enc', true );
		echo '<tr><th>' . esc_html__( 'Delivery address', 'reservechain' ) . '</th><td>';
		if ( ! $enc ) {
			echo '—';
		} elseif ( self::can_see_address() && isset( $_GET['reveal'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ?? '' ), 'rc_rdm_reveal_' . $id ) ) { // phpcs:ignore
			Audit_Log::record( 'redemption.address_viewed', self::TYPE, $id, self::record_no( $id ) . ': delivery address viewed', array( 'address_sha256' => get_post_meta( $id, '_rc_rdm_address_hash', true ) ) );
			echo '<pre class="rc-rdm-addr">' . esc_html( (string) Auth::decrypt( $enc ) ) . '</pre>';
		} elseif ( self::can_see_address() ) {
			echo '<em>' . esc_html__( 'Encrypted', 'reservechain' ) . '</em> <a class="button button-small" href="' . esc_url( wp_nonce_url( self::url( $id, array( 'reveal' => 1 ) ), 'rc_rdm_reveal_' . $id ) ) . '">' . esc_html__( 'Reveal (logged)', 'reservechain' ) . '</a>';
		} else {
			echo '<em>' . esc_html__( 'Encrypted', 'reservechain' ) . '</em>';
		}
		echo '<br><code class="rc-hash">sha256 ' . esc_html( substr( (string) get_post_meta( $id, '_rc_rdm_address_hash', true ), 0, 16 ) ) . '…</code></td></tr>';
		echo '</tbody></table></div>';

		// Units.
		echo '<div class="rc-panel"><h2>' . esc_html__( 'Units', 'reservechain' ) . '</h2>';
		$affected = self::affected( $id );
		if ( $affected ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Record no.', 'reservechain' ) . '</th><th>' . esc_html__( 'Record', 'reservechain' ) . '</th><th>' . esc_html__( 'Redemption status', 'reservechain' ) . '</th></tr></thead><tbody>';
			foreach ( $affected as $u ) {
				$direct = in_array( $u, self::units( $id ), true ) || (int) self::m( $id, 'lot' ) === $u;
				printf( '<tr><td><a href="%s"><code>%s</code></a></td><td>%s%s</td><td>%s</td></tr>', esc_url( (string) get_edit_post_link( $u ) ), esc_html( self::record_no_of( $u ) ), esc_html( get_the_title( $u ) ), $direct ? '' : ' <small>(' . esc_html__( 'via lot', 'reservechain' ) . ')</small>', esc_html( (string) get_post_meta( $u, Schema::meta_key( 'redemption_status' ), true ) ?: 'not_available' ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No units selected yet.', 'reservechain' ) . '</p>';
		}
		if ( in_array( $state, array( 'requested', 'compliance_review' ), true ) && current_user_can( 'rc_manage_registry' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rc-rdm-form"><input type="hidden" name="action" value="rc_rdm_units"><input type="hidden" name="id" value="' . (int) $id . '">';
			wp_nonce_field( 'rc_rdm_units_' . $id );
			echo '<p><label>' . esc_html__( 'Containers / coils', 'reservechain' ) . '<br><select name="units[]" multiple size="8" class="rc-rdm-multi">' . self::unit_options( $tp, self::units( $id ), $id ) . '</select></label></p>'; // phpcs:ignore
			echo '<p><label>' . esc_html__( 'Or a whole lot', 'reservechain' ) . '<br><select name="lot"><option value="">— ' . esc_html__( 'none', 'reservechain' ) . ' —</option>' . self::unit_options( $tp, array( (int) self::m( $id, 'lot' ) ), $id, 'lots' ) . '</select></label></p>'; // phpcs:ignore
			echo '<p><button class="button">' . esc_html__( 'Save unit selection', 'reservechain' ) . '</button></p></form>';
		}
		echo '</div>';

		// Evidence.
		$net = (array) Settings::get( 'network' );
		$tx  = (string) self::m( $id, 'burn_tx' );
		$doc = static fn( $d ) => $d ? '<a href="' . esc_url( (string) get_edit_post_link( (int) $d ) ) . '"><code>' . esc_html( self::record_no_of( (int) $d ) ) . '</code></a> <code class="rc-hash">' . esc_html( substr( (string) get_post_meta( (int) $d, '_rc_sha256', true ), 0, 16 ) ) . '…</code>' : '—';
		echo '<div class="rc-panel"><h2>' . esc_html__( 'Evidence', 'reservechain' ) . '</h2><table class="widefat rc-rdm-kv"><tbody>';
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Burn transaction', 'reservechain' ), $tx ? '<a target="_blank" rel="noopener" href="' . esc_url( rtrim( (string) ( $net['explorer'] ?? '' ), '/' ) . '/tx/' . $tx ) . '"><code class="rc-hash">' . esc_html( $tx ) . '</code></a>' : '—' ); // phpcs:ignore
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'On-chain request ID', 'reservechain' ), esc_html( (string) self::m( $id, 'onchain_request_id' ) ?: '—' ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Custody release document', 'reservechain' ), $doc( self::m( $id, 'release_document' ) ) ); // phpcs:ignore
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Carrier / collecting party', 'reservechain' ), esc_html( (string) self::m( $id, 'carrier' ) ?: '—' ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Tracking / collection reference', 'reservechain' ), esc_html( (string) self::m( $id, 'tracking_ref' ) ?: '—' ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Customs / logistics documents', 'reservechain' ), implode( '<br>', array_map( $doc, self::multi( $id, 'customs_documents' ) ) ) ?: '—' ); // phpcs:ignore
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Delivery confirmation document', 'reservechain' ), $doc( self::m( $id, 'delivery_document' ) ) ); // phpcs:ignore
		echo '</tbody></table></div>';

		echo '</div><div>';
		self::render_actions( $id );

		// Compliance snapshots.
		echo '<div class="rc-panel"><h2>' . esc_html__( 'Compliance snapshots', 'reservechain' ) . '</h2>';
		$snaps = (array) get_post_meta( $id, '_rc_rdm_compliance', true );
		$live  = Compliance::eligibility( self::requester( $id ) );
		$cols  = array_merge( array_filter( $snaps, 'is_array' ), array( 'current' => $live ) );
		echo '<table class="widefat striped"><thead><tr><th></th>';
		foreach ( array_keys( $cols ) as $stage ) {
			$names = array( 'request' => __( 'At request', 'reservechain' ), 'approval' => __( 'At approval', 'reservechain' ), 'current' => __( 'Current', 'reservechain' ) );
			echo '<th>' . esc_html( $names[ $stage ] ?? $stage ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array( 'country', 'jurisdiction', 'entity_type', 'kyc', 'kyb', 'aml', 'sanctions', 'overall', 'at' ) as $k ) {
			echo '<tr><th>' . esc_html( $k ) . '</th>';
			foreach ( $cols as $c ) {
				echo '<td>' . esc_html( (string) ( $c[ $k ] ?? '—' ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		// Timeline.
		echo '<div class="rc-panel"><h2>' . esc_html__( 'Timeline', 'reservechain' ) . '</h2><ol class="rc-rdm-timeline">';
		$alabels = self::action_labels();
		foreach ( array_reverse( self::history( $id ) ) as $h ) {
			printf(
				'<li class="rc-rdm-ev rc-rdm-ev-%s"><span class="rc-rdm-at">%s UTC</span> <strong>%s</strong> %s<br><small>%s%s</small>%s</li>',
				esc_attr( $h['to'] ),
				esc_html( str_replace( 'T', ' ', substr( $h['at'], 0, 19 ) ) ),
				esc_html( $alabels[ $h['action'] ] ?? $h['action'] ),
				( $h['from'] && $h['from'] !== $h['to'] ? self::pill( $h['from'] ) . ' → ' : '' ) . self::pill( $h['to'] ), // phpcs:ignore
				esc_html( self::user_label( (int) $h['user'] ) ),
				$h['reason'] ? ' — ' . esc_html( $h['reason'] ) : '',
				$h['evidence'] ? '<details><summary>' . esc_html__( 'Evidence', 'reservechain' ) . '</summary><pre>' . esc_html( (string) wp_json_encode( $h['evidence'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>' : ''
			);
		}
		echo '</ol></div>';
		echo '</div></div>';
	}

	private static function doc_select( string $name, bool $multi = false ): string {
		$docs = get_posts( array( 'post_type' => 'rc_document', 'post_status' => array( 'publish', 'draft', 'rc_review', 'rc_approved', 'private' ), 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC' ) );
		$html = sprintf( '<select name="%s%s"%s class="rc-rdm-doc">', esc_attr( $name ), $multi ? '[]' : '', $multi ? ' multiple size="5"' : '' );
		if ( ! $multi ) {
			$html .= '<option value="">— ' . esc_html__( 'select document', 'reservechain' ) . ' —</option>';
		}
		foreach ( $docs as $d ) {
			$sha   = (string) get_post_meta( $d->ID, '_rc_sha256', true );
			$html .= sprintf( '<option value="%d"%s>%s · %s%s</option>', (int) $d->ID, $sha ? '' : ' disabled', esc_html( self::record_no_of( $d->ID ) ), esc_html( $d->post_title ), $sha ? '' : ' — ' . esc_html__( 'no file', 'reservechain' ) );
		}
		return $html . '</select>';
	}

	private static function render_actions( int $id ): void {
		$state = self::state( $id );
		echo '<div class="rc-panel rc-rdm-actions"><h2>' . esc_html__( 'Actions', 'reservechain' ) . '</h2>';
		$any = false;
		foreach ( self::TRANSITIONS as $action => $def ) {
			if ( ! in_array( $state, $def[0], true ) ) {
				continue;
			}
			$any   = true;
			$label = self::action_labels()[ $action ];
			$why   = '';
			if ( ! self::can( $id, $action, $why ) ) {
				printf( '<p class="rc-rdm-blocked"><span class="button disabled">%s</span> <small>%s</small></p>', esc_html( $label ), esc_html( $why ) );
				continue;
			}
			echo '<details class="rc-rdm-act"><summary class="button ' . ( in_array( $action, array( 'reject', 'hold' ), true ) ? '' : 'button-primary' ) . '">' . esc_html( $label ) . '</summary>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="rc-rdm-form" data-rc-confirm="1"><input type="hidden" name="action" value="rc_rdm_action"><input type="hidden" name="do" value="' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . (int) $id . '">';
			wp_nonce_field( 'rc_rdm_' . $action . '_' . $id );
			switch ( $action ) {
				case 'record_burn':
					echo '<p><label>' . esc_html__( 'Burn transaction hash (testnet)', 'reservechain' ) . '<br><input name="burn_tx" class="large-text code" pattern="0x[0-9a-fA-F]{64}" required></label></p>';
					echo '<p><label>' . esc_html__( 'On-chain request ID (optional)', 'reservechain' ) . '<br><input name="onchain_request_id" class="regular-text"></label></p>';
					break;
				case 'release_custody':
					echo '<p><label>' . esc_html__( 'Custody release document', 'reservechain' ) . '<br>' . self::doc_select( 'release_document' ) . '</label></p>'; // phpcs:ignore
					break;
				case 'dispatch':
					echo '<p><label>' . esc_html__( 'Carrier / collecting party', 'reservechain' ) . '<br><input name="carrier" class="regular-text" required></label></p>';
					echo '<p><label>' . esc_html__( 'Tracking / collection reference', 'reservechain' ) . '<br><input name="tracking_ref" class="regular-text" required></label></p>';
					echo '<p><label>' . esc_html__( 'Customs / logistics documents', 'reservechain' ) . '<br>' . self::doc_select( 'customs_documents', true ) . '</label></p>'; // phpcs:ignore
					break;
				case 'confirm_delivery':
					echo '<p><label>' . esc_html__( 'Delivery confirmation document', 'reservechain' ) . '<br>' . self::doc_select( 'delivery_document' ) . '</label></p>'; // phpcs:ignore
					break;
			}
			$required = in_array( $action, array( 'reject', 'hold' ), true );
			echo '<p><label>' . esc_html( $required ? __( 'Reason (required)', 'reservechain' ) : __( 'Comment (optional)', 'reservechain' ) ) . '<br><textarea name="reason" rows="2" class="large-text"' . ( $required ? ' required minlength="5"' : '' ) . '></textarea></label></p>';
			echo '<p><button class="button button-primary">' . esc_html( $label ) . '</button></p></form></details>';
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No further actions: this redemption is closed.', 'reservechain' ) . '</p>';
		}
		echo '</div>';
	}

	public static function handle_action(): void {
		$action = sanitize_key( $_POST['do'] ?? '' ); // phpcs:ignore
		$id     = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'rc_rdm_' . $action . '_' . $id );
		$in  = array(
			'reason'             => wp_unslash( $_POST['reason'] ?? '' ), // phpcs:ignore
			'burn_tx'            => sanitize_text_field( wp_unslash( $_POST['burn_tx'] ?? '' ) ),
			'onchain_request_id' => sanitize_text_field( wp_unslash( $_POST['onchain_request_id'] ?? '' ) ),
			'release_document'   => absint( $_POST['release_document'] ?? 0 ),
			'carrier'            => sanitize_text_field( wp_unslash( $_POST['carrier'] ?? '' ) ),
			'tracking_ref'       => sanitize_text_field( wp_unslash( $_POST['tracking_ref'] ?? '' ) ),
			'customs_documents'  => array_map( 'absint', (array) ( $_POST['customs_documents'] ?? array() ) ),
			'delivery_document'  => absint( $_POST['delivery_document'] ?? 0 ),
		);
		$res = self::apply( $id, $action, $in );
		self::flash( $res, ( self::action_labels()[ $action ] ?? $action ) . ' — ' . __( 'done.', 'reservechain' ) );
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	public static function handle_units(): void {
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'rc_rdm_units_' . $id );
		$res = self::select_units( $id, array_map( 'absint', (array) ( $_POST['units'] ?? array() ) ), absint( $_POST['lot'] ?? 0 ) );
		self::flash( $res, __( 'Unit selection saved.', 'reservechain' ) );
		wp_safe_redirect( self::url( $id ) );
		exit;
	}

	public static function handle_create_test(): void {
		check_admin_referer( 'rc_rdm_create_test' );
		$who  = sanitize_text_field( wp_unslash( $_POST['requester'] ?? '' ) );
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		$res  = self::create(
			array(
				'requester'        => $user ? $user->ID : 0,
				'token_program'    => absint( $_POST['token_program'] ?? 0 ),
				'amount'           => sanitize_text_field( wp_unslash( $_POST['amount'] ?? '' ) ),
				'units'            => array_map( 'absint', (array) ( $_POST['units'] ?? array() ) ),
				'lot'              => absint( $_POST['lot'] ?? 0 ),
				'delivery_method'  => sanitize_key( $_POST['delivery_method'] ?? '' ),
				'delivery_address' => wp_unslash( $_POST['delivery_address'] ?? '' ), // phpcs:ignore -- sanitised in create().
				'channel'          => 'admin_test',
			),
			true
		);
		self::flash( $res, __( 'Test redemption created.', 'reservechain' ) );
		wp_safe_redirect( is_wp_error( $res ) ? self::url() : self::url( (int) $res ) );
		exit;
	}
}
