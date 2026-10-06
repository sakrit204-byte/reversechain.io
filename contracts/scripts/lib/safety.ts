/**
 * Network safety guard shared by every script that sends transactions.
 *
 * ReserveChain policy: mainnet deployment is NEVER performed. Only known testnets / local chains are allowed.
 * Any other chain id (including every known mainnet) is refused unless the operator sets
 *   ALLOW_MAINNET=I_HAVE_WRITTEN_AUTHORIZATION
 * and even then a prominent warning is printed.
 */
export const ALLOWED_TEST_CHAINS: Record<number, string> = {
  31337: "hardhat / localhost",
  11155111: "ethereum-sepolia",
  80002: "polygon-amoy",
};

export const KNOWN_MAINNETS: Record<number, string> = {
  1: "Ethereum mainnet",
  137: "Polygon PoS mainnet",
  10: "OP mainnet",
  56: "BNB Smart Chain",
  100: "Gnosis",
  250: "Fantom",
  324: "zkSync Era",
  1101: "Polygon zkEVM",
  5000: "Mantle",
  8453: "Base",
  42161: "Arbitrum One",
  42220: "Celo",
  43114: "Avalanche C-Chain",
  59144: "Linea",
  81457: "Blast",
  534352: "Scroll",
};

export const MAINNET_OVERRIDE_VALUE = "I_HAVE_WRITTEN_AUTHORIZATION";

/**
 * Throws unless `chainId` is an allowed testnet, or the explicit written-authorisation override is present.
 * @param chainId Chain id reported by the connected provider.
 */
export function assertSafeNetwork(chainId: bigint | number): void {
  const id = Number(chainId);
  if (ALLOWED_TEST_CHAINS[id]) return;

  const label = KNOWN_MAINNETS[id] ?? `unrecognised chain ${id} (treated as mainnet)`;
  const banner = "!".repeat(78);
  if (process.env.ALLOW_MAINNET !== MAINNET_OVERRIDE_VALUE) {
    throw new Error(
      `Refusing to run on chainId ${id} (${label}). ReserveChain contracts are TESTNET ONLY. ` +
        `Set ALLOW_MAINNET=${MAINNET_OVERRIDE_VALUE} only with written legal/board authorisation.`,
    );
  }
  console.warn(`\n${banner}\n!! WARNING: running on chainId ${id} (${label}) under ALLOW_MAINNET override.`);
  console.warn("!! ReserveChain is in development. Ensure written authorisation, final legal documentation,");
  console.warn(`!! an independent audit, and multisig administration are in place before proceeding.\n${banner}\n`);
}
