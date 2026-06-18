# UCC-FMS — PHP deployment guide (cPanel / Apache / PHP 8 + SQLite)

The PHP port is a **single front controller** (`php/index.php`, ~2,900 LOC) that
serves the SPA, the static assets, and the entire `/api` surface. It runs against
the **same SQLite database** as the Python reference, so the two can run in
parallel during cut-over (the PHP side owns only its `php_sessions` /
`php_mfa*` / `php_login_attempts` tables).

**Status:** passes the full acceptance gate — `regression_fixes.py` **78/78** and
`smoke_test.py` **11/11** — i.e. behaviourally at parity with the Python app on
the finance-correctness contract.

## Requirements
- PHP **8.0+** with extensions: `pdo_sqlite`, `json`, `mbstring`, `openssl`, `zip`
  (`zip` powers XLSX export + the audit-pack ZIP; everything else is core).
- Apache with `mod_rewrite` (standard on cPanel), **or** Nginx (see note below).

## Directory layout
Upload the repo so the front controller sits one level **below** the SPA:

```
<app-root>/
  index.html          ← SPA (served by the front controller)
  assets/             ← JS/CSS/img
  php/
    index.php         ← front controller (set the web docroot here)
    .htaccess         ← routing + hardening (included)
  ucc_fms.db          ← SQLite DB  (KEEP OUTSIDE the docroot in production — see below)
```

Point the cPanel domain/subdomain **document root at the `php/` directory**.
`index.php` reads the SPA and assets from its parent (`dirname(__DIR__)`), so the
SPA does not need to live inside the docroot.

## Database path
`db()` resolves the SQLite file in this order:
1. `SBS_DB` environment variable (absolute path) — **preferred for production**.
2. `RENDER_DATA_DIR` or `/var/data` if present.
3. `ucc_fms.db` next to the SPA (falls back to legacy `sbs_fms.db`).

Set `SBS_DB` to a path **outside the web root** (e.g. `/home/<cpanel-user>/data/ucc_fms.db`):
- cPanel "MultiPHP INI Editor" / `.user.ini`:  `SBS_DB=/home/USER/data/ucc_fms.db`
- or an Apache `SetEnv SBS_DB /home/USER/data/ucc_fms.db` in the vhost.

The DB file and its directory must be **writable by the PHP/Apache user**
(WAL mode creates `-wal`/`-shm` siblings). `chmod 660` the file, `750` the dir.

## Security notes
- The bundled `.htaccess` blocks HTTP access to `*.db`, dotfiles and `.user.ini`,
  but the database should still live **outside** the docroot.
- `index.php` forces `display_errors=0` + `log_errors=1`, so PHP notices/warnings
  never leak into a JSON response; check the PHP error log for diagnostics.
- HTTPS only (cPanel AutoSSL). Passwords are PBKDF2/legacy-sha256 dual-verified.

## First run / smoke check
```bash
# from the repo root, locally:
SBS_DB=/path/to/ucc_fms.db php -S 127.0.0.1:8421 php/index.php
curl -s localhost:8421/healthz            # {"ok":true,"status":"ok",...}
python3 smoke_test.py     --base http://127.0.0.1:8421 --user admin --pass UCC@2024 --period 2026-06
python3 regression_fixes.py --base http://127.0.0.1:8421 --user admin --pass UCC@2024 --period 2026-06
```
Expect `SMOKE TEST: 11/11` and `REGRESSION (finance fixes): 78/78`.

## Parallel-run cut-over (recommended)
1. Deploy PHP pointing `SBS_DB` at the **live** SQLite file the Python app uses.
2. Run both for a period; the gate above is the acceptance contract on the shared DB.
3. The PHP side only writes its own `php_*` tables plus the same business tables
   the Python app writes — every JV is balanced and posted through the same GL,
   so the trial balance stays at zero across both.

## Nginx (alternative)
Route everything to the front controller:
```nginx
location / { try_files $uri /php/index.php$is_args$args; }
location ~ \.(db|sqlite3?|db-wal|db-shm)$ { deny all; }
```

## What is NOT in the PHP port yet (non-gate)
The backend passes the full finance gate. Browser QA against the PHP backend has
been run for the main path: login → dashboard renders with live data, zero console
errors, and every SPA list endpoint the UI iterates (`departments`, `dept-summary`,
`vendors`, `approvals`, `fuel-vehicles`, `procure-to-pay`, `attachments`, …) now
returns the bare-array shape the SPA expects (these were added/aligned during QA).
A full click-through of **all ~40 SPA views** (driven via the SPA's own `nav()`
router) now renders with **zero console errors** against the PHP backend — several
view feeds (fuel-coupons, fx-rates, audit, payroll months/employees/settings,
quarterly-budgets, budget-periods, budget-uploads, departments, procure-to-pay,
vendors, approvals) were added/shape-aligned to the bare-array/object shapes the SPA
expects. Performance: all GET endpoints time 2–5ms warm on a populated DB; the port self-
ensures its hot indexes (`ensure_perf_indexes`). SMTP: `/api/email/status` shows the
outbox + config state; the only go-live step left is setting `SMTP_HOST/PORT/USER/
PASSWORD/FROM` on the server (email/dunning/remittance queue gracefully until then).

**Navigability audit (per-view render sweep).** A browser sweep drove all 71 `nav()`
targets and instrumented `fetch` to catch HTTP 4xx on render (the SPA swallows these
into empty views, so console errors alone don't reveal them). It found — and this round
fixes — two real navigable-view **crashes** the shape-tolerant gate could not catch:
`/api/jvs` and `/api/fund-receipts` were wrapped as `{ok,jvs}` / `{ok,receipts}` but the
SPA's JV and Receipts views do `jvs.forEach(...)` / `receipts.reduce(...)` on a bare
array (the Python reference returns bare arrays) → both now return bare arrays. Four
finance feeds the SPA depends on and the Python reference serves were also ported, so
their views render fully: `/api/bank-reconciliations` (reconciliation view),
`/api/opening-balance-wizard` (opening-balances view), `/api/changes-in-net-assets`
(Statement of Changes in Net Assets/Equity, IPSAS 1, with prior-FY comparative) and
`/api/notes-to-accounts` (full IPSAS-1 notes: policies, GL-derived breakdowns, PP&E
movement schedule IPSAS 17.88, segment note IPSAS 18, commitments/contingencies IPSAS 19,
related parties IPSAS 20, compliance statement — ties to the SFP balance-sheet identity
and cross-ties to the I&E statement). Remaining render-time 404s are NON-regressions:
they are endpoints the **Python reference also 404s** (`/api/opening-balances/list`) or
pure global chrome (`/api/app-version`, `/api/notification-summary`, `/api/ai/context`,
`/api/document-watermark`) that degrade gracefully and never crash a view. The pure
governance/meta views (ai-governance, quality-seal, launch-lock, system-health, …) remain
intentionally absent (non-finance, no navigable crash).
