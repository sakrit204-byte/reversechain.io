# ReserveChain.io — Requirements Traceability Matrix

> Maps every requirement in the contest brief (as summarised in `SPEC.md`) to where it is implemented in this entry and its status. Intended as a judging and acceptance aid. Milestone numbers refer to [`DEVELOPMENT-SCHEDULE.md`](DEVELOPMENT-SCHEDULE.md).

> **Mandatory disclosure.** ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.

## Status legend

| Status | Meaning |
|---|---|
| **Done (demo)** | Working in the contest entry; can be demonstrated locally (`docker compose up`, `npx hardhat test`, `npx expo start`) |
| **Done (docs)** | Delivered as documentation in `docs/` |
| **Scaffolded** | Structure, interfaces, configuration or UI exist; production integration or content pending ReserveChain input |
| **Gated (off)** | Implemented but intentionally inactive / feature-flagged off until written authorization |
| **Planned Mn** | Not in entry; scheduled in milestone *n* |
| **Input needed** | Cannot be completed without information or decisions from ReserveChain; placeholder shown |

Abbreviations: **T** = `wordpress/themes/reservechain/` · **P** = `wordpress/plugins/reservechain-core/` · **C** = `contracts/` · **M** = `mobile/` · **D** = `docs/`.

---

## 1. Website sections

| ID | Requirement | Implemented in | Status | Notes / acceptance evidence |
|---|---|---|---|---|
| W-01 | Home | T (front page) | Done (demo) | Cu 29 / Ni 28 element tiles, disclosure banner, Claim Status pills |
| W-02 | Project Overview | T | Done (demo) | Model described as proposed; entity "proposed Swiss structure" — never asserted as formed |
| W-03 | Copper Powder program page | T + P `rc_program` | Done (demo) | Generic industrial context; purity/spec fields render pending states |
| W-04 | Nickel Wire program page | T + P `rc_program` | Done (demo) | idem |
| W-05 | Asset Registry (public) | T + P registry CPTs + `/passports` | Done (demo) | Lists Published lots/batches/containers/coils with status |
| W-06 | Digital Asset Passports | T + P `Passport` + `/passports/{no}` | Done (demo) | QR, timeline, evidence ledger, SHA-256, Merkle root, completeness |
| W-07 | Verification | T Verify page + P `/verify` | Done (demo) | Client-side SHA-256; file never uploaded |
| W-08 | Custody | T + P `rc_custody` | Done (demo) / Input needed | Custodian, vault, location: "[To be provided by ReserveChain]" |
| W-09 | Proof of Reserves | T + P `rc_reserve_report` + C `ReserveGuard` | Gated (off) | Section shows "proposed framework — inactive"; module flag `proof_of_reserves` off |
| W-10 | Tokenization | T + P `rc_token_program` | Done (demo) | All parameters "Not yet determined — subject to written approval" |
| W-11 | Redemption | T + P `rc_redemption` + C `RedemptionManager` | Gated (off) | Proposed process only; module flag `redemption` off |
| W-12 | Enterprise Services | T | Done (demo) | Turnkey tokenization services described as proposed |
| W-13 | Documents / Whitepaper | T + P `rc_document` + D/whitepaper | Done (demo) | Public library with fingerprints; whitepaper MD/DOCX/PDF |
| W-14 | Roadmap | T | Done (demo) | Phase-based, no invented dates |
| W-15 | Governance | T | Done (demo) | Four-eyes, roles, multisig intent, audit trail |
| W-16 | FAQ | T | Done (demo) | Compliance-reviewed wording |
| W-17 | Contact | T + `/support` | Done (demo) | Rate-limited; honeypot |
| W-18 | Waitlist | T + P `Waitlist` + `/waitlist` | Done (demo) | EU/EEA ⇒ restricted + notice; double opt-in; consent hash |
| W-19 | Legal notices (disclosure, EU/EEA, privacy, terms, cookies) | T + P settings + `/config` | Done (demo) / Input needed | Disclosure + EU/EEA verbatim; privacy/terms texts require counsel approval |
| W-20 | Multilingual EN / ES / IT | T + P textdomain `reservechain` | Scaffolded | EN complete; ES/IT string catalogues; legal translations pending counsel → M8 |
| W-21 | Responsive / mobile layouts | T | Done (demo) | Verified at 360 px+; full device matrix → M3 |
| W-22 | Accessibility WCAG 2.2 AA | T | Scaffolded | Semantic HTML, focus states, contrast tokens; formal audit → M3/M9 |
| W-23 | Performance | T | Scaffolded | No heavy frameworks; budgets + Lighthouse CI → M3 |
| W-24 | Site modes (prelaunch / waitlist_only / maintenance; `live` locked) | P `Settings` | Done (demo) | `live` refused unless `RC_ALLOW_LIVE_MODE` + written authorization |
| W-25 | Module visibility toggles | P `Settings` + `/config` | Done (demo) | Gated modules require authorization reference |
| W-26 | Claim Status System | P `Schema::CLAIM_STATUSES` + T pills | Done (demo) | Differentiator |
| W-27 | Cookie consent / analytics | T | Planned M3 | Provider: [To be decided by ReserveChain] |

