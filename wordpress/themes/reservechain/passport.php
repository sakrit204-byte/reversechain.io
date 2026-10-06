<?php
/**
 * Digital Asset Passport view — /passport/{passport_no}/
 * Data is assembled by RC\Passport::build(); nothing on this page is hand-typed.
 *
 * @package ReserveChain\Theme
 */

defined( 'ABSPATH' ) || exit;

$p = RC\Passport::current();
if ( ! $p ) {
	get_template_part( '404' );
	return;
}
wp_enqueue_script( 'rc-public' );
get_header();

$sym      = $p['program']['symbol'] ?? 'RC';
$specimen = 0 === stripos( $p['description'], 'SPECIMEN' ) || false !== stripos( $p['title'], 'specimen' ) || false !== stripos( $p['title'], 'Illustrative' );
$status_keys = array( 'availability_status', 'custody_status', 'reserve_status', 'tokenization_status', 'redemption_status' );
$f_status    = array_values( array_filter( $p['fields'], static fn( $f ) => in_array( $f['key'], $status_keys, true ) ) );
$f_spec      = array_values( array_filter( $p['fields'], static fn( $f ) => ! empty( $f['scope'] ) ) );
$f_ident     = array_values( array_filter( $p['fields'], static fn( $f ) => empty( $f['scope'] ) && ! in_array( $f['key'], $status_keys, true ) ) );
$pct      = (int) $p['completeness']['percent'];
$circ     = 2 * M_PI * 52;
$stage_lb = array(
	'verified'             => __( 'Verified', 'reservechain' ),
	'pending_verification' => __( 'Evidence received — pending verification', 'reservechain' ),
	'pending'              => __( 'Pending', 'reservechain' ),
);
?>
<section class="rc-dap-hero rc-dap-hero--<?php echo esc_attr( strtolower( $sym ) ); ?>">
	<div class="rc-wrap">
		<nav class="rc-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'reservechain' ); ?>"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'reservechain' ); ?></a><span aria-hidden="true">/</span><a href="<?php echo esc_url( home_url( '/platform/digital-asset-passports/' ) ); ?>"><?php esc_html_e( 'Digital Asset Passports', 'reservechain' ); ?></a><?php if ( ! empty( $p['program']['slug'] ) ) : ?><span aria-hidden="true">/</span><a href="<?php echo esc_url( home_url( '/assets/industrial-metals/' . $p['program']['slug'] . '/' ) ); ?>"><?php echo esc_html( rct__( $p['program']['name'] ) ); ?></a><?php endif; ?><span aria-hidden="true">/</span><span aria-current="page"><?php echo esc_html( $p['passport_no'] ); ?></span></nav>
		<?php echo rct_provisional(); // phpcs:ignore ?>
		<?php if ( $specimen ) : ?>
			<div class="rc-specimen" role="note"><strong><?php esc_html_e( 'Illustrative / demo data', 'reservechain' ); ?></strong> — <?php esc_html_e( 'This presentation demonstrates the future format of a ReserveChain industrial-metal asset page. No verified material, ownership document, laboratory report, valuation, custody arrangement, reserve claim or token is represented by this placeholder.', 'reservechain' ); ?></div>
		<?php endif; ?>
		<div class="rc-dap-hero__grid">
			<div class="rc-dap-hero__id">
				<div class="rc-element rc-element--<?php echo esc_attr( strtolower( $sym ) ); ?>" aria-hidden="true">
					<span class="rc-element__n"><?php echo esc_html( (string) ( $p['program']['atomic_number'] ?? '' ) ); ?></span>
					<span class="rc-element__s"><?php echo esc_html( $sym ); ?></span>
					<span class="rc-element__l"><?php echo esc_html( rct__( $p['program']['name'] ?? '' ) ); ?></span>
				</div>
				<div>
					<p class="rc-kicker"><?php esc_html_e( 'Digital Asset Passport', 'reservechain' ); ?> · <?php echo esc_html( rct__( $p['entity_label'] ) ); ?></p>
					<h1 class="rc-dap-hero__no rc-mono" data-copy><?php echo esc_html( $p['passport_no'] ); ?></h1>
					<p class="rc-dap-hero__title"><?php echo esc_html( $p['title'] ); ?></p>
					<p class="rc-dap-hero__meta"><?php echo rct_pill( $p['status'] ); // phpcs:ignore ?> <span><?php esc_html_e( 'Updated', 'reservechain' ); ?> <time datetime="<?php echo esc_attr( $p['updated_at'] ); ?>"><?php echo esc_html( substr( $p['updated_at'], 0, 10 ) ); ?></time></span> <span class="rc-mono">schema <?php echo esc_html( $p['schema'] ); ?></span></p>
				</div>
			</div>
			<aside class="rc-dap-card" aria-label="<?php esc_attr_e( 'Passport integrity', 'reservechain' ); ?>">
				<div class="rc-dap-card__qr" data-qr="<?php echo esc_attr( $p['url'] ); ?>" aria-label="<?php esc_attr_e( 'QR code linking to this passport', 'reservechain' ); ?>"></div>
				<div class="rc-dap-card__ring" style="--pct:<?php echo esc_attr( (string) $pct ); ?>">
					<svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" class="bg"/><circle cx="60" cy="60" r="52" class="fg" stroke-dasharray="<?php echo esc_attr( (string) round( $circ * $pct / 100, 2 ) ); ?> <?php echo esc_attr( (string) round( $circ, 2 ) ); ?>"/></svg>
					<div><strong><?php echo esc_html( (string) $pct ); ?>%</strong><span><?php esc_html_e( 'evidence completeness', 'reservechain' ); ?></span><small><?php echo esc_html( sprintf( '%d / %d', $p['completeness']['present'], $p['completeness']['total'] ) ); ?></small></div>
				</div>
				<div class="rc-dap-card__root">
					<span><?php esc_html_e( 'Evidence Merkle root', 'reservechain' ); ?></span>
					<code class="rc-mono" data-copy><?php echo esc_html( (string) $p['merkle_root'] ); ?></code>
				</div>
				<div class="rc-dap-card__actions">
					<a class="rc-btn rc-btn--sm" href="<?php echo esc_url( $p['json_url'] ); ?>"><?php esc_html_e( 'Machine-readable JSON', 'reservechain' ); ?></a>
					<button class="rc-btn rc-btn--sm" type="button" data-print><?php esc_html_e( 'Print / PDF', 'reservechain' ); ?></button>
				</div>
			</aside>
		</div>
		<?php if ( $f_status ) : ?>
		<dl class="rc-unitstatus" aria-label="<?php esc_attr_e( 'Unit status', 'reservechain' ); ?>">
			<?php foreach ( $f_status as $f ) : ?>
				<div><dt><?php echo esc_html( rct__( $f['label'] ) ); ?></dt><dd><?php echo esc_html( rct__( (string) ( $f['value'] ?? $f['pending'] ) ) ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<?php endif; ?>
	</div>
</section>

<div class="rc-wrap rc-dap">
	<nav class="rc-dap__toc" aria-label="<?php esc_attr_e( 'Passport sections', 'reservechain' ); ?>">
		<a href="#identity"><?php esc_html_e( 'Identity', 'reservechain' ); ?></a>
		<a href="#lifecycle"><?php esc_html_e( 'Lifecycle', 'reservechain' ); ?></a>
		<a href="#provenance"><?php esc_html_e( 'Provenance', 'reservechain' ); ?></a>
		<a href="#evidence"><?php esc_html_e( 'Evidence', 'reservechain' ); ?></a>
		<a href="#documents"><?php esc_html_e( 'Documents', 'reservechain' ); ?></a>
		<a href="#integrity"><?php esc_html_e( 'Integrity', 'reservechain' ); ?></a>
	</nav>

	<div class="rc-dap__body">
		<section id="identity" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Identity & specification', 'reservechain' ); ?></h2>
			<?php if ( $p['description'] ) : ?><p class="rc-dap__desc"><?php echo esc_html( $p['description'] ); ?></p><?php endif; ?>
			<?php echo RC\Shortcodes::field_table( $f_ident ); // phpcs:ignore ?>
			<?php if ( $f_spec ) : ?>
				<h3 class="rc-dap__sub"><?php esc_html_e( 'Technical specification', 'reservechain' ); ?> <small><?php echo esc_html( sprintf( __( '%s program fields', 'reservechain' ), $sym ) ); ?></small></h3>
				<?php echo RC\Shortcodes::field_table( $f_spec ); // phpcs:ignore ?>
			<?php endif; ?>
		</section>

		<section id="lifecycle" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Lifecycle', 'reservechain' ); ?> <small><?php esc_html_e( 'derived from evidence — never typed by hand', 'reservechain' ); ?></small></h2>
			<ol class="rc-timeline">
				<?php foreach ( $p['timeline'] as $s ) : ?>
					<li class="rc-timeline__step rc-timeline__step--<?php echo esc_attr( $s['status'] ); ?>">
						<span class="rc-timeline__dot" aria-hidden="true"></span>
						<div>
							<strong><?php echo esc_html( rct__( $s['label'] ) ); ?></strong>
							<span class="rc-timeline__status"><?php echo esc_html( $stage_lb[ $s['status'] ] ?? $s['status'] ); ?><?php echo $s['date'] ? ' · ' . esc_html( $s['date'] ) : ''; ?></span>
							<?php if ( $s['evidence'] ) : ?><span class="rc-mono rc-timeline__ev"><?php echo esc_html( $s['evidence'] ); ?><?php echo $s['inherited'] ? ' · ' . esc_html( sprintf( __( 'inherited from %s', 'reservechain' ), $s['inherited'] ) ) : ''; ?></span><?php endif; ?>
							<?php if ( $s['note'] ) : ?><span class="rc-pending"><?php echo esc_html( rct__( $s['note'] ) ); ?></span><?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>

		<section id="provenance" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Provenance', 'reservechain' ); ?></h2>
			<div class="rc-chainview">
				<?php foreach ( array_reverse( $p['lineage'] ) as $l ) : ?>
					<a class="rc-chainview__node" href="<?php echo esc_url( home_url( '/passport/' . $l['record_no'] . '/' ) ); ?>"><span><?php echo esc_html( rct__( $l['type'] ) ); ?></span><code class="rc-mono"><?php echo esc_html( $l['record_no'] ); ?></code></a>
					<span class="rc-chainview__link" aria-hidden="true"></span>
				<?php endforeach; ?>
				<span class="rc-chainview__node is-current"><span><?php echo esc_html( rct__( $p['entity_label'] ) ); ?></span><code class="rc-mono"><?php echo esc_html( $p['passport_no'] ); ?></code></span>
				<?php if ( $p['children'] ) : ?>
					<span class="rc-chainview__link" aria-hidden="true"></span>
					<span class="rc-chainview__kids">
						<?php foreach ( $p['children'] as $c ) : ?>
							<a class="rc-chainview__node" href="<?php echo esc_url( home_url( '/passport/' . $c['record_no'] . '/' ) ); ?>"><span><?php echo esc_html( rct__( $c['type'] ) ); ?></span><code class="rc-mono"><?php echo esc_html( $c['record_no'] ); ?></code></a>
						<?php endforeach; ?>
					</span>
				<?php endif; ?>
			</div>
		</section>

		<section id="evidence" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Evidence records', 'reservechain' ); ?></h2>
			<?php if ( ! $p['evidence'] ) : ?>
				<p class="rc-empty"><?php esc_html_e( 'No evidence records have been published for this asset yet.', 'reservechain' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $p['evidence'] as $ev ) : ?>
				<details class="rc-evidence">
					<summary><span class="rc-mono"><?php echo esc_html( $ev['record_no'] ); ?></span> <strong><?php echo esc_html( rct__( $ev['type_label'] ) ); ?></strong> <span class="rc-evidence__title"><?php echo esc_html( $ev['title'] ); ?></span> <?php echo rct_pill( $ev['status'] ); // phpcs:ignore ?><?php echo $ev['inherited'] ? ' <span class="rc-tag">' . esc_html( sprintf( __( 'inherited from %s', 'reservechain' ), $ev['inherited'] ) ) . '</span>' : ''; ?></summary>
					<?php echo RC\Shortcodes::field_table( $ev['fields'] ); // phpcs:ignore ?>
				</details>
			<?php endforeach; ?>
		</section>

		<section id="documents" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Document ledger', 'reservechain' ); ?></h2>
			<?php if ( ! $p['documents'] ) : ?>
				<p class="rc-empty"><?php esc_html_e( 'No documents have been published for this asset yet.', 'reservechain' ); ?></p>
			<?php endif; ?>
			<div class="rc-doclist">
			<?php foreach ( $p['documents'] as $d ) : ?>
				<article class="rc-doc">
					<div class="rc-doc__icon" aria-hidden="true">PDF</div>
					<div class="rc-doc__body">
						<h3><?php echo esc_html( $d['title'] ); ?></h3>
						<p class="rc-doc__meta"><span class="rc-mono"><?php echo esc_html( $d['record_no'] ); ?></span> · <?php echo esc_html( rct__( $d['type_label'] ) ); ?><?php echo $d['version'] ? ' · ' . esc_html( $d['version'] ) : ''; ?><?php echo $d['issued_by'] ? ' · ' . esc_html( $d['issued_by'] ) : ''; ?></p>
						<p class="rc-doc__hash"><span>SHA-256</span><code class="rc-mono" data-copy><?php echo esc_html( (string) $d['sha256'] ); ?></code></p>
					</div>
					<div class="rc-doc__actions">
						<?php echo rct_pill( $d['status'] ); // phpcs:ignore ?>
						<a class="rc-btn rc-btn--sm" href="<?php echo esc_url( add_query_arg( 'hash', $d['sha256'], home_url( '/verification/' ) ) ); ?>"><?php esc_html_e( 'Verify', 'reservechain' ); ?></a>
						<?php if ( $d['url'] ) : ?><a class="rc-btn rc-btn--sm" href="<?php echo esc_url( $d['url'] ); ?>" download><?php esc_html_e( 'Download', 'reservechain' ); ?></a><?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
			</div>
		</section>

		<section id="integrity" class="rc-dap__sec">
			<h2><?php esc_html_e( 'Integrity', 'reservechain' ); ?></h2>
			<dl class="rc-kv">
				<div><dt><?php esc_html_e( 'Record fingerprint', 'reservechain' ); ?></dt><dd><code class="rc-mono" data-copy><?php echo esc_html( $p['record_fingerprint'] ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'Evidence Merkle root', 'reservechain' ); ?></dt><dd><code class="rc-mono" data-copy><?php echo esc_html( (string) $p['merkle_root'] ); ?></code></dd></div>
				<div><dt><?php esc_html_e( 'Leaves', 'reservechain' ); ?></dt><dd><?php echo esc_html( (string) $p['merkle_leaves'] ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Algorithm', 'reservechain' ); ?></dt><dd class="rc-mono">SHA-256 · sorted leaves · sorted pairs</dd></div>
			</dl>
			<p class="rc-fine"><?php esc_html_e( 'The record fingerprint commits to every public identity field. The Merkle root commits to that fingerprint and to the SHA-256 fingerprint of every linked document. If any field or document changes, the root changes. Anyone can recompute it from the JSON export.', 'reservechain' ); ?></p>
		</section>

		<aside class="rc-disclosure"><strong><?php esc_html_e( 'Important notice', 'reservechain' ); ?></strong><p><?php echo esc_html( rct_disclosure() ); ?></p><p><?php esc_html_e( 'A Digital Asset Passport records evidence about a physical asset. It is not a token, a certificate of title, a warehouse receipt or an offer of any kind.', 'reservechain' ); ?></p></aside>
	</div>
</div>
<?php
get_footer();
