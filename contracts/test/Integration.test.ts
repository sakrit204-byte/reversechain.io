import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture, time } from "@nomicfoundation/hardhat-network-helpers";
import { deployWithCompliance, PROGRAM_ID, RSTATUS } from "./fixtures";

/**
 * End-to-end scenario on the full stack with compliance + reserve guard enabled.
 * All numeric values are arbitrary TEST values, not ReserveChain parameters.
 */
describe("Integration: attest → mint → transfer → redeem → anchor", () => {
  it("runs the complete testnet flow", async () => {
    const f = await loadFixture(deployWithCompliance);
    const { token, guard, redemption, anchor, treasury, admin, minter, attestor, operator, alice, bob, carol } = f;
    const tokenAddr = await token.getAddress();

    // Guard on: minting blocked until configured
    await token.connect(admin).setReserveGuard(await guard.getAddress());
    await token.connect(admin).setReserveGuardEnabled(true);
    await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "ReserveGuardExceeded");

    await guard.connect(admin).bindToken(tokenAddr, PROGRAM_ID);
    await guard.connect(admin).setMaxAttestationAge(7 * 86400);
    await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, ethers.parseEther("1"));
    await guard.connect(attestor).postAttestation(PROGRAM_ID, 1_000, ethers.id("report-1"), "ipfs://report-1", await time.latest());

    await token.connect(minter).mint(alice.address, ethers.parseEther("600"));
    await token.connect(minter).mint(await treasury.getAddress(), ethers.parseEther("400"));
    await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "ReserveGuardExceeded");

    // Transfers: CH → US ok; → EU blocked
    await token.connect(alice).transfer(bob.address, ethers.parseEther("100"));
    await expect(token.connect(alice).transfer(carol.address, 1)).to.be.revertedWithCustomError(token, "TransferNotCompliant");

    // Redemption: bob redeems 40, which frees guard headroom
    await redemption.connect(admin).setEnabled(true);
    await token.connect(bob).approve(await redemption.getAddress(), ethers.parseEther("40"));
    await redemption.connect(bob).request(ethers.parseEther("40"));
    await redemption.connect(operator).approve(1, ethers.id("fulfilment-ref"));
    expect((await redemption.getRequest(1)).status).to.equal(RSTATUS.Approved);
    expect(await guard.maxMintable(tokenAddr)).to.equal(ethers.parseEther("40"));

    // Anchor the audit chain head
    await anchor.connect(operator).anchor(ethers.sha256("0x01"), 1, "https://cms.local/wp-json/rc/v1/audit/head");
    expect(await anchor.latestSeq()).to.equal(1);
  });
});
