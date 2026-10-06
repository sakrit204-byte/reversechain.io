// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";
import {Pausable} from "@openzeppelin/contracts/utils/Pausable.sol";
import {ReentrancyGuard} from "@openzeppelin/contracts/utils/ReentrancyGuard.sol";
import {SafeERC20} from "@openzeppelin/contracts/token/ERC20/utils/SafeERC20.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";

/**
 * @title Treasury
 * @author ReserveChain.io (in development)
 * @notice Role-controlled ERC-20 holder with per-token daily withdrawal limits and an optional timelock queue for
 *         withdrawals above the immediate limit. TESTNET ONLY.
 *
 * @dev Two withdrawal paths:
 *        1. {withdraw} — immediate, bounded by `dailyLimit[token]` per UTC day (block.timestamp / 1 days).
 *           A limit of 0 (the default) means NO immediate withdrawals for that token.
 *        2. {queueWithdrawal} → wait `timelockDelay` → {executeWithdrawal}. Available only when
 *           `timelockDelay` > 0 (default 0 = queue disabled). Queued amounts do not consume the daily limit.
 *           Optional `gracePeriod` (0 = no expiry) after which a queued item can no longer be executed.
 *      Every parameter is a configuration input, unset by default. Native ETH is not accepted.
 */
contract Treasury is AccessControlDefaultAdminRules, Pausable, ReentrancyGuard {
    using SafeERC20 for IERC20;

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /// @notice Role allowed to withdraw, queue, execute and cancel withdrawals.
    bytes32 public constant TREASURER_ROLE = keccak256("TREASURER_ROLE");
    /// @notice Role allowed to set limits, timelock delay and grace period, and to cancel queued withdrawals.
    bytes32 public constant LIMIT_ADMIN_ROLE = keccak256("LIMIT_ADMIN_ROLE");
    /// @notice Role allowed to pause / unpause withdrawals.
    bytes32 public constant PAUSER_ROLE = keccak256("PAUSER_ROLE");

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    /// @notice Queued withdrawal state.
    enum QueueStatus {
        None,
        Queued,
        Executed,
        Cancelled
    }

    /// @notice A timelocked withdrawal.
    /// @param token  Token to withdraw.
    /// @param to     Recipient.
    /// @param amount Amount in base units.
    /// @param eta    Earliest execution timestamp.
    /// @param status Current status.
    struct QueuedWithdrawal {
        address token;
        address to;
        uint256 amount;
        uint64 eta;
        QueueStatus status;
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /// @notice Immediate-withdrawal limit per UTC day, per token. 0 = no immediate withdrawals.
    mapping(address token => uint256) public dailyLimit;
    /// @notice Amount withdrawn immediately per token per day index.
    mapping(address token => mapping(uint256 day => uint256)) public withdrawnOnDay;
    /// @notice Delay (seconds) for queued withdrawals. 0 = queue disabled.
    uint64 public timelockDelay;
    /// @notice Window (seconds) after `eta` during which a queued withdrawal can execute. 0 = no expiry.
    uint64 public gracePeriod;
    /// @notice Number of queued withdrawals ever created (ids are 1-based).
    uint256 public queueCount;

    mapping(uint256 id => QueuedWithdrawal) private _queue;

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    /// @notice Emitted when a token's daily limit changes.
    event DailyLimitUpdated(address indexed token, uint256 previousLimit, uint256 newLimit);
    /// @notice Emitted when the timelock delay changes.
    event TimelockDelayUpdated(uint64 previousDelay, uint64 newDelay);
    /// @notice Emitted when the grace period changes.
    event GracePeriodUpdated(uint64 previousGrace, uint64 newGrace);
    /// @notice Emitted on an immediate withdrawal.
    event Withdrawn(address indexed token, address indexed to, uint256 amount, address indexed operator);
    /// @notice Emitted when a withdrawal is queued.
    event WithdrawalQueued(uint256 indexed id, address indexed token, address indexed to, uint256 amount, uint64 eta);
    /// @notice Emitted when a queued withdrawal executes.
    event WithdrawalExecuted(uint256 indexed id, address indexed token, address indexed to, uint256 amount);
    /// @notice Emitted when a queued withdrawal is cancelled.
    event WithdrawalCancelled(uint256 indexed id, address indexed operator);

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /// @notice A zero address was supplied.
    error ZeroAddress();
    /// @notice Amount must be non-zero.
    error ZeroAmount();
    /// @notice Immediate withdrawal exceeds today's remaining limit.
    error DailyLimitExceeded(address token, uint256 amount, uint256 remaining);
    /// @notice The timelock queue is disabled (delay == 0).
    error TimelockDisabled();
    /// @notice Queued withdrawal is not in Queued status.
    error NotQueued(uint256 id);
    /// @notice Queued withdrawal is not yet executable.
    error TimelockNotElapsed(uint256 id, uint64 eta);
    /// @notice Queued withdrawal expired (grace period passed).
    error WithdrawalExpired(uint256 id);
    /// @notice Caller lacks TREASURER_ROLE and LIMIT_ADMIN_ROLE.
    error NotAuthorizedToCancel(address caller);

    // ---------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------

    /**
     * @param initialAdmin       Initial DEFAULT_ADMIN_ROLE holder.
     * @param adminTransferDelay Delay (seconds) enforced on DEFAULT_ADMIN_ROLE transfers.
     */
    constructor(address initialAdmin, uint48 adminTransferDelay)
        AccessControlDefaultAdminRules(adminTransferDelay, initialAdmin)
    {}

    // ---------------------------------------------------------------------
    // Withdrawals
    // ---------------------------------------------------------------------

    /**
     * @notice Immediately withdraws `amount` of `token` to `to`, within today's limit.
     * @dev Restricted to TREASURER_ROLE; blocked while paused.
     * @param token  Token address.
     * @param to     Recipient.
     * @param amount Amount in base units.
     */
    function withdraw(address token, address to, uint256 amount)
        external
        onlyRole(TREASURER_ROLE)
        whenNotPaused
        nonReentrant
    {
        _validate(token, to, amount);
        uint256 day = currentDay();
        uint256 remaining = remainingToday(token);
        if (amount > remaining) revert DailyLimitExceeded(token, amount, remaining);
        withdrawnOnDay[token][day] += amount;
        IERC20(token).safeTransfer(to, amount);
        emit Withdrawn(token, to, amount, _msgSender());
    }

    /**
     * @notice Queues a timelocked withdrawal (for amounts above the immediate limit).
     * @dev Restricted to TREASURER_ROLE; requires `timelockDelay` > 0; blocked while paused.
     * @param token  Token address.
     * @param to     Recipient.
     * @param amount Amount in base units.
     * @return id    Queue id.
     */
    function queueWithdrawal(address token, address to, uint256 amount)
        external
        onlyRole(TREASURER_ROLE)
        whenNotPaused
        returns (uint256 id)
    {
        _validate(token, to, amount);
        uint64 delay = timelockDelay;
        if (delay == 0) revert TimelockDisabled();
        id = ++queueCount;
        uint64 eta = uint64(block.timestamp) + delay;
        _queue[id] = QueuedWithdrawal(token, to, amount, eta, QueueStatus.Queued);
        emit WithdrawalQueued(id, token, to, amount, eta);
    }

    /**
     * @notice Executes a queued withdrawal after its delay (and before expiry, if a grace period is set).
     * @dev Restricted to TREASURER_ROLE; blocked while paused.
     * @param id Queue id.
     */
    function executeWithdrawal(uint256 id) external onlyRole(TREASURER_ROLE) whenNotPaused nonReentrant {
        QueuedWithdrawal storage q = _queue[id];
        if (q.status != QueueStatus.Queued) revert NotQueued(id);
        if (block.timestamp < q.eta) revert TimelockNotElapsed(id, q.eta);
        if (gracePeriod != 0 && block.timestamp > uint256(q.eta) + gracePeriod) revert WithdrawalExpired(id);
        q.status = QueueStatus.Executed;
        IERC20(q.token).safeTransfer(q.to, q.amount);
        emit WithdrawalExecuted(id, q.token, q.to, q.amount);
    }

    /**
     * @notice Cancels a queued withdrawal. Callable by TREASURER_ROLE or LIMIT_ADMIN_ROLE (allowed while paused).
     * @param id Queue id.
     */
    function cancelWithdrawal(uint256 id) external {
        if (!hasRole(TREASURER_ROLE, _msgSender()) && !hasRole(LIMIT_ADMIN_ROLE, _msgSender())) {
            revert NotAuthorizedToCancel(_msgSender());
        }
        QueuedWithdrawal storage q = _queue[id];
        if (q.status != QueueStatus.Queued) revert NotQueued(id);
        q.status = QueueStatus.Cancelled;
        emit WithdrawalCancelled(id, _msgSender());
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * @notice Sets the immediate daily limit for `token`. Restricted to LIMIT_ADMIN_ROLE.
     * @param token Token address.
     * @param limit Limit per UTC day in base units (0 = no immediate withdrawals).
     */
    function setDailyLimit(address token, uint256 limit) external onlyRole(LIMIT_ADMIN_ROLE) {
        if (token == address(0)) revert ZeroAddress();
        emit DailyLimitUpdated(token, dailyLimit[token], limit);
        dailyLimit[token] = limit;
    }

    /**
     * @notice Sets the timelock delay (0 disables the queue). Restricted to LIMIT_ADMIN_ROLE.
     * @dev Applies to withdrawals queued after the change; existing ETAs are unaffected.
     * @param delay Delay in seconds.
     */
    function setTimelockDelay(uint64 delay) external onlyRole(LIMIT_ADMIN_ROLE) {
        emit TimelockDelayUpdated(timelockDelay, delay);
        timelockDelay = delay;
    }

    /**
     * @notice Sets the grace period (0 = no expiry). Restricted to LIMIT_ADMIN_ROLE.
     * @param grace Grace period in seconds.
     */
    function setGracePeriod(uint64 grace) external onlyRole(LIMIT_ADMIN_ROLE) {
        emit GracePeriodUpdated(gracePeriod, grace);
        gracePeriod = grace;
    }

    /// @notice Pauses withdrawals. Restricted to PAUSER_ROLE.
    function pause() external onlyRole(PAUSER_ROLE) {
        _pause();
    }

    /// @notice Unpauses withdrawals. Restricted to PAUSER_ROLE.
    function unpause() external onlyRole(PAUSER_ROLE) {
        _unpause();
    }

    // ---------------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------------

    /**
     * @notice Current UTC day index used for daily limits.
     * @return day block.timestamp / 1 days.
     */
    function currentDay() public view returns (uint256 day) {
        return block.timestamp / 1 days;
    }

    /**
     * @notice Remaining immediate-withdrawal allowance for `token` today.
     * @param token Token address.
     * @return remaining Amount in base units.
     */
    function remainingToday(address token) public view returns (uint256 remaining) {
        uint256 limit = dailyLimit[token];
        uint256 used = withdrawnOnDay[token][currentDay()];
        return limit > used ? limit - used : 0;
    }

    /**
     * @notice Returns queued withdrawal `id`.
     * @param id Queue id.
     * @return item The queued withdrawal (zeroed if unknown).
     */
    function getQueuedWithdrawal(uint256 id) external view returns (QueuedWithdrawal memory item) {
        return _queue[id];
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    function _validate(address token, address to, uint256 amount) private pure {
        if (token == address(0) || to == address(0)) revert ZeroAddress();
        if (amount == 0) revert ZeroAmount();
    }
}
