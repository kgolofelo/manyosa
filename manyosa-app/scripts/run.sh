#!/usr/bin/env bash
# Manyosa scheduled song discovery — invoked by cron.
# Logs to storage/discovery/cron.log (rotated externally if needed).

set -euo pipefail

APP_DIR="/home/kgolofelo/manyosa/manyosa-app"
LOG_DIR="${APP_DIR}/storage/discovery"
LOG_FILE="${LOG_DIR}/cron.log"

mkdir -p "${LOG_DIR}"
exec >> "${LOG_FILE}" 2>&1

echo
echo "===== $(date -Is) — discovery run starting ====="

cd "${APP_DIR}"

# Ensure PATH includes php for both interactive and cron contexts.
export PATH="/usr/local/bin:/usr/bin:/bin:${PATH}"

"${APP_DIR}/scripts/.venv/bin/python" "${APP_DIR}/scripts/discover.py"

echo "===== $(date -Is) — discovery run finished ====="
