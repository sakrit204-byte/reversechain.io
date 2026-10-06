<?php
/**
 * Authentication for the API / apps and MFA for everyone.
 *
 *  - TOTP (RFC 6238, SHA-1, 6 digits, 30 s) with replay protection and hashed recovery codes.
 *  - TOTP secrets encrypted at rest with AES-256-GCM (key derived from RC_TOKEN_SECRET).
 *  - Access tokens: JWT HS256, 1 h, revocable via a per-user token version.
 *  - Refresh tokens: opaque, 30 days, stored hashed, rotated on every use (reuse ⇒ all sessions revoked).
 *  - wp-admin: one-time code step on wp-login.php; optional enforcement of MFA for staff roles.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Auth {

	private const ACCESS_TTL  = HOUR_IN_SECONDS;
	private const REFRESH_TTL = 30 * DAY_IN_SECONDS;
	private const MFA_TTL     = 5 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( 'login_form', array( __CLASS__, 'login_field' ) );
		add_filter( 'authenticate', array( __CLASS__, 'check_login_otp' ), 99, 3 );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_mfa' ), 5 );
		add_action( 'admin_post_rc_mfa', array( __CLASS__, 'handle_profile_mfa' ) );
		add_action( 'admin_init', array( __CLASS__, 'enforce_staff_mfa' ) );
	}

	/* ------------------------------------------------------------ crypto */

	private static function secret_key(): string {
		$base = defined( 'RC_TOKEN_SECRET' ) ? RC_TOKEN_SECRET : wp_salt( 'secure_auth' );
		return hash( 'sha256', 'rc-key|' . $base, true );
	}

	public static function encrypt( string $plain ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $ct );
	}

	public static function decrypt( string $blob ): ?string {
		$raw = base64_decode( $blob, true );
		if ( ! $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}

	private static function b64url( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $s ): string {
		return (string) base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
	}

	public static function jwt( array $claims ): string {
		$head = self::b64url( wp_json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT' ) ) );
		$body = self::b64url( wp_json_encode( $claims ) );
		$sig  = self::b64url( hash_hmac( 'sha256', "$head.$body", self::secret_key(), true ) );
		return "$head.$body.$sig";
	}

	public static function verify_jwt( string $token, string $typ ): ?array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$expected = self::b64url( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::secret_key(), true ) );
		if ( ! hash_equals( $expected, $parts[2] ) ) {
			return null;
		}
		$claims = json_decode( self::b64url_decode( $parts[1] ), true );
		if ( ! is_array( $claims ) || ( $claims['typ'] ?? '' ) !== $typ || ( $claims['exp'] ?? 0 ) < time() ) {
			return null;
		}
		$uid = (int) ( $claims['sub'] ?? 0 );
		if ( ! $uid || ! get_userdata( $uid ) || (int) get_user_meta( $uid, 'rc_token_version', true ) !== (int) ( $claims['ver'] ?? -1 ) ) {
			return null;
		}
		return $claims;
	}

	/* ------------------------------------------------------------ TOTP */

	public static function base32_encode( string $bin ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';
		foreach ( str_split( strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', $b32 ) ) ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alphabet, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	public static function totp( string $secret_b32, int $step ): string {
		$key  = self::base32_decode( $secret_b32 );
		$hmac = hash_hmac( 'sha1', pack( 'J', $step ), $key, true );
		$off  = ord( $hmac[19] ) & 0x0f;
		$code = ( ( ord( $hmac[ $off ] ) & 0x7f ) << 24 | ( ord( $hmac[ $off + 1 ] ) & 0xff ) << 16 | ( ord( $hmac[ $off + 2 ] ) & 0xff ) << 8 | ( ord( $hmac[ $off + 3 ] ) & 0xff ) ) % 1000000;
		return str_pad( (string) $code, 6, '0', STR_PAD_LEFT );
	}

	/** Verify a TOTP or recovery code for a user, with ±1 step drift and replay protection. */
	public static function verify_code( int $user_id, string $code, ?string $secret = null ): bool {
		$code = preg_replace( '/\s+/', '', $code );
		if ( null === $secret ) {
			$blob   = get_user_meta( $user_id, 'rc_mfa_secret', true );
			$secret = $blob ? self::decrypt( $blob ) : null;
		}
		if ( ! $secret ) {
			return false;
		}
		if ( preg_match( '/^\d{6}$/', $code ) ) {
			$now  = (int) floor( time() / 30 );
			$last = (int) get_user_meta( $user_id, 'rc_mfa_last_step', true );
			for ( $i = -1; $i <= 1; $i++ ) {
				$step = $now + $i;
				if ( $step > $last && hash_equals( self::totp( $secret, $step ), $code ) ) {
					update_user_meta( $user_id, 'rc_mfa_last_step', $step );
					return true;
				}
			}
			return false;
		}
		// Recovery code (single use).
		$codes = (array) get_user_meta( $user_id, 'rc_mfa_recovery', true );
		$hash  = hash( 'sha256', strtoupper( $code ) );
		$idx   = array_search( $hash, $codes, true );
		if ( false !== $idx ) {
			unset( $codes[ $idx ] );
			update_user_meta( $user_id, 'rc_mfa_recovery', array_values( $codes ) );
			Audit_Log::record( 'auth.recovery_code_used', 'user', $user_id, 'MFA recovery code used', array( 'remaining' => count( $codes ) ), $user_id );
			return true;
		}
		return false;
	}

	public static function mfa_enabled( int $user_id ): bool {
		return (bool) get_user_meta( $user_id, 'rc_mfa_enabled', true );
	}

	public static function begin_mfa_setup( int $user_id ): array {
		$secret = self::base32_encode( random_bytes( 20 ) );
		update_user_meta( $user_id, 'rc_mfa_pending', self::encrypt( $secret ) );
		$user  = get_userdata( $user_id );
		$label = rawurlencode( 'ReserveChain:' . $user->user_email );
		return array(
			'secret'      => $secret,
			'otpauth_url' => "otpauth://totp/{$label}?secret={$secret}&issuer=ReserveChain&algorithm=SHA1&digits=6&period=30",
		);
	}

	/** @return string[]|null recovery codes on success */
	public static function complete_mfa_setup( int $user_id, string $code ): ?array {
		$blob   = get_user_meta( $user_id, 'rc_mfa_pending', true );
		$secret = $blob ? self::decrypt( $blob ) : null;
		if ( ! $secret || ! self::verify_code( $user_id, $code, $secret ) ) {
			return null;
		}
		update_user_meta( $user_id, 'rc_mfa_secret', $blob );
		update_user_meta( $user_id, 'rc_mfa_enabled', 1 );
		delete_user_meta( $user_id, 'rc_mfa_pending' );
		$plain = array();
		$hashes = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$c        = strtoupper( substr( bin2hex( random_bytes( 5 ) ), 0, 10 ) );
			$plain[]  = $c;
			$hashes[] = hash( 'sha256', $c );
		}
		update_user_meta( $user_id, 'rc_mfa_recovery', $hashes );
		Audit_Log::record( 'auth.mfa_enabled', 'user', $user_id, 'Multi-factor authentication enabled', array(), $user_id );
		return $plain;
	}

	public static function disable_mfa( int $user_id ): void {
		foreach ( array( 'rc_mfa_secret', 'rc_mfa_enabled', 'rc_mfa_recovery', 'rc_mfa_pending', 'rc_mfa_last_step' ) as $k ) {
			delete_user_meta( $user_id, $k );
		}
		self::revoke_all( $user_id );
		Audit_Log::record( 'auth.mfa_disabled', 'user', $user_id, 'Multi-factor authentication disabled' );
	}

	/* ------------------------------------------------------------ sessions */

	public static function issue_tokens( int $user_id, string $client = 'app' ): array {
		$ver = (int) get_user_meta( $user_id, 'rc_token_version', true );
		if ( ! $ver ) {
			$ver = 1;
			update_user_meta( $user_id, 'rc_token_version', $ver );
		}
		$now     = time();
		$access  = self::jwt( array( 'iss' => home_url(), 'sub' => $user_id, 'iat' => $now, 'exp' => $now + self::ACCESS_TTL, 'typ' => 'access', 'ver' => $ver, 'mfa' => self::mfa_enabled( $user_id ) ) );
		$refresh = wp_generate_password( 64, false );
		$tokens  = array_filter( (array) get_user_meta( $user_id, 'rc_refresh_tokens', true ), static fn( $t ) => is_array( $t ) && $t['exp'] > time() );
		$tokens[ hash( 'sha256', $refresh ) ] = array( 'exp' => $now + self::REFRESH_TTL, 'client' => sanitize_key( $client ), 'created' => $now );
		update_user_meta( $user_id, 'rc_refresh_tokens', array_slice( $tokens, -10, null, true ) );
		return array(
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
		);
	}

	public static function mfa_challenge( int $user_id ): string {
		return self::jwt( array( 'sub' => $user_id, 'iat' => time(), 'exp' => time() + self::MFA_TTL, 'typ' => 'mfa', 'ver' => (int) get_user_meta( $user_id, 'rc_token_version', true ) ) );
	}

	public static function refresh( string $refresh ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', $refresh );
		$uid  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'rc_refresh_tokens' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $hash ) . '%' ) );
		if ( ! $uid ) {
			return null;
		}
		$tokens = (array) get_user_meta( $uid, 'rc_refresh_tokens', true );
		if ( empty( $tokens[ $hash ] ) || $tokens[ $hash ]['exp'] < time() ) {
			return null;
		}
		$client = $tokens[ $hash ]['client'] ?? 'app';
		unset( $tokens[ $hash ] );
		update_user_meta( $uid, 'rc_refresh_tokens', $tokens );
		return self::issue_tokens( $uid, $client );
	}

	public static function revoke_all( int $user_id ): void {
		update_user_meta( $user_id, 'rc_token_version', (int) get_user_meta( $user_id, 'rc_token_version', true ) + 1 );
		delete_user_meta( $user_id, 'rc_refresh_tokens' );
	}

	public static function bearer_user(): int {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; // phpcs:ignore
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', (string) $header, $m ) ) {
			return 0;
		}
		$claims = self::verify_jwt( $m[1], 'access' );
		return $claims ? (int) $claims['sub'] : 0;
	}

	/* ------------------------------------------------------------ wp-admin MFA */

	public static function login_field(): void {
		echo '<p><label for="rc_otp">Authentication code <small>(if MFA is enabled)</small></label><input type="text" name="rc_otp" id="rc_otp" class="input" autocomplete="one-time-code" inputmode="numeric" size="20"></p>';
	}

	public static function check_login_otp( $user, $username, $password ) {
		if ( ! ( $user instanceof \WP_User ) || ! self::mfa_enabled( $user->ID ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return $user;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $user; // REST app-password auth; app logins use the rc/v1 MFA flow.
		}
		$code = sanitize_text_field( wp_unslash( $_POST['rc_otp'] ?? '' ) ); // phpcs:ignore
		if ( '' === $code || ! self::verify_code( $user->ID, $code ) ) {
			Audit_Log::record( 'auth.mfa_failed', 'user', $user->ID, 'Invalid or missing MFA code at sign-in', array(), 0 );
			return new \WP_Error( 'rc_mfa', '<strong>Error:</strong> A valid authentication code is required for this account.' );
		}
		return $user;
	}

	public static function is_staff( \WP_User $user ): bool {
		return (bool) array_intersect( (array) $user->roles, array( 'administrator', 'editor', 'rc_editor', 'rc_reviewer', 'rc_compliance_officer', 'rc_registry_manager', 'rc_auditor' ) );
	}

	public static function enforce_staff_mfa(): void {
		if ( wp_doing_ajax() || ! Settings::get( 'mfa_enforce_staff' ) ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user->ID || ! self::is_staff( $user ) || self::mfa_enabled( $user->ID ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'profile.php?rc_mfa_required=1#rc-mfa' ) );
		exit;
	}

	public static function profile_mfa( \WP_User $user ): void {
		echo '<h2 id="rc-mfa">Multi-factor authentication</h2>';
		if ( isset( $_GET['rc_mfa_required'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-warning inline"><p>MFA is mandatory for staff accounts. Set it up below to continue.</p></div>';
		}
		$codes = get_transient( 'rc_mfa_codes_' . $user->ID );
		if ( $codes ) {
			delete_transient( 'rc_mfa_codes_' . $user->ID );
			echo '<div class="notice notice-success inline"><p><strong>Save these single-use recovery codes now — they will not be shown again:</strong></p><p><code>' . esc_html( implode( '  ', $codes ) ) . '</code></p></div>';
		}
		echo '<table class="form-table" role="presentation"><tr><th>Status</th><td>';
		if ( self::mfa_enabled( $user->ID ) ) {
			$left = count( (array) get_user_meta( $user->ID, 'rc_mfa_recovery', true ) );
			echo '<span class="rc-pill rc-pill-verified">Enabled</span> <small>' . (int) $left . ' recovery codes remaining</small>';
			echo '<p><input type="text" name="rc_mfa_code" placeholder="Current code" autocomplete="one-time-code" form="rc-mfa-form"> <button class="button" form="rc-mfa-form" name="do" value="disable">Disable MFA</button></p>';
		} else {
			$setup = self::begin_mfa_setup( $user->ID );
			echo '<span class="rc-pill rc-pill-pending_verification">Not enabled</span>';
			echo '<p>Scan with an authenticator app (1Password, Authy, Google Authenticator, Microsoft Authenticator):</p>';
			echo '<div class="rc-qr" data-qr="' . esc_attr( $setup['otpauth_url'] ) . '"></div>';
			echo '<p><small>Or enter manually: <code>' . esc_html( trim( chunk_split( $setup['secret'], 4, ' ' ) ) ) . '</code></small></p>';
			echo '<p><input type="text" name="rc_mfa_code" placeholder="6-digit code" inputmode="numeric" autocomplete="one-time-code" form="rc-mfa-form"> <button class="button button-primary" form="rc-mfa-form" name="do" value="enable">Enable MFA</button></p>';
		}
		echo '</td></tr></table>';
		add_action(
			'admin_footer',
			static function () {
				echo '<form id="rc-mfa-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_mfa">';
				wp_nonce_field( 'rc_mfa' );
				echo '</form>';
			}
		);
	}

	public static function handle_profile_mfa(): void {
		check_admin_referer( 'rc_mfa' );
		$uid  = get_current_user_id();
		$code = sanitize_text_field( wp_unslash( $_POST['rc_mfa_code'] ?? '' ) );
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$msg  = 'rc_mfa_error=1';
		if ( 'enable' === $do ) {
			$codes = self::complete_mfa_setup( $uid, $code );
			if ( $codes ) {
				set_transient( 'rc_mfa_codes_' . $uid, $codes, 300 );
				$msg = 'rc_mfa_ok=1';
			}
		} elseif ( 'disable' === $do && self::verify_code( $uid, $code ) ) {
			self::disable_mfa( $uid );
			$msg = 'rc_mfa_off=1';
		}
		wp_safe_redirect( admin_url( 'profile.php?' . $msg . '#rc-mfa' ) );
		exit;
	}
}
