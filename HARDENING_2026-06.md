# Finance Hardening — June 2026

A correctness + controls hardening pass across **SBS‑ERP** and **AOI‑FMS** (kept in parity).
Every fix was verified live against a UAT/preview instance and gated by two test suites.

---

## ✅ What changed (9 fixes + 1 feature)

| # | Area | Bug → Fix | Impact |
|---|------|-----------|--------|
| 1 | UI | Orphaned dead JS rendered as visible text at page bottom; removed | Cosmetic/professional |
| 2 | CSS (AOI) | Truncated `@keyframes` swallowed the mobile button‑wrap rules; completed | Mobile layout |
| 3 | Budget control | Direct PVs (no commitment) skipped the over‑budget block; wired it in (Finance‑Officer, setting‑gated, Admin‑exempt) | Over‑spend prevention |
| 4 | FX (SBS) | Gain/Loss **sign inverted** on foreign‑currency PVs; corrected + repaired historical rows | Correct FX reporting |
| 5 | Year‑end (SBS) | "Periods not closed" blocker queried non‑existent columns (error swallowed) → never fired; fixed | Prevents premature year‑end close |
| 6 | Fuel coupons | Stock reconciliation mishandled Borrow / Return‑Borrowed (two figures disagreed); aligned to the correct per‑batch model | Accurate coupon stock |
| 7 | Depreciation | Assets depreciated **past cost forever** (negative carrying); capped at depreciable amount | Correct PPE/NBV + I&E |
| 8 | Commitments | A **partial** payment marked the commitment Fully Paid and released the unpaid budget; now encumbrance‑aware (unpaid stays reserved, closes only when settled) | Over‑spend prevention |
| 9 | Bank reconciliation | Bank charges applied to the **wrong side** (off by 2× charges → reconciled accounts flagged as exceptions); moved to the cashbook side | Correct reconciliation |
| ★ | Global search | Results now jump to **and highlight** the exact record, not just open the list | UX |

The core statements (Trial Balance, SFP, Cash Flow, I&E, year‑end) were re‑confirmed
GL‑based, balanced, and tied. Withholding (compute + settlement), payroll, staff advances,
virements, interunit transfers and reversals were audited and verified **correct**.

---

## 🧪 How to verify (run against a UAT/preview instance — both create test data)

```bash
# GL integrity (11 checks)
python3 smoke_test.py        --base http://127.0.0.1:5002 --user admin --pass UCC@2024 --period 2026-06
python3 smoke_test.py        --base http://127.0.0.1:5001 --user admin --pass AOI@2024 --period 2026

# Finance-fix regression (12 checks — one per fix above)
python3 regression_fixes.py  --base http://127.0.0.1:5002 --user admin --pass UCC@2024 --period 2026-06
python3 regression_fixes.py  --base http://127.0.0.1:5001 --user admin --pass AOI@2024 --period 2026 --division ADM
```
Expected: **smoke 11/11, regression 12/12** on both apps. Run both as the gate before any
future deploy or a SQLite→PostgreSQL cutover.

---

## 🚦 Go‑live checklist (these are YOURS to do — not code)

> Ordered by risk. Item 1 is security‑critical.

1. **🔴 Rotate all default passwords.** The apps force‑set defaults on every startup
   (`admin`, `demo`, `finance01`, project users). Until changed, anyone who knows them can
   log in. Change them in **Settings → Change Password** for every active account, and review
   the force‑reset behaviour in `_v509_ensure_login_access` before go‑live so production
   passwords aren't reset on restart.
2. **Render environment variables** — set `BACKUP_S3_*` (off‑site backups), `CRON_TOKEN`,
   and `APP_URL`. Add a Render **Cron Job** hitting the backup endpoint for guaranteed
   backup cadence (the in‑app scheduler is pull‑based — it only fires when an admin loads a page).
3. **AOI opening balances** — load via the Opening Balance Wizard (posts a balanced AJV to the GL).
4. **Turn on dual control** — set `dual_control_threshold_ghs` in the Approval Centre so
   high‑value payments require a second approver (creator ≠ approver is already enforced).
5. **Budget‑overrun blocking** — `budget_control_block_overrun` defaults ON; confirm that
   matches your policy (Admins are always exempt).
6. **Confirm production picked up these commits** — verify the live Render deployments are on
   the latest `main` for both repos.

---

## 📝 Notes for the next engineer

- Both apps are single‑file layered builds: `app.py` (routes) + `server.py` (handlers, many
  functions redefined — **the last definition wins**) + `index.html` (SPA). When auditing a
  handler, find the *last* `def`, or test live — don't trust the first definition.
- Commitment budget encumbrance is now **derived**: `SUM(MAX(0, amount − posted_payments))`
  over commitments `NOT IN ('Cancelled','Fully Paid')`. Keep new budget queries consistent.
- Tax rates are hardcoded in `api_save_actual` (Ghana: WHT goods 3% / services 7.5% / income
  10% / sitting 20% / works 5%, WHVAT 7%, UCC Common Fund 5%, VAT 20%). There is no
  settings‑edit UI; if one is ever added it must feed these.
- Forward‑only data note: any asset already over‑depreciated, or any commitment already
  mislabeled by the old code, keeps its historical value (FX rows were auto‑repaired; the
  others were not, to avoid disturbing posted GL). Correct those via JV reversal if needed.

## 2026-06-10 — Stability audit round (cold-start + numbering integrity)

**Scope:** full architecture/flow re-audit of both apps from the 2026-06-07 builds; static sweeps
(JS parse 110 blocks / CSS brace balance 52 blocks / app-diff of 819 shared functions / frontend-to-route
parity of 304 API references) plus live gates on factory-fresh databases.

