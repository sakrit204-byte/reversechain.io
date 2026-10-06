<?php
/**
 * Generic page: hero (title, summary, breadcrumbs) + CMS content.
 * Content is authored in the CMS as HTML sections using the design-system classes,
 * with shortcodes for live modules. Untranslated pages fall back to English with a notice.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	$slug    = get_post_field( 'post_name' );
	$excerpt = has_excerpt() ? get_the_excerpt() : '';
	$kicker  = get_post_meta( get_the_ID(), '_rct_kicker', true );
	?>
	<section class="rc-pagehero rc-pagehero--<?php echo esc_attr( $slug ); ?>">
		<div class="rc-wrap">
			<?php echo rct_breadcrumbs(); // phpcs:ignore ?>
			<?php if ( $kicker ) : ?><p class="rc-kicker"><?php echo esc_html( $kicker ); ?></p><?php endif; ?>
			<h1 class="rc-pagehero__title"><?php the_title(); ?></h1>
			<?php if ( $excerpt ) : ?><p class="rc-pagehero__lead"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
		</div>
		<div class="rc-pagehero__grid" aria-hidden="true"></div>
	</section>
	<?php if ( rct_core() && ! RC\I18n::has_translation( get_the_ID() ) ) : ?>
		<div class="rc-wrap"><p class="rc-alert rc-alert--info"><?php esc_html_e( 'This page has not yet been translated into your language. The English version is shown.', 'reservechain' ); ?></p></div>
	<?php endif; ?>
	<article class="rc-content">
		<?php the_content(); ?>
	</article>
	<?php
endwhile;
get_footer();
