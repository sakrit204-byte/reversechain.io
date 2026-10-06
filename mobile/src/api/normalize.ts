/**
 * Defensive normalisation of raw API payloads into the app model.
 * Rules: unknown/missing values become null; unknown statuses DOWNGRADE (never upgrade) a claim.
 */
import {
  CLAIM_STATUSES,
  type AppNotification,
  type CheckValue,
  type ClaimStatus,
  type DataField,
  type Eligibility,
  type EvidenceDocument,
  type JurisdictionValue,
  type LibraryDocument,
  type Me,
  type OverallEligibility,
  type Passport,
  type PassportSummary,
  type Program,
  type ProgramDetail,
  type TimelineEvent,
  type TokenProgram,
} from './types';

type Obj = Record<string, unknown>;
const obj = (v: unknown): Obj => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Obj) : {});
const arr = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);
const str = (v: unknown): string | null =>
  typeof v === 'string' && v.trim() !== '' ? v : typeof v === 'number' ? String(v) : null;
const num = (v: unknown): number | null => {
  const n = typeof v === 'number' ? v : typeof v === 'string' ? Number(v) : NaN;
  return Number.isFinite(n) ? n : null;
};
const id = (v: unknown, fallback: string): string | number =>
  typeof v === 'number' || (typeof v === 'string' && v) ? (v as string | number) : fallback;

/** Map any status string to a ClaimStatus. "pending" ⇒ pending_verification; unknown ⇒ proposed. */
export function toClaimStatus(v: unknown): ClaimStatus {
  if (typeof v !== 'string') return 'proposed';
  if ((CLAIM_STATUSES as readonly string[]).includes(v)) return v as ClaimStatus;
  if (v === 'pending' || v === 'pending_review') return 'pending_verification';
  if (v === 'draft' || v === 'planned') return 'proposed';
  return 'proposed';
}

/** Like toClaimStatus, but returns null when the payload carries no claim status at all. */
function optionalStatus(o: Obj): ClaimStatus | null {
  if (typeof o.status === 'string') return toClaimStatus(o.status);
  // A missing value is not a claim awaiting verification: no pill unless the backend sends a claim status.
  return null;
}

export function toField(v: unknown, i = 0): DataField {
  const o = obj(v);
  const pending = o.pending === true || o.state === 'pending';
  return {
    key: str(o.key) ?? `field_${i}`,
    label: str(o.label) ?? str(o.key) ?? '—',
    value: pending ? null : str(o.value),
    unit: str(o.unit),
    status: optionalStatus(o),
    pendingText: typeof o.pending === 'string' && o.pending ? o.pending : null,
  };
}

export function toProgram(v: unknown): Program {
  const o = obj(v);
  const symbol = o.symbol === 'Ni' ? 'Ni' : 'Cu';
  return {
    id: id(o.id, str(o.slug) ?? symbol),
    slug: str(o.slug) ?? (symbol === 'Cu' ? 'copper-powder' : 'nickel-wire'),
    symbol,
    atomic_number: num(o.atomic_number) ?? (symbol === 'Cu' ? 29 : 28),
    name: str(o.name) ?? (symbol === 'Cu' ? 'Copper Powder' : 'Nickel Wire'),
    summary: str(o.summary),
    status: toClaimStatus(o.status),
    claims: arr(o.claims).map((c) => {
      const co = obj(c);
      return { label: str(co.label) ?? '—', status: toClaimStatus(co.status), note: str(co.note) };
    }),
  };
}

function toTokenProgram(v: unknown): TokenProgram | null {
  if (!v || typeof v !== 'object') return null;
  const o = obj(v);
  // SPEC shape: flat object of nullable params. Backend shape: {record_no,status,fields[]}.
  const fields = Array.isArray(o.fields)
    ? o.fields.map(toField)
    : Object.entries(o)
        .filter(([k]) => !['record_no', 'status'].includes(k))
        .map(([k, val]) => ({ key: k, label: k, value: str(val), unit: null, status: null }));
  return { record_no: str(o.record_no), status: toClaimStatus(o.status), fields };
}

export function toProgramDetail(v: unknown): ProgramDetail {
  const o = obj(v);
  return {
    ...toProgram(o),
    fields: arr(o.fields).map(toField),
    token_program: toTokenProgram(o.token_program),
  };
}

function programSlug(p: unknown): string {
  if (typeof p === 'string') return p;
  return str(obj(p).slug) ?? '';
}

