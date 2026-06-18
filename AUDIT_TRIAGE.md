# UCC-FMS Audit Triage (2026-06-15) — findings from 5 parallel agents

Status key: ⏳ pending verify · ✅ verified-real · ❌ not-real/wontfix · 🔧 fixed

# ===== FINAL STATUS (verified) =====
SUITES GREEN BOTH STACKS: Python smoke 11/11, regression 56/0, multiunit 22/22 | PHP smoke 11/11, regression 56/0, multiunit 10/10, phase3a 13/0, phase3b 10/0, phase4 8/0.
FIXED+VERIFIED: official UCC logo everywhere (0 visible SBS on SPA + payslip/invoice/footer + org name corrected); NaN/Inf/neg ledger gate (PY+PHP); PAYE bands 192k/366,240/605k (PY source+DB; PHP auto via DB); cross-unit isolation C1/C2/C3/C4/H2/H2b/H3 + login lockout 5/15min + scope fail-closed (PY); PHP parity D2 default-bank, D3 VAT-irrecoverable(+gate updated 6→5 legs), D5 SSNIT tier2 default, D7 trial-balance. Deliverable zip refreshed 1.3MB clean.
PHP AGENT WAS KILLED mid-work → CONFIRM in cutover pass: D4 opening-balance dual-contract + full PHP isolation detail/IDOR mirror (multiunit 10/10 passes so list-scope works; detail endpoints unconfirmed).
BACKLOG (hardening/cutover — fold into strategy, NOT demo-blockers):
  PY: period-guard unseeded(a1-H2), SoD direct-post mode-aware(a1-H1), cashflow multi-leg classify(a1-M3), dedup(a1-L4), SSNIT max-insurable cap(a2-L4), malformed-JSON 400(a5-H2), JV-post atomicity(a5-M1), exception-leak(a5-L1), demo-acct disable(a3-M1, go-live).
  Parity (task#9 cutover): D1 COA filter, D6 fund-receipt lifecycle, D8 ap/vendors col, D9 petty/inv contracts, D10 GET surface, D11 statement schemas, D12 PHP go-live-gate/dedup/payee.
  DEV-ONLY wontfix: wsgiref threading hang under concurrent large responses (prod uses passenger/Render).
  Docs: USER_GUIDE.html "SBS-FMS", runbook "SBS".

# ===== FIX PROGRESS =====
DONE BY ME (server.py + index.html, verified in code):
- 🔧 Official UCC coat-of-arms embedded (ucc-logo.png 148x148), cache-bust → 20260615-ucc-official.
- 🔧 ALL visible branding: server.py UNIT_SHORT→UCC (payslip), invoice prefix UCC-INV + system name, institution setting (2x), CANS-SBS org name→"School of Biological Sciences"; index.html footer, Mo header, fuel labels + Central Store, ORG_SHORT AOI→UCC, ORG_CONTEXT, detectApp default→UCC, getOrgLongName/Short/Sub UCC-aware, help text "In UCC FMS", placeholder UCC-GRANT, "UCC reporting", "(SBS)" phrase removed. Live-DOM rescan pending after restart.
- 🔧 **C1 (agent5) NaN/Inf ledger corruption FIXED**: hardened `_f53_f` (math.isfinite) + negative-amount reject at api_post_journal_voucher post-gate. PHP mirror delegated.
- 🔧 **H1 (agent2) PAYE bands FIXED**: 25%→192000, 30%→366240(min238760), 35%→605000 in all 3 server.py locations + 2nd seed block (4344-4346). PHP mirror delegated.
DELEGATED (2 background fix-agents, must keep suites green):
- server.py agent: isolation C1/C2/C3/C4/H2/H2b/H3 + login lockout H1 + scope fail-closed M3.
- php agent: PAYE + NaN + SSNIT-default(D5) + default-bank(D2) + VAT(D3) + opening-bal(D4) + trial-balance(D7) + isolation mirror.
STILL OPEN (post-agents, lower-tier / strategy): agent1 H2 period-guard, agent1 H1 SoD direct-post (mode-aware), agent1 M3 cashflow classify, agent2 L4 SSNIT cap, agent5 H2 malformed-JSON 400, agent5 M1 JV atomicity, agent5 L1 exception leak, agent3 M1 demo acct, agent4 D1/D6/D8/D9/D10/D11/D12 parity, docs SBS.

## Agent 1 — Accounting & statements (DONE)
- ✅ **H1** SoD/approval bypass on direct-post `/api/journal-vouchers/post` (server.py:22513). Admin-only; enforces period gate but NOT Approved-status nor preparer≠poster. CONSTRAINT: smoke_test.py:101 + gates + PHP post via this endpoint as same admin → fix must be mode-aware (tighten only at go-live LIVE; allow in SETUP/UAT/demo) OR delegate to workflow with a documented admin override. Mirror in php/index.php.
- ✅ **H2** Period guard permissive for unseeded periods. `_ensure_period_accepts` (server.py:12088) only blocks when a row exists Closed/Locked; no row → posts freely. `api_manage_period` close = silent no-op (0 rows) but returns ok. Workflow post path (server.py:3835) may not call the guard at all (paths disagree). Fix: default-deny posting to periods without an Open row; close/lock returns error on 0-row update; one shared gate across both paths. Mirror in PHP.
- ⏳ **M3** Cash-flow misclassifies multi-leg JVs: `_classify`/`api_cashflow_v556` (server.py:25024,25055) buckets per-JV by priority, not per-contra-line. Dr Exp100/Dr PPE400/Cr Bank500 → all 500 to investing. Totals still tie. Fix: apportion cash per contra leg.
- ⏳ **L4** Dedup guard (server.py:33481) keys on description+amount+time → can block distinct same-amount JVs. Fix: fingerprint full line set / idempotency key.

VERIFIED-CORRECT by agent 1: unbalanced JV rejected; JV→GL→TB=0; SFP balances; I&E↔SFP articulation; CF↔SFP cash ties; opening balances; FX; reversals + re-reverse block; workflow double-post block; workflow approve SoD enforced even for admin.

## Agent 2 — Ghana tax & payroll (DONE)
- ✅ **H1 (verified vs statute)** PAYE 25%/30% band widths WRONG → over-taxes. Code: pb26_5 width 160000, pb26_6 min 206760/width 540000, pb26_7 min 746760. CORRECT (2024 schedule, monthly 16,000→annual 192,000 @25%; 30,520→366,240 @30%; 35% above 605,000): pb26_5 min 46760 width **192000**; pb26_6 min **238760** width **366240**; pb26_7 min **605000**. FIX ALL 3 LOCATIONS in server.py (seed 736-743, migration 755-758, idempotent 789-792) + descriptions + the PHP twin. First 4 bands correct.
- ⏳ **H2** VAT-exclusive PV drops VAT from GL (server.py:4076-4116 api_auto_generate_jv, source='actuals'). VAT add-on computed but never debited (input VAT or expense) nor added to cash credit. NOTE: earlier work fixed a VAT branch in api_actual_post — confirm whether auto-generate is a separate path. Verify before fixing.
- ⏳ **M3** No recoverable vs irrecoverable VAT switch; acct 1012 Input VAT exists but never posted. Add vat_recoverable flag (university = irrecoverable → load to expense). Tie with H2.
- ⏳ **L4** SSNIT not capped at max insurable earnings (~GHS 61k/mo, confirm gazette). Add min(pensionable, ceiling) as a setting. Rarely binds.
- ⏳ **L5** /api/actuals GET + rapid POSTs left dev process unresponsive (HTTP 000) — possibly wsgiref single-thread/socket artifact; data persisted. Cross-check with Agent 5. Investigate handler/threading.

VERIFIED-CORRECT by agent 2 (to the cent): PAYE engine mechanics + bands 1-4 + 35% rate; SSNIT 3-tier 5.5/8 Tier1 + 5 Tier2, pensionable=basic+market; payroll net + GL legs; VAT 20% combined (15+2.5+2.5); WHT 3/7.5/5/20; WHVAT 7% + ex-VAT extraction; UCF 5%; tax reliefs.

## Agent 2 note: PHP PAYE bands must be fixed to match (parity).
## Agent 3 — Security & isolation (DONE) — ROOT CAUSE: isolation is opt-in per endpoint
Scoped (good): GL list, TB, I&E, AR-invoices list, AP-bills list, actuals, fund-receipts, petty-cash. Unscoped (leak):
- ⏳ **C1 IDOR** api_get_jv_detail (server.py:3669, app.py:786) no unit check → reads any unit's JV. Fix: _ucc_resolve_read_scope, 403 if jv.unit_id not in scope.
- ⏳ **C2** api_get_jvs (server.py:3633, app.py:783) JV list no unit filter. Fix: append _ucc_gl_scope_clause(alias='jv').
- ⏳ **C3** /api/export/audit-trail (app.py:468) + other /api/export/* leak all units. Fix: scope reads OR restrict to Admin/Auditor.
- ⏳ **C4** AR: api_ar_customers (server.py:30101) list unscoped; /api/ar/statement (app.py:531) + /api/ar/invoice-lines (app.py:532) IDOR. Fix: scope list + unit check on statement/lines.
- ⏳ **H1** Brute-force lockout disabled: outer api_login wrapper (server.py:29433-29477, _SEC_MAX_FAILS=10/_SEC_COOLDOWN=30s) shadows v564 5/15min (28144-28183); resets after 30s → ~28.8k guesses/day. Fix: outer defers to v564 (low max, long lockout, no 30s reset).
- ⚠️ **H2b CONTESTED** ledger-summary api_get_ledger_accounts_summary (server.py:19197, app.py:791) calls _ips_gl_rows w/o scope; agent saw 300 regardless of ?unit. BUT this contradicts passing ucc_multiunit (22/22) + php_multiunit (10/10) which use this exact endpoint as isolation proxy. MUST VERIFY which handler /api/ledger-summary actually routes to (dual route-table gotcha) before fixing.
- ⏳ **H2** Cross-unit JV approval: api_jv_workflow (server.py:3783) no unit check (SoD-by-preparer OK). Fix: require jv.unit_id in approver scope for submit/approve/post/void/reverse (Admin exempt).
- ⏳ **H3** Projects (api_get_projects 6989) + Vendor register (api_vendor_register 23780) lists unscoped. Fix: _ucc_filter_rows_by_scope.
- ⏳ **M1** demo/Demo@2024 = Admin unrestricted; admin/UCC@2024 default pw. Fix: ship demo disabled/scoped; force pw change pre-go-live.
- ⏳ **M2** MFA wrapper (server.py:33372/33434) returns ok:true+dead-sid on DB-lock exception. Fail closed.
- ⏳ **M3** _ucc_resolve_read_scope (server.py:35711) fails OPEN (returns None=unrestricted) on exception. Fix: fail closed (set()) for non-Admin.

VERIFIED-SOLID by agent 3: unauth /api/* blocked; SQLi parameterized; JV SoD-by-preparer; post/void/reverse admin-only; TB scope incl node-param (own=100/SOA=0/admin=300); AR-invoices+AP-bills+petty-cash scoped; MFA fails closed (dead sid); PBKDF2 + pw policy.
NOTE: all isolation fixes must mirror in php/index.php.

## Agent 3 — Security & isolation: prior pending marker removed
## Agent 4 — PHP↔Python parity (DONE) — 12 divergences; core engines (tax compute, JV+post, SoD, AP, depreciation, PAYE/Tier1) at full parity
- ⏳ **D2 H** Default bank differs: PY credits 12703001 (ADB, via _bank_coa_for/_get_coa['1001']); PHP credits 12701001 (ABSA, get_coa(['127','126']) prefix LIMIT1). Bank rec won't tie. Pick ONE canonical operating bank both sides.
- ⏳ **D3 H** PV VAT (has_vat+has_whvat): PY Dr expense full 1548 (no input VAT) = irrecoverable→expense (CORRECT for non-VAT-registered univ); PHP Dr ex-VAT 1290 + Dr InputVAT 258 (recoverable). Net bank same. DECISION: univ=irrecoverable→align PHP to PY (load to expense). ALSO fix PY VAT-EXCLUSIVE drop (agent2 H2). Dormant PY _expense_lines_for_actual (6510) does the split but isn't the live override.
- ⏳ **D4 H** opening-balances contract: PHP needs coa_code/debit/opening_date; PY accepts BOTH coa_id/debit_amount AND coa_code. Fix PHP to accept coa_id too.
- ⏳ **D5 H** SSNIT Tier-2 default: PY=1 (server.py:4186,5305 — Ghana mandatory 5%, CORRECT); PHP=0 (php:1373). Fix PHP default→1.
- ⏳ **D6 H** fund-receipt lifecycle: PY auto-posts on create + real bank_accounts FK + receipt ref required + credits deferred/restricted income; PHP two-step, nullable bank, credits revenue 41100003. Align lifecycle + income account.
- ⏳ **D7 H** /api/trial-balance MISSING in PHP (404). Implement in PHP.
- ⏳ **D1 M** /api/coa count: PY 834 (LENGTH(code)=8 filter, server.py:11255/11270); PHP 843 (no filter, php:237). Add filter to PHP (but confirm 9 short codes e.g. 2034 WHVAT not needed as posting targets).
- ⏳ **D8 M** PY /api/ap/vendors errors "no such column b.debited_ghs" on seeded DB (server.py:30828; migration 30782 didn't run). Ensure migration runs / COALESCE guard.
- ⏳ **D9 M** petty-cash/inventory create contracts differ (field names, bank requirement).
- ⏳ **D10 M** GET vs POST surface gaps: GET /api/jvs, /api/payroll/employees list on PY but 404 PHP; GET /api/settings/dual-control 404 PY but ok PHP; list envelope shapes differ.
- ⏳ **D11 L/M** statement JSON shapes differ (field names/nesting) on sfp/cashflow/I&E/assets etc — amounts match, schema differs → breaks shared frontend. Align schemas.
- ⏳ **D12 M** PHP MISSING controls: go-live gate (PY blocks posting in SETUP), duplicate-payment guard, payee-must-be-registered-vendor. Port to PHP for cutover.
CAVEAT: PY wsgiref go-live state not fully thread-safe (prod note); withholding-settle path unconfirmed both sides.
STRATEGIC: PHP parity gaps (D1-D12) are the bulk of task #9 (PHP cutover) — many belong in the parallel-run phase, not all blocking the demo.

## Agent 5 — Data integrity & branding (DONE)
- ⏳ **C1 LEDGER CORRUPTION** NaN/Infinity/negative amounts post. _f53_f (server.py:20365) float() accepts "NaN"/"Infinity"; balance gate abs(nan-nan)>0.01 == False → passes save+post. Repro confirmed: posted JV → TB total=Infinity, balanced:false. NOTE contradicts agent1 (which said /api/jvs save via _validate_jv_lines rejects NaN) — different endpoint /api/journal-vouchers. FIX (catch-all): add math.isfinite + >=0 in api_post_journal_voucher balance gate (22531) AND _f53_f AND save validators. Mirror PHP.
- 🔧VISIBLE **C2** Payslip "SBS" x3: server.py:5708 UNIT_SHORT="SBS" rendered 5857/5858/5862. → "UCC".
- 🔧VISIBLE **C3** Client report footer index.html:9197 rptFooter() "University of Cape Coast (SBS) · University of Cape Coast, Ghana" → "University of Cape Coast · Cape Coast, Ghana".
- 🔧VISIBLE **H1** Invoice SBS branding: server.py:2279-2283 _v523_invoice_profile prefix SBS-INV→UCC-INV, system "SBS Enterprise Resource Planning System"→UCC FMS; invoice logo _v523_ucc_logo_svg (2417) is placeholder not coat-of-arms.
- ⏳ **H2** _body (app.py:114-120) try/except→{} swallows bad/oversized JSON; permissive POST (e.g. /api/projects) → 200 + junk row. Fix: parse-fail sentinel→400, oversized→413, require key fields.
- ⏳ **M1** JV post idempotency read-then-write (server.py:22527 select → 22579 update), not atomic → prod multi-worker double-post risk. Fix: UPDATE...WHERE status!='Posted', check rowcount.
- 🔧VISIBLE **M2** institution setting default "(SBS)" server.py:589 + 4372. → drop (SBS).
- 🔧VISIBLE **M3** org unit CANS-SBS NAMED "University of Cape Coast" (botched rebrand) server.py:35376 → "School of Biological Sciences" (KEEP code CANS-SBS — tests ref it).
- ⏳ **L1** raw exception str leaked on 500 (server.py:25478, 22591, 22556). Generic msg + log.
- ❌ **L2** dev _ThreadingWSGIServer hangs under concurrent large responses (app.py:1254) — DEV ONLY (prod uses passenger_wsgi/Render import). Same root as agent2-L5. WONTFIX (note in handover).
- 🔧VISIBLE **L3** unit-logo.svg still placeholder; conflicting CSS (index.html:17152,13334) re-show .unit display:block; report header 11639 renders unit-logo beside real logo; 6 refs miss ?v= cache-buster (11639,15225,15641,18607,21751,27038). Make all logo refs = official coat of arms or stay hidden.
- 🔧VISIBLE **LATENT** index.html:12273-12274 ORG_SHORT derives from title, no "SBS" → falls to 'AOI' else-branch → fuel forms say "AOI". Fix → 'UCC'.
- 🔧VISIBLE Mo assistant header index.html:1790 "SBS Finance Guide"→"UCC Finance Guide"; fuel labels 3277,3283,3952,3970 "(SBS)".
- DEAD (not visible, leave or low-pri): php SBS_DB/sbs_fms.db, app.py sbs_sid cookie/cache-key/origin, server.py sbs_sid/SBS- prefix/org='SBS' default-path/School-Wide alias/fallback bank names; index.html ===' SBS'/isSBS dead gates. Docs (USER_GUIDE "SBS-FMS", runbook "SBS") VISIBLE-in-docs → fix docs too.
VERIFIED-CLEAN by agent5: logo asset official 148x148; migrations idempotent+self-correcting; JV concurrency 1-posted (dev); malformed JSON graceful on validated endpoints; COA no SBS names.

## H2b RESOLVED = REAL: 3 defs of api_get_ledger_accounts_summary (3999/6775/19197); effective last def 19197 calls _ips_gl_rows w/o scope. /api/ledger-summary (app.py:792) passes only period, drops unit. Python multiunit test must use a scoped endpoint; this one genuinely leaks. FIX 19197 + pass unit param.

# ===== GO/NO-GO ASSESSMENT (6 analysts) — verified register =====
A6 gaps/controls (DONE, conditional-GO): F2 default-creds no-forced-change VALID(go-live); F4 Admin SoD carve-out VALID(=agent1 H1, open); F5 dual-control off-by-default VALID(config); F6 only 4 roles VALID; F7 own_unit users NULL home_unit VALID(onboarding); F8 NO student-fees/billing engine VALID(SIS integration boundary — accounts exist, no student master/AR subledger); F9 org-tree stub (3/14 halls, no depts, IGF single node) VALID.
  ❌FALSE-POSITIVE F1 "unsalted SHA-256": effective login = _pbkdf2_verify (server.py:29433) PBKDF2-HMAC-SHA256 260k iters + upgrade-on-login (29461); seed sha256 only until first login. Passwords ADEQUATE. Downgrade.
  ❌FALSE-POSITIVE F10 "no general inventory": FULL inventory/stores module exists (api_inv_items/receipt/issue/adjust/reorder/create_reorder_po, server.py:30572-32508).
  ⚠️PARTIAL F3 audit hash-chain: effective _audit (35221) DOES chain (prev_hash→row_hash); but live verify showed hash_chained:0 + misleading "intact" msg → confirm row_hash populated on every write + fix verify messaging.
A4 IPSAS (DONE, conditional No-Go for AUDITED accounts; fine for internal mgmt): statements all articulate+balance. BLOCKERS: F1 no comparatives (IPSAS 1.53) on any statement; F2 IPSAS-24 budget-vs-actual = expenditure-only (no revenue). NEEDS-FIX: F3 cashflow classifies opening accumulated-fund as financing; F4 "Receipts from Donors" mislabel; F5 SFP no current/non-current liability split + missing provisions/deferred-grant/employee-benefit lines; F6 changes-in-net-assets single column; F7 no exchange/non-exchange revenue split; F8 COA→statement mapping by name/prefix keyword (fragile); F9 notes missing PP&E movement schedule/related-party/segment/risk/contingencies/compliance stmt.
A2 forms/bugs (DONE, NO-GO until 4 fixed): 
  🔧FIXED **SBS code-prefix leak** (was wrongly classed DEAD): server.py:1626 project pcode SBS-→UCC-; 20250 asset prefix SBS/AOI→UCC. (live-proven: SBS-2026-E59 / SBS-AST-00001).
  ⏳BLOCKER non-grant PV unsaveable: actuals form sends project_id="" → FK constraint failed (index.html:2744). General expense broken via UI.
  ⏳dead "Department" required field (Budget+Actuals forms) never sent; inverted required/optional logic (project_id required but labeled optional).
  ⏳empty/garbage records save ok:true (no server required-field validation); negative asset cost/zero life accepted.
  ⏳systemic: app.py catches all exceptions → HTTP 200 {ok:false, error:str(exc)} leaking raw Python/SQL to client.
  ⏳no duplicate-submit guard (JV posted twice = 2 docs). OK: JV/AR/AP/inventory/petty-cash forms well-validated; SPA shell clean (no undefined/NaN).
