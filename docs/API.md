# ReserveChain API reference (`rc/v1`)

| | |
|---|---|
| Base URL | `https://{host}/wp-json/rc/v1` · local: `http://localhost:8088/wp-json/rc/v1` |
| Machine-readable spec | [`docs/openapi.yaml`](openapi.yaml) (OpenAPI 3.1, passes `redocly lint`) |
| Rendered reference | [`docs/api/index.html`](api/index.html) (self-contained, works offline) |
| Plugin version documented | 0.9.0 (`RC_VERSION`) |
| Source of truth | `wordpress/plugins/reservechain-core/includes/class-{rest,por,redemption,web3,portal,monitoring,auth}.php` |

This API is shared by the website, the participant portal (`[rc_portal]`) and the iOS / Android apps.

> ReserveChain is in development. No tokens are being offered or sold. The wallet, USDT-payment, redemption,
> Proof-of-Reserves publication and data-room endpoints are built but **inactive**. Until written authorization
> and final approval, and until the required site mode is set, they return the `403` responses documented below.
> Clients must render `disclosure` and `eu_notice` from `GET /config` **verbatim**.

Examples are real responses from the local development instance (`RC_ENV=development`, site mode
`prelaunch`), captured on 2026-10-06. Tokens, secrets, signatures and personal data are redacted. Some
responses could only be described from code, either because the module is inactive or because a live
call would create records or send email. Those are marked *shape from code*.

---

## 1. Conventions

* **Format.** JSON in and out. Send `Content-Type: application/json`. Timestamps are ISO 8601 UTC. A few
  audit/notification timestamps use the form `YYYY-MM-DD HH:MM:SS[.ffffff]Z`.
* **Caching.** Every `rc/v1` response carries `Cache-Control: no-store`.
* **Errors.** Errors use the WordPress envelope:
  ```json
  { "code": "rc_unauthorized", "message": "Authentication required.", "data": { "status": 401 } }
  ```
  Validation errors add `data.fields` (field → message). The redemption endpoints are the one exception:
  they return `{ "code", "message" }` and carry the status only in the HTTP status line.
* **Gated modules.** Feature flags are listed in `GET /config` → `modules`. A disabled module answers in one
  of three ways:
  * a `200` placeholder `{ enabled:false, reason, items:[] }` (`/me/holdings`, `/me/transactions`,
    `GET /me/redemptions`)
  * a `403` (`rc_module_inactive`, `rc_module_disabled`, `rc_disabled`)
  * a `404`, where revealing the record would leak information (`/passports/{no}`, `/por/snapshots/{id}`)
* **Maintenance.** In site mode `maintenance`, every endpoint behind the public gate returns
  `503 rc_maintenance`. Staff with `edit_posts` are exempt. `/health` stays available.

## 2. Authentication

| Scheme | How | Used by |
|---|---|---|
| **Public** | none (rate-limited "public gate") | catalogue, verify, PoR, forms, auth endpoints, signed downloads |
| **Bearer JWT** | `Authorization: Bearer <access_token>` | all `/me/*`, `/support`, `/auth/mfa/setup`, `/auth/mfa/enable`, `/auth/logout` |
| **WP nonce** | WordPress login cookie + `X-WP-Nonce: <wp_rest nonce>` | Web3 endpoints (`/me/wallet*`, `/me/payments*`) also accept a website session; `/audit/anchor` |
| **Staff capability** | WP session with capability `rc_anchor_audit` | `POST /audit/anchor` |
| **Health token** | `X-RC-Health-Token: <RC_HEALTH_TOKEN>` (≥ 16 chars, set in `wp-config.php`) | `GET /health/full` |

### 2.1 Token model (`class-auth.php`)

| Token | Format | Lifetime | Notes |
|---|---|---|---|
| Access token | HS256 JWT. Claims: `iss`, `sub`, `iat`, `exp`, `typ=access`, `ver`, `mfa` | 3600 s (`expires_in`) | Rejected once the user's token version (`ver`) is bumped |
| Refresh token | Opaque, 64 characters | 30 days | Stored as SHA-256, **single use**, at most 10 live per user, labelled with `client` |
| MFA token | HS256 JWT, `typ=mfa` | 300 s | Exchanged at `/auth/mfa/verify` |

### 2.2 Flow

