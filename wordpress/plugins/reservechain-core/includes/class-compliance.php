<?php
/**
 * Compliance controls: jurisdiction screening, KYC / KYB / AML / sanctions status, eligibility.
 *
 * ReserveChain does not perform identity verification itself; this module stores the outcome of an
 * external KYC/KYB provider (to be selected) and enforces eligibility consistently across website,
 * API and apps. Provider webhooks plug in via the `rc_compliance_update` action.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Compliance {

	public const CHECKS = array(
		'kyc'       => 'KYC (individual identity)',
		'kyb'       => 'KYB (business verification)',
		'aml'       => 'AML screening',
		'sanctions' => 'Sanctions / PEP screening',
	);

	public const CHECK_STATES = array(
		'not_started'    => 'Not started',
		'pending'        => 'Pending',
		'approved'       => 'Approved',
		'rejected'       => 'Rejected',
		'not_applicable' => 'Not applicable',
	);

	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'rc_compliance_update', array( __CLASS__, 'set_check' ), 10, 4 );
	}

	/**
	 * Jurisdiction status for a country code.
	 *
	 * @return string eligible | restricted | pending
	 */
	public static function jurisdiction( string $country ): string {
		$country = strtoupper( $country );
		if ( '' === $country || ! isset( Schema::countries()[ $country ] ) ) {
			return 'pending';
		}
		if ( Settings::get( 'restrict_eu_eea' ) && in_array( $country, Schema::eu_eea_countries(), true ) ) {
			return 'restricted';
		}
		if ( in_array( $country, (array) Settings::get( 'restricted_countries', array() ), true ) ) {
			return 'restricted';
		}
		return 'eligible';
	}

	public static function reason( string $country ): ?string {
		$country = strtoupper( $country );
		if ( Settings::get( 'restrict_eu_eea' ) && in_array( $country, Schema::eu_eea_countries(), true ) ) {
			return 'eu_eea';
		}
		if ( in_array( $country, (array) Settings::get( 'restricted_countries', array() ), true ) ) {
			return 'restricted_jurisdiction';
		}
		return null;
	}

	/** Country signal from the edge (Cloudflare / CloudFront) to flag self-declaration mismatches. */
	public static function edge_country(): ?string {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$c = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
				return preg_match( '/^[A-Z]{2}$/', $c ) ? $c : null;
			}
		}
		return null;
	}

	public static function eligibility( int $user_id ): array {
		$country = (string) get_user_meta( $user_id, 'rc_country', true );
		$entity  = get_user_meta( $user_id, 'rc_entity_type', true ) ?: 'individual';
		$out     = array(
			'country'      => $country ?: null,
			'entity_type'  => $entity,
			'jurisdiction' => self::jurisdiction( $country ),
		);
		foreach ( array_keys( self::CHECKS ) as $check ) {
			$v = get_user_meta( $user_id, 'rc_' . $check, true );
			if ( ! $v ) {
				$v = ( 'kyb' === $check && 'individual' === $entity ) || ( 'kyc' === $check && 'institution' === $entity ) ? 'not_applicable' : 'not_started';
			}
			$out[ $check ] = $v;
		}
		$required          = 'institution' === $entity ? array( 'kyb', 'aml', 'sanctions' ) : array( 'kyc', 'aml', 'sanctions' );
		$all_ok            = 'eligible' === $out['jurisdiction'] && ! array_diff( $required, array_keys( array_filter( $out, static fn( $v ) => 'approved' === $v ) ) );
		$out['overall']    = 'restricted' === $out['jurisdiction'] ? 'restricted' : ( $all_ok ? 'eligible_subject_to_final_approval' : 'incomplete' );
		$out['note']       = 'Eligibility status does not confer any right to participate in any future offering.';
		return $out;
	}

	public static function set_check( int $user_id, string $check, string $state, string $source = 'manual' ): bool {
		if ( ! isset( self::CHECKS[ $check ] ) || ! isset( self::CHECK_STATES[ $state ] ) ) {
			return false;
		}
		$old = get_user_meta( $user_id, 'rc_' . $check, true ) ?: 'not_started';
		if ( $old === $state ) {
			return true;
		}
		update_user_meta( $user_id, 'rc_' . $check, $state );
		Audit_Log::record( 'compliance.' . $check, 'user', $user_id, sprintf( '%s: %s → %s', self::CHECKS[ $check ], $old, $state ), array( 'source' => $source ) );
		Notifications::push( $user_id, 'Compliance status updated', sprintf( '%s status: %s.', self::CHECKS[ $check ], self::CHECK_STATES[ $state ] ), 'compliance' );
		return true;
	}

	public static function profile_fields( \WP_User $user ): void {
		$elig = self::eligibility( $user->ID );
		$can  = current_user_can( 'rc_manage_compliance' );
		wp_nonce_field( 'rc_compliance_' . $user->ID, 'rc_compliance_nonce' );
		echo '<h2>ReserveChain eligibility</h2><table class="form-table" role="presentation">';
		echo '<tr><th>Country of residence</th><td><select name="rc_country"' . disabled( ! $can && get_user_meta( $user->ID, 'rc_country', true ), true, false ) . '><option value="">— not provided —</option>';
		foreach ( Schema::countries() as $code => $name ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $code ), selected( $elig['country'], $code, false ), esc_html( $name ) );
		}
		echo '</select> <span class="rc-pill rc-pill-' . esc_attr( $elig['jurisdiction'] ) . '">' . esc_html( ucfirst( $elig['jurisdiction'] ) ) . '</span></td></tr>';
		echo '<tr><th>Entity type</th><td><select name="rc_entity_type"' . disabled( ! $can, true, false ) . '>';
		foreach ( array( 'individual' => 'Individual', 'institution' => 'Institution / company' ) as $k => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $elig['entity_type'], $k, false ), esc_html( $l ) );
		}
		echo '</select></td></tr>';
		foreach ( self::CHECKS as $check => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>';
			if ( $can ) {
				echo '<select name="rc_check[' . esc_attr( $check ) . ']">';
				foreach ( self::CHECK_STATES as $k => $l ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $elig[ $check ], $k, false ), esc_html( $l ) );
				}
				echo '</select>';
			} else {
				echo esc_html( self::CHECK_STATES[ $elig[ $check ] ] ?? $elig[ $check ] );
			}
			echo '</td></tr>';
		}
		echo '<tr><th>Overall</th><td><strong>' . esc_html( str_replace( '_', ' ', $elig['overall'] ) ) . '</strong><p class="description">' . esc_html( $elig['note'] ) . '</p></td></tr>';
		echo '</table>';
	}

	public static function save_profile( int $user_id ): void {
		if ( ! isset( $_POST['rc_compliance_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rc_compliance_nonce'] ), 'rc_compliance_' . $user_id ) ) {
			return;
		}
		$can     = current_user_can( 'rc_manage_compliance' );
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['rc_country'] ?? '' ) ) );
		$old     = get_user_meta( $user_id, 'rc_country', true );
		if ( ( $can || ! $old ) && $country !== $old && ( '' === $country || isset( Schema::countries()[ $country ] ) ) ) {
			update_user_meta( $user_id, 'rc_country', $country );
			Audit_Log::record( 'compliance.country', 'user', $user_id, sprintf( 'Country of residence %s → %s', $old ?: '—', $country ?: '—' ), array( 'jurisdiction' => self::jurisdiction( $country ) ) );
		}
		if ( ! $can ) {
			return;
		}
		$entity = sanitize_key( $_POST['rc_entity_type'] ?? 'individual' );
		if ( in_array( $entity, array( 'individual', 'institution' ), true ) && get_user_meta( $user_id, 'rc_entity_type', true ) !== $entity ) {
			update_user_meta( $user_id, 'rc_entity_type', $entity );
			Audit_Log::record( 'compliance.entity_type', 'user', $user_id, 'Entity type set to ' . $entity );
		}
		foreach ( (array) ( $_POST['rc_check'] ?? array() ) as $check => $state ) {
			self::set_check( $user_id, sanitize_key( $check ), sanitize_key( $state ), 'admin' );
		}
	}
}
