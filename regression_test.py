#!/usr/bin/env python3
"""End-to-end accounting regression gate.

Posts ONE of every transaction type against a running instance and asserts the
five financial statements reconcile after each step. Exits non-zero on any
failure so it can gate a deploy.

Env:
  REG_BASE  base URL           (default http://127.0.0.1:5099)
  REG_USER  admin username     (default admin)
  REG_PASS  admin password     (required, e.g. UCC@2024 / AOI@2024)

Run against a FRESH database (see run_regression.sh).
"""
import json, subprocess, sys, os
BASE=os.environ.get('REG_BASE','http://127.0.0.1:5099').rstrip('/')
USER=os.environ.get('REG_USER','admin'); PASS=os.environ.get('REG_PASS','UCC@2024')
UNIT=os.environ.get('REG_UNIT','').strip()   # AOI validates units (ACECoR/CeMA/DFAS/ADM); SBS is lenient
JAR='/tmp/reg_gate.txt'; APPJAR='/tmp/reg_gate_approver.txt'; RESULTS=[]; IDS={}

def call(method, path, payload=None, jar=JAR):
    a=['curl','-s','-c',jar,'-b',jar,'-X',method,BASE+path,'-H','Content-Type: application/json']
    if payload is not None: a+=['-d',json.dumps(payload)]
    out=subprocess.run(a,capture_output=True,text=True,timeout=120).stdout
    try: return json.loads(out or '{}')
    except Exception: return {'_raw':out[:300]}
def rec(step, ok, detail=''):
    RESULTS.append((step,bool(ok),detail)); print(f"  [{'PASS' if ok else 'FAIL'}] {step}{' — '+str(detail) if detail else ''}")
def gl_balance():
    gl=call('GET','/api/general-ledger')
    if not isinstance(gl,list): return None,None,None
    dr=sum(float(r.get('debit_amount') or 0) for r in gl); cr=sum(float(r.get('credit_amount') or 0) for r in gl)
    return round(dr,2),round(cr,2),len(gl)
def assert_tb(step):
    dr,cr,n=gl_balance()
    rec(f'{step}: TB balances (Dr={dr} Cr={cr} rows={n})', dr is not None and abs((dr or 0)-(cr or 0))<0.01)

r=call('POST','/api/login',{'username':USER,'password':PASS})
if not r.get('sid'): print('LOGIN FAILED',r); sys.exit(2)
# Start from a truly clean slate — fresh DBs may ship with seeded demo/smoke data
# (e.g. AOI's "Smoke: donor receipt" JVs). The gate must control its starting state
# so its assertions reflect only the transactions it posts.
rz=call('POST','/api/ledger/reset-zero',{'confirm':'RESET'})
print(f"reset-to-zero: {'ok' if rz.get('ok') else rz.get('error')}")
call('POST','/api/go-live-enforcement/mode',{'mode':'UAT','reason':'regression gate'})

# Segregation of duties: a JV preparer cannot approve their own journal, so the gate
# provisions a SECOND authorised officer (Admin) and approves/posts JVs as that user.
# This exercises maker-checker exactly as a UCC auditor expects, rather than weakening it.
APPROVER_USER='reg_approver'; APPROVER_PASS='RegApprove1!'
call('POST','/api/users',{'username':APPROVER_USER,'password':APPROVER_PASS,
    'full_name':'Regression Approver','role':'Admin','email':'reg_approver@example.com'})
_alog=call('POST','/api/login',{'username':APPROVER_USER,'password':APPROVER_PASS},jar=APPJAR)
def call_approver(method, path, payload=None): return call(method, path, payload, jar=APPJAR)
rec('approver provisioned (segregation of duties)', bool(_alog.get('sid')), _alog.get('error',''))

# Resolve accounts dynamically so the gate works on any unit's chart
_COA=call('GET','/api/coa'); _COA=_COA if isinstance(_COA,list) else []
def by_code(code): return next((x.get('id') for x in _COA if (x.get('code')==code or x.get('coa_code')==code)),None)
def by_prefix(*pfx):
    for x in _COA:
        c=str(x.get('code') or x.get('coa_code') or '')
        if c.startswith(pfx): return x.get('id'),c
    return None,None
def code_of(cid): return next((str(x.get('code') or x.get('coa_code')) for x in _COA if x.get('id')==cid),None)
CASH_ID = by_code('12703001') or by_prefix('127')[0] or by_prefix('126')[0]; CASH_CODE=code_of(CASH_ID)
EQUITY_ID= by_code('31100001') or by_prefix('3')[0]; EQUITY_CODE=code_of(EQUITY_ID)
INCOME_ID= by_code('41100001') or by_prefix('4')[0]
EXP_ID   = by_code('61300002') or by_prefix('6')[0]
EXP2_ID  = by_code('61500006') or by_code('61300005') or EXP_ID

