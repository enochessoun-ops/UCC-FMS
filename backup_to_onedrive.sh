#!/usr/bin/env bash
# ============================================================================
#  UCC ERP  ->  OneDrive  off-site backup puller   (macOS / Linux)
# ============================================================================
#  Pulls a fresh database backup from each live ERP app using its CRON_TOKEN
#  (no admin login needed) and saves it into your OneDrive-synced folder, where
#  the OneDrive app copies it to the cloud automatically.
#
#  SETUP (one time)
#  ----------------
#  1. Edit the CONFIG block below: $ONEDRIVE_FOLDER and each app's URL + TOKEN.
#  2. Make it executable:   chmod +x backup_to_onedrive.sh
#  3. Test it:              ./backup_to_onedrive.sh
#  4. Schedule it daily with cron or launchd (see the bottom of this file).
#
#  Keeps the last $KEEP_PER_APP backups per app; logs to backup_log.txt
#  next to this script. Treat this file like a password (it holds the tokens).
# ============================================================================

# ============================== CONFIG ======================================
# Personal OneDrive on modern macOS usually lives under ~/Library/CloudStorage.
# Check yours with:  ls ~/Library/CloudStorage   (e.g. OneDrive-Personal)
ONEDRIVE_FOLDER="$HOME/Library/CloudStorage/OneDrive-Personal/ERP-Backups"
KEEP_PER_APP=30

# One line per app:  "Name|https://url|TOKEN"
APPS=(
  "UCC-FMS|https://ucc-fms.onrender.com|0W0t59wp1crys7G9EZguQ7sB7BwXUFmqH6noypGINPI"
  "AOI-FMS|https://aoi-fms.onrender.com|5YuGzVkjkwyyr2q28adYsEBXOGxa9sNe4cq3EEB0_M0"
)
# ============================================================================

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/backup_log.txt"

log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" | tee -a "$LOG_FILE"; }

mkdir -p "$ONEDRIVE_FOLDER"
log "===== Backup run started ====="
failures=0

for entry in "${APPS[@]}"; do
  IFS='|' read -r name url token <<< "$entry"
  url="${url%/}/api/cron/backup/download"

  if [[ -z "$token" || "$token" == PASTE_* ]]; then
    log "[$name] SKIPPED - token not configured."; continue
  fi

  stamp="$(date '+%Y%m%d-%H%M%S')"
  out="$ONEDRIVE_FOLDER/${name}-backup-${stamp}.db"
  tmp="${out}.part"

  log "[$name] downloading from $url"
  http_code="$(curl -fsS -m 180 -o "$tmp" -w '%{http_code}' \
                 -H "X-Cron-Token: $token" "$url" 2>>"$LOG_FILE")" || {
    failures=$((failures+1)); log "[$name] FAILED - curl error (HTTP ${http_code:-?})"
    rm -f "$tmp"; continue; }

  # Validate: non-trivial size + SQLite magic header
  size=$(wc -c < "$tmp" | tr -d ' ')
  magic=$(head -c 15 "$tmp" 2>/dev/null)
  if [[ "${size:-0}" -lt 1024 || "$magic" != "SQLite format 3" ]]; then
    failures=$((failures+1)); log "[$name] FAILED - validation (size=${size} header='${magic}')"
    rm -f "$tmp"; continue
  fi

  mv -f "$tmp" "$out"
  mb=$(awk "BEGIN{printf \"%.2f\", ${size}/1048576}")
  log "[$name] OK -> $out (${mb} MB)"

  # Retention: keep newest $KEEP_PER_APP, delete the rest
  ls -1t "$ONEDRIVE_FOLDER/${name}-backup-"*.db 2>/dev/null | tail -n +$((KEEP_PER_APP+1)) | while read -r oldf; do
    rm -f "$oldf"; log "[$name] pruned old backup $(basename "$oldf")"
  done
done

log "===== Backup run finished (failures: $failures) ====="
exit "$failures"

# ============================================================================
#  SCHEDULE IT
#  ---------------------------------------------------------------------------
#  Option A - cron (simplest). Run:  crontab -e   and add (daily 18:00):
#     0 18 * * * /full/path/to/backup_to_onedrive.sh >/dev/null 2>&1
#
#  Option B - launchd (runs even after missed times). Create
#     ~/Library/LaunchAgents/com.ucc.erp.backup.plist  with:
#     <?xml version="1.0" encoding="UTF-8"?>
#     <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
#       "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
#     <plist version="1.0"><dict>
#       <key>Label</key><string>com.ucc.erp.backup</string>
#       <key>ProgramArguments</key>
#         <array><string>/full/path/to/backup_to_onedrive.sh</string></array>
#       <key>StartCalendarInterval</key><dict>
#         <key>Hour</key><integer>18</integer><key>Minute</key><integer>0</integer></dict>
#       <key>RunAtLoad</key><false/>
#     </dict></plist>
#  then:  launchctl load ~/Library/LaunchAgents/com.ucc.erp.backup.plist
# ============================================================================
