import { expect } from "chai";
import { ethers } from "hardhat";
import { loadFixture, time } from "@nomicfoundation/hardhat-network-helpers";
import { deployAll, deployBare, deployWithCompliance, PROGRAM_ID, ADMIN_DELAY, TEST_CAP, role, j, KYC } from "./fixtures";

const E = (n: number | string) => ethers.parseEther(String(n));

describe("ReserveToken", () => {
  describe("construction & metadata", () => {
    it("uses constructor-supplied name/symbol/decimals/cap/programId", async () => {
      const { token, admin } = await loadFixture(deployBare);
      expect(await token.mintingEnabled()).to.equal(false);
      expect(await token.name()).to.equal("Test Reserve Token");
      expect(await token.symbol()).to.equal("tRSV");
      expect(await token.decimals()).to.equal(18);
      expect(await token.supplyCap()).to.equal(0);
      expect(await token.programId()).to.equal(PROGRAM_ID);
      expect(await token.totalSupply()).to.equal(0);
      expect(await token.defaultAdmin()).to.equal(admin.address);
      expect(await token.defaultAdminDelay()).to.equal(ADMIN_DELAY);
      expect(await token.complianceEnabled()).to.equal(false);
      expect(await token.reserveGuardEnabled()).to.equal(false);
      const [uri, hash] = await token.document();
      expect(uri).to.equal("");
      expect(hash).to.equal(ethers.ZeroHash);
    });

    it("supports custom decimals and rejects > 18", async () => {
      const [a] = await ethers.getSigners();
      const F = await ethers.getContractFactory("ReserveToken");
      const t6 = await F.deploy("X", "X", 6, 0, ethers.ZeroHash, a.address, 0);
      expect(await t6.decimals()).to.equal(6);
      await expect(F.deploy("X", "X", 19, 0, ethers.ZeroHash, a.address, 0))
        .to.be.revertedWithCustomError(F, "InvalidDecimals")
        .withArgs(19);
    });

    it("admin can set programId and document; others cannot", async () => {
      const { token, admin, outsider } = await loadFixture(deployAll);
      const pid = ethers.id("OTHER");
      await expect(token.connect(admin).setProgramId(pid))
        .to.emit(token, "ProgramIdUpdated")
        .withArgs(PROGRAM_ID, pid);
      const h = ethers.id("doc");
      await expect(token.connect(admin).setDocument("ipfs://doc", h))
        .to.emit(token, "DocumentUpdated")
        .withArgs("ipfs://doc", h);
      expect(await token.document()).to.deep.equal(["ipfs://doc", h]);
      await expect(token.connect(outsider).setProgramId(pid)).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
      await expect(token.connect(outsider).setDocument("x", h)).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
    });
  });

  describe("mint / burn", () => {
    it("minter mints; non-minter cannot", async () => {
      const { token, minter, alice, outsider } = await loadFixture(deployAll);
      await expect(token.connect(minter).mint(alice.address, E(10)))
        .to.emit(token, "Minted")
        .withArgs(minter.address, alice.address, E(10));
      expect(await token.balanceOf(alice.address)).to.equal(E(10));
      await expect(token.connect(outsider).mint(alice.address, 1))
        .to.be.revertedWithCustomError(token, "AccessControlUnauthorizedAccount")
        .withArgs(outsider.address, role("MINTER_ROLE"));
    });

    it("rejects zero address / zero amount mints", async () => {
      const { token, minter, alice } = await loadFixture(deployAll);
      await expect(token.connect(minter).mint(ethers.ZeroAddress, 1)).to.be.revertedWithCustomError(token, "ZeroAddress");
      await expect(token.connect(minter).mint(alice.address, 0)).to.be.revertedWithCustomError(token, "ZeroAmount");
    });

    it("burner burns own balance; non-burner cannot", async () => {
      const { token, minter, burner, alice } = await loadFixture(deployAll);
      await token.connect(minter).mint(burner.address, E(5));
      await expect(token.connect(burner).burn(E(2)))
        .to.emit(token, "Burned")
        .withArgs(burner.address, burner.address, E(2));
      expect(await token.totalSupply()).to.equal(E(3));
      await token.connect(minter).mint(alice.address, E(1));
      await expect(token.connect(alice).burn(1)).to.be.revertedWithCustomError(token, "AccessControlUnauthorizedAccount");
      await expect(token.connect(burner).burn(0)).to.be.revertedWithCustomError(token, "ZeroAmount");
    });

    it("burnFrom requires allowance and BURNER_ROLE", async () => {
      const { token, minter, burner, alice, outsider } = await loadFixture(deployAll);
      await token.connect(minter).mint(alice.address, E(5));
      await expect(token.connect(burner).burnFrom(alice.address, E(1))).to.be.revertedWithCustomError(
        token,
        "ERC20InsufficientAllowance",
      );
      await token.connect(alice).approve(burner.address, E(2));
      await expect(token.connect(burner).burnFrom(alice.address, E(2)))
        .to.emit(token, "Burned")
        .withArgs(burner.address, alice.address, E(2));
      expect(await token.balanceOf(alice.address)).to.equal(E(3));
      await token.connect(alice).approve(outsider.address, E(1));
      await expect(token.connect(outsider).burnFrom(alice.address, E(1))).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
      await expect(token.connect(burner).burnFrom(alice.address, 0)).to.be.revertedWithCustomError(token, "ZeroAmount");
    });
  });

  describe("fail-closed minting", () => {
    it("default state: MINTER cannot mint (no guard, no cap, mintingEnabled=false)", async () => {
      const { token, minter, alice } = await loadFixture(deployBare);
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "MintingDisabled");
    });

    it("static cap set but mintingEnabled=false ⇒ blocked", async () => {
      const { token, admin, minter, alice } = await loadFixture(deployBare);
      await token.connect(admin).setSupplyCap(E(100));
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "MintingDisabled");
    });

    it("mintingEnabled=true but cap 0 ⇒ blocked (no unlimited minting)", async () => {
      const { token, admin, minter, alice } = await loadFixture(deployBare);
      await token.connect(admin).setMintingEnabled(true);
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "MintingDisabled");
    });

    it("static-cap mode: non-zero cap AND mintingEnabled ⇒ mint up to the cap", async () => {
      const { token, admin, minter, alice } = await loadFixture(deployBare);
      await token.connect(admin).setSupplyCap(E(100));
      await expect(token.connect(admin).setMintingEnabled(true)).to.emit(token, "MintingEnabledUpdated").withArgs(true);
      await token.connect(minter).mint(alice.address, E(100));
      await expect(token.connect(minter).mint(alice.address, 1))
        .to.be.revertedWithCustomError(token, "SupplyCapExceeded")
        .withArgs(E(100) + 1n, E(100));
      await expect(token.connect(admin).setMintingEnabled(false)).to.emit(token, "MintingEnabledUpdated").withArgs(false);
      await token.connect(admin).setSupplyCap(E(200));
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "MintingDisabled");
    });

    it("removing the cap (0) re-blocks static-cap mode", async () => {
      const { token, admin, minter, alice } = await loadFixture(deployAll);
      await token.connect(minter).mint(alice.address, 1);
      await token.connect(admin).setSupplyCap(0);
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "MintingDisabled");
    });

    it("only DEFAULT_ADMIN can toggle mintingEnabled", async () => {
      const { token, minter, outsider } = await loadFixture(deployBare);
      for (const s of [minter, outsider]) {
        await expect(token.connect(s).setMintingEnabled(true))
          .to.be.revertedWithCustomError(token, "AccessControlUnauthorizedAccount")
          .withArgs(s.address, ethers.ZeroHash);
      }
    });

    it("enabled guard governs even when static-cap mode is configured", async () => {
      const { token, guard, admin, minter, alice } = await loadFixture(deployAll);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await expect(token.connect(minter).mint(alice.address, 1))
        .to.be.revertedWithCustomError(token, "ReserveGuardExceeded")
        .withArgs(1, 0);
      await token.connect(admin).setReserveGuardEnabled(false);
      await token.connect(minter).mint(alice.address, 1);
    });

    it("reserve mode works without a static cap or mintingEnabled", async () => {
      const { token, guard, admin, minter, attestor, alice } = await loadFixture(deployBare);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await guard.connect(admin).bindToken(await token.getAddress(), PROGRAM_ID);
      await guard.connect(admin).setMaxAttestationAge(86400);
      await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 2n);
      await guard.connect(attestor).postAttestation(PROGRAM_ID, 5, ethers.id("r"), "", await time.latest());
      await token.connect(minter).mint(alice.address, 10);
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "ReserveGuardExceeded");
    });

    it("non-zero static cap is also enforced in reserve mode", async () => {
      const { token, guard, admin, minter, attestor, alice } = await loadFixture(deployBare);
      await token.connect(admin).setSupplyCap(5);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await guard.connect(admin).bindToken(await token.getAddress(), PROGRAM_ID);
      await guard.connect(admin).setMaxAttestationAge(86400);
      await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 100n);
      await guard.connect(attestor).postAttestation(PROGRAM_ID, 5, ethers.id("r"), "", await time.latest());
      await expect(token.connect(minter).mint(alice.address, 6)).to.be.revertedWithCustomError(token, "SupplyCapExceeded");
    });
  });

  describe("supply cap", () => {
    it("cap updates emit events; cannot be set below supply; only admin", async () => {
      const { token, admin, minter, alice, outsider } = await loadFixture(deployAll);
      await token.connect(minter).mint(alice.address, E(10));
      await expect(token.connect(admin).setSupplyCap(E(150)))
        .to.emit(token, "SupplyCapUpdated")
        .withArgs(TEST_CAP, E(150));
      await expect(token.connect(admin).setSupplyCap(E(9)))
        .to.be.revertedWithCustomError(token, "CapBelowSupply")
        .withArgs(E(9), E(10));
      await expect(token.connect(outsider).setSupplyCap(E(20))).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
    });

    it("constructor cap is enforced (with mintingEnabled)", async () => {
      const [a] = await ethers.getSigners();
      const F = await ethers.getContractFactory("ReserveToken");
      const t = await F.deploy("X", "X", 18, 100, ethers.ZeroHash, a.address, 0);
      await t.grantRole(await t.MINTER_ROLE(), a.address);
      await expect(t.mint(a.address, 1)).to.be.revertedWithCustomError(t, "MintingDisabled");
      await t.setMintingEnabled(true);
      await t.mint(a.address, 100);
      await expect(t.mint(a.address, 1)).to.be.revertedWithCustomError(t, "SupplyCapExceeded");
    });
  });

  describe("pause", () => {
    it("pauser pauses transfers, mints and burns", async () => {
      const { token, pauser, minter, burner, alice, bob } = await loadFixture(deployAll);
      await token.connect(minter).mint(alice.address, E(5));
      await token.connect(minter).mint(burner.address, E(5));
      await token.connect(pauser).pause();
      expect(await token.paused()).to.equal(true);
      await expect(token.connect(alice).transfer(bob.address, 1)).to.be.revertedWithCustomError(token, "EnforcedPause");
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(token, "EnforcedPause");
      await expect(token.connect(burner).burn(1)).to.be.revertedWithCustomError(token, "EnforcedPause");
      await token.connect(pauser).unpause();
      await token.connect(alice).transfer(bob.address, 1);
      expect(await token.balanceOf(bob.address)).to.equal(1);
    });

    it("non-pauser cannot pause/unpause", async () => {
      const { token, outsider, pauser } = await loadFixture(deployAll);
      await expect(token.connect(outsider).pause()).to.be.revertedWithCustomError(token, "AccessControlUnauthorizedAccount");
      await token.connect(pauser).pause();
      await expect(token.connect(outsider).unpause()).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
    });
  });

  describe("compliance hook", () => {
    it("cannot enable without registry; only COMPLIANCE_ADMIN", async () => {
      const { token, complianceAdmin, admin, registry } = await loadFixture(deployAll);
      await expect(token.connect(complianceAdmin).setComplianceEnabled(true)).to.be.revertedWithCustomError(
        token,
        "HookNotConfigured",
      );
      await expect(token.connect(admin).setComplianceRegistry(await registry.getAddress())).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
      await expect(token.connect(complianceAdmin).setComplianceRegistry(await registry.getAddress()))
        .to.emit(token, "ComplianceRegistryUpdated")
        .withArgs(ethers.ZeroAddress, await registry.getAddress());
      await expect(token.connect(complianceAdmin).setComplianceEnabled(true))
        .to.emit(token, "ComplianceEnabledUpdated")
        .withArgs(true);
    });

    it("blocks transfer to an EU/EEA-jurisdiction holder", async () => {
      const { token, minter, alice, carol } = await loadFixture(deployWithCompliance);
      await token.connect(minter).mint(alice.address, E(5));
      await expect(token.connect(alice).transfer(carol.address, 1))
        .to.be.revertedWithCustomError(token, "TransferNotCompliant")
        .withArgs(alice.address, carol.address, 1);
      await expect(token.connect(minter).mint(carol.address, 1)).to.be.revertedWithCustomError(
        token,
        "TransferNotCompliant",
      );
    });

    it("allows transfer between approved non-blocked holders", async () => {
      const { token, minter, alice, bob } = await loadFixture(deployWithCompliance);
      await token.connect(minter).mint(alice.address, E(5));
      await token.connect(alice).transfer(bob.address, E(1));
      expect(await token.balanceOf(bob.address)).to.equal(E(1));
    });

    it("blocks frozen senders and receivers", async () => {
      const { token, registry, kyc, minter, alice, bob } = await loadFixture(deployWithCompliance);
      await token.connect(minter).mint(alice.address, E(5));
      await registry.connect(kyc).setFrozen(alice.address, true);
      await expect(token.connect(alice).transfer(bob.address, 1)).to.be.revertedWithCustomError(
        token,
        "TransferNotCompliant",
      );
      await registry.connect(kyc).setFrozen(alice.address, false);
      await registry.connect(kyc).setFrozen(bob.address, true);
      await expect(token.connect(alice).transfer(bob.address, 1)).to.be.revertedWithCustomError(
        token,
        "TransferNotCompliant",
      );
    });

    it("blocks expired approvals", async () => {
      const { token, registry, kyc, minter, alice, bob } = await loadFixture(deployWithCompliance);
      await token.connect(minter).mint(alice.address, E(5));
      const exp = (await time.latest()) + 100;
      await registry.connect(kyc).setRecord(bob.address, KYC.Approved, j("US"), exp);
      await token.connect(alice).transfer(bob.address, 1);
      await time.increaseTo(exp);
      await expect(token.connect(alice).transfer(bob.address, 1)).to.be.revertedWithCustomError(
        token,
        "TransferNotCompliant",
      );
    });

    it("blocks unregistered addresses and disabling the hook lifts restrictions", async () => {
      const { token, minter, alice, outsider, complianceAdmin } = await loadFixture(deployWithCompliance);
      await token.connect(minter).mint(alice.address, E(5));
      await expect(token.connect(alice).transfer(outsider.address, 1)).to.be.revertedWithCustomError(
        token,
        "TransferNotCompliant",
      );
      await token.connect(complianceAdmin).setComplianceEnabled(false);
      await token.connect(alice).transfer(outsider.address, 1);
    });

    it("clearing the registry auto-disables the hook", async () => {
      const { token, complianceAdmin } = await loadFixture(deployWithCompliance);
      await expect(token.connect(complianceAdmin).setComplianceRegistry(ethers.ZeroAddress))
        .to.emit(token, "ComplianceEnabledUpdated")
        .withArgs(false);
      expect(await token.complianceEnabled()).to.equal(false);
      // clearing again when already disabled emits no toggle
      await expect(token.connect(complianceAdmin).setComplianceRegistry(ethers.ZeroAddress)).to.not.emit(
        token,
        "ComplianceEnabledUpdated",
      );
    });
  });

  describe("reserve guard hook", () => {
    it("cannot enable without guard; only admin", async () => {
      const { token, admin, outsider, guard } = await loadFixture(deployAll);
      await expect(token.connect(admin).setReserveGuardEnabled(true)).to.be.revertedWithCustomError(
        token,
        "HookNotConfigured",
      );
      await expect(token.connect(outsider).setReserveGuard(await guard.getAddress())).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
      await expect(token.connect(admin).setReserveGuard(await guard.getAddress()))
        .to.emit(token, "ReserveGuardUpdated")
        .withArgs(ethers.ZeroAddress, await guard.getAddress());
      await expect(token.connect(admin).setReserveGuardEnabled(true))
        .to.emit(token, "ReserveGuardEnabledUpdated")
        .withArgs(true);
      await expect(token.connect(admin).setReserveGuard(ethers.ZeroAddress))
        .to.emit(token, "ReserveGuardEnabledUpdated")
        .withArgs(false);
      await expect(token.connect(admin).setReserveGuard(ethers.ZeroAddress)).to.not.emit(
        token,
        "ReserveGuardEnabledUpdated",
      );
    });

    it("unset ratio blocks all minting when guard enabled", async () => {
      const { token, guard, admin, minter, attestor, alice } = await loadFixture(deployAll);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await guard.connect(admin).bindToken(await token.getAddress(), PROGRAM_ID);
      await guard.connect(admin).setMaxAttestationAge(86400);
      await guard.connect(attestor).postAttestation(PROGRAM_ID, 1000, ethers.id("r1"), "ipfs://r1", await time.latest());
      await expect(token.connect(minter).mint(alice.address, 1))
        .to.be.revertedWithCustomError(token, "ReserveGuardExceeded")
        .withArgs(1, 0);
    });

    it("mints within guard headroom, rejects above it", async () => {
      const { token, guard, admin, minter, attestor, alice } = await loadFixture(deployAll);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await guard.connect(admin).bindToken(await token.getAddress(), PROGRAM_ID);
      await guard.connect(admin).setMaxAttestationAge(86400);
      await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 7n); // arbitrary test ratio
      await guard.connect(attestor).postAttestation(PROGRAM_ID, 100, ethers.id("r1"), "ipfs://r1", await time.latest());
      await token.connect(minter).mint(alice.address, 600);
      expect(await guard.maxMintable(await token.getAddress())).to.equal(100);
      await token.connect(minter).mint(alice.address, 100);
      await expect(token.connect(minter).mint(alice.address, 1))
        .to.be.revertedWithCustomError(token, "ReserveGuardExceeded")
        .withArgs(1, 0);
    });

    it("stale attestation blocks minting", async () => {
      const { token, guard, admin, minter, attestor, alice } = await loadFixture(deployAll);
      await token.connect(admin).setReserveGuard(await guard.getAddress());
      await token.connect(admin).setReserveGuardEnabled(true);
      await guard.connect(admin).bindToken(await token.getAddress(), PROGRAM_ID);
      await guard.connect(admin).setMaxAttestationAge(3600);
      await guard.connect(admin).setTokensPerUnit(PROGRAM_ID, 1n);
      await guard.connect(attestor).postAttestation(PROGRAM_ID, 100, ethers.id("r1"), "u", await time.latest());
      await token.connect(minter).mint(alice.address, 10);
      await time.increase(3601);
      await expect(token.connect(minter).mint(alice.address, 1)).to.be.revertedWithCustomError(
        token,
        "ReserveGuardExceeded",
      );
    });
  });

  describe("recoverERC20", () => {
    it("treasury role recovers foreign tokens; others cannot", async () => {
      const { token, treasuryRole, outsider, bob } = await loadFixture(deployAll);
      const M = await ethers.getContractFactory("MockERC20");
      const m = await M.deploy("M", "M");
      await m.mint(await token.getAddress(), 100);
      await expect(token.connect(treasuryRole).recoverERC20(await m.getAddress(), bob.address, 60))
        .to.emit(token, "ERC20Recovered")
        .withArgs(await m.getAddress(), bob.address, 60);
      expect(await m.balanceOf(bob.address)).to.equal(60);
      await expect(
        token.connect(outsider).recoverERC20(await m.getAddress(), bob.address, 1),
      ).to.be.revertedWithCustomError(token, "AccessControlUnauthorizedAccount");
      await expect(
        token.connect(treasuryRole).recoverERC20(ethers.ZeroAddress, bob.address, 1),
      ).to.be.revertedWithCustomError(token, "ZeroAddress");
      await expect(
        token.connect(treasuryRole).recoverERC20(await m.getAddress(), ethers.ZeroAddress, 1),
      ).to.be.revertedWithCustomError(token, "ZeroAddress");
      await expect(
        token.connect(treasuryRole).recoverERC20(await m.getAddress(), bob.address, 0),
      ).to.be.revertedWithCustomError(token, "ZeroAmount");
    });

    it("recovers own token sent to the contract (recipient screened under compliance)", async () => {
      const { token, minter, treasuryRole, complianceAdmin, bob, carol } = await loadFixture(deployWithCompliance);
      const tokenAddr = await token.getAddress();
      // Simulate a mistaken transfer that happened while the hook was off (the token contract itself is not
      // a registered holder, so sending to it is otherwise blocked).
      await token.connect(complianceAdmin).setComplianceEnabled(false);
      await token.connect(minter).mint(tokenAddr, 30);
      await token.connect(complianceAdmin).setComplianceEnabled(true);
      await expect(
        token.connect(treasuryRole).recoverERC20(tokenAddr, carol.address, 10),
      ).to.be.revertedWithCustomError(token, "TransferNotCompliant");
      await expect(token.connect(treasuryRole).recoverERC20(tokenAddr, bob.address, 10))
        .to.emit(token, "ERC20Recovered")
        .withArgs(tokenAddr, bob.address, 10);
      expect(await token.balanceOf(bob.address)).to.equal(10);
      expect(await token.balanceOf(tokenAddr)).to.equal(20);
    });
  });

  describe("permit (EIP-2612)", () => {
    it("sets allowance via signature and rejects replay / expired", async () => {
      const { token, minter, alice, bob } = await loadFixture(deployAll);
      await token.connect(minter).mint(alice.address, E(10));
      const deadline = (await time.latest()) + 3600;
      const nonce = await token.nonces(alice.address);
      const { chainId } = await ethers.provider.getNetwork();
      const domain = {
        name: await token.name(),
        version: "1",
        chainId,
        verifyingContract: await token.getAddress(),
      };
      const types = {
        Permit: [
          { name: "owner", type: "address" },
          { name: "spender", type: "address" },
          { name: "value", type: "uint256" },
          { name: "nonce", type: "uint256" },
          { name: "deadline", type: "uint256" },
        ],
      };
      const value = E(3);
      const sig = ethers.Signature.from(
        await alice.signTypedData(domain, types, { owner: alice.address, spender: bob.address, value, nonce, deadline }),
      );
      await token.permit(alice.address, bob.address, value, deadline, sig.v, sig.r, sig.s);
      expect(await token.allowance(alice.address, bob.address)).to.equal(value);
      expect(await token.nonces(alice.address)).to.equal(nonce + 1n);
      await expect(
        token.permit(alice.address, bob.address, value, deadline, sig.v, sig.r, sig.s),
      ).to.be.revertedWithCustomError(token, "ERC2612InvalidSigner");
      await token.connect(bob).transferFrom(alice.address, bob.address, value);
      expect(await token.balanceOf(bob.address)).to.equal(value);

      const past = (await time.latest()) - 1;
      const sig2 = ethers.Signature.from(
        await alice.signTypedData(domain, types, {
          owner: alice.address, spender: bob.address, value, nonce: nonce + 1n, deadline: past,
        }),
      );
      await expect(
        token.permit(alice.address, bob.address, value, past, sig2.v, sig2.r, sig2.s),
      ).to.be.revertedWithCustomError(token, "ERC2612ExpiredSignature");
    });
  });

  describe("admin handover (AccessControlDefaultAdminRules)", () => {
    it("two-step transfer with delay; direct grant of DEFAULT_ADMIN is refused", async () => {
      const { token, admin, alice, outsider } = await loadFixture(deployAll);
      const DEFAULT_ADMIN = await token.DEFAULT_ADMIN_ROLE();
      await expect(token.connect(admin).grantRole(DEFAULT_ADMIN, alice.address)).to.be.revertedWithCustomError(
        token,
        "AccessControlEnforcedDefaultAdminRules",
      );
      await expect(token.connect(outsider).beginDefaultAdminTransfer(alice.address)).to.be.revertedWithCustomError(
        token,
        "AccessControlUnauthorizedAccount",
      );
      await token.connect(admin).beginDefaultAdminTransfer(alice.address);
      await expect(token.connect(alice).acceptDefaultAdminTransfer()).to.be.revertedWithCustomError(
        token,
        "AccessControlEnforcedDefaultAdminDelay",
      );
      await time.increase(Number(ADMIN_DELAY) + 1);
      await token.connect(alice).acceptDefaultAdminTransfer();
      expect(await token.defaultAdmin()).to.equal(alice.address);
      expect(await token.hasRole(DEFAULT_ADMIN, admin.address)).to.equal(false);
    });
  });
});