print("== SETUP ==")
r=call('POST','/api/bank-accounts',{'account_name':'REG Test Account','bank_name':'REG Bank','account_number':'REG-0001','account_type':'Current','branch':'Main','currency':'GHS','opening_balance':0})
IDS['bank']=r.get('id'); rec('create bank account', r.get('ok'), r.get('error',''))
r=call('POST','/api/projects',{'project_code':'REG-001','title':'Regression Project','donor':'Test','division':(UNIT or 'TEST'),'currency':'GHS','budget_fcy':100000,'fx_rate':1,'start_date':'2026-01-01','end_date':'2026-12-31','status':'Active','pi_name':'Tester'})
IDS['project']=r.get('id') or (r.get('project') or {}).get('id'); rec('create project', r.get('ok'), r.get('error',''))
r=call('POST','/api/vendors',{'vendor_name':'REG Vendor Ltd','vendor_type':'Supplier','tin':'TINVENDOR01','tax_status':'Withhold Tax where applicable','compliance_status':'Active'}); rec('create vendor', r.get('ok'), r.get('error',''))
r=call('POST','/api/payroll/employees',{'full_name':'REG Employee','staff_number':'REG001','employee_id':'REG001','basic_salary':5000,'grade':'G1','job_title':'Officer','division':'TEST','division':(UNIT or 'TEST'),'employment_type':'Permanent','status':'Active','date_appointed':'2025-01-01','tier1_member':1,'tier2_member':1,'ssnit_number':'SS001','gra_tin':'TIN001','tax_residency':'Resident'}); rec('create employee', r.get('ok'), r.get('error',''))
r=call('POST','/api/assets',{'asset_name':'REG Laptop','asset_description':'reg','asset_category':'Computer Equipment','acquisition_cost':12000,'cost':12000,'acquisition_date':'2026-01-01','depreciation_start_date':'2026-01-01','useful_life_years':3,'depreciation_method':'Straight Line','residual_value':0,'status':'Active','project_code':'REG-001'}); rec('create asset', r.get('ok'), r.get('error',''))
# budget lines (for multi-line auto-charge coverage)
rb=call('POST','/api/budgets',{'project_id':IDS.get('project'),'coa_id':EXP_ID,'budget_fcy':50000,'currency':'GHS','fx_rate':1}); IDS['bud1']=rb.get('id') or (rb.get('budget') or {}).get('id')
rb=call('POST','/api/budgets',{'project_id':IDS.get('project'),'coa_id':EXP2_ID,'budget_fcy':50000,'currency':'GHS','fx_rate':1}); IDS['bud2']=rb.get('id') or (rb.get('budget') or {}).get('id')
rec('create 2 budget lines', bool(IDS.get('bud1') and IDS.get('bud2')))

print("== TRANSACTIONS ==")
# 1) opening balance
r=call('POST','/api/opening-balances/post',{'opening_date':'2026-01-01','description':'REG opening','reference':'OB',
    'lines':[{'coa_code':CASH_CODE,'debit':50000},{'coa_code':EQUITY_CODE,'credit':50000}]})
