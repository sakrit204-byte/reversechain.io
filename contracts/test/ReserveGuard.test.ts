import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture, time } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll, PROGRAM_ID } from "./fixtures";

describe("ReserveGuard", () => {
  async function configured() {
    const f = await deployAll();
    const tokenAddr = await f.token.getAddress();
    await f.guard.connect(f.admin).bindToken(tokenAddr, PROGRAM_ID);
    await f.guard.connect(f.admin).setMaxAttestationAge(86400);
    return { ...f, tokenAddr };
  }

  it("is fail-closed by default (no ratio, no window, no binding, no attestation)", async () => {
    const { guard, token } = await loadFixture(deployAll);
    const t = await token.getAddress();
    expect(await guard.tokensPerUnit(PROGRAM_ID)).to.equal(0);
    expect(await guard.maxAttestationAge()).to.equal(0);
    expect(await guard.tokenProgram(t)).to.equal(ethers.ZeroHash);
    expect(await guard.maxMintable(t)).to.equal(0);
    expect(await guard.reserveCeiling(t)).to.equal(0);
    expect(await guard.isFresh(PROGRAM_ID)).to.equal(false);
    await expect(guard.latestAttestation(PROGRAM_ID)).to.be.revertedWithCustomError(guard, "NoAttestation");
  });

  it("attestor posts attestations; others cannot", async () => {
    const { guard, attestor, outsider } = await loadFixture(configured);
    const ts = await time.latest();
    await expect(guard.connect(attestor).postAttestation(PROGRAM_ID, 500, ethers.id("r"), "ipfs://r", ts))
      .to.emit(guard, "AttestationPosted")
      .withArgs(PROGRAM_ID, 0, 500, ethers.id("r"), "ipfs://r", ts, attestor.address);
    const a = await guard.latestAttestation(PROGRAM_ID);
    expect(a.units).to.equal(500);
    expect(a.uri).to.equal("ipfs://r");
    expect(a.attestor).to.equal(attestor.address);
    expect(await guard.attestationCount(PROGRAM_ID)).to.equal(1);
    expect((await guard.getAttestation(PROGRAM_ID, 0)).reportHash).to.equal(ethers.id("r"));
    await expect(
      guard.connect(outsider).postAttestation(PROGRAM_ID, 1, ethers.id("x"), "", ts),
    ).to.be.revertedWithCustomError(guard, "AccessControlUnauthorizedAccount");
  });

  it("validates attestation inputs and monotonic timestamps", async () => {
    const { guard, attestor } = await loadFixture(configured);
    const ts = await time.latest();
    await expect(
      guard.connect(attestor).postAttestation(ethers.ZeroHash, 1, ethers.id("r"), "", ts),
    ).to.be.revertedWithCustomError(guard, "InvalidProgramId");
    await expect(
      guard.connect(attestor).postAttestation(PROGRAM_ID, 1, ethers.ZeroHash, "", ts),
    ).to.be.revertedWithCustomError(guard, "InvalidReportHash");
    await expect(
      guard.connect(attestor).postAttestation(PROGRAM_ID, 1, ethers.id("r"), "", ts + 1000),
    ).to.be.revertedWithCustomError(guard, "AttestationInFuture");
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 1, ethers.id("r"), "", ts);
    await expect(guard.connect(attestor).postAttestation(PROGRAM_ID, 2, ethers.id("r2"), "", ts))
      .to.be.revertedWithCustomError(guard, "AttestationNotNewer")
      .withArgs(ts, ts);
  });

  it("unset ratio ⇒ maxMintable 0 even with fresh attestation", async () => {
    const { guard, attestor, tokenAddr } = await loadFixture(configured);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 1000, ethers.id("r"), "", await time.latest());
    expect(await guard.isFresh(PROGRAM_ID)).to.equal(true);
    expect(await guard.maxMintable(tokenAddr)).to.equal(0);
  });

  it("unset staleness window ⇒ maxMintable 0", async () => {
    const { guard, admin, attestor, tokenAddr } = await loadFixture(configured);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 3);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 1000, ethers.id("r"), "", await time.latest());
    expect(await guard.maxMintable(tokenAddr)).to.equal(3000);
    await expect(guard.connect(admin).setMaxAttestationAge(0))
      .to.emit(guard, "MaxAttestationAgeUpdated")
      .withArgs(86400, 0);
    expect(await guard.maxMintable(tokenAddr)).to.equal(0);
  });

  it("unbound token ⇒ maxMintable 0", async () => {
    const { guard, admin, attestor, tokenAddr } = await loadFixture(configured);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 3);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 1000, ethers.id("r"), "", await time.latest());
    await expect(guard.connect(admin).bindToken(tokenAddr, ethers.ZeroHash))
      .to.emit(guard, "TokenBound")
      .withArgs(tokenAddr, ethers.ZeroHash);
    expect(await guard.maxMintable(tokenAddr)).to.equal(0);
    await expect(guard.connect(admin).bindToken(ethers.ZeroAddress, PROGRAM_ID)).to.be.revertedWithCustomError(
      guard,
      "ZeroAddress",
    );
  });

  it("stale attestation ⇒ maxMintable 0; fresh attestation restores headroom", async () => {
    const { guard, admin, attestor, tokenAddr } = await loadFixture(configured);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 2);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 50, ethers.id("r"), "", await time.latest());
    expect(await guard.maxMintable(tokenAddr)).to.equal(100);
    await time.increase(86401);
    expect(await guard.isFresh(PROGRAM_ID)).to.equal(false);
    expect(await guard.maxMintable(tokenAddr)).to.equal(0);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 60, ethers.id("r2"), "", await time.latest());
    expect(await guard.maxMintable(tokenAddr)).to.equal(120);
  });

  it("headroom shrinks with supply and floors at zero when reserves drop", async () => {
    const { guard, token, admin, minter, attestor, alice, tokenAddr } = await loadFixture(configured);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 10);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 10, ethers.id("r"), "", await time.latest());
    await token.connect(minter).mint(alice.address, 80);
    expect(await guard.maxMintable(tokenAddr)).to.equal(20);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 5, ethers.id("r2"), "", await time.latest());
    expect(await guard.reserveCeiling(tokenAddr)).to.equal(50);
    expect(await guard.maxMintable(tokenAddr)).to.equal(0);
  });

  it("ceiling saturates instead of overflowing", async () => {
    const { guard, admin, attestor, tokenAddr } = await loadFixture(configured);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, ethers.MaxUint256);
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 2, ethers.id("r"), "", await time.latest());
    expect(await guard.reserveCeiling(tokenAddr)).to.equal(ethers.MaxUint256);
  });

  it("config is GUARD_ADMIN-only and validated", async () => {
    const { guard, admin, outsider, tokenAddr } = await loadFixture(configured);
    await expect(guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 5))
      .to.emit(guard, "TokensPerUnitUpdated")
      .withArgs(PROGRAM_ID, 0, 5);
    await expect(guard.connect(admin).setTokensPerUnit(ethers.ZeroHash, 5)).to.be.revertedWithCustomError(
      guard,
      "InvalidProgramId",
    );
    await expect(guard.connect(outsider).setTokensPerUnit(PROGRAM_ID, 5)).to.be.revertedWithCustomError(
      guard,
      "AccessControlUnauthorizedAccount",
    );
    await expect(guard.connect(outsider).setMaxAttestationAge(5)).to.be.revertedWithCustomError(
      guard,
      "AccessControlUnauthorizedAccount",
    );
    await expect(guard.connect(outsider).bindToken(tokenAddr, PROGRAM_ID)).to.be.revertedWithCustomError(
      guard,
      "AccessControlUnauthorizedAccount",
    );
  });
});
