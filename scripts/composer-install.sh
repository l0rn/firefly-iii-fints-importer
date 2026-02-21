#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

check_url() {
  local url="$1"
  curl --silent --show-error --head --max-time 10 "$url" >/dev/null 2>&1
}

run_install() {
  local mode="$1"
  shift
  echo "[composer-install] Running composer install in ${mode} mode..."
  composer install --no-interaction "$@"
}

# Allow passthrough args, e.g. --no-dev
EXTRA_ARGS=("$@")

echo "[composer-install] Probing connectivity to Packagist + GitHub API..."

if check_url "https://repo.packagist.org" && check_url "https://api.github.com"; then
  run_install "current-environment" "${EXTRA_ARGS[@]}"
  exit 0
fi

echo "[composer-install] Connectivity check failed with current environment." >&2

# If proxy env vars are set, try again without them.
if env | grep -qiE '^(https?_proxy|HTTPS?_PROXY)='; then
  echo "[composer-install] Retrying connectivity check without proxy variables..."
  if HTTPS_PROXY= HTTP_PROXY= https_proxy= http_proxy= check_url "https://repo.packagist.org" \
     && HTTPS_PROXY= HTTP_PROXY= https_proxy= http_proxy= check_url "https://api.github.com"; then
    HTTPS_PROXY= HTTP_PROXY= https_proxy= http_proxy= run_install "direct-network" "${EXTRA_ARGS[@]}"
    exit 0
  fi
fi

cat >&2 <<'EOF'
[composer-install] ERROR: Could not reach both https://repo.packagist.org and https://api.github.com.

Most likely cause: outbound network/proxy policy blocks Composer dependency downloads.

Try one of these:
  1) Ensure proxy allows CONNECT to repo.packagist.org and api.github.com/github.com.
  2) Configure valid proxy variables for this shell (HTTP_PROXY/HTTPS_PROXY).
  3) If your network allows direct egress, unset proxy vars and retry:
       HTTPS_PROXY= HTTP_PROXY= https_proxy= http_proxy= composer install --no-interaction

Tip: run this script in verbose mode by passing Composer flags, e.g.:
  scripts/composer-install.sh -vvv
EOF

exit 1
