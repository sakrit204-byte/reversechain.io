<?php
/**
 * Curated REST API — namespace rc/v1. Shared by the website, iOS and Android apps.
 * Reference: docs/API.md (OpenAPI: docs/openapi.yaml).
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'rc/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Replace WordPress core's permissive CORS (it echoes any Origin with credentials) with an allow-list.
		add_action( 'rest_api_init', static function () {
			remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}, 15 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors' ), 10, 4 );
	}

	public static function cors( $served, $result, $request, $server ) {
		$origins = array_unique( array_filter( apply_filters( 'rc_cors_origins', array( home_url(), site_url() ) ) ) );
		$origins = array_map( static fn( $o ) => untrailingslashit( (string) wp_parse_url( $o, PHP_URL_SCHEME ) . '://' . wp_parse_url( $o, PHP_URL_HOST ) . ( wp_parse_url( $o, PHP_URL_PORT ) ? ':' . wp_parse_url( $o, PHP_URL_PORT ) : '' ) ), $origins );
		$origin  = get_http_origin();
		if ( $origin && in_array( untrailingslashit( $origin ), $origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
		}
		header( 'Vary: Origin', false );
		if ( 0 === strpos( $request->get_route(), '/' . self::NS ) ) {
			header( 'Cache-Control: no-store' );
		}
		return $served;
	}

	private static function route( string $path, string $method, callable $cb, $perm = '__return_true', array $args = array() ): void {
		register_rest_route( self::NS, $path, array( 'methods' => $method, 'callback' => $cb, 'permission_callback' => $perm, 'args' => $args ) );
	}

	public static function routes(): void {
		$public = array( __CLASS__, 'public_gate' );
		$bearer = array( __CLASS__, 'bearer_gate' );

		self::route( '/config', 'GET', array( __CLASS__, 'config' ), $public );
		self::route( '/programs', 'GET', array( __CLASS__, 'programs' ), $public );
		self::route( '/programs/(?P<slug>[a-z0-9\-]+)', 'GET', array( __CLASS__, 'program' ), $public );
		self::route( '/passports', 'GET', array( __CLASS__, 'passports' ), $public );
		self::route( '/passports/(?P<no>[A-Za-z0-9\-]+)', 'GET', array( __CLASS__, 'passport' ), $public );
		self::route( '/verify', 'GET', array( __CLASS__, 'verify' ), $public );
		self::route( '/documents', 'GET', array( __CLASS__, 'documents' ), $public );
		self::route( '/registry/stats', 'GET', array( __CLASS__, 'registry_stats' ), $public );
		self::route( '/audit/head', 'GET', array( __CLASS__, 'audit_head' ), $public );
		self::route( '/audit/anchor', 'POST', array( __CLASS__, 'audit_anchor' ), static fn() => current_user_can( 'rc_anchor_audit' ) );
		self::route( '/waitlist', 'POST', array( __CLASS__, 'waitlist' ), $public );
		self::route( '/contact', 'POST', array( __CLASS__, 'contact' ), $public );

		self::route( '/auth/register', 'POST', array( __CLASS__, 'register' ), $public );
		self::route( '/auth/login', 'POST', array( __CLASS__, 'login' ), $public );
		self::route( '/auth/mfa/verify', 'POST', array( __CLASS__, 'mfa_verify' ), $public );
		self::route( '/auth/mfa/setup', 'POST', array( __CLASS__, 'mfa_setup' ), $bearer );
		self::route( '/auth/mfa/enable', 'POST', array( __CLASS__, 'mfa_enable' ), $bearer );
		self::route( '/auth/refresh', 'POST', array( __CLASS__, 'refresh' ), $public );
		self::route( '/auth/logout', 'POST', array( __CLASS__, 'logout' ), $bearer );

		self::route( '/me', 'GET', array( __CLASS__, 'me' ), $bearer );
		self::route( '/me', 'PATCH', array( __CLASS__, 'me_update' ), $bearer );
		self::route( '/me/holdings', 'GET', array( __CLASS__, 'holdings' ), $bearer );
		self::route( '/me/transactions', 'GET', array( __CLASS__, 'transactions' ), $bearer );
		self::route( '/me/notifications', 'GET', array( __CLASS__, 'notifications' ), $bearer );
		self::route( '/me/notifications/(?P<id>\d+)/read', 'POST', array( __CLASS__, 'notification_read' ), $bearer );
		self::route( '/me/devices', 'POST', array( __CLASS__, 'device' ), $bearer );
		self::route( '/support', 'POST', array( __CLASS__, 'support' ), $bearer );
		self::route( '/me/delete', 'POST', array( __CLASS__, 'delete_account' ), $bearer );
	}

	/* ------------------------------------------------------------ gates */

	public static function public_gate( \WP_REST_Request $r ) {
		if ( 'maintenance' === Settings::get( 'site_mode' ) && ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rc_maintenance', 'Platform under maintenance.', array( 'status' => 503 ) );
		}
		$limit = 'GET' === $r->get_method() ? 120 : 20;
		if ( ! Security::rate_limit( 'api_' . $r->get_method(), $limit, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'rc_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		return true;
	}

	public static function bearer_gate( \WP_REST_Request $r ) {
		$uid = Auth::bearer_user();
		if ( ! $uid ) {
			return new \WP_Error( 'rc_unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}
		wp_set_current_user( $uid );
		if ( ! Security::rate_limit( 'api_user', 300, MINUTE_IN_SECONDS, 'u' . $uid ) ) {
			return new \WP_Error( 'rc_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		return true;
	}

	/* ------------------------------------------------------------ public */

	public static function config(): array {
		$modules = Settings::get( 'modules' );
		$net     = Settings::get( 'network' );
		return array(
			'site_mode'     => Settings::get( 'site_mode' ),
			'environment'   => RC_ENV,
			'modules'       => array_map( 'boolval', $modules ),
			'sections'      => array_map( 'boolval', Settings::get( 'sections' ) ),
			'languages'     => array( 'en', 'es', 'it' ),
			'disclosure'    => Settings::get( 'disclosure' ),
			'eu_notice'     => Settings::get( 'eu_notice' ),
			'disclosure_hash' => Settings::disclosure_hash(),
			'claim_statuses'=> Schema::claim_statuses(),
			'network'       => array(
				'chain_id'       => (int) $net['chain_id'],
				'name'           => $net['name'],
				'explorer'       => $net['explorer'],
				'token_address'  => $net['token_address'] ?: null,
				'anchor_address' => $net['anchor_address'] ?: null,
				'testnet_only'   => true,
			),
			'version'       => RC_VERSION,
		);
	}

	public static function program_payload( \WP_Post $p, bool $full = false ): array {
		$out = array(
			'id'            => $p->ID,
			'slug'          => $p->post_name,
			'record_no'     => get_post_meta( $p->ID, '_rc_record_no', true ),
			'symbol'        => get_post_meta( $p->ID, '_rc_symbol', true ),
			'atomic_number' => (int) get_post_meta( $p->ID, '_rc_atomic_number', true ) ?: null,
			'name'          => $p->post_title,
			'summary'       => wp_strip_all_tags( $p->post_excerpt ?: wp_trim_words( $p->post_content, 40 ) ),
			'status'        => get_post_meta( $p->ID, '_rc_verification_status', true ) ?: 'in_development',
			'claims'        => (array) get_post_meta( $p->ID, '_rc_claims', true ),
		);
		if ( $full ) {
			$out['description'] = wp_strip_all_tags( $p->post_content );
			$out['fields']      = array_values( array_map( static fn( $f ) => array_diff_key( $f, array( 'countable' => 1 ) ), Passport::public_fields( $p->ID ) ) );
			$tp                 = Registry::referencing( $p->ID, array( 'rc_token_program' ) );
			$out['token_program'] = $tp ? array(
				'record_no' => get_post_meta( $tp[0]->ID, '_rc_record_no', true ),
				'status'    => get_post_meta( $tp[0]->ID, '_rc_verification_status', true ) ?: 'proposed',
				'fields'    => array_values( array_map( static fn( $f ) => array_diff_key( $f, array( 'countable' => 1 ) ), Passport::public_fields( $tp[0]->ID ) ) ),
			) : null;
			$out['passports']   = Passport::list( array( 'program' => $p->post_name ) );
			$out['documents']   = array_values( array_filter( array_map( static fn( $d ) => Passport::document( $d->ID ), Registry::referencing( $p->ID, array( 'rc_document' ) ) ) ) );
		}
		return $out;
	}

	public static function programs(): array {
		$posts = get_posts( array( 'post_type' => 'rc_program', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
		return array_map( array( __CLASS__, 'program_payload' ), $posts );
	}

	public static function program( \WP_REST_Request $r ) {
		$p = get_page_by_path( sanitize_title( $r['slug'] ), OBJECT, 'rc_program' );
		if ( ! $p || 'publish' !== $p->post_status ) {
			return new \WP_Error( 'rc_not_found', 'Program not found.', array( 'status' => 404 ) );
		}
		return self::program_payload( $p, true );
	}

	public static function passports( \WP_REST_Request $r ) {
		if ( ! Settings::module_on( 'passports_public' ) ) {
			return new \WP_Error( 'rc_disabled', 'Passports are not public.', array( 'status' => 403 ) );
		}
		return Passport::list( array( 'program' => $r['program'], 'type' => $r['type'], 'page' => $r['page'] ) );
	}

	public static function passport( \WP_REST_Request $r ) {
		$post = Registry::find_by_record_no( (string) $r['no'] );
		if ( ! Settings::module_on( 'passports_public' ) || ! $post || ! Schema::is_passport_type( $post->post_type ) ) {
			return new \WP_Error( 'rc_not_found', 'Passport not found.', array( 'status' => 404 ) );
		}
		$p           = Passport::build( $post->ID );
		$p['qr_url'] = $p['url'];
		return $p;
	}

	public static function verify( \WP_REST_Request $r ) {
		$hash = strtolower( trim( (string) $r['hash'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return new \WP_Error( 'rc_bad_hash', 'Provide a SHA-256 hash (64 hex characters).', array( 'status' => 400 ) );
		}
		$docs = get_posts( array( 'post_type' => 'rc_document', 'post_status' => array( 'publish', 'rc_unpublished', 'rc_archived' ), 'meta_key' => '_rc_sha256', 'meta_value' => $hash, 'posts_per_page' => 1 ) ); // phpcs:ignore
		$res  = array( 'match' => false, 'hash' => $hash, 'checked_at' => gmdate( 'c' ) );
		if ( $docs ) {
			$doc            = $docs[0];
			$res['match']   = true;
			$res['state']   = 'publish' === $doc->post_status ? 'current' : ( 'rc_archived' === $doc->post_status ? 'archived' : 'withdrawn' );
			$res['document'] = Passport::document( $doc->ID, true );
			$res['document']['url'] = 'publish' === $doc->post_status ? $res['document']['url'] : null;
			$res['passports'] = array();
			foreach ( Registry::get( $doc->ID, 'subject' ) as $sid ) {
				if ( 'publish' === get_post_status( $sid ) && Schema::is_passport_type( get_post_type( $sid ) ) ) {
					$res['passports'][] = Passport::summary( get_post( $sid ) );
				}
			}
			foreach ( Registry::referencing( $doc->ID, array( 'rc_coa', 'rc_custody', 'rc_valuation', 'rc_insurance', 'rc_reserve_report' ) ) as $ev ) {
				foreach ( Registry::get( $ev->ID, 'subject' ) as $sid ) {
					if ( 'publish' === get_post_status( $sid ) && Schema::is_passport_type( get_post_type( $sid ) ) ) {
						$res['passports'][] = Passport::summary( get_post( $sid ) );
					}
				}
			}
			$res['passports'] = array_values( array_unique( $res['passports'], SORT_REGULAR ) );
		}
		Audit_Log::record( 'verify.lookup', 'document', $docs ? $docs[0]->ID : 0, $res['match'] ? 'Verification lookup: match' : 'Verification lookup: no match', array( 'hash' => $hash ), 0 );
		return $res;
	}

	public static function documents(): array {
		$docs = get_posts( array( 'post_type' => 'rc_document', 'post_status' => 'publish', 'posts_per_page' => 200, 'meta_query' => array( 'relation' => 'AND', array( 'key' => '_rc_public_library', 'value' => 'yes' ), array( 'relation' => 'OR', array( 'key' => '_rc_audience', 'compare' => 'NOT EXISTS' ), array( 'key' => '_rc_audience', 'value' => 'public' ) ) ), 'orderby' => 'date', 'order' => 'DESC' ) ); // phpcs:ignore
		return array_values( array_filter( array_map( static fn( $d ) => Passport::document( $d->ID ), $docs ) ) );
	}

	public static function registry_stats(): array {
		$out = array();
		foreach ( Schema::entities() as $type => $def ) {
			if ( isset( $def['public'] ) && ! $def['public'] ) {
				continue;
			}
			$c                  = wp_count_posts( $type );
			$out[ $type ] = array( 'label' => $def['label'], 'published' => (int) ( $c->publish ?? 0 ) );
		}
		global $wpdb;
		$verified = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_rc_verification_status' AND pm.meta_value = 'verified' AND p.post_status = 'publish'" );
		return array( 'entities' => $out, 'verified_records' => $verified, 'audit' => Audit_Log::head() );
	}

	public static function audit_head(): array {
		$head   = Audit_Log::head();
		$anchor = Audit_Log::latest_anchor();
		$last   = get_option( 'rc_audit_last_verify' );
		return array_merge(
			$head,
			array(
				'algorithm'     => 'sha256-chain/v1',
				'last_verified' => $last ? array( 'ok' => (bool) $last['ok'], 'checked' => (int) $last['checked'], 'at' => gmdate( 'c', (int) $last['at'] ) ) : null,
				'last_anchor'   => $anchor ? array( 'seq' => (int) $anchor['seq'], 'chain_head' => $anchor['chain_head'], 'network' => $anchor['network'], 'tx_hash' => $anchor['tx_hash'], 'at' => $anchor['created_at'] . 'Z' ) : null,
				'db_triggers'   => Install::triggers_active(),
			)
		);
	}

	public static function audit_anchor( \WP_REST_Request $r ) {
		$ok = Audit_Log::add_anchor( (int) $r['seq'], strtolower( (string) $r['chain_head'] ), (string) $r['network'], (string) $r['tx_hash'] );
		return $ok ? array( 'ok' => true ) : new \WP_Error( 'rc_anchor_mismatch', 'Chain head does not match the stored entry.', array( 'status' => 409 ) );
	}

	public static function waitlist( \WP_REST_Request $r ) {
		$params = $r->get_json_params() ?: $r->get_body_params();
		$source = 'app' === ( $params['source'] ?? '' ) ? 'app' : 'web';
		return Waitlist::submit( (array) $params, $source );
	}

	public static function contact( \WP_REST_Request $r ) {
		if ( ! Security::rate_limit( 'contact', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rc_rate', 'Too many messages. Please try later.', array( 'status' => 429 ) );
		}
		$p = $r->get_json_params() ?: $r->get_body_params();
		if ( ! empty( $p['website'] ) ) {
			return array( 'ok' => true );
		}
		$email = sanitize_email( $p['email'] ?? '' );
		$msg   = sanitize_textarea_field( $p['message'] ?? '' );
		if ( ! is_email( $email ) || mb_strlen( $msg ) < 10 || empty( $p['consent_privacy'] ) ) {
			return new \WP_Error( 'rc_invalid', 'Please provide a valid email, a message and accept the privacy notice.', array( 'status' => 422 ) );
		}
		return self::store_support( 0, 'web', sanitize_text_field( $p['name'] ?? '' ), $email, sanitize_text_field( $p['subject'] ?? 'Website enquiry' ), $msg, sanitize_key( $p['topic'] ?? 'general' ) );
	}

	private static function store_support( int $uid, string $channel, string $name, string $email, string $subject, string $message, string $topic = 'general' ): array {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'rc_support', array( 'created_at' => current_time( 'mysql', true ), 'user_id' => $uid, 'channel' => $channel, 'name' => $name, 'email' => $email, 'subject' => mb_substr( '[' . $topic . '] ' . $subject, 0, 191 ), 'message' => $message ) );
		$id = (int) $wpdb->insert_id;
		Audit_Log::record( 'support.created', 'support', $id, 'Support/contact request received via ' . $channel, array( 'topic' => $topic ), $uid );
		$to = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
		wp_mail( $to, '[ReserveChain] ' . $subject, $message . "\n\nFrom: " . $name . ' <' . $email . '>' );
		return array( 'ok' => true, 'ticket' => 'RC-SUP-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ) );
	}

	/* ------------------------------------------------------------ auth */

	public static function register( \WP_REST_Request $r ) {
		if ( ! Settings::module_on( 'app_registration' ) ) {
			return new \WP_Error( 'rc_closed', 'Registration is currently closed.', array( 'status' => 403 ) );
		}
		if ( ! Security::rate_limit( 'register', 5, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'rc_rate', 'Too many attempts.', array( 'status' => 429 ) );
		}
		$p        = $r->get_json_params();
		$email    = sanitize_email( $p['email'] ?? '' );
		$password = (string) ( $p['password'] ?? '' );
		$name     = sanitize_text_field( $p['name'] ?? '' );
		$country  = strtoupper( sanitize_text_field( $p['country'] ?? '' ) );
		$errors   = array();
		if ( ! is_email( $email ) ) {
			$errors['email'] = 'Invalid email address.';
		}
		if ( strlen( $password ) < 12 || ! preg_match( '/[A-Z]/', $password ) || ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/\d/', $password ) ) {
			$errors['password'] = 'Use at least 12 characters with upper- and lower-case letters and a number.';
		}
		if ( ! isset( Schema::countries()[ $country ] ) ) {
			$errors['country'] = 'Select your country of residence.';
		}
		if ( empty( $p['accept_disclosure'] ) || empty( $p['accept_terms'] ) ) {
			$errors['consent'] = 'You must acknowledge the disclosure and accept the terms.';
		}
		if ( $errors ) {
			return new \WP_Error( 'rc_invalid', 'Validation failed.', array( 'status' => 422, 'fields' => $errors ) );
		}
		if ( email_exists( $email ) ) {
			// Do not reveal account existence.
			return array( 'ok' => true, 'message' => 'If the address can be registered, you can now sign in.' );
		}
		$uid = wp_insert_user( array( 'user_login' => 'u_' . substr( hash( 'sha256', $email . microtime() ), 0, 12 ), 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'role' => 'subscriber' ) );
		if ( is_wp_error( $uid ) ) {
			return new \WP_Error( 'rc_failed', 'Registration failed.', array( 'status' => 500 ) );
		}
		update_user_meta( $uid, 'rc_country', $country );
		update_user_meta( $uid, 'rc_entity_type', 'institution' === ( $p['entity_type'] ?? '' ) ? 'institution' : 'individual' );
		update_user_meta( $uid, 'rc_disclosure_ack', array( 'hash' => Settings::disclosure_hash(), 'at' => gmdate( 'c' ) ) );
		Notifications::push( $uid, 'Welcome to ReserveChain', 'Your account has been created. ReserveChain is in development — no tokens are offered or sold. Enable multi-factor authentication to secure your account.', 'account' );
		Audit_Log::record( 'app.registered', 'user', $uid, 'App account registered', array( 'jurisdiction' => Compliance::jurisdiction( $country ) ), $uid );
		return array( 'ok' => true, 'message' => 'If the address can be registered, you can now sign in.' );
	}

	public static function login( \WP_REST_Request $r ) {
		$p     = $r->get_json_params();
		$email = sanitize_email( $p['email'] ?? '' );
		if ( ! Security::rate_limit( 'login', 10, 15 * MINUTE_IN_SECONDS ) || ! Security::rate_limit( 'login_acct', 5, 15 * MINUTE_IN_SECONDS, hash( 'sha256', strtolower( $email ) ) ) ) {
			return new \WP_Error( 'rc_rate', 'Too many attempts. Try again later.', array( 'status' => 429 ) );
		}
		$user = get_user_by( 'email', $email ) ?: get_user_by( 'login', sanitize_user( (string) ( $p['email'] ?? '' ) ) );
		if ( ! $user || ! wp_check_password( (string) ( $p['password'] ?? '' ), $user->user_pass, $user->ID ) ) {
			Audit_Log::record( 'auth.api_login_failed', 'user', 0, 'Failed app sign-in', array( 'email_hash' => hash( 'sha256', strtolower( $email ) ) ), 0 );
			return new \WP_Error( 'rc_invalid_credentials', 'Invalid email or password.', array( 'status' => 401 ) );
		}
		if ( Auth::mfa_enabled( $user->ID ) ) {
			return array( 'mfa_required' => true, 'mfa_token' => Auth::mfa_challenge( $user->ID ) );
		}
		Audit_Log::record( 'auth.api_login', 'user', $user->ID, 'App sign-in (no MFA)', array(), $user->ID );
		return array_merge( array( 'mfa_required' => false, 'mfa_recommended' => true ), Auth::issue_tokens( $user->ID, sanitize_key( $p['client'] ?? 'app' ) ) );
	}

	public static function mfa_verify( \WP_REST_Request $r ) {
		$p      = $r->get_json_params();
		$claims = Auth::verify_jwt( (string) ( $p['mfa_token'] ?? '' ), 'mfa' );
		if ( ! $claims ) {
			return new \WP_Error( 'rc_mfa_expired', 'The sign-in session expired. Please sign in again.', array( 'status' => 401 ) );
		}
		$uid = (int) $claims['sub'];
		if ( ! Security::rate_limit( 'mfa', 5, 5 * MINUTE_IN_SECONDS, 'u' . $uid ) || ! Auth::verify_code( $uid, (string) ( $p['code'] ?? '' ) ) ) {
			Audit_Log::record( 'auth.mfa_failed', 'user', $uid, 'Invalid MFA code (app)', array(), 0 );
			return new \WP_Error( 'rc_mfa_invalid', 'Invalid authentication code.', array( 'status' => 401 ) );
		}
		Audit_Log::record( 'auth.api_login', 'user', $uid, 'App sign-in with MFA', array(), $uid );
		return Auth::issue_tokens( $uid, sanitize_key( $p['client'] ?? 'app' ) );
	}

	public static function mfa_setup(): array {
		return Auth::begin_mfa_setup( get_current_user_id() );
	}

	public static function mfa_enable( \WP_REST_Request $r ) {
		$codes = Auth::complete_mfa_setup( get_current_user_id(), (string) $r->get_param( 'code' ) );
		return $codes ? array( 'ok' => true, 'recovery_codes' => $codes ) : new \WP_Error( 'rc_mfa_invalid', 'Invalid code.', array( 'status' => 422 ) );
	}

	public static function refresh( \WP_REST_Request $r ) {
		if ( ! Security::rate_limit( 'refresh', 30, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'rc_rate', 'Too many requests.', array( 'status' => 429 ) );
		}
		$tokens = Auth::refresh( (string) $r->get_param( 'refresh_token' ) );
		return $tokens ?: new \WP_Error( 'rc_refresh_invalid', 'Session expired. Please sign in again.', array( 'status' => 401 ) );
	}

	public static function logout(): array {
		Auth::revoke_all( get_current_user_id() );
		Audit_Log::record( 'auth.api_logout', 'user', get_current_user_id(), 'App sign-out (all sessions revoked)' );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------ account */

	public static function me(): array {
		$u = wp_get_current_user();
		return array(
			'id'          => $u->ID,
			'name'        => $u->display_name,
			'email'       => $u->user_email,
			'country'     => get_user_meta( $u->ID, 'rc_country', true ) ?: null,
			'entity_type' => get_user_meta( $u->ID, 'rc_entity_type', true ) ?: 'individual',
			'language'    => get_user_meta( $u->ID, 'rc_language', true ) ?: 'en',
			'mfa_enabled' => Auth::mfa_enabled( $u->ID ),
			'eligibility' => Compliance::eligibility( $u->ID ),
			'created_at'  => gmdate( 'c', strtotime( $u->user_registered ) ),
		);
	}

	public static function me_update( \WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$p   = $r->get_json_params();
		if ( isset( $p['name'] ) ) {
			wp_update_user( array( 'ID' => $uid, 'display_name' => sanitize_text_field( $p['name'] ) ) );
		}
		if ( isset( $p['language'] ) && in_array( $p['language'], array( 'en', 'es', 'it' ), true ) ) {
			update_user_meta( $uid, 'rc_language', $p['language'] );
		}
		Audit_Log::record( 'account.updated', 'user', $uid, 'Profile updated via app', array_keys( (array) $p ) );
		return self::me();
	}

	private static function gated( string $module ): array {
		return array(
			'enabled' => Settings::module_on( $module ),
			'reason'  => Settings::module_on( $module ) ? null : 'Not yet available — subject to written authorization and final approval.',
			'items'   => array(),
		);
	}

	public static function holdings(): array {
		return self::gated( 'wallet' );
	}

	public static function transactions(): array {
		return self::gated( 'wallet' );
	}

	public static function notifications(): array {
		return Notifications::for_user( get_current_user_id() );
	}

	public static function notification_read( \WP_REST_Request $r ): array {
		Notifications::mark_read( get_current_user_id(), (int) $r['id'] );
		return array( 'ok' => true );
	}

	public static function device( \WP_REST_Request $r ): array {
		$token = sanitize_text_field( (string) $r->get_param( 'push_token' ) );
		if ( $token ) {
			$list = array_unique( array_merge( (array) get_user_meta( get_current_user_id(), 'rc_push_tokens', true ), array( $token ) ) );
			update_user_meta( get_current_user_id(), 'rc_push_tokens', array_slice( array_values( array_filter( $list ) ), -5 ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * Account deletion (Apple 5.1.1(v), Google Play account-deletion policy, GDPR/FADP erasure).
	 * Body: { password, confirm: "DELETE" }. Staff accounts are never deleted through the API.
	 * Accounts without compliance history are deleted. Accounts with KYC/KYB/AML/sanctions outcomes are
	 * anonymised and locked; only the minimum compliance record required by law is retained, without
	 * contact details, and is scheduled for erasure when the retention period ends.
	 */
	public static function delete_account( \WP_REST_Request $r ) {
		$u = wp_get_current_user();
		$p = $r->get_json_params();
		if ( 'DELETE' !== ( $p['confirm'] ?? '' ) || ! wp_check_password( (string) ( $p['password'] ?? '' ), $u->user_pass, $u->ID ) ) {
			return new \WP_Error( 'rc_delete_confirm', 'Confirm with your password and the word DELETE.', array( 'status' => 422 ) );
		}
		if ( Auth::is_staff( $u ) ) {
			return new \WP_Error( 'rc_delete_staff', 'Staff accounts are closed by an administrator.', array( 'status' => 403 ) );
		}
		$uid      = $u->ID;
		$email_h  = hash( 'sha256', strtolower( $u->user_email ) );
		$has_kyc  = false;
		foreach ( array_keys( Compliance::CHECKS ) as $check ) {
			$v = get_user_meta( $uid, 'rc_' . $check, true );
			if ( $v && ! in_array( $v, array( 'not_started', 'not_applicable' ), true ) ) {
				$has_kyc = true;
			}
		}
		Auth::revoke_all( $uid );
		foreach ( array( 'rc_mfa_secret', 'rc_mfa_enabled', 'rc_mfa_recovery', 'rc_mfa_pending', 'rc_push_tokens', 'rc_language' ) as $k ) {
			delete_user_meta( $uid, $k );
		}
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'rc_notifications', array( 'user_id' => $uid ) );
		$wpdb->update( $wpdb->prefix . 'rc_support', array( 'name' => '', 'email' => '', 'message' => '[erased on account deletion]' ), array( 'user_id' => $uid ) );

		if ( $has_kyc ) {
			$anon = 'deleted-' . substr( $email_h, 0, 16 );
			wp_update_user( array( 'ID' => $uid, 'user_email' => $anon . '@deleted.invalid', 'display_name' => 'Deleted account', 'first_name' => '', 'last_name' => '', 'user_pass' => wp_generate_password( 64 ) ) );
			$wpdb->update( $wpdb->users, array( 'user_login' => $anon, 'user_nicename' => $anon ), array( 'ID' => $uid ) );
			( new \WP_User( $uid ) )->set_role( '' );
			update_user_meta( $uid, 'rc_deleted_at', gmdate( 'c' ) );
			update_user_meta( $uid, 'rc_retention_until', gmdate( 'Y-m-d', strtotime( '+10 years' ) ) );
			$mode = 'anonymised';
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $uid );
			$mode = 'deleted';
		}
		Audit_Log::record( 'account.deleted', 'user', $uid, 'Account ' . $mode . ' at the user\'s request', array( 'email_hash' => $email_h, 'mode' => $mode ), $uid );
		return array( 'ok' => true, 'mode' => $mode, 'message' => 'anonymised' === $mode ? 'Your account has been closed and your personal details erased. A minimal compliance record is retained as required by law.' : 'Your account and personal data have been deleted.' );
	}

	public static function support( \WP_REST_Request $r ) {
		$p = $r->get_json_params();
		$u = wp_get_current_user();
		if ( mb_strlen( (string) ( $p['message'] ?? '' ) ) < 10 ) {
			return new \WP_Error( 'rc_invalid', 'Please describe your request.', array( 'status' => 422 ) );
		}
		return self::store_support( $u->ID, 'app', $u->display_name, $u->user_email, sanitize_text_field( $p['subject'] ?? 'App support' ), sanitize_textarea_field( $p['message'] ), sanitize_key( $p['topic'] ?? 'general' ) );
	}
}
