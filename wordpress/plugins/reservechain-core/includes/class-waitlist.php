<?php
/**
 * Waitlist / registration of interest.
 *
 * - Double opt-in (confirmation token stored as a hash only).
 * - Jurisdiction screening: EU/EEA and restricted jurisdictions are recorded as restricted and receive the
 *   EU/EEA notice; they can opt in to general project updates only.
 * - Explicit acknowledgement of the mandatory disclosure; the exact disclosure text version + hash is stored
 *   with each registration as consent evidence.
 * - Honeypot, minimum fill time and per-IP rate limits.
 * - Registration of interest never creates any allocation, reservation or entitlement.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Waitlist {

	public const MATERIALS = array(
		'cu'     => 'Copper Powder',
		'ni'     => 'Nickel Wire',
		'both'   => 'Both',
		'future' => 'Future asset programs',
	);

	/** Indicative, non-binding. Never a commitment, reservation or allocation. */
	public const RANGES = array(
		'undisclosed' => 'Prefer not to say',
		'lt10k'       => 'Under 10,000 (USD equivalent)',
		'10k_50k'     => '10,000 – 50,000 (USD equivalent)',
		'50k_250k'    => '50,000 – 250,000 (USD equivalent)',
		'250k_1m'     => '250,000 – 1,000,000 (USD equivalent)',
		'gt1m'        => 'Over 1,000,000 (USD equivalent)',
	);

	public const PARTICIPATION = array(
		'updates'      => 'Project updates only',
		'early'        => 'Future early participation (subject to eligibility and approval)',
		'buyer'        => 'Industrial buyer / offtake',
		'owner'        => 'Asset owner / originator',
		'enterprise'   => 'Enterprise services / technology licensing',
		'research'     => 'Media / research',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rc_waitlist';
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'confirm_route' ) );
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function submit( array $in, string $source = 'web' ) {
		global $wpdb;

		if ( ! Settings::module_on( 'waitlist' ) || 'maintenance' === Settings::get( 'site_mode' ) ) {
			return new \WP_Error( 'rc_closed', __( 'The waitlist is currently closed.', 'reservechain' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $in['website'] ) ) { // Honeypot.
			return array( 'ok' => true, 'status' => 'pending_confirmation' );
		}
		if ( 'web' === $source && ! empty( $in['ts'] ) && ( time() - (int) $in['ts'] ) < 3 ) {
			return new \WP_Error( 'rc_too_fast', __( 'Please take a moment to review the form.', 'reservechain' ), array( 'status' => 429 ) );
		}
		if ( ! Security::rate_limit( 'waitlist', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rc_rate', __( 'Too many submissions from this network. Please try again later.', 'reservechain' ), array( 'status' => 429 ) );
		}

		$first   = sanitize_text_field( wp_unslash( $in['first_name'] ?? '' ) );
		$last    = sanitize_text_field( wp_unslash( $in['last_name'] ?? '' ) );
		$name    = trim( $first . ' ' . $last ) ?: sanitize_text_field( wp_unslash( $in['name'] ?? '' ) );
		$material = isset( self::MATERIALS[ $in['materials'] ?? '' ] ) ? $in['materials'] : '';
		$range    = isset( self::RANGES[ $in['interest_range'] ?? '' ] ) ? $in['interest_range'] : 'undisclosed';
		$ptype    = isset( self::PARTICIPATION[ $in['participation_type'] ?? '' ] ) ? $in['participation_type'] : 'updates';
		$nat      = Settings::module_on( 'waitlist_nationality' ) ? strtoupper( sanitize_text_field( (string) ( $in['nationality'] ?? '' ) ) ) : '';
		$loc      = Settings::module_on( 'waitlist_nationality' ) ? strtoupper( sanitize_text_field( (string) ( $in['current_location'] ?? '' ) ) ) : '';
		$email   = sanitize_email( wp_unslash( $in['email'] ?? '' ) );
		$country = strtoupper( sanitize_text_field( wp_unslash( $in['country'] ?? '' ) ) );
		$entity  = in_array( $in['entity_type'] ?? '', array( 'individual', 'institution' ), true ) ? $in['entity_type'] : 'individual';
		$org     = sanitize_text_field( wp_unslash( $in['organisation'] ?? '' ) );
		$lang    = in_array( $in['language'] ?? 'en', array( 'en', 'es', 'it' ), true ) ? $in['language'] : 'en';
		$interest = array_values( array_intersect( array( 'cu', 'ni', 'enterprise' ), array_map( 'sanitize_key', (array) ( $in['interest'] ?? array() ) ) ) );
		$general = ! empty( $in['general_updates'] );

		$errors = array();
		if ( isset( $in['first_name'] ) || isset( $in['last_name'] ) ) {
			if ( mb_strlen( $first ) < 1 ) {
				$errors['first_name'] = __( 'Please enter your first name.', 'reservechain' );
			}
			if ( mb_strlen( $last ) < 1 ) {
				$errors['last_name'] = __( 'Please enter your last name.', 'reservechain' );
			}
		} elseif ( mb_strlen( $name ) < 2 ) {
			$errors['name'] = __( 'Please enter your name.', 'reservechain' );
		}
		if ( '' === $material && empty( $interest ) ) {
			$errors['materials'] = __( 'Please select the material of interest.', 'reservechain' );
		}
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Please enter a valid email address.', 'reservechain' );
		}
		if ( ! isset( Schema::countries()[ $country ] ) ) {
			$errors['country'] = __( 'Please select your country of residence.', 'reservechain' );
		}
		if ( 'institution' === $entity && mb_strlen( $org ) < 2 ) {
			$errors['organisation'] = __( 'Please enter your organisation.', 'reservechain' );
		}
		if ( empty( $in['consent_disclosure'] ) ) {
			$errors['consent_disclosure'] = __( 'You must acknowledge the disclosure.', 'reservechain' );
		}
		if ( empty( $in['consent_privacy'] ) ) {
			$errors['consent_privacy'] = __( 'You must accept the privacy notice.', 'reservechain' );
		}
		if ( $errors ) {
			return new \WP_Error( 'rc_invalid', __( 'Please correct the highlighted fields.', 'reservechain' ), array( 'status' => 422, 'fields' => $errors ) );
		}

		$jurisdiction = Compliance::jurisdiction( $country );
		$edge         = Compliance::edge_country();
		$email_hash   = hash( 'sha256', strtolower( $email ) );
		$token        = wp_generate_password( 40, false );
		$now          = current_time( 'mysql', true );

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::table() . ' WHERE email_hash = %s', $email_hash ), ARRAY_A ); // phpcs:ignore
		$row      = array(
			'updated_at'           => $now,
			'name'                 => $name,
			'email'                => $email,
			'email_hash'           => $email_hash,
			'country'              => $country,
			'entity_type'          => $entity,
			'organisation'         => $org,
			'interest'             => implode( ',', $interest ?: ( 'both' === $material ? array( 'cu', 'ni' ) : ( in_array( $material, array( 'cu', 'ni' ), true ) ? array( $material ) : array() ) ) ),
			'first_name'           => $first,
			'last_name'            => $last,
			'buyer_interest'       => ! empty( $in['buyer_interest'] ) ? 1 : 0,
			'owner_interest'       => ! empty( $in['owner_interest'] ) ? 1 : 0,
			'materials'            => $material,
			'interest_range'       => $range,
			'participation_type'   => $ptype,
			'consent_updates'      => ! empty( $in['consent_updates'] ) ? 1 : 0,
			'nationality'          => isset( Schema::countries()[ $nat ] ) ? $nat : '',
			'current_location'     => isset( Schema::countries()[ $loc ] ) ? $loc : '',
			'campaign_source'      => substr( sanitize_text_field( (string) ( $in['campaign_source'] ?? '' ) ), 0, 100 ),
			'language'             => $lang,
			'jurisdiction_status'  => $jurisdiction,
			'general_updates_only' => 'restricted' === $jurisdiction ? 1 : 0,
			'consent_version'      => 'v' . substr( Settings::disclosure_hash(), 0, 12 ),
			'consent_hash'         => Settings::disclosure_hash(),
			'source'               => sanitize_key( $source ),
			'ip_hash'              => Audit_Log::ip_hash(),
		);

		if ( 'restricted' === $jurisdiction && ! $general ) {
			Audit_Log::record( 'waitlist.restricted', 'waitlist', 0, 'Restricted-jurisdiction registration declined (no general-updates opt-in)', array( 'country' => $country, 'reason' => Compliance::reason( $country ) ), 0 );
			return array(
				'ok'       => true,
				'status'   => 'ineligible_jurisdiction',
				'reason'   => Compliance::reason( $country ),
				'message'  => Settings::get( 'eu_notice' ),
				'stored'   => false,
			);
		}

		if ( $existing && 'confirmed' === $existing['status'] ) {
			$wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ) );
			$id     = (int) $existing['id'];
			$status = 'confirmed';
		} else {
			$row['status']             = 'pending_confirmation';
			$row['confirm_token_hash'] = hash( 'sha256', $token );
			if ( $existing ) {
				$wpdb->update( self::table(), $row, array( 'id' => $existing['id'] ) );
				$id = (int) $existing['id'];
			} else {
				$row['created_at'] = $now;
				$wpdb->insert( self::table(), $row );
				$id = (int) $wpdb->insert_id;
			}
			$status = 'pending_confirmation';
			self::send_confirmation( $email, $name, $token, $lang );
		}

		Audit_Log::record(
			'waitlist.registered',
			'waitlist',
			$id,
			sprintf( 'Waitlist registration (%s, %s, %s)', $country, $entity, $jurisdiction ),
			array( 'email_hash' => $email_hash, 'consent_hash' => $row['consent_hash'], 'edge_country' => $edge, 'mismatch' => $edge && $edge !== $country, 'source' => $source ),
			0
		);

		$out = array(
			'ok'           => true,
			'status'       => $status,
			'jurisdiction' => $jurisdiction,
			'message'      => 'restricted' === $jurisdiction ? Settings::get( 'eu_notice' ) : __( 'Thank you. Please confirm your email address using the link we sent you.', 'reservechain' ),
		);
		if ( 'development' === RC_ENV ) {
			$out['dev_confirm_url'] = self::confirm_url( $token );
		}
		return $out;
	}

	public static function confirm_url( string $token ): string {
		return add_query_arg( array( 'rc_confirm' => $token ), home_url( '/participation/waitlist/' ) );
	}

	private static function send_confirmation( string $email, string $name, string $token, string $lang ): void {
		$subjects = array(
			'en' => 'Confirm your ReserveChain registration of interest',
			'es' => 'Confirme su registro de interés en ReserveChain',
			'it' => 'Conferma la tua registrazione di interesse a ReserveChain',
		);
		$body  = sprintf( "Hello %s,\n\nPlease confirm your registration of interest:\n%s\n\n", $name, self::confirm_url( $token ) );
		$body .= Settings::get( 'disclosure' ) . "\n\n" . Settings::get( 'eu_notice' ) . "\n\nIf you did not request this, ignore this email.\n";
		wp_mail( $email, $subjects[ $lang ] ?? $subjects['en'], $body );
	}

	public static function confirm_route(): void {
		if ( empty( $_GET['rc_confirm'] ) ) { // phpcs:ignore
			return;
		}
		global $wpdb;
		$hash = hash( 'sha256', sanitize_text_field( wp_unslash( $_GET['rc_confirm'] ) ) ); // phpcs:ignore
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE confirm_token_hash = %s AND status = %s', $hash, 'pending_confirmation' ), ARRAY_A ); // phpcs:ignore
		if ( $row ) {
			$wpdb->update( self::table(), array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql', true ), 'confirm_token_hash' => '' ), array( 'id' => $row['id'] ) );
			Audit_Log::record( 'waitlist.confirmed', 'waitlist', (int) $row['id'], 'Waitlist email confirmed', array(), 0 );
			$GLOBALS['rc_waitlist_confirmed'] = true;
		} else {
			$GLOBALS['rc_waitlist_confirmed'] = false;
		}
	}

	public static function stats(): array {
		global $wpdb;
		$t = self::table();
		return array(
			'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore
			'confirmed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status='confirmed'" ), // phpcs:ignore
			'restricted' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE jurisdiction_status='restricted'" ), // phpcs:ignore
			'by_country' => $wpdb->get_results( "SELECT country, COUNT(*) n FROM $t GROUP BY country ORDER BY n DESC LIMIT 10", ARRAY_A ), // phpcs:ignore
			'institutions' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE entity_type='institution'" ), // phpcs:ignore
		);
	}
}
