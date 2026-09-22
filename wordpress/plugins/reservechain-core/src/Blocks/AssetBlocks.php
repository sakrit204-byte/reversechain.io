<?php
/**
 * Asset-programme page blocks.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Blocks;

use ReserveChain\Core\Content\Publication;

/**
 * The blocks that build an asset-programme page.
 *
 * The Website brief requires each initial programme page to function as "a
 * full asset-program and evidence hub, not a simple marketing product page",
 * and lists twenty-three things it must carry. Most of those are facts about
 * a specific lot or unit, so they are blocks reading the registry rather than
 * text an editor retypes — retyped facts drift from the record, and the brief
 * is explicit that every value shown must originate from one canonical source.
 *
 * Every block below degrades to an honest statement of absence. Where no
 * approved evidence exists, the reader is told that in plain words instead of
 * being shown an empty panel or, worse, a plausible-looking placeholder.
 */
final class AssetBlocks {

	/**
	 * Register the asset-page blocks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ), 20 );
	}

	/**
	 * Block registrations.
	 */
	public static function register_blocks(): void {
		$common = array(
			'api_version' => 3,
			'category'    => 'reservechain',
			'supports'    => array( 'align' => array( 'wide', 'full' ), 'html' => false ),
			'attributes'  => array(
				'programCode' => array( 'type' => 'string', 'default' => '' ),
				'heading'     => array( 'type' => 'string', 'default' => '' ),
			),
		);

		register_block_type(
			'reservechain/program-header',
			$common + array(
				'title'           => __( 'Programme header', 'reservechain' ),
				'description'     => __( 'Programme name, purity with its standard, and the full status row.', 'reservechain' ),
				'icon'            => 'id',
				'render_callback' => array( self::class, 'render_header' ),
			)
		);

		register_block_type(
			'reservechain/program-specifications',
			$common + array(
				'title'           => __( 'Technical specifications', 'reservechain' ),
				'description'     => __( 'The programme specification fields, showing which are supplied and which remain pending.', 'reservechain' ),
				'icon'            => 'editor-table',
				'render_callback' => array( self::class, 'render_specifications' ),
			)
		);

		register_block_type(
			'reservechain/program-certificates',
			$common + array(
				'title'           => __( 'Laboratory certificates', 'reservechain' ),
				'description'     => __( 'Certificates of Analysis with the full measured element table and sampling provenance.', 'reservechain' ),
				'icon'            => 'clipboard',
				'render_callback' => array( self::class, 'render_certificates' ),
			)
		);

		register_block_type(
			'reservechain/program-inventory',
			$common + array(
				'title'           => __( 'Lot and unit inventory', 'reservechain' ),
				'description'     => __( 'Lots and individually identified units, with declared and independently verified quantities kept apart.', 'reservechain' ),
				'icon'            => 'archive',
				'render_callback' => array( self::class, 'render_inventory' ),
			)
		);
	}

