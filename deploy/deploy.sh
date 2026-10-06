#!/usr/bin/env bash
# Deploy ReserveChain to a Linux server (Oracle Cloud Always Free, Hetzner, ...). Staging and production run
# side by side on the same host, each as its own compose project with its own volumes, fronted by ONE shared
# Caddy (automatic HTTPS):
#
#   ~/reservechain/edge/         docker-compose.edge.yml, Caddyfile, sites/<env>.caddy   (project rc-edge)
#   ~/reservechain/production/   docker-compose.prod.yml, .env, app -> releases/<stamp>-<ref>, backup.sh, ...
#   ~/reservechain/staging/      same layout                                              (project rc-staging)
#
# Usage:
#   deploy/deploy.sh --env staging|production [options] <ssh-user>@<server-ip>
# Options:
#   --ref <git-ref>     deploy a tag/commit via `git archive` (default: the current working tree)
#   --key <file>        SSH key (default ~/.ssh/reservechain_deploy)
#   --host <name>       SITE_HOST for a NEW environment (default: <ip>.sslip.io / staging.<ip>.sslip.io)
#   --push-env          upload deploy/env/<env>.env over the server .env (otherwise only on first deploy)
#   --seed-demo         run the full demo seed on first install (default: staging yes, production no)
#   --skip-cron         do not (re)install the crontab entries
#   --cron-only         only (re)install the crontab entries for this environment
# Environment variables honoured on first deploy: SITE_HOST, ADMIN_EMAIL, RC_DEMO_PASSWORD.
#
# Crontab installed per environment (times are UTC, converted to the server's local zone):
#   production backup 02:30, staging backup 03:00 (backup.sh), WP-cron every 5 minutes via real cron
#   (`wp cron event run --due-now`; DISABLE_WP_CRON is set in docker-compose.prod.yml).
set -euo pipefail

ENV="" REF="" KEY="$HOME/.ssh/reservechain_deploy" HOST_OVERRIDE="${SITE_HOST:-}" PUSH_ENV=0 SEED_DEMO="" SKIP_CRON=0 CRON_ONLY=0 TARGET=""
while [ $# -gt 0 ]; do
	case "$1" in
		--env) ENV="$2"; shift 2 ;;
		--ref) REF="$2"; shift 2 ;;
		--key) KEY="$2"; shift 2 ;;
		--host) HOST_OVERRIDE="$2"; shift 2 ;;
		--push-env) PUSH_ENV=1; shift ;;
		--seed-demo) SEED_DEMO=1; shift ;;
		--skip-cron) SKIP_CRON=1; shift ;;
		--cron-only) CRON_ONLY=1; shift ;;
		-h|--help) sed -n '2,26p' "$0"; exit 0 ;;
		-*) echo "unknown option $1" >&2; exit 2 ;;
		*) TARGET="$1"; shift ;;
	esac
done
case "$ENV" in staging|production) ;; *) echo "--env staging|production is required" >&2; exit 2 ;; esac
[ -n "$TARGET" ] || { echo "usage: deploy.sh --env staging|production <user>@<server>" >&2; exit 2; }
[ -z "$SEED_DEMO" ] && { [ "$ENV" = staging ] && SEED_DEMO=1 || SEED_DEMO=0; }

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IP="${TARGET#*@}"
SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$TARGET")
DASHED="$(echo "$IP" | tr . -)"
if [ -z "$HOST_OVERRIDE" ]; then
	[ "$ENV" = production ] && HOST_OVERRIDE="$DASHED.sslip.io" || HOST_OVERRIDE="staging.$DASHED.sslip.io"
fi

# ----------------------------------------------------------------------------- crontab installer
install_cron() {
	echo "==> Installing crontab entries for $ENV"
	"${SSH[@]}" bash -s -- "$ENV" <<'REMOTE'
set -e
ENV="$1"; DIR="$HOME/reservechain/$ENV"; TAG="# rc-ops:$ENV"
mkdir -p "$DIR/logs"
if [ "$ENV" = production ]; then T="02:30"; else T="03:00"; fi
read -r BMIN BHOUR < <(date -d "$T UTC" +'%M %H')
if docker info >/dev/null 2>&1; then D="docker"; else D="sudo docker"; fi
{
	crontab -l 2>/dev/null | grep -v -F "$TAG" || true
	echo "$BMIN $BHOUR * * * cd $DIR && ./backup.sh >> $DIR/logs/backup.log 2>&1 $TAG"
	echo "*/5 * * * * cd $DIR && $D compose -p rc-$ENV -f docker-compose.prod.yml --env-file .env run --rm -T wpcli wp cron event run --due-now --quiet >> $DIR/logs/cron.log 2>&1 $TAG"
	echo "15 4 * * 0 find $DIR/logs -name '*.log' -size +20M -exec truncate -s 0 {} + $TAG"
} | crontab -
echo "crontab ($ENV): backup $T UTC (= $BHOUR:$BMIN server time), wp-cron every 5 min"
crontab -l | grep -F "$TAG"
REMOTE
}

