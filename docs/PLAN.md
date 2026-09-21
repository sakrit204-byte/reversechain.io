# ReserveChain.io — Contest Execution Plan

Contest: https://www.freelancer.in/contest/ReserveChainio-Turnkey-IndustrialMetalsBacked-RWA-ERC-Tokenization-Platform-2767013/details
Prize $1,500 · Deadline ≈ **2026-10-12 (UTC evening)** · Our internal submission target: **2026-10-10**
Entry format: images only (GIF/JPEG/PNG) + description text → hosted demo URL goes in the description.

Source material (all reviewed in full):
- `docs/brief/contest-brief.md` — verbatim Freelancer brief
- `docs/brief/attachments/ReserveChain_Final_Master_Developer_Instructions_v2.0.pdf` — 57 pp: 22 CMS/site sections, 22-phase binding roadmap, 90–120-day structure, 32-item "response required", acceptance gates, owner-input register, handover inventory
- `docs/brief/attachments/ReserveChain_Website_Developer_Instructions.pdf` — 25 pp: 51-page sitemap, mega-menu IA, asset-page template, CTA library, data-integrity rules, acceptance criteria
- `docs/brief/attachments/IGAS Analysis Nickel Wire.png` — real CoA **0004368**, 19.10.2021, Ni wire 0.025 mm, lot "120/NP1", 5000 g* in 30 bobbins, ICP/MS+ICP/OES, purity 99.9807 %, impurities 0.0193 % (GOST 2179-75), sampled bobbins 8/10/19/27 at Goslar
- `docs/brief/attachments/Ultrafine Copper powder - IGAS Analysis.png` — real CoA **0004512**, 04.07.2022, lot #03-K-07, 2000 kg* in glass ampoules/cardboard boxes, sampled at ProSafe Magdeburg box 20, ICP/OES, purity 99.9999 % (TU 1793-011-50316079-2004), isotopes ⁶³Cu 69.1 % / ⁶⁵Cu 30.9 %
  (*net weight "according to information given by customer" — i.e. declared, not independently verified)
- `docs/brief/attachments/79B38BE6….png` — official logo (navy/gold, cube with gold bar, painting, emerald, copper tubes/powder, nickel coil; tagline "The infrastructure for real-world assets. Own. Trade. Redeem.")
- `docs/brief/attachments/reference-site/` — 51 page mockups (design benchmark: deep navy/black, warm gold, copper & nickel accents, dense institutional layouts, mega-menu, breadcrumbs, dashboards, timelines, doc panels)

---

## 1. What is actually being judged (contest phase)

The brief lists the hosted working draft must show, explicitly:

| # | Required in the draft | Our answer |
|---|---|---|
| 1 | Homepage | Full 22-section institutional homepage (Master §6) with approved copy (Master §7) |
| 2 | Responsive design | Desktop/tablet/mobile QA'd, no horizontal clipping, tables/dashboards mobile-safe |
| 3 | Prelaunch disclosure | CMS-managed, versioned legal notice on home, program pages, waitlist, documents |
| 4 | Copper Powder + Nickel Wire pages | Full asset-program hubs per Website PDF §5 (23-item template) |
| 5 | Sample Digital Asset Passport | Live passport page + QR + public verification endpoint, clearly marked ILLUSTRATIVE |
| 6 | Functional waitlist | All 12 fields (Master §15), double-opt-in email verification, consent records, admin search/filter/export |
| 7 | Proposed CMS/admin structure | Working WordPress admin + custom registry/PoR/audit screens, roles, MFA, modes |
| 8 | Technical architecture | Architecture doc + diagrams (C4 L1–L3, data model ERD, contract topology, env topology) |
| 9 | Development schedule | 22-phase milestone schedule mapped to the 90–120-day structure, per-phase table with all 19 columns the Master PDF demands |
| 10 | Confirmation attachments reviewed | Requirements Traceability Matrix (RTM) covering every line of the brief + both PDFs + a Clarification Register |

Selection criteria: "working demo, architecture, security, apps, whitepaper and demonstrated progress — not only price."
So the entry = **hosted demo + contracts on Sepolia + mobile app builds + whitepaper draft + proposal pack**.

## 2. Key decisions (made; reasoning recorded)

