#!/usr/bin/env bash
# PHP port — Phase 3d (AR/AP subledgers).
# Asserts: AR customer + invoice → posts Dr Receivables / Cr Income (balanced);
# AP vendor + bill → posts Dr Expense / Cr Payables (balanced); trial balance ties.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5088}"
PYDATA="$(mktemp -d /tmp/sbs_php3d.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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
 (pid,'PRJ-3D','Phase3d','Internal','MBB','2026-01-01','2026-12-31','GHS',100000,1,100000,'Active'))
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

# AR: customer -> invoice 5000 -> post (Dr Receivables / Cr Income)
CUST=$(H -X POST "$B/api/ar/customers" -d '{"customer_name":"Ministry of Health"}'); CID=$(echo "$CUST"|J id)
ck "AR customer created" "$([ -n "$CID" ] && echo 1||echo 0)" "$CUST"
INV=$(H -X POST "$B/api/ar/invoices" -d "{\"customer_id\":\"$CID\",\"income_coa_id\":\"$INC\",\"project_id\":\"$PROJ\",\"invoice_date\":\"2026-06-15\",\"description\":\"Services\",\"amount_ghs\":5000}")
IID=$(echo "$INV"|J id); ck "AR invoice created (Draft)" "$([ -n "$IID" ] && echo 1||echo 0)" "$INV"
IP=$(H -X POST "$B/api/ar/invoices/post" -d "{\"id\":\"$IID\"}")
ck "AR invoice posts (Dr Receivables/Cr Income, total 5000)" "$([ "$(echo "$IP"|J status)" = Posted ] && approx "$(echo "$IP"|J total)" 5000 && echo 1||echo 0)" "$IP"

# AP: vendor -> bill 3000 -> post (Dr Expense / Cr Payables)
VEN=$(H -X POST "$B/api/vendors" -d '{"vendor_name":"Supplies Co"}'); VID=$(echo "$VEN"|J id)
ck "AP vendor created" "$([ -n "$VID" ] && echo 1||echo 0)" "$VEN"
BILL=$(H -X POST "$B/api/ap/bills" -d "{\"vendor_id\":\"$VID\",\"expense_coa_id\":\"$EXP\",\"project_id\":\"$PROJ\",\"bill_date\":\"2026-06-15\",\"description\":\"Supplies\",\"amount_ghs\":3000}")
BID=$(echo "$BILL"|J id); ck "AP bill created (Draft)" "$([ -n "$BID" ] && echo 1||echo 0)" "$BILL"
BP=$(H -X POST "$B/api/ap/bills/post" -d "{\"id\":\"$BID\"}")
ck "AP bill posts (Dr Expense/Cr Payables, total 3000)" "$([ "$(echo "$BP"|J status)" = Posted ] && approx "$(echo "$BP"|J total)" 3000 && echo 1||echo 0)" "$BP"

# GL: AR and AP journals each balanced
GL=$(H "$B/api/general-ledger" | python3 -c "
import sys,json
gl=json.load(sys.stdin)
dr=round(sum(float(r.get('debit_amount') or 0) for r in gl),2); cr=round(sum(float(r.get('credit_amount') or 0) for r in gl),2)
import json as j; print(j.dumps({'dr':dr,'cr':cr,'n':len(gl)}))")
ck "GL balanced after AR+AP postings" "$(approx "$(echo "$GL"|J dr)" "$(echo "$GL"|J cr)" && echo 1||echo 0)" "$GL"
# AR control debited 5000, AP control credited 3000 present
CHK=$(H "$B/api/ledger-summary" | python3 -c "
import sys,json
r=json.load(sys.stdin)
ar=sum(float(x.get('total_debit') or 0) for x in r if str(x.get('coa_code','')).startswith('123'))
ap=sum(float(x.get('total_credit') or 0) for x in r if str(x.get('coa_code','')).startswith('21'))
print(round(ar,2),round(ap,2))")
ck "Receivables control debited >=5000" "$(python3 -c "import sys;sys.exit(0 if float('$(echo $CHK|cut -d' ' -f1)')>=5000-0.01 else 1)" && echo 1||echo 0)" "AR=$(echo $CHK|cut -d' ' -f1)"
ck "Payables control credited >=3000" "$(python3 -c "import sys;sys.exit(0 if float('$(echo $CHK|cut -d' ' -f2)')>=3000-0.01 else 1)" && echo 1||echo 0)" "AP=$(echo $CHK|cut -d' ' -f2)"
# TB ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3d: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
