#!/usr/bin/env bash
# Encrypted backup of one ReserveChain environment. Runs on the server from cron (installed by deploy.sh):
#   30 2 * * *  cd ~/reservechain/production && ./backup.sh >> logs/backup.log 2>&1
#
# Contents (one encrypted tar):  db.sql.gz       mysqldump --single-transaction --routines --triggers --events
#                                uploads.tar.gz  wp-content/uploads
#                                env.dotenv      the environment's .env (secrets - hence the whole archive is encrypted)
#                                wp-config.php   generated config (salts for legacy installs)
#                                MANIFEST, SHA256SUMS
# Encryption: age public key (BACKUP_AGE_RECIPIENT, preferred: authenticated, the server cannot decrypt) or
#             openssl AES-256-CBC + PBKDF2 (600k iterations) with BACKUP_PASSPHRASE_FILE (kept outside the backup).
#             `openssl enc` does not support AEAD modes such as GCM; integrity is covered by SHA256SUMS inside
#             the archive and the .sha256 sidecar of the ciphertext.
# Retention:  BACKUP_KEEP_DAILY (7) / BACKUP_KEEP_WEEKLY (4) / BACKUP_KEEP_MONTHLY (6) under BACKUP_DIR.
# Off-site:   rclone copy to $RCLONE_REMOTE/<env>/<tier>/ when RCLONE_REMOTE is set (R2 / B2 / any S3-compatible).
# Marker:     `wp rc-ops backup-mark ...` (monitoring alerts when the last success is older than 26 h).
#
# Overrides for local testing (see docs/BACKUP-DR.md): COMPOSE_FILE, COMPOSE_PROJECT, ENV_FILE, RC_HOME,
# BACKUP_DIR, BACKUP_PASSPHRASE_FILE, BACKUP_AGE_RECIPIENT, BACKUP_MARK=0 (skip the WP marker).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/ops-common.sh
. "$SCRIPT_DIR/ops-common.sh"
rc_load_context "$SCRIPT_DIR"

BACKUP_DIR="$(rc_setting BACKUP_DIR "$HOME/reservechain-backups/$RC_ENV")"
BACKUP_AGE_RECIPIENT="$(rc_setting BACKUP_AGE_RECIPIENT "")"
BACKUP_PASSPHRASE_FILE="$(rc_setting BACKUP_PASSPHRASE_FILE "")"
KEEP_DAILY="$(rc_setting BACKUP_KEEP_DAILY 7)"
KEEP_WEEKLY="$(rc_setting BACKUP_KEEP_WEEKLY 4)"
KEEP_MONTHLY="$(rc_setting BACKUP_KEEP_MONTHLY 6)"
RCLONE_REMOTE="$(rc_setting RCLONE_REMOTE "")"
BACKUP_MARK="${BACKUP_MARK:-1}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK=""
cleanup() { if [ -n "$WORK" ]; then rm -rf "$WORK"; fi; }
fail() {
	rc_log "ERROR: $1"
	if [ "$BACKUP_MARK" = "1" ]; then wpc rc-ops backup-mark --failed --message="$1" >/dev/null 2>&1 || true; fi
	cleanup
	exit 1
}
trap 'fail "backup failed at line $LINENO"' ERR
trap cleanup EXIT

# --- encryption setup (refuse to write an unencrypted backup) ---
if [ -n "$BACKUP_AGE_RECIPIENT" ]; then
	command -v age >/dev/null || fail "BACKUP_AGE_RECIPIENT set but 'age' is not installed (sudo apt-get install -y age)"
	EXT="age"
	encrypt() { age -r "$BACKUP_AGE_RECIPIENT" -o "$(rc_native "$1")"; }
elif [ -n "$BACKUP_PASSPHRASE_FILE" ] && [ -r "$BACKUP_PASSPHRASE_FILE" ]; then
	command -v openssl >/dev/null || fail "openssl not installed"
	[ "$(wc -c < "$BACKUP_PASSPHRASE_FILE")" -ge 32 ] || fail "passphrase file too short (>= 32 chars required)"
	EXT="enc"
	encrypt() { openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -md sha256 -salt -pass "file:$(rc_native "$BACKUP_PASSPHRASE_FILE")" -out "$(rc_native "$1")"; }
