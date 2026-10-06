/**
 * ReserveChain.io — deployment script (TESTNET ONLY).
 *
 *   npx hardhat run scripts/deploy.ts --network <hardhat|localhost|sepolia|amoy>
 *
 * Reads config/<network>.json (hardhat => config/localhost.json; override with DEPLOY_CONFIG=path),
 * deploys the shared ComplianceRegistry / ReserveGuard / Treasury / AuditAnchor and, per configured program,
 * a ReserveToken + RedemptionManager. Wires hooks and roles, optionally starts the two-step DEFAULT_ADMIN
 * handover to a Safe multisig, and writes deployments/<network>.json.
 *
 * Refuses to run on any non-testnet chain id unless ALLOW_MAINNET=I_HAVE_WRITTEN_AUTHORIZATION.
 * No tokenomics value is defined here: every number comes from config and defaults to unset/zero/disabled.
 */
import * as fs from "fs";
import * as path from "path";
import { ethers, network } from "hardhat";
import type { BaseContract, ContractTransactionResponse } from "ethers";
import { assertSafeNetwork } from "./lib/safety";
import { loadConfig, resolveAddresses, toBytes2, toProgramId, toUnits } from "./lib/config";

const KYC_APPROVED = 2;

type Granted = { contract: string; role: string; account: string };

async function send(label: string, p: Promise<ContractTransactionResponse>): Promise<void> {
  const tx = await p;
  await tx.wait();
  console.log(`  ✓ ${label}`);
}

async function addr(c: BaseContract): Promise<string> {
  return c.getAddress();
}

