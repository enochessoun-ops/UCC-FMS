#!/usr/bin/env bash
# Boot a throwaway instance against a FRESH database, run the end-to-end
# accounting regression gate, then tear down. Exits non-zero on any failure.
#
# Usage:  ./run_regression.sh [PORT] [PASSWORD]
#   PORT      test port           (default 5099)
#   PASSWORD  admin password      (default UCC@2024; use AOI@2024 for AOI-FMS)
set -u
PORT="${1:-5099}"
PASS="${2:-UCC@2024}"
UNIT="${3:-}"
APPDIR="$(cd "$(dirname "$0")" && pwd)"
DATADIR="$(mktemp -d /tmp/regtest.XXXXXX)"
LOG="$DATADIR/boot.log"

cleanup(){ [ -n "${PID:-}" ] && kill -9 "$PID" 2>/dev/null; rm -rf "$DATADIR"; }
trap cleanup EXIT

echo "Booting throwaway instance on :$PORT (DB: $DATADIR) ..."
( cd "$APPDIR" && RENDER_DATA_DIR="$DATADIR" PORT="$PORT" python3 app.py >"$LOG" 2>&1 ) &
PID=$!

# Wait for FULL readiness: a real login must succeed. Polling /api/coa only proves the
# socket is up, which can be true mid-init (before the admin-password reset / role-CHECK
# migration finish) and makes login race to a spurious 500. Probing login removes the race.
for i in $(seq 1 30); do
  if curl -s -m 3 "http://127.0.0.1:$PORT/api/login" -H 'Content-Type: application/json' \
       -d "{\"username\":\"admin\",\"password\":\"$PASS\"}" 2>/dev/null | grep -q '"sid"'; then break; fi
  sleep 1
done

REG_BASE="http://127.0.0.1:$PORT" REG_USER=admin REG_PASS="$PASS" REG_UNIT="$UNIT" python3 "$APPDIR/regression_test.py"
RC=$?
if [ $RC -ne 0 ]; then echo "--- server log tail ---"; tail -20 "$LOG"; fi
exit $RC