if [ "$CRON_ONLY" = 1 ]; then install_cron; exit 0; fi

# ----------------------------------------------------------------------------- release artefact
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
if [ -n "$REF" ]; then
	echo "==> Building release from git ref $REF"
	git -C "$ROOT" archive --format=tar "$REF" wordpress/themes/reservechain wordpress/plugins/reservechain-core | gzip > "$TMP/release.tgz"
	REFNAME="$REF"
else
	echo "==> Building release from the working tree"
	tar -C "$ROOT" -czf "$TMP/release.tgz" wordpress/themes/reservechain wordpress/plugins/reservechain-core
	REFNAME="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo worktree)"
fi
REL="$STAMP-$(echo "$REFNAME" | tr -c 'A-Za-z0-9._-\n' '_')"

# ----------------------------------------------------------------------------- server preparation
echo "==> Preparing server $IP ($ENV)"
"${SSH[@]}" bash -s -- "$ENV" <<'REMOTE'
set -e
ENV="$1"
if ! command -v docker >/dev/null; then
	curl -fsSL https://get.docker.com | sudo sh
	sudo usermod -aG docker "$USER"
fi
# Oracle Ubuntu images ship restrictive iptables rules: allow HTTP/HTTPS.
if sudo iptables -L INPUT -n | grep -q "REJECT"; then
	for p in 80 443; do sudo iptables -C INPUT -m state --state NEW -p tcp --dport $p -j ACCEPT 2>/dev/null || sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport $p -j ACCEPT || true; done
	sudo sh -c 'command -v netfilter-persistent >/dev/null && netfilter-persistent save' || true
fi
# Small instances: add swap for MySQL (two stacks share the host).
if [ ! -f /swapfile ] && [ "$(free -m | awk '/Mem/{print $2}')" -lt 4000 ]; then
	sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
	echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
fi
command -v age >/dev/null || sudo apt-get install -y -qq age >/dev/null 2>&1 || echo "note: 'age' not installed; backups will use openssl"
if [ -f "$HOME/reservechain/docker-compose.prod.yml" ] && [ ! -d "$HOME/reservechain/$ENV" ]; then
	echo "LEGACY single-stack layout found in ~/reservechain (project 'reservechain')." >&2
	echo "Migrate first (docs/DEPLOYMENT-RUNBOOK.md §3.4): back it up, stop it, then restore into the new layout." >&2
	exit 3
fi
mkdir -p "$HOME/reservechain/$ENV/releases" "$HOME/reservechain/$ENV/mu-plugins" "$HOME/reservechain/$ENV/logs" "$HOME/reservechain/edge/sites"
if docker info >/dev/null 2>&1; then D="docker"; else D="sudo docker"; fi
$D network inspect rc_edge >/dev/null 2>&1 || $D network create rc_edge >/dev/null
REMOTE

echo "==> Uploading release $REL"
"${SSH[@]}" "mkdir -p ~/reservechain/$ENV/releases/$REL && tar -xzf - -C ~/reservechain/$ENV/releases/$REL" < "$TMP/release.tgz"
for f in docker-compose.prod.yml php-uploads.ini backup.sh restore.sh rollback.sh ops-common.sh; do
	"${SSH[@]}" "cat > ~/reservechain/$ENV/$f" < "$ROOT/deploy/$f"
done
"${SSH[@]}" "cat > ~/reservechain/$ENV/mu-plugins/rc-smtp.php" < "$ROOT/deploy/mu-plugins/rc-smtp.php"
"${SSH[@]}" "chmod +x ~/reservechain/$ENV/*.sh"
for f in docker-compose.edge.yml Caddyfile; do
	"${SSH[@]}" "cat > ~/reservechain/edge/$f" < "$ROOT/deploy/$f"
done

