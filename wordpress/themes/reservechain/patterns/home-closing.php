<?php
/**
 * Title: Home — participation, risks, documentation and contact
 * Slug: reservechain/home-closing
 * Categories: reservechain
 * Description: Enterprise services, asset originators, risks, documentation centre, FAQ, waitlist, development status, contact and the anti-fraud notice.
 * Inserter: yes
 *
 * @package ReserveChain\Theme
 *
 * Homepage sections 14 to 22.
 *
 * Two things here are load-bearing rather than decorative.
 *
 * The risks section comes before the waitlist, not after it. A reader should
 * meet the limitations of a pre-launch project before they are asked to
 * register interest, and burying risk beneath a conversion form is exactly the
 * pattern the brief's prohibitions are written against.
 *
 * The anti-fraud notice is on the homepage rather than only on its own page,
 * because impersonation of pre-launch token projects targets people at first
 * contact. Someone who never reaches an interior page is the person most
 * likely to be defrauded.
 */

?>
<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--participation","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--participation" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--70)">

	<!-- wp:heading {"level":2,"fontSize":"2xl"} -->
	<h2 class="wp-block-heading has-2-xl-font-size">Working With ReserveChain</h2>
	<!-- /wp:heading -->

	<!-- wp:group {"align":"wide","layout":{"type":"grid","minimumColumnWidth":"24rem"}} -->
	<div class="wp-block-group alignwide">

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Enterprise Tokenization Services</h3>
			<p class="rc-framework__body">For institutions seeking real-world asset tokenization infrastructure: asset onboarding, data models, smart contracts, a compliance rule engine, custody and reserve integration, participant controls, reporting, APIs, portals, deployment and governance. Technology licensing and white-label delivery are part of the proposed model.</p>
			<p class="rc-framework__cta"><a href="/enterprise-services/">Request enterprise information</a></p>
		</article>
		<!-- /wp:html -->

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Asset Owners and Originators</h3>
			<p class="rc-framework__body">For producers, asset owners, suppliers, custodians and institutional asset holders: submission, due diligence, verification, valuation, custody, registry and passport creation, reserve controls, the tokenization path and the commercial enquiry process.</p>
			<p class="rc-framework__cta"><a href="/asset-owners-and-originators/">Submit an asset for assessment</a></p>
		</article>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--risks","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"backgroundColor":"surface","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--risks has-surface-background-color has-background" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">

	<!-- wp:heading {"level":2,"fontSize":"xl"} -->
	<h2 class="wp-block-heading has-xl-font-size">Risks and Limitations</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color">ReserveChain is a project in development. The following limitations apply today and are stated before any invitation to register interest.</p>
	<!-- /wp:paragraph -->

	<!-- wp:html -->
	<ul class="rc-risks rc-prose">
		<li>The corporate and issuance structure is not complete, and no offering documentation exists.</li>
		<li>No token has been issued, offered or sold. No contract address has been published.</li>
		<li>No custody, insurance, Proof of Reserves, liquidity, redemption right, ownership right or return is confirmed.</li>
		<li>Laboratory documentation supplied to date is under review and has not been approved for publication.</li>
		<li>Quantities described in supplied documentation are stated by the supplier and have not been independently verified.</li>
		<li>Permitted jurisdictions, eligibility rules and investor classification requirements have not been determined.</li>
		<li>ReserveChain does not currently intend to offer tokens to residents or persons located in the European Union or European Economic Area.</li>
		<li>Registering interest creates no entitlement of any kind, and confers no priority, allocation or price.</li>
	</ul>
	<!-- /wp:html -->

	<!-- wp:paragraph {"fontSize":"sm"} -->
	<p class="has-sm-font-size"><a href="/risk-disclosure/">Read the full risk disclosure</a></p>
	<!-- /wp:paragraph -->

