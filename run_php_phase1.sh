#!/usr/bin/env bash
# PHP port — Phase 1 (Foundation) acceptance gate.
# Lints php/index.php, builds a seeded DB with the Python reference, boots the PHP
# front controller against it, and asserts: login → me → org-units → logout, plus
# the role guard (read-only Auditor blocked from a write). Exits non-zero on any fail.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5077}"
PYDATA="$(mktemp -d /tmp/sbs_php.XXXXXX)"
DB="$PYDATA/ucc_fms.db"
PASS_N=0; FAIL_N=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; PASS_N=$((PASS_N+1)); else echo "  [FAIL] $1 — $3"; FAIL_N=$((FAIL_N+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT

echo "== php -l =="; php -l "$APPDIR/php/index.php" || exit 2

echo "== seed DB via Python reference =="
( cd "$APPDIR" && RENDER_DATA_DIR="$PYDATA" PORT=5066 python3 app.py >"$PYDATA/py.log" 2>&1 ) & PYPID=$!
for i in $(seq 1 30); do curl -s -m 3 "http://127.0.0.1:5066/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' 2>/dev/null | grep -q '"sid"' && break; sleep 1; done
# create a read-only Auditor for the role-guard test
ASID=$(curl -s -X POST http://127.0.0.1:5066/api/login -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | python3 -c "import sys,json;print(json.load(sys.stdin).get('sid',''))")
curl -s -X POST http://127.0.0.1:5066/api/users -H "X-Session-ID: $ASID" -H 'Content-Type: application/json' \
  -d '{"username":"auditor1","password":"Auditor123","full_name":"Auditor","role":"Auditor","email":"a@x"}' >/dev/null
kill -9 "$PYPID" 2>/dev/null; PYPID=""

echo "== boot PHP front controller =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
for i in $(seq 1 20); do curl -s -m 3 "http://127.0.0.1:$PHP_PORT/healthz" 2>/dev/null | grep -q '"db": "ok"' && break; sleep 0.5; done
B="http://127.0.0.1:$PHP_PORT"

j(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }
HZ=$(curl -s "$B/healthz"); ck "healthz db ok" "$([ "$(echo "$HZ"|j db)" = ok ] && echo 1||echo 0)" "$HZ"
LOG=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}')
SID=$(echo "$LOG"|j sid); ck "admin login returns sid" "$([ -n "$SID" ] && echo 1||echo 0)" "$LOG"
ROLE=$(echo "$LOG"|python3 -c "import sys,json;print(json.load(sys.stdin).get('user',{}).get('role',''))" 2>/dev/null)
ck "login returns Admin role" "$([ "$ROLE" = Admin ] && echo 1||echo 0)" "$ROLE"
BADLOG=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"wrong"}')
ck "bad password rejected" "$([ "$(echo "$BADLOG"|j ok)" = False ] && echo 1||echo 0)" "$BADLOG"
ME=$(curl -s "$B/api/me" -H "X-Session-ID: $SID")
ck "me returns the logged-in user" "$([ "$(echo "$ME"|python3 -c "import sys,json;print(json.load(sys.stdin).get('user',{}).get('username',''))" 2>/dev/null)" = admin ] && echo 1||echo 0)" "$ME"
NOAUTH=$(curl -s "$B/api/me")
ck "me without session is rejected" "$([ "$(echo "$NOAUTH"|j ok)" = False ] && echo 1||echo 0)" "$NOAUTH"
OU=$(curl -s "$B/api/org-units" -H "X-Session-ID: $SID")
ck "org-units returns the 47-node tree" "$([ "$(echo "$OU"|j count)" = 47 ] && echo 1||echo 0)" "$(echo "$OU"|head -c 80)"
# role guard: Admin allowed, Auditor blocked
WADM=$(curl -s -X POST "$B/api/_phase1_write_probe" -H "X-Session-ID: $SID")
ck "write probe allowed for Admin" "$([ "$(echo "$WADM"|j ok)" = True ] && echo 1||echo 0)" "$WADM"
AULOG=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"auditor1","password":"Auditor123"}')
AUSID=$(echo "$AULOG"|j sid)
WAUD=$(curl -s -X POST "$B/api/_phase1_write_probe" -H "X-Session-ID: $AUSID")
ck "write probe BLOCKED for read-only Auditor" "$([ "$(echo "$WAUD"|j ok)" = False ] && echo 1||echo 0)" "$WAUD"
LO=$(curl -s -X POST "$B/api/logout" -H "X-Session-ID: $SID")
AFTER=$(curl -s "$B/api/me" -H "X-Session-ID: $SID")
ck "logout invalidates the session" "$([ "$(echo "$AFTER"|j ok)" = False ] && echo 1||echo 0)" "$AFTER"

echo ""; echo "==== PHP PHASE 1: $PASS_N passed, $FAIL_N FAILED ===="
[ "$FAIL_N" = 0 ] || exit 1
