<?php
/**
 * Title: Home — initial asset programmes
 * Slug: reservechain/home-programs
 * Categories: reservechain
 * Description: Copper Powder and Nickel Wire introductions, the illustrative asset template and live registry counts.
 * Inserter: yes
 *
 * @package ReserveChain\Theme
 *
 * Homepage sections 5, 6 and 7: the two initial programmes and the
 * illustrative asset presentation.
 *
 * The programme cards are the reservechain/program-card block, which reads
 * the registry and runs every status through the publication gate. Both
 * programmes currently sit in `under_review`, so the cards render with the
 * illustrative marker and every status badge reads "Pending" or "Under
 * review" — not because the template says so, but because the gate refuses to
 * make a claim the evidence does not yet support. When the owner approves the
 * records, the same markup starts showing real statuses with no template
 * change.
 */

?>
<!-- wp:group {"tagName":"section","align":"full","className":"rc-section rc-section--programs","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<section class="wp-block-group alignfull rc-section rc-section--programs" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"level":2,"fontSize":"2xl"} -->
	<h2 class="wp-block-heading has-2-xl-font-size">Initial Industrial Metal Programmes</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"className":"rc-prose","textColor":"contrast-muted"} -->
	<p class="rc-prose has-contrast-muted-color has-text-color">Two proposed programmes form the first phase of ReserveChain's asset framework. The architecture is designed to extend to further approved real-world asset programmes without rebuilding the platform.</p>
	<!-- /wp:paragraph -->

	<!-- wp:spacer {"height":"var:preset|spacing|60"} -->
	<div style="height:var(--wp--preset--spacing--60)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:group {"align":"wide","className":"rc-program-grid","layout":{"type":"grid","minimumColumnWidth":"26rem"}} -->
	<div class="wp-block-group alignwide rc-program-grid">

		<!-- wp:group {"className":"rc-program-block rc-reveal","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-program-block rc-reveal">
			<!-- wp:reservechain/program-card {"programCode":"RC-CU-POWDER","accent":"copper"} /-->

			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">A high-purity copper powder proposed for advanced manufacturing, electronics, battery and additive-manufacturing applications. Laboratory documentation has been supplied and is under review; it has not been approved for publication.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline","fontSize":"sm"} -->
				<div class="wp-block-button is-style-outline has-custom-font-size has-sm-font-size"><a class="wp-block-button__link wp-element-button" href="/ultrafine-copper-powder/">View Copper Programme</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->

		<!-- wp:group {"className":"rc-program-block rc-reveal","layout":{"type":"constrained"}} -->
		<div class="wp-block-group rc-program-block rc-reveal">
			<!-- wp:reservechain/program-card {"programCode":"RC-NI-WIRE-0025","accent":"nickel"} /-->

			<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted"} -->
			<p class="has-contrast-muted-color has-text-color has-sm-font-size">An ultrafine nickel wire proposed for electronics, precision engineering and high-specification industrial applications. Laboratory documentation has been supplied and is under review; it has not been approved for publication.</p>
			<!-- /wp:paragraph -->

			<!-- wp:buttons -->
			<div class="wp-block-buttons">
				<!-- wp:button {"className":"is-style-outline","fontSize":"sm"} -->
				<div class="wp-block-button is-style-outline has-custom-font-size has-sm-font-size"><a class="wp-block-button__link wp-element-button" href="/ultrafine-nickel-wire-0-025mm/">View Nickel Programme</a></div>
				<!-- /wp:button -->
			</div>
			<!-- /wp:buttons -->
		</div>
		<!-- /wp:group -->

	</div>
	<!-- /wp:group -->

	<!-- wp:spacer {"height":"var:preset|spacing|70"} -->
	<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	
	<!-- wp:group {"align":"wide","className":"rc-illustrative","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group alignwide rc-illustrative" style="padding-top:var(--wp--preset--spacing--50);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">

		<!-- wp:html -->
		<p class="rc-illustrative__label">Illustrative — demo data</p>
		<!-- /wp:html -->

		<!-- wp:heading {"level":3,"fontSize":"lg"} -->
		<h3 class="wp-block-heading has-lg-font-size">Illustrative Industrial Metal Asset Template</h3>
		<!-- /wp:heading -->

		<!-- wp:paragraph {"fontSize":"sm","textColor":"contrast-muted","className":"rc-prose"} -->
		<p class="rc-prose has-contrast-muted-color has-text-color has-sm-font-size">This presentation demonstrates the future format of a ReserveChain industrial-metal asset page. No verified material, ownership document, laboratory report, valuation, custody arrangement, reserve claim or token is represented by this placeholder.</p>
		<!-- /wp:paragraph -->

		<!-- wp:html -->
		<div class="rc-table-scroll">
			<table>
				<caption class="screen-reader-text">Illustrative asset record showing the fields a published asset page will carry</caption>
				<thead>
					<tr><th scope="col">Field</th><th scope="col">Status</th></tr>
				</thead>
				<tbody>
					<tr><th scope="row">Material</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Asset programme</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Purity</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Lot or batch</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Net weight</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Laboratory verification</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Ownership verification</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Valuation</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Custody status</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Reserve status</th><td><span class="rc-badge rc-badge--pending">Pending</span></td></tr>
					<tr><th scope="row">Tokenization status</th><td><span class="rc-badge rc-badge--pending">Not issued</span></td></tr>
					<tr><th scope="row">Availability</th><td><span class="rc-badge rc-badge--pending">Not offered for sale</span></td></tr>
					<tr><th scope="row">Imagery</th><td>Verified industrial-asset photography pending.</td></tr>
				</tbody>
			</table>
		</div>
		<!-- /wp:html -->

		<!-- wp:reservechain/pending-notice /-->

	</div>
	<!-- /wp:group -->

	<!-- wp:spacer {"height":"var:preset|spacing|70"} -->
	<div style="height:var(--wp--preset--spacing--70)" aria-hidden="true" class="wp-block-spacer"></div>
	<!-- /wp:spacer -->

	<!-- wp:reservechain/registry-summary {"heading":"Registry at a glance","align":"wide"} /-->

</section>
<!-- /wp:group -->
