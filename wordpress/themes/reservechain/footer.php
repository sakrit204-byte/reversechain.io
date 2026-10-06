<?php
/**
 * Site footer: full disclosure, navigation, live integrity badge.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
?>
</main>
<footer class="rc-footer">
	<div class="rc-wrap">
		<div class="rc-footer__top">
			<div class="rc-footer__brand">
				<?php echo rct_logo(); // phpcs:ignore ?>
				<p><?php esc_html_e( 'Proposed infrastructure for verifiable, industrial-metal real-world assets. Evidence first; tokens only if and when approved.', 'reservechain' ); ?></p>
				<?php echo rct_audit_badge(); // phpcs:ignore ?>
				<a class="rc-btn rc-btn--sm rc-footer__wl" href="<?php echo esc_url( home_url( '/participation/waitlist/' ) ); ?>"><?php esc_html_e( 'Receive project-development updates', 'reservechain' ); ?> →</a>
			</div>
			<div class="rc-footer__cols">
			<?php
			$cols = array(
				'footer-1' => __( 'Platform', 'reservechain' ),
				'footer-2' => __( 'Assets', 'reservechain' ),
				'footer-3' => __( 'Enterprise', 'reservechain' ),
				'footer-4' => __( 'Participation', 'reservechain' ),
				'footer-5' => __( 'Company', 'reservechain' ),
				'footer-6' => __( 'Resources', 'reservechain' ),
				'footer-7' => __( 'Legal', 'reservechain' ),
			);
			foreach ( $cols as $loc => $label ) :
				if ( ! has_nav_menu( $loc ) ) {
					continue;
				}
				?>
				<nav class="rc-footer__col" aria-label="<?php echo esc_attr( $label ); ?>">
					<h2><?php echo esc_html( $label ); ?></h2>
					<?php wp_nav_menu( array( 'theme_location' => $loc, 'container' => false, 'depth' => 1 ) ); ?>
				</nav>
			<?php endforeach; ?>
			</div>
		</div>
		<div class="rc-footer__legal">
			<p><strong><?php esc_html_e( 'Important notice', 'reservechain' ); ?>.</strong> <?php echo esc_html( rct_disclosure() ); ?></p>
			<p><?php echo esc_html( rct_eu_notice() ); ?> <?php esc_html_e( 'ReserveChain is not described as, and does not claim to be, compliant with MiCA or any other regime. Nothing on this website is an offer, solicitation or recommendation.', 'reservechain' ); ?></p>
			<?php if ( 'en' !== rct_lang() ) : ?>
				<p><em><?php esc_html_e( 'Translations are provided for convenience. The English version is authoritative.', 'reservechain' ); ?></em></p>
			<?php endif; ?>
		</div>
		<div class="rc-footer__bottom">
			<span>© <?php echo esc_html( gmdate( 'Y' ) ); ?> ReserveChain — <?php esc_html_e( 'entity in formation; final corporate structure subject to approval', 'reservechain' ); ?></span>
			<?php echo rct_lang_switcher(); // phpcs:ignore ?>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
