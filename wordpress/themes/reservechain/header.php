<?php
/**
 * Site header: persistent prelaunch notice, navigation, language switcher.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?> lang="<?php echo esc_attr( rct_lang() ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="rc-skip" href="#main"><?php esc_html_e( 'Skip to content', 'reservechain' ); ?></a>

<div class="rc-notice" role="note" aria-label="<?php esc_attr_e( 'Prelaunch notice', 'reservechain' ); ?>">
	<div class="rc-wrap rc-notice__inner">
		<span class="rc-notice__tag"><?php esc_html_e( 'Prelaunch', 'reservechain' ); ?></span>
		<p class="rc-notice__short"><?php esc_html_e( 'ReserveChain is in development. No tokens are being offered or sold through this website.', 'reservechain' ); ?></p>
		<button class="rc-notice__toggle" type="button" aria-expanded="false" aria-controls="rc-notice-full"><?php esc_html_e( 'Read full notice', 'reservechain' ); ?></button>
	</div>
	<div class="rc-notice__full" id="rc-notice-full" hidden>
		<div class="rc-wrap">
			<p><?php echo esc_html( rct_disclosure() ); ?></p>
			<p><?php echo esc_html( rct_eu_notice() ); ?></p>
		</div>
	</div>
</div>

<header class="rc-header" data-rc-header>
	<div class="rc-wrap rc-header__inner">
		<a class="rc-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="ReserveChain — <?php esc_attr_e( 'Home', 'reservechain' ); ?>"><?php echo rct_logo(); // phpcs:ignore ?></a>
		<nav class="rc-nav" id="rc-nav" aria-label="<?php esc_attr_e( 'Primary', 'reservechain' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'rc-nav__list',
					'depth'          => 1,
					'fallback_cb'    => false,
				)
			);
			?>
			<div class="rc-nav__extra">
				<?php echo rct_lang_switcher(); // phpcs:ignore ?>
				<a class="rc-btn rc-btn--primary rc-btn--sm" href="<?php echo esc_url( home_url( '/waitlist/' ) ); ?>"><?php esc_html_e( 'Register interest', 'reservechain' ); ?></a>
			</div>
		</nav>
		<button class="rc-burger" type="button" aria-controls="rc-nav" aria-expanded="false"><span class="screen-reader-text"><?php esc_html_e( 'Menu', 'reservechain' ); ?></span><i></i><i></i><i></i></button>
	</div>
</header>
<main id="main" class="rc-main" tabindex="-1">
