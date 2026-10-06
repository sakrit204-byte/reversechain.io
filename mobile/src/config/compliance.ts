/**
 * Compliance copy that is legally authoritative in ENGLISH (SPEC.md rules 3 & 4).
 * Keep verbatim. Translations live in the i18n locale files and are informational only.
 * Bump DISCLOSURE_VERSION whenever the text changes so users must re-acknowledge.
 */
export const DISCLOSURE_EN =
  'ReserveChain is currently in development. No tokens are being offered or sold through this website. Registration of interest does not constitute an investment, token purchase, asset reservation, price reservation, token allocation or entitlement to participate in any future offering. Any future availability will be subject to the final Swiss corporate and legal structure, definitive offering documentation, asset verification, custody arrangements, jurisdictional eligibility, KYC/KYB, sanctions screening and final approval.';

export const EU_NOTICE_EN =
  'ReserveChain does not currently intend to offer tokens to residents of, or persons located in, the EU/EEA.';

export const DISCLOSURE_VERSION = '2026-10-v1';

/** SPEC "Waitlist rules" — EU/EEA country codes. */
export const EU_EEA_COUNTRIES: readonly string[] = [
  'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
  'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
];

export const isEuEea = (country: string): boolean =>
  EU_EEA_COUNTRIES.includes(country.trim().toUpperCase());
