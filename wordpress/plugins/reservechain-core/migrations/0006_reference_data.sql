-- =============================================================================
-- ReserveChain.io — 0006  Reference and configuration data
--
-- Reference data is part of the installed system, so it ships as a migration:
-- the website modes, the module register, the jurisdiction table and the
-- owner-input register must exist identically in every environment.
--
-- Illustrative registry content (demonstration programs, lots, passports) is
-- NOT seeded here. It is loaded by an explicit WP-CLI command, is marked
-- illustrative in the data itself, and can be removed without touching the
-- schema — because "demonstration data must be clearly marked ILLUSTRATIVE /
-- DEMO DATA and visually distinguishable from live production data" [W§8].
--
-- Every commercial value below is deliberately NULL. Token price, supply,
-- ratios, discounts, fees and thresholds are owner decisions that have not
-- been supplied, and [M§12] forbids inventing them. NULL here means
-- "pending", and the platform is required to render it as pending.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- Website modes [M§18]
--
-- Only development, pre-launch and waitlist are active. Live offering, token
-- purchase, wallet, payment, eligibility and redemption stay off and are
-- flagged high-risk, so switching one on needs a second administrator and a
-- recorded written authorisation reference.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_site_modes`
  (`mode_key`, `label`, `description`, `is_active`, `is_high_risk`, `requires_written_authorization`, `sort_order`)
VALUES
  ('development',          'Development Mode',          'Internal build. Site is not publicly reachable and every page is non-indexable.', 0, 0, 0,  1),
  ('pre_launch',           'Pre-Launch Mode',           'Institutional pre-launch platform. No tokens are offered or sold; the mandatory no-offer disclosure is displayed on the homepage, asset-program pages, waitlist and documentation area.', 1, 0, 0,  2),
  ('waitlist',             'Waitlist Mode',             'Registration of interest is open. Email verification is required before a registration counts as confirmed. No funds, payment details, wallet addresses or token reservations are accepted.', 1, 0, 0,  3),
  ('documentation_release','Documentation Release Mode','Whitepaper and investor documents become downloadable once approved for publication.', 0, 0, 1,  4),
  ('asset_verification',   'Asset Verification Mode',   'Independent verification results and Certificates of Analysis are published against registry records.', 0, 1, 1,  5),
  ('eligibility',          'Eligibility Mode',          'Jurisdiction, KYC/KYB, AML and sanctions screening become active for participant onboarding.', 0, 1, 1,  6),
  ('early_participation',  'Early Participation Mode',  'Early Participation Programme opens. Requires final legal structure and approved offering documentation.', 0, 1, 1,  7),
  ('live_offering',        'Live Offering Mode',        'Token acquisition is live. Requires written issuer authorisation following legal, asset, custody, security, operational and acceptance approvals.', 0, 1, 1,  8),
  ('redemption',           'Redemption Mode',           'Physical redemption requests are accepted. Requires approved redemption terms, custody release procedures and logistics arrangements.', 0, 1, 1,  9),
  ('enterprise_onboarding','Enterprise Onboarding Mode','Enterprise tokenization and white-label onboarding workflows are available to institutional clients.', 0, 1, 1, 10)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `description` = VALUES(`description`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Module register [M§17]
--
-- "Prepare the complete technical structure, but keep it hidden, inactive and
--  non-indexable until expressly authorized."
--
-- Every module named in §17 is listed. The registry and passport modules are
-- 'staged': their pages are required navigation areas, so they are reachable,
-- but the records inside them are illustrative and marked as such.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_module_flags`
  (`module_key`, `label`, `state`, `is_public`, `is_indexable`, `depends_on_mode`, `activation_note`)
