import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture, time } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll, QSTATUS } from "./fixtures";

describe("Treasury", () => {
  async function funded() {
    const f = await deployAll();
    const M = await ethers.getContractFactory("MockERC20");
    const asset = await M.deploy("Mock", "MCK");
    const tAddr = await f.treasury.getAddress();
    await asset.mint(tAddr, 10_000);
    return { ...f, asset, assetAddr: await asset.getAddress(), tAddr };
  }

  it("defaults: no immediate limit, timelock disabled, no grace", async () => {
    const { treasury, assetAddr, operator, bob } = await loadFixture(funded);
    expect(await treasury.dailyLimit(assetAddr)).to.equal(0);
    expect(await treasury.timelockDelay()).to.equal(0);
    expect(await treasury.gracePeriod()).to.equal(0);
    expect(await treasury.remainingToday(assetAddr)).to.equal(0);
    await expect(treasury.connect(operator).withdraw(assetAddr, bob.address, 1))
      .to.be.revertedWithCustomError(treasury, "DailyLimitExceeded")
      .withArgs(assetAddr, 1, 0);
    await expect(treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 1)).to.be.revertedWithCustomError(
      treasury,
      "TimelockDisabled",
    );
  });

  it("enforces per-token daily limit and resets next day", async () => {
    const { treasury, asset, assetAddr, admin, operator, bob } = await loadFixture(funded);
    await expect(treasury.connect(admin).setDailyLimit(assetAddr, 100))
      .to.emit(treasury, "DailyLimitUpdated")
      .withArgs(assetAddr, 0, 100);
    await expect(treasury.connect(operator).withdraw(assetAddr, bob.address, 60))
      .to.emit(treasury, "Withdrawn")
      .withArgs(assetAddr, bob.address, 60, operator.address);
    expect(await treasury.remainingToday(assetAddr)).to.equal(40);
    await expect(treasury.connect(operator).withdraw(assetAddr, bob.address, 41))
      .to.be.revertedWithCustomError(treasury, "DailyLimitExceeded")
      .withArgs(assetAddr, 41, 40);
    await treasury.connect(operator).withdraw(assetAddr, bob.address, 40);
    await time.increase(86400);
    expect(await treasury.remainingToday(assetAddr)).to.equal(100);
    await treasury.connect(operator).withdraw(assetAddr, bob.address, 100);
    expect(await asset.balanceOf(bob.address)).to.equal(200);
    // lowering the limit below today's usage leaves zero remaining
    await treasury.connect(admin).setDailyLimit(assetAddr, 50);
    expect(await treasury.remainingToday(assetAddr)).to.equal(0);
  });

  it("validates inputs and roles", async () => {
    const { treasury, assetAddr, admin, operator, outsider, bob } = await loadFixture(funded);
    await treasury.connect(admin).setDailyLimit(assetAddr, 100);
    await expect(treasury.connect(outsider).withdraw(assetAddr, bob.address, 1)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    await expect(treasury.connect(operator).withdraw(ethers.ZeroAddress, bob.address, 1)).to.be.revertedWithCustomError(
      treasury,
      "ZeroAddress",
    );
    await expect(treasury.connect(operator).withdraw(assetAddr, ethers.ZeroAddress, 1)).to.be.revertedWithCustomError(
      treasury,
      "ZeroAddress",
    );
    await expect(treasury.connect(operator).withdraw(assetAddr, bob.address, 0)).to.be.revertedWithCustomError(
      treasury,
      "ZeroAmount",
    );
    await expect(treasury.connect(outsider).setDailyLimit(assetAddr, 1)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    await expect(treasury.connect(admin).setDailyLimit(ethers.ZeroAddress, 1)).to.be.revertedWithCustomError(
      treasury,
      "ZeroAddress",
    );
    await expect(treasury.connect(outsider).setTimelockDelay(1)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    await expect(treasury.connect(outsider).setGracePeriod(1)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
  });

  it("timelock queue: queue → wait → execute; not before eta; not twice", async () => {
    const { treasury, asset, assetAddr, admin, operator, outsider, bob } = await loadFixture(funded);
    await expect(treasury.connect(admin).setTimelockDelay(3600))
      .to.emit(treasury, "TimelockDelayUpdated")
      .withArgs(0, 3600);
    await expect(treasury.connect(outsider).queueWithdrawal(assetAddr, bob.address, 5000)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    const tx = await treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 5000);
    const eta = BigInt(await time.latest()) + 3600n;
    await expect(tx).to.emit(treasury, "WithdrawalQueued").withArgs(1, assetAddr, bob.address, 5000, eta);
    const q = await treasury.getQueuedWithdrawal(1);
    expect(q.status).to.equal(QSTATUS.Queued);
    await expect(treasury.connect(operator).executeWithdrawal(1))
      .to.be.revertedWithCustomError(treasury, "TimelockNotElapsed")
      .withArgs(1, eta);
    await time.increaseTo(eta);
    await expect(treasury.connect(outsider).executeWithdrawal(1)).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    await expect(treasury.connect(operator).executeWithdrawal(1))
      .to.emit(treasury, "WithdrawalExecuted")
      .withArgs(1, assetAddr, bob.address, 5000);
    expect(await asset.balanceOf(bob.address)).to.equal(5000);
    expect((await treasury.getQueuedWithdrawal(1)).status).to.equal(QSTATUS.Executed);
    await expect(treasury.connect(operator).executeWithdrawal(1)).to.be.revertedWithCustomError(treasury, "NotQueued");
    await expect(treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 0)).to.be.revertedWithCustomError(
      treasury,
      "ZeroAmount",
    );
  });

  it("grace period expires queued withdrawals", async () => {
    const { treasury, assetAddr, admin, operator, bob } = await loadFixture(funded);
    await treasury.connect(admin).setTimelockDelay(100);
    await expect(treasury.connect(admin).setGracePeriod(50)).to.emit(treasury, "GracePeriodUpdated").withArgs(0, 50);
    await treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 10);
    await time.increase(100 + 51);
    await expect(treasury.connect(operator).executeWithdrawal(1))
      .to.be.revertedWithCustomError(treasury, "WithdrawalExpired")
      .withArgs(1);
  });

  it("cancel by treasurer or limit admin only", async () => {
    const { treasury, assetAddr, admin, operator, outsider, bob } = await loadFixture(funded);
    await treasury.connect(admin).setTimelockDelay(100);
    await treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 10);
    await treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 10);
    await expect(treasury.connect(outsider).cancelWithdrawal(1))
      .to.be.revertedWithCustomError(treasury, "NotAuthorizedToCancel")
      .withArgs(outsider.address);
    await expect(treasury.connect(operator).cancelWithdrawal(1))
      .to.emit(treasury, "WithdrawalCancelled")
      .withArgs(1, operator.address);
    await expect(treasury.connect(admin).cancelWithdrawal(2))
      .to.emit(treasury, "WithdrawalCancelled")
      .withArgs(2, admin.address);
    await expect(treasury.connect(admin).cancelWithdrawal(2)).to.be.revertedWithCustomError(treasury, "NotQueued");
    await time.increase(200);
    await expect(treasury.connect(operator).executeWithdrawal(1)).to.be.revertedWithCustomError(treasury, "NotQueued");
  });

  it("pause blocks withdraw/queue/execute", async () => {
    const { treasury, assetAddr, admin, operator, pauser, outsider, bob } = await loadFixture(funded);
    await treasury.connect(admin).setDailyLimit(assetAddr, 100);
    await treasury.connect(admin).setTimelockDelay(10);
    await treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 10);
    await expect(treasury.connect(outsider).pause()).to.be.revertedWithCustomError(
      treasury,
      "AccessControlUnauthorizedAccount",
    );
    await treasury.connect(pauser).pause();
    await time.increase(20);
    await expect(treasury.connect(operator).withdraw(assetAddr, bob.address, 1)).to.be.revertedWithCustomError(
      treasury,
      "EnforcedPause",
    );
    await expect(treasury.connect(operator).queueWithdrawal(assetAddr, bob.address, 1)).to.be.revertedWithCustomError(
      treasury,
      "EnforcedPause",
    );
    await expect(treasury.connect(operator).executeWithdrawal(1)).to.be.revertedWithCustomError(
      treasury,
      "EnforcedPause",
    );
    await treasury.connect(pauser).unpause();
    await treasury.connect(operator).executeWithdrawal(1);
  });

  it("works with ReserveToken under compliance (treasury registered)", async () => {
    const f = await loadFixture(deployAll);
    // compliance not enabled here; just confirm a ReserveToken can be held and withdrawn
    const tAddr = await f.treasury.getAddress();
    const tokenAddr = await f.token.getAddress();
    await f.token.connect(f.minter).mint(tAddr, 100);
    await f.treasury.connect(f.admin).setDailyLimit(tokenAddr, 100);
    await f.treasury.connect(f.operator).withdraw(tokenAddr, f.alice.address, 100);
    expect(await f.token.balanceOf(f.alice.address)).to.equal(100);
  });
});
