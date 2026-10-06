<?php
/**
 * Platform hardening: rate limiting, login lockout, security headers, upload policy,
 * user-enumeration protection, XML-RPC off, maintenance mode.
 *
 * Infrastructure-level controls (WAF, TLS, backups, monitoring) are documented in docs/SECURITY.md.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Security {

	public static function init(): void {
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'wp_headers', array( __CLASS__, 'strip_pingback' ) );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'upload_mimes', array( __CLASS__, 'upload_mimes' ) );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'upload_check' ) );
		add_filter( 'rest_endpoints', array( __CLASS__, 'hide_user_endpoints' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_author_enum' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maintenance' ), 2 );
		add_filter( 'authenticate', array( __CLASS__, 'login_lockout' ), 30, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
		add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'session_length' ), 10, 3 );
	}

	public static function client_key(): string {
		return Audit_Log::ip_hash();
	}

	/**
	 * Fixed-window rate limiter. Returns false when the limit is exceeded.
	 */
	public static function rate_limit( string $bucket, int $max, int $window, ?string $key = null ): bool {
		$k     = 'rc_rl_' . md5( $bucket . '|' . ( $key ?? self::client_key() ) );
		$state = get_transient( $k );
		if ( ! is_array( $state ) || $state['reset'] < time() ) {
			$state = array( 'n' => 0, 'reset' => time() + $window );
		}
		++$state['n'];
		set_transient( $k, $state, max( 1, $state['reset'] - time() ) );
		return $state['n'] <= $max;
	}

	public static function headers(): void {
		if ( is_admin() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );
		}
		$csp = apply_filters(
			'rc_csp',
			"default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'"
		);
		header( 'Content-Security-Policy: ' . $csp );
	}

	public static function strip_pingback( array $headers ): array {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/** Evidence documents: PDF, images, office documents, CSV. SVG/HTML/executables are never accepted. */
	public static function upload_mimes( array $mimes ): array {
		return array(
			'pdf'          => 'application/pdf',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'csv'          => 'text/csv',
			'txt'          => 'text/plain',
			'json'         => 'application/json',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'mp4'          => 'video/mp4',
		);
	}

	public static function upload_check( array $file ): array {
		$max = (int) apply_filters( 'rc_max_upload_bytes', 50 * MB_IN_BYTES );
		if ( $file['size'] > $max ) {
			$file['error'] = 'File exceeds the maximum permitted size.';
			return $file;
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $check['type'] ) ) {
			$file['error'] = 'This file type is not permitted.';
			return $file;
		}
		if ( 'application/pdf' === $check['type'] ) {
			$head = (string) file_get_contents( $file['tmp_name'], false, null, 0, 1024 ); // phpcs:ignore
			if ( 0 !== strpos( $head, '%PDF-' ) ) {
				$file['error'] = 'File content does not match a PDF document.';
			} elseif ( preg_match( '#/(JavaScript|JS|Launch|EmbeddedFile)\b#', (string) file_get_contents( $file['tmp_name'] ) ) ) { // phpcs:ignore
				$file['error'] = 'PDF contains active content (JavaScript / launch actions / embedded files) and was rejected.';
			}
		}
		// Hook for malware scanning (ClamAV / provider API) — see docs/SECURITY.md.
		return apply_filters( 'rc_scan_upload', $file );
	}

	public static function hide_user_endpoints( array $endpoints ): array {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	public static function block_author_enum(): void {
		if ( isset( $_GET['author'] ) && ! is_admin() ) { // phpcs:ignore
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function maintenance(): void {
		if ( ! in_array( Settings::get( 'site_mode' ), array( 'maintenance', 'development' ), true ) || current_user_can( 'edit_posts' ) || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 3600' );
		$tpl = locate_template( array( 'maintenance.php' ) );
		if ( $tpl ) {
			include $tpl;
		} else {
			echo '<!doctype html><meta charset="utf-8"><title>ReserveChain — Maintenance</title><p style="font-family:sans-serif;padding:4rem">ReserveChain is undergoing scheduled maintenance.</p>';
		}
		exit;
	}

	public static function login_lockout( $user, $username ) {
		if ( empty( $username ) ) {
			return $user;
		}
		$k = 'rc_lock_' . md5( self::client_key() . '|' . strtolower( (string) $username ) );
		if ( (int) get_transient( $k ) >= 5 ) {
			return new \WP_Error( 'rc_locked', '<strong>Error:</strong> Too many failed attempts. Try again in 15 minutes.' );
		}
		return $user;
	}

	public static function login_failed( $username ): void {
		$k = 'rc_lock_' . md5( self::client_key() . '|' . strtolower( (string) $username ) );
		set_transient( $k, (int) get_transient( $k ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	public static function generic_login_error( $error ) {
		return false !== strpos( (string) $error, 'rc_' ) || false !== strpos( (string) $error, 'authentication code' ) || false !== strpos( (string) $error, 'Too many' ) ? $error : '<strong>Error:</strong> The credentials provided are incorrect.';
	}

	public static function session_length( int $length, int $user_id, bool $remember ): int {
		$user = get_userdata( $user_id );
		return $user && Auth::is_staff( $user ) ? ( $remember ? DAY_IN_SECONDS : 8 * HOUR_IN_SECONDS ) : $length;
	}
}
