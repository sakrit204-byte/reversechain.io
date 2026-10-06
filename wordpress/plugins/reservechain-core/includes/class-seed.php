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

	public static function run( bool $pages_only = false ): void {
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
			'Copper Powder',
			'Proposed program for ultra-high-purity copper powder held as the reference asset of a future, separately approved token program. Specifications, quantities, producers, custody and valuation are pending and will only be published once evidenced.',
			array(
				'symbol'              => 'Cu',
				'atomic_number'       => 29,
				'industrial_uses'     => 'High-purity copper powders are used across powder metallurgy, additive manufacturing, electronics (conductive pastes and thermal management), brazing and friction materials. Purity grade and particle-size distribution determine suitability for each application.',
				'verification_status' => 'in_development',
			),
			'publish',
			'Ultra-high-purity copper powder — proposed program, in development.'
		);
		update_post_meta( $cu, '_rc_claims', $claims_common );
		wp_update_post( array( 'ID' => $cu, 'menu_order' => 1 ) );

		$ni = self::upsert(
			'rc_program',
			'nickel-wire',
			'Nickel Wire',
			'Proposed program for high-purity nickel wire held as the reference asset of a future, separately approved token program. Specifications, quantities, producers, custody and valuation are pending and will only be published once evidenced.',
			array(
				'symbol'              => 'Ni',
				'atomic_number'       => 28,
				'industrial_uses'     => 'High-purity nickel wire is used in electronics, battery and energy-storage interconnects, heating and resistance elements, sensors, lead wires and specialised welding. Purity, diameter tolerance and temper determine suitability for each application.',
				'verification_status' => 'in_development',
			),
			'publish',
			'High-purity nickel wire — proposed program, in development.'
		);
		update_post_meta( $ni, '_rc_claims', $claims_common );
		wp_update_post( array( 'ID' => $ni, 'menu_order' => 2 ) );

		foreach ( array( $cu => 'Copper Powder', $ni => 'Nickel Wire' ) as $pid => $name ) {
			self::upsert(
				'rc_token_program',
				sanitize_title( $name . ' token program' ),
				$name . ' — proposed token program',
				'Placeholder token program. All tokenomics parameters are intentionally unset and require written approval before publication.',
				array( 'program' => $pid, 'network' => 'sepolia', 'verification_status' => 'proposed' )
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

	private static function registry( array $p ): void {
		$lab = self::upsert( 'rc_laboratory', 'laboratory-to-be-appointed', 'Accredited laboratory — to be appointed', 'Placeholder. No laboratory has been appointed. ReserveChain intends to work with laboratories holding ISO/IEC 17025 accreditation for the relevant methods.', array( 'verification_status' => 'proposed' ) );

		$cu_lot = self::upsert( 'rc_lot', 'specimen-cu-lot-1', 'Specimen lot — Copper Powder', 'SPECIMEN RECORD. Demonstrates the Digital Asset Passport structure. It does not represent physical material held by or for ReserveChain.', array( 'program' => $p['cu'], 'verification_status' => 'in_development' ) );
		$cu_bat = self::upsert( 'rc_batch', 'specimen-cu-batch-1', 'Specimen batch — Copper Powder', 'SPECIMEN RECORD. Child batch of the specimen lot.', array( 'program' => $p['cu'], 'lot' => $cu_lot, 'verification_status' => 'in_development' ) );
		$cu_ctn = self::upsert( 'rc_container', 'specimen-cu-container-1', 'Specimen container — Copper Powder', 'SPECIMEN RECORD. Sealed container within the specimen batch.', array( 'program' => $p['cu'], 'batch' => $cu_bat, 'verification_status' => 'in_development' ) );
		$ni_lot = self::upsert( 'rc_lot', 'specimen-ni-lot-1', 'Specimen lot — Nickel Wire', 'SPECIMEN RECORD. Demonstrates the Digital Asset Passport structure. It does not represent physical material held by or for ReserveChain.', array( 'program' => $p['ni'], 'verification_status' => 'in_development' ) );
		$ni_col = self::upsert( 'rc_coil', 'specimen-ni-coil-1', 'Specimen coil — Nickel Wire', 'SPECIMEN RECORD. Spool within the specimen nickel lot.', array( 'program' => $p['ni'], 'lot' => $ni_lot, 'verification_status' => 'in_development' ) );

		$notice = array( 'small', Settings::DISCLOSURE );
		$coa_tpl = self::document(
			'specimen-coa-template',
			'Certificate of Analysis — specimen template',
			'specimen',
			array(
				array( 'h1', 'Certificate of Analysis — SPECIMEN TEMPLATE' ),
				array( 'p', 'THIS IS NOT A CERTIFICATE. It contains no analytical results. It illustrates the fields ReserveChain will require from an accredited laboratory before a Certificate of Analysis can be linked to a Digital Asset Passport.' ),
				array( 'rule' ),
				array( 'h2', 'Required fields' ),
				array( 'p', 'Laboratory legal name · Accreditation body and number (e.g. ISO/IEC 17025) · Certificate number · Sample identification and sampling method · Asset reference (lot / batch / container / coil) · Analytical method(s) · Element / impurity results with units and uncertainty · Date of analysis · Authorised signatory · Digital signature or verification URL.' ),
				array( 'h2', 'How this document is registered' ),
				array( 'p', 'On upload, the platform computes the SHA-256 fingerprint of the exact file. The fingerprint is included in the passport evidence Merkle root and can be checked by anyone on the Verification page without uploading the file.' ),
				array( 'rule' ),
				$notice,
			),
			array( $cu_lot, $ni_lot ),
			'not_applicable'
		);

		$dap_spec = self::document(
			'dap-data-model',
			'Digital Asset Passport — data model specification',
			'policy',
			array(
				array( 'h1', 'Digital Asset Passport — data model (reservechain.dap/1.0)' ),
				array( 'p', 'A Digital Asset Passport is assembled from registry records; it is never typed by hand. This document describes the structure published at /passport/{passport_no}/ and its machine-readable JSON form (?format=json).' ),
				array( 'h2', '1. Identity' ),
				array( 'p', 'passport_no (immutable, e.g. RC-CU-LOT-000001), entity type (lot, batch, container, coil), program (element symbol and atomic number), verification status (proposed, in development, pending verification, verified, not applicable).' ),
				array( 'h2', '2. Provenance' ),
				array( 'p', 'Lineage container -> batch -> lot (and coil -> lot). Evidence attached to a parent is inherited by its children and labelled as inherited.' ),
				array( 'h2', '3. Evidence' ),
				array( 'p', 'Certificates of Analysis, custody intake and transfer records, legal ownership records, insurance, independent valuation and reserve attestations. Each evidence record carries its own status and links to a fingerprinted document.' ),
				array( 'h2', '4. Lifecycle (derived)' ),
				array( 'p', 'Each stage is computed from evidence: a stage is shown as verified only if a verified evidence record exists; as pending verification if evidence exists but is not yet verified; otherwise as pending with an explanatory note.' ),
				array( 'h2', '5. Integrity' ),
				array( 'p', 'record_fingerprint = SHA-256 over the public identity fields. merkle_root = SHA-256 Merkle tree over {record_fingerprint} U {document fingerprints}, leaves sorted, pairs sorted before hashing, odd leaf paired with itself.' ),
				array( 'h2', '6. Completeness' ),
				array( 'p', 'present / total over required identity fields and evidence stages. Missing information is shown, never estimated.' ),
				array( 'rule' ),
				$notice,
			),
			array( $cu_lot, $ni_lot ),
			'in_development'
		);

		self::document(
			'prelaunch-disclosure',
			'Prelaunch disclosure and jurisdiction notice',
			'legal',
			array(
				array( 'h1', 'Prelaunch disclosure' ),
				array( 'p', Settings::DISCLOSURE ),
				array( 'h2', 'EU / EEA' ),
				array( 'p', Settings::EU_NOTICE ),
				array( 'h2', 'Language' ),
				array( 'p', 'Statements about programs, custody, insurance, Proof of Reserves, liquidity, redemption, ownership rights or token parameters describe proposed or planned arrangements in development and subject to final approval. None of them is confirmed.' ),
				array( 'rule' ),
				array( 'small', 'Disclosure fingerprint: ' . Settings::disclosure_hash() ),
			),
			array( $p['cu'], $p['ni'] ),
			'in_development'
		);

		self::upsert(
			'rc_coa',
			'specimen-coa-record-cu',
			'Specimen CoA record — awaiting laboratory certificate',
			'SPECIMEN evidence record. Linked to the CoA template, not to an issued certificate. Results are intentionally empty.',
			array( 'subject' => array( $cu_lot ), 'laboratory' => $lab, 'document' => $coa_tpl, 'verification_status' => 'pending_verification' )
		);

		self::say( 'Specimen registry: Cu lot/batch/container, Ni lot/coil, laboratory placeholder, 3 fingerprinted documents.' );
	}

	/** Leave realistic items in the workflow so reviewers can exercise four-eyes approval. */
	private static function review_items(): void {
		$author = get_user_by( 'login', 'demo.registry' );
		if ( ! $author || get_page_by_path( 'specimen-custody-intake-cu', OBJECT, 'rc_custody' ) ) {
			return;
		}
		wp_set_current_user( $author->ID );
		$lot = get_page_by_path( 'specimen-cu-lot-1', OBJECT, 'rc_lot' );
		$id  = Workflow::bypass(
			static fn() => self::upsert( 'rc_custody', 'specimen-custody-intake-cu', 'Specimen custody intake — draft for review', 'SPECIMEN. Draft custody intake record created by the registry manager and awaiting four-eyes review. The custodian field is intentionally empty.', array( 'subject' => array( $lot->ID ), 'record_type' => 'custody_intake', 'verification_status' => 'pending_verification' ), 'draft' )
		);
		update_post_meta( $id, '_edit_last', $author->ID );
		Workflow::apply( 'submit', $id, 'Seeded for workflow demonstration.' );

		$faq = get_page_by_path( 'faq' );
		if ( $faq ) {
			$draft = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Proposed update — FAQ additions (draft)', 'post_content' => '<p>Draft content awaiting review. Demonstrates that page edits follow the same four-eyes workflow as registry records.</p>', 'post_status' => 'draft', 'post_author' => $author->ID ) );
			update_post_meta( $draft, '_edit_last', $author->ID );
			Workflow::apply( 'submit', (int) $draft, 'Seeded for workflow demonstration.' );
		}
		wp_set_current_user( 0 );
		self::say( 'Review queue: 2 items submitted by demo.registry awaiting approval.' );
	}

	/* ------------------------------------------------------------ pages */

	public static function pages(): void {
		$dir   = RC_DIR . 'seed/pages/';
		$files = glob( $dir . '*.html' ) ?: array();
		$ids   = array();
		$defs  = array();
		foreach ( $files as $file ) {
			$base = basename( $file, '.html' );
			if ( preg_match( '/\.(es|it)$/', $base ) ) {
				continue;
			}
			$defs[ $base ] = self::parse_page( $file );
		}
		uasort( $defs, static fn( $a, $b ) => ( '' === $a['parent'] ? 0 : 1 ) <=> ( '' === $b['parent'] ? 0 : 1 ) );

		foreach ( $defs as $slug => $d ) {
			$parent_id = $d['parent'] ? ( $ids[ $d['parent'] ] ?? ( get_page_by_path( $d['parent'] )->ID ?? 0 ) ) : 0;
			$path      = $d['parent'] ? $d['parent'] . '/' . $slug : $slug;
			$existing  = get_page_by_path( $path );
			$args      = array( 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $d['title'], 'post_content' => $d['content'], 'post_excerpt' => $d['excerpt'], 'post_status' => 'publish', 'post_parent' => $parent_id, 'menu_order' => (int) $d['order'], 'page_template' => $d['template'] ?: 'default' );
			if ( $existing ) {
				$args['ID'] = $existing->ID;
				$id         = wp_update_post( $args );
			} else {
				$id = wp_insert_post( $args );
			}
			$ids[ $slug ] = (int) $id;
			foreach ( array( 'es', 'it' ) as $lang ) {
				$tf = $dir . $slug . '.' . $lang . '.html';
				if ( is_readable( $tf ) ) {
					$t = self::parse_page( $tf );
					update_post_meta( $id, "_rc_i18n_{$lang}_title", $t['title'] );
					update_post_meta( $id, "_rc_i18n_{$lang}_content", $t['content'] );
					update_post_meta( $id, "_rc_i18n_{$lang}_excerpt", $t['excerpt'] );
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
		$meta = array( 'title' => '', 'parent' => '', 'order' => 0, 'excerpt' => '', 'template' => '' );
		if ( preg_match( '/^<!--(.*?)-->/s', $raw, $m ) ) {
			foreach ( preg_split( '/\r?\n/', trim( $m[1] ) ) as $line ) {
				if ( preg_match( '/^\s*(\w+)\s*:\s*(.*)$/', $line, $kv ) ) {
					$meta[ strtolower( $kv[1] ) ] = trim( $kv[2] );
				}
			}
			$raw = substr( $raw, strlen( $m[0] ) );
		}
		$meta['content'] = trim( $raw );
		return $meta;
	}

	private static function menus(): void {
		$primary = array( 'overview', 'copper-powder', 'nickel-wire', 'asset-registry', 'verification', 'tokenization', 'documents', 'roadmap' );
		$footer  = array(
			'Platform'    => array( 'overview', 'asset-registry', 'digital-asset-passports', 'verification', 'custody', 'proof-of-reserves', 'tokenization', 'redemption' ),
			'Company'     => array( 'enterprise-services', 'governance', 'roadmap', 'documents', 'faq', 'contact', 'waitlist' ),
			'Legal'       => array( 'legal/legal-notice', 'legal/privacy', 'legal/terms', 'legal/cookies', 'legal/risk-disclosure' ),
		);
		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$build     = static function ( string $name, array $slugs ) {
			$menu = wp_get_nav_menu_object( $name );
			$mid  = $menu ? $menu->term_id : wp_create_nav_menu( $name );
			foreach ( (array) wp_get_nav_menu_items( $mid ) as $item ) {
				wp_delete_post( $item->ID, true );
			}
			foreach ( $slugs as $i => $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page ) {
					wp_update_nav_menu_item( $mid, 0, array( 'menu-item-object-id' => $page->ID, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1 ) );
				}
			}
			return $mid;
		};
		$locations['primary'] = $build( 'Primary', $primary );
		$n                    = 1;
		foreach ( $footer as $label => $slugs ) {
			$locations[ 'footer-' . $n ] = $build( 'Footer — ' . $label, $slugs );
			++$n;
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		self::say( 'Menus built: primary + 3 footer columns.' );
	}
}
