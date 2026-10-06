import type { ClaimStatus, EligibilityValue } from '@/api/types';
import { colors } from './tokens';

/** SPEC: proposed=nickel, in_development=amber, pending_verification=copper, verified=green, not_applicable=muted */
export const claimStatusColor: Record<ClaimStatus, string> = {
  proposed: colors.nickel,
  in_development: colors.amber,
  pending_verification: colors.copper,
  verified: colors.green,
  not_applicable: colors.muted,
};

export const eligibilityColor = (v: EligibilityValue): string => {
  switch (v) {
    case 'approved':
    case 'eligible':
      return colors.green;
    case 'pending':
      return colors.amber;
    case 'rejected':
    case 'restricted':
      return colors.red;
    default:
      return colors.muted;
  }
};
