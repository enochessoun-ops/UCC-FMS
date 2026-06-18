#!/usr/bin/env python3
"""
Tax-liability audit (READ-ONLY).

Detects the "edit-after-remit" corruption: a posted PV whose withholding was
already remitted (withholding_payables.status='Paid') and whose voucher was
later REVERSED by an edit -- which can leave a phantom debit in the tax-
liability account (negative/understated balance, reversals mis-read as
payments on the Tax Pack).

For each vendor-withholding head (WHT / WHVAT / UCF) it reconciles:
      GL balance (Cr - Dr)   ==   outstanding (Pending) withholdings
If they differ, the head is flagged and the contributing PVs are listed so an
accountant can post a proper correcting journal (with the correct counter-
account -- this tool never writes to the ledger).

Usage:
    python3 tax_liability_audit.py                 # auto-resolve app DB
    python3 tax_liability_audit.py --db /path/to/app.db
"""
import argparse, os, sqlite3, sys
from pathlib import Path

HEADS = {'WHT': '21100014', 'WHVAT': '21100024', 'UCF': '21100027'}
# payroll/statutory heads recognised by payroll (NOT tracked in withholding_payables)
PAYROLL_HEADS = {'PAYE': '21100017', 'SSNIT': '21100015'}


def resolve_db(explicit):
    if explicit:
        return explicit
    here = Path(__file__).parent
    data_dir = os.environ.get('RENDER_DATA_DIR')
    cands = []
    if data_dir:
        cands += [Path(data_dir) / 'sbs_fms.db', Path(data_dir) / 'aoi_fms.db']
    cands += [here / 'sbs_fms.db', here / 'aoi_fms.db']
    for p in cands:
        if p.exists() and p.stat().st_size > 0:
            return str(p)
    sys.exit('No database found. Pass --db /path/to/app.db')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--db', help='path to the SQLite DB (defaults to the app DB)')
    args = ap.parse_args()
    db = resolve_db(args.db)
    print('Auditing: %s\n' % db)
    c = sqlite3.connect(db)
    c.row_factory = sqlite3.Row
    q = lambda s, *a: c.execute(s, a).fetchall()

    problems = 0
    print('=== Vendor-withholding reconciliation (GL balance vs outstanding) ===')
    for t, code in HEADS.items():
        gl = q("SELECT COALESCE(SUM(credit_amount),0)-COALESCE(SUM(debit_amount),0) b "
               "FROM general_ledger WHERE coa_code=?", code)[0]['b']
        pend = q("SELECT COALESCE(SUM(amount_ghs),0) s FROM withholding_payables "
                 "WHERE payable_type=? AND status='Pending'", t)[0]['s']
        paid = q("SELECT COALESCE(SUM(amount_ghs),0) s FROM withholding_payables "
                 "WHERE payable_type=? AND status='Paid'", t)[0]['s']
        diff = round(gl - pend, 2)
        ok = abs(diff) < 0.005
        if not ok:
            problems += 1
        flag = 'OK' if ok else ('MISMATCH (%s by %.2f)' %
                                ('understated/phantom-debit' if diff < 0 else 'overstated', abs(diff)))
        print('  %-6s %s  GL=%10.2f  outstanding=%9.2f  remitted=%9.2f  -> %s'
              % (t, code, gl, pend, paid, flag))

    print('\n=== Edit-after-remit candidates (Paid withholding + reversed voucher) ===')
    rows = q("""SELECT DISTINCT w.actual_id aid, a.description d
                FROM withholding_payables w JOIN actuals a ON a.id = w.actual_id
                WHERE w.status='Paid'
                  AND EXISTS (SELECT 1 FROM journal_vouchers j
                              WHERE j.source_id = w.actual_id AND j.status='Reversed')""")
    if not rows:
        print('  (none)')
    for r in rows:
        # per-head net GL contribution for this actual's journals
        jvs = [x['jv_number'] for x in q(
            "SELECT jv_number FROM journal_vouchers WHERE source_id=?", r['aid'])]
        # include reversal RJVs that reference those PVs
        detail = []
        for t, code in HEADS.items():
            if not jvs:
                continue
            ph = ','.join('?' * len(jvs))
            net = q("SELECT COALESCE(SUM(credit_amount),0)-COALESCE(SUM(debit_amount),0) b "
                    "FROM general_ledger WHERE coa_code=? AND jv_number IN (%s)" % ph,
                    code, *jvs)[0]['b']
            if abs(net) > 0.005:
                detail.append('%s net=%.2f' % (t, net))
        print('  actual %s | %s | recognitions: %s' % (
            r['aid'][:8], (r['d'] or '')[:42],
            '; '.join(detail) or '0'))
    if rows:
        print('  (candidates only -- the account reconciliation above is the authoritative'
              ' corruption test; "recognitions" excludes offsetting reversals/settlements)')

    print('\n=== Payroll statutory liabilities (informational; not edit-bug related) ===')
    for t, code in PAYROLL_HEADS.items():
        gl = q("SELECT COALESCE(SUM(credit_amount),0)-COALESCE(SUM(debit_amount),0) b "
               "FROM general_ledger WHERE coa_code=?", code)[0]['b']
        print('  %-6s %s  GL balance (owed, awaiting remittance) = %10.2f' % (t, code, gl))

    print('\nVERDICT: %s' % ('NO withholding-account corruption found.' if problems == 0
                             else '%d head(s) mismatched -- review the candidates above and post a '
                                  'correcting journal with the proper counter-account.' % problems))
    c.close()
    sys.exit(1 if problems else 0)


if __name__ == '__main__':
    main()
