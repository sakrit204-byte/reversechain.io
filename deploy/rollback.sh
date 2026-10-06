#!/usr/bin/env bash
# Code rollback for one environment (runs on the server inside ~/reservechain/<env>).
#
# deploy.sh keeps every release as releases/<UTC-stamp>-<git-ref>/ (last 5) and points the `app` symlink at
# the live one; the compose file mounts ./app/wordpress/... read-only. Rolling back = atomically swapping the
# symlink and recreating the WordPress container. Database state is untouched unless --with-db is given.
#
#   ./rollback.sh --list
#   ./rollback.sh --to previous                 # the release deployed before the current one
#   ./rollback.sh --to v1.2.3                   # newest kept release whose name contains the git ref/tag
#   ./rollback.sh --to 20261006T101500Z-v1.2.3  # exact release directory
#   ./rollback.sh --to previous --with-db <archive> [--i-know]   # also restore a backup (restore.sh)
#
# A ref that is no longer kept on the server is redeployed from the workstation instead:
#   deploy/deploy.sh --env <env> --ref <tag> <user>@<server>
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/ops-common.sh
. "$SCRIPT_DIR/ops-common.sh"

TO="" LIST=0 DB_ARCHIVE="" PASS=()
while [ $# -gt 0 ]; do
	case "$1" in
		--list) LIST=1; shift ;;
		--to) TO="$2"; shift 2 ;;
		--with-db) DB_ARCHIVE="$2"; shift 2 ;;
		--i-know|--yes|-y|--no-pre-backup) PASS+=("$1"); shift ;;
		-h|--help) sed -n '2,18p' "$0"; exit 0 ;;
		*) rc_die "unknown argument: $1" ;;
	esac
done

rc_load_context "$SCRIPT_DIR"
REL="$RC_HOME/releases"
[ -d "$REL" ] || rc_die "no releases directory at $REL"
CURRENT="$(basename "$(readlink "$RC_HOME/app" 2>/dev/null || echo none)")"
mapfile -t ALL < <(find "$REL" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)

if [ "$LIST" = "1" ] || [ -z "$TO" ]; then
	echo "Releases for $RC_ENV (oldest first):"
	for r in "${ALL[@]}"; do
		if [ "$r" = "$CURRENT" ]; then echo "  * $r   (live)"; else echo "    $r"; fi
	done
	[ -n "$TO" ] || exit 0
fi

TARGET=""
if [ "$TO" = "previous" ]; then
	for r in "${ALL[@]}"; do
		[ "$r" = "$CURRENT" ] && break
		TARGET="$r"
	done
elif [ -d "$REL/$TO" ]; then
	TARGET="$TO"
else
	for r in "${ALL[@]}"; do
		case "$r" in *-"$TO") TARGET="$r" ;; esac
	done
fi
[ -n "$TARGET" ] || rc_die "no kept release matches '$TO'. Redeploy it: deploy/deploy.sh --env $RC_ENV --ref $TO <user>@<server>"
[ "$TARGET" != "$CURRENT" ] || rc_die "'$TARGET' is already live"

rc_log "rolling back $RC_ENV: $CURRENT -> $TARGET"
ln -sfn "releases/$TARGET" "$RC_HOME/app.next"
mv -T "$RC_HOME/app.next" "$RC_HOME/app"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) rollback $CURRENT -> $TARGET" >> "$RC_HOME/releases.log"
dc up -d --force-recreate wordpress >/dev/null
wpc eval 'RC\Install::upgrade(); echo "migrations re-applied\n";' || true

if [ -n "$DB_ARCHIVE" ]; then
	rc_log "restoring database from $DB_ARCHIVE"
	"$SCRIPT_DIR/restore.sh" --archive "$DB_ARCHIVE" "${PASS[@]}"
fi

wpc rc tamper-test
wpc rc audit-verify
if wpc rc-ops health --strict >/dev/null 2>&1; then rc_log "health: ok"; else rc_log "health: degraded - inspect with: wp rc-ops health"; fi
rc_log "ROLLBACK OK: $RC_ENV now serves $TARGET"
