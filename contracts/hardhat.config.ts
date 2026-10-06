import { HardhatUserConfig } from "hardhat/config";
import "@nomicfoundation/hardhat-toolbox";
import * as dotenv from "dotenv";

dotenv.config({ quiet: true });

/**
 * ReserveChain.io — Hardhat configuration.
 * TESTNET ONLY. No mainnet network is configured on purpose; scripts/deploy.ts additionally refuses
 * to run on known mainnet chain ids unless ALLOW_MAINNET=I_HAVE_WRITTEN_AUTHORIZATION is set.
 */
const DEPLOYER_PRIVATE_KEY = process.env.DEPLOYER_PRIVATE_KEY ?? "";
const accounts = /^0x[0-9a-fA-F]{64}$/.test(DEPLOYER_PRIVATE_KEY) ? [DEPLOYER_PRIVATE_KEY] : [];

const config: HardhatUserConfig = {
  solidity: {
    version: "0.8.28",
    settings: {
      optimizer: { enabled: true, runs: 200 },
      evmVersion: "cancun",
    },
  },
  networks: {
    hardhat: { chainId: 31337 },
    localhost: { url: "http://127.0.0.1:8545", chainId: 31337 },
    sepolia: {
      url: process.env.SEPOLIA_RPC_URL ?? "",
      chainId: 11155111,
      accounts,
    },
    amoy: {
      url: process.env.AMOY_RPC_URL ?? "",
      chainId: 80002,
      accounts,
    },
  },
  etherscan: {
    // Etherscan API v2: a single key covers Sepolia and Polygon Amoy.
    apiKey: process.env.ETHERSCAN_API_KEY ?? "",
  },
  sourcify: { enabled: false },
  gasReporter: {
    enabled: process.env.REPORT_GAS === "true",
    currency: "USD",
    noColors: true,
    outputFile: process.env.GAS_REPORT_FILE || undefined,
    excludeContracts: ["MockERC20"],
  },
  typechain: {
    outDir: "typechain-types",
    target: "ethers-v6",
  },
  mocha: { timeout: 120000 },
};

export default config;
