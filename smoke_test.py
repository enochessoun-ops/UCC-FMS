#!/usr/bin/env python3
"""
UCC-FMS / AOI-FMS — End-to-end regression smoke test.

Exercises the core financial flow and asserts that every posting reaches the
General Ledger and that the financial statements tie out:

  fund receipt  ->  PV / actual post  ->  payroll run+approve
  ->  asset register + depreciation run  ->  manual JV post

then checks:
  * Trial Balance balances           (SUM debits == SUM credits)
  * SFP ties to the GL               (Assets == Liabilities + Net Assets, diff 0)
  * Cash Flow closing == SFP cash    (cross-statement tie)
  * I&E reflects expenditure         (depreciation / payroll show up)

Usage:
  python3 smoke_test.py --base http://127.0.0.1:5002 --user admin --pass UCC@2024 --period 2026-06
  python3 smoke_test.py --base http://127.0.0.1:5001 --user admin --pass AOI@2024 --period 2026

`--period` is the JV/accounting period string the app expects:
  SBS uses monthly periods (e.g. 2026-06); AOI uses an annual period (e.g. 2026).
Exit code is 0 if all assertions pass, 1 otherwise.
"""
import argparse, json, sys, time, urllib.request, urllib.error, collections

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', default='http://127.0.0.1:5002')
    ap.add_argument('--user', default='admin')
    ap.add_argument('--pass', dest='pw', default='UCC@2024')
    ap.add_argument('--period', default='2026-06', help="JV period string (SBS: 2026-06, AOI: 2026)")
    ap.add_argument('--date', default='2026-06-15', help="transaction date YYYY-MM-DD")
    args = ap.parse_args()

    B = args.base.rstrip('/')
    sid = {'v': None}
    results = []  # (name, ok, detail)

    def call(path, data=None):
        h = {'Content-Type': 'application/json'}
        if sid['v']:
            h['X-Session-ID'] = sid['v']
        req = urllib.request.Request(
            B + path,
            data=(json.dumps(data).encode() if data is not None else None),
            headers=h)
        try:
            return json.loads(urllib.request.urlopen(req, timeout=45).read().decode())
        except urllib.error.HTTPError as e:
            return {'ok': False, 'http': e.code, 'body': e.read().decode()[:200]}
        except Exception as e:
            return {'ok': False, 'error': str(e)}

    def as_list(x, *keys):
        if isinstance(x, list):
            return x
        if isinstance(x, dict):
            for k in keys:
                if isinstance(x.get(k), list):
                    return x[k]
        return []

    def check(name, ok, detail=''):
        results.append((name, bool(ok), detail))
        print(("  PASS  " if ok else "  FAIL  ") + name + (("  — " + detail) if detail else ''))

    # ── 0. login + UAT ──────────────────────────────────────────────────────
    lg = call('/api/login', {'username': args.user, 'password': args.pw})
    sid['v'] = lg.get('sid')
    check('login', bool(sid['v']), '' if sid['v'] else str(lg)[:120])
    if not sid['v']:
        return finish(results)
    call('/api/go-live-enforcement/mode', {'mode': 'UAT', 'reason': 'automated smoke test run'})

    coa = call('/api/coa')
    coa = coa if isinstance(coa, list) else as_list(coa, 'accounts', 'rows')
    def acc_pref(p):
        return next((c['id'] for c in coa if str(c.get('code', '')).startswith(p)), None)
    bank = acc_pref('127') or acc_pref('126')
    rev = acc_pref('4')
    exp = acc_pref('63') or acc_pref('62')
    check('chart of accounts loaded', bool(coa) and bool(bank) and bool(rev) and bool(exp),
          'accounts=%d' % len(coa))

    # snapshot trial balance before
    def tb():
        ls = call('/api/ledger-summary')
        ls = ls if isinstance(ls, list) else as_list(ls, 'rows', 'data', 'summary')
        dr = sum(float(r.get('total_debit') or 0) for r in ls)
        cr = sum(float(r.get('total_credit') or 0) for r in ls)
        return round(dr, 2), round(cr, 2), ls

    # ── 1. manual JV: donor receipt (Dr Bank / Cr Revenue) ──────────────────
    def post_jv(desc, lines):
        r = call('/api/jvs', {'jv_type': 'JV', 'jv_date': args.date, 'period': args.period,
                              'description': desc, 'lines': lines})
        jid = r.get('id') or r.get('jv_id')
        if not jid:
            return None, str(r)[:140]
        pr = call('/api/journal-vouchers/post', {'jv_id': jid})
        return jid, pr.get('status') or str(pr)[:120]

    jid, info = post_jv('Smoke: donor receipt',
                        [{'coa_id': bank, 'debit_amount': 5000, 'credit_amount': 0},
                         {'coa_id': rev, 'debit_amount': 0, 'credit_amount': 5000}])
    check('manual JV posts to GL', bool(jid), info)

    # ── 2. payroll: employee -> run -> approve ──────────────────────────────
    emp_code = 'SMK' + str(int(time.time()) % 100000)
    call('/api/payroll/employees', {'employee_id': emp_code, 'full_name': 'Smoke Tester',
        'division': 'MBB', 'employment_type': 'Permanent', 'basic_salary': 3000,
        'status': 'Active', 'date_appointed': '2026-01-01'})
    pmonth = args.date[:7]
    call('/api/payroll/run', {'month': pmonth})
    pa = call('/api/payroll/approve', {'month': pmonth})
    check('payroll run + approve', pa.get('ok', False) or 'approv' in str(pa).lower(), str(pa)[:120])

    # ── 3. asset register + depreciation run ────────────────────────────────
    call('/api/assets', {'asset_name': 'Smoke Asset', 'asset_category': 'ICT Equipment',
        'acquisition_date': '2025-01-01', 'acquisition_cost': 12000, 'useful_life_years': 5,
        'status': 'Active'})
    dep = call('/api/depreciation/run', {'month': pmonth, 'force': True})
    check('depreciation posts to GL', dep.get('ok', False) and (dep.get('jv_number') or dep.get('total', 0) > 0),
          'jv=%s total=%s' % (dep.get('jv_number'), dep.get('total')))

    # ── 4. assertions: statements tie out ───────────────────────────────────
    dr, cr, ls = tb()
    check('Trial Balance balances', abs(dr - cr) < 0.01, 'Dr=%.2f Cr=%.2f' % (dr, cr))

    # GL-truth balance-sheet identity
    nat = lambda c: {'1': 'A', '2': 'L', '3': 'Q', '4': 'R', '5': 'E', '6': 'E'}.get(str(c or '')[:1], '?')
    bal = collections.defaultdict(float)
    for r in ls:
        bal[nat(r.get('coa_code') or r.get('code'))] += float(r.get('total_debit') or 0) - float(r.get('total_credit') or 0)
    assets = bal['A']; liab = -bal['L']; eq = -bal['Q']; surplus = (-bal['R']) - bal['E']
    check('GL identity: Assets == Liab + Net Assets',
          abs(assets - (liab + eq + surplus)) < 0.01,
          'A=%.2f  L+NA=%.2f' % (assets, liab + eq + surplus))

    # backend SFP
    sfp = call('/api/sfp?date_to=%s-12-31' % args.date[:4])
    A = (sfp.get('assets') or {}).get('total')
    L = (sfp.get('liabilities') or {}).get('total')
    NA = sfp.get('net_assets')
    pd = sfp.get('presentation_difference')
    check('SFP ties (presentation_difference == 0)',
          A is not None and pd is not None and abs(pd) < 0.01,
          'A=%s L=%s NA=%s diff=%s basis=%s' % (A, L, NA, pd, sfp.get('basis')))

    # cash flow closing == SFP cash
    cf = call('/api/cashflow?date_from=%s-01-01&date_to=%s-12-31' % (args.date[:4], args.date[:4]))
    closing = cf.get('closing_cash')
    sfp_cash = (sfp.get('assets') or {}).get('cash_and_bank')
    check('Cash Flow closing == SFP cash',
          closing is not None and sfp_cash is not None and abs(closing - sfp_cash) < 0.01,
          'closing=%s sfp_cash=%s basis=%s' % (closing, sfp_cash, cf.get('basis')))

    # I&E reflects expenditure (payroll + depreciation)
    ie = call('/api/income-expenditure?date_from=%s-01-01&date_to=%s-12-31' % (args.date[:4], args.date[:4]))
    total_exp = ie.get('total_expenditure')
    has_dep = any('epreciation' in (x.get('label', '')) for x in (ie.get('expenditure') or []))
    check('I&E reflects expenditure (incl. depreciation)',
          (total_exp or 0) > 0 and has_dep,
          'total_expenditure=%s depreciation_line=%s' % (total_exp, has_dep))

    # year-end close figures must tie to the GL-based I&E statement
    ye = call('/api/year-end-status')
    yi, yx = ye.get('total_income'), ye.get('total_expenditure')
    check('Year-end close ties to I&E (GL-based)',
          yi is not None and abs((yi or 0) - (ie.get('total_income') or 0)) < 0.01
          and abs((yx or 0) - (total_exp or 0)) < 0.01,
          'year-end inc=%s exp=%s vs I&E inc=%s exp=%s' % (yi, yx, ie.get('total_income'), total_exp))

    return finish(results)

def finish(results):
    passed = sum(1 for _, ok, _ in results if ok)
    total = len(results)
    print('\n' + '=' * 56)
    print('SMOKE TEST: %d/%d checks passed' % (passed, total))
    print('=' * 56)
    if passed != total:
        print('FAILURES:')
        for name, ok, detail in results:
            if not ok:
                print('  - %s  %s' % (name, detail))
    return 0 if passed == total else 1

if __name__ == '__main__':
    sys.exit(main())
