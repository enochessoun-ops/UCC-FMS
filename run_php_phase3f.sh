#!/usr/bin/env bash
# PHP port — Phase 3d (inventory stores ledger).
# Asserts: item create; stock receipt (Dr Inventory / Cr Bank, moving-average);
# stock issue (Dr Expense / Cr Inventory at avg); qty/avg tracking; GL balanced; TB ties.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5090}"
PYDATA="$(mktemp -d /tmp/sbs_phpinv.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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
SID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | J sid)
H(){ curl -s -H "X-Session-ID: $SID" -H 'Content-Type: application/json' "$@"; }
EXP=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('63') or str(a.get('code','')).startswith('62')),''))")
INVA=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('121')),''))")

# 1. item
IT=$(H -X POST "$B/api/inv/items" -d "{\"item_name\":\"A4 Paper\",\"unit\":\"ream\",\"inventory_coa_id\":\"$INVA\",\"expense_coa_id\":\"$EXP\"}")
IID=$(echo "$IT"|J id); ck "inventory item created" "$([ -n "$IID" ] && echo 1||echo 0)" "$IT"
# 2. receipt 10 @ 50 = 500 (Dr Inventory / Cr Bank); qty 10, avg 50
RC=$(H -X POST "$B/api/inv/receipt" -d "{\"item_id\":\"$IID\",\"qty\":10,\"unit_cost\":50,\"movement_date\":\"2026-06-15\"}")
ck "stock receipt posts; qty=10, avg=50" "$(approx "$(echo "$RC"|J qty_on_hand)" 10 && approx "$(echo "$RC"|J avg_cost)" 50 && [ -n "$(echo "$RC"|J jv_number)" ] && echo 1||echo 0)" "$RC"
# 3. issue 4 @ avg 50 = 200 (Dr Expense / Cr Inventory); qty 6
IS=$(H -X POST "$B/api/inv/issue" -d "{\"item_id\":\"$IID\",\"qty\":4,\"movement_date\":\"2026-06-16\"}")
ck "stock issue posts at avg; qty=6, cost=200" "$(approx "$(echo "$IS"|J qty_on_hand)" 6 && approx "$(echo "$IS"|J cost)" 200 && [ -n "$(echo "$IS"|J jv_number)" ] && echo 1||echo 0)" "$IS"
# 4. over-issue blocked
OV=$(H -X POST "$B/api/inv/issue" -d "{\"item_id\":\"$IID\",\"qty\":999}")
ck "over-issue blocked (insufficient stock)" "$([ "$(echo "$OV"|J ok)" = False ] && echo 1||echo 0)" "$OV"
# 5. inventory asset balance = 500-200 = 300; expense 200
BAL=$(H "$B/api/ledger-summary" | python3 -c "
import sys,json
r=json.load(sys.stdin)
inv=sum(float(x.get('total_debit') or 0)-float(x.get('total_credit') or 0) for x in r if str(x.get('coa_code','')).startswith('121'))
print(round(inv,2))")
ck "inventory GL asset balance = 300" "$(approx "$BAL" 300 && echo 1||echo 0)" "$BAL"
# 6. GL balanced + TB ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3d (inventory): $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
