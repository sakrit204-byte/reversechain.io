#!/usr/bin/env bash
# Restore an encrypted ReserveChain backup (made by backup.sh) into an environment, then PROVE integrity:
#   1. audit immutability triggers exist straight after the SQL import (before WordPress boots),
#   2. `wp rc tamper-test` (UPDATE/DELETE on the audit log are rejected by the database),
#   3. `wp rc audit-verify` (full hash-chain verification),
#   4. the site answers HTTP 200 and /wp-json/rc/v1/health responds.
#
# Usage (on the server, inside ~/reservechain/<env>):
#   ./restore.sh --archive ~/reservechain-backups/staging/daily/rc-production-20261006T023000Z.tar.age \
#                [--url https://staging.example.com] [--skip-uploads] [--restore-env] [--restore-wp-config] \
#                [--no-pre-backup] [--i-know] [--yes]
#
#   --url URL            rewrite the site URL (search-replace; the audit tables are never touched)
#   --restore-env        also restore the archived .env over ENV_FILE (DR rebuild on EMPTY volumes only: the DB
#                        passwords inside must match the database volume)
#   --restore-wp-config  also restore wp-config.php (legacy installs whose salts are not in .env)
#   --i-know             required when the target is production
#   --no-pre-backup      production only: skip the automatic safety backup taken before overwriting
#   --yes                do not ask for confirmation
#
# Decryption keys (never stored in the backup):
#   .age  -> BACKUP_AGE_IDENTITY=/path/to/rc-backup.key  (the offline age private key)
#   .enc  -> BACKUP_PASSPHRASE_FILE=/path/to/passphrase
# Target overrides (local testing): COMPOSE_FILE, COMPOSE_PROJECT, ENV_FILE, RC_HOME.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/ops-common.sh
. "$SCRIPT_DIR/ops-common.sh"

ARCHIVE="" URL="" SKIP_UPLOADS=0 RESTORE_ENV=0 RESTORE_WPCONFIG=0 I_KNOW=0 PRE_BACKUP=1 YES=0
while [ $# -gt 0 ]; do
	case "$1" in
		--archive) ARCHIVE="$2"; shift 2 ;;
		--url) URL="${2%/}"; shift 2 ;;
		--skip-uploads) SKIP_UPLOADS=1; shift ;;
		--restore-env) RESTORE_ENV=1; shift ;;
		--restore-wp-config) RESTORE_WPCONFIG=1; shift ;;
		--i-know) I_KNOW=1; shift ;;
		--no-pre-backup) PRE_BACKUP=0; shift ;;
		--yes|-y) YES=1; shift ;;
		-h|--help) sed -n '2,30p' "$0"; exit 0 ;;
		*) rc_die "unknown argument: $1" ;;
	esac
done
[ -n "$ARCHIVE" ] && [ -r "$ARCHIVE" ] || rc_die "--archive <file> is required and must be readable"

rc_load_context "$SCRIPT_DIR"
BACKUP_AGE_IDENTITY="$(rc_setting BACKUP_AGE_IDENTITY "")"
BACKUP_PASSPHRASE_FILE="$(rc_setting BACKUP_PASSPHRASE_FILE "")"

if [ "$RC_ENV" = "production" ] && [ "$I_KNOW" != "1" ]; then
	rc_die "target is PRODUCTION (project $COMPOSE_PROJECT). Re-run with --i-know if this is really intended."
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/rc-restore.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
umask 077

# --- 1. integrity of the ciphertext + decrypt ---
if [ -r "$ARCHIVE.sha256" ]; then
	[ "$(rc_sha256 "$ARCHIVE")" = "$(awk '{print $1}' "$ARCHIVE.sha256")" ] || rc_die "ciphertext SHA-256 does not match $ARCHIVE.sha256"
	rc_log "ciphertext SHA-256 matches sidecar"