## 2. Registry entities

| ID | Entity | Implemented in | Status | Notes |
|---|---|---|---|---|
| R-01 | Programs | P `rc_program` | Done (demo) | |
| R-02 | Lots | P `rc_lot` | Done (demo) | Passport-bearing |
| R-03 | Batches | P `rc_batch` | Done (demo) | Passport-bearing |
| R-04 | Containers | P `rc_container` | Done (demo) | Passport-bearing |
| R-05 | Coils / spools | P `rc_coil` | Done (demo) | Passport-bearing |
| R-06 | Laboratories | P `rc_laboratory` | Done (demo) / Input needed | No lab named |
| R-07 | Certificates of Analysis | P `rc_coa` | Done (demo) / Input needed | No results invented |
| R-08 | Custody & ownership records | P `rc_custody` | Done (demo) / Input needed | |
| R-09 | Valuations | P `rc_valuation` | Done (demo) / Input needed | No values published |
| R-10 | Insurance | P `rc_insurance` | Done (demo) / Input needed | |
| R-11 | Reserve reports | P `rc_reserve_report` | Done (demo) / Input needed | |
| R-12 | Token programs | P `rc_token_program` | Done (demo) | All parameters unset |
| R-13 | Redemptions | P `rc_redemption` | Gated (off) | Internal only |
| R-14 | Documents with SHA-256 | P `rc_document`, `Audit_Log::on_attachment` | Done (demo) | Hash immutable after publish |
| R-15 | Extensible schema without rebuild | P `rc_registry_schema` filter | Done (demo) | |
| R-16 | Registry data import / bulk upload | P CLI (`class-cli.php`) | Scaffolded | CSV import UI → M2 |

## 3. CMS features

| ID | Requirement | Implemented in | Status | Notes |
|---|---|---|---|---|
| C-01 | Custom CMS on WordPress (PHP/MySQL) | P | Done (demo) | |
| C-02 | Four-eyes workflow Draft→Under Review→Approved→Published→Unpublished→Archived | P `Workflow` | Done (demo) | Approver ≠ author enforced server-side |
| C-03 | Roles rc_editor / rc_reviewer / rc_compliance_officer / rc_registry_manager / rc_auditor | P `Install::create_roles` | Done (demo) | See CMS-STRUCTURE §6 |
| C-04 | Granular capabilities | P `Install::CAPS` | Done (demo) | 11 custom capabilities |
| C-05 | Append-only, hash-chained audit log | P `Audit_Log` | Done (demo) | |
| C-06 | MySQL triggers blocking UPDATE/DELETE | P `Install::create_triggers` | Done (demo) | Status shown in admin |
| C-07 | Verify chain integrity tool | P `Audit_Log::verify` + Admin | Done (demo) | |
| C-08 | On-chain audit anchoring | P `Audit_Log::add_anchor` + C `AuditAnchor` | Done (demo) / Gated | Testnet only; off by default |
| C-09 | Jurisdiction controls | P `Compliance` | Done (demo) | EU/EEA pre-restricted |
| C-10 | KYC / KYB / AML / sanctions status model | P `Compliance`, `/me` | Scaffolded | Provider integration → M5; provider [To be decided] |
| C-11 | Waitlist management + export | P `Waitlist`, `rc_manage_waitlist` | Done (demo) | |
| C-12 | Versioned migrations | P `RC_DB_VERSION`, `Install::upgrade` | Done (demo) | Each migration audit-logged |
| C-13 | REST API `/wp-json/rc/v1` | P `Rest` | Done (demo) | Endpoints per SPEC |
| C-14 | Bearer + refresh tokens, MFA TOTP | P `Auth` | Done (demo) | |
| C-15 | Security hardening (headers, rate limiting) | P `Security` | Done (demo) / Planned M1 | Edge WAF → M1 |
| C-16 | Document library management | P `rc_document` | Done (demo) | Virus scanning → M4 |
| C-17 | Media upload SHA-256 fingerprinting | P `Audit_Log::on_attachment` | Done (demo) | |
| C-18 | Admin dashboard (queue, integrity, mode) | P `Admin` | Done (demo) | |
| C-19 | Shortcodes / blocks for editors | P `Shortcodes` | Done (demo) | |
| C-20 | CMS user manual | D (manuals) | Planned M10 | Outline in HANDOVER-CHECKLIST |

