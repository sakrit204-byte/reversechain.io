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

/** Brand lockup: hexagonal cube (gold top · copper · nickel faces) + ReserveChain.io wordmark. Source: assets/img/logo.svg. */
function rct_logo( bool $wordmark = true ): string {
	static $n = 0;
	$i   = ++$n;
	$svg = '<svg class="rc-logo__mark" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><defs><linearGradient id="rcg' . $i . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F0D59A"/><stop offset=".5" stop-color="#D5B167"/><stop offset="1" stop-color="#9C6E22"/></linearGradient><linearGradient id="rcc' . $i . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E8A06A"/><stop offset="1" stop-color="#8E4A20"/></linearGradient><linearGradient id="rcn' . $i . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E1E9EF"/><stop offset="1" stop-color="#7C8F9E"/></linearGradient></defs><polygon points="24,2.5 42.6,13.25 42.6,34.75 24,45.5 5.4,34.75 5.4,13.25" fill="#0A141F" stroke="url(#rcg' . $i . ')" stroke-width="2"/><polygon points="24,11 35.3,17.5 24,24 12.7,17.5" fill="url(#rcg' . $i . ')"/><polygon points="12.7,17.5 24,24 24,37 12.7,30.5" fill="url(#rcc' . $i . ')"/><polygon points="24,24 35.3,17.5 35.3,30.5 24,37" fill="url(#rcn' . $i . ')"/><g fill="#D5B167"><circle cx="24" cy="2.5" r="1.6"/><circle cx="42.6" cy="13.25" r="1.6"/><circle cx="42.6" cy="34.75" r="1.6"/><circle cx="24" cy="45.5" r="1.6"/><circle cx="5.4" cy="34.75" r="1.6"/><circle cx="5.4" cy="13.25" r="1.6"/></g></svg>';
	return '<span class="rc-logo">' . $svg . ( $wordmark ? '<span class="rc-logo__text"><span class="rc-logo__word">Reserve<b>Chain.io</b></span><span class="rc-logo__tag">' . esc_html__( 'Infrastructure for real-world assets', 'reservechain' ) . '</span></span>' : '' ) . '</span>';
}

function rct_provisional(): string {
	$t = rct_core() ? RC\I18n::t( (string) RC\Settings::get( 'provisional_notice' ) ) : '';
	return $t ? '<div class="rc-provisional" role="note"><strong>' . esc_html__( 'Provisional Asset Notice', 'reservechain' ) . '</strong>' . esc_html( $t ) . '</div>' : '';
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
