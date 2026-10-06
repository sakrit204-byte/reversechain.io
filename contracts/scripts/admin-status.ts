/**
 * Prints DEFAULT_ADMIN / pending-admin status for every contract in deployments/<network>.json.
 * Use during the Safe multisig handover to confirm which contracts still await acceptance.
 *
 *   npx hardhat run scripts/admin-status.ts --network sepolia
 */
import * as fs from "fs";
import * as path from "path";
import { ethers, network } from "hardhat";

async function main(): Promise<void> {
  const file = path.resolve(__dirname, "..", "deployments", `${network.name}.json`);
  if (!fs.existsSync(file)) throw new Error(`No deployment record at ${file}.`);
  const d = JSON.parse(fs.readFileSync(file, "utf8"));
  const entries: [string, string][] = Object.entries<any>(d.contracts).map(([k, v]) => [k, v.address]);
  for (const [key, t] of Object.entries<any>(d.tokens)) {
    entries.push([`${key}.ReserveToken`, t.ReserveToken.address]);
    entries.push([`${key}.RedemptionManager`, t.RedemptionManager.address]);
  }
  const now = BigInt((await ethers.provider.getBlock("latest"))!.timestamp);
  console.log(`DEFAULT_ADMIN status on ${network.name}:`);
  for (const [name, address] of entries) {
    // Every contract inherits AccessControlDefaultAdminRules; the AuditAnchor ABI is sufficient.
    const c = await ethers.getContractAt("AuditAnchor", address);
    const admin = await c.defaultAdmin();
    const [pending, schedule] = await c.pendingDefaultAdmin();
    const pendingTxt =
      pending === ethers.ZeroAddress
        ? "none"
        : `${pending} (${schedule <= now ? "acceptable now" : `acceptable in ${schedule - now}s`})`;
    console.log(`  ${name.padEnd(22)} admin=${admin} pending=${pendingTxt}`);
  }
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exitCode = 1;
});
