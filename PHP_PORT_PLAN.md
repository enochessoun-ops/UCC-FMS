# PHP Port Plan — UCC-FMS & AOI-FMS
_Phase-governed re-implementation of the backend in PHP, with the existing 60-check verification
suite as the binding acceptance contract. Drafted 2026-06-11._

## Why this is tractable
1. **The entire user interface carries over unchanged.** `index.html` is a self-contained SPA that
   talks JSON to the backend; it is language-agnostic. The port is the API layer + accounting engine.
2. **Acceptance is mechanical, not judgemental.** `smoke_test.py` (11 checks) and
   `regression_fixes.py` (49 checks) are plain HTTP clients. Pointed at a PHP backend they certify —
   or refuse to certify — functional parity: balanced postings, statement ties, tax math, controls,
   petty cash, reversal dating, MFA, the Auditor role. **60/60 green on PHP = done; anything less = not done.**
3. **The contract is already extracted.** `API_CONTRACT.md` (this repo) lists every route (~430 per
   app), method, query parameters, request fields, and probed response shapes.

## Target stack (UCC-compatible)
- PHP 8.x on Apache (cPanel) · PDO with **SQLite** first (the live `.db` file works as-is — zero
  data migration), optional MySQL profile later if the Directorate prefers.
- Single front controller (`index.php`) routing the `/api/*` surface; sessions via the same
  `X-Session-ID` token model; PBKDF2 password verification compatible with existing hashes.

## Phases (each ends gate-certified; the Python system stays live throughout)
| Phase | Scope | Acceptance |
|---|---|---|
| 0 ✅ | API contract extraction (routes, params, request fields, response shapes) | `API_CONTRACT.md` in repo |
| 1 | Foundation: router, auth/sessions, PDO layer, response envelope, role guard, audit log | login/logout/me + Auditor write-block checks pass |
| 2 | The accounting core: chart of accounts, canonical balanced-journal gate, period guard, sequential codes (`_seq_code` semantics), general ledger | smoke checks 1–4 (JV validation, TB balance) pass |
| 3a | Payments (PV) + Ghana tax engine + budgets/commitments/encumbrance + vendors | regression PV/tax/budget/commitment checks pass |
| 3b | Receipts, journals workflow (submit/approve/post/reverse incl. original-period dating), withholding settlement | reversal & withholding checks pass |
| 3c | Payroll (PAYE/SSNIT), fuel coupons, fixed assets (depreciate/revalue/dispose) | payroll/fuel/asset checks pass |
| 3d | AR, AP, inventory, petty cash imprest, recurring engines | module checks pass |
| 3e | Statements & reports (TB, I&E, SFP, CF, Changes, Notes, IPSAS 24, statutory filings, audit pack, cashbook, PPE) | smoke 5–11 (statement ties) pass |
| 4 | Security parity (TOTP MFA, dual control, throttle), ops (backups, cron), exports/prints | full 60/60 on PHP |
| 5 | Parallel run on UCC server (PHP) vs reference (Python) on identical data; sign-off; cutover | identical statement outputs on the same data |

## Working rules
- Module order is dependency order; no phase starts until the previous is green.
- Every PHP module is built against the SAME SQLite file format — at any moment the data can be
  opened by either implementation, which makes the parallel run in Phase 5 trivial.
- The Python implementation is the reference: behavioural questions are answered by reading/running
  it, never by guessing.
- No UI rewrites: any UI defect found during the port is fixed once, in the shared `index.html`.

## Honest sizing
~430 endpoints, an accounting/tax engine, and a controls layer: this is a multi-month professional
effort even with the contract and acceptance suite in hand. The phase table — not a calendar — is
the commitment device: progress is measured in certified phases.
