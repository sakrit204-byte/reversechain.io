# Deploy configuration

These files contain **no tokenomics.** Every economic field is `null`, zero or `false`, which means unset or disabled. Unset values are fail-closed:
- `tokensPerUnit: null` or `maxAttestationAgeSeconds: null` while the reserve guard is enabled ⇒ **minting is blocked**.
- `mintingEnabled: false` (the default) ⇒ static-cap mode is off. The token mints only if (a) the guard is enabled and permits the amount, or (b) `supplyCap` is non-null **and** `mintingEnabled: true`. In every other state minting is blocked, including when the guard is disabled and no cap is set.
- `redemption.enabled: false` ⇒ no redemption requests are accepted.
- `treasuryDailyLimit: null` ⇒ no immediate treasury withdrawals.
- `timelockDelaySeconds: null` ⇒ the withdrawal queue is disabled.

| File | Use |
|---|---|
| `localhost.json` | `npx hardhat node` and the in-process `hardhat` network. Operational roles go to the local `deployer` account so flows can be tested by hand. |
| `sepolia.example.json` | Template. Copy it to `sepolia.json`, then fill in the role addresses and `safeMultisig`. |
| `amoy.example.json` | Template for the optional Polygon Amoy deployment. |

Conventions:
- Keys that start with `_` are comments and are stripped by the loader.
- Amounts (`supplyCap`, `tokensPerUnit`, `redemption.minAmount/maxAmount`, `treasuryDailyLimit`) are **decimal strings in whole tokens**. They are converted with each token's `decimals`.
- `programId` is either a label, which is hashed with keccak256, or a 0x-prefixed bytes32.
- `blockedJurisdictions` holds ISO-3166 alpha-2 codes. The shipped list contains the EU/EEA codes from SPEC.md. ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.
- Token names and symbols are **TESTNET placeholders**, subject to final approval.

Setting any economic value (ratio, cap, thresholds, limits) requires written authorisation. Record that authorisation, and the config SHA-256 that the deploy script prints, in the CMS audit trail.

The full field reference is in [`../README.md` §4](../README.md#4-configuration).