VALUES
  -- Organisation and counterparties: named third parties may not appear
  -- without written authorisation. [M§3]
  ('team_profiles',            'Confirmed team profiles',              'built_inactive', 0, 0, NULL,                  'Publish only verified biographies supplied and approved by the project owner.'),
  ('partners',                 'Confirmed partners',                   'built_inactive', 0, 0, NULL,                  'No third-party name or logo may be displayed without written authorisation.'),
  ('laboratories',             'Laboratories',                         'built_inactive', 0, 0, NULL,                  'Requires approved laboratory details and evidence of accreditation.'),
  ('custodian',                'Custodian',                            'built_inactive', 0, 0, NULL,                  'Requires an executed custody agreement supplied by the project owner.'),
  ('warehouse_facilities',     'Warehouse facilities',                 'built_inactive', 0, 0, NULL,                  'Requires approved facility records. Do not present unverified renders as actual facilities.'),
  ('insurance_provider',       'Insurance provider',                   'built_inactive', 0, 0, NULL,                  'Requires an insurance policy document and approved coverage statement.'),
  ('advisers',                 'Legal and technical advisers',         'built_inactive', 0, 0, NULL,                  'Requires approved adviser details.'),

  -- Registry and evidence.
  ('industrial_metals_registry','Industrial Metals Registry',          'staged',         1, 1, 'pre_launch',          'Structure and workflows are live; records are illustrative until verified documents are supplied and approved.'),
  ('digital_asset_passports',  'Digital Asset Passports',              'staged',         1, 1, 'pre_launch',          'Passport format is demonstrable; sample passports are permanently marked ILLUSTRATIVE.'),
  ('proof_of_reserves',        'Proof of Industrial Metal Reserves',   'built_inactive', 0, 0, 'asset_verification',   'Publish only after inventory, documents, token supply and reconciliation inputs are approved.'),
  ('reserve_dashboard',        'Reserve reconciliation dashboard',     'built_inactive', 0, 0, 'asset_verification',   'Requires approved reserve methodology and at least one reconciled reporting period.'),
  ('blockchain_explorer_links','Blockchain explorer links',            'built_inactive', 0, 0, NULL,                  'No contract address may be published before deployment approval.'),
  ('tokenomics',               'Tokenomics',                           'built_inactive', 0, 0, NULL,                  'Requires approved supply, allocations, treasury, fees and reserve ratios.'),
  ('smart_contract_info',      'Smart-contract information',           'built_inactive', 0, 0, NULL,                  'Requires final contract addresses, verified source and completed audit.'),

  -- Compliance.
  ('kyc_kyb',                  'KYC / KYB',                            'built_inactive', 0, 0, 'eligibility',          'Requires the project owner to select a provider and approve eligibility rules.'),
  ('aml_sanctions',            'AML and sanctions screening',          'built_inactive', 0, 0, 'eligibility',          'Requires provider selection and approved screening policy.'),
  ('jurisdictional_eligibility','Jurisdictional eligibility',          'built_inactive', 0, 0, 'eligibility',          'Requires the approved permitted and restricted jurisdiction lists.'),

  -- Wallet, payment and acquisition.
  ('wallet_connection',        'Wallet connection',                    'built_inactive', 0, 0, 'live_offering',        'Wallet connection is prohibited in public pre-launch mode. [M-3]'),
  ('usdt_payments',            'USDT ERC-20 payments',                 'built_inactive', 0, 0, 'live_offering',        'No live funds may be accepted until contracts, wallet controls, KYC gating and reconciliation have passed testing and received written approval.'),
  ('token_purchase',           'Token purchase',                       'built_inactive', 0, 0, 'live_offering',        'Payment collection is prohibited in public pre-launch mode.'),

  -- Portals.
  ('investor_portal',          'Investor portal',                      'built_inactive', 0, 0, 'early_participation',  'Built and testable; staged activation after eligibility controls are approved.'),
  ('enterprise_portal',        'Enterprise client portal',             'built_inactive', 0, 0, 'enterprise_onboarding','Built and testable.'),
  ('originator_portal',        'Asset-owner / originator portal',      'built_inactive', 0, 0, 'enterprise_onboarding','Built and testable.'),
  ('token_holdings',           'Token holdings',                       'built_inactive', 0, 0, 'live_offering',        'Balances are derived from chain state; never manually editable.'),
  ('transaction_history',      'Transaction history',                  'built_inactive', 0, 0, 'live_offering',        'Populated by the blockchain indexer.'),
  ('client_documents',         'Client documents',                     'built_inactive', 0, 0, 'early_participation',  'Private data-room access is permission-controlled per participant.'),

  -- Redemption and logistics.
  ('redemption_requests',      'Physical-redemption requests',         'built_inactive', 0, 0, 'redemption',           'Requires approved redemption rights, minimum amounts, fees and delivery conditions.'),
  ('container_selection',      'Container-selection controls',         'built_inactive', 0, 0, 'redemption',           'Container-by-container redemption for the Copper Powder programme.'),
  ('coil_selection',           'Coil-selection controls',              'built_inactive', 0, 0, 'redemption',           'Coil-by-coil redemption for the Nickel Wire programme.'),
  ('logistics_workflow',       'Logistics workflow',                   'built_inactive', 0, 0, 'redemption',           'Requires approved carriers and handling procedures.'),
  ('customs_documentation',    'Customs and delivery documentation',   'built_inactive', 0, 0, 'redemption',           'Requires approved export, import and dangerous-goods handling rules.'),
  ('compliance_review',        'Compliance review',                    'built_inactive', 0, 0, 'redemption',           'Compliance is re-checked at redemption, not inherited from onboarding.'),

  -- External.
  ('dex_exchange_info',        'DEX and exchange information',         'built_inactive', 0, 0, 'live_offering',        'No listing, liquidity or market-data outcome may be represented as guaranteed.'),
  ('mobile_app_links',         'Mobile-application links',             'built_inactive', 0, 0, NULL,                  'Publish once the applications are available through TestFlight and Google Play under ReserveChain-controlled accounts.')
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `activation_note` = VALUES(`activation_note`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Platform settings
--
-- Operational defaults are set. Commercial values are NULL and flagged as
-- requiring approval, so the admin UI renders them as "Pending — owner input
-- required" and a maker-checker is needed to populate them. [M§12, MP3]
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_settings`
  (`setting_key`, `setting_group`, `value_json`, `value_type`, `label`, `description`, `requires_approval`)
VALUES
  -- Site.
  ('site.default_locale',              'site',       '"en"',            'string',  'Default language',                 'English, Spanish and Italian are supported.', 0),
  ('site.enabled_locales',             'site',       '["en","es","it"]','json',    'Enabled languages',                 'Mandatory language set for the platform.', 0),
  ('site.corporate_jurisdiction',      'site',       '"Switzerland"',   'string',  'Corporate jurisdiction',            'Per the binding developer instructions and the mandatory no-offer disclosure. Recorded as a setting because the project description separately refers to Estonia; see the clarification register.', 1),
  ('site.corporate_status',            'site',       '"in_development"','string',  'Corporate status',                  'Swiss corporate and issuance structure in development.', 1),

  -- Compliance posture.
  ('compliance.offer_to_eu_eea',       'compliance', 'false',           'boolean', 'Offer to EU/EEA residents',         'ReserveChain does not currently intend to offer tokens to residents or persons located in the EU or EEA.', 1),
  ('compliance.mica_marketing_claims', 'compliance', 'false',           'boolean', 'Permit MiCA marketing claims',      'Prohibited. The public website must not be designed around MiCA marketing claims.', 1),
  ('compliance.require_email_verification','compliance','true',         'boolean', 'Require email verification',        'A waitlist registration is not treated as confirmed until the email address is verified.', 0),
  ('compliance.collect_nationality',   'compliance', 'false',           'boolean', 'Collect nationality',               'Supported technically; activate only when legally required.', 1),
  ('compliance.collect_current_location','compliance','false',          'boolean', 'Collect current location',          'Supported technically; activate only when legally required.', 1),

  -- Token parameters — all pending owner decision. [M§12]
  ('token.name',                       'token',      NULL,              'string',  'Token name',                        'Pending. Must not be published before final authorisation.', 1),
  ('token.symbol',                     'token',      NULL,              'string',  'Token symbol',                      'Pending. Token symbols must not be published before final authorisation.', 1),
  ('token.decimals',                   'token',      '18',              'integer', 'Token decimals',                    'ERC-20 default; adjustable before deployment.', 1),
  ('token.max_supply',                 'token',      NULL,              'string',  'Maximum supply',                    'Pending. Supply must not be derived automatically from material valuation.', 1),
  ('token.chain',                      'token',      '"ethereum"',      'string',  'Primary chain',                     'Ethereum. Binance Smart Chain / BEP-20 is not accepted as the primary chain.', 1),
  ('token.reference_price',            'token',      NULL,              'decimal', 'Reference token price',             'Pending. No fixed token price may be hard-coded.', 1),
  ('token.asset_to_token_ratio',       'token',      NULL,              'string',  'Asset-to-token ratio',              'Pending. No fixed token-to-kilogram, token-to-container or token-to-coil ratio may be hard-coded.', 1),

  -- Commercial parameters — all pending.
  ('commercial.early_participation_discount_pct','commercial', NULL,    'decimal', 'Early Participation discount (%)',  'Pending. Never display a discount percentage as an unexplained promotional claim.', 1),
  ('commercial.tokenization_fee_pct',  'commercial', NULL,              'decimal', 'Tokenization and structuring fee (%)','Pending owner decision.', 1),
  ('commercial.administration_fee_pct','commercial', NULL,              'decimal', 'Recurring administration fee (%)',  'Pending owner decision.', 1),
  ('commercial.custody_allocation_pct','commercial', NULL,              'decimal', 'Custody allocation (%)',            'Pending owner decision.', 1),
  ('commercial.insurance_allocation_pct','commercial', NULL,            'decimal', 'Insurance allocation (%)',          'Pending owner decision.', 1),
  ('commercial.redemption_fee',        'commercial', NULL,              'decimal', 'Redemption processing fee',         'Pending owner decision.', 1),
  ('commercial.minimum_redemption_qty','commercial', NULL,              'decimal', 'Minimum redemption quantity',       'Pending owner decision.', 1),

  -- Reserve policy and alert thresholds. Operational, so defaults are set.
  ('reserve.target_coverage_ratio',    'reserve',    NULL,              'decimal', 'Target reserve coverage ratio',     'Pending. Reserve ratios must not be published before reconciliation.', 1),
  ('reserve.valuation_validity_days',  'reserve',    '90',              'integer', 'Valuation validity (days)',         'A valuation older than this raises a stale-valuation exception.', 0),
  ('reserve.attestation_validity_days','reserve',    '180',             'integer', 'Attestation validity (days)',       'An attestation or Certificate of Analysis older than this raises a stale-attestation exception.', 0),
  ('reserve.document_expiry_warning_days','reserve', '30',              'integer', 'Document expiry warning (days)',    'Raises an expiring-document alert this many days before expiry.', 0),

  -- Security.
  ('security.session_idle_minutes',    'security',   '30',              'integer', 'Session idle timeout (minutes)',    'Administrative sessions expire after this period of inactivity.', 0),
  ('security.session_absolute_hours',  'security',   '12',              'integer', 'Session absolute lifetime (hours)', 'Sessions expire regardless of activity.', 0),
  ('security.login_max_attempts',      'security',   '5',               'integer', 'Maximum failed logins',             'Login-attempt protection threshold.', 0),
  ('security.login_lockout_minutes',   'security',   '15',              'integer', 'Lockout duration (minutes)',        'Applied after the failed-login threshold is reached.', 0),
  ('security.require_mfa_for_admins',  'security',   'true',            'boolean', 'Require MFA for administrators',    'Multi-factor authentication is mandatory for administrative roles.', 0),
  ('security.audit_anchor_enabled',    'security',   'false',           'boolean', 'Anchor audit trail on-chain',       'Publishes a daily Merkle root of the audit chain. Enable once a testnet or authorised mainnet endpoint is configured.', 0)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `description` = VALUES(`description`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Owner-input register [Master, Part III §B]
--
-- Every input the project owner or appointed advisers must supply, with the
-- components it unblocks. Pending status never permits omitting the
-- corresponding module, field or document area.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_owner_inputs`
  (`category`, `input_key`, `required_input`, `status`, `affected_components`)
VALUES
  ('corporate',    'corporate.entity_details',      'Registered entity name, registration number, address, issuer and asset-holding structure, directors and authorised signatories.', 'pending', 'About, Corporate Development Status, Legal & Disclosures, Terms of Use, Privacy Policy, whitepaper'),
  ('corporate',    'corporate.authorised_signatories','Persons authorised to approve publication, token administration and redemption releases.',                                    'pending', 'Maker-checker approvals, token administration, redemption approval'),

  ('legal',        'legal.token_classification',    'Token classification and the legal opinion supporting it.',                                                                     'pending', 'Tokenization, Token Terms, whitepaper, Risk Disclosure'),
  ('legal',        'legal.holder_rights',           'Token-holder rights, including whether any right to physical title exists.',                                                     'pending', 'Tokenization, Redemption, whitepaper'),
  ('legal',        'legal.permitted_jurisdictions', 'Permitted and restricted jurisdiction lists and investor eligibility rules.',                                                    'pending', 'Jurisdiction controls, Restricted Jurisdictions, KYC/KYB, waitlist eligibility'),
  ('legal',        'legal.final_disclaimers',       'Final approved wording for the no-offer disclosure, risk disclosure and website terms.',                                         'pending', 'Legal notices, homepage disclosure, waitlist, documentation area'),
  ('legal',        'legal.privacy_retention',       'Privacy, data-retention and records-management instructions.',                                                                   'pending', 'Privacy Policy, waitlist retention, consent handling'),

  ('assets',       'assets.ownership_title',        'Ownership, title and acquisition documents for each lot.',                                                                      'pending', 'Asset Registry ownership records, Digital Asset Passports, Proof of Reserves'),
  ('assets',       'assets.quantities',             'Confirmed quantities, batch, container and coil identifiers, and independently verified weights.',                               'pending', 'Lots, units, reserve reporting, redemption allocation'),
  ('assets',       'assets.technical_specs',        'Approved technical specifications and product photographs for each programme.',                                                  'pending', 'Copper Powder and Nickel Wire programme pages, product galleries'),

  ('laboratory',   'laboratory.certificates',       'Certificates of Analysis, purity reports, assay and inspection reports approved for publication.',                               'pending', 'Certificates, Independent Verification, passports'),
  ('laboratory',   'laboratory.provider_details',   'Laboratory identity and evidence of accreditation.',                                                                             'pending', 'Laboratories module, Independent Verification page'),

  ('valuation',    'valuation.reports',             'Independent valuations, approved pricing, methodology and pricing benchmark.',                                                   'pending', 'Valuations, Proof of Reserves, tokenomics'),
  ('valuation',    'valuation.commercial_terms',    'Approved fees, spreads, discounts, allocations, use of funds and financial assumptions.',                                        'pending', 'Commercial settings, 20% Discount Methodology page, whitepaper'),

  ('custody',      'custody.agreements',            'Custodian identity, warehouse agreements, inventory reports and receipts.',                                                      'pending', 'Custody & Vault Structure, custody records, Proof of Reserves'),
  ('custody',      'custody.insurance',             'Insurance provider, policy documents and approved coverage statements.',                                                        'pending', 'Insurance records, Custody page'),

  ('reserves',     'reserves.methodology',          'Approved reserve methodology, reconciliation inputs, publication schedule and authorised reports.',                              'pending', 'Proof of Reserves dashboard, reserve reports, reconciliation'),
  ('reserves',     'reserves.attestation_provider', 'Independent attestation provider and engagement terms.',                                                                         'pending', 'Proof of Reserves attestation status'),

  ('brand',        'brand.official_channels',       'Official contact addresses, social channels and the anti-impersonation directory.',                                             'pending', 'Contact, Anti-Fraud Notice, Official Channels Directory, footer'),
  ('brand',        'brand.team_partners',           'Verified biographies, partner details and approved logos.',                                                                      'pending', 'About, Governance, Partners'),
  ('brand',        'brand.translations',            'Approved Spanish and Italian translations of published content.',                                                                'pending', 'All public pages in ES and IT'),

  ('launch',       'launch.deployment_authorization','Written authorisation for Ethereum Mainnet deployment and token generation.',                                                   'pending', 'Smart-contract deployment, contract address publication'),
  ('launch',       'launch.treasury_signers',       'Issuer-controlled multisig signers, approval thresholds and treasury wallet addresses.',                                         'pending', 'Wallet and key management, treasury controls'),
  ('launch',       'launch.store_accounts',         'ReserveChain-controlled Apple Developer, App Store Connect, Google Play Console and Firebase accounts.',                          'pending', 'iOS and Android release, TestFlight and Play testing'),
  ('launch',       'launch.production_infrastructure','Issuer-controlled hosting, domain, DNS, email, analytics and monitoring accounts.',                                            'pending', 'Production deployment, handover, monitoring')
ON DUPLICATE KEY UPDATE `required_input` = VALUES(`required_input`), `affected_components` = VALUES(`affected_components`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Jurisdictions
--
-- ISO 3166-1 alpha-2 countries with the EU/EEA flag set, because membership is
-- a matter of fact. Every `status` stays 'undetermined': the permitted and
-- restricted lists are owner and adviser decisions that have not been supplied,
-- and inventing them would be exactly the kind of unsupported claim the brief
-- prohibits. The control exists and is ready to be populated.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_jurisdictions` (`country_code`, `country_name`, `region`, `is_eu_eea`) VALUES
('AD','Andorra','Europe',0),('AE','United Arab Emirates','Asia',0),('AF','Afghanistan','Asia',0),('AG','Antigua and Barbuda','Americas',0),
('AI','Anguilla','Americas',0),('AL','Albania','Europe',0),('AM','Armenia','Asia',0),('AO','Angola','Africa',0),
('AR','Argentina','Americas',0),('AS','American Samoa','Oceania',0),('AT','Austria','Europe',1),('AU','Australia','Oceania',0),
('AW','Aruba','Americas',0),('AX','Aland Islands','Europe',1),('AZ','Azerbaijan','Asia',0),('BA','Bosnia and Herzegovina','Europe',0),
('BB','Barbados','Americas',0),('BD','Bangladesh','Asia',0),('BE','Belgium','Europe',1),('BF','Burkina Faso','Africa',0),
('BG','Bulgaria','Europe',1),('BH','Bahrain','Asia',0),('BI','Burundi','Africa',0),('BJ','Benin','Africa',0),
('BL','Saint Barthelemy','Americas',0),('BM','Bermuda','Americas',0),('BN','Brunei Darussalam','Asia',0),('BO','Bolivia','Americas',0),
('BQ','Bonaire, Sint Eustatius and Saba','Americas',0),('BR','Brazil','Americas',0),('BS','Bahamas','Americas',0),('BT','Bhutan','Asia',0),
('BW','Botswana','Africa',0),('BY','Belarus','Europe',0),('BZ','Belize','Americas',0),('CA','Canada','Americas',0),
('CD','Congo, Democratic Republic of the','Africa',0),('CF','Central African Republic','Africa',0),('CG','Congo','Africa',0),('CH','Switzerland','Europe',0),
('CI','Cote d Ivoire','Africa',0),('CK','Cook Islands','Oceania',0),('CL','Chile','Americas',0),('CM','Cameroon','Africa',0),
('CN','China','Asia',0),('CO','Colombia','Americas',0),('CR','Costa Rica','Americas',0),('CU','Cuba','Americas',0),
('CV','Cabo Verde','Africa',0),('CW','Curacao','Americas',0),('CY','Cyprus','Europe',1),('CZ','Czechia','Europe',1),
('DE','Germany','Europe',1),('DJ','Djibouti','Africa',0),('DK','Denmark','Europe',1),('DM','Dominica','Americas',0),
('DO','Dominican Republic','Americas',0),('DZ','Algeria','Africa',0),('EC','Ecuador','Americas',0),('EE','Estonia','Europe',1),
('EG','Egypt','Africa',0),('ER','Eritrea','Africa',0),('ES','Spain','Europe',1),('ET','Ethiopia','Africa',0),
('FI','Finland','Europe',1),('FJ','Fiji','Oceania',0),('FK','Falkland Islands','Americas',0),('FM','Micronesia','Oceania',0),
('FO','Faroe Islands','Europe',0),('FR','France','Europe',1),('GA','Gabon','Africa',0),('GB','United Kingdom','Europe',0),
('GD','Grenada','Americas',0),('GE','Georgia','Asia',0),('GG','Guernsey','Europe',0),('GH','Ghana','Africa',0),
('GI','Gibraltar','Europe',0),('GL','Greenland','Americas',0),('GM','Gambia','Africa',0),('GN','Guinea','Africa',0),
('GQ','Equatorial Guinea','Africa',0),('GR','Greece','Europe',1),('GT','Guatemala','Americas',0),('GU','Guam','Oceania',0),
('GW','Guinea-Bissau','Africa',0),('GY','Guyana','Americas',0),('HK','Hong Kong','Asia',0),('HN','Honduras','Americas',0),
('HR','Croatia','Europe',1),('HT','Haiti','Americas',0),('HU','Hungary','Europe',1),('ID','Indonesia','Asia',0),
('IE','Ireland','Europe',1),('IL','Israel','Asia',0),('IM','Isle of Man','Europe',0),('IN','India','Asia',0),
('IQ','Iraq','Asia',0),('IR','Iran','Asia',0),('IS','Iceland','Europe',1),('IT','Italy','Europe',1),
('JE','Jersey','Europe',0),('JM','Jamaica','Americas',0),('JO','Jordan','Asia',0),('JP','Japan','Asia',0),
('KE','Kenya','Africa',0),('KG','Kyrgyzstan','Asia',0),('KH','Cambodia','Asia',0),('KI','Kiribati','Oceania',0),
('KM','Comoros','Africa',0),('KN','Saint Kitts and Nevis','Americas',0),('KP','Korea, Democratic People s Republic of','Asia',0),('KR','Korea, Republic of','Asia',0),
('KW','Kuwait','Asia',0),('KY','Cayman Islands','Americas',0),('KZ','Kazakhstan','Asia',0),('LA','Lao People s Democratic Republic','Asia',0),
('LB','Lebanon','Asia',0),('LC','Saint Lucia','Americas',0),('LI','Liechtenstein','Europe',1),('LK','Sri Lanka','Asia',0),
('LR','Liberia','Africa',0),('LS','Lesotho','Africa',0),('LT','Lithuania','Europe',1),('LU','Luxembourg','Europe',1),
('LV','Latvia','Europe',1),('LY','Libya','Africa',0),('MA','Morocco','Africa',0),('MC','Monaco','Europe',0),
('MD','Moldova','Europe',0),('ME','Montenegro','Europe',0),('MG','Madagascar','Africa',0),('MH','Marshall Islands','Oceania',0),
('MK','North Macedonia','Europe',0),('ML','Mali','Africa',0),('MM','Myanmar','Asia',0),('MN','Mongolia','Asia',0),
('MO','Macao','Asia',0),('MR','Mauritania','Africa',0),('MT','Malta','Europe',1),('MU','Mauritius','Africa',0),
('MV','Maldives','Asia',0),('MW','Malawi','Africa',0),('MX','Mexico','Americas',0),('MY','Malaysia','Asia',0),
('MZ','Mozambique','Africa',0),('NA','Namibia','Africa',0),('NC','New Caledonia','Oceania',0),('NE','Niger','Africa',0),
('NG','Nigeria','Africa',0),('NI','Nicaragua','Americas',0),('NL','Netherlands','Europe',1),('NO','Norway','Europe',1),
('NP','Nepal','Asia',0),('NR','Nauru','Oceania',0),('NZ','New Zealand','Oceania',0),('OM','Oman','Asia',0),
('PA','Panama','Americas',0),('PE','Peru','Americas',0),('PF','French Polynesia','Oceania',0),('PG','Papua New Guinea','Oceania',0),
('PH','Philippines','Asia',0),('PK','Pakistan','Asia',0),('PL','Poland','Europe',1),('PR','Puerto Rico','Americas',0),
('PS','Palestine, State of','Asia',0),('PT','Portugal','Europe',1),('PW','Palau','Oceania',0),('PY','Paraguay','Americas',0),
('QA','Qatar','Asia',0),('RO','Romania','Europe',1),('RS','Serbia','Europe',0),('RU','Russian Federation','Europe',0),
('RW','Rwanda','Africa',0),('SA','Saudi Arabia','Asia',0),('SB','Solomon Islands','Oceania',0),('SC','Seychelles','Africa',0),
('SD','Sudan','Africa',0),('SE','Sweden','Europe',1),('SG','Singapore','Asia',0),('SI','Slovenia','Europe',1),
('SK','Slovakia','Europe',1),('SL','Sierra Leone','Africa',0),('SM','San Marino','Europe',0),('SN','Senegal','Africa',0),
('SO','Somalia','Africa',0),('SR','Suriname','Americas',0),('SS','South Sudan','Africa',0),('ST','Sao Tome and Principe','Africa',0),
('SV','El Salvador','Americas',0),('SX','Sint Maarten','Americas',0),('SY','Syrian Arab Republic','Asia',0),('SZ','Eswatini','Africa',0),
('TC','Turks and Caicos Islands','Americas',0),('TD','Chad','Africa',0),('TG','Togo','Africa',0),('TH','Thailand','Asia',0),
('TJ','Tajikistan','Asia',0),('TL','Timor-Leste','Asia',0),('TM','Turkmenistan','Asia',0),('TN','Tunisia','Africa',0),
('TO','Tonga','Oceania',0),('TR','Turkiye','Asia',0),('TT','Trinidad and Tobago','Americas',0),('TV','Tuvalu','Oceania',0),
('TW','Taiwan','Asia',0),('TZ','Tanzania','Africa',0),('UA','Ukraine','Europe',0),('UG','Uganda','Africa',0),
('US','United States of America','Americas',0),('UY','Uruguay','Americas',0),('UZ','Uzbekistan','Asia',0),('VA','Holy See','Europe',0),
('VC','Saint Vincent and the Grenadines','Americas',0),('VE','Venezuela','Americas',0),('VG','Virgin Islands, British','Americas',0),('VI','Virgin Islands, U.S.','Americas',0),
('VN','Viet Nam','Asia',0),('VU','Vanuatu','Oceania',0),('WS','Samoa','Oceania',0),('YE','Yemen','Asia',0),
('ZA','South Africa','Africa',0),('ZM','Zambia','Africa',0),('ZW','Zimbabwe','Africa',0)
ON DUPLICATE KEY UPDATE `country_name` = VALUES(`country_name`), `is_eu_eea` = VALUES(`is_eu_eea`);

-- ─────────────────────────────────────────────────────────────────────────────
-- Mandatory no-offer disclosure [M§4 / project description]
--
-- Stored as a versioned legal notice rather than template text, so legal
-- review can revise the wording without a code deployment, and so the exact
-- text shown to a registrant at any past date remains reproducible.
--
-- This is the wording from the project description, which is the most recent
-- and most specific of the two variants supplied. The Master Instructions
-- variant is recorded alongside it as version 1 for traceability; the
-- clarification register documents the difference.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO `{prefix}rc_legal_notices`
  (`key`, `locale`, `version`, `title`, `body`, `source_reference`, `publication_state`)
VALUES
  ('no_offer_disclosure', 'en', 1, 'Pre-Launch Disclosure',
   'ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, allocation of tokens or entitlement to participate in any future offering. Any future availability will be subject to the final legal and corporate structure, definitive offering documentation, asset verification, jurisdictional eligibility, KYC/KYB, sanctions screening and approval.',
   'Final Master Developer Instructions v2.0, section 4', 'unpublished'),
  ('no_offer_disclosure', 'en', 2, 'Pre-Launch Disclosure',
   'ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.',
   'Freelancer project description (current)', 'published')
ON DUPLICATE KEY UPDATE `body` = VALUES(`body`), `source_reference` = VALUES(`source_reference`);
