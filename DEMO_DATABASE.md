# Demo Account — Database Isolation

**Status: CONFIRMED ISOLATED** (verified live on ucc-fms-3b70.onrender.com)

## Summary
The `demo` account operates on a **completely separate database** from the live
institutional data. Live work and demo activity can never read or write each
other's data.

## Evidence (verified on the live site)
Logged in simultaneously as `admin` (live) and `demo`:

| Metric          | Admin (live DB) | Demo (sandbox DB) |
|-----------------|-----------------|-------------------|
| Budgets         | 190             | 0                 |
| Bank accounts   | 176             | 175               |

The two sessions see different datasets at the same instant — proof they are
backed by different database files.

## How it works
On every request the backend decides which database to open:

1. `app.py` records the current session for the request
   (`set_current_session(sess)`).
2. `get_db()` calls `_cf_is_demo_session(session)`, which returns true when the
   session's **username, full name, email, or role** contains `demo`, `trial`,
   or `uat`.
3. The built-in `demo` user (username `demo`, email `demo@sbs.ucc.edu.gh`)
   matches, so all of its reads and writes are routed to a separate demo
   database file; everyone else uses the live database (`/var/data/...`).

## Things to know
1. **The demo sandbox is ephemeral on Render.** It lives in the server's
   temporary storage, so it **resets to a fresh seed on every restart or
   redeploy**. This is normal for a demo (always clean) — demo data is not meant
   to persist.
2. **Ignore the "DB path" shown in System Health.** It always displays the main
   live path regardless of routing; it is cosmetic. The routing is per-request,
   and the data difference above is the real proof.
3. **Isolation depends on the demo user keeping a demo-like identity.** Renaming
   the demo user to something without `demo`/`trial`/`uat` would route it to the
   live database. Keep the username/email as-is.

## Detection keywords
A session is treated as demo when any of these appear (case-insensitive) in the
username, full name, email, or role: `demo`, `trial`, `uat`.
