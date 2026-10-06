# ReserveChain.io — Test Plan

> **Status:** Proposed — subject to final approval. Results are recorded per milestone in `docs/acceptance/<milestone>/test-results.md` with evidence (screenshots, CI logs, tx hashes).

> **Mandatory disclosure.** ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.

---

## 1. Strategy

| Level | Tooling | Runs |
|---|---|---|
| Unit — PHP | PHPUnit + WP test suite | Every PR |
| Static — PHP | PHPStan, PHPCS (WordPress standards) | Every PR |
| Unit — Solidity | Hardhat + Chai, `solidity-coverage` | Every PR touching `contracts/` |
| Static — Solidity | Slither | Every PR touching `contracts/` |
| API contract | Playwright API tests / Postman collection against Staging | Every deploy to Staging |
| E2E — web | Playwright (Chromium, Firefox, WebKit) | Every deploy to Staging |
| Accessibility | axe-core in Playwright + manual screen-reader pass | Milestones M3, M9 |
| Performance | Lighthouse CI; k6 load test | M3, M9 |
| Mobile | Jest (unit), Maestro/Detox (E2E) on device matrix | M7 |
| Ops | Backup restore, DR, deployment, rollback drills | M1, M9 |

Entry criteria: build green on Staging, test data seeded (synthetic, no real asset data). Exit criteria: all P1 cases pass; no open critical/high defects; deviations accepted in writing.

## 2. Compliance invariants (run in every cycle)

| ID | Case | Expected |
|---|---|---|
| CI-1 | Mandatory disclosure on every public page, app, PDF | Verbatim text present |
| CI-2 | EU/EEA notice present on site, waitlist and app | Verbatim text present |
| CI-3 | Scan seeded content & templates for prices, supply, purity numbers, counterparty names | None found; placeholders shown |
| CI-4 | Scan for prohibited terms ("investment", "returns", "profit", "APY", "guaranteed") in public copy | None, except inside the disclosure/negations |
| CI-5 | `/config` gated modules (`wallet`, `purchase`, `proof_of_reserves`, `redemption`) | `false` |
| CI-6 | `/me/holdings`, `/me/transactions` | `{enabled:false, items:[]}` |
| CI-7 | Token program parameters unset | "Not yet determined — subject to written approval" |
| CI-8 | Contracts network | Testnet chain IDs only |

## 3. Browsers and devices

| Platform | Browsers / versions |
|---|---|
| Windows 11 | Chrome, Edge, Firefox (latest 2) |
| macOS | Safari, Chrome, Firefox (latest 2) |
| iOS 17+ | Safari (iPhone SE size, iPhone 15/16 size, iPad) |
| Android 13+ | Chrome (small and large phones, tablet) |
| Widths | 360, 390, 768, 1024, 1280, 1440, 1920 px |

Cases: layout, navigation, language switcher, disclosure banner, passport page, Verify (file hashing works on all), forms, print stylesheet for passports.

## 4. Forms

| ID | Case | Expected |
|---|---|---|
| F-1 | Waitlist valid non-EU submission | `pending_confirmation`; confirmation email; audit entry |
| F-2 | Waitlist EU/EEA country (each of 30 codes, parameterised) | `jurisdiction_status=restricted`; EU/EEA notice shown |
| F-3 | Missing consent checkboxes | Rejected with accessible error |
| F-4 | Honeypot filled | Silently rejected |
| F-5 | Rate limit exceeded | HTTP 429 |
| F-6 | Duplicate email | No duplicate row (email_hash unique); neutral message |
| F-7 | Double opt-in link valid / expired / reused | Confirmed / error / idempotent |
| F-8 | Consent version + hash stored | Matches displayed text |
| F-9 | Contact/support form validation & rate limit | As above |
| F-10 | Auth: register, login, MFA setup, MFA verify, refresh, bad code lockout | Per SPEC |
| F-11 | XSS/SQLi payloads in all fields | Escaped / rejected |

## 5. CMS permissions

