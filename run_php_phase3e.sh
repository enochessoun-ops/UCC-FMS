#!/usr/bin/env bash
# PHP port — Phase 3e (financial statements). Posts a receipt (20000) and a plain
# expense (2000), then asserts: I&E income/expenditure/surplus; SFP balances
# (Assets == Liabilities + Net Assets, presentation_difference 0); Cash Flow
# closing == SFP cash; trial balance ties.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5089}"
PYDATA="$(mktemp -d /tmp/sbs_php3e.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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
PROJ=$(python3 - "$DB" <<'PYEOF'
import sqlite3,sys,uuid
c=sqlite3.connect(sys.argv[1]); pid=str(uuid.uuid4())
c.execute("INSERT INTO projects(id,project_code,title,donor,division,start_date,end_date,currency,budget_fcy,fx_rate,budget_ghs,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
 (pid,'PRJ-3E','Phase3e','Internal','MBB','2026-01-01','2026-12-31','GHS',100000,1,100000,'Active'))
c.commit(); print(pid)
PYEOF
)
echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
B="http://127.0.0.1:$PHP_PORT"
for i in $(seq 1 20); do curl -s -m 3 "$B/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
SID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | J sid)
H(){ curl -s -H "X-Session-ID: $SID" -H 'Content-Type: application/json' "$@"; }
INC=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('4')),''))")
EXP=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('63') or str(a.get('code','')).startswith('62')),''))")

# Post a receipt (Dr Bank / Cr Income 20000) and a plain expense (Dr Expense / Cr Bank 2000)
RV=$(H -X POST "$B/api/fund-receipts" -d "{\"project_id\":\"$PROJ\",\"income_coa_id\":\"$INC\",\"receipt_date\":\"2026-06-15\",\"donor\":\"WB\",\"description\":\"Grant\",\"currency\":\"GHS\",\"amount_fcy\":20000,\"fx_rate\":1}")
H -X POST "$B/api/fund-receipts/post" -d "{\"id\":\"$(echo "$RV"|J id)\"}" >/dev/null
PV=$(H -X POST "$B/api/actuals" -d "{\"project_id\":\"$PROJ\",\"expense_coa_id\":\"$EXP\",\"expense_date\":\"2026-06-15\",\"payee\":\"X\",\"description\":\"Stationery\",\"currency\":\"GHS\",\"amount_fcy\":2000,\"pay_fx_rate\":1,\"wht_type\":\"None\"}")
H -X POST "$B/api/actuals/post" -d "{\"id\":\"$(echo "$PV"|J id)\"}" >/dev/null

# 1. Income & Expenditure
IE=$(H "$B/api/income-expenditure")
INCT=$(echo "$IE"|J total_income); EXPT=$(echo "$IE"|J total_expenditure); SUR=$(echo "$IE"|J surplus_deficit)
ck "I&E income = 20000" "$(approx "$INCT" 20000 && echo 1||echo 0)" "$INCT"
ck "I&E expenditure = 2000" "$(approx "$EXPT" 2000 && echo 1||echo 0)" "$EXPT"
ck "I&E surplus = 18000" "$(approx "$SUR" 18000 && echo 1||echo 0)" "$SUR"

# 2. SFP balances
SFP=$(H "$B/api/sfp")
DIFF=$(echo "$SFP"|J presentation_difference); NA=$(echo "$SFP"|J net_assets); CASH=$(echo "$SFP"|J cash)
ck "SFP balances (Assets == Liab + Net Assets, diff 0)" "$(approx "$DIFF" 0 && echo 1||echo 0)" "diff=$DIFF"
ck "SFP net assets = 18000" "$(approx "$NA" 18000 && echo 1||echo 0)" "$NA"
ck "SFP cash = 18000" "$(approx "$CASH" 18000 && echo 1||echo 0)" "$CASH"

# 3. Cash flow ties to SFP cash
CF=$(H "$B/api/cashflow"); CL=$(echo "$CF"|J closing_cash)
ck "Cash Flow closing == SFP cash (18000)" "$(approx "$CL" "$CASH" && approx "$CL" 18000 && echo 1||echo 0)" "$CL"

# 4. Trial balance ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3e: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
