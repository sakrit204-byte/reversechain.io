<?php
/**
 * Title: Home — proposed model and tokenization workflow
 * Slug: reservechain/home-model
 * Categories: reservechain
 * Description: The seven-stage proposed operating model and the physical-to-digital chain of trust.
 * Inserter: yes
 *
 * @package ReserveChain\Theme
 *
 * Two sections the brief requires on the homepage: an explanation of the
 * proposed model (section 8 of the Master Instructions) and the industrial
 * metals tokenization workflow.
 *
 * The chain runs PHYSICAL ASSET -> VERIFY -> VALUE -> CUSTODY -> PASSPORT ->
 * TOKENIZE -> RECONCILE -> PARTICIPATE -> REDEEM, which is the sequence the
 * Website brief names as the one coherent chain of trust the site must
 * present. Each stage says who performs it and what evidence it produces,
 * because a diagram that only names stages tells a reader nothing about
 * whether to believe any of it.
 *
 * Every verb is conditional. "is intended to", "may be issued", "will be
 * governed" — none of this has happened yet.
 */

?>
<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--model","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--model has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"level":2,"fontSize":"2xl"} -->
	<h2 class="wp-block-heading has-2-xl-font-size">How the Proposed ReserveChain Model Will Work</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color">The model below describes the framework ReserveChain intends to operate once the corporate, legal, custody and verification arrangements are complete. No stage is currently live.</p>
	<!-- /wp:paragraph -->

	<!-- wp:spacer {"height":"var:preset|spacing|60"} -->
	<div style="height:var(--wp--preset--spacing--60)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:group {"align":"wide","className":"rc-stages","layout":{"type":"grid","minimumColumnWidth":"20rem"}} -->
	<div class="wp-block-group alignwide rc-stages">

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">01</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Asset Program Selection</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">ReserveChain intends to evaluate industrial-metal programmes against approved purity, ownership, documentation, valuation, storage, marketability and redemption criteria.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">02</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Independent Material Verification</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">Eligible material is intended to undergo appropriate laboratory testing, assay, inspection and documentary verification. Sampling is recorded against the individual units tested.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">03</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Ownership and Custody Documentation</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">Approved material is intended to be recorded through ownership documentation, warehouse or custody records, inventory controls and appropriate insurance arrangements.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">04</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Digital Asset Passport</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">Each approved lot, batch, container or coil is intended to receive a structured digital record connecting the physical material with its laboratory, ownership, valuation, custody and reserve documentation.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">05</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Tokenization</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">ERC-20 tokens or asset-programme token series may be issued under the final legal, commercial and technical structure. No token has been issued and no contract address has been published.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">06</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Administration and Reserve Reconciliation</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">The platform is intended to reconcile eligible physical inventory with issued and circulating tokens through controlled records and periodic verification, reporting differences rather than adjusting them away.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-stage","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-stage">
			<!-- wp:paragraph {"className":"rc-stage__index","fontSize":"xs","textColor":"gold"} -->
			<p class="rc-stage__index has-gold-color has-text-color has-xs-font-size">07</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":3,"fontSize":"md"} -->
			<h3 class="wp-block-heading has-md-font-size">Transfer and Redemption</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">Any transferability, secondary-market participation or physical redemption will be governed exclusively by the final token terms, eligibility rules, operational requirements and legal documentation.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

	<!-- wp:spacer {"height":"var:preset|spacing|80"} -->
	<div style="height:var(--wp--preset--spacing--80)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:heading {"level":2,"fontSize":"xl"} -->
	<h2 class="wp-block-heading has-xl-font-size">From physical asset to digital ownership infrastructure</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","fontSize":"sm","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color has-sm-font-size">The proposed lifecycle, end to end. Each stage is intended to produce evidence that the next stage depends on.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<ol class="rc-chain" data-rc-scene="chain">
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Physical asset</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Verify</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Value</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Custody</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Digital Asset Passport</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Tokenize</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Reconcile</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Participate</span></li>
		<li class="rc-chain__link"><span class="rc-chain__dot" aria-hidden="true"></span><span class="rc-chain__label">Redeem</span></li>
	</ol>
	<!-- /wp:html -->

	<!-- wp:paragraph {"className":"rc-prose","fontSize":"xs","textColor":"contrast-subtle"} -->
	<p class="rc-prose has-contrast-subtle-color has-text-color has-xs-font-size">The physical asset creates the economic foundation. Evidence, custody, reserve reconciliation and transparent infrastructure are intended to create trust. Each element remains subject to final legal, contractual, technical and operational confirmation.</p>
	<!-- /wp:paragraph -->

</section>
<!-- /wp:group -->
