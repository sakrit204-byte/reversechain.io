<?php
/**
 * Fallback template.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
get_header();
?>
<section class="rc-pagehero"><div class="rc-wrap"><h1 class="rc-pagehero__title"><?php echo esc_html( wp_get_document_title() ); ?></h1></div></section>
<div class="rc-content"><div class="rc-section"><div class="rc-wrap rc-prose">
<?php
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		echo '<article><h2><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h2>';
		the_excerpt();
		echo '</article>';
	}
} else {
	echo '<p>' . esc_html__( 'Nothing found.', 'reservechain' ) . '</p>';
}
?>
</div></div></div>
<?php
get_footer();
