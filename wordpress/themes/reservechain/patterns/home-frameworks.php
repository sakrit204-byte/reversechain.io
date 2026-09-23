<?php
/**
 * Title: Home — proposed frameworks
 * Slug: reservechain/home-frameworks
 * Categories: reservechain
 * Description: Verification, custody, Proof of Reserves, legal structure, ERC-20 architecture and physical redemption — each stated as proposed.
 * Inserter: yes
 *
 * @package ReserveChain\Theme
 *
 * Homepage sections 8 to 13.
 *
 * The Master Instructions are explicit that these six sections "must clearly
 * state that they describe the proposed framework and remain subject to final
 * legal, contractual, technical and operational confirmation". Rather than
 * bury that in a footnote, each card carries its own status line, so a reader
 * who skims one card still sees the qualification attached to it.
 */

$rc_frameworks = array(
	array(
		'title'  => __( 'Proposed Independent Verification', 'reservechain' ),
		'lead'   => __( 'Trust is intended to be established before an asset is tokenized.', 'reservechain' ),
		'body'   => __( 'A proposed workflow covering independent inspection and testing, certificate and document validation, sampling methodology, third-party laboratories, valuation review, chain of evidence and periodic re-verification. Verification status is intended to be displayed per asset record rather than claimed for the platform as a whole.', 'reservechain' ),
		'status' => __( 'Framework in design. No independent verification arrangement has been finalised.', 'reservechain' ),
		'link'   => '/independent-verification/',
		'cta'    => __( 'View verification process', 'reservechain' ),
	),
	array(
		'title'  => __( 'Proposed Custody and Storage', 'reservechain' ),
		'lead'   => __( 'Secure. Controlled. Auditable.', 'reservechain' ),
		'body'   => __( 'A proposed institutional custody model covering segregation, chain of custody, access controls, warehousing structure, inventory records, insurance where applicable, inspections, reconciliation and asset release. Custodian identity and agreements are owner-supplied information that has not yet been provided.', 'reservechain' ),
		'status' => __( 'No custodian has been appointed and no custody or insurance arrangement is confirmed.', 'reservechain' ),
		'link'   => '/custody-and-vault-structure/',
		'cta'    => __( 'Explore custody', 'reservechain' ),
	),
	array(
		'title'  => __( 'Proposed Proof of Reserves', 'reservechain' ),
		'lead'   => __( 'Transparent. Verifiable. Reconciled.', 'reservechain' ),
		'body'   => __( 'A proposed reserve methodology covering physical inventory, eligible reserves, tokenized reserves, reserve coverage, reconciliation frequency, supporting documents, verification history and exceptions. The platform is designed to report discrepancies rather than silently adjust balances.', 'reservechain' ),
		'status' => __( 'No reserve figure, coverage ratio or attestation has been produced or approved for publication.', 'reservechain' ),
		'link'   => '/proof-of-reserves/',
		'cta'    => __( 'View reserve framework', 'reservechain' ),
	),
	array(
		'title'  => __( 'Proposed Legal and Asset-Holding Structure', 'reservechain' ),
		'lead'   => __( 'Structure precedes issuance.', 'reservechain' ),
		'body'   => __( 'The Swiss corporate and issuance structure is in development. Final company details, legal structure, token classification, token-holder rights, offering terms and permitted jurisdictions will be supplied by the project owner and appointed advisers.', 'reservechain' ),
		'status' => __( 'Subject to final legal review. No legal conclusion is represented on this website.', 'reservechain' ),
		'link'   => '/legal-and-disclosures/',
		'cta'    => __( 'Legal and disclosures', 'reservechain' ),
	),
	array(
		'title'  => __( 'Proposed ERC-20 Tokenization Architecture', 'reservechain' ),
		'lead'   => __( 'Connecting verified physical assets to digital infrastructure.', 'reservechain' ),
		'body'   => __( 'A proposed token architecture on Ethereum covering asset onboarding, eligibility, asset identity, legal and operational linkage, smart-contract issuance controls, token lifecycle, transfer rules, reconciliation, retirement and redemption. Supply, allocations and issuance parameters remain configurable and unapproved.', 'reservechain' ),
		'status' => __( 'No token has been issued. No contract address has been published. No trading is implied.', 'reservechain' ),
		'link'   => '/tokenization/',
		'cta'    => __( 'Learn about tokenization', 'reservechain' ),
	),
	array(
		'title'  => __( 'Proposed Physical Redemption', 'reservechain' ),
		'lead'   => __( 'From digital position to physical asset delivery.', 'reservechain' ),
		'body'   => __( 'A proposed process covering redemption request, eligibility, verification, settlement and token burn, custody release, logistics and collection, and completion. Container-by-container and coil-by-coil selection is supported in the platform design.', 'reservechain' ),
		'status' => __( 'Redemption is inactive. Rights, minimum amounts, fees and delivery conditions are pending owner approval.', 'reservechain' ),
		'link'   => '/physical-redemption/',
		'cta'    => __( 'How redemption works', 'reservechain' ),
	),
);

?>
<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--frameworks","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--frameworks has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"level":2,"fontSize":"2xl"} -->
	<h2 class="wp-block-heading has-2-xl-font-size">The Proposed Platform Frameworks</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color">Each framework below describes what ReserveChain is building. Every one remains subject to final legal, contractual, technical and operational confirmation.</p>
	<!-- /wp:paragraph -->

	<!-- wp:spacer {"height":"var:preset|spacing|60"} -->
	<div style="height:var(--wp--preset--spacing--60)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:group {"align":"wide","className":"rc-framework-grid","layout":{"type":"grid","minimumColumnWidth":"22rem"}} -->
	<div class="wp-block-group alignwide rc-framework-grid">
		<?php foreach ( $rc_frameworks as $rc_item ) : ?>
		<!-- wp:html -->
		<article class="rc-framework rc-reveal">
			<h3 class="rc-framework__title"><?php echo esc_html( $rc_item['title'] ); ?></h3>
			<p class="rc-framework__lead"><?php echo esc_html( $rc_item['lead'] ); ?></p>
			<p class="rc-framework__body"><?php echo esc_html( $rc_item['body'] ); ?></p>
			<p class="rc-framework__status">
				<span class="rc-badge rc-badge--review"><?php echo esc_html__( 'Proposed', 'reservechain' ); ?></span>
				<span><?php echo esc_html( $rc_item['status'] ); ?></span>
			</p>
			<p class="rc-framework__cta">
				<a href="<?php echo esc_url( $rc_item['link'] ); ?>"><?php echo esc_html( $rc_item['cta'] ); ?></a>
			</p>
		</article>
		<!-- /wp:html -->
		<?php endforeach; ?>
	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
