# ReserveChain.io — platform monorepo

Proposed institutional infrastructure for industrial-metal real-world assets (Ultra-High-Purity Copper Powder ·
High-Purity Nickel Wire 0.025 mm). **In development — no tokens are offered or sold.**

| Path | What |
|---|---|
| `wordpress/plugins/reservechain-core/` | Registry, Digital Asset Passports, audit trail, workflow, compliance, waitlist, auth/MFA, REST API, seed data |
| `wordpress/themes/reservechain/` | Public website theme (navy/gold design system, mega-menu IA, passport view) |
| `wordpress/plugins/reservechain-core/seed/pages/` | All website pages (EN + `.es` / `.it`), synchronised with `wp rc seed --pages-only` |
| `contracts/` | Solidity contracts, tests, deploy/verify/anchor scripts (testnet only) |
| `mobile/` | Expo (React Native) iOS/Android app |
| `docs/` | Architecture, CMS structure, schedule, traceability, security, DR, runbooks, whitepaper |
| `deploy/` | Production stack (Caddy HTTPS) and one-command server deploy |
| `submission/` | Contest entry text and boards |

## Run locally

```bash
docker compose up -d              # WordPress on http://localhost:8088
bash scripts/dev-reset.sh         # install, activate, seed, immutability self-test
bash scripts/lint-php.sh          # PHP syntax check
cd contracts && npm ci && npx hardhat test
cd mobile && npm ci && npm run start:mock
```

Useful WP-CLI: `wp rc audit-verify`, `wp rc tamper-test`, `wp rc passport RC-CU-LOT-000001`,
`wp rc import-doc <file> --title="…" --type=whitepaper`.

## Deploy

`deploy/deploy.sh ubuntu@<server-ip>` — installs Docker, opens 80/443, syncs code, generates secrets, starts
WordPress + MySQL + Caddy (automatic HTTPS), installs and seeds. See `docs/DEPLOYMENT-RUNBOOK.md`.

All accounts, repositories, servers, domains and credentials are to be owned by ReserveChain (`docs/HANDOVER-CHECKLIST.md`).
