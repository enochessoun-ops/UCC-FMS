<#
============================================================================
 UCC ERP  ->  OneDrive  off-site backup puller   (Windows PowerShell)
============================================================================
 Pulls a fresh database backup from each live ERP app using its CRON_TOKEN
 (no admin login needed) and saves it into your OneDrive-synced folder, where
 the OneDrive desktop app copies it to the cloud automatically.

 SETUP (one time)
 ----------------
 1. Edit the CONFIG block below:
      - $OneDriveFolder : where to save (must be inside your OneDrive folder)
      - For each app    : its public Url and its CRON_TOKEN (same token you
                          set in the Render dashboard).
 2. Test it:   right-click the file -> "Run with PowerShell"
               (or:  powershell -ExecutionPolicy Bypass -File backup_to_onedrive.ps1)
 3. Schedule it (daily) with Task Scheduler -- see the instructions your
    assistant provided, or the bottom of this file.

 The script keeps the last $KeepPerApp backups per app and deletes older ones.
 All activity is written to backup_log.txt next to this script.
============================================================================
#>

# ============================== CONFIG ====================================
$OneDriveFolder = "$env:USERPROFILE\OneDrive\ERP-Backups"   # destination (inside OneDrive)
$KeepPerApp     = 30                                          # how many backups to keep per app

$Apps = @(
    @{ Name = "UCC-FMS";  Url = "https://ucc-fms.onrender.com"; Token = "0W0t59wp1crys7G9EZguQ7sB7BwXUFmqH6noypGINPI" },
    @{ Name = "AOI-FMS";  Url = "https://aoi-fms.onrender.com"; Token = "5YuGzVkjkwyyr2q28adYsEBXOGxa9sNe4cq3EEB0_M0" }
)
# ==========================================================================

$ErrorActionPreference = "Stop"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogFile   = Join-Path $ScriptDir "backup_log.txt"

function Log($msg) {
    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
    Write-Host $line
    Add-Content -Path $LogFile -Value $line
}

# TLS 1.2 for older PowerShell
try { [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12 } catch {}

# Ensure destination exists
if (-not (Test-Path $OneDriveFolder)) {
    New-Item -ItemType Directory -Path $OneDriveFolder -Force | Out-Null
}

Log "===== Backup run started ====="
$failures = 0

foreach ($app in $Apps) {
    $name  = $app.Name
    $url   = ($app.Url.TrimEnd('/')) + "/api/cron/backup/download"
    $token = $app.Token

    if ($token -match "PASTE_|^$") { Log "[$name] SKIPPED - token not configured."; continue }

    $stamp   = Get-Date -Format "yyyyMMdd-HHmmss"
    $outFile = Join-Path $OneDriveFolder ("{0}-backup-{1}.db" -f $name, $stamp)
    $tmpFile = "$outFile.part"

    try {
        Log "[$name] downloading from $url"
        Invoke-WebRequest -Uri $url -Headers @{ "X-Cron-Token" = $token } `
            -OutFile $tmpFile -TimeoutSec 180 -UseBasicParsing

        # Validate: non-trivial size + SQLite magic header
        $size = (Get-Item $tmpFile).Length
        $fs   = [System.IO.File]::OpenRead($tmpFile)
        $buf  = New-Object byte[] 16
        [void]$fs.Read($buf, 0, 16); $fs.Close()
        $magic = [System.Text.Encoding]::ASCII.GetString($buf, 0, 15)

        if ($size -lt 1024 -or $magic -ne "SQLite format 3") {
            throw "downloaded file failed validation (size=$size bytes, header='$magic')"
        }

        Move-Item -Path $tmpFile -Destination $outFile -Force
        Log "[$name] OK -> $outFile  ($([math]::Round($size/1MB,2)) MB)"

        # Retention: keep newest $KeepPerApp, delete the rest
        $old = Get-ChildItem -Path $OneDriveFolder -Filter "$name-backup-*.db" |
               Sort-Object LastWriteTime -Descending | Select-Object -Skip $KeepPerApp
        foreach ($f in $old) { Remove-Item $f.FullName -Force; Log "[$name] pruned old backup $($f.Name)" }
    }
    catch {
        $failures++
        Log "[$name] FAILED - $($_.Exception.Message)"
        if (Test-Path $tmpFile) { Remove-Item $tmpFile -Force -ErrorAction SilentlyContinue }
    }
}

Log "===== Backup run finished (failures: $failures) ====="
exit $failures

<#
============================================================================
 SCHEDULE IT (daily at 6pm) via Task Scheduler - run once in PowerShell:
----------------------------------------------------------------------------
 $action  = New-ScheduledTaskAction -Execute "powershell.exe" `
            -Argument '-ExecutionPolicy Bypass -WindowStyle Hidden -File "C:\Path\To\backup_to_onedrive.ps1"'
 $trigger = New-ScheduledTaskTrigger -Daily -At 6:00PM
 Register-ScheduledTask -TaskName "ERP OneDrive Backup" -Action $action -Trigger $trigger `
            -Description "Pulls UCC-FMS & AOI-FMS backups into OneDrive"
----------------------------------------------------------------------------
 (Replace C:\Path\To\ with the real folder. To run whether or not you are
  logged in, add it in the Task Scheduler GUI with "Run whether user is
  logged on or not".)
============================================================================
#>
