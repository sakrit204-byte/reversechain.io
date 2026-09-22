<?php
/**
 * ReserveChain theme bootstrap.
 *
 * @package ReserveChain\Theme
 *
 * ---------------------------------------------------------------------------
 * The theme renders. It never decides what may be shown.
 *
 * Publication gating — whether a custody claim, laboratory result, reserve
 * figure or token status is allowed on a public page — belongs to the
 * reservechain-core plugin, because the website, the REST API and the mobile
 * applications must all reach the same answer. A template that made its own
 * judgement would be a way for one surface to disagree with the others.
 * ---------------------------------------------------------------------------
 */

declare( strict_types = 1 );

namespace ReserveChain\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

/**
 * Theme supports.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );

		// Alternative text and captions are mandatory on asset imagery, so the
		// editor should never strip them.
		add_theme_support( 'align-wide' );

		register_nav_menus(
			array(
				'primary' => __( 'Primary navigation', 'reservechain' ),
				'footer'  => __( 'Footer navigation', 'reservechain' ),
				'legal'   => __( 'Legal navigation', 'reservechain' ),
			)
		);
	}
);

/**
 * Front-end assets.
 *
 * Built by Vite into assets/build/. The manifest is read at runtime so hashed
 * filenames stay cache-busted without hard-coding a version string.
 */
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		$theme_uri = get_stylesheet_directory_uri();
		$theme_dir = get_stylesheet_directory();

		wp_enqueue_style(
			'reservechain',
			$theme_uri . '/style.css',
			array(),
			(string) filemtime( $theme_dir . '/style.css' )
		);

		// Components are a separate layer: the base stylesheet is stable, the
		// component layer changes with every new page, and reviewing them
		// together makes both harder to follow.
		wp_enqueue_style(
			'reservechain-components',
			$theme_uri . '/assets/css/components.css',
			array( 'reservechain' ),
			(string) filemtime( $theme_dir . '/assets/css/components.css' )
		);

		$manifest_path = $theme_dir . '/assets/build/.vite/manifest.json';

		if ( ! is_readable( $manifest_path ) ) {
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );

		if ( ! is_array( $manifest ) ) {
			return;
		}

		$entry = $manifest['src/main.ts'] ?? null;

		if ( ! is_array( $entry ) ) {
			return;
		}

		foreach ( (array) ( $entry['css'] ?? array() ) as $index => $css ) {
			wp_enqueue_style(
				'reservechain-app-' . $index,
				$theme_uri . '/assets/build/' . $css,
				array( 'reservechain' ),
				VERSION
			);
		}

		if ( ! empty( $entry['file'] ) ) {
			wp_enqueue_script(
				'reservechain-app',
				$theme_uri . '/assets/build/' . $entry['file'],
				array(),
				VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}
	}
);

/**
 * Load the compiled bundle as an ES module.
 *
 * Vite emits modern ESM; WordPress still writes a classic script tag.
 */
add_filter(
	'script_loader_tag',
	static function ( string $tag, string $handle ): string {
		if ( 'reservechain-app' !== $handle ) {
			return $tag;
		}

		return str_replace( '<script ', '<script type="module" ', $tag );
	},
	10,
	2
);

/**
 * Security response headers.
 *
 * The brief names HTTPS, HSTS, Content Security Policy, secure response
 * headers and XSS/CSRF protection. Headers are set here rather than in Apache
 * config so they travel with the codebase and survive a change of host, which
 * the handover terms make likely.
 *
 * HSTS is emitted only over HTTPS: sending it over plain HTTP is meaningless
 * and would break local development.
 */
add_action(
	'send_headers',
	static function (): void {
		if ( is_admin() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header(
			'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()'
		);

		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );
		}
	}
);

/**
 * Keep pre-launch content out of search engines.
 *
 * Draft, under-review, approved-but-unpublished and archived records are
 * already excluded from public queries by their post-status registration. This
 * covers the separate requirement to prevent indexing of the staging site and
 * of any module that is built but not yet authorised. [M§19]
 */
add_action(
	'wp_head',
	static function (): void {
		$environment = defined( 'RC_ENV' ) ? (string) RC_ENV : wp_get_environment_type();

		if ( 'production' !== $environment ) {
			echo '<meta name="robots" content="noindex, nofollow, noarchive" />' . "\n";
			return;
		}

		if ( is_404() || is_search() ) {
			echo '<meta name="robots" content="noindex, follow" />' . "\n";
		}
	},
	1
);

/**
 * Remove version fingerprints and unused endpoints.
 */
add_action(
	'init',
	static function (): void {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );

		// XML-RPC is not used and is a standing brute-force target.
		add_filter( 'xmlrpc_enabled', '__return_false' );
	}
);

/**
 * Editor styles so the block editor matches the front end.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		add_editor_style( array( 'style.css', 'assets/css/components.css' ) );
	}
);

/**
 * Register the theme's block pattern category.
 */
add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return;
		}

		register_block_pattern_category(
			'reservechain',
			array( 'label' => __( 'ReserveChain', 'reservechain' ) )
		);
	}
);