### Fixed
1. **Collision-proof document & code numbering (`_seq_code`, 14 sites).** Every sequential code
   generator previously used `COUNT(*)+1` against UNIQUE columns. Any delete, import or manual number
   desynchronised the count and the next insert crashed with `UNIQUE constraint failed` (HTTP 500).
   Confirmed live: `api_po_to_bill` vendor creation failed on a fresh DB, leaving POs received but
   unbillable. Replaced with `_seq_code(conn, table, column, prefix, width)` — MAX numeric suffix + 1,
   then bump-while-exists. Hardened sites: `_next_jv_number` (ALL GL postings), `_ar_next`, `_ap_next`,
   `_inv_next`, `api_save_vendor`, `api_po_to_bill` vendor, AP-import vendor, PR/PO/GRN/Contract/
   Inter-unit-transfer numbers, inventory-reorder PO number, legacy JV fallback.
2. **Regression gate is now self-sufficient on a fresh DB.** `regression_fixes.py` never set the
   Go-Live mode, so on a factory database (SETUP mode) PV posting was blocked and 5 checks failed
   unless `smoke_test.py` happened to run first. The gate now sets UAT after login; the P2P check also
   surfaces the server error (`billerr=`) on failure.
3. **`nav()` dead-ends rescued (`<script id="nav-fn-fallback">`).** Thirteen routes (statutory-filings,
   comparative-statements, leave-management, donor-reports, vat-calculator, year-end-close, budget-
   revision, bulk-import, approval-workflow, cagd-mapping, payment-voucher, comparative-report,
   project-closeout) were reachable only through their sidebar item's direct view-function call;
   programmatic `window.nav('<route>')` rendered "View not found". A final last-wins nav wrapper now
   falls back to the registered `window.vXxx` view when the chain dead-ends.

### Verified clean (no change needed)
- All inline `<script>` blocks parse (node --check) and all `<style>` blocks brace-balance, both apps.
- App-diff of all shared single-definition functions: remaining divergences are intentional
  (branding, AOI annual vs SBS monthly period logic, comments).
- Frontend/route parity: 304 distinct `/api/...` references in each index.html — all routed in app.py.
- Browser sweep: 27-30 major views render with ZERO console errors, both apps.
- Gates from factory-fresh DBs, first run: regression 38/38 + smoke 11/11, both apps.

## 2026-06-10 (later) — IPSAS 24 Statement of Comparison of Budget and Actual Amounts
- New report `Budget vs Actual (IPSAS 24)` (sidebar, Reports — next to Comparative Statements; route `ipsas24`).
- Backend `api_ipsas24(session, fy)` → GET `/api/ipsas24?fy=YYYY`: per budget line and aggregated by account —
  Original budget (current approved minus all logged `budget_revisions` changes), Final budget, Actual (posted PVs
  in the fiscal year on the line), open commitments (memo), variance and utilisation; unbudgeted posted spend;
  IPSAS 24.47 reconciliation to total general-ledger expenditure (payroll/depreciation/manual journals shown as
  expenditure outside the appropriation ledger); material variances (≥10% of a head) flagged for the IPSAS 24.29
  management explanation.
- `api_virement` now logs BOTH legs to `budget_revisions` (`Virement-Out`/`Virement-In`) so line-level Original
  budget reconstructs going forward.
- Printable A4-landscape statement with letterhead; on-screen view reuses the report total-band/print CSS.
- Regression gate extended to 39 checks (line totals + variance identity + GL reconciliation tie).

## 2026-06-10 (later) — Sidebar reclassification by finance function
- `<script id="sidebar-reorg-v2">` (end of body): regroups all 97 menu items into 16 functional groups
  in finance-cycle order — Projects & Grants · Planning & Budgeting · Revenue & Receivables · Procurement ·
  Payments & Payables · Treasury & Banking · General Ledger & Periods · Fixed Assets & Stores · HR & Payroll ·
  Tax & Statutory · Financial Statements · Management Reports · Approvals & Workflow · Administration ·
  System & Assurance · Help & Guide. Dashboard + the Finance Command Center (Overview) pinned at top.