### D1 — WordPress, properly engineered (decided 2026-09-21)
The brief says "Website in WordPress" and lists PHP/MySQL among required skills. We build a genuine WordPress site — no headless frontend, no deviation to argue about in a submission. The quality bar is protected by *how* it is built, not by replacing the platform:

- **WordPress 6.7 / PHP 8.3 / MySQL 8**, custom plugin `reservechain-core` + custom block theme `reservechain`. No page builder, no purchased template, no plugin sprawl.
- **Editorial content → custom post types** with custom post statuses mapped exactly to the client's six workflow states (Draft, Under Review, Approved, Published, Unpublished, Archived). The 51 pages, FAQ, announcements and legal notices are edited in the native WordPress editor, which is what the client's team will actually operate.
- **The registry → dedicated relational tables with foreign keys.** Programs, lots, units, certificates, custody, valuations, reserves and the audit trail are referential data with unit-level reconciliation and reporting requirements. Postmeta cannot express or query that at scale, and a reviewer who looks will see the difference immediately.
- Engineering controls: Composer + PSR-4 autoloading, PHPStan (level 8), WordPress Coding Standards via PHPCS, PHPUnit with the WP test suite, WP-CLI commands for migrations/seed/reconciliation, Vite for TypeScript + SCSS + Three.js bundling, GitHub Actions CI.
- The same plugin exposes authenticated REST at `/wp-json/rc/v1/*`, which the iOS and Android apps consume — so "connected to the same backend, APIs, authentication and blockchain infrastructure" is literally true.

**Status: schema verified working.** 51 tables, 39 foreign keys, 7 immutability triggers created cleanly on MySQL 8.0.46; `UPDATE` and `DELETE` against the audit log are both rejected at the database with `ERROR 1644 (45000)`.

### D2 — Ethereum mainnet is the mandated chain; Sepolia for the demo; Foundry toolchain
Master Phase 3: "primary token must be ERC-20 on Ethereum Mainnet. BSC/BEP-20 not accepted." No mainnet deploy without written authorization → we deploy to **Sepolia**, verify on Etherscan, and hand the mainnet script over unexecuted.
- Foundry (forge/cast/anvil): unit + fuzz + invariant tests (brief asks for "fuzz or invariant testing"), gas report, coverage, Slither + Aderyn static analysis.
- OpenZeppelin Contracts v5, non-upgradeable by default (brief: upgradeability only if expressly approved; we document a migration path instead).
- Admin = **Safe multisig** + OZ `TimelockController`. Deployer renounces roles to Safe in the deploy script.

### D3 — Nothing hard-coded, everything owner-configurable
Token name/symbol/decimals/cap/allocations, jurisdiction lists, fees, ratios, thresholds, discount %, modes → constructor params from `tokenomics.config.json` on-chain; CMS settings off-chain. Every commercial parameter change = maker-checker + audit entry.

### D4 — Real CoAs go in as **Draft** registry records; public shows the illustrative template
Master §13 is explicit that the public placeholder shows "Pending" everywhere and §9 says lab claims stay unpublished until approved. The two IGAS certificates are owner-supplied but not owner-approved for publication. So:
- Public copper/nickel pages → illustrative template exactly per Master §13 + Required Provisional Asset Notice.
- Admin demo → both CoAs ingested as real lot records (`Under Review`), every field from Master §10/§11 populated from the certificates, documents hashed (SHA-256), and the workflow Draft → Under Review → Approved → Published demonstrated live on a preview link (draft preview tokens prove "draft never public/indexed").
- Sample Passport → demo record with a persistent `ILLUSTRATIVE / DEMO DATA` banner (Website PDF §8).

### D5 — Mobile: Expo (React Native) with EAS builds
Same TypeScript, same API client, EAS → TestFlight/Play internal testing once ReserveChain-owned Apple/Google accounts exist (we state that plainly). For the contest: Android internal build + iOS dev build + recorded walkthrough.

### D5b — Why not a headless/JS rewrite
Considered and rejected on 2026-09-21. The brief names WordPress explicitly; submitting a non-WordPress build invites a one-line disqualification no amount of engineering quality can argue back. Every control we wanted from a modern stack — typed code, migrations, foreign keys, append-only audit, CI, static analysis, a component library — is achievable inside WordPress with a properly structured plugin and theme, and that is what we are doing.