# ----------------------------------------------------------------------------- environment file
LOCAL_ENV="$ROOT/deploy/env/$ENV.env"
if [ -f "$LOCAL_ENV" ] && { [ "$PUSH_ENV" = 1 ] || ! "${SSH[@]}" "test -f ~/reservechain/$ENV/.env"; }; then
	echo "==> Uploading $LOCAL_ENV"
	"${SSH[@]}" "umask 077; cat > ~/reservechain/$ENV/.env" < "$LOCAL_ENV"
fi
echo "==> Environment ($ENV)"
"${SSH[@]}" bash -s -- "$ENV" "$HOST_OVERRIDE" "${ADMIN_EMAIL:-admin@reservechain.invalid}" "${RC_DEMO_PASSWORD:-}" <<'REMOTE'
set -e
ENV="$1"; HOSTNAME_="$2"; ADMIN_EMAIL_="$3"; DEMO_="$4"
F="$HOME/reservechain/$ENV/.env"
umask 077
R() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1" || true; }
if [ ! -f "$F" ]; then
	cat > "$F" <<ENV
RC_ENV=$ENV
SITE_HOST=$HOSTNAME_
ADMIN_USER=rcadmin
ADMIN_PASSWORD=$(R 20)
ADMIN_EMAIL=$ADMIN_EMAIL_
DB_PASSWORD=$(R 32)
DB_ROOT_PASSWORD=$(R 32)
RC_TOKEN_SECRET=$(R 64)
RC_DEMO_PASSWORD=${DEMO_:-$( [ "$ENV" = staging ] && echo ReserveChain-Demo-2026 )}
ENV
	echo "generated new $F"
fi
ensure() { grep -q "^$1=" "$F" || printf '%s=%s\n' "$1" "$2" >> "$F"; }
ensure RC_ENV "$ENV"
for k in WORDPRESS_AUTH_KEY WORDPRESS_SECURE_AUTH_KEY WORDPRESS_LOGGED_IN_KEY WORDPRESS_NONCE_KEY WORDPRESS_AUTH_SALT WORDPRESS_SECURE_AUTH_SALT WORDPRESS_LOGGED_IN_SALT WORDPRESS_NONCE_SALT; do ensure "$k" "$(R 64)"; done
ensure RC_HEALTH_TOKEN "$(R 40)"
ensure RC_FORCE_SSL_ADMIN true
for k in WORDPRESS_SMTP_HOST WORDPRESS_SMTP_USER WORDPRESS_SMTP_PASSWORD WORDPRESS_SMTP_FROM; do ensure "$k" ""; done
ensure WORDPRESS_SMTP_PORT 587
ensure WORDPRESS_SMTP_SECURE tls
ensure WORDPRESS_SMTP_FROM_NAME ReserveChain
[ "$ENV" = staging ] && ensure STAGING_BASIC_AUTH ""
ensure BACKUP_AGE_RECIPIENT ""
ensure BACKUP_PASSPHRASE_FILE "$HOME/.config/reservechain/backup-$ENV.pass"
ensure BACKUP_DIR "$HOME/reservechain-backups/$ENV"
ensure BACKUP_KEEP_DAILY 7
ensure BACKUP_KEEP_WEEKLY 4
ensure BACKUP_KEEP_MONTHLY 6
ensure RCLONE_REMOTE ""
chmod 600 "$F"
PF="$(grep '^BACKUP_PASSPHRASE_FILE=' "$F" | tail -n1 | cut -d= -f2- | tr -d "'\"")"
AGE="$(grep '^BACKUP_AGE_RECIPIENT=' "$F" | tail -n1 | cut -d= -f2- | tr -d "'\"")"
if [ -z "$AGE" ] && [ -n "$PF" ] && [ ! -f "$PF" ]; then
	mkdir -p "$(dirname "$PF")"; chmod 700 "$(dirname "$PF")"
	R 48 > "$PF"; chmod 600 "$PF"
	echo "!! generated backup passphrase $PF - copy it to your password manager NOW (backups cannot be restored without it)"
fi
REMOTE

