<?php
/**
 * SEO: meta description, canonical, hreflang (EN/ES/IT), Open Graph, JSON-LD, robots policy.
 * Non-production environments are never indexed. Unpublished states are never public (core plugin).
 * Per-page SEO title/description can be set in the "SEO" meta box.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'rct_seo_head', 2 );
add_action( 'add_meta_boxes', 'rct_seo_box' );
add_action( 'save_post_page', 'rct_seo_save' );

add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( ( defined( 'RC_ENV' ) && 'production' !== RC_ENV ) || get_query_var( 'rc_passport' ) && ! get_option( 'rc_index_passports' ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
);

add_filter(
	'pre_get_document_title',
	static function ( $title ) {
		if ( is_page() ) {
			$custom = get_post_meta( get_queried_object_id(), '_rct_seo_title', true );
			if ( $custom ) {
				return $custom;
			}
		}
		if ( function_exists( 'rct_core' ) && rct_core() && get_query_var( 'rc_passport' ) ) {
			$p = RC\Passport::current();
			return $p ? $p['passport_no'] . ' — ' . __( 'Digital Asset Passport', 'reservechain' ) . ' | ReserveChain' : $title;
		}
		return $title;
	}
);

function rct_seo_description(): string {
	if ( is_page() ) {
		$id   = get_queried_object_id();
		$desc = get_post_meta( $id, '_rct_seo_desc', true ) ?: get_the_excerpt( $id );
		if ( $desc ) {
			return wp_strip_all_tags( $desc );
		}
	}
	return __( 'ReserveChain — proposed institutional infrastructure for industrial-metal real-world assets: Asset Registry, Digital Asset Passports and verifiable evidence for ultra-high-purity Copper Powder and high-purity Nickel Wire. In development; no tokens are offered or sold.', 'reservechain' );
}

function rct_seo_head(): void {
	$desc = rct_seo_description();
	$url  = is_singular() ? get_permalink() : home_url( add_query_arg( array() ) );
	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
	if ( is_singular() ) {
		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $url ) );
	}
	if ( rct_core() ) {
		foreach ( array_keys( RC\I18n::LANGS ) as $code ) {
			printf( '<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr( $code ), esc_url( add_query_arg( 'lang', $code, $url ) ) );
		}
		printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( $url ) );
	}
	$og = array(
		'og:type'        => 'website',
		'og:site_name'   => 'ReserveChain',
		'og:title'       => wp_get_document_title(),
		'og:description' => $desc,
		'og:url'         => $url,
		'og:image'       => RCT_URI . '/assets/img/og.png',
		'og:locale'      => rct_core() ? RC\I18n::LANGS[ rct_lang() ]['locale'] : 'en_GB',
	);
	foreach ( $og as $k => $v ) {
		printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $k ), esc_attr( $v ) );
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";

	if ( is_front_page() ) {
		$ld = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Organization',
			'name'        => 'ReserveChain',
			'url'         => home_url( '/' ),
			'logo'        => RCT_URI . '/assets/img/logo.svg',
			'description' => rct_seo_description(),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}
}

function rct_seo_box(): void {
	add_meta_box( 'rct_seo', 'SEO', 'rct_seo_render', 'page', 'side', 'low' );
}

function rct_seo_render( WP_Post $post ): void {
	wp_nonce_field( 'rct_seo', 'rct_seo_nonce' );
	printf( '<p><label>SEO title<br><input type="text" name="rct_seo_title" class="widefat" value="%s"></label></p>', esc_attr( get_post_meta( $post->ID, '_rct_seo_title', true ) ) );
	printf( '<p><label>Meta description<br><textarea name="rct_seo_desc" class="widefat" rows="3" maxlength="320">%s</textarea></label></p>', esc_textarea( get_post_meta( $post->ID, '_rct_seo_desc', true ) ) );
	echo '<p class="description">Falls back to the page summary. Non-production environments are always noindex.</p>';
}

function rct_seo_save( int $post_id ): void {
	if ( ! isset( $_POST['rct_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rct_seo_nonce'] ), 'rct_seo' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_rct_seo_title', sanitize_text_field( wp_unslash( $_POST['rct_seo_title'] ?? '' ) ) );
	update_post_meta( $post_id, '_rct_seo_desc', sanitize_textarea_field( wp_unslash( $_POST['rct_seo_desc'] ?? '' ) ) );
}
