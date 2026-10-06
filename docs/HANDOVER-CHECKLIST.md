# ReserveChain.io — Handover Checklist

> **Principle:** every account, credential and asset is created **in ReserveChain's name from day one** (milestone M0). The contractor is only ever an invited member with revocable access. Handover therefore means *confirming* ownership and *removing* contractor access — never transferring assets out of a contractor's personal accounts.

> **Mandatory disclosure.** ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.

Each item requires: ☐ delivered · ☐ verified by ReserveChain · signature/date in the acceptance log.

---

## 1. Account ownership (must be owned by ReserveChain)

| # | Account / asset | Owner of record | Contractor access | Delivered | Verified |
|---|---|---|---|---|---|
| 1.1 | Source repositories (GitHub/GitLab organisation, all repos) | ReserveChain | Member → removed at M11 | ☐ | ☐ |
| 1.2 | CI/CD (Actions secrets, environments, runners) | ReserveChain | Removed | ☐ | ☐ |
| 1.3 | Cloud accounts (AWS org / Hetzner project) incl. root/billing | ReserveChain | IAM user/role → removed | ☐ | ☐ |
| 1.4 | Servers / containers / load balancers | ReserveChain cloud | Removed | ☐ | ☐ |
| 1.5 | Databases (Prod, Staging) and DB admin credentials | ReserveChain | Rotated after handover | ☐ | ☐ |
| 1.6 | Object storage buckets & backup vaults (incl. off-site account) | ReserveChain | Removed | ☐ | ☐ |
| 1.7 | Secrets manager / KMS keys | ReserveChain | Removed; all secrets rotated | ☐ | ☐ |
| 1.8 | Domain registrar account (`reservechain.io` and variants) | ReserveChain | None | ☐ | ☐ |
| 1.9 | DNS provider (Cloudflare / Route 53) | ReserveChain | Removed | ☐ | ☐ |
| 1.10 | CDN / WAF | ReserveChain | Removed | ☐ | ☐ |
| 1.11 | TLS certificates | ReserveChain (auto-managed) | — | ☐ | ☐ |
| 1.12 | Email: mailbox domain + transactional email provider | ReserveChain | Removed | ☐ | ☐ |
| 1.13 | Analytics (privacy-respecting) | ReserveChain | Removed | ☐ | ☐ |
| 1.14 | Monitoring / uptime / logging / error tracking | ReserveChain | Removed | ☐ | ☐ |
| 1.15 | WordPress administrator accounts | ReserveChain named users | Contractor accounts deleted (audit-logged) | ☐ | ☐ |
| 1.16 | Blockchain accounts: deployer EOA, Safe multisig, anchor key, explorer API keys, RPC provider | ReserveChain | Never held by contractor beyond testnet deployer; rotated | ☐ | ☐ |
| 1.17 | Apple Developer Program account (App Store Connect, certificates, provisioning) | ReserveChain | Removed | ☐ | ☐ |
| 1.18 | Google Play Console account, upload key / Play App Signing | ReserveChain | Removed | ☐ | ☐ |
| 1.19 | Expo / EAS organisation, push notification credentials | ReserveChain | Removed | ☐ | ☐ |
| 1.20 | KYC/KYB/AML provider account (when selected) | ReserveChain | Removed | ☐ | ☐ |
| 1.21 | Third-party licences (fonts, plugins, SaaS) | ReserveChain | — | ☐ | ☐ |

## 2. Source code and repository

- ☐ Complete source: theme, plugin, contracts, mobile app, infrastructure-as-code, scripts, docs.
- ☐ Full Git history preserved; default branch protected; tags for every release.
- ☐ No secrets in history (secret scan report attached).
- ☐ Licence/ownership headers state ReserveChain ownership; third-party licence inventory.
- ☐ Build reproducible from clean checkout using documented commands.

## 3. Documentation deliverables

