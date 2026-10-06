<?php
/**
 * Four-eyes editorial workflow for every governed content type.
 *
 *   Draft → Under Review → Approved → Published → Unpublished → (Under Review | Archived)
 *
 * Rules:
 *  - The approver must differ from the submitter and from the last editor (four-eyes).
 *  - Approval binds to a SHA-256 fingerprint of the content + registry fields. Any change after
 *    approval sends the item back to review; publishing re-checks the fingerprint.
 *  - Direct publishing (Publish button, REST, Quick Edit) is intercepted and routed to review.
 *  - Every transition is appended to the item's history and to the tamper-evident audit trail.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Workflow {

	public const STATUSES = array(
		'rc_review'      => 'Under Review',
		'rc_approved'    => 'Approved',
		'rc_unpublished' => 'Unpublished',
		'rc_archived'    => 'Archived',
	);

	/** action => [from statuses, to status, capability, label] */
	public const TRANSITIONS = array(
		'submit'    => array( array( 'draft', 'pending', 'rc_unpublished' ), 'rc_review', 'rc_submit', 'Submit for review' ),
		'approve'   => array( array( 'rc_review' ), 'rc_approved', 'rc_approve', 'Approve' ),
		'reject'    => array( array( 'rc_review', 'rc_approved' ), 'draft', 'rc_approve', 'Request changes' ),
		'publish'   => array( array( 'rc_approved' ), 'publish', 'rc_publish', 'Publish' ),
		'unpublish' => array( array( 'publish' ), 'rc_unpublished', 'rc_publish', 'Unpublish' ),
		'archive'   => array( array( 'draft', 'rc_review', 'rc_approved', 'rc_unpublished', 'publish' ), 'rc_archived', 'rc_archive', 'Archive' ),
		'restore'   => array( array( 'rc_archived' ), 'draft', 'rc_archive', 'Restore to draft' ),
	);

	private static bool $bypass = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_statuses' ), 5 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'intercept' ), 99, 2 );
		add_action( 'save_post', array( __CLASS__, 'invalidate_on_change' ), 99, 2 );
		add_action( 'admin_post_rc_workflow', array( __CLASS__, 'handle' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function governed_types(): array {
		return apply_filters( 'rc_governed_types', array_merge( array( 'page', 'post' ), Schema::types() ) );
	}

	public static function is_governed( string $type ): bool {
		return in_array( $type, self::governed_types(), true );
	}

	public static function register_statuses(): void {
		foreach ( self::STATUSES as $slug => $label ) {
			register_post_status(
				$slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'protected'                 => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => 'rc_archived' !== $slug,
					'show_in_admin_status_list' => true,
					/* translators: %s: count */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ), // phpcs:ignore
				)
			);
		}
	}

	/**
	 * Run a callback with workflow enforcement suspended (seeding, migrations, CLI).
	 */
	public static function bypass( callable $fn ) {
		self::$bypass = true;
		try {
			return $fn();
		} finally {
			self::$bypass = false;
		}
	}

	public static function intercept( array $data, array $postarr ): array {
		if ( self::$bypass || ! self::is_governed( $data['post_type'] ) ) {
			return $data;
		}
		$id  = (int) ( $postarr['ID'] ?? 0 );
		$old = $id ? get_post_status( $id ) : 'new';

		if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) && 'publish' !== $old ) {
			$data['post_status'] = current_user_can( 'rc_submit' ) || current_user_can( 'rc_manage_registry' ) ? 'rc_review' : 'draft';
			set_transient( 'rc_wf_notice_' . get_current_user_id(), 'Direct publishing is disabled. The item was routed to the four-eyes review workflow.', 60 );
			if ( $id ) {
				update_post_meta( $id, '_rc_submitted_by', get_current_user_id() );
			}
		}

		if ( 'publish' === $old && 'publish' === $data['post_status'] && ! current_user_can( 'rc_publish' ) ) {
			// Live content can only be changed by holders of rc_publish; others must unpublish via the workflow.
			$data['post_status'] = 'rc_review';
			set_transient( 'rc_wf_notice_' . get_current_user_id(), 'Changes to published content require review. The item was moved to Under Review.', 60 );
		}
		return $data;
	}

	/** Content fingerprint used to bind approvals to exact content. */
	public static function fingerprint( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$meta = array();
		foreach ( get_post_meta( $post_id ) as $k => $v ) {
			if ( 0 === strpos( $k, '_rc_' ) && ! in_array( $k, array( '_rc_approved_fp', '_rc_workflow_history', '_rc_submitted_by', '_rc_approved_by' ), true ) ) {
				$meta[ $k ] = $v;
			}
		}
		ksort( $meta );
		return hash( 'sha256', $post->post_title . "\x1f" . $post->post_content . "\x1f" . $post->post_excerpt . "\x1f" . wp_json_encode( $meta ) );
	}

	public static function invalidate_on_change( int $post_id, \WP_Post $post ): void {
		if ( self::$bypass || wp_is_post_revision( $post_id ) || 'rc_approved' !== $post->post_status ) {
			return;
		}
		$approved = get_post_meta( $post_id, '_rc_approved_fp', true );
		if ( $approved && $approved !== self::fingerprint( $post_id ) ) {
			self::$bypass = true;
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'rc_review' ) );
			self::$bypass = false;
			self::history( $post_id, 'auto_return', 'rc_approved', 'rc_review', 'Content changed after approval — approval invalidated.' );
		}
	}

	public static function can( string $action, \WP_Post $post, ?string &$why = null ): bool {
		if ( ! isset( self::TRANSITIONS[ $action ] ) ) {
			$why = 'Unknown action.';
			return false;
		}
		list( $from, , $cap ) = self::TRANSITIONS[ $action ];
		if ( ! in_array( $post->post_status, $from, true ) ) {
			$why = 'Not available from the current state.';
			return false;
		}
		$has = current_user_can( $cap ) || ( 'submit' === $action && current_user_can( 'rc_manage_registry' ) );
		if ( ! $has || ! current_user_can( 'edit_post', $post->ID ) ) {
			$why = 'Your role does not permit this action.';
			return false;
		}
		if ( 'approve' === $action ) {
			$me = get_current_user_id();
			if ( (int) get_post_meta( $post->ID, '_rc_submitted_by', true ) === $me || (int) get_post_meta( $post->ID, '_edit_last', true ) === $me ) {
				$why = 'Four-eyes rule: you submitted or last edited this item, so another reviewer must approve it.';
				return false;
			}
		}
		if ( 'publish' === $action && get_post_meta( $post->ID, '_rc_approved_fp', true ) !== self::fingerprint( $post->ID ) ) {
			$why = 'Content differs from the approved version.';
			return false;
		}
		return true;
	}

	public static function apply( string $action, int $post_id, string $comment = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'rc_wf', 'Item not found.' );
		}
		$why = '';
		if ( ! self::can( $action, $post, $why ) ) {
			return new \WP_Error( 'rc_wf', $why );
		}
		$from = $post->post_status;
		$to   = self::TRANSITIONS[ $action ][1];

		self::bypass(
			static function () use ( $post_id, $to ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => $to ) );
			}
		);

		$me = get_current_user_id();
		if ( 'submit' === $action ) {
			update_post_meta( $post_id, '_rc_submitted_by', $me );
			delete_post_meta( $post_id, '_rc_approved_fp' );
		}
		if ( 'approve' === $action ) {
			update_post_meta( $post_id, '_rc_approved_fp', self::fingerprint( $post_id ) );
			update_post_meta( $post_id, '_rc_approved_by', $me );
		}
		if ( 'reject' === $action ) {
			delete_post_meta( $post_id, '_rc_approved_fp' );
		}
		self::history( $post_id, $action, $from, $to, $comment );
		return true;
	}

	public static function history( int $post_id, string $action, string $from, string $to, string $comment = '' ): void {
		$history   = (array) get_post_meta( $post_id, '_rc_workflow_history', true );
		$history[] = array(
			'at'      => gmdate( 'c' ),
			'user'    => get_current_user_id(),
			'action'  => $action,
			'from'    => $from,
			'to'      => $to,
			'comment' => $comment,
			'fp'      => self::fingerprint( $post_id ),
		);
		update_post_meta( $post_id, '_rc_workflow_history', array_values( array_filter( $history ) ) );
		Audit_Log::record( 'workflow.' . $action, get_post_type( $post_id ), $post_id, sprintf( '%s: %s → %s', get_the_title( $post_id ), $from, $to ), array( 'comment' => $comment, 'fingerprint' => self::fingerprint( $post_id ) ) );
	}

	public static function action_url( string $action, int $post_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=rc_workflow&do=' . $action . '&post=' . $post_id ), 'rc_wf_' . $action . '_' . $post_id );
	}

	public static function handle(): void {
		$action  = sanitize_key( $_REQUEST['do'] ?? '' );
		$post_id = absint( $_REQUEST['post'] ?? 0 );
		check_admin_referer( 'rc_wf_' . $action . '_' . $post_id );
		$comment = sanitize_textarea_field( wp_unslash( $_REQUEST['comment'] ?? '' ) );
		$result  = self::apply( $action, $post_id, $comment );
		set_transient( 'rc_wf_notice_' . get_current_user_id(), is_wp_error( $result ) ? 'Workflow: ' . $result->get_error_message() : 'Workflow: ' . self::TRANSITIONS[ $action ][3] . ' — done.', 60 );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		exit;
	}

	public static function meta_box(): void {
		foreach ( self::governed_types() as $type ) {
			add_meta_box( 'rc_workflow', 'Four-eyes workflow', array( __CLASS__, 'render_box' ), $type, 'side', 'high' );
		}
	}

	public static function render_box( \WP_Post $post ): void {
		$labels  = array_merge( array( 'draft' => 'Draft', 'publish' => 'Published', 'pending' => 'Pending', 'auto-draft' => 'New' ), self::STATUSES );
		$current = $labels[ $post->post_status ] ?? $post->post_status;
		echo '<p><strong>State:</strong> <span class="rc-wf-state rc-wf-' . esc_attr( $post->post_status ) . '">' . esc_html( $current ) . '</span></p>';

		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">Save the draft to start the workflow.</p>';
			return;
		}
		$fp = self::fingerprint( $post->ID );
		echo '<p class="description" style="word-break:break-all">Fingerprint: <code>' . esc_html( substr( $fp, 0, 16 ) ) . '…</code></p>';

		echo '<div class="rc-wf-actions">';
		foreach ( self::TRANSITIONS as $action => $def ) {
			$why = '';
			if ( ! in_array( $post->post_status, $def[0], true ) ) {
				continue;
			}
			if ( self::can( $action, $post, $why ) ) {
				printf( '<a class="button %s" href="%s">%s</a> ', 'publish' === $action || 'approve' === $action ? 'button-primary' : '', esc_url( self::action_url( $action, $post->ID ) ), esc_html( $def[3] ) );
			} else {
				printf( '<span class="button disabled" title="%s">%s</span> ', esc_attr( $why ), esc_html( $def[3] ) );
			}
		}
		echo '</div>';

		$history = array_reverse( (array) get_post_meta( $post->ID, '_rc_workflow_history', true ) );
		if ( $history ) {
			echo '<details style="margin-top:10px"><summary>History (' . count( $history ) . ')</summary><ol style="margin-left:16px">';
			foreach ( array_slice( $history, 0, 15 ) as $h ) {
				if ( ! is_array( $h ) ) {
					continue;
				}
				$u = get_userdata( (int) $h['user'] );
				printf( '<li><small>%s — <strong>%s</strong> by %s%s</small></li>', esc_html( substr( $h['at'], 0, 16 ) ), esc_html( $h['action'] ), esc_html( $u ? $u->display_name : 'system' ), $h['comment'] ? ': ' . esc_html( $h['comment'] ) : '' );
			}
			echo '</ol></details>';
		}
	}

	public static function post_states( array $states, \WP_Post $post ): array {
		if ( isset( self::STATUSES[ $post->post_status ] ) ) {
			$states[ $post->post_status ] = self::STATUSES[ $post->post_status ];
		}
		return $states;
	}

	public static function notices(): void {
		$key = 'rc_wf_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}
