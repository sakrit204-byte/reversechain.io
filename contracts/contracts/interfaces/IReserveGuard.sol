// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

/**
 * @title IReserveGuard
 * @notice Interface consumed by {ReserveToken} to bound minting by attested reserves.
 */
interface IReserveGuard {
    /**
     * @notice Maximum additional amount (in token base units) that `token` may mint right now.
     * @dev MUST return 0 when the guard is not fully configured (no ratio, no binding, no fresh attestation).
     * @param token The token whose headroom is queried.
     * @return amount Remaining mintable headroom in token base units.
     */
    function maxMintable(address token) external view returns (uint256 amount);
}
