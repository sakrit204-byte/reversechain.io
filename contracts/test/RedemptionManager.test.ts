import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll, deployWithCompliance, RSTATUS } from "./fixtures";

describe("RedemptionManager", () => {
  async function ready() {
    const f = await deployWithCompliance();
    const rmAddr = await f.redemption.getAddress();
    await f.token.connect(f.minter).mint(f.alice.address, 1000);
    await f.token.connect(f.alice).approve(rmAddr, ethers.MaxUint256);
    await f.redemption.connect(f.admin).setEnabled(true);
    return { ...f, rmAddr };
  }

  it("is disabled by default with thresholds unset", async () => {
    const { redemption, token, minter, alice } = await loadFixture(deployAll);
    expect(await redemption.enabled()).to.equal(false);
    expect(await redemption.minAmount()).to.equal(0);
    expect(await redemption.maxAmount()).to.equal(0);
    expect(await redemption.token()).to.equal(await token.getAddress());
    await token.connect(minter).mint(alice.address, 10);
    await token.connect(alice).approve(await redemption.getAddress(), 10);
    await expect(redemption.connect(alice).request(5)).to.be.revertedWithCustomError(redemption, "RedemptionDisabled");
  });

  it("rejects zero token address in constructor", async () => {
    const [a] = await ethers.getSigners();
    const F = await ethers.getContractFactory("RedemptionManager");
    await expect(F.deploy(ethers.ZeroAddress, a.address, 0)).to.be.revertedWithCustomError(F, "ZeroAddress");
  });

  it("full lifecycle: request → approve burns escrow and records fulfilment ref", async () => {
    const { redemption, token, operator, alice, rmAddr } = await loadFixture(ready);
    await expect(redemption.connect(alice).request(400))
      .to.emit(redemption, "RedemptionRequested")
      .withArgs(1, alice.address, 400);
    expect(await token.balanceOf(rmAddr)).to.equal(400);
    expect(await redemption.totalEscrowed()).to.equal(400);
    let r = await redemption.getRequest(1);
    expect(r.status).to.equal(RSTATUS.Pending);
    expect(r.requester).to.equal(alice.address);

    const ref = ethers.id("fulfilment-001");
    await expect(redemption.connect(operator).approve(1, ref))
      .to.emit(redemption, "RedemptionApproved")
      .withArgs(1, operator.address, 400, ref);
    r = await redemption.getRequest(1);
    expect(r.status).to.equal(RSTATUS.Approved);
    expect(r.fulfilmentRef).to.equal(ref);
    expect(await token.balanceOf(rmAddr)).to.equal(0);
    expect(await token.totalSupply()).to.equal(600);
    expect(await redemption.totalEscrowed()).to.equal(0);
    await expect(redemption.connect(operator).approve(1, ref))
      .to.be.revertedWithCustomError(redemption, "NotPending")
      .withArgs(1, RSTATUS.Approved);
  });

  it("reject returns escrow and records reason", async () => {
    const { redemption, token, operator, alice } = await loadFixture(ready);
    await redemption.connect(alice).request(300);
    const reason = ethers.id("kyc-review-failed");
    await expect(redemption.connect(operator).reject(1, reason))
      .to.emit(redemption, "RedemptionRejected")
      .withArgs(1, operator.address, 300, reason);
    expect(await token.balanceOf(alice.address)).to.equal(1000);
    expect((await redemption.getRequest(1)).status).to.equal(RSTATUS.Rejected);
    expect((await redemption.getRequest(1)).reasonHash).to.equal(reason);
    await expect(redemption.connect(alice).cancel(1)).to.be.revertedWithCustomError(redemption, "NotPending");
  });

  it("requester can cancel while pending; others cannot", async () => {
    const { redemption, token, alice, bob } = await loadFixture(ready);
    await redemption.connect(alice).request(250);
    await expect(redemption.connect(bob).cancel(1))
      .to.be.revertedWithCustomError(redemption, "NotRequester")
      .withArgs(1, bob.address);
    await expect(redemption.connect(alice).cancel(1))
      .to.emit(redemption, "RedemptionCancelled")
      .withArgs(1, alice.address, 250);
    expect(await token.balanceOf(alice.address)).to.equal(1000);
    expect((await redemption.getRequest(1)).status).to.equal(RSTATUS.Cancelled);
    await expect(redemption.connect(alice).cancel(1)).to.be.revertedWithCustomError(redemption, "NotPending");
    await expect(redemption.connect(alice).cancel(99))
      .to.be.revertedWithCustomError(redemption, "NotPending")
      .withArgs(99, RSTATUS.None);
  });

  it("only operator approves/rejects; fulfilment ref must be non-zero", async () => {
    const { redemption, alice, outsider, operator } = await loadFixture(ready);
    await redemption.connect(alice).request(10);
    await expect(redemption.connect(outsider).approve(1, ethers.id("x"))).to.be.revertedWithCustomError(
      redemption,
      "AccessControlUnauthorizedAccount",
    );
    await expect(redemption.connect(outsider).reject(1, ethers.ZeroHash)).to.be.revertedWithCustomError(
      redemption,
      "AccessControlUnauthorizedAccount",
    );
    await expect(redemption.connect(operator).approve(1, ethers.ZeroHash)).to.be.revertedWithCustomError(
      redemption,
      "InvalidReference",
    );
  });

  it("thresholds (configurable, unset by default) are enforced", async () => {
    const { redemption, admin, alice, outsider } = await loadFixture(ready);
    await expect(redemption.connect(admin).setThresholds(50, 10))
      .to.be.revertedWithCustomError(redemption, "InvalidThresholds")
      .withArgs(50, 10);
    await expect(redemption.connect(outsider).setThresholds(1, 2)).to.be.revertedWithCustomError(
      redemption,
      "AccessControlUnauthorizedAccount",
    );
    await expect(redemption.connect(admin).setThresholds(10, 100))
      .to.emit(redemption, "ThresholdsUpdated")
      .withArgs(10, 100);
    await expect(redemption.connect(alice).request(9))
      .to.be.revertedWithCustomError(redemption, "BelowMinimum")
      .withArgs(9, 10);
    await expect(redemption.connect(alice).request(101))
      .to.be.revertedWithCustomError(redemption, "AboveMaximum")
      .withArgs(101, 100);
    await redemption.connect(alice).request(10);
    await redemption.connect(alice).request(100);
    // only min set
    await redemption.connect(admin).setThresholds(5, 0);
    await redemption.connect(alice).request(500);
    // only max set
    await redemption.connect(admin).setThresholds(0, 5);
    await redemption.connect(alice).request(1);
    await expect(redemption.connect(alice).request(0)).to.be.revertedWithCustomError(redemption, "ZeroAmount");
  });

  it("pause blocks request and approve but allows reject and cancel", async () => {
    const { redemption, pauser, operator, alice, outsider } = await loadFixture(ready);
    await redemption.connect(alice).request(10);
    await redemption.connect(alice).request(20);
    await redemption.connect(alice).request(30);
    await expect(redemption.connect(outsider).pause()).to.be.revertedWithCustomError(
      redemption,
      "AccessControlUnauthorizedAccount",
    );
    await redemption.connect(pauser).pause();
    await expect(redemption.connect(alice).request(1)).to.be.revertedWithCustomError(redemption, "EnforcedPause");
    await expect(redemption.connect(operator).approve(1, ethers.id("f"))).to.be.revertedWithCustomError(
      redemption,
      "EnforcedPause",
    );
    await redemption.connect(operator).reject(1, ethers.ZeroHash);
    await redemption.connect(alice).cancel(2);
    await redemption.connect(pauser).unpause();
    await redemption.connect(operator).approve(3, ethers.id("f"));
  });

  it("pending requests can be settled after the module is disabled", async () => {
    const { redemption, admin, operator, alice, outsider } = await loadFixture(ready);
    await redemption.connect(alice).request(10);
    await expect(redemption.connect(outsider).setEnabled(false)).to.be.revertedWithCustomError(
      redemption,
      "AccessControlUnauthorizedAccount",
    );
    await expect(redemption.connect(admin).setEnabled(false)).to.emit(redemption, "EnabledUpdated").withArgs(false);
    await expect(redemption.connect(alice).request(10)).to.be.revertedWithCustomError(redemption, "RedemptionDisabled");
    await redemption.connect(operator).approve(1, ethers.id("f"));
    expect(await redemption.requestCount()).to.equal(1);
  });

  it("compliance applies to escrow (blocked holder cannot request)", async () => {
    const { redemption, registry, kyc, token, alice } = await loadFixture(ready);
    await registry.connect(kyc).setFrozen(alice.address, true);
    await expect(redemption.connect(alice).request(10)).to.be.revertedWithCustomError(token, "TransferNotCompliant");
  });

  it("approve fails if manager lacks BURNER_ROLE", async () => {
    const { redemption, token, admin, operator, alice, rmAddr } = await loadFixture(ready);
    await redemption.connect(alice).request(10);
    await token.connect(admin).revokeRole(await token.BURNER_ROLE(), rmAddr);
    await expect(redemption.connect(operator).approve(1, ethers.id("f"))).to.be.revertedWithCustomError(
      token,
      "AccessControlUnauthorizedAccount",
    );
  });
});