	/**
	 * Fetch a programme row by its code.
	 *
	 * @param string $code Programme code.
	 * @return array<string,mixed>|null
	 */
	private static function program( string $code ): ?array {
		global $wpdb;

		if ( '' === $code ) {
			return null;
		}

		$table = $wpdb->prefix . 'rc_asset_programs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE `program_code` = %s", $code ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * A section wrapper with an optional heading.
	 *
	 * @param string $class   Section class.
	 * @param string $heading Heading text.
	 * @param string $inner   Inner HTML.
	 */
	private static function section( string $class, string $heading, string $inner ): string {
		$html = sprintf( '<section class="rc-asset-section %s">', esc_attr( $class ) );

		if ( '' !== $heading ) {
			$html .= sprintf( '<h2 class="rc-asset-section__heading">%s</h2>', esc_html( $heading ) );
		}

		return $html . $inner . '</section>';
	}

	/**
	 * A standard "nothing approved yet" message.
	 *
	 * Saying this plainly is better than an empty panel. A reader who sees
	 * nothing assumes the site is broken; a reader who is told the evidence has
	 * not been supplied learns something true about the project's stage.
	 *
	 * @param string $what Description of the missing material.
	 */
	private static function pending( string $what ): string {
		return sprintf(
			'<p class="rc-asset-empty"><span class="rc-badge rc-badge--pending">%s</span> %s</p>',
			esc_html__( 'Pending', 'reservechain' ),
			esc_html( $what )
		);
	}

	/**
	 * Programme header: identity, purity, status row.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_header( array $attributes ): string {
		$program = self::program( (string) ( $attributes['programCode'] ?? '' ) );

		if ( null === $program ) {
			return '';
		}

		$published = Publication::can_display( $program );

		$html = sprintf(
			'<header class="rc-asset-header%s">',
			$published ? '' : ' rc-illustrative'
		);

		if ( ! $published ) {
			$html .= sprintf(
				'<p class="rc-illustrative__label">%s</p>',
				esc_html__( 'Record under review — not approved for publication', 'reservechain' )
			);
		}

		$html .= sprintf(
			'<h1 class="rc-asset-header__name">%s</h1>',
			esc_html( (string) $program['name'] )
		);

		if ( ! empty( $program['product_name'] ) && $program['product_name'] !== $program['name'] ) {
			$html .= sprintf(
				'<p class="rc-asset-header__product">%s</p>',
				esc_html( (string) $program['product_name'] )
			);
		}

		if ( ! empty( $program['summary'] ) ) {
			$html .= sprintf( '<p class="rc-asset-header__summary">%s</p>', esc_html( (string) $program['summary'] ) );
		}

		// Purity is never shown without the standard it was measured against:
		// a bare percentage is close to meaningless to an industrial buyer.
		$tested   = $program['tested_purity_pct'] ?? null;
		$standard = (string) ( $program['purity_standard'] ?? '' );

		if ( null !== $tested ) {
			$html .= '<div class="rc-asset-header__purity">';
			$html .= sprintf(
				'<span class="rc-asset-header__purity-value">%s%%</span>',
				esc_html( Publication::format_number( (string) $tested ) )
			);
			$html .= sprintf(
				'<span class="rc-asset-header__purity-meta">%s</span>',
				esc_html(
					'' !== $standard
						/* translators: %s: measurement standard reference */
						? sprintf( __( 'Purity as tested, per %s', 'reservechain' ), $standard )
						: __( 'Purity as tested', 'reservechain' )
				)
			);
			$html .= '</div>';
		}

		$html .= '<ul class="rc-asset-header__statuses" role="list">';

		foreach ( array(
			'verification_status' => __( 'Independent verification', 'reservechain' ),
			'custody_status'      => __( 'Custody', 'reservechain' ),
			'reserve_status'      => __( 'Reserve status', 'reservechain' ),
			'tokenization_status' => __( 'Tokenization', 'reservechain' ),
			'redemption_status'   => __( 'Physical redemption', 'reservechain' ),
		) as $field => $label ) {
			$status = Publication::status_display( $program, $field );

			$html .= sprintf(
				'<li><span class="rc-asset-header__status-label">%s</span><span class="rc-badge rc-badge--%s">%s</span></li>',
				esc_html( $label ),
				esc_attr( $status['variant'] ),
				esc_html( $status['label'] )
			);
		}

		$html .= '</ul></header>';

		return $html;
	}

	/**
	 * Technical specifications.
	 *
	 * Renders every field the programme defines, including the ones with no
	 * value. That is deliberate: the brief requires the full specification
	 * structure to exist now so approved data can be added later, and showing
	 * the empty fields makes the shape of what is still missing visible to the
	 * owner and to a reviewer.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_specifications( array $attributes ): string {
		global $wpdb;

		$program = self::program( (string) ( $attributes['programCode'] ?? '' ) );

		if ( null === $program ) {
			return '';
		}

		$fields_table = $wpdb->prefix . 'rc_program_spec_fields';
		$values_table = $wpdb->prefix . 'rc_program_spec_values';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT f.`group_key`, f.`label`, f.`unit`, v.`value_text`, v.`value_number`, v.`source_reference`
				 FROM `{$fields_table}` f
				 LEFT JOIN `{$values_table}` v
				        ON v.`field_id` = f.`id` AND v.`entity_type` = 'program' AND v.`entity_id` = f.`program_id`
				 WHERE f.`program_id` = %d AND f.`is_public` = 1
				 ORDER BY f.`group_key` ASC, f.`sort_order` ASC",
				(int) $program['id']
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return self::section(
				'rc-asset-section--specs',
				(string) ( $attributes['heading'] ?: __( 'Technical specifications', 'reservechain' ) ),
				self::pending( __( 'No specification fields are defined for this programme yet.', 'reservechain' ) )
			);
		}

		$groups = array();

		foreach ( $rows as $row ) {
			$groups[ (string) $row['group_key'] ][] = $row;
		}

		$labels = array(
			'composition' => __( 'Composition and purity', 'reservechain' ),
			'testing'     => __( 'Testing', 'reservechain' ),
			'particle'    => __( 'Particle characteristics', 'reservechain' ),
			'physical'    => __( 'Physical properties', 'reservechain' ),
			'dimensions'  => __( 'Dimensions', 'reservechain' ),
			'mechanical'  => __( 'Mechanical properties', 'reservechain' ),
			'electrical'  => __( 'Electrical and thermal', 'reservechain' ),
			'production'  => __( 'Production', 'reservechain' ),
			'handling'    => __( 'Handling and safety', 'reservechain' ),
			'general'     => __( 'General', 'reservechain' ),
		);

		$supplied = 0;
		$total    = count( $rows );
		$inner    = '';

		foreach ( $groups as $key => $group_rows ) {
			$inner .= sprintf(
				'<h3 class="rc-spec-group">%s</h3><div class="rc-table-scroll"><table class="rc-spec-table"><tbody>',
				esc_html( $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) ) )
			);

			foreach ( $group_rows as $row ) {
				$value = $row['value_text'] ?? null;

				if ( null !== $value && '' !== $value ) {
					$supplied++;
					$display = esc_html( (string) $value );

					if ( ! empty( $row['unit'] ) ) {
						$display .= ' ' . esc_html( (string) $row['unit'] );
					}

					$source = ! empty( $row['source_reference'] )
						? sprintf(
							'<span class="rc-spec-source">%s</span>',
							esc_html( (string) $row['source_reference'] )
						)
						: '';
				} else {
					$display = sprintf(
						'<span class="rc-badge rc-badge--pending">%s</span>',
						esc_html__( 'Pending', 'reservechain' )
					);
					$source  = '';
				}

				$inner .= sprintf(
					'<tr><th scope="row">%s</th><td>%s%s</td></tr>',
					esc_html( (string) $row['label'] ),
					$display,
					$source
				);
			}

			$inner .= '</tbody></table></div>';
		}

		// A completeness line, so a reader can see at a glance how much of the
		// specification is actually backed by supplied documentation.
		$inner .= sprintf(
			'<p class="rc-asset-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of supplied fields, 2: total fields */
					__( '%1$d of %2$d specification fields are populated from supplied documentation. The remainder are pending owner-supplied information and are shown so the outstanding requirements are visible.', 'reservechain' ),
					$supplied,
					$total
				)
			)
		);

		return self::section(
			'rc-asset-section--specs',
			(string) ( $attributes['heading'] ?: __( 'Technical specifications', 'reservechain' ) ),
			$inner
		);
	}

	/**
	 * Laboratory certificates with the full element table.
	 *
	 * Reproducing every measured element, with its detection-limit operator,
	 * is the difference between showing a certificate and asserting a number.
	 * A reader can compare this table against the PDF they were given.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_certificates( array $attributes ): string {
		global $wpdb;

		$program = self::program( (string) ( $attributes['programCode'] ?? '' ) );

		if ( null === $program ) {
			return '';
		}

		$certs_table    = $wpdb->prefix . 'rc_certificates';
		$lots_table     = $wpdb->prefix . 'rc_lots';
		$labs_table     = $wpdb->prefix . 'rc_laboratories';
		$elements_table = $wpdb->prefix . 'rc_certificate_elements';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$certificates = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT c.*, l.`lot_number`, lab.`name` AS lab_name, lab.`city` AS lab_city,
				        lab.`country_code` AS lab_country, lab.`accreditation_verified`
				 FROM `{$certs_table}` c
				 JOIN `{$lots_table}` l ON l.`id` = c.`lot_id`
				 LEFT JOIN `{$labs_table}` lab ON lab.`id` = c.`laboratory_id`
				 WHERE l.`program_id` = %d
				 ORDER BY c.`certificate_date` DESC",
				(int) $program['id']
			),
			ARRAY_A
		);

		$heading = (string) ( $attributes['heading'] ?: __( 'Laboratory certificates', 'reservechain' ) );

		if ( empty( $certificates ) ) {
			return self::section(
				'rc-asset-section--certs',
				$heading,
				self::pending( __( 'No laboratory certificate has been supplied for this programme.', 'reservechain' ) )
			);
		}

		$inner = '';

		foreach ( $certificates as $cert ) {
			$approved = Publication::can_display( $cert );

			$inner .= sprintf( '<article class="rc-certificate%s">', $approved ? '' : ' rc-illustrative' );

			if ( ! $approved ) {
				$inner .= sprintf(
					'<p class="rc-illustrative__label">%s</p>',
					esc_html__( 'Supplied — under review, not approved for publication', 'reservechain' )
				);
			}

			$inner .= sprintf(
				'<h3 class="rc-certificate__title">%s</h3>',
				esc_html(
					sprintf(
						/* translators: %s: certificate number */
						__( 'Certificate of Analysis No. %s', 'reservechain' ),
						(string) $cert['certificate_number']
					)
				)
			);

			// Provenance grid: who tested what, when, from which units.
			$meta = array(
				__( 'Laboratory', 'reservechain' )    => (string) ( $cert['lab_name'] ?? '' ),
				__( 'Certificate date', 'reservechain' ) => (string) ( $cert['certificate_date'] ?? '' ),
				__( 'Sampled on', 'reservechain' )    => (string) ( $cert['sample_date'] ?? '' ),
				__( 'Method', 'reservechain' )        => (string) ( $cert['test_method'] ?? '' ),
				__( 'Standard', 'reservechain' )      => (string) ( $cert['standard_reference'] ?? '' ),
				__( 'Lot', 'reservechain' )           => (string) ( $cert['lot_number'] ?? '' ),
				__( 'Units sampled', 'reservechain' ) => (string) ( $cert['sampled_units'] ?? '' ),
				__( 'Sampling location', 'reservechain' ) => (string) ( $cert['sample_location'] ?? '' ),
			);

			$inner .= '<dl class="rc-certificate__meta">';

			foreach ( $meta as $label => $value ) {
				if ( '' === $value ) {
					continue;
				}

				$inner .= sprintf(
					'<div><dt>%s</dt><dd>%s</dd></div>',
					esc_html( (string) $label ),
					esc_html( $value )
				);
			}

			$inner .= '</dl>';

			// Accreditation is stated only when evidenced. [M§3]
			if ( empty( $cert['accreditation_verified'] ) && ! empty( $cert['lab_name'] ) ) {
				$inner .= sprintf(
					'<p class="rc-asset-note">%s</p>',
					esc_html__( 'The laboratory is named on the certificate. Evidence of its accreditation has not been supplied, so no accreditation is claimed here.', 'reservechain' )
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$elements = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM `{$elements_table}` WHERE `certificate_id` = %d ORDER BY `element` ASC", (int) $cert['id'] ),
				ARRAY_A
			);

			if ( ! empty( $elements ) ) {
				$inner .= '<div class="rc-table-scroll"><table class="rc-element-table">';
				$inner .= sprintf(
					'<caption>%s</caption>',
					esc_html(
						sprintf(
							/* translators: %d: number of measured elements */
							__( 'Measured element concentrations (%d elements, values in ppm)', 'reservechain' ),
							count( $elements )
						)
					)
				);
				$inner .= sprintf(
					'<thead><tr><th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>',
					esc_html__( 'Element', 'reservechain' ),
					esc_html__( 'Measured value (ppm)', 'reservechain' )
				);

				foreach ( $elements as $element ) {
					if ( ! empty( $element['is_matrix'] ) ) {
						$value = sprintf( '<em>%s</em>', esc_html__( 'Matrix', 'reservechain' ) );
					} elseif ( null === $element['value_ppm'] ) {
						$value = esc_html__( 'Not reported', 'reservechain' );
					} else {
						$operator = '=' === $element['operator'] ? '' : (string) $element['operator'] . ' ';
						$value    = esc_html( $operator . Publication::format_number( (string) $element['value_ppm'] ) );
					}

					$inner .= sprintf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html( (string) $element['element'] ),
						$value
					);
				}

				$inner .= '</tbody></table></div>';
			}

			if ( ! empty( $cert['purity_pct'] ) ) {
				$inner .= sprintf(
					'<p class="rc-certificate__purity">%s <strong>%s%%</strong></p>',
					esc_html__( 'Stated purity:', 'reservechain' ),
					esc_html( Publication::format_number( (string) $cert['purity_pct'] ) )
				);
			}

			if ( ! empty( $cert['additional_findings'] ) ) {
				$inner .= sprintf(
					'<p class="rc-certificate__findings">%s</p>',
					esc_html( (string) $cert['additional_findings'] )
				);
			}

			// Certificate age drives the stale-attestation control, and the
			// reader deserves to know it without doing the arithmetic.
			if ( ! empty( $cert['certificate_date'] ) ) {
				$age_days = (int) floor( ( time() - strtotime( (string) $cert['certificate_date'] ) ) / DAY_IN_SECONDS );

				if ( $age_days > 365 ) {
					$inner .= sprintf(
						'<p class="rc-asset-note rc-asset-note--attention">%s</p>',
						esc_html(
							sprintf(
								/* translators: %d: age of the certificate in years */
								_n(
									'This certificate is approximately %d year old. Re-verification is a matter for the project owner and appointed laboratory; no current independent verification is claimed.',
									'This certificate is approximately %d years old. Re-verification is a matter for the project owner and appointed laboratory; no current independent verification is claimed.',
									(int) round( $age_days / 365 ),
									'reservechain'
								),
								(int) round( $age_days / 365 )
							)
						)
					);
				}
			}

			$inner .= '</article>';
		}

		return self::section( 'rc-asset-section--certs', $heading, $inner );
	}

	/**
	 * Lots and their individually identified units.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public static function render_inventory( array $attributes ): string {
		global $wpdb;

		$program = self::program( (string) ( $attributes['programCode'] ?? '' ) );

		if ( null === $program ) {
			return '';
		}

		$lots_table  = $wpdb->prefix . 'rc_lots';
		$units_table = $wpdb->prefix . 'rc_units';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$lots = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$lots_table}` WHERE `program_id` = %d ORDER BY `lot_number` ASC", (int) $program['id'] ),
			ARRAY_A
		);

		$heading = (string) ( $attributes['heading'] ?: __( 'Lots and units', 'reservechain' ) );

		if ( empty( $lots ) ) {
			return self::section(
				'rc-asset-section--inventory',
				$heading,
				self::pending( __( 'No lot has been recorded for this programme.', 'reservechain' ) )
			);
		}

		$inner = '';

		foreach ( $lots as $lot ) {
			$approved = Publication::can_display( $lot );

			$inner .= sprintf( '<article class="rc-lot%s">', $approved ? '' : ' rc-illustrative' );

			if ( ! $approved ) {
				$inner .= sprintf(
					'<p class="rc-illustrative__label">%s</p>',
					esc_html__( 'Under review — not approved for publication', 'reservechain' )
				);
			}

			$inner .= sprintf(
				'<h3 class="rc-lot__title">%s</h3>',
				esc_html(
					sprintf(
						/* translators: %s: lot or batch number */
						__( 'Lot %s', 'reservechain' ),
						(string) $lot['lot_number']
					)
				)
			);

			// The declared-versus-verified distinction, rendered explicitly.
			$quantity = Publication::quantity_display(
				$lot,
				'declared_net_weight',
				'verified_net_weight',
				(string) ( $lot['weight_unit'] ?? '' )
			);

			$inner .= sprintf(
				'<div class="rc-figure rc-figure--%s"><span class="rc-figure__label">%s</span><span class="rc-figure__value">%s</span><span class="rc-figure__qualifier">%s</span></div>',
				$quantity['verified'] ? 'verified' : 'declared',
				esc_html__( 'Net weight', 'reservechain' ),
				esc_html( $quantity['value'] ),
				esc_html( $quantity['qualifier'] )
			);

			if ( ! empty( $lot['quantity_source'] ) ) {
				$inner .= sprintf(
					'<p class="rc-asset-note">%s</p>',
					esc_html( (string) $lot['quantity_source'] )
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$units = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM `{$units_table}` WHERE `lot_id` = %d ORDER BY `sequence_no` ASC, `identifier` ASC", (int) $lot['id'] ),
				ARRAY_A
			);

			$declared_units = $lot['unit_count'];
			$recorded_units = count( $units );

			$inner .= '<div class="rc-lot__units">';
			$inner .= sprintf(
				'<p class="rc-asset-note">%s</p>',
				esc_html(
					null === $declared_units
						? sprintf(
							/* translators: %d: number of units recorded */
							__( '%d individually identified unit is recorded. The total number of units in this lot is not stated in the supplied documentation, so no total is shown.', 'reservechain' ),
							$recorded_units
						)
						: sprintf(
							/* translators: 1: units recorded, 2: units declared */
							__( '%1$d of %2$d declared units are individually recorded.', 'reservechain' ),
							$recorded_units,
							(int) $declared_units
						)
				)
			);

			if ( ! empty( $units ) ) {
				$sampled = array_filter( $units, static fn ( array $u ): bool => ! empty( $u['was_sampled'] ) );

				$inner .= '<div class="rc-table-scroll"><table class="rc-unit-table"><thead><tr>';
				$inner .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Unit', 'reservechain' ) );
				$inner .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Type', 'reservechain' ) );
				$inner .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Sampled', 'reservechain' ) );
				$inner .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Reserve', 'reservechain' ) );
				$inner .= sprintf( '<th scope="col">%s</th>', esc_html__( 'Redemption', 'reservechain' ) );
				$inner .= '</tr></thead><tbody>';

				foreach ( $units as $unit ) {
					$reserve    = Publication::status_display( $unit, 'reserve_status' );
					$redemption = Publication::status_display( $unit, 'redemption_status' );

					$inner .= sprintf(
						'<tr><th scope="row">%s</th><td>%s</td><td>%s</td><td><span class="rc-badge rc-badge--%s">%s</span></td><td><span class="rc-badge rc-badge--%s">%s</span></td></tr>',
						esc_html( (string) $unit['identifier'] ),
						esc_html( ucfirst( (string) $unit['kind'] ) ),
						empty( $unit['was_sampled'] )
							? esc_html__( '—', 'reservechain' )
							: esc_html( (string) ( $unit['sampled_on'] ?? __( 'Yes', 'reservechain' ) ) ),
						esc_attr( $reserve['variant'] ),
						esc_html( $reserve['label'] ),
						esc_attr( $redemption['variant'] ),
						esc_html( $redemption['label'] )
					);
				}

				$inner .= '</tbody></table></div>';

				if ( ! empty( $sampled ) ) {
					$inner .= sprintf(
						'<p class="rc-asset-note">%s</p>',
						esc_html(
							sprintf(
								/* translators: %d: number of units sampled */
								_n(
									'%d unit was sampled for laboratory analysis. Results describe the sampled material; they are not a measurement of every unit.',
									'%d units were sampled for laboratory analysis. Results describe the sampled material; they are not a measurement of every unit.',
									count( $sampled ),
									'reservechain'
								),
								count( $sampled )
							)
						)
					);
				}
			}

			$inner .= '</div></article>';
		}

		return self::section( 'rc-asset-section--inventory', $heading, $inner );
	}
}
