<?php
/**
 * Registry seeding: supplied evidence and the illustrative public template.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Seed;

use ReserveChain\Core\Plugin;

/**
 * Loads registry content.
 *
 * Two clearly separated bodies of data, because conflating them is the single
 * biggest way this platform could mislead someone:
 *
 *  1. SUPPLIED EVIDENCE — the two Certificates of Analysis the project owner
 *     attached to the brief. These are real documents describing real
 *     material, so they are loaded as real registry records. They are created
 *     in `under_review`, never `published`, because [M§9] requires that
 *     "all ownership, laboratory, custody, insurance and reserve claims must
 *     remain unpublished until the supporting evidence has been supplied and
 *     approved" — supplied is not the same as approved for publication.
 *
 *  2. ILLUSTRATIVE TEMPLATE — the placeholder specified in [M§13], with every
 *     substantive field reading "Pending". This is what the public site shows
 *     until approved data exists, and it is permanently marked illustrative.
 *
 * What this seeder deliberately does not do
 * -----------------------------------------
 * It does not invent a single quantity, container, custodian, insurer,
 * valuation, token price or contract address. Where the certificates are
 * silent, the field stays NULL and the reconciliation engine reports it as an
 * open item. The clearest example is the copper lot: the certificate states
 * 2000 kg in glass ampoules packed in cardboard boxes, and evidences exactly
 * one individually identified unit — box 20, the box that was sampled. So one
 * unit is created, not an invented inventory of boxes. The nickel certificate
 * does state "30 bobbins in 1 box", so thirty bobbin units are created, with
 * the four that were sampled flagged as such.
 */
final class RegistrySeeder {

	/**
	 * Copper Powder — Certificate of Analysis No. 0004512, 04.07.2022.
	 * Measured with ICP/OES. Values in ppm; '<' marks a detection-limit bound.
	 *
	 * @return array<string,array{0:string,1:float}>
	 */
	private const COPPER_ELEMENTS = array(
		'Ag' => array( '=', 8.0 ),   'Al' => array( '<', 1.0 ),   'As' => array( '=', 4.0 ),
		'Au' => array( '<', 1.0 ),   'B'  => array( '<', 1.0 ),   'Ba' => array( '<', 1.0 ),
		'Bi' => array( '<', 0.5 ),   'Ca' => array( '<', 1.0 ),   'Cd' => array( '<', 0.5 ),
		'Co' => array( '<', 0.5 ),   'Cr' => array( '<', 0.5 ),   'Fe' => array( '<', 1.0 ),
		'Hg' => array( '<', 1.0 ),   'K'  => array( '<', 1.0 ),   'Li' => array( '<', 1.0 ),
		'Mg' => array( '<', 1.0 ),   'Mn' => array( '<', 0.5 ),   'Mo' => array( '<', 0.5 ),
		'Na' => array( '<', 1.0 ),   'Ni' => array( '<', 0.5 ),   'P'  => array( '=', 5.0 ),
		'Pb' => array( '=', 1.0 ),   'S'  => array( '=', 16.0 ),  'Sb' => array( '=', 1.0 ),
		'Sn' => array( '<', 0.5 ),   'Sr' => array( '<', 1.0 ),   'Ti' => array( '<', 0.5 ),
		'V'  => array( '<', 0.5 ),   'Zn' => array( '<', 0.5 ),   'Zr' => array( '<', 0.5 ),
	);