fi
case "$ARCHIVE" in
	*.age)
		[ -n "$BACKUP_AGE_IDENTITY" ] && [ -r "$BACKUP_AGE_IDENTITY" ] || rc_die "set BACKUP_AGE_IDENTITY to the age private key file"
		age -d -i "$(rc_native "$BACKUP_AGE_IDENTITY")" "$(rc_native "$ARCHIVE")" | tar -C "$WORK" -xf - ;;
	*.enc)
		[ -n "$BACKUP_PASSPHRASE_FILE" ] && [ -r "$BACKUP_PASSPHRASE_FILE" ] || rc_die "set BACKUP_PASSPHRASE_FILE to the passphrase file"
		openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256 -pass "file:$(rc_native "$BACKUP_PASSPHRASE_FILE")" -in "$(rc_native "$ARCHIVE")" | tar -C "$WORK" -xf - ;;
	*) rc_die "unknown archive type (expected .tar.age or .tar.enc)" ;;
esac
P="$(find "$WORK" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
[ -f "$P/SHA256SUMS" ] || rc_die "archive payload missing SHA256SUMS"
(cd "$P" && sha256sum -c --quiet SHA256SUMS) || rc_die "payload checksum mismatch"
rc_log "decrypted and verified payload: $(basename "$P")"
sed 's/^/    /' "$P/MANIFEST"
SRC_PREFIX="$(grep '^table_prefix=' "$P/MANIFEST" | cut -d= -f2)"
SRC_PREFIX="${SRC_PREFIX:-wp_}"

echo
rc_log "TARGET: env=$RC_ENV project=$COMPOSE_PROJECT compose=$COMPOSE_FILE"
rc_log "The target database will be DROPPED and replaced (uploads too unless --skip-uploads)."
if [ "$YES" != "1" ]; then
	read -r -p "Type the target environment name ($RC_ENV) to continue: " answer
	[ "$answer" = "$RC_ENV" ] || rc_die "aborted"
fi

# --- 2. optional .env restore (before containers start so they pick it up) ---
if [ "$RESTORE_ENV" = "1" ]; then
	[ -s "$P/env.dotenv" ] || rc_die "archive contains no .env"
	[ -n "$ENV_FILE" ] || rc_die "ENV_FILE is not set for this target"
	[ -f "$ENV_FILE" ] && cp -p "$ENV_FILE" "$ENV_FILE.pre-restore-$(date -u +%Y%m%dT%H%M%SZ)"
	cp "$P/env.dotenv" "$ENV_FILE"
	chmod 600 "$ENV_FILE"
	rc_log "restored .env -> $ENV_FILE (previous copy kept alongside)"
	rc_load_context "$SCRIPT_DIR"
fi

# --- 3. bring the stack up and wait for MySQL on TCP (not the init-time socket server) ---
dc up -d db wordpress >/dev/null
rc_log "waiting for MySQL..."
for i in $(seq 1 90); do
	if dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -h 127.0.0.1 -e "SELECT 1" >/dev/null 2>&1'; then break; fi
	[ "$i" = 90 ] && rc_die "MySQL did not become ready"
	sleep 2
done

# --- 4. safety backup of production before overwriting it ---
if [ "$RC_ENV" = "production" ] && [ "$PRE_BACKUP" = "1" ]; then
	rc_log "taking a safety backup of production first"
	BACKUP_MARK=0 "$SCRIPT_DIR/backup.sh" || rc_die "safety backup failed; use --no-pre-backup to override"
fi

# --- 5. database ---
rc_log "recreating database and importing dump"
dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -h 127.0.0.1 -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
# DEFINER clauses are stripped so triggers/routines are owned by the importing account on any target server.
# AUTO_INCREMENT=N table options are stripped: mysqldump writes the LIVE counter (read after the consistent
# snapshot), so the first post-restore audit entry would skip ids and fail the chain's gap check. Without it
# InnoDB recomputes the counter from MAX(id)+1.
gzip -dc "$P/db.sql.gz" \
	| sed -E -e 's#/\*!50017 DEFINER=[^*]*\*/##g' -e 's/DEFINER=`[^`]*`@`[^`]*`//g' -e '/^\) ENGINE=/s/ AUTO_INCREMENT=[0-9]+//' \
	| dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -h 127.0.0.1 --default-character-set=utf8mb4 "$MYSQL_DATABASE"'

