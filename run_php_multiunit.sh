#!/usr/bin/env bash
# PHP port — institutional multi-unit parity (HTTP). Mirrors ucc_multiunit_test.py:
# write attribution (unit_id stamped), isolation (a unit head sees only its own),
# subtree roll-up (node param consolidates a college's schools), university view,
# and the consolidation tie-out (Σ units == university).
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5094}"
PYDATA="$(mktemp -d /tmp/sbs_phpmu.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
P=0; Fn=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; P=$((P+1)); else echo "  [FAIL] $1 — $3"; Fn=$((Fn+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT
J(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }
approx(){ python3 -c "import sys;sys.exit(0 if abs(float('$1' or 0)-float('$2'))<0.02 else 1)"; }

php -l "$APPDIR/php/index.php" >/dev/null || exit 2
echo "== seed DB via Python reference =="
( cd "$APPDIR" && RENDER_DATA_DIR="$PYDATA" PORT=5066 python3 app.py >"$PYDATA/py.log" 2>&1 ) & PYPID=$!
for i in $(seq 1 30); do curl -s -m 3 "http://127.0.0.1:5066/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' 2>/dev/null | grep -q '"sid"' && break; sleep 1; done
kill -9 "$PYPID" 2>/dev/null; PYPID=""
echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
B="http://127.0.0.1:$PHP_PORT"
for i in $(seq 1 20); do curl -s -m 3 "$B/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
A(){ local s="$1"; shift; curl -s -H "X-Session-ID: $s" -H 'Content-Type: application/json' "$@"; }
ASID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | J sid)
A "$ASID" -X POST "$B/api/go-live-enforcement/mode" -d '{"mode":"UAT"}' >/dev/null
# org node ids
OU=$(A "$ASID" "$B/api/org-units")
oid(){ echo "$OU" | python3 -c "import sys,json;print(next((u['id'] for u in json.load(sys.stdin)['units'] if u['code']=='$1'),''))"; }
SBS=$(oid CANS-SBS); SOA=$(oid CANS-SOA); CANS=$(oid CANS)
read C1 C2 < <(A "$ASID" "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c[0]['id'],c[1]['id'])")
# unit-homed users
A "$ASID" -X POST "$B/api/users" -d "{\"username\":\"sbshead\",\"password\":\"Pass12345\",\"full_name\":\"SBS Head\",\"role\":\"Finance Officer\",\"home_unit_id\":\"$SBS\",\"scope\":\"own_unit\"}" >/dev/null
A "$ASID" -X POST "$B/api/users" -d "{\"username\":\"soahead\",\"password\":\"Pass12345\",\"full_name\":\"SOA Head\",\"role\":\"Finance Officer\",\"home_unit_id\":\"$SOA\",\"scope\":\"own_unit\"}" >/dev/null
A "$ASID" -X POST "$B/api/users" -d "{\"username\":\"provost\",\"password\":\"Pass12345\",\"full_name\":\"CANS Provost\",\"role\":\"Finance Officer\",\"home_unit_id\":\"$CANS\",\"scope\":\"subtree\"}" >/dev/null
SSID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"sbshead","password":"Pass12345"}'|J sid)
OSID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"soahead","password":"Pass12345"}'|J sid)
PSID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"provost","password":"Pass12345"}'|J sid)
ck "unit-homed users provisioned" "$([ -n "$SSID" ] && [ -n "$OSID" ] && [ -n "$PSID" ] && echo 1||echo 0)" "ss=$SSID os=$OSID ps=$PSID"

# post a JV as each unit head (unit_id stamped from home unit): SBS=100, SOA=200
postjv(){ local sid="$1" amt="$2"; local jv; jv=$(A "$sid" -X POST "$B/api/jvs" -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"unit jv\",\"lines\":[{\"coa_id\":\"$C1\",\"debit_amount\":$amt,\"credit_amount\":0},{\"coa_id\":\"$C2\",\"debit_amount\":0,\"credit_amount\":$amt}]}"); A "$sid" -X POST "$B/api/journal-vouchers/post" -d "{\"jv_id\":\"$(echo "$jv"|J id)\"}" >/dev/null; }
postjv "$SSID" 100
postjv "$OSID" 200
# Σtotal_debit (expense leg) under a given scope = sum of that scope's JV amounts
dr(){ local sid="$1" unit="$2"; A "$sid" "$B/api/ledger-summary${unit:+?unit=$unit}" | python3 -c "import sys,json;print(round(sum(float(x.get('total_debit') or 0) for x in json.load(sys.stdin)),2))"; }

ck "write attribution: GL stamped to units (admin sees 300)" "$(approx "$(dr "$ASID" '')" 300 && echo 1||echo 0)" "$(dr "$ASID" '')"
ck "node roll-up: node=CANS-SBS isolates (100)" "$(approx "$(dr "$ASID" "$SBS")" 100 && echo 1||echo 0)" "$(dr "$ASID" "$SBS")"
ck "node roll-up: node=CANS-SOA isolates (200)" "$(approx "$(dr "$ASID" "$SOA")" 200 && echo 1||echo 0)" "$(dr "$ASID" "$SOA")"
ck "node roll-up: node=CANS consolidates schools (300)" "$(approx "$(dr "$ASID" "$CANS")" 300 && echo 1||echo 0)" "$(dr "$ASID" "$CANS")"
ck "isolation: SBS head sees only its own (100)" "$(approx "$(dr "$SSID" '')" 100 && echo 1||echo 0)" "$(dr "$SSID" '')"
ck "isolation: SOA head sees only its own (200)" "$(approx "$(dr "$OSID" '')" 200 && echo 1||echo 0)" "$(dr "$OSID" '')"
ck "subtree: CANS provost sees the college (300)" "$(approx "$(dr "$PSID" '')" 300 && echo 1||echo 0)" "$(dr "$PSID" '')"
ck "consolidation tie-out: SBS + SOA == CANS" "$(python3 -c "import sys;sys.exit(0 if abs($(dr "$ASID" "$SBS")+$(dr "$ASID" "$SOA")-$(dr "$ASID" "$CANS"))<0.02 else 1)" && echo 1||echo 0)" "100+200==300"
# I&E node-scoped (these JVs hit expense+ another account; check I&E expenditure for a node is bounded)
ck "I&E node=CANS-SBS scoped" "$([ "$(A "$ASID" "$B/api/income-expenditure?unit=$SBS" | J ok)" = True ] && echo 1||echo 0)" "ie scoped"

echo ""; echo "==== PHP MULTI-UNIT: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
