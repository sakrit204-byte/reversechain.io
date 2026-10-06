#!/usr/bin/env bash
# Shared helpers for backup.sh / restore.sh / rollback.sh. Sourced, not executed.
#
# Context resolution (explicit environment variable > value in the env file > default):
#   RC_HOME          directory of the environment on the server (default: directory of the calling script)
#   ENV_FILE         compose .env of the environment (default: $RC_HOME/.env if present; set to "" for none)
#   COMPOSE_FILE     compose file (default: $RC_HOME/docker-compose.prod.yml)
#   COMPOSE_PROJECT  compose project name (default: rc-$RC_ENV)
#   COMPOSE_OVERRIDE optional extra compose file (e.g. publish a port for a local restore drill)
#   RC_ENV           environment name (from ENV_FILE, else "development")
#   BACKUP_*         see deploy/env/*.env.example

set -o errexit -o nounset -o pipefail -o errtrace

rc_log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
rc_die() { rc_log "ERROR: $*" >&2; exit 1; }

# rc_env_get FILE KEY — print the value of KEY from a dotenv file without evaluating it (no shell expansion).
rc_env_get() {
	local file="$1" key="$2" line val
	[ -n "$file" ] && [ -r "$file" ] || return 0
	line="$(grep -E "^[[:space:]]*${key}=" "$file" | tail -n 1 || true)"
	[ -n "$line" ] || return 0
	val="${line#*=}"
	val="${val%$'\r'}"
	case "$val" in
		\'*\') val="${val#\'}"; val="${val%\'}" ;;
		\"*\") val="${val#\"}"; val="${val%\"}" ;;
		*) val="${val%%[[:space:]]#*}"; val="${val%"${val##*[![:space:]]}"}" ;;
	esac
	printf '%s' "$val"
}

# rc_setting KEY DEFAULT — environment variable if set, else env-file value, else default.
rc_setting() {
	local key="$1" def="${2-}" v
	if [ -n "${!key+x}" ]; then printf '%s' "${!key}"; return; fi
	v="$(rc_env_get "${ENV_FILE:-}" "$key")"
	if [ -n "$v" ]; then printf '%s' "$v"; else printf '%s' "$def"; fi
}

rc_load_context() {
	local caller_dir="$1"
	RC_HOME="${RC_HOME:-$caller_dir}"
	if [ -z "${ENV_FILE+x}" ]; then
		if [ -f "$RC_HOME/.env" ]; then ENV_FILE="$RC_HOME/.env"; else ENV_FILE=""; fi
	fi
	COMPOSE_FILE="${COMPOSE_FILE:-$RC_HOME/docker-compose.prod.yml}"
	[ -f "$COMPOSE_FILE" ] || rc_die "compose file not found: $COMPOSE_FILE"
	RC_ENV="$(rc_setting RC_ENV development)"
	COMPOSE_PROJECT="${COMPOSE_PROJECT:-rc-$RC_ENV}"

	# Git Bash on Windows (local testing): give docker native paths and stop MSYS path mangling.
	if command -v cygpath >/dev/null 2>&1; then
		COMPOSE_FILE_NATIVE="$(cygpath -m "$COMPOSE_FILE")"
		COMPOSE_OVERRIDE_NATIVE="${COMPOSE_OVERRIDE:+$(cygpath -m "${COMPOSE_OVERRIDE:-}")}"
		ENV_FILE_NATIVE="${ENV_FILE:+$(cygpath -m "$ENV_FILE")}"
		export MSYS_NO_PATHCONV=1
	else
		COMPOSE_FILE_NATIVE="$COMPOSE_FILE"
		COMPOSE_OVERRIDE_NATIVE="${COMPOSE_OVERRIDE:-}"
		ENV_FILE_NATIVE="$ENV_FILE"
	fi

	if docker info >/dev/null 2>&1; then DOCKER="docker"; else DOCKER="sudo docker"; fi
	export RC_HOME ENV_FILE COMPOSE_FILE COMPOSE_PROJECT RC_ENV DOCKER
}

# dc … — docker compose bound to the selected environment.
dc() {
	local args=(-p "$COMPOSE_PROJECT" -f "$COMPOSE_FILE_NATIVE")
	if [ -n "${COMPOSE_OVERRIDE_NATIVE:-}" ]; then args+=(-f "$COMPOSE_OVERRIDE_NATIVE"); fi
	if [ -n "${ENV_FILE_NATIVE:-}" ]; then args+=(--env-file "$ENV_FILE_NATIVE"); fi
	$DOCKER compose "${args[@]}" "$@"
}

# wpc … — WP-CLI inside the environment (tools profile container).
wpc() { dc run --rm -T wpcli wp "$@"; }

# sqlq "SQL" — run a query as MySQL root inside the db container; tab-separated output, no headers.
sqlq() {
	dc exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -h 127.0.0.1 -N -B "$MYSQL_DATABASE" -e "$1"' sh "$1"
}

# rc_native PATH � path as understood by native (non-MSYS) tools; identity on Linux.
rc_native() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi; }

rc_sha256() { sha256sum "$1" | awk '{print $1}'; }
