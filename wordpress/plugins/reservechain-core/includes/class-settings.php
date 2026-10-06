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

	public const PROVISIONAL_NOTICE = 'Preliminary illustrative information only - subject to documentary verification, independent assessment and final approval. No asset or token is currently offered for sale through this website.';

	/**
	 * The ten website modes of the master instructions (+ maintenance). Modes flagged `locked` need BOTH an
	 * admin action (written authorization reference) AND a deployment action (wp-config constant) — they can
	 * never auto-activate.
	 */
	public const MODES = array(
		'development'           => array( 'Development', 'Staff preview; public sees holding page', false ),
		'prelaunch'             => array( 'Pre-Launch', 'Full informational site, waitlist open, no offering', false ),
		'waitlist'              => array( 'Waitlist', 'Homepage, disclosures, waitlist and legal pages only', false ),
		'documentation_release' => array( 'Documentation Release', 'Pre-Launch + whitepaper and document library emphasised', false ),
		'asset_verification'    => array( 'Asset Verification', 'Pre-Launch + registry and passports emphasised as evidence arrives', false ),
		'enterprise_onboarding' => array( 'Enterprise Onboarding', 'Pre-Launch + enterprise / asset-owner intake emphasised', false ),
		'eligibility'           => array( 'Eligibility', 'Account eligibility checks (KYC/KYB) open — locked', true ),
		'early_participation'   => array( 'Early Participation', 'Token-acquisition module visible — locked', true ),
		'live_offering'         => array( 'Live Offering', 'Offering live under definitive documentation — locked', true ),
		'redemption'            => array( 'Redemption', 'Physical redemption requests open — locked', true ),
		'maintenance'           => array( 'Maintenance', 'Holding page (HTTP 503) for visitors; staff unaffected', false ),
	);

	/** Built but inactive, hidden and non-indexable until written authorization (MASTER §17). */
	public const GATED_MODULES = array(
		'wallet'               => 'Wallet connection',
		'purchase'             => 'Token purchase / token-acquisition module',
		'usdt_payments'        => 'USDT (ERC-20) payments',
		'kyc_kyb'              => 'KYC / KYB, AML and sanctions screening',
		'eligibility'          => 'Jurisdictional eligibility decisions',
		'proof_of_reserves'    => 'Proof of Industrial Metal Reserves (live figures)',
		'reserve_dashboard'    => 'Reserve reconciliation dashboard',
		'holdings'             => 'Token holdings & transaction history',
		'client_documents'     => 'Client documents',
		'redemption'           => 'Physical-redemption requests',
		'unit_selection'       => 'Container / coil selection for redemption',
		'logistics'            => 'Logistics, customs & delivery workflow',
		'investor_portal'      => 'Investor portal',
		'enterprise_portal'    => 'Enterprise client portal',
		'asset_owner_portal'   => 'Asset-owner / originator portal',
		'tokenomics'           => 'Tokenomics publication',
		'contract_info'        => 'Smart-contract addresses & explorer links',
		'dex_info'             => 'DEX / exchange information',
		'team_profiles'        => 'Confirmed team profiles',
		'partners_directory'   => 'Confirmed partners, laboratories, custodians, insurers, advisers',
		'mobile_app_links'     => 'Public mobile-app store links',
		'waitlist_nationality' => 'Waitlist nationality & current-location fields (only when legally required)',
	);

	public const OPEN_MODULES = array(
		'waitlist'          => 'Waitlist registration',
		'registry_public'   => 'Public Industrial Metals Registry (approved records only)',
		'passports_public'  => 'Public Digital Asset Passports (approved records only)',
		'verify_tool'       => 'Document fingerprint verification tool',
		'app_registration'  => 'Mobile app account registration',
		'news'              => 'News & announcements',
	);

	/** Website sections (page paths) that can be hidden without deleting content. */
	public const SECTIONS = array(
		'platform'                                 => 'Platform overview',
		'platform/how-it-works'                    => 'How ReserveChain Works',
		'platform/infrastructure'                  => 'Platform Infrastructure',
		'platform/technology'                      => 'Technology',
		'platform/security'                        => 'Security',
		'platform/verification'                    => 'Independent Verification',
		'platform/custody'                         => 'Custody & Vault Structure',
		'platform/proof-of-reserves'               => 'Proof of Reserves',
		'platform/digital-asset-passports'         => 'Digital Asset Passports',
		'platform/asset-registry'                  => 'Industrial Metals Registry',
		'platform/tokenization'                    => 'Tokenization',
		'platform/redemption'                      => 'Physical Redemption',
		'assets'                                   => 'Explore Real-World Assets',
		'assets/industrial-metals/copper-powder'   => 'Copper Powder program',
		'assets/industrial-metals/nickel-wire'     => 'Nickel Wire program',
		'assets/future-categories'                 => 'Future Asset Categories',
		'participation'                            => 'Early Participation Program',
		'participation/discount-methodology'       => 'Discount Methodology',
		'participation/waitlist'                   => 'Waitlist',
		'enterprise'                               => 'Enterprise Services',
		'investors'                                => 'Investors',
		'resources'                                => 'Resources',
		'resources/whitepaper'                     => 'Whitepaper',
		'resources/investor-presentation'          => 'Investor Presentation',
		'company/news'                             => 'News & Announcements',
		'company/roadmap'                          => 'Roadmap',
		'portal'                                   => 'Participant Portal',
		'portal/redemption'                        => 'Redemption Portal',
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
			'provisional_notice'   => self::PROVISIONAL_NOTICE,
			'mode_authorizations'  => array(),
			'redemption_fee_schedule' => '',
			'redemption_min'       => '',
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
		if ( 'waitlist' === self::get( 'site_mode' ) && 0 !== strpos( $section, 'participation/waitlist' ) && 0 !== strpos( $section, 'legal' ) && 'company/contact' !== $section ) {
			return false;
		}
		return ! isset( $sections[ $section ] ) || ! empty( $sections[ $section ] );
	}

	public static function mode_label( ?string $mode = null ): string {
		$mode = $mode ?? (string) self::get( 'site_mode' );
		return self::MODES[ $mode ][0] ?? $mode;
	}

	/** Public-facing modes behave as the informational pre-launch site. */
	public static function is_public_info_mode(): bool {
		return in_array( self::get( 'site_mode' ), array( 'prelaunch', 'documentation_release', 'asset_verification', 'enterprise_onboarding' ), true );
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

		// Locked modes need an admin action (written authorization reference) AND a deployment action (constant).
		$mode = (string) ( $new['site_mode'] ?? 'prelaunch' );
		if ( ! isset( self::MODES[ $mode ] ) ) {
			$new['site_mode'] = $old['site_mode'] ?? 'prelaunch';
		} elseif ( self::MODES[ $mode ][2] && ( $old['site_mode'] ?? '' ) !== $mode ) {
			$const = 'RC_ALLOW_MODE_' . strtoupper( $mode );
			$ref   = trim( (string) ( $new['mode_authorizations'][ $mode ]['ref'] ?? '' ) );
			if ( ! ( defined( $const ) && constant( $const ) ) || '' === $ref || ! current_user_can( 'rc_authorize_modules' ) ) {
				$new['site_mode'] = $old['site_mode'] ?? 'prelaunch';
				add_settings_error( self::OPTION, 'rc_mode', sprintf( '“%s” mode is locked. It requires %s in wp-config.php (deployment action) AND a written authorization reference entered by an authorized administrator.', self::MODES[ $mode ][0], $const ) );
			} else {
				$new['mode_authorizations'][ $mode ] = array( 'ref' => $ref, 'by' => get_current_user_id(), 'at' => gmdate( 'c' ) );
			}
		}

		// The mandatory disclosure can be extended but never emptied.
		if ( empty( trim( (string) ( $new['disclosure'] ?? '' ) ) ) ) {
			$new['disclosure'] = self::DISCLOSURE;
		}
		if ( empty( trim( (string) ( $new['eu_notice'] ?? '' ) ) ) ) {
			$new['eu_notice'] = self::EU_NOTICE;
		}
		if ( empty( trim( (string) ( $new['provisional_notice'] ?? '' ) ) ) ) {
			$new['provisional_notice'] = self::PROVISIONAL_NOTICE;
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