	/**
	 * Nickel Wire — Certificate of Analysis No. 0004368, 19.10.2021.
	 * Measured with ICP/MS and ICP/OES. Nickel itself is the matrix element.
	 *
	 * @return array<string,array{0:string,1:float|null}>
	 */
	private const NICKEL_ELEMENTS = array(
		'Ag' => array( '<', 0.5 ),   'Al' => array( '<', 1.0 ),   'As' => array( '=', 13.0 ),
		'B'  => array( '<', 1.0 ),   'Bi' => array( '<', 0.1 ),   'Ca' => array( '<', 1.0 ),
		'Cd' => array( '<', 0.1 ),   'Co' => array( '=', 41.0 ),  'Cr' => array( '<', 1.0 ),
		'Cu' => array( '=', 45.0 ),  'Fe' => array( '=', 116.0 ), 'Hf' => array( '<', 0.1 ),
		'K'  => array( '=', 41.0 ),  'Mg' => array( '<', 1.0 ),   'Mn' => array( '=', 3.0 ),
		'Mo' => array( '<', 0.5 ),   'Na' => array( '<', 1.0 ),   'Nb' => array( '<', 0.1 ),
		'Ni' => array( '=', null ),  // Matrix element.
		'P'  => array( '<', 1.0 ),   'Pb' => array( '=', 3.0 ),   'Pd' => array( '<', 0.1 ),
		'Pt' => array( '<', 0.1 ),   'S'  => array( '<', 1.0 ),   'Sb' => array( '<', 0.5 ),
		'Se' => array( '<', 1.0 ),   'Si' => array( '=', 13.0 ),  'Sn' => array( '<', 0.5 ),
		'Ta' => array( '<', 0.5 ),   'Ti' => array( '=', 227.0 ), 'U'  => array( '<', 0.1 ),
		'V'  => array( '<', 0.5 ),   'W'  => array( '<', 0.5 ),   'Zn' => array( '<', 1.0 ),
		'Zr' => array( '<', 0.5 ),
	);

	/**
	 * Run every seeder.
	 *
	 * @return array<string,int> Summary counts.
	 */
	public function run(): array {
		$summary = array();

		$summary['laboratories'] = $this->seed_laboratory();
		$summary['programs']     = $this->seed_programs();
		$summary['spec_fields']  = $this->seed_spec_fields();
		$summary['lots']         = $this->seed_lots();
		$summary['units']        = $this->seed_units();
		$summary['certificates'] = $this->seed_certificates();
		$summary['illustrative'] = $this->seed_illustrative_template();

		Plugin::instance()->audit()->log(
			array(
				'action'       => 'registry_seeded',
				'entity_type'  => 'system',
				'entity_label' => 'Registry seed',
				'new_value'    => $summary,
				'reason'       => 'Supplied Certificates of Analysis loaded as under-review records; illustrative public template created.',
				'severity'     => 'notice',
			)
		);

		return $summary;
	}

