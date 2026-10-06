/**
 * Verifies every contract listed in deployments/<network>.json on Etherscan (API v2 — Sepolia / Amoy).
 *
 *   npx hardhat run scripts/verify.ts --network sepolia
 *
 * Requires ETHERSCAN_API_KEY in .env. Already-verified contracts are skipped gracefully.
 */
import * as fs from "fs";
import * as path from "path";
import hre, { network } from "hardhat";

interface Entry {
  address: string;
  args: string[];
}

async function verify(label: string, contract: string, e: Entry): Promise<void> {
  console.log(`\n→ ${label} @ ${e.address}`);
  try {
    await hre.run("verify:verify", { address: e.address, constructorArguments: e.args, contract });
    console.log(`  ✓ verified`);
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    if (/already verified/i.test(msg)) console.log("  · already verified");
    else console.error(`  ✗ ${msg}`);
  }
}

async function main(): Promise<void> {
  if (network.name === "hardhat" || network.name === "localhost") {
    throw new Error("Verification is only meaningful on public testnets (sepolia, amoy).");
  }
  if (!process.env.ETHERSCAN_API_KEY) throw new Error("ETHERSCAN_API_KEY is not set.");
  const file = path.resolve(__dirname, "..", "deployments", `${network.name}.json`);
  if (!fs.existsSync(file)) throw new Error(`No deployment record at ${file}. Run scripts/deploy.ts first.`);
  const d = JSON.parse(fs.readFileSync(file, "utf8"));

  const shared: Record<string, string> = {
    ComplianceRegistry: "contracts/ComplianceRegistry.sol:ComplianceRegistry",
    ReserveGuard: "contracts/ReserveGuard.sol:ReserveGuard",
    Treasury: "contracts/Treasury.sol:Treasury",
    AuditAnchor: "contracts/AuditAnchor.sol:AuditAnchor",
  };
  for (const [name, fq] of Object.entries(shared)) await verify(name, fq, d.contracts[name]);
  for (const [key, t] of Object.entries<any>(d.tokens)) {
    await verify(`${key}.ReserveToken`, "contracts/ReserveToken.sol:ReserveToken", t.ReserveToken);
    await verify(`${key}.RedemptionManager`, "contracts/RedemptionManager.sol:RedemptionManager", t.RedemptionManager);
  }
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exitCode = 1;
});
