<?php
/**
 * Server-rendered blocks.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Blocks;

use ReserveChain\Core\Content\Publication;

/**
 * Dynamic blocks for registry-backed content.
 *
 * Every block here renders on the server, which is the point. A client-side
 * block would have to be handed the underlying record to render it, and that
 * record contains draft statuses, unapproved custody claims and internal
 * notes. Rendering in PHP means the publication gate runs before anything is
 * serialised, so an unapproved value never reaches the browser at all — not
 * hidden with CSS, not sitting in a JSON island, simply absent.
 *
 * The blocks are also the reason the client can rebuild pages without us. Each
 * one takes attributes an editor can set in the block inspector and pulls its
 * facts from the registry, so moving a programme card or adding a reserve
 * summary to a new page is an editorial action, not a deployment.
 */
final class Blocks {

	/**
	 * Register every block.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ), 20 );
	}

	/**
	 * Block registrations.
	 */
	public static function register_blocks(): void {
		register_block_type(
			'reservechain/disclosure',
			array(
				'api_version'     => 3,
				'title'           => __( 'Pre-launch disclosure', 'reservechain' ),
				'description'     => __( 'The mandatory no-offer disclosure, pulled from the versioned legal notice so legal review can revise it without a deployment.', 'reservechain' ),
				'category'        => 'reservechain',
				'icon'            => 'shield',
				'attributes'      => array(
					'noticeKey' => array( 'type' => 'string', 'default' => 'no_offer_disclosure' ),
					'variant'   => array( 'type' => 'string', 'default' => 'panel' ),
					'showTitle' => array( 'type' => 'boolean', 'default' => false ),
				),
				'render_callback' => array( self::class, 'render_disclosure' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ), 'html' => false ),
			)
		);

		register_block_type(
			'reservechain/program-card',
			array(
				'api_version'     => 3,
				'title'           => __( 'Asset programme card', 'reservechain' ),
				'description'     => __( 'A programme summary with status badges that never overstate what the evidence supports.', 'reservechain' ),
				'category'        => 'reservechain',
				'icon'            => 'archive',
				'attributes'      => array(
					'programCode' => array( 'type' => 'string', 'default' => '' ),
					'accent'      => array( 'type' => 'string', 'default' => 'gold' ),
					'showSpecs'   => array( 'type' => 'boolean', 'default' => true ),
				),
				'render_callback' => array( self::class, 'render_program_card' ),
				'supports'        => array( 'align' => array( 'wide' ), 'html' => false ),
			)
		);

		register_block_type(
			'reservechain/registry-summary',
			array(
				'api_version'     => 3,
				'title'           => __( 'Registry summary', 'reservechain' ),
				'description'     => __( 'Counts drawn from the live registry. Shows nothing rather than a placeholder figure when no approved data exists.', 'reservechain' ),
				'category'        => 'reservechain',
				'icon'            => 'chart-bar',
				'attributes'      => array(
					'heading' => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => array( self::class, 'render_registry_summary' ),
				'supports'        => array( 'align' => array( 'wide', 'full' ), 'html' => false ),
			)
		);

		register_block_type(
			'reservechain/pending-notice',
			array(
				'api_version'     => 3,
				'title'           => __( 'Provisional information notice', 'reservechain' ),
				'description'     => __( 'The required notice for any section presenting provisional or illustrative information.', 'reservechain' ),
				'category'        => 'reservechain',
				'icon'            => 'info',
				'attributes'      => array(
					'context' => array( 'type' => 'string', 'default' => '' ),
				),
				'render_callback' => array( self::class, 'render_pending_notice' ),
				'supports'        => array( 'align' => array( 'wide' ), 'html' => false ),
			)
		);
	}

