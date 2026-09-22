<?php
/**
 * The single authority on what may be shown publicly.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Content;

/**
 * Publication gate.
 *
 * Every public surface — website templates, the REST API, the mobile
 * applications, exports and the sitemap — asks this class the same question
 * and gets the same answer. Nothing else is allowed to decide, because the
 * failure mode of scattered checks is that one surface eventually disagrees
 * with another and the platform publishes something it should not.
 *
 * The rules it enforces, from the binding instructions:
 *
 *   "Draft and under-review content must never be publicly accessible,
 *    indexed or exposed through public APIs." [M§16]
 *
 *   "All ownership, laboratory, custody, insurance and reserve claims must
 *    remain unpublished until the supporting evidence has been supplied and
 *    approved." [M§9]
 *
 *   "Do not label an asset Verified, Certified, Secured, In Custody,
 *    Tokenized, Available, or Redeemable unless that status is actually
 *    supported and authorized for publication." [W§8]
 *
 * The bias throughout is refusal. Where a value is missing, ambiguous or
 * unapproved, these methods return false or "pending" rather than guessing.
 * Showing nothing is recoverable; publishing an unsupported claim is not.
 */
final class Publication {

	/**
	 * Statuses that may be presented as an affirmative claim, and only when the
	 * owning record is itself published.
	 */
	private const AFFIRMATIVE = array(
		'verification_status' => array( 'verified' ),
		'custody_status'      => array( 'in_custody', 'segregated' ),
		'reserve_status'      => array( 'reconciled' ),
		'tokenization_status' => array( 'published' ),
		'redemption_status'   => array( 'available' ),
	);

	/**
	 * Whether a record's publication state allows public display.
	 *
	 * @param string|null $state Publication state.
	 */
	public static function is_public( ?string $state ): bool {
		return WorkflowStates::PUBLISHED === $state || 'published' === $state;
	}

