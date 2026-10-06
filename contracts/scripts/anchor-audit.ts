/**
 * Anchors the CMS audit-trail chain head on-chain via AuditAnchor (TESTNET ONLY).
 *
 *   WP_URL=https://cms.example npx hardhat run scripts/anchor-audit.ts --network sepolia
 *
 * Flow:
 *   1. GET {WP_URL}/wp-json/rc/v1/audit/head  →  {"seq": <int>, "chain_head": "<64 lowercase hex, no 0x>", "at": "<ISO UTC>"}
 *   2. If seq > AuditAnchor.latestSeq(): anchor(0x + chain_head, seq, uri)   (otherwise: nothing to do)
 *   3. Optional: POST {WP_URL}/wp-json/rc/v1/audit/anchor  {"seq","chain_head","network","tx_hash"}
 *      using WordPress Application Password basic auth (WP_USER / WP_APP_PASSWORD; capability rc_anchor_audit).
 *
 * Environment:
 *   WP_URL            (required) WordPress base URL, e.g. http://localhost:8080
 *   WP_USER           (optional) WordPress username for the report-back POST
 *   WP_APP_PASSWORD   (optional) WordPress Application Password for the report-back POST
 *   ANCHOR_ADDRESS    (optional) AuditAnchor address; defaults to deployments/<network>.json
 *   ANCHOR_URI        (optional) context URI stored on-chain; defaults to {WP_URL}/wp-json/rc/v1/audit/head
 *   DRY_RUN=true      (optional) fetch and validate only; send no transaction
 */
import * as fs from "fs";
import * as path from "path";
import { ethers, network } from "hardhat";
import { assertSafeNetwork } from "./lib/safety";

interface AuditHead {
  seq: number;
  chain_head: string;
  at: string;
}

function baseUrl(): string {
  const u = process.env.WP_URL;
  if (!u) throw new Error("WP_URL is not set (WordPress base URL exposing /wp-json/rc/v1/audit/head).");
  return u.replace(/\/+$/, "");
}

async function fetchHead(wp: string): Promise<AuditHead> {
  const url = `${wp}/wp-json/rc/v1/audit/head`;
  const res = await fetch(url, { headers: { Accept: "application/json" } });
  if (!res.ok) throw new Error(`GET ${url} → HTTP ${res.status}`);
  const body = (await res.json()) as Partial<AuditHead>;
  if (!Number.isSafeInteger(body.seq) || (body.seq as number) <= 0) throw new Error(`Invalid seq in response: ${body.seq}`);
  if (typeof body.chain_head !== "string" || !/^[0-9a-f]{64}$/.test(body.chain_head)) {
    throw new Error(`Invalid chain_head in response (expected 64 lowercase hex chars, no 0x): ${body.chain_head}`);
  }
  return { seq: body.seq as number, chain_head: body.chain_head, at: String(body.at ?? "") };
}

function anchorAddress(): string {
  if (process.env.ANCHOR_ADDRESS) return ethers.getAddress(process.env.ANCHOR_ADDRESS);
  const file = path.resolve(__dirname, "..", "deployments", `${network.name}.json`);
  if (!fs.existsSync(file)) throw new Error(`No deployment record at ${file}; set ANCHOR_ADDRESS or deploy first.`);
  return JSON.parse(fs.readFileSync(file, "utf8")).contracts.AuditAnchor.address;
}

async function reportBack(wp: string, payload: Record<string, unknown>): Promise<void> {
  const user = process.env.WP_USER;
  const pass = process.env.WP_APP_PASSWORD;
  if (!user || !pass) {
    console.log("· WP_USER / WP_APP_PASSWORD not set — skipping report-back to WordPress.");
    return;
  }
  const url = `${wp}/wp-json/rc/v1/audit/anchor`;
  const res = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: "Basic " + Buffer.from(`${user}:${pass}`).toString("base64"),
    },
    body: JSON.stringify(payload),
  });
  if (!res.ok) {
    console.warn(`! POST ${url} → HTTP ${res.status}: ${(await res.text()).slice(0, 300)}`);
    return;
  }
  console.log(`✓ Reported anchor to WordPress (${url}).`);
}

async function main(): Promise<void> {
  const { chainId } = await ethers.provider.getNetwork();
  assertSafeNetwork(chainId);
  const wp = baseUrl();

  const head = await fetchHead(wp);
  const chainHead = "0x" + head.chain_head;
  console.log(`CMS audit head: seq=${head.seq} chain_head=${chainHead} at=${head.at}`);

  const anchor = await ethers.getContractAt("AuditAnchor", anchorAddress());
  const latestSeq = await anchor.latestSeq();
  console.log(`On-chain latestSeq: ${latestSeq} (AuditAnchor ${await anchor.getAddress()}, chainId ${chainId})`);

  if (BigInt(head.seq) <= latestSeq) {
    const already = await anchor.verify(head.seq, chainHead);
    console.log(
      already
        ? "✓ Head already anchored with matching chain_head — nothing to do."
        : `! seq ${head.seq} <= latestSeq ${latestSeq} and no matching anchor at this seq — nothing anchored. ` +
            "Investigate if the CMS chain was reset.",
    );
    return;
  }

  if (process.env.DRY_RUN === "true") {
    console.log("DRY_RUN=true — not sending a transaction.");
    return;
  }

  const uri = process.env.ANCHOR_URI ?? `${wp}/wp-json/rc/v1/audit/head`;
  const tx = await anchor.anchor(chainHead, head.seq, uri);
  const rcpt = await tx.wait();
  console.log(`✓ Anchored seq ${head.seq} in tx ${tx.hash} (block ${rcpt?.blockNumber}).`);

  await reportBack(wp, { seq: head.seq, chain_head: head.chain_head, network: network.name, tx_hash: tx.hash });
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : err);
  process.exitCode = 1;
});
