/**
 * Types for the ReserveChain REST API (namespace /wp-json/rc/v1), aligned with SPEC.md and the
 * implemented WordPress plugin. Raw responses are normalised (see normalize.ts) into the app model
 * below. Every factual value that may not exist yet is nullable — the UI renders an explicit
 * "Pending" state instead of inventing a value.
 */

export type ClaimStatus =
  | 'proposed'
  | 'in_development'
  | 'pending_verification'
  | 'verified'
  | 'not_applicable';

export const CLAIM_STATUSES: readonly ClaimStatus[] = [
  'proposed',
  'in_development',
  'pending_verification',
  'verified',
  'not_applicable',
] as const;

export type LanguageCode = 'en' | 'es' | 'it';
export type ClientPlatform = 'ios' | 'android' | 'web';
export type EntityType = 'individual' | 'institution';

/** Modules that are feature-flagged OFF until authorised (SPEC rule 7). */
export type ModuleKey =
  | 'wallet'
  | 'purchase'
  | 'proof_of_reserves'
  | 'redemption'
  | 'waitlist'
  | 'holdings'
  | 'transactions';

export interface NetworkInfo {
  chain_id: number | null;
  name: string | null;
  token_address: string | null;
}

export interface AppConfig {
  site_mode: string;
  modules: Partial<Record<ModuleKey, boolean>> & Record<string, boolean | undefined>;
  languages: LanguageCode[];
  disclosure: string | null;
  eu_notice: string | null;
  network: NetworkInfo | null;
}

export interface Claim {
  label: string;
  status: ClaimStatus;
  note: string | null;
}

export interface Program {
  id: number | string;
  slug: string;
  symbol: 'Cu' | 'Ni';
  atomic_number: number;
  name: string;
  summary: string | null;
  status: ClaimStatus;
  claims: Claim[];
}

/**
 * A data field on a program, token program or passport.
 * `status` is null when the backend only says provided/pending — the app then shows no claim pill
 * (it never upgrades a value to "verified" on its own).
 */
export interface DataField {
  key: string;
  label: string;
  value: string | null;
  unit: string | null;
  status: ClaimStatus | null;
}

/** All token parameters are configuration and nullable (SPEC rule 5). */
export interface TokenProgram {
  record_no: string | null;
  status: ClaimStatus;
  fields: DataField[];
}

export interface ProgramDetail extends Program {
  fields: DataField[];
  token_program: TokenProgram | null;
}

export interface PassportSummary {
  id: number | string;
  passport_no: string;
  entity_type: string;
  entity_label: string | null;
  program: string;
  title: string;
  status: ClaimStatus;
  merkle_root: string | null;
  updated_at: string | null;
}

export interface TimelineEvent {
  key: string;
  date: string | null;
  event: string;
  status: ClaimStatus;
  note: string | null;
}

export interface EvidenceDocument {
  id: number | string;
  title: string;
  type: string;
  sha256: string | null;
  status: ClaimStatus;
  issued_by: string | null;
  issue_date: string | null;
  url: string | null;
}

export interface CustodyEntry {
  [key: string]: unknown;
}

export interface Completeness {
  present: number;
  total: number;
  percent: number;
}

export interface Passport extends PassportSummary {
  description: string | null;
  program_name: string | null;
  program_symbol: string | null;
  atomic_number: number | null;
  fields: DataField[];
  timeline: TimelineEvent[];
  documents: EvidenceDocument[];
  custody: CustodyEntry[];
  completeness: Completeness | null;
  /** Public passport URL (QR + share target). */
  url: string | null;
}

export interface VerifyResult {
  match: boolean;
  document?: EvidenceDocument;
  passport_no?: string;
}

export interface LibraryDocument {
  id: number | string;
  title: string;
  type: string | null;
  language: string | null;
  url: string | null;
  sha256: string | null;
  status: ClaimStatus;
  updated_at: string | null;
}

export interface Tokens {
  access_token: string;
  refresh_token: string;
  token_type?: string;
  expires_in?: number;
}

export type LoginResult =
  | { mfa_required: true; mfa_token: string }
  | ({ mfa_required?: false; mfa_recommended?: boolean } & Tokens);

export interface RegisterInput {
  email: string;
  password: string;
  name: string;
  country: string;
  entity_type: EntityType;
  accept_disclosure: true;
  accept_terms: true;
}

export interface MfaSetup {
  secret: string;
  otpauth_url: string;
}

export interface MfaEnableResult {
  ok: boolean;
  recovery_codes: string[];
}

export type CheckValue = 'not_started' | 'pending' | 'approved' | 'rejected' | 'not_applicable';
export type JurisdictionValue = 'eligible' | 'restricted' | 'pending';
export type EligibilityValue = CheckValue | JurisdictionValue;
export type OverallEligibility = 'restricted' | 'incomplete' | 'eligible_subject_to_final_approval';

export interface Eligibility {
  country: string | null;
  entity_type: string | null;
  kyc: CheckValue;
  kyb: CheckValue;
  aml: CheckValue;
  sanctions: CheckValue;
  jurisdiction: JurisdictionValue;
  overall: OverallEligibility | null;
  note: string | null;
}

export interface Me {
  id: number | string;
  name: string;
  email: string;
  country: string | null;
  entity_type: string | null;
  language: string | null;
  created_at: string | null;
  mfa_enabled: boolean;
  eligibility: Eligibility;
}

export interface Holding {
  [key: string]: unknown;
}

export interface Transaction {
  [key: string]: unknown;
}

/** /me/holdings and /me/transactions return {enabled:false, reason, items:[]} until authorised. */
export interface GatedList<T> {
  enabled: boolean;
  reason: string | null;
  items: T[];
}

export interface AppNotification {
  id: number | string;
  title: string;
  body: string | null;
  category: string | null;
  created_at: string | null;
  read: boolean;
  broadcast: boolean;
}

export interface SupportInput {
  subject: string;
  message: string;
  topic: string;
}

export interface SupportResult {
  ok: boolean;
  ticket: string | null;
}

export interface OkResult {
  ok: boolean;
  message?: string;
  [key: string]: unknown;
}
