import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture, time } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll, EU_EEA, j, KYC, ELIG, role } from "./fixtures";

describe("ComplianceRegistry", () => {
  it("defaults: unknown address is NotApproved and not eligible", async () => {
    const { registry, alice } = await loadFixture(deployAll);
    const r = await registry.getRecord(alice.address);
    expect(r.status).to.equal(KYC.None);
    expect(r.frozen).to.equal(false);
    expect(await registry.eligibilityOf(alice.address)).to.equal(ELIG.NotApproved);
    expect(await registry.isEligible(alice.address)).to.equal(false);
    expect(await registry.blockedJurisdictions()).to.deep.equal([]);
  });

  it("KYC operator writes records; others cannot", async () => {
    const { registry, kyc, alice, outsider } = await loadFixture(deployAll);
    await expect(registry.connect(kyc).setRecord(alice.address, KYC.Approved, j("CH"), 0))
      .to.emit(registry, "RecordUpdated")
      .withArgs(alice.address, KYC.Approved, j("CH"), 0);
    expect(await registry.isEligible(alice.address)).to.equal(true);
    await expect(registry.connect(outsider).setRecord(alice.address, KYC.Approved, j("CH"), 0))
      .to.be.revertedWithCustomError(registry, "AccessControlUnauthorizedAccount")
      .withArgs(outsider.address, role("KYC_OPERATOR_ROLE"));
    await expect(registry.connect(outsider).setFrozen(alice.address, true)).to.be.revertedWithCustomError(
      registry,
      "AccessControlUnauthorizedAccount",
    );
    await expect(registry.connect(kyc).setRecord(ethers.ZeroAddress, KYC.Approved, j("CH"), 0)).to.be.revertedWithCustomError(
      registry,
      "ZeroAddress",
    );
    await expect(registry.connect(kyc).setFrozen(ethers.ZeroAddress, true)).to.be.revertedWithCustomError(
      registry,
      "ZeroAddress",
    );
  });

  it("each non-approved status is ineligible", async () => {
    const { registry, kyc, alice } = await loadFixture(deployAll);
    for (const s of [KYC.None, KYC.Pending, KYC.Rejected, KYC.Revoked]) {
      await registry.connect(kyc).setRecord(alice.address, s, j("CH"), 0);
      expect(await registry.eligibilityOf(alice.address)).to.equal(ELIG.NotApproved);
    }
  });

  it("blocked jurisdictions seeded from config list (EU/EEA) make holders ineligible", async () => {
    const { registry, admin, kyc, alice, outsider } = await loadFixture(deployAll);
    await expect(registry.connect(outsider).setJurisdictionsBlocked([j("DE")], true)).to.be.revertedWithCustomError(
      registry,
      "AccessControlUnauthorizedAccount",
    );
    await expect(registry.connect(admin).setJurisdictionsBlocked(EU_EEA.map(j), true))
      .to.emit(registry, "JurisdictionBlockedUpdated")
      .withArgs(j("AT"), true);
    expect((await registry.blockedJurisdictions()).length).to.equal(EU_EEA.length);
    for (const c of ["DE", "IT", "ES", "NO", "IS", "LI"]) expect(await registry.isJurisdictionBlocked(j(c))).to.equal(true);
    expect(await registry.isJurisdictionBlocked(j("CH"))).to.equal(false);
    expect(await registry.isJurisdictionBlocked(j("US"))).to.equal(false);

    await registry.connect(kyc).setRecord(alice.address, KYC.Approved, j("IT"), 0);
    expect(await registry.eligibilityOf(alice.address)).to.equal(ELIG.JurisdictionBlocked);

    // re-adding is a no-op (no event)
    await expect(registry.connect(admin).setJurisdictionsBlocked([j("IT")], true)).to.not.emit(
      registry,
      "JurisdictionBlockedUpdated",
    );
    await expect(registry.connect(admin).setJurisdictionsBlocked([j("IT")], false))
      .to.emit(registry, "JurisdictionBlockedUpdated")
      .withArgs(j("IT"), false);
    expect(await registry.isEligible(alice.address)).to.equal(true);
    await expect(registry.connect(admin).setJurisdictionsBlocked(["0x0000"], true)).to.be.revertedWithCustomError(
      registry,
      "InvalidJurisdiction",
    );
  });

  it("expiry lapses eligibility; 0 means no expiry", async () => {
    const { registry, kyc, alice } = await loadFixture(deployAll);
    const exp = (await time.latest()) + 1000;
    await registry.connect(kyc).setRecord(alice.address, KYC.Approved, j("CH"), exp);
    expect(await registry.isEligible(alice.address)).to.equal(true);
    await time.increaseTo(exp);
    expect(await registry.eligibilityOf(alice.address)).to.equal(ELIG.Expired);
  });

  it("freeze overrides approval and survives record rewrites", async () => {
    const { registry, kyc, alice } = await loadFixture(deployAll);
    await registry.connect(kyc).setRecord(alice.address, KYC.Approved, j("CH"), 0);
    await expect(registry.connect(kyc).setFrozen(alice.address, true))
      .to.emit(registry, "FrozenUpdated")
      .withArgs(alice.address, true);
    expect(await registry.eligibilityOf(alice.address)).to.equal(ELIG.Frozen);
    await registry.connect(kyc).setRecord(alice.address, KYC.Approved, j("US"), 0);
    expect((await registry.getRecord(alice.address)).frozen).to.equal(true);
    await registry.connect(kyc).setFrozen(alice.address, false);
    expect(await registry.isEligible(alice.address)).to.equal(true);
  });

  it("batch setters", async () => {
    const { registry, kyc, alice, bob, carol } = await loadFixture(deployAll);
    await registry
      .connect(kyc)
      .setRecordsBatch([alice.address, bob.address], [KYC.Approved, KYC.Pending], [j("CH"), j("SG")], [0, 0]);
    expect(await registry.isEligible(alice.address)).to.equal(true);
    expect(await registry.isEligible(bob.address)).to.equal(false);
    await expect(
      registry.connect(kyc).setRecordsBatch([alice.address], [KYC.Approved, KYC.Approved], [j("CH")], [0]),
    ).to.be.revertedWithCustomError(registry, "LengthMismatch");
    await expect(
      registry.connect(kyc).setRecordsBatch([alice.address], [KYC.Approved], [j("CH"), j("CH")], [0]),
    ).to.be.revertedWithCustomError(registry, "LengthMismatch");
    await expect(
      registry.connect(kyc).setRecordsBatch([alice.address], [KYC.Approved], [j("CH")], [0, 0]),
    ).to.be.revertedWithCustomError(registry, "LengthMismatch");
    await registry.connect(kyc).setFrozenBatch([alice.address, carol.address], true);
    expect((await registry.getRecord(carol.address)).frozen).to.equal(true);
    expect(await registry.isEligible(alice.address)).to.equal(false);
  });

  it("canTransfer screens both sides and skips address(0)", async () => {
    const { registry, kyc, alice, bob, outsider } = await loadFixture(deployAll);
    await registry.connect(kyc).setRecordsBatch([alice.address, bob.address], [KYC.Approved, KYC.Approved], [j("CH"), j("US")], [0, 0]);
    expect(await registry.canTransfer(alice.address, bob.address, 1)).to.equal(true);
    expect(await registry.canTransfer(ethers.ZeroAddress, bob.address, 1)).to.equal(true);
    expect(await registry.canTransfer(alice.address, ethers.ZeroAddress, 1)).to.equal(true);
    expect(await registry.canTransfer(outsider.address, bob.address, 1)).to.equal(false);
    expect(await registry.canTransfer(alice.address, outsider.address, 1)).to.equal(false);
  });
});
