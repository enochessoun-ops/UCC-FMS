#!/usr/bin/env bash
# PHP port — Phase 3d (petty cash imprest floats).
# Asserts: float setup (Dr Petty Cash / Cr Bank); voucher (Dr Expense / Cr Petty
# Cash) within balance; over-spend blocked; book balance tracked; GL balanced; TB ties.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5092}"
PYDATA="$(mktemp -d /tmp/sbs_phppc.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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
IMP=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('129')), next((a['id'] for a in c if str(a.get('code','')).startswith('12')),'')))")

# 1. setup float: imprest 1000 (Dr Petty Cash / Cr Bank)
FL=$(H -X POST "$B/api/petty-cash2/float" -d "{\"name\":\"MBB Imprest\",\"custodian\":\"Ama\",\"imprest_amount\":1000,\"coa_id\":\"$IMP\",\"department_code\":\"MBB\",\"established_date\":\"2026-06-01\"}")
FID=$(echo "$FL"|J id); ck "float established (Dr Petty Cash / Cr Bank 1000)" "$([ -n "$FID" ] && [ -n "$(echo "$FL"|J jv_number)" ] && echo 1||echo 0)" "$FL"
# 2. float requires dept/project (accountability)
NA=$(H -X POST "$B/api/petty-cash2/float" -d "{\"name\":\"X\",\"imprest_amount\":500,\"coa_id\":\"$IMP\"}")
ck "float without dept/project rejected" "$([ "$(echo "$NA"|J ok)" = False ] && echo 1||echo 0)" "$NA"
# 3. voucher 50 within balance (Dr Expense / Cr Petty Cash); balance 950
V=$(H -X POST "$B/api/petty-cash2/voucher" -d "{\"float_id\":\"$FID\",\"payee\":\"Taxi\",\"description\":\"transport\",\"expense_coa_id\":\"$EXP\",\"amount_ghs\":50,\"voucher_date\":\"2026-06-05\"}")
ck "voucher posts; balance_after = 950" "$([ -n "$(echo "$V"|J pcv_number)" ] && approx "$(echo "$V"|J balance_after)" 950 && echo 1||echo 0)" "$V"
# 4. over-spend blocked
OV=$(H -X POST "$B/api/petty-cash2/voucher" -d "{\"float_id\":\"$FID\",\"payee\":\"Big\",\"expense_coa_id\":\"$EXP\",\"amount_ghs\":5000}")
ck "over-balance voucher blocked" "$([ "$(echo "$OV"|J ok)" = False ] && echo 1||echo 0)" "$OV"
# 5. state shows float book balance 950
ST=$(H "$B/api/petty-cash2" | python3 -c "import sys,json;d=json.load(sys.stdin);print(next((f['book_balance'] for f in d.get('floats',[]) if f['id']=='$FID'),''))")
ck "petty-cash state book balance = 950" "$(approx "$ST" 950 && echo 1||echo 0)" "$ST"
# 6. TB ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3d (petty cash): $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
