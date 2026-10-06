<?php
/**
 * Front-end components as shortcodes, so every page stays editable in the CMS while dynamic
 * modules (registry, passports, verification, waitlist) are rendered from live data.
 *
 * [rc_waitlist] [rc_contact] [rc_verify] [rc_passports program=""] [rc_documents type=""]
 * [rc_registry_stats] [rc_audit_status] [rc_token_params program=""] [rc_disclosure] [rc_status s=""]
 * [rc_program_facts program=""] [rc_module module="" title=""]
 *
 * @package ReserveChain
 */

namespace RC;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public static function init(): void {
		$map = array(
			'rc_waitlist'       => 'waitlist',
			'rc_contact'        => 'contact',
			'rc_verify'         => 'verify',
			'rc_passports'      => 'passports',
			'rc_documents'      => 'documents',
			'rc_registry_stats' => 'registry_stats',
			'rc_audit_status'   => 'audit_status',
			'rc_token_params'   => 'token_params',
			'rc_disclosure'     => 'disclosure',
			'rc_status'         => 'status',
			'rc_program_facts'  => 'program_facts',
			'rc_module'         => 'module',
			'rc_provisional'    => 'provisional',
			'rc_registry_table' => 'registry_table',
			'rc_coa'            => 'coa',
			'rc_por'            => 'por',
		);
		foreach ( $map as $tag => $method ) {
			add_shortcode( $tag, array( __CLASS__, $method ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets(): void {
		wp_register_script( 'rc-qrcode', RC_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		wp_register_script( 'rc-public', RC_URL . 'assets/public.js', array( 'rc-qrcode' ), RC_VERSION, true );
		wp_localize_script(
			'rc-public',
			'RC',
			array(
				'api'  => esc_url_raw( rest_url( Rest::NS ) ),
				'lang' => I18n::lang(),
				'eu'   => Schema::eu_eea_countries(),
				'restricted' => array_values( (array) Settings::get( 'restricted_countries', array() ) ),
				'restrictEu' => (bool) Settings::get( 'restrict_eu_eea' ),
				'i18n' => array(
					'hashing'    => __( 'Computing SHA-256 fingerprint locally…', 'reservechain' ),
					'checking'   => __( 'Checking the registry…', 'reservechain' ),
					'match'      => __( 'Match found in the ReserveChain registry', 'reservechain' ),
					'nomatch'    => __( 'No match. This exact file is not registered. Even a one-byte change produces a different fingerprint.', 'reservechain' ),
					'withdrawn'  => __( 'This document was registered but has since been withdrawn or archived.', 'reservechain' ),
					'error'      => __( 'Something went wrong. Please try again.', 'reservechain' ),
					'sending'    => __( 'Submitting…', 'reservechain' ),
					'copied'     => __( 'Copied', 'reservechain' ),
					'restricted' => __( 'Residents of your selected country are not eligible for any future token offering. You may still opt in to general project updates.', 'reservechain' ),
					'viewPassport' => __( 'View passport', 'reservechain' ),
					'download'   => __( 'Download', 'reservechain' ),
					'issuedBy'   => __( 'Issued by', 'reservechain' ),
				),
			)
		);
	}

	public static function enqueue(): void {
		wp_enqueue_script( 'rc-public' );
	}

	public static function pill( string $status, ?string $label = null ): string {
		$labels = Schema::claim_statuses() + array(
			'pending'    => __( 'Pending', 'reservechain' ),
			'eligible'   => __( 'Eligible', 'reservechain' ),
			'restricted' => __( 'Restricted', 'reservechain' ),
			'provided'   => __( 'Provided', 'reservechain' ),
		);
		return sprintf( '<span class="rc-pill rc-pill--%1$s"><i aria-hidden="true"></i>%2$s</span>', esc_attr( $status ), esc_html( $label ?? ( $labels[ $status ] ?? $status ) ) );
	}

	public static function status( $atts ): string {
		$a = shortcode_atts( array( 's' => 'proposed', 'label' => null ), $atts );
		return self::pill( sanitize_key( $a['s'] ), $a['label'] );
	}

	public static function disclosure(): string {
		return '<aside class="rc-disclosure" role="note"><strong>' . esc_html__( 'Important notice', 'reservechain' ) . '</strong><p>' . esc_html( I18n::t( Settings::get( 'disclosure' ) ) ) . '</p><p>' . esc_html( I18n::t( Settings::get( 'eu_notice' ) ) ) . '</p></aside>';
	}

	private static function module_off( string $module, string $title ): ?string {
		if ( Settings::module_on( $module ) ) {
			return null;
		}
		return '<div class="rc-locked"><span class="rc-locked__icon" aria-hidden="true"></span><div><strong>' . esc_html( $title ) . '</strong><p>' . esc_html__( 'This module is built but not active. It will only be enabled after written authorization and final approval.', 'reservechain' ) . '</p></div></div>';
	}

	public static function module( $atts ): string {
		$a = shortcode_atts( array( 'module' => '', 'title' => '' ), $atts );
		return self::module_off( sanitize_key( $a['module'] ), $a['title'] ?: ( Settings::GATED_MODULES[ $a['module'] ] ?? $a['module'] ) ) ?? '';
	}

	/* ------------------------------------------------------------ live registry components */

	public static function provisional(): string {
		return '<div class="rc-provisional" role="note"><strong>' . esc_html__( 'Provisional Asset Notice', 'reservechain' ) . '</strong>' . esc_html( I18n::t( (string) Settings::get( 'provisional_notice' ) ) ) . '</div>';
	}

	private static function program_id( string $slug ): int {
		$p = $slug ? get_page_by_path( sanitize_title( $slug ), OBJECT, 'rc_program' ) : null;
		return $p && 'publish' === $p->post_status ? (int) $p->ID : 0;
	}

	private static function select_label( string $type, string $key, string $value ): string {
		$f = Schema::field( $type, $key );
		return $f && isset( $f['options'][ $value ] ) ? __( $f['options'][ $value ], 'reservechain' ) : ( $value ?: '—' ); // phpcs:ignore
	}

	/** Industrial Metals Registry table with unit-level statuses. [rc_registry_table program="" type="" limit=""] */
	public static function registry_table( $atts ): string {
		$a = shortcode_atts( array( 'program' => '', 'type' => '', 'limit' => 100 ), $atts );
		if ( ! Settings::module_on( 'registry_public' ) ) {
			return self::module_off( 'registry_public', __( 'Industrial Metals Registry', 'reservechain' ) ) ?? '';
		}
		$q = array( 'post_type' => $a['type'] && Schema::is_passport_type( $a['type'] ) ? $a['type'] : Schema::PASSPORT_TYPES, 'post_status' => 'publish', 'posts_per_page' => (int) $a['limit'], 'orderby' => array( 'type' => 'ASC', 'title' => 'ASC' ) );
		if ( $a['program'] ) {
			$pid = self::program_id( $a['program'] );
			if ( ! $pid ) {
				return '';
			}
			$q['meta_query'] = array( array( 'key' => '_rc_program', 'value' => $pid ) ); // phpcs:ignore
		}
		$rows = get_posts( $q );
		if ( ! $rows ) {
			return '<p class="rc-empty">' . esc_html__( 'No registry records have been published yet.', 'reservechain' ) . '</p>';
		}
		$out = '<div class="rc-table-wrap"><table class="rc-table rc-registry"><thead><tr><th>' . esc_html__( 'Record', 'reservechain' ) . '</th><th>' . esc_html__( 'Unit', 'reservechain' ) . '</th><th>' . esc_html__( 'Verification', 'reservechain' ) . '</th><th>' . esc_html__( 'Custody', 'reservechain' ) . '</th><th>' . esc_html__( 'Reserve', 'reservechain' ) . '</th><th>' . esc_html__( 'Tokenization', 'reservechain' ) . '</th><th>' . esc_html__( 'Redemption', 'reservechain' ) . '</th><th>' . esc_html__( 'Availability', 'reservechain' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$m   = static fn( $k ) => (string) get_post_meta( $r->ID, '_rc_' . $k, true );
			$no  = $m( 'record_no' );
			$out .= sprintf(
				'<tr><td><a class="rc-mono" href="%1$s">%2$s</a></td><td>%3$s<br><small>%4$s</small></td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td><td>%9$s</td><td>%10$s</td></tr>',
				esc_url( home_url( '/passport/' . rawurlencode( $no ) . '/' ) ),
				esc_html( $no ),
				esc_html( $r->post_title ),
				esc_html( __( Schema::entity( $r->post_type )['singular'], 'reservechain' ) ), // phpcs:ignore
				self::pill( $m( 'verification_status' ) ?: 'in_development' ),
				esc_html( self::select_label( $r->post_type, 'custody_status', $m( 'custody_status' ) ?: 'pending' ) ),
				esc_html( self::select_label( $r->post_type, 'reserve_status', $m( 'reserve_status' ) ?: 'pending' ) ),
				esc_html( self::select_label( $r->post_type, 'tokenization_status', $m( 'tokenization_status' ) ?: 'not_issued' ) ),
				esc_html( self::select_label( $r->post_type, 'redemption_status', $m( 'redemption_status' ) ?: 'not_available' ) ),
				esc_html( self::select_label( $r->post_type, 'availability_status', $m( 'availability_status' ) ?: 'not_offered' ) )
			);
		}
		return $out . '</tbody></table></div><p class="rc-fine">' . esc_html( sprintf( _n( '%d record', '%d records', count( $rows ), 'reservechain' ), count( $rows ) ) ) . ' · ' . esc_html__( 'Statuses are set through the four-eyes workflow and change only when supporting evidence is approved.', 'reservechain' ) . '</p>';
	}

	/** Certificate of Analysis evidence panel for a program. [rc_coa program="copper-powder"] */
	public static function coa( $atts ): string {
		$a   = shortcode_atts( array( 'program' => '' ), $atts );
		$pid = self::program_id( $a['program'] );
		if ( ! $pid ) {
			return '';
		}
		$lots = get_posts( array( 'post_type' => 'rc_lot', 'post_status' => 'publish', 'posts_per_page' => 20, 'meta_key' => '_rc_program', 'meta_value' => $pid, 'fields' => 'ids' ) ); // phpcs:ignore
		$coas = array();
		foreach ( $lots as $lot ) {
			$coas = array_merge( $coas, Registry::referencing( $lot, array( 'rc_coa' ) ) );
		}
		if ( ! $coas ) {
			return '<p class="rc-empty">' . esc_html__( 'No Certificate of Analysis has been published for this program yet.', 'reservechain' ) . '</p>';
		}
		$out = '';
		foreach ( $coas as $coa ) {
			$fields = Passport::public_fields( $coa->ID );
			$doc    = Passport::document( (int) get_post_meta( $coa->ID, '_rc_document', true ) );
			$assay  = array_values( array_filter( $fields, static fn( $f ) => 'assay_results' === $f['key'] ) );
			$facts  = array_values( array_filter( $fields, static fn( $f ) => ! in_array( $f['key'], array( 'assay_results', 'subject', 'document', 'provenance' ), true ) && 'provided' === $f['state'] ) );
			$prov   = (string) get_post_meta( $coa->ID, '_rc_provenance', true );
			$out   .= '<div class="rc-coa">';
			$out   .= '<div class="rc-coa__head"><div><p class="rc-kicker">' . esc_html__( 'Certificate of Analysis', 'reservechain' ) . '</p><h3>' . esc_html( $coa->post_title ) . '</h3><p class="rc-coa__badges">' . self::pill( (string) get_post_meta( $coa->ID, '_rc_verification_status', true ) ) . ( $prov ? ' <span class="rc-tag">' . esc_html( self::select_label( 'rc_coa', 'provenance', $prov ) ) . '</span>' : '' ) . '</p></div>';
			if ( $doc && $doc['url'] ) {
				$out .= '<a class="rc-coa__thumb" href="' . esc_url( $doc['url'] ) . '" target="_blank" rel="noopener">' . ( 0 === strpos( (string) $doc['mime'], 'image/' ) ? '<img src="' . esc_url( $doc['url'] ) . '" alt="' . esc_attr( $doc['title'] ) . '" loading="lazy" width="180" height="250">' : '<span>PDF</span>' ) . '<small>' . esc_html__( 'View original scan', 'reservechain' ) . '</small></a>';
			}
			$out .= '</div>' . self::field_table( $facts ) . ( $assay ? self::field_table( $assay ) : '' );
			if ( $doc ) {
				$out .= '<p class="rc-doc__hash"><span>SHA-256</span><code class="rc-mono" data-copy>' . esc_html( (string) $doc['sha256'] ) . '</code> <a class="rc-btn rc-btn--sm" href="' . esc_url( add_query_arg( 'hash', $doc['sha256'], home_url( '/platform/verification/' ) ) ) . '">' . esc_html__( 'Verify fingerprint', 'reservechain' ) . '</a></p>';
			}
			$out .= '<p class="rc-fine">' . esc_html__( 'Transcribed exactly as printed on the owner-supplied certificate (comma decimals). Owner-supplied evidence is not an independent verification by ReserveChain; publication and any "verified" status are subject to documentary verification and approval.', 'reservechain' ) . '</p></div>';
		}
		return $out;
	}

	/**
	 * Proof-of-Reserves dashboard computed live from the registry. Declared (owner-supplied) and verified
	 * quantities are never mixed; coverage is only computed from an attested reserve report. [rc_por program=""]
	 */
	public static function por( $atts ): string {
		$a     = shortcode_atts( array( 'program' => '' ), $atts );
		$progs = $a['program'] ? array_filter( array( self::program_id( $a['program'] ) ) ) : get_posts( array( 'post_type' => 'rc_program', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$out   = '<div class="rc-por">';
		foreach ( $progs as $pid ) {
			$units    = get_posts( array( 'post_type' => Schema::PASSPORT_TYPES, 'post_status' => 'publish', 'posts_per_page' => 500, 'meta_key' => '_rc_program', 'meta_value' => $pid ) ); // phpcs:ignore
			$lots     = array_filter( $units, static fn( $u ) => 'rc_lot' === $u->post_type );
			$declared = 0.0;
			foreach ( $lots as $l ) {
				$declared += (float) get_post_meta( $l->ID, '_rc_net_weight', true );
			}
			$verified = 0;
			foreach ( $units as $u ) {
				$verified += 'verified' === get_post_meta( $u->ID, '_rc_verification_status', true ) ? 1 : 0;
			}
			$reports  = get_posts( array( 'post_type' => 'rc_reserve_report', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_rc_program', 'meta_value' => $pid ) ); // phpcs:ignore
			$attested = $reports && 'verified' === get_post_meta( $reports[0]->ID, '_rc_verification_status', true );
			$sym      = (string) get_post_meta( $pid, '_rc_symbol', true );
			$cells    = array(
				array( __( 'Registered physical units', 'reservechain' ), (string) count( $units ), __( 'lots, containers, coils', 'reservechain' ) ),
				array( __( 'Declared net weight', 'reservechain' ), $declared ? rtrim( rtrim( number_format( $declared, 3, '.', ',' ), '0' ), '.' ) . ' kg' : '—', __( 'owner-declared, unverified', 'reservechain' ) ),
				array( __( 'Independently verified units', 'reservechain' ), (string) $verified, __( 'requires verified evidence', 'reservechain' ) ),
				array( __( 'Attested reserve', 'reservechain' ), $attested ? (string) get_post_meta( $reports[0]->ID, '_rc_reserve_units', true ) : __( 'None', 'reservechain' ), __( 'no attestation published', 'reservechain' ) ),
				array( __( 'Tokens issued', 'reservechain' ), '0', __( 'no tokens exist', 'reservechain' ) ),
				array( __( 'Reserve coverage', 'reservechain' ), __( 'Not computed', 'reservechain' ), __( 'computed only from an attested report', 'reservechain' ) ),
			);
			$out .= '<section class="rc-por__prog rc-por__prog--' . esc_attr( strtolower( $sym ) ) . '"><header><span class="rc-por__el">' . esc_html( $sym ) . '</span><h3>' . esc_html( get_the_title( $pid ) ) . '</h3>' . self::pill( 'in_development', __( 'Live from registry · no attestation', 'reservechain' ) ) . '</header><dl class="rc-por__grid">';
			foreach ( $cells as $c ) {
				$out .= '<div><dt>' . esc_html( $c[0] ) . '</dt><dd>' . esc_html( $c[1] ) . '</dd><small>' . esc_html( $c[2] ) . '</small></div>';
			}
			$out .= '</dl></section>';
		}
		return $out . '<p class="rc-fine">' . esc_html__( 'Figures are computed in real time from approved registry records. Declared quantities come from owner-supplied documents and are not verified reserves. Reserve coverage will only be calculated once an independent attestation is published.', 'reservechain' ) . '</p></div>';
	}

	/* ------------------------------------------------------------ waitlist */

	public static function waitlist(): string {
		self::enqueue();
		$off = self::module_off( 'waitlist', __( 'Waitlist', 'reservechain' ) );
		if ( $off ) {
			return $off;
		}
		$confirmed = $GLOBALS['rc_waitlist_confirmed'] ?? null;
		$banner    = '';
		if ( true === $confirmed ) {
			$banner = '<div class="rc-alert rc-alert--ok" role="status">' . esc_html__( 'Your email address is confirmed. Thank you for registering your interest.', 'reservechain' ) . '</div>';
		} elseif ( isset( $GLOBALS['rc_waitlist_unsubscribed'] ) ) {
			$banner = '<div class="rc-alert ' . ( $GLOBALS['rc_waitlist_unsubscribed'] ? 'rc-alert--ok' : 'rc-alert--warn' ) . '" role="status">' . esc_html( $GLOBALS['rc_waitlist_unsubscribed'] ? __( 'You have been unsubscribed. You will not receive further updates.', 'reservechain' ) : __( 'This unsubscribe link is invalid.', 'reservechain' ) ) . '</div>';
		} elseif ( false === $confirmed ) {
			$banner = '<div class="rc-alert rc-alert--warn" role="status">' . esc_html__( 'This confirmation link is invalid or has already been used.', 'reservechain' ) . '</div>';
		}

		ob_start();
		?>
		<?php echo $banner; // phpcs:ignore ?>
		<form class="rc-form rc-waitlist" novalidate data-rc-form="waitlist">
			<div class="rc-form__grid">
				<label class="rc-field"><span><?php esc_html_e( 'First name', 'reservechain' ); ?> *</span><input name="first_name" autocomplete="given-name" required maxlength="100"></label>
				<label class="rc-field"><span><?php esc_html_e( 'Last name', 'reservechain' ); ?> *</span><input name="last_name" autocomplete="family-name" required maxlength="100"></label>
				<label class="rc-field rc-field--wide"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?> *</span><input type="email" name="email" autocomplete="email" required maxlength="191"></label>
				<label class="rc-field"><span><?php esc_html_e( 'Country of residence', 'reservechain' ); ?> *</span>
					<select name="country" required>
						<option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option>
						<?php foreach ( Schema::countries() as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<fieldset class="rc-field rc-field--inline"><legend><?php esc_html_e( 'I am registering as', 'reservechain' ); ?></legend>
					<label><input type="radio" name="entity_type" value="individual" checked> <?php esc_html_e( 'Individual', 'reservechain' ); ?></label>
					<label><input type="radio" name="entity_type" value="institution"> <?php esc_html_e( 'Institution / company', 'reservechain' ); ?></label>
				</fieldset>
				<label class="rc-field rc-field--org" hidden><span><?php esc_html_e( 'Organisation', 'reservechain' ); ?> *</span><input name="organisation" autocomplete="organization" maxlength="191"></label>
				<fieldset class="rc-field rc-field--inline rc-field--wide"><legend><?php esc_html_e( 'Material of interest', 'reservechain' ); ?> *</legend>
					<label class="rc-chip"><input type="radio" name="materials" value="cu"> <b>Cu</b> <?php esc_html_e( 'Copper Powder', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="radio" name="materials" value="ni"> <b>Ni</b> <?php esc_html_e( 'Nickel Wire', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="radio" name="materials" value="both" checked> <?php esc_html_e( 'Both', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="radio" name="materials" value="future"> <?php esc_html_e( 'Future asset programs', 'reservechain' ); ?></label>
				</fieldset>
				<fieldset class="rc-field rc-field--inline rc-field--wide"><legend><?php esc_html_e( 'I am also interested as', 'reservechain' ); ?></legend>
					<label class="rc-chip"><input type="checkbox" name="buyer_interest" value="1"> <?php esc_html_e( 'Industrial buyer', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="checkbox" name="owner_interest" value="1"> <?php esc_html_e( 'Asset owner / originator', 'reservechain' ); ?></label>
				</fieldset>
				<label class="rc-field"><span><?php esc_html_e( 'Approximate interest range (indicative, non-binding)', 'reservechain' ); ?></span>
					<select name="interest_range"><?php foreach ( Waitlist::RANGES as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( __( $l, 'reservechain' ) ); // phpcs:ignore ?></option><?php endforeach; ?></select>
				</label>
				<label class="rc-field"><span><?php esc_html_e( 'Intended participation type', 'reservechain' ); ?></span>
					<select name="participation_type"><?php foreach ( Waitlist::PARTICIPATION as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( __( $l, 'reservechain' ) ); // phpcs:ignore ?></option><?php endforeach; ?></select>
				</label>
				<?php if ( Settings::module_on( 'waitlist_nationality' ) ) : ?>
					<label class="rc-field"><span><?php esc_html_e( 'Nationality', 'reservechain' ); ?></span><select name="nationality"><option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option><?php foreach ( Schema::countries() as $code => $cname ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $cname ); ?></option><?php endforeach; ?></select></label>
					<label class="rc-field"><span><?php esc_html_e( 'Current location', 'reservechain' ); ?></span><select name="current_location"><option value=""><?php esc_html_e( 'Select…', 'reservechain' ); ?></option><?php foreach ( Schema::countries() as $code => $cname ) : ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $cname ); ?></option><?php endforeach; ?></select></label>
				<?php endif; ?>
			</div>

			<div class="rc-alert rc-alert--warn rc-restricted-note" hidden role="alert"></div>
			<label class="rc-check rc-general-updates" hidden><input type="checkbox" name="general_updates" value="1"> <?php esc_html_e( 'Send me general project updates only (no offering-related communications).', 'reservechain' ); ?></label>

			<div class="rc-disclosure rc-disclosure--form">
				<p><?php echo esc_html( I18n::t( Settings::get( 'disclosure' ) ) ); ?></p>
				<p><?php echo esc_html( I18n::t( Settings::get( 'eu_notice' ) ) ); ?></p>
			</div>
			<label class="rc-check"><input type="checkbox" name="consent_updates" value="1"> <?php esc_html_e( 'I consent to receive project-development updates and future eligibility information by email. I can withdraw consent at any time.', 'reservechain' ); ?></label>
			<label class="rc-check"><input type="checkbox" name="consent_disclosure" value="1" required> <?php esc_html_e( 'I have read and understood the notice above. I understand that registering interest is not an investment, purchase, reservation or allocation of any kind.', 'reservechain' ); ?> *</label>
			<label class="rc-check"><input type="checkbox" name="consent_privacy" value="1" required> <?php printf( wp_kses( __( 'I agree to the processing of my data as described in the <a href="%s">Privacy Notice</a>.', 'reservechain' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( home_url( '/legal/privacy/' ) ) ); ?> *</label>

			<div class="rc-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
			<input type="hidden" name="ts" value="<?php echo esc_attr( (string) time() ); ?>">
			<input type="hidden" name="campaign_source" value="" data-rc-campaign>
			<input type="hidden" name="language" value="<?php echo esc_attr( I18n::lang() ); ?>">
			<button class="rc-btn rc-btn--primary" type="submit"><?php esc_html_e( 'Register interest', 'reservechain' ); ?></button>
			<p class="rc-form__fine"><?php esc_html_e( 'We use double opt-in. Your consent record stores a fingerprint of the exact notice you acknowledged.', 'reservechain' ); ?></p>
			<div class="rc-form__result" role="status" aria-live="polite"></div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function contact(): string {
		self::enqueue();
		ob_start();
		?>
		<form class="rc-form rc-contact" novalidate data-rc-form="contact">
			<div class="rc-form__grid">
				<label class="rc-field"><span><?php esc_html_e( 'Full name', 'reservechain' ); ?></span><input name="name" autocomplete="name" maxlength="191"></label>
				<label class="rc-field"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?> *</span><input type="email" name="email" autocomplete="email" required></label>
				<label class="rc-field"><span><?php esc_html_e( 'Topic', 'reservechain' ); ?></span>
					<select name="topic">
						<?php
						$topics = array(
							'general'      => __( 'General enquiry', 'reservechain' ),
							'supply'       => __( 'Asset owners & originators', 'reservechain' ),
							'buyers'       => __( 'Industrial buyers', 'reservechain' ),
							'institutions' => __( 'Institutions & strategic partners', 'reservechain' ),
							'enterprise'   => __( 'Enterprise tokenization services', 'reservechain' ),
							'licensing'    => __( 'Technology licensing & white-label', 'reservechain' ),
							'participation' => __( 'Early Participation questions', 'reservechain' ),
							'custody'      => __( 'Custody, laboratory & insurance partners', 'reservechain' ),
							'compliance'   => __( 'Compliance', 'reservechain' ),
							'media'        => __( 'Media', 'reservechain' ),
							'support'      => __( 'Support', 'reservechain' ),
							'security'     => __( 'Security / fraud report', 'reservechain' ),
						);
						$current = sanitize_key( $_GET['topic'] ?? 'general' ); // phpcs:ignore
						foreach ( $topics as $k => $l ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $current, $k, false ), esc_html( $l ) );
						}
						?>
					</select>
				</label>
				<label class="rc-field"><span><?php esc_html_e( 'Subject', 'reservechain' ); ?></span><input name="subject" maxlength="150"></label>
				<label class="rc-field rc-field--wide"><span><?php esc_html_e( 'Message', 'reservechain' ); ?> *</span><textarea name="message" rows="6" required minlength="10"></textarea></label>
			</div>
			<label class="rc-check"><input type="checkbox" name="consent_privacy" value="1" required> <?php printf( wp_kses( __( 'I agree to the processing of my data as described in the <a href="%s">Privacy Notice</a>.', 'reservechain' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( home_url( '/legal/privacy/' ) ) ); ?> *</label>
			<div class="rc-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
			<button class="rc-btn rc-btn--primary" type="submit"><?php esc_html_e( 'Send message', 'reservechain' ); ?></button>
			<div class="rc-form__result" role="status" aria-live="polite"></div>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------ verification */

	public static function verify(): string {
		self::enqueue();
		$off = self::module_off( 'verify_tool', __( 'Document verification', 'reservechain' ) );
		if ( $off ) {
			return $off;
		}
		ob_start();
		?>
		<div class="rc-verify" data-rc-verify>
			<label class="rc-drop" tabindex="0">
				<input type="file" class="rc-drop__input" aria-label="<?php esc_attr_e( 'Choose a document to verify', 'reservechain' ); ?>">
				<span class="rc-drop__icon" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Drop a document here, or click to choose', 'reservechain' ); ?></strong>
				<span><?php esc_html_e( 'Your file never leaves your device. Its SHA-256 fingerprint is computed in your browser and only the fingerprint is checked against the registry.', 'reservechain' ); ?></span>
			</label>
			<form class="rc-verify__manual" data-rc-hash-form>
				<label class="rc-field"><span><?php esc_html_e( 'Or paste a SHA-256 fingerprint', 'reservechain' ); ?></span><input name="hash" pattern="[A-Fa-f0-9]{64}" spellcheck="false" autocomplete="off" class="rc-mono" placeholder="e3b0c44298fc1c149afbf4c8996fb924…"></label>
				<button class="rc-btn" type="submit"><?php esc_html_e( 'Verify', 'reservechain' ); ?></button>
			</form>
			<div class="rc-verify__result" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------ registry */

	public static function passports( $atts ): string {
		self::enqueue();
		$a   = shortcode_atts( array( 'program' => '', 'limit' => 24 ), $atts );
		$off = self::module_off( 'passports_public', __( 'Digital Asset Passports', 'reservechain' ) );
		if ( $off ) {
			return $off;
		}
		$items = Passport::list( array( 'program' => $a['program'], 'per_page' => (int) $a['limit'] ) );
		if ( ! $items ) {
			return '<p class="rc-empty">' . esc_html__( 'No passports have been published yet.', 'reservechain' ) . '</p>';
		}
		$out = '<div class="rc-passport-grid">';
		foreach ( $items as $p ) {
			$full = Passport::build( $p['id'] );
			$out .= sprintf(
				'<a class="rc-pcard rc-pcard--%1$s" href="%2$s"><span class="rc-pcard__el" aria-hidden="true">%3$s</span><span class="rc-pcard__type">%4$s</span><strong class="rc-pcard__no">%5$s</strong><span class="rc-pcard__title">%6$s</span><span class="rc-pcard__meter" style="--p:%7$d%%"><i></i></span><span class="rc-pcard__meta">%8$s · %9$s</span>%10$s</a>',
				esc_attr( strtolower( (string) $p['symbol'] ) ),
				esc_url( $p['url'] ),
				esc_html( $p['symbol'] ?: 'RC' ),
				esc_html( __( $p['entity_label'], 'reservechain' ) ), // phpcs:ignore
				esc_html( $p['passport_no'] ),
				esc_html( get_the_title( $p['id'] ) ),
				(int) $full['completeness']['percent'],
				/* translators: %d: percent */
				esc_html( sprintf( __( 'Evidence %d%%', 'reservechain' ), (int) $full['completeness']['percent'] ) ),
				esc_html( sprintf( _n( '%d document', '%d documents', count( $full['documents'] ), 'reservechain' ), count( $full['documents'] ) ) ),
				self::pill( $p['status'] )
			);
		}
		return $out . '</div>';
	}

	public static function documents( $atts ): string {
		self::enqueue();
		$a    = shortcode_atts( array( 'type' => '' ), $atts );
		$docs = Rest::documents();
		if ( $a['type'] ) {
			$docs = array_filter( $docs, static fn( $d ) => $d['type'] === $a['type'] );
		}
		if ( ! $docs ) {
			return '<p class="rc-empty">' . esc_html__( 'No documents have been published yet.', 'reservechain' ) . '</p>';
		}
		$out = '<div class="rc-doclist">';
		foreach ( $docs as $d ) {
			$out .= sprintf(
				'<article class="rc-doc"><div class="rc-doc__icon" aria-hidden="true">%1$s</div><div class="rc-doc__body"><h3>%2$s</h3><p class="rc-doc__meta">%3$s%4$s%5$s</p><p class="rc-doc__hash"><span>SHA-256</span><code class="rc-mono" data-copy>%6$s</code></p></div><div class="rc-doc__actions">%7$s%8$s</div></article>',
				esc_html( strtoupper( wp_check_filetype( (string) $d['url'] )['ext'] ?: 'DOC' ) ),
				esc_html( $d['title'] ),
				esc_html( __( $d['type_label'], 'reservechain' ) ), // phpcs:ignore
				$d['version'] ? ' · ' . esc_html( $d['version'] ) : '',
				$d['issue_date'] ? ' · ' . esc_html( $d['issue_date'] ) : '',
				esc_html( $d['sha256'] ?? '—' ),
				self::pill( $d['status'] ),
				$d['url'] ? '<a class="rc-btn rc-btn--sm" href="' . esc_url( $d['url'] ) . '" download>' . esc_html__( 'Download', 'reservechain' ) . '</a>' : ''
			);
		}
		return $out . '</div>';
	}

	public static function registry_stats(): string {
		$s     = Rest::registry_stats();
		$keys  = array( 'rc_program', 'rc_lot', 'rc_batch', 'rc_container', 'rc_coil', 'rc_coa', 'rc_custody', 'rc_reserve_report', 'rc_document' );
		$out   = '<dl class="rc-stats">';
		foreach ( $keys as $k ) {
			if ( isset( $s['entities'][ $k ] ) ) {
				$out .= sprintf( '<div><dt>%s</dt><dd>%d</dd></div>', esc_html( __( $s['entities'][ $k ]['label'], 'reservechain' ) ), (int) $s['entities'][ $k ]['published'] ); // phpcs:ignore
			}
		}
		$out .= sprintf( '<div><dt>%s</dt><dd>%d</dd></div>', esc_html__( 'Verified records', 'reservechain' ), (int) $s['verified_records'] );
		return $out . '</dl>';
	}

	public static function audit_status(): string {
		$h = Rest::audit_head();
		$v = $h['last_verified'];
		return sprintf(
			'<div class="rc-chain"><div class="rc-chain__row"><span>%1$s</span><strong>#%2$d</strong></div><div class="rc-chain__row"><span>%3$s</span><code class="rc-mono" data-copy>%4$s</code></div><div class="rc-chain__row"><span>%5$s</span>%6$s</div><div class="rc-chain__row"><span>%7$s</span>%8$s</div><div class="rc-chain__row"><span>%9$s</span>%10$s</div></div>',
			esc_html__( 'Audit entries', 'reservechain' ),
			(int) $h['seq'],
			esc_html__( 'Chain head (SHA-256)', 'reservechain' ),
			esc_html( $h['chain_head'] ),
			esc_html__( 'Database immutability triggers', 'reservechain' ),
			$h['db_triggers'] ? self::pill( 'verified', __( 'Active', 'reservechain' ) ) : self::pill( 'pending_verification', __( 'Unavailable', 'reservechain' ) ),
			esc_html__( 'Last full-chain verification', 'reservechain' ),
			$v ? self::pill( $v['ok'] ? 'verified' : 'restricted', $v['ok'] ? sprintf( __( 'Intact · %1$d entries · %2$s', 'reservechain' ), $v['checked'], substr( $v['at'], 0, 10 ) ) : __( 'Integrity failure', 'reservechain' ) ) : self::pill( 'pending' ),
			esc_html__( 'Latest on-chain anchor', 'reservechain' ),
			$h['last_anchor'] ? '<code class="rc-mono">#' . (int) $h['last_anchor']['seq'] . ' · ' . esc_html( $h['last_anchor']['network'] ) . ' · ' . esc_html( substr( $h['last_anchor']['tx_hash'], 0, 18 ) ) . '…</code>' : self::pill( 'in_development', __( 'Not yet anchored (testnet pending)', 'reservechain' ) )
		);
	}

	public static function token_params( $atts ): string {
		$a    = shortcode_atts( array( 'program' => '' ), $atts );
		$prog = get_page_by_path( sanitize_title( $a['program'] ), OBJECT, 'rc_program' );
		$tp   = $prog ? Registry::referencing( $prog->ID, array( 'rc_token_program' ) ) : array();
		if ( ! $tp ) {
			return '<p class="rc-empty">' . esc_html__( 'No token program has been published for this metal program.', 'reservechain' ) . '</p>';
		}
		return self::field_table( Passport::public_fields( $tp[0]->ID ), self::pill( get_post_meta( $tp[0]->ID, '_rc_verification_status', true ) ?: 'proposed' ) );
	}

	public static function program_facts( $atts ): string {
		$a    = shortcode_atts( array( 'program' => '' ), $atts );
		$prog = get_page_by_path( sanitize_title( $a['program'] ), OBJECT, 'rc_program' );
		if ( ! $prog || 'publish' !== $prog->post_status ) {
			return '';
		}
		$fields = array_filter( Passport::public_fields( $prog->ID ), static fn( $f ) => ! in_array( $f['key'], array( 'industrial_uses', 'symbol', 'atomic_number' ), true ) );
		return self::field_table( $fields, self::pill( get_post_meta( $prog->ID, '_rc_verification_status', true ) ?: 'in_development' ) );
	}

	/** "Element: value" lines → periodic-style result grid (values exactly as printed on the certificate). */
	public static function assay_grid( string $raw ): string {
		$out = '<div class="rc-assay">';
		foreach ( preg_split( '/\r?\n/', trim( $raw ) ) as $line ) {
			if ( ! preg_match( '/^\s*([A-Z][a-z]?)\s*[:=]\s*(.+?)\s*$/', $line, $m ) ) {
				continue;
			}
			$matrix = 0 === strcasecmp( $m[2], 'matrix' );
			$det    = ! $matrix && '<' !== substr( $m[2], 0, 1 );
			$out   .= sprintf( '<span class="rc-assay__el%s"><b>%s</b><i>%s</i></span>', $matrix ? ' is-matrix' : ( $det ? ' is-detected' : '' ), esc_html( $m[1] ), esc_html( $m[2] ) );
		}
		return $out . '</div><p class="rc-assay__legend"><span class="is-detected"></span>' . esc_html__( 'detected', 'reservechain' ) . ' <span></span>' . esc_html__( 'below detection limit', 'reservechain' ) . ' <span class="is-matrix"></span>' . esc_html__( 'matrix (base metal)', 'reservechain' ) . '</p>';
	}

	public static function field_table( array $fields, string $status_html = '' ): string {
		$out = '<div class="rc-ftable">' . ( $status_html ? '<div class="rc-ftable__status">' . $status_html . '</div>' : '' ) . '<dl>';
		foreach ( $fields as $f ) {
			if ( 'assay' === ( $f['type'] ?? '' ) && 'provided' === $f['state'] ) {
				$out .= '<div class="rc-ftable__row rc-ftable__row--assay"><dt>' . esc_html( __( $f['label'], 'reservechain' ) ) . ' <small>[' . esc_html( (string) $f['unit'] ) . ']</small></dt><dd>' . self::assay_grid( (string) $f['value'] ) . '</dd></div>'; // phpcs:ignore
				continue;
			}
			$out .= sprintf(
				'<div class="rc-ftable__row rc-ftable__row--%1$s"><dt>%2$s</dt><dd>%3$s</dd></div>',
				esc_attr( $f['state'] ),
				esc_html( __( $f['label'], 'reservechain' ) ), // phpcs:ignore
				'provided' === $f['state'] ? esc_html( $f['value'] . ( $f['unit'] ? ' ' . $f['unit'] : '' ) ) : '<span class="rc-pending">' . esc_html( __( $f['pending'], 'reservechain' ) ) . '</span>' // phpcs:ignore
			);
		}
		return $out . '</dl></div>';
	}
}