| # | Document | Location | ☐ |
|---|---|---|---|
| 3.1 | Database schema & migrations | `CMS-STRUCTURE.md` §3, plugin `class-install.php` | ☐ |
| 3.2 | API documentation (REST, OpenAPI) | `SPEC.md`, `docs/api/openapi.yaml` (M10) | ☐ |
| 3.3 | Architecture documentation | `ARCHITECTURE.md` | ☐ |
| 3.4 | Infrastructure documentation + IaC | `ARCHITECTURE.md` §5–6, `infra/` | ☐ |
| 3.5 | Website / CMS user manuals (editor, reviewer, compliance, registry manager, auditor) | `docs/manuals/` (M10) | ☐ |
| 3.6 | iOS / Android build, signing, update and store-submission manuals | `docs/manuals/mobile.md` (M7/M10) | ☐ |
| 3.7 | Smart-contract & token administration procedures (roles, pause, attest, redeem, anchor) | `docs/manuals/contracts.md` (M10), `SECURITY.md` §7 | ☐ |
| 3.8 | Registry / DAP / Proof-of-Reserves operating manuals | `docs/manuals/registry.md` (M10) | ☐ |
| 3.9 | Security manual | `SECURITY.md` | ☐ |
| 3.10 | Backup / restore / DR manual | `BACKUP-DR.md` | ☐ |
| 3.11 | Deployment / maintenance / rollback | `DEPLOYMENT-RUNBOOK.md` | ☐ |
| 3.12 | Domain / DNS / email / analytics / monitoring configuration record | §4 below | ☐ |
| 3.13 | Environment variable templates | `.env.example` (root, contracts, mobile), `DEPLOYMENT-RUNBOOK.md` §2 | ☐ |
| 3.14 | Service & dependency inventory | §5 below | ☐ |
| 3.15 | Testing documentation & results | `TEST-PLAN.md` + results | ☐ |
| 3.16 | Troubleshooting & known issues | `DEPLOYMENT-RUNBOOK.md` §7 + issue tracker | ☐ |
| 3.17 | Whitepaper (MD, DOCX, PDF) + diagram sources | `docs/whitepaper/` | ☐ |
| 3.18 | Recorded training sessions (CMS roles, registry/DAP, compliance controls, deployment/rollback, contracts admin, mobile release) | ReserveChain-owned storage | ☐ |

## 4. Configuration record (values filled at M1/M11)

| Item | Value |
|---|---|
| Registrar & expiry | [To be recorded at handover] |
| DNS zone (records list incl. SPF, DKIM, DMARC, CAA, DNSSEC status) | [To be recorded] |
| Email provider & sending domains | [To be decided by ReserveChain] |
| Analytics provider & property IDs | [To be decided by ReserveChain] |
| Monitoring endpoints & alert routes | [To be recorded] |
| Contract addresses (testnet) & explorer links | [Recorded after M6 deployment] |
| Multisig address, signers, threshold | [To be provided by ReserveChain] |
| App bundle IDs / package names | [To be confirmed by ReserveChain] |

## 5. Service and dependency inventory (template)

| Service / dependency | Purpose | Environment(s) | Owner account | Cost centre | Renewal |
|---|---|---|---|---|---|
| WordPress core | CMS | all | ReserveChain | — | updates weekly |
| MySQL 8 (RDS/managed) | DB | stg/prod | ReserveChain | [TBD] | — |
| Object storage | Documents/backups | stg/prod | ReserveChain | [TBD] | — |
| CDN/WAF | Edge security | prod | ReserveChain | [TBD] | — |
| Email provider | Transactional email | stg/prod | ReserveChain | [TBD] | — |
| RPC provider | Testnet access | all | ReserveChain | [TBD] | — |
| Expo EAS | Mobile builds | — | ReserveChain | [TBD] | — |
| Apple / Google developer programs | Stores | — | ReserveChain | [TBD] | annual |
| OpenZeppelin Contracts v5 | Contract libraries | — | OSS (MIT) | — | pinned |
| npm / composer packages | See lockfiles | — | OSS | — | Renovate |

## 6. Final verification

- ☐ ReserveChain staff deploy a release to Staging and roll it back unaided.
- ☐ ReserveChain staff perform a restore drill unaided.
- ☐ ReserveChain staff build and submit an internal-test mobile build unaided.
- ☐ All secrets rotated after contractor access removal.
- ☐ Audit-log integrity verified and chain head recorded at handover moment.

## 7. Access revocation

- ☐ Contractor removed from every account in §1 (screenshots / audit-log entries attached).
- ☐ Contractor WordPress accounts deleted (audit-logged `user.deleted`).
- ☐ Contractor SSH keys, API tokens, personal access tokens revoked.
- ☐ Contractor confirms in writing that no copies of credentials or production data are retained.

## 8. Formal acceptance

| Role | Name | Signature | Date |
|---|---|---|---|
| ReserveChain authorised representative | [To be provided] | | |
| Independent verifier (if appointed) | [To be provided] | | |
| Contractor lead | | | |
