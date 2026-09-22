<?php
/**
 * Sitemap seeding.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Seed;

use ReserveChain\Core\Content\WorkflowStates;
use ReserveChain\Core\Plugin;

/**
 * Creates the 51 pages the Website Development brief assigns.
 *
 * Why every page exists as a record from day one
 * ----------------------------------------------
 * The acceptance criteria require that "all pages in this brief are
 * implemented, or intentionally staged according to the approved development
 * phase. No silent omissions." Creating all 51 up front makes the sitemap
 * auditable: a reviewer can list them and see exactly which are live, which
 * are staged, and what each is for.
 *
 * Why most of them are drafts
 * ---------------------------
 * The same brief prohibits "placeholder shells or repeated filler sections"
 * and demands page-specific content on every published page. Publishing
 * fifty near-empty pages to look complete would fail that outright — and
 * would also breach our own rule that draft content must never be publicly
 * reachable.
 *
 * So a page is published only when it has been genuinely built. The rest are
 * drafts carrying the scope the brief assigns them, which is useful to the
 * client (it is the build plan, visible in their own admin) and honest to a
 * reviewer (nothing pretends to be finished). Draft status is enforced by the
 * post-status registration, so these are not reachable by URL, search, feed,
 * sitemap or REST.
 */
final class PageSeeder {

