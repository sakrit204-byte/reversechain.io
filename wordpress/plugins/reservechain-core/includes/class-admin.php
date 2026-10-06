<?php
/**
 * ReserveChain control centre (wp-admin).
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_post_rc_audit_verify', array( __CLASS__, 'do_verify' ) );
		add_action( 'admin_post_rc_audit_anchor', array( __CLASS__, 'do_anchor' ) );
		add_action( 'admin_post_rc_audit_export', array( __CLASS__, 'do_audit_export' ) );
		add_action( 'admin_post_rc_waitlist_export', array( __CLASS__, 'do_waitlist_export' ) );
		add_action( 'admin_post_rc_save_settings', array( __CLASS__, 'do_save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'env_banner' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
	}

	public static function assets(): void {
		wp_enqueue_style( 'rc-admin', RC_URL . 'assets/admin.css', array(), RC_VERSION );
		wp_enqueue_script( 'rc-qrcode', RC_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		wp_enqueue_script( 'rc-admin', RC_URL . 'assets/admin.js', array( 'jquery', 'rc-qrcode' ), RC_VERSION, true );
	}

	public static function menu(): void {
		add_menu_page( 'ReserveChain', 'ReserveChain', 'read', 'reservechain', array( __CLASS__, 'page_dashboard' ), 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h6v6H3zM11 3h6v6h-6zM3 11h6v6H3zM11 11h6v6h-6z" opacity=".9"/></svg>' ), 3 );
		add_submenu_page( 'reservechain', 'Control centre', 'Control centre', 'read', 'reservechain', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'reservechain', 'Review queue', 'Review queue', 'edit_rc_entities', 'rc-review', array( __CLASS__, 'page_review' ) );
		add_submenu_page( 'reservechain', 'Audit trail', 'Audit trail', 'rc_view_audit', 'rc-audit', array( __CLASS__, 'page_audit' ) );
		add_submenu_page( 'reservechain', 'Waitlist', 'Waitlist', 'rc_manage_waitlist', 'rc-waitlist', array( __CLASS__, 'page_waitlist' ) );
		add_submenu_page( 'reservechain', 'Compliance', 'Compliance', 'rc_manage_compliance', 'rc-compliance', array( __CLASS__, 'page_compliance' ) );
		add_submenu_page( 'reservechain', 'Support inbox', 'Support inbox', 'rc_manage_waitlist', 'rc-support', array( __CLASS__, 'page_support' ) );
		add_submenu_page( 'reservechain', 'Settings', 'Settings & modules', 'rc_manage_settings', 'rc-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'reservechain', 'System health', 'System health', 'rc_view_audit', 'rc-health', array( __CLASS__, 'page_health' ) );

		add_menu_page( 'Asset Registry', 'Asset Registry', 'edit_rc_entities', 'reservechain-registry', '__return_null', 'dashicons-database', 4 );
	}

	public static function env_banner(): void {
		$screen = get_current_screen();
		if ( ! $screen || ( false === strpos( (string) $screen->id, 'reservechain' ) && false === strpos( (string) $screen->id, 'rc-' ) && false === strpos( (string) $screen->post_type, 'rc_' ) ) ) {
			return;
		}
		$colors = array( 'development' => '#2271b1', 'staging' => '#b26200', 'production' => '#1e7e4f' );
		printf( '<div class="rc-envbar" style="--env:%s"><strong>%s</strong> environment · Site mode: <strong>%s</strong> · Testnet only · <a href="%s">Audit chain</a></div>', esc_attr( $colors[ RC_ENV ] ?? '#555' ), esc_html( strtoupper( RC_ENV ) ), esc_html( Settings::get( 'site_mode' ) ), esc_url( admin_url( 'admin.php?page=rc-audit' ) ) );
	}

	public static function dashboard_widget(): void {
		wp_add_dashboard_widget( 'rc_overview', 'ReserveChain — platform status', array( __CLASS__, 'overview_cards' ) );
	}

	private static function pill( string $s, string $label ): string {
		return '<span class="rc-pill rc-pill-' . esc_attr( $s ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function overview_cards(): void {
		$wl     = Waitlist::stats();
		$last   = get_option( 'rc_audit_last_verify' );
		$head   = Audit_Log::head();
		$review = count( get_posts( array( 'post_type' => Workflow::governed_types(), 'post_status' => array( 'rc_review', 'rc_approved' ), 'posts_per_page' => 200, 'fields' => 'ids' ) ) );
		echo '<div class="rc-cards">';
		printf( '<div class="rc-card"><span>Website mode</span><strong>%s</strong><a href="%s">Change</a></div>', esc_html( Settings::mode_label() ), esc_url( admin_url( 'admin.php?page=rc-settings' ) ) );
		printf( '<div class="rc-card"><span>Awaiting review / publication</span><strong>%d</strong><a href="%s">Open queue</a></div>', (int) $review, esc_url( admin_url( 'admin.php?page=rc-review' ) ) );
		printf( '<div class="rc-card"><span>Waitlist (confirmed / total)</span><strong>%d / %d</strong><a href="%s">View</a></div>', (int) $wl['confirmed'], (int) $wl['total'], esc_url( admin_url( 'admin.php?page=rc-waitlist' ) ) );
		printf( '<div class="rc-card"><span>Audit chain</span><strong>#%d</strong>%s</div>', (int) $head['seq'], $last ? self::pill( $last['ok'] ? 'verified' : 'restricted', $last['ok'] ? 'Intact (' . human_time_diff( (int) $last['at'] ) . ' ago)' : 'INTEGRITY FAILURE' ) : self::pill( 'pending_verification', 'Not yet verified' ) );
		printf( '<div class="rc-card"><span>DB immutability triggers</span><strong>%s</strong></div>', Install::triggers_active() ? self::pill( 'verified', 'Active' ) : self::pill( 'restricted', 'Missing' ) );
		$gated = array_filter( array_intersect_key( Settings::get( 'modules' ), Settings::GATED_MODULES ) );
		printf( '<div class="rc-card"><span>Gated modules active</span><strong>%d / %d</strong>%s</div>', count( $gated ), count( Settings::GATED_MODULES ), $gated ? self::pill( 'pending_verification', 'Authorized' ) : self::pill( 'in_development', 'All locked' ) );
		echo '</div>';
	}

	public static function page_dashboard(): void {
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1"><span class="rc-mark">RC</span> ReserveChain control centre</h1>';
		self::overview_cards();
		echo '<div class="rc-grid2"><div class="rc-panel"><h2>Asset Registry</h2><table class="widefat striped"><thead><tr><th>Entity</th><th>Published</th><th>In workflow</th><th></th></tr></thead><tbody>';
		foreach ( Schema::entities() as $type => $def ) {
			$c  = wp_count_posts( $type );
			$wf = (int) ( $c->rc_review ?? 0 ) + (int) ( $c->rc_approved ?? 0 ) + (int) ( $c->draft ?? 0 );
			printf( '<tr><td><span class="dashicons %s"></span> %s</td><td>%d</td><td>%d</td><td><a href="%s">Manage</a> · <a href="%s">Add</a></td></tr>', esc_attr( $def['icon'] ), esc_html( $def['label'] ), (int) ( $c->publish ?? 0 ), $wf, esc_url( admin_url( 'edit.php?post_type=' . $type ) ), esc_url( admin_url( 'post-new.php?post_type=' . $type ) ) );
		}
		echo '</tbody></table></div><div class="rc-panel"><h2>Recent audit events</h2><ol class="rc-feed">';
		global $wpdb;
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . Audit_Log::table() . ' ORDER BY id DESC LIMIT 12', ARRAY_A ) as $row ) { // phpcs:ignore
			printf( '<li><code>#%d</code> <strong>%s</strong> %s <small>— %s, %s UTC</small></li>', (int) $row['id'], esc_html( $row['action'] ), esc_html( $row['summary'] ), esc_html( $row['actor_login'] ?: 'system' ), esc_html( substr( $row['created_at'], 0, 19 ) ) );
		}
		echo '</ol><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=rc-audit' ) ) . '">Open audit trail</a></p></div></div>';
		echo '<div class="rc-panel"><h2>Your role & permissions</h2><p>';
		foreach ( Install::CAPS as $cap => $label ) {
			echo current_user_can( $cap ) ? self::pill( 'verified', $label ) . ' ' : ''; // phpcs:ignore
		}
		echo '</p></div></div>';
	}

	public static function page_review(): void {
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Review queue</h1><p>Four-eyes principle: the approver must differ from the submitter and the last editor. Approval binds to the exact content fingerprint.</p>';
		foreach ( array( 'rc_review' => 'Under review', 'rc_approved' => 'Approved — ready to publish', 'draft' => 'Drafts', 'rc_unpublished' => 'Unpublished' ) as $status => $label ) {
			$items = get_posts( array( 'post_type' => Workflow::governed_types(), 'post_status' => $status, 'posts_per_page' => 100, 'orderby' => 'modified' ) );
			echo '<h2>' . esc_html( $label ) . ' <span class="count">(' . count( $items ) . ')</span></h2>';
			if ( ! $items ) {
				echo '<p class="description">Nothing here.</p>';
				continue;
			}
			echo '<table class="widefat striped"><thead><tr><th>Item</th><th>Type</th><th>Submitted by</th><th>Modified</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $items as $p ) {
				$sub  = get_userdata( (int) get_post_meta( $p->ID, '_rc_submitted_by', true ) );
				$type = get_post_type_object( $p->post_type );
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '"><strong>' . esc_html( $p->post_title ) . '</strong></a> <code>' . esc_html( get_post_meta( $p->ID, '_rc_record_no', true ) ) . '</code></td>';
				echo '<td>' . esc_html( $type ? $type->labels->singular_name : $p->post_type ) . '</td><td>' . esc_html( $sub ? $sub->display_name : '—' ) . '</td><td>' . esc_html( get_the_modified_date( 'Y-m-d H:i', $p ) ) . '</td><td>';
				foreach ( Workflow::TRANSITIONS as $action => $def ) {
					$why = '';
					if ( in_array( $p->post_status, $def[0], true ) && Workflow::can( $action, $p, $why ) ) {
						printf( '<a class="button button-small" href="%s">%s</a> ', esc_url( Workflow::action_url( $action, $p->ID ) ), esc_html( $def[3] ) );
					} elseif ( in_array( $p->post_status, $def[0], true ) && in_array( $action, array( 'approve', 'publish' ), true ) ) {
						printf( '<span class="button button-small disabled" title="%s">%s</span> ', esc_attr( $why ), esc_html( $def[3] ) );
					}
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function page_audit(): void {
		global $wpdb;
		$per    = 50;
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$action = sanitize_text_field( wp_unslash( $_GET['action_filter'] ?? '' ) ); // phpcs:ignore
		$where  = $action ? $wpdb->prepare( 'WHERE action LIKE %s', $wpdb->esc_like( $action ) . '%' ) : '';
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Audit_Log::table() . " $where" ); // phpcs:ignore
		$rows   = $wpdb->get_results( 'SELECT * FROM ' . Audit_Log::table() . " $where ORDER BY id DESC LIMIT " . $per . ' OFFSET ' . ( ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore
		$last   = get_option( 'rc_audit_last_verify' );
		$head   = Audit_Log::head();
		$anchor = Audit_Log::latest_anchor();

		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Audit trail <small>append-only · hash-chained · tamper-evident</small></h1>';
		echo '<div class="rc-grid2"><div class="rc-panel"><h2>Integrity</h2>';
		printf( '<p>Chain head <code>#%d</code><br><code class="rc-hash">%s</code></p>', (int) $head['seq'], esc_html( $head['chain_head'] ) );
		printf( '<p>Database triggers: %s</p>', Install::triggers_active() ? self::pill( 'verified', 'UPDATE/DELETE blocked at database level' ) : self::pill( 'restricted', 'Triggers missing — re-run migration with TRIGGER privilege' ) );
		if ( $last ) {
			printf( '<p>Last verification: %s — %d entries, %s ago</p>', $last['ok'] ? self::pill( 'verified', 'Intact' ) : self::pill( 'restricted', 'FAILED' ), (int) $last['checked'], esc_html( human_time_diff( (int) $last['at'] ) ) );
			foreach ( (array) $last['errors'] as $err ) {
				echo '<p class="rc-error">⚠ ' . esc_html( $err['message'] ) . '</p>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="rc_audit_verify">';
		wp_nonce_field( 'rc_audit_verify' );
		echo '<button class="button button-primary">Verify entire chain now</button></form> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rc_audit_export' ), 'rc_audit_export' ) ) . '">Export (JSONL, independently verifiable)</a>';
		echo '</div><div class="rc-panel"><h2>On-chain anchoring</h2>';
		echo '<p>Anchoring commits the chain head to the <code>AuditAnchor</code> contract (testnet). Run <code>npx hardhat run scripts/anchor-audit.ts --network sepolia</code> in <code>contracts/</code>, then record the transaction here (or let the script post it via the API).</p>';
		if ( $anchor ) {
			printf( '<p>Latest anchor: <code>#%d</code> on %s<br><code class="rc-hash">%s</code></p>', (int) $anchor['seq'], esc_html( $anchor['network'] ), esc_html( $anchor['tx_hash'] ) );
		} else {
			echo '<p>' . self::pill( 'in_development', 'No anchors recorded yet' ) . '</p>'; // phpcs:ignore
		}
		if ( current_user_can( 'rc_anchor_audit' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_audit_anchor">';
			wp_nonce_field( 'rc_audit_anchor' );
			printf( '<p><input name="seq" type="number" value="%d" style="width:90px"> <input name="chain_head" class="rc-mono" value="%s" size="40"> <select name="network"><option>sepolia</option><option>amoy</option><option>localhost</option></select><br><input name="tx_hash" class="rc-mono regular-text" placeholder="0x… transaction hash" required> <button class="button">Record anchor</button></p></form>', (int) $head['seq'], esc_attr( $head['chain_head'] ) );
		}
		echo '</div></div>';

		echo '<form method="get"><input type="hidden" name="page" value="rc-audit"><p><input name="action_filter" placeholder="Filter by action prefix (e.g. workflow, auth, registry)" value="' . esc_attr( $action ) . '" class="regular-text"> <button class="button">Filter</button></p></form>';
		echo '<table class="widefat striped rc-audit"><thead><tr><th>#</th><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Object</th><th>Summary</th><th>Hash</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$data = json_decode( (string) $r['data'], true );
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s<br><small>%s</small></td><td><code>%s</code></td><td>%s</td><td>%s%s</td><td><code title="prev %s">%s…</code></td></tr>',
				(int) $r['id'],
				esc_html( substr( $r['created_at'], 0, 23 ) ),
				esc_html( $r['actor_login'] ?: 'system' ),
				esc_html( $r['actor_role'] ),
				esc_html( $r['action'] ),
				esc_html( $r['object_type'] . ( $r['object_id'] ? ' #' . $r['object_id'] : '' ) ),
				esc_html( $r['summary'] ),
				$data ? '<details><summary>data</summary><pre>' . esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>' : '',
				esc_attr( $r['prev_hash'] ),
				esc_html( substr( $r['row_hash'], 0, 12 ) )
			);
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p class="rc-pager">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) . '</p>'; // phpcs:ignore
		}
		echo '<p class="description">Entries cannot be edited or deleted through any interface. There is intentionally no delete function.</p></div>';
	}

	public static function do_verify(): void {
		check_admin_referer( 'rc_audit_verify' );
		if ( ! current_user_can( 'rc_view_audit' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$res = Audit_Log::verify();
		Audit_Log::record( 'audit.verified', 'audit', $res['seq'], $res['ok'] ? sprintf( 'Chain verified intact (%d entries)', $res['checked'] ) : 'Chain verification FAILED', array( 'errors' => count( $res['errors'] ), 'head' => $res['head'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=rc-audit' ) );
		exit;
	}

	public static function do_anchor(): void {
		check_admin_referer( 'rc_audit_anchor' );
		if ( ! current_user_can( 'rc_anchor_audit' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$ok = Audit_Log::add_anchor( absint( $_POST['seq'] ?? 0 ), strtolower( sanitize_text_field( wp_unslash( $_POST['chain_head'] ?? '' ) ) ), sanitize_key( $_POST['network'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['tx_hash'] ?? '' ) ) );
		set_transient( 'rc_wf_notice_' . get_current_user_id(), $ok ? 'Anchor recorded.' : 'Anchor rejected: chain head does not match the stored entry.', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=rc-audit' ) );
		exit;
	}

	public static function do_audit_export(): void {
		check_admin_referer( 'rc_audit_export' );
		if ( ! current_user_can( 'rc_view_audit' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		global $wpdb;
		Audit_Log::record( 'audit.exported', 'audit', 0, 'Audit trail exported (JSONL)' );
		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reservechain-audit-' . gmdate( 'Ymd-His' ) . '.jsonl"' );
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Audit_Log::table() . ' WHERE id > %d ORDER BY id ASC LIMIT 1000', $last ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				echo wp_json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n"; // phpcs:ignore
				$last = (int) $r['id'];
			}
		} while ( count( $rows ) === 1000 );
		exit;
	}

	public static function page_waitlist(): void {
		global $wpdb;
		$s    = Waitlist::stats();
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Waitlist::table() . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Waitlist <small>registration of interest — not an allocation or reservation</small></h1><div class="rc-cards">';
		printf( '<div class="rc-card"><span>Total</span><strong>%d</strong></div><div class="rc-card"><span>Confirmed (double opt-in)</span><strong>%d</strong></div><div class="rc-card"><span>Institutions</span><strong>%d</strong></div><div class="rc-card"><span>Restricted jurisdictions (updates only)</span><strong>%d</strong></div>', (int) $s['total'], (int) $s['confirmed'], (int) $s['institutions'], (int) $s['restricted'] );
		echo '</div><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rc_waitlist_export' ), 'rc_waitlist_export' ) ) . '">Export CSV</a> <span class="description">Exports are logged in the audit trail.</span></p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Registered</th><th>Name</th><th>Email</th><th>Country</th><th>Type</th><th>Interest</th><th>Lang</th><th>Jurisdiction</th><th>Status</th><th>Consent</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code title="%s">%s</code></td></tr>',
				(int) $r['id'],
				esc_html( substr( $r['created_at'], 0, 16 ) ),
				esc_html( $r['name'] ),
				$r['organisation'] ? '<br><small>' . esc_html( $r['organisation'] ) . '</small>' : '',
				esc_html( $r['email'] ),
				esc_html( $r['country'] ),
				esc_html( $r['entity_type'] ),
				esc_html( $r['interest'] ),
				esc_html( $r['language'] ),
				self::pill( 'restricted' === $r['jurisdiction_status'] ? 'restricted' : 'verified', $r['jurisdiction_status'] ), // phpcs:ignore
				self::pill( 'confirmed' === $r['status'] ? 'verified' : 'pending_verification', $r['status'] ), // phpcs:ignore
				esc_attr( $r['consent_hash'] ),
				esc_html( $r['consent_version'] )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function do_waitlist_export(): void {
		check_admin_referer( 'rc_waitlist_export' );
		if ( ! current_user_can( 'rc_manage_waitlist' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, created_at, name, email, country, entity_type, organisation, interest, language, jurisdiction_status, status, general_updates_only, confirmed_at, consent_version, consent_hash, source FROM ' . Waitlist::table() . ' ORDER BY id', ARRAY_A ); // phpcs:ignore
		Audit_Log::record( 'waitlist.exported', 'waitlist', 0, sprintf( 'Waitlist exported (%d rows)', count( $rows ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reservechain-waitlist-' . gmdate( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $rows ? array_keys( $rows[0] ) : array( 'empty' ) );
		foreach ( $rows as $r ) {
			// Neutralise spreadsheet formula injection.
			fputcsv( $out, array_map( static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v, $r ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	public static function page_compliance(): void {
		$users = get_users( array( 'number' => 200, 'orderby' => 'registered', 'order' => 'DESC' ) );
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Compliance <small>KYC · KYB · AML · sanctions · jurisdiction</small></h1>';
		echo '<p>Identity verification is performed by an external provider (to be selected). Outcomes are recorded here manually or via provider webhook (<code>do_action( \'rc_compliance_update\', $user_id, $check, $state, $source )</code>). Every change is written to the audit trail and notified to the user.</p>';
		echo '<div class="rc-panel"><h2>Jurisdiction policy</h2><p>EU/EEA restriction: ' . ( Settings::get( 'restrict_eu_eea' ) ? self::pill( 'restricted', 'On' ) : self::pill( 'pending_verification', 'Off' ) ) . ' · Additional restricted jurisdictions: <code>' . esc_html( implode( ', ', (array) Settings::get( 'restricted_countries' ) ) ) . '</code> <a href="' . esc_url( admin_url( 'admin.php?page=rc-settings#jurisdictions' ) ) . '">Edit</a></p><p class="description">Default restricted list is a placeholder and must be confirmed by counsel.</p></div>'; // phpcs:ignore
		echo '<table class="widefat striped"><thead><tr><th>User</th><th>Country</th><th>Jurisdiction</th><th>Type</th><th>KYC</th><th>KYB</th><th>AML</th><th>Sanctions</th><th>MFA</th><th>Overall</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$e = Compliance::eligibility( $u->ID );
			printf( '<tr><td><a href="%s">%s</a><br><small>%s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( get_edit_user_link( $u->ID ) ), esc_html( $u->display_name ), esc_html( $u->user_email ), esc_html( $e['country'] ?: '—' ), esc_html( $e['jurisdiction'] ), esc_html( $e['entity_type'] ), esc_html( $e['kyc'] ), esc_html( $e['kyb'] ), esc_html( $e['aml'] ), esc_html( $e['sanctions'] ), Auth::mfa_enabled( $u->ID ) ? '✓' : '—', esc_html( str_replace( '_', ' ', $e['overall'] ) ) );
		}
		echo '</tbody></table></div>';
	}

	public static function page_support(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}rc_support ORDER BY id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Support inbox <small>website contact + app support</small></h1><table class="widefat striped"><thead><tr><th>Ticket</th><th>Received</th><th>Channel</th><th>From</th><th>Subject</th><th>Message</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td><code>RC-SUP-%06d</code></td><td>%s</td><td>%s</td><td>%s<br><small>%s</small></td><td>%s</td><td>%s</td></tr>', (int) $r['id'], esc_html( substr( $r['created_at'], 0, 16 ) ), esc_html( $r['channel'] ), esc_html( $r['name'] ), esc_html( $r['email'] ), esc_html( $r['subject'] ), esc_html( wp_trim_words( $r['message'], 30 ) ) );
		}
		echo '</tbody></table></div>';
	}

	public static function page_settings(): void {
		$s = Settings::all();
		settings_errors( Settings::OPTION );
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">Settings & modules</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_save_settings">';
		wp_nonce_field( 'rc_save_settings' );

		echo '<div class="rc-panel"><h2>Website mode</h2>';
		echo '<p class="description">Locked modes need a deployment action (wp-config constant) <strong>and</strong> a written authorization reference. They never auto-activate.</p>';
		foreach ( Settings::MODES as $k => $def ) {
			$const  = 'RC_ALLOW_MODE_' . strtoupper( $k );
			$armed  = ! $def[2] || ( defined( $const ) && constant( $const ) );
			printf(
				'<div class="rc-gated"><label class="rc-radio"><input type="radio" name="s[site_mode]" value="%1$s"%2$s%3$s> <strong>%4$s</strong> — %5$s</label>%6$s</div>',
				esc_attr( $k ),
				checked( $s['site_mode'], $k, false ),
				$armed ? '' : ' disabled',
				esc_html( $def[0] ),
				esc_html( $def[1] ),
				$def[2] ? sprintf( '<p><code>%s</code> %s <input type="text" name="s[mode_authorizations][%s][ref]" value="%s" placeholder="Written authorization reference" class="regular-text"></p>', esc_html( $const ), $armed ? self::pill( 'pending_verification', 'deployment flag set' ) : self::pill( 'restricted', 'not set — locked' ), esc_attr( $k ), esc_attr( $s['mode_authorizations'][ $k ]['ref'] ?? '' ) ) : ''
			);
		}
		echo '</div>';

		echo '<div class="rc-grid2"><div class="rc-panel"><h2>Website sections</h2><p class="description">Hide sections without deleting content. Hidden sections return 404 and are removed from navigation.</p>';
		foreach ( Settings::SECTIONS as $k => $label ) {
			printf( '<label class="rc-check"><input type="checkbox" name="s[sections][%s]" value="1"%s> %s</label>', esc_attr( $k ), checked( ! empty( $s['sections'][ $k ] ), true, false ), esc_html( $label ) );
		}
		echo '</div><div class="rc-panel"><h2>Modules</h2>';
		foreach ( Settings::OPEN_MODULES as $k => $label ) {
			printf( '<label class="rc-check"><input type="checkbox" name="s[modules][%s]" value="1"%s> %s</label>', esc_attr( $k ), checked( ! empty( $s['modules'][ $k ] ), true, false ), esc_html( $label ) );
		}
		echo '<h3>Gated modules <small>(require written authorization)</small></h3>';
		foreach ( Settings::GATED_MODULES as $k => $label ) {
			$auth = $s['authorizations'][ $k ] ?? array();
			printf(
				'<div class="rc-gated"><label class="rc-check"><input type="checkbox" name="s[modules][%1$s]" value="1"%2$s%3$s> <strong>%4$s</strong></label><input type="text" name="s[authorizations][%1$s][ref]" value="%5$s" placeholder="Written authorization reference (required to enable)" class="regular-text"%3$s>%6$s</div>',
				esc_attr( $k ),
				checked( ! empty( $s['modules'][ $k ] ), true, false ),
				current_user_can( 'rc_authorize_modules' ) ? '' : ' disabled',
				esc_html( $label ),
				esc_attr( $auth['ref'] ?? '' ),
				! empty( $auth['at'] ) ? '<small>Authorized ' . esc_html( $auth['at'] ) . '</small>' : ''
			);
		}
		echo '</div></div>';

		echo '<div class="rc-panel"><h2>Mandatory disclosures</h2><p class="description">Shown site-wide, in the apps, in emails and stored (as a hash) with every consent. They can be extended but never removed.</p>';
		printf( '<p><label>Prelaunch disclosure<br><textarea name="s[disclosure]" rows="5" class="large-text">%s</textarea></label></p>', esc_textarea( $s['disclosure'] ) );
		printf( '<p><label>EU/EEA notice<br><textarea name="s[eu_notice]" rows="2" class="large-text">%s</textarea></label></p>', esc_textarea( $s['eu_notice'] ) );
		printf( '<p><label>Provisional Asset Notice (asset pages, passports, illustrative data)<br><textarea name="s[provisional_notice]" rows="2" class="large-text">%s</textarea></label></p>', esc_textarea( $s['provisional_notice'] ) );
		echo '<p>Current consent fingerprint: <code>' . esc_html( Settings::disclosure_hash() ) . '</code></p></div>';

		echo '<div class="rc-grid2"><div class="rc-panel" id="jurisdictions"><h2>Jurisdictions</h2>';
		printf( '<label class="rc-check"><input type="checkbox" name="s[restrict_eu_eea]" value="1"%s> Restrict EU/EEA residents (%s)</label>', checked( $s['restrict_eu_eea'], true, false ), esc_html( implode( ' ', Schema::eu_eea_countries() ) ) );
		printf( '<p><label>Additional restricted jurisdictions (ISO codes, comma separated)<br><input name="s[restricted_countries]" class="large-text rc-mono" value="%s"></label></p><p class="description">Placeholder default — to be confirmed by legal counsel.</p>', esc_attr( implode( ', ', (array) $s['restricted_countries'] ) ) );
		echo '</div><div class="rc-panel"><h2>Network (testnet only)</h2>';
		foreach ( array( 'chain_id' => 'Chain ID', 'name' => 'Network name', 'explorer' => 'Explorer URL', 'token_address' => 'Token contract (testnet)', 'anchor_address' => 'AuditAnchor contract (testnet)' ) as $k => $label ) {
			printf( '<p><label>%s<br><input name="s[network][%s]" class="regular-text rc-mono" value="%s"></label></p>', esc_html( $label ), esc_attr( $k ), esc_attr( (string) $s['network'][ $k ] ) );
		}
		echo '</div></div>';

		echo '<div class="rc-panel"><h2>Security & contact</h2>';
		printf( '<label class="rc-check"><input type="checkbox" name="s[mfa_enforce_staff]" value="1"%s> Enforce MFA for all staff roles</label>', checked( $s['mfa_enforce_staff'], true, false ) );
		printf( '<p><label>Contact / support inbox email<br><input type="email" name="s[contact_email]" class="regular-text" value="%s"></label></p>', esc_attr( $s['contact_email'] ) );
		echo '</div>';
		submit_button( 'Save settings (changes are audited)' );
		echo '</form></div>';
	}

	public static function do_save_settings(): void {
		check_admin_referer( 'rc_save_settings' );
		if ( ! current_user_can( 'rc_manage_settings' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$in  = wp_unslash( $_POST['s'] ?? array() ); // phpcs:ignore
		$cur = Settings::all();
		$new = $cur;

		$new['site_mode'] = isset( Settings::MODES[ $in['site_mode'] ?? '' ] ) ? $in['site_mode'] : $cur['site_mode'];
		foreach ( Settings::MODES as $k => $def ) {
			if ( $def[2] ) {
				$new['mode_authorizations'][ $k ] = array_merge( $cur['mode_authorizations'][ $k ] ?? array(), array( 'ref' => sanitize_text_field( $in['mode_authorizations'][ $k ]['ref'] ?? '' ) ) );
			}
		}
		$new['provisional_notice'] = sanitize_textarea_field( $in['provisional_notice'] ?? '' );
		foreach ( Settings::SECTIONS as $k => $l ) {
			$new['sections'][ $k ] = ! empty( $in['sections'][ $k ] );
		}
		foreach ( array_merge( Settings::OPEN_MODULES, Settings::GATED_MODULES ) as $k => $l ) {
			$new['modules'][ $k ] = ! empty( $in['modules'][ $k ] );
		}
		foreach ( Settings::GATED_MODULES as $k => $l ) {
			$ref = sanitize_text_field( $in['authorizations'][ $k ]['ref'] ?? '' );
			$new['authorizations'][ $k ] = array_merge( $cur['authorizations'][ $k ] ?? array(), array( 'ref' => $ref ) );
		}
		$new['disclosure']           = sanitize_textarea_field( $in['disclosure'] ?? '' );
		$new['eu_notice']            = sanitize_textarea_field( $in['eu_notice'] ?? '' );
		$new['restrict_eu_eea']      = ! empty( $in['restrict_eu_eea'] );
		$new['restricted_countries'] = array_values( array_filter( array_map( static fn( $c ) => strtoupper( trim( $c ) ), explode( ',', (string) ( $in['restricted_countries'] ?? '' ) ) ), static fn( $c ) => isset( Schema::countries()[ $c ] ) ) );
		$new['mfa_enforce_staff']    = ! empty( $in['mfa_enforce_staff'] );
		$new['contact_email']        = sanitize_email( $in['contact_email'] ?? '' );
		foreach ( array( 'chain_id', 'name', 'explorer', 'token_address', 'anchor_address' ) as $k ) {
			$v                      = sanitize_text_field( $in['network'][ $k ] ?? '' );
			$new['network'][ $k ] = 'chain_id' === $k ? absint( $v ) : $v;
		}
		if ( in_array( (int) $new['network']['chain_id'], array( 1, 10, 56, 137, 42161, 8453 ), true ) ) {
			$new['network']['chain_id'] = $cur['network']['chain_id'];
			add_settings_error( Settings::OPTION, 'rc_mainnet', 'Mainnet chain IDs are not permitted without written authorization.' );
		}
		update_option( Settings::OPTION, $new, false );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=rc-settings&settings-updated=1' ) );
		exit;
	}

	public static function page_health(): void {
		global $wpdb;
		$checks = array(
			array( 'Environment', RC_ENV, in_array( RC_ENV, array( 'development', 'staging', 'production' ), true ) ),
			array( 'HTTPS', is_ssl() ? 'Yes' : 'No', is_ssl() || 'development' === RC_ENV ),
			array( 'PHP version', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) ),
			array( 'MySQL version', $wpdb->db_version(), version_compare( $wpdb->db_version(), '8.0', '>=' ) ),
			array( 'Audit immutability triggers', Install::triggers_active() ? 'Active' : 'Missing', Install::triggers_active() ),
			array( 'Token secret configured', defined( 'RC_TOKEN_SECRET' ) && 'dev-only-change-me-in-staging-and-production' !== RC_TOKEN_SECRET ? 'Yes' : 'Default / missing', defined( 'RC_TOKEN_SECRET' ) && ( 'development' === RC_ENV || 'dev-only-change-me-in-staging-and-production' !== RC_TOKEN_SECRET ) ),
			array( 'File editor disabled', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'Yes' : 'No', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
			array( 'Debug display off', defined( 'WP_DEBUG_DISPLAY' ) && ! WP_DEBUG_DISPLAY ? 'Yes' : 'No', ! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ),
			array( 'Staff MFA enforcement', Settings::get( 'mfa_enforce_staff' ) ? 'On' : 'Off', (bool) Settings::get( 'mfa_enforce_staff' ) ),
			array( 'Your account MFA', Auth::mfa_enabled( get_current_user_id() ) ? 'Enabled' : 'Not enabled', Auth::mfa_enabled( get_current_user_id() ) ),
			array( 'OpenSSL AES-256-GCM', in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ? 'Available' : 'Missing', in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ),
			array( 'Gated modules locked', array_filter( array_intersect_key( Settings::get( 'modules' ), Settings::GATED_MODULES ) ) ? 'Some authorized' : 'All locked', true ),
			array( 'Database schema version', (string) get_option( 'rc_db_version' ), RC_DB_VERSION === get_option( 'rc_db_version' ) ),
		);
		echo '<div class="wrap rc-wrap"><h1 class="rc-h1">System health</h1><table class="widefat striped"><tbody>';
		foreach ( $checks as $c ) {
			printf( '<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>', esc_html( $c[0] ), esc_html( $c[1] ), $c[2] ? self::pill( 'verified', 'OK' ) : self::pill( 'restricted', 'Action required' ) ); // phpcs:ignore
		}
		echo '</tbody></table><p class="description">Backups, WAF, uptime monitoring and vulnerability scanning are infrastructure controls — see docs/SECURITY.md and docs/BACKUP-DR.md.</p></div>';
	}
}
