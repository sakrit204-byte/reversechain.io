<?php
/**
 * Plugin Name: ReserveChain SMTP (mu-plugin)
 * Description: Configures PHPMailer from WORDPRESS_SMTP_* constants or environment variables. No-op when WORDPRESS_SMTP_HOST is empty.
 *
 * Variables (constant takes precedence over environment):
 *   WORDPRESS_SMTP_HOST       smtp.example.com            (required to enable)
 *   WORDPRESS_SMTP_PORT       587
 *   WORDPRESS_SMTP_SECURE     tls | ssl | none            (default tls)
 *   WORDPRESS_SMTP_USER       username (enables SMTP AUTH when set)
 *   WORDPRESS_SMTP_PASSWORD   password / API key
 *   WORDPRESS_SMTP_FROM       no-reply@example.com
 *   WORDPRESS_SMTP_FROM_NAME  ReserveChain
 *
 * Mounted read-only into wp-content/mu-plugins by deploy/docker-compose.prod.yml.
 *
 * @package ReserveChain
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rc_smtp_conf' ) ) {
	function rc_smtp_conf( string $key, string $fallback = '' ): string {
		$name = 'WORDPRESS_SMTP_' . $key;
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$v = getenv( $name );
		return false === $v || '' === $v ? $fallback : (string) $v;
	}
}

if ( '' !== rc_smtp_conf( 'HOST' ) ) {
	if ( ! defined( 'RC_SMTP_CONFIGURED' ) ) {
		define( 'RC_SMTP_CONFIGURED', true );
	}

	add_action(
		'phpmailer_init',
		static function ( $mailer ): void {
			$mailer->isSMTP();
			$mailer->Host    = rc_smtp_conf( 'HOST' );
			$mailer->Port    = (int) rc_smtp_conf( 'PORT', '587' );
			$secure          = strtolower( rc_smtp_conf( 'SECURE', 'tls' ) );
			$mailer->SMTPSecure  = in_array( $secure, array( 'tls', 'ssl' ), true ) ? $secure : '';
			$mailer->SMTPAutoTLS = 'none' !== $secure;
			$user            = rc_smtp_conf( 'USER' );
			if ( '' !== $user ) {
				$mailer->SMTPAuth = true;
				$mailer->Username = $user;
				$mailer->Password = rc_smtp_conf( 'PASSWORD' );
			}
			$mailer->Timeout = 15;
			$from            = rc_smtp_conf( 'FROM' );
			if ( '' !== $from && is_email( $from ) ) {
				$mailer->setFrom( $from, rc_smtp_conf( 'FROM_NAME', 'ReserveChain' ), false );
				$mailer->Sender = $from;
			}
		}
	);

	add_filter(
		'wp_mail_from',
		static function ( $from ) {
			$f = rc_smtp_conf( 'FROM' );
			return '' !== $f && is_email( $f ) ? $f : $from;
		}
	);
	add_filter(
		'wp_mail_from_name',
		static function ( $name ) {
			return rc_smtp_conf( 'FROM_NAME', $name );
		}
	);
}
