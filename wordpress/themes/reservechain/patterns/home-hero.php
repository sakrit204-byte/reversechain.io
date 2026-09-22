<?php
/**
 * Title: Home — institutional hero
 * Slug: reservechain/home-hero
 * Categories: reservechain
 * Description: Hero using the approved homepage copy, with the corporate-status line, primary calls to action and the proposed trust bar.
 * Inserter: yes
 *
 * @package ReserveChain\Theme
 *
 * Copy here is the wording approved in section 7 of the Master Developer
 * Instructions. It is reproduced exactly — "intended to", "proposed",
 * "in development" — because the pre-launch language rules turn on those
 * words. Editors can change it, and the language linter checks what they
 * write, but the shipped default is the approved text.
 *
 * The 3D scene mounts into .rc-hero__canvas. The markup below renders and
 * reads correctly with no JavaScript at all: the canvas is decorative,
 * aria-hidden, sits behind the content, and is never initialised when the
 * visitor prefers reduced motion or the device reports low capability.
 */

?>
<!-- wp:group {"tagName":"section","align":"full","className":"rc-hero","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-hero" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">

	<!-- wp:html -->
	<div class="rc-hero__canvas" data-rc-scene="hero" aria-hidden="true"></div>
	<!-- /wp:html -->

	<!-- wp:group {"className":"rc-hero__content","layout":{"type":"constrained"}} -->
	<div class="wp-block-group rc-hero__content">

		<!-- wp:paragraph {"className":"rc-hero__eyebrow","fontSize":"xs","textColor":"gold"} -->
		<p class="rc-hero__eyebrow has-gold-color has-text-color has-xs-font-size">Swiss corporate and issuance structure in development</p>
		<!-- /wp:paragraph -->

		<!-- wp:heading {"level":1,"className":"rc-hero__headline","fontSize":"4xl"} -->
		<h1 class="wp-block-heading rc-hero__headline has-4-xl-font-size">Building the Infrastructure for Tokenized Industrial Metals</h1>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"className":"rc-hero__subhead rc-prose","fontSize":"md","textColor":"contrast-muted"} -->
		<p class="rc-hero__subhead rc-prose has-contrast-muted-color has-text-color has-md-font-size">ReserveChain is developing an institutional platform intended to connect documented industrial-metal assets with digital tokenization, verification, custody and physical-redemption infrastructure. The corporate, legal, custody, asset-verification and technical arrangements are currently being finalized.</p>
		<!-- /wp:paragraph -->

		<!-- wp:buttons {"className":"rc-hero__actions","layout":{"type":"flex","flexWrap":"wrap"}} -->
		<div class="wp-block-buttons rc-hero__actions">
			<!-- wp:button {"backgroundColor":"gold","textColor":"base","className":"is-style-fill"} -->
			<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-base-color has-gold-background-color has-text-color has-background wp-element-button" href="/join-the-waitlist/">Join the Project Waitlist</a></div>
			<!-- /wp:button -->

			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/how-reservechain-works/">Explore the Proposed Model</a></div>
			<!-- /wp:button -->

			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/industrial-metal-programs/">View Industrial Metal Programs</a></div>
			<!-- /wp:button -->

			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/enterprise-services/">Request Enterprise Information</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->

		<!-- wp:paragraph {"className":"rc-hero__microcopy rc-prose","fontSize":"sm","textColor":"contrast-subtle"} -->
		<p class="rc-hero__microcopy rc-prose has-contrast-subtle-color has-text-color has-sm-font-size">Receive project-development updates and future eligibility information. Registration does not constitute an investment, token purchase or reservation of industrial metals.</p>
		<!-- /wp:paragraph -->

	</div>
	<!-- /wp:group -->

	<!-- wp:spacer {"height":"var:preset|spacing|70"} -->
	<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	
	<!-- wp:group {"className":"rc-trustbar","align":"wide","layout":{"type":"grid","minimumColumnWidth":"15rem"}} -->
	<div class="wp-block-group alignwide rc-trustbar">

		<!-- wp:group {"className":"rc-trustbar__item","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-trustbar__item">
			<!-- wp:heading {"level":3,"fontSize":"sm"} -->
			<h3 class="wp-block-heading has-sm-font-size">Industrial Asset Verification Framework</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"xs","textColor":"contrast-subtle"} -->
			<p class="has-contrast-subtle-color has-text-color has-xs-font-size">Proposed independent testing, assay and documentary verification. Subject to final arrangements.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-trustbar__item","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-trustbar__item">
			<!-- wp:heading {"level":3,"fontSize":"sm"} -->
			<h3 class="wp-block-heading has-sm-font-size">Custody Structure in Development</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"xs","textColor":"contrast-subtle"} -->
			<p class="has-contrast-subtle-color has-text-color has-xs-font-size">Proposed warehousing, segregation and inventory controls. Subject to final custody arrangements.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-trustbar__item","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-trustbar__item">
			<!-- wp:heading {"level":3,"fontSize":"sm"} -->
			<h3 class="wp-block-heading has-sm-font-size">Reserve Reconciliation Architecture</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"xs","textColor":"contrast-subtle"} -->
			<p class="has-contrast-subtle-color has-text-color has-xs-font-size">Planned reconciliation between physical inventory records and issued tokens. Not yet operational.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-trustbar__item","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-trustbar__item">
			<!-- wp:heading {"level":3,"fontSize":"sm"} -->
			<h3 class="wp-block-heading has-sm-font-size">Planned ERC-20 Infrastructure</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"fontSize":"xs","textColor":"contrast-subtle"} -->
			<p class="has-contrast-subtle-color has-text-color has-xs-font-size">Proposed token architecture on Ethereum. No token has been issued and no contract address has been published.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
