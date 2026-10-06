// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import {ERC20Permit} from "@openzeppelin/contracts/token/ERC20/extensions/ERC20Permit.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";
import {Pausable} from "@openzeppelin/contracts/utils/Pausable.sol";

import {IComplianceRegistry} from "./interfaces/IComplianceRegistry.sol";
import {IReserveGuard} from "./interfaces/IReserveGuard.sol";
import {IReserveToken} from "./interfaces/IReserveToken.sol";

/**
 * @title ReserveToken
 * @author ReserveChain.io (in development)
 * @notice ERC-20 token template for a proposed industrial-metals reserve program (e.g. Copper Powder, Nickel Wire).
 *         TESTNET ONLY. ReserveChain is currently in development. No tokens are being offered or sold.
 *
 * @dev Design notes:
 *  - All economic parameters (name, symbol, decimals, supply cap) are constructor/configuration inputs. Nothing
 *    about price, supply, asset-to-token ratio or allocations is hard-coded. A cap of 0 means "no static cap".
 *  - Minting is FAIL-CLOSED. {mint} succeeds only in one of two explicitly configured modes:
 *      (a) Reserve mode: the {IReserveGuard} hook is enabled and its `maxMintable` headroom covers the amount; or
 *      (b) Static-cap mode: the guard is disabled, a NON-ZERO `supplyCap` is set AND `mintingEnabled` is true
 *          (default false, settable only by DEFAULT_ADMIN_ROLE).
 *    In every other state (the default) {mint} reverts with {MintingDisabled}. A non-zero static cap is also
 *    enforced in reserve mode as a second bound.
 *  - Admin handover uses {AccessControlDefaultAdminRules}: DEFAULT_ADMIN_ROLE is held by exactly one account
 *    (intended: a Safe multisig) and transfers require a two-step begin/accept with a configurable delay.
 *  - The compliance hook ({IComplianceRegistry.canTransfer}) runs on every balance movement while enabled,
 *    including mints and burns. Movements out of the token contract itself (ERC-20 recovery of this token)
 *    are checked as issuance-side movements (only the recipient is screened).
 *  - Burning is restricted to BURNER_ROLE (intended holder: {RedemptionManager} only). There is no forced
 *    transfer / clawback function.
 */