- SAFETY DESIGN: items are MOVED as live DOM nodes (click handlers untouched), never rebuilt; a catch-all
  "Other Tools" group adopts any unmapped/late-injected item so nothing can be lost; original (emptied)
  groups + the old Finance Suite shell are hidden, not removed; role-awareness preserved (admin-only items
  simply don't exist for other roles, so their groups shrink/hide); group open/closed state persists per
  user (localStorage). The legacy fin-suite grouper retires itself when the reorganizer is present
  (prevents a mutation tug-of-war), and the reorg observer is throttled.
- Cleanup: hid the duplicate "Donor / Client Invoice" item (donor-invoice-d — same Invoice Designer view).
- Verified both apps: 97/97 items accounted for, 24-route click sweep OK, Finance-Officer view correct
  (System & Assurance hidden, Administration shrinks), collapse/expand + persistence working, zero console
  errors, smoke 11/11.

## 2026-06-10 (build round) — Audit Pack · PPE Schedule · Cash Book · Payment Runs ·
## Revaluation/Impairment · Approval Emails · Flash Email · Auditor Role · TOTP MFA

**New modules**
1. **External Audit Pack** (`/api/audit-pack?fy=`, view in Financial Statements): one-click ZIP of ten
   audit schedules — trial balance, GL detail, I&E, SFP, AR/AP aging, PPE movement, tax schedules,
   IPSAS 24 statement, bank-reconciliation register + manifest. Every section best-effort; failures
   reported, never fatal. Admin/Finance Officer/Auditor.
2. **PPE Movement Schedule** (`/api/ppe-schedule?fy=`, IPSAS 17 note): cost/depreciation/NBV
   roll-forward by category with revaluation & impairment columns and a GL tie (111x/119x).
3. **Cash Book** (`/api/cashbook`, Treasury & Banking): per-bank (or all-cash) receipts/payments with
   running balance from the general ledger.
4. **Payment Runs** (`/api/payment-runs`, `/api/ap/payment-run-file`, Payments & Payables): each AP
   batch settlement produces a bank-upload CSV (vendor bank details from the vendor master, missing
   details flagged), a printable transfer letter, and optional per-vendor remittance-advice emails.
5. **Asset revaluation / impairment** (`/api/assets/revalue`, button on the Asset Register):
   upward → Dr Asset / Cr Revaluation Surplus (auto-created 31200001 if absent); downward →
   Dr Impairment Loss / Cr Accumulated Impairment (auto-created 61900031 / 11912001). Admin-only,
   reasoned, posts via the canonical journal gate; register carrying amount updated.
6. **Approval e-mail notifications**: submitting for approval emails Admin/Finance Officer users;
   a decision emails the requester (wrappers over the live submit/process endpoints; queue-based,
   SMTP-optional).
7. **Monthly flash e-mail**: recipients configurable on the Month-End Flash view; sends on demand and
   automatically on the 1st with the daily cron (piggybacked on /api/cron/backup).
8. **Auditor role** (read-only): the users-table CHECK constraint is extended in place via
   writable_schema at first login (a table-rebuild approach was abandoned after it proved destructive
   in testing). An app.py guard blocks every Auditor POST except login/logout/password/MFA with a clear
   message; all GETs, reports, exports and the audit-trail viewer work.
9. **Authenticator (TOTP) MFA**: stdlib HMAC-SHA1 TOTP integrated into the existing MFA challenge
   framework (which was found to be orphaned — earlier api_login re-implementations bypassed the v42
   MFA wrapper entirely, so MFA never actually gated logins; a new FINAL wrapper enforces it).
   Setup/confirm endpoints + UI card on the MFA Security screen (admin: any user; others: self).
   Login flow: password OK → session revoked → 6-digit app code via the existing verify endpoint.
   Break-glass: MFA_RESET=1 env + restart disables all MFA; admin can also disable per user.

**Gates**: regression suite extended to **46 checks** (PPE identities, cash-book identity, audit-pack
sections, payment-run file, revaluation+impairment with SFP balance, Auditor lifecycle, full TOTP
lifecycle). 46/46 + smoke 11/11 on factory-fresh databases, both apps; browser-verified.

## 2026-06-10 (field-fix round) — 16 items from live user testing
1. Fuel ISSUE could not be saved: the ips fuel layer passed footer HTML to modal()/showModal whose 3rd
   parameter is an onOK callback — the custom "Save Issue" button was discarded and a dead default Save
   rendered. The modal helper now detects string footers and installs them properly. Issue saves verified E2E.
2. Fuel coupon serials now editable after entry: movement Edit modal gained Serial From/To fields and the
   movement-update endpoint persists them (both admin and non-admin branches); batch Edit already had them.
3. Bulk PV posting was self-defeating: after the feature inserted its own checkbox column, its table-finder
   (which required the FIRST header to be 'PV Code') no longer matched, so "Post N selected" exited silently.
   Finder now scans all headers. Verified: bulk post works.
4. NEW: multi-JV bulk posting (checkbox column + "Post selected JVs" on approved vouchers).
5. JV reversal asked for a reason server-side but offered no input: reason textarea added (≥5 chars), stored
   in the audit trail and Reversals Register.
6. Double-click double-save: client-side guard disables modal Save/Post buttons for 2.5s, and server-side
   creation wrappers reject an identical PV/JV saved within 10 seconds with a clear message.
7. Edit prefill: department/commitment/budget/bank selects load async and lost the record's values; a
   re-assertion pass now restores them once options arrive (incl. SBS 'dept' field naming).
8. Quick-search filter on the PV and JV lists (code, payee, description, amount, status — covers posted
   and unposted).
9. Smart type-to-search extended to the PV form's budget-line / commitment / department selects.
10. Print: floating chatbot/help/version widgets hidden in print; page heights/overflow flattened so
    multi-page reports paginate instead of clipping to one page.
11. Cleaner statements: report tables show plain figures with one "All amounts are in Ghana Cedis (GHS)"
    declaration in the header (display-layer; underlying data unchanged).
12. Legibility: GRA/SSNIT summary tiles' figures were pale-on-white in light mode (a dark-theme !important
    rule overrode their inline colours) — re-asserted; PV form tax/preview panels now solid readable panels
    in light mode.
13. Accounting periods pruned to FY2026: non-2026 periods with no ledger/journal activity are removed once
    at first login (periods carrying data are always kept).
14. VERIFIED (no change needed): viewing or opening-and-cancelling a posted PV/JV creates NO reversal; only
    SAVING an edit to a posted voucher auto-reverses and reposts — the audit-correct treatment (the original
    entry is immutable; the correction trail is explicit).
Gates: 46/46 regression + 11/11 smoke on fresh databases, both apps; every fix browser-verified.

## 2026-06-11 — Interaction-level audit (the missing audit layer) + dark-mode sweep
After live user testing exposed UI-layer bugs the API gates could not see, a new audit layer was
built and run: an in-browser interaction sweep that visits every sidebar view (69 per app), clicks
every creator/action button, opens every modal and verifies it has a live save handler, and checks
every onclick references a defined function.
- **Findings: ONE real bug** — the Recurring Commitments "+ New" button was dead whenever the list
  was empty (the empty-state render returned early before `window.f54RCNew` was assigned, so the
  first recurring payment could never be created). Fixed by assigning the handlers regardless of the
  empty state. All other 68 views, every button and modal: clean in BOTH apps.
- **Dark-mode contrast sweep** (new scanner that composites translucent backgrounds rather than
  skipping them): 12 views flagged on dark mode — grey/navy status badges kept light backgrounds
  under lightened text; inline light-mode colours (incl. `color:var(--navy)`/`var(--teal)` on the
  fuel tiles and `#0f172a` on statutory tiles) unreadable on dark panels; plain `.btn` falling back
  to the browser's light chrome; amber/blue/green inline info notes. All fixed with dark-scoped
  re-assertion rules; re-verified clean. Light mode: zero findings across all views.
Gates re-run after fixes: 46/46 regression + 11/11 smoke, fresh DBs, both apps.

