#!/usr/bin/env bash
# PHP port — Phase 3b (Receipts + JV workflow with segregation of duties).
# Asserts: a fund receipt posts Dr Bank / Cr Income (balanced); the JV workflow
# enforces maker-checker (preparer CANNOT approve own JV), Admin-only posting, and
# a clean Draft→Submitted→Approved→Posted path; trial balance ties out.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5086}"
PYDATA="$(mktemp -d /tmp/sbs_php3b.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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
# project + a SECOND admin (approver) + a finance officer, inserted directly
PROJ=$(python3 - "$DB" <<'PYEOF'
import sqlite3,sys,uuid,hashlib
c=sqlite3.connect(sys.argv[1]); pid=str(uuid.uuid4())
c.execute("INSERT INTO projects(id,project_code,title,donor,division,start_date,end_date,currency,budget_fcy,fx_rate,budget_ghs,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
 (pid,'PRJ-3B','Phase3b','Internal','MBB','2026-01-01','2026-12-31','GHS',100000,1,100000,'Active'))
def mkuser(u,role):
    c.execute("INSERT OR REPLACE INTO users(id,username,password_hash,full_name,role,active) VALUES(?,?,?,?,?,1)",
      (str(uuid.uuid4()),u,hashlib.sha256(b'Passw0rd123').hexdigest(),u,role))
mkuser('approver2','Admin'); mkuser('fo1','Finance Officer')
c.commit(); print(pid)
PYEOF
)
echo "project=$PROJ"
echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
B="http://127.0.0.1:$PHP_PORT"
for i in $(seq 1 20); do curl -s -m 3 "$B/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
login(){ curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d "{\"username\":\"$1\",\"password\":\"$2\"}" | J sid; }
ASID=$(login admin UCC@2024); APSID=$(login approver2 Passw0rd123); FOSID=$(login fo1 Passw0rd123)
A(){ local s="$1"; shift; curl -s -H "X-Session-ID: $s" -H 'Content-Type: application/json' "$@"; }
INC=$(A "$ASID" "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('4')),''))")
EXP=$(A "$ASID" "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('63') or str(a.get('code','')).startswith('62')),''))")
BANKC=$(A "$ASID" "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(next((a['id'] for a in c if str(a.get('code','')).startswith('127') or str(a.get('code','')).startswith('126')),''))")

ck "second approver + finance officer logged in" "$([ -n "$APSID" ] && [ -n "$FOSID" ] && echo 1||echo 0)" "ap=$APSID fo=$FOSID"

# 1. Receipt: save + post (Dr Bank / Cr Income)
RV=$(A "$ASID" -X POST "$B/api/fund-receipts" -d "{\"project_id\":\"$PROJ\",\"income_coa_id\":\"$INC\",\"receipt_date\":\"2026-06-15\",\"donor\":\"World Bank\",\"description\":\"Grant\",\"currency\":\"GHS\",\"amount_fcy\":20000,\"fx_rate\":1}")
RID=$(echo "$RV"|J id); ck "receipt saved" "$([ -n "$RID" ] && echo 1||echo 0)" "$RV"
RP=$(A "$ASID" -X POST "$B/api/fund-receipts/post" -d "{\"id\":\"$RID\"}")
ck "receipt posts (Dr Bank/Cr Income, amount 20000)" "$([ "$(echo "$RP"|J status)" = Posted ] && approx "$(echo "$RP"|J amount)" 20000 && echo 1||echo 0)" "$RP"

# 2. JV workflow with segregation of duties
JV=$(A "$ASID" -X POST "$B/api/jvs" -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"WF JV\",\"lines\":[{\"coa_id\":\"$EXP\",\"debit_amount\":500,\"credit_amount\":0},{\"coa_id\":\"$BANKC\",\"debit_amount\":0,\"credit_amount\":500}]}")
JID=$(echo "$JV"|J id); ck "JV created (Draft) by admin/preparer" "$([ -n "$JID" ] && echo 1||echo 0)" "$JV"
S1=$(A "$ASID" -X POST "$B/api/jvs/workflow" -d "{\"jv_id\":\"$JID\",\"action\":\"submit\"}")
ck "submit → Submitted" "$([ "$(echo "$S1"|J new_status)" = Submitted ] && echo 1||echo 0)" "$S1"
SOD=$(A "$ASID" -X POST "$B/api/jvs/workflow" -d "{\"jv_id\":\"$JID\",\"action\":\"approve\"}")
ck "SoD: preparer CANNOT approve own JV" "$([ "$(echo "$SOD"|J ok)" = False ] && echo 1||echo 0)" "$SOD"
AP=$(A "$APSID" -X POST "$B/api/jvs/workflow" -d "{\"jv_id\":\"$JID\",\"action\":\"approve\"}")
ck "different officer approves → Approved" "$([ "$(echo "$AP"|J new_status)" = Approved ] && echo 1||echo 0)" "$AP"
FOPOST=$(A "$FOSID" -X POST "$B/api/jvs/workflow" -d "{\"jv_id\":\"$JID\",\"action\":\"post\"}")
ck "Finance Officer BLOCKED from posting (Admin-only)" "$([ "$(echo "$FOPOST"|J ok)" = False ] && echo 1||echo 0)" "$FOPOST"
PO=$(A "$APSID" -X POST "$B/api/jvs/workflow" -d "{\"jv_id\":\"$JID\",\"action\":\"post\"}")
ck "Admin posts → Posted (to GL)" "$([ "$(echo "$PO"|J new_status)" = Posted ] && echo 1||echo 0)" "$PO"

# 3. trial balance ties out
TB=$(A "$ASID" "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
DRT=$(echo $TB|cut -d' ' -f1); CRT=$(echo $TB|cut -d' ' -f2)
ck "trial balance ties out (Dr≈Cr)" "$(approx "$DRT" "$CRT" && echo 1||echo 0)" "Dr=$DRT Cr=$CRT"

echo ""; echo "==== PHP PHASE 3b: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