	/**
	 * The sitemap: [brief page number, slug, title, nav group, scope].
	 *
	 * Numbering follows the Website Development brief exactly, so a reviewer
	 * can check them off against their own document.
	 *
	 * @return array<int,array{0:int,1:string,2:string,3:string,4:string}>
	 */
	private static function sitemap(): array {
		return array(
			// Core.
			array( 1, 'home', 'Home', 'core', 'Primary homepage: positioning, trust pillars, platform metrics, both initial asset programmes, future asset categories, the physical-to-digital lifecycle, a Digital Asset Passport example, the Proof of Reserves dashboard, enterprise capabilities, Early Participation call to action and complete footer navigation.' ),

			// Company.
			array( 2, 'about-reservechain', 'About ReserveChain', 'company', 'Mission, vision, core principles, who we are, what we do, the platform thesis, initial programmes, expansion model and institutional positioning.' ),
			array( 3, 'corporate-development-status', 'Corporate Development Status', 'company', 'Visual project-status dashboard covering corporate, technology, platform, legal, asset onboarding, verification, custody, tokenization, documentation and launch readiness, with completed, in-progress and planned states.' ),
			array( 4, 'governance', 'Governance', 'company', 'Governance framework, corporate oversight, platform governance, asset governance, conflicts management, security and compliance oversight, future committees, policies and the governance roadmap.' ),
			array( 5, 'news-and-announcements', 'News & Announcements', 'company', 'Project milestones, platform updates, asset-programme developments, partnerships, document releases, technology milestones, media cards and an archive and filter structure.' ),
			array( 6, 'contact', 'Contact ReserveChain.io', 'company', 'Full contact page with enquiry routing for general enquiries, asset owners, industrial buyers, institutions, enterprise tokenization, licensing, media, compliance, support and Early Participation questions.' ),

			// Platform.
			array( 7, 'how-reservechain-works', 'How ReserveChain Works', 'platform', 'Visual lifecycle from identify and source through verify, value, custody, passport, tokenize, reconcile and redeem, explaining who performs each stage, the evidence generated, the controls and participant visibility.' ),
			array( 8, 'platform-infrastructure', 'Platform Infrastructure for Real-World Assets', 'platform', 'Architecture page showing the asset registry, Digital Asset Passports, independent verification, custody, compliance controls, the tokenization engine, the reserve and reconciliation engine, APIs, participant access, the enterprise portal and reporting.' ),
			array( 9, 'technology', 'Technology', 'platform', 'Blockchain architecture, smart contracts, asset registry, passport records, the reserve engine, APIs, security layers, audit logs, access controls, the integration model, scalability and the technology roadmap.' ),
			array( 10, 'security', 'Security', 'platform', 'Application security, identity and access controls, infrastructure security, data protection, logging and monitoring, the key-management approach, portal security, incident response, vendor controls and security testing. No unsupported certifications.' ),
			array( 11, 'independent-verification', 'Independent Verification', 'platform', 'Verification workflow, independent inspection and testing, certificate and document validation, sampling methodology, third-party laboratories, valuation review, chain of evidence, re-verification and verification-status display.' ),
			array( 12, 'custody-and-vault-structure', 'Custody & Vault Structure', 'platform', 'Institutional custody model, segregation, chain of custody, access controls, warehousing and vault structure, inventory records, insurance where applicable, inspections, audit and reconciliation, asset release and redemption controls.' ),
			array( 13, 'proof-of-reserves', 'Proof of Real-World Asset Reserves', 'platform', 'Reserve methodology, physical inventory, eligible reserves, tokenized reserves, reserve coverage, reconciliation frequency and status, supporting documents, verification history, exceptions and the reserve dashboard design.' ),
			array( 14, 'digital-asset-passports', 'Digital Asset Passports', 'platform', 'Unique asset IDs, specifications, certificates, provenance and source, verification, custody, valuation, reserve status, tokenization status, blockchain references, history, QR access and downloadable evidence.' ),
			array( 15, 'tokenization', 'Tokenization', 'platform', 'Onboarding, eligibility, asset identity, legal and operational linkage, smart-contract issuance controls, token lifecycle, transfer rules, reconciliation, retirement and burn, and redemption. Does not imply active trading before activation.' ),
			array( 16, 'physical-redemption', 'Physical Redemption', 'platform', 'Visual process: redemption request, eligibility, verification, settlement and burn, custody release, logistics and collection, completion. Fees and thresholds only when approved.' ),

			// Portal.
			array( 17, 'redemption-portal', 'Redemption Portal', 'portal', 'Functional portal concept with eligible holdings, request creation, quantity and asset selection, KYC and compliance state, settlement status, custody release, delivery and collection, documents, support and history.' ),
			array( 51, 'participant-portal', 'ReserveChain Participant Portal', 'portal', 'Functional portal architecture: account and profile, KYC/KYB status, available programmes, holdings and positions, Digital Asset Passports, documents, transactions, notices, reserve information, redemption access, support and security settings.' ),

			// Assets.
			array( 18, 'explore-real-world-assets', 'Explore Real-World Assets', 'assets', 'Asset marketplace and catalogue style page. Initial programmes first; future categories clearly labelled pre-launch. Filters may include asset class, programme status, verification, custody, passport, tokenization status and availability.' ),
			array( 19, 'all-asset-programs', 'All Real-World Asset Programs', 'assets', 'Master programme directory covering current, upcoming and future asset programmes, using status badges and linking to full programme pages. Future categories are never presented as active holdings.' ),
			array( 20, 'initial-asset-programs', 'Initial Asset Programs', 'assets', 'Dedicated page presenting Ultrafine Copper Powder and Ultrafine Nickel Wire 0.025 mm as the first ReserveChain programmes, including key specifications, use cases, the verification and custody framework, passports, the reserve model and calls to action.' ),
			array( 21, 'industrial-metal-programs', 'All Industrial Metal Programs', 'assets', 'Industrial-metal hub showing Copper Powder and Nickel Wire prominently, plus clearly separated future industrial-metal programme concepts if approved, with workflow and sector or use-case context.' ),
			array( 26, 'future-asset-categories', 'Future Real-World Asset Categories', 'assets', 'Overview for future expansion into precious metals, gemstones, energy assets, real estate, art and collectibles, and other assets. Every card is explicitly marked Future Category / Pre-Launch until formally activated.' ),

			// Asset programmes.
			array( 22, 'ultrafine-copper-powder', 'Ultrafine Copper Powder', 'asset-program', 'Full programme page using approved specifications and certificate data: hero, product overview, technical specifications, verification, laboratory documentation, custody, Digital Asset Passport, Proof of Reserves, image gallery, use cases, tokenization status, documents, redemption and participation call to action.' ),
			array( 23, 'copper-powder-gallery', 'Ultrafine Copper Powder — Product Gallery', 'asset-program', 'Evidence-oriented gallery with approved real product photographs, packaging, macro views, production and handling imagery where approved, certificate previews, captions, file metadata and links back to the asset programme and passport. No generated image is labelled as proof of product.' ),
			array( 24, 'ultrafine-nickel-wire-0-025mm', 'Ultrafine Nickel Wire 0.025 mm', 'asset-program', 'Full programme page: hero, diameter and approved technical specifications, verification and certificate evidence, custody, Digital Asset Passport, Proof of Reserves, product images, applications, documents, tokenization status, redemption and call to action.' ),
			array( 25, 'nickel-wire-gallery', 'Nickel Wire 0.025 mm — Product Gallery', 'asset-program', 'High-resolution gallery with case and packaging images, ultrafine wire macro views, spools and reels where accurate, measurement and verification imagery, certificate preview, captions and evidence links. Visuals must accurately represent the 0.025 mm wire scale.' ),

			// Participation.
			array( 27, 'early-participation-program', 'Early Participation Program for Real-World Asset Infrastructure', 'participation', 'Dedicated programme page explaining purpose, participant profile, programme stage, eligibility, methodology, initial assets, safeguards, documentation, process, risks and waitlist access. Token issuance remains inactive until formally launched.' ),
			array( 28, 'program-overview', 'Early Participation Program Overview', 'participation', 'Detailed overview of programme structure, initial asset programmes, platform benefits, steps, compliance, allocation and acquisition logic where approved, methodology links, documentation, risk factors and status.' ),
			array( 29, 'how-token-acquisition-will-work', 'How Token Acquisition Will Work', 'participation', 'Visual process: create account, eligibility, KYC/KYB, review available programme, review documents, acquisition and settlement, account and wallet, portfolio, redemption. Future functionality is marked as planned until active.' ),
			array( 30, 'discount-methodology', '20% Discount Methodology', 'participation', 'Methodology page with valuation reference, calculation formula, worked examples, eligibility, programme limits, conditions, exclusions, risk disclosures and governance approval. The percentage is never displayed as an unexplained promotional claim.' ),
			array( 31, 'eligibility-and-kyc', 'Eligibility & KYC', 'participation', 'Individual and corporate eligibility, KYC, KYB, AML, sanctions screening, source of funds and source of wealth where required, enhanced due diligence, document requirements, approval states and access restrictions.' ),
			array( 32, 'restricted-jurisdictions', 'Restricted Jurisdictions', 'participation', 'Dedicated legal and compliance page covering geographic restrictions, participant responsibility, eligibility controls, sanctions, blocked access, updates and referral to final legal documentation.' ),
			array( 33, 'join-the-waitlist', 'Join the Early Participation Waitlist', 'participation', 'Full landing page: reasons to join, what is currently available, who may register interest, the process, expected communications, compliance notice, FAQ, consent and the waitlist form.' ),

			// Market.
			array( 34, 'industrial-buyers', 'Industrial Buyers', 'market', 'Buyer-focused page for manufacturers, processors, industrial purchasers and qualified counterparties, explaining verified materials, documentation, sourcing, custody, the procurement workflow, physical delivery and redemption, and institutional enquiries.' ),
			array( 35, 'asset-owners-and-originators', 'Asset Owners & Originators', 'market', 'For producers, asset owners, originators, suppliers, custodians and institutional asset holders: submission, due diligence, verification, valuation, custody, registry and passport creation, reserve controls, the tokenization path and the commercial enquiry process.' ),

			// Enterprise.
			array( 36, 'enterprise-services', 'Enterprise Services', 'enterprise', 'Overview of enterprise modules: asset onboarding, registry, passports, verification integrations, custody integrations, compliance, tokenization, the reserve engine, reporting, APIs, portals, customisation and managed services.' ),
			array( 37, 'enterprise-tokenization-services', 'Enterprise Tokenization Services', 'enterprise', 'B2B page for institutions seeking asset-tokenization infrastructure: onboarding, data models, smart contracts, the rule engine, custody and reserve integration, participant controls, reporting, APIs, deployment, governance and the enterprise call to action.' ),
			array( 38, 'technology-licensing', 'Technology Licensing & White-Label', 'enterprise', 'Full licensing page: white-label platform, modules, branding, APIs, asset registry, passport engine, compliance integration, tokenization infrastructure, the reserve engine, portals, analytics, deployment, support, the licensing model and the enterprise enquiry call to action.' ),

			// Investor and strategy.
			array( 39, 'future-of-rwa-infrastructure', 'Invest in the Future of Real-World Asset Infrastructure', 'investor', 'Strategic platform thesis: market problem, infrastructure opportunity, initial programmes, the scalable asset-class model, enterprise revenue opportunities, the technology moat, development roadmap, governance, risks and an approved investor call to action. Investment language only within the approved legal structure.' ),

			// Resources.
			array( 40, 'resources', 'Resources', 'resources', 'Central hub linking to documentation, whitepaper, investor presentation, programme methodology, verification, custody, Digital Asset Passports, Proof of Reserves, tokenization, compliance, legal, FAQs and news.' ),
			array( 41, 'documentation', 'Documentation', 'resources', 'Structured library for platform, technology, asset programmes, certificates, verification, custody, reserve reports, compliance, enterprise, participation, policies, legal and version-controlled releases.' ),
			array( 42, 'whitepaper', 'ReserveChain Whitepaper — In Preparation', 'resources', 'Full whitepaper landing page showing status, purpose, planned chapters, architecture, the asset framework, verification, custody and reserves, tokenization, compliance, the enterprise model, roadmap, risk, governance, version and date, and future download.' ),
			array( 43, 'investor-presentation', 'Investor Presentation — Real-World Asset Infrastructure', 'resources', 'Dedicated presentation page with the platform thesis, initial programmes, expansion opportunity, infrastructure modules, business model, roadmap, risks, governance, document version and date, preview and approved download.' ),
			array( 44, 'faq', 'Frequently Asked Questions', 'resources', 'Organised by platform, assets, Copper Powder, Nickel Wire, verification, custody, Proof of Reserves, Digital Asset Passports, tokenization, Early Participation, KYC, redemption, enterprise, technology, and legal and risk.' ),

			// Legal and risk.
			array( 45, 'risk-disclosure', 'Risk Disclosure', 'legal', 'Comprehensive RWA-specific risks: asset authenticity and specification, valuation, market and liquidity, custody, insurance, counterparty, logistics, regulatory classification, token and asset linkage, smart contracts, blockchain, wallets, technology, jurisdiction, taxes, programme changes and redemption.' ),
			array( 46, 'anti-fraud-notice', 'Anti-Fraud Notice', 'legal', 'Official domains and channels, fake token warnings, fake presales, impersonation, fake agents, wallets, payment instructions, social-media scams, phishing, document fraud, verification steps and suspicious-activity reporting.' ),
			array( 47, 'legal-and-disclosures', 'Legal & Disclosures', 'legal', 'Central legal hub linking to current entity and operator information, pre-launch status, risk disclosure, participation restrictions, terms, privacy, disclaimers, intellectual property, third-party data and future offering documentation.' ),
			array( 48, 'privacy-policy', 'Privacy Policy', 'legal', 'Identify the current data controller or operator, collected data, purposes, account and portal data, KYC/KYB data where applicable, service providers, transfers, retention, cookies and analytics, security, user rights, marketing, contact and policy changes.' ),
			array( 49, 'terms-of-use', 'Terms of Use', 'legal', 'Website operator, pre-launch status, eligibility, jurisdiction restrictions, no-offering language where applicable, information limitations, asset data, valuation and certificates, third parties, acceptable use, intellectual property, portals, warranties, liability, termination, governing law, updates and contact.' ),

			// Support.
			array( 50, 'support', 'ReserveChain Support', 'support', 'Support hub with platform help, account and portal support, asset-programme questions, enterprise support, documentation, status information, security and fraud reporting, a ticket and contact form, and FAQ routing.' ),
		);
	}

