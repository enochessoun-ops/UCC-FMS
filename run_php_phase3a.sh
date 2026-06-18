#!/usr/bin/env bash
# PHP port — Phase 3a (Payments + Ghana tax engine + budgets/commitments/vendors).
# Boots PHP against a Python-seeded DB and asserts: vendor/budget/commitment create,
# a PV with VAT+WHVAT+WHT+UCF computes the exact tax breakdown and posts a BALANCED
# journal to the GL (expense ex-VAT, input VAT, WHT/WHVAT/UCF payables, net bank),
# a plain PV posts, and the trial balance still ties out.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5085}"
PYDATA="$(mktemp -d /tmp/sbs_php3.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
P=0; Fn=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; P=$((P+1)); else echo "  [FAIL] $1 — $3"; Fn=$((Fn+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT
J(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }
approx(){ python3 -c "import sys;a=float('$1' or 0);b=float('$2');sys.exit(0 if abs(a-b)<0.02 else 1)"; }

php -l "$APPDIR/php/index.php" >/dev/null || exit 2
echo "== seed DB via Python reference =="
( cd "$APPDIR" && RENDER_DATA_DIR="$PYDATA" PORT=5066 python3 app.py >"$PYDATA/py.log" 2>&1 ) & PYPID=$!
for i in $(seq 1 30); do curl -s -m 3 "http://127.0.0.1:5066/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' 2>/dev/null | grep -q '"sid"' && break; sleep 1; done
kill -9 "$PYPID" 2>/dev/null; PYPID=""
# a project for the PV/budget/commitment to attach to
PROJ=$(python3 - "$DB" <<'PYEOF'
import sqlite3,sys,uuid
c=sqlite3.connect(sys.argv[1]); pid=str(uuid.uuid4())
c.execute("INSERT INTO projects(id,project_code,title,donor,division,start_date,end_date,currency,budget_fcy,fx_rate,budget_ghs,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
 (pid,'PRJ-3A','Phase3a Project','Internal','MBB','2026-01-01','2026-12-31','GHS',100000,1,100000,'Active'))
c.commit(); print(pid)
PYEOF
)
echo "project=$PROJ"

echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
B="http://127.0.0.1:$PHP_PORT"
for i in $(seq 1 20); do curl -s -m 3 "$B/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
SID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | J sid)
H(){ curl -s -H "X-Session-ID: $SID" -H 'Content-Type: application/json' "$@"; }
EXP=$(H "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('63') or str(a.get('code','')).startswith('62')),''))")

# 1. vendor
V=$(H -X POST "$B/api/vendors" -d '{"vendor_name":"Acme Ltd"}'); ck "vendor create" "$([ -n "$(echo "$V"|J id)" ] && echo 1||echo 0)" "$V"
# 2. budget
BU=$(H -X POST "$B/api/budgets" -d "{\"project_id\":\"$PROJ\",\"coa_id\":\"$EXP\",\"budget_fcy\":50000}"); ck "budget create" "$([ -n "$(echo "$BU"|J id)" ] && echo 1||echo 0)" "$BU"
# 3. commitment
CM=$(H -X POST "$B/api/commitments" -d "{\"project_id\":\"$PROJ\",\"vendor\":\"Acme Ltd\",\"description\":\"PO\",\"amount_fcy\":10000}"); ck "commitment create (encumbrance)" "$([ -n "$(echo "$CM"|J id)" ] && echo 1||echo 0)" "$CM"

# 4. PV with full tax (VAT+WHVAT+WHT-Service+UCF) on 10000
PV=$(H -X POST "$B/api/actuals" -d "{\"project_id\":\"$PROJ\",\"expense_coa_id\":\"$EXP\",\"expense_date\":\"2026-06-15\",\"payee\":\"Acme Ltd\",\"description\":\"Taxed service\",\"currency\":\"GHS\",\"amount_fcy\":10000,\"pay_fx_rate\":1,\"has_vat\":1,\"has_whvat\":1,\"has_ucf\":1,\"wht_type\":\"WHT-Service\"}")
AID=$(echo "$PV"|J id)
VAT=$(echo "$PV"|python3 -c "import sys,json;print(json.load(sys.stdin).get('tax',{}).get('vat',''))" 2>/dev/null)
WHT=$(echo "$PV"|python3 -c "import sys,json;print(json.load(sys.stdin).get('tax',{}).get('wht',''))" 2>/dev/null)
WHV=$(echo "$PV"|python3 -c "import sys,json;print(json.load(sys.stdin).get('tax',{}).get('whvat',''))" 2>/dev/null)
UCF=$(echo "$PV"|python3 -c "import sys,json;print(json.load(sys.stdin).get('tax',{}).get('ucf',''))" 2>/dev/null)
ck "PV tax: VAT=1666.67"   "$(approx "$VAT" 1666.67 && echo 1||echo 0)" "$VAT"
ck "PV tax: WHVAT=583.33"  "$(approx "$WHV" 583.33 && echo 1||echo 0)" "$WHV"
ck "PV tax: WHT(service)=625.00" "$(approx "$WHT" 625.00 && echo 1||echo 0)" "$WHT"
ck "PV tax: UCF=356.25"    "$(approx "$UCF" 356.25 && echo 1||echo 0)" "$UCF"
# 5. post the PV → GL, net bank = 8435.42
PR=$(H -X POST "$B/api/actuals/post" -d "{\"id\":\"$AID\"}")
NET=$(echo "$PR"|J net_paid)
ck "PV posts to GL (status Posted)" "$([ "$(echo "$PR"|J status)" = Posted ] && echo 1||echo 0)" "$PR"
ck "PV net bank credit = 8435.42" "$(approx "$NET" 8435.42 && echo 1||echo 0)" "$NET"
# 6. GL legs for that PV are present and balanced
LEGS=$(H "$B/api/general-ledger" | python3 -c "
import sys,json
gl=json.load(sys.stdin)
pv=[r for r in gl if str(r.get('jv_number','')).startswith('PV-')]
dr=round(sum(float(r.get('debit_amount') or 0) for r in pv),2); cr=round(sum(float(r.get('credit_amount') or 0) for r in pv),2)
import json as j; print(j.dumps({'n':len(pv),'dr':dr,'cr':cr}))")
NLEG=$(echo "$LEGS"|J n); DRL=$(echo "$LEGS"|J dr); CRL=$(echo "$LEGS"|J cr)
# Irrecoverable input VAT (the university is not VAT-registered) is loaded onto the
# expense rather than a recoverable input-VAT asset — matching the Python reference.
# So the PV posts 5 legs: expense(incl. VAT), WHT, WHVAT, UCF payables, net bank.
ck "PV journal has 5 GL legs (exp incl. irrecoverable VAT, WHT,WHVAT,UCF,bank)" "$([ "$NLEG" = 5 ] && echo 1||echo 0)" "$LEGS"
ck "PV journal balances (Dr≈Cr)" "$(approx "$DRL" "$CRL" && echo 1||echo 0)" "Dr=$DRL Cr=$CRL"

# 7. plain PV (no tax) posts, net = full amount
PV2=$(H -X POST "$B/api/actuals" -d "{\"project_id\":\"$PROJ\",\"expense_coa_id\":\"$EXP\",\"expense_date\":\"2026-06-15\",\"payee\":\"Stationer\",\"description\":\"Stationery\",\"currency\":\"GHS\",\"amount_fcy\":2000,\"pay_fx_rate\":1,\"wht_type\":\"None\"}")
A2=$(echo "$PV2"|J id); PR2=$(H -X POST "$B/api/actuals/post" -d "{\"id\":\"$A2\"}")
ck "plain PV posts, net=2000" "$([ "$(echo "$PR2"|J status)" = Posted ] && approx "$(echo "$PR2"|J net_paid)" 2000 && echo 1||echo 0)" "$PR2"

# 8. trial balance still ties out
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
DRT=$(echo $TB|cut -d' ' -f1); CRT=$(echo $TB|cut -d' ' -f2)
ck "trial balance ties out (Dr≈Cr)" "$(approx "$DRT" "$CRT" && echo 1||echo 0)" "Dr=$DRT Cr=$CRT"

echo ""; echo "==== PHP PHASE 3a: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
