#!/usr/bin/env python3
"""Federated org-unit (tree) acceptance gate for UCC-FMS.

The finance gate (regression_fixes.py / smoke_test.py) protects the finance-correctness
contract. THIS suite protects the FEDERATED-UNIT contract that the tree audit established:
a unit only ever sees its own subtree, postings attribute to the right unit, parent
statements roll up their children, inter-unit transfers stay balanced per unit, payroll
cost lands on each employee's unit, scope fails CLOSED, and the tree builder rejects cycles.

Black-box HTTP, like the other gates (creates its own test data on a UAT/preview DB):
  python3 tree_acceptance.py --base http://127.0.0.1:8421 --user admin --pass UCC@2024

It assumes the standard UCC org tree is seeded (UCC > CANS > CANS-SBS > CANS-SBS-DEPT1,
and a sibling CHLS). Each check maps to a behaviour the tree audit (batches 1-12) shipped.
"""
import argparse, json, sys, uuid, urllib.request, urllib.error

ap = argparse.ArgumentParser()
ap.add_argument('--base', required=True)
ap.add_argument('--user', default='admin')
ap.add_argument('--pass', dest='pw', required=True)
ap.add_argument('--period', default='2026-09')   # a clean month for the payroll leg
args = ap.parse_args()
B = args.base.rstrip('/')


def client(user, pw):
    sid = {'v': None}
    def call(path, data=None, method=None):
        h = {'Content-Type': 'application/json'}
        if sid['v']:
            h['X-Session-ID'] = sid['v']
        req = urllib.request.Request(B + path,
            data=(json.dumps(data).encode() if data is not None else None), headers=h, method=method)
        try:
            return json.loads(urllib.request.urlopen(req, timeout=45).read().decode() or '{}')
        except urllib.error.HTTPError as e:
            try:
                return json.loads(e.read().decode() or '{}')
            except Exception:
                return {'ok': False, 'http': e.code}
        except Exception as e:
            return {'ok': False, 'error': str(e)}
    lg = call('/api/login', {'username': user, 'password': pw})
    sid['v'] = lg.get('sid')
    return call, bool(sid['v'])


results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail))
    print(("  PASS  " if ok else "  FAIL  ") + name + (("  - " + detail) if detail else ''))


def tb_debit(call, unit=None):
    """Total debit on the trial balance, optionally scoped to a unit subtree. Use a wide
    period window so test postings in any month are captured (the TB defaults to <= today)."""
    q = '/api/trial-balance?period_to=2027-12-31'
    if unit:
        q += '&unit=' + unit
    r = call(q)
    return round(float((r or {}).get('total_debit') or 0), 2), bool((r or {}).get('balanced'))


admin, ok_login = client(args.user, args.pw)
check('admin login', ok_login)
if not ok_login:
    sys.exit(1)

# ── Fixtures: a rare expense + a liability account, and a scoped user homed at CANS ──
coa = admin('/api/coa')
coa = coa if isinstance(coa, list) else (coa.get('data') or [])
exp = next((c['id'] for c in coa if str(c.get('code', '')).startswith('6')), None)
cr = next((c['id'] for c in coa if str(c.get('code', '')).startswith('2')), None)
units = admin('/api/org-units')
units = units if isinstance(units, list) else (units.get('units') or units.get('data') or [])
uid = {u['code']: u['id'] for u in units}
have_tree = all(c in uid for c in ('CANS', 'CHLS', 'CANS-SBS-DEPT1'))
check('standard org tree seeded (CANS / CHLS / CANS-SBS-DEPT1)', have_tree)
if not (exp and cr and have_tree):
    print('REQUIRED FIXTURES MISSING — cannot run tree gate'); sys.exit(1)

tag = uuid.uuid4().hex[:6]
cans_user = 'cans_' + tag
own_user = 'own_' + tag
admin('/api/users', {'username': cans_user, 'full_name': 'Gate CANS Head', 'role': 'Finance Officer',
                     'password': 'Gate@2024', 'home_unit_id': uid['CANS'], 'scope': 'subtree'})
admin('/api/users', {'username': own_user, 'full_name': 'Gate Orphan', 'role': 'Finance Officer',
                     'password': 'Gate@2024', 'scope': 'own_unit'})  # NO home unit -> must fail closed
