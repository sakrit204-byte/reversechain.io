// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";
import {Pausable} from "@openzeppelin/contracts/utils/Pausable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/utils/ReentrancyGuard.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";

import {IReserveToken} from "./interfaces/IReserveToken.sol";

/**
 * @title RedemptionManager
 * @author ReserveChain.io (in development)
 * @notice Request / review / settle workflow for a *proposed* redemption process. DISABLED BY DEFAULT and
 *         TESTNET ONLY. Redemption is not confirmed or offered; enabling this module requires written
 *         authorisation and final legal documentation.
 *
 * @dev Lifecycle:
 *        request(amount)        holder → tokens escrowed here, status Pending
 *        approve(id, ref)       REDEMPTION_OPERATOR → escrow burned via token.burn(), off-chain fulfilment ref recorded
 *        reject(id, reason)     REDEMPTION_OPERATOR → escrow returned to requester
 *        cancel(id)             requester → escrow returned while still Pending
 *      Min / max thresholds are configuration inputs; 0 means "not set" (not enforced). This contract must hold
 *      BURNER_ROLE on the token and must be registered as Approved in the ComplianceRegistry when compliance is on.
 */
contract RedemptionManager is AccessControlDefaultAdminRules, Pausable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /// @notice Role allowed to approve or reject redemption requests.
    bytes32 public constant REDEMPTION_OPERATOR_ROLE = keccak256("REDEMPTION_OPERATOR_ROLE");
    /// @notice Role allowed to pause / unpause new requests and approvals.
    bytes32 public constant PAUSER_ROLE = keccak256("PAUSER_ROLE");
    /// @notice Role allowed to enable/disable the module and set thresholds.
    bytes32 public constant CONFIG_ADMIN_ROLE = keccak256("CONFIG_ADMIN_ROLE");

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    /// @notice Request status.
    enum Status {
        None,
        Pending,
        Approved,
        Rejected,
        Cancelled
    }

    /// @notice A redemption request.
    /// @param requester      Holder who submitted the request.
    /// @param amount         Escrowed amount (token base units).
    /// @param status         Current status.
    /// @param createdAt      Submission timestamp.
    /// @param updatedAt      Last status change timestamp.
    /// @param fulfilmentRef  Hash referencing off-chain fulfilment records (set on approval).
    /// @param reasonHash     Hash referencing off-chain rejection reason (set on rejection).
    struct Request {
        address requester;
        uint256 amount;
        Status status;
        uint64 createdAt;
        uint64 updatedAt;
        bytes32 fulfilmentRef;
        bytes32 reasonHash;
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /// @notice Token being redeemed.
    IReserveToken public immutable token;

    /// @notice Whether new requests are accepted. FALSE BY DEFAULT.
    bool public enabled;
    /// @notice Minimum request amount; 0 = not set.
    uint256 public minAmount;
    /// @notice Maximum request amount; 0 = not set.
    uint256 public maxAmount;
    /// @notice Sum of amounts currently held in escrow for Pending requests.
    uint256 public totalEscrowed;
    /// @notice Number of requests ever created (ids are 1-based).
    uint256 public requestCount;

    mapping(uint256 id => Request) private _requests;

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    /// @notice Emitted when the module is enabled / disabled.
    event EnabledUpdated(bool enabled);
    /// @notice Emitted when thresholds change.
    event ThresholdsUpdated(uint256 minAmount, uint256 maxAmount);
    /// @notice Emitted when a request is created.
    event RedemptionRequested(uint256 indexed id, address indexed requester, uint256 amount);
    /// @notice Emitted when a request is approved and burned.
    event RedemptionApproved(uint256 indexed id, address indexed operator, uint256 amount, bytes32 fulfilmentRef);
    /// @notice Emitted when a request is rejected and escrow returned.
    event RedemptionRejected(uint256 indexed id, address indexed operator, uint256 amount, bytes32 reasonHash);
    /// @notice Emitted when the requester cancels a pending request.
    event RedemptionCancelled(uint256 indexed id, address indexed requester, uint256 amount);

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /// @notice Module is disabled.
    error RedemptionDisabled();
    /// @notice A zero address was supplied.
    error ZeroAddress();
    /// @notice Amount must be non-zero.
    error ZeroAmount();
    /// @notice Amount is below the configured minimum.
    error BelowMinimum(uint256 amount, uint256 minAmount);
    /// @notice Amount is above the configured maximum.
    error AboveMaximum(uint256 amount, uint256 maxAmount);
    /// @notice Min exceeds max (both set).
    error InvalidThresholds(uint256 minAmount, uint256 maxAmount);
    /// @notice Request is not in Pending status.
    error NotPending(uint256 id, Status status);
    /// @notice Caller is not the requester.
    error NotRequester(uint256 id, address caller);
    /// @notice Fulfilment reference must be non-zero.
    error InvalidReference();

    // ---------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------

    /**
     * @param token_             Token being redeemed.
     * @param initialAdmin       Initial DEFAULT_ADMIN_ROLE holder.
     * @param adminTransferDelay Delay (seconds) enforced on DEFAULT_ADMIN_ROLE transfers.
     */
    constructor(address token_, address initialAdmin, uint48 adminTransferDelay)
        AccessControlDefaultAdminRules(adminTransferDelay, initialAdmin)
    {
        if (token_ == address(0)) revert ZeroAddress();
        token = IReserveToken(token_);
    }

    // ---------------------------------------------------------------------
    // Holder actions
    // ---------------------------------------------------------------------

    /**
     * @notice Submits a redemption request, escrowing `amount` tokens (requires prior ERC-20 approval).
     * @dev Reverts while disabled or paused, and when outside configured thresholds. Token-level compliance and
     *      pause rules apply to the escrow transfer.
     * @param amount Amount in token base units.
     * @return id The new request id.
     */
    function request(uint256 amount) external whenNotPaused nonReentrant returns (uint256 id) {
        if (!enabled) revert RedemptionDisabled();
        if (amount == 0) revert ZeroAmount();
        if (minAmount != 0 && amount < minAmount) revert BelowMinimum(amount, minAmount);
        if (maxAmount != 0 && amount > maxAmount) revert AboveMaximum(amount, maxAmount);

        id = ++requestCount;
        _requests[id] = Request({
            requester: _msgSender(),
            amount: amount,
            status: Status.Pending,
            createdAt: uint64(block.timestamp),
            updatedAt: uint64(block.timestamp),
            fulfilmentRef: bytes32(0),
            reasonHash: bytes32(0)
        });
        totalEscrowed += amount;
        IERC20(address(token)).safeTransferFrom(_msgSender(), address(this), amount);
        emit RedemptionRequested(id, _msgSender(), amount);
    }

    /**
     * @notice Cancels a pending request and returns escrow. Callable only by the requester; allowed while paused.
     * @param id Request id.
     */
    function cancel(uint256 id) external nonReentrant {
        Request storage r = _pending(id);
        if (r.requester != _msgSender()) revert NotRequester(id, _msgSender());
        r.status = Status.Cancelled;
        r.updatedAt = uint64(block.timestamp);
        totalEscrowed -= r.amount;
        IERC20(address(token)).safeTransfer(r.requester, r.amount);
        emit RedemptionCancelled(id, r.requester, r.amount);
    }

    // ---------------------------------------------------------------------
    // Operator actions
    // ---------------------------------------------------------------------

    /**
     * @notice Approves a pending request: burns the escrowed tokens and records the off-chain fulfilment reference.
     * @dev Restricted to REDEMPTION_OPERATOR_ROLE; blocked while paused. Works even if the module was disabled
     *      after the request was made, so pending requests can always be settled.
     * @param id            Request id.
     * @param fulfilmentRef Non-zero hash referencing off-chain fulfilment documentation.
     */
    function approve(uint256 id, bytes32 fulfilmentRef)
        external
        onlyRole(REDEMPTION_OPERATOR_ROLE)
        whenNotPaused
        nonReentrant
    {
        if (fulfilmentRef == bytes32(0)) revert InvalidReference();
        Request storage r = _pending(id);
        r.status = Status.Approved;
        r.updatedAt = uint64(block.timestamp);
        r.fulfilmentRef = fulfilmentRef;
        totalEscrowed -= r.amount;
        token.burn(r.amount);
        emit RedemptionApproved(id, _msgSender(), r.amount, fulfilmentRef);
    }

    /**
     * @notice Rejects a pending request and returns escrow to the requester.
     * @dev Restricted to REDEMPTION_OPERATOR_ROLE; allowed while paused (returns funds).
     * @param id         Request id.
     * @param reasonHash Hash referencing the off-chain rejection reason (may be zero).
     */
    function reject(uint256 id, bytes32 reasonHash) external onlyRole(REDEMPTION_OPERATOR_ROLE) nonReentrant {
        Request storage r = _pending(id);
        r.status = Status.Rejected;
        r.updatedAt = uint64(block.timestamp);
        r.reasonHash = reasonHash;
        totalEscrowed -= r.amount;
        IERC20(address(token)).safeTransfer(r.requester, r.amount);
        emit RedemptionRejected(id, _msgSender(), r.amount, reasonHash);
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * @notice Enables or disables new requests. Restricted to CONFIG_ADMIN_ROLE.
     * @param enabled_ New state.
     */
    function setEnabled(bool enabled_) external onlyRole(CONFIG_ADMIN_ROLE) {
        enabled = enabled_;
        emit EnabledUpdated(enabled_);
    }

    /**
     * @notice Sets min / max request thresholds (0 = not set). Restricted to CONFIG_ADMIN_ROLE.
     * @param minAmount_ Minimum amount.
     * @param maxAmount_ Maximum amount.
     */
    function setThresholds(uint256 minAmount_, uint256 maxAmount_) external onlyRole(CONFIG_ADMIN_ROLE) {
        if (minAmount_ != 0 && maxAmount_ != 0 && minAmount_ > maxAmount_) {
            revert InvalidThresholds(minAmount_, maxAmount_);
        }
        minAmount = minAmount_;
        maxAmount = maxAmount_;
        emit ThresholdsUpdated(minAmount_, maxAmount_);
    }

    /// @notice Pauses new requests and approvals. Restricted to PAUSER_ROLE.
    function pause() external onlyRole(PAUSER_ROLE) {
        _pause();
    }

    /// @notice Unpauses. Restricted to PAUSER_ROLE.
    function unpause() external onlyRole(PAUSER_ROLE) {
        _unpause();
    }

    // ---------------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------------

    /**
     * @notice Returns request `id`.
     * @param id Request id.
     * @return req The request (zeroed if unknown).
     */
    function getRequest(uint256 id) external view returns (Request memory req) {
        return _requests[id];
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    function _pending(uint256 id) private view returns (Request storage r) {
        r = _requests[id];
        if (r.status != Status.Pending) revert NotPending(id, r.status);
    }
}
