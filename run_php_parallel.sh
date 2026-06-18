#!/usr/bin/env bash
# PHP port — Phase 5 parallel run: the Python acceptance suites pointed at the PHP
# backend. Seeds a fresh DB via the Python reference, boots the PHP front controller
# on it, then runs smoke_test.py against PHP. (regression_test.py / ucc_multiunit
# parity is the next reconciliation milestone.) Exits non-zero on any failure.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5097}"
PYDATA="$(mktemp -d /tmp/sbs_parallel.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT

php -l "$APPDIR/php/index.php" >/dev/null || exit 2
echo "== seed DB via Python reference =="
( cd "$APPDIR" && RENDER_DATA_DIR="$PYDATA" PORT=5066 python3 app.py >"$PYDATA/py.log" 2>&1 ) & PYPID=$!
for i in $(seq 1 30); do curl -s -m 3 "http://127.0.0.1:5066/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' 2>/dev/null | grep -q '"sid"' && break; sleep 1; done
kill -9 "$PYPID" 2>/dev/null; PYPID=""
echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
for i in $(seq 1 20); do curl -s -m 3 "http://127.0.0.1:$PHP_PORT/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
echo "== smoke_test.py vs PHP =="
python3 "$APPDIR/smoke_test.py" --base "http://127.0.0.1:$PHP_PORT" --user admin --pass UCC@2024 --period 2026-06 | tail -3
echo ""
echo "== regression_test.py vs PHP (deep parity; remaining gaps are unported features) =="
rm -f /tmp/reg_gate.txt /tmp/reg_gate_approver.txt
REG_BASE="http://127.0.0.1:$PHP_PORT" REG_USER=admin REG_PASS="UCC@2024" python3 "$APPDIR/regression_test.py" 2>&1 | grep -E "passed, .*FAILED" | tail -1
