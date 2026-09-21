<?php
/**
 * Editorial workflow states.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Content;

/**
 * The six content states the brief mandates, as real WordPress post statuses.
 *
 * [Master §16]
 *   "Content states must include Draft, Under Review, Approved, Published,
 *    Unpublished and Archived. Draft content must not be public or indexed."
 *
 * Two of the six already exist in WordPress: `draft` and `publish`. The other
 * four are registered here rather than faked with taxonomy terms or post meta,
 * so that every native query, the admin list tables, the REST controller and
 * any future plugin all respect them automatically. A custom status that
 * declares `public => false` is excluded from public queries by core itself —
 * which is a far stronger guarantee than remembering to add a filter on every
 * template.
 *
 * `exclude_from_search`, `publicly_queryable` and `public` are all false for
 * the pre-publication states, so draft and under-review records cannot be
 * reached by URL, search, feed, sitemap or REST, satisfying "Draft and
 * under-review content must never be publicly accessible, indexed or exposed
 * through public APIs".
 */
final class WorkflowStates {

	/**
	 * Native WordPress draft.
	 */
	public const DRAFT = 'draft';

	/**
	 * Submitted for editorial or compliance review.
	 */
	public const UNDER_REVIEW = 'rc_under_review';

	/**
	 * Signed off, but not yet live. Approval and publication are separate acts:
	 * legal sign-off does not imply "put it on the website right now".
	 */
	public const APPROVED = 'rc_approved';

	/**
	 * Native WordPress publish.
	 */
	public const PUBLISHED = 'publish';

	/**
	 * Withdrawn from public view but retained and restorable.
	 */
	public const UNPUBLISHED = 'rc_unpublished';

	/**
	 * Retired. Retained permanently for the record.
	 */
	public const ARCHIVED = 'rc_archived';

	/**
	 * All six states in workflow order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::UNDER_REVIEW,
			self::APPROVED,
			self::PUBLISHED,
			self::UNPUBLISHED,
			self::ARCHIVED,
		);
	}

	/**
	 * States whose content may be shown to the public.
	 *
	 * Exactly one. Kept as a method so that no template ever hard-codes the
	 * assumption.
	 *
	 * @return string[]
	 */
	public static function public_states(): array {
		return array( self::PUBLISHED );
	}

	/**
	 * Register the four custom statuses.
	 */
	public static function register(): void {
		$definitions = array(
			self::UNDER_REVIEW => array(
				'label'       => _x( 'Under Review', 'post status', 'reservechain' ),
				/* translators: %s: number of posts */
				'label_count' => _n_noop( 'Under Review <span class="count">(%s)</span>', 'Under Review <span class="count">(%s)</span>', 'reservechain' ),
			),
			self::APPROVED     => array(
				'label'       => _x( 'Approved', 'post status', 'reservechain' ),
				/* translators: %s: number of posts */
				'label_count' => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'reservechain' ),
			),
			self::UNPUBLISHED  => array(
				'label'       => _x( 'Unpublished', 'post status', 'reservechain' ),
				/* translators: %s: number of posts */
				'label_count' => _n_noop( 'Unpublished <span class="count">(%s)</span>', 'Unpublished <span class="count">(%s)</span>', 'reservechain' ),
			),
			self::ARCHIVED     => array(
				'label'       => _x( 'Archived', 'post status', 'reservechain' ),
				/* translators: %s: number of posts */
				'label_count' => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'reservechain' ),
			),
		);

		foreach ( $definitions as $status => $args ) {
			register_post_status(
				$status,
				array(
					'label'                     => $args['label'],
					'label_count'               => $args['label_count'],
					// Not public, not queryable, not searchable: core then
					// excludes these records from every front-end surface.
					'public'                    => false,
					'publicly_queryable'        => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'internal'                  => false,
					'protected'                 => true,
				)
			);
		}
	}

	/**
	 * Human-readable label for a state.
	 *
	 * @param string $status Status key.
	 */
	public static function label( string $status ): string {
		$labels = array(
			self::DRAFT        => __( 'Draft', 'reservechain' ),
			self::UNDER_REVIEW => __( 'Under Review', 'reservechain' ),
			self::APPROVED     => __( 'Approved', 'reservechain' ),
			self::PUBLISHED    => __( 'Published', 'reservechain' ),
			self::UNPUBLISHED  => __( 'Unpublished', 'reservechain' ),
			self::ARCHIVED     => __( 'Archived', 'reservechain' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Whether a transition is permitted.
	 *
	 * Publication is forward-only through review and approval: nothing reaches
	 * the public site without having been approved first, and archived records
	 * cannot be silently republished.
	 *
	 * @param string $from Current state.
	 * @param string $to   Requested state.
	 */
	public static function can_transition( string $from, string $to ): bool {
		$allowed = array(
			self::DRAFT        => array( self::UNDER_REVIEW, self::ARCHIVED ),
			self::UNDER_REVIEW => array( self::DRAFT, self::APPROVED, self::ARCHIVED ),
			self::APPROVED     => array( self::PUBLISHED, self::UNDER_REVIEW, self::ARCHIVED ),
			self::PUBLISHED    => array( self::UNPUBLISHED, self::ARCHIVED ),
			self::UNPUBLISHED  => array( self::UNDER_REVIEW, self::PUBLISHED, self::ARCHIVED ),
			// Terminal. Restoring an archived record is a deliberate
			// administrative act that re-enters the review cycle.
			self::ARCHIVED     => array( self::UNDER_REVIEW ),
		);

		return in_array( $to, $allowed[ $from ] ?? array(), true );
	}
}