### D6 — Three.js where it earns its place (their rule: controlled animation, no neon, reduced-motion)
1. Homepage hero: PBR copper-powder particle field + nickel coil resolving into a sealed, QR-tagged unit → "physical → digital". Loads after LCP; static WebP fallback for `prefers-reduced-motion` and low-power devices.
2. Digital Asset Passport: 3D unit card (container/coil) with flip-to-QR, evidence chain rings.
3. "How ReserveChain Works": scroll-driven 9-stage chain of trust (Identify → Verify → Value → Custody → Passport → Tokenize → Reconcile → Participate → Redeem).
Everything else is HTML/CSS + real charts (PoR dashboard). Budget: LCP < 2.5 s, CLS < 0.05, Lighthouse ≥ 95 on every public page.

### D7 — Repo name `reservechain` (folder stays `reversechain.io`)
Single monorepo (pnpm workspaces): `apps/web`, `apps/mobile`, `apps/admin` (React screens embedded in WP), `packages/contracts`, `packages/api-client`, `packages/design-system`, `services/chain-sync`, `wordpress/` (docker + plugin + theme stub), `docs/`, `infra/`.

## 3. Architecture (summary — full doc in `docs/architecture/`)

```
                ┌──────────────── Public Internet ────────────────┐
   Browser ───► WordPress 6.7 + theme `reservechain`  ◄─── Expo iOS/Android
                (PHP 8.3, Apache, CSP + HSTS, Vite assets)   │
                     │                                        │ REST /rc/v1
                     ▼                                        ▼  (JWT, rate-limited)
                 plugin `reservechain-core`
          ├─ Content (pages, docs, FAQ, legal, i18n Polylang, SEO)
          ├─ Registry tables: programs, lots, batches, containers, coils, labs,
          │   certificates, custody, valuations, insurance, reserve_reports,
          │   token_programs, redemption_units, documents (+hash), passports
          ├─ Workflow: Draft→Under Review→Approved→Published→Unpublished→Archived
          ├─ Audit trail: append-only, hash-chained, DB user w/o UPDATE/DELETE,
          │   daily Merkle root anchored on-chain
          ├─ Website modes (10) + module visibility flags + maker-checker
          ├─ Waitlist (double opt-in), enquiries, notifications, exports
          └─ Users/roles/MFA (TOTP), sessions, login protection
                     │                       ▲
                     ▼                       │ events / reconciliation
          MySQL 8 ──────────────  services/chain-sync (Node/TS, viem)
                                  ├─ event indexer (reorg-safe, idempotent)
                                  ├─ supply/reserve reconciliation + alerts
                                  └─ attestation anchoring job
                                            │
                                            ▼
                     Ethereum Sepolia (→ Mainnet on written authorization)
                     ├─ ReserveChainToken (ERC20+Burnable+Pausable+Permit+AccessControl, capped)
                     ├─ AssetProgramTokenFactory (new program tokens w/o redeploying platform)
                     ├─ ComplianceRegistry (allow/deny lists, jurisdiction flags; OFF in prelaunch)
                     ├─ RedemptionController (lock→approve→burn w/ unit reference; INACTIVE)
                     ├─ ReserveAttestationRegistry (PoR report + audit-log Merkle roots)
                     └─ Safe multisig + TimelockController hold all privileged roles
```

Environments: `dev` (Docker Compose, one command), `staging` (hosted demo), `production` (documented, not created until award). Backups: nightly `mysqldump` + media to encrypted object storage; restore drill recorded.

## 4. Gold flakes — visible differentiators, each traceable to a brief line

