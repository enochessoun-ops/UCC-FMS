#!/usr/bin/env bash
# PHP port — Phase 3c (assets): fixed-asset register + straight-line depreciation.
# Asserts: asset register; monthly depreciation = (cost-residual)/(life*12); a run
# posts Dr Depreciation Expense / Cr Accumulated Depreciation to the GL; the asset's
# accumulated/carrying update; and the trial balance ties out.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5087}"
PYDATA="$(mktemp -d /tmp/sbs_php3c.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
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

# 1. register an asset: cost 12000, 5-yr life, residual 0 -> monthly = 200
AS=$(H -X POST "$B/api/assets" -d '{"asset_name":"Lab Microscope","asset_category":"Equipment","acquisition_date":"2026-01-01","acquisition_cost":12000,"useful_life_years":5,"residual_value":0}')
AID=$(echo "$AS"|J id); ck "asset registered" "$([ -n "$AID" ] && echo 1||echo 0)" "$AS"
# 2. schedule shows monthly 200
SCH=$(H "$B/api/depreciation/schedule" | python3 -c "import sys,json;d=json.load(sys.stdin);print(next((r['monthly'] for r in d.get('schedule',[]) if r['id']=='$AID'),''))")
ck "monthly depreciation = 200.00" "$(approx "$SCH" 200 && echo 1||echo 0)" "$SCH"
# 3. run depreciation -> posts to GL
RUN=$(H -X POST "$B/api/depreciation/run" -d '{"month":"2026-06"}')
ck "depreciation run posts to GL (total 200)" "$([ "$(echo "$RUN"|J status)" = Posted ] && approx "$(echo "$RUN"|J total)" 200 && echo 1||echo 0)" "$RUN"
# 4. GL has Dr 200 expense (619) and Cr 200 accumulated (119)
GL=$(H "$B/api/general-ledger" | python3 -c "
import sys,json
gl=json.load(sys.stdin)
dep=[r for r in gl if str(r.get('jv_number','')).startswith('JV-') and 'epreciation' in (r.get('description') or '')]
dr=round(sum(float(r.get('debit_amount') or 0) for r in dep),2); cr=round(sum(float(r.get('credit_amount') or 0) for r in dep),2)
import json as j; print(j.dumps({'dr':dr,'cr':cr,'n':len(dep)}))")
ck "GL depreciation legs balanced (Dr 200 / Cr 200)" "$([ "$(echo "$GL"|J n)" = 2 ] && approx "$(echo "$GL"|J dr)" 200 && approx "$(echo "$GL"|J cr)" 200 && echo 1||echo 0)" "$GL"
# 5. asset accumulated/carrying updated
ACC=$(python3 -c "import sqlite3;c=sqlite3.connect('$DB');r=c.execute(\"SELECT accumulated_depreciation,carrying_amount FROM asset_register WHERE id='$AID'\").fetchone();print(r[0],r[1])")
ck "asset accumulated=200, carrying=11800" "$(approx "$(echo $ACC|cut -d' ' -f1)" 200 && approx "$(echo $ACC|cut -d' ' -f2)" 11800 && echo 1||echo 0)" "$ACC"
# 6. re-run same month blocked
RE=$(H -X POST "$B/api/depreciation/run" -d '{"month":"2026-06"}')
ck "duplicate depreciation run blocked" "$([ "$(echo "$RE"|J ok)" = False ] && echo 1||echo 0)" "$RE"
# 7. trial balance ties
TB=$(H "$B/api/ledger-summary" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2),round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out" "$(approx "$(echo $TB|cut -d' ' -f1)" "$(echo $TB|cut -d' ' -f2)" && echo 1||echo 0)" "$TB"

echo ""; echo "==== PHP PHASE 3c (assets): $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
