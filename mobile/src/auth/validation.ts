export const isValidEmail = (v: string): boolean => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim());

/** Backend policy: ≥12 chars with upper-case, lower-case and a digit. */
export const meetsPasswordPolicy = (v: string): boolean =>
  v.length >= 12 && /[a-z]/.test(v) && /[A-Z]/.test(v) && /\d/.test(v);

export const isCountryCode = (v: string): boolean => /^[A-Za-z]{2}$/.test(v.trim());

/** 6-digit TOTP or a 10-character recovery code. */
export const isMfaCode = (v: string): boolean => /^\d{6}$/.test(v) || /^[A-Za-z0-9]{10}$/.test(v);

export const isTotp = (v: string): boolean => /^\d{6}$/.test(v);
