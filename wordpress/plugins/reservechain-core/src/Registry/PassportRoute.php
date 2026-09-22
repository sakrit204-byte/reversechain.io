<?php
/**
 * Public passport permalink.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Registry;

use ReserveChain\Core\Content\Publication;

/**
 * Serves /passport/{public_id}/.
 *
 * A passport gets a real URL rather than a query string because it is meant to
 * be printed on documentation, embedded in a QR code and pasted into an email
 * during due diligence. Those uses need something stable, short and obviously
 * not a search result.
 *
 * The route is registered by the plugin, not the theme, so a change of theme
 * cannot break links that counterparties already hold.
 */
final class PassportRoute {

	/**
	 * Query variable carrying the passport identifier.
	 */
	private const QUERY_VAR = 'rc_passport';

	/**
	 * Hook the rewrite rule and the template takeover.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite' ), 5 );
		add_filter( 'query_vars', array( self::class, 'add_query_var' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Register the rewrite rule.
	 *
	 * Crockford base32 excludes I, L, O and U to avoid transcription errors,
	 * which is exactly the property wanted for an identifier someone may read
	 * off a printed page, so the pattern matches that alphabet only.
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule(
			'^passport/([0-9A-HJKMNP-TV-Z]{26})/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		// Flush once per rule change rather than on every request.
		if ( get_option( 'rc_passport_rewrite_version' ) !== '1' ) {
			flush_rewrite_rules( false );
			update_option( 'rc_passport_rewrite_version', '1', false );
		}
	}

	/**
	 * Allow the query variable.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Render a passport when the route matches.
	 */
	public static function maybe_render(): void {
		$public_id = get_query_var( self::QUERY_VAR );

		if ( ! is_string( $public_id ) || '' === $public_id ) {
			return;
		}

		$record = Passports::by_public_id( $public_id );

		if ( null === $record || ! Passports::is_viewable( $record ) ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return;
		}

		status_header( 200 );
		nocache_headers();

		self::render( $record );

		exit;
	}

