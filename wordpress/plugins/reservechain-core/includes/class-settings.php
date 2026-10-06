<?php
/**
 * Platform settings: website mode, section visibility, gated modules, disclosures, jurisdictions, network.
 *
 * Gated modules (wallet, purchase, proof_of_reserves, redemption) cannot be switched on without a
 * written-authorization reference; every activation is written to the audit trail.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'rc_settings';

	public const DISCLOSURE = 'ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.';

	public const EU_NOTICE = 'ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.';

	public const MODES = array(
		'prelaunch'     => 'Prelaunch — full informational site, waitlist open, no offering',
		'waitlist_only' => 'Waitlist only — homepage, disclosures and waitlist',
		'maintenance'   => 'Maintenance — holding page for visitors, staff unaffected',
		'live'          => 'Live — locked; requires RC_ALLOW_LIVE_MODE and written authorization',
	);

	public const GATED_MODULES = array(
		'wallet'            => 'Wallet connection',
		'purchase'          => 'Purchase / subscription',
		'proof_of_reserves' => 'Proof of Reserves (live data)',
		'redemption'        => 'Redemption requests',
	);

	public const OPEN_MODULES = array(
		'waitlist'          => 'Waitlist registration',
		'registry_public'   => 'Public Asset Registry',
		'passports_public'  => 'Public Digital Asset Passports',
		'verify_tool'       => 'Document verification tool',
		'app_registration'  => 'Mobile app account registration',
	);

	public const SECTIONS = array(
		'overview'            => 'Project Overview',
		'copper-powder'       => 'Copper Powder program',
		'nickel-wire'         => 'Nickel Wire program',
		'asset-registry'      => 'Asset Registry',
		'digital-asset-passports' => 'Digital Asset Passports',
		'verification'        => 'Verification',
		'custody'             => 'Custody',
		'proof-of-reserves'   => 'Proof of Reserves',
		'tokenization'        => 'Tokenization',
		'redemption'          => 'Redemption',
		'enterprise-services' => 'Enterprise Services',
		'documents'           => 'Documents & Whitepaper',
		'roadmap'             => 'Roadmap',
		'governance'          => 'Governance',
		'faq'                 => 'FAQ',
		'contact'             => 'Contact',
		'waitlist'            => 'Waitlist',
	);

	/** Default comprehensive-sanctions screen. Must be confirmed by counsel before launch. */
	public const DEFAULT_RESTRICTED = array( 'CU', 'IR', 'KP', 'SY', 'RU', 'BY' );

	private static ?array $cache = null;

	public static function init(): void {
		add_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'guard' ), 10, 2 );
	}

	public static function defaults(): array {
		return array(
			'site_mode'            => 'prelaunch',
			'sections'             => array_fill_keys( array_keys( self::SECTIONS ), true ),
			'modules'              => array_merge( array_fill_keys( array_keys( self::GATED_MODULES ), false ), array_fill_keys( array_keys( self::OPEN_MODULES ), true ) ),
			'authorizations'       => array(),
			'disclosure'           => self::DISCLOSURE,
			'eu_notice'            => self::EU_NOTICE,
			'restrict_eu_eea'      => true,
			'restricted_countries' => self::DEFAULT_RESTRICTED,
			'mfa_enforce_staff'    => true,
			'contact_email'        => '',
			'network'              => array(
				'chain_id'       => 11155111,
				'name'           => 'Ethereum Sepolia (testnet)',
				'explorer'       => 'https://sepolia.etherscan.io',
				'token_address'  => '',
				'anchor_address' => '',
			),
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, self::merge( self::all(), $values ), false );
		self::$cache = null;
	}

	public static function module_on( string $module ): bool {
		$modules = self::get( 'modules', array() );
		return ! empty( $modules[ $module ] );
	}

	public static function section_on( string $section ): bool {
		$sections = self::get( 'sections', array() );
		if ( 'waitlist_only' === self::get( 'site_mode' ) && ! in_array( $section, array( 'waitlist', 'contact' ), true ) ) {
			return false;
		}
		return ! isset( $sections[ $section ] ) || ! empty( $sections[ $section ] );
	}

	public static function disclosure_hash(): string {
		return hash( 'sha256', self::get( 'disclosure' ) . "\n" . self::get( 'eu_notice' ) );
	}

	/**
	 * Server-side guard: gated modules need an authorization reference; live mode needs a constant.
	 */
	public static function guard( $new, $old ) {
		$new = is_array( $new ) ? $new : array();
		$old = is_array( $old ) ? $old : array();

		foreach ( array_keys( self::GATED_MODULES ) as $module ) {
			$was = ! empty( $old['modules'][ $module ] );
			$now = ! empty( $new['modules'][ $module ] );
			if ( $now && ! $was ) {
				$ref = trim( (string) ( $new['authorizations'][ $module ]['ref'] ?? '' ) );
				if ( '' === $ref || ! current_user_can( 'rc_authorize_modules' ) ) {
					$new['modules'][ $module ] = false;
					add_settings_error( self::OPTION, 'rc_gate_' . $module, sprintf( '“%s” was not activated: a written authorization reference and the rc_authorize_modules capability are required.', self::GATED_MODULES[ $module ] ) );
				} else {
					$new['authorizations'][ $module ] = array( 'ref' => $ref, 'by' => get_current_user_id(), 'at' => gmdate( 'c' ) );
				}
			}
		}

		if ( 'live' === ( $new['site_mode'] ?? '' ) && ! ( defined( 'RC_ALLOW_LIVE_MODE' ) && RC_ALLOW_LIVE_MODE ) ) {
			$new['site_mode'] = $old['site_mode'] ?? 'prelaunch';
			add_settings_error( self::OPTION, 'rc_live', 'Live mode is locked. It requires RC_ALLOW_LIVE_MODE in wp-config.php following written authorization.' );
		}

		// The mandatory disclosure can be extended but never emptied.
		if ( empty( trim( (string) ( $new['disclosure'] ?? '' ) ) ) ) {
			$new['disclosure'] = self::DISCLOSURE;
		}
		if ( empty( trim( (string) ( $new['eu_notice'] ?? '' ) ) ) ) {
			$new['eu_notice'] = self::EU_NOTICE;
		}

		self::$cache = null;
		return $new;
	}

	private static function merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! array_is_list( $v ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}
}
