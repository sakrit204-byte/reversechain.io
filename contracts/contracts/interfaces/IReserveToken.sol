// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {IERC20} from "@openzeppelin/contracts/token/ERC20/IERC20.sol";

/**
 * @title IReserveToken
 * @notice Subset of {ReserveToken} used by peripheral contracts (e.g. {RedemptionManager}).
 */
interface IReserveToken is IERC20 {
    /**
     * @notice Burns `amount` tokens from the caller's own balance. Restricted to BURNER_ROLE.
     * @param amount Amount to burn, in token base units.
     */
    function burn(uint256 amount) external;
}
