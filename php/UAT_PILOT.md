# UCC-FMS — UAT pilot guide

Two parts: **(A)** the running pilot instance you can use right now on this machine, and
**(B)** the runbook to put it on a real `uat.` subdomain on the UCC server.

---

## A. Pilot it now (running locally)

A UAT instance is **already running** on this machine against a clean, realistically
seeded database.

| | |
|---|---|
| **URL** | http://127.0.0.1:8421 |
| **Login** | `admin` / `UCC@2024` |
| **Database** | `~/ucc-uat/ucc_fms.db` (persistent — your pilot data survives restarts) |
| **Seeded with** | the full org tree (60 units), 843 chart-of-accounts lines, 20 accounting periods, 175 bank accounts; **0 transactions** (clean start) |
| **Email** | queue-only (no SMTP set) — statements/dunning collect in the outbox, nothing sends. Expected for UAT. |

### Restart it (if the server stops)
```bash
SBS_DB=~/ucc-uat/ucc_fms.db php -S 127.0.0.1:8421 ~/Documents/GitHub/UCC-FMS/php/index.php
```
Then open http://127.0.0.1:8421 and log in. To start the pilot over from a clean
seed, delete `~/ucc-uat/ucc_fms.db` and re-copy a fresh one from the seeder.

### A 10-minute pilot script (exercises the whole chain)
1. **See the tree** — sidebar → **STRUCTURE → Organisation Tree**. Expand a College;
   click **💰 Financials** on a node to see its consolidated (subtree) figures.
2. **Create a Payment Voucher** — Payments → New PV. Pick a **Unit / Cost Centre**
   (the tree-indented picker), a project, payee, bank, an expense line. Save → Post.
3. **Confirm it hit the ledger, scoped** — Reports → **Trial Balance** / **Cashbook**;
   filter by the unit you charged. The amount appears under that unit and rolls up to
   its College; a sibling College shows nothing.
4. **Approvals** — submit the PV for approval, then approve it (Approvals view).
5. **Per-unit reporting** — Financial Statements → SFP / Income & Expenditure with the
   unit filter; the figures tie to what you posted.
6. **Inter-unit transfer** — Treasury → Inter-unit transfer: move budget from one unit
   to another; on approval it posts a balanced clearing journal per unit.
7. **Scoped login (optional)** — Administration → Users → add a Finance Officer homed
   at one College with scope **subtree**; log in as them in a private window. They see
   only their College's data; the header chip shows their unit; they cannot widen it.

### Health / acceptance (optional, from the repo root)
```bash
python3 smoke_test.py       --base http://127.0.0.1:8421 --user admin --pass UCC@2024 --period 2026-06
python3 regression_fixes.py --base http://127.0.0.1:8421 --user admin --pass UCC@2024 --period 2026-06   # run on a fresh seed
python3 tree_acceptance.py  --base http://127.0.0.1:8421 --user admin --pass UCC@2024
```
> Run `regression_fixes.py` on a **fresh** seed (not after `smoke_test.py` on the same
> DB) — smoke approves the 2026-06 payroll, which would block regression's payroll check.

---

## B. Put it on a real `uat.ucc.edu.gh` subdomain (UCC IT runs this)

I can't access the UCC server, so these are the exact steps for whoever has cPanel.

1. **Create the subdomain** in cPanel (e.g. `uat.ucc.edu.gh`) and point its document
   root at the repo's **`php/`** directory (the front controller serves the SPA from its
   parent). Enable AutoSSL (HTTPS).
2. **Upload** the deploy bundle (`~/Downloads/UCC-FMS_PHP_deploy_20260618.zip`) and
   extract so the layout is:
   ```
   <subdomain-root>/index.html, assets/, php/index.php, php/.htaccess
   <home>/data/ucc_fms.db          ← keep the DB OUTSIDE the web root
   ```
3. **Point the DB** outside the docroot (cPanel MultiPHP INI / `.user.ini` or vhost):
   `SBS_DB=/home/<cpuser>/data/ucc_fms.db` — seed it with the UAT DB (`~/ucc-uat/ucc_fms.db`).
   `chmod 660` the file, `750` its directory, owned by the PHP/Apache user (WAL needs write).
4. **Verify the environment**: `php php/preflight.php` → expect `READY`. Then delete or
   deny `preflight.php`.
5. **Run the three gates** against the live URL (commands above, with `--base https://uat.ucc.edu.gh`).
   Expect `11/11`, `78/78` (fresh seed), `14/14`.
6. **Enable email when ready**: set `SMTP_HOST/PORT/USER/PASSWORD/FROM`, then
   `POST /api/email/test {to}` (admin) to confirm; `POST /api/email/flush` to send anything queued.

Full detail (Nginx variant, security notes, parallel-run cut-over): **`php/DEPLOY_PHP.md`**.

**Change the default `admin` password before any real pilot use** (Administration → Users).
