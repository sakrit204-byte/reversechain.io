<?php
/**
 * Declarative Asset Registry schema.
 *
 * One definition drives: post-type registration, admin meta boxes, validation, REST output,
 * Digital Asset Passport rendering, evidence-derived lifecycle and completeness scoring.
 * Adding a new entity type or field never requires a platform rebuild — extend via the
 * `rc_registry_schema` filter.
 *
 * Field types: text, textarea, number, date, select, relation, relations, country, url, money, status.
 * `public` => false keeps a field out of the website, API and passports.
 * `pending` => the text shown when no value has been provided (missing information is never invented).
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Claim / verification statuses used everywhere on the platform. */
	public const CLAIM_STATUSES = array(
		'proposed'             => 'Proposed',
		'in_development'       => 'In development',
		'pending_verification' => 'Pending verification',
		'verified'             => 'Verified',
		'not_applicable'       => 'Not applicable',
	);

	/** Entity types whose records receive a Digital Asset Passport. */
	public const PASSPORT_TYPES = array( 'rc_lot', 'rc_batch', 'rc_container', 'rc_coil' );

	private static ?array $cache = null;

	public static function claim_statuses(): array {
		return array(
			'proposed'             => __( 'Proposed', 'reservechain' ),
			'in_development'       => __( 'In development', 'reservechain' ),
			'pending_verification' => __( 'Pending verification', 'reservechain' ),
			'verified'             => __( 'Verified', 'reservechain' ),
			'not_applicable'       => __( 'Not applicable', 'reservechain' ),
		);
	}

	/**
	 * Full registry definition.
	 */
	public static function entities(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$status = array(
			'key'     => 'verification_status',
			'label'   => 'Verification status',
			'type'    => 'status',
			'public'  => true,
			'default' => 'in_development',
		);

		$program_rel = array(
			'key'      => 'program',
			'label'    => 'Metal program',
			'type'     => 'relation',
			'target'   => 'rc_program',
			'required' => true,
			'public'   => true,
		);

		$lifecycle_status = array(
			array( 'key' => 'availability_status', 'label' => 'Availability', 'type' => 'select', 'options' => array( 'not_offered' => 'Not offered for sale', 'held' => 'Held: not available', 'pending_approval' => 'Pending approval' ), 'public' => true, 'default' => 'not_offered' ),
			array( 'key' => 'custody_status', 'label' => 'Custody status', 'type' => 'select', 'options' => array( 'pending' => 'Pending', 'under_review' => 'Under review', 'arranged' => 'Arranged (evidence attached)', 'not_applicable' => 'Not applicable' ), 'public' => true, 'default' => 'pending' ),
			array( 'key' => 'reserve_status', 'label' => 'Reserve status', 'type' => 'select', 'options' => array( 'pending' => 'Pending', 'eligible_pending_approval' => 'Eligible: pending approval', 'accepted' => 'Accepted into reserve (attested)', 'excluded' => 'Excluded' ), 'public' => true, 'default' => 'pending' ),
			array( 'key' => 'tokenization_status', 'label' => 'Tokenization status', 'type' => 'select', 'options' => array( 'not_issued' => 'Not issued', 'proposed' => 'Proposed', 'approved' => 'Approved: not issued', 'issued' => 'Issued (authorized)', 'retired' => 'Retired' ), 'public' => true, 'default' => 'not_issued' ),
			array( 'key' => 'redemption_status', 'label' => 'Redemption status', 'type' => 'select', 'options' => array( 'not_available' => 'Not available', 'eligible' => 'Eligible (authorized)', 'requested' => 'Requested', 'released' => 'Released', 'delivered' => 'Delivered' ), 'public' => true, 'default' => 'not_available' ),
			array( 'key' => 'encumbrance_status', 'label' => 'Encumbrance / lien status', 'type' => 'select', 'options' => array( '' => '— not provided —', 'none_declared' => 'None declared (unverified)', 'none_verified' => 'None (verified)', 'encumbered' => 'Encumbered / restricted' ), 'public' => false ),
		);
		$origin = array(
			array( 'key' => 'product_name', 'label' => 'Product name', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'material_form', 'label' => 'Physical form', 'type' => 'select', 'options' => array( '' => '— not set —', 'powder' => 'Powder', 'wire' => 'Wire', 'coil' => 'Coil / bobbin', 'container' => 'Container', 'package' => 'Package', 'other' => 'Other' ), 'public' => true ),
			array( 'key' => 'supplier_owner', 'label' => 'Supplier / asset owner', 'type' => 'text', 'public' => false ),
			array( 'key' => 'country_of_manufacture', 'label' => 'Country of manufacture', 'type' => 'country', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'acquisition_date', 'label' => 'Acquisition / structuring date', 'type' => 'date', 'public' => false ),
			array( 'key' => 'number_of_units', 'label' => 'Number of physical units', 'type' => 'number', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'packaging_type', 'label' => 'Packaging', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'seal_numbers', 'label' => 'Seal numbers', 'type' => 'text', 'public' => true, 'pending' => 'Pending: sealed at custody intake' ),
			array( 'key' => 'storage_location', 'label' => 'Current location (disclosure level)', 'type' => 'text', 'public' => true, 'pending' => 'Pending: custody arrangement not yet confirmed' ),
			array( 'key' => 'storage_requirements', 'label' => 'Storage requirements', 'type' => 'textarea', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'handling_requirements', 'label' => 'Handling requirements', 'type' => 'textarea', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'data_source', 'label' => 'Data source reference', 'type' => 'text', 'public' => true, 'help' => 'Where each value came from, e.g. "Owner-supplied CoA 0004512". Values without a source must stay empty.' ),
		);
		$cu = array(
			array( 'key' => 'cu_psd', 'label' => 'Particle-size distribution', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_size_min', 'label' => 'Minimum particle size', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_size_max', 'label' => 'Maximum particle size', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_size_avg', 'label' => 'Average particle size', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_morphology', 'label' => 'Morphology', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_apparent_density', 'label' => 'Apparent density', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_tap_density', 'label' => 'Tap density', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_oxygen', 'label' => 'Oxygen content', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_moisture', 'label' => 'Moisture content', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'cu_flow', 'label' => 'Flow characteristics', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Where applicable: not yet provided' ),
			array( 'key' => 'cu_production_method', 'label' => 'Production method', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Where documented: not yet provided' ),
			array( 'key' => 'cu_container_type', 'label' => 'Container type', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'cu_net_per_container', 'label' => 'Net weight per container', 'type' => 'number', 'unit' => 'kg', 'scope' => 'Cu', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'cu_safety_docs', 'label' => 'Safety documentation', 'type' => 'text', 'scope' => 'Cu', 'public' => true, 'pending' => 'Where applicable: not yet provided' ),
		);
		$ni = array(
			array( 'key' => 'ni_diameter', 'label' => 'Wire diameter', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Pending: dimensional inspection required' ),
			array( 'key' => 'ni_gauge', 'label' => 'Gauge', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'ni_tolerance', 'label' => 'Diameter tolerance', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'ni_coil_length', 'label' => 'Coil length', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'ni_net_coil_weight', 'label' => 'Net coil weight', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Not yet provided' ),
			array( 'key' => 'ni_surface_finish', 'label' => 'Surface finish', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'ni_temper', 'label' => 'Temper / material condition', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Pending: not covered by supplied certificate' ),
			array( 'key' => 'ni_tensile', 'label' => 'Tensile strength', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Where documented: not yet provided' ),
			array( 'key' => 'ni_elongation', 'label' => 'Elongation', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Where documented: not yet provided' ),
			array( 'key' => 'ni_electrical', 'label' => 'Electrical / thermal characteristics', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Where relevant: not yet provided' ),
			array( 'key' => 'ni_packaging_method', 'label' => 'Packaging method', 'type' => 'text', 'scope' => 'Ni', 'public' => true, 'pending' => 'Not yet provided' ),
		);

		$entities = array(

			'rc_program'        => array(
				'label'    => 'Metal Programs',
				'singular' => 'Metal Program',
				'slug'     => 'programs',
				'icon'     => 'dashicons-database',
				'prefix'   => 'PRG',
				'group'    => 'Programs',
				'fields'   => array(
					array( 'key' => 'symbol', 'label' => 'Element symbol', 'type' => 'text', 'public' => true, 'required' => true, 'help' => 'e.g. Cu, Ni' ),
					array( 'key' => 'atomic_number', 'label' => 'Atomic number', 'type' => 'number', 'public' => true ),
					array( 'key' => 'material_form', 'label' => 'Material form', 'type' => 'text', 'public' => true, 'pending' => 'Pending: material form to be confirmed' ),
					array( 'key' => 'purity_grade_target', 'label' => 'Target purity grade', 'type' => 'text', 'public' => true, 'pending' => 'Pending: to be confirmed by laboratory analysis' ),
					array( 'key' => 'specification_standard', 'label' => 'Specification standard', 'type' => 'text', 'public' => true, 'pending' => 'Pending: standard to be confirmed' ),
					array( 'key' => 'unit_of_account', 'label' => 'Unit of account', 'type' => 'select', 'options' => array( '' => '— not set —', 'kg' => 'Kilogram (kg)', 't' => 'Metric tonne (t)', 'lb' => 'Pound (lb)' ), 'public' => true, 'pending' => 'Not yet determined' ),
					array( 'key' => 'origin_disclosure', 'label' => 'Origin / sourcing disclosure', 'type' => 'textarea', 'public' => true, 'pending' => 'Pending: sourcing disclosure to be provided' ),
					array( 'key' => 'industrial_uses', 'label' => 'Industrial context (general)', 'type' => 'textarea', 'public' => true ),
					array( 'key' => 'program_manager_notes', 'label' => 'Internal notes', 'type' => 'textarea', 'public' => false ),
					$status,
				),
			),

			'rc_laboratory'     => array(
				'label'    => 'Laboratories',
				'singular' => 'Laboratory',
				'slug'     => 'laboratories',
				'icon'     => 'dashicons-admin-site-alt3',
				'prefix'   => 'LAB',
				'group'    => 'Evidence',
				'fields'   => array(
					array( 'key' => 'legal_name', 'label' => 'Legal name', 'type' => 'text', 'public' => true, 'pending' => 'Laboratory to be appointed' ),
					array( 'key' => 'country', 'label' => 'Country', 'type' => 'country', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'accreditation', 'label' => 'Accreditation (e.g. ISO/IEC 17025)', 'type' => 'text', 'public' => true, 'pending' => 'Pending: accreditation evidence required' ),
					array( 'key' => 'accreditation_number', 'label' => 'Accreditation number', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'accreditation_expiry', 'label' => 'Accreditation expiry', 'type' => 'date', 'public' => true ),
					array( 'key' => 'website', 'label' => 'Website', 'type' => 'url', 'public' => true ),
					array( 'key' => 'contact_internal', 'label' => 'Contact (internal)', 'type' => 'textarea', 'public' => false ),
					$status,
				),
			),

			'rc_lot'            => array(
				'label'    => 'Lots',
				'singular' => 'Lot',
				'slug'     => 'lots',
				'icon'     => 'dashicons-archive',
				'prefix'   => 'LOT',
				'group'    => 'Physical assets',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'lot_reference', 'label' => 'Producer lot reference', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'producer', 'label' => 'Producer / refiner', 'type' => 'text', 'public' => true, 'pending' => 'Pending: producer to be disclosed' ),
					array( 'key' => 'production_date', 'label' => 'Production date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'gross_weight', 'label' => 'Gross weight', 'type' => 'number', 'unit' => 'kg', 'public' => true, 'pending' => 'Pending: weight certificate required' ),
					array( 'key' => 'net_weight', 'label' => 'Net weight', 'type' => 'number', 'unit' => 'kg', 'public' => true, 'pending' => 'Pending: weight certificate required' ),
					array( 'key' => 'declared_purity', 'label' => 'Declared purity', 'type' => 'text', 'public' => true, 'pending' => 'Pending: Certificate of Analysis required' ),
					array( 'key' => 'country_of_origin', 'label' => 'Country of origin', 'type' => 'country', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'chain_of_custody_notes', 'label' => 'Chain-of-custody notes', 'type' => 'textarea', 'public' => true ),
					...$origin,
					...$cu,
					...$ni,
					...$lifecycle_status,
					$status,
				),
			),

			'rc_batch'          => array(
				'label'    => 'Batches',
				'singular' => 'Batch',
				'slug'     => 'batches',
				'icon'     => 'dashicons-screenoptions',
				'prefix'   => 'BAT',
				'group'    => 'Physical assets',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'lot', 'label' => 'Parent lot', 'type' => 'relation', 'target' => 'rc_lot', 'public' => true ),
					array( 'key' => 'batch_reference', 'label' => 'Batch reference', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'net_weight', 'label' => 'Net weight', 'type' => 'number', 'unit' => 'kg', 'public' => true, 'pending' => 'Pending: weight certificate required' ),
					array( 'key' => 'particle_size', 'label' => 'Particle size distribution (powder)', 'type' => 'text', 'public' => true, 'pending' => 'Pending: laboratory analysis required' ),
					array( 'key' => 'packaging', 'label' => 'Packaging', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					...$lifecycle_status,
					$status,
				),
			),

			'rc_container'      => array(
				'label'    => 'Containers',
				'singular' => 'Container',
				'slug'     => 'containers',
				'icon'     => 'dashicons-products',
				'prefix'   => 'CTN',
				'group'    => 'Physical assets',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'batch', 'label' => 'Batch', 'type' => 'relation', 'target' => 'rc_batch', 'public' => true ),
					array( 'key' => 'lot', 'label' => 'Parent lot (if no batch)', 'type' => 'relation', 'target' => 'rc_lot', 'public' => true ),
					array( 'key' => 'container_id', 'label' => 'Container / drum ID', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'seal_number', 'label' => 'Tamper seal number', 'type' => 'text', 'public' => true, 'pending' => 'Pending: sealed at custody intake' ),
					array( 'key' => 'net_weight', 'label' => 'Net weight', 'type' => 'number', 'unit' => 'kg', 'public' => true, 'pending' => 'Pending: weight certificate required' ),
					array( 'key' => 'location_disclosure', 'label' => 'Location (disclosure level)', 'type' => 'text', 'public' => true, 'pending' => 'Pending: custody arrangement not yet confirmed' ),
					...$lifecycle_status,
					$status,
				),
			),

			'rc_coil'           => array(
				'label'    => 'Coils / Spools',
				'singular' => 'Coil',
				'slug'     => 'coils',
				'icon'     => 'dashicons-marker',
				'prefix'   => 'COL',
				'group'    => 'Physical assets',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'lot', 'label' => 'Parent lot', 'type' => 'relation', 'target' => 'rc_lot', 'public' => true ),
					array( 'key' => 'coil_id', 'label' => 'Coil / spool ID', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'wire_diameter', 'label' => 'Wire diameter', 'type' => 'text', 'public' => true, 'pending' => 'Pending: laboratory analysis required' ),
					array( 'key' => 'length', 'label' => 'Length', 'type' => 'number', 'unit' => 'm', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'net_weight', 'label' => 'Net weight', 'type' => 'number', 'unit' => 'kg', 'public' => true, 'pending' => 'Pending: weight certificate required' ),
					array( 'key' => 'seal_number', 'label' => 'Tamper seal number', 'type' => 'text', 'public' => true, 'pending' => 'Pending: sealed at custody intake' ),
					...$lifecycle_status,
					$status,
				),
			),

			'rc_coa'            => array(
				'label'    => 'Certificates of Analysis',
				'singular' => 'Certificate of Analysis',
				'slug'     => 'certificates',
				'icon'     => 'dashicons-awards',
				'prefix'   => 'COA',
				'group'    => 'Evidence',
				'evidence' => 'coa',
				'fields'   => array(
					array( 'key' => 'subject', 'label' => 'Applies to', 'type' => 'relations', 'target' => self::PASSPORT_TYPES, 'public' => true, 'required' => true ),
					array( 'key' => 'laboratory', 'label' => 'Issuing laboratory', 'type' => 'relation', 'target' => 'rc_laboratory', 'public' => true ),
					array( 'key' => 'certificate_number', 'label' => 'Certificate number', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'issue_date', 'label' => 'Issue date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'method', 'label' => 'Analytical method', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'result_purity', 'label' => 'Reported purity', 'type' => 'text', 'public' => true, 'pending' => 'Pending: certificate not yet issued' ),
					array( 'key' => 'purity_basis', 'label' => 'Purity basis / standard reference', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'goods_description', 'label' => 'Goods as described on certificate', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'declared_quantity', 'label' => 'Quantity (as declared to laboratory)', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'sample_description', 'label' => 'Sample', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'sampling_location', 'label' => 'Sampling location', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'sampling_date', 'label' => 'Sampling date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'impurity_statement', 'label' => 'Impurity statement', 'type' => 'textarea', 'public' => true ),
					array( 'key' => 'isotopic_composition', 'label' => 'Isotopic composition', 'type' => 'text', 'public' => true ),
					array( 'key' => 'radioactivity', 'label' => 'Radioactivity', 'type' => 'text', 'public' => true ),
					array( 'key' => 'assay_results', 'label' => 'Results of analysis', 'type' => 'assay', 'unit' => 'ppm', 'public' => true, 'help' => 'One per line, exactly as printed, e.g. "Ti: 227" or "Ag: <0,5". Use "Matrix" for the base metal.' ),
					array( 'key' => 'provenance', 'label' => 'Evidence provenance', 'type' => 'select', 'options' => array( 'owner_supplied' => 'Owner-supplied: not independently verified by ReserveChain', 'lab_direct' => 'Received directly from laboratory', 'independently_verified' => 'Independently verified (attestation attached)' ), 'public' => true ),
					array( 'key' => 'document', 'label' => 'Certificate document', 'type' => 'relation', 'target' => 'rc_document', 'public' => true ),
					$status,
				),
			),

			'rc_custody'        => array(
				'label'    => 'Custody & Ownership',
				'singular' => 'Custody / Ownership Record',
				'slug'     => 'custody-records',
				'icon'     => 'dashicons-lock',
				'prefix'   => 'CUS',
				'group'    => 'Evidence',
				'evidence' => 'custody',
				'fields'   => array(
					array( 'key' => 'subject', 'label' => 'Applies to', 'type' => 'relations', 'target' => self::PASSPORT_TYPES, 'public' => true, 'required' => true ),
					array( 'key' => 'record_type', 'label' => 'Record type', 'type' => 'select', 'options' => array( 'custody_intake' => 'Custody intake', 'custody_transfer' => 'Custody transfer', 'ownership' => 'Legal ownership record', 'release' => 'Release / outbound' ), 'public' => true ),
					array( 'key' => 'custodian', 'label' => 'Custodian / warehouse', 'type' => 'text', 'public' => true, 'pending' => 'Custodian to be appointed: subject to final approval' ),
					array( 'key' => 'legal_owner', 'label' => 'Legal owner', 'type' => 'text', 'public' => true, 'pending' => 'Pending: subject to final legal structure' ),
					array( 'key' => 'jurisdiction', 'label' => 'Jurisdiction', 'type' => 'country', 'public' => true, 'pending' => 'Not yet determined' ),
					array( 'key' => 'effective_date', 'label' => 'Effective date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'warehouse_receipt_no', 'label' => 'Warehouse receipt number', 'type' => 'text', 'public' => true, 'pending' => 'Not yet issued' ),
					array( 'key' => 'document', 'label' => 'Supporting document', 'type' => 'relation', 'target' => 'rc_document', 'public' => true ),
					$status,
				),
			),

			'rc_valuation'      => array(
				'label'    => 'Valuations',
				'singular' => 'Valuation',
				'slug'     => 'valuations',
				'icon'     => 'dashicons-chart-line',
				'prefix'   => 'VAL',
				'group'    => 'Evidence',
				'evidence' => 'valuation',
				'fields'   => array(
					array( 'key' => 'subject', 'label' => 'Applies to', 'type' => 'relations', 'target' => array_merge( self::PASSPORT_TYPES, array( 'rc_program' ) ), 'public' => true, 'required' => true ),
					array( 'key' => 'valuer', 'label' => 'Independent valuer', 'type' => 'text', 'public' => true, 'pending' => 'Valuer to be appointed' ),
					array( 'key' => 'valuation_date', 'label' => 'Valuation date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'methodology', 'label' => 'Methodology', 'type' => 'textarea', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'value', 'label' => 'Reported value', 'type' => 'money', 'public' => true, 'pending' => 'No valuation published: subject to independent valuation' ),
					array( 'key' => 'currency', 'label' => 'Currency', 'type' => 'select', 'options' => array( '' => '— not set —', 'CHF' => 'CHF', 'USD' => 'USD', 'EUR' => 'EUR' ), 'public' => true ),
					array( 'key' => 'price_per_unit', 'label' => 'Price per kg / metric ton', 'type' => 'text', 'public' => true, 'pending' => 'No valuation published' ),
					array( 'key' => 'pricing_benchmark', 'label' => 'Pricing benchmark / source', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'acquisition_cost', 'label' => 'Acquisition / structuring cost (restricted)', 'type' => 'money', 'public' => false ),
					array( 'key' => 'commercial_spread', 'label' => 'Commercial spread & fees (restricted)', 'type' => 'textarea', 'public' => false ),
					array( 'key' => 'document', 'label' => 'Valuation report', 'type' => 'relation', 'target' => 'rc_document', 'public' => true ),
					$status,
				),
			),

			'rc_insurance'      => array(
				'label'    => 'Insurance',
				'singular' => 'Insurance Record',
				'slug'     => 'insurance',
				'icon'     => 'dashicons-shield',
				'prefix'   => 'INS',
				'group'    => 'Evidence',
				'evidence' => 'insurance',
				'fields'   => array(
					array( 'key' => 'subject', 'label' => 'Applies to', 'type' => 'relations', 'target' => array_merge( self::PASSPORT_TYPES, array( 'rc_program' ) ), 'public' => true, 'required' => true ),
					array( 'key' => 'insurer', 'label' => 'Insurer', 'type' => 'text', 'public' => true, 'pending' => 'Insurance not yet arranged: subject to final approval' ),
					array( 'key' => 'policy_number', 'label' => 'Policy number', 'type' => 'text', 'public' => false ),
					array( 'key' => 'coverage_type', 'label' => 'Coverage type', 'type' => 'text', 'public' => true, 'pending' => 'Not yet determined' ),
					array( 'key' => 'coverage_amount', 'label' => 'Coverage amount', 'type' => 'money', 'public' => true, 'pending' => 'Not yet determined' ),
					array( 'key' => 'period_start', 'label' => 'Period start', 'type' => 'date', 'public' => true ),
					array( 'key' => 'period_end', 'label' => 'Period end', 'type' => 'date', 'public' => true ),
					array( 'key' => 'document', 'label' => 'Certificate of insurance', 'type' => 'relation', 'target' => 'rc_document', 'public' => true ),
					$status,
				),
			),

			'rc_reserve_report' => array(
				'label'    => 'Reserve Reports',
				'singular' => 'Reserve Report',
				'slug'     => 'reserve-reports',
				'icon'     => 'dashicons-clipboard',
				'prefix'   => 'RSV',
				'group'    => 'Proof of Reserves',
				'evidence' => 'reserve',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'subject', 'label' => 'Assets covered', 'type' => 'relations', 'target' => self::PASSPORT_TYPES, 'public' => true ),
					array( 'key' => 'report_date', 'label' => 'Report date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'attestor', 'label' => 'Attestor / auditor', 'type' => 'text', 'public' => true, 'pending' => 'Independent attestor to be appointed' ),
					array( 'key' => 'reserve_units', 'label' => 'Attested reserve units', 'type' => 'number', 'public' => true, 'pending' => 'No attestation published' ),
					array( 'key' => 'tokens_outstanding', 'label' => 'Tokens outstanding at report date', 'type' => 'number', 'public' => true, 'pending' => 'Not applicable: no tokens issued' ),
					array( 'key' => 'onchain_tx', 'label' => 'On-chain attestation tx', 'type' => 'text', 'public' => true ),
					array( 'key' => 'document', 'label' => 'Reserve report document', 'type' => 'relation', 'target' => 'rc_document', 'public' => true ),
					$status,
				),
			),

			'rc_token_program'  => array(
				'label'    => 'Token Programs',
				'singular' => 'Token Program',
				'slug'     => 'token-programs',
				'icon'     => 'dashicons-admin-network',
				'prefix'   => 'TKN',
				'group'    => 'Tokenization',
				'fields'   => array(
					$program_rel,
					array( 'key' => 'token_name', 'label' => 'Token name', 'type' => 'text', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'token_symbol', 'label' => 'Token symbol', 'type' => 'text', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'network', 'label' => 'Network', 'type' => 'select', 'options' => array( '' => '— not set —', 'sepolia' => 'Ethereum Sepolia (testnet)', 'amoy' => 'Polygon Amoy (testnet)', 'ethereum' => 'Ethereum mainnet (requires written authorization)' ), 'public' => true, 'pending' => 'Testnet only: no mainnet deployment' ),
					array( 'key' => 'contract_address', 'label' => 'Contract address', 'type' => 'text', 'public' => true, 'pending' => 'Not deployed' ),
					array( 'key' => 'max_supply', 'label' => 'Maximum supply', 'type' => 'number', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'asset_to_token_ratio', 'label' => 'Asset-to-token ratio', 'type' => 'text', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'holder_rights', 'label' => 'Holder rights', 'type' => 'textarea', 'public' => true, 'pending' => 'Not yet determined: subject to final legal structure' ),
					array( 'key' => 'redemption_min', 'label' => 'Redemption minimum', 'type' => 'number', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'allocations', 'label' => 'Allocations', 'type' => 'textarea', 'public' => true, 'pending' => 'Not yet determined: subject to written approval' ),
					array( 'key' => 'token_state', 'label' => 'Token field state', 'type' => 'select', 'options' => array( 'pending' => 'Pending', 'under_review' => 'Under Review', 'not_applicable' => 'Not Applicable', 'approved' => 'Approved', 'published' => 'Published', 'suspended' => 'Suspended', 'retired' => 'Retired' ), 'public' => true, 'default' => 'pending' ),
					array( 'key' => 'approval_reference', 'label' => 'Written approval reference', 'type' => 'text', 'public' => false, 'help' => 'Required before any tokenomics parameter is published.' ),
					$status,
				),
			),

			'rc_redemption'     => array(
				'label'    => 'Redemptions',
				'singular' => 'Redemption',
				'slug'     => 'redemptions',
				'icon'     => 'dashicons-undo',
				'prefix'   => 'RDM',
				'group'    => 'Tokenization',
				'public'   => false,
				'fields'   => array(
					array( 'key' => 'token_program', 'label' => 'Token program', 'type' => 'relation', 'target' => 'rc_token_program', 'public' => false ),
					array( 'key' => 'requester', 'label' => 'Requester (internal ref)', 'type' => 'text', 'public' => false, 'help' => 'Managed by the Redemptions workflow (ReserveChain → Redemptions).' ),
					array( 'key' => 'amount', 'label' => 'Token amount', 'type' => 'number', 'public' => false ),
					array( 'key' => 'redemption_state', 'label' => 'Redemption state', 'type' => 'select', 'options' => array( 'requested' => 'Requested', 'compliance_review' => 'Compliance review', 'approved' => 'Approved', 'tokens_burned' => 'Tokens burned', 'custody_released' => 'Custody released', 'in_logistics' => 'In logistics / customs', 'delivered' => 'Delivered (completed)', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'on_hold' => 'On hold' ), 'public' => false, 'help' => 'Changed only through the Redemptions workflow; direct edits are ignored.' ),
					array( 'key' => 'units', 'label' => 'Selected units (containers / coils)', 'type' => 'relations', 'target' => array( 'rc_container', 'rc_coil' ), 'public' => false ),
					array( 'key' => 'lot', 'label' => 'Selected lot', 'type' => 'relation', 'target' => 'rc_lot', 'public' => false ),
					array( 'key' => 'delivery_method', 'label' => 'Delivery method', 'type' => 'select', 'options' => array( '' => '— not set —', 'collection' => 'Collection', 'delivery' => 'Delivery' ), 'public' => false ),
					array( 'key' => 'onchain_request_id', 'label' => 'On-chain request ID', 'type' => 'text', 'public' => false ),
					array( 'key' => 'burn_tx', 'label' => 'Burn transaction', 'type' => 'text', 'public' => false ),
					array( 'key' => 'release_document', 'label' => 'Custody release document', 'type' => 'relation', 'target' => 'rc_document', 'public' => false ),
					array( 'key' => 'carrier', 'label' => 'Carrier / collecting party', 'type' => 'text', 'public' => false ),
					array( 'key' => 'tracking_ref', 'label' => 'Tracking / collection reference', 'type' => 'text', 'public' => false ),
					array( 'key' => 'customs_documents', 'label' => 'Customs / logistics documents', 'type' => 'relations', 'target' => 'rc_document', 'public' => false ),
					array( 'key' => 'delivery_document', 'label' => 'Delivery confirmation document', 'type' => 'relation', 'target' => 'rc_document', 'public' => false ),
					array( 'key' => 'fulfilment_ref', 'label' => 'Fulfilment reference', 'type' => 'text', 'public' => false ),
				),
			),

			'rc_document'       => array(
				'label'    => 'Documents',
				'singular' => 'Document',
				'slug'     => 'documents',
				'icon'     => 'dashicons-media-document',
				'prefix'   => 'DOC',
				'group'    => 'Evidence',
				'fields'   => array(
					array( 'key' => 'file', 'label' => 'File', 'type' => 'file', 'public' => true, 'required' => true, 'help' => 'SHA-256 fingerprint is computed automatically on save and becomes immutable once published.' ),
					array( 'key' => 'doc_type', 'label' => 'Document type', 'type' => 'select', 'options' => array( 'whitepaper' => 'Whitepaper', 'coa' => 'Certificate of Analysis', 'weight' => 'Weight certificate', 'custody' => 'Custody / warehouse receipt', 'insurance' => 'Insurance certificate', 'valuation' => 'Valuation report', 'reserve' => 'Reserve report', 'legal' => 'Legal / offering document', 'policy' => 'Policy', 'specimen' => 'Specimen / template', 'photo' => 'Photograph / media', 'other' => 'Other' ), 'public' => true ),
					array( 'key' => 'issued_by', 'label' => 'Issued by', 'type' => 'text', 'public' => true, 'pending' => 'Not yet provided' ),
					array( 'key' => 'issue_date', 'label' => 'Issue date', 'type' => 'date', 'public' => true ),
					array( 'key' => 'version_label', 'label' => 'Version', 'type' => 'text', 'public' => true ),
					array( 'key' => 'subject', 'label' => 'Related records', 'type' => 'relations', 'target' => array_merge( self::PASSPORT_TYPES, array( 'rc_program', 'rc_token_program' ) ), 'public' => true ),
					array( 'key' => 'public_library', 'label' => 'Show in public document library', 'type' => 'select', 'options' => array( 'no' => 'No', 'yes' => 'Yes' ), 'public' => false ),
					array( 'key' => 'audience', 'label' => 'Audience', 'type' => 'select', 'options' => array( 'public' => 'Public', 'investor' => 'Investors (data room)', 'enterprise' => 'Enterprise clients', 'auditor' => 'Auditors', 'custodian' => 'Custodians', 'staff' => 'Staff only' ), 'public' => false, 'default' => 'public', 'help' => 'Restricted audiences are stored outside the public uploads area and are only served through signed, expiring download links to permitted roles.' ),
					$status,
				),
			),
		);

		self::$cache = apply_filters( 'rc_registry_schema', $entities );
		return self::$cache;
	}

	public static function entity( string $type ): ?array {
		$all = self::entities();
		return $all[ $type ] ?? null;
	}

	public static function types(): array {
		return array_keys( self::entities() );
	}

	public static function field( string $type, string $key ): ?array {
		$entity = self::entity( $type );
		if ( ! $entity ) {
			return null;
		}
		foreach ( $entity['fields'] as $field ) {
			if ( $field['key'] === $key ) {
				return $field;
			}
		}
		return null;
	}

	public static function is_passport_type( string $type ): bool {
		return in_array( $type, self::PASSPORT_TYPES, true );
	}

	/** Meta key used for a schema field. */
	public static function meta_key( string $key ): string {
		return '_rc_' . $key;
	}

	public static function eu_eea_countries(): array {
		return array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO' );
	}

	public static function countries(): array {
		// ISO 3166-1 alpha-2. Kept inline so the platform has no external dependency for compliance screening.
		return array(
			'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
			'BS' => 'Bahamas', 'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
			'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cabo Verde', 'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'KM' => 'Comoros', 'CG' => 'Congo', 'CD' => 'Congo (DRC)', 'CR' => 'Costa Rica', 'CI' => "Côte d'Ivoire", 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czechia',
			'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador', 'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini', 'ET' => 'Ethiopia',
			'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France', 'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana',
			'HT' => 'Haiti', 'HN' => 'Honduras', 'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
			'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati', 'KP' => 'Korea (North)', 'KR' => 'Korea (South)', 'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan',
			'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya', 'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
			'MG' => 'Madagascar', 'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania', 'MU' => 'Mauritius', 'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique', 'MM' => 'Myanmar',
			'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal', 'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'MK' => 'North Macedonia', 'NO' => 'Norway', 'OM' => 'Oman',
			'PK' => 'Pakistan', 'PW' => 'Palau', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar',
			'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines', 'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'São Tomé and Príncipe', 'SA' => 'Saudi Arabia', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands', 'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan', 'ES' => 'Spain', 'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria',
			'TW' => 'Taiwan', 'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo', 'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye', 'TM' => 'Turkmenistan', 'TV' => 'Tuvalu',
			'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VA' => 'Vatican City', 'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
		);
	}
}
