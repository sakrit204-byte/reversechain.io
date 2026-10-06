#!/usr/bin/env bash
# One-command deploy from this repo to a fresh Ubuntu server (Oracle Cloud Always Free, Hetzner, …).
# Usage: deploy/deploy.sh <ssh-user>@<server-ip> [ssh-key]
#  - installs Docker (first run), opens 80/443 in the host firewall
#  - syncs theme + plugin, writes .env with generated secrets (first run only)
#  - starts the stack, installs WordPress, activates theme/plugin, seeds demo data
set -euo pipefail
TARGET="$1"; KEY="${2:-$HOME/.ssh/reservechain_deploy}"
SSH="ssh -i $KEY -o StrictHostKeyChecking=accept-new $TARGET"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
IP="${TARGET#*@}"

echo "==> Preparing server $IP"
$SSH 'bash -s' <<'REMOTE'
set -e
if ! command -v docker >/dev/null; then
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"
fi
# Oracle Ubuntu images ship restrictive iptables rules: allow HTTP/HTTPS.
if sudo iptables -L INPUT -n | grep -q "REJECT"; then
  sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT || true
  sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT || true
  sudo sh -c 'command -v netfilter-persistent >/dev/null && netfilter-persistent save' || true
fi
# Small instances: add swap for MySQL.
if [ ! -f /swapfile ] && [ "$(free -m | awk '/Mem/{print $2}')" -lt 2500 ]; then
  sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
fi
mkdir -p ~/reservechain/app/wordpress/themes ~/reservechain/app/wordpress/plugins
REMOTE

echo "==> Syncing code"
tar -C "$ROOT" -czf - wordpress/themes/reservechain wordpress/plugins/reservechain-core | $SSH 'rm -rf ~/reservechain/app/wordpress/themes/reservechain ~/reservechain/app/wordpress/plugins/reservechain-core && tar -C ~/reservechain/app -xzf -'
for f in docker-compose.prod.yml Caddyfile php-uploads.ini; do $SSH "cat > ~/reservechain/$f" < "$ROOT/deploy/$f"; done

echo "==> Environment"
$SSH "test -f ~/reservechain/.env || { R() { tr -dc A-Za-z0-9 </dev/urandom | head -c \$1; }; cat > ~/reservechain/.env <<ENV
SITE_HOST=${SITE_HOST:-$(echo $IP | tr . -).sslip.io}
RC_ENV=${RC_ENV:-staging}
DB_PASSWORD=\$(R 32)
DB_ROOT_PASSWORD=\$(R 32)
RC_TOKEN_SECRET=\$(R 64)
RC_DEMO_PASSWORD=\${RC_DEMO_PASSWORD:-ReserveChain-Demo-2026}
ADMIN_USER=rcadmin
ADMIN_PASSWORD=\$(R 20)
ADMIN_EMAIL=${ADMIN_EMAIL:-admin@reservechain.invalid}
ENV
chmod 600 ~/reservechain/.env; }"

echo "==> Starting stack"
$SSH 'cd ~/reservechain && sudo docker compose -f docker-compose.prod.yml --env-file .env up -d && sudo docker compose -f docker-compose.prod.yml --env-file .env pull wpcli -q'

echo "==> Installing / seeding"
$SSH 'bash -s' <<'REMOTE'
set -e
cd ~/reservechain; set -a; . ./.env; set +a
W="sudo docker compose -f docker-compose.prod.yml --env-file .env run --rm wpcli wp"
for i in $(seq 1 60); do $W core is-installed >/dev/null 2>&1 && break; $W db check >/dev/null 2>&1 && break; sleep 5; done
if ! $W core is-installed >/dev/null 2>&1; then
  $W core install --url="https://$SITE_HOST" --title=ReserveChain --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWORD" --admin_email="$ADMIN_EMAIL" --skip-email
  $W rewrite structure '/%postname%/' --hard
  $W theme activate reservechain
  $W plugin activate reservechain-core
  $W rc seed
else
  $W eval 'RC\Install::upgrade();'
  $W rc seed --pages-only
fi
$W rc tamper-test || true
echo "Site: https://$SITE_HOST   Admin user: $ADMIN_USER (password in ~/reservechain/.env)"
REMOTE