## 4. Smart contracts (testnet only)

| ID | Requirement | Implemented in | Status | Notes |
|---|---|---|---|---|
| SC-01 | ERC-20 token (OZ v5) | C `ReserveToken.sol` | Done (demo) | Name/symbol/decimals/cap from config |
| SC-02 | ERC20Permit | C `ReserveToken.sol` | Done (demo) | |
| SC-03 | Role-based access (ADMIN, MINTER, BURNER, PAUSER, COMPLIANCE_ADMIN, TREASURY) | C `ReserveToken.sol` | Done (demo) | DEFAULT_ADMIN intended Safe multisig |
| SC-04 | Pausable | C | Done (demo) | |
| SC-05 | Transfer-time compliance check | C `ComplianceRegistry.sol`, `IComplianceRegistry` | Done (demo) | KYC status, jurisdiction, frozen, expiry |
| SC-06 | Blocked jurisdictions | C `ComplianceRegistry.sol` | Done (demo) | |
| SC-07 | Reserve-gated minting | C `ReserveGuard.sol`, `IReserveGuard` | Done (demo) | Ratio unset ⇒ minting disabled |
| SC-08 | Redemption (escrow → review → burn/return) | C `RedemptionManager.sol` | Gated (off) | Thresholds configurable; disabled by default |
| SC-09 | Treasury with limits / timelock option | C `Treasury.sol` | Done (demo) | |
| SC-10 | Audit anchor | C `AuditAnchor.sol` | Done (demo) | |
| SC-11 | No hard-coded tokenomics | C `config/<network>.json` | Done (demo) | |
| SC-12 | Unit tests | C `test/` | Done (demo) | Coverage target ≥ 95 % → M6 |
| SC-13 | Testnet deployment (Sepolia; Amoy optional) | C `scripts/deploy.ts`, `verify.ts`, `admin-status.ts`, `anchor-audit.ts`, `config/*.example.json` | Scaffolded | Requires ReserveChain-owned deployer + RPC key → M6 |
| SC-14 | Source verification on explorer | C hardhat-verify | Planned M6 | |
| SC-15 | Safe multisig role assignment | C scripts + docs | Planned M6 / Input needed | Signers & threshold [To be provided] |
| SC-16 | Static analysis (Slither) | C | Planned M6 | |
| SC-17 | Third-party smart-contract audit | — | Planned M9 / Input needed | Firm [To be appointed by ReserveChain] |
| SC-18 | Mainnet deployment | — | **Out of scope** | Never performed; requires separate written authorization |
| SC-19 | Token admin procedures manual | D | Planned M10 | |

## 5. Mobile apps (iOS / Android)

