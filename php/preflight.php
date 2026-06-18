<?php
/**
 * UCC FMS — deployment pre-flight check.
 *
 * Run this ONCE on the UCC server after uploading, to confirm the environment is
 * ready before going live. It is self-contained (does not boot the app) and makes
 * no changes — it only inspects PHP, extensions, the database path/permissions and
 * the SMTP configuration.
 *
 *   CLI:      php php/preflight.php
 *   Browser:  https://your-domain/php/preflight.php   (delete or block after use)
 *
 * Exit code 0 = all critical checks pass; 1 = at least one critical FAIL.
 *
 * SECURITY: this file reveals environment details. Remove it (or deny it in
 * .htaccess) once the deployment is verified.
 */

$cli = (php_sapi_name() === 'cli');
if (!$cli) { header('Content-Type: text/plain; charset=utf-8'); }

$rows = [];      // [status, label, detail]
$critical_fail = false;
function check(string $label, bool $ok, string $detail, bool $critical = true): void {
    global $rows, $critical_fail;
    $rows[] = [$ok ? 'PASS' : ($critical ? 'FAIL' : 'WARN'), $label, $detail];
    if (!$ok && $critical) $critical_fail = true;
}

// ── PHP version ───────────────────────────────────────────────────────────────
check('PHP version >= 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), 'running ' . PHP_VERSION);

// ── Required extensions ───────────────────────────────────────────────────────
foreach (['pdo_sqlite', 'json', 'mbstring', 'openssl'] as $ext) {
    check("Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'MISSING — install/enable it');
}
check('Extension: zip (XLSX + audit-pack)', extension_loaded('zip'), extension_loaded('zip') ? 'loaded' : 'missing — exports/audit-pack ZIP disabled', false);

// ── Database path + permissions ───────────────────────────────────────────────
$dbpath = getenv('SBS_DB');
$source = 'SBS_DB env';
if (!$dbpath) {
    $dir = getenv('RENDER_DATA_DIR') ?: (is_dir('/var/data') ? '/var/data' : dirname(__DIR__));
    $ucc = rtrim($dir, '/') . '/ucc_fms.db';
    $dbpath = (file_exists($ucc) || !file_exists(rtrim($dir, '/') . '/sbs_fms.db')) ? $ucc : rtrim($dir, '/') . '/sbs_fms.db';
    $source = 'default (' . $dir . ')';
}
$dbdir = dirname($dbpath);
check('Database directory exists', is_dir($dbdir), $dbdir . ' [' . $source . ']');
check('Database directory writable (WAL needs it)', is_writable($dbdir), is_writable($dbdir) ? 'writable' : 'NOT writable — chmod 750 the dir, owned by the PHP/Apache user');
if (file_exists($dbpath)) {
    check('Database file present', true, $dbpath . ' (' . round(filesize($dbpath) / 1048576, 2) . ' MB)');
    check('Database file writable', is_writable($dbpath), is_writable($dbpath) ? 'writable' : 'NOT writable — chmod 660 the .db, owned by the PHP/Apache user');
} else {
    check('Database file present', false, $dbpath . ' not found — point SBS_DB at the live DB, or it will be created empty', false);
}

// Live open/WAL test (read-only intent; creates the file only if the dir is writable).
if (extension_loaded('pdo_sqlite') && is_writable($dbdir)) {
    try {
        $pdo = new PDO('sqlite:' . $dbpath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $mode = $pdo->query('PRAGMA journal_mode=WAL')->fetchColumn();
        check('SQLite open + WAL mode', strtolower((string)$mode) === 'wal', 'journal_mode=' . $mode);
        $tbl = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
        check('Schema present (tables found)', $tbl > 0, $tbl . ' table(s)' . ($tbl === 0 ? ' — brand-new DB' : ''), false);
    } catch (Throwable $e) {
        check('SQLite open + WAL mode', false, 'open failed: ' . $e->getMessage());
    }
}

// ── App layout ────────────────────────────────────────────────────────────────
$root = dirname(__DIR__);
check('SPA present (index.html one level up)', is_file($root . '/index.html'), $root . '/index.html');
check('Front controller present', is_file(__DIR__ . '/index.php'), __DIR__ . '/index.php');
check('.htaccess present (Apache routing)', is_file(__DIR__ . '/.htaccess'), is_file(__DIR__ . '/.htaccess') ? 'present' : 'missing (Nginx deployments use a location rule instead)', false);

// ── Error display hardening ───────────────────────────────────────────────────
check('display_errors off (no leakage into JSON)', !ini_get('display_errors'), 'display_errors=' . (ini_get('display_errors') ?: '0'), false);

// ── SMTP (optional but needed for email/dunning/remittance) ───────────────────
$smtp = trim((string)getenv('SMTP_HOST'));
check('SMTP configured (email/dunning/remittance)', $smtp !== '',
    $smtp !== '' ? ('SMTP_HOST=' . $smtp . ' port=' . (getenv('SMTP_PORT') ?: '587'))
                 : 'not set — email queues gracefully until SMTP_HOST/PORT/USER/PASSWORD/FROM are set; verify later with POST /api/email/test',
    false);

// ── Output ────────────────────────────────────────────────────────────────────
$pass = count(array_filter($rows, fn($r) => $r[0] === 'PASS'));
$warn = count(array_filter($rows, fn($r) => $r[0] === 'WARN'));
$fail = count(array_filter($rows, fn($r) => $r[0] === 'FAIL'));
echo "UCC FMS — deployment pre-flight\n";
echo str_repeat('=', 64) . "\n";
foreach ($rows as [$st, $label, $detail]) {
    printf("  [%-4s] %-44s %s\n", $st, $label, $detail);
}
echo str_repeat('=', 64) . "\n";
printf("  %d passed, %d warning(s), %d failure(s)\n", $pass, $warn, $fail);
echo $critical_fail
    ? "  RESULT: NOT READY — resolve the FAIL item(s) above.\n"
    : "  RESULT: READY — environment checks pass. Now run the acceptance gate:\n    python3 smoke_test.py      --base https://your-domain --user admin --pass UCC@2024 --period " . date('Y-m') . "\n    python3 regression_fixes.py --base https://your-domain --user admin --pass UCC@2024 --period " . date('Y-m') . "\n";
if ($cli) exit($critical_fail ? 1 : 0);
