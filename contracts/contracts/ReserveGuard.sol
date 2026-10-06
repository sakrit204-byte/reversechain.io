// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";
import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";
import {Math} from "@openzeppelin/contracts/utils/math/Math.sol";

import {IReserveGuard} from "./interfaces/IReserveGuard.sol";

/**
 * @title ReserveGuard
 * @author ReserveChain.io (in development)
 * @notice Reserve-gated minting: bounds a token's total supply by the most recent reserve attestation multiplied
 *         by a configured tokens-per-unit ratio. TESTNET ONLY. Attestations recorded here are statements posted
 *         by an authorised attestor key; they are NOT a confirmed Proof of Reserves.
 *
 * @dev Fail-closed by design. {maxMintable} returns 0 unless ALL of the following are configured:
 *        - the token is bound to a program id ({bindToken});
 *        - the program's `tokensPerUnit` ratio is set (non-zero) — UNSET BY DEFAULT;
 *        - the staleness window `maxAttestationAge` is set (non-zero) — UNSET BY DEFAULT;
 *        - the program has an attestation whose `asOf` timestamp is within the staleness window.
 *      Ceiling = units × tokensPerUnit, where `units` is the attested reserve quantity in the program's
 *      off-chain reporting unit and `tokensPerUnit` is expressed in token base units (so fractional ratios are
 *      representable through token decimals). No ratio or quantity is hard-coded.
 */
