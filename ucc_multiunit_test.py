#!/usr/bin/env python3
"""UCC multi-unit acceptance gate.

Codifies the institutional guarantees added for the University of Cape Coast
roll-out so they cannot silently regress (and so the PHP port can certify
parity). Runs IN-PROCESS against a fresh database — no server required.

Proves, on real General Ledger postings to two sibling units (CANS-SBS and
CANS-SOA under the CANS college):

  1. Write attribution  — postings carry their unit on BOTH journal_vouchers
                          and general_ledger.
  2. Isolation          — a unit user sees ONLY their unit's ledger/reports.
  3. Subtree roll-up    — a college (provost / node) sees the sum of its schools.
  4. University view     — an Admin / no-node sees everything.
  5. Consolidation tie-out — Σ(per-unit trial balances) == university trial
                          balance, per account and in total.
  6. Source-doc isolation — a unit user's operational lists (AR invoices, AP
                          bills) show only their own unit's documents.
  7. Petty-cash isolation — floats are unit-owned; a unit user sees only its own
                          float, vouchers, and ledger.
  8. Per-unit dashboard — budgets/projects are unit-attributed, so the executive
                          dashboard reflects the viewer's unit (admins drill down).

Exit code 0 if all pass, 1 otherwise.

Usage:  RENDER_DATA_DIR=$(mktemp -d) python3 ucc_multiunit_test.py
        (run_ucc_multiunit.sh wraps this with a throwaway data dir)
"""
import os, sys, uuid

R = []
def check(name, ok, detail=''):
    R.append((name, bool(ok)))
    print("  [%s] %s%s" % ('PASS' if ok else 'FAIL', name, (' — ' + str(detail)) if detail else ''))