else
	fail "no encryption key: set BACKUP_AGE_RECIPIENT or a readable BACKUP_PASSPHRASE_FILE"
fi

umask 077
mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly" "$BACKUP_DIR/monthly"
BACKUP_DIR="$(cd "$BACKUP_DIR" && pwd)"
if [ -n "$BACKUP_PASSPHRASE_FILE" ]; then
	case "$(cd "$(dirname "$BACKUP_PASSPHRASE_FILE")" && pwd)/" in
		"$BACKUP_DIR"/*) fail "the passphrase file must not live inside BACKUP_DIR" ;;
	esac
fi
if command -v flock >/dev/null 2>&1; then
	exec 9>"$BACKUP_DIR/.lock"
	flock -n 9 || fail "another backup is running"
fi
WORK="$(mktemp -d "${TMPDIR:-/tmp}/rc-backup.XXXXXX")"
P="$WORK/rc-$RC_ENV-$STAMP"
mkdir -p "$P"

rc_log "Backup of '$RC_ENV' (compose project $COMPOSE_PROJECT) -> $BACKUP_DIR"

# --- database ---
rc_log "mysqldump (single-transaction, routines, triggers, events)"
dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump -uroot -h 127.0.0.1 --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "$MYSQL_DATABASE"' | gzip -6 > "$P/db.sql.gz"
gzip -dc "$P/db.sql.gz" | tail -n 1 | grep -q 'Dump completed' || fail "dump is incomplete"
TRIGGERS="$(gzip -dc "$P/db.sql.gz" | grep -cE 'TRIGGER `[^`]*rc_audit_no_(update|delete)`' || true)"
[ "${TRIGGERS:-0}" -ge 2 ] || fail "audit immutability triggers missing from dump (found ${TRIGGERS:-0}); refusing to produce a backup that would restore without them"
PREFIX="$(sqlq "SELECT SUBSTRING(table_name,1,LENGTH(table_name)-12) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%rc\\_audit\\_log' LIMIT 1" | tr -d '\r')"
HEAD="$(sqlq "SELECT CONCAT(id,' ',row_hash) FROM \`${PREFIX}rc_audit_log\` ORDER BY id DESC LIMIT 1" | tr -d '\r' || true)"

# --- files ---
rc_log "uploads + config"
dc exec -T wordpress sh -c 'cd /var/www/html/wp-content && if [ -d uploads ]; then tar -czf - uploads; else mkdir -p /tmp/rc-empty/uploads && tar -C /tmp/rc-empty -czf - uploads; fi' > "$P/uploads.tar.gz"
gzip -t "$P/uploads.tar.gz"
dc exec -T wordpress cat /var/www/html/wp-config.php > "$P/wp-config.php" || : > "$P/wp-config.php"
if [ -n "$ENV_FILE" ] && [ -r "$ENV_FILE" ]; then cp "$ENV_FILE" "$P/env.dotenv"; else : > "$P/env.dotenv"; fi

cat > "$P/MANIFEST" <<MAN
format=reservechain-backup/1
env=$RC_ENV
created_utc=$STAMP
source_host=$(hostname)
compose_project=$COMPOSE_PROJECT
table_prefix=$PREFIX
audit_head=${HEAD:-none}
audit_triggers_in_dump=$TRIGGERS
encryption=$EXT
MAN
(cd "$P" && sha256sum db.sql.gz uploads.tar.gz env.dotenv wp-config.php MANIFEST > SHA256SUMS)

# --- encrypt ---
NAME="rc-$RC_ENV-$STAMP.tar.$EXT"
OUT="$BACKUP_DIR/daily/$NAME"
rc_log "encrypting ($EXT) -> $OUT"
tar -C "$WORK" -cf - "rc-$RC_ENV-$STAMP" | encrypt "$OUT.partial"
mv "$OUT.partial" "$OUT"
SUM="$(rc_sha256 "$OUT")"
echo "$SUM  $NAME" > "$OUT.sha256"
SIZE="$(wc -c < "$OUT" | tr -d ' ')"
if [ "$EXT" = "enc" ]; then
	# Self-test: the archive decrypts and lists with the configured passphrase.
	openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -md sha256 -pass "file:$(rc_native "$BACKUP_PASSPHRASE_FILE")" -in "$(rc_native "$OUT")" | tar -tf - >/dev/null
fi
rm -rf "$WORK"
WORK=""

# --- retention: promote to weekly / monthly, then prune ---
list_archives() { find "$1" -maxdepth 1 -type f -name 'rc-*.tar.*' ! -name '*.sha256' ! -name '*.partial' | sed 's#.*/##' | sort; }
PROMOTED=""
promote() {
	ln "$OUT" "$1/$NAME" 2>/dev/null || cp -p "$OUT" "$1/$NAME"
	cp -p "$OUT.sha256" "$1/$NAME.sha256"
	PROMOTED="$PROMOTED $(basename "$1")"
}
LAST_W="$(list_archives "$BACKUP_DIR/weekly" | tail -n 1)"
if [ -z "$LAST_W" ]; then
	promote "$BACKUP_DIR/weekly"
