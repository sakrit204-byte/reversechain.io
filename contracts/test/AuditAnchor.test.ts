import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll } from "./fixtures";

describe("AuditAnchor", () => {
  const head = (s: string) => ethers.sha256(ethers.toUtf8Bytes(s));

  it("starts empty", async () => {
    const { anchor } = await loadFixture(deployAll);
    expect(await anchor.latestSeq()).to.equal(0);
    expect(await anchor.anchorCount()).to.equal(0);
    await expect(anchor.latest()).to.be.revertedWithCustomError(anchor, "AnchorNotFound").withArgs(0);
    await expect(anchor.getAnchor(1)).to.be.revertedWithCustomError(anchor, "AnchorNotFound").withArgs(1);
  });

  it("ANCHOR_ROLE anchors; latest/getAnchor/verify work", async () => {
    const { anchor, operator } = await loadFixture(deployAll);
    await expect(anchor.connect(operator).anchor(head("a"), 10, "https://cms/audit/10"))
      .to.emit(anchor, "Anchored")
      .withArgs(10, head("a"), "https://cms/audit/10", operator.address);
    const l = await anchor.latest();
    expect(l.chainHead).to.equal(head("a"));
    expect(l.seq).to.equal(10);
    expect(l.submitter).to.equal(operator.address);
    expect((await anchor.getAnchor(10)).uri).to.equal("https://cms/audit/10");
    expect(await anchor.verify(10, head("a"))).to.equal(true);
    expect(await anchor.verify(10, head("b"))).to.equal(false);
    expect(await anchor.verify(11, ethers.ZeroHash)).to.equal(false);
    expect(await anchor.anchorCount()).to.equal(1);
  });

  it("enforces strictly monotonic sequence numbers", async () => {
    const { anchor, operator } = await loadFixture(deployAll);
    await anchor.connect(operator).anchor(head("a"), 5, "");
    await expect(anchor.connect(operator).anchor(head("b"), 5, ""))
      .to.be.revertedWithCustomError(anchor, "NonMonotonicSeq")
      .withArgs(5, 5);
    await expect(anchor.connect(operator).anchor(head("b"), 4, ""))
      .to.be.revertedWithCustomError(anchor, "NonMonotonicSeq")
      .withArgs(4, 5);
    await anchor.connect(operator).anchor(head("c"), 6, "");
    expect(await anchor.latestSeq()).to.equal(6);
    expect((await anchor.getAnchor(5)).chainHead).to.equal(head("a"));
    await expect(anchor.connect(operator).anchor(head("x"), 0, "")).to.be.revertedWithCustomError(
      anchor,
      "NonMonotonicSeq",
    );
  });

  it("rejects zero head and unauthorized callers", async () => {
    const { anchor, operator, outsider } = await loadFixture(deployAll);
    await expect(anchor.connect(operator).anchor(ethers.ZeroHash, 1, "")).to.be.revertedWithCustomError(
      anchor,
      "InvalidChainHead",
    );
    await expect(anchor.connect(outsider).anchor(head("a"), 1, "")).to.be.revertedWithCustomError(
      anchor,
      "AccessControlUnauthorizedAccount",
    );
  });
});