## 2026-06-11 (accounting round) — Reversal dating + complete imprest Petty Cash
**Reversal dating (user-reported cash book distortion).** Live verification confirmed: PV-edit
reversals were always dated TODAY, so January corrections hit June's cash book (phantom inflows in
June, uncorrected outflows in January), and they carried is_reversal=0 so the Reversals Register and
tax-pack netting never saw them. Fixes:
1. Auto-reversals (PV edit) now post INTO THE ORIGINAL period whenever it is still open (IPSAS 3
   correction-of-error treatment) — the wrong entry and its reversal cancel in the same month and the
   corrected entry stands on the true date; only a closed/locked original period forces today's date.
2. Edit-reversals now set is_reversal=1 + reversal_of (plus a one-time backfill flags existing RJVs),
   so the Reversals Register, dual-control queue and tax-pack reversal netting all see them.
3. New Admin tool POST /api/journals/redate — moves a POSTED REVERSAL (and its ledger lines) to a
   chosen open-period date (defaults to the original transaction's date), reason required,
   audit-logged, restricted to reversal journals only (other postings stay immutable). A 📅 Re-date
   button appears on the Reversals Register for admins.
**Petty Cash 2.0 (was an unposted standalone register).** Full imprest system, every step posting
to the GL through the canonical journal gate: float establishment (Dr Petty Cash 12x cash account —
auto-created in the cash family so it flows to TB/SFP/cash book/cash-flow — / Cr Bank); numbered
petty-cash vouchers PCV-YYYY-NNNN (Dr Expense / Cr Petty Cash, over-spend blocked at the float
balance); replenishment to the imprest level (Dr Petty Cash / Cr Bank, amount auto-computed);
physical cash count & reconciliation with optional variance posting (shortage → expense, overage →
income); imprest ledger with running balance; printable voucher; live GL-tie badge on the float card.
The legacy register remains viewable; the imprest system is the book of record.
Gates: regression extended to **49 checks** (petty-cash cycle + GL tie, reversal-into-original-period
+ register visibility, re-date tool). 49/49 + smoke 11/11 fresh DBs, both apps.

## 2026-06-11 (imprest round) — Petty cash on the OFFICIAL Imprest accounts + mandatory accountability tie
1. Floats now live on the official UCC chart's **129x "Imprest – …" family** (selectable per float;
   auto-detection prefers official imprest accounts; the earlier auto-created account is removed when
   unused). The 129x family was added to every numeric cash-family classifier (Mo context, cash
   forecast opening, working capital, cash book, tax-pack bank predicates) — SFP and the cash-flow
   statement already matched imprest by name/1290 prefix.
2. **Accountability tie is now mandatory:** a float must belong to a department/unit (or a project) —
   a custodian name alone is rejected. Vouchers carry department + optional project (defaulted from
   the float, overridable), the project flows onto the GL lines, and the department is stamped into
   the ledger narrations, the float card and the voucher register.
Gates: 49/49 + 11/11 fresh DBs, both apps.

## 2026-06-11 (pre-UCC readiness audit) — fuzz, concurrency, soak, deployment kit
New audit dimensions ahead of institutional deployment:
1. **HTTP-500 shield**: both dispatchers (GET simple-map + POST routes) now convert any unhandled
   handler exception into the standard `{ok:false, error}` JSON envelope with a stderr traceback —
   a malformed request can never again surface a raw 500/HTML traceback to the client.
2. **Fuzz audit** of every POST endpoint (181 SBS / 182 AOI) with empty and garbage payloads plus a
   full no-session pass: ZERO 5xx, ZERO endpoints accepting unauthenticated writes. One latent bug
   found and fixed: `/api/actuals/tag-budget` crashed on a fresh database (selected the lazily-added
   `expense_coa_id` column before its migration ran) — now schema-defensive.
3. **Concurrency**: 10 simultaneous save+post payment vouchers — no "database is locked" errors,
   every voucher code unique, ledger balanced (validates the WAL + busy-timeout + collision-proof
   numbering stack under parallel users).
4. **Soak**: 47-operation mixed workload (taxed PVs across months, journals, petty cash cycle) then
   full invariant assert — SFP balances, cash-flow closing == SFP cash, integrity cockpit shows no
   danger ties, zero orphan ledger lines, petty-cash GL tie holds. Both apps identical results.
5. **UCC deployment kit** added to both repos: `passenger_wsgi.py` (cPanel "Setup Python App" entry),
   `erp.service` (systemd unit for a VM), `DEPLOY_UCC.md` (both options + operations runbook).
Gates after everything: 49/49 + 11/11 on fresh databases, both apps.

## 2026-06-12 — Withholding payables now arise at PV APPROVAL (policy change)
Per the finance owner's request, the tracked GRA/UCC withholding payables (WHT, WHVAT, UCF) are now
created when the payment voucher is APPROVED rather than when it is posted — giving the remittance
desk earlier visibility of upcoming statutory obligations. Accounting integrity is preserved by a
two-stage status:
- At approval the rows appear as **'Awaiting Posting'** (visible in Withholding Payables with an
  amber tag, NOT settleable — remitting against a liability that is not yet in the ledger is blocked
  with a clear message). All three approval paths are hooked (process, action, auto-approve).
- Posting the voucher flips them to **'Pending'** (settleable, exactly as before). Amounts re-sync at
  posting in case the PV was edited in between. The withholding↔GL integrity tie keeps counting
  'Pending' only, so the cockpit stays green throughout the in-between state.
Two latent fresh-database bugs were exposed and fixed along the way:
1. `api_process_approval` was written against the legacy approval_steps schema (`actioned_by`,
   `document_type`) and CRASHED on the live schema (`action_by`, approval_id linkage) — the route the
   Approval Centre uses. It is now schema-adaptive and also completes the parent `approvals` row
   (status/decided_by) on the live schema.
2. `withholding_payables.status` carried a CHECK constraint without the new stage — extended in place
   via the proven writable_schema technique (no rebuild, integrity-checked).
