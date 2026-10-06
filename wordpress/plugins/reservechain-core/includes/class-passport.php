<?php
/**
 * Digital Asset Passport (DAP) engine.
 *
 * A passport is assembled — never typed — from registry records:
 *  - identity fields of the asset record (with explicit pending states for missing data);
 *  - provenance chain (lot → batch → container / lot → coil) with evidence inheritance;
 *  - evidence records (CoA, custody, ownership, insurance, valuation, reserve reports) that reference it;
 *  - a document ledger with SHA-256 fingerprints;
 *  - an evidence-derived lifecycle: a stage is only "verified" when a verified evidence record exists;
 *  - a Merkle root over the record fingerprint + all evidence fingerprints, so any change is detectable;
 *  - a completeness score that makes gaps visible instead of hiding them.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Passport {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'json_export' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ) );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^passport/([A-Za-z0-9\-]+)/?$', 'index.php?rc_passport=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'rc_passport';
		return $vars;
	}

	public static function current(): ?array {
		static $cached = false;
		if ( false !== $cached ) {
			return $cached;
		}
		$no     = get_query_var( 'rc_passport' );
		$post   = $no ? Registry::find_by_record_no( $no ) : null;
		$cached = ( $post && Schema::is_passport_type( $post->post_type ) && Settings::module_on( 'passports_public' ) ) ? self::build( $post->ID ) : null;
		return $cached;
	}

	public static function json_export(): void {
		if ( ! get_query_var( 'rc_passport' ) || 'json' !== ( $_GET['format'] ?? '' ) ) { // phpcs:ignore
			return;
		}
		$p = self::current();
		if ( ! $p ) {
			status_header( 404 );
			wp_send_json( array( 'error' => 'not_found' ), 404 );
		}
		header( 'Content-Disposition: inline; filename="' . $p['passport_no'] . '.json"' );
		wp_send_json( $p );
	}

	public static function template( string $template ): string {
		if ( ! get_query_var( 'rc_passport' ) ) {
			return $template;
		}
		if ( ! self::current() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return get_404_template() ?: $template;
		}
		status_header( 200 );
		$theme = locate_template( array( 'passport.php' ) );
		return $theme ?: RC_DIR . 'templates/passport.php';
	}

	/**
	 * Assemble a passport.
	 */
	public static function build( int $post_id, bool $include_private = false ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! Schema::is_passport_type( $post->post_type ) ) {
			return null;
		}
		$status = $include_private ? array( 'publish', 'draft', 'rc_review', 'rc_approved', 'rc_unpublished' ) : 'publish';
		$def    = Schema::entity( $post->post_type );
		$no     = get_post_meta( $post_id, '_rc_record_no', true );

		$fields = self::public_fields( $post_id, $include_private );

		$program_id = (int) get_post_meta( $post_id, '_rc_program', true );
		$program    = $program_id ? array(
			'id'            => $program_id,
			'record_no'     => get_post_meta( $program_id, '_rc_record_no', true ),
			'name'          => get_the_title( $program_id ),
			'slug'          => get_post_field( 'post_name', $program_id ),
			'symbol'        => get_post_meta( $program_id, '_rc_symbol', true ),
			'atomic_number' => (int) get_post_meta( $program_id, '_rc_atomic_number', true ) ?: null,
		) : null;

		// Provenance: walk up the parent chain (container → batch → lot).
		$lineage = array();
		$cursor  = $post_id;
		for ( $depth = 0; $depth < 5; $depth++ ) {
			$parent = 0;
			foreach ( array( 'batch', 'lot' ) as $pk ) {
				$v = (int) get_post_meta( $cursor, Schema::meta_key( $pk ), true );
				if ( $v && $v !== $cursor ) {
					$parent = $v;
					break;
				}
			}
			if ( ! $parent ) {
				break;
			}
			$lineage[] = $parent;
			$cursor    = $parent;
		}

		$scope    = array_merge( array( $post_id ), $lineage );
		$evidence = array();
		$ev_types = array( 'rc_coa', 'rc_custody', 'rc_valuation', 'rc_insurance', 'rc_reserve_report' );
		foreach ( $scope as $subject_id ) {
			foreach ( Registry::referencing( $subject_id, $ev_types, $status ) as $ev ) {
				if ( isset( $evidence[ $ev->ID ] ) ) {
					continue;
				}
				$evidence[ $ev->ID ] = array(
					'id'            => $ev->ID,
					'record_no'     => get_post_meta( $ev->ID, '_rc_record_no', true ),
					'type'          => $ev->post_type,
					'type_label'    => Schema::entity( $ev->post_type )['singular'],
					'title'         => $ev->post_title,
					'status'        => get_post_meta( $ev->ID, '_rc_verification_status', true ) ?: 'in_development',
					'record_type'   => get_post_meta( $ev->ID, '_rc_record_type', true ) ?: null,
					'inherited'     => $subject_id !== $post_id ? get_post_meta( $subject_id, '_rc_record_no', true ) : null,
					'date'          => self::first_date( $ev->ID ),
					'fields'        => array_map( static fn( $f ) => array_diff_key( $f, array( 'countable' => 1 ) ), self::public_fields( $ev->ID, $include_private ) ),
					'document_id'   => (int) get_post_meta( $ev->ID, '_rc_document', true ) ?: null,
				);
			}
		}

		// Document ledger: documents referenced by evidence + documents directly related to the asset scope.
		$doc_ids = array_filter( array_column( $evidence, 'document_id' ) );
		foreach ( $scope as $subject_id ) {
			foreach ( Registry::referencing( $subject_id, array( 'rc_document' ), $status ) as $d ) {
				$doc_ids[] = $d->ID;
			}
		}
		$documents = array();
		foreach ( array_unique( $doc_ids ) as $doc_id ) {
			$doc = self::document( (int) $doc_id, $include_private );
			if ( $doc ) {
				$documents[] = $doc;
			}
		}

		$lifecycle = self::lifecycle( $post, $evidence, $program_id, $include_private );

		// Record fingerprint commits to every public identity field.
		$record_fp = hash( 'sha256', wp_json_encode( array( $no, $post->post_type, array_map( static fn( $f ) => array( $f['key'], $f['value'] ), $fields ) ) ) );
		$leaves    = array_merge( array( $record_fp ), array_filter( array_column( $documents, 'sha256' ) ) );
		$merkle    = self::merkle_root( $leaves );

		$counted = array_filter( $fields, static fn( $f ) => $f['countable'] );
		$filled  = count( array_filter( $counted, static fn( $f ) => 'provided' === $f['state'] ) );
		$stages  = array_filter( $lifecycle, static fn( $s ) => $s['counted'] );
		$done    = count( array_filter( $stages, static fn( $s ) => 'verified' === $s['status'] ) );
		$total   = count( $counted ) + count( $stages );
		$present = $filled + $done;

		return array(
			'passport_no'   => $no,
			'entity_type'   => $post->post_type,
			'entity_label'  => $def['singular'],
			'title'         => $post->post_title,
			'description'   => wp_strip_all_tags( $post->post_content ),
			'status'        => get_post_meta( $post_id, '_rc_verification_status', true ) ?: 'in_development',
			'program'       => $program,
			'lineage'       => array_map(
				static fn( $id ) => array(
					'record_no' => get_post_meta( $id, '_rc_record_no', true ),
					'type'      => Schema::entity( get_post_type( $id ) )['singular'] ?? '',
					'title'     => get_the_title( $id ),
				),
				$lineage
			),
			'children'      => array_map(
				static fn( $c ) => array(
					'record_no' => get_post_meta( $c->ID, '_rc_record_no', true ),
					'type'      => Schema::entity( $c->post_type )['singular'],
					'title'     => $c->post_title,
				),
				self::children( $post_id, $status )
			),
			'fields'        => array_values( array_map( static fn( $f ) => array_diff_key( $f, array( 'countable' => 1 ) ), $fields ) ),
			'evidence'      => array_values( $evidence ),
			'documents'     => $documents,
			'timeline'      => $lifecycle,
			'record_fingerprint' => $record_fp,
			'merkle_root'   => $merkle,
			'merkle_leaves' => count( $leaves ),
			'completeness'  => array(
				'present' => $present,
				'total'   => $total,
				'percent' => $total ? (int) round( 100 * $present / $total ) : 0,
			),
			'url'           => home_url( '/passport/' . rawurlencode( $no ) . '/' ),
			'json_url'      => add_query_arg( 'format', 'json', home_url( '/passport/' . rawurlencode( $no ) . '/' ) ),
			'created_at'    => get_post_time( 'c', true, $post ),
			'updated_at'    => get_post_modified_time( 'c', true, $post ),
			'disclosure'    => Settings::get( 'disclosure' ),
			'schema'        => 'reservechain.dap/1.0',
		);
	}

	private static function first_date( int $post_id ): ?string {
		foreach ( array( 'issue_date', 'effective_date', 'valuation_date', 'report_date', 'period_start' ) as $k ) {
			$v = get_post_meta( $post_id, Schema::meta_key( $k ), true );
			if ( $v ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Public field list with explicit provided / pending state.
	 */
	public static function public_fields( int $post_id, bool $include_private = false ): array {
		$def    = Schema::entity( (string) get_post_type( $post_id ) );
		$out    = array();
		$symbol = Registry::program_symbol( $post_id );
		foreach ( $def['fields'] ?? array() as $field ) {
			if ( ! empty( $field['scope'] ) && strcasecmp( $field['scope'], $symbol ) !== 0 ) {
				continue;
			}
			if ( ( isset( $field['public'] ) && ! $field['public'] && ! $include_private ) || in_array( $field['type'], array( 'status', 'file' ), true ) ) {
				continue;
			}
			$raw     = Registry::get( $post_id, $field['key'] );
			$display = self::display( $field, $raw, $post_id );
			$empty   = null === $display || '' === $display || array() === $display;
			$out[]   = array(
				'key'       => $field['key'],
				'label'     => $field['label'],
				'value'     => $empty ? null : $display,
				'unit'      => $field['unit'] ?? null,
				'type'      => $field['type'],
				'scope'     => $field['scope'] ?? null,
				'state'     => $empty ? 'pending' : 'provided',
				'pending'   => $empty ? ( $field['pending'] ?? 'Not yet provided' ) : null,
				'countable' => ! in_array( $field['type'], array( 'relation', 'relations' ), true ) && ! empty( $field['pending'] ),
			);
		}
		return $out;
	}

	private static function display( array $field, $raw, int $post_id ) {
		switch ( $field['type'] ) {
			case 'relation':
				if ( ! $raw || 'publish' !== get_post_status( (int) $raw ) ) {
					return null;
				}
				$no = get_post_meta( (int) $raw, '_rc_record_no', true );
				return trim( ( $no ? $no . ' · ' : '' ) . get_the_title( (int) $raw ) );
			case 'relations':
				$items = array();
				foreach ( (array) $raw as $id ) {
					if ( 'publish' === get_post_status( (int) $id ) ) {
						$items[] = get_post_meta( (int) $id, '_rc_record_no', true ) ?: get_the_title( (int) $id );
					}
				}
				return $items ? implode( ', ', $items ) : null;
			case 'country':
				return $raw ? ( Schema::countries()[ $raw ] ?? $raw ) : null;
			case 'select':
				return '' !== (string) $raw ? ( $field['options'][ $raw ] ?? $raw ) : null;
			case 'money':
				if ( '' === (string) $raw ) {
					return null;
				}
				$cur = get_post_meta( $post_id, '_rc_currency', true );
				return trim( $cur . ' ' . number_format( (float) $raw, 2 ) );
			case 'number':
				return '' === (string) $raw ? null : rtrim( rtrim( number_format( (float) $raw, 4, '.', ',' ), '0' ), '.' );
			default:
				return '' === (string) $raw ? null : (string) $raw;
		}
	}

	public static function document( int $doc_id, bool $include_private = false ): ?array {
		$doc = get_post( $doc_id );
		if ( ! $doc || 'rc_document' !== $doc->post_type || ( 'publish' !== $doc->post_status && ! $include_private ) ) {
			return null;
		}
		$att  = (int) get_post_meta( $doc_id, '_rc_file', true );
		$type = get_post_meta( $doc_id, '_rc_doc_type', true );
		return array(
			'id'          => $doc_id,
			'record_no'   => get_post_meta( $doc_id, '_rc_record_no', true ),
			'title'       => $doc->post_title,
			'type'        => $type ?: 'other',
			'type_label'  => Schema::field( 'rc_document', 'doc_type' )['options'][ $type ] ?? 'Document',
			'sha256'      => get_post_meta( $doc_id, '_rc_sha256', true ) ?: null,
			'status'      => get_post_meta( $doc_id, '_rc_verification_status', true ) ?: 'in_development',
			'issued_by'   => get_post_meta( $doc_id, '_rc_issued_by', true ) ?: null,
			'issue_date'  => get_post_meta( $doc_id, '_rc_issue_date', true ) ?: null,
			'version'     => get_post_meta( $doc_id, '_rc_version_label', true ) ?: null,
			'url'         => $att ? wp_get_attachment_url( $att ) : null,
			'size'        => $att && get_attached_file( $att ) && file_exists( get_attached_file( $att ) ) ? filesize( get_attached_file( $att ) ) : null,
			'mime'        => $att ? get_post_mime_type( $att ) : null,
			'description' => wp_strip_all_tags( $doc->post_content ),
		);
	}

	/**
	 * Evidence-derived lifecycle. Nothing here is typed by hand: each stage reflects what the registry can prove.
	 */
	private static function lifecycle( \WP_Post $post, array $evidence, int $program_id, bool $include_private ): array {
		$find = static function ( string $type, ?string $record_type = null ) use ( $evidence ) {
			$best = null;
			foreach ( $evidence as $ev ) {
				if ( $ev['type'] !== $type || ( $record_type && $ev['record_type'] !== $record_type ) ) {
					continue;
				}
				if ( ! $best || 'verified' === $ev['status'] ) {
					$best = $ev;
				}
			}
			return $best;
		};

		$stage = static function ( string $key, string $label, ?array $ev, string $pending, bool $counted = true ) {
			return array(
				'key'       => $key,
				'label'     => $label,
				'status'    => $ev ? ( 'verified' === $ev['status'] ? 'verified' : 'pending_verification' ) : 'pending',
				'date'      => $ev['date'] ?? null,
				'evidence'  => $ev['record_no'] ?? null,
				'inherited' => $ev['inherited'] ?? null,
				'note'      => $ev ? null : $pending,
				'counted'   => $counted,
			);
		};

		$stages   = array();
		$stages[] = array(
			'key'       => 'registered',
			'label'     => 'Registry record created',
			'status'    => 'verified',
			'date'      => get_post_time( 'Y-m-d', true, $post ),
			'evidence'  => get_post_meta( $post->ID, '_rc_record_no', true ),
			'inherited' => null,
			'note'      => null,
			'counted'   => false,
		);
		$stages[] = $stage( 'laboratory', 'Laboratory analysis (Certificate of Analysis)', $find( 'rc_coa' ), 'Pending: awaiting accredited laboratory certificate' );
		$stages[] = $stage( 'custody', 'Custody intake', $find( 'rc_custody', 'custody_intake' ), 'Pending: custody arrangement not yet confirmed' );
		$stages[] = $stage( 'ownership', 'Legal ownership record', $find( 'rc_custody', 'ownership' ), 'Pending: subject to final legal structure' );
		$stages[] = $stage( 'insurance', 'Insurance', $find( 'rc_insurance' ), 'Pending: insurance not yet arranged' );
		$stages[] = $stage( 'valuation', 'Independent valuation', $find( 'rc_valuation' ), 'Pending: independent valuation not yet performed' );
		$stages[] = $stage( 'reserve', 'Reserve attestation', $find( 'rc_reserve_report' ), 'Pending: Proof of Reserves module in development' );

		$token = null;
		if ( $program_id ) {
			$tp = Registry::referencing( $program_id, array( 'rc_token_program' ), $include_private ? array( 'publish', 'draft', 'rc_review', 'rc_approved' ) : 'publish' );
			if ( $tp && get_post_meta( $tp[0]->ID, '_rc_contract_address', true ) ) {
				$token = array( 'status' => get_post_meta( $tp[0]->ID, '_rc_verification_status', true ), 'record_no' => get_post_meta( $tp[0]->ID, '_rc_record_no', true ), 'date' => null );
			}
		}
		$stages[] = $stage( 'token', 'Token program linkage', $token, 'Not linked: tokenization subject to final approval', false );
		return $stages;
	}

	private static function children( int $post_id, $status ): array {
		$out = array();
		foreach ( array( 'batch' => array( 'rc_container' ), 'lot' => array( 'rc_batch', 'rc_coil', 'rc_container' ) ) as $key => $types ) {
			$out = array_merge(
				$out,
				get_posts(
					array(
						'post_type'      => $types,
						'post_status'    => $status,
						'posts_per_page' => 100,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'meta_key'       => Schema::meta_key( $key ), // phpcs:ignore
						'meta_value'     => $post_id, // phpcs:ignore
					)
				)
			);
		}
		return $out;
	}

	/**
	 * SHA-256 Merkle root over hex leaves (sorted, pairwise sorted — order-independent and proof-friendly).
	 */
	public static function merkle_root( array $leaves ): ?string {
		$level = array_values( array_unique( array_filter( $leaves ) ) );
		if ( ! $level ) {
			return null;
		}
		sort( $level );
		while ( count( $level ) > 1 ) {
			$next = array();
			for ( $i = 0, $n = count( $level ); $i < $n; $i += 2 ) {
				$a = $level[ $i ];
				$b = $level[ $i + 1 ] ?? $a;
				$pair   = strcmp( $a, $b ) <= 0 ? array( $a, $b ) : array( $b, $a );
				$next[] = hash( 'sha256', hex2bin( $pair[0] ) . hex2bin( $pair[1] ) );
			}
			$level = $next;
		}
		return $level[0];
	}

	/** Lightweight index row for listings. */
	public static function summary( \WP_Post $post ): array {
		$program = (int) get_post_meta( $post->ID, '_rc_program', true );
		return array(
			'id'           => $post->ID,
			'passport_no'  => get_post_meta( $post->ID, '_rc_record_no', true ),
			'entity_type'  => $post->post_type,
			'entity_label' => Schema::entity( $post->post_type )['singular'],
			'title'        => $post->post_title,
			'program'      => $program ? get_post_field( 'post_name', $program ) : null,
			'symbol'       => $program ? get_post_meta( $program, '_rc_symbol', true ) : null,
			'status'       => get_post_meta( $post->ID, '_rc_verification_status', true ) ?: 'in_development',
			'updated_at'   => get_post_modified_time( 'c', true, $post ),
			'url'          => home_url( '/passport/' . rawurlencode( get_post_meta( $post->ID, '_rc_record_no', true ) ) . '/' ),
		);
	}

	public static function list( array $args = array() ): array {
		$q = array(
			'post_type'      => Schema::PASSPORT_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => min( 100, (int) ( $args['per_page'] ?? 50 ) ),
			'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'        => 'date',
			'order'          => 'ASC',
		);
		if ( ! empty( $args['program'] ) ) {
			$prog = get_page_by_path( sanitize_title( $args['program'] ), OBJECT, 'rc_program' );
			if ( ! $prog ) {
				return array();
			}
			$q['meta_query'] = array( array( 'key' => '_rc_program', 'value' => $prog->ID ) ); // phpcs:ignore
		}
		if ( ! empty( $args['type'] ) && in_array( $args['type'], Schema::PASSPORT_TYPES, true ) ) {
			$q['post_type'] = $args['type'];
		}
		// Stable, meaningful order: lots → batches → containers → coils, then by record number.
		$rank  = array_flip( Schema::PASSPORT_TYPES );
		$limit = $q['posts_per_page'];
		$q['posts_per_page'] = 500;
		$q['paged']          = 1;
		$posts = get_posts( $q );
		usort( $posts, static fn( $a, $b ) => ( $rank[ $a->post_type ] <=> $rank[ $b->post_type ] ) ?: strnatcmp( (string) get_post_meta( $a->ID, '_rc_record_no', true ), (string) get_post_meta( $b->ID, '_rc_record_no', true ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		return array_map( array( __CLASS__, 'summary' ), array_slice( $posts, ( $page - 1 ) * $limit, $limit ) );
	}
}
