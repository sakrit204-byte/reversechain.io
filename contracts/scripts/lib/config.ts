import * as fs from "fs";
import * as path from "path";
import { ethers } from "ethers";

/**
 * Deployment configuration schema (config/<network>.json).
 * Every tokenomics field is nullable and defaults to "unset". See contracts/README.md -> "Configuration".
 */
export interface TokenConfig {
  key: string;
  name: string;
  symbol: string;
  decimals: number;
  /** Static supply cap in WHOLE tokens (decimal string). null = no static cap. */
  supplyCap: string | null;
  /** Static-cap-mode minting switch (requires non-zero supplyCap; ignored while the guard is enabled). Default false. */
  mintingEnabled: boolean;
  /** Program label (hashed with keccak256) or 0x-prefixed bytes32. null = unset. */
  programId: string | null;
  document: { uri: string | null; hash: string | null };
  /** Token amount (WHOLE tokens, decimal string) per one attested reserve unit. null = UNSET => minting blocked. */
  tokensPerUnit: string | null;
  roles: {
    minters: string[];
    burners: string[];
    pausers: string[];
    complianceAdmins: string[];
    treasuryOperators: string[];
  };
  redemption: {
    enabled: boolean;
    /** WHOLE tokens; null = not set */
    minAmount: string | null;
    /** WHOLE tokens; null = not set */
    maxAmount: string | null;
    operators: string[];
    pausers: string[];
  };
  /** Treasury immediate daily withdrawal limit in WHOLE tokens; null = 0 (no immediate withdrawals). */
  treasuryDailyLimit: string | null;
}

export interface DeployConfig {
  network: string;
  /** Delay (seconds) applied to DEFAULT_ADMIN_ROLE transfers on every contract. */
  adminTransferDelaySeconds: number;
  /** Safe multisig address to receive DEFAULT_ADMIN_ROLE (two-step). null = deployer remains admin. */
  safeMultisig: string | null;
  /** Revoke the temporary wiring roles the deployer granted itself (unless also listed in config). */
  revokeDeployerWiringRoles: boolean;
  compliance: {
    enabled: boolean;
    /** ISO-3166 alpha-2 codes to block (EU/EEA list supplied here - never hard-coded in Solidity). */
    blockedJurisdictions: string[];
    kycOperators: string[];
    jurisdictionAdmins: string[];
  };
  reserveGuard: {
    enabled: boolean;
    /** Staleness window in seconds; null = UNSET => minting blocked. */
    maxAttestationAgeSeconds: number | null;
    attestors: string[];
    guardAdmins: string[];
  };
  treasury: {
    /** null = timelock queue disabled */
    timelockDelaySeconds: number | null;
    /** null = queued withdrawals never expire */
    gracePeriodSeconds: number | null;
    treasurers: string[];
    limitAdmins: string[];
    pausers: string[];
  };
  auditAnchor: { anchorers: string[] };
  tokens: TokenConfig[];
}

/** Recursively strips keys starting with "_" (used for inline JSON comments). */
function stripComments<T>(v: T): T {
  if (Array.isArray(v)) return v.map(stripComments) as unknown as T;
  if (v && typeof v === "object") {
    const out: Record<string, unknown> = {};
    for (const [k, val] of Object.entries(v)) if (!k.startsWith("_")) out[k] = stripComments(val);
    return out as T;
  }
  return v;
}

/**
 * Loads config/<network>.json (or the file named by env DEPLOY_CONFIG).
 * The in-process "hardhat" network uses config/localhost.json.
 * @param network Hardhat network name.
 */
export function loadConfig(network: string): { config: DeployConfig; file: string; hash: string } {
  const root = path.resolve(__dirname, "..", "..");
  const file = process.env.DEPLOY_CONFIG
    ? path.resolve(process.env.DEPLOY_CONFIG)
    : path.join(root, "config", `${network === "hardhat" ? "localhost" : network}.json`);
  if (!fs.existsSync(file)) {
    throw new Error(
      `Config not found: ${file}. Copy config/${network}.example.json to config/${network}.json and fill it in.`,
    );
  }
  const raw = fs.readFileSync(file, "utf8");
  const config = stripComments(JSON.parse(raw)) as DeployConfig;
  validate(config);
  return { config, file, hash: ethers.sha256(ethers.toUtf8Bytes(raw)) };
}

function validate(c: DeployConfig): void {
  const fail = (m: string): never => {
    throw new Error(`Invalid deploy config: ${m}`);
  };
  if (!Array.isArray(c.tokens) || c.tokens.length === 0) fail("tokens[] must be non-empty");
  if (!Number.isInteger(c.adminTransferDelaySeconds) || c.adminTransferDelaySeconds < 0) {
    fail("adminTransferDelaySeconds must be a non-negative integer");
  }
  if (c.safeMultisig !== null && !ethers.isAddress(c.safeMultisig)) fail("safeMultisig must be an address or null");
  for (const code of c.compliance.blockedJurisdictions) {
    if (!/^[A-Z]{2}$/.test(code)) fail(`blockedJurisdictions: invalid code "${code}"`);
  }
  const keys = new Set<string>();
  for (const t of c.tokens) {
    if (!t.key || keys.has(t.key)) fail(`token key missing or duplicated: ${t.key}`);
    keys.add(t.key);
    if (!t.name || !t.symbol) fail(`token ${t.key}: name and symbol are required`);
    if (typeof t.mintingEnabled !== "boolean") fail(`token ${t.key}: mintingEnabled must be boolean`);
    if (!Number.isInteger(t.decimals) || t.decimals < 0 || t.decimals > 18) fail(`token ${t.key}: decimals 0..18`);
    if (t.document.hash !== null && !/^0x[0-9a-fA-F]{64}$/.test(t.document.hash)) {
      fail(`token ${t.key}: document.hash must be 0x-prefixed bytes32 or null`);
    }
  }
}

/** Converts an ISO alpha-2 code to bytes2 hex. */
export const toBytes2 = (code: string): string => ethers.hexlify(ethers.toUtf8Bytes(code));

/** Resolves a program id label / bytes32 / null. */
export function toProgramId(v: string | null): string {
  if (v === null || v === "") return ethers.ZeroHash;
  if (/^0x[0-9a-fA-F]{64}$/.test(v)) return v;
  return ethers.id(v);
}

/** Parses an optional whole-token decimal string into base units (null => 0n). */
export function toUnits(v: string | null, decimals: number): bigint {
  if (v === null || v === "") return 0n;
  return ethers.parseUnits(v, decimals);
}

/** Resolves role-holder entries: the literal "deployer" maps to the deployer address. */
export function resolveAddresses(list: string[] | undefined, deployer: string): string[] {
  return (list ?? []).map((a) => {
    if (a === "deployer") return deployer;
    if (!ethers.isAddress(a)) throw new Error(`Invalid address in config: ${a}`);
    return ethers.getAddress(a);
  });
}