| ID | Requirement | Implemented in | Status | Notes |
|---|---|---|---|---|
| A-01 | Native iOS & Android apps | M (Expo RN → EAS native builds) | Done (demo) source / Planned M7 builds | |
| A-02 | Programs & registry browsing | M | Done (demo) | |
| A-03 | Digital Asset Passports + QR scan | M | Done (demo) | |
| A-04 | Document verification (local hash) | M | Done (demo) | |
| A-05 | Account: register / login / MFA | M + `/auth/*` | Done (demo) | Secure storage |
| A-06 | Eligibility status (KYC/KYB/AML/sanctions) | M + `/me` | Done (demo) | Read-only statuses |
| A-07 | Holdings / transactions / wallet | M + `/me/holdings` | Gated (off) | `{enabled:false}` |
| A-08 | Notifications | M + `/me/notifications` | Scaffolded | Push provider → M7 |
| A-09 | Support | M + `/support` | Done (demo) | |
| A-10 | Waitlist from app | M + `/waitlist` | Done (demo) | |
| A-11 | Disclosure + EU/EEA notice in app | M | Done (demo) | From `/config` |
| A-12 | EN / ES / IT | M i18n | Scaffolded | → M8 |
| A-13 | Remote config of modules / site mode | M + `/config` | Done (demo) | |
| A-14 | Store builds, signing, submission | EAS | Planned M7 / Input needed | Apple/Google accounts must be owned by ReserveChain |
| A-15 | Build / sign / update / store manuals | D | Planned M10 | |

## 6. Security

| ID | Requirement | Implemented in | Status | Notes |
|---|---|---|---|---|
| S-01 | RBAC | P | Done (demo) | |
| S-02 | MFA | P `Auth` (TOTP) | Done (demo) | Mandatory for staff in production |
| S-03 | Session security | P `Security` + WP | Done (demo) / Planned M1 | Idle timeouts |
| S-04 | HTTPS / HSTS / CSP / security headers | P `Security` + edge | Scaffolded | Final CSP at edge → M1 |
| S-05 | Upload validation & malware scanning | P | Scaffolded | ClamAV / provider → M4 |
| S-06 | Rate limiting | P + edge WAF | Done (demo) / Planned M1 | |
| S-07 | Secrets via env / secrets manager | `.env.example`, D/SECURITY | Done (docs) / Planned M1 | |
| S-08 | Backups 3-2-1 | D/BACKUP-DR | Done (docs) / Planned M1 | |
| S-09 | Monitoring & alerting | D/ARCHITECTURE §7.9 | Planned M1 | |
| S-10 | Vulnerability management | D/SECURITY | Done (docs) / Planned M9 | |
| S-11 | Incident response | D/SECURITY §9 | Done (docs) | |
| S-12 | Environment separation Dev / Staging / Prod | D/ARCHITECTURE §5 | Done (docs) / Planned M1 | |
| S-13 | Penetration test | — | Planned M9 / Input needed | Vendor [To be appointed] |
| S-14 | Privacy (hashed IP/email, PII restriction) | P | Done (demo) | |

## 7. Compliance

| ID | Requirement | Implemented in | Status |
|---|---|---|---|
| L-01 | Mandatory disclosure verbatim site-wide, app, docs | T, M, D, `/config` | Done (demo) |
| L-02 | EU/EEA notice | T, M, D, waitlist | Done (demo) |
| L-03 | No invented information; explicit placeholders | P schema pending texts, D | Done (demo) |
| L-04 | Language: proposed / in development / subject to final approval | All | Done (demo) |
| L-05 | Wallet / purchase / PoR / redemption inactive | P flags, C, M | Gated (off) |
| L-06 | Tokenomics never hard-coded | P, C config | Done (demo) |
| L-07 | Testnet only | C | Done (demo) |
| L-08 | Proposed Swiss structure (not asserted as formed) | T, D | Done (demo) |

## 8. Whitepaper

| ID | Requirement | Implemented in | Status |
|---|---|---|---|
| WP-01 | Institutional whitepaper | `docs/whitepaper/whitepaper.md` | Done (docs) |
| WP-02 | Editable format | `docs/whitepaper/ReserveChain-Whitepaper.docx` | Done (docs) |
| WP-03 | Publication-ready PDF | `docs/whitepaper/ReserveChain-Whitepaper.pdf` | Done (docs) |
| WP-04 | Diagram sources | `docs/whitepaper/diagrams/*.mmd` (+ rendered SVG/PNG) | Done (docs) |
| WP-05 | Asset-specific facts | Placeholders | Input needed |
| WP-06 | Legal review of whitepaper | — | Planned M10 / Input needed |

## 9. Testing