	/**
	 * Table helper.
	 *
	 * @param string $name Table name without prefix.
	 */
	private function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'rc_' . $name;
	}

	/**
	 * Insert or fetch a row by a unique column.
	 *
	 * @param string              $table  Unprefixed table name.
	 * @param string              $unique Unique column.
	 * @param array<string,mixed> $data   Row data.
	 * @return int Row id.
	 */
	private function upsert( string $table, string $unique, array $data ): int {
		global $wpdb;

		$full = $this->table( $table );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT `id` FROM `{$full}` WHERE `{$unique}` = %s LIMIT 1", (string) $data[ $unique ] )
		);

		if ( null !== $existing ) {
			return (int) $existing;
		}

		$wpdb->insert( $full, $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * The testing laboratory named on both certificates.
	 *
	 * Accreditation is recorded as unverified: the certificates identify the
	 * laboratory but do not evidence an accreditation scheme, and we do not
	 * display an accreditation we have not been shown. [M§3]
	 */
	private function seed_laboratory(): int {
		$this->upsert(
			'laboratories',
			'slug',
			array(
				'slug'                  => 'igas-research',
				'name'                  => 'IGAS research — Independent Global Assaying Services',
				'provider_type'         => 'laboratory',
				'address_line'          => 'Landstrasse 88a',
				'city'                  => 'Goslar',
				'country_code'          => 'DE',
				'registration_number'   => 'DE267751897',
				'accreditation_body'    => null,
				'accreditation_ref'     => null,
				'accreditation_verified' => 0,
				'is_independent'        => 1,
				'publication_state'     => 'under_review',
				'notes'                 => 'Identified on the Certificates of Analysis supplied with the project brief. Accreditation evidence has not been supplied; no accreditation claim may be published until it is.',
			)
		);

		return 1;
	}

	/**
	 * The two initial asset programmes.
	 */
	private function seed_programs(): int {
		$this->upsert(
			'asset_programs',
			'program_code',
			array(
				'program_code'        => 'RC-CU-POWDER',
				'slug'                => 'ultrafine-copper-powder',
				'name'                => 'Ultrafine Copper Powder',
				'asset_class'         => 'industrial_metal',
				'material_category'   => 'copper',
				'material_form'       => 'powder',
				'product_name'        => 'Ultrafine Copper Powder',
				'summary'             => 'Proposed asset programme for ultra-high-purity copper powder for advanced manufacturing, electronics, battery and additive-manufacturing applications.',
				// From Certificate of Analysis 0004512. Declared and tested are
				// the same figure here because the certificate is the source of
				// both; the columns stay separate so a later independent
				// re-assay can diverge from the supplier's declaration.
				'declared_purity_pct' => '99.999900',
				'tested_purity_pct'   => '99.999900',
				'purity_standard'     => 'TU 1793-011-50316079-2004',
				'publication_state'   => 'under_review',
				'availability_status' => 'pre_launch',
				'verification_status' => 'submitted',
				'sort_order'          => 1,
			)
		);

		$this->upsert(
			'asset_programs',
			'program_code',
			array(
				'program_code'        => 'RC-NI-WIRE-0025',
				'slug'                => 'ultrafine-nickel-wire-0-025mm',
				'name'                => 'Ultrafine Nickel Wire 0.025 mm',
				'asset_class'         => 'industrial_metal',
				'material_category'   => 'nickel',
				'material_form'       => 'wire',
				'product_name'        => 'Nickel wire 0.025 mm dia, DKRNT NP1',
				'summary'             => 'Proposed asset programme for high-purity ultrafine nickel wire for electronics, precision and high-specification industrial applications.',
				// From Certificate of Analysis 0004368.
				'declared_purity_pct' => '99.980700',
				'tested_purity_pct'   => '99.980700',
				'purity_standard'     => 'GOST 2179-75',
				'publication_state'   => 'under_review',
				'availability_status' => 'pre_launch',
				'verification_status' => 'submitted',
				'sort_order'          => 2,
			)
		);

		return 2;
	}

	/**
	 * Programme-specific specification fields.
	 *
	 * The full field sets from [M§10] and [M§11]. Fields with no supplied value
	 * still exist — pending owner information never removes a field. [MP1..22]
	 */
	private function seed_spec_fields(): int {
		global $wpdb;

		$copper_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'asset_programs' )} WHERE program_code = %s", 'RC-CU-POWDER' ) ); // phpcs:ignore
		$nickel_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'asset_programs' )} WHERE program_code = %s", 'RC-NI-WIRE-0025' ) ); // phpcs:ignore

		// [M§10] Copper Powder Program Requirements.
		$copper_fields = array(
			array( 'composition', 'chemical_composition', 'Chemical composition', 'text', null ),
			array( 'composition', 'impurity_profile', 'Impurity profile', 'text', null ),
			array( 'testing', 'testing_methodology', 'Testing methodology', 'string', null ),
			array( 'particle', 'particle_size_distribution', 'Particle-size distribution', 'text', 'um' ),
			array( 'particle', 'particle_size_min', 'Minimum particle size', 'decimal', 'um' ),
			array( 'particle', 'particle_size_max', 'Maximum particle size', 'decimal', 'um' ),
			array( 'particle', 'particle_size_avg', 'Average particle size', 'decimal', 'um' ),
			array( 'particle', 'morphology', 'Morphology', 'string', null ),
			array( 'physical', 'apparent_density', 'Apparent density', 'decimal', 'g/cm3' ),
			array( 'physical', 'tap_density', 'Tap density', 'decimal', 'g/cm3' ),
			array( 'physical', 'oxygen_content', 'Oxygen content', 'decimal', 'ppm' ),
			array( 'physical', 'moisture_content', 'Moisture content', 'decimal', '%' ),
			array( 'physical', 'flow_characteristics', 'Flow characteristics', 'string', null ),
			array( 'production', 'production_method', 'Production method', 'string', null ),
			array( 'handling', 'safety_documentation', 'Safety documentation', 'document', null ),
		);

		// [M§11] Nickel Wire Program Requirements.
		$nickel_fields = array(
			array( 'composition', 'chemical_composition', 'Chemical composition', 'text', null ),
			array( 'composition', 'impurity_profile', 'Impurity profile', 'text', null ),
			array( 'testing', 'testing_methodology', 'Testing methodology', 'string', null ),
			array( 'dimensions', 'wire_diameter', 'Wire diameter', 'decimal', 'mm' ),
			array( 'dimensions', 'gauge', 'Gauge', 'string', null ),
			array( 'dimensions', 'diameter_tolerance', 'Diameter tolerance', 'string', 'mm' ),
			array( 'dimensions', 'coil_length', 'Coil length', 'decimal', 'm' ),
			array( 'mechanical', 'surface_finish', 'Surface finish', 'string', null ),
			array( 'mechanical', 'temper_condition', 'Temper or material condition', 'string', null ),
			array( 'mechanical', 'tensile_strength', 'Tensile strength', 'decimal', 'MPa' ),
			array( 'mechanical', 'elongation', 'Elongation', 'decimal', '%' ),
			array( 'electrical', 'electrical_characteristics', 'Electrical or thermal characteristics', 'text', null ),
			array( 'production', 'production_batch', 'Production batch', 'string', null ),
			array( 'handling', 'safety_documentation', 'Safety documentation', 'document', null ),
		);

		$count = 0;

		foreach ( array( $copper_id => $copper_fields, $nickel_id => $nickel_fields ) as $program_id => $fields ) {
			foreach ( $fields as $index => $field ) {
				$this->upsert_spec_field( (int) $program_id, $field, $index );
				$count++;
			}
		}

		// The only specification values the supplied certificates actually
		// evidence. Everything else stays empty and shows as pending.
		$this->set_spec_value( $copper_id, 'testing_methodology', 'ICP/OES' );
		$this->set_spec_value( $nickel_id, 'testing_methodology', 'ICP/MS and ICP/OES' );
		$this->set_spec_value( $nickel_id, 'wire_diameter', '0.025' );
		$this->set_spec_value( $nickel_id, 'production_batch', 'DKRNT NP1' );

		return $count;
	}

	/**
	 * Insert a specification field definition.
	 *
	 * @param int                                              $program_id Programme id.
	 * @param array{0:string,1:string,2:string,3:string,4:?string} $field   Definition.
	 * @param int                                              $sort       Sort order.
	 */
	private function upsert_spec_field( int $program_id, array $field, int $sort ): void {
		global $wpdb;

		[ $group, $key, $label, $type, $unit ] = $field;

		$table = $this->table( 'program_spec_fields' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM `{$table}` WHERE program_id = %d AND field_key = %s", $program_id, $key )
		);

		if ( null !== $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'program_id' => $program_id,
				'group_key'  => $group,
				'field_key'  => $key,
				'label'      => $label,
				'data_type'  => $type,
				'unit'       => $unit,
				'is_public'  => 1,
				'sort_order' => $sort,
			)
		);
	}

	/**
	 * Record a specification value sourced from a supplied certificate.
	 *
	 * @param int    $program_id Programme id.
	 * @param string $key        Field key.
	 * @param string $value      Value as text.
	 */
	private function set_spec_value( int $program_id, string $key, string $value ): void {
		global $wpdb;

		$fields = $this->table( 'program_spec_fields' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$field_id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM `{$fields}` WHERE program_id = %d AND field_key = %s", $program_id, $key )
		);

		if ( null === $field_id ) {
			return;
		}

		$values = $this->table( 'program_spec_values' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM `{$values}` WHERE field_id = %d AND entity_type = 'program' AND entity_id = %d", (int) $field_id, $program_id )
		);

		if ( null !== $exists ) {
			return;
		}

		$wpdb->insert(
			$values,
			array(
				'field_id'          => (int) $field_id,
				'entity_type'       => 'program',
				'entity_id'         => $program_id,
				'value_text'        => $value,
				'value_number'      => is_numeric( $value ) ? $value : null,
				'source_reference'  => 'Certificate of Analysis supplied with the project brief',
				'publication_state' => 'under_review',
			)
		);
	}

	/**
	 * The two lots described by the supplied certificates.
	 */
	private function seed_lots(): int {
		global $wpdb;

		$copper_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'asset_programs' )} WHERE program_code = %s", 'RC-CU-POWDER' ) ); // phpcs:ignore
		$nickel_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'asset_programs' )} WHERE program_code = %s", 'RC-NI-WIRE-0025' ) ); // phpcs:ignore

		/*
		 * Copper lot 03-K-07.
		 *
		 * The certificate states 2000 kg "in glass ampoules, packed in
		 * cardboard boxes", with a footnote that net weight is "according to
		 * data supplied by customer". That is a declaration, so it is recorded
		 * as declared_net_weight and verified_net_weight stays NULL. The
		 * reconciliation engine raises an unverified-quantity exception for
		 * exactly this, which is the correct outcome rather than a defect.
		 *
		 * unit_count is NULL: the number of boxes is not stated anywhere in the
		 * supplied evidence, and inventing one would be fabricating inventory.
		 */
		$this->upsert(
			'lots',
			'slug',
			array(
				'program_id'          => $copper_id,
				'lot_number'          => '03-K-07',
				'slug'                => 'copper-powder-lot-03-k-07',
				'declared_quantity'   => '2000.000000',
				'verified_quantity'   => null,
				'quantity_unit'       => 'kg',
				'declared_net_weight' => '2000.000000',
				'verified_net_weight' => null,
				'weight_unit'         => 'kg',
				'quantity_source'     => 'Supplier declaration recorded on Certificate of Analysis 0004512 ("net weight according to data supplied by customer")',
				'unit_count'          => null,
				'packaging_type'      => 'Glass ampoules packed in cardboard boxes',
				'declared_purity_pct' => '99.999900',
				'tested_purity_pct'   => '99.999900',
				'purity_standard'     => 'TU 1793-011-50316079-2004',
				'publication_state'   => 'under_review',
				'verification_status' => 'submitted',
				'custody_status'      => 'not_applicable',
				'reserve_status'      => 'not_assessed',
				'notes'               => 'Sampled by IGAS research at ProSafe, Magdeburg on 2022-07-01 (box 20). Ownership, custody, insurance and valuation records have not been supplied.',
			)
		);

		/*
		 * Nickel lot 120/NP1.
		 *
		 * The certificate states 5000 g total net weight as "30 bobbins in 1
		 * box", again customer-declared. The bobbin count IS evidenced, so
		 * unit_count is 30. Per-bobbin weight is not stated; dividing 5000 by
		 * 30 would be a derivation presented as a measurement, so each unit's
		 * weight stays NULL.
		 */
		$this->upsert(
			'lots',
			'slug',
			array(
				'program_id'          => $nickel_id,
				'lot_number'          => '120/NP1',
				'slug'                => 'nickel-wire-lot-120-np1',
				'declared_quantity'   => '5000.000000',
				'verified_quantity'   => null,
				'quantity_unit'       => 'g',
				'declared_net_weight' => '5000.000000',
				'verified_net_weight' => null,
				'weight_unit'         => 'g',
				'quantity_source'     => 'Supplier declaration recorded on Certificate of Analysis 0004368 ("net weight according to information given by customer")',
				'unit_count'          => 30,
				'packaging_type'      => '30 bobbins in 1 box',
				'producer'            => null,
				'declared_purity_pct' => '99.980700',
				'tested_purity_pct'   => '99.980700',
				'impurity_pct'        => '0.019300',
				'purity_standard'     => 'GOST 2179-75',
				'publication_state'   => 'under_review',
				'verification_status' => 'submitted',
				'custody_status'      => 'not_applicable',
				'reserve_status'      => 'not_assessed',
				'notes'               => 'Sampled by IGAS research in Goslar on 2021-10-14 from bobbins 8, 10, 19 and 27. Ownership, custody, insurance and valuation records have not been supplied.',
			)
		);

		return 2;
	}

	/**
	 * Individually identifiable units, created only where evidenced.
	 */
	private function seed_units(): int {
		global $wpdb;

		$copper_lot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'lots' )} WHERE slug = %s", 'copper-powder-lot-03-k-07' ) ); // phpcs:ignore
		$nickel_lot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'lots' )} WHERE slug = %s", 'nickel-wire-lot-120-np1' ) ); // phpcs:ignore

		$created = 0;
		$table   = $this->table( 'units' );

		// Copper: exactly one evidenced unit — the box that was sampled.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE lot_id = %d AND identifier = %s", $copper_lot, 'BOX-20' ) );

		if ( null === $exists ) {
			$wpdb->insert(
				$table,
				array(
					'lot_id'              => $copper_lot,
					'kind'                => 'box',
					'identifier'          => 'BOX-20',
					'sequence_no'         => 20,
					'declared_net_weight' => null,
					'weight_unit'         => 'kg',
					'packaging_type'      => 'Cardboard box containing glass ampoules',
					'was_sampled'         => 1,
					'sampled_on'          => '2022-07-01',
					'storage_location'    => 'ProSafe, Magdeburg (at time of sampling)',
					'publication_state'   => 'under_review',
					'verification_status' => 'submitted',
					'notes'               => 'The only individually identified unit evidenced by Certificate of Analysis 0004512. The total number of boxes in this lot has not been supplied; remaining units are pending owner information.',
				)
			);
			$created++;
		}

		// Nickel: 30 bobbins, explicitly stated on the certificate.
		// Bobbins 8, 10, 19 and 27 were sampled.
		$sampled = array( 8, 10, 19, 27 );

		for ( $i = 1; $i <= 30; $i++ ) {
			$identifier = sprintf( 'BOBBIN-%02d', $i );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE lot_id = %d AND identifier = %s", $nickel_lot, $identifier ) );

			if ( null !== $exists ) {
				continue;
			}

			$was_sampled = in_array( $i, $sampled, true );

			$wpdb->insert(
				$table,
				array(
					'lot_id'              => $nickel_lot,
					'kind'                => 'bobbin',
					'identifier'          => $identifier,
					'sequence_no'         => $i,
					// Not stated per bobbin. 5000 g / 30 would be a derivation
					// presented as a measurement.
					'declared_net_weight' => null,
					'weight_unit'         => 'g',
					'packaging_type'      => 'Bobbin, 30 per box',
					'was_sampled'         => $was_sampled ? 1 : 0,
					'sampled_on'          => $was_sampled ? '2021-10-14' : null,
					'publication_state'   => 'under_review',
					'verification_status' => $was_sampled ? 'submitted' : 'not_verified',
					'notes'               => $was_sampled
						? 'Sampled by IGAS research on 2021-10-14 for Certificate of Analysis 0004368.'
						: 'Unit count evidenced by the certificate ("30 bobbins in 1 box"). This unit was not individually sampled.',
				)
			);
			$created++;
		}

		return $created;
	}

	/**
	 * The two Certificates of Analysis, with every measured element.
	 */
	private function seed_certificates(): int {
		global $wpdb;

		$lab        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'laboratories' )} WHERE slug = %s", 'igas-research' ) ); // phpcs:ignore
		$copper_lot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'lots' )} WHERE slug = %s", 'copper-powder-lot-03-k-07' ) ); // phpcs:ignore
		$nickel_lot = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table( 'lots' )} WHERE slug = %s", 'nickel-wire-lot-120-np1' ) ); // phpcs:ignore

		$copper_cert = $this->upsert(
			'certificates',
			'certificate_number',
			array(
				'laboratory_id'       => $lab,
				'lot_id'              => $copper_lot,
				'certificate_number'  => '0004512',
				'certificate_date'    => '2022-07-04',
				'sample_date'         => '2022-07-01',
				'test_method'         => 'ICP/OES',
				'standard_reference'  => 'TU 1793-011-50316079-2004',
				'goods_description'   => 'Ultrafine Copper Powder, Lot #03-K-07',
				'sample_description'  => '10 g',
				'sample_location'     => 'ProSafe, Magdeburg, Germany',
				'sampled_units'       => 'Box no. 20',
				'purity_pct'          => '99.999900',
				'additional_findings' => 'Isotopic composition is that of natural copper: 63Cu 69.1% +/- 0.05% and 65Cu 30.9% +/- 0.05%. The material is not radioactive. Chemical purity is based on the impurities Al, Cd, Fe, Mg, Mo, Ni, Sb, Ti and Zn.',
				'publication_state'   => 'under_review',
				'verification_status' => 'submitted',
			)
		);

		$nickel_cert = $this->upsert(
			'certificates',
			'certificate_number',
			array(
				'laboratory_id'       => $lab,
				'lot_id'              => $nickel_lot,
				'certificate_number'  => '0004368',
				'certificate_date'    => '2021-10-19',
				'sample_date'         => '2021-10-14',
				'test_method'         => 'ICP/MS and ICP/OES',
				'standard_reference'  => 'GOST 2179-75',
				'goods_description'   => 'Nickel wire 0.025 mm dia, DKRNT NP1, Lot "120/NP1"',
				'sample_description'  => '0.8 g',
				'sample_location'     => 'Goslar, Germany',
				'sampled_units'       => 'Bobbins no. 8, 10, 19, 27',
				'purity_pct'          => '99.980700',
				'impurity_pct'        => '0.019300',
				'additional_findings' => 'The concentration of impurities according to GOST 2179-75 (As, Cu, Fe, Mn, Pb, Si) in this sample is 0.0193% by weight. The material is not radioactive.',
				'publication_state'   => 'under_review',
				'verification_status' => 'submitted',
			)
		);

		$this->seed_elements( $copper_cert, self::COPPER_ELEMENTS, 'Cu' );
		$this->seed_elements( $nickel_cert, self::NICKEL_ELEMENTS, 'Ni' );

		return 2;
	}

	/**
	 * Store one row per measured element.
	 *
	 * @param int                                           $certificate_id Certificate id.
	 * @param array<string,array{0:string,1:float|null}>    $elements       Element map.
	 * @param string                                        $matrix         Base-material symbol.
	 */
	private function seed_elements( int $certificate_id, array $elements, string $matrix ): void {
		global $wpdb;

		$table = $this->table( 'certificate_elements' );
		$sort  = 0;

		foreach ( $elements as $symbol => [ $operator, $value ] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE certificate_id = %d AND element = %s", $certificate_id, $symbol ) );

			if ( null !== $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'certificate_id' => $certificate_id,
					'element'        => $symbol,
					'operator'       => $operator,
					'value_ppm'      => $value,
					'is_matrix'      => ( $symbol === $matrix && null === $value ) ? 1 : 0,
					'sort_order'     => $sort++,
				)
			);
		}
	}

	/**
	 * The illustrative public placeholder specified in [M§13].
	 *
	 * Every substantive field reads "Pending". This is what the public asset
	 * pages show while real records remain under review, and it exists so that
	 * the page can demonstrate the future format without presenting a single
	 * invented quantity, certificate, custodian, insurer, valuation, token
	 * price or contract address.
	 */
	private function seed_illustrative_template(): int {
		$program_id = $this->upsert(
			'asset_programs',
			'program_code',
			array(
				'program_code'        => 'RC-ILLUSTRATIVE',
				'slug'                => 'illustrative-industrial-metal-asset-template',
				'name'                => 'Illustrative Industrial Metal Asset Template',
				'asset_class'         => 'industrial_metal',
				'material_category'   => null,
				'material_form'       => null,
				'product_name'        => null,
				'summary'             => 'This presentation demonstrates the future format of a ReserveChain industrial-metal asset page. No verified material, ownership document, laboratory report, valuation, custody arrangement, reserve claim or token is represented by this placeholder.',
				'declared_purity_pct' => null,
				'tested_purity_pct'   => null,
				'publication_state'   => 'published',
				'availability_status' => 'not_offered',
				'verification_status' => 'not_verified',
				'custody_status'      => 'not_applicable',
				'reserve_status'      => 'not_assessed',
				'tokenization_status' => 'pending',
				'redemption_status'   => 'not_available',
				'is_future_category'  => 0,
				'sort_order'          => 99,
			)
		);

		return $program_id > 0 ? 1 : 0;
	}
}
