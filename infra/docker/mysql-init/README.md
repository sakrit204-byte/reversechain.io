# Database initialisation scripts

Files here are executed **once**, in filename order, the first time the MySQL
container initialises an empty data volume.

| File | Purpose |
|---|---|
| `10-audit-grants.sql` | Narrows the application database user so append-only tables cannot be updated or deleted, even by a compromised application process. |

## Important

These scripts do **not** re-run against an existing volume. If you change them:

```bash
docker compose down -v      # destroys local data
docker compose up -d db
```

The grants reference tables created by Prisma migrations, so on a truly empty
volume they are applied *before* the tables exist. MySQL permits granting on a
not-yet-existing table, so the grant is recorded and takes effect as soon as
the migration creates it.

To re-apply the grants against a running database without destroying data:

```bash
pnpm --filter @reservechain/api db:harden
```

## Role separation

| Role | Used by | Privileges |
|---|---|---|
| `root` (dev) / `rc_migrator` (staging, prod) | Prisma migrations, CI | DDL + DML, including on append-only tables |
| `reservechain` | The API at runtime | DML only; no DDL; no UPDATE/DELETE on append-only tables |
| `rc_readonly` | Reporting, the auditor role, backups | SELECT only |

Provisioning for staging and production is documented in
`docs/architecture/database-roles.md` and applied by `infra/ci`.
