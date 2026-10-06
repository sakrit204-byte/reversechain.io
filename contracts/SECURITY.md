# Security — ReserveChain.io contracts

Status: **in development, testnet only, unaudited.** This document describes the intended security properties. It is not an audit and does not confirm custody, reserves, redemption or any other off-chain arrangement.

## 1. Scope

The following contracts are in scope:
- `ReserveToken`
- `ComplianceRegistry`
- `ReserveGuard`
- `RedemptionManager`
- `Treasury`
- `AuditAnchor`
- `scripts/deploy.ts`, `scripts/anchor-audit.ts` and `scripts/lib/*`

The OpenZeppelin Contracts 5.4 library is a trusted dependency.

## 2. Trust assumptions

| Actor | Trusted for | If compromised |
|---|---|---|
| Safe multisig (DEFAULT_ADMIN) | All role assignments, caps, guard and hook wiring | Full control of the system. Mitigations: threshold signing, the admin-transfer delay, and event monitoring |
| MINTER | Issuing within the cap and guard headroom | Can mint up to the guard headroom to eligible addresses. Mitigations: the guard ratio and staleness window, pause |
| ATTESTOR | Truthful reserve figures | Can inflate `units` and therefore headroom. Mitigations: separate the attestor key from minters, publish report hashes and URIs, require off-chain review |
| KYC_OPERATOR | Accurate eligibility outcomes | Can approve ineligible addresses or freeze honest ones. All changes emit events |
| REDEMPTION_OPERATOR | Fair settlement | Can approve (burn) or reject pending requests. It cannot take escrow for itself, because funds only return to the requester or are burned |
| TREASURER | Withdrawals | Limited to the daily limit. Larger amounts go through the timelock, where LIMIT_ADMIN can cancel |
| PAUSER | Liveness | Can halt transfers (denial of service). It cannot move funds |
| ANCHOR | Correct CMS head | Can anchor a wrong head for a new `seq`. It can never overwrite an existing `seq` |

Off-chain assumptions: KYC/AML results, the reserve reports and the CMS audit chain are produced correctly off-chain. The contracts only record outcomes and hashes.

## 3. Threat model and mitigations

| Threat | Mitigation |
|---|---|
| Unbacked / unlimited issuance | `ReserveToken.mint` is fail-closed. It requires either (a) an enabled guard that permits the amount, or (b) a non-zero `supplyCap` **and** `mintingEnabled == true`. `mintingEnabled` defaults to false and only DEFAULT_ADMIN can set it. Otherwise it reverts with `MintingDisabled`, so a MINTER can never mint an unlimited amount. `ReserveGuard` is fail-closed too. Headroom is 0 when the ratio, staleness window, binding or a fresh attestation is missing. The optional static `supplyCap` gives a second bound. Attestation `asOf` must be strictly increasing and must not be in the future |
| Transfers to restricted persons or jurisdictions | The registry hook runs on every `_update`, covering mints, burns and transfers. The EU/EEA blocked set comes from config. Freeze and expiry are supported |
| Admin key theft / rushed admin change | `AccessControlDefaultAdminRules`: a single admin, two-step transfer, enforced delay, and `DEFAULT_ADMIN_ROLE` cannot be granted directly |
| Deployer retains power | The deploy script revokes its temporary wiring roles and starts the Safe handover. `admin-status.ts` confirms completion |
| Accidental mainnet deployment | No mainnet network is configured. `assertSafeNetwork` refuses any chain id outside {31337, 11155111, 80002} unless `ALLOW_MAINNET=I_HAVE_WRITTEN_AUTHORIZATION` is set, and still warns when it is |
| Reentrancy | `RedemptionManager` and `Treasury` use `nonReentrant` plus checks-effects-interactions. `SafeERC20` is used throughout |
| Redemption escrow theft | Escrow can only be burned (approve) or returned to the original requester (reject/cancel). `totalEscrowed` is tracked |
| Large treasury drain | A daily limit per token (default 0 = none), a timelock queue, cancellation by LIMIT_ADMIN, and pause |
| Audit history rewrite | `AuditAnchor` enforces strictly monotonic `seq` and never overwrites an anchor. Anyone can call `verify(seq, head)` |
| Signature replay (permit) | OZ `ERC20Permit` uses EIP-712 with the chain id and per-owner nonces. Tests cover replay and expiry |
| Leaked secrets | `.env` is git-ignored. `.env.example` holds placeholders only. Use a dedicated testnet key |

