import { expect } from "chai";
import * as fs from "fs";
import * as path from "path";
import { ethers } from "hardhat";
import { assertSafeNetwork, MAINNET_OVERRIDE_VALUE } from "../scripts/lib/safety";
import { loadConfig, resolveAddresses, toBytes2, toProgramId, toUnits } from "../scripts/lib/config";

describe("Deployment tooling", () => {
  describe("network safety guard", () => {
    const saved = process.env.ALLOW_MAINNET;
    afterEach(() => {
      if (saved === undefined) delete process.env.ALLOW_MAINNET;
      else process.env.ALLOW_MAINNET = saved;
    });

    it("allows hardhat, sepolia, amoy", () => {
      for (const id of [31337, 11155111, 80002, 31337n]) expect(() => assertSafeNetwork(id)).to.not.throw();
    });

    it("refuses Ethereum and Polygon mainnet and unknown chains", () => {
      delete process.env.ALLOW_MAINNET;
      expect(() => assertSafeNetwork(1)).to.throw(/Refusing to run on chainId 1/);
      expect(() => assertSafeNetwork(137)).to.throw(/Polygon PoS mainnet/);
      expect(() => assertSafeNetwork(424242)).to.throw(/unrecognised chain/);
      process.env.ALLOW_MAINNET = "yes";
      expect(() => assertSafeNetwork(1)).to.throw(/Refusing/);
    });

    it("only the exact written-authorisation value overrides (and still warns)", () => {
      process.env.ALLOW_MAINNET = MAINNET_OVERRIDE_VALUE;
      const orig = console.warn;
      const lines: string[] = [];
      console.warn = (m: string) => lines.push(m);
      try {
        expect(() => assertSafeNetwork(1)).to.not.throw();
      } finally {
        console.warn = orig;
      }
      expect(lines.join("\n")).to.match(/WARNING/);
    });
  });

  describe("config helpers", () => {
    it("shipped configs load and keep every tokenomics field unset", () => {
      for (const name of ["localhost.json", "sepolia.example.json", "amoy.example.json"]) {
        process.env.DEPLOY_CONFIG = path.resolve(__dirname, "..", "config", name);
        const { config } = loadConfig("x");
        expect(config.reserveGuard.maxAttestationAgeSeconds).to.equal(null);
        expect(config.compliance.blockedJurisdictions).to.include.members(["DE", "IT", "ES", "NO", "IS", "LI"]);
        expect(config.compliance.blockedJurisdictions).to.have.length(30);
        for (const t of config.tokens) {
          expect(t.supplyCap).to.equal(null);
          expect(t.mintingEnabled).to.equal(false);
          expect(t.tokensPerUnit).to.equal(null);
          expect(t.treasuryDailyLimit).to.equal(null);
          expect(t.redemption.enabled).to.equal(false);
          expect(t.redemption.minAmount).to.equal(null);
          expect(t.redemption.maxAmount).to.equal(null);
          expect((t as any)._comment).to.equal(undefined); // comments stripped
        }
        expect(config.treasury.timelockDelaySeconds).to.equal(null);
      }
      delete process.env.DEPLOY_CONFIG;
    });

    it("rejects invalid configs", () => {
      const dir = fs.mkdtempSync(path.join(require("os").tmpdir(), "rc-cfg-"));
      const base = JSON.parse(fs.readFileSync(path.resolve(__dirname, "..", "config", "localhost.json"), "utf8"));
      const cases: [string, (c: any) => void][] = [
        ["tokens", (c) => (c.tokens = [])],
        ["adminTransferDelaySeconds", (c) => (c.adminTransferDelaySeconds = -1)],
        ["safeMultisig", (c) => (c.safeMultisig = "0x123")],
        ["blockedJurisdictions", (c) => (c.compliance.blockedJurisdictions = ["de"])],
        ["duplicated", (c) => (c.tokens[1].key = c.tokens[0].key)],
        ["decimals", (c) => (c.tokens[0].decimals = 19)],
        ["mintingEnabled", (c) => (c.tokens[0].mintingEnabled = "yes")],
        ["name and symbol", (c) => (c.tokens[0].symbol = "")],
        ["document.hash", (c) => (c.tokens[0].document.hash = "0x12")],
      ];
      for (const [msg, mutate] of cases) {
        const c = JSON.parse(JSON.stringify(base));
        mutate(c);
        const f = path.join(dir, `${msg.replace(/\W/g, "")}.json`);
        fs.writeFileSync(f, JSON.stringify(c));
        process.env.DEPLOY_CONFIG = f;
        expect(() => loadConfig("x"), msg).to.throw(new RegExp(msg));
      }
      process.env.DEPLOY_CONFIG = path.join(dir, "missing.json");
      expect(() => loadConfig("x")).to.throw(/Config not found/);
      delete process.env.DEPLOY_CONFIG;
    });

    it("conversions", () => {
      expect(toBytes2("CH")).to.equal("0x4348");
      expect(toProgramId(null)).to.equal(ethers.ZeroHash);
      expect(toProgramId("RC-PROGRAM-CU")).to.equal(ethers.id("RC-PROGRAM-CU"));
      const raw = ethers.id("x");
      expect(toProgramId(raw)).to.equal(raw);
      expect(toUnits(null, 18)).to.equal(0n);
      expect(toUnits("1.5", 6)).to.equal(1_500_000n);
      const a = "0x00000000000000000000000000000000000000aa";
      expect(resolveAddresses(["deployer", a], "0xDEP")).to.deep.equal(["0xDEP", ethers.getAddress(a)]);
      expect(() => resolveAddresses(["nope"], "0x")).to.throw(/Invalid address/);
    });
  });
});
