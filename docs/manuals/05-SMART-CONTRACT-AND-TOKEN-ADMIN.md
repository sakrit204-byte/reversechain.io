# 05 — Smart Contract and Token Administration Manual

**Readers:** Safe multisig signers, the technical operator, compliance officers. **Source of truth:** `contracts/README.md`, `contracts/SECURITY.md`, `contracts/contracts/*.sol`, `contracts/scripts/{deploy,verify,admin-status,anchor-audit}.ts`, `contracts/scripts/lib/safety.ts`, `contracts/config/*.json`; CMS side: `class-web3.php`, `class-audit-log.php`.
**Related:** [01 §14.4 Audit anchoring in the CMS](01-CMS-ADMIN-MANUAL.md#144-anchor-the-chain-head-on-chain) · [03 Proof of Reserves](03-PROOF-OF-RESERVES-MANUAL.md) · [04 Redemption](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md) · [Index](README.md)

> ## Never on mainnet without written authorization
> These contracts are **testnet only**: Sepolia (11155111), Polygon Amoy (80002), or local Hardhat (31337).
> - The deploy script refuses every other chain id unless `ALLOW_MAINNET=I_HAVE_WRITTEN_AUTHORIZATION` is set.
> - The CMS refuses mainnet chain ids.
> - **Never set that variable without a signed written authorization from ReserveChain's board and counsel, and an independent smart-contract audit.** Even then, mainnet deployment is outside the current engagement.
> - Testnet keys must never be reused on mainnet.
> - No tokens are offered or sold. The token parameters (name, symbol, supply, ratio, thresholds) are **unset** until written approval exists.

---

## Contents

1. [Contracts, roles and keys](#1-contracts-roles-and-keys)
2. [Testnet deployment](#2-testnet-deployment)
3. [Source verification](#3-source-verification)
4. [Safe multisig handover](#4-safe-multisig-handover)
5. [Mint, burn, pause](#5-mint-burn-pause)
6. [Compliance registry updates](#6-compliance-registry-updates)
7. [Reserve attestations](#7-reserve-attestations)
8. [Redemption operations](#8-redemption-operations)
9. [Audit anchoring](#9-audit-anchoring)
10. [Incident response](#10-incident-response)
11. [Known limitations](#11-known-limitations)
12. [Record keeping](#12-record-keeping)

---

## 1. Contracts, roles and keys

| Contract | Purpose | Shipped default |
|---|---|---|
| `ReserveToken` (one per program, `tCU` / `tNI` placeholders) | ERC-20 + permit + pause; compliance hook; reserve-guard mint check | **Minting blocked** (`MintingDisabled`) |
| `ComplianceRegistry` | Per address: status (None/Pending/Approved/Rejected/Revoked), jurisdiction (bytes2), expiry, frozen; blocked jurisdictions | EU/EEA blocked; no records |
| `ReserveGuard` | Attestations `(programId, units, reportHash, uri, asOf)`; `maxMintable = units × tokensPerUnit − totalSupply` | Ratio and window unset ⇒ 0 |
| `RedemptionManager` (one per program) | request (escrow) → approve (burn) / reject / cancel | **Disabled**, thresholds unset |
| `Treasury` | ERC-20 holder; daily limit and timelock queue | Limit 0, queue off |
| `AuditAnchor` | `(chainHead, seq, uri)` with strictly increasing `seq` | Empty |

Every contract uses `AccessControlDefaultAdminRules`: exactly one `DEFAULT_ADMIN`, with a two-step, delayed transfer.

| Role | Contract | Intended holder | Key storage |
|---|---|---|---|
| `DEFAULT_ADMIN_ROLE` | all | **Safe multisig** (signers and threshold provided separately) | Hardware wallets of the signers |
| `MINTER_ROLE` | ReserveToken | Issuance ops key / Safe module | Hardware wallet |
| `BURNER_ROLE` | ReserveToken | **RedemptionManager only** | — |
| `PAUSER_ROLE` | Token, RedemptionManager, Treasury | Incident-response signer(s) | Hardware wallet |
| `COMPLIANCE_ADMIN_ROLE` | ReserveToken | Compliance / Safe | Hardware wallet |
| `KYC_OPERATOR_ROLE` | ComplianceRegistry | KYC back-office key or compliance multisig | HSM or hardware wallet |
| `JURISDICTION_ADMIN_ROLE` | ComplianceRegistry | Compliance officer | Hardware wallet |
| `ATTESTOR_ROLE` | ReserveGuard | Independent attestor (**to be appointed**) | Attestor-controlled |
| `GUARD_ADMIN_ROLE` | ReserveGuard | Safe | — |
| `REDEMPTION_OPERATOR_ROLE` / `CONFIG_ADMIN_ROLE` | RedemptionManager | Redemption ops / Safe | — |
| `TREASURER_ROLE` / `LIMIT_ADMIN_ROLE` | Treasury | Treasury ops / Safe | — |
| `TREASURY_ROLE` | ReserveToken | Treasury ops (`recoverERC20` only) | — |
| `ANCHOR_ROLE` | AuditAnchor | CMS anchoring key (low privilege) | Secrets manager / password manager |
| Deployer EOA | — | ReserveChain-owned, testnet only, used once | Hardware wallet or a dedicated key; retire it after handover |

**The CMS never holds privileged keys.** Its only on-chain write is the optional audit anchor. All other actions go through the Safe or the role keys above.

**Workstation setup (once)**
1. Install Node 20 or later and Git, then clone the repository.
2. `cd contracts && npm ci`.
3. `cp .env.example .env`. Fill in `SEPOLIA_RPC_URL` (or `AMOY_RPC_URL`), `DEPLOYER_PRIVATE_KEY`, `ETHERSCAN_API_KEY`, and for anchoring `WP_URL`, `WP_USER` and `WP_APP_PASSWORD`. All values are provided separately. **Never commit `.env`.**
4. Run `npm test`. Expect 83 passing tests.

---

## 2. Testnet deployment

**Before you start**
- You have a fresh deployer EOA, used only for this deployment, funded with Sepolia ETH from a faucet.
- The Safe exists on Sepolia, and you have its address (provided separately).
- The role holder addresses are agreed in writing.
- Tokenomics fields stay `null` unless written approval exists.

**Steps**
1. `cp config/sepolia.example.json config/sepolia.json`.
2. Edit `config/sepolia.json`:
   - `safeMultisig`: the Safe address.
   - `adminTransferDelaySeconds`: e.g. `86400` (one day).
   - `revokeDeployerWiringRoles: true`.
   - `compliance.kycOperators`, `jurisdictionAdmins`; `reserveGuard.attestors`, `guardAdmins`; `treasury.*`; `tokens[].roles.*`: the agreed addresses.
   - Keep `tokens[].supplyCap`, `tokensPerUnit`, `redemption.*`, `treasuryDailyLimit`, `reserveGuard.maxAttestationAgeSeconds` and `mintingEnabled` **unset / false** unless written approval exists.
3. Quality gate: `npm test && npm run coverage`. Expect 100 % lines/statements/functions.
4. **Four-eyes:** a second person reviews the config diff and signs it off in the ticket.
5. Deploy: `npm run deploy:sepolia`. The script:
   1. checks the chain id and refuses non-testnets;
   2. deploys the shared contracts, then a token and a RedemptionManager per program;
   3. seeds the blocked jurisdictions and registers the system holders (RedemptionManager, Treasury) as `Approved` with jurisdiction `0x0000`;
   4. wires the hooks and grants the configured roles;
   5. revokes its own temporary wiring roles;
   6. calls `beginDefaultAdminTransfer(safe)` on every contract;
   7. writes `deployments/sepolia.json`, including the constructor arguments and the config SHA-256.
6. Archive `deployments/sepolia.json` and `config/sepolia.json` in the repository (pull request), together with the config hash.
7. Record in the CMS, through the four-eyes workflow:
   - **Settings & modules → Network**: chain ID 11155111, explorer, **Token contract (testnet)**, **AuditAnchor contract (testnet)** (`http://localhost:8088/wp-admin/admin.php?page=rc-settings`).
   - Each **Token Program** record: **Network** = *Ethereum Sepolia (testnet)*, **Contract address**. Then submit → approve → publish ([01 §4](01-CMS-ADMIN-MANUAL.md#4-the-four-eyes-workflow)).
   - **Web3 settings → ComplianceRegistry address** (`http://localhost:8088/wp-admin/admin.php?page=rc-web3`).

**Result:**
- All contracts are deployed, with admin transfer pending to the Safe.
- The CMS PoR screen shows token supply `read` once the RPC is set ([03 §8](03-PROOF-OF-RESERVES-MANUAL.md#8-on-chain-supply-reading)).
- Publishing contract addresses on the public site still needs the gated module `contract_info`.

**If something goes wrong**
- *"Refusing to run on chainId …":* the RPC points at a non-testnet. Fix `SEPOLIA_RPC_URL`. **Never** set `ALLOW_MAINNET`.
- *Out of gas or funds:* top up the deployer from a faucet and re-run. The script writes the deployment record only at the end, so check the explorer for partially deployed contracts and note them.
- *Config validation error:* fix the address format (checksummed `0x…`) or remove the extra keys.

### 2.1 Local rehearsal

Run this before a testnet deployment:

```bash
npx hardhat node                 # terminal 1
npm run deploy:localhost         # terminal 2 → deployments/localhost.json
WP_URL=http://localhost:8088 npm run anchor:localhost
```

---

## 3. Source verification

**Steps**
1. Check that `ETHERSCAN_API_KEY` is set in `.env` (Etherscan API v2 also covers Amoy).
2. `npm run verify:sepolia` (or `verify:amoy`). The script verifies every contract listed in `deployments/sepolia.json` and skips those already verified.
3. Open each address on `https://sepolia.etherscan.io` and confirm the **Contract** tab shows verified source code.

**If something goes wrong:** "already verified" is fine. A constructor-argument mismatch means the deployment record was edited by hand; restore it from Git.

---

## 4. Safe multisig handover

**Before you start**
- The deploy script has called `beginDefaultAdminTransfer(safe)`.
- The Safe signers are available, with their hardware wallets.

**Steps**
1. Wait `adminTransferDelaySeconds`.
2. Check the status: `npx hardhat run scripts/admin-status.ts --network sepolia` (or `npm run admin-status:sepolia`). Each contract should show `pending=<safe> (acceptable now)`.
3. In the Safe web app, open **Transaction Builder**. For **every** contract address in `deployments/sepolia.json`, add `acceptDefaultAdminTransfer()`. That is 8 contracts for two programs: 4 shared + 2 tokens + 2 RedemptionManagers.
4. Collect the threshold signatures and execute.
5. Run `admin-status.ts` again. Every line must read `admin=<safe> pending=none`.
6. Record the Safe transaction hash in the CMS audit trail. Use the deployment ticket, or add a note to the Token Program record and submit it.
7. Retire the deployer key: move any remaining test ETH to the treasury test wallet, and mark the key as retired in the password manager.

**If something goes wrong**
- *The transfer was started to a wrong address:* the current admin calls `cancelDefaultAdminTransfer()`, then begins again with the correct address.
- *To change the delay later:* `changeDefaultAdminDelay`. This is itself delayed.

---

## 5. Mint, burn, pause

> **No minting in the current phase.** Minting is fail-closed. It succeeds only in one of two modes, both of which need written approval for the parameters.

### 5.1 Mint (only after written authorization)

**Mode (a), reserve mode** (guard enabled, as shipped). The Safe or a role holder must first:
1. `guard.bindToken(token, programId)`
2. `guard.setTokensPerUnit(programId, ratio)` (the ratio needs written approval)
3. `guard.setMaxAttestationAge(window)`
4. The attestor posts a fresh attestation (§7)

**Mode (b), static-cap mode:** the Safe disables the guard, calls `token.setSupplyCap(cap)` with a non-zero cap, then `token.setMintingEnabled(true)`.

**Then, for either mode**
1. The recipient is `Approved` in the ComplianceRegistry (§6).
2. Check the headroom: `guard.maxMintable(token)`, as a read call in the explorer or Safe.
3. A `MINTER_ROLE` key calls `token.mint(to, amount)`.
4. Record the transaction hash in the CMS. Check the PoR screen for `supply_*` exceptions ([03 §3](03-PROOF-OF-RESERVES-MANUAL.md#3-exception-codes-and-how-to-resolve-them)).

**To stop minting quickly:** call `setMintingEnabled(false)`, or unset the ratio or the attestation window. `pause()` stops every movement.

### 5.2 Burn

Burns happen **only** through the RedemptionManager approval (§8). `burnFrom` exists for exceptional, holder-consented burns: it needs the holder's ERC-20 approval and `BURNER_ROLE`. There is no forced burn.

### 5.3 Pause and unpause

**Steps (pause)**
1. A `PAUSER_ROLE` holder calls `token.pause()`. This stops transfers, mints and burns. `redemption.pause()` and `treasury.pause()` are scoped to their own modules.
2. Record the reason and the transaction hash in the incident log.

**Steps (unpause)**
1. Only after the incident review is signed off by two signers.
2. A `PAUSER_ROLE` holder calls `unpause()`. Record the transaction hash.

---

## 6. Compliance registry updates

The registry stores **eligibility flags only, never personal data**. An address is eligible when its status is `Approved`, it is not frozen, it has not expired, and its jurisdiction is not blocked.

### 6.1 Onboard holders using the CMS calldata export (preferred)

The CMS prepares **unsigned** calldata for every user whose **Overall** eligibility is *eligible subject to final approval* and who has linked wallets ([04 §4](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#4-wallet-linking)). Nothing is signed or sent by the server.

**Before you start**
- The ComplianceRegistry address is set in **Web3 settings**.
- You hold `rc_manage_compliance`, which is needed for the download.
- A Safe or `KYC_OPERATOR_ROLE` signer is available.

**Steps**
1. Open **ReserveChain → Web3 settings → ComplianceRegistry allow-list sync** (`http://localhost:8088/wp-admin/admin.php?page=rc-web3`) and click **Download calldata (JSON)**. You get `rc-compliance-registry-calldata-<timestamp>.json`. The audit trail logs `web3.compliance_calldata_export` with the accounts and the file's SHA-256.
2. Review `records[]`: user id, account, status 2 (Approved), jurisdiction (ISO-2 and bytes2) and expiry. Compare it against the Compliance page. A second person checks it.
3. In the Safe **Transaction Builder**, create a transaction to `to` (the registry) with the `batch.data` hex (`setRecordsBatch(address[],uint8[],bytes2[],uint64[])`). Or use `calls.items[]` for single `setRecord(address,uint8,bytes2,uint64)` calls.
4. Simulate in Safe, then collect the signatures and execute.
5. Spot-check in the explorer: `isEligible(account)` or `canTransfer`.

**Result:** the approved wallets can receive tokens, once minting is ever authorized.

### 6.2 Manual updates

| Task | Call | Role |
|---|---|---|
| Onboard one holder | `registry.setRecord(addr, 2 /*Approved*/, 0x4348 /*"CH"*/, expiry)` | KYC_OPERATOR |
| Revoke | `setRecord(addr, 4 /*Revoked*/, …)` | KYC_OPERATOR |
| Emergency freeze | `setFrozen(addr, true)` / `setFrozenBatch` | KYC_OPERATOR |
| Change blocked jurisdictions | `setJurisdictionsBlocked(codes, true/false)` | JURISDICTION_ADMIN |
| Disable the token's compliance hook (exceptional) | `token.setComplianceEnabled(false)` | COMPLIANCE_ADMIN via Safe, with written approval |

Keep the CMS and the chain in step. When compliance revokes a user in the CMS ([01 §12](01-CMS-ADMIN-MANUAL.md#12-compliance-page-and-user-eligibility)), revoke or freeze their wallets on-chain the same day. When the CMS restricted-jurisdiction list changes, mirror it with `setJurisdictionsBlocked`.

---

## 7. Reserve attestations

**Before you start:** an independent attestor is appointed and holds `ATTESTOR_ROLE`. **Not yet the case.**

**Steps**
1. The attestor computes the SHA-256 of the report and publishes the report at a stable URI.
2. The attestor calls `guard.postAttestation(programId, units, reportHash, uri, asOf)`. `asOf` must be later than the previous attestation and not in the future.
3. ReserveChain records the attestation in the CMS through **Proof of Reserves → Record a reserve attestation**, including the **On-chain attestation tx** ([03 §5](03-PROOF-OF-RESERVES-MANUAL.md#5-recording-a-reserve-attestation)). Check that the uploaded report's SHA-256 equals `reportHash`.
4. Repeat before `maxAttestationAge` elapses. Otherwise `maxMintable` falls to 0 and minting stops. The CMS raises `stale_attestation` after 90 days.

An attestation is a **statement by a key holder**. It is not Proof of Reserves by itself ([03](03-PROOF-OF-RESERVES-MANUAL.md)).

---

## 8. Redemption operations

**Only after written authorization.** It must be authorized both on-chain and in the CMS ([04 §1](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#1-authorizations-required-before-anything-goes-live)).

**Enable (Safe, once)**
1. `redemption.setThresholds(min, max)` with the approved values.
2. `redemption.setEnabled(true)`.

**Per request**
1. The holder calls `token.approve(redemptionManager, amount)`, then `redemption.request(amount)`. The tokens are escrowed.
2. Operations review the request off-chain through the CMS workflow ([04 §2](04-REDEMPTION-PAYMENTS-PORTALS-MANUAL.md#2-redemption-workflow)).
3. After CMS approval, a `REDEMPTION_OPERATOR_ROLE` key calls `approve(id, fulfilmentRefHash)`. This **burns** the escrow. Record the burn transaction in the CMS (**Record token burn**).
4. Or, before approval: `reject(id, reasonHash)` returns the escrow. The holder may call `cancel(id)` while the request is pending.
5. Invariant to check regularly: `totalEscrowed` = the RedemptionManager's token balance (minus tokens sent to it by mistake).

---

## 9. Audit anchoring

Anchoring commits the CMS audit chain head to `AuditAnchor`. Any later rewrite of the CMS history becomes detectable.

**Before you start**
- `AuditAnchor` is deployed. Its address is in `deployments/<network>.json`, or set `ANCHOR_ADDRESS`.
- The signing key (the `DEPLOYER_PRIVATE_KEY` used by Hardhat for this network) holds `ANCHOR_ROLE`.
- To report back to the CMS automatically: an administrator account (`rc_anchor_audit`) with a **WordPress Application Password**, created under **Users → Profile → Application Passwords**. WordPress offers Application Passwords only over HTTPS, or on a local environment. Store it in the password manager.

**Steps**
1. Dry run: `WP_URL=https://reservechain.io DRY_RUN=true npm run anchor:sepolia`. It fetches `/wp-json/rc/v1/audit/head` and validates it, without sending anything.
2. Real run: `WP_URL=https://reservechain.io WP_USER=<admin login> WP_APP_PASSWORD=<app password> npm run anchor:sepolia`. The script:
   1. reads `{seq, chain_head}`;
   2. if `seq` is greater than `AuditAnchor.latestSeq()`, calls `anchor(0x+chain_head, seq, uri)`; otherwise it does nothing (idempotent);
   3. posts `{seq, chain_head, network, tx_hash}` to `/wp-json/rc/v1/audit/anchor`.
3. In the CMS **Audit trail**, check that **Latest anchor** shows the new sequence number, network and transaction ([01 §14.4](01-CMS-ADMIN-MANUAL.md#144-anchor-the-chain-head-on-chain)).
4. To schedule it, run step 2 daily from CI or cron. Keep the keys in the CI secret store.

**Verify an anchor (auditor)**
1. Call `AuditAnchor.verify(seq, head)` in the explorer. It returns true if that head was anchored at that sequence number.
2. Recompute the CMS chain from a JSONL export up to that sequence number ([01 §14.3](01-CMS-ADMIN-MANUAL.md#143-export-jsonl)) and compare the row hash.

**If something goes wrong**
- *HTTP 401/403 on the report-back POST:* the Application Password or the capability is wrong. Record the anchor manually in the Audit trail instead.
- *"Anchor rejected: chain head does not match the stored entry":* the head you entered is not the stored head at that sequence number. Re-read `/audit/head`.
- *Anchoring is skipped:* nothing new since the last anchor. This is expected.
- *Local:* the stack runs on `http://localhost:8088`, not on 8080 as in `.env.example` ([KI-08](TROUBLESHOOTING-AND-KNOWN-ISSUES.md#known-issues-register)).

---

## 10. Incident response

| Severity | Examples |
|---|---|
| SEV-1 | A key is compromised; an unauthorised mint; `supply_without_attestation` or `supply_exceeds_reserve` |
| SEV-2 | Suspicious role grants; compliance mismatches; a failed anchor verification |

**Steps**
1. **Contain:**
   - a `PAUSER` calls `token.pause()`, `redemption.pause()` and `treasury.pause()`;
   - a `KYC_OPERATOR` freezes the affected addresses (`setFrozen` / `setFrozenBatch`);
   - a Treasury role holder cancels queued withdrawals (`cancelWithdrawal`).
2. **Assess:**
   - snapshot the events and balances (explorer exports);
   - anchor the current CMS audit head (§9);
   - export the CMS audit log ([01 §14.3](01-CMS-ADMIN-MANUAL.md#143-export-jsonl)).
3. **Rotate:**
   - the Safe revokes the compromised role keys and grants new ones;
   - if needed, unbind the guard or unset the ratio to stop minting.
4. **Recover:**
   - unpause only after the root cause is fixed and two signers have reviewed the fix;
   - publish a post-mortem, after counsel review.
5. **If the Safe itself is compromised:** the admin delay leaves time to react. Monitor `DefaultAdminTransferScheduled` events. Any unexpected event means the remaining signers coordinate immediately, through the current admin, to call `cancelDefaultAdminTransfer()`.
6. On the CMS side, follow [07 §8.3](07-OPERATIONS-BACKUP-DR-MANUAL.md#83-suspected-compromise).

---

## 11. Known limitations

These come from `contracts/SECURITY.md`:
1. **The attestor is an oracle.** The guard bounds supply only by *reported* units.
2. **The compliance registry mirrors off-chain checks.** It cannot verify identity.
3. **No forced transfer or clawback.** Frozen tokens stay where they are.
4. **Compliance can block escrow returns.** If a requester becomes ineligible while a request is pending, `reject` and `cancel` revert. Either restore eligibility temporarily, or keep the request pending.
5. **Pause is global per token.** It also halts redemption burns and treasury movements of that token.
6. **Attestation freshness** relies on the attestor-supplied `asOf`.
7. **Treasury daily limits use fixed UTC days.** Up to twice the limit can leave around midnight UTC.
8. **Treasury configuration changes are not timelocked on-chain.** Only the Safe threshold protects them.
9. **Large guard ratios saturate** instead of reverting. Configure sane values.
10. **No upgradeability.** Any change means redeployment and migration.
11. **Fee-on-transfer and rebasing tokens** are not supported in the Treasury.

An **independent audit** is required before any non-test use.

---

## 12. Record keeping

For every on-chain administrative action, record the following in the CMS: the action, the contract, the transaction hash, the signers, the reason and the authorization reference. Use the Token Program record's content or the related ticket, submitted through the workflow, so that it enters the audit trail.

Keep the following in Git:
- `deployments/<network>.json`
- `config/<network>.json`, with its SHA-256

Keep the Safe address, the signers and the threshold in the handover configuration record (`docs/HANDOVER-CHECKLIST.md` §4).