contract ReserveGuard is IReserveGuard, AccessControlDefaultAdminRules {
    // ---------------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------------

    /// @notice Role allowed to post reserve attestations.
    bytes32 public constant ATTESTOR_ROLE = keccak256("ATTESTOR_ROLE");
    /// @notice Role allowed to configure ratios, staleness window and token bindings.
    bytes32 public constant GUARD_ADMIN_ROLE = keccak256("GUARD_ADMIN_ROLE");

    // ---------------------------------------------------------------------
    // Types
    // ---------------------------------------------------------------------

    /// @notice A reserve attestation.
    /// @param units      Attested reserve quantity in the program's reporting unit.
    /// @param reportHash Hash of the underlying report document (e.g. SHA-256).
    /// @param uri        Location of the report.
    /// @param asOf       Timestamp the report refers to (supplied by the attestor).
    /// @param postedAt   Block timestamp when posted on-chain.
    /// @param attestor   Address that posted the attestation.
    struct Attestation {
        uint256 units;
        bytes32 reportHash;
        string uri;
        uint64 asOf;
        uint64 postedAt;
        address attestor;
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    /// @notice Token base units per one reserve unit, per program. 0 = UNSET (minting blocked).
    mapping(bytes32 programId => uint256) public tokensPerUnit;

    /// @notice Program id a token is bound to. bytes32(0) = unbound (minting blocked).
    mapping(address token => bytes32) public tokenProgram;

    /// @notice Maximum age (seconds) of an attestation's `asOf` for it to count. 0 = UNSET (minting blocked).
    uint64 public maxAttestationAge;

    mapping(bytes32 programId => Attestation[]) private _attestations;

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    /// @notice Emitted when an attestation is posted.
    event AttestationPosted(
        bytes32 indexed programId,
        uint256 indexed index,
        uint256 units,
        bytes32 reportHash,
        string uri,
        uint64 asOf,
        address indexed attestor
    );
    /// @notice Emitted when a program's ratio changes.
    event TokensPerUnitUpdated(bytes32 indexed programId, uint256 previousRatio, uint256 newRatio);
    /// @notice Emitted when the staleness window changes.
    event MaxAttestationAgeUpdated(uint64 previousAge, uint64 newAge);
    /// @notice Emitted when a token is bound to a program (bytes32(0) = unbound).
    event TokenBound(address indexed token, bytes32 indexed programId);

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    /// @notice Program id must be non-zero.
    error InvalidProgramId();
    /// @notice Report hash must be non-zero.
    error InvalidReportHash();
    /// @notice Attestation timestamp is in the future.
    error AttestationInFuture(uint64 asOf, uint256 nowTs);
    /// @notice Attestation timestamp must be strictly greater than the previous one.
    error AttestationNotNewer(uint64 asOf, uint64 previousAsOf);
    /// @notice Token address must be non-zero.
    error ZeroAddress();
    /// @notice No attestation exists for the program.
    error NoAttestation(bytes32 programId);

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
    // Attestations
    // ---------------------------------------------------------------------

    /**
     * @notice Posts a reserve attestation for `programId`. Restricted to ATTESTOR_ROLE.
     * @param programId  Program identifier.
     * @param units      Attested reserve quantity (may be 0).
     * @param reportHash Hash of the report document (non-zero).
     * @param uri        Report location.
     * @param asOf       Report as-of timestamp; must be <= now and > previous attestation's `asOf`.
     * @return index     Index of the new attestation in the program's history.
     */
    function postAttestation(bytes32 programId, uint256 units, bytes32 reportHash, string calldata uri, uint64 asOf)
        external
        onlyRole(ATTESTOR_ROLE)
        returns (uint256 index)
    {
        if (programId == bytes32(0)) revert InvalidProgramId();
        if (reportHash == bytes32(0)) revert InvalidReportHash();
        if (asOf > block.timestamp) revert AttestationInFuture(asOf, block.timestamp);
        Attestation[] storage list = _attestations[programId];
        if (list.length != 0) {
            uint64 prev = list[list.length - 1].asOf;
            if (asOf <= prev) revert AttestationNotNewer(asOf, prev);
        }
        index = list.length;
        list.push(Attestation(units, reportHash, uri, asOf, uint64(block.timestamp), _msgSender()));
        emit AttestationPosted(programId, index, units, reportHash, uri, asOf, _msgSender());
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * @notice Sets the tokens-per-unit ratio for a program. 0 unsets it (blocks minting).
     * @dev Restricted to GUARD_ADMIN_ROLE. The value is a configuration input — never hard-coded.
     * @param programId Program identifier.
     * @param ratio     Token base units per reserve unit.
     */
    function setTokensPerUnit(bytes32 programId, uint256 ratio) external onlyRole(GUARD_ADMIN_ROLE) {
        if (programId == bytes32(0)) revert InvalidProgramId();
        emit TokensPerUnitUpdated(programId, tokensPerUnit[programId], ratio);
        tokensPerUnit[programId] = ratio;
    }

    /**
     * @notice Sets the staleness window. 0 unsets it (blocks minting). Restricted to GUARD_ADMIN_ROLE.
     * @param maxAge Maximum attestation age in seconds.
     */
    function setMaxAttestationAge(uint64 maxAge) external onlyRole(GUARD_ADMIN_ROLE) {
        emit MaxAttestationAgeUpdated(maxAttestationAge, maxAge);
        maxAttestationAge = maxAge;
    }

    /**
     * @notice Binds `token` to `programId` (bytes32(0) unbinds). Restricted to GUARD_ADMIN_ROLE.
     * @param token     Token address.
     * @param programId Program identifier.
     */
    function bindToken(address token, bytes32 programId) external onlyRole(GUARD_ADMIN_ROLE) {
        if (token == address(0)) revert ZeroAddress();
        tokenProgram[token] = programId;
        emit TokenBound(token, programId);
    }

    // ---------------------------------------------------------------------
    // Views
    // ---------------------------------------------------------------------

    /**
     * @notice Number of attestations recorded for `programId`.
     * @param programId Program identifier.
     * @return count Attestation count.
     */
    function attestationCount(bytes32 programId) external view returns (uint256 count) {
        return _attestations[programId].length;
    }

    /**
     * @notice Returns attestation `index` for `programId`.
     * @param programId Program identifier.
     * @param index     Attestation index.
     * @return attestation The attestation.
     */
    function getAttestation(bytes32 programId, uint256 index) external view returns (Attestation memory attestation) {
        return _attestations[programId][index];
    }

    /**
     * @notice Returns the latest attestation for `programId`; reverts if none.
     * @param programId Program identifier.
     * @return attestation The latest attestation.
     */
    function latestAttestation(bytes32 programId) external view returns (Attestation memory attestation) {
        Attestation[] storage list = _attestations[programId];
        if (list.length == 0) revert NoAttestation(programId);
        return list[list.length - 1];
    }

    /**
     * @notice Whether `programId` has an attestation within the staleness window.
     * @param programId Program identifier.
     * @return fresh True if a fresh attestation exists and the window is configured.
     */
    function isFresh(bytes32 programId) public view returns (bool fresh) {
        uint64 maxAge = maxAttestationAge;
        Attestation[] storage list = _attestations[programId];
        if (maxAge == 0 || list.length == 0) return false;
        return block.timestamp - list[list.length - 1].asOf <= maxAge;
    }

    /**
     * @notice Total-supply ceiling for `token` implied by the latest fresh attestation (0 when unconfigured).
     * @param token Token address.
     * @return ceiling Ceiling in token base units (saturates at type(uint256).max).
     */
    function reserveCeiling(address token) public view returns (uint256 ceiling) {
        bytes32 programId = tokenProgram[token];
        if (programId == bytes32(0)) return 0;
        uint256 ratio = tokensPerUnit[programId];
        if (ratio == 0 || !isFresh(programId)) return 0;
        Attestation[] storage list = _attestations[programId];
        (bool ok, uint256 product) = Math.tryMul(list[list.length - 1].units, ratio);
        return ok ? product : type(uint256).max;
    }

    /// @inheritdoc IReserveGuard
    function maxMintable(address token) external view returns (uint256 amount) {
        uint256 ceiling = reserveCeiling(token);
        if (ceiling == 0) return 0;
        uint256 supply = IERC20(token).totalSupply();
        return ceiling > supply ? ceiling - supply : 0;
    }
}
