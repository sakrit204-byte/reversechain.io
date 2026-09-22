<?php
/**
 * Public verification endpoints.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Rest;

use ReserveChain\Core\Content\Publication;
use ReserveChain\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lets anyone confirm that a document they hold is the one the platform relies on.
 *
 * The problem this solves
 * ----------------------
 * A counterparty is handed a Certificate of Analysis as a PDF or an image.
 * Nothing about that file proves it is the version the platform's asset record
 * is built on — it could have been edited in transit, or superseded since.
 *
 * The brief asks for "asset-document hashes or evidence references" [MP10] and
 * "downloadable evidence" on passports [W§4 #14]. A digest turns those into
 * something checkable by someone who does not trust us.
 *
 * Why the file is never uploaded
 * ------------------------------
 * The browser computes SHA-256 locally with WebCrypto and sends only the
 * 64-character digest. That keeps a confidential assay report on the holder's
 * machine, means we cannot retain a document we were never given, and makes
 * the check work on documents we have never seen. It also keeps the endpoint
 * cheap: it is an indexed lookup on a fixed-width column.
 *
 * What the response is careful not to leak
 * ----------------------------------------
 * A match on an unpublished document confirms only that the digest is known to
 * the registry and reports its review state. It does not disclose the title,
 * the lot, the valuation or anything else that has not been approved for
 * publication — the caller already holds the file, so confirming the digest
 * tells them nothing new, while the surrounding record might.
 */
final class VerifyController {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'rc/v1';

	/**
	 * Requests allowed per IP per window.
	 */
	private const RATE_LIMIT = 30;

	/**
	 * Rate-limit window, in seconds.
	 */
	private const RATE_WINDOW = 300;