async function main(): Promise<void> {
  const { chainId } = await ethers.provider.getNetwork();
  assertSafeNetwork(chainId);

  const { config, file, hash } = loadConfig(network.name);
  const [deployer] = await ethers.getSigners();
  if (!deployer) throw new Error("No deployer account. Set DEPLOYER_PRIVATE_KEY in .env for remote networks.");
  const me = deployer.address;
  const R = (list: string[] | undefined) => resolveAddresses(list, me);
  const delay = BigInt(config.adminTransferDelaySeconds);

  console.log("ReserveChain.io deploy — TESTNET ONLY");
  console.log(`  network:  ${network.name} (chainId ${chainId})`);
  console.log(`  config:   ${path.relative(process.cwd(), file)} (sha256 ${hash})`);
  console.log(`  deployer: ${me}`);
  console.log(`  balance:  ${ethers.formatEther(await ethers.provider.getBalance(me))} native`);

  const granted: Granted[] = [];
  const tempRoles: { c: BaseContract & { revokeRole: Function; hasRole: Function }; name: string; role: string; keep: boolean }[] = [];

  async function grant(c: any, name: string, roleName: string, accounts: string[]): Promise<void> {
    const role: string = await c[roleName]();
    for (const a of accounts) {
      if (!(await c.hasRole(role, a))) await send(`${name}.grant ${roleName} → ${a}`, c.grantRole(role, a));
      granted.push({ contract: name, role: roleName, account: a });
    }
  }
  async function grantTemp(c: any, name: string, roleName: string, keep: boolean): Promise<void> {
    const role: string = await c[roleName]();
    if (!(await c.hasRole(role, me))) await send(`${name}.grant ${roleName} → deployer (wiring)`, c.grantRole(role, me));
    tempRoles.push({ c, name, role: roleName, keep });
  }

  // ---------------------------------------------------------------- shared contracts
  console.log("\nDeploying shared contracts…");
  const registry = await (await ethers.getContractFactory("ComplianceRegistry")).deploy(me, delay);
  await registry.waitForDeployment();
  const guard = await (await ethers.getContractFactory("ReserveGuard")).deploy(me, delay);
  await guard.waitForDeployment();
  const treasury = await (await ethers.getContractFactory("Treasury")).deploy(me, delay);
  await treasury.waitForDeployment();
  const anchor = await (await ethers.getContractFactory("AuditAnchor")).deploy(me, delay);
  await anchor.waitForDeployment();
  console.log(`  ComplianceRegistry ${await addr(registry)}`);
  console.log(`  ReserveGuard       ${await addr(guard)}`);
  console.log(`  Treasury           ${await addr(treasury)}`);
  console.log(`  AuditAnchor        ${await addr(anchor)}`);

  console.log("\nConfiguring ComplianceRegistry…");
  const kycOps = R(config.compliance.kycOperators);
  const jurAdmins = R(config.compliance.jurisdictionAdmins);
  await grantTemp(registry, "ComplianceRegistry", "JURISDICTION_ADMIN_ROLE", jurAdmins.includes(me));
  await grantTemp(registry, "ComplianceRegistry", "KYC_OPERATOR_ROLE", kycOps.includes(me));
  if (config.compliance.blockedJurisdictions.length > 0) {
    await send(
      `blocked jurisdictions [${config.compliance.blockedJurisdictions.join(",")}]`,
      registry.setJurisdictionsBlocked(config.compliance.blockedJurisdictions.map(toBytes2), true),
    );
  }
  await send(
    "register Treasury as system holder",
    registry.setRecord(await addr(treasury), KYC_APPROVED, "0x0000", 0),
  );
  await grant(registry, "ComplianceRegistry", "KYC_OPERATOR_ROLE", kycOps);
  await grant(registry, "ComplianceRegistry", "JURISDICTION_ADMIN_ROLE", jurAdmins);

  console.log("\nConfiguring ReserveGuard…");
  const guardAdmins = R(config.reserveGuard.guardAdmins);
  await grantTemp(guard, "ReserveGuard", "GUARD_ADMIN_ROLE", guardAdmins.includes(me));
  if (config.reserveGuard.maxAttestationAgeSeconds !== null) {
    await send(
      `maxAttestationAge = ${config.reserveGuard.maxAttestationAgeSeconds}s`,
      guard.setMaxAttestationAge(config.reserveGuard.maxAttestationAgeSeconds),
    );
  } else {
    console.log("  · maxAttestationAge UNSET (minting blocked while guard enabled)");
  }
  await grant(guard, "ReserveGuard", "ATTESTOR_ROLE", R(config.reserveGuard.attestors));
  await grant(guard, "ReserveGuard", "GUARD_ADMIN_ROLE", guardAdmins);

  console.log("\nConfiguring Treasury…");
  const limitAdmins = R(config.treasury.limitAdmins);
  await grantTemp(treasury, "Treasury", "LIMIT_ADMIN_ROLE", limitAdmins.includes(me));
  if (config.treasury.timelockDelaySeconds !== null) {
    await send(`timelockDelay = ${config.treasury.timelockDelaySeconds}s`, treasury.setTimelockDelay(config.treasury.timelockDelaySeconds));
  }
  if (config.treasury.gracePeriodSeconds !== null) {
    await send(`gracePeriod = ${config.treasury.gracePeriodSeconds}s`, treasury.setGracePeriod(config.treasury.gracePeriodSeconds));
  }
  await grant(treasury, "Treasury", "TREASURER_ROLE", R(config.treasury.treasurers));
  await grant(treasury, "Treasury", "PAUSER_ROLE", R(config.treasury.pausers));
  await grant(treasury, "Treasury", "LIMIT_ADMIN_ROLE", limitAdmins);

  await grant(anchor, "AuditAnchor", "ANCHOR_ROLE", R(config.auditAnchor.anchorers));

  // ---------------------------------------------------------------- per-program contracts
  const tokensOut: Record<string, unknown> = {};
  for (const t of config.tokens) {
    console.log(`\nDeploying program "${t.key}" (${t.name} / ${t.symbol})…`);
    const programId = toProgramId(t.programId);
    const cap = toUnits(t.supplyCap, t.decimals);
    const tokenArgs = [t.name, t.symbol, t.decimals, cap, programId, me, delay] as const;
    const token = await (await ethers.getContractFactory("ReserveToken")).deploy(...tokenArgs);
    await token.waitForDeployment();
    const tokenAddr = await addr(token);
    const rmArgs = [tokenAddr, me, delay] as const;
    const rm = await (await ethers.getContractFactory("RedemptionManager")).deploy(...rmArgs);
    await rm.waitForDeployment();
    const rmAddr = await addr(rm);
    console.log(`  ReserveToken       ${tokenAddr}`);
    console.log(`  RedemptionManager  ${rmAddr}`);
    console.log(`  supplyCap          ${cap === 0n ? "none (0)" : cap.toString()}`);

    // compliance hook
    const complianceAdmins = R(t.roles.complianceAdmins);
    await grantTemp(token, `${t.symbol}.ReserveToken`, "COMPLIANCE_ADMIN_ROLE", complianceAdmins.includes(me));
    await send("register RedemptionManager as system holder", registry.setRecord(rmAddr, KYC_APPROVED, "0x0000", 0));
    await send("token.setComplianceRegistry", token.setComplianceRegistry(await addr(registry)));
    if (config.compliance.enabled) await send("token.setComplianceEnabled(true)", token.setComplianceEnabled(true));

    // reserve guard hook
    await send("token.setReserveGuard", token.setReserveGuard(await addr(guard)));
    if (config.reserveGuard.enabled) await send("token.setReserveGuardEnabled(true)", token.setReserveGuardEnabled(true));
    if (programId !== ethers.ZeroHash) {
      await send(`guard.bindToken(${t.programId})`, guard.bindToken(tokenAddr, programId));
      if (t.tokensPerUnit !== null) {
        const ratio = toUnits(t.tokensPerUnit, t.decimals);
        await send(`guard.setTokensPerUnit = ${ratio}`, guard.setTokensPerUnit(programId, ratio));
      } else {
        console.log("  · tokensPerUnit UNSET (minting blocked while guard enabled)");
      }
    } else {
      console.log("  · programId UNSET: token not bound to guard (minting blocked while guard enabled)");
    }

    // static-cap-mode switch (fail-closed by default)
    if (t.mintingEnabled) {
      await send("token.setMintingEnabled(true)", token.setMintingEnabled(true));
      if (cap === 0n && !config.reserveGuard.enabled) {
        console.log("  ! mintingEnabled=true but supplyCap unset and guard disabled: minting remains BLOCKED");
      }
    } else if (!config.reserveGuard.enabled) {
      console.log("  · guard disabled and mintingEnabled=false: minting BLOCKED");
    }

    if (t.document.uri !== null || t.document.hash !== null) {
      await send("token.setDocument", token.setDocument(t.document.uri ?? "", t.document.hash ?? ethers.ZeroHash));
    }

    // token roles
    await grant(token, `${t.symbol}.ReserveToken`, "BURNER_ROLE", [rmAddr, ...R(t.roles.burners)]);
    await grant(token, `${t.symbol}.ReserveToken`, "MINTER_ROLE", R(t.roles.minters));
    await grant(token, `${t.symbol}.ReserveToken`, "PAUSER_ROLE", R(t.roles.pausers));
    await grant(token, `${t.symbol}.ReserveToken`, "TREASURY_ROLE", R(t.roles.treasuryOperators));
    await grant(token, `${t.symbol}.ReserveToken`, "COMPLIANCE_ADMIN_ROLE", complianceAdmins);

    // redemption (disabled by default)
    const rmName = `${t.symbol}.RedemptionManager`;
    await grantTemp(rm, rmName, "CONFIG_ADMIN_ROLE", false);
    const minA = toUnits(t.redemption.minAmount, t.decimals);
    const maxA = toUnits(t.redemption.maxAmount, t.decimals);
    if (minA !== 0n || maxA !== 0n) await send(`redemption thresholds ${minA}/${maxA}`, rm.setThresholds(minA, maxA));
    if (t.redemption.enabled) await send("redemption.setEnabled(true)", rm.setEnabled(true));
    else console.log("  · redemption module DISABLED");
    await grant(rm, rmName, "REDEMPTION_OPERATOR_ROLE", R(t.redemption.operators));
    await grant(rm, rmName, "PAUSER_ROLE", R(t.redemption.pausers));

    // treasury daily limit
    const limit = toUnits(t.treasuryDailyLimit, t.decimals);
    if (limit !== 0n) await send(`treasury.setDailyLimit = ${limit}`, treasury.setDailyLimit(tokenAddr, limit));

    tokensOut[t.key] = {
      name: t.name,
      symbol: t.symbol,
      decimals: t.decimals,
      programId,
      ReserveToken: { address: tokenAddr, args: tokenArgs.map(String) },
      RedemptionManager: { address: rmAddr, args: rmArgs.map(String) },
    };
  }

  // ---------------------------------------------------------------- cleanup temp roles
  if (config.revokeDeployerWiringRoles) {
    console.log("\nRevoking deployer wiring roles…");
    for (const tr of tempRoles) {
      if (tr.keep) continue;
      const role: string = await (tr.c as any)[tr.role]();
      if (await tr.c.hasRole(role, me)) await send(`${tr.name}.revoke ${tr.role} ← deployer`, (tr.c as any).revokeRole(role, me));
    }
  }

  // ---------------------------------------------------------------- multisig handover
  const allAdminContracts: [string, any][] = [
    ["ComplianceRegistry", registry],
    ["ReserveGuard", guard],
    ["Treasury", treasury],
    ["AuditAnchor", anchor],
  ];
  for (const t of config.tokens) {
    const o = tokensOut[t.key] as any;
    allAdminContracts.push([`${t.symbol}.ReserveToken`, await ethers.getContractAt("ReserveToken", o.ReserveToken.address)]);
    allAdminContracts.push([
      `${t.symbol}.RedemptionManager`,
      await ethers.getContractAt("RedemptionManager", o.RedemptionManager.address),
    ]);
  }
  let handover: unknown = null;
  if (config.safeMultisig) {
    const safe = ethers.getAddress(config.safeMultisig);
    console.log(`\nStarting DEFAULT_ADMIN handover to Safe ${safe} (accept after ${delay}s)…`);
    for (const [n, c] of allAdminContracts) await send(`${n}.beginDefaultAdminTransfer`, c.beginDefaultAdminTransfer(safe));
    handover = { safe, delaySeconds: Number(delay), status: "pending_acceptance_by_safe" };
  } else {
    console.log("\n· safeMultisig not set — deployer remains DEFAULT_ADMIN (acceptable for local/test only).");
  }

  // ---------------------------------------------------------------- output
  const out = {
    _notice: "ReserveChain is in development. TESTNET deployment only. No tokens are offered or sold.",
    network: network.name,
    chainId: Number(chainId),
    deployer: me,
    deployedAt: new Date().toISOString(),
    blockNumber: await ethers.provider.getBlockNumber(),
    configFile: path.basename(file),
    configSha256: hash,
    adminTransferDelaySeconds: Number(delay),
    contracts: {
      ComplianceRegistry: { address: await addr(registry), args: [me, String(delay)] },
      ReserveGuard: { address: await addr(guard), args: [me, String(delay)] },
      Treasury: { address: await addr(treasury), args: [me, String(delay)] },
      AuditAnchor: { address: await addr(anchor), args: [me, String(delay)] },
    },
    tokens: tokensOut,
    roles: granted,
    adminHandover: handover,
  };
  const dir = path.resolve(__dirname, "..", "deployments");
  fs.mkdirSync(dir, { recursive: true });
  const outFile = path.join(dir, `${network.name}.json`);
  fs.writeFileSync(outFile, JSON.stringify(out, null, 2) + "\n");
  console.log(`\nWrote ${path.relative(process.cwd(), outFile)}`);
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exitCode = 1;
});
