/**
 * MOCK mode (EXPO_PUBLIC_API_MOCK=1).
 *
 * Realistically *shaped* responses so the app can be demoed without a backend — but every factual
 * value that does not exist yet is null / "Pending". Nothing here is a real specification, price,
 * supply figure, laboratory, custodian, insurer, vault or date (SPEC.md rule 1).
 *
 * The SHA-256 values below are genuine hashes of the literal strings
 * "RESERVECHAIN MOCK PLACEHOLDER DOCUMENT A|B|C" — they fingerprint placeholder text only.
 * The Cu Merkle root is SHA-256(hashA || hashB); the Ni root is the single leaf hash C.
 */
import { DISCLOSURE_EN, EU_NOTICE_EN } from '@/config/compliance';
import { ApiError, type ReserveChainApi, type TokenStore } from './client';
import type {
  AppConfig,
  AppNotification,
  DataField,
  LibraryDocument,
  Me,
  Passport,
  PassportSummary,
  ProgramDetail,
  TokenProgram,
} from './types';

const HASH_A = 'fe17d0733d955431d7ecf698eebec77f0bb2265ea83be3e7bf90cd2856f5af76';
const HASH_B = 'dda86fd5a16c702e35cb9d80666aaf8d0f50d2236a856d1d1fe95ca35813947c';
const HASH_C = 'c66fd061e86528f72787a700a8d0b9fb93221237b187e2625eaea1d7625ac26a';
const ROOT_CU = 'b8a5b0a5bb606bcbfe1b0654f66844a3cd8d0a0087d598e0cdabb3eb3de9d5b3';
const ROOT_NI = HASH_C;

const pending = (key: string, label: string): DataField => ({
  key,
  label,
  value: null,
  unit: null,
  status: 'pending_verification',
});
const notProvided = (key: string, label: string): DataField => ({
  key,
  label,
  value: null,
  unit: null,
  status: 'proposed',
});

/** All token parameters unset — they are configuration, never hard-coded (SPEC rule 5). */
const unsetTokenProgram = (): TokenProgram => ({
  record_no: null,
  status: 'in_development',
  fields: [
    'token_name',
    'token_symbol',
    'decimals',
    'price',
    'supply_cap',
    'asset_to_token_ratio',
    'redemption_min',
    'redemption_max',
    'network',
    'contract_address',
  ].map((k) => ({ key: k, label: k, value: null, unit: null, status: null })),
});

export const MOCK_CONFIG: AppConfig = {
  site_mode: 'pre_launch',
  modules: {
    wallet: false,
    purchase: false,
    proof_of_reserves: false,
    redemption: false,
    waitlist: true,
    holdings: false,
    transactions: false,
  },
  languages: ['en', 'es', 'it'],
  disclosure: DISCLOSURE_EN,
  eu_notice: EU_NOTICE_EN,
  network: { chain_id: 11155111, name: 'Sepolia (testnet)', token_address: null },
};

const claims = () => [
  { label: 'Asset specification', status: 'pending_verification' as const, note: 'Pending — awaiting certificate of analysis' },
  { label: 'Custody arrangement', status: 'proposed' as const, note: 'Not yet provided' },
  { label: 'Insurance', status: 'proposed' as const, note: 'Not yet provided' },
  { label: 'Token program', status: 'in_development' as const, note: 'Subject to final approval' },
  { label: 'Proof of Reserves', status: 'proposed' as const, note: 'Inactive — subject to authorization' },
];

const PROGRAMS: ProgramDetail[] = [
  {
    id: 1,
    slug: 'copper-powder',
    symbol: 'Cu',
    atomic_number: 29,
    name: 'Copper Powder',
    summary:
      'Proposed program for ultra-high-purity copper powder. Copper powder is used in applications such as additive manufacturing and electronics. Asset specifications for this program are pending verification.',
    status: 'proposed',
    claims: claims(),
    fields: [
      pending('purity', 'Purity'),
      pending('particle_size', 'Particle size distribution'),
      notProvided('custodian', 'Custodian'),
      notProvided('vault_location', 'Vault location'),
    ],
    token_program: unsetTokenProgram(),
  },
  {
    id: 2,
    slug: 'nickel-wire',
    symbol: 'Ni',
    atomic_number: 28,
    name: 'Nickel Wire',
    summary:
      'Proposed program for high-purity nickel wire. Nickel wire is used in applications such as heating elements and battery manufacturing. Asset specifications for this program are pending verification.',
    status: 'proposed',
    claims: claims(),
    fields: [
      pending('purity', 'Purity'),
      pending('wire_diameter', 'Wire diameter'),
      notProvided('custodian', 'Custodian'),
      notProvided('vault_location', 'Vault location'),
    ],
    token_program: unsetTokenProgram(),
  },
];

const passportFields = (kind: 'cu' | 'ni'): DataField[] => [
  pending('purity', 'Purity'),
  kind === 'cu' ? pending('particle_size', 'Particle size distribution') : pending('wire_diameter', 'Wire diameter'),
  pending('net_weight', 'Net weight'),
  pending('origin', 'Origin'),
  notProvided('laboratory', 'Assay laboratory'),
  notProvided('custodian', 'Custodian'),
  notProvided('vault_location', 'Vault location'),
  notProvided('insurer', 'Insurer'),
];

