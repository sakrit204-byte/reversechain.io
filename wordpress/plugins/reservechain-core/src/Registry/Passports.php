<?php
/**
 * Digital Asset Passports.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Registry;

use ReserveChain\Core\Content\Publication;

/**
 * Creates and reads Digital Asset Passports.
 *
 * A passport is "a persistent digital identity for every asset unit" [W§4 #14]
 * — a stable, citable record joining one physical object to its laboratory,
 * ownership, custody, valuation, reserve and tokenization evidence.
 *
 * Two design points that matter more than they look:
 *
 * The public identifier is random, not sequential. A passport URL is meant to
 * be shared with a counterparty; a guessable one would let anyone enumerate
 * the entire inventory and infer holdings the owner has not published.
 *
 * The evidence hash is a digest over the passport's approved evidence set. It
 * lets a holder confirm months later that the record they were shown has not
 * changed underneath them — the same argument as the audit chain, applied to a
 * single asset. Because it covers only approved, published values, it does not
 * change when unrelated internal edits happen.
 */
final class Passports {

	/**
	 * Crockford base32 alphabet, used for ULID-style public identifiers.
	 */
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Generate a 26-character, non-sequential public identifier.
	 */
	public static function public_id(): string {
		$id = '';

		for ( $i = 0; $i < 26; $i++ ) {
			$id .= self::ALPHABET[ random_int( 0, 31 ) ];
		}

		return $id;
	}