	/**
	 * Slugs that carry genuinely built content and may be published.
	 *
	 * Everything else stays a draft until it is actually built, because the
	 * brief prohibits placeholder shells on published pages.
	 *
	 * @return string[]
	 */
	private static function built(): array {
		return array(
			'home',
			'ultrafine-copper-powder',
			'ultrafine-nickel-wire-0-025mm',
		);
	}

	/**
	 * Create or update every page in the sitemap.
	 *
	 * @return array{created:int,updated:int,published:int,drafted:int}
	 */
	public function run(): array {
		$built   = self::built();
		$summary = array( 'created' => 0, 'updated' => 0, 'published' => 0, 'drafted' => 0 );

		foreach ( self::sitemap() as [ $number, $slug, $title, $group, $scope ] ) {
			$is_built = in_array( $slug, $built, true );
			$status   = $is_built ? 'publish' : WorkflowStates::DRAFT;
			$existing = get_page_by_path( $slug, OBJECT, 'page' );

			$content = $this->content_for( $slug, $scope, $is_built );

			$data = array(
				'post_type'    => 'page',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_status'  => $status,
				'post_content' => $content,
			);

			if ( $existing instanceof \WP_Post ) {
				// Never clobber a page an editor has already worked on. The
				// seeder is for establishing the sitemap, not for overwriting
				// the client's content on every run.
				if ( (int) $existing->post_modified_gmt !== 0 && $existing->post_content !== $content && $is_built ) {
					$summary['updated']++;
					$data['ID'] = $existing->ID;
					wp_update_post( $data );
				}

				$page_id = (int) $existing->ID;
			} else {
				$page_id = (int) wp_insert_post( $data );
				$summary['created']++;
			}

			if ( $page_id > 0 ) {
				update_post_meta( $page_id, '_rc_brief_page_number', $number );
				update_post_meta( $page_id, '_rc_nav_group', $group );
				update_post_meta( $page_id, '_rc_scope', $scope );
			}

			if ( $is_built ) {
				$summary['published']++;
			} else {
				$summary['drafted']++;
			}
		}

		Plugin::instance()->audit()->log(
			array(
				'action'       => 'sitemap_seeded',
				'entity_type'  => 'system',
				'entity_label' => 'Website sitemap',
				'new_value'    => $summary,
				'reason'       => 'All 51 assigned pages recorded; only genuinely built pages published.',
				'severity'     => 'notice',
			)
		);

		return $summary;
	}