Gate grown to **50 checks** (full approve→awaiting→blocked-settle→post→pending→settle lifecycle).
50/50 + 11/11 fresh DBs, both apps.

## 2026-06-12 (clarity round) — corrected entries identifiable + self-describing withholding remittances
1. **"Edited PVs missing from the cash book" — investigated live: nothing was missing.** Every
   corrected journal already sat in the right month with the right bank lines (January nets exactly
   to the corrected amounts). The real problem was RECOGNITION: corrected entries carry internal
   journal numbers (PV-2026-0038) that don't visibly connect to the voucher codes users know
   (SBS/PV/2026/0053). Fixed at the root, no data surgery:
   - The Cash Book now resolves and shows the **voucher code** on every payment line (bold, with the
     internal journal number beneath) — corrections are instantly recognisable as their voucher.
   - Corrected journals now self-describe: "Corrected payment: … **[voucher SBS/PV/2026/00xx —
     replaces reversed entry]**" (both apps; AOI appends the marker at the edit-repost site).
2. **Withholding remittance vouchers now narrate themselves.** On settlement the PV's description —
   on the voucher AND on both ledger lines — is built from the source transaction:
   "**3% WHT - iMo Enterprise - Supply of Computers (source SBS/PV/2026/0001)**" (rate from the
   actual's WHT rate; WHVAT 7%; UCF 5%). Nobody re-types the story of a withholding, so it can be
   neither misleading nor altered.
Gate grown to **51 checks** (settlement self-description asserted). 51/51 + 11/11 fresh DBs, both apps.

## 2026-06-12 (cash book round) — net view, posted-voucher date correction, dating watchdog
Live audit first: every reversal already sits exactly on its original's date and cancelled pairs net
to 0.00 in every month — the books agree; the pain was visual. Three additions:
1. **Cash Book "Net view"** (default ON, toggleable): hides reversed entries and their reversals —
   but ONLY pairs that cancel on the same date, so the closing balance is provably identical to the
   gross book (asserted in the gate). A note counts the hidden lines; untick for the gross audit view.
2. **Posted-voucher date correction (Admin)**: a 📅 button on every posted PV row — corrects a wrong
   transaction date by moving the journal, its ledger lines, the voucher's own dates and the
   withholding due dates to the right date (reason required, audit-logged, open periods only, amounts
   untouchable). No reversal noise for a pure date error.
3. **Integrity watchdog**: the Financial Integrity cockpit now warns when any reversal is dated
   differently from the entry it reverses; the reversal_of links are backfilled from narrations so
   legacy reversals participate in pairing and the Net view.
Gate grown to **52 checks** (date correction + net/gross closing equality). 52/52 + 11/11 both apps.

## 2026-06-12 (multi-line round) — line-aware editing + payable in-place updates proven
1. **Multi-line PV editing was broken by design**: the Edit button opened the SINGLE-payment form
   showing only the total — saving from it could corrupt the line structure. Now a voucher with
   itemised lines opens a LINE-AWARE editor (all lines prefilled: account, description, amount,
   VAT/WHVAT/UCF, WHT type), lines can be changed/added/removed, and a POSTED voucher auto-reverses
   and reposts from the corrected lines (edit reason required, withholding-remitted guard enforced,
   voucher marker carried). Backend: /api/actuals/multiline now accepts {id, …} as a full update.
2. **Tax-rate correction before remittance — VERIFIED AND ASSERTED**: when a posted PV's WHT
   rate/type is corrected before the withholding is paid, the existing payable row is UPDATED IN
   PLACE (same row, new amount — proven 3%→7.5%, GHS 90→240, no duplicate ever created). Once the
   withholding HAS been remitted, the edit is blocked until that remittance is reversed.
Gate grown to **53 checks** (multi-line edit lifecycle incl. in-place payable). 53/53 + 11/11 both apps.

## 2026-06-12 (lifecycle round) — scenario-probed gaps the field tests hadn't reached
Probed ten correction-lifecycle scenarios end-to-end on fresh instances of both apps; five were broken and are now fixed (both apps):
1. **Deleting a draft PV left ghost tax liabilities**: its withholding payables stayed alive in
   the remittance register and a multi-line voucher's itemised lines were orphaned. Deletion now
   Cancels the payables (audit-logged) and removes the lines with the voucher.
2. **Reversing a remittance journal left the payable marked Paid**: the liability reappeared in
   the GL but the register said settled — and the source PV stayed permanently un-amendable.
   Reversal now returns the payable to Pending with settlement details cleared, ready to re-remit.
3. **Rejecting a PV in the approval workflow kept its approval-stage payables alive** — they are
   now Cancelled on rejection (both the approvals route and the step-action route).
4. **A withholding could be remitted DATED BEFORE the voucher that created it** — chronology
   guard added: remittance date must be on/after the source PV date.
5. **Re-dating into a never-opened period was allowed** (the guard only caught Closed/Locked rows,
   not missing ones) — re-date now passes the same period gate as posting.
Verified still-correct under probing: edit-removes-WHT cancels payable; posting into a Closed
period blocked; unposted multi-line edit; WHT+WHVAT pairs update in place (ex-VAT base by design).
Gate grown to **57 checks**. 57/57 + 11/11 on fresh DBs, both apps.

## 2026-06-12 (flow round) — bottom-to-top sweep of every flow's correction paths
Swept all GL-touching flows (PV, JV, fund receipts, AR, AP, petty cash, fuel, opening balances,
staff advances) for edit/reverse/void behaviour and report effects — 24-point sweep per app.
Verified-correct out of the box: posted AR invoices / AP bills / JVs immutable; over-credit /
over-payment / over-retirement guards; fuel batch reverse (proper RJV, dated at procurement);
opening-balance reverse; replenishment maths; receipt edits demand an Admin reason and keep
GL in step. Five gaps fixed (both apps):
1. **Self-approval of journals was possible** — a later api_jv_workflow re-implementation had
   orphaned the segregation-of-duties rule. Restored: a preparer can never approve their own JV.
2. **Workflow reversals were dated TODAY** (manual JVs, receipt journals): a June transaction
   reversed in August would distort August's cash book — the same defect PVs had. All
   jvs/workflow reversals now land on the ORIGINAL journal's date when that period is open.
3. **Reversing a receipt journal left the receipts register un-touched** — receipt-driven views
   kept showing reversed money as received. The register row is now un-posted with an audit note.
4. **AR receipts / credit notes and AP payments / debit notes could pre-date their document** —
   chronology guards added (money cannot move before the document it settles).
5. **Petty cash had no correction path** — new void endpoint + UI button: same-date reversing
   journal, voucher marked Voided, float restored. (Routes added to app.py both apps.)
Gate grown to **62 checks**. 62/62 + 11/11 fresh DBs, both apps; JS blocks 122/120 all clean.

## 2026-06-12 (polish round) — whole-app pass: every tab, every view, hidden destabilizers
Click-drove **all 101 sidebar tabs in each app** in a real browser (console-instrumented), swept
all ~210 GET endpoints, scanned boot logs, and ran light/dark visual checks. Results:
1. **Single-threaded local dev server** made data-heavy views appear to take 20–50s (every browser
   fetch queued). Local serving is now multi-threaded — all 101 views render in ~0.6–1s. (Render/
   gunicorn was already concurrent; this protects local demos and UCC's own hosting trials.)
2. **Duplicate sidebar tab removed**: "Donor / Client Invoice" appeared twice (an injection
   fallback also fired) — both opened the same Invoice Designer.
3. **AOI's Departments tab was dead** ("View not found" — AOI is units-based): now opens the Units
   register, relabelled "Units / Divisions". SBS keeps its real Departments view.
4. **"Comparative Reports" relabelled "Year-on-Year Comparison"** — it sat one row from
   "Comparative Financial Statements" with a near-identical name.
5. **FX baseline rates never seeded on a fresh install** (seed ran before the table existed, then
   with wrong column names; AOI had no offline seed at all) — both apps now seed GHS/USD/EUR/GBP
   after init_db, so the FX module works without internet access.
6. **Comparative report SQL referenced a column `budgets.start_date` that does not exist** (the
   budget line could never compute) — now schema-aware.
7. **BOG rate fetch** gains a certifi SSL fallback; v558 boot-noise NameError print silenced.
All 99 probeable tabs per app render with real content; zero console errors; boot logs clean.
Gate grown to **63 checks**; 63/63 + 11/11 fresh DBs, both apps; JS blocks all syntax-clean.

## 2026-06-12 (assurance round) — the two audits an external auditor actually runs
Built and ran two end-to-end assurance probes against a realistic seeded workload (grant in,
multi-line PV with WHT, AR invoice + part receipt, AP bill + part payment, manual accrual JV):
**A. Statement articulation (11 checks/app, all pass).** Proves the IPSAS statements cross-foot:
  - posted general ledger sums to zero; Trial Balance total debits = total credits;
  - SFP balances (Assets = Liabilities + Net Assets; presentation difference = 0);
  - Income & Expenditure surplus flows to SFP accumulated surplus;
  - Statement of Changes in Net Assets closing = SFP net assets, and its surplus = I&E surplus;
  - Cash Flow closing cash = SFP cash & bank line = Cash Book closing; cash flow articulates
    (opening + net change = closing);
  - SFP withholding liability = open Withholding Payables register.
  ONE fix: the SFP classified the WHT liability (held on "Ghana Revenue Authority", 21100014) as a
  general payable because it keyed only on the account *name*. Total liabilities were always
  correct; the sub-line now recognises the statutory-withholding accounts the tax engine credits
  (GRA 21100014, VAT Withholding 21100024, UCC Common Fund 21100027) so the SFP tax line ties to
  the register.
**B. Authorization enforcement (11 checks/app, all pass).** Proves least-privilege holds: a
  Finance Officer and a Project Leader are rejected from creating users, posting/reversing/
  re-dating journals, closing periods and deleting transactions; unauthenticated writes are
  refused; the Auditor role is globally read-only yet can still read every report.
Gate grown to **66 checks**; 66/66 + 11/11 fresh DBs, both apps.

## 2026-06-13 (field-fix round) — cashbook net view + multi-line petty cash
Two issues reported from live testing, plus latent bugs found alongside them; all fixed both apps.
1. **Cashbook "Net view" checkbox didn't reliably hide reversed entries.** The hide-set was a SQL
   `gl.jv_number NOT IN (subquery)` restricted to SAME-DATE pairs — two faults: (a) if any
   jv_number in the subquery was NULL the whole `NOT IN` went NULL and hid *nothing*; (b) a
   reversal in a different month from its original was never hidden even in a full-year view.
   Rewritten to compute the hidden set in Python after the gross rows are fetched: a reversed
   entry and its reversal are hidden together **only when both legs fall inside the displayed
   window** — so the closing balance is never distorted. Verified: June view keeps a straddling
   original (closing intact); full-year view hides the complete pair (closing intact); NULL-safe.
2. **Petty cash reworked to the real imprest model** (operated by every department incl. the Dean's
   office):
   - **All imprest GL accounts are selectable** — the state API now exposes the full 129x imprest
     family; the float-setup dropdown lists them, and a "New imprest float" button is always
     available so each department sets up its own.
   - **Multi-line disbursement**: a voucher can be split across several expense accounts — Dr each
     expense / Cr the float's imprest account, one credit for the total; the total is what gets
     reimbursed on replenishment. New `petty_cash_voucher_lines` table; the voucher modal has
     add/remove expense-line rows with a live total and an imprest/float selector.
   - Void now reverses from the original journal's actual GL lines (robust for multi-line); the
     printed voucher shows the full expense breakdown.
   Verified end-to-end: 3-line disbursement posts 3 expense debits / 1 imprest credit, balances
   tie, overspend blocked, void restores the float, multiple departmental imprests run in parallel.
Gate grown to **68 checks**; 68/68 + 11/11 fresh DBs both apps; articulation 11/11, authz 11/11.

## 2026-06-13 (petty-cash follow-up) — multi-float GL tie + cache bust
- The petty-cash "GL tie" indicator summed only one default imprest account, so once
  several departmental floats ran (each on its own imprest GL), every card showed a red
  "✕". Fixed: the tie now sums the GL across **every float's own imprest account**, so it
  ties correctly with multiple departmental imprests (verified 1,000 + 800 = 1,800 ✓).
- Bumped the service-worker cache (v3 → v4) on both apps so the previous build's cached SPA
  self-clears — the multi-line disbursement and "New imprest float" button appear without a
  manual hard-refresh. (Field note: a user saw the old single-float screen because the
  browser/PWA served the prior cached page; the code already supported multiple floats.)

## 2026-06-13 (petty-cash corrections) — edit/review/fix floats & disbursements
Answering "how do you edit, review and fix an error in a float amount or a disbursement
without breaking the flow" — the audit-safe way (posted journals are never mutated):
1. **Edit a float** (`/api/petty-cash2/float/edit`): name/custodian/department change with no
   ledger entry; changing the **imprest amount** posts the difference as a real cash movement
   (raise → Dr Imprest / Cr Bank; lower → Dr Bank / Cr Imprest), reason required, and is blocked
   from lowering below the cash on hand. The book balance and GL stay tied.
2. **Close a float** (`/api/petty-cash2/float/close`): undo a float created in error — only when
   untouched (no live vouchers); reverses the establishment (Dr Bank / Cr Imprest) and marks it
   Closed. Blocked if posted vouchers exist (void those first).
3. **Edit a disbursement** (`/api/petty-cash2/voucher/edit`): one action that voids the original
   (same-date reversing journal, marks it Voided) and re-issues a corrected new voucher (new PCV)
   from the supplied lines, cross-referenced in the audit trail; float-sufficiency guarded.
   "Review" is the existing Imprest Ledger + audit log.
UI: ✏ Edit float / ✕ Close on each float card; ✏ Edit beside Void on each voucher row (prefilled
multi-line editor, reason required). One math fix: an imprest-level change must NOT also record a
replenishment row (it would double-count) — the level change alone drives the book balance while
the journal drives the GL, so they tie. Verified E2E: 3000→2000→2500 adjusts, metadata edit,
voucher 400→250 void/reissue, close untouched float, close-with-vouchers blocked, GL imbalance 0.
Gate **69 checks**; 69/69 + 11/11 fresh DBs both apps. SW cache already at v4.

## 2026-06-13 (pre-takeoff audit) — full re-audit, IPSAS/IFRS module verification
Ran the whole battery fresh on clean DBs both apps (gate + smoke + flow-sweep + articulation +
authorization) — all green — then deep-probed the modules not previously exhausted, verifying each
against hand calculations and double-entry:
• Payroll statutory (Ghana 2026): employee SSNIT 5.5%, Tier-1 total 13.5% (EE 5.5% + ER 8%),
  Tier-2 5%, PAYE on the 2026 bands, net = gross − employee-tier1 − PAYE. Exact on both apps.
• Depreciation (IPSAS 17): straight-line 120,000 ÷ 5 ÷ 12 = 2,000/mo; Dr 619 Depreciation
  Expense / Cr 119 Accumulated Depreciation, balanced.
• Asset disposal (IPSAS 17): correctly requires the PPE cost + accumulated-depreciation accounts
  (UI supplies them), posts balanced, recognises gain/loss vs NBV.
• Inventory (IAS 2 / IPSAS 12): weighted-average — 100@10 + 100@20 → avg 15, 150 on hand; issue
  at average; GL balanced.
• AR/AP aging, budget control: respond correctly; GL stays balanced throughout.
• Look pass: 20 key views per app render with content and ZERO console errors; dark mode legible;
  petty-cash GL-tie shows green.
RESULT: zero product defects found. Locked the three highest-IPSAS-risk areas into the permanent
gate (payroll statutory, depreciation, inventory weighted-average) so a future change cannot
silently break them. Gate **72 checks**; 72/72 + 11/11 fresh DBs both apps.

## 2026-06-14 (recommendations build) — every pre-takeoff recommendation implemented
Built out all recommendations from the pre-takeoff audit (both apps):
1. **Year-end close — verified end-to-end** (was untested): surplus/deficit rolls to the
   Accumulated Fund (31100001), income & expenditure zero out at year-end, balances carry forward,
   GL stays balanced, a second close is blocked. Locked with a gate check.
2. **FX retranslation (IAS 21 / IPSAS 4)** — NEW. Period-end retranslation of foreign-currency
   bank balances at the closing rate; the difference posts as an unrealised exchange gain
   (42300028) or loss (61700019). Incremental across periods; reversible. New Treasury sidebar
   tab "FX Revaluation". Verified: 1,000 USD @15.0→15.5 = +500 gain; @15.5→14.0 = −1,500 loss; GL balanced.
3. **Tamper-evident audit log** — NEW. Every audit row is SHA-256 hash-chained
   (row_hash = hash(prev_hash | fields)); /api/audit/verify recomputes the chain and pinpoints any
   altered/deleted row; a green/red integrity badge shows on the Audit Log view. Chains across
   non-_audit inserts by linking to the last hashed row. Verified: intact passes; a row edit is detected.
4. **Bank reconciliation — persistent clearing** — NEW. Tick each cash-book line that cleared the
   bank; the cleared state persists (bank_recon_cleared), so cleared items never reappear and the
   outstanding items reconcile the book balance to the statement. New Treasury tab "Statement Clearing".
5. **UCC-wide consolidation** — NEW. Each app exports its trial balance (/api/consolidation/export);
   a "Consolidation" tab (Financial Statements) merges this entity with pasted exports from other
   entities into a consolidated trial balance with per-entity columns and a balanced check.
6. **Go-live readiness** now also flags off-site (S3) backup, a read-only Auditor login, and MFA —
   alongside the existing default-password / SMTP / backup checks (these remain user-actioned).
User-only items (cannot be done in code): rotate default passwords, enable TOTP MFA, create the
Auditor login, set SMTP_* / BACKUP_S3_* / CRON_TOKEN env vars.
Gate grown to **77 checks**; 77/77 + 11/11 fresh DBs both apps; all new views render with zero
console errors; SW cache bumped v4→v5.

## 2026-06-14 (trends fix) — Management Reports → Trends always returned zeros
Field-reported: the Trends view showed nothing. Root cause: `api_trends` built its SQL with
`"... WHERE period IN %s" % inlist` while the query body contained `LIKE '4%'` / `LIKE '6%'` — the
`%` literals collided with Python's `%` string-format operator, raising ValueError that a bare
`except: rows={}` swallowed, so every period returned 0/0/0. Rewrote the query to be format-safe
and to bucket by the MONTH of the ledger date (robust to SBS's YYYY-MM vs AOI's YYYY period tags),
taking the account class from the GL code with a fallback to the joined chart code; income = 4/7,
expenditure = 5/6, posted entries only. Verified: a GHS 3,000 expense in the month now shows
(expenditure 3,000, surplus −3,000) on both apps; previously all zeros. Locked with gate check #70.
Gate **78 checks**; 78/78 + 11/11 fresh DBs both apps.

## 2026-06-14 (masking-except sweep) — silent failure-hiding excepts surfaced
After the Trends bug (a swallowed ValueError that silently zeroed a report), swept both apps for
the same anti-pattern. Findings:
- Scanned 477 except sites: the overwhelming majority are legitimate (migrations, "column already
  exists" ALTERs, rollbacks, BrokenPipe, column-introspection helpers returning [] — all correct).
- **No other same-literal `%`-format traps** exist (the Trends `LIKE '4%' ... IN %s % inlist` defect
  was the only one of its kind; tax_schedules looked similar but its LIKE parts are concatenated
  separately, so it works — verified accrued/lines populate).
- Hardened the genuinely risky class — report/query endpoints whose `except` silently substitutes
  empty data — to LOG to stderr first (so a future query failure is visible in logs instead of
  showing a blank report), while keeping graceful degradation. Sites: trial balance, SSNIT
  remittance, tax-schedule lines, finance overview, audit-CSV export, approval centre, and the
  three advanced-query branches.
Behaviour unchanged (logging only). Gate 78/78 + 11/11 both apps.

## 2026-06-14 (performance) — DB indexes + slowness diagnosis
Investigated "live feels slow" by timing the live endpoints (HTTP, not the Render dashboard).
Findings:
- gzip IS applied (2.5MB shell → 637KB on the wire) and ETag/304 revalidation works (~0.6s, no body).
- **The dominant cost is network round-trip + Render tier**: a 166-byte `/api/me` takes ~0.75s LIVE
  but **0.001s locally** — i.e. ~0.7s of every request is latency/CPU on the hosting side, not the
  app. This is addressed by Render plan/region (USER-ONLY): upgrade off the free/starter shared-CPU
  tier (which also cold-starts after idle) and pick the region nearest Ghana (EU/Frankfurt) — see
  the deploy guide. No code change can remove network RTT.
- **Real code issue fixed — missing indexes**: general_ledger had NO index on coa_id/jv_id/
  ledger_date/period/jv_number, yet every report filters/joins/sums on them → full table scans that
  worsen as the ledger grows. Added 15 idempotent indexes (general_ledger ×6, journal_vouchers ×4,
  actuals ×2, fund_receipts, audit_log timestamp, petty_cash_vouchers) created at startup and
  **backfilled onto existing databases on deploy** (verified: a 40k-row GL DB went 0→15 indexes on
  boot; date-filtered GL query 0.32s→0.035s, cashbook 16ms, trial balance 30ms).
Behaviour unchanged; gate 78/78 + 11/11 both apps.

## 2026-06-14 (stale-cache auto-update) — "only one imprest/bank in float setup"
A user saw only ONE imprest account and ONE bank in the Set-Up-Float dropdowns on live. Verified
it was NOT data or code: live /api/coa returns 11 imprest accounts and /api/bank-accounts 176; the
live index.html has the correct multi-imprest pc2Setup (matches local byte-for-byte); a fresh load
renders all 11 + all banks. Root cause: the browser/PWA was serving a STALE cached build (3rd such
symptom). Permanent fix: the service-worker registration now auto-updates — it calls reg.update()
on load + every 5 min, and reloads the page once when a new SW takes control (controllerchange,
guarded so first install doesn't loop). Bumped SW cache v5→v6. From the next deploy on, every
client self-refreshes to the latest build within one load — no manual hard-refresh needed. (This
deploy still needs one hard refresh to pick up the new registration code itself.) Gate unaffected.

## 2026-06-14 (docs + chatbot) — user manual & Mo' updated with all new capabilities
Updated the in-app User Manual (user_manual_sections seed, upserts on boot) from 8 to 18 sections,
adding: Petty Cash (Imprest), Correcting Petty Cash Errors, Editing & Reversing Posted Transactions,
Cash Book & Net View, Withholding Tax & Remittance, FX Revaluation (IAS 21), Bank Reconciliation
Statement Clearing, UCC-Wide Consolidation, Year-End Close, Tamper-Evident Audit Log. Added 6 entries
to the institutional feature registry (imprest, FX revaluation, audit chain, bank clearing,
consolidation, year-end). Updated the Mo' chatbot system prompt (_aiSystem) with a RECENT
CAPABILITIES block covering every new feature so it answers accurately. SW cache v6→v7 so clients
auto-pull the new chatbot knowledge. Gate 78/78 + 11/11 both apps.
