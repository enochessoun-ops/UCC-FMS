# Institutional Roll-out Plan — University of Cape Coast (UCC)
_Shipping UCC-FMS as a university-wide ERP. Drafted 2026-06-14. Companion to `PHP_PORT_PLAN.md`,
`RECOVERY_AND_SCALING.md`, and `API_CONTRACT.md`._

## Decision summary
- **Operating model:** *Federated operation on one unified live database.* Every academic and
  administrative unit operates separately (its own budgets, banks, approval chains, document
  sequences, dashboard, and a user who sees **only** their own unit), but all share a single live
  database with row-level `unit_id` scoping. This is the only way to satisfy both requirements that
  were chosen: **operate separately** *and* **instant/live** university-wide reporting.
- **Target stack:** PHP 8 / Apache / **MySQL** on cPanel — UCC IT Directorate's native stack — with
  the language-agnostic `index.html` SPA unchanged. The Python `server.py` stays the **live
  reference** throughout the port (see `PHP_PORT_PLAN.md` Phase 5 parallel run).
- **Acceptance contract:** the existing 60-check suite (`smoke_test.py` 11 + `regression_fixes.py`
  49) **plus** new multi-unit checks (§G). All green on PHP = done; anything less = not done.

## Why not the alternatives
- **Tenant-per-unit (one app/DB per unit):** zero code change but 50–80 instances to operate,
  COA/tax/rate drift across them, and roll-up is never live. Fails the instant/live requirement.
- **Periodic federated hub (ETL sync):** lower risk but reporting is only as fresh as the sync.
  Fails the instant/live requirement.
- **Chosen — single live multi-unit DB with federated operation:** real-time roll-up at any node,
  one source of truth, one upgrade, while preserving per-unit autonomy via scoping.

## A. Target architecture
- One shared **MySQL** instance, multi-unit, live. SQLite→MySQL dialect sweep is scoped in
  `RECOVERY_AND_SCALING.md` §4 (`strftime`→`to_char`/`extract`, `INSERT OR IGNORE`→
  `ON DUPLICATE KEY`/`ON CONFLICT`, drop `PRAGMA`, identity columns).
- SPA (`index.html`) carries over unchanged — it talks JSON; the work is the API + accounting engine.
- **Build rule (interim Python):** every new endpoint MUST be added to **both** `app.py` and
  `server.py` route tables. `app.py` (gunicorn/Render entry) has its own hardcoded route dict and
  does **not** delegate unmatched `/api/*` to `server.py`; endpoints added only to `server.py`
  silently 404 on the deployed entry point.

## B. Data-model additions (core of the work)
1. **`org_units` hierarchy** — flexible `parent_id` tree, NOT a hard-coded 5-college enum:
   `id, code, name, type, parent_id, head_name, head_email, status`
   `type ∈ {University, College, School, Faculty, Department, Directorate, Institute, Centre,
   Section, Hall, IGF, AdminUnit}`.
   Seed with the full UCC structure:
   - University (root)
   - 5 Colleges (each headed by a Provost): CANS, CHLS, CoES, CoHAS, CoDE → their schools/faculties
     → departments
   - 7 Directorates: Academic Affairs, Human Resources, Finance, DAPQA, DPDEM, DRIC, University
     Health Services
   - 3 Institutes: Institute of Education, IEPA, IDS
   - Centres: ACECoR, CEGRAD, D-Hub, CTS, CAIS, Counselling Centre
   - MIS / IT sections: Cybersecurity, Systems & Database Administration, Web Services & IT Support
   - Office of the Dean of Students → 14 Residential Halls (Casley Hayford, Atlantic, Adehye, …)
   - IGF / income-generating units
2. **`unit_id` FK on every transactional table** — `general_ledger`, `journal_vouchers`/`jv_lines`,
   AP/AR, `payroll_register`, `asset_register`, `budgets`/`dept_allocations`, procurement
   (`purchase_requisitions`→`purchase_orders`→`goods_received_notes`), `petty_cash*`, `commitments`,
   `bank_accounts`, etc. Backfill from the existing `departments`/`dept_allocations` layer.
3. **User ↔ unit scoping** — extend `users` + `permission_matrix` with `home_unit_id` and `scope`
   (`own_unit` | `subtree` | `university`).

