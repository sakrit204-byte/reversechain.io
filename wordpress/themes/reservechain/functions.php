<?php
/**
 * ReserveChain theme.
 *
 * Presentation only — all data, workflow, compliance and API logic lives in the ReserveChain Core plugin.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;

define( 'RCT_VERSION', '0.9.0' );
define( 'RCT_URI', get_template_directory_uri() );
define( 'RCT_DIR', get_template_directory() );

require_once RCT_DIR . '/inc/helpers.php';
require_once RCT_DIR . '/inc/seo.php';

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_editor_style( 'assets/css/main.css' );
		register_nav_menus(
			array(
				'primary'  => __( 'Primary navigation', 'reservechain' ),
				'footer-1' => __( 'Footer — Platform', 'reservechain' ),
				'footer-2' => __( 'Footer — Assets', 'reservechain' ),
				'footer-3' => __( 'Footer — Enterprise', 'reservechain' ),
				'footer-4' => __( 'Footer — Participation', 'reservechain' ),
				'footer-5' => __( 'Footer — Company', 'reservechain' ),
				'footer-6' => __( 'Footer — Resources', 'reservechain' ),
				'footer-7' => __( 'Footer — Legal', 'reservechain' ),
			)
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'rct-main', RCT_URI . '/assets/css/main.css', array(), RCT_VERSION );
		wp_enqueue_script( 'rct-main', RCT_URI . '/assets/js/main.js', array(), RCT_VERSION, array( 'strategy' => 'defer', 'in_footer' => true ) );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'global-styles' );
		wp_dequeue_style( 'classic-theme-styles' );
		if ( get_query_var( 'rc_passport' ) || is_page( array( 'verification', 'digital-asset-passports' ) ) ) {
			wp_enqueue_script( 'rc-public' );
		}
	}
);

add_action(
	'wp_head',
	static function () {
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>', esc_url( RCT_URI . '/assets/fonts/inter-tight-latin-wght-normal.woff2' ) );
		printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>', esc_url( RCT_URI . '/assets/fonts/fraunces-latin-wght-normal.woff2' ) );
		echo '<meta name="theme-color" content="#050C14">';
		printf( '<link rel="icon" href="%s" type="image/svg+xml">', esc_url( RCT_URI . '/assets/img/favicon.svg' ) );
		printf( '<link rel="apple-touch-icon" href="%s">', esc_url( RCT_URI . '/assets/img/apple-touch-icon.png' ) );
	},
	1
);

// Remove emoji scripts & other front-end noise (performance, CSP).
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );

/**
 * Hidden website sections (CMS → Settings & modules) return 404 and disappear from menus.
 */
add_action(
	'template_redirect',
	static function () {
		if ( ! class_exists( 'RC\\Settings' ) || ! is_page() ) {
			return;
		}
		$path = get_page_uri( get_queried_object_id() );
		if ( ! RC\Settings::section_on( $path ) && ! current_user_can( 'edit_pages' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
		}
	}
);

add_filter(
	'wp_nav_menu_objects',
	static function ( $items ) {
		if ( ! class_exists( 'RC\\Settings' ) ) {
			return $items;
		}
		return array_filter(
			$items,
			static function ( $item ) {
				if ( 'page' !== $item->object ) {
					return true;
				}
				return RC\Settings::section_on( get_page_uri( (int) $item->object_id ) );
			}
		);
	}
);

add_filter(
	'body_class',
	static function ( $classes ) {
		if ( is_page() ) {
			$classes[] = 'page-' . get_post_field( 'post_name', get_queried_object_id() );
		}
		$classes[] = 'lang-' . rct_lang();
		return $classes;
	}
);

/** Page content is authored as HTML sections in the CMS: keep wpautop from breaking the layout markup. */
add_filter(
	'the_content',
	static function ( $content ) {
		if ( is_page() && false !== strpos( $content, 'class="rc-' ) ) {
			remove_filter( 'the_content', 'wpautop' );
		}
		return $content;
	},
	0
);

/** Mandated browser title for the homepage (MASTER p.3). */
add_filter(
	'pre_get_document_title',
	static function ( $title ) {
		return is_front_page() ? 'ReserveChain.io | Building the Infrastructure for Industrial-Metals Tokenization' : $title;
	},
	20
);

add_filter(
	'document_title_parts',
	static function ( $parts ) {
		$parts['site'] = 'ReserveChain.io';
		unset( $parts['tagline'] );
		return $parts;
	}
);
