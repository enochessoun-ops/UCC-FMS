#!/usr/bin/env bash
# PHP port — Phase 4 (security hardening): login throttle, TOTP MFA, dual control.
set -u
APPDIR="$(cd "$(dirname "$0")" && pwd)"
PHP_PORT="${1:-5095}"
PYDATA="$(mktemp -d /tmp/sbs_php4.XXXXXX)"; DB="$PYDATA/ucc_fms.db"
P=0; Fn=0
ck(){ if [ "$2" = "1" ]; then echo "  [PASS] $1"; P=$((P+1)); else echo "  [FAIL] $1 — $3"; Fn=$((Fn+1)); fi; }
cleanup(){ [ -n "${PYPID:-}" ] && kill -9 "$PYPID" 2>/dev/null; [ -n "${PHPID:-}" ] && kill -9 "$PHPID" 2>/dev/null; rm -rf "$PYDATA"; }
trap cleanup EXIT
J(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('$1',''))" 2>/dev/null; }
totp(){ python3 -c "import sys,hmac,hashlib,base64,struct,time;k=base64.b32decode('$1');t=int(time.time()//30);h=hmac.new(k,struct.pack('>Q',t),hashlib.sha1).digest();o=h[19]&0xf;print('%06d'%((struct.unpack('>I',h[o:o+4])[0]&0x7fffffff)%1000000))"; }

php -l "$APPDIR/php/index.php" >/dev/null || exit 2
echo "== seed DB via Python reference =="
( cd "$APPDIR" && RENDER_DATA_DIR="$PYDATA" PORT=5066 python3 app.py >"$PYDATA/py.log" 2>&1 ) & PYPID=$!
for i in $(seq 1 30); do curl -s -m 3 "http://127.0.0.1:5066/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' 2>/dev/null | grep -q '"sid"' && break; sleep 1; done
kill -9 "$PYPID" 2>/dev/null; PYPID=""
echo "== boot PHP backend =="
( SBS_DB="$DB" php -S 127.0.0.1:"$PHP_PORT" "$APPDIR/php/index.php" >"$PYDATA/php.log" 2>&1 ) & PHPID=$!
B="http://127.0.0.1:$PHP_PORT"
for i in $(seq 1 20); do curl -s -m 3 "$B/healthz" 2>/dev/null | grep -q '"db":"ok"' && break; sleep 0.5; done
ASID=$(curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d '{"username":"admin","password":"UCC@2024"}' | J sid)
A(){ local s="$1"; shift; curl -s -H "X-Session-ID: $s" -H 'Content-Type: application/json' "$@"; }
lg(){ curl -s -X POST "$B/api/login" -H 'Content-Type: application/json' -d "{\"username\":\"$1\",\"password\":\"$2\"}"; }

# ── 1. Login throttle: 5 wrong → locked out ──
A "$ASID" -X POST "$B/api/users" -d '{"username":"thr","password":"GoodPass99","full_name":"Throttle","role":"Finance Officer"}' >/dev/null
for i in 1 2 3 4 5; do lg thr WRONGPASS >/dev/null; done
LOCK=$(lg thr GoodPass99)   # correct pw, but should be locked now
ck "login throttle: locked after 5 failed attempts" "$([ "$(echo "$LOCK"|J ok)" = False ] && echo 1||echo 0)" "$LOCK"
ck "throttle is per-user (admin still logs in)" "$([ -n "$(lg admin UCC@2024 | J sid)" ] && echo 1||echo 0)" "admin login"

# ── 2. TOTP MFA: enroll → login requires code → verify ──
A "$ASID" -X POST "$B/api/users" -d '{"username":"mfu","password":"MfaPass99x","full_name":"MFA User","role":"Finance Officer"}' >/dev/null
MSID=$(lg mfu MfaPass99x | J sid)
SECRET=$(A "$MSID" -X POST "$B/api/mfa/enroll" -d '{}' | J secret)
ck "MFA enrolled (secret issued)" "$([ -n "$SECRET" ] && echo 1||echo 0)" "$SECRET"
CH=$(lg mfu MfaPass99x)     # now MFA on → should be a challenge, not a sid
ck "MFA-enabled login returns a step-up challenge (no sid)" "$([ "$(echo "$CH"|J mfa_required)" = True ] && [ -z "$(echo "$CH"|J sid)" ] && echo 1||echo 0)" "$CH"
BADV=$(curl -s -X POST "$B/api/verify-mfa" -H 'Content-Type: application/json' -d '{"username":"mfu","code":"000000"}')
ck "MFA rejects a wrong code" "$([ "$(echo "$BADV"|J ok)" = False ] && echo 1||echo 0)" "$BADV"
CODE=$(totp "$SECRET")
GOODV=$(curl -s -X POST "$B/api/verify-mfa" -H 'Content-Type: application/json' -d "{\"username\":\"mfu\",\"code\":\"$CODE\"}")
ck "MFA accepts the current TOTP and issues a session" "$([ -n "$(echo "$GOODV"|J sid)" ] && echo 1||echo 0)" "$GOODV"

# ── 3. Dual control: high-value JV cannot be direct-posted by its preparer ──
A "$ASID" -X POST "$B/api/settings/dual-control" -d '{"threshold":1000}' >/dev/null
read C1 C2 < <(A "$ASID" "$B/api/coa" | python3 -c "import sys,json;c=json.load(sys.stdin);print(c[0]['id'],c[1]['id'])")
A "$ASID" -X POST "$B/api/go-live-enforcement/mode" -d '{"mode":"UAT"}' >/dev/null
JV=$(A "$ASID" -X POST "$B/api/jvs" -d "{\"jv_type\":\"JV\",\"jv_date\":\"2026-06-15\",\"period\":\"2026-06\",\"description\":\"big\",\"lines\":[{\"coa_id\":\"$C1\",\"debit_amount\":5000,\"credit_amount\":0},{\"coa_id\":\"$C2\",\"debit_amount\":0,\"credit_amount\":5000}]}")
JID=$(echo "$JV"|J id)
SELF=$(A "$ASID" -X POST "$B/api/journal-vouchers/post" -d "{\"jv_id\":\"$JID\"}")
ck "dual control: preparer blocked from posting a GHS 5000 JV" "$([ "$(echo "$SELF"|J ok)" = False ] && echo 1||echo 0)" "$SELF"
A "$ASID" -X POST "$B/api/users" -d '{"username":"poster2","password":"PosterPass9","full_name":"Second Admin","role":"Admin"}' >/dev/null
P2=$(lg poster2 PosterPass9 | J sid)
OK2=$(A "$P2" -X POST "$B/api/journal-vouchers/post" -d "{\"jv_id\":\"$JID\"}")
ck "dual control: a DIFFERENT officer can post it" "$([ "$(echo "$OK2"|J status)" = Posted ] && echo 1||echo 0)" "$OK2"

echo ""; echo "==== PHP PHASE 4: $P passed, $Fn FAILED ===="
[ "$Fn" = 0 ] || exit 1
