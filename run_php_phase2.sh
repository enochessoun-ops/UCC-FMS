#!/usr/bin/env bash
# PHP port — Phase 2 (Accounting core) acceptance gate.
# Boots the PHP front controller against a Python-seeded DB and asserts the
# accounting engine: chart of accounts, the balanced-journal gate (accept/reject),
# period guard, sequential JV numbers, JV→GL posting, and that the trial balance
# (ledger-summary) ties out. Exits non-zero on any failure.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5079}"
PYDATA="$(mktemp -d /tmp/sbs_php2.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
P=0; Fn=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; P=$((P+1)); else echo "  [FAIL] $1 — $3"; Fn=$((Fn+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT
J(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }

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
curl -s -X POST "$B/api/go-live-enforcement/mode" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' -d '{"mode":"UAT"}' >/dev/null

# 1. chart of accounts: find bank/revenue/expense ids (same prefixes smoke uses)
COA=$(curl -s "$B/api/coa" -H "X-Session-ID: $SID")
read BANK REV EXP NACC < <(echo "$COA" | python3 -c "
import sys,json
c=json.load(sys.stdin)
def pref(*ps):
  for p in ps:
    for a in c:
      if str(a.get('code','')).startswith(p): return a['id']
  return ''
print(pref('127','126'), pref('4'), pref('63','62'), len(c))")
ck "chart of accounts loaded (bank/rev/exp resolved)" "$([ -n "$BANK" ] && [ -n "$REV" ] && [ -n "$EXP" ] && echo 1||echo 0)" "accounts=$NACC bank=$BANK rev=$REV exp=$EXP"

# 2. balanced JV posts to GL
JV=$(curl -s -X POST "$B/api/jvs" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' \
  -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"PHP2 donor receipt\",\"lines\":[{\"coa_id\":\"$BANK\",\"debit_amount\":5000,\"credit_amount\":0},{\"coa_id\":\"$REV\",\"debit_amount\":0,\"credit_amount\":5000}]}")
JID=$(echo "$JV" | J id); JNUM=$(echo "$JV" | J jv_number)
ck "balanced JV created with sequential number" "$([ -n "$JID" ] && echo "$JNUM" | grep -q 'JV-2026-' && echo 1||echo 0)" "$JV"
PR=$(curl -s -X POST "$B/api/journal-vouchers/post" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' -d "{\"jv_id\":\"$JID\"}")
ck "JV posts to GL (status Posted)" "$([ "$(echo "$PR" | J status)" = Posted ] && echo 1||echo 0)" "$PR"

# 3. balanced-journal gate rejects an UNBALANCED JV
UB=$(curl -s -X POST "$B/api/jvs" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' \
  -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"unbalanced\",\"lines\":[{\"coa_id\":\"$BANK\",\"debit_amount\":5000,\"credit_amount\":0},{\"coa_id\":\"$REV\",\"debit_amount\":0,\"credit_amount\":4000}]}")
ck "balanced-journal gate REJECTS unbalanced JV" "$([ "$(echo "$UB" | J ok)" = False ] && echo 1||echo 0)" "$UB"

# 4. gate rejects a line with both debit AND credit
BC=$(curl -s -X POST "$B/api/jvs" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' \
  -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"bothdc\",\"lines\":[{\"coa_id\":\"$BANK\",\"debit_amount\":10,\"credit_amount\":10},{\"coa_id\":\"$REV\",\"debit_amount\":0,\"credit_amount\":10}]}")
ck "gate REJECTS a debit+credit-on-one-line JV" "$([ "$(echo "$BC" | J ok)" = False ] && echo 1||echo 0)" "$BC"

# 5. period guard rejects a CLOSED period (close 2025-01 via sqlite, then try to post to it)
python3 - "$DB" <<PYEOF
import sqlite3,sys,uuid
c=sqlite3.connect(sys.argv[1])
c.execute("INSERT OR REPLACE INTO accounting_periods(id,period,period_name,status) VALUES(?,?,?, 'Closed')",(str(uuid.uuid4()),'2025-01','2025-01'))
c.commit()
PYEOF
CP=$(curl -s -X POST "$B/api/jvs" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' \
  -d "{\"jv_type\":\"JV\",\"jv_date\":\"2025-01-10\",\"period\":\"2025-01\",\"description\":\"closedperiod\",\"lines\":[{\"coa_id\":\"$BANK\",\"debit_amount\":5,\"credit_amount\":0},{\"coa_id\":\"$REV\",\"debit_amount\":0,\"credit_amount\":5}]}")
ck "period guard REJECTS a Closed period" "$([ "$(echo "$CP" | J ok)" = False ] && echo 1||echo 0)" "$CP"

# 6. second JV gets the NEXT sequential number
JV2=$(curl -s -X POST "$B/api/jvs" -H "X-Session-ID: $SID" -H 'Content-Type: application/json' \
  -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"second\",\"lines\":[{\"coa_id\":\"$EXP\",\"debit_amount\":200,\"credit_amount\":0},{\"coa_id\":\"$BANK\",\"debit_amount\":0,\"credit_amount\":200}]}")
JNUM2=$(echo "$JV2" | J jv_number)
ck "sequential numbering advances ($JNUM -> $JNUM2)" "$([ -n "$JNUM2" ] && [ "$JNUM2" != "$JNUM" ] && echo 1||echo 0)" "$JNUM2"

# 7. trial balance (ledger-summary) ties out
TB=$(curl -s "$B/api/ledger-summary" -H "X-Session-ID: $SID")
read DRT CRT < <(echo "$TB" | python3 -c "import sys,json;r=json.load(sys.stdin);print(round(sum(float(x.get('total_debit') or 0) for x in r),2), round(sum(float(x.get('total_credit') or 0) for x in r),2))")
ck "trial balance ties out (Dr=$DRT == Cr=$CRT, includes the 5000 posting)" "$([ "$DRT" = "$CRT" ] && python3 -c "import sys;sys.exit(0 if $DRT>=5000 else 1)" && echo 1||echo 0)" "Dr=$DRT Cr=$CRT"

echo ""; echo "==== PHP PHASE 2: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