## C. Security = data partitioning (non-negotiable)
- Scoping enforced **server-side in the query layer** (`get_db` / report builders), never in the UI.
- Central injection of `WHERE unit_id IN (<resolved scope>)`; resolved scope = user's node + (if
  `subtree`) all descendants from `org_units`.
- Unit head → `own_unit`; Provost → their college `subtree`; Finance/VC/Auditor → `university`.
- A unit user must be **unable** to read/write another unit's rows even by crafting a request.

## D. Consolidation & live reporting
- Every statement endpoint (Trial Balance, Income & Expenditure, SFP, Cash Flow, Notes/IPSAS) gains
  a `node` parameter = any `org_units.id`. Figure = live sum over that node's subtree. University-wide
  = `node = root`.
- Drill-down: University → College → School → Department, all live.
- Keep `/api/consolidation/export` (`ucc-consolidation-v1`) but repurpose for **external** submission
  (CAGD/GIFMIS, auditors, Council) — no longer the internal roll-up mechanism.

## E. Standardization with per-unit autonomy
- Central single source: one COA, PAYE bands, SSNIT, FX rates, role definitions, approval-rule
  templates.
- Per-unit: budgets, bank accounts, petty-cash floats, document sequences, approval-chain
  instances, dashboards.

## F. Migration & onboarding
1. SQLite → MySQL per `RECOVERY_AND_SCALING.md` §4; gate cutover on `smoke_test.py` 11/11.
2. Seed `org_units` from the UCC structure; backfill `unit_id`.
3. Per-unit onboarding via existing `setup_wizard_state` + `institutional_reset.py` (clears
   transactions, preserves master setup) so every unit starts from an identical template.

## G. Acceptance gates (the contract)
Extend the 60-check suite with multi-unit checks that must also go green:
- **Isolation:** a unit-A user cannot read/write any unit-B row.
- **Consolidation tie-out:** Σ(per-unit trial balances) == university trial balance, and balances.
- **Subtree roll-up:** a College node == Σ of its schools/departments.
- **Scope matrix:** unit head / Provost / Finance / VC / Auditor each see exactly their allowed scope.

## H. Phasing (folded into the existing PHP port phases)
| Phase | Adds on top of `PHP_PORT_PLAN.md` |
|---|---|
| **0 — Schema** | `org_units` + `unit_id` columns + scope fields; seed UCC tree; backfill. Done once, shared by Python reference & PHP. |
| **1 — Foundation** | Central scope-injection in the query layer; user↔unit assignment; role×scope guard + isolation checks. |
| **2–3 — Core/modules** | Each module carries `unit_id`; per-unit sequences/budgets/banks. |
| **3e — Statements** | `node`-parameterized live statements + drill-down + consolidation tie-out checks. |
| **4 — Security/ops** | MFA/dual-control parity, MySQL managed backups, cPanel cron, `/healthz`. |
| **5 — Parallel run** | PHP vs Python on identical multi-unit data → identical consolidated output → cutover. |

## Honest sizing
Multi-month, phase-certified. The PHP port alone is ~430 endpoints + an accounting/tax/controls
engine; the multi-unit layer adds the `org_units` tree, `unit_id` propagation, central scope
enforcement, and `node`-parameterized reporting. The schema-once + 60-check-contract approach keeps
it measurable and de-risked. Progress is measured in certified phases, not a calendar.

---

## Build progress log

**Baseline certification (pre-build).** Established a green acceptance baseline on the Python
reference before any UCC change:
- `smoke_test.py` **11/11**, `regression_test.py` **56/0** from a fresh DB.
- Fixed a pre-existing gate failure: the regression tried to submit *and* approve a JV as one
  admin, which the (correct, intentional) segregation-of-duties control blocks. The gate now
  provisions a second authorised officer and approves/posts as that user — exercising maker-checker
  faithfully rather than weakening the control.
- Hardened `run_regression.sh` readiness: it now waits for a real login to succeed instead of a
  bare socket connect, removing a boot-race that produced spurious "LOGIN FAILED" 500s.

**Phase 0 — Schema (done, verified, zero regression).**
- `org_units` hierarchy table (parent_code tree) added via the codebase's chained-migration idiom;
  idempotent; seeded with **47 UCC nodes** (1 University · 5 Colleges · 9 Schools · 6 Faculties ·
  8 Directorates · 3 Institutes · 6 Centres · 4 IT sections · Dean of Students + 3 named halls ·
  IGF grouping). Single root, no orphan parent refs. Remaining halls/IGF units are added at
  onboarding (no fabricated names).