	/**
	 * Build a human-readable display identifier for a unit.
	 *
	 * Shaped so a warehouse operator can read it off a screen and match it to
	 * a physical label: programme, lot, unit. e.g. RC-CU-03K07-BOX20.
	 *
	 * @param array<string,mixed> $program Programme row.
	 * @param array<string,mixed> $lot     Lot row.
	 * @param array<string,mixed> $unit    Unit row.
	 */
	public static function display_id( array $program, array $lot, array $unit ): string {
		$metal  = self::element_symbol( (string) ( $program['material_category'] ?? '' ) );
		$lot_c  = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $lot['lot_number'] ) );
		$unit_c = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $unit['identifier'] ) );

		return sprintf( 'RC-%s-%s-%s', $metal, $lot_c, $unit_c );
	}

	/**
	 * Chemical symbol for a material category.
	 *
	 * An identifier printed on industrial-metal documentation should use the
	 * element symbol an engineer already reads — Cu, not CO, which is carbon
	 * monoxide and exactly the kind of ambiguity a materials specialist would
	 * notice. Falls back to the first two letters for categories that are not
	 * single elements, such as an alloy or a gemstone programme.
	 *
	 * @param string $category Material category.
	 */
	private static function element_symbol( string $category ): string {
		$symbols = array(
			'copper'    => 'CU',
			'nickel'    => 'NI',
			'aluminium' => 'AL',
			'aluminum'  => 'AL',
			'zinc'      => 'ZN',
			'tin'       => 'SN',
			'lead'      => 'PB',
			'iron'      => 'FE',
			'cobalt'    => 'CO',
			'titanium'  => 'TI',
			'silver'    => 'AG',
			'gold'      => 'AU',
			'platinum'  => 'PT',
			'palladium' => 'PD',
			'tungsten'  => 'W',
			'manganese' => 'MN',
			'magnesium' => 'MG',
			'lithium'   => 'LI',
			'silicon'   => 'SI',
		);

		$key = strtolower( trim( $category ) );

		return $symbols[ $key ] ?? strtoupper( substr( $key !== '' ? $key : 'xx', 0, 2 ) );
	}

	/**
	 * Issue passports for every unit that does not have one.
	 *
	 * @return int Number created.
	 */
	public static function issue_missing(): int {
		global $wpdb;

		$units_table     = $wpdb->prefix . 'rc_units';
		$lots_table      = $wpdb->prefix . 'rc_lots';
		$programs_table  = $wpdb->prefix . 'rc_asset_programs';
		$passports_table = $wpdb->prefix . 'rc_passports';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT u.*, l.`lot_number`, l.`program_id`
			 FROM `{$units_table}` u
			 JOIN `{$lots_table}` l ON l.`id` = u.`lot_id`
			 LEFT JOIN `{$passports_table}` p ON p.`unit_id` = u.`id`
			 WHERE p.`id` IS NULL",
			ARRAY_A
		);

		$created = 0;

		foreach ( (array) $rows as $unit ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$program = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM `{$programs_table}` WHERE `id` = %d", (int) $unit['program_id'] ),
				ARRAY_A
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$lot = $wpdb->get_row(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM `{$lots_table}` WHERE `id` = %d", (int) $unit['lot_id'] ),
				ARRAY_A
			);

			if ( null === $program || null === $lot ) {
				continue;
			}

			$wpdb->insert(
				$passports_table,
				array(
					'unit_id'    => (int) $unit['id'],
					'public_id'  => self::public_id(),
					'display_id' => self::display_id( $program, $lot, $unit ),
					// A passport inherits its unit's publication state. It can
					// never be more public than the record it describes.
					'publication_state' => (string) $unit['publication_state'],
					'is_illustrative'   => 1,
					'issued_at'         => current_time( 'mysql', true ),
				)
			);

			$created++;
		}

		self::refresh_evidence_hashes();

		return $created;
	}

	/**
	 * Recompute the evidence digest for every passport.
	 */
	public static function refresh_evidence_hashes(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rc_passports';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( "SELECT `id` FROM `{$table}`" );

		foreach ( (array) $ids as $id ) {
			$record = self::by_id( (int) $id );

			if ( null === $record ) {
				continue;
			}

			$wpdb->update(
				$table,
				array( 'evidence_hash' => self::evidence_hash( $record ) ),
				array( 'id' => (int) $id )
			);
		}
	}

	/**
	 * Digest over a passport's evidence set.
	 *
	 * Deliberately covers the factual fields only, in a fixed order. Editing a
	 * description or fixing a typo must not invalidate a digest someone has
	 * already been given; changing a weight, a purity, a certificate or a
	 * status must.
	 *
	 * @param array<string,mixed> $record Assembled passport record.
	 */
	public static function evidence_hash( array $record ): string {
		$material = array(
			'display_id'          => $record['display_id'] ?? null,
			'program_code'        => $record['program']['program_code'] ?? null,
			'lot_number'          => $record['lot']['lot_number'] ?? null,
			'unit_identifier'     => $record['unit']['identifier'] ?? null,
			'unit_kind'           => $record['unit']['kind'] ?? null,
			'declared_net_weight' => $record['unit']['declared_net_weight'] ?? null,
			'verified_net_weight' => $record['unit']['verified_net_weight'] ?? null,
			'weight_unit'         => $record['unit']['weight_unit'] ?? null,
			'was_sampled'         => $record['unit']['was_sampled'] ?? null,
			'verification_status' => $record['unit']['verification_status'] ?? null,
			'custody_status'      => $record['unit']['custody_status'] ?? null,
			'reserve_status'      => $record['unit']['reserve_status'] ?? null,
			'redemption_status'   => $record['unit']['redemption_status'] ?? null,
			'certificates'        => array_map(
				static fn ( array $c ): array => array(
					'number'     => $c['certificate_number'] ?? null,
					'date'       => $c['certificate_date'] ?? null,
					'purity_pct' => $c['purity_pct'] ?? null,
					'standard'   => $c['standard_reference'] ?? null,
					'sha256'     => $c['document_sha256'] ?? null,
				),
				(array) ( $record['certificates'] ?? array() )
			),
		);

		// Sort recursively so the digest cannot change because of array order.
		$canonical = self::canonicalise( $material );

		return hash( 'sha256', (string) wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Recursively sort array keys.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonicalise( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_is_list( $value );
		$value   = array_map( static fn ( $v ) => self::canonicalise( $v ), $value );

		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}

	/**
	 * Load a passport and everything it references, by public identifier.
	 *
	 * @param string $public_id Public identifier.
	 * @return array<string,mixed>|null
	 */
	public static function by_public_id( string $public_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'rc_passports';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT `id` FROM `{$table}` WHERE `public_id` = %s", $public_id )
		);

		return null === $id ? null : self::by_id( (int) $id );
	}

	/**
	 * Load a passport and everything it references.
	 *
	 * @param int $id Passport id.
	 * @return array<string,mixed>|null
	 */
	public static function by_id( int $id ): ?array {
		global $wpdb;

		$p = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$passport = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$p}rc_passports` WHERE `id` = %d", $id ),
			ARRAY_A
		);

		if ( null === $passport ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$unit = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$p}rc_units` WHERE `id` = %d", (int) $passport['unit_id'] ),
			ARRAY_A
		);

		if ( null === $unit ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$lot = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$p}rc_lots` WHERE `id` = %d", (int) $unit['lot_id'] ),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$program = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM `{$p}rc_asset_programs` WHERE `id` = %d", (int) ( $lot['program_id'] ?? 0 ) ),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$certificates = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT c.*, d.`sha256` AS document_sha256, d.`public_id` AS document_public_id,
				        lab.`name` AS laboratory_name
				 FROM `{$p}rc_certificates` c
				 LEFT JOIN `{$p}rc_documents` d ON d.`id` = c.`document_id`
				 LEFT JOIN `{$p}rc_laboratories` lab ON lab.`id` = c.`laboratory_id`
				 WHERE c.`lot_id` = %d
				 ORDER BY c.`certificate_date` DESC",
				(int) $unit['lot_id']
			),
			ARRAY_A
		);

		return array(
			'id'                => (int) $passport['id'],
			'public_id'         => (string) $passport['public_id'],
			'display_id'        => (string) $passport['display_id'],
			'publication_state' => (string) $passport['publication_state'],
			'is_illustrative'   => (bool) $passport['is_illustrative'],
			'evidence_hash'     => (string) ( $passport['evidence_hash'] ?? '' ),
			'issued_at'         => (string) ( $passport['issued_at'] ?? '' ),
			'unit'              => $unit,
			'lot'               => $lot ?: array(),
			'program'           => $program ?: array(),
			'certificates'      => (array) $certificates,
		);
	}

	/**
	 * Public URL for a passport.
	 *
	 * @param string $public_id Public identifier.
	 */
	public static function url( string $public_id ): string {
		return home_url( '/passport/' . $public_id . '/' );
	}

	/**
	 * Whether a passport may be shown to an anonymous visitor.
	 *
	 * Under-review passports remain reachable by their unguessable URL, because
	 * the whole point of a passport is that it can be handed to a counterparty
	 * during due diligence — but they are marked unmistakably and are excluded
	 * from indexing. Draft and archived passports are not reachable at all.
	 *
	 * @param array<string,mixed> $record Passport record.
	 */
	public static function is_viewable( array $record ): bool {
		return in_array(
			(string) $record['publication_state'],
			array( 'under_review', 'approved', 'published' ),
			true
		);
	}

	/**
	 * Whether search engines may index a passport.
	 *
	 * @param array<string,mixed> $record Passport record.
	 */
	public static function is_indexable( array $record ): bool {
		return Publication::is_public( (string) $record['publication_state'] )
			&& ! $record['is_illustrative'];
	}
}
