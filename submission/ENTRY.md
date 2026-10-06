# ReserveChain.io — Contest entry (working platform, not a mock-up)

**Hosted working draft:** https://__DEMO_HOST__  (always on · HTTPS · staging environment, not indexed)
**Source repository:** https://github.com/sakrit204-byte/reversechain.io (branch `reservechain-v2`)
Reviewer logins for each CMS role (editor, registry manager, reviewer, compliance officer, auditor) and the
iOS/Android preview are provided **on request via Freelancer message** — we do not publish demo credentials.

---

## What you can open and test today

| Requirement in the brief | Where to see it working |
|---|---|
| Homepage, responsive design, prelaunch disclosure | `/` — 22 sections in the master-instruction order, approved hero copy, mandated browser title, no-offer disclosure + EU/EEA notice + Provisional Asset Notice on every relevant page |
| Copper Powder and Nickel Wire pages | `/assets/industrial-metals/copper-powder/` and `/nickel-wire/` — the full 22-block program template, owner-supplied IGAS CoAs **transcribed value by value** with the original scans and SHA-256 fingerprints |
| Sample Digital Asset Passport | `/passport/RC-CU-LOT-000001/` (copper lot), `/passport/RC-NI-COL-000008/` (sampled nickel bobbin), `/passport/RC-LOT-000001/` (mandated Illustrative Industrial Metal Asset Template) — QR, Merkle root over evidence, derived lifecycle, JSON export |
| Functional waitlist | `/participation/waitlist/` — all 13 fields of §15, double opt-in, consent fingerprint, EU/EEA + restricted-jurisdiction screening, rate limiting, CSV export in CMS |
| Proposed CMS / admin structure | Live WordPress admin (on request) + `docs/CMS-STRUCTURE.md` — registry for programs, lots, batches, containers, coils, laboratories, CoAs, custody/ownership, valuations, insurance, reserve reports, token programs, redemptions, documents |
| Technical architecture | `/platform/infrastructure/`, `/platform/technology/` + `docs/ARCHITECTURE.md` |
| Development schedule | `/company/roadmap/`, `/company/development-status/` + `docs/DEVELOPMENT-SCHEDULE.md` |
| Verification | `/platform/verification/` — drop any ReserveChain document; its SHA-256 is computed **in your browser** and matched against the registry |
| Proof of Reserves | `/platform/proof-of-reserves/` — dashboard computed live from the registry; declared vs verified never blended; no coverage shown without an attestation |
| Audit trail | `/company/governance/#audit` — live chain head; MySQL triggers reject UPDATE/DELETE; hash chain verifiable and anchorable on-chain |
| Languages | Every page in English, Spanish and Italian (`?lang=es`, `?lang=it`) |

## What is built (all source in the repository)

* **WordPress platform** — custom theme + `reservechain-core` plugin (PHP 8.1+, MySQL 8): declarative registry schema,
  Digital Asset Passports, four-eyes workflow (Draft → Under Review → Approved → Published → Unpublished → Archived,
  approval bound to a content fingerprint), append-only hash-chained audit trail, 10 website modes with dual-key
  activation, 22 gated modules (wallet, USDT payments, KYC/KYB, PoR, redemption, portals…) built but inactive,
  RBAC with 5 staff roles, TOTP MFA + recovery codes, rate limiting, CSP/HSTS, upload scanning, REST API `rc/v1`.
* **63-route website** following the Website Developer Instructions IA (mega-menu, 7-column footer, CTA library).
* **Smart contracts** (Solidity 0.8.28, OpenZeppelin v5): ReserveToken (fail-closed minting), ComplianceRegistry,
  ReserveGuard, RedemptionManager, Treasury, AuditAnchor — **83 tests, 100 % line coverage**, Slither reviewed,
  deploy scripts that refuse mainnet without written authorization; every tokenomics parameter unset.
* **iOS & Android app** (Expo / React Native, TypeScript strict) connected to the same API: registration, login,
  MFA, biometric unlock, programs, passports, documents, eligibility, notifications, support; wallet/purchase/PoR/
  redemption locked until authorized; EAS profiles for TestFlight and Google Play internal testing.
* **Whitepaper** — 33-page publication PDF + editable DOCX + diagram sources; pending items clearly marked.
* **Documentation** — architecture, CMS structure, requirements traceability (~150 rows), security, backup/DR,
  deployment & rollback runbook, test plan, handover checklist (all accounts owned by ReserveChain), content guide.

## Confirmation that all attachments were reviewed

We reviewed every attachment in full and built against them:
1. *ReserveChain_Final_Master_Developer_Instructions_v2.0.pdf* (all 57 pages)
2. *ReserveChain_Website_Developer_Instructions.pdf* (all 25 pages)
3. *Ultrafine Copper powder – IGAS Analysis.png* (CoA 0004512 — transcribed exactly)
4. *IGAS Analysis Nickel Wire.png* (CoA 0004368 — transcribed exactly)
5. *79B38BE6-….png* (official logo — redrawn as SVG brand assets)
6. *ReserveChain Website.zip* (51 reference mock-ups — used for layout density only; invented figures, partner logos,
   token prices and the mis-transcribed nickel certificate in those mock-ups were deliberately **not** reproduced)

A requirement-by-requirement digest is in `docs/ATTACHMENTS-REVIEW.md` and the traceability matrix in
`docs/REQUIREMENTS-TRACEABILITY.md`.

## Principles we held to

Nothing is invented: no quantities, prices, custodians, insurers, partners, people, contract addresses or returns.
Owner-supplied facts are labelled as such. Everything else is "proposed", "in development" or "subject to final
approval" — visibly, on every claim, through a status system driven by the CMS.

*ReserveChain is currently in development. No tokens are being offered or sold through this website.*
