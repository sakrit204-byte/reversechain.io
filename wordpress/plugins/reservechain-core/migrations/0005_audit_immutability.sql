-- =============================================================================
-- ReserveChain.io — 0002  Audit-trail immutability triggers
--
-- Defence #3 of the four described in 0001. Privilege grants stop the runtime
-- user; these triggers stop *everyone*, including a database session holding
-- full rights and including the WordPress administrator. There is no code path,
-- and no SQL statement, that can rewrite or erase administrative history.
--
--   [M§16] "Audit records must not be editable or removable through the
--           standard administrative interface."
--
-- Correcting a mistaken entry is done the way an accounting ledger does it:
-- append a compensating record that references the original. Nothing is
-- overwritten, so the chain and its on-chain anchors stay verifiable.
--
-- Operational note: a genuine schema migration that must alter these tables
-- drops the triggers, migrates, re-creates them, and records the fact — the
-- procedure is in docs/architecture/audit-trail.md and is itself audited.
-- =============================================================================

DROP TRIGGER IF EXISTS `{prefix}rc_audit_log_no_update`;
DROP TRIGGER IF EXISTS `{prefix}rc_audit_log_no_delete`;
DROP TRIGGER IF EXISTS `{prefix}rc_record_revisions_no_update`;
DROP TRIGGER IF EXISTS `{prefix}rc_record_revisions_no_delete`;
DROP TRIGGER IF EXISTS `{prefix}rc_ledger_entries_no_update`;
DROP TRIGGER IF EXISTS `{prefix}rc_ledger_entries_no_delete`;
DROP TRIGGER IF EXISTS `{prefix}rc_consents_no_delete`;

-- --- Audit log ---------------------------------------------------------------
CREATE TRIGGER `{prefix}rc_audit_log_no_update`
BEFORE UPDATE ON `{prefix}rc_audit_log`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain audit trail is append-only: UPDATE is not permitted. Append a compensating entry instead.';

CREATE TRIGGER `{prefix}rc_audit_log_no_delete`
BEFORE DELETE ON `{prefix}rc_audit_log`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain audit trail is append-only: DELETE is not permitted.';

-- --- Record revisions --------------------------------------------------------
CREATE TRIGGER `{prefix}rc_record_revisions_no_update`
BEFORE UPDATE ON `{prefix}rc_record_revisions`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain record revisions are immutable: UPDATE is not permitted.';

CREATE TRIGGER `{prefix}rc_record_revisions_no_delete`
BEFORE DELETE ON `{prefix}rc_record_revisions`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain record revisions are immutable: DELETE is not permitted.';

-- --- Financial ledger --------------------------------------------------------
-- [MP5] "Financial or token balances must not be maintained solely as manually
--        editable database fields. Every balance-affecting action must have a
--        supporting transaction, reason, timestamp, user, wallet or reference
--        and audit history."
CREATE TRIGGER `{prefix}rc_ledger_entries_no_update`
BEFORE UPDATE ON `{prefix}rc_ledger_entries`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain ledger is append-only: post a reversing entry instead of editing.';

CREATE TRIGGER `{prefix}rc_ledger_entries_no_delete`
BEFORE DELETE ON `{prefix}rc_ledger_entries`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain ledger is append-only: DELETE is not permitted.';

-- --- Consent records ---------------------------------------------------------
-- Consent evidence must survive: what was agreed, to which document version,
-- when, and from where. [M§15, MP6] Withdrawal is recorded by setting
-- `withdrawn_at`, which is an update to a live field, not a deletion — so only
-- DELETE is blocked here.
CREATE TRIGGER `{prefix}rc_consents_no_delete`
BEFORE DELETE ON `{prefix}rc_consents`
FOR EACH ROW
  SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ReserveChain consent records are retained: withdraw consent instead of deleting the record.';