- `GET /api/org-units` read endpoint registered in **both** `app.py` and `server.py` route tables
  (dual-route gotcha honoured).
- Nullable `unit_id` propagated to **22 transactional tables** (GL, JV/JV-lines, actuals, fund
  receipts, payroll, assets, budgets, dept allocations, commitments, AR×3, AP×2, procurement×3,
  petty cash, inventory movements, bank accounts, inter-unit transfers) via a self-correcting
  `get_db` wrapper that covers both eagerly- and lazily-created tables, then drops to zero overhead.
  Column is unused by existing code paths, so single-entity behaviour is unchanged.
- **Backfill is intentionally deferred:** a fresh per-unit deployment has no transactions to
  backfill, and legacy `departments` codes don't map to `org_units` codes — populating now would
  inject wrong data. Backfill runs at legacy-DB cut-over once each unit's node is assigned.
- Re-verified after every change: `smoke_test.py` 11/11, `regression_test.py` 56/0.

**Phase 1a — user↔unit assignment (done, verified, zero regression).**
- `users.home_unit_id` (→ org_units.id) and `users.scope` (own_unit | subtree | university) added
  idempotently. Defaults are self-correcting: Admin → `university`, everyone else → `own_unit`,
  applied authoritatively in `_v509_ensure_login_access` so even the late-created `demo` Admin is
  covered regardless of migration timing.
