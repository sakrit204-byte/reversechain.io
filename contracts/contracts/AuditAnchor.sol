// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {AccessControlDefaultAdminRules} from
    "@openzeppelin/contracts/access/extensions/AccessControlDefaultAdminRules.sol";

/**
 * @title AuditAnchor
 * @author ReserveChain.io (in development)
 * @notice Anchors the head of the CMS tamper-evident audit trail (SHA-256 hash chain) on-chain so that any later
 *         rewrite of the off-chain log becomes detectable. TESTNET ONLY.
 *
 * @dev The CMS exposes `{seq, chain_head}` at `/wp-json/rc/v1/audit/head`; `scripts/anchor-audit.ts` posts it here.
 *      Sequence numbers must be strictly increasing, so anchors can never be back-dated or replaced.
 */
contract AuditAnchor is AccessControlDefaultAdminRules {
    /// @notice Role allowed to anchor chain heads.
    bytes32 public constant ANCHOR_ROLE = keccak256("ANCHOR_ROLE");

    /// @notice An anchored audit-chain head.
    /// @param chainHead   Row hash of the audit chain head (SHA-256).
    /// @param seq         Audit-log sequence number of the head row.
    /// @param uri         Optional URI with context (e.g. CMS export location).
    /// @param timestamp   Block timestamp of anchoring.
    /// @param blockNumber Block number of anchoring.
    /// @param submitter   Address that anchored.
    struct Anchor {
        bytes32 chainHead;
        uint64 seq;
        string uri;
        uint64 timestamp;
        uint64 blockNumber;
        address submitter;
    }

    /// @notice Sequence number of the latest anchor (0 = none yet).
    uint64 public latestSeq;
    /// @notice Number of anchors recorded.
    uint256 public anchorCount;

    mapping(uint64 seq => Anchor) private _anchors;

    /// @notice Emitted when a chain head is anchored.
    event Anchored(uint64 indexed seq, bytes32 indexed chainHead, string uri, address indexed submitter);

    /// @notice Chain head must be non-zero.
    error InvalidChainHead();
    /// @notice Sequence must be strictly greater than the latest anchored sequence.
    error NonMonotonicSeq(uint64 seq, uint64 latestSeq);
    /// @notice No anchor exists for the requested sequence / none recorded yet.
    error AnchorNotFound(uint64 seq);

    /**
     * @param initialAdmin       Initial DEFAULT_ADMIN_ROLE holder.
     * @param adminTransferDelay Delay (seconds) enforced on DEFAULT_ADMIN_ROLE transfers.
     */
    constructor(address initialAdmin, uint48 adminTransferDelay)
        AccessControlDefaultAdminRules(adminTransferDelay, initialAdmin)
    {}

    /**
     * @notice Anchors an audit-chain head. Restricted to ANCHOR_ROLE.
     * @param chainHead Non-zero chain head hash.
     * @param seq       Sequence number; must be > {latestSeq}.
     * @param uri       Optional context URI.
     */
    function anchor(bytes32 chainHead, uint64 seq, string calldata uri) external onlyRole(ANCHOR_ROLE) {
        if (chainHead == bytes32(0)) revert InvalidChainHead();
        if (seq <= latestSeq) revert NonMonotonicSeq(seq, latestSeq);
        _anchors[seq] = Anchor(chainHead, seq, uri, uint64(block.timestamp), uint64(block.number), _msgSender());
        latestSeq = seq;
        ++anchorCount;
        emit Anchored(seq, chainHead, uri, _msgSender());
    }

    /**
     * @notice Returns the latest anchor; reverts if none.
     * @return a The latest anchor.
     */
    function latest() external view returns (Anchor memory a) {
        if (latestSeq == 0) revert AnchorNotFound(0);
        return _anchors[latestSeq];
    }

    /**
     * @notice Returns the anchor at `seq`; reverts if none.
     * @param seq Sequence number.
     * @return a The anchor.
     */
    function getAnchor(uint64 seq) external view returns (Anchor memory a) {
        a = _anchors[seq];
        if (a.chainHead == bytes32(0)) revert AnchorNotFound(seq);
    }

    /**
     * @notice Whether `chainHead` was anchored at `seq`.
     * @param seq       Sequence number.
     * @param chainHead Expected chain head.
     * @return matches True on exact match.
     */
    function verify(uint64 seq, bytes32 chainHead) external view returns (bool matches) {
        return chainHead != bytes32(0) && _anchors[seq].chainHead == chainHead;
    }
}