	/**
	 * Output the passport document.
	 *
	 * Rendered directly rather than through a block template because a
	 * passport is a record, not a page: it has no editorial content, and
	 * putting it in the editor would invite someone to alter evidence.
	 *
	 * @param array<string,mixed> $record Passport record.
	 */
	private static function render( array $record ): void {
		$unit    = (array) $record['unit'];
		$lot     = (array) $record['lot'];
		$program = (array) $record['program'];

		$quantity = Publication::quantity_display(
			$unit,
			'declared_net_weight',
			'verified_net_weight',
			(string) ( $unit['weight_unit'] ?? '' )
		);

		$indexable = Passports::is_indexable( $record );
		$url       = Passports::url( (string) $record['public_id'] );

		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if ( ! $indexable ) : ?>
	<meta name="robots" content="noindex, nofollow, noarchive">
	<?php endif; ?>
	<title><?php echo esc_html( sprintf( '%s — Digital Asset Passport', (string) $record['display_id'] ) ); ?></title>
	<link rel="canonical" href="<?php echo esc_url( $url ); ?>">
	<?php wp_head(); ?>
</head>
<body class="rc-passport-page">
<a class="rc-skip-link" href="#rc-passport"><?php esc_html_e( 'Skip to passport', 'reservechain' ); ?></a>

<main id="rc-passport" class="rc-passport<?php echo $record['is_illustrative'] ? ' rc-illustrative' : ''; ?>">

	<?php if ( $record['is_illustrative'] ) : ?>
		<p class="rc-illustrative__label"><?php esc_html_e( 'Illustrative — demo data', 'reservechain' ); ?></p>
	<?php endif; ?>

	<header class="rc-passport__header">
		<p class="rc-passport__eyebrow"><?php esc_html_e( 'Digital Asset Passport', 'reservechain' ); ?></p>
		<h1 class="rc-passport__id"><?php echo esc_html( (string) $record['display_id'] ); ?></h1>
		<p class="rc-passport__program">
			<?php echo esc_html( (string) ( $program['name'] ?? '' ) ); ?>
			<?php if ( ! empty( $lot['lot_number'] ) ) : ?>
				· <?php echo esc_html( sprintf( __( 'Lot %s', 'reservechain' ), (string) $lot['lot_number'] ) ); ?>
			<?php endif; ?>
		</p>
	</header>

	<?php if ( ! Publication::is_public( (string) $record['publication_state'] ) ) : ?>
		<p class="rc-passport__state">
			<span class="rc-badge rc-badge--review"><?php esc_html_e( 'Under review', 'reservechain' ); ?></span>
			<?php esc_html_e( 'This record has not been approved for publication. It is reachable by its unique link for due-diligence purposes and is excluded from search indexing.', 'reservechain' ); ?>
		</p>
	<?php endif; ?>

	<section class="rc-passport__section">
		<h2><?php esc_html_e( 'Physical unit', 'reservechain' ); ?></h2>
		<dl class="rc-passport__grid">
			<div><dt><?php esc_html_e( 'Unit identifier', 'reservechain' ); ?></dt><dd><?php echo esc_html( (string) $unit['identifier'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Unit type', 'reservechain' ); ?></dt><dd><?php echo esc_html( ucfirst( (string) $unit['kind'] ) ); ?></dd></div>
			<div class="rc-figure rc-figure--<?php echo $quantity['verified'] ? 'verified' : 'declared'; ?>">
				<dt><?php esc_html_e( 'Net weight', 'reservechain' ); ?></dt>
				<dd>
					<span class="rc-figure__value"><?php echo esc_html( $quantity['value'] ); ?></span>
					<span class="rc-figure__qualifier"><?php echo esc_html( $quantity['qualifier'] ); ?></span>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Sampled for analysis', 'reservechain' ); ?></dt>
				<dd>
					<?php
					echo empty( $unit['was_sampled'] )
						? esc_html__( 'Not individually sampled', 'reservechain' )
						: esc_html( (string) ( $unit['sampled_on'] ?? __( 'Yes', 'reservechain' ) ) );
					?>
				</dd>
			</div>
		</dl>
	</section>

	<section class="rc-passport__section">
		<h2><?php esc_html_e( 'Status', 'reservechain' ); ?></h2>
		<ul class="rc-passport__statuses" role="list">
			<?php
			$fields = array(
				'verification_status' => __( 'Independent verification', 'reservechain' ),
				'custody_status'      => __( 'Custody', 'reservechain' ),
				'reserve_status'      => __( 'Reserve allocation', 'reservechain' ),
				'redemption_status'   => __( 'Physical redemption', 'reservechain' ),
			);

			foreach ( $fields as $field => $label ) :
				$status = Publication::status_display( $unit, $field );
				?>
				<li>
					<span><?php echo esc_html( $label ); ?></span>
					<span class="rc-badge rc-badge--<?php echo esc_attr( $status['variant'] ); ?>"><?php echo esc_html( $status['label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="rc-asset-note"><?php esc_html_e( 'A status of "Pending" means no approved evidence exists for that item. It is not a statement that the underlying arrangement is absent.', 'reservechain' ); ?></p>
	</section>

	<?php if ( ! empty( $record['certificates'] ) ) : ?>
	<section class="rc-passport__section">
		<h2><?php esc_html_e( 'Evidence', 'reservechain' ); ?></h2>
		<?php foreach ( (array) $record['certificates'] as $certificate ) : ?>
			<article class="rc-passport__evidence">
				<h3><?php echo esc_html( sprintf( __( 'Certificate of Analysis No. %s', 'reservechain' ), (string) $certificate['certificate_number'] ) ); ?></h3>
				<dl class="rc-passport__grid">
					<?php if ( ! empty( $certificate['laboratory_name'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Laboratory', 'reservechain' ); ?></dt><dd><?php echo esc_html( (string) $certificate['laboratory_name'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( ! empty( $certificate['certificate_date'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Date', 'reservechain' ); ?></dt><dd><?php echo esc_html( (string) $certificate['certificate_date'] ); ?></dd></div>
					<?php endif; ?>
					<?php if ( ! empty( $certificate['purity_pct'] ) ) : ?>
						<div><dt><?php esc_html_e( 'Stated purity', 'reservechain' ); ?></dt><dd><?php echo esc_html( Publication::format_number( (string) $certificate['purity_pct'] ) ); ?>%</dd></div>
					<?php endif; ?>
				</dl>
				<?php if ( ! empty( $certificate['document_sha256'] ) ) : ?>
					<p class="rc-passport__digest">
						<span class="rc-passport__digest-label"><?php esc_html_e( 'Document SHA-256', 'reservechain' ); ?></span>
						<code><?php echo esc_html( (string) $certificate['document_sha256'] ); ?></code>
					</p>
					<p class="rc-asset-note">
						<?php
						printf(
							/* translators: %s: verifier URL */
							esc_html__( 'Hold a copy of this certificate? Confirm it is byte-identical to the record above at %s. The file is hashed in your browser and never uploaded.', 'reservechain' ),
							'<a href="' . esc_url( home_url( '/verify/' ) ) . '">' . esc_html( home_url( '/verify/' ) ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</section>
	<?php endif; ?>

	<?php if ( ! empty( $record['evidence_hash'] ) ) : ?>
	<section class="rc-passport__section">
		<h2><?php esc_html_e( 'Record integrity', 'reservechain' ); ?></h2>
		<p class="rc-passport__digest">
			<span class="rc-passport__digest-label"><?php esc_html_e( 'Evidence digest', 'reservechain' ); ?></span>
			<code><?php echo esc_html( (string) $record['evidence_hash'] ); ?></code>
		</p>
		<p class="rc-asset-note"><?php esc_html_e( 'This digest covers the factual content of this passport: identifiers, weights, sampling, statuses and certificate references. If any of them changes, the digest changes. Record it when you receive this passport so you can confirm later that the record you were shown has not been altered.', 'reservechain' ); ?></p>
	</section>
	<?php endif; ?>

	<footer class="rc-passport__footer">
		<p class="rc-passport__url"><?php echo esc_html( $url ); ?></p>
		<p class="rc-asset-note">
			<?php esc_html_e( 'ReserveChain is currently in development. No tokens are being offered or sold through this website. This passport is a record of supplied documentation and does not represent ownership, custody, insurance, reserve coverage or any right to the material described.', 'reservechain' ); ?>
		</p>
	</footer>

</main>

<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