cans, ok_cu = client(cans_user, 'Gate@2024')
check('scoped user (subtree@CANS) can log in', ok_cu)
orphan, ok_ou = client(own_user, 'Gate@2024')

# ── Baselines ──
base_all, _ = tb_debit(admin)
base_cans, _ = tb_debit(admin, 'CANS')
base_chls, _ = tb_debit(admin, 'CHLS')
base_cans_user, _ = tb_debit(cans)


def post_jv(unit_code, amount, narr):
    j = admin('/api/jvs', {'jv_date': args.period + '-15', 'period': args.period, 'narration': narr,
                           'unit_code': unit_code,
                           'lines': [{'coa_id': exp, 'debit_amount': amount, 'credit_amount': 0, 'narration': narr},
                                     {'coa_id': cr, 'debit_amount': 0, 'credit_amount': amount, 'narration': narr}]})
    jid = j.get('id')
    if jid:
        admin('/api/journal-vouchers/post', {'id': jid})
    return j.get('jv_number')


jv_cans = post_jv('CANS', 700000, 'gate CANS ' + tag)
jv_chls = post_jv('CHLS', 300000, 'gate CHLS ' + tag)
jv_dept = post_jv('CANS-SBS-DEPT1', 50000, 'gate DEPT ' + tag)

aft_all, bal_all = tb_debit(admin)
aft_cans, _ = tb_debit(admin, 'CANS')
aft_chls, _ = tb_debit(admin, 'CHLS')
aft_cans_user, _ = tb_debit(cans)

d_all = round(aft_all - base_all, 2)
d_cans = round(aft_cans - base_cans, 2)
d_chls = round(aft_chls - base_chls, 2)
d_cans_user = round(aft_cans_user - base_cans_user, 2)

# 1. Write attribution + subtree roll-up: CANS subtree picks up its own 700k AND the
#    CANS-SBS-DEPT1 leaf's 50k (rolled up two levels), but NOT the CHLS 300k.
check('write attribution + subtree roll-up (CANS subtree = 750,000)',
      d_cans == 750000.0, 'delta CANS=%.0f (expect 750000)' % d_cans)

# 2. Sibling isolation: CHLS sees only its own 300k (not CANS 700k, not the DEPT 50k).
check('sibling isolation (CHLS subtree = 300,000, excludes CANS+DEPT)',
      d_chls == 300000.0, 'delta CHLS=%.0f (expect 300000)' % d_chls)

# 3. Consolidation ties: whole-institution delta == sum of the unit subtrees.
check('consolidation ties (all = CANS + CHLS = 1,050,000)',
      d_all == 1050000.0 and abs(d_all - (d_cans + d_chls)) < 0.01, 'delta all=%.0f' % d_all)

# 4. Scoped-user read isolation: the subtree@CANS user sees EXACTLY the CANS subtree
#    (same as admin?unit=CANS) and never the CHLS posting.
check('scoped user sees only own subtree (== admin?unit=CANS, excludes CHLS)',
      d_cans_user == 750000.0, 'delta scoped-user=%.0f (expect 750000)' % d_cans_user)

# 4b. Horizontal bypass guard: a scoped user passing ?unit=<sibling> must STILL see
#     nothing — the requested node is intersected with the caller's own scope, so it
#     cannot widen their view by naming another unit (regression guard for the scope
#     bypass the security review caught).
bypass_chls, _ = tb_debit(cans, 'CHLS')
bypass_ucc, _ = tb_debit(cans, 'UCC')
check('scoped user CANNOT bypass scope via ?unit=<other node>',
      bypass_chls == 0.0 and bypass_ucc == aft_cans_user,
      'cans-user ?unit=CHLS=%.0f (expect 0), ?unit=UCC=%.0f (expect own %.0f)' % (bypass_chls, bypass_ucc, aft_cans_user))

# 5. Scoped LIST isolation: the CANS user's JV list has the CANS voucher, not the CHLS one.
jl = cans('/api/jvs')
jl = jl if isinstance(jl, list) else (jl.get('jvs') or jl.get('data') or [])
nums = {r.get('jv_number') for r in jl}
check('scoped JV list shows own unit, hides sibling',
      (jv_cans in nums) and (jv_chls not in nums), 'cans=%s chls_hidden=%s' % (jv_cans in nums, jv_chls not in nums))

