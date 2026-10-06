// SPDX-License-Identifier: UNLICENSED
pragma solidity ^0.8.24;

import {ERC20} from "@openzeppelin/contracts/token/ERC20/ERC20.sol";

/**
 * @title MockERC20
 * @notice TEST-ONLY ERC-20 with unrestricted minting. Never deploy outside local test networks.
 */
contract MockERC20 is ERC20 {
    /// @param name_ Token name.
    /// @param symbol_ Token symbol.
    constructor(string memory name_, string memory symbol_) ERC20(name_, symbol_) {}

    /// @notice Mints `amount` to `to` (test only).
    /// @param to Recipient.
    /// @param amount Amount.
    function mint(address to, uint256 amount) external {
        _mint(to, amount);
    }
}