rec('opening balance posts', r.get('ok'), r.get('error') or r.get('jv_number','')); assert_tb('opening')
# 2) receipt
r=call('POST','/api/fund-receipts',{'project_id':IDS.get('project'),'bank_account_id':IDS.get('bank'),'income_coa_id':INCOME_ID,'revenue_coa_id':INCOME_ID,'income_type':'Project / Donor Receipt','receipt_date':'2026-02-01','donor':'Test Donor','description':'Donor receipt','currency':'GHS','amount_fcy':20000,'fx_rate':1,'reference_no':'RV001','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
rid=r.get('id') or (r.get('receipt') or {}).get('id'); rec('receipt saves', r.get('ok'), r.get('error',''))
if rid and not r.get('is_posted'): call('POST','/api/fund-receipts/post',{'id':rid})
assert_tb('receipt')
# 3) PV expense
r=call('POST','/api/actuals',{'payment_type':'Expense Payment','project_id':IDS.get('project'),'expense_coa_id':EXP_ID,'bank_account_id':IDS.get('bank'),'expense_date':'2026-03-01','payee':'REG Vendor Ltd','description':'Stationery','currency':'GHS','amount_fcy':2000,'pay_fx_rate':1,'has_vat':0,'has_whvat':0,'has_ucf':0,'wht_type':'None','payment_method':'Cheque','receipt_no':'C001','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
aid=r.get('id'); IDS['pv']=aid; rec('PV expense saves', r.get('ok'), r.get('error',''))
if aid: pr=call('POST','/api/actuals/post',{'id':aid}); rec('PV expense posts', pr.get('ok'), pr.get('error',''))
assert_tb('PV-expense')
# 3b) MULTI-LINE PV (#4): one payee, 2 COA lines (1 non-taxable + 1 taxable WHT), one balanced journal
r=call('POST','/api/actuals/multiline',{'payee':'REG Vendor Ltd','bank_account_id':IDS.get('bank'),'expense_date':'2026-03-25','payment_method':'Cheque','receipt_no':'MLC1','payment_reference':'MLC1','cheque_no':'MLC1','description':'Mixed taxable + non-taxable',
    'lines':[{'coa_id':EXP_ID,'project_id':IDS.get('project'),'amount_ghs':1000,'description':'Item A (non-tax)','is_taxable':0},
             {'coa_id':EXP2_ID,'project_id':IDS.get('project'),'amount_ghs':2000,'description':'Item B (taxable)','has_whvat':1,'wht_type':'WHT-Service'}]})
mlid=r.get('id'); rec('multi-line PV saves (2 lines, 1 taxable)', r.get('ok'), r.get('error',''))
if mlid: pr=call('POST','/api/actuals/post',{'id':mlid}); rec('multi-line PV posts (Dr per line / Cr WHT / Cr bank)', pr.get('ok'), pr.get('error',''))
assert_tb('multi-line-PV')
if mlid:
    lns=call('GET','/api/actuals/lines?id='+mlid); lns=lns if isinstance(lns,list) else []
    rec('multi-line stored 2 lines, each auto-charged to its budget', len(lns)==2 and all(l.get('budget_id') for l in lns),
        'lines=%d budgets=%s'%(len(lns),[bool(l.get('budget_id')) for l in lns]))
# 4) manual JV (save -> submit -> approve -> post)
r=call('POST','/api/jvs',{'jv_type':'JV','jv_date':'2026-03-15','period':'2026','description':'REG JV','narration':'reg','reference':'JV001',
    'lines':[{'coa_id':EXP_ID,'debit_amount':500,'credit_amount':0,'description':'exp'},{'coa_id':CASH_ID,'debit_amount':0,'credit_amount':500,'description':'bank'}]})
jid=r.get('id') or (r.get('jv') or {}).get('id'); rec('JV saves', r.get('ok'), r.get('error',''))
if jid:
    pr={}
    # Maker (admin) submits; a different authorised officer approves and posts (SoD).
    call('POST','/api/jvs/workflow',{'jv_id':jid,'id':jid,'action':'submit'})
    for act in ('approve','post'): pr=call_approver('POST','/api/jvs/workflow',{'jv_id':jid,'id':jid,'action':act})
    rec('JV submit→approve→post', pr.get('ok'), pr.get('error',''))
assert_tb('JV')
# 5) payroll
rec('payroll run', call('POST','/api/payroll/run',{'month':'2026-04'}).get('ok'))
r=call('POST','/api/payroll/approve',{'month':'2026-04'}); rec('payroll approve+post', r.get('ok'), r.get('error') or r.get('gl_msg',''))
assert_tb('payroll')
# 6) depreciation
r=call('POST','/api/depreciation/run',{'month':'2026-04','force':1}); rec('depreciation run+post', r.get('ok'), r.get('error','')); assert_tb('depreciation')
# 7) fuel procurement
r=call('POST','/api/fuel-coupons/batch',{'movement_type':'Procurement','supplier':'REG Fuel','denomination':50,'quantity':100,'cost_value':5000,'serial_from':'F0001','serial_to':'F0100','bank_account_id':IDS.get('bank'),'post_to_ledger':1})
fb=r.get('batch_id') or r.get('id'); rec('fuel batch saves', r.get('ok'), r.get('error',''))
if fb: pr=call('POST','/api/fuel-coupons/batch/post',{'batch_id':fb,'id':fb,'bank_account_id':IDS.get('bank')}); rec('fuel batch posts', pr.get('ok'), pr.get('error',''))
assert_tb('fuel')

print("== ADVANCED PATHS ==")
# 8) PV with FULL tax (Input VAT + WHT + WHVAT + UCF) — exercises the tax GL legs
r=call('POST','/api/actuals',{'payment_type':'Expense Payment','project_id':IDS.get('project'),'expense_coa_id':EXP_ID,'bank_account_id':IDS.get('bank'),'expense_date':'2026-03-20','payee':'REG Vendor Ltd','description':'Taxed service','currency':'GHS','amount_fcy':10000,'pay_fx_rate':1,'has_vat':1,'has_whvat':1,'has_ucf':1,'wht_type':'WHT-Service','payment_method':'Cheque','receipt_no':'C002','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
tid=r.get('id'); rec('PV+full tax saves', r.get('ok'), r.get('error',''))
if tid: pr=call('POST','/api/actuals/post',{'id':tid}); rec('PV+full tax posts (VAT/WHT/WHVAT/UCF legs)', pr.get('ok'), pr.get('error',''))
assert_tb('PV-taxed')
# 8b) VAT-ONLY PV (VAT ticked, no WHVAT): amount is ex-VAT; the 20% VAT add-on must hit
#     BOTH the expense debit and the bank settlement (regression guard for the SBS add-on fix).
rv=call('POST','/api/actuals',{'payment_type':'Expense Payment','project_id':IDS.get('project'),'expense_coa_id':EXP_ID,'bank_account_id':IDS.get('bank'),'expense_date':'2026-03-22','payee':'REG Vendor Ltd','description':'VATONLY addon check','currency':'GHS','amount_fcy':1000,'pay_fx_rate':1,'has_vat':1,'has_whvat':0,'has_ucf':0,'wht_type':'None','payment_method':'Cheque','receipt_no':'C003','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
vid=rv.get('id'); rec('VAT-only PV saves', rv.get('ok'), rv.get('error',''))
if vid:
    pv=call('POST','/api/actuals/post',{'id':vid}); rec('VAT-only PV posts', pv.get('ok'), pv.get('error',''))
    _gl=call('GET','/api/general-ledger'); _gl=_gl if isinstance(_gl,list) else _gl.get('data',[])
    _vl=[g for g in _gl if 'VATONLY' in (g.get('description') or '')]
    _jv=_vl[0].get('jv_number') if _vl else None
    _all=[g for g in _gl if _jv and g.get('jv_number')==_jv]
    _dr=round(sum(float(g.get('debit_amount') or 0) for g in _all),2)
    _cr=round(sum(float(g.get('credit_amount') or 0) for g in _all),2)
    _exp_dr=round(sum(float(g.get('debit_amount') or 0) for g in _vl),2)
    rec('VAT-only add-on: expense debit = 1200 (1000 + 20% VAT)', abs(_exp_dr-1200.0)<0.01, f'got debit {_exp_dr}')
    rec('VAT-only add-on: journal balances at 1200 (expense Dr = bank Cr incl VAT)', abs(_dr-1200.0)<0.01 and abs(_cr-1200.0)<0.01, f'Dr {_dr} Cr {_cr}')
assert_tb('PV-vatonly')
# 9) FX receipt (USD @ 15) — multi-currency GL
r=call('POST','/api/fund-receipts',{'project_id':IDS.get('project'),'bank_account_id':IDS.get('bank'),'income_coa_id':INCOME_ID,'revenue_coa_id':INCOME_ID,'income_type':'Project / Donor Receipt','receipt_date':'2026-04-05','donor':'Test Donor','description':'USD receipt','currency':'USD','amount_fcy':1000,'fx_rate':15,'reference_no':'RV-USD','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
fxr=r.get('id') or (r.get('receipt') or {}).get('id'); rec('FX receipt saves (USD@15=15000)', r.get('ok'), r.get('error',''))
if fxr and not r.get('is_posted'): call('POST','/api/fund-receipts/post',{'id':fxr})
assert_tb('FX-receipt')
# 10) Admin edit -> auto reverse + repost of a posted PV (the flow that confused users)
if IDS.get('pv'):
    r=call('POST','/api/actuals/update',{'id':IDS['pv'],'payment_type':'Expense Payment','project_id':IDS.get('project'),'expense_coa_id':EXP_ID,'bank_account_id':IDS.get('bank'),'expense_date':'2026-03-01','payee':'REG Vendor Ltd','description':'Stationery (amended)','currency':'GHS','amount_fcy':2500,'pay_fx_rate':1,'has_vat':0,'has_whvat':0,'has_ucf':0,'wht_type':'None','payment_method':'Cheque','receipt_no':'C001','edit_reason':'Regression: amend posted PV','unit':UNIT,'unit_code':UNIT,'dept_code':UNIT})
    rec('Admin edit posted PV (auto reverse+repost)', r.get('ok'), r.get('error',''))
assert_tb('edit-reverse-repost')
# 11) Withholding settlement (Dr WHT liability / Cr bank)
wps=call('GET','/api/withholding-payables')
wps=wps.get('rows') if isinstance(wps,dict) else (wps if isinstance(wps,list) else [])
wopen=[w for w in (wps or []) if str(w.get('status','')).lower() not in ('settled','paid','remitted','void')]
if wopen:
    wid=wopen[0].get('id')
    r=call('POST','/api/withholding-payables/settle',{'id':wid,'bank_account_id':IDS.get('bank'),'payment_method':'Bank Transfer','payment_reference':'WHT-REMIT-01','payment_date':'2026-05-01'})
    rec('WHT payable settlement posts', r.get('ok'), r.get('error',''))
else:
    rec('WHT payable settlement posts', False, 'no open withholding payable found to settle')
assert_tb('WHT-settle')
# 12) Year-end close (posts closing journals)
st=call('GET','/api/year-end-status')
r=call('POST','/api/year-end-close',{'financial_year':'2026','confirm':True,'notes':'regression close'})
rec('year-end close', r.get('ok'), r.get('error') or r.get('message',''))
assert_tb('year-end')

print("== STATUTORY FILINGS (GRA + SSNIT) ==")
# WHT/WHVAT from posted PVs (2026-03); PAYE/SSNIT from approved payroll (2026-04)
s03=call('GET','/api/statutory-filings?month=2026-03')
rec('statutory: WHT return populated (2026-03)', (s03.get('wht') or {}).get('total',0) > 0, f"WHT total {(s03.get('wht') or {}).get('total')}")
rec('statutory: WHVAT return populated (2026-03)', (s03.get('whvat') or {}).get('total',0) > 0, f"WHVAT total {(s03.get('whvat') or {}).get('total')}")
_whtrows=(s03.get('wht') or {}).get('rows') or []
rec('statutory: vendor TIN joined onto WHT line', any(r.get('tin')=='TINVENDOR01' for r in _whtrows), 'no TINVENDOR01 on WHT rows')
rec('statutory: WHT-Service rate = 7.5% (0.075 not 0.07)', any(abs(float(r.get('rate') or 0)-0.075)<0.0005 for r in _whtrows), 'rates '+str([r.get('rate') for r in _whtrows]))
s04=call('GET','/api/statutory-filings?month=2026-04')
rec('statutory: PAYE return populated (2026-04)', (s04.get('paye') or {}).get('total',0) > 0, f"PAYE total {(s04.get('paye') or {}).get('total')}")
_t1=(s04.get('tier1') or {}); _t2=(s04.get('tier2') or {})
rec('statutory: SSNIT Tier 1 = 13.5% of pensionable', _t1.get('total',0)>0 and abs(_t1.get('total',0) - _t1.get('basic',0)*0.135) < 1.0, f"tier1 {_t1.get('total')} on basic {_t1.get('basic')}")
rec('statutory: SSNIT Tier 2 = 5% of pensionable', _t2.get('total',0)>0 and abs(_t2.get('total',0) - _t2.get('basic',0)*0.05) < 1.0, f"tier2 {_t2.get('total')} on basic {_t2.get('basic')}")

print("== FINAL RECONCILIATION ==")
dr,cr,n=gl_balance(); rec(f'GL balanced (Dr={dr} Cr={cr})', dr is not None and abs((dr or 0)-(cr or 0))<0.01)
s=call('GET','/api/sfp?as_at=2026-12-31'); pd=s.get('presentation_difference'); rec(f'SFP balances (diff={pd})', pd is not None and abs(float(pd))<0.01)
c=call('GET','/api/cashflow?date_from=2026-01-01&date_to=2026-12-31')
sc=(s.get('assets') or {}).get('cash_and_bank'); cc=c.get('closing_cash')
rec(f'Cashflow closing == SFP cash ({cc} vs {sc})', sc is not None and cc is not None and abs(float(cc)-float(sc))<0.01)
i=call('GET','/api/income-expenditure?date_from=2026-01-01&date_to=2026-12-31')
rec(f'I&E reflected (exp={i.get("total_expenditure")}, NetAssets={s.get("net_assets")})', s.get('net_assets') is not None)

p=sum(1 for _,ok,_ in RESULTS if ok); f=len(RESULTS)-p
print(f"\n==== {p} passed, {f} FAILED ====")
for st,ok,d in RESULTS:
    if not ok: print("  FAIL:",st,'—',d)
sys.exit(0 if f==0 else 1)