# ----------------------------------------------------------------------------- activate release + start
echo "==> Activating release and starting $ENV stack"
"${SSH[@]}" bash -s -- "$ENV" "$REL" <<'REMOTE'
set -e
ENV="$1"; REL="$2"; DIR="$HOME/reservechain/$ENV"
cd "$DIR"
PREV="$(basename "$(readlink app 2>/dev/null || echo none)")"
ln -sfn "releases/$REL" app.next && mv -T app.next app
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) deploy $PREV -> $REL" >> releases.log
# keep the 5 newest releases (never the live one)
ls -1 releases | sort | head -n -5 | while read -r r; do [ "$r" = "$REL" ] || rm -rf "releases/$r"; done
if docker info >/dev/null 2>&1; then D="docker"; else D="sudo docker"; fi
DC="$D compose -p rc-$ENV -f docker-compose.prod.yml --env-file .env"
$DC up -d
$DC up -d --force-recreate --no-deps wordpress
$DC pull -q wpcli >/dev/null 2>&1 || true

# Caddy site block for this environment (generated from .env; never hand-edited).
val() { grep "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e "s/^'//" -e "s/'$//" -e 's/^"//' -e 's/"$//'; }
HOST="$(val SITE_HOST)"; AUTH="$(val STAGING_BASIC_AUTH || true)"
S="$HOME/reservechain/edge/sites/$ENV.caddy"
{
	echo "# generated by deploy.sh for $ENV - do not edit"
	echo "$HOST {"
	echo "	import rc_common"
	if [ "$ENV" != production ]; then
		echo '	header X-Robots-Tag "noindex, nofollow, noarchive"'
		echo '	handle /robots.txt {'
		echo '		respond "User-agent: *'
		echo 'Disallow: /'
		echo '" 200'
		echo '	}'
		if [ -n "$AUTH" ]; then
			echo '	@protected not path /wp-json/rc/v1/health /wp-json/rc/v1/health/full'
			echo '	basic_auth @protected {'
			echo "		${AUTH%%:*} ${AUTH#*:}"
			echo '	}'
		fi
	fi
	echo "	reverse_proxy wp-$ENV:80 {"
	echo '		header_up X-Forwarded-Proto https'
	echo '	}'
	echo '}'
} > "$S"
cd "$HOME/reservechain/edge"
EDGE="$D compose -p rc-edge -f docker-compose.edge.yml"
$EDGE up -d
$EDGE exec -T caddy caddy validate --config /etc/caddy/Caddyfile >/dev/null
$EDGE exec -T caddy caddy reload --config /etc/caddy/Caddyfile || $EDGE restart caddy
REMOTE

# ----------------------------------------------------------------------------- install / upgrade WordPress
echo "==> Installing / upgrading WordPress ($ENV)"
"${SSH[@]}" bash -s -- "$ENV" "$SEED_DEMO" <<'REMOTE'
set -e
ENV="$1"; SEED_DEMO="$2"; cd "$HOME/reservechain/$ENV"
val() { grep "^$1=" .env | tail -n1 | cut -d= -f2- | sed -e "s/^'//" -e "s/'$//" -e 's/^"//' -e 's/"$//'; }
if docker info >/dev/null 2>&1; then D="docker"; else D="sudo docker"; fi
W="$D compose -p rc-$ENV -f docker-compose.prod.yml --env-file .env run --rm -T wpcli wp"
for i in $(seq 1 60); do $W core is-installed >/dev/null 2>&1 && break; $W db check >/dev/null 2>&1 && break; sleep 5; done
if ! $W core is-installed >/dev/null 2>&1; then
	$W core install --url="https://$(val SITE_HOST)" --title=ReserveChain --admin_user="$(val ADMIN_USER)" --admin_password="$(val ADMIN_PASSWORD)" --admin_email="$(val ADMIN_EMAIL)" --skip-email
	$W rewrite structure '/%postname%/' --hard
	$W theme activate reservechain
	$W plugin activate reservechain-core
	if [ "$SEED_DEMO" = 1 ]; then $W rc seed; else $W rc seed --pages-only; fi
else
	$W eval 'RC\Install::upgrade();'
	$W rc seed --pages-only
fi
if [ "$ENV" != production ]; then $W option update blog_public 0 >/dev/null; fi
$W cron event run --due-now --quiet || true
$W rc tamper-test || true
$W rc-ops check --no-alerts || true
echo "Site: https://$(val SITE_HOST)   Admin: $(val ADMIN_USER) (password in ~/reservechain/$ENV/.env)"
echo "Health: https://$(val SITE_HOST)/wp-json/rc/v1/health   Detailed: /health/full with header X-RC-Health-Token (RC_HEALTH_TOKEN in .env)"
REMOTE

[ "$SKIP_CRON" = 1 ] || install_cron
echo "==> Done: $ENV release $REL"
