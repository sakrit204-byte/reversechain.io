import en from '@/i18n/locales/en';
import es from '@/i18n/locales/es';
import itLocale from '@/i18n/locales/it';
import { DISCLOSURE_EN, EU_NOTICE_EN } from '@/config/compliance';

interface Tree {
  [k: string]: string | Tree;
}

function flatten(obj: Tree, prefix = ''): Record<string, string> {
  return Object.entries(obj).reduce<Record<string, string>>((acc, [k, v]) => {
    const key = prefix ? `${prefix}.${k}` : k;
    if (typeof v === 'string') acc[key] = v;
    else Object.assign(acc, flatten(v, key));
    return acc;
  }, {});
}

const placeholders = (s: string) => (s.match(/\{\{\s*\w+\s*\}\}/g) ?? []).sort();

const others: Record<string, Record<string, string>> = {
  es: flatten(es as unknown as Tree),
  it: flatten(itLocale as unknown as Tree),
};
const base = flatten(en as unknown as Tree);

describe('i18n completeness', () => {
  it.each(Object.keys(others))('%s has exactly the same keys as en', (lng) => {
    expect(Object.keys(others[lng] ?? {}).sort()).toEqual(Object.keys(base).sort());
  });

  it.each(Object.keys(others))('%s has no empty strings and the same interpolation placeholders', (lng) => {
    const other = others[lng] ?? {};
    for (const [k, v] of Object.entries(base)) {
      expect([k, (other[k] ?? '').trim().length > 0]).toEqual([k, true]);
      expect([k, placeholders(other[k] ?? '')]).toEqual([k, placeholders(v)]);
    }
  });

  it('English disclosure and EU/EEA notice are verbatim (SPEC rules 3-4)', () => {
    expect(en.disclosure.body).toBe(DISCLOSURE_EN);
    expect(en.disclosure.euBody).toBe(EU_NOTICE_EN);
  });

  it('translations state that the English text is authoritative', () => {
    expect(es.disclosure.authoritativeNote).toMatch(/inglés/i);
    expect(itLocale.disclosure.authoritativeNote).toMatch(/inglese/i);
  });

  it('UI copy avoids prohibited promotional terms (SPEC rule 2)', () => {
    const banned = /\b(APY|profits?|returns?|yields?|guaranteed)\b/i;
    for (const [k, v] of Object.entries(base)) {
      expect([k, banned.test(v)]).toEqual([k, false]);
    }
  });
});
