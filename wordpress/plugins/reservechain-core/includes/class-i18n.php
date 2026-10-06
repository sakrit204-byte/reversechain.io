<?php
/**
 * Trilingual layer (EN / ES / IT).
 *
 * - Language resolution: ?lang= → cookie → user preference → Accept-Language → English.
 * - Interface strings: every string in the `reservechain` text domain is translated through the
 *   dictionaries in /languages/{lang}.php (gettext filter — no compiled .mo files needed, editors can
 *   review translations as plain PHP arrays). Standard .po/.mo files remain supported by WordPress.
 * - Content: pages and registry records carry per-language title/excerpt/content overrides
 *   (`_rc_i18n_{lang}_title|content|excerpt`) editable in the "Translations" meta box.
 *   Missing translations fall back to English with a visible notice — nothing is machine-invented at runtime.
 * - English is the legally authoritative version of all disclosures.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class I18n {

	public const LANGS = array(
		'en' => array( 'name' => 'English', 'locale' => 'en_GB', 'native' => 'English' ),
		'es' => array( 'name' => 'Spanish', 'locale' => 'es_ES', 'native' => 'Español' ),
		'it' => array( 'name' => 'Italian', 'locale' => 'it_IT', 'native' => 'Italiano' ),
	);

	private static ?string $lang = null;
	private static array $dict   = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'detect' ), 1 );
		add_filter( 'gettext_reservechain', array( __CLASS__, 'gettext' ), 10, 2 );
		add_filter( 'gettext_with_context_reservechain', array( __CLASS__, 'gettext' ), 10, 2 );
		add_filter( 'locale', array( __CLASS__, 'locale' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
		add_filter( 'the_title', array( __CLASS__, 'filter_title' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 1 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 10, 2 );
		add_action( 'rest_api_init', array( __CLASS__, 'detect_rest' ) );
	}

	public static function detect(): void {
		$lang = null;
		if ( isset( $_GET['lang'] ) ) { // phpcs:ignore
			$lang = sanitize_key( $_GET['lang'] ); // phpcs:ignore
			if ( isset( self::LANGS[ $lang ] ) && ! headers_sent() && ! is_admin() ) {
				setcookie( 'rc_lang', $lang, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			}
		}
		if ( ! $lang && isset( $_COOKIE['rc_lang'] ) ) {
			$lang = sanitize_key( $_COOKIE['rc_lang'] );
		}
		if ( ! $lang && ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$lang = substr( strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) ), 0, 2 );
		}
		self::set( $lang );
	}

	public static function detect_rest(): void {
		$p = $_REQUEST['language'] ?? $_REQUEST['lang'] ?? null; // phpcs:ignore
		if ( ! $p ) {
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );
			$p    = is_array( $body ) ? ( $body['language'] ?? null ) : null;
		}
		if ( $p ) {
			self::set( sanitize_key( (string) $p ) );
		}
	}

	public static function set( ?string $lang ): void {
		self::$lang = ( $lang && isset( self::LANGS[ $lang ] ) && ! is_admin() ) ? $lang : 'en';
		self::$dict = array();
		if ( 'en' !== self::$lang ) {
			$file = RC_DIR . 'languages/' . self::$lang . '.php';
			self::$dict = is_readable( $file ) ? (array) include $file : array();
		}
	}

	public static function lang(): string {
		return self::$lang ?? 'en';
	}

	public static function locale( $locale ) {
		return ( self::$lang && 'en' !== self::$lang && ! is_admin() ) ? self::LANGS[ self::$lang ]['locale'] : $locale;
	}

	public static function gettext( $translation, $text ) {
		return self::$dict[ $text ] ?? $translation;
	}

	public static function t( string $text ): string {
		return self::$dict[ $text ] ?? $text;
	}

	public static function url( string $lang, ?string $url = null ): string {
		$url = $url ?? ( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore
		return esc_url( add_query_arg( 'lang', $lang, remove_query_arg( array( 'lang', 'rc_confirm' ), $url ) ) );
	}

	/* ------------------------------------------------------------ content overrides */

	private static function override( int $post_id, string $field ): ?string {
		$lang = self::lang();
		if ( 'en' === $lang || ! $post_id ) {
			return null;
		}
		$v = get_post_meta( $post_id, "_rc_i18n_{$lang}_{$field}", true );
		return '' === $v ? null : $v;
	}

	public static function has_translation( int $post_id ): bool {
		return 'en' === self::lang() || null !== self::override( $post_id, 'content' );
	}

	public static function filter_title( $title, $post_id = 0 ) {
		if ( is_admin() || ! $post_id ) {
			return $title;
		}
		return self::override( (int) $post_id, 'title' ) ?? $title;
	}

	public static function filter_content( $content ) {
		if ( is_admin() ) {
			return $content;
		}
		$id = get_the_ID();
		return $id ? ( self::override( (int) $id, 'content' ) ?? $content ) : $content;
	}

	public static function filter_excerpt( $excerpt, $post = null ) {
		if ( is_admin() || ! $post ) {
			return $excerpt;
		}
		return self::override( (int) $post->ID, 'excerpt' ) ?? $excerpt;
	}

	public static function meta_box(): void {
		foreach ( array_merge( array( 'page' ), Schema::types() ) as $type ) {
			add_meta_box( 'rc_i18n', 'Translations (ES / IT)', array( __CLASS__, 'render_box' ), $type, 'normal', 'default' );
		}
	}

	public static function render_box( \WP_Post $post ): void {
		wp_nonce_field( 'rc_i18n', 'rc_i18n_nonce' );
		echo '<p class="description">English is the authoritative version. Translations go through the same four-eyes workflow — any change resets approval.</p>';
		foreach ( array( 'es', 'it' ) as $lang ) {
			$t = get_post_meta( $post->ID, "_rc_i18n_{$lang}_title", true );
			$c = get_post_meta( $post->ID, "_rc_i18n_{$lang}_content", true );
			$e = get_post_meta( $post->ID, "_rc_i18n_{$lang}_excerpt", true );
			printf( '<details%s><summary><strong>%s</strong> %s</summary>', $c || $t ? '' : ' open', esc_html( self::LANGS[ $lang ]['native'] ), $c ? '<span class="rc-pill rc-pill-verified">translated</span>' : '<span class="rc-pill rc-pill-pending_verification">missing</span>' );
			printf( '<p><label>Title<br><input type="text" class="large-text" name="rc_i18n[%1$s][title]" value="%2$s"></label></p>', esc_attr( $lang ), esc_attr( $t ) );
			printf( '<p><label>Summary<br><textarea class="large-text" rows="2" name="rc_i18n[%1$s][excerpt]">%2$s</textarea></label></p>', esc_attr( $lang ), esc_textarea( $e ) );
			printf( '<p><label>Content (HTML)<br><textarea class="large-text code" rows="10" name="rc_i18n[%1$s][content]">%2$s</textarea></label></p></details>', esc_attr( $lang ), esc_textarea( $c ) );
		}
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['rc_i18n_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rc_i18n_nonce'] ), 'rc_i18n' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$changed = array();
		foreach ( array( 'es', 'it' ) as $lang ) {
			foreach ( array( 'title', 'excerpt', 'content' ) as $f ) {
				$raw = wp_unslash( $_POST['rc_i18n'][ $lang ][ $f ] ?? '' ); // phpcs:ignore
				$val = 'content' === $f ? wp_kses_post( $raw ) : sanitize_textarea_field( $raw );
				$key = "_rc_i18n_{$lang}_{$f}";
				if ( get_post_meta( $post_id, $key, true ) !== $val ) {
					'' === $val ? delete_post_meta( $post_id, $key ) : update_post_meta( $post_id, $key, $val );
					$changed[] = "$lang.$f";
				}
			}
		}
		if ( $changed ) {
			Audit_Log::record( 'content.translation', $post->post_type, $post_id, 'Translations updated: ' . implode( ', ', $changed ) );
		}
	}
}
