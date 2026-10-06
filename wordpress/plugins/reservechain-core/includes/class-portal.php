<?php
/**
 * Participant Portal ([rc_portal]) and the restricted document data room.
 *
 *  - [rc_portal]: a self-contained single-page portal on top of the rc/v1 API (sign-in, MFA, dashboard).
 *    The portal itself is open (it is the staged-access entry point); registration follows `app_registration`.
 *  - Restricted documents: rc_document field `audience` (public / investor / enterprise / auditor / custodian / staff).
 *    Restricted files are moved to wp-content/uploads/rc-private/ (deny-all .htaccess + index.php + web.config,
 *    unguessable file names) and are only ever streamed through PHP via a signed, expiring link that re-checks access.
 *  - Data rooms are gated: investor audience by module `investor_portal`, enterprise audience by `enterprise_portal`.
 *
 * REST (rc/v1): GET /me/documents (bearer) · GET /me/documents/{id}/link (bearer) · GET /documents/{id}/download?u=&exp=&sig=
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Portal {

	/** Signed download links are valid for this many seconds. */
	private const LINK_TTL = 10 * MINUTE_IN_SECONDS;

	/** Read-only participant roles created by this module (no wp-admin access). */
	public const ROLES = array(
		'rc_investor'          => array( 'RC Investor (data room)', 'investor' ),
		'rc_enterprise_client' => array( 'RC Enterprise client', 'enterprise' ),
		'rc_custodian'         => array( 'RC Custodian (read-only)', 'custodian' ),
	);

	/** Audience → roles that may see it (capability `rc_docs_{audience}` grants it too; `rc_docs_all` grants every audience). */
	public const AUDIENCE_ROLES = array(
		'investor'   => array( 'rc_investor' ),
		'enterprise' => array( 'rc_enterprise_client' ),
		'auditor'    => array( 'rc_auditor' ),
		'custodian'  => array( 'rc_custodian' ),
		'staff'      => array( 'administrator', 'rc_compliance_officer', 'rc_registry_manager', 'rc_reviewer', 'rc_editor' ),
	);

	/** Audience → gated module that must be on before members (non-staff) can see it. */
	public const AUDIENCE_GATES = array(
		'investor'   => 'investor_portal',
		'enterprise' => 'enterprise_portal',
	);

	public static function audience_labels(): array {
		return array(
			'public'     => __( 'Public', 'reservechain' ),
			'investor'   => __( 'Investor data room', 'reservechain' ),
			'enterprise' => __( 'Enterprise clients', 'reservechain' ),
			'auditor'    => __( 'Auditors', 'reservechain' ),
			'custodian'  => __( 'Custodians', 'reservechain' ),
			'staff'      => __( 'Staff only', 'reservechain' ),
		);
	}

	public static function init(): void {
		add_shortcode( 'rc_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Restricted storage for documents.
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 99, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'hide_private_url' ), 99, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'block_private_attachment' ), 3 );

		// Participant roles: no wp-admin, no admin bar.
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
	}

	public static function install(): void {
		foreach ( self::ROLES as $slug => $def ) {
			remove_role( $slug );
			add_role( $slug, $def[0], array( 'read' => true, 'rc_docs_' . $def[1] => true ) );
		}
		$map = array(
			'administrator'         => array( 'rc_docs_all' ),
			'rc_compliance_officer' => array( 'rc_docs_all' ),
			'rc_auditor'            => array( 'rc_docs_auditor' ),
			'rc_registry_manager'   => array( 'rc_docs_staff' ),
			'rc_reviewer'           => array( 'rc_docs_staff' ),
			'rc_editor'             => array( 'rc_docs_staff' ),
		);
		foreach ( $map as $role => $caps ) {
			$r = get_role( $role );
			if ( $r ) {
				foreach ( $caps as $cap ) {
					$r->add_cap( $cap );
				}
			}
		}
		self::private_dir();
	}

	/* ------------------------------------------------------------ access model */

	public static function audience( int $doc_id ): string {
		$a = (string) get_post_meta( $doc_id, '_rc_audience', true );
		return isset( self::audience_labels()[ $a ] ) ? $a : 'public';
	}

	private static function is_member( \WP_User $user, string $audience ): bool {
		return user_can( $user, 'rc_docs_' . $audience ) || (bool) array_intersect( (array) $user->roles, self::AUDIENCE_ROLES[ $audience ] ?? array() );
	}

	private static function is_doc_staff( \WP_User $user ): bool {
		return user_can( $user, 'rc_docs_all' ) || (bool) array_intersect( (array) $user->roles, array( 'administrator', 'rc_compliance_officer' ) );
	}

	/**
	 * Restricted audiences (never 'public') a user may currently view, with module gates applied.
	 *
	 * @return string[]
	 */
	public static function audiences_for( int $user_id ): array {
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return array();
		}
		$all = self::is_doc_staff( $user );
		$out = array();
		foreach ( array_keys( self::AUDIENCE_ROLES ) as $aud ) {
			if ( ! $all && ! self::is_member( $user, $aud ) ) {
				continue;
			}
			if ( ! $all && isset( self::AUDIENCE_GATES[ $aud ] ) && ! Settings::module_on( self::AUDIENCE_GATES[ $aud ] ) ) {
				continue;
			}
			$out[] = $aud;
		}
		return $out;
	}

	public static function can_access( int $user_id, int $doc_id ): bool {
		$aud = self::audience( $doc_id );
		return 'public' === $aud || in_array( $aud, self::audiences_for( $user_id ), true );
	}

	/** Data rooms the user belongs to, and whether each is open. */
	public static function rooms( int $user_id ): array {
		$user = get_userdata( $user_id );
		$out  = array();
		foreach ( self::AUDIENCE_GATES as $aud => $module ) {
			$out[ $aud ] = array(
				'member'  => $user ? ( self::is_member( $user, $aud ) || self::is_doc_staff( $user ) ) : false,
				'enabled' => Settings::module_on( $module ),
				'module'  => $module,
			);
		}
		return $out;
	}

	/**
	 * Published restricted documents the user may see (audience matches the user's role(s); gates applied).
	 * Download URLs are signed and expire; direct file URLs are never exposed.
	 */
	public static function visible_documents( int $user_id ): array {
		$auds = self::audiences_for( $user_id );
		if ( ! $auds ) {
			return array();
		}
		$docs = get_posts(
			array(
				'post_type'      => 'rc_document',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( array( 'key' => '_rc_audience', 'value' => $auds, 'compare' => 'IN' ) ), // phpcs:ignore
			)
		);
		$labels = self::audience_labels();
		$out    = array();
		foreach ( $docs as $d ) {
			$p = Passport::document( $d->ID );
			if ( ! $p ) {
				continue;
			}
			$aud                 = self::audience( $d->ID );
			$p['url']            = null;
			$p['audience']       = $aud;
			$p['audience_label'] = $labels[ $aud ];
			$p['download_url']   = self::signed_url( $d->ID, $user_id );
			$out[]               = $p;
		}
		return $out;
	}

	/* ------------------------------------------------------------ signed links */

	private static function link_key(): string {
		return hash( 'sha256', 'rc-doc-link|' . ( defined( 'RC_TOKEN_SECRET' ) ? RC_TOKEN_SECRET : '' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	private static function sign( int $doc_id, int $user_id, int $exp ): string {
		return hash_hmac( 'sha256', $doc_id . '|' . $user_id . '|' . $exp, self::link_key() );
	}

	public static function signed_url( int $doc_id, int $user_id, int $ttl = self::LINK_TTL ): string {
		$exp = time() + $ttl;
		return add_query_arg(
			array( 'u' => $user_id, 'exp' => $exp, 'sig' => self::sign( $doc_id, $user_id, $exp ) ),
			rest_url( Rest::NS . '/documents/' . $doc_id . '/download' )
		);
	}

	/* ------------------------------------------------------------ REST */

	public static function routes(): void {
		register_rest_route( Rest::NS, '/me/documents', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_my_documents' ), 'permission_callback' => array( Rest::class, 'bearer_gate' ) ) );
		register_rest_route( Rest::NS, '/me/documents/(?P<id>\d+)/link', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_link' ), 'permission_callback' => array( Rest::class, 'bearer_gate' ) ) );
		register_rest_route( Rest::NS, '/documents/(?P<id>\d+)/download', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_download' ), 'permission_callback' => array( Rest::class, 'public_gate' ) ) );
	}

	public static function rest_my_documents(): array {
		$uid = get_current_user_id();
		return array(
			'items'     => self::visible_documents( $uid ),
			'audiences' => self::audiences_for( $uid ),
			'rooms'     => self::rooms( $uid ),
			'link_ttl'  => self::LINK_TTL,
		);
	}

	public static function rest_link( \WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$uid = get_current_user_id();
		if ( 'rc_document' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || ! self::can_access( $uid, $id ) ) {
			return new \WP_Error( 'rc_forbidden', 'You do not have access to this document.', array( 'status' => 403 ) );
		}
		return array( 'url' => self::signed_url( $id, $uid ), 'expires_in' => self::LINK_TTL );
	}

	public static function rest_download( \WP_REST_Request $r ) {
		$id  = (int) $r['id'];
		$uid = (int) $r->get_param( 'u' );
		$exp = (int) $r->get_param( 'exp' );
		$sig = (string) $r->get_param( 'sig' );
		if ( $exp < time() || $exp > time() + DAY_IN_SECONDS || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || ! hash_equals( self::sign( $id, $uid, $exp ), $sig ) ) {
			Audit_Log::record( 'document.download_denied', 'document', $id, 'Download refused: invalid or expired link', array( 'reason' => 'signature' ), 0 );
			return new \WP_Error( 'rc_link_invalid', 'This download link is invalid or has expired. Request a new link from the portal.', array( 'status' => 403 ) );
		}
		if ( 'rc_document' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new \WP_Error( 'rc_not_found', 'Document not found.', array( 'status' => 404 ) );
		}
		if ( ! self::can_access( $uid, $id ) ) {
			Audit_Log::record( 'document.download_denied', 'document', $id, 'Download refused: no access for this audience', array( 'audience' => self::audience( $id ) ), $uid );
			return new \WP_Error( 'rc_forbidden', 'You do not have access to this document.', array( 'status' => 403 ) );
		}
		$att  = (int) get_post_meta( $id, '_rc_file', true );
		$path = $att ? get_attached_file( $att ) : '';
		if ( ! $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'rc_not_found', 'File not available.', array( 'status' => 404 ) );
		}
		Audit_Log::record( 'document.download', 'document', $id, 'Document downloaded via signed link', array( 'audience' => self::audience( $id ), 'sha256' => (string) get_post_meta( $id, '_rc_sha256', true ) ), $uid );
		self::stream( $path, (string) get_post_mime_type( $att ), preg_replace( '/^[A-Za-z0-9]{20}-/', '', basename( $path ) ) );
		return null; // Unreachable.
	}

	/** Stream a file and terminate the request. */
	public static function stream( string $path, string $mime, string $name ): void {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( $name ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* ------------------------------------------------------------ private storage */

	/** wp-content/uploads/rc-private, protected by deny-all files (and by never publishing its URLs). */
	public static function private_dir( string $sub = '' ): string {
		$base = wp_upload_dir( null, false );
		$dir  = trailingslashit( $base['basedir'] ) . 'rc-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$files = array(
			'.htaccess'  => "# ReserveChain restricted files: never served directly.\nRequire all denied\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'index.php'  => "<?php\n// Silence is golden.\nhttp_response_code( 403 );\n",
			'index.html' => '',
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $f => $body ) {
			if ( ! file_exists( $dir . '/' . $f ) ) {
				file_put_contents( $dir . '/' . $f, $body ); // phpcs:ignore
			}
		}
		if ( $sub ) {
			$dir .= '/' . trim( $sub, '/' );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
				file_put_contents( $dir . '/index.php', $files['index.php'] ); // phpcs:ignore
			}
		}
		return $dir;
	}

	public static function is_private_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		$base = wp_upload_dir( null, false );
		return 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( trailingslashit( $base['basedir'] ) . 'rc-private/' ) );
	}

	public static function on_save( int $post_id, \WP_Post $post ): void {
		if ( 'rc_document' === $post->post_type && ! wp_is_post_revision( $post_id ) ) {
			self::sync_storage( $post_id );
		}
	}

	public static function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, array( '_rc_audience', '_rc_file' ), true ) && 'rc_document' === get_post_type( (int) $object_id ) ) {
			self::sync_storage( (int) $object_id );
		}
	}

	/** Move a document's file into or out of rc-private/ to match its audience. Content (and SHA-256) is unchanged. */
	public static function sync_storage( int $doc_id ): void {
		static $busy = false;
		if ( $busy ) {
			return;
		}
		$att  = (int) get_post_meta( $doc_id, '_rc_file', true );
		$path = $att ? (string) get_attached_file( $att ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}
		$aud     = self::audience( $doc_id );
		$private = self::is_private_path( $path );
		if ( ( 'public' !== $aud ) === $private ) {
			return;
		}
		$busy = true;
		try {
			$meta = wp_get_attachment_metadata( $att );
			if ( ! $private ) {
				// Drop generated previews (they would stay public) and move the original under an unguessable name.
				if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $size ) {
						$f = dirname( $path ) . '/' . ( $size['file'] ?? '' );
						if ( ! empty( $size['file'] ) && is_file( $f ) ) {
							wp_delete_file( $f );
						}
					}
					$meta['sizes'] = array();
					wp_update_attachment_metadata( $att, $meta );
				}
				$dest = self::private_dir() . '/' . wp_generate_password( 20, false ) . '-' . basename( $path );
				if ( ! @rename( $path, $dest ) ) { // phpcs:ignore
					Audit_Log::record( 'document.storage_failed', 'document', $doc_id, 'Could not move restricted document to private storage', array( 'audience' => $aud ) );
					return;
				}
				update_post_meta( $att, '_rc_public_dir', dirname( (string) _wp_relative_upload_path( $path ) ) );
				update_attached_file( $att, $dest );
				Audit_Log::record( 'document.restricted', 'document', $doc_id, 'Document file moved to restricted storage', array( 'audience' => $aud, 'sha256' => (string) get_post_meta( $doc_id, '_rc_sha256', true ) ) );
			} else {
				$up   = wp_upload_dir( null, false );
				$rel  = (string) get_post_meta( $att, '_rc_public_dir', true );
				$dir  = $rel && '.' !== $rel ? trailingslashit( $up['basedir'] ) . $rel : $up['path'];
				wp_mkdir_p( $dir );
				$name = wp_unique_filename( $dir, preg_replace( '/^[A-Za-z0-9]{20}-/', '', basename( $path ) ) );
				$dest = trailingslashit( $dir ) . $name;
				if ( ! @rename( $path, $dest ) ) { // phpcs:ignore
					Audit_Log::record( 'document.storage_failed', 'document', $doc_id, 'Could not move document back to public storage', array( 'audience' => $aud ) );
					return;
				}
				update_attached_file( $att, $dest );
				delete_post_meta( $att, '_rc_public_dir' );
				if ( 0 === strpos( (string) get_post_mime_type( $att ), 'image/' ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
					wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $dest ) );
				}
				Audit_Log::record( 'document.unrestricted', 'document', $doc_id, 'Document file returned to public storage', array( 'sha256' => (string) get_post_meta( $doc_id, '_rc_sha256', true ) ) );
			}
		} finally {
			$busy = false;
		}
	}

	/** Private files never get a public URL (front end, REST, media endpoints). */
	public static function hide_private_url( $url, $att_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $url;
		}
		return self::is_private_path( (string) get_attached_file( (int) $att_id, true ) ) ? '' : $url;
	}

	public static function block_private_attachment(): void {
		if ( is_attachment() && self::is_private_path( (string) get_attached_file( get_queried_object_id(), true ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/* ------------------------------------------------------------ participant roles */

	private static function is_participant_only( \WP_User $user ): bool {
		return $user->ID && array_intersect( (array) $user->roles, array_keys( self::ROLES ) ) && ! user_can( $user, 'edit_posts' );
	}

	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( self::is_participant_only( wp_get_current_user() ) ) {
			wp_safe_redirect( home_url( '/portal/' ) );
			exit;
		}
	}

	public static function admin_bar( $show ) {
		return self::is_participant_only( wp_get_current_user() ) ? false : $show;
	}

	/* ------------------------------------------------------------ [rc_portal] */

	public static function shortcode(): string {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;

		wp_enqueue_script( 'rc-qrcode', RC_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		wp_enqueue_script( 'rc-portal', RC_URL . 'assets/portal.js', array( 'rc-qrcode' ), RC_VERSION, true );
		wp_localize_script( 'rc-portal', 'RCPortal', self::js_config() );

		$reg_on = Settings::module_on( 'app_registration' );
		ob_start();
		?>
		<div class="rc-portal" data-rc-portal>
			<noscript><div class="rc-alert rc-alert--warn"><?php esc_html_e( 'The Participant Portal requires JavaScript. Please enable it to sign in.', 'reservechain' ); ?></div></noscript>
			<div class="rc-portal__live rc-sr" role="status" aria-live="polite" data-portal-live></div>

			<!-- Sign in -->
			<section class="rc-portal__auth" data-view="signin" hidden aria-labelledby="rc-portal-signin-h">
				<form class="rc-form rc-portal__form" novalidate data-form="signin">
					<p class="rc-kicker"><?php esc_html_e( 'Participant Portal', 'reservechain' ); ?></p>
					<h2 id="rc-portal-signin-h" tabindex="-1"><?php esc_html_e( 'Sign in', 'reservechain' ); ?></h2>
					<p class="rc-fine"><?php esc_html_e( 'Accounts are provided in staged access. Only sign in on reservechain.io. We never ask for a private key or seed phrase.', 'reservechain' ); ?></p>
					<div class="rc-form__grid rc-form__grid--1">
						<label class="rc-field"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?></span><input type="email" name="email" autocomplete="username" required maxlength="191"></label>
						<label class="rc-field"><span><?php esc_html_e( 'Password', 'reservechain' ); ?></span><input type="password" name="password" autocomplete="current-password" required></label>
					</div>
					<div class="rc-form__result" role="alert" data-result></div>
					<button class="rc-btn rc-btn--primary" type="submit"><?php esc_html_e( 'Sign in', 'reservechain' ); ?></button>
					<p class="rc-portal__alt">
						<?php if ( $reg_on ) : ?>
							<?php esc_html_e( 'No account yet?', 'reservechain' ); ?> <button type="button" class="rc-linkbtn" data-go="register"><?php esc_html_e( 'Create an account', 'reservechain' ); ?></button>
						<?php else : ?>
							<?php esc_html_e( 'Registration is currently closed.', 'reservechain' ); ?> <a href="<?php echo esc_url( home_url( '/participation/waitlist/' ) ); ?>"><?php esc_html_e( 'Join the waitlist', 'reservechain' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			</section>

			<!-- MFA -->
			<section class="rc-portal__auth" data-view="mfa" hidden aria-labelledby="rc-portal-mfa-h">
				<form class="rc-form rc-portal__form" novalidate data-form="mfa">
					<p class="rc-kicker"><?php esc_html_e( 'Two-step verification', 'reservechain' ); ?></p>
					<h2 id="rc-portal-mfa-h" tabindex="-1"><?php esc_html_e( 'Enter your authentication code', 'reservechain' ); ?></h2>
					<p class="rc-fine"><?php esc_html_e( 'Open your authenticator app and enter the 6-digit code, or use one of your single-use recovery codes.', 'reservechain' ); ?></p>
					<label class="rc-field"><span><?php esc_html_e( 'Authentication code', 'reservechain' ); ?></span><input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="12" class="rc-mono rc-portal__code"></label>
					<div class="rc-form__result" role="alert" data-result></div>
					<div class="rc-btn-row">
						<button class="rc-btn rc-btn--primary" type="submit"><?php esc_html_e( 'Verify', 'reservechain' ); ?></button>
						<button class="rc-btn" type="button" data-go="signin"><?php esc_html_e( 'Back', 'reservechain' ); ?></button>
					</div>
				</form>
			</section>

			<!-- Register -->
			<section class="rc-portal__auth" data-view="register" hidden aria-labelledby="rc-portal-reg-h">
				<?php if ( $reg_on ) : ?>
				<form class="rc-form rc-portal__form rc-portal__form--wide" novalidate data-form="register">
					<p class="rc-kicker"><?php esc_html_e( 'Participant Portal', 'reservechain' ); ?></p>
					<h2 id="rc-portal-reg-h" tabindex="-1"><?php esc_html_e( 'Create an account', 'reservechain' ); ?></h2>
					<p class="rc-fine"><?php esc_html_e( 'An account gives you access to your profile, eligibility status, notices and documents. It is not an investment, purchase, reservation or allocation of any kind.', 'reservechain' ); ?></p>
					<div class="rc-form__grid">
						<label class="rc-field"><span><?php esc_html_e( 'Full name', 'reservechain' ); ?></span><input name="name" autocomplete="name" maxlength="191"></label>
						<label class="rc-field" data-field="email"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?> *</span><input type="email" name="email" autocomplete="email" required maxlength="191"></label>
						<label class="rc-field" data-field="password"><span><?php esc_html_e( 'Password', 'reservechain' ); ?> *</span><input type="password" name="password" autocomplete="new-password" required minlength="12" aria-describedby="rc-portal-pw-hint"><small id="rc-portal-pw-hint" class="rc-fine"><?php esc_html_e( 'At least 12 characters with upper- and lower-case letters and a number.', 'reservechain' ); ?></small></label>
						<label class="rc-field" data-field="country"><span><?php esc_html_e( 'Country of residence', 'reservechain' ); ?> *</span>
							<select name="country" required>
								<option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option>
								<?php foreach ( Schema::countries() as $code => $cname ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $cname ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<fieldset class="rc-field rc-field--inline rc-field--wide"><legend><?php esc_html_e( 'I am registering as', 'reservechain' ); ?></legend>
							<label><input type="radio" name="entity_type" value="individual" checked> <?php esc_html_e( 'Individual', 'reservechain' ); ?></label>
							<label><input type="radio" name="entity_type" value="institution"> <?php esc_html_e( 'Institution / company', 'reservechain' ); ?></label>
						</fieldset>
					</div>
					<div class="rc-disclosure rc-disclosure--form">
						<p><?php echo esc_html( I18n::t( (string) Settings::get( 'disclosure' ) ) ); ?></p>
						<p><?php echo esc_html( I18n::t( (string) Settings::get( 'eu_notice' ) ) ); ?></p>
					</div>
					<div data-field="consent">
						<label class="rc-check"><input type="checkbox" name="accept_disclosure" value="1" required> <?php esc_html_e( 'I have read and understood the notice above.', 'reservechain' ); ?> *</label>
						<label class="rc-check"><input type="checkbox" name="accept_terms" value="1" required> <?php printf( wp_kses( __( 'I accept the <a href="%1$s">Terms of Use</a> and the <a href="%2$s">Privacy Notice</a>.', 'reservechain' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( home_url( '/legal/terms/' ) ), esc_url( home_url( '/legal/privacy/' ) ) ); ?> *</label>
					</div>
					<div class="rc-form__result" role="alert" data-result></div>
					<div class="rc-btn-row">
						<button class="rc-btn rc-btn--primary" type="submit"><?php esc_html_e( 'Create account', 'reservechain' ); ?></button>
						<button class="rc-btn" type="button" data-go="signin"><?php esc_html_e( 'Back to sign in', 'reservechain' ); ?></button>
					</div>
				</form>
				<?php else : ?>
				<div class="rc-form rc-portal__form">
					<h2 id="rc-portal-reg-h" tabindex="-1"><?php esc_html_e( 'Create an account', 'reservechain' ); ?></h2>
					<div class="rc-locked"><span class="rc-locked__icon" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Registration is currently closed.', 'reservechain' ); ?></strong><p><?php esc_html_e( 'Accounts are provided in staged access. Join the waitlist for development updates and future eligibility information.', 'reservechain' ); ?></p></div></div>
					<div class="rc-btn-row"><a class="rc-btn rc-btn--primary" href="<?php echo esc_url( home_url( '/participation/waitlist/' ) ); ?>"><?php esc_html_e( 'Join the waitlist', 'reservechain' ); ?></a><button class="rc-btn" type="button" data-go="signin"><?php esc_html_e( 'Back to sign in', 'reservechain' ); ?></button></div>
				</div>
				<?php endif; ?>
			</section>

			<!-- Dashboard -->
			<section class="rc-portal__app" data-view="app" hidden aria-labelledby="rc-portal-app-h">
				<header class="rc-portal__bar">
					<div>
						<p class="rc-kicker"><?php esc_html_e( 'Participant Portal', 'reservechain' ); ?></p>
						<h2 id="rc-portal-app-h" tabindex="-1"><?php esc_html_e( 'Welcome', 'reservechain' ); ?> <span data-bind="name"></span></h2>
					</div>
					<div class="rc-portal__bar-actions">
						<span class="rc-fine" data-bind="email"></span>
						<button class="rc-btn rc-btn--sm" type="button" data-action="logout"><?php esc_html_e( 'Sign out', 'reservechain' ); ?></button>
					</div>
				</header>
				<div class="rc-portal__layout">
					<nav class="rc-portal__nav" aria-label="<?php esc_attr_e( 'Portal sections', 'reservechain' ); ?>">
						<ul>
							<?php
							$tabs = array(
								'overview'      => __( 'Overview', 'reservechain' ),
								'profile'       => __( 'Profile', 'reservechain' ),
								'eligibility'   => __( 'Eligibility', 'reservechain' ),
								'security'      => __( 'Security & MFA', 'reservechain' ),
								'notifications' => __( 'Notifications', 'reservechain' ),
								'programs'      => __( 'Programs', 'reservechain' ),
								'passports'     => __( 'Digital Asset Passports', 'reservechain' ),
								'documents'     => __( 'Documents', 'reservechain' ),
								'holdings'      => __( 'Holdings & transactions', 'reservechain' ),
								'redemption'    => __( 'Redemption', 'reservechain' ),
								'wallet'        => __( 'Wallet', 'reservechain' ),
								'support'       => __( 'Support', 'reservechain' ),
							);
							foreach ( $tabs as $k => $label ) {
								printf( '<li><a href="#portal-%1$s" data-tab="%1$s">%2$s%3$s</a></li>', esc_attr( $k ), esc_html( $label ), 'notifications' === $k ? ' <span class="rc-portal__badge" data-bind="unread" hidden></span>' : '' );
							}
							?>
						</ul>
					</nav>
					<div class="rc-portal__main">
						<section class="rc-portal__panel" data-panel="overview" aria-labelledby="rc-p-overview">
							<h3 id="rc-p-overview" tabindex="-1"><?php esc_html_e( 'Overview', 'reservechain' ); ?></h3>
							<dl class="rc-kv" data-overview></dl>
							<p class="rc-fine"><?php esc_html_e( 'ReserveChain is in development. No tokens are offered or sold and no holdings exist. Modules marked inactive are built but locked until written authorization and final approval.', 'reservechain' ); ?></p>
						</section>

						<section class="rc-portal__panel" data-panel="profile" hidden aria-labelledby="rc-p-profile">
							<h3 id="rc-p-profile" tabindex="-1"><?php esc_html_e( 'Profile', 'reservechain' ); ?></h3>
							<dl class="rc-kv" data-profile></dl>
							<form class="rc-form rc-portal__subform" novalidate data-form="profile">
								<div class="rc-form__grid">
									<label class="rc-field"><span><?php esc_html_e( 'Display name', 'reservechain' ); ?></span><input name="name" autocomplete="name" maxlength="191"></label>
									<label class="rc-field"><span><?php esc_html_e( 'Language', 'reservechain' ); ?></span><select name="language"><option value="en">English</option><option value="es">Español</option><option value="it">Italiano</option></select></label>
								</div>
								<div class="rc-form__result" role="status" data-result></div>
								<button class="rc-btn rc-btn--sm" type="submit"><?php esc_html_e( 'Save changes', 'reservechain' ); ?></button>
							</form>
							<p class="rc-fine"><?php esc_html_e( 'Country of residence and entity type can only be changed by the compliance team. Contact support for data-export or account-closure requests.', 'reservechain' ); ?></p>
						</section>

						<section class="rc-portal__panel" data-panel="eligibility" hidden aria-labelledby="rc-p-elig">
							<h3 id="rc-p-elig" tabindex="-1"><?php esc_html_e( 'Eligibility', 'reservechain' ); ?></h3>
							<div class="rc-claims" data-eligibility></div>
							<p class="rc-fine"><?php esc_html_e( 'ReserveChain does not perform identity verification itself; statuses reflect the outcome of an independent KYC/KYB provider, which is still to be selected. Eligibility status does not confer any right to participate in any future offering.', 'reservechain' ); ?></p>
						</section>

						<section class="rc-portal__panel" data-panel="security" hidden aria-labelledby="rc-p-sec">
							<h3 id="rc-p-sec" tabindex="-1"><?php esc_html_e( 'Security & MFA', 'reservechain' ); ?></h3>
							<div data-mfa-status></div>
							<div class="rc-portal__mfa" data-mfa-setup hidden>
								<p><?php esc_html_e( 'Scan this QR code with an authenticator app (1Password, Authy, Google Authenticator, Microsoft Authenticator), then enter the 6-digit code it shows.', 'reservechain' ); ?></p>
								<div class="rc-portal__qr" data-mfa-qr></div>
								<p class="rc-fine"><?php esc_html_e( 'Or enter this key manually:', 'reservechain' ); ?> <code class="rc-mono" data-mfa-secret></code></p>
								<form class="rc-portal__inline" novalidate data-form="mfa-enable">
									<label class="rc-field"><span><?php esc_html_e( 'Authentication code', 'reservechain' ); ?></span><input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="6" class="rc-mono"></label>
									<button class="rc-btn rc-btn--primary rc-btn--sm" type="submit"><?php esc_html_e( 'Enable MFA', 'reservechain' ); ?></button>
									<div class="rc-form__result" role="alert" data-result></div>
								</form>
							</div>
							<div class="rc-alert rc-alert--warn rc-portal__codes" data-mfa-codes hidden tabindex="-1">
								<strong><?php esc_html_e( 'Save these single-use recovery codes now. They will not be shown again.', 'reservechain' ); ?></strong>
								<ol class="rc-mono" data-mfa-codes-list></ol>
								<div class="rc-btn-row"><button class="rc-btn rc-btn--sm" type="button" data-action="copy-codes"><?php esc_html_e( 'Copy codes', 'reservechain' ); ?></button><button class="rc-btn rc-btn--primary rc-btn--sm" type="button" data-action="codes-saved"><?php esc_html_e( 'I have saved my codes', 'reservechain' ); ?></button></div>
							</div>
							<ul class="rc-list rc-fine">
								<li><?php esc_html_e( 'You are signed out automatically after 15 minutes of inactivity.', 'reservechain' ); ?></li>
								<li><?php esc_html_e( 'Signing out ends all of your sessions, on the website and in the apps.', 'reservechain' ); ?></li>
								<li><?php esc_html_e( 'We never ask for a private key or seed phrase.', 'reservechain' ); ?></li>
							</ul>
						</section>

						<section class="rc-portal__panel" data-panel="notifications" hidden aria-labelledby="rc-p-notif">
							<h3 id="rc-p-notif" tabindex="-1"><?php esc_html_e( 'Notifications', 'reservechain' ); ?></h3>
							<ul class="rc-portal__list" data-notifications></ul>
						</section>

						<section class="rc-portal__panel" data-panel="programs" hidden aria-labelledby="rc-p-prog">
							<h3 id="rc-p-prog" tabindex="-1"><?php esc_html_e( 'Programs', 'reservechain' ); ?></h3>
							<div class="rc-portal__cards" data-programs></div>
						</section>

						<section class="rc-portal__panel" data-panel="passports" hidden aria-labelledby="rc-p-pass">
							<h3 id="rc-p-pass" tabindex="-1"><?php esc_html_e( 'Digital Asset Passports', 'reservechain' ); ?></h3>
							<ul class="rc-portal__list" data-passports></ul>
						</section>

						<section class="rc-portal__panel" data-panel="documents" hidden aria-labelledby="rc-p-docs">
							<h3 id="rc-p-docs" tabindex="-1"><?php esc_html_e( 'Documents', 'reservechain' ); ?></h3>
							<div data-rooms></div>
							<h4><?php esc_html_e( 'Restricted documents available to you', 'reservechain' ); ?></h4>
							<div class="rc-doclist" data-restricted-docs></div>
							<h4><?php esc_html_e( 'Public document library', 'reservechain' ); ?></h4>
							<div class="rc-doclist" data-public-docs></div>
						</section>

						<section class="rc-portal__panel" data-panel="holdings" hidden aria-labelledby="rc-p-hold">
							<h3 id="rc-p-hold" tabindex="-1"><?php esc_html_e( 'Holdings & transactions', 'reservechain' ); ?></h3>
							<div data-holdings></div>
							<div data-transactions></div>
						</section>

						<section class="rc-portal__panel" data-panel="redemption" hidden aria-labelledby="rc-p-red">
							<h3 id="rc-p-red" tabindex="-1"><?php esc_html_e( 'Redemption', 'reservechain' ); ?></h3>
							<div data-module-panel="redemption" data-href="<?php echo esc_url( home_url( '/portal/redemption/' ) ); ?>"></div>
						</section>

						<section class="rc-portal__panel" data-panel="wallet" hidden aria-labelledby="rc-p-wal">
							<h3 id="rc-p-wal" tabindex="-1"><?php esc_html_e( 'Wallet', 'reservechain' ); ?></h3>
							<div data-module-panel="wallet"></div>
							<p class="rc-fine"><?php esc_html_e( 'If wallet functions are ever authorized, a wallet will require signature-based ownership proof and risk screening before use. Testnet only.', 'reservechain' ); ?></p>
						</section>

						<section class="rc-portal__panel" data-panel="support" hidden aria-labelledby="rc-p-sup">
							<h3 id="rc-p-sup" tabindex="-1"><?php esc_html_e( 'Support', 'reservechain' ); ?></h3>
							<form class="rc-form rc-portal__subform" novalidate data-form="support">
								<div class="rc-form__grid">
									<label class="rc-field"><span><?php esc_html_e( 'Topic', 'reservechain' ); ?></span>
										<select name="topic">
											<option value="support"><?php esc_html_e( 'Account & portal support', 'reservechain' ); ?></option>
											<option value="compliance"><?php esc_html_e( 'Eligibility & compliance', 'reservechain' ); ?></option>
											<option value="documents"><?php esc_html_e( 'Documents & data room', 'reservechain' ); ?></option>
											<option value="security"><?php esc_html_e( 'Security / fraud report', 'reservechain' ); ?></option>
											<option value="general"><?php esc_html_e( 'General enquiry', 'reservechain' ); ?></option>
										</select>
									</label>
									<label class="rc-field"><span><?php esc_html_e( 'Subject', 'reservechain' ); ?></span><input name="subject" maxlength="150"></label>
									<label class="rc-field rc-field--wide" data-field="message"><span><?php esc_html_e( 'Message', 'reservechain' ); ?> *</span><textarea name="message" rows="5" required minlength="10"></textarea></label>
								</div>
								<div class="rc-form__result" role="status" data-result></div>
								<button class="rc-btn rc-btn--primary rc-btn--sm" type="submit"><?php esc_html_e( 'Send request', 'reservechain' ); ?></button>
							</form>
						</section>
					</div>
				</div>
			</section>
			<p class="rc-portal__loading" data-view="loading"><?php esc_html_e( 'Loading the portal…', 'reservechain' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function js_config(): array {
		$labels = Compliance::CHECK_STATES;
		return array(
			'api'        => esc_url_raw( rest_url( Rest::NS ) ),
			'lang'       => I18n::lang(),
			'idleMs'     => 15 * MINUTE_IN_SECONDS * 1000,
			'programUrl' => home_url( '/assets/industrial-metals/' ),
			'audiences'  => self::audience_labels(),
			'i18n'       => array(
				'error'         => __( 'Something went wrong. Please try again.', 'reservechain' ),
				'network'       => __( 'The portal could not reach the server. Check your connection and try again.', 'reservechain' ),
				'signingIn'     => __( 'Signing in…', 'reservechain' ),
				'signedIn'      => __( 'Signed in.', 'reservechain' ),
				'signedOut'     => __( 'You have been signed out.', 'reservechain' ),
				'idleOut'       => __( 'You were signed out after 15 minutes of inactivity.', 'reservechain' ),
				'expired'       => __( 'Your session expired. Please sign in again.', 'reservechain' ),
				'required'      => __( 'Please fill in the required fields.', 'reservechain' ),
				'registered'    => __( 'If the address can be registered, you can now sign in.', 'reservechain' ),
				'saved'         => __( 'Saved.', 'reservechain' ),
				'sending'       => __( 'Submitting…', 'reservechain' ),
				'ticket'        => __( 'Request received. Your reference is %s.', 'reservechain' ),
				'mfaOn'         => __( 'Multi-factor authentication is enabled.', 'reservechain' ),
				'mfaOff'        => __( 'Multi-factor authentication is not enabled. Protect your account by setting it up now.', 'reservechain' ),
				'mfaSetup'      => __( 'Set up MFA', 'reservechain' ),
				'mfaEnabled'    => __( 'Enabled', 'reservechain' ),
				'mfaNotEnabled' => __( 'Not enabled', 'reservechain' ),
				'mfaDone'       => __( 'MFA enabled. Save your recovery codes.', 'reservechain' ),
				'copied'        => __( 'Copied', 'reservechain' ),
				'markRead'      => __( 'Mark as read', 'reservechain' ),
				'read'          => __( 'Read', 'reservechain' ),
				'unread'        => __( 'Unread', 'reservechain' ),
				'broadcast'     => __( 'Announcement', 'reservechain' ),
				'noNotices'     => __( 'No notifications yet.', 'reservechain' ),
				'noPrograms'    => __( 'No programs have been published yet.', 'reservechain' ),
				'noPassports'   => __( 'No passports have been published yet.', 'reservechain' ),
				'passportsOff'  => __( 'Digital Asset Passports are not public at the moment.', 'reservechain' ),
				'noRestricted'  => __( 'There are no restricted documents for your account.', 'reservechain' ),
				'noPublic'      => __( 'No documents have been published yet.', 'reservechain' ),
				'download'      => __( 'Download', 'reservechain' ),
				'open'          => __( 'Open', 'reservechain' ),
				'viewPassport'  => __( 'View passport', 'reservechain' ),
				'viewProgram'   => __( 'View program', 'reservechain' ),
				'roomInvestor'  => __( 'Investor data room', 'reservechain' ),
				'roomEnterprise' => __( 'Enterprise client documents', 'reservechain' ),
				'locked'        => __( 'This module is built but not active. It will only be enabled after written authorization and final approval.', 'reservechain' ),
				'active'        => __( 'Active', 'reservechain' ),
				'inactive'      => __( 'Inactive', 'reservechain' ),
				'moduleOn'      => __( 'This module is active for your account.', 'reservechain' ),
				'holdings'      => __( 'Token holdings', 'reservechain' ),
				'transactions'  => __( 'Transaction history', 'reservechain' ),
				'redemption'    => __( 'Redemption requests', 'reservechain' ),
				'wallet'        => __( 'Wallet connection', 'reservechain' ),
				'noItems'       => __( 'Nothing to show.', 'reservechain' ),
				'name'          => __( 'Name', 'reservechain' ),
				'email'         => __( 'Email', 'reservechain' ),
				'country'       => __( 'Country of residence', 'reservechain' ),
				'entity'        => __( 'Entity type', 'reservechain' ),
				'individual'    => __( 'Individual', 'reservechain' ),
				'institution'   => __( 'Institution / company', 'reservechain' ),
				'language'      => __( 'Language', 'reservechain' ),
				'memberSince'   => __( 'Member since', 'reservechain' ),
				'mfa'           => __( 'MFA', 'reservechain' ),
				'eligibility'   => __( 'Eligibility', 'reservechain' ),
				'notices'       => __( 'Unread notifications', 'reservechain' ),
				'notProvided'   => __( 'Not provided', 'reservechain' ),
				'jurisdiction'  => __( 'Jurisdiction', 'reservechain' ),
				'kyc'           => __( 'KYC (individual identity)', 'reservechain' ),
				'kyb'           => __( 'KYB (business verification)', 'reservechain' ),
				'aml'           => __( 'AML screening', 'reservechain' ),
				'sanctions'     => __( 'Sanctions / PEP screening', 'reservechain' ),
				'overall'       => __( 'Overall', 'reservechain' ),
				'st'            => array(
					'not_started'    => __( $labels['not_started'], 'reservechain' ), // phpcs:ignore
					'pending'        => __( $labels['pending'], 'reservechain' ), // phpcs:ignore
					'approved'       => __( $labels['approved'], 'reservechain' ), // phpcs:ignore
					'rejected'       => __( $labels['rejected'], 'reservechain' ), // phpcs:ignore
					'not_applicable' => __( $labels['not_applicable'], 'reservechain' ), // phpcs:ignore
					'eligible'       => __( 'Eligible', 'reservechain' ),
					'restricted'     => __( 'Restricted', 'reservechain' ),
					'incomplete'     => __( 'Incomplete', 'reservechain' ),
					'eligible_subject_to_final_approval' => __( 'Eligible, subject to final approval', 'reservechain' ),
				),
				'statuses'      => Schema::claim_statuses(),
				'eligNote'      => __( 'Eligibility status does not confer any right to participate in any future offering.', 'reservechain' ),
			),
		);
	}
}
