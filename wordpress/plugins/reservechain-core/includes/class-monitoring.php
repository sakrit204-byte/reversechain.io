<?php
/**
 * Operations monitoring: health endpoints, scheduled integrity checks, alerting and the
 * "Operations" dashboard widget. Also registers the `wp rc-ops` WP-CLI command (backup marker,
 * health, checks) used by deploy/backup.sh and deploy/restore.sh.
 *
 *   GET /wp-json/rc/v1/health        Public, no secrets. Suitable for uptime monitors.
 *   GET /wp-json/rc/v1/health/full   Detailed; requires header X-RC-Health-Token = RC_HEALTH_TOKEN.
 *
 * Options owned by this module:
 *   rc_ops_last_backup     array  written by `wp rc-ops backup-mark` at the end of deploy/backup.sh
 *   rc_ops_cron_heartbeat  int    unix time of the last WP-cron heartbeat (every 5 minutes)
 *   rc_ops_failures        array  recent waitlist/contact/mail failures (timestamps + kind, no PII)
 *   rc_ops_alert_state     array  last time each alert key fired (de-duplication)
 *   rc_ops_last_check      array  result of the last full check run
 *   rc_alert_webhook       string optional Slack/Teams-compatible incoming-webhook URL
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Monitoring {

	public const CRON_HEARTBEAT = 'rc_ops_heartbeat';
	public const CRON_HOURLY    = 'rc_ops_hourly';
	public const CRON_DAILY     = 'rc_ops_daily';

	/** Thresholds. */
	public const BACKUP_MAX_AGE      = 26 * HOUR_IN_SECONDS;
	public const VERIFY_MAX_AGE      = 26 * HOUR_IN_SECONDS;
	public const CRON_MAX_AGE        = 20 * MINUTE_IN_SECONDS;
	public const FAILED_LOGINS_LIMIT = 20;
	public const DISK_USED_LIMIT     = 85.0;
	public const FAILURES_LIMIT      = 3;
	public const ALERT_REPEAT        = 6 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( self::CRON_HEARTBEAT, array( __CLASS__, 'heartbeat' ) );
		add_action( self::CRON_HOURLY, array( __CLASS__, 'run_hourly' ) );
		add_action( self::CRON_DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'watch_submissions' ), 10, 3 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_post_rc_ops_run_checks', array( __CLASS__, 'do_run_checks' ) );
	}

	public static function install(): void {
		self::ensure_scheduled();
		add_option( 'rc_alert_webhook', '', '', false );
	}

	/* -------------------------------------------------------------- scheduling */

	public static function schedules( array $s ): array {
		$s['rc_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (ReserveChain)', 'reservechain' ),
		);
		return $s;
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HEARTBEAT ) ) {
			wp_schedule_event( time() + 60, 'rc_five_minutes', self::CRON_HEARTBEAT );
		}
		if ( ! wp_next_scheduled( self::CRON_HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOURLY );
		}
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			// 03:15 UTC: after the 02:30 UTC backup, so a failed backup is caught the same night.
			$next = strtotime( 'tomorrow 03:15 UTC' );
			$today = strtotime( 'today 03:15 UTC' );
			wp_schedule_event( $today > time() ? $today : $next, 'daily', self::CRON_DAILY );
		}
	}

	public static function heartbeat(): void {
		update_option( 'rc_ops_cron_heartbeat', time(), false );
	}

	public static function run_hourly(): void {
		self::heartbeat();
		self::run_checks( false, true );
	}

	public static function run_daily(): void {
		self::heartbeat();
		self::run_checks( true, true );
	}

	/* -------------------------------------------------------------- checks */

	/**
	 * Evaluate every check. A full chain walk only when $full_verify (daily / on demand);
	 * otherwise the age and result of the last verification are used.
	 *
	 * @return array{status:string,checks:array<string,array{status:string,detail:string}>,at:int}
	 */
	public static function run_checks( bool $full_verify = false, bool $send_alerts = false, bool $persist = true ): array {
		$checks = array();

		// Database.
		$db_ok              = self::db_reachable();
		$checks['database'] = array( 'status' => $db_ok ? 'ok' : 'fail', 'detail' => $db_ok ? 'reachable' : 'query failed' );

		// Audit chain.
		if ( $full_verify && $db_ok ) {
			$r = Audit_Log::verify();
			Audit_Log::record( 'ops.integrity_check', 'audit', (int) $r['seq'], $r['ok'] ? sprintf( 'Scheduled chain verification OK (%d entries)', $r['checked'] ) : 'Scheduled chain verification FAILED', array( 'errors' => count( $r['errors'] ), 'head' => $r['head'] ), 0 );
		}
		$last = get_option( 'rc_audit_last_verify', array() );
		$age  = ! empty( $last['at'] ) ? time() - (int) $last['at'] : null;
		if ( ! empty( $last ) && empty( $last['ok'] ) ) {
			$checks['audit_chain'] = array( 'status' => 'fail', 'detail' => sprintf( 'verification FAILED (%d error(s)) at %s UTC', count( (array) ( $last['errors'] ?? array() ) ), gmdate( 'Y-m-d H:i', (int) $last['at'] ) ) );
		} elseif ( null === $age || $age > self::VERIFY_MAX_AGE ) {
			$checks['audit_chain'] = array( 'status' => 'warn', 'detail' => null === $age ? 'never verified' : sprintf( 'last verified %s ago', human_time_diff( (int) $last['at'] ) ) );
		} else {
			$checks['audit_chain'] = array( 'status' => 'ok', 'detail' => sprintf( '%d entries verified %s ago', (int) ( $last['checked'] ?? 0 ), human_time_diff( (int) $last['at'] ) ) );
		}

		// Triggers.
		$trig               = $db_ok && Install::triggers_active();
		$checks['triggers'] = array( 'status' => $trig ? 'ok' : 'fail', 'detail' => $trig ? 'UPDATE/DELETE blocked on audit log' : 'audit immutability triggers MISSING' );

		// Failed logins in the last hour.
		$fails                   = $db_ok ? self::failed_logins( HOUR_IN_SECONDS ) : 0;
		$checks['failed_logins'] = array( 'status' => $fails > self::FAILED_LOGINS_LIMIT ? 'fail' : 'ok', 'detail' => sprintf( '%d failed sign-in(s) in the last hour (threshold %d)', $fails, self::FAILED_LOGINS_LIMIT ) );

		// Waitlist / contact submission failures (incl. mail transport) in the last 24 h.
		$sub                 = self::recent_failures( DAY_IN_SECONDS );
		$checks['submissions'] = array( 'status' => count( $sub ) >= self::FAILURES_LIMIT ? 'fail' : ( $sub ? 'warn' : 'ok' ), 'detail' => sprintf( '%d waitlist/contact/mail failure(s) in 24 h%s', count( $sub ), $sub ? ' — kinds: ' . implode( ', ', array_unique( array_column( $sub, 'kind' ) ) ) : '' ) );

		// Backups (not expected in development).
		$b = get_option( 'rc_ops_last_backup', array() );
		if ( 'development' === RC_ENV && empty( $b ) ) {
			$checks['backup'] = array( 'status' => 'ok', 'detail' => 'n/a in development' );
		} elseif ( empty( $b['at'] ) ) {
			$checks['backup'] = array( 'status' => 'fail', 'detail' => 'no successful backup recorded' );
		} elseif ( empty( $b['ok'] ) ) {
			$checks['backup'] = array( 'status' => 'fail', 'detail' => 'last backup run FAILED: ' . sanitize_text_field( (string) ( $b['message'] ?? '' ) ) );
		} elseif ( time() - (int) $b['at'] > self::BACKUP_MAX_AGE ) {
			$checks['backup'] = array( 'status' => 'fail', 'detail' => sprintf( 'last backup %s ago (limit 26 h)', human_time_diff( (int) $b['at'] ) ) );
		} else {
			$checks['backup'] = array( 'status' => 'ok', 'detail' => sprintf( 'last backup %s ago%s', human_time_diff( (int) $b['at'] ), ! empty( $b['offsite'] ) ? ', off-site copy OK' : '' ) );
		}

		// Disk.
		$disk = self::disk_used_percent();
		if ( null === $disk ) {
			$checks['disk'] = array( 'status' => 'warn', 'detail' => 'disk usage unavailable' );
		} else {
			$checks['disk'] = array( 'status' => $disk > self::DISK_USED_LIMIT ? 'fail' : 'ok', 'detail' => sprintf( '%.1f%% used (threshold %d%%)', $disk, (int) self::DISK_USED_LIMIT ) );
		}

		// WP-cron.
		$hb             = (int) get_option( 'rc_ops_cron_heartbeat', 0 );
		$checks['cron'] = array( 'status' => $hb && time() - $hb <= self::CRON_MAX_AGE ? 'ok' : 'warn', 'detail' => $hb ? sprintf( 'last heartbeat %s ago', human_time_diff( $hb ) ) : 'no heartbeat yet' );

		$status = 'ok';
		foreach ( $checks as $c ) {
			if ( 'ok' !== $c['status'] ) {
				$status = 'degraded';
			}
		}
		$result = array( 'status' => $status, 'checks' => $checks, 'at' => time() );
		if ( $persist ) {
			update_option( 'rc_ops_last_check', $result, false );
		}

		if ( $send_alerts ) {
			self::alert_on( $checks );
		}
		return $result;
	}

	public static function db_reachable(): bool {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$ok = '1' === (string) $wpdb->get_var( 'SELECT 1' );
		$wpdb->suppress_errors( false );
		return $ok;
	}

	public static function failed_logins( int $window ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $window );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Audit_Log::table() . " WHERE action IN ('auth.login_failed','auth.api_login_failed') AND created_at >= %s", $since ) ); // phpcs:ignore
	}

	public static function disk_used_percent(): ?float {
		$path  = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH;
		$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false; // phpcs:ignore
		$total = function_exists( 'disk_total_space' ) ? @disk_total_space( $path ) : false; // phpcs:ignore
		if ( ! $free || ! $total ) {
			return null;
		}
		return round( 100 - ( $free / $total * 100 ), 1 );
	}

	/* -------------------------------------------------------------- failure tracking */

	/** Count waitlist / contact requests that ended in a server error. Validation (4xx) is not a failure. */
	public static function watch_submissions( $response, $server, $request ) {
		$route = is_object( $request ) ? (string) $request->get_route() : '';
		if ( in_array( $route, array( '/' . Rest::NS . '/waitlist', '/' . Rest::NS . '/contact' ), true ) && $response instanceof \WP_HTTP_Response && $response->get_status() >= 500 ) {
			self::record_failure( 'waitlist' === basename( $route ) ? 'waitlist_5xx' : 'contact_5xx' );
		}
		return $response;
	}

	/** Mail transport failures (waitlist confirmation emails, contact forwarding). */
	public static function on_mail_failed( $error ): void {
		self::record_failure( 'mail' );
	}

	public static function record_failure( string $kind ): void {
		$list   = (array) get_option( 'rc_ops_failures', array() );
		$list[] = array( 'at' => time(), 'kind' => sanitize_key( $kind ) );
		update_option( 'rc_ops_failures', array_slice( $list, -100 ), false );
	}

	public static function recent_failures( int $window ): array {
		$cut = time() - $window;
		return array_values( array_filter( (array) get_option( 'rc_ops_failures', array() ), static fn( $f ) => is_array( $f ) && (int) ( $f['at'] ?? 0 ) >= $cut ) );
	}

	/* -------------------------------------------------------------- alerting */

	private static function alert_on( array $checks ): void {
		$titles = array(
			'database'      => __( 'Database unreachable', 'reservechain' ),
			'audit_chain'   => __( 'Audit chain verification failure', 'reservechain' ),
			'triggers'      => __( 'Audit immutability triggers missing', 'reservechain' ),
			'failed_logins' => __( 'Excessive failed sign-ins', 'reservechain' ),
			'submissions'   => __( 'Waitlist/contact submissions failing', 'reservechain' ),
			'backup'        => __( 'Backup missing or stale', 'reservechain' ),
			'disk'          => __( 'Disk usage above 85%', 'reservechain' ),
		);
		$state = (array) get_option( 'rc_ops_alert_state', array() );
		$fired = array();
		foreach ( $titles as $key => $title ) {
			if ( ! isset( $checks[ $key ] ) || 'fail' !== $checks[ $key ]['status'] ) {
				unset( $state[ $key ] );
				continue;
			}
			if ( ! empty( $state[ $key ] ) && time() - (int) $state[ $key ] < self::ALERT_REPEAT ) {
				continue;
			}
			$state[ $key ] = time();
			$fired[]       = $title . ': ' . $checks[ $key ]['detail'];
		}
		update_option( 'rc_ops_alert_state', $state, false );
		if ( $fired ) {
			self::send_alert( $fired );
		}
	}

	/** Send an alert by e-mail (Settings contact email, else admin_email) and optional webhook. */
	public static function send_alert( array $lines ): bool {
		$env     = strtoupper( (string) RC_ENV );
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf( '[ReserveChain %s] %s', $env, 1 === count( $lines ) ? $lines[0] : sprintf( __( '%d operational alerts', 'reservechain' ), count( $lines ) ) );
		$body    = sprintf( "%s (%s)\n\n- %s\n\n%s\n%s", $site, $env, implode( "\n- ", $lines ), __( 'Details: WordPress admin → Dashboard → Operations, or GET /wp-json/rc/v1/health/full with the health token.', 'reservechain' ), __( 'Runbook: docs/OPERATIONS.md', 'reservechain' ) );
		$to      = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
		$mailed  = $to ? wp_mail( $to, mb_substr( $subject, 0, 180 ), $body ) : false;

		$hook = (string) get_option( 'rc_alert_webhook', '' );
		if ( $hook && 0 === strpos( $hook, 'https://' ) ) {
			// {"text": …} is accepted by Slack and Microsoft Teams incoming webhooks (and Mattermost/Discord-compatible bridges).
			wp_remote_post(
				$hook,
				array(
					'timeout'  => 5,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'text' => sprintf( '[ReserveChain %s] %s', $env, $site ) . "\n" . '• ' . implode( "\n• ", $lines ) ) ),
				)
			);
		}
		Audit_Log::record( 'ops.alert', 'ops', 0, mb_substr( $subject, 0, 200 ), array( 'alerts' => $lines, 'mailed' => (bool) $mailed, 'webhook' => '' !== $hook ), 0 );
		return (bool) $mailed;
	}

	/* -------------------------------------------------------------- REST */

	public static function routes(): void {
		register_rest_route(
			Rest::NS,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_health' ),
				'permission_callback' => static fn() => Security::rate_limit( 'health', 60, MINUTE_IN_SECONDS ) ? true : new \WP_Error( 'rc_rate_limited', 'Too many requests.', array( 'status' => 429 ) ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/health/full',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_health_full' ),
				'permission_callback' => array( __CLASS__, 'token_gate' ),
			)
		);
	}

	public static function token_gate( \WP_REST_Request $r ) {
		$expected = defined( 'RC_HEALTH_TOKEN' ) ? (string) RC_HEALTH_TOKEN : '';
		$given    = (string) $r->get_header( 'x_rc_health_token' );
		if ( strlen( $expected ) < 16 ) {
			return new \WP_Error( 'rc_health_disabled', 'Detailed health is disabled (RC_HEALTH_TOKEN not configured).', array( 'status' => 404 ) );
		}
		if ( '' === $given || ! hash_equals( $expected, $given ) ) {
			Security::rate_limit( 'health_full_bad', 10, HOUR_IN_SECONDS );
			return new \WP_Error( 'rc_unauthorized', 'Invalid health token.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Public summary — contains nothing that is not already public (chain head is published at /audit/head). */
	public static function summary(): array {
		$c    = self::run_checks( false, false, false );
		$last = get_option( 'rc_audit_last_verify', array() );
		$hb   = (int) get_option( 'rc_ops_cron_heartbeat', 0 );
		$disk = self::disk_used_percent();
		$head = 'ok' === $c['checks']['database']['status'] ? Audit_Log::head() : null;
		$out  = array(
			'status'      => $c['status'],
			'time'        => gmdate( 'c' ),
			'db'          => 'ok' === $c['checks']['database']['status'],
			'audit'       => array(
				'seq'                   => $head['seq'] ?? null,
				'chain_head'            => $head['chain_head'] ?? null,
				'last_verify_ok'        => isset( $last['ok'] ) ? (bool) $last['ok'] : null,
				'last_verify_age_s'     => ! empty( $last['at'] ) ? time() - (int) $last['at'] : null,
			),
			'triggers'    => 'ok' === $c['checks']['triggers']['status'],
			'cron_age_s'  => $hb ? time() - $hb : null,
			'disk_free_pct' => null === $disk ? null : round( 100 - $disk, 1 ),
			'backup_ok'   => 'ok' === $c['checks']['backup']['status'],
		);
		if ( 'production' !== RC_ENV ) {
			$out['env']      = RC_ENV;
			$out['php']      = PHP_VERSION;
			$out['wp']       = get_bloginfo( 'version' );
			$out['rc']       = RC_VERSION;
		}
		return $out;
	}

	public static function rest_health(): \WP_REST_Response {
		$res = new \WP_REST_Response( self::summary(), 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function rest_health_full(): \WP_REST_Response {
		$res = new \WP_REST_Response( self::full(), 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function full(): array {
		$c    = self::run_checks( false, false, false );
		$cron = array();
		foreach ( array( self::CRON_HEARTBEAT, self::CRON_HOURLY, self::CRON_DAILY, 'rc_waitlist_purge' ) as $hook ) {
			$n             = wp_next_scheduled( $hook );
			$cron[ $hook ] = $n ? gmdate( 'c', $n ) : null;
		}
		$b = get_option( 'rc_ops_last_backup', array() );
		return array(
			'status'        => $c['status'],
			'time'          => gmdate( 'c' ),
			'env'           => RC_ENV,
			'versions'      => array( 'php' => PHP_VERSION, 'wp' => get_bloginfo( 'version' ), 'rc' => RC_VERSION, 'rc_db' => RC_DB_VERSION, 'mysql' => self::mysql_version() ),
			'checks'        => $c['checks'],
			'audit'         => array( 'head' => Audit_Log::head(), 'last_verify' => array_diff_key( (array) get_option( 'rc_audit_last_verify', array() ), array( 'errors' => 1 ) ), 'anchor' => Audit_Log::latest_anchor() ),
			'backup'        => is_array( $b ) ? $b : array(),
			'failures_24h'  => self::recent_failures( DAY_IN_SECONDS ),
			'cron'          => array( 'disabled_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'next' => $cron ),
			'alerts'        => array( 'state' => get_option( 'rc_ops_alert_state', array() ), 'webhook_configured' => '' !== (string) get_option( 'rc_alert_webhook', '' ) ),
			'site_mode'     => Settings::get( 'site_mode' ),
			'smtp'          => defined( 'RC_SMTP_CONFIGURED' ) && RC_SMTP_CONFIGURED,
		);
	}

	private static function mysql_version(): string {
		global $wpdb;
		return (string) $wpdb->get_var( 'SELECT VERSION()' );
	}

	/* -------------------------------------------------------------- admin */

	public static function dashboard_widget(): void {
		if ( current_user_can( 'rc_view_audit' ) || current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'rc_operations', __( 'Operations', 'reservechain' ), array( __CLASS__, 'render_widget' ) );
		}
	}

	public static function render_widget(): void {
		$c      = get_option( 'rc_ops_last_check', array() );
		$c      = ( empty( $c['at'] ) || time() - (int) $c['at'] > 15 * MINUTE_IN_SECONDS ) ? self::run_checks( false, false ) : $c;
		$labels = array(
			'database'      => __( 'Database', 'reservechain' ),
			'audit_chain'   => __( 'Audit chain', 'reservechain' ),
			'triggers'      => __( 'Immutability triggers', 'reservechain' ),
			'failed_logins' => __( 'Failed sign-ins (1 h)', 'reservechain' ),
			'submissions'   => __( 'Waitlist / contact', 'reservechain' ),
			'backup'        => __( 'Backup', 'reservechain' ),
			'disk'          => __( 'Disk', 'reservechain' ),
			'cron'          => __( 'Scheduled jobs', 'reservechain' ),
		);
		$colors = array( 'ok' => '#1a7f37', 'warn' => '#9a6700', 'fail' => '#cf222e' );
		printf( '<p><strong>%s</strong> <span style="color:%s">%s</span> · %s</p>', esc_html__( 'Overall:', 'reservechain' ), esc_attr( 'ok' === $c['status'] ? $colors['ok'] : $colors['fail'] ), esc_html( 'ok' === $c['status'] ? __( 'OK', 'reservechain' ) : __( 'Degraded', 'reservechain' ) ), esc_html( sprintf( /* translators: %s: environment */ __( 'environment: %s', 'reservechain' ), RC_ENV ) ) );
		echo '<table class="widefat striped" style="border:0"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( empty( $c['checks'][ $key ] ) ) {
				continue;
			}
			$row = $c['checks'][ $key ];
			printf( '<tr><td>%s</td><td><span style="color:%s;font-weight:600">%s</span></td><td>%s</td></tr>', esc_html( $label ), esc_attr( $colors[ $row['status'] ] ?? '#000' ), esc_html( strtoupper( $row['status'] ) ), esc_html( $row['detail'] ) );
		}
		echo '</tbody></table>';
		printf( '<p class="description">%s</p>', esc_html( sprintf( /* translators: %s: time */ __( 'Checked %s ago. Full chain verification runs daily at 03:15 UTC; alerts go to the Settings contact email and the optional webhook.', 'reservechain' ), human_time_diff( (int) $c['at'] ) ) ) );
		if ( current_user_can( 'rc_view_audit' ) || current_user_can( 'manage_options' ) ) {
			printf(
				'<form method="post" action="%s"><input type="hidden" name="action" value="rc_ops_run_checks">%s<button class="button">%s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'rc_ops_run_checks', '_wpnonce', true, false ), // phpcs:ignore
				esc_html__( 'Run full checks now (verifies the whole chain)', 'reservechain' )
			);
		}
	}

	public static function do_run_checks(): void {
		if ( ! ( current_user_can( 'rc_view_audit' ) || current_user_can( 'manage_options' ) ) ) {
			wp_die( esc_html__( 'Not allowed.', 'reservechain' ), 403 );
		}
		check_admin_referer( 'rc_ops_run_checks' );
		self::run_checks( true, true );
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( __NAMESPACE__ . '\\Ops_CLI', false ) ) {

	/**
	 * Operations commands: `wp rc-ops <command>`.
	 *
	 *   wp rc-ops health [--strict]        Print the detailed health report (JSON).
	 *   wp rc-ops check [--no-alerts]      Run all checks incl. full audit-chain verification.
	 *   wp rc-ops backup-mark [--file=<f>] [--size=<bytes>] [--sha256=<h>] [--offsite] [--failed] [--message=<m>]
	 *   wp rc-ops test-alert               Send a test alert (email + webhook).
	 */
	final class Ops_CLI {

		/**
		 * Print the detailed health report.
		 *
		 * [--strict]
		 * : Exit non-zero when status is not "ok".
		 */
		public function health( $args, $assoc ): void {
			$h = Monitoring::full();
			\WP_CLI::line( wp_json_encode( $h, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( 'ok' !== $h['status'] ) {
				! empty( $assoc['strict'] ) ? \WP_CLI::error( 'Health: ' . $h['status'] ) : \WP_CLI::warning( 'Health: ' . $h['status'] );
			} else {
				\WP_CLI::success( 'Health: ok' );
			}
		}

		/**
		 * Run all checks, including a full audit-chain verification.
		 *
		 * [--[no-]alerts]
		 * : Send e-mail/webhook alerts for failing checks (default: yes; --no-alerts to suppress).
		 */
		public function check( $args, $assoc ): void {
			$r = Monitoring::run_checks( true, (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'alerts', true ) );
			foreach ( $r['checks'] as $k => $c ) {
				\WP_CLI::line( sprintf( '%-14s %-5s %s', $k, strtoupper( $c['status'] ), $c['detail'] ) );
			}
			'ok' === $r['status'] ? \WP_CLI::success( 'All checks OK.' ) : \WP_CLI::warning( 'Status: ' . $r['status'] );
		}

		/**
		 * Record the outcome of a backup run (called by deploy/backup.sh).
		 *
		 * [--file=<file>]
		 * : Archive file name.
		 *
		 * [--size=<bytes>]
		 * : Archive size.
		 *
		 * [--sha256=<hash>]
		 * : SHA-256 of the encrypted archive.
		 *
		 * [--offsite]
		 * : Off-site copy succeeded.
		 *
		 * [--failed]
		 * : Record a failed run instead.
		 *
		 * [--message=<message>]
		 * : Failure message.
		 *
		 * @subcommand backup-mark
		 */
		public function backup_mark( $args, $assoc ): void {
			$prev = (array) get_option( 'rc_ops_last_backup', array() );
			if ( ! empty( $assoc['failed'] ) ) {
				// Keep the time of the last *successful* backup so the age check stays truthful.
				$rec = array_merge( $prev, array( 'ok' => false, 'failed_at' => time(), 'message' => sanitize_text_field( (string) ( $assoc['message'] ?? 'unknown error' ) ) ) );
				update_option( 'rc_ops_last_backup', $rec, false );
				Audit_Log::record( 'ops.backup_failed', 'ops', 0, 'Backup run failed', array( 'message' => $rec['message'] ), 0 );
				\WP_CLI::warning( 'Backup failure recorded.' );
				return;
			}
			$rec = array(
				'ok'      => true,
				'at'      => time(),
				'file'    => preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $assoc['file'] ?? '' ) ),
				'size'    => (int) ( $assoc['size'] ?? 0 ),
				'sha256'  => preg_match( '/^[a-f0-9]{64}$/', (string) ( $assoc['sha256'] ?? '' ) ) ? $assoc['sha256'] : '',
				'offsite' => ! empty( $assoc['offsite'] ),
			);
			update_option( 'rc_ops_last_backup', $rec, false );
			Audit_Log::record( 'ops.backup_completed', 'ops', 0, 'Encrypted backup completed', array_diff_key( $rec, array( 'ok' => 1 ) ), 0 );
			\WP_CLI::success( sprintf( 'Backup recorded: %s (%d bytes)', $rec['file'], $rec['size'] ) );
		}

		/**
		 * Send a test alert through e-mail and the optional webhook.
		 *
		 * @subcommand test-alert
		 */
		public function test_alert(): void {
			Monitoring::send_alert( array( 'Test alert from wp rc-ops test-alert — no action required.' ) ) ? \WP_CLI::success( 'Alert e-mail accepted by wp_mail.' ) : \WP_CLI::warning( 'wp_mail returned false (check SMTP settings).' );
		}
	}

	\WP_CLI::add_command( 'rc-ops', __NAMESPACE__ . '\\Ops_CLI' );
}