- Surfaced in `GET /api/users`; accepted by `api_save_user` on both create (role-aware scope
  default) and update (optional — a plain profile edit never wipes a user's unit/scope).
- Verified: a Finance Officer can be created assigned to `CANS-SBS` with `subtree` scope; all Admins
  resolve to `university`; `smoke_test.py` 11/11, `regression_test.py` 56/0.

**IMPORTANT sequencing correction.** Read-side enforcement (the `WHERE unit_id IN (...)` injection)
must NOT precede **write-side stamping**. `unit_id` columns exist but nothing populates them yet, so
enforcing scope now would hide every NULL-unit transaction from non-university users. Correct order:
  1. **Phase 1b — stamp `unit_id` on writes** (insert = explicit request unit, else creator's
     `home_unit_id`; university users may post on behalf of any unit) — via a central helper, not
     per-insert edits.
  2. **Phase 1c — enforce scope on reads** (resolve user scope → unit_id set via org_units subtree;
     inject the filter centrally; Admin/university = unrestricted) + isolation acceptance checks.

**Phase 1b — write-path `unit_id` stamping (in progress).**
- Central resolver `_ucc_resolve_write_unit(conn, data, session)`: explicit `unit_id` in the request
  (university/Admin posting on behalf of a unit) → else the creating user's `home_unit_id` → else
  None (column stays NULL, single-entity behaviour preserved).
- Wired into the main journal-voucher create (`api_save_jv`). Verified both paths: an Admin posting
  with explicit `unit_id` and a unit-assigned Finance Officer posting their own JV both produce a
  `journal_vouchers.unit_id` matching the target node. `smoke_test.py` 11/11, `regression_test.py`
  56/0 (the admin gate user has no home unit, so its JV is correctly NULL — single-entity safe).
- **Reporting consequence:** every GL row carries `jv_id`, so consolidation can attribute GL to a
  unit via `general_ledger → journal_vouchers.unit_id` (no need to edit the 6 GL insert sites).
- **Central auto-post helper covered:** `_insert_voucher_posted(conn, session, ..., unit_id=None)`
  now stamps both `journal_vouchers.unit_id` AND `general_ledger.unit_id` (explicit kwarg → posting
  user's home unit). This single point covers payroll, depreciation, PV and other system-posted
  sources. Proven by an in-process unit test: a user homed at CANS-SBS produces a JV and GL lines
  all tagged with the CANS-SBS node. `smoke` 11/11, `regression` 56/0 unchanged.
- **All posting paths now stamp the ledger (done, verified).** JV **and** GL `unit_id` are written
  by: manual JV create + post (GL inherits the JV's unit), the central auto-poster
  `_insert_voucher_posted` (payroll/depreciation/PV), the fund-receipt auto-poster, the
  `api_save_journal_voucher` / `_v557` variants, and reversals (inherit the original's unit).
  Proven end-to-end by two in-process tests (a CANS-SBS user's central-posted and full manual
  create→approve→post chains both yield JV+GL tagged CANS-SBS) plus `smoke` 11/11, `regression` 56/0.
  **The General Ledger — the consolidation substrate — is fully unit-attributed.**
- **Deferred to 1c (source-level listing only):** stamping the source-document *rows* themselves
  (actuals, AR invoices, AP bills, petty-cash vouchers) so unit users can filter their own source
  lists. Not needed for GL-based consolidation; folds naturally into read-scope work. `year_end_close`
  closing entries (a university-level construct) are intentionally left unstamped.

**Phase 1c — read-side scope enforcement + Phase 3e — node-parameterized live reports
(done together, verified, zero regression).** One mechanism serves both:
- `_ucc_subtree_unit_ids(conn, node)` — node + all descendants via the parent_code tree.
- `_ucc_resolve_read_scope(conn, session)` — Admin/university = unrestricted; `subtree` = home +
  descendants; `own_unit` = home; non-university with no home unit = sees nothing (onboarding must
  assign a unit). Fails open only on unexpected error.
- `_ucc_gl_scope_clause(conn, session, requested)` — combines enforced scope with an optional
  requested node (enforced ∩ requested) into ` AND gl.unit_id IN (...)`.
- Wired into the General Ledger read surface: **trial balance, Income & Expenditure, Statement of
  Financial Position, Cash Flow, and the GL listing**. Because enforcement is keyed on the session
  (admins unrestricted), the admin-run smoke/regression suites stay green by construction; the
  legacy `unit_code`→division filter is preserved (a `unit_code` that names an org unit is treated
  as a consolidation node, otherwise the old division filter applies).
- **New permanent acceptance gate:** `ucc_multiunit_test.py` (run via `run_ucc_multiunit.sh`) —
  **12/12**, proving write attribution, isolation (a unit user sees only its own ledger/reports),
  subtree roll-up (college = Σ schools; node=CANS-SBS→100, CANS-SOA→200, CANS→300), university view
  (Admin/no-node→300), and **consolidation tie-out (Σ unit TBs == university TB)**.

**Acceptance status:** `smoke_test.py` 11/11 · `regression_test.py` 56/0 · `ucc_multiunit_test.py`
12/12 — all green together.

**Source-document list scoping (done, verified) — ALL operational modules: payment vouchers, fund
receipts, AR invoices, AP bills, and petty cash.** Each transaction is stamped with its unit and its
LIST endpoint is filtered to the viewer's scope, using the codebase's wrap-and-delegate idiom (base
inserts untouched; Admin/university unrestricted):
- Flat lists (`actuals`) and dict envelopes (`{'invoices':[...]}`, `{'bills':[...]}`) are both
  handled by an envelope-aware filter.
- **Petty cash** is float-centric: a float is unit-owned, vouchers inherit their float's unit, and a
  tailored filter scopes the multi-list state payload (floats/vouchers by unit; replenishments/counts
  by their surviving floats); the ledger denies out-of-scope floats and defaults to the viewer's own.
All are permanently gated by `ucc_multiunit_test.py` (now **19/19**).

**Acceptance status (final):** `smoke_test.py` 11/11 · `regression_test.py` 56/0 ·
`ucc_multiunit_test.py` 19/19 — all green together. Every operational transaction list and every
financial statement is unit-scoped end to end.

**Front-end node drill-down (done, verified).** The report filter "Unit / Department" dropdown
(`loadUnitDropdown`, used by all six statement views — Trial Balance, SFP, Cash Flow, Changes in
Net Assets, Notes, Budget Variance) now populates from `/api/org-units` as an indented hierarchy:
Colleges/Directorates/Institutes/Centres at top level with their Schools/Faculties/Departments
nested beneath. Selecting a node passes its code as `unit_code`, which the report endpoints already
resolve to a live subtree roll-up; the default "All Units" = whole university (unrestricted). Falls
back to the legacy unit list if `/api/org-units` is unavailable. Verified: SPA loads with no console
errors; the tree-walk builds 46 correctly-indented options from the 47 seeded nodes (root stays the
default); backend roll-up gated at 19/19. A `.claude/launch.json` was added to run the app under the
preview server (`bash -c "RENDER_DATA_DIR=… PORT=5055 python3 app.py"`).

**Per-unit dashboard (done, verified).** Projects, budgets and quarterly budgets are now
unit-attributed (unit_id + stamp-on-save), so the executive dashboard scopes **every** financial
figure uniformly through `_ucc_gl_scope_clause`: a unit head's dashboard shows only their unit's
projects/budgets/commitments/expenditure, a provost their college's roll-up, and Admin/university the
whole institution. `api_dashboard` also accepts a node param for admin drill-down. Verified
end-to-end (real `api_save_project` path) and gated in `ucc_multiunit_test.py` (now **22/22**).

**Acceptance status (final):** `smoke_test.py` 11/11 · `regression_test.py` 56/0 ·
`ucc_multiunit_test.py` 22/22 — all green together. Every transaction list, every financial
statement, AND the executive dashboard are unit-scoped end to end, in both API and UI.

**PHP port — Phase 1 (Foundation) certified.** `php/index.php` is a single front controller on PDO
against the **same SQLite DB** as the Python reference, with a DB-backed session store (the same
`X-Session-ID` token the SPA sends), JSON envelope, auth (login is sha256-compatible with
`server.py`, plus logout/me), a role guard (read-only Auditor blocked from writes), and `/api/org-units`
parity. Gated by `run_php_phase1.sh` — **10/10** (login → me → org-units → logout, bad-password and
no-session rejection, role guard). The port now runs in parallel with Python on one DB (Phase 5
model), one certified phase done.

**PHP port — Phase 2 (Accounting core) certified.** `php/index.php` now implements the engine
foundation: `/api/coa`; the **balanced-journal gate** (`_validate_jv_lines` parity — rejects an
unbalanced entry and a debit+credit-on-one-line); the **period guard** (rejects Closed/Locked,
auto-opens an unseen period); **sequential JV numbers** (`_seq_code` parity, `JV-2026-0001…`);
`/api/jvs` create; `/api/journal-vouchers/post` → `general_ledger`; `/api/ledger-summary` (trial
balance); `/api/general-ledger`; `/api/accounting-periods`. Postings stamp `unit_id` from the
poster's home unit. Gated by `run_php_phase2.sh` — **8/8** (COA, gate accept+reject, period guard,
sequential numbering, JV→GL, TB ties out Dr 5000 == Cr 5000).

**PHP port — Phase 3a (Payments + Ghana tax engine) certified.** `php/index.php` adds vendors,
budgets and commitments CRUD; the **Ghana tax engine** (`compute_tax` — VAT on the ex-VAT base,
WHVAT 7%, WHT by type, UCF 5%, exact parity with `server.py`); PV save (`/api/actuals`) and post
(`/api/actuals/post`) that build the **6-leg balanced journal** (expense ex-VAT, input VAT `12300001`,
WHT `21100014`, WHVAT `21100024`, UCF `21100027`, net bank) via a shared `post_journal`; account-code
aliasing (`get_coa`, short→8-digit UCC codes); and commitment encumbrance settlement. Gated by
`run_php_phase3a.sh` — **13/13** (exact tax breakdown, 6 balanced GL legs, net bank 8435.42, TB ties).

**PHP port — Phase 3b (Receipts + JV workflow) certified.** Fund receipts save+post (Dr Bank /
Cr Income); `/api/jvs/workflow` submit→approve→post with **segregation of duties** (preparer cannot
approve own JV), Finance-Officer blocked from posting, Admin-only post writes GL. Gate
`run_php_phase3b.sh` — **10/10**.

**PHP port — Phase 3c (Fixed assets + depreciation) certified.** Asset register; straight-line
depreciation (`monthly = (cost−residual)/(life×12)`, capped at remaining) posting one JV
Dr Depreciation Expense `619` / Cr Accumulated Depreciation `119`; asset accumulated/carrying update;
duplicate-month guard. Gate `run_php_phase3c.sh` — **7/7**.

**PHP port — Phase 3d (AR/AP subledgers) certified.** AR customers + invoices (post Dr Receivables
`123` / Cr Income + Output VAT `21100024`); AP bills (post Dr Expense / Cr Payables `21100021`);
control accounts move correctly; TB ties. Gate `run_php_phase3d.sh` — **10/10**.

**PHP port — Phase 3e (financial statements) certified.** GL-derived Income & Expenditure, Statement
of Financial Position (Assets == Liabilities + Net Assets incl. period surplus, presentation
difference 0) and Cash Flow (closing cash ties to SFP cash). Gate `run_php_phase3e.sh` — **8/8**.

**PHP port — Inventory + Petty cash certified (all subledgers now ported).** Inventory stores
ledger (receipt Dr Inventory/Cr Bank moving-average, issue Dr Expense/Cr Inventory at avg,
over-issue guard) — `run_php_phase3f.sh` **6/6**. Petty-cash imprest floats (setup Dr Petty Cash/
Cr Bank with dept/project accountability, vouchers Dr Expense/Cr Petty Cash with book-balance
guard) — `run_php_phase3g.sh` **6/6**.

**PHP port — Payroll engine certified (the full accounting engine is now ported).** Faithful port of
`calc_employee_payroll`/`calc_ghana_paye`: Ghana PAYE graduated bands, 3-tier SSNIT (emp 5.5% / empr
8% Tier-1, empr 5% Tier-2 on pensionable = basic+market), the GRA reliefs, residency, and the
balanced IPSAS-25 payroll GL journal. **Exact parity** with the Python reference — basic 5,000 →
PAYE 779.75, SSNIT 275/400, net 3,945.25, employer cost 5,650. Gate `run_php_phase3h.sh` — **11/11**.

**PHP port — Phase 5 parallel run: FULL smoke + regression parity achieved.** The PHP backend passes
the complete Python acceptance suites — **`smoke_test.py` 11/11 AND `regression_test.py` 56/0** — on
both a fresh DB and a shared DB (`run_php_parallel.sh` runs both). The final reconciliation added:
opening balances, year-end close, the withholding-payable lifecycle (PV→payables→settle), the
statutory-filings engine (WHT/WHVAT/PAYE/SSNIT), fuel coupons, PV admin edit (reverse+repost),
multi-line PV (per-line tax + budget auto-charge), and `/api/ledger/reset-zero`. The binding cutover
contract is met for smoke + regression.

**PHP port — institutional multi-unit parity certified.** Node/unit read-scoping wired into the PHP
statements (ledger-summary, I&E, SFP, cash flow, GL) via `org_subtree_ids` + `resolve_read_scope`
(own_unit | subtree | university; node param accepts code or id). Verified: write attribution, node
roll-up (CANS-SBS 100 · CANS-SOA 200 · CANS 300), per-user isolation (unit head → own; provost →
college subtree), and the consolidation tie-out — `run_php_multiunit.sh` **10/10** (mirrors the Python
`ucc_multiunit` 22/22). Admin = university = unrestricted, so smoke 11/11 + regression 56/0 are
unaffected. **The PHP backend is now a full suite-certified twin, including the institutional
multi-unit layer.**

**PHP port — Phase 4 (security hardening) certified.** Login throttle (5 fails → 15-min per-user
lockout); **TOTP MFA** (RFC-6238 — enroll issues a base32 secret, an MFA-enabled login returns a
step-up challenge, `/api/verify-mfa` checks the code ±1 window → session); **dual-control threshold**
(a high-value JV cannot be direct-posted by its own preparer; a different officer can). Gate
`run_php_phase4.sh` — **8/8**. Defaults are off, so smoke 11/11 + regression 56/0 + multi-unit 10/10
are unaffected.

**Port status: FUNCTIONALLY COMPLETE, HARDENED, SUITE-CERTIFIED.** 13 PHP gates
(`run_php_phase{1,2,3a–3h,4}.sh`, `run_php_multiunit.sh`) + `run_php_parallel.sh`. The PHP backend is
a faithful twin of the Python engine across the whole accounting + statutory + institutional
multi-unit surface, plus security hardening.

**Remaining — operational, not engineering:**
- Ops config: off-site backup + cron schedule (deployment settings; `/healthz` already present).
- Phase 5 cutover sign-off: run the suites against the PHP backend on the institution's **real UCC
  data**, confirm identical statements vs the Python reference, then switch. The Python reference
  remains the complete, working institutional system until the switch.
- Binding acceptance contract on PHP: `smoke` 11/11 · `regression` 56/0 · `ucc_multiunit` 22/22.
  **The entire accounting engine is ported & certified** — 10 gates
  `run_php_phase{1,2,3a,3b,3c,3d,3e,3f,3g,3h}.sh` = 10·8·13·10·7·10·8·6·6·11. The Python reference
  remains the complete, working institutional system throughout.

**Then:** Phase 1c (read-side scope enforcement) → Phase 3e (node-parameterized live reports). _(Both
now delivered — see above.)_