```
POST /auth/login {email, password, client}
 ├─ MFA off → 200 {mfa_required:false, mfa_recommended:true, access_token, refresh_token, token_type:"Bearer", expires_in:3600}
 └─ MFA on  → 200 {mfa_required:true, mfa_token}
              POST /auth/mfa/verify {mfa_token, code, client} → 200 {access_token, refresh_token, …}

(optional) enrol MFA:  POST /auth/mfa/setup → {secret, otpauth_url}
                       POST /auth/mfa/enable {code} → {ok:true, recovery_codes:[8 × 10 chars]}   (shown once)

on 401 from any bearer endpoint:
  POST /auth/refresh {refresh_token} → new pair; the old refresh token is consumed
  replaying a consumed refresh token → 401 rc_refresh_invalid → sign in again

POST /auth/logout → {ok:true}; revokes ALL sessions of the user (token version +1, refresh tokens deleted)
```

Facts checked against the live instance on 2026-10-06:
* The first refresh returned a new pair.
* Replaying the same refresh token returned `401 rc_refresh_invalid`.
* After `/auth/logout`, the access token returned `401 rc_unauthorized` immediately.

Other behaviour:
* TOTP is RFC 6238 (SHA-1, 6 digits, 30 s). It accepts ±1 step and rejects replay of an already-used step.
* Recovery codes are single use, and each use is audited.
* Disabling MFA (wp-admin) and deleting the account (`/me/delete`) also revoke every session.
* `/auth/register` gives the same answer whether or not the email already exists, so it cannot be used for
  account enumeration.
* Staff accounts have MFA enforced in wp-admin.

## 3. Rate limits

The limits are fixed windows. They are keyed per client (a hash of the IP) unless the table says otherwise.
Exceeding one returns `429` (`rc_rate_limited` or `rc_rate`).

| Scope | Limit |
|---|---|
| Public gate, GET | 120 / min / client |
| Public gate, POST | 20 / min / client |
| Bearer / user gate | 300 / min / **user** |
| `/auth/login` | 10 / 15 min / client **and** 5 / 15 min / account |
| `/auth/mfa/verify` | 5 / 5 min / user |
| `/auth/refresh` | 30 / min / client |
| `/auth/register` | 5 / h / client |
| `/waitlist` | 5 / h / client; the web form must be open for at least 3 s (`rc_too_fast`) |
| `/contact` | 5 / h / client |
| `POST /me/redemptions` | 5 / h / user |
| `/me/wallet/challenge`, `/me/wallet/verify` | 10 / 10 min / user each |
| `POST /me/payments` | 5 / h / user (max 3 open intents) |
| `/health` | 60 / min / client |

## 4. Endpoint index (48 operations on 45 routes — matches the live `GET /wp-json/rc/v1/` route index)

Notes on the table:
* **Gate** is the module (and site mode, where needed) that must be on. "—" means always available.
* The public gate (P) adds the public rate limits and the maintenance-mode block.
* The full request and response schemas are in `openapi.yaml`.

### Public catalogue & verification

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| GET | `/config` | P | — | Site mode, module/section flags, verbatim disclosure + EU/EEA notice, `disclosure_hash`, claim statuses, testnet network, version |
| GET | `/programs` | P | — | Metal programs (≤ 50), each with claims and their status (`proposed` / `in_development` / `pending_verification` / `verified` / `not_applicable`) |
| GET | `/programs/{slug}` | P | — | Adds `description`, `fields`, `token_program`, `passports`, `documents`. Unknown slug → 404 `rc_not_found` |
| GET | `/passports` | P | `passports_public` | `?program=&type=&page=`. Disabled → 403 `rc_disabled` |
| GET | `/passports/{no}` | P | `passports_public` | Digital Asset Passport `reservechain.dap/1.0`. 404 if unknown or the module is off |
| GET | `/verify?hash=` | P | — | SHA-256 document lookup. 400 `rc_bad_hash`. Every lookup is audited |
| GET | `/documents` | P | — | Public library: audience `public`, newest first, ≤ 200 |
| GET | `/registry/stats` | P | — | Published counts per entity, `verified_records`, audit head |
| GET | `/audit/head` | P | — | Hash-chain head, last verification, last anchor, DB immutability triggers |

### Forms

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| POST | `/waitlist` | P | `waitlist` (closed in `maintenance`) | Double opt-in. Restricted jurisdictions are stored only with `general_updates:true`. Honeypot `website` |
| POST | `/contact` | P | — | Returns `{ok, ticket:"RC-SUP-000123"}`. 422 `rc_invalid` |

