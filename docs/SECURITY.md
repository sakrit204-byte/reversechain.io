# ReserveChain.io — Security Manual

> **Status:** Proposed security baseline — subject to final approval by ReserveChain. Controls marked *(demo)* are implemented in the contest entry; others are planned per [`DEVELOPMENT-SCHEDULE.md`](DEVELOPMENT-SCHEDULE.md).

> **Mandatory disclosure.** ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.

---

## 1. Scope and threat model

Assets protected: registry integrity and evidence fingerprints; audit trail; personal data (waitlist, members, eligibility); administrative credentials; smart-contract admin keys; brand trust (no unauthorised claims or offers).

| Threat | Example | Primary controls |
|---|---|---|
| Unauthorised content change / false claim published | Compromised editor publishes "verified" claim | Four-eyes workflow, `verified` gated, audit trail, MFA |
| History rewrite | Insider edits audit rows | DB triggers, hash chain, on-chain anchoring, off-site log export |
| Account takeover | Credential stuffing | MFA, throttling, breached-password check, WAF |
| Web exploitation | XSS, SQLi, CSRF, plugin vuln | CSP, prepared statements, nonces, minimal plugins, WPScan, WAF |
| Malicious upload | Infected PDF | MIME/magic validation, AV scan, private storage, signed URLs |
| Data leak | PII exposure via API | Field-level `public` flag, auth on PII endpoints, hashing |
| Smart-contract key compromise | Minter key stolen | Multisig admin, least-privilege roles, pause, reserve guard, minting disabled by default |
| Supply-chain | Malicious npm/composer dep | Lockfiles, Dependabot/Renovate, review, image scanning |
| Abuse / spam | Waitlist flooding | Honeypot, rate limit, double opt-in, optional challenge |
| Unauthorised feature activation | Purchase module enabled | `rc_authorize_modules` + written approval reference + audit log |

## 2. Identity and access management

- Named accounts only; no shared logins. Administrator accounts held by ReserveChain individuals.
- Roles and capabilities per [`CMS-STRUCTURE.md §6`](CMS-STRUCTURE.md#6-role--capability-matrix). Principle of least privilege; quarterly access review by compliance officer (recorded).
- **MFA:** TOTP (RFC 6238) *(demo)*; mandatory for all `rc_*` roles and administrators in Staging and Production. Recovery codes issued once, stored hashed.
- **Passwords:** ≥ 12 characters; WordPress core hashing; optional Have-I-Been-Pwned k-anonymity check.
- **Login throttling:** progressive delays and temporary lockouts per account and per IP; failed attempts audit-logged with hashed login *(demo)*.
- **API tokens:** HMAC-signed bearer tokens, 1 h lifetime; refresh tokens rotated on use, stored hashed, revocable; refresh-token reuse revokes the token family *(demo)*.
- Infrastructure access: SSO with MFA to cloud console; SSH via bastion or SSM only; no direct DB access from the internet.

## 3. Sessions

| Setting | Value |
|---|---|
| Cookie flags | `Secure; HttpOnly; SameSite=Lax` |
| Admin idle timeout | 30 minutes (proposed) |
| Absolute lifetime | 12 hours |
| Revocation | On password reset, role change, MFA reset; admin can terminate sessions |
| Mobile | Tokens in iOS Keychain / Android Keystore (`expo-secure-store`); biometric unlock optional |

## 4. Application security

- WordPress core, theme and plugin kept minimal; no page builders; every third-party plugin requires review and is listed in the dependency inventory.
- All DB access through `$wpdb->prepare`; output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`); nonces on all admin forms; capability checks on every action and REST route.
- REST: public endpoints read-only and rate-limited; private endpoints require bearer token; only `public: true` schema fields serialised.
- File editing in admin disabled (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS` in Production — updates deployed via CI only).
- XML-RPC disabled; user enumeration blocked; WordPress version disclosure removed.

### 4.1 HTTP security headers (target)

```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{n}'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
  font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self' {RPC_URL};
  frame-ancestors 'none'; object-src 'none'; base-uri 'self'; form-action 'self'
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
Cross-Origin-Opener-Policy: same-origin
```

HSTS preload is submitted only after Production has served HSTS successfully for the full staging validation period. `'unsafe-inline'` for styles is to be removed once inline styles are eliminated.

## 5. Uploads and documents

1. Allow-list: PDF, PNG, JPEG, WebP, CSV, JSON. Max size configurable (default 25 MB).
2. Server checks extension **and** magic bytes; rejects mismatches.
3. Antivirus scan (ClamAV sidecar or provider scanning) — document stays in quarantine until clean *(planned M4)*.
4. SHA-256 computed on ingest and stored in `_rc_sha256` *(demo)*; immutable after publish; recorded in audit log.
5. Storage in private object storage; public delivery via short-lived signed URLs; randomised object keys.
6. Client-side Verify never uploads user files — hashing occurs in the browser *(demo)*.

## 6. Data protection

- PII limited to waitlist, member accounts, support tickets and eligibility records.
- Emails stored with `email_hash` for de-duplication; IPs stored only as HMAC *(demo)*.
- Audit trail stores actor ids and hashed identifiers — not raw PII in `data`.
- Encryption at rest (RDS/EBS/S3 with KMS) and in transit (TLS 1.2+).
- Data-subject requests: export and erasure tools for waitlist/member records; audit entries retained (hashed identifiers only).
- Retention periods: **[To be defined by ReserveChain counsel]** under Swiss FADP / applicable law.
- KYC documents are never stored in WordPress; they remain with the KYC provider (**[To be decided by ReserveChain]**), only status results are stored.