Parameterised over roles `administrator, rc_compliance_officer, rc_reviewer, rc_registry_manager, rc_editor, rc_auditor` × actions (create, edit, submit, approve own, approve other, publish, unpublish, archive, view waitlist, export waitlist, change site mode, authorize module, view audit, anchor audit, edit users). Expected results = [`CMS-STRUCTURE.md §6`](CMS-STRUCTURE.md#6-role--capability-matrix).

Key negative cases: author cannot approve own item; editor cannot publish via direct REST/`wp_update_post`; auditor cannot save; gated module cannot be enabled without approval reference; non-compliance role cannot view PII.

## 6. Audit logs

| ID | Case | Expected |
|---|---|---|
| AL-1 | Each auditable action (create, transition, login ok/fail, role change, option change, upload, plugin/theme change) | One row with correct actor/action |
| AL-2 | `UPDATE wp_rc_audit_log ...` via SQL (or `wp rc tamper-test`) | Error SQLSTATE 45000 |
| AL-3 | `DELETE FROM wp_rc_audit_log ...` | Error SQLSTATE 45000 |
| AL-4 | Verify chain integrity on clean log | OK |
| AL-5 | Drop triggers as DB root, alter one row, run verify | Reports `content` mismatch at the altered id |
| AL-6 | Delete a middle row as root | Reports broken link at next id |
| AL-7 | Concurrent writes (50 parallel requests) | No fork; chain verifies |
| AL-8 | Anchor on testnet | `Anchored` event; `wp_rc_audit_anchor` row; head matches row at `seq` |
| AL-9 | Export JSONL and recompute hashes with independent script | Matches |

## 7. Accessibility (WCAG 2.2 AA)

Automated axe scan (zero critical/serious); keyboard-only navigation of all flows; visible focus; colour contrast of status pills and copper/nickel on ink/paper; screen reader pass (NVDA + Firefox, VoiceOver + Safari/iOS, TalkBack); reduced-motion respected; form errors announced; language attributes correct for ES/IT.

## 8. Performance

| Metric | Budget (proposed) |
|---|---|
| Lighthouse Performance (mobile) on Home, program, passport | ≥ 90 |
| LCP | ≤ 2.5 s (p75, 4G) |
| CLS | ≤ 0.1 |
| INP | ≤ 200 ms |
| API `/passports/{no}` p95 at 50 rps | ≤ 300 ms (cached) / ≤ 800 ms (uncached) |
| Load test | 200 concurrent users on public pages without errors |

## 9. Smart contracts

| Area | Cases |
|---|---|
| ReserveToken | Constructor params from config; cap; roles granted correctly; only MINTER mints; only BURNER burns; pause blocks transfers/mint; permit works; compliance hook rejects ineligible/frozen/expired/blocked-jurisdiction; compliance disabled path |
| ReserveGuard | Attestation by attestor only; `maxMintable` = units × ratio; ratio unset ⇒ 0 ⇒ mint reverts; mint exceeding cap reverts |
| ComplianceRegistry | Set/clear eligibility; expiry enforcement; jurisdiction block list; access control |
| RedemptionManager | Disabled by default; enable by admin; min/max thresholds; request escrows; approve burns; reject returns; cancel; events |
| Treasury | Withdrawal limits; timelock option; role restrictions |
| AuditAnchor | Only ANCHOR_ROLE; monotonic `seq`; event data |
| General | Reentrancy, role renounce, admin transfer to multisig, gas snapshot, coverage ≥ 95 % lines, Slither findings triaged |

## 10. Testnet

Deploy to Sepolia from config; verify source on explorer; transfer admin to Safe; run scripted scenario (attest → mint within cap → transfer between eligible addresses → blocked transfer to ineligible → pause/unpause → anchor audit head); record all tx hashes. Optional repeat on Polygon Amoy.

## 11. Apps (iOS / Android)

Install from TestFlight / Play internal testing on device matrix (§3); launch with `/config` reachable / unreachable; module flags respected (wallet/holdings hidden or shown as inactive); disclosure & EU/EEA notice visible; login + MFA; secure storage (token not in plain storage); passport view + QR scan; local document hash verify; ES/IT switching; deep links; offline behaviour; accessibility (Dynamic Type / font scale, screen readers); app update flow (OTA vs store build policy).

## 12. Backups and restoration

| ID | Case | Expected |
|---|---|---|
| B-1 | Scheduled backups ran (DB snapshot, dump, bucket replication) | Present, encrypted, non-empty |
| B-2 | Restore latest dump to ephemeral DB | Success; triggers present; chain verifies |
| B-3 | PITR to Staging at given timestamp | Data as of timestamp |
| B-4 | Restore a deleted document version | SHA-256 matches registry |
| B-5 | Off-site immutable copy cannot be deleted with production credentials | Access denied |
| B-6 | Full DR rebuild | Within RTO; report filed |

## 13. Deployment and rollback

| ID | Case | Expected |
|---|---|---|
| D-1 | Deploy tag to Staging via CI | Healthy; post-deploy checklist passes |
| D-2 | Deploy to Production with approval gate | Requires approver; rolling, no downtime |
| D-3 | Migration idempotency (run twice) | No error; single `system.migrated` entry per version change |
| D-4 | Rollback code-only release | Previous version live < 15 min |
| D-5 | Rollback with PITR | Completed within target; audit export archived |
| D-6 | Maintenance mode on/off | 503 page for public; staff access works |

## 14. Defect management

Severity: S1 (blocks release / compliance breach) · S2 (major function broken) · S3 (minor) · S4 (cosmetic). Any compliance-invariant failure (§2) is S1 regardless of technical impact.