| Flake | Brief requirement it exceeds |
|---|---|
| **Hash-chained audit log, daily Merkle root anchored on Sepolia**, "Verify integrity" button in admin | "append-only, tamper-evident … not editable or removable through the normal admin interface" |
| **Passport QR → public verifier**: paste a document, get SHA-256 match against the registry | Passport "QR access", "asset-document hashes", "downloadable evidence" |
| **Reconciliation alerts fire on their real data**: CoA 0004368 (2021) and 0004512 (2022) trigger *stale attestation*; declared-vs-verified weight flagged | PoR: "stale-valuation alerts, expiring-document alerts, missing-document alerts, exception report" |
| **Prelaunch language linter** in CI + CMS pre-publish check (blocks "guaranteed", "buy now", "MiCA-compliant", unapproved "Verified/In Custody/Tokenized" badges, countdown timers) | Master §1, §3; Website PDF §8 "No false status labels" |
| **Website-mode switchboard** with maker-checker: Live Offering / Redemption / Wallet can only be enabled by two distinct admins, never by config file | "must never activate automatically" |
| **RTM as a live admin page + PDF**: every requirement → phase → deliverable → demo URL → test ID | "requirements traceability matrix mapping every Freelancer requirement" |
| **Clarification Register** (see §6) | "identify it specifically before proceeding" |
| **Contract quality pack**: fuzz+invariant tests, Slither/Aderyn clean, coverage & gas reports, Sepolia-verified, Safe+timelock, prohibited-function checklist signed off | Phase 3 deliverables + prohibited functionality list |
| **Declared vs verified quantities** as first-class fields (`quantity_declared`, `quantity_verified`, `verification_source`) | CoAs literally say "*net weight according to information given by customer*" |
| **Recorded backup→restore drill** and staging→prod rollback demo in the entry video | "Backup restoration is independently tested", "rollback are tested" |
| **Institutional whitepaper Stage-1** (DOCX+PDF+SVG sources) with `[PENDING — owner input]` markers | Phase 14 two-stage delivery |
| **Proposal pack answering all 32 "response required" items** + per-phase table with all 19 columns | Master p.52 |

## 5. Data model (registry core — custom tables, InnoDB, FK-enforced)

`asset_programs` (id, slug, metal, form, status fields ×7, i18n) → `lots` (program_id, lot_no, declared/verified qty+unit, origin, producer, production_date) → `units` (lot_id, kind ENUM[container,coil,bobbin,ampoule,box], identifier, net/gross weight, seal_no, packaging, storage_location, reserve_status, redemption_status) · `laboratories` · `certificates` (lab_id, lot_id, number, date, method, purity, impurity_pct, standard, sampled_units JSON, document_id) · `certificate_elements` (certificate_id, element, value_ppm, operator) · `documents` (kind, version, sha256, visibility ENUM[public,private,pending], state) · `ownership_records` · `custody_records` · `insurance_records` · `valuations` (date, currency, price_per_kg, methodology, benchmark, valuer) · `reserve_reports` · `token_programs` (chain, address NULL until approved, name/symbol/decimals/cap, allocations JSON, state ENUM[Pending,Under Review,Not Applicable,Approved,Published,Suspended,Retired]) · `redemption_requests` · `passports` (unit_id, public_id, qr_payload) · `material_events` · `audit_log` (id, ts, actor, action, entity, entity_id, before JSON, after JSON, reason, prev_hash, hash) · `content_states` · `site_modes` · `module_flags` · `waitlist_registrations` + `consents` · `enquiries`.

Copper-specific spec fields (Master §10) and nickel-specific fields (Master §11) live in `program_spec_fields` (typed EAV per program, versioned) so a third metal needs no migration.

## 6. Clarification Register (goes in the entry — proves we read everything)

| # | Conflict / gap | Our handling |
|---|---|---|
| C1 | Freelancer brief: "Estonia pre-incorporation"; mandatory disclosure + both PDFs: "Swiss corporate and legal structure" | Use **Swiss** (appears in the binding PDFs and inside the mandatory disclosure text); corporate jurisdiction is a single CMS setting, so either is a one-field change |
| C2 | Two different wordings of the mandatory disclosure (Freelancer brief vs Master §4) | Ship the Freelancer-brief wording (newer, more specific); legal notices are versioned CMS records; both versions stored, diff visible |
| C3 | Master §6 nav (15 items) vs Website PDF §3 mega-menu (7 groups / 51 pages) | Implement the 51-page mega-menu IA; Master §6 items map 1:1 into it (mapping table in RTM) |
| C4 | Master says "no wallet connection in public pre-launch mode"; Website PDF & mockups show Portal login / wallet | Wallet module built, gated behind mode flag, hidden + non-indexable in prelaunch |
| C5 | CoAs are dated 2021/2022 | Ingested as Under Review; stale-attestation alert demonstrates the PoR control; re-assay flagged as owner input |
| C6 | CoA weights are customer-declared | Modelled as declared vs verified; public template shows "Pending" |
| C7 | Mockups show partner logos (SGS, Intertek, Eurofins, ALS), named board members, "100% backed", "649.07 tonnes", "$1.00/RSC" etc. | **Excluded** — prohibited by Master §3 (third-party logos/names without authorization, invented statistics). Stated explicitly |
| C8 | Master mentions "USDT ERC-20 payments" + "20% discount" pages; Freelancer brief says no purchase functions | Pages built as methodology explainers with "Planned / subject to approval"; purchase module built inactive |
| C9 | Entry accepts images only | Hosted demo URL + credentials for a read-only reviewer admin account in the entry description |
| C10 | TestFlight/Play testing requires ReserveChain-owned accounts | Builds + recorded demo now; store distribution on award under owner accounts |

