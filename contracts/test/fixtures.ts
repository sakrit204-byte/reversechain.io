import { ethers } from "hardhat";
import { time } from "@nomicfoundation/hardhat-network-helpers";

/** Status enum mirrors ComplianceRegistry.Status */
export const KYC = { None: 0, Pending: 1, Approved: 2, Rejected: 3, Revoked: 4 } as const;
/** Eligibility enum mirrors ComplianceRegistry.Eligibility */
export const ELIG = { Eligible: 0, NotApproved: 1, Expired: 2, Frozen: 3, JurisdictionBlocked: 4 } as const;
/** RedemptionManager.Status */
export const RSTATUS = { None: 0, Pending: 1, Approved: 2, Rejected: 3, Cancelled: 4 } as const;
/** Treasury.QueueStatus */
export const QSTATUS = { None: 0, Queued: 1, Executed: 2, Cancelled: 3 } as const;

/** Test-only EU/EEA list (in production this comes from config/<network>.json). */
export const EU_EEA = [
  "AT", "BE", "BG", "HR", "CY", "CZ", "DK", "EE", "FI", "FR", "DE", "GR", "HU", "IE", "IT",
  "LV", "LT", "LU", "MT", "NL", "PL", "PT", "RO", "SK", "SI", "ES", "SE", "IS", "LI", "NO",
];

export const j = (code: string) => ethers.hexlify(ethers.toUtf8Bytes(code)) as `0x${string}`;
export const role = (name: string) => ethers.id(name);
export const PROGRAM_ID = ethers.id("TEST-PROGRAM");
/** Arbitrary TEST values only — not ReserveChain parameters. */
export const TEST_DECIMALS = 18;
export const ADMIN_DELAY = 3600n;

/** Arbitrary TEST static cap used to put the token in static-cap mode for generic tests. */
export const TEST_CAP = ethers.parseEther("1000000000");

/**
 * Deploys everything with default (fail-closed) minting state: no cap, mintingEnabled=false, guard disabled.
 */
export async function deployBare() {
  const [admin, minter, burner, pauser, complianceAdmin, treasuryRole, kyc, attestor, operator, alice, bob, carol, outsider] =
    await ethers.getSigners();

  const Token = await ethers.getContractFactory("ReserveToken");
  const token = await Token.deploy("Test Reserve Token", "tRSV", TEST_DECIMALS, 0n, PROGRAM_ID, admin.address, ADMIN_DELAY);

  const Registry = await ethers.getContractFactory("ComplianceRegistry");
  const registry = await Registry.deploy(admin.address, ADMIN_DELAY);

  const Guard = await ethers.getContractFactory("ReserveGuard");
  const guard = await Guard.deploy(admin.address, ADMIN_DELAY);

  const RM = await ethers.getContractFactory("RedemptionManager");
  const redemption = await RM.deploy(await token.getAddress(), admin.address, ADMIN_DELAY);

  const Treasury = await ethers.getContractFactory("Treasury");
  const treasury = await Treasury.deploy(admin.address, ADMIN_DELAY);

  const Anchor = await ethers.getContractFactory("AuditAnchor");
  const anchor = await Anchor.deploy(admin.address, ADMIN_DELAY);

  // Roles
  await token.grantRole(await token.MINTER_ROLE(), minter.address);
  await token.grantRole(await token.BURNER_ROLE(), burner.address);
  await token.grantRole(await token.BURNER_ROLE(), await redemption.getAddress());
  await token.grantRole(await token.PAUSER_ROLE(), pauser.address);
  await token.grantRole(await token.COMPLIANCE_ADMIN_ROLE(), complianceAdmin.address);
  await token.grantRole(await token.TREASURY_ROLE(), treasuryRole.address);
  await registry.grantRole(await registry.KYC_OPERATOR_ROLE(), kyc.address);
  await registry.grantRole(await registry.JURISDICTION_ADMIN_ROLE(), admin.address);
  await guard.grantRole(await guard.ATTESTOR_ROLE(), attestor.address);
  await guard.grantRole(await guard.GUARD_ADMIN_ROLE(), admin.address);
  await redemption.grantRole(await redemption.REDEMPTION_OPERATOR_ROLE(), operator.address);
  await redemption.grantRole(await redemption.CONFIG_ADMIN_ROLE(), admin.address);
  await redemption.grantRole(await redemption.PAUSER_ROLE(), pauser.address);
  await treasury.grantRole(await treasury.TREASURER_ROLE(), operator.address);
  await treasury.grantRole(await treasury.LIMIT_ADMIN_ROLE(), admin.address);
  await treasury.grantRole(await treasury.PAUSER_ROLE(), pauser.address);
  await anchor.grantRole(await anchor.ANCHOR_ROLE(), operator.address);

  return {
    token, registry, guard, redemption, treasury, anchor,
    admin, minter, burner, pauser, complianceAdmin, treasuryRole, kyc, attestor, operator, alice, bob, carol, outsider,
  };
}

/**
 * deployBare + static-cap mode (TEST_CAP, mintingEnabled=true) so generic tests can mint without the guard.
 */
export async function deployAll() {
  const f = await deployBare();
  await f.token.connect(f.admin).setSupplyCap(TEST_CAP);
  await f.token.connect(f.admin).setMintingEnabled(true);
  return f;
}

/** Deploys everything and wires compliance on, with alice/bob approved (CH/US), carol EU (DE). */
export async function deployWithCompliance() {
  const f = await deployAll();
  const { registry, token, complianceAdmin, kyc, admin, alice, bob, carol, redemption, treasury } = f;
  await registry.connect(admin).setJurisdictionsBlocked(EU_EEA.map(j), true);
  await registry
    .connect(kyc)
    .setRecordsBatch(
      [alice.address, bob.address, carol.address, await redemption.getAddress(), await treasury.getAddress()],
      [KYC.Approved, KYC.Approved, KYC.Approved, KYC.Approved, KYC.Approved],
      [j("CH"), j("US"), j("DE"), "0x0000", "0x0000"],
      [0, 0, 0, 0, 0],
    );
  await token.connect(complianceAdmin).setComplianceRegistry(await registry.getAddress());
  await token.connect(complianceAdmin).setComplianceEnabled(true);
  return f;
}

export async function now(): Promise<bigint> {
  return BigInt(await time.latest());
}