else
	LW_TS="$(echo "$LAST_W" | sed -E 's/.*-([0-9]{4})([0-9]{2})([0-9]{2})T([0-9]{2})([0-9]{2})([0-9]{2})Z.*/\1-\2-\3 \4:\5:\6/')"
	if [ $(( $(date -u +%s) - $(date -u -d "$LW_TS" +%s) )) -ge $(( 6 * 86400 + 12 * 3600 )) ]; then promote "$BACKUP_DIR/weekly"; fi
fi
if ! list_archives "$BACKUP_DIR/monthly" | grep -q -- "-$(date -u +%Y%m)[0-9][0-9]T"; then promote "$BACKUP_DIR/monthly"; fi
prune() {
	local dir="$1" keep="$2" f
	list_archives "$dir" | sort -r | tail -n +"$((keep + 1))" | while read -r f; do
		rm -f "$dir/$f" "$dir/$f.sha256"
		rc_log "pruned $(basename "$dir")/$f"
	done
}
prune "$BACKUP_DIR/daily" "$KEEP_DAILY"
prune "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"
prune "$BACKUP_DIR/monthly" "$KEEP_MONTHLY"

# --- off-site ---
OFFSITE=""
if [ -n "$RCLONE_REMOTE" ]; then
	command -v rclone >/dev/null || fail "RCLONE_REMOTE set but rclone is not installed"
	rc_log "off-site copy -> $RCLONE_REMOTE/$RC_ENV/{daily$PROMOTED}"
	for tier in daily $PROMOTED; do
		rclone copy --no-traverse "$OUT" "$RCLONE_REMOTE/$RC_ENV/$tier/" || fail "off-site copy failed ($tier)"
		rclone copy --no-traverse "$OUT.sha256" "$RCLONE_REMOTE/$RC_ENV/$tier/" || fail "off-site copy failed ($tier sidecar)"
	done
	rclone check --one-way --size-only --include "$NAME" "$BACKUP_DIR/daily" "$RCLONE_REMOTE/$RC_ENV/daily/" >/dev/null 2>&1 || fail "off-site copy could not be verified"
	OFFSITE="--offsite"
fi

printf '{"file":"%s","sha256":"%s","size":%s,"at":"%s","offsite":%s}\n' "$NAME" "$SUM" "$SIZE" "$STAMP" "$([ -n "$OFFSITE" ] && echo true || echo false)" > "$BACKUP_DIR/last-success.json"
if [ "$BACKUP_MARK" = "1" ]; then
	wpc rc-ops backup-mark --file="$NAME" --size="$SIZE" --sha256="$SUM" $OFFSITE
fi
rc_log "OK $BACKUP_DIR/daily/$NAME ($SIZE bytes, sha256 $SUM; audit triggers in dump: $TRIGGERS; audit head: ${HEAD:-none}; promoted:${PROMOTED:- none})"