## 7. Schedule — 21 working days (Sep 20 → Oct 10)

| Days | Dates | Workstream | Exit criteria |
|---|---|---|---|
| 1–2 | Sep 20–21 | Monorepo, Docker (WP+MySQL+Next+chain-sync+anvil), design tokens, ERD, RTM skeleton, CI (lint, tests, language linter) | `docker compose up` gives a running stack; RTM lists every requirement ID |
| 3–6 | Sep 22–25 | `reservechain-core` plugin: schema+migrations, workflow states, audit chain, modes/flags, waitlist+double opt-in, enquiries, REST, MFA, roles; ingest both CoAs | API contract frozen; Postman/OpenAPI published |
| 5–9 | Sep 24–28 | Contracts: token, factory, compliance registry, redemption controller, attestation registry; tests (unit/fuzz/invariant), Slither/Aderyn, deploy+verify Sepolia, gas/coverage reports; chain-sync indexer + reconciliation | ≥ 95 % coverage, 0 high/medium static findings, verified addresses in register |
| 6–13 | Sep 25 – Oct 2 | Web: design system, mega-menu, homepage (3D hero), disclosure system, copper & nickel hubs, passport + verifier, how-it-works, PoR dashboard (demo-data), enterprise/participation/company/resources/legal pages, 404/500, EN/ES/IT | All 51 pages exist with page-specific content; Lighthouse ≥ 95; axe clean |
| 10–15 | Sep 29 – Oct 4 | Admin: registry screens, passport builder, PoR/reconciliation, audit viewer + integrity check, modes switchboard (maker-checker), waitlist/enquiry management, exports | Reviewer walkthrough script passes end-to-end |
| 12–17 | Oct 1–6 | Mobile (Expo): auth+TOTP MFA, profile/eligibility, programs, passports, documents, holdings (gated), notifications, support; EAS builds | Android internal APK/AAB + iOS build; recorded walkthrough |
| 14–18 | Oct 3–7 | Whitepaper Stage-1 (DOCX/PDF/SVG), architecture docs, RTM complete, 22-phase milestone schedule, proposal pack (32 items), manuals skeleton, backup/restore drill, Playwright e2e, security headers audit | All docs exported; drill recorded |
| 18–20 | Oct 7–9 | Staging deploy, cross-browser/device QA, performance, copy/legal pass with language linter, entry images + GIF/video | Zero P1/P2 defects |
| 21 | Oct 10 | Submit; monitor clarification board | Entry live ≥ 48 h before deadline |

Parallelism: contracts (days 5–9) and web (6–13) overlap; mobile starts once the API is frozen (day 6).

## 8. Owner inputs we need (from the user, not the client)

1. Hosting for the staging demo (VPS with Docker, or Vercel + small VPS) and a domain/subdomain.
2. Transactional email provider for double opt-in (Resend/Postmark/SES) — or we run Mailpit on staging and show the flow.
3. Sepolia ETH (faucet is fine) and an Etherscan API key for verification.
4. Expo/EAS account for builds.
5. The Freelancer account that will submit (for the acknowledgement block on Master p.57 we fill "Developer / Company", "Freelancer username").

## 9. Standards we hold ourselves to

- OWASP ASVS L2 controls checklist for web/API; MASVS for mobile.
- Solidity: OZ v5, Solidity 0.8.2x, `forge fmt`, NatSpec on every public function, Slither + Aderyn in CI, no `selfdestruct`/`delegatecall`, no hidden taxes/blacklists (Phase 3 prohibited list signed).
- WCAG 2.2 AA; Core Web Vitals green; reduced-motion honoured.
- Conventional commits; protected `main`; PR template includes RTM IDs touched.
- Every public string that describes status goes through the language linter.
