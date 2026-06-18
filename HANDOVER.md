# UCC-FMS — University of Cape Coast Financial Management System — Handover & Operations Guide

_Institutional finance system for University of Cape Coast (CANS), University of Cape Coast. IPSAS-aligned, Ghana PFM Act 921 / Ghana PAYE compliant._

## 1. What it is & how it runs
- **Entry point:** `app.py` is the WSGI app (`gunicorn app:app`); `server.py` holds all business logic; `index.html` is the single-page UI. Deployed on **Render** (see `render.yaml`).
- **Database:** SQLite in **WAL mode**, stored on the Render **persistent disk** at `/var/data/ucc_fms.db` (survives redeploys). Locally it falls back to the app folder.
- **Dual route tables (important):** new `/api/*` endpoints must be registered in **both** `app.py` (production/gunicorn) **and** `server.py` (standalone). 

## 2. Logins & security
- **Default admin:** `admin` / `UCC@2024` (and `demo` / `Demo@2024`). These are **force-reset on every startup** so the institution can never be locked out — **change them after first sign-in** (a dismissible banner reminds you).
- **Login protection:** failed-attempt **audit logging** + rate limiting. A *correct* password always works immediately; throttling only affects repeated *wrong* attempts and never hard-locks a known-good account.
- **Sessions:** 30-minute idle auto-logout (re-login with the same credentials).
- **Emergency admin reset:** set `ADMIN_RECOVERY_TOKEN` env var, GET `/api/admin-recovery?token=...` (new password printed to logs only), then remove the env var.

## 3. Financial integrity (verified)
All financial statements derive from the **general ledger**, so they reconcile to the Trial Balance:
- Statement of Financial Position: **Assets = Liabilities + Net Assets** (presentation difference = 0).
- Cash Flow: closing cash = SFP cash (cross-statement tie).
- Income & Expenditure and **year-end surplus/deficit** both GL-based and tie to each other.
- Every posting flow reaches the GL: payment vouchers, payroll, **asset depreciation**, fuel coupons, and manual journal vouchers.

### Regression smoke test
```
python3 smoke_test.py --base <url> --user admin --pass 'UCC@2024' --period 2026-06
```
Runs the full posting chain and asserts every statement ties out (**11/11 = healthy**). It also runs automatically on every push via GitHub Actions (`.github/workflows/smoke.yml`).

## 4. Operations
- **Health check:** GET `/healthz` (also `/api/health`) returns `{"db":"ok"}` and HTTP 200 when healthy, **503** if the database is unreachable. Use it for Render health checks / UptimeRobot.
- **Backups:** WAL-consistent SQLite snapshots (online backup API) to `/var/data/backups/`. Create/download from the **Institutional Console**, or via the daily cron below. Restore from the same console (a safety backup is taken first) — **restart the service after a restore**. Old backups are **auto-pruned** beyond `BACKUP_RETENTION_DAYS` days (default 30; set 0 to disable), always keeping the newest 7 so history is never wiped.
- **Daily automated backup:** set `CRON_TOKEN` (a long random string) and `APP_URL` env vars; the `render.yaml` cron service calls `POST /api/cron/backup` once a day (02:00). Off-site copies: use Download Backup periodically.
- **Year-end close:** Institutional Console → Year-End. Blocks until periods are closed and bank recs signed off; records the GL-based surplus and carries retained earnings forward. Opening balances post a balanced AJV via the Opening Balance Wizard.

## 5. Scaling
SQLite is fine for current load. For higher concurrency, migrate to PostgreSQL — see **RECOVERY_AND_SCALING.md** for the scoped plan (gate the cutover on the smoke test).

## 6. Recent hardening (this release)
- SFP, Cash Flow, I&E and year-end made fully GL-based (tie to the Trial Balance).
- Manual journal vouchers and asset depreciation now post to the GL (were silently not posting).
- Backups switched to WAL-consistent snapshots.
- Login audit + throttle, idle logout, default-password banner (non-blocking).
- `/healthz` DB check, `/api/cron/backup` endpoint, CI smoke test, UI legibility overhaul (light + dark).
