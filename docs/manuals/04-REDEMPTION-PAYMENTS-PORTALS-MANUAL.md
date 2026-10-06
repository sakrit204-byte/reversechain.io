# 04 — Redemption, Payments and Portals Manual

**Readers:** operations, compliance officers, reviewers, administrators. **Source of truth:** `includes/class-redemption.php`, `class-web3.php`, `class-portal.php`, `class-intake.php`, `class-compliance.php`, `class-settings.php`.
**Related:** [01 §8 Modes and modules](01-CMS-ADMIN-MANUAL.md#8-website-modes-sections-and-module-authorizations) · [01 §12 Eligibility](01-CMS-ADMIN-MANUAL.md#12-compliance-page-and-user-eligibility) · [05 Smart contracts](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md) · [Index](README.md)

> **Everything in this manual is built but inactive.** Redemption, payments, wallet linking, the data rooms and asset intake are switched **off**. Each needs the authorizations listed in [§1](#1-authorizations-required-before-anything-goes-live), and some need a locked site mode as well. Until then, the only live parts are: the Participant Portal sign-in and dashboard, public documents, **test** redemptions in the development environment, and notes on intake records.

---

## Contents

1. [Authorizations required before anything goes live](#1-authorizations-required-before-anything-goes-live)
2. [Redemption workflow](#2-redemption-workflow)
3. [Test redemptions (development only)](#3-test-redemptions-development-only)
4. [Wallet linking](#4-wallet-linking)
5. [Payment intents (USDT, testnet)](#5-payment-intents-usdt-testnet)
6. [Participant Portal](#6-participant-portal)
7. [Asset intake pipeline](#7-asset-intake-pipeline)
8. [Data rooms: audiences and signed downloads](#8-data-rooms-audiences-and-signed-downloads)

---

## 1. Authorizations required before anything goes live

Every item below needs **written authorization from ReserveChain**. It must also have legal sign-off on the definitive documentation, an appointed KYC/KYB provider, an independent smart-contract audit where tokens are involved, and the technical gates listed. Locked modes need a server constant **and** a reference ([01 §8.1](01-CMS-ADMIN-MANUAL.md#81-website-modes)). Gated modules need a reference entered by an administrator ([01 §8.3](01-CMS-ADMIN-MANUAL.md#83-gated-modules)).

| Feature | Gated module(s) | Site mode | Other technical prerequisites |
|---|---|---|---|
| Live redemption requests | `redemption` | **Redemption** (locked: `RC_ALLOW_MODE_REDEMPTION`) | Redemption minimum (token program or settings) **and** fee schedule set with approved wording; published token program; RedemptionManager enabled on-chain ([05 §8](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#8-redemption-operations)) |
| Unit selection by the requester (API) | `unit_selection` | — | `redemption` |
| Dispatch and delivery steps | `logistics` | — | `redemption` |
| Wallet linking | `wallet` | — | Testnet RPC configured; platform network is a testnet |
| USDT payment intents | `purchase` **and** `usdt_payments` | **Live Offering** or **Early Participation** (both locked) | RPC, USDT contract and treasury address in Web3 settings; at least two staff holding `rc_manage_payments` |
| Investor data room | `investor_portal` | — | Users with role `rc_investor` |
| Enterprise data room | `enterprise_portal` | — | Users with role `rc_enterprise_client` |
| Asset intake form and pipeline | `asset_owner_portal` | (Enterprise Onboarding recommended) | Mail delivery working; `post_max_size` large enough ([KI-04](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)) |
| Holdings and transactions in apps | `holdings` | — | Token deployed and indexed |
| Public app registration | `app_registration` (open module) | — | Mail delivery; MFA available to users |

**Go-live sign-off sheet (copy for each feature)**

| Step | Who | Evidence |
|---|---|---|
| Written authorization (reference no.) | Authorized signatories | Signed document |
| Counsel sign-off on user-facing texts and terms | Counsel | Email or memo |
| Staging test of the full flow with test accounts | Ops + compliance | Test record IDs, audit entries |
| Server constant set (locked modes only) | Server operator | `wp-config` diff, deploy log |
| Module switched on with the reference | Administrator | `settings.changed` audit entry |
| Second-person check of the audit entry | Compliance officer | Signature in the authorization file |
| Post-go-live review after 24 h | Compliance | Review notes |

---

## 2. Redemption workflow

Screen: **ReserveChain → Redemptions** (`http://localhost:8088/wp-admin/admin.php?page=rc-redemptions`).

- **Who sees it:** users with `rc_manage_registry`, `rc_approve`, `rc_manage_compliance` or `rc_view_audit`.
- Redemption records cannot be edited in the generic registry editor. Such links redirect to this screen, and direct field writes are ignored.

### 2.1 States

```
requested ─start_review─▶ compliance_review ─approve─▶ approved ─record_burn─▶ tokens_burned
   ─release_custody─▶ custody_released ─dispatch─▶ in_logistics ─confirm_delivery─▶ delivered
Side exits:  reject (staff, any state before the burn) · cancel (requester, before approval)
             hold (staff, any open state) ─resume─▶ the state before the hold
```

This mirrors `RedemptionManager.sol`. On-chain, tokens are escrowed at request, burned at approval, and returned on reject or cancel. Off-chain, **reject and cancel are therefore impossible once tokens are burned**.

### 2.2 Who does what, and the evidence for each step

| Step (button) | From → To | Who may act | Who may **not** (four-eyes) | Evidence the system demands |
|---|---|---|---|---|
| *Request* (API / portal) | — → requested | The token holder, through the authenticated API | — | Compliance re-check passes; amount > 0 and ≥ minimum; minimum and fees determined; collection or delivery (an address of at least 10 characters for delivery, stored encrypted); units free |
| **Save unit selection** | requested / compliance_review | `rc_manage_registry` (registry manager) | The requester | Units belong to the token program's metal program, are not in another open redemption, and have redemption status *Not available* or *Eligible* |
| **Start compliance review** | requested → compliance_review | `rc_manage_registry` (the "preparing operator") | The requester | At least one container or coil, or a lot, is selected |
| **Approve redemption** | compliance_review → approved | `rc_approve` (reviewer, compliance) | The requester, the creator, and the operator who prepared it | Eligibility is re-checked and snapshotted; minimum and fees are determined; units are selected. The **approval fingerprint** binds the requester, token program, amount, units, lot, delivery method and address hash |
| **Record token burn** | approved → tokens_burned | `rc_manage_registry` | The approver | **Burn transaction hash** (`0x` + 64 hex), unique across redemptions; platform network is a testnet; optional on-chain request ID |
| **Release from custody** | tokens_burned → custody_released | `rc_manage_registry` | — | **Custody release document**: a registry Document with a SHA-256. Unit redemption status becomes *Released* |
| **Hand over to logistics** | custody_released → in_logistics | `rc_manage_registry` (needs the `logistics` module) | — | **Carrier / collecting party**, **tracking / collection reference**, and **at least one customs / logistics document** (fingerprinted) |
| **Confirm delivery** | in_logistics → delivered | `rc_manage_registry` (needs `logistics`) | The person who dispatched | **Delivery confirmation document** (fingerprinted). Unit status becomes *Delivered* |
| **Reject** | requested / compliance_review / approved / on_hold (pre-burn) → rejected | `rc_approve` | The requester | **Reason** (at least 5 characters). Units are released back |
| **Place on hold** | any open state → on_hold | `rc_manage_compliance` or `rc_approve` | The requester | **Reason** (at least 5 characters) |
| **Release hold** | on_hold → previous state | `rc_approve` | The person who placed the hold | Optional comment |
| *Cancel* (API / portal) | requested / compliance_review → cancelled | The requester only | — | Optional reason |

Additional rules:
- Steps after approval check that the record still matches the approval fingerprint. Otherwise: "The redemption differs from what was approved. It must be re-approved."
- Protective exits (reject, cancel, hold) stay available even while the module is off.
- Each step writes the record's timeline, an audit entry (`redemption.<action>`, including the evidence and fingerprint) and an in-app notification to the requester. Staff identities are never shown to the requester.
- The delivery address is encrypted. **Reveal (logged)** is visible only to registry managers and compliance, and every reveal is audit-logged.

### 2.3 Process a redemption (operator and approver)

**Before you start**
- The module is live (§1), or this is a test record (§3).
- You are signed in with MFA.
- A second qualified colleague is available for the four-eyes steps.

**Steps**
1. Open **Redemptions** and click the record number in the **Requested** table.
2. Read the **Summary**: requester, token program, amount, minimum, fees, delivery method. Read the **Compliance snapshots** (*At request* / *Current*).
3. **Operator:** in **Units**, choose containers and coils, or a whole lot, then click **Save unit selection**.
4. **Operator:** in **Actions**, open **Start compliance review**, add a comment if needed, then confirm.
5. **Approver (a different person):** open the record and check:
   - the units against the registry;
   - eligibility (KYC/KYB, AML, sanctions, jurisdiction);
   - the amount against the minimum and the token-to-unit terms.

   Then open **Approve redemption** and confirm.
6. **On-chain:** a `REDEMPTION_OPERATOR_ROLE` holder calls `approve(id, fulfilmentRefHash)` on the RedemptionManager. This burns the escrow ([05 §8](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#8-redemption-operations)).
7. **Operator (not the approver):** open **Record token burn**, paste the burn transaction hash (and the on-chain request ID), then confirm.
8. **Custody:** register the custodian's release note as a Document ([01 §5.2](01-CMS-ADMIN-MANUAL.md#52-add-a-document)). Then **Release from custody** and select it.
9. **Logistics:** register the customs and transport documents. Then **Hand over to logistics** with the carrier and tracking reference.
10. **A different operator:** register the signed delivery note. Then **Confirm delivery** and select it.

**Result:**
- The state is *Delivered (completed)*. The timeline shows each step with its evidence, and the units show redemption status *Delivered*.
- The audit trail holds the complete chain of `redemption.*` entries.

> [Screenshot: Redemption detail — Summary, Units, Evidence, Timeline, Actions — http://localhost:8088/wp-admin/admin.php?page=rc-redemptions&id=<ID>]

**If something goes wrong**
- *Button greyed out, with the reason shown next to it:* typical reasons are a four-eyes conflict, a module that is off, or a state that does not allow the action. Ask a colleague, or check §1.
- *"Compliance checks are not complete (…)" at approval:* a check expired or changed. Fix it on the Compliance page ([01 §12](01-CMS-ADMIN-MANUAL.md#12-compliance-page-and-user-eligibility)), or reject with a reason.
- *"Tokens have already been burned; the redemption can no longer be rejected.":* after the burn, the only way out is delivery, or a hold followed by manual resolution with counsel.
- *"Another change is in progress. Please retry.":* someone else is acting on the same record. Wait a few seconds.
- *On-chain `reject` or `cancel` reverts:* the requester became ineligible in the ComplianceRegistry ([05, known limitation 4](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#11-known-limitations)).

---

## 3. Test redemptions (development only)

Test records let you rehearse the workflow while the module is off. They are allowed **only when `RC_ENV=development`**. Each is labelled **[TEST]** / "Test — module inactive" everywhere. Test records never involve real tokens or assets.

**Before you start**
- You are on the local or development stack, as a registry manager or administrator.
- A requester user exists who is **eligible**: country eligible, and KYC (or KYB) + AML + sanctions *Approved*. The requester must not be you. For example, set this up for `demo.app` on the Compliance page.
- A token program exists, linked to a metal program. It may be a draft.

**Steps**
1. Open **Redemptions**, then the panel **Create a test redemption**.
2. Fill in:
   - **Requester:** login or email;
   - **Token program**;
   - **Containers / coils** (or **Or a whole lot**);
   - **Token amount**;
   - **Delivery method** (and an address for delivery).
3. Click **Create test redemption**.
4. Run the steps in §2.3 with two or three different staff accounts (for example `demo.registry` as operator and `demo.reviewer` as approver). For the burn step, use a dummy hash of the right format, such as `0x` followed by 64 hex digits.
5. Afterwards, **Reject** test records that are still open. This returns the units to their previous status. Leave the audit entries as they are.

**Result:** a `[TEST] Redemption RC-RDM-…` record moves through the states. Unit statuses change and are restored correctly.

**If something goes wrong:** "Test redemptions can only be created by staff in the development environment." means you are on staging or production, or you lack `rc_manage_registry`.

---

## 4. Wallet linking

Users prove ownership of a wallet by signing a text message, in the EIP-4361 / Sign-In-with-Ethereum style, with `personal_sign`. **There is no transaction and no fee.** The server rebuilds the message, recovers the signer and compares it with the address.

| Rule | Value |
|---|---|
| Module | `wallet` (gated) |
| Networks | Sepolia 11155111, Amoy 80002, local 31337 only. The RPC's `eth_chainId` must match the platform network |
| Wallets per user | at most 3; a wallet can belong to one user only |
| Challenge | single use, valid 10 minutes |
| Rate limit | 10 challenge or verify calls per 10 minutes per user |

### 4.1 How a user links a wallet (for support staff)

1. The user opens **Portal → Wallet** and connects a browser wallet (MetaMask or another EIP-1193 wallet) on the network shown.
2. They click **Connect wallet & link**. The site requests a challenge (`POST /me/wallet/challenge`).
3. The wallet shows the message "Link this wallet to your ReserveChain account. No transaction, no fees." with the domain, the chain and a nonce. The user signs it.
4. The site sends the signature (`POST /me/wallet/verify`). On success: "Wallet linked."

The audit trail records `wallet.challenge` and `wallet.linked` (or `wallet.link_failed` with a reason). The user receives a "Wallet linked" notification.

### 4.2 Staff view and support

- **Edit User → Linked wallets** (`http://localhost:8088/wp-admin/user-edit.php?user_id=<ID>`) shows addresses, chains, link times and explorer links. Compliance and the user themselves can see it.
- The Compliance page shows the first 10 characters of each wallet.
- **Unlinking** is done by the user (`DELETE /me/wallets/<address>`). It is refused while a payment intent uses the wallet. Staff have no unlink button. In a fraud case, freeze the address on-chain ([05 §6](05-SMART-CONTRACT-AND-TOKEN-ADMIN.md#6-compliance-registry-updates)) and handle it as an incident.

| Failure reason (`wallet.link_failed`) | Meaning | Tell the user |
|---|---|---|
| `expired` / `no_challenge` | More than 10 minutes passed, or the page was reloaded | Click Connect again |
| `mismatch` / `message_mismatch` | A different account or message was signed | Use the same account in the wallet |
| `chain_changed` | Staff changed the network in between | Retry |
| `bad_signature` | The signature does not match the address | Retry; use a standard wallet |
| "This wallet cannot be linked to your account." | It is linked to another user | Contact support (possible fraud) |

**Never ask a user for a private key or seed phrase.** The portal says so explicitly.

---

## 5. Payment intents (USDT, testnet)

There is **no pricing logic anywhere**. Staff enter an amount from an approved basis document. A **different** staff member approves it. The approval is bound to a fingerprint of the user, wallet, program, amount, treasury, token contract, chain and required confirmations. The system then verifies the user's on-chain transfer.

### 5.1 Configure Web3 settings (administrator)

Screen: **ReserveChain → Web3 settings** (`http://localhost:8088/wp-admin/admin.php?page=rc-web3`). You need `rc_manage_settings`.

1. **Testnet RPC URL:** an HTTPS JSON-RPC endpoint for the platform network. Plain HTTP is allowed only in development. On save, the server checks `eth_chainId` and refuses anything that is not the configured testnet.
2. **USDT contract (testnet token)**, **Treasury address**, and the optional **ComplianceRegistry address**: EIP-55 checksummed, or all-lowercase. The zero address is refused.
3. **Required confirmations** (1–100, default 6) and **Intent validity after approval (hours)** (1–720, default 72).
4. Click **Save Web3 settings**. The panel shows "RPC status: reachable, chain id matches". `web3.settings` is logged.

> [Screenshot: Web3 settings — http://localhost:8088/wp-admin/admin.php?page=rc-web3]

### 5.2 Intent lifecycle

```
created (user request) ─Set amount (staff A)─▶ created + amount ─Approve (staff B)─▶ awaiting_tx
   ─user submits tx hash─▶ confirming ─(cron every 5 min / Re-check now)─▶ confirmed | failed
awaiting_tx past expiry ─▶ expired          Cancel (created / awaiting_tx) ─▶ failed ("cancelled by staff")
```

Each user may have at most 3 open intents, and may create at most 5 per hour.

### 5.3 Approve an amount (four-eyes)

Screen: **ReserveChain → Payments (gated)** (`http://localhost:8088/wp-admin/admin.php?page=rc-payments`). You need `rc_manage_payments`, which only administrators hold by default. Four-eyes therefore needs either two named administrators, or the capability granted to a second role. To grant it, an administrator runs `W cap add rc_compliance_officer rc_manage_payments` after written approval, and records the change, because it is audit-relevant. **Every deploy resets the `rc_*` roles**, so a grant made this way must be re-applied after each deploy ([KI-11](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)).

**Before you start**
- The gate is open (§1).
- The user's **Overall** status is *eligible subject to final approval*.
- You have the approved basis document with the amount in USDT **minor units** (USDT uses 6 decimals, so 1 USDT = `1000000`).

**Steps**
1. **Staff A:** on an intent with status `created`, enter **USDT minor units** and **Basis / document reference**, then click **Set amount**. The message reads "Amount recorded. A different staff member must approve it."
2. **Staff B** (not A, and not the user): check the amount against the basis document, then click **Approve (second person)**. The status becomes `awaiting_tx`, with an expiry. The user is told to send exactly that amount from the linked wallet to the treasury.
3. The user submits the transaction hash from the portal. The status becomes `confirming`.
4. A job every 5 minutes, or **Re-check now**, verifies the transaction. It checks all of the following:
   - receipt status = 1;
   - the block is after the approval block;
   - an ERC-20 `Transfer` from the configured USDT contract, from the linked wallet to the treasury, with **exactly** the approved value;
   - at least the required confirmations.

**Result:** `confirmed`. The **Verification evidence** column holds the receipt, the matched log and the confirmations. `payment.confirmed` is logged, and the user is told that any allocation remains subject to final approval.

### 5.4 Failure reasons

| `failure_reason` | Meaning | Action |
|---|---|---|
| `approval fingerprint mismatch` | Intent data changed after approval (database tampering, or a settings change) | SEV-2 investigation; do not re-approve the same intent |
| `transaction reverted (status != 1)` | The transfer failed on-chain | The user retries with a new intent; nothing was received |
| `transfer predates the approval` | The user reused an older transaction | Cancel; explain the process |
| `no matching USDT Transfer(from wallet → treasury, exact amount) in receipt` | Wrong token, wrong sender, wrong recipient or wrong amount | Check the evidence. Refunds of wrong transfers are a manual treasury process under counsel guidance |
| `transaction not found on chain` | No receipt within the expiry + 1 day | Ask the user for the correct hash |
| `cancelled by staff` | Cancelled manually | — |
| expired (status) | No transfer before the expiry | The user must not send funds; create a new intent if needed |

While the RPC is unreachable, or the chain does not match, an intent **stays** in `confirming`, with the reason under `evidence.pending`. This is not a failure. Fix the RPC instead.

---

## 6. Participant Portal

The portal is the `[rc_portal]` shortcode on `/portal/`. It is a single-page app on top of the `rc/v1` API.

| Area | What participants see | Gate |
|---|---|---|
| Sign in → MFA | Email + password, then a TOTP or recovery code | — |
| Register | Account creation | Open module `app_registration` |
| Overview, Profile, Eligibility, Security & MFA, Notifications, Programs, Passports, Support | Always available after sign-in | — |
| Documents | Restricted documents for their audiences (§8) + the public library | Audience gates |
| Holdings & transactions, Redemption, Wallet | "Inactive — subject to final approval" panels | `holdings`, `redemption`, `wallet` |

Behaviour:
- Automatic sign-out after 15 minutes of inactivity.
- Signing out ends all of the user's sessions (website and apps).
- Country of residence and entity type can only be changed by compliance.
- Participant-only roles (`rc_investor`, `rc_enterprise_client`, `rc_custodian`) are redirected from wp-admin to `/portal/` and do not get the admin bar.

**Support tasks**
- *User locked out of MFA:* there is no self-service reset. Verify the identity out of band, then run the break-glass reset ([01 §2.5](01-CMS-ADMIN-MANUAL.md#25-lost-device-and-no-recovery-codes-break-glass)).
- *User wants to close the account:* in the app, Account → Delete account (`POST /me/delete`). On the web, the page `/support/delete-account/`. Staff accounts are closed only by an administrator.
- *Data export request:* compile the user's profile, eligibility and support tickets from wp-admin, and log the request.

> [Screenshot: Participant Portal dashboard — http://localhost:8088/portal/]

---

## 7. Asset intake pipeline

Asset owners submit material through `[rc_asset_intake]`, on `/enterprise/asset-owners/`. Each submission becomes a private **Asset intake** record (`RC-INT-000123`), under **ReserveChain → Asset intake** (`http://localhost:8088/wp-admin/edit.php?post_type=rc_intake`).

- **Who:** `rc_manage_intake` (administrator, compliance officer, registry manager, reviewer). Confirming final decisions needs `rc_approve`. If the menu is missing for non-administrators after a deploy, see [KI-12](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register).
- **Gate:** module `asset_owner_portal`. While it is off, the form is not shown, submissions are refused, and **pipeline actions are disabled**. Only internal notes are possible.
- Certificates (PDF, JPG, PNG or WebP; up to 5 files of 20 MB each) are stored in `uploads/rc-private/intake/`, SHA-256-fingerprinted, and streamed only to staff through a logged **Download**.
- A submission is **not** an acceptance, a valuation or a commitment.

States: **Received → Initial review → Due diligence → Accepted for onboarding** or **Declined**.

### 7.1 Work a submission

**Steps**
1. Open the record. Read the **Submission** box: organisation, contact, role, material, declared quantity (*owner-declared, unverified*), location, description, consents, certificates with SHA-256.
2. **Pipeline** box: choose an **Assignee** and click **Assign**.
3. Click **Start initial review**. After the first checks, click **Move to due diligence**. A comment is optional; it is stored as an internal note.
4. During due diligence, record findings with **Comment**. Notes are internal and never sent to the submitter.
5. **Propose a decision:** **Propose: accept for onboarding** (only from due diligence) or **Propose: decline** (any open state).
6. **A different user with `rc_approve`**, who is neither the proposer nor the submitter, clicks **Confirm decision**. If the submission changed after the proposal, the proposal is withdrawn automatically and must be made again.
7. The submitter receives an email and a notification at every status change.
8. After acceptance, register the asset in the registry ([02](02-REGISTRY-AND-PASSPORT-MANUAL.md)). Register the certificates as Documents from the original files, not from the intake copies, unless they are identical. Compare the SHA-256 values.

**Result:** `intake.status` entries with the fingerprint, and `intake.decision_proposed` and confirmation entries in the record's **Audit log** box.

> [Screenshot: Asset submission — Submission, Pipeline, Internal notes — http://localhost:8088/wp-admin/post.php?post=<ID>&action=edit]

**If something goes wrong:** "A different user with approval rights must confirm." means you proposed the decision yourself. Ask a colleague. To withdraw your proposal, click **Withdraw proposal**.

---

## 8. Data rooms: audiences and signed downloads

Every Document has an **Audience** ([01 §5.2](01-CMS-ADMIN-MANUAL.md#52-add-a-document)):

| Audience | Who can see it | Extra gate |
|---|---|---|
| Public | Everyone (passports, library, `/verify`) | — |
| Investors (data room) | Role `rc_investor`, document staff | Module `investor_portal` (for members) |
| Enterprise clients | Role `rc_enterprise_client`, document staff | Module `enterprise_portal` (for members) |
| Auditors | Role `rc_auditor`, document staff | — |
| Custodians | Role `rc_custodian`, document staff | — |
| Staff only | administrator, compliance, registry manager, reviewer, editor | — |

"Document staff" means administrators and compliance officers. They see every audience.

How restricted files are handled:
- When the audience is set to anything other than *Public*, the file is **moved** to `wp-content/uploads/rc-private/`. That folder denies direct access, and the file gets an unguessable name. Generated image previews are deleted.
- The SHA-256 does not change. The move is logged (`document.restricted`; or `document.unrestricted` when the audience is set back to Public).
- Restricted documents **never** appear in public passports, program pages or `/verify`.
- Members download through the portal. Each link is a **signed URL valid for 10 minutes**, bound to the user and the document. Access is re-checked on every download.

### 8.1 Give an external party data-room access

**Before you start**
- An NDA or agreement is in place.
- For investor or enterprise rooms, the module is authorized (§1).

**Steps**
1. **Users → Add New User** (`http://localhost:8088/wp-admin/user-new.php`). Role: **RC Investor (data room)**, **RC Enterprise client** or **RC Custodian (read-only)**.
2. The user signs in at `/portal/` and sets up MFA (strongly recommended).
3. Publish the relevant Documents with the matching **Audience**.
4. Ask the user to open **Portal → Documents → Restricted documents available to you**.

**Result:** the user sees only the documents for their audience. Each download link expires after 10 minutes.

**If something goes wrong**
- *"This download link is invalid or has expired. Request a new link from the portal.":* reload the Documents tab.
- *The document is not listed:* it is not published, its audience does not match, or the room's module is off.
- *`document.storage_failed` in the audit trail:* the server could not move the file, usually a file-permission problem. Fix the permissions on `wp-content/uploads`, then re-save the document.
