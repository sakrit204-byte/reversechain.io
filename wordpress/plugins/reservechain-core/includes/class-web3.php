<?php
/**
 * Web3 module: wallet linking (EIP-4361 / SIWE-style challenge + EIP-191 personal_sign) and USDT (ERC-20)
 * payment verification against a configured TESTNET RPC.
 *
 *  - Wallet linking is gated by module `wallet`. Signatures are verified server-side: the SIWE message is rebuilt
 *    from the stored challenge, hashed with EIP-191 (Keccak-256, pure PHP — {@see Keccak}) and the signer is
 *    recovered with pure-PHP secp256k1 ({@see Secp256k1}, BCMath). If BCMath is unavailable the module falls back
 *    to the ecrecover precompile (0x…01) via eth_call on the configured testnet RPC.
 *  - USDT payments are gated by modules `purchase` AND `usdt_payments` AND site mode live_offering /
 *    early_participation. Amounts are entered and approved by staff (four-eyes, fingerprint-bound) — there is no
 *    pricing logic anywhere. Verification: eth_getTransactionReceipt + eth_blockNumber, status 1, an ERC-20
 *    Transfer log from the configured USDT contract with from = linked wallet, to = treasury, value == amount,
 *    N confirmations. A cron job re-checks pending intents.
 *  - Mainnet never: every RPC call first checks eth_chainId against the testnet allow-list AND the platform
 *    network setting.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Web3 {

	public const TESTNET_CHAINS = array(
		11155111 => 'Ethereum Sepolia (testnet)',
		80002    => 'Polygon Amoy (testnet)',
		31337    => 'Local Hardhat (development)',
	);

	/** Protocol text of the signed message — kept in English so the signed bytes are stable. */
	public const STATEMENT = 'Link this wallet to your ReserveChain account. No transaction, no fees.';

	public const MAX_WALLETS   = 3;
	public const CHALLENGE_TTL = 600;
	public const CAP           = 'rc_manage_payments';
	public const CRON          = 'rc_web3_recheck';
	public const ECRECOVER     = '0x0000000000000000000000000000000000000001';

	/** keccak256("Transfer(address,address,uint256)") — asserted against {@see Keccak} in tests. */
	public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	public const STATUSES = array( 'created', 'awaiting_tx', 'confirming', 'confirmed', 'failed', 'expired' );

	private const OPEN_STATES = array( 'created', 'awaiting_tx', 'confirming' );

	private static ?array $chain_check = null;

	/* ================================================================ bootstrap */

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'rc_wallet', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_rc_web3_settings', array( __CLASS__, 'do_settings' ) );
		add_action( 'admin_post_rc_web3_calldata', array( __CLASS__, 'do_calldata' ) );
		add_action( 'admin_post_rc_payment_action', array( __CLASS__, 'do_payment_action' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_wallets' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_wallets' ), 20 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			program_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount_minor DECIMAL(38,0) NULL,
			amount_ref VARCHAR(191) NOT NULL DEFAULT '',
			amount_set_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			approved_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			approved_at DATETIME NULL,
			approved_block BIGINT UNSIGNED NOT NULL DEFAULT 0,
			fingerprint CHAR(64) NOT NULL DEFAULT '',
			chain_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wallet VARCHAR(42) NOT NULL DEFAULT '',
			treasury VARCHAR(42) NOT NULL DEFAULT '',
			token_contract VARCHAR(42) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			tx_hash VARCHAR(66) NULL,
			tx_block BIGINT UNSIGNED NOT NULL DEFAULT 0,
			confirmations INT UNSIGNED NOT NULL DEFAULT 0,
			required_confirmations INT UNSIGNED NOT NULL DEFAULT 6,
			evidence LONGTEXT NULL,
			failure_reason VARCHAR(191) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tx_hash (tx_hash),
			KEY user_id (user_id),
			KEY status (status)
			) $charset;"
		);
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
		add_option( 'rc_web3_rpc_url', '', '', false );
		add_option( 'rc_web3_treasury', '', '', false );
		add_option( 'rc_web3_usdt', '', '', false );
		add_option( 'rc_web3_registry', '', '', false );
		add_option( 'rc_web3_confirmations', 6, '', false );
		add_option( 'rc_web3_intent_ttl_hours', 72, '', false );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'rc_payment_intents';
	}

	public static function cron_schedules( array $s ): array {
		$s['rc_web3_5min'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (ReserveChain Web3)' );
		return $s;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'rc_web3_5min', self::CRON );
		}
	}

	/* ================================================================ gates */

	public static function wallet_on(): bool {
		return Settings::module_on( 'wallet' );
	}

	public static function payments_on(): bool {
		return Settings::module_on( 'purchase' ) && Settings::module_on( 'usdt_payments' ) && in_array( Settings::get( 'site_mode' ), array( 'live_offering', 'early_participation' ), true );
	}

	private static function wallet_gate(): ?\WP_Error {
		return self::wallet_on() ? null : new \WP_Error( 'rc_module_disabled', __( 'Wallet linking is not available — the wallet module is not activated (subject to written authorization and final approval).', 'reservechain' ), array( 'status' => 403, 'module' => 'wallet' ) );
	}

	private static function payments_gate(): ?\WP_Error {
		if ( self::payments_on() ) {
			return null;
		}
		return new \WP_Error( 'rc_module_disabled', __( 'USDT payments are not available — they require the purchase and USDT payment modules (written authorization) and a live offering or early participation site mode.', 'reservechain' ), array( 'status' => 403, 'modules' => array( 'purchase', 'usdt_payments' ) ) );
	}

	/** Logged-in user via WP session (cookie + X-WP-Nonce) or bearer token (apps). */
	public static function user_gate( \WP_REST_Request $r ) {
		if ( Auth::bearer_user() ) {
			return Rest::bearer_gate( $r );
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return new \WP_Error( 'rc_unauthorized', __( 'Authentication required.', 'reservechain' ), array( 'status' => 401 ) );
		}
		if ( ! Security::rate_limit( 'api_user', 300, MINUTE_IN_SECONDS, 'u' . $uid ) ) {
			return new \WP_Error( 'rc_rate_limited', __( 'Too many requests.', 'reservechain' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/** Configured chain id if it is an allowed testnet, else null. */
	public static function chain_id(): ?int {
		$net = (array) Settings::get( 'network', array() );
		$id  = (int) ( $net['chain_id'] ?? 0 );
		return isset( self::TESTNET_CHAINS[ $id ] ) ? $id : null;
	}

	/* ================================================================ crypto helpers */

	public static function is_address( string $a ): bool {
		return (bool) preg_match( '/^0x[0-9a-fA-F]{40}$/', $a );
	}

	/** EIP-55 checksum encoding. */
	public static function checksum( string $address ): string {
		$lower = strtolower( substr( $address, 2 ) );
		$hash  = Keccak::hash( $lower );
		$out   = '0x';
		for ( $i = 0; $i < 40; $i++ ) {
			$out .= hexdec( $hash[ $i ] ) >= 8 ? strtoupper( $lower[ $i ] ) : $lower[ $i ];
		}
		return $out;
	}

	/** Validate an address; mixed-case input must carry a valid EIP-55 checksum. Returns checksummed or null. */
	public static function normalize( string $address ): ?string {
		$address = trim( $address );
		if ( ! self::is_address( $address ) ) {
			return null;
		}
		$body = substr( $address, 2 );
		$cs   = self::checksum( $address );
		if ( strtolower( $body ) !== $body && strtoupper( $body ) !== $body && $cs !== $address ) {
			return null;
		}
		return $cs;
	}

	/** EIP-191 personal_sign digest (raw 32 bytes). */
	public static function eip191_hash( string $message ): string {
		return Keccak::hash( "\x19Ethereum Signed Message:\n" . strlen( $message ) . $message, true );
	}

	/** Build the EIP-4361 (SIWE) message. */
	public static function build_message( string $domain, string $address, string $uri, int $chain_id, string $nonce, string $issued_at, string $expires_at ): string {
		return $domain . " wants you to sign in with your Ethereum account:\n"
			. $address . "\n\n"
			. self::STATEMENT . "\n\n"
			. 'URI: ' . $uri . "\n"
			. "Version: 1\n"
			. 'Chain ID: ' . $chain_id . "\n"
			. 'Nonce: ' . $nonce . "\n"
			. 'Issued At: ' . $issued_at . "\n"
			. 'Expiration Time: ' . $expires_at;
	}

	public static function domain(): string {
		$parts = wp_parse_url( home_url() );
		return ( $parts['host'] ?? 'localhost' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Recover the signer address (checksummed) of an EIP-191 digest.
	 *
	 * @param string $digest Raw 32-byte digest.
	 * @param string $sig    0x-prefixed 65-byte signature (r||s||v).
	 * @param string $via    auto | php | rpc.
	 */
	public static function recover_address( string $digest, string $sig, string $via = 'auto' ): ?string {
		if ( ! preg_match( '/^0x[0-9a-fA-F]{130}$/', $sig ) || 32 !== strlen( $digest ) ) {
			return null;
		}
		$raw = hex2bin( substr( $sig, 2 ) );
		if ( 'php' === $via || ( 'auto' === $via && Secp256k1::available() ) ) {
			$pub = Secp256k1::recover( $digest, $raw );
			return $pub ? self::checksum( '0x' . substr( Keccak::hash( $pub ), 24 ) ) : null;
		}
		// Fallback: ecrecover precompile through eth_call on the configured testnet RPC.
		$v = ord( $raw[64] );
		$v = $v < 27 ? $v + 27 : $v;
		if ( 27 !== $v && 28 !== $v ) {
			return null;
		}
		$data = '0x' . bin2hex( $digest ) . str_pad( dechex( $v ), 64, '0', STR_PAD_LEFT ) . bin2hex( substr( $raw, 0, 64 ) );
		$res  = self::rpc( 'eth_call', array( array( 'to' => self::ECRECOVER, 'data' => $data ), 'latest' ) );
		if ( is_wp_error( $res ) || ! is_string( $res ) || ! preg_match( '/^0x[0-9a-f]{64}$/i', $res ) ) {
			return null;
		}
		$addr = '0x' . substr( $res, 26 );
		return '0x0000000000000000000000000000000000000000' === strtolower( $addr ) ? null : self::checksum( $addr );
	}

	public static function recovery_method(): string {
		return Secp256k1::available() ? 'php-bcmath' : 'rpc-ecrecover-precompile';
	}

	/** Hex quantity → decimal string (arbitrary precision). */
	public static function hex_to_dec( string $hex ): string {
		$hex = preg_replace( '/^0x/i', '', $hex );
		return '' === $hex ? '0' : Secp256k1::hexdec( $hex );
	}

	/* ================================================================ RPC (testnet only) */

	/** @return mixed|\WP_Error */
	public static function rpc( string $method, array $params = array(), bool $skip_chain_check = false ) {
		$url = (string) get_option( 'rc_web3_rpc_url', '' );
		if ( '' === $url ) {
			return new \WP_Error( 'rc_rpc_unconfigured', __( 'No testnet RPC endpoint is configured.', 'reservechain' ), array( 'status' => 503 ) );
		}
		if ( ! $skip_chain_check ) {
			$ok = self::check_chain();
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'rc_rpc_unreachable', 'RPC unreachable: ' . $res->get_error_message(), array( 'status' => 502 ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || isset( $body['error'] ) || ! array_key_exists( 'result', $body ) ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'invalid response';
			return new \WP_Error( 'rc_rpc_error', 'RPC error: ' . $msg, array( 'status' => 502 ) );
		}
		return $body['result'];
	}

	/** RPC must report an allow-listed testnet chain id equal to the platform network setting. */
	public static function check_chain( bool $fresh = false ) {
		if ( null !== self::$chain_check && ! $fresh ) {
			return self::$chain_check['ok'] ? true : self::$chain_check['err'];
		}
		$res      = self::rpc( 'eth_chainId', array(), true );
		$expected = self::chain_id();
		$err      = null;
		if ( is_wp_error( $res ) ) {
			$err = $res;
		} else {
			$got = (int) hexdec( preg_replace( '/^0x/i', '', (string) $res ) );
			if ( ! isset( self::TESTNET_CHAINS[ $got ] ) ) {
				$err = new \WP_Error( 'rc_rpc_not_testnet', sprintf( 'RPC reports chain id %d, which is not an allowed testnet. Mainnet is never used.', $got ), array( 'status' => 409 ) );
			} elseif ( $got !== $expected ) {
				$err = new \WP_Error( 'rc_rpc_chain_mismatch', sprintf( 'RPC chain id %d does not match the platform network setting (%s).', $got, null === $expected ? 'not a testnet' : (string) $expected ), array( 'status' => 409 ) );
			}
		}
		self::$chain_check = array( 'ok' => null === $err, 'err' => $err );
		return $err ?? true;
	}

	/* ================================================================ wallets */

	/** Linked wallets of a user (static helper for the Compliance page and other modules). */
	public static function wallets( int $uid ): array {
		$list = get_user_meta( $uid, 'rc_wallets', true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	public static function owner_of( string $address ): int {
		$ids = get_users( array( 'meta_key' => 'rc_wallet_addr', 'meta_value' => strtolower( $address ), 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore
		return $ids ? (int) $ids[0] : 0;
	}

	public static function has_wallet( int $uid, string $address ): bool {
		foreach ( self::wallets( $uid ) as $w ) {
			if ( strtolower( $w['address'] ) === strtolower( $address ) ) {
				return true;
			}
		}
		return false;
	}

	private static function wallet_payload( int $uid ): array {
		$net = (array) Settings::get( 'network', array() );
		return array(
			'max'     => self::MAX_WALLETS,
			'items'   => array_map(
				static fn( $w ) => array(
					'address'   => $w['address'],
					'chain_id'  => (int) $w['chain_id'],
					'linked_at' => $w['linked_at'],
					'explorer'  => ! empty( $net['explorer'] ) ? rtrim( (string) $net['explorer'], '/' ) . '/address/' . $w['address'] : null,
				),
				self::wallets( $uid )
			),
		);
	}

	/* ================================================================ REST */

	public static function routes(): void {
		$user = array( __CLASS__, 'user_gate' );
		$reg  = static function ( string $path, string $method, string $cb ) use ( $user ) {
			register_rest_route( Rest::NS, $path, array( 'methods' => $method, 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $user ) );
		};
		$reg( '/me/wallet/challenge', 'POST', 'rest_challenge' );
		$reg( '/me/wallet/verify', 'POST', 'rest_verify' );
		$reg( '/me/wallets', 'GET', 'rest_wallets' );
		$reg( '/me/wallets/(?P<address>0x[a-fA-F0-9]{40})', 'DELETE', 'rest_unlink' );
		$reg( '/me/payments', 'GET', 'rest_payments' );
		$reg( '/me/payments', 'POST', 'rest_payment_create' );
		$reg( '/me/payments/(?P<id>\d+)', 'GET', 'rest_payment' );
		$reg( '/me/payments/(?P<id>\d+)/tx', 'POST', 'rest_payment_tx' );
	}

	private static function param( \WP_REST_Request $r, string $key ): string {
		$p = $r->get_json_params() ?: $r->get_body_params();
		return trim( (string) ( $p[ $key ] ?? $r->get_param( $key ) ?? '' ) );
	}

	public static function rest_challenge( \WP_REST_Request $r ) {
		$err = self::wallet_gate();
		if ( $err ) {
			return $err;
		}
		$uid = get_current_user_id();
		if ( ! Security::rate_limit( 'wallet_challenge', 10, 10 * MINUTE_IN_SECONDS, 'u' . $uid ) ) {
			return new \WP_Error( 'rc_rate_limited', __( 'Too many requests.', 'reservechain' ), array( 'status' => 429 ) );
		}
		$address = self::normalize( self::param( $r, 'address' ) );
		if ( ! $address ) {
			return new \WP_Error( 'rc_invalid_address', __( 'Provide a valid Ethereum address.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$chain = self::chain_id();
		if ( null === $chain ) {
			return new \WP_Error( 'rc_not_testnet', __( 'The platform network is not an allowed testnet.', 'reservechain' ), array( 'status' => 409 ) );
		}
		$check = self::link_allowed( $uid, $address );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$nonce   = bin2hex( random_bytes( 12 ) );
		$now     = time();
		$issued  = gmdate( 'Y-m-d\TH:i:s\Z', $now );
		$expires = gmdate( 'Y-m-d\TH:i:s\Z', $now + self::CHALLENGE_TTL );
		$domain  = self::domain();
		$uri     = home_url( '/' );
		update_user_meta(
			$uid,
			'rc_wallet_challenge',
			array(
				'address'    => $address,
				'nonce_hash' => hash( 'sha256', $nonce ),
				'chain_id'   => $chain,
				'domain'     => $domain,
				'uri'        => $uri,
				'issued_at'  => $issued,
				'expires_at' => $expires,
				'exp'        => $now + self::CHALLENGE_TTL,
			)
		);
		Audit_Log::record( 'wallet.challenge', 'user', $uid, 'Wallet-link challenge issued', array( 'address' => $address, 'chain_id' => $chain ) );
		return array(
			'address'    => $address,
			'chain_id'   => $chain,
			'nonce'      => $nonce,
			'expires_at' => $expires,
			'message'    => self::build_message( $domain, $address, $uri, $chain, $nonce, $issued, $expires ),
		);
	}

	private static function link_allowed( int $uid, string $address ) {
		if ( self::has_wallet( $uid, $address ) ) {
			return new \WP_Error( 'rc_wallet_exists', __( 'This wallet is already linked to your account.', 'reservechain' ), array( 'status' => 409 ) );
		}
		if ( count( self::wallets( $uid ) ) >= self::MAX_WALLETS ) {
			/* translators: %d: maximum number of wallets */
			return new \WP_Error( 'rc_wallet_limit', sprintf( __( 'You can link at most %d wallets. Unlink one first.', 'reservechain' ), self::MAX_WALLETS ), array( 'status' => 409 ) );
		}
		$owner = self::owner_of( $address );
		if ( $owner && $owner !== $uid ) {
			return new \WP_Error( 'rc_wallet_unavailable', __( 'This wallet cannot be linked to your account.', 'reservechain' ), array( 'status' => 409 ) );
		}
		return true;
	}

	public static function rest_verify( \WP_REST_Request $r ) {
		$err = self::wallet_gate();
		if ( $err ) {
			return $err;
		}
		$uid = get_current_user_id();
		if ( ! Security::rate_limit( 'wallet_verify', 10, 10 * MINUTE_IN_SECONDS, 'u' . $uid ) ) {
			return new \WP_Error( 'rc_rate_limited', __( 'Too many requests.', 'reservechain' ), array( 'status' => 429 ) );
		}
		$ch = get_user_meta( $uid, 'rc_wallet_challenge', true );
		delete_user_meta( $uid, 'rc_wallet_challenge' ); // Single use, whatever the outcome.
		$address = self::normalize( self::param( $r, 'address' ) );
		$nonce   = self::param( $r, 'nonce' );
		$sig     = self::param( $r, 'signature' );
		$fail    = static function ( string $reason, string $msg ) use ( $uid, $address ) {
			Audit_Log::record( 'wallet.link_failed', 'user', $uid, 'Wallet link failed: ' . $reason, array( 'address' => $address, 'reason' => $reason ) );
			return new \WP_Error( 'rc_wallet_verify_failed', $msg, array( 'status' => 422, 'reason' => $reason ) );
		};
		if ( ! is_array( $ch ) || empty( $ch['nonce_hash'] ) ) {
			return $fail( 'no_challenge', __( 'No active challenge. Request a new one.', 'reservechain' ) );
		}
		if ( time() > (int) $ch['exp'] ) {
			return $fail( 'expired', __( 'The challenge expired. Request a new one.', 'reservechain' ) );
		}
		if ( ! $address || strtolower( $address ) !== strtolower( (string) $ch['address'] ) || ! hash_equals( (string) $ch['nonce_hash'], hash( 'sha256', $nonce ) ) ) {
			return $fail( 'mismatch', __( 'The signed challenge does not match. Request a new one.', 'reservechain' ) );
		}
		if ( self::chain_id() !== (int) $ch['chain_id'] ) {
			return $fail( 'chain_changed', __( 'The platform network changed. Request a new challenge.', 'reservechain' ) );
		}
		$message = self::build_message( (string) $ch['domain'], (string) $ch['address'], (string) $ch['uri'], (int) $ch['chain_id'], $nonce, (string) $ch['issued_at'], (string) $ch['expires_at'] );
		$client  = self::param( $r, 'message' );
		if ( '' !== $client && str_replace( "\r\n", "\n", $client ) !== $message ) {
			return $fail( 'message_mismatch', __( 'The signed challenge does not match. Request a new one.', 'reservechain' ) );
		}
		$recovered = self::recover_address( self::eip191_hash( $message ), $sig );
		if ( ! $recovered || strtolower( $recovered ) !== strtolower( $address ) ) {
			return $fail( 'bad_signature', __( 'The signature could not be verified for this wallet.', 'reservechain' ) );
		}
		$check = self::link_allowed( $uid, $address );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$list   = self::wallets( $uid );
		$list[] = array( 'address' => $address, 'chain_id' => (int) $ch['chain_id'], 'linked_at' => gmdate( 'c' ), 'method' => 'eip4361-personal_sign' );
		update_user_meta( $uid, 'rc_wallets', $list );
		add_user_meta( $uid, 'rc_wallet_addr', strtolower( $address ) );
		Audit_Log::record( 'wallet.linked', 'user', $uid, 'Wallet linked (signature verified, ' . self::recovery_method() . ')', array( 'address' => $address, 'chain_id' => (int) $ch['chain_id'] ) );
		/* translators: %s: wallet address */
		Notifications::push( $uid, __( 'Wallet linked', 'reservechain' ), sprintf( __( 'Wallet %s was linked to your ReserveChain account. If this was not you, contact support immediately.', 'reservechain' ), $address ), 'wallet' );
		return array_merge( array( 'ok' => true, 'linked' => $address ), self::wallet_payload( $uid ) );
	}

	public static function rest_wallets() {
		$err = self::wallet_gate();
		return $err ?? self::wallet_payload( get_current_user_id() );
	}

	public static function rest_unlink( \WP_REST_Request $r ) {
		$err = self::wallet_gate();
		if ( $err ) {
			return $err;
		}
		$uid     = get_current_user_id();
		$address = (string) $r['address'];
		if ( ! self::has_wallet( $uid, $address ) ) {
			return new \WP_Error( 'rc_not_found', __( 'Wallet not linked to your account.', 'reservechain' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$busy = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND LOWER(wallet) = %s AND status IN ('awaiting_tx','confirming')", $uid, strtolower( $address ) ) ); // phpcs:ignore
		if ( $busy ) {
			return new \WP_Error( 'rc_wallet_in_use', __( 'This wallet is used by a pending payment and cannot be unlinked yet.', 'reservechain' ), array( 'status' => 409 ) );
		}
		$list = array_values( array_filter( self::wallets( $uid ), static fn( $w ) => strtolower( $w['address'] ) !== strtolower( $address ) ) );
		update_user_meta( $uid, 'rc_wallets', $list );
		delete_user_meta( $uid, 'rc_wallet_addr', strtolower( $address ) );
		$cs = self::checksum( $address );
		Audit_Log::record( 'wallet.unlinked', 'user', $uid, 'Wallet unlinked', array( 'address' => $cs ) );
		/* translators: %s: wallet address */
		Notifications::push( $uid, __( 'Wallet unlinked', 'reservechain' ), sprintf( __( 'Wallet %s was removed from your ReserveChain account.', 'reservechain' ), $cs ), 'wallet' );
		return array_merge( array( 'ok' => true ), self::wallet_payload( $uid ) );
	}

	/* ---------------------------------------------------------------- payments */

	public static function intent( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	private static function update_intent( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	public static function intent_payload( array $i ): array {
		$net   = (array) Settings::get( 'network', array() );
		$ready = in_array( $i['status'], array( 'awaiting_tx', 'confirming', 'confirmed' ), true );
		return array(
			'id'                     => (int) $i['id'],
			'program_id'             => (int) $i['program_id'],
			'program'                => $i['program_id'] ? get_the_title( (int) $i['program_id'] ) : null,
			'status'                 => $i['status'],
			'amount_minor'           => null === $i['amount_minor'] ? null : (string) $i['amount_minor'],
			'amount_approved'        => $ready,
			'wallet'                 => $i['wallet'],
			'treasury'               => $ready ? $i['treasury'] : null,
			'token_contract'         => $ready ? $i['token_contract'] : null,
			'chain_id'               => (int) $i['chain_id'],
			'tx_hash'                => $i['tx_hash'],
			'tx_url'                 => $i['tx_hash'] && ! empty( $net['explorer'] ) ? rtrim( (string) $net['explorer'], '/' ) . '/tx/' . $i['tx_hash'] : null,
			'confirmations'          => (int) $i['confirmations'],
			'required_confirmations' => (int) $i['required_confirmations'],
			'failure_reason'         => $i['failure_reason'] ?: null,
			'expires_at'             => $i['expires_at'] ? $i['expires_at'] . 'Z' : null,
			'created_at'             => $i['created_at'] . 'Z',
			'updated_at'             => $i['updated_at'] . 'Z',
		);
	}

	private static function own_intent( \WP_REST_Request $r ) {
		$i = self::intent( (int) $r['id'] );
		if ( ! $i || (int) $i['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'rc_not_found', __( 'Payment intent not found.', 'reservechain' ), array( 'status' => 404 ) );
		}
		return $i;
	}

	public static function rest_payments() {
		$err = self::payments_gate();
		if ( $err ) {
			return $err;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 50', get_current_user_id() ), ARRAY_A ); // phpcs:ignore
		return array( 'items' => array_map( array( __CLASS__, 'intent_payload' ), $rows ), 'note' => __( 'Amounts are set and approved by ReserveChain staff. Never send funds before an intent shows “awaiting transfer”.', 'reservechain' ) );
	}

	public static function rest_payment( \WP_REST_Request $r ) {
		$err = self::payments_gate();
		if ( $err ) {
			return $err;
		}
		$i = self::own_intent( $r );
		return is_wp_error( $i ) ? $i : self::intent_payload( $i );
	}

	public static function rest_payment_create( \WP_REST_Request $r ) {
		$err = self::payments_gate();
		if ( $err ) {
			return $err;
		}
		$uid = get_current_user_id();
		if ( ! Security::rate_limit( 'payment_create', 5, HOUR_IN_SECONDS, 'u' . $uid ) ) {
			return new \WP_Error( 'rc_rate_limited', __( 'Too many requests.', 'reservechain' ), array( 'status' => 429 ) );
		}
		$elig = Compliance::eligibility( $uid );
		if ( 'eligible_subject_to_final_approval' !== $elig['overall'] ) {
			return new \WP_Error( 'rc_not_eligible', __( 'Your account has not completed the required eligibility checks.', 'reservechain' ), array( 'status' => 403, 'overall' => $elig['overall'] ) );
		}
		$wallets = self::wallets( $uid );
		if ( ! $wallets ) {
			return new \WP_Error( 'rc_no_wallet', __( 'Link a wallet before creating a payment intent.', 'reservechain' ), array( 'status' => 409 ) );
		}
		$wallet = self::param( $r, 'wallet' );
		$wallet = '' === $wallet ? $wallets[0]['address'] : ( self::normalize( $wallet ) ?? '' );
		if ( '' === $wallet || ! self::has_wallet( $uid, $wallet ) ) {
			return new \WP_Error( 'rc_invalid_wallet', __( 'Choose one of your linked wallets.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$program = (int) self::param( $r, 'program_id' );
		if ( ! $program || 'rc_token_program' !== get_post_type( $program ) ) {
			return new \WP_Error( 'rc_invalid_program', __( 'Choose a valid token program.', 'reservechain' ), array( 'status' => 422 ) );
		}
		$chain = self::chain_id();
		if ( null === $chain ) {
			return new \WP_Error( 'rc_not_testnet', __( 'The platform network is not an allowed testnet.', 'reservechain' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND status IN ('created','awaiting_tx','confirming')", $uid ) ); // phpcs:ignore
		if ( $open >= 3 ) {
			return new \WP_Error( 'rc_too_many_intents', __( 'You already have open payment intents.', 'reservechain' ), array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'user_id'                => $uid,
				'program_id'             => $program,
				'wallet'                 => self::checksum( $wallet ),
				'chain_id'               => $chain,
				'status'                 => 'created',
				'required_confirmations' => self::required_confirmations(),
				'created_at'             => $now,
				'updated_at'             => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		Audit_Log::record( 'payment.intent_created', 'payment_intent', $id, 'USDT payment intent requested (amount to be set by staff)', array( 'wallet' => self::checksum( $wallet ), 'program_id' => $program, 'chain_id' => $chain ) );
		Notifications::push( $uid, __( 'Payment intent received', 'reservechain' ), __( 'Your request was recorded. Do not send any funds until the amount has been approved and the intent shows “awaiting transfer”.', 'reservechain' ), 'payment' );
		return self::intent_payload( self::intent( $id ) );
	}

	public static function rest_payment_tx( \WP_REST_Request $r ) {
		$err = self::payments_gate();
		if ( $err ) {
			return $err;
		}
		$i = self::own_intent( $r );
		if ( is_wp_error( $i ) ) {
			return $i;
		}
		if ( 'awaiting_tx' !== $i['status'] ) {
			return new \WP_Error( 'rc_invalid_state', __( 'This intent is not awaiting a transfer.', 'reservechain' ), array( 'status' => 409 ) );
		}
		if ( $i['expires_at'] && strtotime( $i['expires_at'] . ' UTC' ) < time() ) {
			self::expire( $i );
			return new \WP_Error( 'rc_expired', __( 'This payment intent has expired.', 'reservechain' ), array( 'status' => 409 ) );
		}
		$hash = strtolower( self::param( $r, 'tx_hash' ) );
		if ( ! preg_match( '/^0x[0-9a-f]{64}$/', $hash ) ) {
			return new \WP_Error( 'rc_invalid_tx', __( 'Provide a valid transaction hash.', 'reservechain' ), array( 'status' => 422 ) );
		}
		if ( ! self::has_wallet( (int) $i['user_id'], $i['wallet'] ) ) {
			return new \WP_Error( 'rc_no_wallet', __( 'The wallet of this intent is no longer linked.', 'reservechain' ), array( 'status' => 409 ) );
		}
		global $wpdb;
		$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE tx_hash = %s', $hash ) ); // phpcs:ignore
		if ( $dup ) {
			Audit_Log::record( 'payment.tx_duplicate', 'payment_intent', (int) $i['id'], 'Transaction hash already used by another intent', array( 'tx_hash' => $hash ) );
			return new \WP_Error( 'rc_tx_used', __( 'This transaction was already submitted.', 'reservechain' ), array( 'status' => 409 ) );
		}
		self::update_intent( (int) $i['id'], array( 'tx_hash' => $hash, 'status' => 'confirming' ) );
		Audit_Log::record( 'payment.tx_submitted', 'payment_intent', (int) $i['id'], 'Transaction hash submitted for verification', array( 'tx_hash' => $hash, 'wallet' => $i['wallet'] ) );
		self::verify_intent( (int) $i['id'] );
		return self::intent_payload( self::intent( (int) $i['id'] ) );
	}

	public static function required_confirmations(): int {
		return max( 1, min( 100, (int) get_option( 'rc_web3_confirmations', 6 ) ) );
	}

	/** Binds the approval to exactly what was approved. */
	public static function fingerprint( array $i ): string {
		return hash(
			'sha256',
			implode(
				'|',
				array( 'rc-payment/v1', (int) $i['id'], (int) $i['user_id'], (int) $i['program_id'], (string) $i['amount_minor'], (string) $i['amount_ref'], (int) $i['amount_set_by'], (int) $i['chain_id'], strtolower( (string) $i['wallet'] ), strtolower( (string) $i['treasury'] ), strtolower( (string) $i['token_contract'] ), (int) $i['required_confirmations'] )
			)
		);
	}

	private static function expire( array $i ): void {
		self::update_intent( (int) $i['id'], array( 'status' => 'expired' ) );
		Audit_Log::record( 'payment.expired', 'payment_intent', (int) $i['id'], 'Payment intent expired without a transfer', array(), 0 );
		Notifications::push( (int) $i['user_id'], __( 'Payment intent expired', 'reservechain' ), __( 'Your payment intent expired. Do not send funds for it; contact support if needed.', 'reservechain' ), 'payment' );
	}

	private static function fail( array $i, string $reason, array $evidence ): void {
		self::update_intent( (int) $i['id'], array( 'status' => 'failed', 'failure_reason' => mb_substr( $reason, 0, 191 ), 'evidence' => wp_json_encode( $evidence ) ) );
		Audit_Log::record( 'payment.failed', 'payment_intent', (int) $i['id'], 'USDT payment verification failed: ' . $reason, array( 'tx_hash' => $i['tx_hash'], 'wallet' => $i['wallet'] ), 0 );
		/* translators: %s: failure reason */
		Notifications::push( (int) $i['user_id'], __( 'Payment could not be verified', 'reservechain' ), sprintf( __( 'Verification of your transfer failed (%s). Contact support before sending anything else.', 'reservechain' ), $reason ), 'payment' );
	}

	/**
	 * Verify a confirming intent on-chain. Returns the resulting status.
	 */
	public static function verify_intent( int $id ): string {
		$i = self::intent( $id );
		if ( ! $i || 'confirming' !== $i['status'] || ! $i['tx_hash'] ) {
			return $i['status'] ?? 'missing';
		}
		$evidence = array( 'checked_at' => gmdate( 'c' ), 'tx_hash' => $i['tx_hash'], 'method' => 'eth_getTransactionReceipt+eth_blockNumber' );
		if ( ! hash_equals( (string) $i['fingerprint'], self::fingerprint( $i ) ) ) {
			self::fail( $i, 'approval fingerprint mismatch', $evidence );
			return 'failed';
		}
		$chain = self::check_chain();
		if ( is_wp_error( $chain ) || (int) $i['chain_id'] !== self::chain_id() ) {
			$evidence['pending'] = is_wp_error( $chain ) ? $chain->get_error_message() : 'network setting differs from intent chain';
			self::update_intent( $id, array( 'evidence' => wp_json_encode( $evidence ) ) );
			return 'confirming';
		}
		$evidence['chain_id'] = (int) $i['chain_id'];
		$receipt              = self::rpc( 'eth_getTransactionReceipt', array( $i['tx_hash'] ) );
		if ( is_wp_error( $receipt ) || ! is_array( $receipt ) ) {
			$evidence['pending'] = is_wp_error( $receipt ) ? $receipt->get_error_message() : 'receipt not yet available';
			$deadline            = $i['expires_at'] ? strtotime( $i['expires_at'] . ' UTC' ) + DAY_IN_SECONDS : 0;
			if ( ! is_wp_error( $receipt ) && $deadline && time() > $deadline ) {
				self::fail( $i, 'transaction not found on chain', $evidence );
				return 'failed';
			}
			self::update_intent( $id, array( 'evidence' => wp_json_encode( $evidence ) ) );
			return 'confirming';
		}
		$block                       = (int) hexdec( substr( (string) $receipt['blockNumber'], 2 ) );
		$evidence['receipt']         = array( 'status' => $receipt['status'] ?? null, 'blockNumber' => $block, 'blockHash' => $receipt['blockHash'] ?? null, 'to' => $receipt['to'] ?? null, 'from' => $receipt['from'] ?? null );
		if ( '0x1' !== strtolower( (string) ( $receipt['status'] ?? '' ) ) ) {
			self::fail( $i, 'transaction reverted (status != 1)', $evidence );
			return 'failed';
		}
		if ( $block < (int) $i['approved_block'] ) {
			self::fail( $i, 'transfer predates the approval', $evidence );
			return 'failed';
		}
		$match = null;
		$seen  = array();
		foreach ( (array) ( $receipt['logs'] ?? array() ) as $log ) {
			$topics = array_map( 'strtolower', (array) ( $log['topics'] ?? array() ) );
			if ( 3 !== count( $topics ) || self::TRANSFER_TOPIC !== $topics[0] ) {
				continue;
			}
			$t = array(
				'logIndex' => (int) hexdec( substr( (string) ( $log['logIndex'] ?? '0x0' ), 2 ) ),
				'address'  => strtolower( (string) $log['address'] ),
				'from'     => '0x' . substr( $topics[1], 26 ),
				'to'       => '0x' . substr( $topics[2], 26 ),
				'value'    => self::hex_to_dec( (string) ( $log['data'] ?? '0x0' ) ),
			);
			$seen[] = $t;
			if ( $t['address'] === strtolower( $i['token_contract'] ) && $t['from'] === strtolower( $i['wallet'] ) && $t['to'] === strtolower( $i['treasury'] ) && 0 === bccomp( $t['value'], (string) $i['amount_minor'], 0 ) ) {
				$match = $t;
				break;
			}
		}
		$evidence['transfer_logs'] = $seen;
		$evidence['expected']      = array( 'token' => strtolower( $i['token_contract'] ), 'from' => strtolower( $i['wallet'] ), 'to' => strtolower( $i['treasury'] ), 'value' => (string) $i['amount_minor'] );
		if ( ! $match ) {
			self::fail( $i, 'no matching USDT Transfer(from wallet → treasury, exact amount) in receipt', $evidence );
			return 'failed';
		}
		$head = self::rpc( 'eth_blockNumber' );
		if ( is_wp_error( $head ) ) {
			$evidence['pending'] = $head->get_error_message();
			self::update_intent( $id, array( 'evidence' => wp_json_encode( $evidence ) ) );
			return 'confirming';
		}
		$conf                     = max( 0, (int) hexdec( substr( (string) $head, 2 ) ) - $block + 1 );
		$evidence['matched_log']  = $match;
		$evidence['head_block']   = (int) hexdec( substr( (string) $head, 2 ) );
		$evidence['confirmations'] = $conf;
		$data                     = array( 'tx_block' => $block, 'confirmations' => $conf, 'evidence' => wp_json_encode( $evidence ) );
		if ( $conf >= (int) $i['required_confirmations'] ) {
			$data['status'] = 'confirmed';
			self::update_intent( $id, $data );
			Audit_Log::record( 'payment.confirmed', 'payment_intent', $id, sprintf( 'USDT transfer verified on-chain (%d confirmations)', $conf ), array( 'tx_hash' => $i['tx_hash'], 'block' => $block, 'log_index' => $match['logIndex'], 'amount_minor' => (string) $i['amount_minor'], 'wallet' => $i['wallet'], 'treasury' => $i['treasury'] ), 0 );
			Notifications::push( (int) $i['user_id'], __( 'Payment confirmed', 'reservechain' ), __( 'Your USDT transfer was verified on-chain. Any allocation remains subject to final approval and definitive documentation.', 'reservechain' ), 'payment' );
			return 'confirmed';
		}
		self::update_intent( $id, $data );
		return 'confirming';
	}

	public static function cron(): void {
		if ( ! self::payments_on() ) {
			return;
		}
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM $t WHERE status IN ('awaiting_tx','confirming') ORDER BY id ASC LIMIT 100", ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $i ) {
			if ( 'awaiting_tx' === $i['status'] ) {
				if ( $i['expires_at'] && strtotime( $i['expires_at'] . ' UTC' ) < time() ) {
					self::expire( $i );
				}
				continue;
			}
			self::verify_intent( (int) $i['id'] );
		}
	}

	/* ================================================================ compliance registry calldata */

	private static function word( string $hex ): string {
		return str_pad( strtolower( $hex ), 64, '0', STR_PAD_LEFT );
	}

	private static function selector( string $sig ): string {
		return substr( Keccak::hash( $sig ), 0, 8 );
	}

	/**
	 * Prepare UNSIGNED ComplianceRegistry calldata to mirror approved users' linked wallets on-chain.
	 * Status enum: Approved = 2. Jurisdiction: ISO alpha-2 as bytes2. Nothing is signed or sent server-side.
	 *
	 * @param int[]|null $user_ids Restrict to these users (null = all users with linked wallets).
	 * @param int        $expiry   Approval expiry (unix seconds, 0 = none).
	 */
	public static function compliance_calldata( ?array $user_ids = null, int $expiry = 0 ): array {
		$args = array( 'meta_key' => 'rc_wallet_addr', 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 1000 ); // phpcs:ignore
		if ( null !== $user_ids ) {
			$args['include'] = array_map( 'intval', $user_ids ?: array( 0 ) );
		}
		$records = array();
		foreach ( get_users( $args ) as $uid ) {
			$e = Compliance::eligibility( (int) $uid );
			if ( 'eligible_subject_to_final_approval' !== $e['overall'] || ! preg_match( '/^[A-Z]{2}$/', (string) $e['country'] ) ) {
				continue;
			}
			foreach ( self::wallets( (int) $uid ) as $w ) {
				$records[] = array( 'user_id' => (int) $uid, 'account' => $w['address'], 'status' => 2, 'status_label' => 'Approved', 'jurisdiction' => $e['country'], 'jurisdiction_bytes2' => '0x' . bin2hex( $e['country'] ), 'expiry' => $expiry );
			}
		}
		$sig_one   = 'setRecord(address,uint8,bytes2,uint64)';
		$sig_batch = 'setRecordsBatch(address[],uint8[],bytes2[],uint64[])';
		$calls     = array();
		$cols      = array( array(), array(), array(), array() );
		foreach ( $records as $rec ) {
			$a  = self::word( substr( $rec['account'], 2 ) );
			$s  = self::word( dechex( $rec['status'] ) );
			$j  = str_pad( bin2hex( $rec['jurisdiction'] ), 64, '0', STR_PAD_RIGHT );
			$x  = self::word( dechex( $rec['expiry'] ) );
			$calls[]   = array( 'account' => $rec['account'], 'data' => '0x' . self::selector( $sig_one ) . $a . $s . $j . $x );
			$cols[0][] = $a;
			$cols[1][] = $s;
			$cols[2][] = $j;
			$cols[3][] = $x;
		}
		$n      = count( $records );
		$head   = '';
		$tail   = '';
		$offset = 4 * 32;
		foreach ( $cols as $col ) {
			$head   .= self::word( dechex( $offset ) );
			$tail   .= self::word( dechex( $n ) ) . implode( '', $col );
			$offset += 32 * ( 1 + $n );
		}
		$registry = (string) get_option( 'rc_web3_registry', '' );
		return array(
			'generated_at' => gmdate( 'c' ),
			'chain_id'     => self::chain_id(),
			'to'           => $registry ?: null,
			'records'      => $records,
			'batch'        => $n ? array( 'function' => $sig_batch, 'selector' => '0x' . self::selector( $sig_batch ), 'data' => '0x' . self::selector( $sig_batch ) . $head . $tail ) : null,
			'calls'        => array( 'function' => $sig_one, 'selector' => '0x' . self::selector( $sig_one ), 'items' => $calls ),
			'note'         => 'UNSIGNED calldata. Review, then submit from the KYC_OPERATOR_ROLE multisig on the configured TESTNET. Off-chain eligibility does not confer any right to participate in any future offering.',
		);
	}

	/* ================================================================ shortcode + assets */

	public static function i18n(): array {
		return array(
			'connect'        => __( 'Connect wallet & link', 'reservechain' ),
			'noProvider'     => __( 'No browser wallet detected. Install or enable an EIP-1193 wallet (e.g. MetaMask) and reload.', 'reservechain' ),
			'wrongChain'     => __( 'Your wallet is on a different network. Switch to the network shown above and try again.', 'reservechain' ),
			'signing'        => __( 'Check your wallet and sign the message. No transaction, no fees.', 'reservechain' ),
			'linked'         => __( 'Wallet linked.', 'reservechain' ),
			'unlink'         => __( 'Unlink', 'reservechain' ),
			'unlinkConfirm'  => __( 'Unlink this wallet from your account?', 'reservechain' ),
			'none'           => __( 'No wallet linked yet.', 'reservechain' ),
			'error'          => __( 'Something went wrong. Please try again.', 'reservechain' ),
			'rejected'       => __( 'Request rejected in the wallet.', 'reservechain' ),
			'payments'       => __( 'USDT payment intents', 'reservechain' ),
			'requestIntent'  => __( 'Request payment intent', 'reservechain' ),
			'sendUsdt'       => __( 'Send USDT from linked wallet', 'reservechain' ),
			'submitTx'       => __( 'Submit transaction hash', 'reservechain' ),
			'txPlaceholder'  => __( 'Transaction hash (0x…)', 'reservechain' ),
			'awaitingAmount' => __( 'Awaiting staff approval of the amount. Do not send funds yet.', 'reservechain' ),
			'amount'         => __( 'Amount (USDT)', 'reservechain' ),
			'confirmations'  => __( 'Confirmations', 'reservechain' ),
			'refresh'        => __( 'Refresh', 'reservechain' ),
			'status'         => array(
				'created'     => __( 'Requested', 'reservechain' ),
				'awaiting_tx' => __( 'Awaiting transfer', 'reservechain' ),
				'confirming'  => __( 'Confirming', 'reservechain' ),
				'confirmed'   => __( 'Confirmed', 'reservechain' ),
				'failed'      => __( 'Failed', 'reservechain' ),
				'expired'     => __( 'Expired', 'reservechain' ),
			),
		);
	}

	public static function shortcode(): string {
		if ( ! self::wallet_on() ) {
			return Shortcodes::module( array( 'module' => 'wallet', 'title' => __( 'Wallet connection', 'reservechain' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return '<div class="rc-alert rc-alert--info">' . esc_html__( 'Sign in to link a wallet to your account.', 'reservechain' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ?: home_url( '/' ) ) ) . '">' . esc_html__( 'Sign in', 'reservechain' ) . '</a></div>';
		}
		$chain = self::chain_id();
		if ( null === $chain ) {
			return '<div class="rc-alert rc-alert--warn">' . esc_html__( 'Wallet linking is unavailable: the platform network is not an allowed testnet.', 'reservechain' ) . '</div>';
		}
		$programs = array();
		if ( self::payments_on() ) {
			foreach ( get_posts( array( 'post_type' => 'rc_token_program', 'post_status' => 'publish', 'posts_per_page' => 50 ) ) as $p ) {
				$programs[] = array( 'id' => $p->ID, 'title' => $p->post_title );
			}
		}
		wp_enqueue_script( 'rc-web3', RC_URL . 'assets/web3.js', array(), RC_VERSION, true );
		wp_localize_script(
			'rc-web3',
			'RC_WEB3',
			array(
				'api'       => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'chainId'   => $chain,
				'chainName' => self::TESTNET_CHAINS[ $chain ],
				'payments'  => self::payments_on(),
				'decimals'  => 6,
				'programs'  => $programs,
				'i18n'      => self::i18n(),
			)
		);
		ob_start();
		?>
		<div class="rc-web3 rc-form" data-rc-web3>
			<p><?php echo esc_html__( 'Link a self-custody wallet by signing a message. No transaction, no fees. You can link up to three wallets.', 'reservechain' ); ?></p>
			<ul class="rc-kv">
				<li><span><?php echo esc_html__( 'Network', 'reservechain' ); ?></span><strong><?php echo esc_html( self::TESTNET_CHAINS[ $chain ] . ' · ' . $chain ); ?></strong></li>
			</ul>
			<div data-rc-web3-wallets aria-live="polite"></div>
			<p><button type="button" class="rc-btn rc-btn--primary" data-rc-web3-connect><?php echo esc_html__( 'Connect wallet & link', 'reservechain' ); ?></button></p>
			<div class="rc-alert" data-rc-web3-msg role="status" hidden></div>
			<?php if ( self::payments_on() ) : ?>
				<div data-rc-web3-payments></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ================================================================ admin */

	public static function menu(): void {
		add_submenu_page( 'reservechain', __( 'Payments (gated)', 'reservechain' ), __( 'Payments (gated)', 'reservechain' ), self::CAP, 'rc-payments', array( __CLASS__, 'page_payments' ) );
		add_submenu_page( 'reservechain', __( 'Web3 settings', 'reservechain' ), __( 'Web3 settings', 'reservechain' ), 'rc_manage_settings', 'rc-web3', array( __CLASS__, 'page_settings' ) );
	}

	private static function notice(): void {
		$msg = isset( $_GET['rc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['rc_msg'] ) ) : ''; // phpcs:ignore
		$ok  = ! empty( $_GET['rc_ok'] ); // phpcs:ignore
		if ( $msg ) {
			printf( '<div class="notice notice-%s"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $msg ) );
		}
	}

	private static function back( string $page, string $msg, bool $ok ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'rc_msg' => rawurlencode( $msg ), 'rc_ok' => $ok ? 1 : 0 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page_settings(): void {
		if ( ! current_user_can( 'rc_manage_settings' ) ) {
			wp_die( 'Forbidden' );
		}
		$chain = self::chain_id();
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">' . esc_html__( 'Web3 settings', 'reservechain' ) . ' <small>' . esc_html__( 'testnet only', 'reservechain' ) . '</small></h1>';
		self::notice();
		echo '<div class="rc-panel"><p>' . esc_html__( 'Platform network (Settings & modules):', 'reservechain' ) . ' <strong>' . esc_html( null === $chain ? 'not an allowed testnet' : self::TESTNET_CHAINS[ $chain ] . ' · ' . $chain ) . '</strong> · ' . esc_html__( 'Signature recovery:', 'reservechain' ) . ' <code>' . esc_html( self::recovery_method() ) . '</code> · ' . esc_html__( 'Modules:', 'reservechain' ) . ' wallet=' . ( self::wallet_on() ? 'on' : 'off' ) . ', payments=' . ( self::payments_on() ? 'open' : 'closed' ) . '</p>';
		if ( get_option( 'rc_web3_rpc_url' ) ) {
			$c = self::check_chain( true );
			echo '<p>' . esc_html__( 'RPC status:', 'reservechain' ) . ' ' . ( is_wp_error( $c ) ? '<span class="rc-pill rc-pill-restricted">' . esc_html( $c->get_error_message() ) . '</span>' : '<span class="rc-pill rc-pill-eligible">' . esc_html__( 'reachable, chain id matches', 'reservechain' ) . '</span>' ) . '</p>';
		}
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_web3_settings">';
		wp_nonce_field( 'rc_web3_settings' );
		$fields = array(
			'rc_web3_rpc_url'          => array( __( 'Testnet RPC URL', 'reservechain' ), 'url', __( 'JSON-RPC endpoint of an allowed testnet (Sepolia 11155111, Amoy 80002, local 31337). Its eth_chainId must match the platform network. Mainnet is refused.', 'reservechain' ) ),
			'rc_web3_usdt'             => array( __( 'USDT contract (testnet token)', 'reservechain' ), 'text', __( 'ERC-20 contract address on the testnet.', 'reservechain' ) ),
			'rc_web3_treasury'         => array( __( 'Treasury address', 'reservechain' ), 'text', __( 'Receiving address for verified transfers. Bound into each approval.', 'reservechain' ) ),
			'rc_web3_registry'         => array( __( 'ComplianceRegistry address', 'reservechain' ), 'text', __( 'Optional: target of the allow-list calldata export.', 'reservechain' ) ),
			'rc_web3_confirmations'    => array( __( 'Required confirmations', 'reservechain' ), 'number', '' ),
			'rc_web3_intent_ttl_hours' => array( __( 'Intent validity after approval (hours)', 'reservechain' ), 'number', '' ),
		);
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => [ $label, $type, $help ] ) {
			printf( '<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text code" id="%1$s" name="%1$s" value="%4$s">%5$s</td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( (string) get_option( $key, '' ) ), $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' );
		}
		echo '</table>';
		submit_button( __( 'Save Web3 settings', 'reservechain' ) );
		echo '</form>';
		echo '<div class="rc-panel"><h2>' . esc_html__( 'ComplianceRegistry allow-list sync', 'reservechain' ) . '</h2><p>' . esc_html__( 'Exports UNSIGNED calldata (setRecordsBatch / setRecord, status Approved) for users whose overall eligibility is “eligible subject to final approval”, covering each linked wallet. Nothing is signed or sent by the server.', 'reservechain' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_web3_calldata">';
		wp_nonce_field( 'rc_web3_calldata' );
		submit_button( __( 'Download calldata (JSON)', 'reservechain' ), 'secondary', 'submit', false );
		echo '</form></div></div>';
	}

	public static function do_settings(): void {
		if ( ! current_user_can( 'rc_manage_settings' ) || ! check_admin_referer( 'rc_web3_settings' ) ) {
			wp_die( 'Forbidden' );
		}
		$errors  = array();
		$changed = array();
		$rpc     = esc_url_raw( trim( (string) wp_unslash( $_POST['rc_web3_rpc_url'] ?? '' ) ), array( 'http', 'https' ) );
		if ( '' !== $rpc && 0 === strpos( $rpc, 'http://' ) && 'development' !== RC_ENV ) {
			$errors[] = 'Plain-HTTP RPC endpoints are only allowed in development.';
		} elseif ( $rpc !== get_option( 'rc_web3_rpc_url' ) ) {
			$old = (string) get_option( 'rc_web3_rpc_url' );
			update_option( 'rc_web3_rpc_url', $rpc, false );
			if ( '' !== $rpc ) {
				$c = self::check_chain( true );
				if ( is_wp_error( $c ) ) {
					update_option( 'rc_web3_rpc_url', $old, false );
					$errors[] = 'RPC refused: ' . $c->get_error_message();
				} else {
					$changed['rc_web3_rpc_url'] = wp_parse_url( $rpc, PHP_URL_HOST );
				}
			} else {
				$changed['rc_web3_rpc_url'] = '';
			}
		}
		foreach ( array( 'rc_web3_usdt', 'rc_web3_treasury', 'rc_web3_registry' ) as $key ) {
			$v = trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) );
			if ( '' !== $v ) {
				$n = self::normalize( $v );
				if ( ! $n || '0x0000000000000000000000000000000000000000' === strtolower( $n ) ) {
					$errors[] = $key . ': invalid address (EIP-55 checksum or all-lowercase hex required).';
					continue;
				}
				$v = $n;
			}
			if ( get_option( $key ) !== $v ) {
				update_option( $key, $v, false );
				$changed[ $key ] = $v;
			}
		}
		foreach ( array( 'rc_web3_confirmations' => array( 1, 100 ), 'rc_web3_intent_ttl_hours' => array( 1, 720 ) ) as $key => [ $min, $max ] ) {
			$v = max( $min, min( $max, (int) ( $_POST[ $key ] ?? 0 ) ) );
			if ( (int) get_option( $key ) !== $v ) {
				update_option( $key, $v, false );
				$changed[ $key ] = $v;
			}
		}
		if ( $changed ) {
			Audit_Log::record( 'web3.settings', 'settings', 0, 'Web3 settings changed: ' . implode( ', ', array_keys( $changed ) ), $changed );
		}
		self::back( 'rc-web3', $errors ? implode( ' ', $errors ) : __( 'Web3 settings saved.', 'reservechain' ), ! $errors );
	}

	public static function do_calldata(): void {
		if ( ! current_user_can( 'rc_manage_compliance' ) || ! check_admin_referer( 'rc_web3_calldata' ) ) {
			wp_die( 'Forbidden' );
		}
		$out = self::compliance_calldata();
		Audit_Log::record( 'web3.compliance_calldata_export', 'settings', 0, sprintf( 'ComplianceRegistry allow-list calldata exported (%d records)', count( $out['records'] ) ), array( 'accounts' => array_column( $out['records'], 'account' ), 'sha256' => hash( 'sha256', (string) wp_json_encode( $out ) ) ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rc-compliance-registry-calldata-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public static function page_payments(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Forbidden' );
		}
		global $wpdb;
		$filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore
		$where  = in_array( $filter, self::STATUSES, true ) ? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " $where ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore
		$net    = (array) Settings::get( 'network', array() );
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">' . esc_html__( 'Payments (gated)', 'reservechain' ) . ' <small>USDT · ERC-20 · ' . esc_html__( 'testnet only', 'reservechain' ) . '</small></h1>';
		self::notice();
		echo '<div class="rc-panel"><p>' . ( self::payments_on() ? '<span class="rc-pill rc-pill-eligible">' . esc_html__( 'Gate open', 'reservechain' ) . '</span>' : '<span class="rc-pill rc-pill-restricted">' . esc_html__( 'Gate closed', 'reservechain' ) . '</span>' ) . ' ' . esc_html__( 'Requires modules “purchase” AND “usdt_payments” and site mode Live Offering or Early Participation. Amounts are entered by one staff member and approved by another (four-eyes); the approval is bound to a fingerprint of user, wallet, program, amount, treasury, token contract and chain. No pricing logic exists in this module.', 'reservechain' ) . '</p>';
		echo '<p>' . esc_html__( 'Treasury:', 'reservechain' ) . ' <code>' . esc_html( get_option( 'rc_web3_treasury' ) ?: '—' ) . '</code> · USDT: <code>' . esc_html( get_option( 'rc_web3_usdt' ) ?: '—' ) . '</code> · ' . esc_html__( 'Confirmations:', 'reservechain' ) . ' ' . (int) self::required_confirmations() . ' · <a href="' . esc_url( admin_url( 'admin.php?page=rc-web3' ) ) . '">' . esc_html__( 'Web3 settings', 'reservechain' ) . '</a></p>';
		echo '<p>';
		foreach ( array_merge( array( '' ), self::STATUSES ) as $s ) {
			printf( '<a class="button%s" href="%s">%s</a> ', $s === $filter ? ' button-primary' : '', esc_url( add_query_arg( array( 'page' => 'rc-payments', 'status' => $s ), admin_url( 'admin.php' ) ) ), esc_html( $s ?: 'all' ) );
		}
		echo '</p></div>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'User', 'reservechain' ) . '</th><th>' . esc_html__( 'Program', 'reservechain' ) . '</th><th>' . esc_html__( 'Wallet', 'reservechain' ) . '</th><th>' . esc_html__( 'Amount (minor units)', 'reservechain' ) . '</th><th>' . esc_html__( 'Status', 'reservechain' ) . '</th><th>' . esc_html__( 'Verification evidence', 'reservechain' ) . '</th><th>' . esc_html__( 'Actions', 'reservechain' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No payment intents.', 'reservechain' ) . '</td></tr>';
		}
		foreach ( $rows as $i ) {
			$u   = get_userdata( (int) $i['user_id'] );
			$ev  = json_decode( (string) $i['evidence'], true );
			$tx  = $i['tx_hash'] ? ( ! empty( $net['explorer'] ) ? '<a href="' . esc_url( rtrim( (string) $net['explorer'], '/' ) . '/tx/' . $i['tx_hash'] ) . '" target="_blank" rel="noopener">' . esc_html( substr( $i['tx_hash'], 0, 14 ) ) . '…</a>' : '<code>' . esc_html( $i['tx_hash'] ) . '</code>' ) : '—';
			$amt = null === $i['amount_minor'] ? '—' : '<code>' . esc_html( (string) $i['amount_minor'] ) . '</code><br><small>' . esc_html( $i['amount_ref'] ) . ' · ' . esc_html__( 'set by', 'reservechain' ) . ' #' . (int) $i['amount_set_by'] . ( $i['approved_by'] ? ' · ' . esc_html__( 'approved by', 'reservechain' ) . ' #' . (int) $i['approved_by'] : '' ) . '</small>';
			echo '<tr><td>' . (int) $i['id'] . '<br><small>' . esc_html( substr( $i['created_at'], 0, 16 ) ) . '</small></td>';
			echo '<td>' . ( $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a><br><small>' . esc_html( str_replace( '_', ' ', Compliance::eligibility( $u->ID )['overall'] ) ) . '</small>' : '#' . (int) $i['user_id'] ) . '</td>';
			echo '<td>' . esc_html( $i['program_id'] ? get_the_title( (int) $i['program_id'] ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $i['wallet'] ) . '</code><br><small>chain ' . (int) $i['chain_id'] . '</small></td>';
			echo '<td>' . $amt . '</td>'; // phpcs:ignore -- escaped above.
			$pill = array( 'confirmed' => 'eligible', 'failed' => 'restricted', 'expired' => 'restricted' )[ $i['status'] ] ?? 'pending';
			echo '<td><span class="rc-pill rc-pill-' . esc_attr( $pill ) . '">' . esc_html( $i['status'] ) . '</span>' . ( $i['failure_reason'] ? '<br><small>' . esc_html( $i['failure_reason'] ) . '</small>' : '' ) . ( $i['expires_at'] ? '<br><small>' . esc_html__( 'expires', 'reservechain' ) . ' ' . esc_html( substr( $i['expires_at'], 0, 16 ) ) . 'Z</small>' : '' ) . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html__( 'tx', 'reservechain' ) . ' ' . $tx . '<br>' . (int) $i['confirmations'] . '/' . (int) $i['required_confirmations'] . ' ' . esc_html__( 'conf.', 'reservechain' ) . ( $i['tx_block'] ? ' · block ' . (int) $i['tx_block'] : '' ); // phpcs:ignore
			if ( $ev ) {
				echo '<details><summary>' . esc_html__( 'evidence', 'reservechain' ) . '</summary><pre style="white-space:pre-wrap;max-width:420px;font-size:11px">' . esc_html( (string) wp_json_encode( $ev, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			}
			echo '</td><td>';
			self::actions( $i );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function action_form( int $id, string $op, string $label, string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 6px">';
		echo '<input type="hidden" name="action" value="rc_payment_action"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		wp_nonce_field( 'rc_payment_' . $op . '_' . $id );
		echo $extra; // phpcs:ignore -- static markup built by caller.
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
	}

	private static function actions( array $i ): void {
		$id = (int) $i['id'];
		if ( 'created' === $i['status'] ) {
			self::action_form( $id, 'amount', __( 'Set amount', 'reservechain' ), '<input name="amount_minor" inputmode="numeric" pattern="[0-9]+" placeholder="' . esc_attr__( 'USDT minor units', 'reservechain' ) . '" required style="width:150px"> <input name="amount_ref" placeholder="' . esc_attr__( 'Basis / document reference', 'reservechain' ) . '" required style="width:170px"> ' );
			if ( null !== $i['amount_minor'] ) {
				self::action_form( $id, 'approve', __( 'Approve (second person)', 'reservechain' ) );
			}
		}
		if ( 'confirming' === $i['status'] ) {
			self::action_form( $id, 'recheck', __( 'Re-check now', 'reservechain' ) );
		}
		if ( in_array( $i['status'], array( 'created', 'awaiting_tx' ), true ) ) {
			self::action_form( $id, 'cancel', __( 'Cancel', 'reservechain' ) );
		}
	}

	public static function do_payment_action(): void {
		$id = (int) ( $_POST['id'] ?? 0 );
		$op = sanitize_key( $_POST['op'] ?? '' );
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'rc_payment_' . $op . '_' . $id ) ) {
			wp_die( 'Forbidden' );
		}
		$res = self::staff_action( $id, $op, array( 'amount_minor' => (string) wp_unslash( $_POST['amount_minor'] ?? '' ), 'amount_ref' => sanitize_text_field( wp_unslash( $_POST['amount_ref'] ?? '' ) ) ) );
		self::back( 'rc-payments', is_wp_error( $res ) ? $res->get_error_message() : (string) $res, ! is_wp_error( $res ) );
	}

	/**
	 * Staff operations on an intent (also used by tests / WP-CLI). The acting user is the current user.
	 *
	 * @return string|\WP_Error Success message or error.
	 */
	public static function staff_action( int $id, string $op, array $args = array() ) {
		$staff = get_current_user_id();
		$i     = self::intent( $id );
		if ( ! $i ) {
			return new \WP_Error( 'rc_not_found', 'Intent not found.' );
		}
		if ( ! current_user_can( self::CAP ) ) {
			return new \WP_Error( 'rc_forbidden', 'Missing capability.' );
		}
		if ( in_array( $op, array( 'amount', 'approve' ), true ) && ! self::payments_on() ) {
			return self::payments_gate();
		}
		if ( $staff === (int) $i['user_id'] ) {
			return new \WP_Error( 'rc_four_eyes', 'Staff cannot act on their own payment intent.' );
		}
		switch ( $op ) {
			case 'amount':
				$amount = trim( (string) ( $args['amount_minor'] ?? '' ) );
				$ref    = trim( (string) ( $args['amount_ref'] ?? '' ) );
				if ( 'created' !== $i['status'] || ! preg_match( '/^[1-9]\d{0,37}$/', $amount ) || '' === $ref ) {
					return new \WP_Error( 'rc_invalid', 'Enter a positive integer amount in USDT minor units and the basis / document reference.' );
				}
				self::update_intent( $id, array( 'amount_minor' => $amount, 'amount_ref' => mb_substr( $ref, 0, 191 ), 'amount_set_by' => $staff, 'approved_by' => 0, 'fingerprint' => '' ) );
				Audit_Log::record( 'payment.amount_set', 'payment_intent', $id, 'Payment amount entered by staff (awaiting second approval)', array( 'amount_minor' => $amount, 'ref' => $ref ) );
				return 'Amount recorded. A different staff member must approve it.';

			case 'approve':
				if ( 'created' !== $i['status'] || null === $i['amount_minor'] ) {
					return new \WP_Error( 'rc_invalid_state', 'Only intents with an entered amount can be approved.' );
				}
				if ( $staff === (int) $i['amount_set_by'] ) {
					return new \WP_Error( 'rc_four_eyes', 'Four-eyes rule: the approver must differ from the staff member who entered the amount.' );
				}
				if ( 'eligible_subject_to_final_approval' !== Compliance::eligibility( (int) $i['user_id'] )['overall'] || ! self::has_wallet( (int) $i['user_id'], $i['wallet'] ) ) {
					return new \WP_Error( 'rc_not_eligible', 'User is no longer eligible or the wallet is no longer linked.' );
				}
				$treasury = (string) get_option( 'rc_web3_treasury', '' );
				$usdt     = (string) get_option( 'rc_web3_usdt', '' );
				if ( ! self::is_address( $treasury ) || ! self::is_address( $usdt ) || self::chain_id() !== (int) $i['chain_id'] ) {
					return new \WP_Error( 'rc_unconfigured', 'Treasury, USDT contract and a matching testnet network must be configured first.' );
				}
				$head = self::rpc( 'eth_blockNumber' );
				if ( is_wp_error( $head ) ) {
					return $head;
				}
				$i['treasury']               = $treasury;
				$i['token_contract']         = $usdt;
				$i['approved_by']            = $staff;
				$i['required_confirmations'] = self::required_confirmations();
				$fp                          = self::fingerprint( $i );
				self::update_intent(
					$id,
					array(
						'treasury'               => $treasury,
						'token_contract'         => $usdt,
						'approved_by'            => $staff,
						'approved_at'            => current_time( 'mysql', true ),
						'approved_block'         => (int) hexdec( substr( (string) $head, 2 ) ),
						'required_confirmations' => $i['required_confirmations'],
						'fingerprint'            => $fp,
						'status'                 => 'awaiting_tx',
						'expires_at'             => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS * max( 1, (int) get_option( 'rc_web3_intent_ttl_hours', 72 ) ) ),
					)
				);
				Audit_Log::record( 'payment.approved', 'payment_intent', $id, 'Payment intent approved (four-eyes) — awaiting transfer', array( 'fingerprint' => $fp, 'amount_minor' => (string) $i['amount_minor'], 'treasury' => $treasury, 'token' => $usdt, 'wallet' => $i['wallet'], 'amount_set_by' => (int) $i['amount_set_by'] ) );
				Notifications::push( (int) $i['user_id'], __( 'Payment intent approved', 'reservechain' ), __( 'Your payment intent was approved. Send exactly the approved USDT amount from your linked wallet to the treasury address shown, then submit the transaction hash.', 'reservechain' ), 'payment' );
				return 'Intent approved; awaiting the user’s transfer.';

			case 'recheck':
				$status = self::verify_intent( $id );
				Audit_Log::record( 'payment.recheck', 'payment_intent', $id, 'Manual on-chain re-check → ' . $status );
				return 'Re-checked: ' . $status;

			case 'cancel':
				if ( ! in_array( $i['status'], array( 'created', 'awaiting_tx' ), true ) ) {
					return new \WP_Error( 'rc_invalid_state', 'Only intents without a submitted transfer can be cancelled.' );
				}
				self::update_intent( $id, array( 'status' => 'failed', 'failure_reason' => 'cancelled by staff' ) );
				Audit_Log::record( 'payment.cancelled', 'payment_intent', $id, 'Payment intent cancelled by staff' );
				Notifications::push( (int) $i['user_id'], __( 'Payment intent cancelled', 'reservechain' ), __( 'Your payment intent was cancelled. Do not send funds for it.', 'reservechain' ), 'payment' );
				return 'Intent cancelled.';
		}
		return new \WP_Error( 'rc_invalid', 'Unknown action.' );
	}

	/* ---------------------------------------------------------------- user profile */

	public static function profile_wallets( \WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID && ! current_user_can( 'rc_manage_compliance' ) ) {
			return;
		}
		$net = (array) Settings::get( 'network', array() );
		echo '<h2>' . esc_html__( 'Linked wallets', 'reservechain' ) . '</h2><table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Wallets', 'reservechain' ) . '</th><td>';
		$list = self::wallets( $user->ID );
		if ( ! $list ) {
			echo esc_html__( 'No wallet linked.', 'reservechain' );
		}
		foreach ( $list as $w ) {
			$url = ! empty( $net['explorer'] ) ? rtrim( (string) $net['explorer'], '/' ) . '/address/' . $w['address'] : '';
			echo '<code>' . esc_html( $w['address'] ) . '</code> <small>chain ' . (int) $w['chain_id'] . ' · ' . esc_html( substr( (string) $w['linked_at'], 0, 16 ) ) . '</small>' . ( $url ? ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'explorer', 'reservechain' ) . '</a>' : '' ) . '<br>';
		}
		echo '<p class="description">' . esc_html__( 'Ownership proven by an EIP-4361 signed message (no transaction). Users manage their wallets from the account page.', 'reservechain' ) . '</p></td></tr></table>';
	}
}
