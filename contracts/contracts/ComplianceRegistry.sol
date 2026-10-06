// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";
import {EnumerableSet} from "@openzeppelin/contracts/utils/structs/EnumerableSet.sol";

import {IComplianceRegistry} from "./interfaces/IComplianceRegistry.sol";

/**
 * @title ComplianceRegistry
 * @author ReserveChain.io (in development)
 * @notice On-chain mirror of off-chain eligibility decisions (KYC/KYB, AML, sanctions, jurisdiction) for the
 *         proposed ReserveChain programs. TESTNET ONLY.
 *
 * @dev The registry stores only the *outcome* of off-chain checks — never personal data. Each address has a record
 *      `{status, jurisdiction, expiry, frozen}`. An address is eligible when:
 *        status == Approved  AND  !frozen  AND  (expiry == 0 || block.timestamp < expiry)
 *        AND jurisdiction is not in the blocked set.
 *      The blocked-jurisdiction set is populated by configuration at deployment (e.g. EU/EEA ISO-3166 alpha-2
 *      codes) — no country code is hard-coded in this contract.
 *      System contracts that hold tokens (RedemptionManager escrow, Treasury) must be registered as Approved.
 */
contract ComplianceRegistry is IComplianceRegistry, AccessControlDefaultAdminRules {
    using EnumerableSet for EnumerableSet.Bytes32Set;

    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /// @notice Role allowed to write participant records and freeze/unfreeze addresses.
    bytes32 public constant KYC_OPERATOR_ROLE = keccak256("KYC_OPERATOR_ROLE");
    /// @notice Role allowed to maintain the blocked-jurisdiction set.
    bytes32 public constant JURISDICTION_ADMIN_ROLE = keccak256("JURISDICTION_ADMIN_ROLE");

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    /// @notice Lifecycle status of an address's eligibility review.
    enum Status {
        None,
        Pending,
        Approved,
        Rejected,
        Revoked
    }

    /// @notice Result of an eligibility evaluation (0 = eligible).
    enum Eligibility {
        Eligible,
        NotApproved,
        Expired,
        Frozen,
        JurisdictionBlocked
    }

    /// @notice Per-address compliance record.
    /// @param status       Review status.
    /// @param jurisdiction ISO-3166 alpha-2 code as bytes2 (e.g. "CH"); bytes2(0) = unspecified.
    /// @param expiry       Unix timestamp after which approval lapses; 0 = no expiry.
    /// @param frozen       Emergency freeze flag, independent of status.
    struct Record {
        Status status;
        bytes2 jurisdiction;
        uint64 expiry;
        bool frozen;
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    mapping(address account => Record) private _records;
    EnumerableSet.Bytes32Set private _blockedJurisdictions;

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    /// @notice Emitted whenever a record's status / jurisdiction / expiry is written.
    event RecordUpdated(address indexed account, Status status, bytes2 jurisdiction, uint64 expiry);
    /// @notice Emitted when an address is frozen or unfrozen.
    event FrozenUpdated(address indexed account, bool frozen);
    /// @notice Emitted when a jurisdiction is added to / removed from the blocked set.
    event JurisdictionBlockedUpdated(bytes2 indexed jurisdiction, bool blocked);

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /// @notice A zero address was supplied where an account is required.
    error ZeroAddress();
    /// @notice Batch input arrays have different lengths.
    error LengthMismatch();
    /// @notice A zero jurisdiction code cannot be blocked.
    error InvalidJurisdiction();

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
    // Record management
    // ---------------------------------------------------------------------

    /**
     * @notice Writes the review record for `account` (frozen flag is preserved).
     * @dev Restricted to KYC_OPERATOR_ROLE.
     * @param account      Subject address.
     * @param status       Review status.
     * @param jurisdiction ISO-3166 alpha-2 code as bytes2.
     * @param expiry       Approval expiry timestamp (0 = none).
     */
    function setRecord(address account, Status status, bytes2 jurisdiction, uint64 expiry)
        external
        onlyRole(KYC_OPERATOR_ROLE)
    {
        _setRecord(account, status, jurisdiction, expiry);
    }

    /**
     * @notice Batch variant of {setRecord}. All arrays must have equal length.
     * @param accounts      Subject addresses.
     * @param statuses      Review statuses.
     * @param jurisdictions Jurisdiction codes.
     * @param expiries      Expiry timestamps.
     */
    function setRecordsBatch(
        address[] calldata accounts,
        Status[] calldata statuses,
        bytes2[] calldata jurisdictions,
        uint64[] calldata expiries
    ) external onlyRole(KYC_OPERATOR_ROLE) {
        uint256 n = accounts.length;
        if (statuses.length != n || jurisdictions.length != n || expiries.length != n) revert LengthMismatch();
        for (uint256 i = 0; i < n; ++i) {
            _setRecord(accounts[i], statuses[i], jurisdictions[i], expiries[i]);
        }
    }

    /**
     * @notice Freezes or unfreezes `account`. Restricted to KYC_OPERATOR_ROLE.
     * @param account Subject address.
     * @param frozen  New frozen flag.
     */
    function setFrozen(address account, bool frozen) external onlyRole(KYC_OPERATOR_ROLE) {
        _setFrozen(account, frozen);
    }

    /**
     * @notice Batch variant of {setFrozen}.
     * @param accounts Subject addresses.
     * @param frozen   Frozen flag applied to all.
     */
    function setFrozenBatch(address[] calldata accounts, bool frozen) external onlyRole(KYC_OPERATOR_ROLE) {
        for (uint256 i = 0; i < accounts.length; ++i) {
            _setFrozen(accounts[i], frozen);
        }
    }

    /**
     * @notice Adds or removes jurisdictions from the blocked set. Restricted to JURISDICTION_ADMIN_ROLE.
     * @dev Codes are supplied by configuration (e.g. the EU/EEA list in config/<network>.json).
     * @param codes   ISO-3166 alpha-2 codes as bytes2.
     * @param blocked True to block, false to unblock.
     */
    function setJurisdictionsBlocked(bytes2[] calldata codes, bool blocked)
        external
        onlyRole(JURISDICTION_ADMIN_ROLE)
    {
        for (uint256 i = 0; i < codes.length; ++i) {
            bytes2 code = codes[i];
            if (code == bytes2(0)) revert InvalidJurisdiction();
            bool changed = blocked
                ? _blockedJurisdictions.add(bytes32(code))
                : _blockedJurisdictions.remove(bytes32(code));
            if (changed) emit JurisdictionBlockedUpdated(code, blocked);
        }
    }

    // ---------------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------------

    /**
     * @notice Returns the stored record for `account`.
     * @param account Subject address.
     * @return record The record (all-zero when never written).
     */
    function getRecord(address account) external view returns (Record memory record) {
        return _records[account];
    }

    /**
     * @notice Whether `code` is in the blocked-jurisdiction set.
     * @param code ISO-3166 alpha-2 code as bytes2.
     * @return blocked True if blocked.
     */
    function isJurisdictionBlocked(bytes2 code) public view returns (bool blocked) {
        return _blockedJurisdictions.contains(bytes32(code));
    }

    /**
     * @notice Returns all blocked jurisdiction codes.
     * @return codes Array of bytes2 codes (unordered).
     */
    function blockedJurisdictions() external view returns (bytes2[] memory codes) {
        uint256 n = _blockedJurisdictions.length();
        codes = new bytes2[](n);
        for (uint256 i = 0; i < n; ++i) {
            codes[i] = bytes2(_blockedJurisdictions.at(i));
        }
    }

    /**
     * @notice Evaluates eligibility for `account`.
     * @param account Subject address.
     * @return result {Eligibility.Eligible} or the first failing reason.
     */
    function eligibilityOf(address account) public view returns (Eligibility result) {
        Record storage r = _records[account];
        if (r.frozen) return Eligibility.Frozen;
        if (r.status != Status.Approved) return Eligibility.NotApproved;
        if (r.expiry != 0 && block.timestamp >= r.expiry) return Eligibility.Expired;
        if (r.jurisdiction != bytes2(0) && isJurisdictionBlocked(r.jurisdiction)) {
            return Eligibility.JurisdictionBlocked;
        }
        return Eligibility.Eligible;
    }

    /**
     * @notice Convenience boolean wrapper of {eligibilityOf}.
     * @param account Subject address.
     * @return eligible True when eligible.
     */
    function isEligible(address account) public view returns (bool eligible) {
        return eligibilityOf(account) == Eligibility.Eligible;
    }

    /**
     * @inheritdoc IComplianceRegistry
     * @dev Non-zero `from` and `to` must both be eligible. address(0) sides (mint / burn) are not screened.
     */
    function canTransfer(address from, address to, uint256 /* amount */ ) external view returns (bool allowed) {
        if (from != address(0) && !isEligible(from)) return false;
        if (to != address(0) && !isEligible(to)) return false;
        return true;
    }

    // ---------------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------------

    function _setRecord(address account, Status status, bytes2 jurisdiction, uint64 expiry) private {
        if (account == address(0)) revert ZeroAddress();
        Record storage r = _records[account];
        r.status = status;
        r.jurisdiction = jurisdiction;
        r.expiry = expiry;
        emit RecordUpdated(account, status, jurisdiction, expiry);
    }

    function _setFrozen(address account, bool frozen) private {
        if (account == address(0)) revert ZeroAddress();
        _records[account].frozen = frozen;
        emit FrozenUpdated(account, frozen);
    }
}