const PASSPORTS: Passport[] = [
  {
    id: 101,
    passport_no: 'RC-CU-LOT-000001',
    entity_type: 'lot',
    entity_label: 'Lot',
    program: 'copper-powder',
    program_name: 'Copper Powder',
    program_symbol: 'Cu',
    atomic_number: 29,
    title: 'Copper Powder — example lot passport (mock placeholder)',
    description: 'Mock placeholder record used to demonstrate the passport layout. Not a real asset.',
    status: 'in_development',
    merkle_root: ROOT_CU,
    updated_at: null,
    fields: passportFields('cu'),
    timeline: [
      { key: 'created', date: null, event: 'Passport record created (mock data)', status: 'in_development', note: null },
      { key: 'coa', date: null, event: 'Certificate of analysis — awaiting laboratory', status: 'pending_verification', note: null },
      { key: 'custody', date: null, event: 'Custody intake — not yet provided', status: 'proposed', note: null },
    ],
    documents: [
      { id: 'a', title: 'Placeholder document A (mock — not a real certificate)', type: 'Placeholder', sha256: HASH_A, status: 'not_applicable', issued_by: null, issue_date: null, url: null },
      { id: 'b', title: 'Placeholder document B (mock — not a real certificate)', type: 'Placeholder', sha256: HASH_B, status: 'not_applicable', issued_by: null, issue_date: null, url: null },
      { id: 'coa', title: 'Certificate of analysis', type: 'Certificate', sha256: null, status: 'pending_verification', issued_by: null, issue_date: null, url: null },
    ],
    custody: [],
    completeness: null,
    url: null,
  },
  {
    id: 102,
    passport_no: 'RC-NI-COL-000001',
    entity_type: 'coil',
    entity_label: 'Coil',
    program: 'nickel-wire',
    program_name: 'Nickel Wire',
    program_symbol: 'Ni',
    atomic_number: 28,
    title: 'Nickel Wire — example coil passport (mock placeholder)',
    description: 'Mock placeholder record used to demonstrate the passport layout. Not a real asset.',
    status: 'proposed',
    merkle_root: ROOT_NI,
    updated_at: null,
    fields: passportFields('ni'),
    timeline: [
      { key: 'created', date: null, event: 'Passport record created (mock data)', status: 'in_development', note: null },
      { key: 'coa', date: null, event: 'Certificate of analysis — awaiting laboratory', status: 'pending_verification', note: null },
    ],
    documents: [
      { id: 'c', title: 'Placeholder document C (mock — not a real certificate)', type: 'Placeholder', sha256: HASH_C, status: 'not_applicable', issued_by: null, issue_date: null, url: null },
      { id: 'coa-ni', title: 'Certificate of analysis', type: 'Certificate', sha256: null, status: 'pending_verification', issued_by: null, issue_date: null, url: null },
    ],
    custody: [],
    completeness: null,
    url: null,
  },
];

const DOCUMENTS: LibraryDocument[] = [
  { id: 'disclosure', title: 'Important disclosure', type: 'Notice', language: 'en', url: null, sha256: null, status: 'verified', updated_at: null },
  { id: 'whitepaper', title: 'Institutional whitepaper (draft)', type: 'Whitepaper', language: 'en', url: null, sha256: null, status: 'in_development', updated_at: null },
  { id: 'privacy', title: 'Privacy notice', type: 'Policy', language: 'en', url: null, sha256: null, status: 'in_development', updated_at: null },
  { id: 'offering', title: 'Offering documentation', type: 'Legal', language: 'en', url: null, sha256: null, status: 'proposed', updated_at: null },
];

const delay = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

export interface MockOptions {
  tokenStore: TokenStore;
  delayMs?: number;
}