export function toPassportSummary(v: unknown): PassportSummary {
  const o = obj(v);
  const no = str(o.passport_no) ?? '—';
  return {
    id: id(o.id, no),
    passport_no: no,
    entity_type: str(o.entity_type) ?? '—',
    entity_label: str(o.entity_label),
    program: programSlug(o.program),
    title: str(o.title) ?? no,
    status: toClaimStatus(o.status),
    merkle_root: str(o.merkle_root),
    updated_at: str(o.updated_at),
  };
}

export function toEvidenceDocument(v: unknown, i = 0): EvidenceDocument {
  const o = obj(v);
  const sha = str(o.sha256);
  return {
    id: id(o.id ?? o.record_no, `doc_${i}`),
    title: str(o.title) ?? '—',
    type: str(o.type_label) ?? str(o.type) ?? '—',
    sha256: sha && /^[0-9a-f]{64}$/i.test(sha) ? sha.toLowerCase() : null,
    status: toClaimStatus(o.status),
    issued_by: str(o.issued_by),
    issue_date: str(o.issue_date),
    url: str(o.url),
  };
}

function toTimeline(v: unknown, i: number): TimelineEvent {
  const o = obj(v);
  return {
    key: str(o.key) ?? `event_${i}`,
    date: str(o.date),
    event: str(o.label) ?? str(o.event) ?? '—',
    status: toClaimStatus(o.status),
    note: str(o.note),
  };
}

export function toPassport(v: unknown): Passport {
  const o = obj(v);
  const p = obj(o.program);
  const c = obj(o.completeness);
  const total = num(c.total);
  // Backend may split evidence into `evidence` and `documents`; merge, de-duplicating by hash/id.
  const docs = [...arr(o.documents), ...arr(o.evidence)].map(toEvidenceDocument);
  const seen = new Set<string>();
  const documents = docs.filter((d) => {
    const k = d.sha256 ?? `id:${d.id}`;
    if (seen.has(k)) return false;
    seen.add(k);
    return true;
  });
  return {
    ...toPassportSummary(o),
    description: str(o.description),
    program_name: str(p.name),
    program_symbol: str(p.symbol),
    atomic_number: num(p.atomic_number),
    fields: arr(o.fields).map(toField),
    timeline: arr(o.timeline).map(toTimeline),
    documents,
    custody: arr(o.custody).map(obj),
    completeness:
      total !== null
        ? { present: num(c.present) ?? 0, total, percent: num(c.percent) ?? 0 }
        : null,
    url: str(o.url) ?? str(o.qr_url),
  };
}

export function toLibraryDocument(v: unknown, i = 0): LibraryDocument {
  const o = obj(v);
  const d = toEvidenceDocument(o, i);
  return {
    id: d.id,
    title: d.title,
    type: str(o.type_label) ?? str(o.type),
    language: str(o.language),
    url: d.url,
    sha256: d.sha256,
    status: d.status,
    updated_at: str(o.updated_at) ?? str(o.issue_date),
  };
}

const CHECKS: readonly CheckValue[] = ['not_started', 'pending', 'approved', 'rejected', 'not_applicable'];
const toCheck = (v: unknown): CheckValue =>
  (CHECKS as readonly unknown[]).includes(v) ? (v as CheckValue) : 'not_started';
const toJurisdiction = (v: unknown): JurisdictionValue =>
  v === 'eligible' || v === 'restricted' ? v : 'pending';
const OVERALL: readonly OverallEligibility[] = ['restricted', 'incomplete', 'eligible_subject_to_final_approval'];

export function toEligibility(v: unknown): Eligibility {
  const o = obj(v);
  return {
    country: str(o.country),
    entity_type: str(o.entity_type),
    kyc: toCheck(o.kyc),
    kyb: toCheck(o.kyb),
    aml: toCheck(o.aml),
    sanctions: toCheck(o.sanctions),
    jurisdiction: toJurisdiction(o.jurisdiction),
    overall: (OVERALL as readonly unknown[]).includes(o.overall) ? (o.overall as OverallEligibility) : null,
    note: str(o.note),
  };
}

export function toMe(v: unknown): Me {
  const o = obj(v);
  return {
    id: id(o.id, 'me'),
    name: str(o.name) ?? '',
    email: str(o.email) ?? '',
    country: str(o.country),
    entity_type: str(o.entity_type),
    language: str(o.language),
    created_at: str(o.created_at),
    mfa_enabled: o.mfa_enabled === true,
    eligibility: toEligibility(o.eligibility),
  };
}

export function toNotification(v: unknown, i = 0): AppNotification {
  const o = obj(v);
  return {
    id: id(o.id, `n_${i}`),
    title: str(o.title) ?? '—',
    body: str(o.body),
    category: str(o.category),
    created_at: str(o.created_at),
    read: o.read === true,
    broadcast: o.broadcast === true,
  };
}
