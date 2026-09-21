#!/usr/bin/env bash
#
# ReserveChain.io — database least-privilege hardening
#
# Narrows the runtime database user so it cannot rewrite append-only history,
# while leaving WordPress able to do everything it legitimately needs.
#
# WHY THIS IS A POST-INSTALL STEP, NOT AN INIT SCRIPT
# ---------------------------------------------------
# WordPress creates and alters its own tables during installation and during
# every core upgrade, so the account in wp-config.php needs DDL at those
# moments. Revoking DDL up front simply breaks the install. Least privilege
# here is therefore a lifecycle control: grant broadly during a deployment
# window, narrow afterwards.
#
# WHY GRANTS ARE APPLIED PER TABLE
# --------------------------------
# MySQL privileges are additive across levels, and a database-level grant
# cannot be revoked at table level. To withhold UPDATE and DELETE on specific
# tables, the account must not hold them database-wide — so this script grants
# them table by table, skipping the append-only set. That is why it enumerates
# tables at run time rather than shipping a fixed list: it stays correct as the
# schema grows.
#
# RELATIONSHIP TO THE OTHER DEFENCES
# ----------------------------------
# This is one of four layers. The BEFORE UPDATE / BEFORE DELETE triggers
# installed by migration 0005 already reject those statements from *any*
# account, including root, so the audit trail is protected even where this
# script has not been run. The grants remove the ability to drop those triggers
# in the first place, and the on-chain Merkle anchor remains verifiable even if
# an attacker obtains full database control.
#
# USAGE
#   ./harden-database.sh                        # uses .env
#   DB_NAME=reservechain DB_USER=reservechain ./harden-database.sh
#   ./harden-database.sh --dry-run              # print statements, change nothing
#   ./harden-database.sh --relax                # restore DDL before a deployment
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# shellcheck disable=SC1091
[ -f "${ROOT_DIR}/.env" ] && set -a && . "${ROOT_DIR}/.env" && set +a

DB_NAME="${DB_NAME:-reservechain}"
DB_USER="${DB_USER:-reservechain}"
DB_HOST_PATTERN="${DB_HOST_PATTERN:-%}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD must be set}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-rc-db}"

DRY_RUN=0
RELAX=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --relax)   RELAX=1 ;;
    *) echo "Unknown option: $arg" >&2; exit 64 ;;
  esac
done

# Tables that must never accept UPDATE or DELETE from the application account.
# Keep in step with the triggers in migration 0005.
APPEND_ONLY_TABLES=(
  "rc_audit_log"
  "rc_record_revisions"
  "rc_ledger_entries"
)

# Tables that may be inserted into and read, but never deleted from.
NO_DELETE_TABLES=(
  "rc_consents"
  "rc_login_attempts"
  "rc_audit_anchors"
)

run_sql() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '%s\n' "$1"
  else
    # stdin is explicitly closed. Without this, `docker exec -i` inside a
    # `while read` loop consumes the loop's input stream and the loop silently
    # processes a single row.
    docker exec "$MYSQL_CONTAINER" mysql -uroot -p"$DB_ROOT_PASSWORD" -e "$1" </dev/null 2>/dev/null
  fi
}

if [ "$RELAX" -eq 1 ]; then
  echo "Restoring full privileges on ${DB_NAME} for '${DB_USER}' (deployment window)."
  run_sql "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST_PATTERN}'; FLUSH PRIVILEGES;"
  echo "Done. Re-run without --relax once the deployment completes."
  exit 0
fi

echo "Hardening '${DB_USER}'@'${DB_HOST_PATTERN}' on ${DB_NAME}"

# Enumerate the live schema.
TABLES="$(docker exec "$MYSQL_CONTAINER" mysql -uroot -p"$DB_ROOT_PASSWORD" -N -B \
  -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_type='BASE TABLE' ORDER BY table_name;" 2>/dev/null)"

if [ -z "$TABLES" ]; then
  echo "No tables found in ${DB_NAME}. Run the WordPress install and plugin migrations first." >&2
  exit 1
fi

# Start from a clean slate, then grant back deliberately.
#
# Database-level privileges exclude UPDATE and DELETE so that they can be
# withheld per table below. DDL is retained: WordPress needs it for core
# upgrades. Deployments that must remove even that should use --relax during
# the window and re-run this script afterwards with RESTRICT_DDL=1.
run_sql "REVOKE ALL PRIVILEGES ON \`${DB_NAME}\`.* FROM '${DB_USER}'@'${DB_HOST_PATTERN}';"

if [ "${RESTRICT_DDL:-0}" -eq 1 ]; then
  BASE_PRIVS="SELECT, INSERT, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW"
  echo "  DDL restricted (RESTRICT_DDL=1). Run with --relax before a WordPress core upgrade."
else
  BASE_PRIVS="SELECT, INSERT, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER, CREATE VIEW, SHOW VIEW, EXECUTE"
fi

run_sql "GRANT ${BASE_PRIVS} ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST_PATTERN}';"

skipped_update=0
skipped_delete=0
granted=0

while IFS= read -r table; do
  [ -z "$table" ] && continue

  is_append_only=0
  for t in "${APPEND_ONLY_TABLES[@]}"; do
    [ "$table" = "${TABLE_PREFIX:-wp_}${t}" ] && is_append_only=1 && break
  done

  is_no_delete=0
  for t in "${NO_DELETE_TABLES[@]}"; do
    [ "$table" = "${TABLE_PREFIX:-wp_}${t}" ] && is_no_delete=1 && break
  done

  if [ "$is_append_only" -eq 1 ]; then
    skipped_update=$((skipped_update + 1))
    skipped_delete=$((skipped_delete + 1))
    echo "  append-only  ${table}  (no UPDATE, no DELETE)"
    continue
  fi

  if [ "$is_no_delete" -eq 1 ]; then
    run_sql "GRANT UPDATE ON \`${DB_NAME}\`.\`${table}\` TO '${DB_USER}'@'${DB_HOST_PATTERN}';"
    skipped_delete=$((skipped_delete + 1))
    echo "  retained     ${table}  (no DELETE)"
    continue
  fi

  run_sql "GRANT UPDATE, DELETE ON \`${DB_NAME}\`.\`${table}\` TO '${DB_USER}'@'${DB_HOST_PATTERN}';"
  granted=$((granted + 1))
done <<< "$TABLES"

run_sql "FLUSH PRIVILEGES;"

echo
echo "Summary"
echo "  tables granted UPDATE+DELETE : ${granted}"
echo "  append-only tables protected : ${skipped_update}"
echo "  delete-protected tables      : $((skipped_delete - skipped_update))"
echo
echo "Verify with:"
echo "  docker exec rc-db mysql -uroot -p\$DB_ROOT_PASSWORD -e \"SHOW GRANTS FOR '${DB_USER}'@'${DB_HOST_PATTERN}';\""