	/**
	 * Register routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/verify/document/(?P<digest>[a-fA-F0-9]{64})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'verify_document' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'digest' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Lowercase hexadecimal SHA-256 digest of the document.', 'reservechain' ),
						'validate_callback' => static fn ( $value ): bool => is_string( $value ) && 1 === preg_match( '/^[a-fA-F0-9]{64}$/', $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/passport/(?P<public_id>[0-9A-HJKMNP-TV-Z]{26})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'read_passport' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Simple per-IP rate limit.
	 *
	 * Uses a transient rather than a table: the audit trail must not fill with
	 * automated verification attempts, and a dropped counter after a cache
	 * flush is an acceptable failure for a read-only public lookup.
	 *
	 * @param string $bucket Bucket name.
	 */
	private static function rate_limited( string $bucket ): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		$key   = 'rc_rl_' . $bucket . '_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		return false;
	}

	/**
	 * Verify a document digest against the registry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify_document( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		if ( self::rate_limited( 'verify' ) ) {
			return new WP_Error(
				'rc_rate_limited',
				__( 'Too many verification requests. Please wait a few minutes and try again.', 'reservechain' ),
				array( 'status' => 429 )
			);
		}

		$digest = strtolower( (string) $request->get_param( 'digest' ) );
		$table  = $wpdb->prefix . 'rc_documents';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$document = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `sha256` = %s ORDER BY `version` DESC LIMIT 1", $digest ),
			ARRAY_A
		);

		if ( null === $document ) {
			return new WP_REST_Response(
				array(
					'digest'  => $digest,
					'match'   => false,
					'status'  => 'not_found',
					'message' => __( 'This digest does not match any document held in the ReserveChain registry. The file may have been modified, may be from a different source, or may not have been supplied to ReserveChain.', 'reservechain' ),
				),
				200
			);
		}

		$published = Publication::can_display( $document );

		$payload = array(
			'digest'  => $digest,
			'match'   => true,
			'status'  => $published ? 'verified_published' : 'verified_unpublished',
			'message' => $published
				? __( 'This file is byte-identical to the document held in the ReserveChain registry.', 'reservechain' )
				: __( 'This file is byte-identical to a document held in the ReserveChain registry. That document is still under review and has not been approved for publication, so its details are not shown here.', 'reservechain' ),
			'document' => array(
				'version'           => (int) $document['version'],
				'publication_state' => (string) $document['publication_state'],
			),
		);

		// Only an approved, published document may disclose its own metadata.
		if ( $published ) {
			$payload['document'] += array(
				'public_id'       => (string) $document['public_id'],
				'title'           => (string) $document['title'],
				'category'        => (string) $document['category'],
				'document_number' => (string) ( $document['document_number'] ?? '' ),
				'issuer'          => (string) ( $document['issuer'] ?? '' ),
				'issued_on'       => (string) ( $document['issued_on'] ?? '' ),
				'expires_on'      => (string) ( $document['expires_on'] ?? '' ),
			);
		}

		// A verification attempt is an event worth keeping: it evidences that a
		// counterparty checked a document, and when. Recorded at info level so
		// it does not drown material changes.
		Plugin::instance()->audit()->log(
			array(
				'action'       => 'document_verified',
				'entity_type'  => 'document',
				'entity_id'    => (int) $document['id'],
				'entity_label' => $published ? (string) $document['title'] : __( 'Unpublished document', 'reservechain' ),
				'new_value'    => array( 'digest' => $digest, 'result' => $payload['status'] ),
				'severity'     => 'info',
			)
		);

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Machine-readable passport record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function read_passport( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$record = \ReserveChain\Core\Registry\Passports::by_public_id( (string) $request->get_param( 'public_id' ) );

		if ( null === $record || ! \ReserveChain\Core\Registry\Passports::is_viewable( $record ) ) {
			return new WP_Error(
				'rc_passport_not_found',
				__( 'No such Digital Asset Passport.', 'reservechain' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( self::passport_payload( $record ), 200 );
	}

	/**
	 * Shape a passport for public consumption.
	 *
	 * Every status is routed through the publication gate rather than copied
	 * from the row, so the API cannot assert something the website would
	 * refuse to.
	 *
	 * @param array<string,mixed> $record Passport record.
	 * @return array<string,mixed>
	 */
	public static function passport_payload( array $record ): array {
		$unit = (array) $record['unit'];

		$quantity = Publication::quantity_display(
			$unit,
			'declared_net_weight',
			'verified_net_weight',
			(string) ( $unit['weight_unit'] ?? '' )
		);

		$statuses = array();

		foreach ( array( 'verification_status', 'custody_status', 'reserve_status', 'redemption_status' ) as $field ) {
			$display = Publication::status_display( $unit, $field );

			$statuses[ $field ] = array(
				'label'   => $display['label'],
				'claimed' => $display['claimed'],
			);
		}

		return array(
			'display_id'      => $record['display_id'],
			'public_id'       => $record['public_id'],
			'url'             => \ReserveChain\Core\Registry\Passports::url( (string) $record['public_id'] ),
			'is_illustrative' => $record['is_illustrative'],
			'state'           => $record['publication_state'],
			'evidence_hash'   => $record['evidence_hash'],
			'issued_at'       => $record['issued_at'],
			'program'         => array(
				'code' => (string) ( $record['program']['program_code'] ?? '' ),
				'name' => (string) ( $record['program']['name'] ?? '' ),
			),
			'lot'             => array(
				'number' => (string) ( $record['lot']['lot_number'] ?? '' ),
			),
			'unit'            => array(
				'identifier'  => (string) $unit['identifier'],
				'kind'        => (string) $unit['kind'],
				'was_sampled' => (bool) $unit['was_sampled'],
				'sampled_on'  => (string) ( $unit['sampled_on'] ?? '' ),
				'net_weight'  => array(
					'value'     => $quantity['value'],
					'qualifier' => $quantity['qualifier'],
					'verified'  => $quantity['verified'],
				),
			),
			'statuses'        => $statuses,
			'certificates'    => array_map(
				static fn ( array $c ): array => array(
					'number'     => (string) $c['certificate_number'],
					'date'       => (string) ( $c['certificate_date'] ?? '' ),
					'laboratory' => (string) ( $c['laboratory_name'] ?? '' ),
					'method'     => (string) ( $c['test_method'] ?? '' ),
					'standard'   => (string) ( $c['standard_reference'] ?? '' ),
					'purity_pct' => $c['purity_pct'],
					'sha256'     => (string) ( $c['document_sha256'] ?? '' ),
					'state'      => (string) $c['publication_state'],
				),
				(array) $record['certificates']
			),
			'notice'          => __( 'Statuses shown reflect approved evidence only. A status of "Pending" means no approved evidence exists for that item; it is not a statement that the underlying arrangement is absent.', 'reservechain' ),
		);
	}
}