# --- 6. triggers must exist straight from the dump ---
TRIG="$(sqlq "SELECT GROUP_CONCAT(CONCAT(TRIGGER_NAME,':',ACTION_TIMING,' ',EVENT_MANIPULATION) ORDER BY TRIGGER_NAME) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='${SRC_PREFIX}rc_audit_log'" | tr -d '\r')"
NTRIG="$(sqlq "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='${SRC_PREFIX}rc_audit_log'" | tr -d '\r')"
[ "${NTRIG:-0}" -ge 2 ] || rc_die "audit triggers MISSING after import (found ${NTRIG:-0})"
rc_log "triggers present after import: $TRIG"

# --- 7. uploads / config files ---
for i in $(seq 1 60); do dc exec -T wordpress test -f /var/www/html/wp-includes/version.php && break; sleep 2; done
if [ "$SKIP_UPLOADS" != "1" ]; then
	rc_log "restoring uploads"
	dc exec -T -u 0 wordpress sh -c 'cd /var/www/html/wp-content && rm -rf uploads.pre-restore && { [ ! -d uploads ] || mv uploads uploads.pre-restore; } && tar -xzf - && chown -R www-data:www-data uploads && rm -rf uploads.pre-restore' < "$P/uploads.tar.gz"
fi
if [ "$RESTORE_WPCONFIG" = "1" ] && [ -s "$P/wp-config.php" ]; then
	dc exec -T -u 0 wordpress sh -c 'cat > /var/www/html/wp-config.php && chown www-data:www-data /var/www/html/wp-config.php' < "$P/wp-config.php"
	rc_log "restored wp-config.php"
fi

# --- 8. URL rewrite (never touches the audit tables) ---
OLD_URL="$(wpc option get home 2>/dev/null | tr -d '\r' || true)"
if [ -n "$URL" ] && [ -n "$OLD_URL" ] && [ "$URL" != "$OLD_URL" ]; then
	rc_log "rewriting URL $OLD_URL -> $URL"
	wpc search-replace "$OLD_URL" "$URL" --skip-tables="${SRC_PREFIX}rc_audit_log,${SRC_PREFIX}rc_audit_anchor" --skip-columns=guid --report-changed-only
fi
wpc cache flush >/dev/null 2>&1 || true

# --- 9. proofs ---
FAIL=0
echo
rc_log "== tamper-test =="
wpc rc tamper-test || FAIL=1
rc_log "== audit-verify =="
wpc rc audit-verify || FAIL=1
SITE_URL="$(wpc option get home | tr -d '\r')"
HOST="$(echo "$SITE_URL" | sed -E 's#^[a-z]+://([^/]+).*#\1#')"
PROTO="$(echo "$SITE_URL" | sed -E 's#^([a-z]+)://.*#\1#')"
CODE="$(dc exec -T wordpress curl -s -o /dev/null -w '%{http_code}' -H "Host: $HOST" -H "X-Forwarded-Proto: $PROTO" http://localhost/ || true)"
HEALTH="$(dc exec -T wordpress curl -s -H "Host: $HOST" -H "X-Forwarded-Proto: $PROTO" http://localhost/wp-json/rc/v1/health || true)"
rc_log "== HTTP == home ($SITE_URL) -> $CODE"
rc_log "== health == $HEALTH"
[ "$CODE" = "200" ] || FAIL=1

echo
if [ "$FAIL" = "0" ]; then
	rc_log "RESTORE OK: env=$RC_ENV project=$COMPOSE_PROJECT triggers=$NTRIG chain verified, site HTTP 200"
else
	rc_die "RESTORE COMPLETED WITH FAILURES (see above)"
fi
