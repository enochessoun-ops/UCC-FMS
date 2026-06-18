# Deploying on UCC infrastructure

## Option A — cPanel "Setup Python App" (Passenger) — fastest
1. cPanel → **Setup Python App** → Create: Python 3.10+, application root = the uploaded app folder,
   entry point `passenger_wsgi.py` (included).
2. Add environment variable `RENDER_DATA_DIR=/home/<account>/erp_data` (any writable folder).
3. `pip install -r requirements.txt` from the app's virtualenv panel.
4. Open the app URL → log in → Settings: rotate passwords, set Go-Live mode.
   Data, backups and logs live under `RENDER_DATA_DIR`. Nightly backup = built-in
   (set `CRON_TOKEN` + a cPanel cron hitting `/api/cron/backup?token=...`).

## Option B — small VM (Ubuntu) with systemd + Apache
1. `sudo useradd -r erp && sudo mkdir -p /opt/<app>-erp /var/lib/<app>-erp && sudo chown erp /var/lib/<app>-erp`
2. Copy the app folder to `/opt/<app>-erp`; `pip3 install -r requirements.txt`.
3. `sudo cp erp.service /etc/systemd/system/<app>-erp.service && sudo systemctl enable --now <app>-erp`
4. Apache reverse proxy (a2enmod proxy proxy_http):
   `ProxyPass / http://127.0.0.1:8000/`  ·  `ProxyPassReverse / http://127.0.0.1:8000/`
5. HTTPS via the University certificate or certbot.

## Operations (either option)
- **Backup**: nightly cron on `/api/cron/backup` (CRON_TOKEN) + weekly "Download Backup" off the server.
- **Restore**: Backup & Restore screen → upload a `.db` snapshot (takes effect immediately).
- **Updates**: replace the app folder with the released zip, restart the app. The database
  migrates itself on start; no SQL scripts are ever run by hand.
- **Health**: `GET /healthz` returns `{"db":"ok"}` — point any monitor at it.