	/**
	 * Render the mandatory disclosure.
	 *
	 * If the notice is missing, this fails loudly in the admin and renders a
	 * conspicuous placeholder on the front end rather than silently omitting
	 * it. A disclosure that disappears because of a data problem is the worst
	 * possible failure mode for a pre-launch platform.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_disclosure( array $attributes ): string {
		$notice = Publication::legal_notice( (string) ( $attributes['noticeKey'] ?? 'no_offer_disclosure' ) );

		if ( null === $notice ) {
			return sprintf(
				'<aside class="rc-disclosure rc-disclosure--missing" role="alert"><p><strong>%s</strong></p></aside>',
				esc_html__( 'Required disclosure is not configured. Publish the pre-launch disclosure in Legal Notices before this page goes live.', 'reservechain' )
			);
		}

		$variant = 'inline' === ( $attributes['variant'] ?? 'panel' ) ? 'inline' : 'panel';

		$html  = sprintf(
			'<aside class="rc-disclosure rc-disclosure--%s" role="note" aria-label="%s" data-notice-version="%d">',
			esc_attr( $variant ),
			esc_attr__( 'Pre-launch disclosure', 'reservechain' ),
			(int) $notice['version']
		);

		if ( ! empty( $attributes['showTitle'] ) ) {
			$html .= sprintf( '<h2 class="rc-disclosure__title">%s</h2>', esc_html( $notice['title'] ) );
		}

		$html .= sprintf( '<p class="rc-disclosure__body">%s</p>', esc_html( $notice['body'] ) );
		$html .= '</aside>';

		return $html;
	}

	/**
	 * Render an asset-programme card.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_program_card( array $attributes ): string {
		global $wpdb;

		$code = (string) ( $attributes['programCode'] ?? '' );

		if ( '' === $code ) {
			return '';
		}

		$table = $wpdb->prefix . 'rc_asset_programs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$program = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `program_code` = %s", $code ),
			ARRAY_A
		);

		if ( null === $program ) {
			return '';
		}

		$accent   = in_array( (string) ( $attributes['accent'] ?? 'gold' ), array( 'gold', 'copper', 'nickel' ), true )
			? (string) $attributes['accent']
			: 'gold';
		$is_public = Publication::can_display( $program );

		$html = sprintf(
			'<article class="rc-program-card rc-program-card--%s%s">',
			esc_attr( $accent ),
			$is_public ? '' : ' rc-illustrative'
		);

		if ( ! $is_public ) {
			$html .= sprintf(
				'<p class="rc-illustrative__label">%s</p>',
				esc_html__( 'Illustrative — pending approval', 'reservechain' )
			);
		}

		$html .= sprintf(
			'<h3 class="rc-program-card__name">%s</h3>',
			esc_html( (string) $program['name'] )
		);

		if ( ! empty( $program['summary'] ) ) {
			$html .= sprintf( '<p class="rc-program-card__summary">%s</p>', esc_html( (string) $program['summary'] ) );
		}

		// Status row. Every badge routes through the publication gate, so a
		// value sitting in the database as "in_custody" still renders as
		// "Pending" unless the record is approved for publication.
		$html .= '<ul class="rc-program-card__statuses" role="list">';

		foreach ( array(
			'verification_status' => __( 'Verification', 'reservechain' ),
			'custody_status'      => __( 'Custody', 'reservechain' ),
			'reserve_status'      => __( 'Reserves', 'reservechain' ),
			'tokenization_status' => __( 'Tokenization', 'reservechain' ),
			'redemption_status'   => __( 'Redemption', 'reservechain' ),
		) as $field => $label ) {
			$status = Publication::status_display( $program, $field );

			$html .= sprintf(
				'<li class="rc-program-card__status"><span class="rc-program-card__status-label">%s</span><span class="rc-badge rc-badge--%s">%s</span></li>',
				esc_html( $label ),
				esc_attr( $status['variant'] ),
				esc_html( $status['label'] )
			);
		}

		$html .= '</ul>';

		if ( ! empty( $attributes['showSpecs'] ) ) {
			$html .= self::render_program_specs( $program );
		}

		$html .= '</article>';

		return $html;
	}

	/**
	 * Purity and standard, with provenance.
	 *
	 * A purity figure without the standard it was measured against is close to
	 * meaningless to an industrial buyer, so the two are never separated.
	 *
	 * @param array<string,mixed> $program Programme row.
	 */
	private static function render_program_specs( array $program ): string {
		$tested   = $program['tested_purity_pct'] ?? null;
		$declared = $program['declared_purity_pct'] ?? null;

		if ( null === $tested && null === $declared ) {
			return sprintf(
				'<p class="rc-program-card__specs rc-program-card__specs--pending">%s</p>',
				esc_html__( 'Technical specifications pending supplied documentation.', 'reservechain' )
			);
		}

		$value = Publication::format_number( (string) ( $tested ?? $declared ) );

		$html = '<dl class="rc-program-card__specs">';
		$html .= sprintf(
			'<div class="rc-figure rc-figure--%s"><dt>%s</dt><dd class="rc-figure__value">%s%%</dd></div>',
			null !== $tested ? 'verified' : 'declared',
			esc_html__( 'Purity', 'reservechain' ),
			esc_html( $value )
		);

		if ( ! empty( $program['purity_standard'] ) ) {
			$html .= sprintf(
				'<div><dt>%s</dt><dd>%s</dd></div>',
				esc_html__( 'Standard', 'reservechain' ),
				esc_html( (string) $program['purity_standard'] )
			);
		}

		$html .= '</dl>';

		return $html;
	}

