<?php
/**
 * Front page: entirely CMS-authored sections (hero included) + live modules via shortcodes.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;
get_header();
while ( have_posts() ) :
	the_post();
	echo '<div class="rc-content rc-content--home">';
	the_content();
	echo '</div>';
endwhile;
get_footer();