def main():
    if not os.environ.get('RENDER_DATA_DIR'):
        print('Set RENDER_DATA_DIR to a fresh empty dir first.'); return 1
    import server
    server.init_db(); server.init_payroll_db(); server.init_jv_db()
    conn = server.get_db()

    def oid(code):
        return conn.execute("SELECT id FROM org_units WHERE code=?", (code,)).fetchone()[0]
    sbs, soa, cans = oid('CANS-SBS'), oid('CANS-SOA'), oid('CANS')

    for u, h, sc in [('sbsu', sbs, 'own_unit'), ('soau', soa, 'own_unit'), ('prov', cans, 'subtree')]:
        conn.execute("INSERT OR REPLACE INTO users(id,username,password_hash,full_name,role,email,active,home_unit_id,scope) "
                     "VALUES(?,?,?,?,?,?,1,?,?)",
                     (str(uuid.uuid4()), u, 'x', u, 'Finance Officer', 'e', h, sc))
    conn.commit()

    exp = conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '6%' LIMIT 1").fetchone()[0]
    cash = conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '126%' OR code LIKE '127%' LIMIT 1").fetchone()[0]
    expc = conn.execute("SELECT code FROM chart_of_accounts WHERE id=?", (exp,)).fetchone()[0]

    # SBS expense 100, SOA expense 200 (auto-posted; unit derived from poster's home unit)
    server._insert_voucher_posted(conn, {'username': 'sbsu', 'role': 'Admin'}, jv_type='JV', jv_date='2026-03-15',
        description='sbs exp', lines=[{'coa_id': exp, 'debit_amount': 100, 'credit_amount': 0},
                                      {'coa_id': cash, 'debit_amount': 0, 'credit_amount': 100}])
    server._insert_voucher_posted(conn, {'username': 'soau', 'role': 'Admin'}, jv_type='JV', jv_date='2026-03-16',
        description='soa exp', lines=[{'coa_id': exp, 'debit_amount': 200, 'credit_amount': 0},
                                      {'coa_id': cash, 'debit_amount': 0, 'credit_amount': 200}])
    conn.commit()

    # 1. Write attribution
    jv_units = [r[0] for r in conn.execute("SELECT unit_id FROM journal_vouchers WHERE description IN ('sbs exp','soa exp')").fetchall()]
    gl_units = [r[0] for r in conn.execute("SELECT DISTINCT unit_id FROM general_ledger").fetchall()]
    check('write attribution: JVs tagged to {SBS,SOA}', set(jv_units) == {sbs, soa}, jv_units)
    check('write attribution: GL only {SBS,SOA}', set(gl_units) == {sbs, soa}, gl_units)
    conn.close()

    admin = {'username': 'admin', 'role': 'Admin'}
    head  = {'username': 'sbsu', 'role': 'Finance Officer'}
    prov  = {'username': 'prov', 'role': 'Finance Officer'}

    def tb_exp(sess, node=None):
        d = server.api_trial_balance_v556(sess, '2026-01-01', '2026-12-31', node, None)
        d = d.get('data', d)
        return next((a['debit'] for a in d.get('accounts', []) if a['code'] == expc), 0)
    def ie_exp(sess, node=None):
        d = server.api_income_expenditure_v556(sess, '2026-01-01', '2026-12-31', node, None)
        return (d.get('data', d)).get('total_expenditure')
    def gl_rows(sess):
        rows = server.api_get_general_ledger(sess)
        return len(rows) if isinstance(rows, list) else -1

    # 2. Isolation
    check('isolation: SBS head sees only its own expense (100)', tb_exp(head) == 100, tb_exp(head))
    check('isolation: SBS head GL listing limited to own rows (2)', gl_rows(head) == 2, gl_rows(head))
    check('isolation: I&E for SBS head = 100', ie_exp(head) == 100, ie_exp(head))

    # 3. Subtree roll-up
    check('roll-up: CANS provost (subtree) sees 300', tb_exp(prov) == 300, tb_exp(prov))
    check('roll-up: node=CANS consolidates schools (300)', tb_exp(admin, 'CANS') == 300, tb_exp(admin, 'CANS'))
    check('roll-up: node=CANS-SBS isolates (100)', tb_exp(admin, 'CANS-SBS') == 100, tb_exp(admin, 'CANS-SBS'))
    check('roll-up: node=CANS-SOA isolates (200)', tb_exp(admin, 'CANS-SOA') == 200, tb_exp(admin, 'CANS-SOA'))

    # 4. University view
    check('university: Admin (no node) sees all (300)', tb_exp(admin) == 300, tb_exp(admin))
    check('university: Admin GL listing sees all rows (4)', gl_rows(admin) == 4, gl_rows(admin))

    # 5. Consolidation tie-out: Σ(unit TBs) == university TB
    sbs_tb = tb_exp(admin, 'CANS-SBS'); soa_tb = tb_exp(admin, 'CANS-SOA'); uni_tb = tb_exp(admin)
    check('consolidation tie-out: SBS + SOA == university', round(sbs_tb + soa_tb, 2) == round(uni_tb, 2),
          '%s + %s == %s' % (sbs_tb, soa_tb, uni_tb))

    # 6. Source-document list isolation (payment-side AR invoices + AP bills): a unit
    #    user's operational lists, not just the ledger, are confined to their unit.
    soau = {'username': 'soau', 'role': 'Finance Officer'}
    def _list(env, key):
        return env.get(key, []) if isinstance(env, dict) else (env if isinstance(env, list) else [])
    conn = server.get_db()
    inc = conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '4%' LIMIT 1").fetchone()[0]
    exp = conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '6%' LIMIT 1").fetchone()[0]
    conn.close()
    cust = server.api_ar_save_customer({'customer_name': 'C', 'customer_code': 'C1'}, admin)
    cid = cust.get('id') if isinstance(cust, dict) else None
    if cid:
        server.api_ar_save_invoice({'customer_id': cid, 'income_coa_id': inc, 'amount_ghs': 100, 'description': 'sbs'}, head)
        server.api_ar_save_invoice({'customer_id': cid, 'income_coa_id': inc, 'amount_ghs': 200, 'description': 'soa'}, soau)
        check('source AR: admin sees both invoices', len(_list(server.api_ar_invoices(admin), 'invoices')) == 2)
        ar_h = _list(server.api_ar_invoices(head), 'invoices')
        check('source AR: SBS user isolated to own invoice',
              len(ar_h) == 1 and abs(float(ar_h[0].get('amount_ghs', 0)) - 100) < 0.01)
    ven = server.api_save_vendor(admin, {'vendor_name': 'V', 'vendor_code': 'V1'})
    vid = ven.get('id') if isinstance(ven, dict) else None
    if vid:
        server.api_ap_save_bill({'vendor_id': vid, 'expense_coa_id': exp, 'amount_ghs': 100, 'description': 'sbs'}, head)
        server.api_ap_save_bill({'vendor_id': vid, 'expense_coa_id': exp, 'amount_ghs': 200, 'description': 'soa'}, soau)
        check('source AP: admin sees both bills', len(_list(server.api_ap_bills(admin), 'bills')) == 2)
        ap_h = _list(server.api_ap_bills(head), 'bills')
        check('source AP: SBS user isolated to own bill',
              len(ap_h) == 1 and abs(float(ap_h[0].get('amount_ghs', 0)) - 100) < 0.01)

    # 7. Petty-cash float isolation (float-centric; vouchers inherit the float's unit)
    server.api_set_go_live_mode({'mode': 'UAT', 'reason': 'multiunit gate'}, admin)
    conn = server.get_db()
    imp = conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '129%' LIMIT 1").fetchone()
    imp = imp[0] if imp else conn.execute("SELECT id FROM chart_of_accounts WHERE code LIKE '12%' LIMIT 1").fetchone()[0]
    dept = conn.execute("SELECT dept_code FROM departments LIMIT 1").fetchone()
    dept = dept[0] if dept else 'ADMIN'
    conn.close()
    bank = server.api_save_bank_account({'account_name': 'PC Bank', 'bank_name': 'CBG', 'account_number': '9',
                                         'currency': 'GHS', 'opening_balance': 50000}, admin)
    bid = bank.get('id') if isinstance(bank, dict) else None
    def _float(sess, nm):
        return server.api_pc2_setup_float({'name': nm, 'custodian': nm, 'imprest_amount': 1000, 'coa_id': imp,
                                           'bank_account_id': bid, 'established_date': '2026-03-01',
                                           'department_code': dept}, sess)
    fS = _float(head, 'SBS Float'); fO = _float(soau, 'SOA Float')
    if fS.get('id') and fO.get('id'):
        server.api_pc2_voucher({'float_id': fS['id'], 'voucher_date': '2026-03-05', 'payee': 'P',
                                'description': 's', 'expense_coa_id': exp, 'amount_ghs': 50}, head)
        server.api_pc2_voucher({'float_id': fO['id'], 'voucher_date': '2026-03-06', 'payee': 'P',
                                'description': 'o', 'expense_coa_id': exp, 'amount_ghs': 70}, soau)
        sa = server.api_pc2_state(admin); sa = sa.get('data', sa)
        sh = server.api_pc2_state(head); sh = sh.get('data', sh)
        check('petty cash: admin sees both floats', len(sa.get('floats', [])) == 2)
        check('petty cash: SBS user sees only its own float',
              len(sh.get('floats', [])) == 1 and all(f.get('unit_id') == sbs for f in sh.get('floats', [])))
        led = server.api_pc2_ledger(head, fO['id']); led = led.get('data', led)
        check("petty cash: SBS user denied another unit's float ledger", led.get('float') is None)
    else:
        check('petty cash: float setup', False, 'setup failed: %s / %s' % (fS.get('error'), fO.get('error')))

    # 8. Per-unit dashboard isolation (budgets/projects are unit-attributed, so the
    #    executive dashboard reflects the viewer's unit; admins/provosts can drill down).
    server.api_save_project({'project_code': 'DPSBS', 'title': 'SBS proj', 'division': 'MBB',
                             'budget_fcy': 1000, 'fx_rate': 1, 'status': 'Active'}, head)
    server.api_save_project({'project_code': 'DPSOA', 'title': 'SOA proj', 'division': 'AGRI',
                             'budget_fcy': 2000, 'fx_rate': 1, 'status': 'Active'}, soau)
    dba = server.api_dashboard(admin); dbh = server.api_dashboard(head)
    dbn = server.api_dashboard(admin, 'CANS-SBS')
    check('dashboard: admin sees both units\' project budget (>=3000)', dba['stats']['project_budget_ghs'] >= 3000)
    check('dashboard: SBS head sees only own project (1, =1000)',
          dbh['stats']['total_projects'] == 1 and abs(dbh['stats']['project_budget_ghs'] - 1000) < 0.01)
    check('dashboard: admin node=CANS-SBS rolls up to 1000', abs(dbn['stats']['project_budget_ghs'] - 1000) < 0.01)

    passed = sum(1 for _, ok in R if ok); total = len(R)
    print('\n==== UCC MULTI-UNIT: %d/%d passed ====' % (passed, total))
    return 0 if passed == total else 1

if __name__ == '__main__':
    sys.exit(main())