| ID | Requirement | Covered by | Status |
|---|---|---|---|
| T-01 | Browsers / devices | TEST-PLAN §3 | Planned M3/M9 |
| T-02 | Forms (waitlist, contact, auth) | TEST-PLAN §4 | Done (demo) partial / Planned M5 |
| T-03 | CMS permissions | TEST-PLAN §5 | Planned M2 |
| T-04 | Audit logs (integrity, tamper) | TEST-PLAN §6 | Done (demo) manual / Planned M2 automated |
| T-05 | Accessibility | TEST-PLAN §7 | Planned M3 |
| T-06 | Performance | TEST-PLAN §8 | Planned M3/M9 |
| T-07 | Smart contracts | `contracts/test`, TEST-PLAN §9 | Done (demo) |
| T-08 | Testnet | TEST-PLAN §10 | Planned M6 |
| T-09 | Apps | TEST-PLAN §11 | Planned M7 |
| T-10 | Backups | TEST-PLAN §12, BACKUP-DR | Planned M1 |
| T-11 | Restoration | TEST-PLAN §12, BACKUP-DR §6 | Planned M1/M9 |
| T-12 | Deployment | TEST-PLAN §13, DEPLOYMENT-RUNBOOK | Planned M1 |
| T-13 | Rollback | TEST-PLAN §13, DEPLOYMENT-RUNBOOK §4 | Planned M9 |

## 10. Handover items

| ID | Handover item | Where / how | Status |
|---|---|---|---|
| H-01 | Complete source code | Monorepo (T, P, C, M, D) | Done (demo) |
| H-02 | Repository transfer to ReserveChain organisation | HANDOVER-CHECKLIST §2 | Planned M0/M11 |
| H-03 | Database schema & migrations | P `class-install.php`, CMS-STRUCTURE §3 | Done (demo) |
| H-04 | API documentation | SPEC.md REST table, ARCHITECTURE §4 | Done (docs); OpenAPI file → M10 |
| H-05 | Architecture documentation | ARCHITECTURE.md | Done (docs) |
| H-06 | Infrastructure documentation | ARCHITECTURE §5–6, DEPLOYMENT-RUNBOOK | Done (docs); IaC → M1 |
| H-07 | Website / CMS user manuals | — | Planned M10 |
| H-08 | iOS / Android build, signing, update & store manuals | — | Planned M7/M10 |
| H-09 | Smart-contract & token administration procedures | SECURITY §7 (key mgmt) | Scaffolded; full manual M10 |
| H-10 | Registry / DAP / Proof-of-Reserves manuals | CMS-STRUCTURE | Scaffolded; manuals M10 |
| H-11 | Security manual | SECURITY.md | Done (docs) |
| H-12 | Backup / restore / disaster-recovery manual | BACKUP-DR.md | Done (docs) |
| H-13 | Deployment / maintenance / rollback procedures | DEPLOYMENT-RUNBOOK.md | Done (docs) |
| H-14 | Domain / DNS / email / analytics / monitoring configuration | HANDOVER-CHECKLIST §4 | Scaffolded; final values M1/M11 |
| H-15 | Environment variable templates | `contracts/.env.example`, `mobile/.env.example`; DEPLOYMENT-RUNBOOK §2 | Done (demo); root/WP template → M1 |
| H-16 | Service & dependency inventory | HANDOVER-CHECKLIST §5 | Done (docs); final M11 |
| H-17 | Testing documentation | TEST-PLAN.md | Done (docs) |
| H-18 | Troubleshooting guide & known issues | DEPLOYMENT-RUNBOOK §7 | Scaffolded; M10 |
| H-19 | Whitepaper + diagram sources | docs/whitepaper | Done (docs) |
| H-20 | Recorded training sessions | — | Planned M11 |
| H-21 | All accounts owned by ReserveChain (repos, servers, domains, DBs, credentials, email, analytics, blockchain, app stores) | HANDOVER-CHECKLIST §1 | Planned M0 (created in ReserveChain's name from day one) |
| H-22 | Contractor access revocation | HANDOVER-CHECKLIST §7 | Planned M11 |

## 11. Acceptance definition

| ID | Requirement | Where |
|---|---|---|
| X-01 | Completion = implementation, deployment, demonstration, testing, documentation, independent verification, formal owner acceptance | DEVELOPMENT-SCHEDULE §1; each milestone row |