### Auth

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| POST | `/auth/register` | P | `app_registration` | 403 `rc_closed`, 422 `rc_invalid` + `fields`. Password ≥ 12 chars with upper-case, lower-case and a digit; both consents required |
| POST | `/auth/login` | P | — | 401 `rc_invalid_credentials`, 429 `rc_rate` |
| POST | `/auth/mfa/verify` | P | — | 401 `rc_mfa_expired` / `rc_mfa_invalid` |
| POST | `/auth/mfa/setup` | Bearer | — | Pending secret + `otpauth://` URL |
| POST | `/auth/mfa/enable` | Bearer | — | 422 `rc_mfa_invalid` |
| POST | `/auth/refresh` | P | — | Rotation. 401 `rc_refresh_invalid` |
| POST | `/auth/logout` | Bearer | — | Revokes all sessions |

### Account

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| GET | `/me` | Bearer | — | Profile + `eligibility` (jurisdiction, kyc/kyb/aml/sanctions, `overall` ∈ `incomplete` / `restricted` / `eligible_subject_to_final_approval`) |
| PATCH | `/me` | Bearer | — | `name`, `language` (`en` / `es` / `it`) |
| GET | `/me/holdings` | Bearer | `wallet` | `200 {enabled:false, reason, items:[]}` placeholder |
| GET | `/me/transactions` | Bearer | `wallet` | Same placeholder |
| GET | `/me/notifications` | Bearer | — | Latest 50 (personal + broadcast) |
| POST | `/me/notifications/{id}/read` | Bearer | — | Idempotent |
| POST | `/me/devices` | Bearer | — | `push_token`; keeps the last 5 |
| POST | `/support` | Bearer | — | Message ≥ 10 chars. 422 `rc_invalid` |
| POST | `/me/delete` | Bearer | — | `{password, confirm:"DELETE"}` → `deleted` or `anonymised` (when compliance history must be retained). 403 `rc_delete_staff`, 422 `rc_delete_confirm` |

### Proof of Reserves

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| GET | `/por` | P | — (live reconciliation is always visible; `module_enabled` reports publication state) | Per program: inventory (declared vs verified kg), supply, attestation, coverage, exception counts, latest published snapshot |
| GET | `/por/snapshots` | P | `proof_of_reserves` | Off → 403 `rc_module_inactive` (live) |
| GET | `/por/snapshots/{id}` | P | `proof_of_reserves` | Canonical JSON `reservechain.por/1.0` with `sha256` and `intact`. 404 when off, unknown or unpublished |

Coverage is `not_computed` unless all three inputs exist: a **verified** attestation, an approved
asset-to-token ratio and a known on-chain (testnet) supply. Declared quantities are owner-supplied.

Exception codes and their severity:

| Severity | Codes |
|---|---|
| Critical | `reserve_without_custody`, `unfingerprinted_document`, `insurance_expired`, `supply_without_attestation`, `supply_exceeds_reserve` |
| Warning | `no_coa`, `missing_weight`, `redemption_overlap`, `insurance_expiring`, `stale_valuation` (> 180 d), `stale_attestation` (> 90 d) |
| Info | `owner_supplied_only`, `no_attestation` |

### Redemption (inactive)