export function createMockApi({ tokenStore, delayMs = 250 }: MockOptions): ReserveChainApi {
  const state = {
    email: 'demo@example.com',
    name: 'Demo user (mock)',
    country: null as string | null,
    entityType: 'individual',
    language: null as string | null,
    mfaEnabled: true,
    seq: 0,
    read: new Set<string>(),
  };
  const tick = () => delay(delayMs);
  const issue = async () => {
    state.seq += 1;
    const t = {
      access_token: `mock-access-${state.seq}`,
      refresh_token: `mock-refresh-${state.seq}`,
      token_type: 'Bearer',
      expires_in: 3600,
    };
    await tokenStore.set(t);
    return t;
  };
  const requireSession = async () => {
    if (!(await tokenStore.get())) throw new ApiError('Not signed in', 401, 'no_session');
  };
  const summary = (p: Passport): PassportSummary => ({
    id: p.id,
    passport_no: p.passport_no,
    entity_type: p.entity_type,
    entity_label: p.entity_label,
    program: p.program,
    title: p.title,
    status: p.status,
    merkle_root: p.merkle_root,
    updated_at: p.updated_at,
  });

  return {
    mock: true,
    async getConfig() {
      await tick();
      return clone(MOCK_CONFIG);
    },
    async getPrograms() {
      await tick();
      return PROGRAMS.map(({ token_program: _tp, fields: _f, ...p }) => clone(p));
    },
    async getProgram(slug) {
      await tick();
      const p = PROGRAMS.find((x) => x.slug === slug);
      if (!p) throw new ApiError('Program not found', 404, 'rc_not_found');
      return clone(p);
    },
    async getPassports(program) {
      await tick();
      return PASSPORTS.filter((p) => !program || p.program === program).map(summary);
    },
    async getPassport(no) {
      await tick();
      const p = PASSPORTS.find((x) => x.passport_no === no);
      if (!p) throw new ApiError('Passport not found', 404, 'rc_not_found');
      return clone(p);
    },
    async verifyHash(hash) {
      await tick();
      const h = hash.toLowerCase();
      for (const p of PASSPORTS) {
        const d = p.documents.find((x) => x.sha256 === h);
        if (d) return { match: true, document: clone(d), passport_no: p.passport_no };
      }
      return { match: false };
    },
    async getDocuments() {
      await tick();
      return clone(DOCUMENTS);
    },
    async register(input) {
      await tick();
      state.email = input.email;
      state.name = input.name;
      state.country = input.country.toUpperCase();
      state.entityType = input.entity_type;
      state.mfaEnabled = false;
      return { ok: true, message: 'If the address can be registered, a confirmation email has been sent.' };
    },
    async login(email, password) {
      await tick();
      if (!email || !password) throw new ApiError('Invalid credentials', 401, 'rc_invalid_credentials');
      state.email = email;
      if (state.mfaEnabled) return { mfa_required: true, mfa_token: 'mock-mfa-token' };
      return { mfa_required: false, mfa_recommended: true, ...(await issue()) };
    },
    async verifyMfa(_token, code) {
      await tick();
      if (!/^\d{6}$/.test(code) && !/^[A-Za-z0-9]{10}$/.test(code)) {
        throw new ApiError('Invalid code', 400, 'rc_invalid_code');
      }
      return issue();
    },
    async setupMfa() {
      await tick();
      await requireSession();
      const secret = 'MOCKMOCKMOCKMOCKMOCKMOCKMOCKMOCK';
      return {
        secret,
        otpauth_url: `otpauth://totp/ReserveChain%20(mock):${encodeURIComponent(state.email)}?secret=${secret}&issuer=ReserveChain%20(mock)&algorithm=SHA1&digits=6&period=30`,
      };
    },
    async enableMfa(code) {
      await tick();
      await requireSession();
      if (!/^\d{6}$/.test(code)) throw new ApiError('Invalid code', 400, 'rc_invalid_code');
      state.mfaEnabled = true;
      // Obviously-fake recovery codes for the demo.
      return { ok: true, recovery_codes: Array.from({ length: 8 }, (_, i) => `MOCK${String(i + 1).padStart(6, '0')}`) };
    },
    async refresh() {
      await tick();
      await requireSession();
      return issue();
    },
    async logout() {
      await tokenStore.clear();
    },
    async getMe(): Promise<Me> {
      await tick();
      await requireSession();
      return {
        id: 'mock-user',
        name: state.name,
        email: state.email,
        country: state.country,
        entity_type: state.entityType,
        language: state.language,
        created_at: null,
        mfa_enabled: state.mfaEnabled,
        eligibility: {
          country: state.country,
          entity_type: state.entityType,
          kyc: 'not_started',
          kyb: state.entityType === 'institution' ? 'not_started' : 'not_applicable',
          aml: 'not_started',
          sanctions: 'not_started',
          jurisdiction: 'pending',
          overall: 'incomplete',
          note: null,
        },
      };
    },
    async updateMe(patch) {
      await tick();
      await requireSession();
      if (patch.name) state.name = patch.name;
      if (patch.language) state.language = patch.language;
      return { ok: true };
    },
    async getHoldings() {
      await tick();
      await requireSession();
      return { enabled: false, reason: 'Inactive — subject to authorization', items: [] };
    },
    async getTransactions() {
      await tick();
      await requireSession();
      return { enabled: false, reason: 'Inactive — subject to authorization', items: [] };
    },
    async getNotifications(): Promise<AppNotification[]> {
      await tick();
      await requireSession();
      return [
        {
          id: 'mock-1',
          title: 'Mock mode',
          body: 'This build is running with placeholder data. No information shown is a real asset record.',
          category: 'system',
          created_at: null,
          read: state.read.has('mock-1'),
          broadcast: true,
        },
      ];
    },
    async markNotificationRead(nid) {
      await tick();
      state.read.add(String(nid));
      return { ok: true };
    },
    async submitSupport(input) {
      await tick();
      await requireSession();
      if (!input.subject.trim() || !input.message.trim()) {
        throw new ApiError('Subject and message are required', 400, 'rc_invalid');
      }
      return { ok: true, ticket: 'RC-SUP-MOCK' };
    },
    async registerDevice() {
      await tick();
      return { ok: true };
    },
  };
}
