# Recovery & Scaling Runbook — UCC-FMS / AOI-FMS

_Last reviewed: 2026-05. Applies to both apps (identical architecture)._

## 1. Backups — how they work

- **Location:** `/var/data/backups/` on the Render **persistent disk** (survives redeploys & restarts). Falls back to the app directory only when running locally.
- **Engine:** `api_create_backup()` uses SQLite's **online backup API** (`sqlite3.Connection.backup`), which produces a *consistent* snapshot **including committed transactions still in the WAL** (`-wal`) file. A plain file copy of the `.db` in WAL mode can silently omit recent data — do not revert to `shutil.copy2`.
- **Triggers:**
  - Manual — Institutional Console → **Create Backup** / **Download Backup**.
  - Scheduled — `scheduled_backup_config` (default every 24h). The check is *pull-based*: it runs when an admin loads the backup/console/dashboard pages.
- **Verified:** `PRAGMA integrity_check = ok`, all tables present, live vs backup row counts match (covered by the verification done in this runbook).

## 2. Tested restore procedure

**Option A — in-app (preferred):** Institutional Console → **Restore**, upload a `.db` backup. A safety backup of the current DB is taken automatically before the overwrite.

**Option B — manual (disaster recovery):**
1. Stop the Render service (or scale to 0) so no connections are open.
2. On the persistent disk, replace the live DB (`/var/data/ucc_fms.db` or `/var/data/aoi_fms.db`) with the chosen backup file.
3. Delete any stale `*-wal` / `*-shm` sidecar files next to it.
4. Restart the service.

> **Always restart the service after a restore** so open connections re-open the restored file (the in-app restore message says this too).

## 3. Backup reliability recommendations

- The scheduler is pull-based, so guaranteed cadence depends on admin activity. For a hard daily guarantee, add a **Render Cron Job** that hits an authenticated backup trigger, or keep the in-app schedule plus a routine daily admin login.
- The Render disk is single-region. Periodically **download an off-site copy** (the Download Backup button returns the file) and store it outside Render.

## 4. Scaling: SQLite → PostgreSQL (recommended for concurrent institutional use)

**Why:** SQLite has a single-writer lock. Under concurrent writes you can hit `database is locked` (observed during testing when a stray process held the DB). PostgreSQL removes this, and on Render gives managed backups + point-in-time recovery.

**Scope / steps:**
1. Provision a Render PostgreSQL instance; expose `DATABASE_URL` to the service.
2. Add a DB abstraction in `get_db()`: return a `psycopg` connection when `DATABASE_URL` is set, else the current `sqlite3` connection. Centralise a param-style shim (`?` → `%s`).
3. Port the schema (the `CREATE TABLE` statements): `TEXT/REAL/INTEGER` → `text/numeric/bigint`, drop `PRAGMA`, `AUTOINCREMENT` → identity/serial.
4. Sweep SQLite-specific SQL: `strftime('%Y', x)` → `to_char(x::date,'YYYY')` / `extract`; `INSERT OR IGNORE` → `INSERT ... ON CONFLICT DO NOTHING`; `datetime('now')` → `now()`.
5. Migrate data: the `postgres_migration_exports` table already scaffolds exports — dump current SQLite rows and load into Postgres.
6. **Gate the cutover on `smoke_test.py`:** point it at the Postgres-backed instance and require **11/11** before going live.

**Effort:** medium. The single-file design keeps SQL fairly centralised; the main work is the `strftime` / `PRAGMA` / upsert dialect sweep. `smoke_test.py` de-risks the cutover by proving the full posting→statements chain still ties out.
