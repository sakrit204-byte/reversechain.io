<?php
/**
 * Demonstration seed.
 *
 * Creates the two metal programs, their (unparameterised) token programs, SPECIMEN registry records,
 * SPECIMEN template documents with real SHA-256 fingerprints, website pages (EN/ES/IT), menus and
 * role-based demo accounts. No commercial, technical or legal fact about ReserveChain's assets is
 * invented: every unknown value is left empty and renders as an explicit pending state.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Seed {

	private static array $log = array();

	public static function log(): array {
		return self::$log;
	}

	private static function say( string $msg ): void {
		self::$log[] = $msg;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $msg );
		}
	}

	private static bool $force = false;

	public static function run( bool $pages_only = false, bool $force = false ): void {
		self::$force = $force;
		kses_remove_filters();
		Workflow::bypass(
			static function () use ( $pages_only ) {
				self::pages();
				self::menus();
				if ( $pages_only ) {
					return;
				}
				self::settings();
				self::users();
				$programs = self::programs();
				self::registry( $programs );
				Notifications::push( 0, 'Welcome to the ReserveChain preview', 'ReserveChain is in development. Programs, passports and documents shown in the app are specimens or pending verification. No tokens are offered or sold.', 'general' );
			}
		);
		if ( ! $pages_only ) {
			self::review_items();
		}
		flush_rewrite_rules();
		$v = Audit_Log::verify();
		self::say( sprintf( 'Audit chain verified: %s (%d entries).', $v['ok'] ? 'intact' : 'FAILED', $v['checked'] ) );
	}

	/* ------------------------------------------------------------ settings & users */

	private static function settings(): void {
		update_option( 'blogname', 'ReserveChain' );
		update_option( 'blogdescription', 'Proposed industrial-metals-backed tokenization infrastructure — in development' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'users_can_register', 0 );
		update_option( 'default_comment_status', 'closed' );
		update_option( 'default_ping_status', 'closed' );
		// Remove WordPress sample content.
		foreach ( array( array( 'hello-world', 'post' ), array( 'sample-page', 'page' ) ) as $sample ) {
			$sp = get_page_by_path( $sample[0], OBJECT, $sample[1] );
			if ( $sp ) {
				wp_delete_post( $sp->ID, true );
			}
		}
		$default_privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $default_privacy && 'draft' === get_post_status( $default_privacy ) ) {
			wp_delete_post( $default_privacy, true );
		}
		$ours = get_page_by_path( 'legal/privacy' );
		if ( $ours ) {
			update_option( 'wp_page_for_privacy_policy', $ours->ID );
		}
		Settings::update( array( 'mfa_enforce_staff' => 'production' === RC_ENV ) );
		self::say( 'Settings applied (prelaunch mode, gated modules locked).' );
	}

	private static function users(): void {
		if ( 'production' === RC_ENV ) {
			self::say( 'Production environment: demo accounts not created.' );
			return;
		}
		$accounts = array(
			'demo.editor'     => array( 'rc_editor', 'Demo Content Editor' ),
			'demo.registry'   => array( 'rc_registry_manager', 'Demo Registry Manager' ),
			'demo.reviewer'   => array( 'rc_reviewer', 'Demo Reviewer' ),
			'demo.compliance' => array( 'rc_compliance_officer', 'Demo Compliance Officer' ),
			'demo.auditor'    => array( 'rc_auditor', 'Demo Auditor (read-only)' ),
			'demo.app'        => array( 'subscriber', 'Demo App User' ),
		);
		$pass = defined( 'RC_DEMO_PASSWORD' ) ? RC_DEMO_PASSWORD : 'ReserveChain-Demo-2026';
		foreach ( $accounts as $login => $def ) {
			if ( username_exists( $login ) ) {
				continue;
			}
			$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $login . '@demo.reservechain.invalid', 'display_name' => $def[1], 'role' => $def[0] ) );
			if ( ! is_wp_error( $id ) ) {
				update_user_meta( $id, 'rc_demo_account', 1 );
				if ( 'subscriber' === $def[0] ) {
					update_user_meta( $id, 'rc_country', 'CH' );
					update_user_meta( $id, 'rc_entity_type', 'individual' );
					update_user_meta( $id, 'rc_kyc', 'pending' );
				}
			}
		}
		self::say( 'Demo role accounts created (password from RC_DEMO_PASSWORD).' );
	}

	/* ------------------------------------------------------------ registry */

	private static function upsert( string $type, string $slug, string $title, string $content, array $fields, string $status = 'publish', string $excerpt = '' ): int {
		$existing = get_page_by_path( $slug, OBJECT, $type );
		// Scalar fields go in via meta_input so they exist when save_post assigns the record number.
		$scalar = array();
		foreach ( $fields as $k => $v ) {
			if ( ! is_array( $v ) ) {
				$scalar[ Schema::meta_key( $k ) ] = $v;
			}
		}
		$id = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => $type, 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt, 'post_status' => 'draft', 'meta_input' => $scalar ) );
		if ( $existing ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => $excerpt ) );
		}
		foreach ( $fields as $k => $v ) {
			if ( is_array( $v ) ) {
				delete_post_meta( $id, Schema::meta_key( $k ) );
				foreach ( $v as $x ) {
					add_post_meta( $id, Schema::meta_key( $k ), $x );
				}
			} else {
				update_post_meta( $id, Schema::meta_key( $k ), $v );
			}
		}
		Registry::ensure_record_no( $id );
		wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
		return (int) $id;
	}

	private static function programs(): array {
		$claims_common = array(
			array( 'label' => 'Program structure', 'status' => 'proposed', 'note' => 'Subject to the final Swiss corporate and legal structure.' ),
			array( 'label' => 'Material specification', 'status' => 'pending_verification', 'note' => 'To be confirmed by accredited laboratory analysis.' ),
			array( 'label' => 'Custody arrangement', 'status' => 'proposed', 'note' => 'No custodian has been appointed.' ),
			array( 'label' => 'Insurance', 'status' => 'proposed', 'note' => 'No insurance has been arranged.' ),
			array( 'label' => 'Proof of Reserves', 'status' => 'in_development', 'note' => 'Module built; no attestation published.' ),
			array( 'label' => 'Token issuance', 'status' => 'proposed', 'note' => 'No tokens exist. Testnet contracts only.' ),
			array( 'label' => 'Redemption', 'status' => 'proposed', 'note' => 'Process designed; not available.' ),
		);

		$cu = self::upsert(
			'rc_program',
			'copper-powder',
			'Ultra-High-Purity Copper Powder',
			'Proposed program for ultra-high-purity copper powder held as the reference asset of a future, separately approved token program. Specifications, quantities, producers, custody and valuation are pending and will only be published once evidenced.',
			array(
				'symbol'              => 'Cu',
				'atomic_number'       => 29,
				'material_form'       => 'Ultrafine powder',
				'purity_grade_target' => '99.9999 % (owner-supplied CoA 0004512 — subject to documentary verification)',
				'unit_of_account'     => 'kg',
				'industrial_uses'     => 'High-purity copper powders are used across powder metallurgy, additive manufacturing, electronics (conductive pastes and thermal management), brazing and friction materials. Purity grade and particle-size distribution determine suitability for each application.',
				'verification_status' => 'in_development',
			),
			'publish',
			'Ultra-high-purity copper powder: proposed program, in development.'
		);
		update_post_meta( $cu, '_rc_claims', $claims_common );
		wp_update_post( array( 'ID' => $cu, 'menu_order' => 1 ) );

		$ni = self::upsert(
			'rc_program',
			'nickel-wire',
			'High-Purity Nickel Wire 0.025 mm',
			'Proposed program for high-purity nickel wire held as the reference asset of a future, separately approved token program. Specifications, quantities, producers, custody and valuation are pending and will only be published once evidenced.',
			array(
				'symbol'              => 'Ni',
				'atomic_number'       => 28,
				'material_form'       => 'Ultrafine wire, 0.025 mm diameter (as described on supplied CoA)',
				'purity_grade_target' => '99.9807 % (owner-supplied CoA 0004368 — subject to documentary verification)',
				'unit_of_account'     => 'kg',
				'industrial_uses'     => 'High-purity nickel wire is used in electronics, battery and energy-storage interconnects, heating and resistance elements, sensors, lead wires and specialised welding. Purity, diameter tolerance and temper determine suitability for each application.',
				'verification_status' => 'in_development',
			),
			'publish',
			'High-purity nickel wire: proposed program, in development.'
		);
		update_post_meta( $ni, '_rc_claims', $claims_common );
		wp_update_post( array( 'ID' => $ni, 'menu_order' => 2 ) );

		foreach ( array( $cu => 'Copper Powder', $ni => 'Nickel Wire' ) as $pid => $name ) { // phpcs:ignore
			self::upsert(
				'rc_token_program',
				sanitize_title( $name . ' token program' ),
				$name . ' — proposed token program',
				'Placeholder token program. All tokenomics parameters are intentionally unset and require written approval before publication.',
				array( 'program' => $pid, 'network' => 'sepolia', 'token_state' => 'pending', 'verification_status' => 'proposed' )
			);
		}
		self::say( 'Programs Cu (29) and Ni (28) with unparameterised token programs.' );
		return array( 'cu' => $cu, 'ni' => $ni );
	}

	private static function document( string $slug, string $title, string $type, array $blocks, array $subjects, string $status = 'in_development', string $version = 'v1.0' ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'rc_document' );
		if ( $existing && get_post_meta( $existing->ID, '_rc_file', true ) ) {
			return $existing->ID;
		}
		$upload = wp_upload_bits( $slug . '.pdf', null, Pdf::build( $title, $blocks ) );
		if ( ! empty( $upload['error'] ) ) {
			self::say( 'Upload failed: ' . $upload['error'] );
			return 0;
		}
		$att = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $upload['file'] );
		update_post_meta( $att, '_rc_sha256', hash_file( 'sha256', $upload['file'] ) );
		$id = self::upsert(
			'rc_document',
			$slug,
			$title,
			'',
			array(
				'file'                => $att,
				'doc_type'            => $type,
				'issued_by'           => 'ReserveChain (in development)',
				'issue_date'          => gmdate( 'Y-m-d' ),
				'version_label'       => $version,
				'subject'             => $subjects,
				'public_library'      => 'yes',
				'verification_status' => $status,
			)
		);
		update_post_meta( $id, '_rc_sha256', hash_file( 'sha256', $upload['file'] ) );
		return $id;
	}

	public static function import_document( string $path, string $title, string $type, bool $public = true, string $version = 'v1.0' ): int {
		$upload = wp_upload_bits( basename( $path ), null, (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$att = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => wp_check_filetype( $upload['file'] )['type'], 'post_status' => 'inherit' ), $upload['file'] );
		$sha = hash_file( 'sha256', $upload['file'] );
		update_post_meta( $att, '_rc_sha256', $sha );
		return Workflow::bypass(
			static function () use ( $title, $type, $att, $public, $version, $sha ) {
				$id = self::upsert( 'rc_document', sanitize_title( $title . '-' . $version ), $title, '', array( 'file' => $att, 'doc_type' => $type, 'issued_by' => 'ReserveChain', 'issue_date' => gmdate( 'Y-m-d' ), 'version_label' => $version, 'public_library' => $public ? 'yes' : 'no', 'verification_status' => 'in_development' ) );
				update_post_meta( $id, '_rc_sha256', $sha );
				return $id;
			}
		);
	}

	/** Register an owner-supplied evidence file (image or PDF) shipped in seed/evidence/. */
	private static function evidence_file( string $slug, string $file, string $title, string $type, string $issued_by, string $date, array $subjects, string $desc ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'rc_document' );
		if ( $existing ) {
			return $existing->ID;
		}
		$path = RC_DIR . 'seed/evidence/' . $file;
		if ( ! is_readable( $path ) ) {
			self::say( 'Missing evidence file: ' . $file );
			return 0;
		}
		$upload = wp_upload_bits( $file, null, (string) file_get_contents( $path ) ); // phpcs:ignore
		$att    = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => wp_check_filetype( $upload['file'] )['type'], 'post_status' => 'inherit' ), $upload['file'] );
		$sha    = hash_file( 'sha256', $upload['file'] );
		update_post_meta( $att, '_rc_sha256', $sha );
		$id = self::upsert(
			'rc_document',
			$slug,
			$title,
			$desc,
			array(
				'file'                => $att,
				'doc_type'            => $type,
				'issued_by'           => $issued_by,
				'issue_date'          => $date,
				'version_label'       => 'Owner-supplied copy',
				'subject'             => $subjects,
				'public_library'      => 'yes',
				'verification_status' => 'pending_verification',
			)
		);
		update_post_meta( $id, '_rc_sha256', $sha );
		return $id;
	}

	private static function registry( array $p ): void {
		$common_status = array(
			'availability_status' => 'not_offered',
			'custody_status'      => 'pending',
			'reserve_status'      => 'pending',
			'tokenization_status' => 'not_issued',
			'redemption_status'   => 'not_available',
		);

		// Laboratory named on the owner-supplied certificates. No partnership or accreditation is implied.
		$igas = self::upsert(
			'rc_laboratory',
			'igas-research-goslar',
			'IGAS research (named on owner-supplied certificates)',
			'Laboratory named on the two owner-supplied Certificates of Analysis. ReserveChain has not yet independently confirmed the certificates with the laboratory, and no partnership, endorsement or accreditation is implied. Accreditation evidence is pending.',
			array( 'legal_name' => 'IGAS research', 'country' => 'DE', 'verification_status' => 'pending_verification' )
		);

		/* ---------------- Copper: Lot #03-K-07 ---------------- */
		$cu_src = 'Owner-supplied IGAS Certificate of Analysis No. 0004512 (04.07.2022). Net weight as declared by the customer to the laboratory.';
		$cu_lot = self::upsert(
			'rc_lot',
			'cu-lot-03-k-07',
			'Ultrafine Copper Powder: Lot #03-K-07',
			'Owner-supplied lot record. Values are transcribed from the supplied Certificate of Analysis and remain subject to documentary verification, independent assessment and final approval. Physical-property fields not covered by the certificate are shown as pending.',
			array_merge(
				$common_status,
				array(
					'program'           => $p['cu'],
					'lot_reference'     => '03-K-07',
					'product_name'      => 'Ultrafine Copper Powder',
					'material_form'     => 'powder',
					'net_weight'        => '2000',
					'declared_purity'   => '99,9999 % (as stated on supplied CoA 0004512 — chemical purity based on the impurities Al, Cd, Fe, Mg, Mo, Ni, Sb, Ti, Zn per TU 1793-011-50316079-2004)',
					'packaging_type'    => 'Glass ampoules, packed in cardboard boxes (per supplied CoA)',
					'cu_container_type' => 'Cardboard boxes containing glass ampoules (per supplied CoA)',
					'data_source'       => $cu_src,
					'verification_status' => 'pending_verification',
				)
			)
		);
		$cu_box = self::upsert(
			'rc_container',
			'cu-lot-03-k-07-box-20',
			'Lot #03-K-07: Box no. 20 (sampled box)',
			'Box identified on the supplied certificate as the sampling source (10 g sample taken 01.07.2022). Box count and per-box net weight for the remainder of the lot have not yet been provided.',
			array_merge( $common_status, array( 'program' => $p['cu'], 'lot' => $cu_lot, 'container_id' => 'Box no. 20', 'verification_status' => 'pending_verification' ) )
		);

		/* ---------------- Nickel: Lot 120/NP1, 30 bobbins ---------------- */
		$ni_src = 'Owner-supplied IGAS Certificate of Analysis No. 0004368 (19.10.2021). Net weight as declared by the customer to the laboratory.';
		$ni_lot = self::upsert(
			'rc_lot',
			'ni-lot-120-np1',
			'Nickel Wire 0.025 mm: Lot 120/NP1',
			'Owner-supplied lot record ("Nickel wire 0,025 mm dia, DKRNT NP1"). Values are transcribed from the supplied Certificate of Analysis and remain subject to documentary verification, independent assessment and final approval. Mechanical and dimensional-tolerance fields are not covered by the certificate and are shown as pending.',
			array_merge(
				$common_status,
				array(
					'program'             => $p['ni'],
					'lot_reference'       => '120/NP1',
					'product_name'        => 'Nickel wire 0,025 mm dia, DKRNT NP1',
					'material_form'       => 'wire',
					'net_weight'          => '5',
					'number_of_units'     => '30',
					'packaging_type'      => '30 bobbins in 1 box (per supplied CoA)',
					'ni_diameter'         => '0,025 mm (as described on supplied CoA — dimensional inspection pending)',
					'ni_packaging_method' => 'Bobbins, packed in one box (per supplied CoA)',
					'declared_purity'     => '99,9807 % (as stated on supplied CoA 0004368 — impurities As, Cu, Fe, Mn, Pb, Si per GOST 2179-75 total 0,0193 %)',
					'data_source'         => $ni_src,
					'verification_status' => 'pending_verification',
				)
			)
		);
		for ( $i = 1; $i <= 30; $i++ ) {
			$sampled = in_array( $i, array( 8, 10, 19, 27 ), true );
			self::upsert(
				'rc_coil',
				sprintf( 'ni-lot-120-np1-bobbin-%02d', $i ),
				sprintf( 'Lot 120/NP1: Bobbin no. %d%s', $i, $sampled ? ' (sampled)' : '' ),
				$sampled ? 'One of the four bobbins sampled by the laboratory on 14.10.2021 (per supplied CoA). Individual bobbin weight and length have not yet been provided.' : 'Bobbin within Lot 120/NP1 (30 bobbins per supplied CoA). Individual bobbin weight and length have not yet been provided.',
				array_merge( $common_status, array( 'program' => $p['ni'], 'lot' => $ni_lot, 'coil_id' => sprintf( 'Bobbin no. %d', $i ), 'wire_diameter' => '0,025 mm (as described on supplied CoA)', 'verification_status' => 'pending_verification' ) )
			);
		}

		/* ---------------- Evidence: certificate scans + transcriptions ---------------- */
		$cu_doc = self::evidence_file( 'igas-coa-0004512', 'igas-coa-0004512-copper-powder.png', 'Certificate of Analysis No. 0004512: Ultrafine Copper Powder (owner-supplied copy)', 'coa', 'IGAS research, Goslar (as shown on certificate)', '2022-07-04', array( $cu_lot ), 'Scan of the owner-supplied certificate. The customer address block is redacted on the supplied copy. Publication subject to approval; not yet independently confirmed with the issuing laboratory.' );
		$ni_doc = self::evidence_file( 'igas-coa-0004368', 'igas-coa-0004368-nickel-wire.png', 'Certificate of Analysis No. 0004368: Nickel Wire 0.025 mm (owner-supplied copy)', 'coa', 'IGAS research, Goslar (as shown on certificate)', '2021-10-19', array( $ni_lot ), 'Scan of the owner-supplied certificate. The customer address block is redacted on the supplied copy. Publication subject to approval; not yet independently confirmed with the issuing laboratory.' );

		self::upsert(
			'rc_coa',
			'coa-0004512',
			'CoA 0004512: Ultrafine Copper Powder, Lot #03-K-07',
			'Exact transcription of the owner-supplied certificate. Values are reproduced as printed (comma decimals).',
			array(
				'subject'              => array( $cu_lot ),
				'laboratory'           => $igas,
				'document'             => $cu_doc,
				'certificate_number'   => '0004512',
				'issue_date'           => '2022-07-04',
				'method'               => 'ICP/OES',
				'result_purity'        => '99,9999 %',
				'purity_basis'         => 'Chemical purity based on the impurities Al, Cd, Fe, Mg, Mo, Ni, Sb, Ti, Zn (TU 1793-011-50316079-2004)',
				'goods_description'    => 'Ultrafine Copper Powder, Lot #03-K-07',
				'declared_quantity'    => '2000 kg* in glass ampoules, packed in cardboard boxes (*net weight according to data supplied by customer)',
				'sample_description'   => '10 g, taken by the laboratory at ProSafe in Magdeburg, Box no. 20',
				'sampling_location'    => 'ProSafe, Magdeburg (DE)',
				'sampling_date'        => '2022-07-01',
				'isotopic_composition' => 'Natural copper: 63Cu 69.1 % ± 0.05 %, 65Cu 30.9 % ± 0.05 %',
				'radioactivity'        => 'The material is not radioactive (per certificate)',
				'assay_results'        => "Ag: 8\nAl: <1\nAs: 4\nAu: <1\nB: <1\nBa: <1\nBi: <0,5\nCa: <1\nCd: <0,5\nCo: <0,5\nCr: <0,5\nFe: <1\nHg: <1\nK: <1\nLi: <1\nMg: <1\nMn: <0,5\nMo: <0,5\nNa: <1\nNi: <0,5\nP: 5\nPb: 1\nS: 16\nSb: 1\nSn: <0,5\nSr: <1\nTi: <0,5\nV: <0,5\nZn: <0,5\nZr: <0,5",
				'provenance'           => 'owner_supplied',
				'verification_status'  => 'pending_verification',
			)
		);
		self::upsert(
			'rc_coa',
			'coa-0004368',
			'CoA 0004368: Nickel Wire 0.025 mm, Lot 120/NP1',
			'Exact transcription of the owner-supplied certificate. Values are reproduced as printed (comma decimals).',
			array(
				'subject'            => array( $ni_lot ),
				'laboratory'         => $igas,
				'document'           => $ni_doc,
				'certificate_number' => '0004368',
				'issue_date'         => '2021-10-19',
				'method'             => 'ICP/MS and ICP/OES',
				'result_purity'      => '99,9807 %',
				'purity_basis'       => 'Impurities acc. GOST 2179-75 (As, Cu, Fe, Mn, Pb, Si): 0,0193 % by weight',
				'goods_description'  => 'Nickel wire 0,025 mm dia, DKRNT NP1, Lot "120/NP1"',
				'declared_quantity'  => 'Total net weight 5000 g*: 30 bobbins in 1 box (*net weight according to information given by customer)',
				'sample_description' => '0,8 g, taken from bobbins no. 8, 10, 19, 27 by the laboratory',
				'sampling_location'  => 'Goslar (DE)',
				'sampling_date'      => '2021-10-14',
				'impurity_statement' => 'The concentration of impurities acc. GOST 2179-75 (As, Cu, Fe, Mn, Pb, Si) to be considered in this sample is 0,0193 % by weight.',
				'radioactivity'      => 'The material is not radioactive (per certificate)',
				'assay_results'      => "Ag: <0,5\nAl: <1\nAs: 13\nB: <1\nBi: <0,1\nCa: <1\nCd: <0,1\nCo: 41\nCr: <1\nCu: 45\nFe: 116\nHf: <0,1\nK: 41\nMg: <1\nMn: 3\nMo: <0,5\nNa: <1\nNb: <0,1\nNi: Matrix\nP: <1\nPb: 3\nPd: <0,1\nPt: <0,1\nS: <1\nSb: <0,5\nSe: <1\nSi: 13\nSn: <0,5\nTa: <0,5\nTi: 227\nU: <0,1\nV: <0,5\nW: <0,5\nZn: <1\nZr: <0,5",
				'provenance'         => 'owner_supplied',
				'verification_status' => 'pending_verification',
			)
		);

		/* ---------------- Mandated illustrative template (MASTER §13) ---------------- */
		self::upsert(
			'rc_lot',
			'illustrative-industrial-metal-asset-template',
			'Illustrative Industrial Metal Asset Template',
			'This presentation demonstrates the future format of a ReserveChain industrial-metal asset page. No verified material, ownership document, laboratory report, valuation, custody arrangement, reserve claim or token is represented by this placeholder.',
			array_merge( $common_status, array( 'verification_status' => 'not_applicable' ) )
		);

		/* ---------------- Reference documents (templates / policies) ---------------- */
		$notice = array( 'small', Settings::DISCLOSURE );
		self::document(
			'specimen-coa-template',
			'Certificate of Analysis: required-fields template',
			'specimen',
			array(
				array( 'h1', 'Certificate of Analysis: REQUIRED-FIELDS TEMPLATE' ),
				array( 'p', 'THIS IS NOT A CERTIFICATE. It lists the fields ReserveChain requires from an accredited laboratory before a Certificate of Analysis can be accepted as verified evidence for a Digital Asset Passport.' ),
				array( 'rule' ),
				array( 'h2', 'Required fields' ),
				array( 'p', 'Laboratory legal name · Accreditation body and number (e.g. ISO/IEC 17025) · Certificate number · Sample identification and sampling method · Asset reference (lot / batch / container / coil) · Analytical method(s) · Element / impurity results with units and uncertainty · Purity basis and standard reference · Date of sampling and analysis · Authorised signatory · Verification URL or digital signature.' ),
				array( 'h2', 'How documents are registered' ),
				array( 'p', 'On upload the platform computes the SHA-256 fingerprint of the exact file. The fingerprint enters the passport evidence Merkle root and can be checked by anyone on the Verification page without uploading the file.' ),
				array( 'rule' ),
				$notice,
			),
			array( $cu_lot, $ni_lot ),
			'not_applicable'
		);
		self::document(
			'dap-data-model',
			'Digital Asset Passport: data model specification',
			'policy',
			array(
				array( 'h1', 'Digital Asset Passport: data model (reservechain.dap/1.0)' ),
				array( 'p', 'A Digital Asset Passport is assembled from registry records; it is never typed by hand. This document describes the structure published at /passport/{passport_no}/ and its machine-readable JSON form (?format=json).' ),
				array( 'h2', '1. Identity' ),
				array( 'p', 'passport_no (immutable, e.g. RC-CU-LOT-000001), entity type (lot, batch, container, coil), program (element symbol and atomic number), verification, custody, reserve, tokenization and redemption status.' ),
				array( 'h2', '2. Provenance' ),
				array( 'p', 'Lineage container -> batch -> lot and coil -> lot. Evidence attached to a parent is inherited by its children and labelled as inherited.' ),
				array( 'h2', '3. Evidence' ),
				array( 'p', 'Certificates of Analysis, custody intake and transfer records, legal ownership records, insurance, independent valuation and reserve attestations. Each evidence record carries its own status, provenance (owner-supplied / laboratory-direct / independently verified) and a fingerprinted document.' ),
				array( 'h2', '4. Lifecycle (derived)' ),
				array( 'p', 'A stage is shown as verified only if a verified evidence record exists; as evidence received - pending verification if evidence exists but is not verified; otherwise as pending with an explanatory note.' ),
				array( 'h2', '5. Integrity' ),
				array( 'p', 'record_fingerprint = SHA-256 over the public identity fields. merkle_root = SHA-256 Merkle tree over {record_fingerprint} and all document fingerprints, leaves sorted, pairs sorted before hashing, odd leaf paired with itself.' ),
				array( 'rule' ),
				$notice,
			),
			array( $cu_lot, $ni_lot ),
			'in_development'
		);
		self::document(
			'prelaunch-disclosure',
			'Pre-launch disclosure and jurisdiction notice',
			'legal',
			array(
				array( 'h1', 'Pre-launch disclosure' ),
				array( 'p', Settings::DISCLOSURE ),
				array( 'h2', 'Provisional Asset Notice' ),
				array( 'p', Settings::PROVISIONAL_NOTICE ),
				array( 'h2', 'EU / EEA' ),
				array( 'p', Settings::EU_NOTICE ),
				array( 'rule' ),
				array( 'small', 'Disclosure fingerprint: ' . Settings::disclosure_hash() ),
			),
			array( $p['cu'], $p['ni'] ),
			'in_development'
		);

		$wp_pdf = RC_DIR . 'seed/evidence/reservechain-whitepaper-draft.pdf';
		if ( is_readable( $wp_pdf ) && ! get_page_by_path( 'reservechain-whitepaper-draft-v0-9', OBJECT, 'rc_document' ) ) {
			$wid = self::import_document( $wp_pdf, 'ReserveChain Whitepaper: Draft v0.9', 'whitepaper', true, 'Draft v0.9' );
			if ( $wid ) {
				wp_update_post( array( 'ID' => $wid, 'post_name' => 'reservechain-whitepaper-draft-v0-9', 'post_content' => 'Draft in preparation. Pending owner inputs are clearly marked; final version subject to legal review and approval.' ) );
				update_post_meta( $wid, '_rc_issued_by', 'ReserveChain (draft: subject to legal review)' );
			}
		}
		self::say( 'Registry: Cu Lot #03-K-07 (+Box 20), Ni Lot 120/NP1 (+30 bobbins), 2 owner-supplied CoAs transcribed, illustrative template, 5 fingerprinted documents.' );
	}

	/** Leave realistic items in the workflow so reviewers can exercise four-eyes approval. */
	private static function review_items(): void {
		$author = get_user_by( 'login', 'demo.registry' );
		if ( ! $author || get_page_by_path( 'specimen-custody-intake-cu', OBJECT, 'rc_custody' ) ) {
			return;
		}
		wp_set_current_user( $author->ID );
		$lot = get_page_by_path( 'cu-lot-03-k-07', OBJECT, 'rc_lot' );
		$id  = Workflow::bypass(
			static fn() => self::upsert( 'rc_custody', 'specimen-custody-intake-cu', 'Custody intake: Lot #03-K-07 (draft for review)', 'Draft custody intake record prepared by the registry manager and awaiting four-eyes review. No custodian has been appointed; the custodian field is intentionally empty.', array( 'subject' => array( $lot->ID ), 'record_type' => 'custody_intake', 'verification_status' => 'pending_verification' ), 'draft' )
		);
		update_post_meta( $id, '_edit_last', $author->ID );
		Workflow::apply( 'submit', $id, 'Seeded for workflow demonstration.' );

		$faq = get_page_by_path( 'resources/faq' );
		if ( $faq ) {
			$draft = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Proposed update: FAQ additions (draft)', 'post_content' => '<p>Draft content awaiting review. Demonstrates that page edits follow the same four-eyes workflow as registry records.</p>', 'post_status' => 'draft', 'post_author' => $author->ID ) );
			update_post_meta( $draft, '_edit_last', $author->ID );
			Workflow::apply( 'submit', (int) $draft, 'Seeded for workflow demonstration.' );
		}
		wp_set_current_user( 0 );
		self::say( 'Review queue: 2 items submitted by demo.registry awaiting approval.' );
	}

	/* ------------------------------------------------------------ pages */

	/**
	 * Synchronise pages from seed/pages/*.html. File name = URL path with "/" written as "__",
	 * e.g. assets__industrial-metals__copper-powder.html. Optional {name}.es.html / {name}.it.html.
	 * Header comment keys: title, excerpt, order, kicker, template, seo_title, seo_desc.
	 */
	public static function pages(): void {
		$dir  = RC_DIR . 'seed/pages/';
		$defs = array();
		foreach ( glob( $dir . '*.html' ) ?: array() as $file ) {
			$base = basename( $file, '.html' );
			if ( preg_match( '/\.(es|it)$/', $base ) ) {
				continue;
			}
			$path          = 'home' === $base ? 'home' : str_replace( '__', '/', $base );
			$defs[ $path ] = array_merge( self::parse_page( $file ), array( 'base' => $base ) );
		}
		uksort( $defs, static fn( $a, $b ) => substr_count( $a, '/' ) <=> substr_count( $b, '/' ) ?: strcmp( $a, $b ) );

		$ids = array();
		foreach ( $defs as $path => $d ) {
			$slug      = basename( $path );
			$parent    = false !== strpos( $path, '/' ) ? dirname( $path ) : '';
			$parent_id = $parent ? ( $ids[ $parent ] ?? ( get_page_by_path( $parent )->ID ?? 0 ) ) : 0;
			if ( $parent && ! $parent_id ) {
				self::say( 'Skipped (missing parent): ' . $path );
				continue;
			}
			$existing = get_page_by_path( $path );
			if ( $existing && ! self::$force ) {
				$ids[ $path ] = (int) $existing->ID; // CMS edits win; use --force to resynchronise from seed files.
				continue;
			}
			$args     = array(
				'post_type'     => 'page',
				'post_name'     => $slug,
				'post_title'    => $d['title'] ?: ucwords( str_replace( '-', ' ', $slug ) ),
				'post_content'  => $d['content'],
				'post_excerpt'  => $d['excerpt'],
				'post_status'   => 'publish',
				'post_parent'   => $parent_id,
				'menu_order'    => (int) $d['order'],
				'page_template' => $d['template'] ?: 'default',
			);
			if ( $existing ) {
				$args['ID'] = $existing->ID;
			}
			$id            = $existing ? wp_update_post( $args ) : wp_insert_post( $args );
			$ids[ $path ]  = (int) $id;
			update_post_meta( $id, '_rct_kicker', $d['kicker'] ?? '' );
			if ( ! empty( $d['seo_title'] ) ) {
				update_post_meta( $id, '_rct_seo_title', $d['seo_title'] );
			}
			if ( ! empty( $d['seo_desc'] ) ) {
				update_post_meta( $id, '_rct_seo_desc', $d['seo_desc'] );
			}
			foreach ( array( 'es', 'it' ) as $lang ) {
				$tf = $dir . $d['base'] . '.' . $lang . '.html';
				if ( is_readable( $tf ) ) {
					$t = self::parse_page( $tf );
					update_post_meta( $id, "_rc_i18n_{$lang}_title", $t['title'] );
					update_post_meta( $id, "_rc_i18n_{$lang}_content", $t['content'] );
					update_post_meta( $id, "_rc_i18n_{$lang}_excerpt", $t['excerpt'] );
					if ( ! empty( $t['kicker'] ) ) {
						update_post_meta( $id, "_rc_i18n_{$lang}_kicker", $t['kicker'] );
					}
				}
			}
		}
		if ( isset( $ids['home'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $ids['home'] );
		}
		self::say( sprintf( 'Pages synchronised: %d.', count( $ids ) ) );
	}

	private static function parse_page( string $file ): array {
		$raw  = (string) file_get_contents( $file ); // phpcs:ignore
		$meta = array( 'title' => '', 'order' => 0, 'excerpt' => '', 'template' => '', 'kicker' => '' );
		if ( preg_match( '/^\s*<!--(.*?)-->/s', $raw, $m ) ) {
			foreach ( preg_split( '/\r?\n/', trim( $m[1] ) ) as $line ) {
				if ( preg_match( '/^\s*(\w+)\s*:\s*(.*)$/', $line, $kv ) ) {
					$meta[ strtolower( $kv[1] ) ] = trim( $kv[2] );
				}
			}
			$raw = substr( $raw, strpos( $raw, '-->' ) + 3 );
		}
		$meta['content'] = trim( $raw );
		return $meta;
	}

	/** Short footer labels (translated through the UI dictionaries). */
	public const FOOTER_LABELS = array(
		'platform/how-it-works' => 'How it works',
		'platform/verification' => 'Verification',
		'platform/custody' => 'Custody',
		'platform/proof-of-reserves' => 'Proof of Reserves',
		'platform/digital-asset-passports' => 'Asset Passports',
		'platform/tokenization' => 'Tokenization',
		'assets/industrial-metals/copper-powder' => 'Copper Powder',
		'assets/industrial-metals/nickel-wire' => 'Nickel Wire 0.025 mm',
		'platform/asset-registry' => 'Asset Registry',
		'assets/future-categories' => 'Future categories',
		'participation' => 'Overview',
		'participation/waitlist' => 'Join the waitlist',
		'participation/eligibility-kyc' => 'Eligibility & KYC',
		'participation/restricted-jurisdictions' => 'Restricted jurisdictions',
		'enterprise' => 'Enterprise',
		'company' => 'About',
		'company/development-status' => 'Development status',
		'company/roadmap' => 'Roadmap',
		'resources/documentation' => 'Documentation',
		'company/contact' => 'Contact',
		'legal' => 'Legal & disclosures',
		'legal/risk-disclosure' => 'Risk disclosure',
		'legal/anti-fraud' => 'Anti-fraud',
		'legal/privacy' => 'Privacy',
		'legal/terms' => 'Terms of use',
		'legal/cookies' => 'Cookies',
	);

	/** Information architecture from the Website Developer Instructions (p.3) — same IA on desktop and mobile. */
	public const IA = array(
		'Platform'      => array( 'platform', 'platform/how-it-works', 'platform/infrastructure', 'platform/technology', 'platform/security', 'platform/verification', 'platform/custody', 'platform/proof-of-reserves', 'platform/digital-asset-passports', 'platform/asset-registry', 'platform/tokenization', 'platform/redemption' ),
		'Assets'        => array( 'assets', 'assets/programs', 'assets/initial-programs', 'assets/industrial-metals', 'assets/industrial-metals/copper-powder', 'assets/industrial-metals/nickel-wire', 'assets/future-categories' ),
		'Enterprise'    => array( 'enterprise', 'enterprise/tokenization-services', 'enterprise/licensing-white-label', 'enterprise/asset-owners', 'enterprise/industrial-buyers' ),
		'Participation' => array( 'participation', 'participation/overview', 'participation/how-token-acquisition-will-work', 'participation/discount-methodology', 'participation/eligibility-kyc', 'participation/restricted-jurisdictions', 'participation/waitlist', 'investors', 'resources/investor-presentation' ),
		'Company'       => array( 'company', 'project-overview', 'company/development-status', 'company/legal-structure', 'company/governance', 'company/roadmap', 'company/news', 'company/official-channels', 'company/contact' ),
		'Resources'     => array( 'resources', 'resources/documentation', 'resources/whitepaper', 'resources/faq', 'resources/contract-addresses', 'legal/risk-disclosure', 'legal/anti-fraud', 'legal', 'support', 'portal', 'portal/redemption' ),
	);

	private static function menus(): void {
		if ( ! self::$force && wp_get_nav_menu_object( 'Primary' ) ) {
			self::say( 'Menus exist: left unchanged (use --force to rebuild).' );
			return;
		}
		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$reset     = static function ( string $name ) {
			$menu = wp_get_nav_menu_object( $name );
			$mid  = $menu ? $menu->term_id : wp_create_nav_menu( $name );
			foreach ( (array) wp_get_nav_menu_items( $mid ) as $item ) {
				wp_delete_post( $item->ID, true );
			}
			return $mid;
		};
		$add = static function ( int $mid, string $path, int $parent = 0, int $pos = 0, string $title = '' ) {
			$page = get_page_by_path( $path );
			if ( ! $page ) {
				return 0;
			}
			return (int) wp_update_nav_menu_item( $mid, 0, array( 'menu-item-object-id' => $page->ID, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent, 'menu-item-position' => $pos, 'menu-item-title' => $title ) );
		};

		// Primary: mega menu (group → pages).
		$mid = $reset( 'Primary' );
		$pos = 0;
		foreach ( self::IA as $group => $paths ) {
			$top = $add( $mid, $paths[0], 0, ++$pos, $group );
			foreach ( $paths as $path ) {
				$add( $mid, $path, $top, ++$pos );
			}
		}
		$locations['primary'] = $mid;

		// Footer: five curated columns (the full IA lives in the header mega menu).
		$footer = array(
			'Platform'      => array( 'platform/how-it-works', 'platform/verification', 'platform/custody', 'platform/proof-of-reserves', 'platform/digital-asset-passports', 'platform/tokenization' ),
			'Assets'        => array( 'assets/industrial-metals/copper-powder', 'assets/industrial-metals/nickel-wire', 'platform/asset-registry', 'assets/future-categories' ),
			'Participation' => array( 'participation', 'participation/waitlist', 'participation/eligibility-kyc', 'participation/restricted-jurisdictions', 'enterprise' ),
			'Company'       => array( 'company', 'company/development-status', 'company/roadmap', 'resources/documentation', 'company/contact' ),
			'Legal'         => array( 'legal', 'legal/risk-disclosure', 'legal/anti-fraud', 'legal/privacy', 'legal/terms', 'legal/cookies' ),
		);
		for ( $k = 6; $k <= 7; $k++ ) {
			unset( $locations[ 'footer-' . $k ] );
		}
		$n = 0;
		foreach ( $footer as $group => $paths ) {
			$fid = $reset( 'Footer: ' . $group );
			foreach ( $paths as $i => $path ) {
				$add( $fid, $path, 0, $i + 1, self::FOOTER_LABELS[ $path ] ?? '' );
			}
			$locations[ 'footer-' . ( ++$n ) ] = $fid;
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		self::say( 'Menus built: mega menu (6 groups) + 5 footer columns.' );
	}
}
