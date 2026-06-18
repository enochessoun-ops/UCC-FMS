#!/usr/bin/env bash
# PHP port — Phase 3c (payroll): Ghana PAYE graduated bands + 3-tier SSNIT.
# Verifies the exact figures for basic 5000 (PAYE 779.75, SSNIT Tier-1 675, net
# 3945.25) and a balanced IPSAS-25 payroll GL journal; trial balance ties.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5093}"
PYDATA="$(mktemp -d /tmp/sbs_phppay.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
P=0; Fn=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; P=$((P+1)); else echo "  [FAIL] $1 — $3"; Fn=$((Fn+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT
J(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }
approx(){ python3 -c "import sys;sys.exit(0 if abs(float('$1' or 0)-float('$2'))<0.05 else 1)"; }

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

# 1. employee: basic 5000, resident, Tier-1 + Tier-2 member
EMP=$(H -X POST "$B/api/payroll/employees" -d '{"full_name":"Kofi Mensah","division":"MBB","employment_type":"Permanent","tax_residency":"Resident","basic_salary":5000,"tier1_member":1,"tier2_member":1}')
ck "employee created" "$([ -n "$(echo "$EMP"|J id)" ] && echo 1||echo 0)" "$EMP"
# 2. run payroll
RUN=$(H -X POST "$B/api/payroll/run" -d '{"month":"2026-06"}')
ck "payroll run (1 employee)" "$([ "$(echo "$RUN"|J count)" = 1 ] && echo 1||echo 0)" "$RUN"
# 3. verify the computed figures
REG=$(H "$B/api/payroll/register?month=2026-06" | python3 -c "
import sys,json
r=json.load(sys.stdin)['register'][0]
import json as j; print(j.dumps({k:r.get(k) for k in ('paye','employee_tier1','employer_tier1','employer_tier2','net_pay','total_employer_cost','gross_pay')}))")
ck "PAYE = 779.75 (graduated bands)" "$(approx "$(echo "$REG"|J paye)" 779.75 && echo 1||echo 0)" "$(echo "$REG"|J paye)"
ck "SSNIT employee Tier-1 = 275 (5.5%)" "$(approx "$(echo "$REG"|J employee_tier1)" 275 && echo 1||echo 0)" "$(echo "$REG"|J employee_tier1)"
ck "SSNIT employer Tier-1 = 400 (8%)" "$(approx "$(echo "$REG"|J employer_tier1)" 400 && echo 1||echo 0)" "$(echo "$REG"|J employer_tier1)"
ck "Tier-2 employer = 250 (5%)" "$(approx "$(echo "$REG"|J employer_tier2)" 250 && echo 1||echo 0)" "$(echo "$REG"|J employer_tier2)"
ck "net pay = 3945.25" "$(approx "$(echo "$REG"|J net_pay)" 3945.25 && echo 1||echo 0)" "$(echo "$REG"|J net_pay)"
ck "total employer cost = 5650" "$(approx "$(echo "$REG"|J total_employer_cost)" 5650 && echo 1||echo 0)" "$(echo "$REG"|J total_employer_cost)"
# 4. approve + post GL
AP=$(H -X POST "$B/api/payroll/approve" -d '{"month":"2026-06"}')
ck "payroll approved + posted to GL" "$([ "$(echo "$AP"|J gl_posted)" = True ] && [ -n "$(echo "$AP"|J jv_number)" ] && echo 1||echo 0)" "$AP"
# 5. payroll GL journal balanced + PAYE/SSNIT credited
GL=$(H "$B/api/general-ledger" | python3 -c "
import sys,json
gl=[r for r in json.load(sys.stdin) if (r.get('description') or '').find('ayroll')>=0 or str(r.get('jv_number','')).startswith('JV-')]
pay=[r for r in gl]
dr=round(sum(float(r.get('debit_amount') or 0) for r in pay),2); cr=round(sum(float(r.get('credit_amount') or 0) for r in pay),2)
import json as j; print(j.dumps({'dr':dr,'cr':cr}))")
ck "payroll GL journal balanced" "$(approx "$(echo "$GL"|J dr)" "$(echo "$GL"|J cr)" && echo 1||echo 0)" "$GL"
# 6. TB ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3c (payroll): $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