contract ReserveToken is IReserveToken, ERC20, ERC20Permit, AccessControlDefaultAdminRules, Pausable {
    using SafeERC20 for IERC20;

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /// @notice Role allowed to mint new tokens (subject to cap, pause, compliance and reserve guard).
    bytes32 public constant MINTER_ROLE = keccak256("MINTER_ROLE");
    /// @notice Role allowed to burn tokens (intended: RedemptionManager only).
    bytes32 public constant BURNER_ROLE = keccak256("BURNER_ROLE");
    /// @notice Role allowed to pause / unpause all token movements.
    bytes32 public constant PAUSER_ROLE = keccak256("PAUSER_ROLE");
    /// @notice Role allowed to configure the compliance registry hook.
    bytes32 public constant COMPLIANCE_ADMIN_ROLE = keccak256("COMPLIANCE_ADMIN_ROLE");
    /// @notice Role allowed to recover ERC-20 tokens mistakenly sent to this contract.
    bytes32 public constant TREASURY_ROLE = keccak256("TREASURY_ROLE");

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    /// @notice Reference to an off-chain offering / whitepaper document.
    /// @param uri  Location of the document (e.g. ipfs:// or https://).
    /// @param hash Content hash (e.g. SHA-256) of the document for integrity verification.
    struct DocumentRef {
        string uri;
        bytes32 hash;
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /// @dev Token decimals, set once at construction.
    uint8 private immutable _decimals;

    /// @notice Maximum total supply in base units. 0 = no static cap configured.
    uint256 public supplyCap;

    /// @notice Program identifier metadata (e.g. keccak256("CU-POWDER") or a CMS program id).
    bytes32 public programId;

    /// @notice Current offering / whitepaper document reference (unset by default).
    DocumentRef private _document;

    /// @notice Compliance registry consulted by the transfer hook.
    IComplianceRegistry public complianceRegistry;
    /// @notice Whether the compliance transfer hook is active.
    bool public complianceEnabled;

    /// @notice Reserve guard consulted on mint.
    IReserveGuard public reserveGuard;
    /// @notice Whether the reserve guard mint check is active.
    bool public reserveGuardEnabled;

    /// @notice Static-cap-mode minting switch (see contract docs). FALSE BY DEFAULT. Ignored in reserve mode.
    bool public mintingEnabled;

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    /// @notice Emitted when the supply cap changes.
    event SupplyCapUpdated(uint256 previousCap, uint256 newCap);
    /// @notice Emitted when the program id metadata changes.
    event ProgramIdUpdated(bytes32 previousProgramId, bytes32 newProgramId);
    /// @notice Emitted when the document reference changes.
    event DocumentUpdated(string uri, bytes32 hash);
    /// @notice Emitted when the compliance registry address changes.
    event ComplianceRegistryUpdated(address indexed previousRegistry, address indexed newRegistry);
    /// @notice Emitted when the compliance hook is toggled.
    event ComplianceEnabledUpdated(bool enabled);
    /// @notice Emitted when the reserve guard address changes.
    event ReserveGuardUpdated(address indexed previousGuard, address indexed newGuard);
    /// @notice Emitted when the reserve guard check is toggled.
    event ReserveGuardEnabledUpdated(bool enabled);
    /// @notice Emitted when the static-cap-mode minting switch is toggled.
    event MintingEnabledUpdated(bool enabled);
    /// @notice Emitted when tokens are minted through {mint}.
    event Minted(address indexed operator, address indexed to, uint256 amount);
    /// @notice Emitted when tokens are burned through {burn} / {burnFrom}.
    event Burned(address indexed operator, address indexed from, uint256 amount);
    /// @notice Emitted when ERC-20 tokens are recovered from this contract.
    event ERC20Recovered(address indexed token, address indexed to, uint256 amount);

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /// @notice Decimals above 18 are not supported.
    error InvalidDecimals(uint8 decimals);
    /// @notice A required address argument was zero.
    error ZeroAddress();
    /// @notice Amount must be non-zero.
    error ZeroAmount();
    /// @notice Minting would exceed the configured supply cap.
    error SupplyCapExceeded(uint256 newSupply, uint256 cap);
    /// @notice New cap is below current total supply.
    error CapBelowSupply(uint256 cap, uint256 totalSupply);
    /// @notice Mint amount exceeds headroom reported by the reserve guard.
    error ReserveGuardExceeded(uint256 amount, uint256 maxMintable);
    /// @notice The compliance registry rejected the movement.
    error TransferNotCompliant(address from, address to, uint256 amount);
    /// @notice Hook cannot be enabled before its target is configured.
    error HookNotConfigured();
    /// @notice Minting is not authorised: guard disabled and static-cap mode not fully configured
    ///         (requires a non-zero supply cap AND mintingEnabled == true).
    error MintingDisabled();

    // ---------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------

    /**
     * @param name_              ERC-20 name (configuration input).
     * @param symbol_            ERC-20 symbol (configuration input).
     * @param decimals_          ERC-20 decimals (configuration input, max 18).
     * @param supplyCap_         Static supply cap in base units; 0 = no static cap.
     * @param programId_         Program identifier metadata; may be zero (unset).
     * @param initialAdmin       Initial DEFAULT_ADMIN_ROLE holder (deployer; later handed to a Safe multisig).
     * @param adminTransferDelay Delay (seconds) enforced on DEFAULT_ADMIN_ROLE transfers.
     */
    constructor(
        string memory name_,
        string memory symbol_,
        uint8 decimals_,
        uint256 supplyCap_,
        bytes32 programId_,
        address initialAdmin,
        uint48 adminTransferDelay
    ) ERC20(name_, symbol_) ERC20Permit(name_) AccessControlDefaultAdminRules(adminTransferDelay, initialAdmin) {
        if (decimals_ > 18) revert InvalidDecimals(decimals_);
        _decimals = decimals_;
        supplyCap = supplyCap_;
        programId = programId_;
        emit SupplyCapUpdated(0, supplyCap_);
        emit ProgramIdUpdated(bytes32(0), programId_);
    }

    // ---------------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------------

    /// @inheritdoc ERC20
    function decimals() public view override returns (uint8) {
        return _decimals;
    }

    /**
     * @notice Returns the current offering / whitepaper document reference.
     * @return uri  Document URI (empty when unset).
     * @return hash Document content hash (zero when unset).
     */
    function document() external view returns (string memory uri, bytes32 hash) {
        return (_document.uri, _document.hash);
    }

    // ---------------------------------------------------------------------
    // Mint / burn
    // ---------------------------------------------------------------------

    /**
     * @notice Mints `amount` tokens to `to`.
     * @dev Restricted to MINTER_ROLE. Fail-closed: requires reserve mode (guard enabled and `amount` within its
     *      headroom) or static-cap mode (guard disabled, `supplyCap` != 0 and `mintingEnabled`); otherwise reverts
     *      with {MintingDisabled}. Also reverts while paused, above a non-zero supply cap, or when the compliance
     *      hook rejects the recipient.
     * @param to     Recipient.
     * @param amount Amount in base units.
     */
    function mint(address to, uint256 amount) external onlyRole(MINTER_ROLE) {
        if (to == address(0)) revert ZeroAddress();
        if (amount == 0) revert ZeroAmount();
        if (reserveGuardEnabled) {
            uint256 headroom = reserveGuard.maxMintable(address(this));
            if (amount > headroom) revert ReserveGuardExceeded(amount, headroom);
        } else if (!mintingEnabled || supplyCap == 0) {
            revert MintingDisabled();
        }
        _mint(to, amount);
        emit Minted(_msgSender(), to, amount);
    }

    /**
     * @notice Burns `amount` tokens from the caller's balance (redemption burn path).
     * @dev Restricted to BURNER_ROLE — intended to be held only by {RedemptionManager}, which burns escrowed tokens
     *      after an operator approves a redemption request.
     * @param amount Amount in base units.
     */
    function burn(uint256 amount) external onlyRole(BURNER_ROLE) {
        if (amount == 0) revert ZeroAmount();
        _burn(_msgSender(), amount);
        emit Burned(_msgSender(), _msgSender(), amount);
    }

    /**
     * @notice Burns `amount` tokens from `account`, consuming the caller's allowance.
     * @dev Restricted to BURNER_ROLE; requires an explicit ERC-20 approval from `account` (no forced burns).
     * @param account Token holder.
     * @param amount  Amount in base units.
     */
    function burnFrom(address account, uint256 amount) external onlyRole(BURNER_ROLE) {
        if (amount == 0) revert ZeroAmount();
        _spendAllowance(account, _msgSender(), amount);
        _burn(account, amount);
        emit Burned(_msgSender(), account, amount);
    }

    // ---------------------------------------------------------------------
    // Pause
    // ---------------------------------------------------------------------

    /// @notice Pauses all token movements (transfers, mints, burns). Restricted to PAUSER_ROLE.
    function pause() external onlyRole(PAUSER_ROLE) {
        _pause();
    }

    /// @notice Resumes token movements. Restricted to PAUSER_ROLE.
    function unpause() external onlyRole(PAUSER_ROLE) {
        _unpause();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * @notice Updates the static supply cap. 0 removes the static cap.
     * @dev Restricted to DEFAULT_ADMIN_ROLE. A non-zero cap must be >= current total supply.
     * @param newCap New cap in base units.
     */
    function setSupplyCap(uint256 newCap) external onlyRole(DEFAULT_ADMIN_ROLE) {
        if (newCap != 0 && newCap < totalSupply()) revert CapBelowSupply(newCap, totalSupply());
        emit SupplyCapUpdated(supplyCap, newCap);
        supplyCap = newCap;
    }

    /**
     * @notice Toggles static-cap-mode minting. Restricted to DEFAULT_ADMIN_ROLE.
     * @dev Only effective while the reserve guard is disabled, and only together with a non-zero `supplyCap`.
     * @param enabled New state.
     */
    function setMintingEnabled(bool enabled) external onlyRole(DEFAULT_ADMIN_ROLE) {
        mintingEnabled = enabled;
        emit MintingEnabledUpdated(enabled);
    }

    /**
     * @notice Updates the program id metadata. Restricted to DEFAULT_ADMIN_ROLE.
     * @param newProgramId New program identifier.
     */
    function setProgramId(bytes32 newProgramId) external onlyRole(DEFAULT_ADMIN_ROLE) {
        emit ProgramIdUpdated(programId, newProgramId);
        programId = newProgramId;
    }

    /**
     * @notice Sets the offering / whitepaper document reference. Restricted to DEFAULT_ADMIN_ROLE.
     * @param uri  Document URI.
     * @param hash Document content hash.
     */
    function setDocument(string calldata uri, bytes32 hash) external onlyRole(DEFAULT_ADMIN_ROLE) {
        _document = DocumentRef(uri, hash);
        emit DocumentUpdated(uri, hash);
    }

    /**
     * @notice Sets the compliance registry. Setting address(0) also disables the hook.
     * @dev Restricted to COMPLIANCE_ADMIN_ROLE.
     * @param registry New registry address.
     */
    function setComplianceRegistry(address registry) external onlyRole(COMPLIANCE_ADMIN_ROLE) {
        emit ComplianceRegistryUpdated(address(complianceRegistry), registry);
        complianceRegistry = IComplianceRegistry(registry);
        if (registry == address(0) && complianceEnabled) {
            complianceEnabled = false;
            emit ComplianceEnabledUpdated(false);
        }
    }

    /**
     * @notice Enables or disables the compliance transfer hook. Restricted to COMPLIANCE_ADMIN_ROLE.
     * @param enabled New state. Enabling requires a registry to be configured.
     */
    function setComplianceEnabled(bool enabled) external onlyRole(COMPLIANCE_ADMIN_ROLE) {
        if (enabled && address(complianceRegistry) == address(0)) revert HookNotConfigured();
        complianceEnabled = enabled;
        emit ComplianceEnabledUpdated(enabled);
    }

    /**
     * @notice Sets the reserve guard. Setting address(0) also disables the check.
     * @dev Restricted to DEFAULT_ADMIN_ROLE.
     * @param guard New guard address.
     */
    function setReserveGuard(address guard) external onlyRole(DEFAULT_ADMIN_ROLE) {
        emit ReserveGuardUpdated(address(reserveGuard), guard);
        reserveGuard = IReserveGuard(guard);
        if (guard == address(0) && reserveGuardEnabled) {
            reserveGuardEnabled = false;
            emit ReserveGuardEnabledUpdated(false);
        }
    }

    /**
     * @notice Enables or disables the reserve guard mint check. Restricted to DEFAULT_ADMIN_ROLE.
     * @param enabled New state. Enabling requires a guard to be configured.
     */
    function setReserveGuardEnabled(bool enabled) external onlyRole(DEFAULT_ADMIN_ROLE) {
        if (enabled && address(reserveGuard) == address(0)) revert HookNotConfigured();
        reserveGuardEnabled = enabled;
        emit ReserveGuardEnabledUpdated(enabled);
    }

    // ---------------------------------------------------------------------
    // Recovery
    // ---------------------------------------------------------------------

    /**
     * @notice Recovers ERC-20 tokens mistakenly sent to this contract. Restricted to TREASURY_ROLE.
     * @dev Recovery of this token itself is supported and is subject to pause and compliance (recipient screened).
     * @param token  Token to recover.
     * @param to     Recipient.
     * @param amount Amount to recover.
     */
    function recoverERC20(address token, address to, uint256 amount) external onlyRole(TREASURY_ROLE) {
        if (token == address(0) || to == address(0)) revert ZeroAddress();
        if (amount == 0) revert ZeroAmount();
        if (token == address(this)) {
            _transfer(address(this), to, amount);
        } else {
            IERC20(token).safeTransfer(to, amount);
        }
        emit ERC20Recovered(token, to, amount);
    }

    // ---------------------------------------------------------------------
    // Internal hooks
    // ---------------------------------------------------------------------

    /**
     * @dev Central balance-movement hook: enforces pause, static cap (on mint) and compliance (when enabled).
     */
    function _update(address from, address to, uint256 value) internal override whenNotPaused {
        if (complianceEnabled) {
            address screenedFrom = from == address(this) ? address(0) : from;
            if (!complianceRegistry.canTransfer(screenedFrom, to, value)) {
                revert TransferNotCompliant(from, to, value);
            }
        }
        super._update(from, to, value);
        if (from == address(0)) {
            uint256 cap = supplyCap;
            if (cap != 0 && totalSupply() > cap) revert SupplyCapExceeded(totalSupply(), cap);
        }
    }
}