The module `redemption` **and** the site mode `redemption` must both be set.

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/me/redemptions` | Bearer | `{enabled, reason, items}` (≤ 100) |
| POST | `/me/redemptions` | Bearer | `{token_program, amount, delivery_method: collection\|delivery, delivery_address?, units?, lot?, note?}` → 201 |
| GET | `/me/redemptions/{id}` | Bearer | Units with unit-level `redemption_status`, lot, burn tx, carrier/tracking, history. Never shows staff identities |
| POST | `/me/redemptions/{id}/cancel` | Bearer | Only while `requested` or `compliance_review` |

States: `requested → compliance_review → approved → tokens_burned → custody_released → in_logistics → delivered`.
Side exits are `rejected` (staff, before burn), `cancelled` (requester, before approval) and `on_hold` (resumable).

Rules enforced by the workflow:
* **Compliance re-check.** Eligibility is checked when the request is made and again at approval.
* **Unit selection.** `units` and `lot` require the module `unit_selection`. Selected units are locked
  against double selection.
* **Undetermined terms.** If the minimum or the fees are not set, the request is refused with
  `rc_terms_undetermined`.

Errors:

| Status | Codes |
|---|---|
| 403 | `rc_module_inactive`, `rc_restricted`, `rc_not_eligible`, `rc_terms_undetermined`, `rc_forbidden` |
| 409 | `rc_unit_unavailable`, `rc_unit_taken`, `rc_state`, `rc_busy` |
| 422 | `rc_invalid`, `rc_below_minimum`, `rc_wrong_program` |

### Wallet linking (inactive)

The module `wallet` must be on. Auth is a bearer token or a WP nonce.

| Method | Path | Notes |
|---|---|---|
| POST | `/me/wallet/challenge` | `{address}` → `{address, chain_id, nonce, expires_at, message}`. The EIP-4361 message is valid for 600 s and can be used once |
| POST | `/me/wallet/verify` | `{address, nonce, signature, message?}`. The server rebuilds the message, hashes it with EIP-191 and recovers the signer with secp256k1 |
| GET | `/me/wallets` | `{max:3, items:[{address, chain_id, linked_at, explorer}]}` |
| DELETE | `/me/wallets/{address}` | 409 `rc_wallet_in_use` while a payment on that wallet is pending |

Only testnet chains are allowed: 11155111 Sepolia, 80002 Amoy and 31337 Hardhat. A wallet can be linked
to one account only, and an account can link at most 3 wallets.

Errors:

| Status | Codes |
|---|---|
| 403 | `rc_module_disabled` (live) |
| 409 | `rc_not_testnet`, `rc_wallet_exists`, `rc_wallet_limit`, `rc_wallet_unavailable` |
| 422 | `rc_invalid_address`; `rc_wallet_verify_failed` with `data.reason` ∈ `no_challenge`, `expired`, `mismatch`, `chain_changed`, `message_mismatch`, `bad_signature` |

### USDT payment verification (inactive)

Requires the modules `purchase` **and** `usdt_payments`, plus site mode `live_offering` or
`early_participation`. Auth is a bearer token or a WP nonce.

| Method | Path | Notes |
|---|---|---|
| GET | `/me/payments` | Latest 50 intents + safety `note` |
| POST | `/me/payments` | `{program_id, wallet?}` → intent `created`. Needs eligibility `eligible_subject_to_final_approval` and a linked wallet |
| GET | `/me/payments/{id}` | One intent |
| POST | `/me/payments/{id}/tx` | `{tx_hash}`, only while `awaiting_tx`. Verified against the receipt, the ERC-20 Transfer log (from = linked wallet, to = treasury, value == approved amount) and N confirmations (default 6) |

Intent statuses: `created → awaiting_tx → confirming → confirmed`, or `failed` / `expired`.

Staff set and approve the amount (four-eyes, bound to a fingerprint). The API contains no pricing logic.
`treasury` and `token_contract` are revealed only after approval.

Errors:

| Status | Codes |
|---|---|
| 403 | `rc_module_disabled` (live), `rc_not_eligible` |
| 409 | `rc_no_wallet`, `rc_not_testnet`, `rc_too_many_intents`, `rc_invalid_state`, `rc_expired`, `rc_tx_used` |
| 422 | `rc_invalid_wallet`, `rc_invalid_program`, `rc_invalid_tx` |

### Participant portal & data rooms

| Method | Path | Auth | Gate | Notes |
|---|---|---|---|---|
| GET | `/me/documents` | Bearer | investor room: `investor_portal`; enterprise room: `enterprise_portal` (for non-staff) | `{items, audiences, rooms, link_ttl:600}` |
| GET | `/me/documents/{id}/link` | Bearer | as above | `{url, expires_in:600}`. 403 `rc_forbidden` |
| GET | `/documents/{id}/download?u=&exp=&sig=` | P + HMAC signature | — | Re-checks access, streams the file as an attachment and audits every download. 403 `rc_link_invalid` / `rc_forbidden`, 404 `rc_not_found` |

Documents are restricted by audience: `public`, `investor`, `enterprise`, `auditor`, `custodian` or `staff`.
Restricted files are stored in `uploads/rc-private/` behind deny-all rules and are only ever served through
signed links.

### Operations

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/health` | none (60 / min) | `{status: ok\|degraded, db, audit, triggers, cron_age_s, disk_free_pct, backup_ok}`. Versions are shown only outside production. Always HTTP 200 |
| GET | `/health/full` | Health token | Checks, versions, backup, cron, alerts, 24 h failures. Token not configured → 404 `rc_health_disabled`; wrong token → 401 |
| POST | `/audit/anchor` | Staff (`rc_anchor_audit`) | `{seq, chain_head, network, tx_hash}`. 409 `rc_anchor_mismatch` |

**Not REST.** Asset-owner intake (`class-intake.php`) and the staff workflows for redemption, PoR and
payments are WordPress `admin-post.php` form handlers. They are protected by WP nonces and capabilities and
are not part of the `rc/v1` API.