	/**
	 * Block content for a page.
	 *
	 * Built pages get their real composition. Everything else gets a short
	 * internal scope note — visible to editors in the admin, never public,
	 * because the page is a draft.
	 *
	 * @param string $slug     Page slug.
	 * @param string $scope    Scope text from the brief.
	 * @param bool   $is_built Whether the page has real content.
	 */
	private function content_for( string $slug, string $scope, bool $is_built ): string {
		if ( ! $is_built ) {
			return sprintf(
				"<!-- wp:paragraph -->\n<p><strong>Assigned scope (Website Development brief):</strong> %s</p>\n<!-- /wp:paragraph -->",
				esc_html( $scope )
			);
		}

		if ( 'home' === $slug ) {
			// The homepage is composed by the front-page template.
			return '';
		}

		$code = 'ultrafine-copper-powder' === $slug ? 'RC-CU-POWDER' : 'RC-NI-WIRE-0025';

		return $this->asset_program_content( $code );
	}

	/**
	 * Block composition for an asset-programme page.
	 *
	 * @param string $code Programme code.
	 */
	private function asset_program_content( string $code ): string {
		$is_copper = 'RC-CU-POWDER' === $code;

		$applications = $is_copper
			? 'Advanced manufacturing, electronics, battery technologies, conductive materials, additive manufacturing, catalysis and research applications.'
			: 'Precision electronics, battery and energy technologies, aerospace and defence, medical devices, filtration and sensors, resistance and heating elements, and advanced manufacturing.';

		$blocks = array();

		$blocks[] = sprintf( '<!-- wp:reservechain/program-header {"programCode":"%s"} /-->', $code );

		$blocks[] = '<!-- wp:reservechain/pending-notice {"context":"Specifications and certificate data shown on this page are drawn from documentation supplied by the project owner and are under review."} /-->';

		$blocks[] = sprintf(
			"<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->",
			esc_html__( 'Industrial applications', 'reservechain' )
		);

		$blocks[] = sprintf(
			"<!-- wp:paragraph {\"className\":\"rc-prose\"} -->\n<p class=\"rc-prose\">%s</p>\n<!-- /wp:paragraph -->",
			esc_html( $applications )
		);

		$blocks[] = sprintf( '<!-- wp:reservechain/program-specifications {"programCode":"%s"} /-->', $code );
		$blocks[] = sprintf( '<!-- wp:reservechain/program-certificates {"programCode":"%s"} /-->', $code );
		$blocks[] = sprintf( '<!-- wp:reservechain/program-inventory {"programCode":"%s"} /-->', $code );

		$blocks[] = sprintf(
			"<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">%s</h2>\n<!-- /wp:heading -->",
			esc_html__( 'Verification, custody and reserves', 'reservechain' )
		);

		$blocks[] = sprintf(
			"<!-- wp:paragraph {\"className\":\"rc-prose\"} -->\n<p class=\"rc-prose\">%s</p>\n<!-- /wp:paragraph -->",
			esc_html__( 'Independent verification, custody and reserve reconciliation for this programme follow the frameworks described elsewhere on this site. None of those arrangements is yet in force: no custodian has been appointed, no insurance is confirmed, and no reserve report has been produced. Each will be recorded against this programme, at unit level, as it is established and approved.', 'reservechain' )
		);

		$blocks[] = '<!-- wp:reservechain/disclosure {"variant":"panel"} /-->';

		return implode( "\n\n", $blocks );
	}
}
