<?php
/**
 * Asset-owner / originator intake ([rc_asset_intake]) — gated by module `asset_owner_portal`.
 *
 * Submissions become private `rc_intake` records (ReserveChain menu) with the pipeline
 * received → initial_review → due_diligence → accepted_for_onboarding | declined.
 * Final decisions use four-eyes: one manager proposes, a different user with `rc_approve` confirms,
 * bound to a fingerprint of the submission as proposed. Certificates (PDF / images, max 5 × 20 MB) are
 * stored in uploads/rc-private/intake/ (never publicly linkable), SHA-256 fingerprinted, and streamed to staff only.
 * A submission is not an acceptance, a valuation or a commitment by either side.
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Intake {

	public const MODULE    = 'asset_owner_portal';
	public const TYPE      = 'rc_intake';
	public const CAP       = 'rc_manage_intake';
	private const MAX_FILES = 5;
	private const MAX_BYTES = 20 * MB_IN_BYTES;
	private const FILE_EXT  = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' );

	public static function states(): array {
		return array(
			'received'                => __( 'Received', 'reservechain' ),
			'initial_review'          => __( 'Initial review', 'reservechain' ),
			'due_diligence'           => __( 'Due diligence', 'reservechain' ),
			'accepted_for_onboarding' => __( 'Accepted for onboarding', 'reservechain' ),
			'declined'                => __( 'Declined', 'reservechain' ),
		);
	}

	/** Pill class per state (site status hallmarks). */
	private const STATE_PILL = array(
		'received'                => 'pending',
		'initial_review'          => 'in_development',
		'due_diligence'           => 'pending_verification',
		'accepted_for_onboarding' => 'verified',
		'declined'                => 'restricted',
	);

	public static function roles(): array {
		return array(
			'producer'   => __( 'Producer / refiner', 'reservechain' ),
			'owner'      => __( 'Owner / holder', 'reservechain' ),
			'originator' => __( 'Originator / supplier', 'reservechain' ),
			'custodian'  => __( 'Custodian / warehouse', 'reservechain' ),
			'other'      => __( 'Other', 'reservechain' ),
		);
	}

	public static function materials(): array {
		return array(
			'copper_powder' => __( 'Copper powder', 'reservechain' ),
			'nickel_wire'   => __( 'Nickel wire', 'reservechain' ),
			'other_metal'   => __( 'Other industrial metal', 'reservechain' ),
			'other_asset'   => __( 'Other asset class', 'reservechain' ),
		);
	}

	public static function units(): array {
		return array(
			'kg'     => __( 'kilograms (kg)', 'reservechain' ),
			't'      => __( 'metric tonnes (t)', 'reservechain' ),
			'lb'     => __( 'pounds (lb)', 'reservechain' ),
			'm'      => __( 'metres (m)', 'reservechain' ),
			'km'     => __( 'kilometres (km)', 'reservechain' ),
			'pieces' => __( 'pieces / units', 'reservechain' ),
			'other'  => __( 'other (describe)', 'reservechain' ),
		);
	}

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_shortcode( 'rc_asset_intake', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_rc_intake_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_nopriv_rc_intake_submit', array( __CLASS__, 'submit' ) );
		add_action( 'admin_post_rc_intake_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_rc_intake_file', array( __CLASS__, 'file' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'manage_' . self::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function install(): void {
		foreach ( array( 'administrator', 'rc_compliance_officer', 'rc_registry_manager', 'rc_reviewer' ) as $role ) {
			$r = get_role( $role );
			if ( $r ) {
				$r->add_cap( self::CAP );
			}
		}
		Portal::private_dir( 'intake' );
	}

	public static function register(): void {
		$c = self::CAP;
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => 'Asset intake',
					'singular_name' => 'Asset submission',
					'menu_name'     => 'Asset intake',
					'all_items'     => 'Asset intake',
					'edit_item'     => 'Review asset submission',
					'search_items'  => 'Search submissions',
					'not_found'     => 'No asset submissions yet.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'reservechain',
				'show_in_rest'        => false,
				'query_var'           => false,
				'rewrite'             => false,
				'supports'            => false,
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $c,
					'read_post'              => $c,
					'delete_post'            => 'do_not_allow',
					'edit_posts'             => $c,
					'edit_others_posts'      => $c,
					'edit_private_posts'     => $c,
					'edit_published_posts'   => $c,
					'publish_posts'          => 'do_not_allow',
					'read_private_posts'     => $c,
					'delete_posts'           => 'do_not_allow',
					'delete_private_posts'   => 'do_not_allow',
					'delete_published_posts' => 'do_not_allow',
					'delete_others_posts'    => 'do_not_allow',
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	/* ------------------------------------------------------------ helpers */

	private static function m( int $id, string $k ) {
		return get_post_meta( $id, '_rc_intake_' . $k, true );
	}

	private static function arr( int $id, string $k ): array {
		$v = get_post_meta( $id, '_rc_intake_' . $k, true );
		return is_array( $v ) ? $v : array();
	}

	public static function state( int $id ): string {
		return (string) self::m( $id, 'state' ) ?: 'received';
	}

	public static function pill( string $state ): string {
		if ( is_admin() ) {
			return '<span class="rc-pill rc-pill-' . esc_attr( self::STATE_PILL[ $state ] ?? 'pending' ) . '">' . esc_html( self::states()[ $state ] ?? $state ) . '</span>';
		}
		return Shortcodes::pill( self::STATE_PILL[ $state ] ?? 'pending', self::states()[ $state ] ?? $state );
	}

	/** Fingerprint of what is being decided: submission content + evidence hashes + state + decision. */
	public static function fingerprint( int $id, string $decision ): string {
		$files = array_map( static fn( $f ) => $f['sha256'] ?? '', self::arr( $id, 'files' ) );
		return hash( 'sha256', wp_json_encode( array( self::arr( $id, 'data' ), $files, self::state( $id ), $decision, $id ) ) );
	}

	private static function wants_json(): bool {
		return false !== strpos( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ), 'application/json' ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ front-end form */

	public static function shortcode(): string {
		if ( ! Settings::module_on( self::MODULE ) ) {
			return '<div class="rc-locked"><span class="rc-locked__icon" aria-hidden="true"></span><div><strong>' . esc_html__( 'Asset-owner portal', 'reservechain' ) . '</strong><p>' . esc_html__( 'This module is built but not active. It will only be enabled after written authorization and final approval.', 'reservechain' ) . '</p><p><a class="rc-btn rc-btn--sm" href="' . esc_url( home_url( '/company/contact/?topic=supply' ) ) . '">' . esc_html__( 'Contact us about an asset', 'reservechain' ) . '</a></p></div></div>';
		}
		wp_enqueue_script( 'rc-intake', RC_URL . 'assets/intake.js', array(), RC_VERSION, true );
		wp_localize_script(
			'rc-intake',
			'RCIntake',
			array(
				'maxFiles' => self::MAX_FILES,
				'maxBytes' => self::MAX_BYTES,
				'ext'      => self::FILE_EXT,
				'i18n'     => array(
					'required' => __( 'Please complete the required fields in this step.', 'reservechain' ),
					'tooMany'  => __( 'You can attach at most 5 files.', 'reservechain' ),
					'tooBig'   => __( 'Each file must be 20 MB or smaller.', 'reservechain' ),
					'badType'  => __( 'Only PDF, JPG, PNG and WebP files are accepted.', 'reservechain' ),
					'sending'  => __( 'Submitting…', 'reservechain' ),
					'error'    => __( 'Something went wrong. Please try again.', 'reservechain' ),
					'step'     => __( 'Step %1$d of %2$d', 'reservechain' ),
				),
			)
		);

		$banner = '';
		if ( isset( $_GET['rc_intake'] ) && 'ok' === $_GET['rc_intake'] ) { // phpcs:ignore
			$banner = '<div class="rc-alert rc-alert--ok" role="status">' . esc_html( sprintf( __( 'Thank you. Your submission was received with reference %s. We have sent a confirmation by email. A submission is not an acceptance, a valuation or a commitment.', 'reservechain' ), sanitize_text_field( wp_unslash( $_GET['ref'] ?? '' ) ) ) ) . '</div>'; // phpcs:ignore
		} elseif ( isset( $_GET['rc_intake_err'] ) ) { // phpcs:ignore
			$errs = get_transient( 'rc_intake_err_' . sanitize_key( $_GET['rc_intake_err'] ) ); // phpcs:ignore
			if ( is_array( $errs ) ) {
				$banner = '<div class="rc-alert rc-alert--err" role="alert"><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errs ) ) . '</li></ul></div>';
			}
		}

		$steps = array( __( 'Organisation & contact', 'reservechain' ), __( 'Asset', 'reservechain' ), __( 'Certificates', 'reservechain' ), __( 'Consents & submit', 'reservechain' ) );
		ob_start();
		echo $banner; // phpcs:ignore
		?>
		<form id="rc-intake" class="rc-form rc-intake" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate data-rc-intake>
			<input type="hidden" name="action" value="rc_intake_submit">
			<?php wp_nonce_field( 'rc_intake_submit', '_rc_intake' ); ?>
			<input type="hidden" name="ts" value="<?php echo esc_attr( (string) time() ); ?>">
			<input type="hidden" name="language" value="<?php echo esc_attr( I18n::lang() ); ?>">
			<input type="hidden" name="return" value="<?php echo esc_url( get_permalink() ?: home_url( '/enterprise/asset-owners/' ) ); ?>">
			<ol class="rc-intake__steps" aria-label="<?php esc_attr_e( 'Submission steps', 'reservechain' ); ?>">
				<?php foreach ( $steps as $i => $s ) : ?>
					<li data-step-ind="<?php echo (int) $i; ?>"<?php echo 0 === $i ? ' aria-current="step"' : ''; ?>><span><?php echo (int) $i + 1; ?></span><?php echo esc_html( $s ); ?></li>
				<?php endforeach; ?>
			</ol>
			<p class="rc-sr" aria-live="polite" data-step-live></p>

			<fieldset class="rc-intake__step" data-step="0">
				<legend><?php echo esc_html( $steps[0] ); ?></legend>
				<div class="rc-form__grid">
					<label class="rc-field" data-field="organisation"><span><?php esc_html_e( 'Organisation', 'reservechain' ); ?> *</span><input name="organisation" autocomplete="organization" required maxlength="191"></label>
					<label class="rc-field" data-field="contact_name"><span><?php esc_html_e( 'Contact person', 'reservechain' ); ?> *</span><input name="contact_name" autocomplete="name" required maxlength="191"></label>
					<label class="rc-field" data-field="email"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?> *</span><input type="email" name="email" autocomplete="email" required maxlength="191"></label>
					<label class="rc-field"><span><?php esc_html_e( 'Phone (optional)', 'reservechain' ); ?></span><input type="tel" name="phone" autocomplete="tel" maxlength="40"></label>
					<fieldset class="rc-field rc-field--inline rc-field--wide" data-field="role"><legend><?php esc_html_e( 'Your role', 'reservechain' ); ?> *</legend>
						<?php foreach ( self::roles() as $k => $l ) : ?>
							<label class="rc-chip"><input type="radio" name="role" value="<?php echo esc_attr( $k ); ?>" required> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</fieldset>
				</div>
			</fieldset>

			<fieldset class="rc-intake__step" data-step="1">
				<legend><?php echo esc_html( $steps[1] ); ?></legend>
				<div class="rc-form__grid">
					<fieldset class="rc-field rc-field--inline rc-field--wide" data-field="material"><legend><?php esc_html_e( 'Material', 'reservechain' ); ?> *</legend>
						<?php foreach ( self::materials() as $k => $l ) : ?>
							<label class="rc-chip"><input type="radio" name="material" value="<?php echo esc_attr( $k ); ?>" required> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label class="rc-field rc-field--wide" data-field="material_other"><span><?php esc_html_e( 'If other: which material or asset class?', 'reservechain' ); ?></span><input name="material_other" maxlength="191"></label>
					<label class="rc-field rc-field--wide" data-field="description"><span><?php esc_html_e( 'Description', 'reservechain' ); ?> *</span><textarea name="description" rows="5" required minlength="20" maxlength="5000" aria-describedby="rc-intake-desc-hint"></textarea><small id="rc-intake-desc-hint" class="rc-fine"><?php esc_html_e( 'Specification, form, purity, packaging, lot identifiers and ownership situation, as far as known.', 'reservechain' ); ?></small></label>
					<label class="rc-field" data-field="quantity"><span><?php esc_html_e( 'Declared quantity', 'reservechain' ); ?> *</span><input type="number" name="quantity" min="0" step="any" required inputmode="decimal"></label>
					<label class="rc-field" data-field="unit"><span><?php esc_html_e( 'Unit', 'reservechain' ); ?> *</span>
						<select name="unit" required><option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option><?php foreach ( self::units() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
					</label>
					<label class="rc-field" data-field="country"><span><?php esc_html_e( 'Location of the asset (country)', 'reservechain' ); ?> *</span>
						<select name="country" required><option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option><?php foreach ( Schema::countries() as $code => $cname ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $cname ); ?></option><?php endforeach; ?></select>
					</label>
				</div>
				<p class="rc-fine"><?php esc_html_e( 'Quantities are recorded as declared by you and are never presented as verified.', 'reservechain' ); ?></p>
			</fieldset>

			<fieldset class="rc-intake__step" data-step="2">
				<legend><?php echo esc_html( $steps[2] ); ?></legend>
				<label class="rc-field rc-field--wide" data-field="certificates"><span><?php esc_html_e( 'Existing certificates (optional)', 'reservechain' ); ?></span><input type="file" name="certificates[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" aria-describedby="rc-intake-files-hint"><small id="rc-intake-files-hint" class="rc-fine"><?php esc_html_e( 'Certificates of Analysis, weight certificates, warehouse receipts, photographs. PDF, JPG, PNG or WebP; up to 5 files, 20 MB each. Files are stored privately and fingerprinted (SHA-256) on receipt.', 'reservechain' ); ?></small></label>
				<ul class="rc-intake__files" data-files></ul>
			</fieldset>

			<fieldset class="rc-intake__step" data-step="3">
				<legend><?php echo esc_html( $steps[3] ); ?></legend>
				<dl class="rc-kv" data-summary hidden></dl>
				<div data-field="consent">
					<label class="rc-check"><input type="checkbox" name="consent_accuracy" value="1" required> <?php esc_html_e( 'The information is accurate to the best of my knowledge and I am authorised to submit it on behalf of the organisation.', 'reservechain' ); ?> *</label>
					<label class="rc-check"><input type="checkbox" name="consent_no_commitment" value="1" required> <?php esc_html_e( 'I understand that a submission starts a due-diligence review and is not an acceptance, a valuation or a commitment by either side.', 'reservechain' ); ?> *</label>
					<label class="rc-check"><input type="checkbox" name="consent_privacy" value="1" required> <?php printf( wp_kses( __( 'I agree to the processing of my data as described in the <a href="%s">Privacy Notice</a>.', 'reservechain' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( home_url( '/legal/privacy/' ) ) ); ?> *</label>
				</div>
				<div class="rc-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
			</fieldset>

			<div class="rc-form__result" role="alert" data-result></div>
			<div class="rc-btn-row rc-intake__nav">
				<button class="rc-btn" type="button" data-prev hidden><?php esc_html_e( 'Back', 'reservechain' ); ?></button>
				<button class="rc-btn rc-btn--primary" type="button" data-next hidden><?php esc_html_e( 'Continue', 'reservechain' ); ?></button>
				<button class="rc-btn rc-btn--primary" type="submit" data-submit><?php esc_html_e( 'Submit asset for review', 'reservechain' ); ?></button>
			</div>
			<p class="rc-form__fine"><?php esc_html_e( 'We will not publish your data, name you as a partner or indicate any value before independent verification, four-eyes review and your written approval.', 'reservechain' ); ?></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------ submission */

	private static function respond( bool $ok, array $payload, int $status, string $return ): void {
		if ( self::wants_json() ) {
			wp_send_json( array_merge( array( 'ok' => $ok ), $payload ), $status );
		}
		$return = wp_validate_redirect( $return, home_url( '/enterprise/asset-owners/' ) );
		if ( $ok ) {
			wp_safe_redirect( add_query_arg( array( 'rc_intake' => 'ok', 'ref' => $payload['ref'] ), $return ) . '#rc-intake' );
		} else {
			$token = wp_generate_password( 12, false );
			set_transient( 'rc_intake_err_' . strtolower( $token ), array_values( (array) ( $payload['fields'] ?? array( $payload['message'] ?? '' ) ) ), 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'rc_intake_err', strtolower( $token ), $return ) );
		}
		exit;
	}

	public static function submit(): void {
		$p      = wp_unslash( $_POST ); // phpcs:ignore -- sanitised per field below.
		$return = esc_url_raw( (string) ( $p['return'] ?? '' ) );
		if ( ! Settings::module_on( self::MODULE ) ) {
			self::respond( false, array( 'message' => 'The asset-owner portal is not active.' ), 403, $return );
		}
		if ( ! wp_verify_nonce( (string) ( $p['_rc_intake'] ?? '' ), 'rc_intake_submit' ) ) {
			self::respond( false, array( 'message' => __( 'Your session expired. Please reload the page and try again.', 'reservechain' ) ), 403, $return );
		}
		if ( ! empty( $p['website'] ) || time() - (int) ( $p['ts'] ?? 0 ) < 3 ) {
			self::respond( true, array( 'ref' => 'RC-INT-000000', 'message' => '' ), 200, $return ); // Bot: silently accept.
		}
		if ( ! Security::rate_limit( 'intake', 5, HOUR_IN_SECONDS ) ) {
			self::respond( false, array( 'message' => __( 'Too many submissions. Please try again later.', 'reservechain' ) ), 429, $return );
		}

		$d = array(
			'organisation'   => sanitize_text_field( (string) ( $p['organisation'] ?? '' ) ),
			'contact_name'   => sanitize_text_field( (string) ( $p['contact_name'] ?? '' ) ),
			'email'          => sanitize_email( (string) ( $p['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
			'role'           => sanitize_key( (string) ( $p['role'] ?? '' ) ),
			'material'       => sanitize_key( (string) ( $p['material'] ?? '' ) ),
			'material_other' => sanitize_text_field( (string) ( $p['material_other'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $p['description'] ?? '' ) ),
			'quantity'       => is_numeric( $p['quantity'] ?? null ) ? (float) $p['quantity'] : null,
			'unit'           => sanitize_key( (string) ( $p['unit'] ?? '' ) ),
			'country'        => strtoupper( sanitize_text_field( (string) ( $p['country'] ?? '' ) ) ),
			'language'       => in_array( $p['language'] ?? '', array( 'en', 'es', 'it' ), true ) ? $p['language'] : 'en',
		);
		$e = array();
		if ( '' === $d['organisation'] ) {
			$e['organisation'] = __( 'Enter the organisation name.', 'reservechain' );
		}
		if ( '' === $d['contact_name'] ) {
			$e['contact_name'] = __( 'Enter a contact person.', 'reservechain' );
		}
		if ( ! is_email( $d['email'] ) ) {
			$e['email'] = __( 'Enter a valid email address.', 'reservechain' );
		}
		if ( ! isset( self::roles()[ $d['role'] ] ) ) {
			$e['role'] = __( 'Select your role.', 'reservechain' );
		}
		if ( ! isset( self::materials()[ $d['material'] ] ) ) {
			$e['material'] = __( 'Select the material.', 'reservechain' );
		} elseif ( in_array( $d['material'], array( 'other_metal', 'other_asset' ), true ) && '' === $d['material_other'] ) {
			$e['material_other'] = __( 'Name the material or asset class.', 'reservechain' );
		}
		if ( mb_strlen( $d['description'] ) < 20 ) {
			$e['description'] = __( 'Describe the asset (at least 20 characters).', 'reservechain' );
		}
		if ( null === $d['quantity'] || $d['quantity'] <= 0 ) {
			$e['quantity'] = __( 'Enter the declared quantity.', 'reservechain' );
		}
		if ( ! isset( self::units()[ $d['unit'] ] ) ) {
			$e['unit'] = __( 'Select a unit.', 'reservechain' );
		}
		if ( ! isset( Schema::countries()[ $d['country'] ] ) ) {
			$e['country'] = __( 'Select the country where the asset is located.', 'reservechain' );
		}
		if ( empty( $p['consent_accuracy'] ) || empty( $p['consent_no_commitment'] ) || empty( $p['consent_privacy'] ) ) {
			$e['consent'] = __( 'Please confirm all three declarations.', 'reservechain' );
		}

		// Certificates: validate everything before storing anything.
		$files = self::normalise_files();
		if ( count( $files ) > self::MAX_FILES ) {
			$e['certificates'] = __( 'You can attach at most 5 files.', 'reservechain' );
		}
		foreach ( $files as $f ) {
			if ( UPLOAD_ERR_OK !== $f['error'] ) {
				$e['certificates'] = UPLOAD_ERR_INI_SIZE === $f['error'] || UPLOAD_ERR_FORM_SIZE === $f['error'] ? __( 'Each file must be 20 MB or smaller.', 'reservechain' ) : __( 'A file could not be uploaded. Please try again.', 'reservechain' );
			} elseif ( $f['size'] > self::MAX_BYTES ) {
				$e['certificates'] = __( 'Each file must be 20 MB or smaller.', 'reservechain' );
			} elseif ( ! in_array( strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ), self::FILE_EXT, true ) ) {
				$e['certificates'] = __( 'Only PDF, JPG, PNG and WebP files are accepted.', 'reservechain' );
			}
		}
		if ( $e ) {
			self::respond( false, array( 'message' => __( 'Please correct the highlighted fields.', 'reservechain' ), 'fields' => $e ), 422, $return );
		}

		$stored = array();
		foreach ( $files as $f ) {
			$res = self::store_upload( $f );
			if ( isset( $res['error'] ) ) {
				foreach ( $stored as $s ) {
					wp_delete_file( self::abs( $s['path'] ) );
				}
				Audit_Log::record( 'intake.upload_rejected', self::TYPE, 0, 'Intake certificate rejected', array( 'reason' => $res['error'], 'ext' => strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) ), 0 );
				self::respond( false, array( 'message' => $res['error'], 'fields' => array( 'certificates' => $res['error'] ) ), 422, $return );
			}
			$stored[] = $res;
		}

		$uid = get_current_user_id();
		$id  = wp_insert_post(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'private',
				'post_title'  => $d['organisation'] . ' — ' . ( self::materials()[ $d['material'] ] ?? $d['material'] ),
				'post_author' => $uid,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			foreach ( $stored as $s ) {
				wp_delete_file( self::abs( $s['path'] ) );
			}
			self::respond( false, array( 'message' => __( 'Something went wrong. Please try again.', 'reservechain' ) ), 500, $return );
		}
		$ref = 'RC-INT-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
		$d['consents']     = array( 'accuracy' => true, 'no_commitment' => true, 'privacy' => true, 'at' => gmdate( 'c' ), 'disclosure_hash' => Settings::disclosure_hash() );
		$d['submitted_by'] = $uid;
		update_post_meta( $id, '_rc_intake_ref', $ref );
		update_post_meta( $id, '_rc_intake_data', $d );
		update_post_meta( $id, '_rc_intake_files', $stored );
		update_post_meta( $id, '_rc_intake_state', 'received' );
		update_post_meta( $id, '_rc_intake_history', array( array( 'from' => '', 'to' => 'received', 'by' => $uid, 'at' => gmdate( 'c' ) ) ) );

		Audit_Log::record(
			'intake.submitted',
			self::TYPE,
			$id,
			'Asset submission ' . $ref . ' received',
			array(
				'ref'        => $ref,
				'role'       => $d['role'],
				'material'   => $d['material'],
				'country'    => $d['country'],
				'email_hash' => hash( 'sha256', strtolower( $d['email'] ) ),
				'files'      => array_map( static fn( $s ) => array( 'sha256' => $s['sha256'], 'size' => $s['size'], 'mime' => $s['mime'] ), $stored ),
			),
			$uid
		);
		foreach ( $stored as $s ) {
			Audit_Log::record( 'intake.file_stored', self::TYPE, $id, 'Intake certificate stored privately', array( 'sha256' => $s['sha256'], 'size' => $s['size'], 'mime' => $s['mime'] ), $uid );
		}

		self::mail_submitter( $id, 'received' );
		$to = Settings::get( 'contact_email' ) ?: get_option( 'admin_email' );
		wp_mail( $to, '[ReserveChain] New asset submission ' . $ref, "A new asset submission was received.\n\nReference: {$ref}\nMaterial: " . ( self::materials()[ $d['material'] ] ?? $d['material'] ) . "\nCertificates: " . count( $stored ) . "\n\nReview: " . admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		if ( $uid ) {
			Notifications::push( $uid, 'Asset submission received', sprintf( 'Your asset submission %s was received and will be reviewed. A submission is not an acceptance, a valuation or a commitment.', $ref ), 'intake' );
		}
		self::respond( true, array( 'ref' => $ref, 'message' => sprintf( __( 'Thank you. Your submission was received with reference %s. We have sent a confirmation by email. A submission is not an acceptance, a valuation or a commitment.', 'reservechain' ), $ref ) ), 200, $return );
	}

	private static function normalise_files(): array {
		$f = $_FILES['certificates'] ?? null; // phpcs:ignore
		if ( ! $f || ! is_array( $f['name'] ?? null ) ) {
			return array();
		}
		$out = array();
		foreach ( $f['name'] as $i => $name ) {
			if ( '' === (string) $name && UPLOAD_ERR_NO_FILE === (int) $f['error'][ $i ] ) {
				continue;
			}
			$out[] = array( 'name' => (string) $name, 'type' => (string) $f['type'][ $i ], 'tmp_name' => (string) $f['tmp_name'][ $i ], 'error' => (int) $f['error'][ $i ], 'size' => (int) $f['size'][ $i ] );
		}
		return $out;
	}

	/** WordPress media handling (wp_handle_upload → Security::upload_check) into the private intake directory. */
	private static function store_upload( array $file ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$dir    = Portal::private_dir( 'intake/' . gmdate( 'Y' ) );
		$filter = static function ( $u ) use ( $dir ) {
			$u['path']   = $dir;
			$u['url']    = '';
			$u['subdir'] = '';
			return $u;
		};
		add_filter( 'upload_dir', $filter );
		$res = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => array( 'pdf' => 'application/pdf', 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ),
				'unique_filename_callback' => static fn( $d, $name, $ext ) => wp_generate_password( 20, false ) . '-' . sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) ) . $ext,
			)
		);
		remove_filter( 'upload_dir', $filter );
		if ( isset( $res['error'] ) ) {
			return array( 'error' => (string) $res['error'] );
		}
		return array(
			'name'   => sanitize_file_name( $file['name'] ),
			'path'   => self::rel( $res['file'] ),
			'size'   => (int) filesize( $res['file'] ),
			'mime'   => (string) $res['type'],
			'sha256' => hash_file( 'sha256', $res['file'] ),
			'at'     => gmdate( 'c' ),
		);
	}

	private static function rel( string $abs ): string {
		$base = wp_normalize_path( trailingslashit( wp_upload_dir( null, false )['basedir'] ) );
		return ltrim( str_replace( $base, '', wp_normalize_path( $abs ) ), '/' );
	}

	private static function abs( string $rel ): string {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . ltrim( $rel, '/' );
	}

	private static function mail_submitter( int $id, string $state ): void {
		$d   = self::arr( $id, 'data' );
		$ref = (string) self::m( $id, 'ref' );
		if ( empty( $d['email'] ) ) {
			return;
		}
		$label = self::states()[ $state ] ?? $state;
		$body  = array(
			'received'                => 'Thank you. We have received your asset submission and will review it. You will receive an email when its status changes.',
			'initial_review'          => 'Your submission is now in initial review.',
			'due_diligence'           => 'Your submission has moved to due diligence. We may contact you for further documents (identity of material, ownership, location, counterparty information).',
			'accepted_for_onboarding' => 'Your submission has been accepted for onboarding. This is not a valuation, an offer or a commitment; onboarding remains subject to independent verification, legal documentation and final approval. We will contact you with next steps.',
			'declined'                => 'After review, we will not proceed with this submission at this time. Thank you for your interest in ReserveChain.',
		);
		$msg = sprintf( "Reference: %s\nStatus: %s\n\n%s\n\nReserveChain is in development. No tokens are offered or sold. Never send funds, private keys or seed phrases to anyone claiming to represent ReserveChain.\n", $ref, $label, $body[ $state ] ?? '' );
		wp_mail( $d['email'], sprintf( '[ReserveChain] Asset submission %s: %s', $ref, $label ), $msg );
	}

	/* ------------------------------------------------------------ pipeline actions (admin-post) */

	public static function action(): void {
		$id = absint( $_POST['post_id'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'rc_intake_action_' . $id, '_rc_intake_nonce' );
		if ( ! current_user_can( self::CAP ) || self::TYPE !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$do      = sanitize_key( $_POST['rc_do'] ?? '' ); // phpcs:ignore
		$comment = sanitize_textarea_field( wp_unslash( $_POST['rc_comment'] ?? '' ) ); // phpcs:ignore
		$msg     = self::apply( $id, $do, $comment, absint( $_POST['rc_assignee'] ?? 0 ) ); // phpcs:ignore
		set_transient( 'rc_intake_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
		exit;
	}

	/**
	 * Apply a pipeline action as the current user. Returns a human-readable result (prefixed "ERR:" on refusal).
	 */
	public static function apply( int $id, string $do, string $comment = '', int $assignee = 0 ): string {
		$uid   = get_current_user_id();
		$state = self::state( $id );
		$ref   = (string) self::m( $id, 'ref' );
		$final = in_array( $state, array( 'accepted_for_onboarding', 'declined' ), true );
		if ( ! Settings::module_on( self::MODULE ) && 'note' !== $do ) {
			return 'ERR: The asset-owner portal module is not active. Pipeline actions are disabled until it is authorized.';
		}
		switch ( $do ) {
			case 'note':
				if ( '' === trim( $comment ) ) {
					return 'ERR: Enter a note.';
				}
				$notes   = self::arr( $id, 'notes' );
				$notes[] = array( 'by' => $uid, 'at' => gmdate( 'c' ), 'text' => $comment );
				update_post_meta( $id, '_rc_intake_notes', $notes );
				Audit_Log::record( 'intake.note', self::TYPE, $id, 'Internal note added to ' . $ref, array( 'length' => mb_strlen( $comment ) ) );
				return 'Note added.';

			case 'assign':
				if ( $assignee && ! user_can( $assignee, self::CAP ) ) {
					return 'ERR: The selected user cannot manage asset intake.';
				}
				update_post_meta( $id, '_rc_intake_assignee', $assignee );
				Audit_Log::record( 'intake.assigned', self::TYPE, $id, $ref . ' assigned', array( 'assignee' => $assignee ) );
				return $assignee ? 'Assignee updated.' : 'Assignee cleared.';

			case 'advance':
				$next = array( 'received' => 'initial_review', 'initial_review' => 'due_diligence' )[ $state ] ?? null;
				if ( ! $next ) {
					return 'ERR: This submission cannot be advanced from its current state.';
				}
				self::transition( $id, $state, $next, $comment );
				return 'Moved to ' . self::states()[ $next ] . '.';

			case 'propose_accept':
			case 'propose_decline':
				$decision = 'propose_accept' === $do ? 'accepted_for_onboarding' : 'declined';
				if ( $final || ( 'accepted_for_onboarding' === $decision && 'due_diligence' !== $state ) ) {
					return 'ERR: Acceptance can only be proposed from due diligence; no decision can be proposed on a closed submission.';
				}
				if ( self::m( $id, 'pending' ) ) {
					return 'ERR: A decision is already pending confirmation.';
				}
				$fp = self::fingerprint( $id, $decision );
				update_post_meta( $id, '_rc_intake_pending', array( 'decision' => $decision, 'by' => $uid, 'at' => gmdate( 'c' ), 'fingerprint' => $fp, 'comment' => $comment ) );
				Audit_Log::record( 'intake.decision_proposed', self::TYPE, $id, sprintf( '%s: %s proposed (awaiting second approver)', $ref, self::states()[ $decision ] ), array( 'fingerprint' => $fp ) );
				return 'Decision proposed. A different user with approval rights must confirm it.';

			case 'confirm':
				$pending = self::m( $id, 'pending' );
				if ( ! is_array( $pending ) ) {
					return 'ERR: There is no pending decision.';
				}
				if ( ! current_user_can( 'rc_approve' ) ) {
					return 'ERR: Confirming a decision requires approval rights.';
				}
				$submitter = (int) ( ( self::arr( $id, 'data' ) )['submitted_by'] ?? 0 );
				if ( (int) $pending['by'] === $uid || ( $submitter && $submitter === $uid ) ) {
					Audit_Log::record( 'intake.four_eyes_blocked', self::TYPE, $id, $ref . ': self-confirmation refused (four-eyes)' );
					return 'ERR: Four-eyes rule: the decision must be confirmed by a different user than the one who proposed it (or submitted the asset).';
				}
				if ( ! hash_equals( (string) $pending['fingerprint'], self::fingerprint( $id, (string) $pending['decision'] ) ) ) {
					delete_post_meta( $id, '_rc_intake_pending' );
					Audit_Log::record( 'intake.decision_invalidated', self::TYPE, $id, $ref . ': submission changed after the decision was proposed; proposal withdrawn' );
					return 'ERR: The submission changed after the decision was proposed. The proposal was withdrawn; propose it again.';
				}
				delete_post_meta( $id, '_rc_intake_pending' );
				self::transition( $id, $state, (string) $pending['decision'], trim( $pending['comment'] . "\n" . $comment ), array( 'proposed_by' => (int) $pending['by'], 'fingerprint' => $pending['fingerprint'] ) );
				return 'Decision confirmed: ' . self::states()[ $pending['decision'] ] . '.';

			case 'cancel':
				if ( ! self::m( $id, 'pending' ) ) {
					return 'ERR: There is no pending decision.';
				}
				delete_post_meta( $id, '_rc_intake_pending' );
				Audit_Log::record( 'intake.decision_withdrawn', self::TYPE, $id, $ref . ': pending decision withdrawn' );
				return 'Pending decision withdrawn.';
		}
		return 'ERR: Unknown action.';
	}

	private static function transition( int $id, string $from, string $to, string $comment = '', array $extra = array() ): void {
		update_post_meta( $id, '_rc_intake_state', $to );
		$hist   = self::arr( $id, 'history' );
		$hist[] = array( 'from' => $from, 'to' => $to, 'by' => get_current_user_id(), 'at' => gmdate( 'c' ) );
		update_post_meta( $id, '_rc_intake_history', $hist );
		if ( '' !== trim( $comment ) ) {
			$notes   = self::arr( $id, 'notes' );
			$notes[] = array( 'by' => get_current_user_id(), 'at' => gmdate( 'c' ), 'text' => '[' . self::states()[ $to ] . '] ' . $comment );
			update_post_meta( $id, '_rc_intake_notes', $notes );
		}
		$ref = (string) self::m( $id, 'ref' );
		Audit_Log::record( 'intake.status', self::TYPE, $id, sprintf( '%s: %s → %s', $ref, self::states()[ $from ] ?? $from, self::states()[ $to ] ?? $to ), $extra );
		self::mail_submitter( $id, $to );
		$submitter = (int) ( ( self::arr( $id, 'data' ) )['submitted_by'] ?? 0 );
		if ( $submitter ) {
			Notifications::push( $submitter, 'Asset submission status updated', sprintf( '%s: %s.', $ref, self::states()[ $to ] ), 'intake' );
		}
	}

	/** Stream a stored certificate to staff. */
	public static function file(): void {
		$id = absint( $_GET['post_id'] ?? 0 ); // phpcs:ignore
		$i  = absint( $_GET['i'] ?? 0 ); // phpcs:ignore
		check_admin_referer( 'rc_intake_file_' . $id . '_' . $i );
		if ( ! current_user_can( self::CAP ) || self::TYPE !== get_post_type( $id ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$files = self::arr( $id, 'files' );
		$f     = $files[ $i ] ?? null;
		$path  = $f ? self::abs( $f['path'] ) : '';
		if ( ! $f || ! Portal::is_private_path( $path ) || ! is_readable( $path ) ) {
			wp_die( 'File not found.', 404 );
		}
		Audit_Log::record( 'intake.file_viewed', self::TYPE, $id, 'Intake certificate downloaded by staff', array( 'sha256' => $f['sha256'] ) );
		Portal::stream( $path, (string) $f['mime'], (string) $f['name'] );
	}

	/* ------------------------------------------------------------ admin UI */

	public static function meta_boxes(): void {
		remove_meta_box( 'submitdiv', self::TYPE, 'side' );
		remove_meta_box( 'slugdiv', self::TYPE, 'normal' );
		add_meta_box( 'rc_intake_sub', 'Submission', array( __CLASS__, 'box_submission' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'rc_intake_notes', 'Internal notes', array( __CLASS__, 'box_notes' ), self::TYPE, 'normal', 'default' );
		add_meta_box( 'rc_intake_audit', 'Audit log', array( __CLASS__, 'box_audit' ), self::TYPE, 'normal', 'low' );
		add_meta_box( 'rc_intake_pipe', 'Pipeline', array( __CLASS__, 'box_pipeline' ), self::TYPE, 'side', 'high' );
		add_action( 'admin_footer', array( __CLASS__, 'action_form' ) );
	}

	public static function action_form(): void {
		global $post;
		if ( ! $post || self::TYPE !== $post->post_type ) {
			return;
		}
		echo '<form id="rc-intake-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rc_intake_action"><input type="hidden" name="post_id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'rc_intake_action_' . $post->ID, '_rc_intake_nonce' );
		echo '</form>';
	}

	private static function btn( string $do, string $label, string $cls = 'button' ): string {
		return '<button type="submit" form="rc-intake-form" name="rc_do" value="' . esc_attr( $do ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</button> ';
	}

	public static function box_submission( \WP_Post $post ): void {
		$d     = self::arr( $post->ID, 'data' );
		$rows  = array(
			'Reference'          => '<code>' . esc_html( (string) self::m( $post->ID, 'ref' ) ) . '</code>',
			'Organisation'       => esc_html( $d['organisation'] ?? '' ),
			'Contact'            => esc_html( ( $d['contact_name'] ?? '' ) . ' <' . ( $d['email'] ?? '' ) . '>' ) . ( ! empty( $d['phone'] ) ? ' · ' . esc_html( $d['phone'] ) : '' ),
			'Role'               => esc_html( self::roles()[ $d['role'] ?? '' ] ?? '' ),
			'Material'           => esc_html( ( self::materials()[ $d['material'] ?? '' ] ?? '' ) . ( ! empty( $d['material_other'] ) ? ' — ' . $d['material_other'] : '' ) ),
			'Declared quantity'  => esc_html( ( isset( $d['quantity'] ) ? rtrim( rtrim( number_format( (float) $d['quantity'], 4, '.', ',' ), '0' ), '.' ) : '' ) . ' ' . ( $d['unit'] ?? '' ) ) . ' <em>(owner-declared, unverified)</em>',
			'Location'           => esc_html( ( Schema::countries()[ $d['country'] ?? '' ] ?? '' ) . ' (' . ( $d['country'] ?? '' ) . ')' ),
			'Description'        => nl2br( esc_html( $d['description'] ?? '' ) ),
			'Consents'           => esc_html( ! empty( $d['consents'] ) ? 'Accuracy & authority, no commitment, privacy — ' . ( $d['consents']['at'] ?? '' ) : '—' ),
			'Submitted'          => esc_html( get_post_time( 'Y-m-d H:i', true, $post ) . ' UTC' ) . ( ! empty( $d['submitted_by'] ) ? ' · user #' . (int) $d['submitted_by'] : ' · not signed in' ),
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $k => $v ) {
			echo '<tr><th style="width:180px">' . esc_html( $k ) . '</th><td>' . $v . '</td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table><h4>Certificates (stored privately)</h4>';
		$files = self::arr( $post->ID, 'files' );
		if ( ! $files ) {
			echo '<p><em>No certificates were attached.</em></p>';
			return;
		}
		echo '<table class="widefat"><thead><tr><th>File</th><th>Size</th><th>SHA-256</th><th></th></tr></thead><tbody>';
		foreach ( $files as $i => $f ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=rc_intake_file&post_id=' . $post->ID . '&i=' . $i ), 'rc_intake_file_' . $post->ID . '_' . $i );
			printf( '<tr><td>%s<br><small>%s</small></td><td>%s</td><td><code style="word-break:break-all">%s</code></td><td><a class="button" href="%s">Download</a></td></tr>', esc_html( $f['name'] ), esc_html( $f['mime'] ), esc_html( size_format( (int) $f['size'] ) ), esc_html( $f['sha256'] ), esc_url( $url ) );
		}
		echo '</tbody></table>';
	}

	public static function box_pipeline( \WP_Post $post ): void {
		$state   = self::state( $post->ID );
		$pending = self::m( $post->ID, 'pending' );
		$on      = Settings::module_on( self::MODULE );
		echo '<p><strong>State</strong><br>' . self::pill( $state ) . '</p>'; // phpcs:ignore
		if ( ! $on ) {
			echo '<p class="description" style="color:#b32d2e">Module “Asset-owner / originator portal” is not active: pipeline actions are disabled (notes remain possible).</p>';
		}
		echo '<ol style="margin-left:18px">';
		foreach ( self::arr( $post->ID, 'history' ) as $h ) {
			$u = $h['by'] ? get_userdata( (int) $h['by'] ) : null;
			printf( '<li>%s <small>· %s · %s</small></li>', esc_html( self::states()[ $h['to'] ] ?? $h['to'] ), esc_html( $u ? $u->user_login : 'submitter' ), esc_html( substr( (string) $h['at'], 0, 16 ) ) );
		}
		echo '</ol>';

		$users = get_users( array( 'capability' => self::CAP, 'fields' => array( 'ID', 'user_login' ) ) );
		$cur   = (int) self::m( $post->ID, 'assignee' );
		echo '<p><label for="rc_assignee"><strong>Assignee</strong></label><br><select id="rc_assignee" name="rc_assignee" form="rc-intake-form"><option value="0">— unassigned —</option>';
		foreach ( $users as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $cur, (int) $u->ID, false ), esc_html( $u->user_login ) );
		}
		echo '</select> ' . self::btn( 'assign', 'Assign' ) . '</p>'; // phpcs:ignore

		echo '<p><label for="rc_comment"><strong>Comment</strong> <small>(internal; stored as a note)</small></label><textarea id="rc_comment" name="rc_comment" rows="3" class="widefat" form="rc-intake-form"></textarea></p><p>';
		if ( is_array( $pending ) ) {
			$by = get_userdata( (int) $pending['by'] );
			echo '<div class="notice notice-warning inline"><p><strong>Pending four-eyes confirmation:</strong> ' . esc_html( self::states()[ $pending['decision'] ] ?? '' ) . '<br><small>Proposed by ' . esc_html( $by ? $by->user_login : '#' . $pending['by'] ) . ' · ' . esc_html( substr( (string) $pending['at'], 0, 16 ) ) . '<br>Fingerprint <code>' . esc_html( substr( (string) $pending['fingerprint'], 0, 16 ) ) . '…</code></small></p></div><p>';
			if ( (int) $pending['by'] !== get_current_user_id() && current_user_can( 'rc_approve' ) ) {
				echo self::btn( 'confirm', 'Confirm decision', 'button button-primary' ); // phpcs:ignore
			} else {
				echo '<small>A different user with approval rights must confirm.</small><br>';
			}
			echo self::btn( 'cancel', 'Withdraw proposal' ); // phpcs:ignore
		} elseif ( ! in_array( $state, array( 'accepted_for_onboarding', 'declined' ), true ) ) {
			if ( 'received' === $state ) {
				echo self::btn( 'advance', 'Start initial review', 'button button-primary' ); // phpcs:ignore
			} elseif ( 'initial_review' === $state ) {
				echo self::btn( 'advance', 'Move to due diligence', 'button button-primary' ); // phpcs:ignore
			} else {
				echo self::btn( 'propose_accept', 'Propose: accept for onboarding', 'button button-primary' ); // phpcs:ignore
			}
			echo self::btn( 'propose_decline', 'Propose: decline' ); // phpcs:ignore
		} else {
			echo '<em>Closed.</em>';
		}
		echo '</p><p class="description">Final decisions require a second approver (four-eyes). The submitter is emailed on every status change.</p>';
	}

	public static function box_notes( \WP_Post $post ): void {
		$notes = array_reverse( self::arr( $post->ID, 'notes' ) );
		if ( $notes ) {
			echo '<ul>';
			foreach ( $notes as $n ) {
				$u = get_userdata( (int) $n['by'] );
				printf( '<li style="border-bottom:1px solid #eee;padding:6px 0"><small>%s · %s</small><br>%s</li>', esc_html( $u ? $u->user_login : '#' . $n['by'] ), esc_html( substr( (string) $n['at'], 0, 16 ) ), nl2br( esc_html( (string) $n['text'] ) ) );
			}
			echo '</ul>';
		} else {
			echo '<p><em>No notes yet.</em></p>';
		}
		echo '<p class="description">Notes are internal and never sent to the submitter. Use the comment field in the Pipeline box, then:</p>' . self::btn( 'note', 'Add note' ); // phpcs:ignore
	}

	public static function box_audit( \WP_Post $post ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, actor_login, action, summary FROM ' . Audit_Log::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 100', self::TYPE, $post->ID ) ); // phpcs:ignore
		if ( ! $rows ) {
			echo '<p><em>No entries.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Summary</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>', esc_html( substr( $r->created_at, 0, 19 ) ), esc_html( $r->actor_login ?: 'system' ), esc_html( $r->action ), esc_html( $r->summary ) );
		}
		echo '</tbody></table>';
	}

	public static function columns( array $cols ): array {
		return array(
			'cb'          => $cols['cb'] ?? '',
			'rc_ref'      => 'Reference',
			'title'       => 'Organisation / material',
			'rc_country'  => 'Location',
			'rc_state'    => 'State',
			'rc_assignee' => 'Assignee',
			'date'        => 'Received',
		);
	}

	public static function column( string $col, int $id ): void {
		$d = self::arr( $id, 'data' );
		switch ( $col ) {
			case 'rc_ref':
				echo '<code>' . esc_html( (string) self::m( $id, 'ref' ) ) . '</code>';
				break;
			case 'rc_country':
				echo esc_html( $d['country'] ?? '' );
				break;
			case 'rc_state':
				echo self::pill( self::state( $id ) ) . ( self::m( $id, 'pending' ) ? ' <small>(decision pending)</small>' : '' ); // phpcs:ignore
				break;
			case 'rc_assignee':
				$u = get_userdata( (int) self::m( $id, 'assignee' ) );
				echo esc_html( $u ? $u->user_login : '—' );
				break;
		}
	}

	public static function notices(): void {
		$msg = get_transient( 'rc_intake_notice_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'rc_intake_notice_' . get_current_user_id() );
		$err = 0 === strpos( $msg, 'ERR:' );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $err ? 'error' : 'success', esc_html( $err ? trim( substr( $msg, 4 ) ) : $msg ) );
	}
}
