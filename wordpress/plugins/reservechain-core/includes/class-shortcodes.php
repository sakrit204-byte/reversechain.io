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
		} elseif ( false === $confirmed ) {
			$banner = '<div class="rc-alert rc-alert--warn" role="status">' . esc_html__( 'This confirmation link is invalid or has already been used.', 'reservechain' ) . '</div>';
		}

		ob_start();
		?>
		<?php echo $banner; // phpcs:ignore ?>
		<form class="rc-form rc-waitlist" novalidate data-rc-form="waitlist">
			<div class="rc-form__grid">
				<label class="rc-field"><span><?php esc_html_e( 'Full name', 'reservechain' ); ?> *</span><input name="name" autocomplete="name" required maxlength="191"></label>
				<label class="rc-field"><span><?php esc_html_e( 'Email address', 'reservechain' ); ?> *</span><input type="email" name="email" autocomplete="email" required maxlength="191"></label>
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
				<fieldset class="rc-field rc-field--inline rc-field--wide"><legend><?php esc_html_e( 'Programs of interest', 'reservechain' ); ?></legend>
					<label class="rc-chip"><input type="checkbox" name="interest[]" value="cu" checked> <b>Cu</b> <?php esc_html_e( 'Copper Powder', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="checkbox" name="interest[]" value="ni" checked> <b>Ni</b> <?php esc_html_e( 'Nickel Wire', 'reservechain' ); ?></label>
					<label class="rc-chip"><input type="checkbox" name="interest[]" value="enterprise"> <?php esc_html_e( 'Enterprise services', 'reservechain' ); ?></label>
				</fieldset>
			</div>

			<div class="rc-alert rc-alert--warn rc-restricted-note" hidden role="alert"></div>
			<label class="rc-check rc-general-updates" hidden><input type="checkbox" name="general_updates" value="1"> <?php esc_html_e( 'Send me general project updates only (no offering-related communications).', 'reservechain' ); ?></label>

			<div class="rc-disclosure rc-disclosure--form">
				<p><?php echo esc_html( I18n::t( Settings::get( 'disclosure' ) ) ); ?></p>
				<p><?php echo esc_html( I18n::t( Settings::get( 'eu_notice' ) ) ); ?></p>
			</div>
			<label class="rc-check"><input type="checkbox" name="consent_disclosure" value="1" required> <?php esc_html_e( 'I have read and understood the notice above. I understand that registering interest is not an investment, purchase, reservation or allocation of any kind.', 'reservechain' ); ?> *</label>
			<label class="rc-check"><input type="checkbox" name="consent_privacy" value="1" required> <?php printf( wp_kses( __( 'I agree to the processing of my data as described in the <a href="%s">Privacy Notice</a>.', 'reservechain' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( home_url( '/legal/privacy/' ) ) ); ?> *</label>

			<div class="rc-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
			<input type="hidden" name="ts" value="<?php echo esc_attr( (string) time() ); ?>">
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
						<option value="general"><?php esc_html_e( 'General enquiry', 'reservechain' ); ?></option>
						<option value="enterprise"><?php esc_html_e( 'Enterprise services', 'reservechain' ); ?></option>
						<option value="supply"><?php esc_html_e( 'Producers & supply partners', 'reservechain' ); ?></option>
						<option value="custody"><?php esc_html_e( 'Custody, laboratory & insurance partners', 'reservechain' ); ?></option>
						<option value="media"><?php esc_html_e( 'Media', 'reservechain' ); ?></option>
						<option value="security"><?php esc_html_e( 'Security disclosure', 'reservechain' ); ?></option>
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

	public static function field_table( array $fields, string $status_html = '' ): string {
		$out = '<div class="rc-ftable">' . ( $status_html ? '<div class="rc-ftable__status">' . $status_html . '</div>' : '' ) . '<dl>';
		foreach ( $fields as $f ) {
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