# 6. Admin is unrestricted (the finance gate depends on this).
ja = admin('/api/jvs')
ja = ja if isinstance(ja, list) else (ja.get('jvs') or ja.get('data') or [])
anums = {r.get('jv_number') for r in ja}
check('admin is unrestricted (sees every unit)', {jv_cans, jv_chls, jv_dept} <= anums)

# 7. Fail-closed: a scoped user with no home unit sees nothing.
if ok_ou:
    od, _ = tb_debit(orphan)
    oj = orphan('/api/jvs')
    oj = oj if isinstance(oj, list) else (oj.get('jvs') or oj.get('data') or [])
    check('scope fails CLOSED (no home unit -> sees no data)', od == 0.0 and len(oj) == 0,
          'tb_debit=%.0f jvs=%d' % (od, len(oj)))
else:
    check('scope fails CLOSED (no home unit -> sees no data)', False, 'orphan user could not log in')

# 8. Inter-unit transfer: balanced clearing JV, each leg on its own unit; TB stays balanced.
t = admin('/api/interunit-transfers', {'from_unit': 'CANS', 'to_unit': 'CHLS', 'amount_ghs': 200000,
                                       'transfer_date': args.period + '-20', 'description': 'gate IUT ' + tag})
appr = admin('/api/interunit-transfers/approve', {'id': t.get('id')}) if t.get('id') else {}
chls_after_iut, _ = tb_debit(admin, 'CHLS')
all_after_iut, bal_after_iut = tb_debit(admin)
# Receiving unit (CHLS) gets the clearing DEBIT (+200000); whole ledger stays balanced.
check('inter-unit transfer posts a balanced per-unit clearing JV',
      bool(appr.get('posted')) and bal_after_iut and round(chls_after_iut - aft_chls, 2) == 200000.0,
      'posted=%s balanced=%s chls_delta=%.0f' % (appr.get('posted'), bal_after_iut, chls_after_iut - aft_chls))

# 9. Per-unit payroll GL: salary cost lands on the employee's unit, not the root/another unit.
#    Use a per-run unique month (inside the TB window) so re-runs aren't blocked by the
#    "month already approved" lock.
pay_month = '2027-%02d' % (1 + (int(tag, 16) % 12))
admin('/api/payroll/employees', {'full_name': 'Gate CANS Staff ' + tag, 'unit_code': 'CANS',
                                 'basic_salary': 8000, 'ssnit_member': 1, 'tier1_member': 1, 'status': 'Active'})
pre_cans_pay, _ = tb_debit(admin, 'CANS')
pre_chls_pay, _ = tb_debit(admin, 'CHLS')
admin('/api/payroll/run', {'month': pay_month})
admin('/api/payroll/approve', {'month': pay_month})
post_cans_pay, _ = tb_debit(admin, 'CANS')
post_chls_pay, _ = tb_debit(admin, 'CHLS')
check('payroll GL tags salary to the employee unit (CANS up, CHLS unchanged)',
      round(post_cans_pay - pre_cans_pay, 2) > 0 and round(post_chls_pay - pre_chls_pay, 2) == 0.0,
      'CANS+%.0f CHLS+%.0f' % (post_cans_pay - pre_cans_pay, post_chls_pay - pre_chls_pay))

# 10. Tree builder: create a child unit OK; a cycle is rejected.
nu = 'GATE-' + tag.upper()
mk = admin('/api/departments', {'code': nu, 'name': 'Gate Test Unit', 'unit_type': 'Department', 'parent_code': 'CANS-SBS'})
cyc = admin('/api/departments', {'code': 'CANS', 'name': 'x', 'unit_type': 'College', 'parent_code': 'CANS-SBS-DEPT1'})
check('tree builder creates a node and REJECTS a cycle',
      bool(mk.get('ok')) and (cyc.get('ok') is False), 'created=%s cycle_rejected=%s' % (mk.get('ok'), cyc.get('ok') is False))

# ── Summary ──
n = len(results); p = sum(1 for _, ok, _ in results if ok)
print('\nTREE / FEDERATED-UNIT ACCEPTANCE: %d/%d checks passed' % (p, n))
sys.exit(0 if p == n else 1)