## 7. Smart-contract and key management

| Key / role | Contract(s) | Holder (proposed) | Storage | Notes |
|---|---|---|---|---|
| `DEFAULT_ADMIN_ROLE` (OZ `AccessControlDefaultAdminRules`: delayed two-step transfer) | all | Safe multisig — signers & threshold **[To be provided by ReserveChain]** | Hardware wallets | Grants/revokes all roles |
| `PAUSER_ROLE` | ReserveToken, RedemptionManager, Treasury | Multisig + optional emergency signer | Hardware wallet | Fast pause in incident |
| `MINTER_ROLE` | ReserveToken | Multisig | — | Bounded by ReserveGuard when enabled; no minting in current phase |
| `BURNER_ROLE` | ReserveToken | RedemptionManager | — | Burns escrowed tokens on approved redemption |
| `COMPLIANCE_ADMIN_ROLE` | ReserveToken | Compliance multisig | Hardware wallet | Sets registry / enables compliance hook |
| `KYC_OPERATOR_ROLE`, `JURISDICTION_ADMIN_ROLE` | ComplianceRegistry | Compliance operations key(s) | HSM or hardware wallet | Eligibility flags only — no PII |
| `ATTESTOR_ROLE` | ReserveGuard | Independent attestor **[To be appointed]** | Attestor-controlled | Posts `(programId, units, reportHash, uri, asOf)` |
| `GUARD_ADMIN_ROLE` | ReserveGuard | Multisig | — | Ratio, staleness window, token binding |
| `REDEMPTION_OPERATOR_ROLE`, `CONFIG_ADMIN_ROLE` | RedemptionManager | Operations / multisig | — | Module disabled by default |
| `TREASURER_ROLE`, `LIMIT_ADMIN_ROLE` | Treasury | Multisig | — | Daily limits, timelock |
| `TREASURY_ROLE` | ReserveToken | Multisig | — | ERC-20 recovery only |
| `ANCHOR_ROLE` | AuditAnchor | Platform anchor key | Secrets manager | Low privilege; only anchors hashes |
| Deployer | — | ReserveChain-owned EOA | Hardware wallet | Hands admin to multisig after deploy, then renounces |

Procedures: deployment from `contracts/config/<network>.json` only; post-deploy role verification script; deployer renounces admin; every role change recorded in the CMS audit trail with tx hash. **Mainnet is out of scope**; testnet keys must never be reused on mainnet.

## 8. Infrastructure security

- Network: app tier in private subnets; DB not publicly reachable; security groups least-privilege.
- Edge: Cloudflare or CloudFront + AWS WAF with OWASP managed rules, bot management, rate-based rules for `/wp-login.php`, `/wp-json/rc/v1/auth/*`, `/waitlist`, `/verify`, `/support`.
- Secrets: AWS Secrets Manager (or Vault/SOPS); rotation — DB credentials 90 days, API HMAC key 180 days (with dual-key overlap), SMTP on provider schedule, salts on incident.
- Images: built in CI, scanned (Trivy), signed; runtime filesystem read-only except uploads/cache.
- Logging: centralised, retained ≥ 12 months (proposed), access restricted to `rc_auditor`-equivalent ops staff.

## 9. Vulnerability management

| Activity | Frequency |
|---|---|
| Dependency updates (Dependabot / Renovate) | Continuous; weekly merge window |
| WPScan / plugin advisory monitoring | Daily |
| Container image scan | Every build |
| PHPStan / PHPCS (WordPress standards) | Every PR |
| Slither + Hardhat tests + coverage | Every PR touching `contracts/` |
| `npm audit` (mobile, contracts, theme) | Every PR |
| External penetration test | Before public launch and annually — vendor **[To be appointed by ReserveChain]** |
| Smart-contract audit | Before any non-testnet use — firm **[To be appointed by ReserveChain]** |

Remediation SLAs: Critical 72 h · High 7 days · Medium 30 days · Low next release.

## 10. Incident response

| Severity | Examples | Response |
|---|---|---|
| SEV-1 | Active compromise, unauthorised publication of offer-like content, key compromise | Immediate; on-call + ReserveChain leadership |
| SEV-2 | Integrity check failure, data exposure suspected | < 4 h |
| SEV-3 | Degraded service, failed backups | < 1 business day |

Playbook:

1. **Detect & triage** — alert from monitoring, integrity job, WAF, or report.
2. **Contain** — switch site mode to `maintenance`; revoke sessions; rotate exposed secrets; if contracts affected, multisig executes `pause()`.
3. **Preserve evidence** — export audit log (JSONL with hashes), compare against last on-chain anchor; snapshot DB and logs.
4. **Eradicate & recover** — patch, rebuild from clean image, restore from known-good backup per `BACKUP-DR.md`; verify chain integrity.
5. **Notify** — ReserveChain counsel decides on regulatory / data-subject notifications (FADP/GDPR timelines as applicable).
6. **Post-incident review** — within 5 business days; actions tracked.

Contact list: **[To be provided by ReserveChain]**.

## 11. Security contacts and disclosure

A `security.txt` (`/.well-known/security.txt`) will be published with a ReserveChain-owned contact address **[To be provided by ReserveChain]**.