	/**
	 * Render live registry counts.
	 *
	 * Counts only, and only of records that exist. No tonnage, no valuation, no
	 * reserve coverage: those are claims about physical material and money, and
	 * none of them has been approved for publication. Publishing a count of
	 * records is a statement about the platform, which is true.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_registry_summary( array $attributes ): string {
		global $wpdb;

		$prefix = $wpdb->prefix;

		$count = static function ( string $table, string $where = '1=1' ) use ( $wpdb, $prefix ): int {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$prefix}{$table}` WHERE {$where}" );
		};

		$metrics = array(
			array(
				'label' => __( 'Asset programmes in preparation', 'reservechain' ),
				'value' => $count( 'rc_asset_programs', "`is_future_category` = 0 AND `program_code` <> 'RC-ILLUSTRATIVE'" ),
			),
			array(
				'label' => __( 'Lots recorded', 'reservechain' ),
				'value' => $count( 'rc_lots' ),
			),
			array(
				'label' => __( 'Individually identified units', 'reservechain' ),
				'value' => $count( 'rc_units' ),
			),
			array(
				'label' => __( 'Laboratory certificates on file', 'reservechain' ),
				'value' => $count( 'rc_certificates' ),
			),
		);

		$heading = (string) ( $attributes['heading'] ?? '' );

		$html = '<section class="rc-registry-summary">';

		if ( '' !== $heading ) {
			$html .= sprintf( '<h2 class="rc-registry-summary__heading">%s</h2>', esc_html( $heading ) );
		}

		$html .= '<dl class="rc-registry-summary__grid">';

		foreach ( $metrics as $metric ) {
			$html .= sprintf(
				'<div class="rc-metric"><dt class="rc-metric__label">%s</dt><dd class="rc-metric__value">%s</dd></div>',
				esc_html( $metric['label'] ),
				esc_html( number_format_i18n( $metric['value'] ) )
			);
		}

		$html .= '</dl>';

		$html .= sprintf(
			'<p class="rc-registry-summary__note">%s</p>',
			esc_html__( 'Counts describe records held in the platform registry. They are not a statement of quantities held, reserve coverage or asset value, none of which has been approved for publication.', 'reservechain' )
		);

		$html .= '</section>';

		return $html;
	}

	/**
	 * The required provisional-information notice. [M§3]
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_pending_notice( array $attributes ): string {
		$context = trim( (string) ( $attributes['context'] ?? '' ) );

		return sprintf(
			'<aside class="rc-pending-notice" role="note"><p><strong>%s</strong> %s%s</p></aside>',
			esc_html__( 'Preliminary illustrative information only', 'reservechain' ),
			esc_html__( 'Subject to documentary verification, independent assessment and final approval. No asset or token is currently offered for sale through this website.', 'reservechain' ),
			'' === $context ? '' : ' ' . esc_html( $context )
		);
	}
}
