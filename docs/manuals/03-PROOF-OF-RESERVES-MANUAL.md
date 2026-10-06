# 03 — Proof of Reserves Manual

**Readers:** reviewers, compliance officers, administrators, auditors. **Source of truth:** `includes/class-por.php` (reconciliation, exceptions, snapshots, intake, cron, REST); `class-registry.php` and `class-passport.php` (inputs).
**Related:** [02 Registry](02-REGISTRY-AND-PASSPORT-MANUAL.md) · [05 §7 Attestations on-chain](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#7-reserve-attestations) · [01 §8.3 Gated modules](01-CMS-ADMIN-MANUAL.md#83-gated-modules) · [Index](README.md)

> **What this module is, and is not.** It reconciles what the registry can prove against what is declared and, if tokens ever exist, against on-chain supply. It **surfaces problems instead of smoothing them away**. Declared quantities are owner-supplied and are **not** verified reserves. Coverage is never estimated. Nothing is described as "independently verified" unless a reserve report has been approved with status *Verified*. The module does not itself constitute Proof of Reserves. That depends on independent attestation arrangements that are **not yet in place**.

---

## Contents

1. [Access and screen layout](#1-access-and-screen-layout)
2. [How reconciliation works](#2-how-reconciliation-works)
3. [Exception codes and how to resolve them](#3-exception-codes-and-how-to-resolve-them)
4. [Snapshots: create, approve, publish (four-eyes)](#4-snapshots-create-approve-publish-four-eyes)
5. [Recording a reserve attestation](#5-recording-a-reserve-attestation)
6. [Alerts](#6-alerts)
7. [Exports](#7-exports)
8. [On-chain supply reading](#8-on-chain-supply-reading)
9. [What may never be published](#9-what-may-never-be-published)

---

## 1. Access and screen layout

Screen: **ReserveChain → Proof of Reserves** (`http://localhost:8088/wp-admin/admin.php?page=rc-por`).

| Who | Can |
|---|---|
| Reviewer (`rc_approve` + `rc_view_audit`) | View; create snapshots; approve or reject other people's snapshots; record attestations; export |
| Compliance officer | Everything a reviewer can, plus **publish** approved snapshots (`rc_publish`) |
| Administrator | Everything |
| Auditor (`rc_view_audit`) | View and export only |
| Registry manager | **No access.** The menu needs `rc_view_audit`. Registry managers fix the *records* that cause exceptions |

Layout, for each published Metal Program:
1. **Cards:** registered units · declared kg (owner) · verified kg · units in custody · reserve-eligible · token supply · coverage · open exceptions.
2. **Exceptions table:** severity, reference, message.
3. **Buttons:** **Create snapshot** · **Download live reconciliation (JSON)** · **Units (CSV)**.

Below the program panels:
- **Snapshots** table: history, integrity flag, actions.
- **Record a reserve attestation** form.

The top line shows **Publication module: authorized / locked**. Snapshots can be created and approved at any time. Publication requires the gated module `proof_of_reserves`.

> [Screenshot: Proof of Reserves — program cards and exceptions — http://localhost:8088/wp-admin/admin.php?page=rc-por]

---

## 2. How reconciliation works

For each published program, the engine reads every **published** lot, batch, container and coil of that program. It builds each record's passport ([02 §9](02-REGISTRY-AND-PASSPORT-MANUAL.md#9-digital-asset-passports)), then computes the following.

| Figure | Rule |
|---|---|
| Registered units | Count of published passport-type records |
| Declared kg | Sum of **Net weight** of **lots** only. This avoids double counting containers |
| Verified kg | Sum of net weight of lots whose verification status is *Verified* |
| Units in custody | Records with **Custody status** = *Arranged (evidence attached)* |
| Reserve-eligible | Reserve status *Eligible: pending approval* or *Accepted into reserve (attested)* |
| Accepted | Reserve status *Accepted into reserve (attested)* |
| In redemption | Redemption status *Requested* or *Released* |
| Attestation | The **most recently published** Reserve Report for the program (by publication time): record no, date, attestor, units, verified?, stale?, on-chain tx |
| Token supply | Read on-chain (§8). Otherwise the status `not_deployed`, `rpc_not_configured` or `rpc_error` |
| Coverage | Computed **only** when all four hold: (1) the latest attestation is *Verified*; (2) it states reserve units; (3) the program's Token Program has a numeric **Asset-to-token ratio** and **Token field state** *Approved* or *Published*; (4) on-chain supply > 0. Then coverage = units × ratio ÷ supply. Otherwise "not computed" |
| Units Merkle root | Merkle root over the record fingerprints of all units |
| Audit head | The audit chain head at the time of computation |

The result is a deterministic JSON document (schema `reservechain.por/1.0`) with the statement: *"Declared quantities are owner-supplied and are not verified reserves. Coverage is never estimated."*

---

## 3. Exception codes and how to resolve them

Exceptions are sorted **critical → warning → information**. Resolve them by fixing the **underlying record**, through the normal four-eyes workflow. Never by editing the snapshot, and never by hiding the exception.

| Code | Severity | Raised when | How to resolve |
|---|---|---|---|
| `reserve_without_custody` | **Critical** | A unit's reserve status is *Eligible* or *Accepted*, but its custody status is not *Arranged* | Either attach custody evidence (a `rc_custody` intake record with a document) and set custody status to *Arranged*, or set the reserve status back to *Pending*. Reserve eligibility without custody must never be published |
| `unfingerprinted_document` | **Critical** | A document linked to the unit has no SHA-256 | Open the Document record, attach the file (the fingerprint is computed on save), and have it re-approved. If the file is lost, unlink the document and record why |
| `insurance_expired` | **Critical** | A linked insurance record's **Period end** is in the past | Attach the renewed policy as a **new** insurance record with a new document, and archive the old one. If cover has lapsed, set the reserve status to *Pending* or *Excluded* and inform compliance |
| `supply_without_attestation` | **Critical** | On-chain supply > 0, but the latest attestation is not *Verified* | **Incident.** Tokens must not exist without a verified attestation. Follow [05 §10](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#10-incident-response): pause the token, then investigate the mint transactions |
| `supply_exceeds_reserve` | **Critical** | Supply > attested units × approved ratio | **Incident.** Same response as above. Check the attestation, the ratio and the mint history |
| `no_coa` | Warning | A lot has no Certificate of Analysis linked (directly or inherited) | Enter the CoA ([02 §6](02-REGISTRY-AND-PASSPORT-MANUAL.md#6-entering-a-certificate-of-analysis-coa)) and link it through **Applies to** |
| `missing_weight` | Warning | A lot has no net weight | Enter the net weight from the weight certificate, with its source. If no certificate exists, the exception stays. **Do not** enter a declared figure without saying so in the data source reference |
| `redemption_overlap` | Warning | A unit is *Accepted* into reserve while a redemption is open (*Requested* or *Released*) | Risk of double counting. When the redemption releases the unit, set the reserve status to *Excluded* after delivery. Until then, document the overlap in the snapshot note |
| `insurance_expiring` | Warning | Insurance ends within 30 days | Start the renewal; attach the new policy before expiry |
| `stale_valuation` | Warning | A unit *Accepted* into reserve has a valuation with no date, or a date older than 180 days | Obtain an updated independent valuation and add it as a new valuation record. (A unit with **no** valuation record does not raise this exception. Check for those separately in the units CSV) |
| `stale_attestation` | Warning | The latest published reserve report is undated or older than 90 days | Obtain a new attestation (§5). If the attestation is published on-chain, the guard also stops minting once its own window expires ([05 §7](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#7-reserve-attestations)) |
| `owner_supplied_only` | Info | A CoA linked directly to the unit has provenance *Owner-supplied* | Expected in the current phase. It is resolved only by independent verification (provenance *Independently verified*, with the attestation attached). Never by changing the label |
| `no_attestation` | Info | No reserve report is published for the program | Expected until an independent attestor is appointed |

**Procedure for working the exceptions list**

**Before you start:** you are a reviewer or compliance officer. Fixes to records are made by a registry manager.

**Steps**
1. Open the PoR screen. Note every **critical** exception: its reference and code.
2. For each reference, open the record (search its record number in the relevant Asset Registry list).
3. Raise a ticket to the registry manager with the code and the fix from the table above.
4. The registry manager fixes the record and submits it. You review and approve it, and a publisher publishes it ([01 §4](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow)).
5. Reload the PoR screen. The exception disappears as soon as the corrected record is **published**.

**Result:** the open exception count falls. The daily job notices that the exception set changed and sends an alert (§6).

---

## 4. Snapshots: create, approve, publish (four-eyes)

A snapshot freezes the reconciliation as **canonical JSON**, together with its **SHA-256** and the units **Merkle root**. Every step is written to the audit chain with the snapshot's SHA-256. Anchoring the audit chain therefore also anchors the snapshots ([01 §14.4](01-CMS-ADMIN-MANUAL.md#144-anchor-the-chain-head-on-chain)).

Snapshot statuses: `draft` → `approved` → `published` (the previous published snapshot becomes `superseded`). From `draft` or `approved`, a snapshot can go to `rejected`.

The **Integrity** column re-hashes the stored JSON every time the page loads:
- *intact* — the JSON still matches its SHA-256;
- *MODIFIED* — the database was altered. This is a **SEV-2** incident ([07 §8.2](07-OPERATIONS-BACKUP-DR-MANUAL.md#82-audit-chain-failure)). Modified snapshots cannot be approved or published.

### 4.1 Create a snapshot

**Before you start**
- You are a reviewer, compliance officer or administrator.
- Critical exceptions are resolved, or explicitly accepted by compliance for the record.

**Steps**
1. On the program's panel, click **Create snapshot**.
2. The message reads "Snapshot #N created as draft. A different reviewer must approve it."

**Result:** a new row with status `draft` and your login. `por.snapshot_created` is logged with the SHA-256, the Merkle root and the exception count.

**Automatic snapshots:** every **Monday**, the daily job creates a draft for each program, with the note "Weekly automatic draft" and creator "system (cron)". Any reviewer may approve these. Reject them if they are not needed.

### 4.2 Approve or reject

**Before you start:** you hold `rc_approve` and **did not** create the snapshot.

**Steps**
1. Click **JSON** on the row and download the snapshot. Review the inventory, the attestation, the exceptions and the units.
2. Check that the file's SHA-256 equals the SHA-256 shown in the row:
   - Windows: `certutil -hashfile reservechain-por-N-….json SHA256`
   - macOS / Linux: `shasum -a 256 …`
3. Click **Approve**, or **Reject** if it is wrong or no longer needed.

**Result:** status `approved` (or `rejected`). `por.snapshot_approve` / `por.snapshot_reject` is logged.

**If something goes wrong**
- "Four-eyes rule: the creator of a snapshot cannot approve it." → ask another reviewer.
- "Only drafts can be approved, by a reviewer." → the snapshot is not a draft, or you lack `rc_approve`.

### 4.3 Publish

**Before you start**
- You hold `rc_publish` (compliance officer or administrator).
- The snapshot is `approved`.
- The gated module **`proof_of_reserves` has been authorized in writing** ([01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)). It is **locked today**.

**Steps**
1. Click **Publish** on the approved row.
2. Check the public outputs:
   - `GET /wp-json/rc/v1/por/snapshots` lists the snapshot;
   - `GET /wp-json/rc/v1/por/snapshots/<id>` returns it with `"intact": true`;
   - the `[rc_por_history]` table on the Proof of Reserves page shows it.

**Result:** status `published`. The previous published snapshot for that program becomes `superseded`. `por.snapshot_publish` is logged.

**If something goes wrong:** "Publication is locked: the Proof of Reserves module has not been authorized." is the expected behaviour until written authorization exists.

> [Screenshot: Snapshots table with integrity pills and actions — http://localhost:8088/wp-admin/admin.php?page=rc-por#snapshots]

---

## 5. Recording a reserve attestation

The intake form uploads the report, fingerprints it, creates a **Document** and a **Reserve Report** as drafts, and submits both to the review queue.

**Before you start**
- You are a reviewer, compliance officer or administrator.
- You have the original report from the attestor. An attestor must be appointed by ReserveChain. Until then, any report is at most *pending verification*.

**Steps**
1. Scroll to **Record a reserve attestation**.
2. **Program:** select it.
3. **Attestor:** the legal name as on the report.
4. **Report date:** the report's "as of" date.
5. **Attested reserve units:** exactly as stated in the report, in the program's unit. Leave it **empty** if the report states none.
6. **Report document:** choose the PDF, PNG or JPG. It is rejected if it is not a genuine PDF or contains active content.
7. **On-chain attestation tx (optional):** the `postAttestation` transaction hash, if the attestor has already posted on testnet ([05 §7](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#7-reserve-attestations)).
8. Click **Submit attestation for review**.

**Result:**
- The message reads "Attestation submitted to the review queue (RC-…-RSV-…). An independent reviewer must approve it."
- Two items are under review: `Reserve attestation — <program> — <date>` and its `(report)` document, both with status *Pending verification*.
- `por.attestation_submitted` is logged with the file's SHA-256.

**Then**
1. A **different** reviewer approves both items. If the attestor is an appointed, independent party and the report has been checked, the reviewer may first ask the preparer to set the status to *Verified* ([02 §8](02-REGISTRY-AND-PASSPORT-MANUAL.md#8-verification-statuses)).
2. A publisher publishes both. The attestation now feeds reconciliation, and the passports of units listed in **Assets covered** (edit the Reserve Report to add them).

**If something goes wrong**
- "Upload rejected: …" → see [01 §5.2](01-CMS-ADMIN-MANUAL.md#52-add-a-document).
- The attestation does not appear in reconciliation → it is not published yet, or another report was published more recently. The engine uses the most recently **published** report.

---

## 6. Alerts

- A **daily job** (`rc_por_daily`) recomputes every program. When the **set of exceptions** changes compared with the previous day (any new, removed or changed exception), it:
  - appends `por.exceptions_changed` to the audit trail;
  - emails *Settings → Contact / support inbox email* (or the WordPress admin email) with the subject `[ReserveChain] Reconciliation exceptions changed`. The email lists all open exceptions as `severity|code|reference`, with a link to the PoR screen.
- No email means nothing changed. It does not mean there are no exceptions.

**When you receive the alert**
1. Open the PoR screen and compare with the list in the email.
2. New **critical** exception → handle it the same day (§3). For `supply_*` codes, follow the incident response.
3. Record the review in the operations log.

---

## 7. Exports

You need `rc_view_audit`. Every export is logged.

| Button | File | Use |
|---|---|---|
| **JSON** (snapshot row) | `reservechain-por-<id>-<sha12>.json` | The canonical JSON **exactly as hashed**. Its SHA-256 must equal the snapshot's SHA-256. Give this to auditors |
| **Download live reconciliation (JSON)** | `reservechain-reconciliation-<timestamp>.json` | Working copy of the current state (not frozen, not hashed) |
| **Units (CSV)** | `reservechain-units-<date>.csv` | One row per unit: record no, type, title, net weight, verification, custody, reserve, redemption, CoAs, document count, Merkle root. Values beginning with `= + - @` are neutralised |

Public, read-only endpoints:
- `GET /wp-json/rc/v1/por` returns a per-program overview: inventory, supply status, attestation (record no, date, verified, stale), coverage, exception **counts**, latest published snapshot. It is available even while the module is locked, and then reports `"module_enabled": false`.
- `GET /wp-json/rc/v1/por/snapshots` and `…/por/snapshots/<id>` work only when the module is authorized.

---

## 8. On-chain supply reading

The engine reads `totalSupply()` and `decimals()` with a JSON-RPC `eth_call`. It does this only if **all** of the following hold:
1. The program has a Token Program whose **Contract address** is a valid `0x…` address.
2. **ReserveChain → Web3 settings → Testnet RPC URL** is set (`http://localhost:8088/wp-admin/admin.php?page=rc-web3`).
3. **Settings & modules → Network → Chain ID** is 11155111 (Sepolia), 80002 (Amoy) or 31337 (local).

| Supply card shows | Meaning | Action |
|---|---|---|
| `not_deployed` | No valid contract address on the token program | Expected today |
| `rpc_not_configured` | Address set, but no RPC, or not a testnet chain id | Set the RPC in Web3 settings ([04 §5.1](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#51-configure-web3-settings-administrator)) |
| `rpc_error` | The RPC did not answer | Check the RPC provider and key; try again later |
| a number | Supply read at `read_at` | Compare with your own explorer reading |

Mainnet is never read. The token address in the Token Program is entered through the workflow, after deployment ([05 §2](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#2-testnet-deployment)).

---

## 9. What may never be published

The following must **never** appear on the website, in the app, in a snapshot note, in a press statement or in a report, unless the stated condition is met in writing:

1. **Declared quantities presented as reserves.** "Declared kg (owner)" is never "reserves", "backing" or "holdings".
2. **Any coverage ratio, "% backed", "fully backed" or "100% backed"** unless the engine computed it (§2) from a *Verified* attestation by an appointed independent attestor. Even then, it must be accompanied by the disclosure and must not be described as a guarantee.
3. **"Verified", "audited", "attested", "insured" or "in custody"** for anything whose underlying record is not *Verified* with the required evidence ([02 §8](02-REGISTRY-AND-PASSPORT-MANUAL.md#8-verification-statuses)).
4. **Published snapshots** before the `proof_of_reserves` module is authorized in writing. The code enforces this.
5. **A snapshot with critical exceptions**, unless compliance has documented the reason in the snapshot note and the publication decision.
6. **Valuation values or prices**, unless an independent valuation exists and compliance has approved its publication.
7. **Names of attestors, custodians, insurers or laboratories** that have not been formally appointed or confirmed.
8. **Restricted documents** (data-room audiences), or exact vault locations.
9. **Token supply or contract addresses** before the `contract_info` module is authorized, and never mainnet figures without written authorization.
10. **Edited or "corrected" snapshot JSON.** Snapshots are never edited. Create a new snapshot instead.
