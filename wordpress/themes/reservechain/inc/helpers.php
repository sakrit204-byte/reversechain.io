<?php
/**
 * Theme helpers. Every helper degrades gracefully if the Core plugin is inactive.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;

function rct_core(): bool {
	return class_exists( 'RC\\Settings' );
}

function rct_lang(): string {
	return rct_core() ? RC\I18n::lang() : 'en';
}

function rct_disclosure(): string {
	if ( ! rct_core() ) {
		return 'ReserveChain is currently in development. No tokens are being offered or sold through this website.';
	}
	return RC\I18n::t( RC\Settings::get( 'disclosure' ) );
}

function rct_eu_notice(): string {
	return rct_core() ? RC\I18n::t( RC\Settings::get( 'eu_notice' ) ) : '';
}

function rct_pill( string $status, ?string $label = null ): string {
	return rct_core() ? RC\Shortcodes::pill( $status, $label ) : '';
}

/** Brand mark: two element tiles (Cu · Ni) forming the RC monogram. */
function rct_logo( bool $wordmark = true ): string {
	$svg = '<svg class="rc-logo__mark" viewBox="0 0 40 40" aria-hidden="true" focusable="false"><rect x="1" y="1" width="18" height="38" rx="3" fill="var(--copper)"/><rect x="21" y="1" width="18" height="38" rx="3" fill="var(--nickel)"/><text x="10" y="25" text-anchor="middle" font-family="IBM Plex Mono, monospace" font-size="11" font-weight="500" fill="#0B0F14">Cu</text><text x="30" y="25" text-anchor="middle" font-family="IBM Plex Mono, monospace" font-size="11" font-weight="500" fill="#0B0F14">Ni</text></svg>';
	return '<span class="rc-logo">' . $svg . ( $wordmark ? '<span class="rc-logo__word">Reserve<b>Chain</b></span>' : '' ) . '</span>';
}

function rct_lang_switcher(): string {
	if ( ! rct_core() ) {
		return '';
	}
	$out = '<nav class="rc-lang" aria-label="' . esc_attr__( 'Language', 'reservechain' ) . '">';
	foreach ( RC\I18n::LANGS as $code => $l ) {
		$out .= sprintf( '<a href="%s" hreflang="%s" lang="%s"%s>%s</a>', RC\I18n::url( $code ), esc_attr( $code ), esc_attr( $code ), $code === rct_lang() ? ' aria-current="true"' : '', esc_html( strtoupper( $code ) ) );
	}
	return $out . '</nav>';
}

function rct_breadcrumbs(): string {
	if ( is_front_page() ) {
		return '';
	}
	$items = array( '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'reservechain' ) . '</a>' );
	if ( is_page() ) {
		foreach ( array_reverse( get_post_ancestors( get_queried_object_id() ) ) as $a ) {
			$items[] = '<a href="' . esc_url( get_permalink( $a ) ) . '">' . esc_html( get_the_title( $a ) ) . '</a>';
		}
		$items[] = '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
	}
	return '<nav class="rc-crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'reservechain' ) . '">' . implode( '<span aria-hidden="true">/</span>', $items ) . '</nav>';
}

/** Compact live integrity badge for the footer — proves the audit chain is real. */
function rct_audit_badge(): string {
	if ( ! rct_core() ) {
		return '';
	}
	$h    = RC\Audit_Log::head();
	$last = get_option( 'rc_audit_last_verify' );
	$ok   = $last && ! empty( $last['ok'] );
	return sprintf(
		'<a class="rc-chainbadge%s" href="%s"><i aria-hidden="true"></i><span>%s <b>#%d</b></span><code>%s…</code></a>',
		$ok ? ' is-ok' : '',
		esc_url( home_url( '/governance/#audit' ) ),
		esc_html__( 'Audit chain', 'reservechain' ),
		(int) $h['seq'],
		esc_html( substr( $h['chain_head'], 0, 10 ) )
	);
}

function rct_site_mode(): string {
	return rct_core() ? (string) RC\Settings::get( 'site_mode' ) : 'prelaunch';
}

/** Translate dynamic labels coming from the registry schema. */
function rct__( string $text ): string {
	return rct_core() ? RC\I18n::t( $text ) : $text;
}