## 5. Pagination

The API does not use cursor or `X-WP-Total` pagination. Collections are bounded:

| Endpoint | Paging |
|---|---|
| `/passports` | `?page=N` with a fixed page size of 50. An empty array means you are past the end |
| `/programs` | ≤ 50 |
| `/documents` | ≤ 200 |
| `/me/notifications` | latest 50 |
| `/me/redemptions` | ≤ 100 |
| `/me/payments` | latest 50 |

If a future version adds paging to any other collection, it will be an additive query parameter.

## 6. CORS

`Rest::cors()` (`rest_pre_serve_request`) adds `Access-Control-Allow-Origin` for the origins returned by the
filter `rc_cors_origins`. The default is `home_url()` only.

> **Hardened 2026-10-06:** WordPress core's permissive `rest_send_cors_headers` (which reflected any `Origin`
> with credentials) is removed on `rest_api_init`. Only origins returned by `rc_cors_origins` (default: the site's
> own `home_url()` / `site_url()` origin) receive `Access-Control-Allow-Origin`, `-Credentials`, `-Methods`
> (GET, POST, PATCH, DELETE, OPTIONS) and `-Headers` (Authorization, Content-Type, X-WP-Nonce). Verified: an
> unknown origin receives no CORS headers.

Native apps are not subject to CORS. Browser-based third-party clients need their origin added through
`rc_cors_origins`.

## 7. Versioning policy

* The namespace version (`v1`) changes only for **breaking** changes: removing or renaming a field or route,
  changing a type, or tightening auth. A breaking change ships as `rc/v2` alongside `rc/v1`, and `v1` is
  kept for at least 6 months after the apps that use v2 are released.
* **Additive** changes ship inside `v1` without notice: new fields, new optional parameters, new routes,
  new error codes and new enum values. Clients must ignore unknown fields and treat unknown enum values as
  "unknown".
* `GET /config` → `version` reports the plugin version. `openapi.yaml` `info.version` tracks it.
* Gated modules being switched on is **not** a version change. Clients must read `modules` at start-up and
  after `403 rc_module_*`.

## 8. Error code index

| Code | HTTP | Meaning |
|---|---|---|
| `rc_unauthorized` | 401 | Missing, invalid, expired or revoked bearer token, or a bad health token |
| `rc_invalid_credentials` | 401 | Wrong email or password |
| `rc_mfa_expired` / `rc_mfa_invalid` | 401 / 401, 422 | MFA session expired / wrong code |
| `rc_refresh_invalid` | 401 | Refresh token unknown, expired or already used |
| `rest_forbidden` | 401 | WordPress core: capability missing (`/audit/anchor`) |
| `rc_rate_limited` / `rc_rate` / `rc_too_fast` | 429 | Rate limits |
| `rc_maintenance` | 503 | Maintenance mode |
| `rc_closed` | 403 | Registration or waitlist closed |
| `rc_disabled`, `rc_module_inactive`, `rc_module_disabled` | 403 | Gated module off |
| `rc_restricted`, `rc_not_eligible` | 403 | Jurisdiction restricted / compliance checks incomplete |
| `rc_terms_undetermined` | 403 | Redemption minimum or fees not approved |
| `rc_forbidden`, `rc_link_invalid`, `rc_delete_staff` | 403 | Access denied |
| `rc_not_found`, `rc_health_disabled` | 404 | Not found / detailed health off |
| `rc_bad_hash` | 400 | Not a SHA-256 |
| `rc_invalid`, `rc_delete_confirm`, `rc_below_minimum`, `rc_wrong_program`, `rc_evidence`, `rc_invalid_*`, `rc_wallet_verify_failed` | 422 | Validation |
| `rc_anchor_mismatch`, `rc_unit_*`, `rc_state`, `rc_busy`, `rc_wallet_*`, `rc_not_testnet`, `rc_too_many_intents`, `rc_tx_used`, `rc_expired`, `rc_no_wallet`, `rc_invalid_state` | 409 | Conflict with current state |

## 9. Regenerating the rendered reference

```bash
# from any temp dir (keeps node_modules out of the repo)
cp docs/openapi.yaml /tmp/rc && cd /tmp/rc
npx -y @redocly/cli lint openapi.yaml
npx -y @redocly/cli build-docs openapi.yaml -o index.html
# then inline the redoc.standalone.js <script src> so docs/api/index.html works offline
```
