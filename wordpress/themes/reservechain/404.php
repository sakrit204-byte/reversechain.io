<?php
/**
 * 404.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<section class="rc-pagehero rc-pagehero--404">
	<div class="rc-wrap">
		<p class="rc-kicker rc-mono">404 · <?php esc_html_e( 'Record not found', 'reservechain' ); ?></p>
		<h1 class="rc-pagehero__title"><?php esc_html_e( 'This page is not in the registry.', 'reservechain' ); ?></h1>
		<p class="rc-pagehero__lead"><?php esc_html_e( 'The page may have been moved, unpublished, or the section may be hidden while it is prepared.', 'reservechain' ); ?></p>
		<p><a class="rc-btn rc-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'reservechain' ); ?></a> <a class="rc-btn" href="<?php echo esc_url( home_url( '/asset-registry/' ) ); ?>"><?php esc_html_e( 'Asset Registry', 'reservechain' ); ?></a></p>
	</div>
	<div class="rc-pagehero__grid" aria-hidden="true"></div>
</section>
<?php
get_footer();