## 4. Known limitations

1. **The attestor is an oracle.** The guard only bounds supply by *reported* units. It is not a Proof of Reserves. Independent attestation and verification are pending.
2. **Compliance mirrors off-chain checks.** The registry stores no PII and cannot verify identity. Its correctness depends on the KYC operator.
3. **No forced transfer or clawback.** Tokens at a frozen address stay there. Recovery requires governance and legal process, and possibly a future token migration.
4. **Compliance can block escrow returns.** If a requester becomes ineligible while a redemption is pending, `reject` and `cancel` revert at the token hook. Operators must either restore eligibility temporarily or keep the request pending. The same applies to any holder who later becomes frozen.
5. **Pause is global per token.** It halts redemption burns and treasury movements of that token too.
6. **Staleness is measured from the attestor-supplied `asOf`.** It is bounded by `block.timestamp` and monotonicity, but the attestor chooses it. Block-timestamp drift of about 15 s is irrelevant at the intended day-scale windows.
7. **Daily limits use fixed UTC days**, not a rolling 24 h window. Up to 2× the limit can leave the treasury around midnight UTC.
8. **Treasury config changes are not timelocked on-chain.** `setDailyLimit` and `setTimelockDelay` are protected only by the Safe threshold.
9. **Large guard ratios saturate** at `type(uint256).max` instead of reverting. The admin must configure sane values.
10. **Upgradeability:** none. The contracts are immutable, and any change requires redeployment and migration.
11. **Fee-on-transfer and rebasing tokens** are not supported in `Treasury` accounting. It is designed for standard ERC-20s.

## 5. Static analysis

Slither 0.11.6, run with `npm run slither` (config in `slither.config.json`; timestamp and naming detectors excluded):

| Finding | Assessment |
|---|---|
| `uninitialized-state` on `ReserveGuard._attestations` | False positive. The mapping of dynamic arrays is written through a storage pointer (`list.push`) |
| `incorrect-equality` in `AuditAnchor.getAnchor` / `verify` | Intended. Exact bytes32 comparison against zero or the expected head |
| `timestamp` (excluded from the default run) | Intended. Expiry, staleness and timelock use `block.timestamp` at hour/day granularity |

## 6. Testing

- 83 Hardhat tests cover:
  - fail-closed minting: the default state, cap-only, flag-only, guard precedence, re-blocking
  - negative cases for every role
  - pause, cap and permit
  - compliance: EU/EEA jurisdiction, frozen, expired, unregistered
  - guard: unset ratio, unset window, unbound, stale, within limit, saturation
  - the full redemption lifecycle, including reject and cancel
  - treasury limits, timelock and grace period
  - anchor monotonicity
  - the deployment safety guard and config validation
  - an end-to-end integration flow
- Coverage: 100% statements, lines and functions; about 95% branches. The remaining branches are mostly modifier paths, such as reentrancy re-entry.
- The deploy script has been exercised on the in-process network and on `npx hardhat node`, including the Safe-handover and role-revocation path. `anchor-audit.ts` has been exercised against a mock of the WordPress `/audit/head` and `/audit/anchor` endpoints.

## 7. Audit recommendation

**An independent third-party smart-contract audit is required before any deployment beyond testnet.** No audit firm has been engaged; this is pending.

Recommended next steps:
1. A professional audit of all six contracts and the deployment scripts, followed by a remediation review.
2. Property-based and invariant fuzzing (Foundry or Echidna). Suggested invariants:
   - `totalSupply ≤ reserveCeiling` whenever the guard is enabled and was respected at mint time
   - `totalEscrowed == token.balanceOf(rm)`, ignoring stray transfers
   - anchors are never overwritten
3. Formal review of the role and governance setup:
   - the Safe threshold and signers
   - the admin delay
   - the separation of the attestor and minter keys
4. Monitoring and alerting on these events:
   - `DefaultAdminTransferScheduled`
   - role grants
   - `Paused`
   - `TokensPerUnitUpdated`
   - `AttestationPosted`
   - `JurisdictionBlockedUpdated`
5. A legal review of the compliance logic, because eligibility rules depend on the final Swiss structure and offering documentation, both still subject to final approval.

## 8. Reporting a vulnerability

Report vulnerabilities privately to the ReserveChain security contact. The contact is pending and not yet provided. Do not open public issues for vulnerabilities.
