// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

/**
 * @title IComplianceRegistry
 * @notice Minimal interface consumed by {ReserveToken} to gate transfers on participant eligibility.
 * @dev Implementations MUST be side-effect free (view) and SHOULD be cheap: the hook runs on every transfer.
 */
interface IComplianceRegistry {
    /**
     * @notice Returns whether a token movement is permitted under the registry's eligibility rules.
     * @param from   Sender (address(0) for mints / issuance-side movements).
     * @param to     Recipient (address(0) for burns).
     * @param amount Amount being moved (available for amount-based rules; may be ignored).
     * @return allowed True if the movement is permitted.
     */
    function canTransfer(address from, address to, uint256 amount) external view returns (bool allowed);
}