	/**
	 * Whether a record may be displayed to the public.
	 *
	 * Accepts a row from any registry table.
	 *
	 * @param array<string,mixed>|object|null $record Registry row.
	 */
	public static function can_display( array|object|null $record ): bool {
		if ( null === $record ) {
			return false;
		}

		$row = (array) $record;

		if ( ! self::is_public( isset( $row['publication_state'] ) ? (string) $row['publication_state'] : null ) ) {
			return false;
		}

		// Archived records are retained but withdrawn from public view.
		if ( ! empty( $row['archived_at'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a specific status may be presented as an affirmative claim.
	 *
	 * Returns false for a status that merely exists in the database but has not
	 * been approved for publication — which is the difference between a record
	 * saying "in_custody" and the website being allowed to tell a reader the
	 * metal is in custody.
	 *
	 * @param array<string,mixed>|object|null $record Registry row.
	 * @param string                          $field  Status column name.
	 */
	public static function can_claim( array|object|null $record, string $field ): bool {
		if ( ! self::can_display( $record ) ) {
			return false;
		}

		$row = (array) $record;

		if ( empty( $row[ $field ] ) ) {
			return false;
		}

		$allowed = self::AFFIRMATIVE[ $field ] ?? array();

		return in_array( (string) $row[ $field ], $allowed, true );
	}

	/**
	 * The label and badge variant to render for a status field.
	 *
	 * Always returns something safe to print. Where a claim is not permitted,
	 * the caller gets "Pending" with the neutral variant rather than the raw
	 * database value, so a template cannot leak an unapproved status by
	 * echoing it directly.
	 *
	 * @param array<string,mixed>|object|null $record Registry row.
	 * @param string                          $field  Status column name.
	 * @return array{label:string,variant:string,claimed:bool}
	 */
	public static function status_display( array|object|null $record, string $field ): array {
		if ( self::can_claim( $record, $field ) ) {
			$row = (array) $record;

			return array(
				'label'   => self::label_for( $field, (string) $row[ $field ] ),
				'variant' => 'verified',
				'claimed' => true,
			);
		}

		$row   = (array) ( $record ?? array() );
		$value = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';

		// A record under review may say so — that is a process statement, not a
		// claim about the metal — but nothing stronger.
		if ( in_array( $value, array( 'submitted', 'in_review' ), true ) ) {
			return array(
				'label'   => __( 'Under review', 'reservechain' ),
				'variant' => 'review',
				'claimed' => false,
			);
		}

		if ( 'not_applicable' === $value ) {
			return array(
				'label'   => __( 'Not applicable', 'reservechain' ),
				'variant' => 'pending',
				'claimed' => false,
			);
		}

		return array(
			'label'   => __( 'Pending', 'reservechain' ),
			'variant' => 'pending',
			'claimed' => false,
		);
	}

	/**
	 * Human label for an affirmative status value.
	 *
	 * @param string $field Status column.
	 * @param string $value Stored value.
	 */
	private static function label_for( string $field, string $value ): string {
		$labels = array(
			'verification_status' => array( 'verified' => __( 'Independently verified', 'reservechain' ) ),
			'custody_status'      => array(
				'in_custody' => __( 'In custody', 'reservechain' ),
				'segregated' => __( 'Segregated custody', 'reservechain' ),
			),
			'reserve_status'      => array( 'reconciled' => __( 'Reconciled', 'reservechain' ) ),
			'tokenization_status' => array( 'published' => __( 'Tokenized', 'reservechain' ) ),
			'redemption_status'   => array( 'available' => __( 'Redeemable', 'reservechain' ) ),
		);

		return $labels[ $field ][ $value ] ?? __( 'Pending', 'reservechain' );
	}

	/**
	 * Render a quantity, distinguishing declared from independently verified.
	 *
	 * The supplied Certificates of Analysis footnote their weights as
	 * "according to information given by customer". Presenting that the same
	 * way as an independent measurement would manufacture an assurance nobody
	 * has given, so the two are rendered differently and the declared figure
	 * carries its qualifier.
	 *
	 * @param array<string,mixed>|object|null $record        Registry row.
	 * @param string                          $declared_key  Declared column.
	 * @param string                          $verified_key  Verified column.
	 * @param string|null                     $unit          Unit suffix.
	 * @return array{value:string,qualifier:string,verified:bool}
	 */
	public static function quantity_display(
		array|object|null $record,
		string $declared_key,
		string $verified_key,
		?string $unit = null
	): array {
		$row    = (array) ( $record ?? array() );
		$suffix = $unit ? ' ' . $unit : '';

		if ( isset( $row[ $verified_key ] ) && null !== $row[ $verified_key ] && '' !== $row[ $verified_key ] ) {
			return array(
				'value'     => self::format_number( (string) $row[ $verified_key ] ) . $suffix,
				'qualifier' => __( 'Independently verified', 'reservechain' ),
				'verified'  => true,
			);
		}

		if ( isset( $row[ $declared_key ] ) && null !== $row[ $declared_key ] && '' !== $row[ $declared_key ] ) {
			return array(
				'value'     => self::format_number( (string) $row[ $declared_key ] ) . $suffix,
				'qualifier' => __( 'Declared by supplier — not independently verified', 'reservechain' ),
				'verified'  => false,
			);
		}

		return array(
			'value'     => __( 'Pending', 'reservechain' ),
			'qualifier' => __( 'Not yet supplied', 'reservechain' ),
			'verified'  => false,
		);
	}

	/**
	 * Trim trailing zeros from a DECIMAL without losing significant digits.
	 *
	 * 2000.000000 reads as 2,000 and 99.999900 as 99.9999 — the certificate's
	 * own precision, neither padded nor rounded away.
	 *
	 * @param string $value Decimal string from the database.
	 */
	public static function format_number( string $value ): string {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$trimmed = rtrim( rtrim( $value, '0' ), '.' );

		if ( '' === $trimmed || '-' === $trimmed ) {
			$trimmed = '0';
		}

		$parts    = explode( '.', $trimmed );
		$integer  = number_format_i18n( (float) $parts[0], 0 );
		$fraction = $parts[1] ?? '';

		return '' === $fraction ? $integer : $integer . '.' . $fraction;
	}

	/**
	 * The current published disclosure text for a notice key.
	 *
	 * Falls back through locales rather than failing silently: a missing
	 * translation must never result in the mandatory disclosure disappearing.
	 *
	 * @param string      $key    Notice key.
	 * @param string|null $locale Preferred locale.
	 * @return array{title:string,body:string,version:int}|null
	 */
	public static function legal_notice( string $key, ?string $locale = null ): ?array {
		global $wpdb;

		$table  = $wpdb->prefix . 'rc_legal_notices';
		$locale = $locale ?? substr( (string) get_locale(), 0, 2 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT `title`, `body`, `version` FROM `{$table}`
				 WHERE `key` = %s AND `publication_state` = 'published' AND `locale` = %s
				 ORDER BY `version` DESC LIMIT 1",
				$key,
				$locale
			),
			ARRAY_A
		);

		if ( null === $row ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT `title`, `body`, `version` FROM `{$table}`
					 WHERE `key` = %s AND `publication_state` = 'published'
					 ORDER BY (`locale` = 'en') DESC, `version` DESC LIMIT 1",
					$key
				),
				ARRAY_A
			);
		}

		if ( null === $row ) {
			return null;
		}

		return array(
			'title'   => (string) $row['title'],
			'body'    => (string) $row['body'],
			'version' => (int) $row['version'],
		);
	}

	/**
	 * Whether a website mode is active.
	 *
	 * @param string $mode_key Mode key.
	 */
	public static function mode_active( string $mode_key ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'rc_site_modes';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT `is_active` FROM `{$table}` WHERE `mode_key` = %s", $mode_key )
		);
	}

	/**
	 * Whether a module may render publicly.
	 *
	 * A module that is built but not authorised returns false, so a template
	 * that references it renders nothing rather than exposing an inactive
	 * feature. [M§17]
	 *
	 * @param string $module_key Module key.
	 */
	public static function module_public( string $module_key ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'rc_module_flags';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT `state`, `is_public`, `depends_on_mode` FROM `{$table}` WHERE `module_key` = %s", $module_key ),
			ARRAY_A
		);

		if ( null === $row || ! (int) $row['is_public'] ) {
			return false;
		}

		if ( ! in_array( (string) $row['state'], array( 'staged', 'active' ), true ) ) {
			return false;
		}

		$required_mode = (string) ( $row['depends_on_mode'] ?? '' );

		return '' === $required_mode || self::mode_active( $required_mode );
	}
}
