# Database initialisation

Files placed here are executed **once**, in filename order, the first time the
MySQL container initialises an empty data volume.

The directory is intentionally empty of privilege scripts.

## Why privilege hardening is not done here

An earlier version of this project narrowed the application database user at
container-init time. It broke the WordPress installation, because WordPress
creates its own tables during install and alters them during every core
upgrade — so the account named in `wp-config.php` genuinely needs DDL at those
moments.

Least privilege for this platform is therefore a **lifecycle control**, not an
install-time one:

| Phase | Privileges | How |
|---|---|---|
| Install / core upgrade | Full, including DDL | `infra/scripts/harden-database.sh --relax` |
| Normal operation | No UPDATE or DELETE on append-only tables | `infra/scripts/harden-database.sh` |
| Locked down | Also no DDL | `RESTRICT_DDL=1 infra/scripts/harden-database.sh` |

Run the hardening script after `wp core install` and after each deployment
that changes the schema:

```bash
infra/scripts/harden-database.sh            # apply least privilege
infra/scripts/harden-database.sh --dry-run  # show statements, change nothing
```

It enumerates the live schema rather than carrying a fixed table list, so it
stays correct as the schema grows.

## Why grants are applied table by table

MySQL privileges are additive across levels, and a database-level grant cannot
be revoked at table level. To withhold `UPDATE` and `DELETE` on the audit trail
specifically, the account must not hold them database-wide — so the script
grants them per table and skips the append-only set.

## This is not the primary defence

The audit trail's immutability does **not** depend on these grants. Migration
`0005_audit_immutability.sql` installs `BEFORE UPDATE` and `BEFORE DELETE`
triggers that raise `SQLSTATE 45000`, and those reject the statement from any
account including `root`. Grants add a second barrier by removing the ability
to drop the triggers; the per-row hash chain and its daily on-chain Merkle
anchor remain verifiable even against an attacker with full database control.

See `docs/architecture/audit-trail.md`.

## Role separation in staging and production

| Role | Used by | Privileges |
|---|---|---|
| `rc_migrator` | Deployments, CI | DDL and DML, including on append-only tables |
| `reservechain` | WordPress at runtime | DML; no UPDATE or DELETE on append-only tables |
| `rc_readonly` | Reporting, auditor role, backups | SELECT only |