</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--resources","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--resources" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">

	<!-- wp:group {"align":"wide","layout":{"type":"grid","minimumColumnWidth":"20rem"}} -->
	<div class="wp-block-group alignwide">

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Documentation Centre</h3>
			<p class="rc-framework__body">Platform, technology, asset programme, certificate, verification, custody, reserve, compliance and legal documents, released under version control as they are approved.</p>
			<p class="rc-framework__status">
				<span class="rc-badge rc-badge--review">In preparation</span>
				<span>The ReserveChain whitepaper will be published following completion of the corporate and issuance structure, legal documentation, industrial-asset verification, custody and insurance arrangements, reserve reconciliation and technical implementation.</span>
			</p>
			<p class="rc-framework__cta"><a href="/documentation/">Visit the documentation centre</a></p>
		</article>
		<!-- /wp:html -->

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Frequently Asked Questions</h3>
			<p class="rc-framework__body">Questions about the platform, the asset programmes, verification, custody, Proof of Reserves, Digital Asset Passports, tokenization, eligibility, redemption, enterprise services, technology, and legal and risk matters.</p>
			<p class="rc-framework__cta"><a href="/faq/">Read the FAQ</a></p>
		</article>
		<!-- /wp:html -->

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Corporate Development Status</h3>
			<p class="rc-framework__body">A transparent view of what is complete, what is in progress and what is planned across corporate, technology, platform, legal, asset onboarding, verification, custody, tokenization, documentation and launch readiness.</p>
			<p class="rc-framework__cta"><a href="/corporate-development-status/">View development status</a></p>
		</article>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--waitlist","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"backgroundColor":"surface-raised","layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--waitlist has-surface-raised-background-color has-background" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"level":2,"fontSize":"2xl"} -->
	<h2 class="wp-block-heading has-2-xl-font-size">Join the Project Waitlist</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color">Receive project-development updates and future eligibility information. Registration does not constitute an investment, token purchase or reservation of industrial metals, and creates no entitlement to participate in any future offering.</p>
	<!-- /wp:paragraph -->

	<!-- wp:paragraph {"className":"rc-prose","fontSize":"sm","textColor":"contrast-subtle"} -->
	<p class="rc-prose has-contrast-subtle-color has-text-color has-sm-font-size">No funds, payment details, wallet addresses or token reservations are accepted. Your email address must be verified before a registration is treated as confirmed.</p>
	<!-- /wp:paragraph -->

	<!-- wp:buttons -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"gold","textColor":"base"} -->
		<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-gold-background-color has-text-color has-background wp-element-button" href="/join-the-waitlist/">Register your interest</a></div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

	<!-- wp:reservechain/disclosure {"variant":"panel"} /-->

</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--contact","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--contact" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">

	<!-- wp:group {"align":"wide","layout":{"type":"grid","minimumColumnWidth":"24rem"}} -->
	<div class="wp-block-group alignwide">

		<!-- wp:html -->
		<article class="rc-framework">
			<h3 class="rc-framework__title">Official Contact</h3>
			<p class="rc-framework__body">Enquiries are handled through the official ReserveChain channels only. Verified contact addresses and the official channels directory are published on the contact page.</p>
			<p class="rc-framework__status">
				<span class="rc-badge rc-badge--pending">Pending</span>
				<span>Official contact details and channel directory are owner-supplied information and will be published once confirmed.</span>
			</p>
			<p class="rc-framework__cta"><a href="/contact/">Contact ReserveChain</a></p>
		</article>
		<!-- /wp:html -->

		<!-- wp:html -->
		<article class="rc-framework rc-framework--alert">
			<h3 class="rc-framework__title">Anti-Fraud Notice</h3>
			<p class="rc-framework__body"><strong>Protect yourself. Verify official communications.</strong></p>
			<ul class="rc-risks">
				<li>ReserveChain will <strong>never</strong> ask for your password, private keys, seed phrase or recovery codes.</li>
				<li>ReserveChain will <strong>never</strong> request payment, deposits or transfers to a wallet for account activation, token sales or "risk-free" investments.</li>
				<li>No presale, token sale or allocation is open. Anyone offering one is not acting for ReserveChain.</li>
				<li>Always verify the domain before entering information, and report suspicious activity through the official contact page.</li>
			</ul>
			<p class="rc-framework__cta"><a href="/anti-fraud-notice/">Read the anti-fraud notice</a></p>
		</article>
		<!-- /wp:html -->

	</div>
	<!-- /wp:group -->

</section>
<!-- /wp:group -->
