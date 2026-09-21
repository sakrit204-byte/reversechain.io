-- =============================================================================
-- ReserveChain.io — audit-trail privilege hardening
--
-- Runs once, on first initialisation of the database container.
--
-- Requirement [M§16]:
--   "A complete append-only and tamper-evident administrative audit trail is
--    mandatory. Audit records must not be editable or removable through the
--    standard administrative interface."
--
-- The application connects as a user that is structurally incapable of
-- rewriting history. Schema migrations use a separate, privileged account
-- (root in development; a dedicated migrator role in staging/production —
-- see docs/architecture/database-roles.md).
--
-- This is defence #2 of four. The others are: no update/delete path in the
-- API, BEFORE UPDATE/DELETE triggers that raise SQLSTATE 45000, and the
-- per-row hash chain anchored on-chain each day.
-- =============================================================================

-- The application user is created by the MySQL entrypoint from MYSQL_USER /
-- MYSQL_PASSWORD and starts with ALL PRIVILEGES on the application schema.
-- Narrow that: append-only tables lose UPDATE and DELETE entirely.

-- Revoke blanket access, then grant back deliberately.
REVOKE ALL PRIVILEGES ON `reservechain`.* FROM 'reservechain'@'%';

-- Ordinary application tables: full DML, no DDL.
GRANT SELECT, INSERT, UPDATE, DELETE ON `reservechain`.* TO 'reservechain'@'%';

-- Append-only tables: INSERT and SELECT only.
REVOKE UPDATE, DELETE ON `reservechain`.`rc_audit_log`         FROM 'reservechain'@'%';
REVOKE UPDATE, DELETE ON `reservechain`.`rc_record_revisions`  FROM 'reservechain'@'%';
REVOKE UPDATE, DELETE ON `reservechain`.`rc_ledger_entries`    FROM 'reservechain'@'%';
REVOKE         DELETE ON `reservechain`.`rc_login_attempts`    FROM 'reservechain'@'%';
REVOKE         DELETE ON `reservechain`.`rc_consents`          FROM 'reservechain'@'%';

FLUSH PRIVILEGES;
