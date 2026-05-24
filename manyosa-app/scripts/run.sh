#!/usr/bin/env bash
# Manyosa scheduled song discovery — invoked by cron and by the UI.
#
# Usage: run.sh [source]
#   source = "cron-auto"  -> respects daily quota (2/day) + minimum spacing
#   source = "manual"     -> from the UI button; bypasses quota/spacing
#   source = "cli"        -> default; bypasses quota/spacing
#
# Mutex via flock — only one run at a time. Every invocation is recorded in
# the discovery_runs table so the UI and catch-up logic have visibility.
# All DB operations are delegated to scripts/run_db.py (stdlib sqlite3).

set -uo pipefail

APP_DIR="/home/kgolofelo/manyosa/manyosa-app"
LOG_DIR="${APP_DIR}/storage/discovery"
LOG_FILE="${LOG_DIR}/cron.log"
LOCK_FILE="/tmp/manyosa-discover.lock"
PY="${APP_DIR}/scripts/.venv/bin/python"
DBPY="${APP_DIR}/scripts/run_db.py"

SOURCE="${1:-cli}"

mkdir -p "${LOG_DIR}"
exec >> "${LOG_FILE}" 2>&1
export PATH="/usr/local/bin:/usr/bin:/bin:${PATH}"

log() { echo "[$(date -Is)] $*"; }

# ----- mutex --------------------------------------------------------------
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    log "(${SOURCE}) another run holds the lock; exiting"
    exit 0
fi

# ----- catch-up gate for cron-auto only -----------------------------------
if [[ "${SOURCE}" == "cron-auto" ]]; then
    if ! "${PY}" "${DBPY}" check-quota; then
        log "(cron-auto) quota/spacing gate said skip"
        exit 0
    fi
fi

# ----- record run start ---------------------------------------------------
RUN_ID=$("${PY}" "${DBPY}" start "${SOURCE}")
log "===== run #${RUN_ID} (${SOURCE}) starting ====="

cd "${APP_DIR}"
new_before=$("${PY}" "${DBPY}" songs-new-count)

if "${PY}" "${APP_DIR}/scripts/discover.py"; then
    new_after=$("${PY}" "${DBPY}" songs-new-count)
    delta=$(( new_after - new_before ))
    "${PY}" "${DBPY}" finish "${RUN_ID}" success "${delta}" "ok"
    log "===== run #${RUN_ID} success (+${delta} new) ====="
else
    "${PY}" "${DBPY}" finish "${RUN_ID}" failed "" "discover.py exited non-zero"
    log "===== run #${RUN_ID} FAILED ====="
    exit 1
fi
