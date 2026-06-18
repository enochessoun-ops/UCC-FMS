<?php
/**
 * UCC-FMS / UCC institutional ERP — PHP 8 port, PHASE 1 (Foundation).
 *
 * Per PHP_PORT_PLAN.md Phase 1: single front controller, PDO data layer, session
 * model (same X-Session-ID token the SPA already sends), JSON response envelope,
 * auth (login/logout/me) and a role guard. Runs against the SAME SQLite database
 * the Python reference uses, so the two can run in parallel (Phase 5).
 *
 * Acceptance for this phase: login → me → logout work; a write is blocked for the
 * read-only Auditor role; the institutional /api/org-units read returns the tree.
 *
 * Run:  SBS_DB=/path/to/sbs_fms.db php -S 127.0.0.1:5077 php/index.php
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Data layer (PDO → the shared SQLite DB) ─────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = getenv('SBS_DB');
    if (!$path) {
        $dir = getenv('RENDER_DATA_DIR') ?: (is_dir('/var/data') ? '/var/data' : dirname(__DIR__));
        // UCC FMS's Python reference names its database ucc_fms.db; fall back to the
        // legacy sbs_fms.db only if the UCC file isn't present (shared-base heritage).
        $ucc = rtrim($dir, '/') . '/ucc_fms.db';
        $path = (file_exists($ucc) || !file_exists(rtrim($dir, '/') . '/sbs_fms.db')) ? $ucc : rtrim($dir, '/') . '/sbs_fms.db';
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    // Port-owned session store (keeps Python's in-memory sessions untouched).
    $pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
        sid TEXT PRIMARY KEY, user_id TEXT, username TEXT, full_name TEXT, role TEXT,
        created_at TEXT DEFAULT (datetime('now')), last_active TEXT DEFAULT (datetime('now')))");
    return $pdo;
}

// ── Response envelope ───────────────────────────────────────────────────────
function send($payload, int $code = 200): void { http_response_code($code); echo json_encode($payload); exit; }
function ok(array $extra = []): void { send(array_merge(['ok' => true], $extra)); }
function err(string $msg, int $code = 200): void { send(['ok' => false, 'error' => $msg], $code); }

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $_POST ?: [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// ── Hardened money parse (mirror server.py _f53_f) ──────────────────────────
// Reject NaN/Infinity: a non-finite value would slip past every abs(dr-cr) > tol
// balance gate (e.g. `NAN > 0.01` is false) and corrupt the ledger. null/'' → $d.
function money($v, float $d = 0.0): float {
    if ($v === null || $v === '') return $d;
    if (!is_numeric($v)) return $d;
    $r = (float)$v;
    return is_finite($r) ? $r : $d;
}

// ── Sessions (same X-Session-ID token model as the SPA) ─────────────────────
function sid_from_request(): ?string {
    $h = $_SERVER['HTTP_X_SESSION_ID'] ?? '';
    if ($h !== '') return trim($h);
    if (!empty($_COOKIE['sid'])) return $_COOKIE['sid'];
    return null;
}
function current_user(): ?array {
    $sid = sid_from_request();
    if (!$sid) return null;
    $st = db()->prepare('SELECT * FROM php_sessions WHERE sid=?');
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s) return null;
    db()->prepare("UPDATE php_sessions SET last_active=datetime('now') WHERE sid=?")->execute([$sid]);
    return $s;
}
function require_auth(): array { $u = current_user(); if (!$u) err('Not authenticated', 401); return $u; }
function require_role(array $roles): array {
    $u = require_auth();
    if (!in_array($u['role'], $roles, true)) err('Insufficient privileges for ' . $u['role'], 403);
    return $u;
}
function uuid4(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ── Auth handlers (mirror the Python contract exactly) ──────────────────────
// ── Phase 4: login throttle, TOTP MFA, dual-control settings ────────────────
function ensure_security_tables(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS php_login_attempts(username TEXT PRIMARY KEY, fails INTEGER DEFAULT 0, locked_until TEXT)");
    db()->exec("CREATE TABLE IF NOT EXISTS php_mfa(username TEXT PRIMARY KEY, enabled INTEGER DEFAULT 0, secret TEXT)");
    db()->exec("CREATE TABLE IF NOT EXISTS php_settings(skey TEXT PRIMARY KEY, svalue TEXT)");
}
function setting_get(string $k, $def = null) { ensure_security_tables(); $r = db()->prepare('SELECT svalue FROM php_settings WHERE skey=?'); $r->execute([$k]); $v = $r->fetchColumn(); return $v === false ? $def : $v; }
function setting_set(string $k, $v): void { ensure_security_tables(); db()->prepare('INSERT INTO php_settings(skey,svalue) VALUES(?,?) ON CONFLICT(skey) DO UPDATE SET svalue=excluded.svalue')->execute([$k, (string)$v]); }
function throttle_check(string $username): void {
    ensure_security_tables();
    $r = db()->prepare('SELECT locked_until FROM php_login_attempts WHERE username=?'); $r->execute([$username]); $lu = $r->fetchColumn();
    if ($lu && $lu > date('Y-m-d H:i:s')) err('Account temporarily locked after repeated failed logins. Try again later.', 429);
}
function throttle_fail(string $username): void {
    ensure_security_tables();
    $r = db()->prepare('SELECT fails FROM php_login_attempts WHERE username=?'); $r->execute([$username]); $f = (int)($r->fetchColumn() ?: 0) + 1;
    $lock = $f >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
    db()->prepare('INSERT INTO php_login_attempts(username,fails,locked_until) VALUES(?,?,?) ON CONFLICT(username) DO UPDATE SET fails=excluded.fails, locked_until=excluded.locked_until')->execute([$username, $f, $lock]);
}
function throttle_reset(string $username): void { ensure_security_tables(); db()->prepare('DELETE FROM php_login_attempts WHERE username=?')->execute([$username]); }
function b32_decode(string $b32): string {
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $b32 = strtoupper(str_replace('=', '', $b32)); $bits = ''; $out = '';
    for ($i = 0; $i < strlen($b32); $i++) { $p = strpos($map, $b32[$i]); if ($p === false) continue; $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
    return $out;
}
function totp_code(string $secret, int $offset = 0): string {
    $key = b32_decode($secret); $t = (int)floor(time() / 30) + $offset;
    $bin = pack('N', 0) . pack('N', $t);
    $h = hash_hmac('sha1', $bin, $key, true); $o = ord($h[19]) & 0xf;
    $code = (((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff)) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}
function mfa_secret(string $username): ?string {
    ensure_security_tables();
    $r = db()->prepare('SELECT secret FROM php_mfa WHERE username=? AND enabled=1'); $r->execute([$username]); $s = $r->fetchColumn();
    return $s ?: null;
}
function start_session(array $user): string {
    $sid = uuid4();
    db()->prepare('INSERT INTO php_sessions(sid,user_id,username,full_name,role) VALUES(?,?,?,?,?)')->execute([$sid, $user['id'], $user['username'], $user['full_name'], $user['role']]);
    @setcookie('sid', $sid, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    return $sid;
}
function api_login(): void {
    $d = body();
    $username = trim((string)($d['username'] ?? ''));
    $password = trim((string)($d['password'] ?? ''));
    throttle_check($username);
    $st = db()->prepare('SELECT * FROM users WHERE username=? AND active=1'); $st->execute([$username]); $user = $st->fetch();
    if (!$user || !hash_equals((string)$user['password_hash'], hash('sha256', $password))) { throttle_fail($username); err('Invalid credentials'); }
    throttle_reset($username);
    if (mfa_secret($username)) { ok(['mfa_required' => true, 'username' => $username]); } // step-up: verify TOTP next
    $sid = start_session($user);
    ok(['sid' => $sid, 'user' => ['username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'],
        'home_unit_id' => $user['home_unit_id'] ?? null, 'scope' => $user['scope'] ?? null]]);
}
function api_verify_mfa(): void {
    $d = body(); $username = trim((string)($d['username'] ?? '')); $code = trim((string)($d['code'] ?? ''));
    $secret = mfa_secret($username); if (!$secret) err('MFA is not enabled for this user');
    if (!in_array($code, [totp_code($secret, 0), totp_code($secret, -1), totp_code($secret, 1)], true)) err('Invalid MFA code');
    $st = db()->prepare('SELECT * FROM users WHERE username=? AND active=1'); $st->execute([$username]); $user = $st->fetch();
    if (!$user) err('User not found');
    $sid = start_session($user);
    ok(['sid' => $sid, 'user' => ['username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'],
        'home_unit_id' => $user['home_unit_id'] ?? null, 'scope' => $user['scope'] ?? null]]);
}
function api_mfa_enroll(): void {
    $u = require_auth(); $d = body(); ensure_security_tables();
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $secret = ''; for ($i = 0; $i < 16; $i++) $secret .= $map[random_int(0, 31)];
    db()->prepare('INSERT INTO php_mfa(username,enabled,secret) VALUES(?,1,?) ON CONFLICT(username) DO UPDATE SET enabled=1, secret=excluded.secret')->execute([$u['username'], $secret]);
    ok(['secret' => $secret, 'otpauth' => 'otpauth://totp/UCC-FMS:' . $u['username'] . '?secret=' . $secret . '&issuer=UCC-FMS']);
}
function api_dual_control_get(): void { require_auth(); ok(['threshold' => (float)setting_get('dual_control_threshold_ghs', 0)]); }
function api_dual_control_set(): void { require_role(['Admin']); $d = body(); setting_set('dual_control_threshold_ghs', (float)($d['threshold'] ?? 0)); ok(['threshold' => (float)($d['threshold'] ?? 0)]); }
function api_logout(): void {
    $sid = sid_from_request();
    if ($sid) db()->prepare('DELETE FROM php_sessions WHERE sid=?')->execute([$sid]);
    ok();
}
function api_me(): void {
    $u = require_auth();
    $hu = null; $scp = null;
    try { $r = db()->prepare('SELECT home_unit_id, scope FROM users WHERE username=?'); $r->execute([$u['username']]); $row = $r->fetch();
        if ($row) { $hu = $row['home_unit_id'] ?? null; $scp = $row['scope'] ?? null; } } catch (Throwable $e) {}
    ok(['user' => ['username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'],
        'home_unit_id' => $hu, 'scope' => $scp]]);
}

// ── Institutional data endpoint (parity with the Python /api/org-units) ──────
function api_org_units(): void {
    require_auth();
    $rows = db()->query(
        "SELECT id, code, name, unit_type, parent_code, head_name, head_title, head_email, status
         FROM org_units ORDER BY COALESCE(parent_code,''), code")->fetchAll();
    ok(['units' => $rows, 'count' => count($rows)]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 2 — Accounting core: chart of accounts, balanced-journal gate, period
// guard, sequential codes, journal vouchers, general ledger, trial balance.
// Mirrors server.py: _validate_jv_lines, _seq_code, _next_jv_number, the direct
// admin JV post, and api_get_ledger_accounts_summary — so the same JV→GL→TB
// chain ties out and smoke checks pass against this backend.
// ════════════════════════════════════════════════════════════════════════════
function home_unit_of(array $user): ?string {
    try {
        $st = db()->prepare('SELECT home_unit_id FROM users WHERE username=?');
        $st->execute([$user['username']]);
        $r = $st->fetch();
        return ($r && !empty($r['home_unit_id'])) ? $r['home_unit_id'] : null;
    } catch (Throwable $e) { return null; }
}

// The institutional default org unit (University / Central level) used when a
// transaction specifies no unit and the poster has no home unit. Configurable via
// the 'default_unit_code' setting; otherwise the root (parent-less) node, else 'UCC'.
// Cached in a static to avoid repeat lookups. Returns null only if org_units is
// empty (pure single-entity install), preserving legacy NULL behaviour.
// (Mirror server.py _ucc_default_unit_id.)
function default_unit_id(): ?string {
    static $cached = '__unset__';
    if ($cached !== '__unset__') return $cached;
    $val = null;
    try {
        $pdo = db();
        $code = null;
        try {
            $st = $pdo->query("SELECT value FROM settings WHERE key='default_unit_code'");
            $r = $st ? $st->fetch() : false;
            $code = ($r && !empty($r['value'])) ? $r['value'] : null;
        } catch (Throwable $e) { $code = null; }
        if ($code) {
            $st = $pdo->prepare('SELECT id FROM org_units WHERE code=?');
            $st->execute([$code]); $r = $st->fetch();
            if ($r) $val = $r['id'];
        }
        if (!$val) {
            $st = $pdo->query("SELECT id FROM org_units WHERE parent_code IS NULL OR parent_code='' ORDER BY rowid LIMIT 1");
            $r = $st ? $st->fetch() : false;
            if ($r) $val = $r['id'];
        }
        if (!$val) {
            $st = $pdo->query("SELECT id FROM org_units WHERE code='UCC' LIMIT 1");
            $r = $st ? $st->fetch() : false;
            $val = $r ? $r['id'] : null;
        }
    } catch (Throwable $e) { $val = null; }
    $cached = $val;
    return $val;
}

// Resolve the org unit to stamp on a new transaction (mirror server.py
// _ucc_resolve_write_unit). Precedence: an explicit unit_id in the request body
// (a university/Admin user posting on behalf of a unit) → the creating user's home
// unit → for an Admin role OR a university-scope user the institutional default
// (Central node) → else NULL. Only an ordinary user with no explicit unit and no
// home unit resolves to NULL, so the write path can hard-require a unit; Admin and
// system/university posters always resolve to Central and are never blocked.
function resolve_write_unit(array $user, ?array $d = null): ?string {
    $explicit = $d['unit_id'] ?? null;
    if ($explicit) return $explicit;
    $home = null; $scope = null;
    try {
        $st = db()->prepare('SELECT home_unit_id, scope FROM users WHERE username=?');
        $st->execute([$user['username'] ?? '']);
        $r = $st->fetch();
        if ($r) {
            $home  = !empty($r['home_unit_id']) ? $r['home_unit_id'] : null;
            $scope = $r['scope'] ?? null;
        }
    } catch (Throwable $e) { $home = null; $scope = null; }
    if ($home) return $home;
    if (($user['role'] ?? '') === 'Admin' || $scope === 'university') return default_unit_id();
    return null;
}

// Hard-require an org unit for a write (mirror server.py _ucc_require_unit_guard).
// When resolve_write_unit yields NULL — i.e. ONLY an ordinary user with no explicit
// unit and no home unit — it emits a clear reject via err() (which exits), so the
// caller never reaches its insert. Admin / university posters resolve to the Central
// node and are never blocked, keeping the admin-run PHP gates green.
function require_write_unit(array $user, ?array $d, string $doc): void {
    if (resolve_write_unit($user, $d)) return;
    err("A unit / cost centre must be selected for this $doc.");
}

function validate_jv_lines(array $lines): array {
    if (count($lines) < 2) return [false, 'A journal entry must have at least 2 lines.'];
    $tdr = 0.0; $tcr = 0.0;
    foreach ($lines as $i => $l) {
        // Use the hardened money() parse so NaN/Infinity (and non-numeric junk) cannot
        // slip past the balance gate below (mirror server.py _f53_f).
        if (!is_finite((float)($l['debit_amount'] ?? 0)) || !is_finite((float)($l['credit_amount'] ?? 0)))
            return [false, 'Line ' . ($i + 1) . ': amount is not finite.'];
        $dr = money($l['debit_amount'] ?? 0); $cr = money($l['credit_amount'] ?? 0);
        if ($dr < 0 || $cr < 0) return [false, 'Line ' . ($i + 1) . ': amounts cannot be negative.'];
        if ($dr > 0 && $cr > 0) return [false, 'Line ' . ($i + 1) . ': cannot have both a debit and credit amount.'];
        if ($dr == 0 && $cr == 0) return [false, 'Line ' . ($i + 1) . ': must have either a debit or credit amount.'];
        if (empty($l['coa_id'])) return [false, 'Line ' . ($i + 1) . ': no account selected.'];
        $tdr += $dr; $tcr += $cr;
    }
    if (abs($tdr - $tcr) > 0.005) return [false, sprintf('Journal is NOT balanced: Debits %.2f != Credits %.2f', $tdr, $tcr)];
    return [true, 'OK'];
}

function seq_code(string $table, string $column, string $prefix, int $width): string {
    $pdo = db(); $n = 0;
    $st = $pdo->prepare("SELECT $column FROM $table WHERE $column LIKE ?");
    $st->execute([$prefix . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $s = substr((string)$v, strlen($prefix));
        if ($s !== '' && ctype_digit($s) && (int)$s > $n) $n = (int)$s;
    }
    $n++; $code = $prefix . str_pad((string)$n, $width, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare("SELECT 1 FROM $table WHERE $column=? LIMIT 1");
    while (true) { $chk->execute([$code]); if (!$chk->fetch()) break; $n++; $code = $prefix . str_pad((string)$n, $width, '0', STR_PAD_LEFT); }
    return $code;
}

function period_guard(string $period): array {
    // Lenient like the Python engine: reject only a Closed/Locked period; auto-open
    // an unseen period so first-use postings are not blocked.
    $st = db()->prepare('SELECT status FROM accounting_periods WHERE period=?');
    $st->execute([$period]); $r = $st->fetch();
    if ($r && in_array($r['status'], ['Closed', 'Locked'], true)) return [false, "Period $period is not open."];
    if (!$r) {
        try {
            db()->prepare('INSERT INTO accounting_periods(id,period,period_name,status,opened_by) VALUES(?,?,?,?,?)')
                ->execute([uuid4(), $period, $period, 'Open', 'php-port']);
        } catch (Throwable $e) {}
    }
    return [true, 'OK'];
}

function api_coa(): void {
    require_auth();
    send(db()->query('SELECT * FROM chart_of_accounts ORDER BY code')->fetchAll()); // bare list (parity)
}

function api_golive_mode(): void { $d = body(); ok(['mode' => $d['mode'] ?? 'UAT']); } // Phase 2 does not gate UAT

function api_jvs_create(): void {
    $u = require_auth();
    $d = body();
    $lines = $d['lines'] ?? [];
    if (!is_array($lines)) $lines = [];
    [$vok, $vmsg] = validate_jv_lines($lines);
    if (!$vok) err($vmsg);
    $period = (string)($d['period'] ?? substr((string)($d['jv_date'] ?? ''), 0, 7));
    [$pok, $pmsg] = period_guard($period);
    if (!$pok) err($pmsg);
    $jv_type = (string)($d['jv_type'] ?? 'JV');
    $jid = uuid4();
    $jvnum = seq_code('journal_vouchers', 'jv_number', $jv_type . '-' . substr($period, 0, 4) . '-', 4);
    $tdr = 0.0; $tcr = 0.0;
    foreach ($lines as $l) { $tdr += money($l['debit_amount'] ?? 0); $tcr += money($l['credit_amount'] ?? 0); }
    require_write_unit($u, $d, 'journal voucher');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO journal_vouchers
        (id,jv_number,jv_type,jv_date,period,description,narration,reference,project_id,
         currency,fx_rate,total_debit,total_credit,status,prepared_by,source_module,notes,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$jid, $jvnum, $jv_type, (string)($d['jv_date'] ?? ''), $period,
            (string)($d['description'] ?? 'Journal Voucher'), (string)($d['narration'] ?? ''),
            (string)($d['reference'] ?? ''), $d['project_id'] ?? null, 'GHS', 1.0, $tdr, $tcr,
            'Draft', $u['username'], 'manual', (string)($d['notes'] ?? ''), $unit]);
    $ins = db()->prepare("INSERT INTO jv_lines
        (id,jv_id,line_number,coa_id,description,debit_amount,credit_amount,project_id)
        VALUES(?,?,?,?,?,?,?,?)");
    $ln = 0;
    foreach ($lines as $l) {
        $ln++;
        $ins->execute([uuid4(), $jid, $ln, $l['coa_id'],
            (string)($l['description'] ?? ($d['description'] ?? '')),
            money($l['debit_amount'] ?? 0), money($l['credit_amount'] ?? 0),
            $l['project_id'] ?? ($d['project_id'] ?? null)]);
    }
    ok(['id' => $jid, 'jv_number' => $jvnum]);
}

// GET /api/jvs — journal-voucher list, unit-scoped (mirror Python's institutional
// read-scope; Admin/university unrestricted, scoped users see only their unit/subtree).
function api_jvs_list(): void {
    $u = require_auth();
    [$sw, $sp] = unit_scope_sql($u, 'journal_vouchers', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT journal_vouchers.* FROM journal_vouchers WHERE 1=1$sw ORDER BY created_at DESC, jv_number DESC");
    $st->execute($sp); send(['ok' => true, 'jvs' => $st->fetchAll()]);
}
// GET /api/jvs/detail?id=… — a single JV with its lines, enforcing the same scope so a
// scoped user cannot read another unit's voucher by id.
function api_jv_detail(): void {
    $u = require_auth();
    $jid = (string)($_GET['id'] ?? ($_GET['jv_id'] ?? ''));
    if ($jid === '') err('id is required');
    [$sw, $sp] = unit_scope_sql($u, 'journal_vouchers', null);
    $st = db()->prepare("SELECT journal_vouchers.* FROM journal_vouchers WHERE id=?$sw");
    $st->execute(array_merge([$jid], $sp)); $jv = $st->fetch();
    if (!$jv) err('JV not found', 404);
    $ls = db()->prepare("SELECT l.*, c.code AS coa_code, c.account_name FROM jv_lines l LEFT JOIN chart_of_accounts c ON c.id=l.coa_id WHERE l.jv_id=? ORDER BY l.line_number");
    $ls->execute([$jid]);
    ok(['jv' => $jv, 'lines' => $ls->fetchAll()]);
}
function api_jv_post(): void {
    $u = require_auth();
    $d = body();
    $jid = (string)($d['jv_id'] ?? ($d['id'] ?? ''));
    if ($jid === '') err('jv_id is required');
    $st = db()->prepare('SELECT * FROM journal_vouchers WHERE id=?'); $st->execute([$jid]); $jv = $st->fetch();
    if (!$jv) err('JV not found');
    if (($jv['status'] ?? '') === 'Posted') err('JV is already posted');
    // Dual control: a high-value journal cannot be direct-posted by its own preparer.
    $thr = (float)setting_get('dual_control_threshold_ghs', 0);
    if ($thr > 0) {
        $amt = max((float)($jv['total_debit'] ?? 0), (float)($jv['total_credit'] ?? 0));
        if ($amt >= $thr && (string)($jv['prepared_by'] ?? '') === $u['username'])
            err('Dual control: a journal of GHS ' . number_format($thr, 2) . ' or more must be posted by a different officer than its preparer.');
    }
    $ls = db()->prepare("SELECT l.*, c.code AS cc, c.account_name AS an
                         FROM jv_lines l JOIN chart_of_accounts c ON c.id=l.coa_id
                         WHERE l.jv_id=? ORDER BY l.line_number");
    $ls->execute([$jid]); $lines = $ls->fetchAll();
    if (!$lines) err('JV has no lines');
    // Post-gate: reject negative (or non-finite) line amounts before they reach the GL
    // (mirror server.py api_post_journal_voucher). money() already neutralises non-finite.
    foreach ($lines as $l) {
        if (money($l['debit_amount'] ?? 0) < 0 || money($l['credit_amount'] ?? 0) < 0)
            err('Journal voucher has a negative line amount; cannot post');
    }
    $ins = db()->prepare("INSERT INTO general_ledger
        (id,jv_id,jv_number,jv_line_id,ledger_date,period,coa_id,coa_code,account_name,description,
         debit_amount,credit_amount,project_id,posted_by,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($lines as $l) {
        $ins->execute([uuid4(), $jid, $jv['jv_number'], $l['id'], $jv['jv_date'], $jv['period'],
            $l['coa_id'], $l['cc'], $l['an'], (string)($l['description'] ?? ''),
            money($l['debit_amount'] ?? 0), money($l['credit_amount'] ?? 0),
            $l['project_id'] ?? null, $u['username'], $jv['unit_id'] ?? null]);
    }
    db()->prepare("UPDATE journal_vouchers SET status='Posted', posted_by=?, posted_at=datetime('now') WHERE id=?")
        ->execute([$u['username'], $jid]);
    ok(['status' => 'Posted', 'jv_number' => $jv['jv_number']]);
}

function api_ledger_summary(): void {
    $u = require_auth();
    $period = $_GET['period'] ?? null;
    $cond = ['1=1']; $args = [];
    if ($period) { $cond[] = 'gl.period=?'; $args[] = $period; }
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $q = "SELECT gl.coa_id, gl.coa_code, gl.account_name, c.category, c.account_type,
        COALESCE(SUM(gl.debit_amount),0) as total_debit, COALESCE(SUM(gl.credit_amount),0) as total_credit,
        COUNT(*) as entry_count
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE " . implode(' AND ', $cond) . $sw .
        ' GROUP BY gl.coa_id, gl.coa_code, gl.account_name, c.category, c.account_type ORDER BY gl.coa_code';
    $st = db()->prepare($q); $st->execute(array_merge($args, $sp));
    send($st->fetchAll());
}

// GET /api/trial-balance — AS-AT trial balance straight from the general ledger
// (mirror server.py api_trial_balance_v556). Aggregates every posting up to the
// reporting date so balances carry forward and reconcile with the SFP. Honours the
// viewer's unit scope and an optional node roll-up (unit/unit_code) + project filter.
function api_trial_balance(): void {
    $u = require_auth();
    $period_to = $_GET['period_to'] ?? ($_GET['date_to'] ?? date('Y-m-d'));
    $period_from = $_GET['period_from'] ?? ($_GET['date_from'] ?? date('Y-01-01'));
    $cond = ['gl.ledger_date <= ?']; $args = [substr((string)$period_to, 0, 10)];
    if (!empty($_GET['project_id'])) { $cond[] = 'gl.project_id=?'; $args[] = $_GET['project_id']; }
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $q = "SELECT gl.coa_id, gl.coa_code, MAX(gl.account_name) AS nm,
        COALESCE(MAX(c.account_type),'') AS at,
        COALESCE(SUM(gl.debit_amount),0) AS dr, COALESCE(SUM(gl.credit_amount),0) AS cr
        FROM general_ledger gl LEFT JOIN chart_of_accounts c ON c.id=gl.coa_id
        WHERE " . implode(' AND ', $cond) . $sw .
        ' GROUP BY gl.coa_id, gl.coa_code ORDER BY gl.coa_code';
    $st = db()->prepare($q); $st->execute(array_merge($args, $sp));
    $accounts = []; $total_dr = 0.0; $total_cr = 0.0;
    foreach ($st->fetchAll() as $r) {
        $at = strtolower((string)($r['at'] ?? ''));
        $isCredit = false;
        foreach (['liab', 'equity', 'income', 'revenue', 'fund', 'capital'] as $k) { if (strpos($at, $k) !== false) { $isCredit = true; break; } }
        $dr = (float)($r['dr'] ?? 0); $cr = (float)($r['cr'] ?? 0);
        $balance = $isCredit ? ($cr - $dr) : ($dr - $cr);
        if ($dr || $cr || $balance) {
            $accounts[] = ['code' => $r['coa_code'], 'name' => $r['nm'], 'account_type' => $r['at'] ?? '',
                'debit' => round($dr, 2), 'credit' => round($cr, 2), 'balance' => round($balance, 2)];
            $total_dr += $dr; $total_cr += $cr;
        }
    }
    $total_dr = round($total_dr, 2); $total_cr = round($total_cr, 2);
    ok(['accounts' => $accounts, 'total_debit' => $total_dr, 'total_credit' => $total_cr,
        'balanced' => abs($total_dr - $total_cr) < 0.02,
        'period_from' => substr((string)$period_from, 0, 10), 'period_to' => substr((string)$period_to, 0, 10)]);
}

function api_general_ledger(): void {
    $u = require_auth();
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare('SELECT gl.* FROM general_ledger gl WHERE 1=1' . $sw . ' ORDER BY gl.ledger_date, gl.jv_number');
    $st->execute($sp);
    send($st->fetchAll());
}

function api_accounting_periods(): void {
    require_auth();
    send(db()->query('SELECT * FROM accounting_periods ORDER BY period DESC')->fetchAll());
}
// POST /api/accounting-periods {action: close|open|lock|reopen, period} — period gate.
function api_accounting_period_action(): void {
    require_role(['Admin', 'Finance Officer']); $d = body();
    $action = strtolower((string)($d['action'] ?? '')); $period = (string)($d['period'] ?? '');
    if ($period === '') err('period is required');
    $status = ['close' => 'Closed', 'open' => 'Open', 'lock' => 'Locked', 'reopen' => 'Open'][$action] ?? null;
    if (!$status) err('Unknown action: ' . $action);
    $ex = db()->prepare('SELECT id FROM accounting_periods WHERE period=?'); $ex->execute([$period]);
    if ($ex->fetchColumn()) db()->prepare('UPDATE accounting_periods SET status=? WHERE period=?')->execute([$status, $period]);
    else db()->prepare('INSERT INTO accounting_periods(id,period,period_name,status,opened_by) VALUES(?,?,?,?,?)')->execute([uuid4(), $period, $period, $status, 'php-port']);
    ok(['period' => $period, 'status' => $status]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3a — Payments (PV) + Ghana tax engine + budgets / commitments / vendors.
// Mirrors server.py: the VAT/WHT/WHVAT/UCF math and the PV→GL leg builder, plus
// the account-code aliasing (_get_coa) that maps short codes to the real 8-digit
// UCC accounts. Self-consistent: PV save computes tax, PV post builds a balanced
// journal into the GL, and it ties out in the trial balance.
// ════════════════════════════════════════════════════════════════════════════
const COA_ALIAS = [
    '2030' => ['21100014', '2030'],          // WHT Payable (GRA)
    '2031' => ['21100024', '2031'],          // WHVAT Payable
    '2034' => ['2034', '21100024'],
    '2033' => ['21100027', '2033'],          // UCC Common Fund
    '2035' => ['2035', '21100027'],
    '1012' => ['12300001', '1012'],          // Input VAT receivable
];
function get_coa(array $codes): ?array {
    $pdo = db();
    // 1) exact match on alias-expanded candidates, in priority order
    foreach ($codes as $code) {
        $cands = COA_ALIAS[$code] ?? [$code];
        foreach ($cands as $cand) {
            $st = $pdo->prepare('SELECT id, code, account_name FROM chart_of_accounts WHERE code=? LIMIT 1');
            $st->execute([$cand]);
            if ($r = $st->fetch()) return $r;
        }
    }
    // 2) fall back to a prefix match on the original codes
    foreach ($codes as $code) {
        $st = $pdo->prepare('SELECT id, code, account_name FROM chart_of_accounts WHERE code LIKE ? ORDER BY code LIMIT 1');
        $st->execute([$code . '%']);
        if ($r = $st->fetch()) return $r;
    }
    return null;
}

// Default operating bank for cash postings (PV/RV/inventory/petty-cash/fuel/
// withholding settlement). Mirror the Python reference _V505_DEFAULT_BANK =
// '12703001' (ADB, SBS) so PHP and Python credit the SAME bank account and bank
// balances reconcile across the parallel run. Falls back to the 127x/126x prefix
// resolution if the canonical ADB account is absent from the chart of accounts.
function operating_bank_coa(): ?array {
    return get_coa(['12703001', '127', '126']);
}

function ensure_col(string $table, string $col, string $type = 'TEXT'): void {
    try {
        $cols = db()->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($col, $cols, true)) db()->exec("ALTER TABLE $table ADD COLUMN $col $type");
    } catch (Throwable $e) {}
}

function compute_tax(float $amount_ghs, int $has_vat, int $has_whvat, string $wht_type, int $has_ucf): array {
    $ex_vat = $has_whvat ? round($amount_ghs / 1.2, 4) : $amount_ghs;
    $vat = $has_whvat ? round($amount_ghs - $ex_vat, 2) : ($has_vat ? round($amount_ghs * 0.20, 2) : 0.0);
    $whvat = $has_whvat ? round($ex_vat * 0.07, 2) : 0.0;
    $rates = ['None' => 0, 'WHT-Goods' => 0.03, 'WHT-Service' => 0.075, 'WHT-Income' => 0.10,
              'WHT-Sitting' => 0.20, 'WHT-Works' => 0.05];
    $wht_rate = $rates[$wht_type] ?? 0;
    $wht = round($ex_vat * $wht_rate, 2);
    $ucf_base = $ex_vat - $wht - $whvat;
    $ucf = $has_ucf ? round(max(0, $ucf_base) * 0.05, 2) : 0.0;
    return compact('ex_vat', 'vat', 'whvat', 'wht', 'wht_rate', 'ucf');
}

/** Post a balanced set of lines as a Posted JV into the general ledger (mirrors
 *  _insert_voucher_posted). $lines: [{coa_id, debit_amount, credit_amount, description}]. */
function post_journal(array $user, string $jv_type, string $date, string $period, string $desc,
                      array $lines, string $source_module, ?string $source_id, ?string $unit): array {
    [$vok, $vmsg] = validate_jv_lines($lines);
    if (!$vok) throw new RuntimeException($vmsg);
    [$pok, $pmsg] = period_guard($period);
    if (!$pok) throw new RuntimeException($pmsg);
    $jid = uuid4();
    $jvnum = seq_code('journal_vouchers', 'jv_number', $jv_type . '-' . substr($period, 0, 4) . '-', 4);
    $tdr = 0.0; $tcr = 0.0;
    foreach ($lines as $l) { $tdr += money($l['debit_amount'] ?? 0); $tcr += money($l['credit_amount'] ?? 0); }
    db()->prepare("INSERT INTO journal_vouchers
        (id,jv_number,jv_type,jv_date,period,description,narration,currency,fx_rate,total_debit,total_credit,
         status,prepared_by,posted_by,posted_at,auto_generated,source_module,source_id,notes,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,'Posted',?,?,datetime('now'),1,?,?,?,?)")
        ->execute([$jid, $jvnum, $jv_type, $date, $period, $desc, $desc, 'GHS', 1.0, $tdr, $tcr,
            $user['username'], $user['username'], $source_module, $source_id, $desc, $unit]);
    $li = db()->prepare("INSERT INTO jv_lines(id,jv_id,line_number,coa_id,description,debit_amount,credit_amount,project_id) VALUES(?,?,?,?,?,?,?,?)");
    $gi = db()->prepare("INSERT INTO general_ledger
        (id,jv_id,jv_number,jv_line_id,ledger_date,period,coa_id,coa_code,account_name,description,
         debit_amount,credit_amount,project_id,posted_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($lines as $l) {
        $n++;
        $lid = uuid4();
        $c = db()->prepare('SELECT code, account_name FROM chart_of_accounts WHERE id=?');
        $c->execute([$l['coa_id']]); $coa = $c->fetch();
        $li->execute([$lid, $jid, $n, $l['coa_id'], (string)($l['description'] ?? $desc),
            money($l['debit_amount'] ?? 0), money($l['credit_amount'] ?? 0), $l['project_id'] ?? null]);
        $gi->execute([uuid4(), $jid, $jvnum, $lid, $date, $period, $l['coa_id'],
            $coa['code'] ?? '', $coa['account_name'] ?? '', (string)($l['description'] ?? $desc),
            money($l['debit_amount'] ?? 0), money($l['credit_amount'] ?? 0), $l['project_id'] ?? null,
            $user['username'], ($l['unit_id'] ?? null) ?: $unit]);
    }
    return [$jid, $jvnum];
}

function api_vendors_list(): void {
    $u = require_auth(); ensure_col('vendors', 'unit_id');
    [$sw, $sp] = unit_scope_sql($u, 'vendors', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT vendors.* FROM vendors WHERE 1=1$sw ORDER BY vendor_name");
    $st->execute($sp); send(['ok' => true, 'vendors' => $st->fetchAll()]);
}
function api_vendor_save(): void {
    $u = require_role(['Admin', 'Finance Officer']);
    $d = body();
    if (empty($d['vendor_name'])) err('vendor_name is required');
    $id = uuid4();
    $code = $d['vendor_code'] ?? seq_code('vendors', 'vendor_code', 'VEN-', 4);
    ensure_col('vendors', 'tin'); ensure_col('vendors', 'vendor_type'); ensure_col('vendors', 'unit_id');
    foreach (['bank_name', 'account_name', 'account_number', 'email', 'phone'] as $c) ensure_col('vendors', $c);
    $unit = resolve_write_unit($u, $d);
    db()->prepare('INSERT INTO vendors(id,vendor_code,vendor_name,tin,vendor_type,bank_name,account_name,account_number,email,phone,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $code, $d['vendor_name'], $d['tin'] ?? null, $d['vendor_type'] ?? 'Supplier', $d['bank_name'] ?? null, $d['account_name'] ?? null, $d['account_number'] ?? null, $d['email'] ?? null, $d['phone'] ?? null, $unit]);
    ok(['id' => $id, 'vendor_code' => $code]);
}
function api_budgets_list(): void {
    require_auth();
    send(db()->query('SELECT * FROM budgets ORDER BY budget_code')->fetchAll());
}
function api_budget_save(): void {
    require_role(['Admin', 'Finance Officer']);
    $d = body();
    foreach (['project_id', 'coa_id'] as $k) if (empty($d[$k])) err("$k is required");
    $id = uuid4();
    $code = $d['budget_code'] ?? seq_code('budgets', 'budget_code', 'BUD-', 4);
    $fcy = (float)($d['budget_fcy'] ?? $d['budget_ghs'] ?? 0);
    $fx = (float)($d['fx_rate'] ?? 1);
    $unit = resolve_write_unit(require_auth(), $d);
    db()->prepare("INSERT INTO budgets(id,budget_code,project_id,coa_id,budget_fcy,currency,fx_rate,budget_ghs,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $code, $d['project_id'], $d['coa_id'], $fcy, $d['currency'] ?? 'GHS', $fx, $fcy * $fx, $unit]);
    ok(['id' => $id, 'budget_code' => $code]);
}
function api_commitments_list(): void {
    require_auth();
    send(db()->query('SELECT * FROM commitments ORDER BY commit_code')->fetchAll());
}
function api_commitment_save(): void {
    $u = require_role(['Admin', 'Finance Officer', 'Project Leader']);
    $d = body();
    foreach (['project_id', 'amount_fcy'] as $k) if (empty($d[$k])) err("$k is required");
    $id = uuid4();
    $code = $d['commit_code'] ?? seq_code('commitments', 'commit_code', 'COM-', 4);
    $fcy = (float)$d['amount_fcy']; $fx = (float)($d['fx_rate'] ?? 1);
    require_write_unit($u, $d, 'commitment');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO commitments(id,commit_code,project_id,budget_id,commit_date,vendor,description,
        currency,amount_fcy,fx_rate,amount_ghs,status,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,'Open',?)")
        ->execute([$id, $code, $d['project_id'], $d['budget_id'] ?? null, $d['commit_date'] ?? date('Y-m-d'),
            $d['vendor'] ?? '', $d['description'] ?? '', $d['currency'] ?? 'GHS', $fcy, $fx, $fcy * $fx, $unit]);
    ok(['id' => $id, 'commit_code' => $code]);
}
function api_actuals_list(): void {
    $u = require_auth(); ensure_col('actuals', 'unit_id');
    [$sw, $sp] = unit_scope_sql($u, 'actuals', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT actuals.* FROM actuals WHERE 1=1$sw ORDER BY created_at DESC");
    $st->execute($sp); send($st->fetchAll());
}
function api_actual_save(): void {
    $u = require_role(['Admin', 'Finance Officer', 'Project Leader']);
    ensure_col('actuals', 'expense_coa_id'); ensure_col('actuals', 'unit_id');
    $d = body();
    // Editing an existing PV (id supplied) is an audit-controlled correction: delegate to
    // the update path, which reverses the original posting and re-posts the amended voucher.
    if (!empty($d['id'])) { api_actual_update(); return; }
    if (empty($d['project_id'])) err('project_id is required');
    if (empty($d['expense_coa_id']) && empty($d['budget_id'])) err('expense account (expense_coa_id) is required');
    $pay_fx = (float)($d['pay_fx_rate'] ?? ($d['fx_rate'] ?? 1));
    $commit_fx = (float)($d['commit_fx_rate'] ?? 0);
    $currency = $d['currency'] ?? 'GHS';
    $amount_fcy = (float)($d['amount_fcy'] ?? 0);
    $amount_ghs = round($amount_fcy * $pay_fx, 2);
    // IAS 21 / IPSAS 4 exchange difference: a foreign-currency PV committed at one
    // rate and paid at another realises a gain (rate fell) or loss (rate rose).
    $fx_gl_ghs = 0.0; $fx_gl_type = null;
    if ($currency !== 'GHS' && $commit_fx > 0 && abs($pay_fx - $commit_fx) > 1e-9) {
        $fx_gl_ghs = round($amount_fcy * ($pay_fx - $commit_fx), 2);
        $fx_gl_type = $pay_fx > $commit_fx ? 'Loss' : 'Gain';
    }
    $has_vat = (int)($d['has_vat'] ?? 0); $has_whvat = (int)($d['has_whvat'] ?? 0); $has_ucf = (int)($d['has_ucf'] ?? 0);
    $wht_type = (string)($d['wht_type'] ?? 'None');
    $t = compute_tax($amount_ghs, $has_vat, $has_whvat, $wht_type, $has_ucf);
    $id = uuid4();
    $code = $d['actual_code'] ?? seq_code('actuals', 'actual_code', 'ACT-', 4);
    require_write_unit($u, $d, 'payment voucher');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO actuals(id,actual_code,project_id,budget_id,commitment_id,expense_date,payee,description,
        currency,amount_fcy,commit_fx_rate,pay_fx_rate,amount_ghs,fx_gl_ghs,fx_gl_type,has_vat,vat_amount,has_whvat,whvat_amount,has_ucf,ucf_amount,
        wht_type,wht_rate,wht_amount,receipt_no,expense_coa_id,is_posted,created_by,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)")
        ->execute([$id, $code, $d['project_id'], $d['budget_id'] ?? null, $d['commitment_id'] ?? null,
            $d['expense_date'] ?? date('Y-m-d'), $d['payee'] ?? '', $d['description'] ?? '',
            $currency, $amount_fcy, $commit_fx ?: null, $pay_fx, $amount_ghs, $fx_gl_ghs, $fx_gl_type,
            $has_vat, $t['vat'], $has_whvat, $t['whvat'], $has_ucf, $t['ucf'],
            $wht_type, $t['wht_rate'], $t['wht'], $d['receipt_no'] ?? '', $d['expense_coa_id'] ?? null,
            $u['username'], $unit]);
    ok(['id' => $id, 'actual_code' => $code, 'tax' => $t, 'fx_gl_ghs' => $fx_gl_ghs, 'fx_gl_type' => $fx_gl_type]);
}
function ensure_actual_lines(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS actual_lines(id TEXT PRIMARY KEY, actual_id TEXT, line_no INTEGER, project_id TEXT,
        dept_code TEXT, coa_id TEXT, description TEXT, amount_ghs REAL, is_taxable INTEGER DEFAULT 0, has_vat INTEGER DEFAULT 0,
        has_whvat INTEGER DEFAULT 0, has_ucf INTEGER DEFAULT 0, wht_type TEXT, vat_amount REAL DEFAULT 0, whvat_amount REAL DEFAULT 0,
        ucf_amount REAL DEFAULT 0, wht_amount REAL DEFAULT 0, budget_id TEXT, created_at TEXT DEFAULT(datetime('now')))");
}
function api_get_actual_lines(): void {
    require_auth(); ensure_actual_lines();
    $aid = $_GET['id'] ?? ($_GET['actual_id'] ?? null);
    if (!$aid) { send([]); }
    $st = db()->prepare('SELECT al.*, c.code AS coa_code, c.account_name FROM actual_lines al LEFT JOIN chart_of_accounts c ON c.id=al.coa_id WHERE al.actual_id=? ORDER BY al.line_no');
    $st->execute([$aid]); send($st->fetchAll());
}
function api_save_multiline_actual(): void {
    $u = require_role(['Admin', 'Finance Officer']); ensure_actual_lines(); ensure_col('actuals', 'expense_coa_id'); ensure_col('actuals', 'unit_id'); ensure_col('actuals', 'is_multiline'); $d = body();
    $lines_in = $d['lines'] ?? []; if (!is_array($lines_in) || count($lines_in) < 1) err('Add at least one payment line');
    if (empty($d['project_id']) && empty($lines_in[0]['project_id'])) { /* project optional per line */ }
    $aid = uuid4(); $code = seq_code('actuals', 'actual_code', 'ACT-', 4);
    $tot = 0.0; $tvat = 0.0; $twhvat = 0.0; $tucf = 0.0; $twht = 0.0; $norm = [];
    foreach ($lines_in as $i => $ln) {
        $coa = $ln['coa_id'] ?? ($ln['expense_coa_id'] ?? null);
        $amt = round((float)($ln['amount_ghs'] ?? ($ln['amount'] ?? 0)), 2);
        if (!$coa) err('Line ' . ($i + 1) . ': account is required');
        if ($amt <= 0) err('Line ' . ($i + 1) . ': amount must be greater than zero');
        $hv = (int)($ln['has_vat'] ?? 0); $hw = (int)($ln['has_whvat'] ?? 0); $hu = (int)($ln['has_ucf'] ?? 0); $wt = (string)($ln['wht_type'] ?? 'None');
        $pid = $ln['project_id'] ?? ($d['project_id'] ?? null);
        $t = compute_tax($amt, $hv, $hw, $wt, $hu);
        // budget auto-charge: match the line's COA (and project, if any) to a budget line.
        $bud = null;
        if ($pid) { $bq = db()->prepare('SELECT id FROM budgets WHERE coa_id=? AND project_id=? LIMIT 1'); $bq->execute([$coa, $pid]); $bud = $bq->fetchColumn() ?: null; }
        if (!$bud) { $bq = db()->prepare('SELECT id FROM budgets WHERE coa_id=? LIMIT 1'); $bq->execute([$coa]); $bud = $bq->fetchColumn() ?: null; }
        $norm[] = ['coa_id' => $coa, 'project_id' => $pid, 'amount' => $amt, 'description' => $ln['description'] ?? '', 'is_taxable' => (int)($ln['is_taxable'] ?? ($hv || $hw || $wt !== 'None')),
                   'has_vat' => $hv, 'has_whvat' => $hw, 'has_ucf' => $hu, 'wht_type' => $wt, 't' => $t, 'budget_id' => $bud];
        $tot += $amt; $tvat += $t['vat']; $twhvat += $t['whvat']; $tucf += $t['ucf']; $twht += $t['wht'];
    }
    require_write_unit($u, $d, 'payment voucher');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO actuals(id,actual_code,project_id,expense_date,payee,description,currency,amount_fcy,pay_fx_rate,amount_ghs,
        has_vat,vat_amount,has_whvat,whvat_amount,has_ucf,ucf_amount,wht_type,wht_amount,receipt_no,expense_coa_id,is_posted,is_multiline,created_by,unit_id)
        VALUES(?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,0,1,?,?)")
        ->execute([$aid, $code, $d['project_id'] ?? ($norm[0]['project_id'] ?? null), $d['expense_date'] ?? date('Y-m-d'), $d['payee'] ?? '',
            $d['description'] ?? 'Multi-line PV', $d['currency'] ?? 'GHS', $tot, $tot,
            ($tvat > 0 ? 1 : 0), round($tvat, 2), ($twhvat > 0 ? 1 : 0), round($twhvat, 2), ($tucf > 0 ? 1 : 0), round($tucf, 2),
            'Mixed', round($twht, 2), $d['receipt_no'] ?? '', $norm[0]['coa_id'], $u['username'], $unit]);
    $li = db()->prepare("INSERT INTO actual_lines(id,actual_id,line_no,project_id,coa_id,description,amount_ghs,is_taxable,has_vat,has_whvat,has_ucf,wht_type,vat_amount,whvat_amount,ucf_amount,wht_amount,budget_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($norm as $ln) { $n++; $li->execute([uuid4(), $aid, $n, $ln['project_id'], $ln['coa_id'], $ln['description'], $ln['amount'], $ln['is_taxable'], $ln['has_vat'], $ln['has_whvat'], $ln['has_ucf'], $ln['wht_type'], $ln['t']['vat'], $ln['t']['whvat'], $ln['t']['ucf'], $ln['t']['wht'], $ln['budget_id']]); }
    ok(['id' => $aid, 'actual_code' => $code, 'lines' => $n]);
}
function api_actual_post(): void {
    $u = require_role(['Admin', 'Finance Officer']);
    $d = body();
    $aid = (string)($d['id'] ?? ($d['actual_id'] ?? ''));
    if ($aid === '') err('id is required');
    $st = db()->prepare('SELECT * FROM actuals WHERE id=?'); $st->execute([$aid]); $a = $st->fetch();
    if (!$a) err('Expense not found');
    if ((int)($a['is_posted'] ?? 0) === 1) err('Expense already posted');
    // ── Multi-line PV: build one balanced journal — Dr each expense line / Cr taxes / Cr bank.
    ensure_actual_lines();
    $mlq = db()->prepare('SELECT * FROM actual_lines WHERE actual_id=? ORDER BY line_no'); $mlq->execute([$aid]); $mlrows = $mlq->fetchAll();
    if ($mlrows) {
        $bank = operating_bank_coa(); if (!$bank) err('Bank account could not be resolved');
        $lines = []; $twht = 0.0; $twhvat = 0.0; $tucf = 0.0; $invoice = 0.0;
        foreach ($mlrows as $r) {
            // VAT treatment mirrors server.py _multiline_expense_lines: a public
            // university's input VAT is IRRECOVERABLE, so it is loaded onto the EXPENSE
            // account (never a separate recoverable input-VAT asset).
            //   * WHVAT line  -> amount_ghs is VAT-INCLUSIVE; expense = the full amount.
            //   * VAT-only    -> amount_ghs is VAT-EXCLUSIVE; add the 20% VAT to expense.
            $amt = (float)$r['amount_ghs']; $hv = (int)$r['has_vat']; $hw = (int)$r['has_whvat'];
            $vat = (float)$r['vat_amount'];
            if ($hw) { $de = $amt; }                                            // VAT-inclusive: full amount to expense
            elseif ($hv) { $de = round($amt + $vat, 2); }                       // VAT-only: irrecoverable VAT onto expense
            else { $de = $amt; }
            $lines[] = ['coa_id' => $r['coa_id'], 'debit_amount' => $de, 'credit_amount' => 0, 'description' => $r['description'] ?: 'Expense line', 'project_id' => $r['project_id']];
            $twht += (float)$r['wht_amount']; $twhvat += (float)$r['whvat_amount']; $tucf += (float)$r['ucf_amount'];
            $invoice += $de;
        }
        foreach ([[$twht, ['2030'], 'WHT Payable'], [$twhvat, ['2031', '2034'], 'WHVAT Payable'], [$tucf, ['2033', '2035'], 'UCC Common Fund Payable']] as $pp) {
            if (round($pp[0], 2) > 0) { $c = get_coa($pp[1]); if ($c) $lines[] = ['coa_id' => $c['id'], 'debit_amount' => 0, 'credit_amount' => round($pp[0], 2), 'description' => $pp[2], 'project_id' => null]; }
        }
        $net = round($invoice - $twht - $twhvat - $tucf, 2);
        $lines[] = ['coa_id' => $bank['id'], 'debit_amount' => 0, 'credit_amount' => $net, 'description' => 'Payment to ' . ($a['payee'] ?? ''), 'project_id' => $a['project_id']];
        try { [$jid, $jvnum] = post_journal($u, 'PV', (string)$a['expense_date'], substr((string)$a['expense_date'], 0, 7), 'Multi-line PV: ' . ($a['payee'] ?? ''), $lines, 'actuals', $aid, ($a['unit_id'] ?? null) ?: resolve_write_unit($u, $d)); }
        catch (Throwable $e) { err('Posting failed: ' . $e->getMessage()); }
        db()->prepare('UPDATE actuals SET is_posted=1, jv_id=? WHERE id=?')->execute([$jid, $aid]);
        ok(['status' => 'Posted', 'jv_number' => $jvnum, 'net_paid' => $net]);
    }
    // resolve accounts
    $exp = null;
    if (!empty($a['expense_coa_id'])) { $c = db()->prepare('SELECT id,code,account_name FROM chart_of_accounts WHERE id=?'); $c->execute([$a['expense_coa_id']]); $exp = $c->fetch() ?: null; }
    if (!$exp && !empty($a['budget_id'])) { $c = db()->prepare('SELECT c.id,c.code,c.account_name FROM budgets b JOIN chart_of_accounts c ON b.coa_id=c.id WHERE b.id=?'); $c->execute([$a['budget_id']]); $exp = $c->fetch() ?: null; }
    if (!$exp) $exp = get_coa(['6103', '6111', '6300', '6000']);
    $bank = operating_bank_coa();
    if (!$exp || !$bank) err('Could not resolve expense or bank account');
    $amount_ghs = (float)$a['amount_ghs']; $has_vat = (int)$a['has_vat']; $has_whvat = (int)$a['has_whvat'];
    $t = compute_tax($amount_ghs, $has_vat, $has_whvat, (string)$a['wht_type'], (int)$a['has_ucf']);
    // VAT treatment mirrors server.py _expense_lines_for_actual: a public university's
    // input VAT is IRRECOVERABLE, so it is loaded onto the EXPENSE account — there is
    // NEVER a separate recoverable input-VAT asset (12300001 / alias '1012') line.
    //   * WHVAT  -> amount_ghs is VAT-INCLUSIVE; expense = the full amount.
    //   * VAT-only -> amount_ghs is VAT-EXCLUSIVE; add the 20% VAT to the expense.
    if ($has_whvat) {
        $debit_expense = $amount_ghs;                                  // full VAT-inclusive sum to expense
    } elseif ($has_vat) {
        $debit_expense = round($amount_ghs + $t['vat'], 2);            // VAT add-on onto expense
    } else {
        $debit_expense = $amount_ghs;
    }
    $gross_invoice = round($debit_expense, 2);
    $net_bank = round($gross_invoice - $t['wht'] - $t['whvat'] - $t['ucf'], 2);
    if ($net_bank < -0.01) err('Deductions exceed gross invoice amount');
    $lines = [['coa_id' => $exp['id'], 'debit_amount' => $debit_expense, 'credit_amount' => 0,
               'description' => 'Expense: ' . ($a['description'] ?? ''), 'project_id' => $a['project_id']]];
    foreach ([[$t['wht'], ['2030'], 'WHT Payable (' . $a['wht_type'] . ')'], [$t['whvat'], ['2031', '2034'], 'WHVAT Payable'], [$t['ucf'], ['2033', '2035'], 'UCC Common Fund Payable']] as $p) {
        if ($p[0] > 0) { $c = get_coa($p[1]); if ($c) $lines[] = ['coa_id' => $c['id'], 'debit_amount' => 0, 'credit_amount' => $p[0], 'description' => $p[2], 'project_id' => $a['project_id']]; }
    }
    $lines[] = ['coa_id' => $bank['id'], 'debit_amount' => 0, 'credit_amount' => $net_bank,
                'description' => 'Payment to ' . ($a['payee'] ?? ''), 'project_id' => $a['project_id']];
    try {
        [$jid, $jvnum] = post_journal($u, 'PV', (string)$a['expense_date'], substr((string)$a['expense_date'], 0, 7),
            'PV: ' . ($a['payee'] ?? '') . ' — ' . ($a['description'] ?? ''), $lines, 'actuals', $aid, ($a['unit_id'] ?? null) ?: resolve_write_unit($u, $d));
    } catch (Throwable $e) { err('Posting failed: ' . $e->getMessage()); }
    db()->prepare("UPDATE actuals SET is_posted=1, jv_id=? WHERE id=?")->execute([$jid, $aid]);
    // Track WHT/WHVAT/UCF as withholding payables for later statutory remittance.
    try { create_withholding_payables($aid, $a, $t, $jvnum, $u); } catch (Throwable $e) {}
    if (!empty($a['commitment_id'])) {
        // encumbrance settle: close the commitment once posted payments cover it
        $cm = db()->prepare('SELECT amount_ghs FROM commitments WHERE id=?'); $cm->execute([$a['commitment_id']]); $cmr = $cm->fetch();
        if ($cmr) {
            $paid = db()->prepare("SELECT COALESCE(SUM(amount_ghs),0) FROM actuals WHERE commitment_id=? AND is_posted=1");
            $paid->execute([$a['commitment_id']]); $pd = (float)$paid->fetchColumn();
            if ((float)$cmr['amount_ghs'] > 0 && $pd >= (float)$cmr['amount_ghs'] - 0.01)
                db()->prepare("UPDATE commitments SET status='Fully Paid' WHERE id=?")->execute([$a['commitment_id']]);
        }
    }
    ok(['status' => 'Posted', 'jv_number' => $jvnum, 'net_paid' => $net_bank, 'tax' => $t]);
}
function reverse_jv(string $jvid, array $u): void {
    $g = db()->prepare('SELECT * FROM general_ledger WHERE jv_id=?'); $g->execute([$jvid]); $rows = $g->fetchAll();
    if (!$rows) return;
    $jq = db()->prepare('SELECT * FROM journal_vouchers WHERE id=?'); $jq->execute([$jvid]); $j = $jq->fetch();
    ensure_col('journal_vouchers', 'is_reversal', 'INTEGER'); ensure_col('journal_vouchers', 'reversal_of', 'TEXT'); ensure_col('journal_vouchers', 'reversed_by', 'TEXT');
    // The reversing JV lands on the ORIGINAL date/period and is tagged is_reversal +
    // reversal_of so it shows in the reversals register and the cash-book net view.
    $rdate = (string)($j['jv_date'] ?? date('Y-m-d')); $rperiod = (string)($j['period'] ?? substr($rdate, 0, 7));
    $rid = uuid4(); $rnum = seq_code('journal_vouchers', 'jv_number', 'RJV-' . substr($rperiod, 0, 4) . '-', 4);
    $tdr = 0.0; $tcr = 0.0; foreach ($rows as $r) { $tdr += money($r['credit_amount']); $tcr += money($r['debit_amount']); }
    db()->prepare("INSERT INTO journal_vouchers(id,jv_number,jv_type,jv_date,period,description,total_debit,total_credit,status,prepared_by,posted_by,posted_at,is_reversal,reversal_of,source_module,source_id,unit_id) VALUES(?,?,?,?,?,?,?,?,'Posted',?,?,datetime('now'),1,?,?,?,?)")
        ->execute([$rid, $rnum, 'RJV', $rdate, $rperiod, 'Reversal of ' . ($j['jv_number'] ?? ''), $tdr, $tcr, $u['username'], $u['username'], $jvid, 'reversal', $jvid, $j['unit_id'] ?? null]);
    $gi = db()->prepare("INSERT INTO general_ledger(id,jv_id,jv_number,jv_line_id,ledger_date,period,coa_id,coa_code,account_name,description,debit_amount,credit_amount,project_id,posted_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) { $gi->execute([uuid4(), $rid, $rnum, null, $rdate, $rperiod, $r['coa_id'], $r['coa_code'], $r['account_name'], 'Reversal: ' . (string)($r['description'] ?? ''), money($r['credit_amount']), money($r['debit_amount']), $r['project_id'] ?? null, $u['username'], $r['unit_id'] ?? null]); }
    if ($j) db()->prepare("UPDATE journal_vouchers SET status='Reversed', reversed_by=? WHERE id=?")->execute([$rid, $jvid]);
}
function api_actual_update(): void {
    $u = require_role(['Admin']); $d = body();
    $aid = (string)($d['id'] ?? ''); if ($aid === '') err('id is required');
    if (empty($d['edit_reason'])) err('Admin edit reason is required to amend a posted PV');
    $st = db()->prepare('SELECT * FROM actuals WHERE id=?'); $st->execute([$aid]); $a = $st->fetch();
    if (!$a) err('Expense not found');
    // Audit-controlled correction: reverse the original posting, then re-post the amended PV.
    if ((int)($a['is_posted'] ?? 0) && !empty($a['jv_id'])) reverse_jv((string)$a['jv_id'], $u);
    $pay_fx = (float)($d['pay_fx_rate'] ?? ($a['pay_fx_rate'] ?? 1));
    $amount_fcy = (float)($d['amount_fcy'] ?? $a['amount_fcy']);
    $amount_ghs = $amount_fcy * $pay_fx;
    $has_vat = (int)($d['has_vat'] ?? $a['has_vat']); $has_whvat = (int)($d['has_whvat'] ?? $a['has_whvat']); $has_ucf = (int)($d['has_ucf'] ?? $a['has_ucf']);
    $wht_type = (string)($d['wht_type'] ?? ($a['wht_type'] ?? 'None'));
    $t = compute_tax($amount_ghs, $has_vat, $has_whvat, $wht_type, $has_ucf);
    db()->prepare("UPDATE actuals SET amount_fcy=?,pay_fx_rate=?,amount_ghs=?,description=?,expense_coa_id=?,has_vat=?,vat_amount=?,has_whvat=?,whvat_amount=?,has_ucf=?,ucf_amount=?,wht_type=?,wht_rate=?,wht_amount=?,is_posted=0,jv_id=NULL WHERE id=?")
        ->execute([$amount_fcy, $pay_fx, $amount_ghs, $d['description'] ?? $a['description'], $d['expense_coa_id'] ?? $a['expense_coa_id'],
            $has_vat, $t['vat'], $has_whvat, $t['whvat'], $has_ucf, $t['ucf'], $wht_type, $t['wht_rate'], $t['wht'], $aid]);
    api_actual_post(); // re-post the amended PV (reads the same body 'id'); ok() exits
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3b — Receipts (RV) + JV workflow (submit→approve→post with segregation of
// duties) + withholding settlement. Mirrors server.py: the fund-receipt auto-post
// (Dr Bank / Cr Income), api_jv_workflow (maker cannot approve own JV; Admin-only
// posting), and withholding-payable settlement.
// ════════════════════════════════════════════════════════════════════════════
function api_fund_receipts_list(): void {
    $u = require_auth(); ensure_col('fund_receipts', 'unit_id');
    [$sw, $sp] = unit_scope_sql($u, 'fund_receipts', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT fund_receipts.* FROM fund_receipts WHERE 1=1$sw ORDER BY created_at DESC");
    $st->execute($sp); send(['ok' => true, 'receipts' => $st->fetchAll()]);
}
function api_fund_receipt_save(): void {
    $u = require_role(['Admin', 'Finance Officer', 'Project Leader']);
    foreach (['income_coa_id', 'is_posted', 'jv_id', 'unit_id'] as $c) ensure_col('fund_receipts', $c);
    $d = body();
    if (empty($d['project_id'])) err('project_id is required');
    // Default the income/revenue account to the first revenue (4xxx) account if the
    // caller didn't specify one (mirrors the Python reference, which auto-classifies).
    if (empty($d['income_coa_id'])) {
        $rc = db()->query("SELECT id FROM chart_of_accounts WHERE code LIKE '4%' ORDER BY code LIMIT 1")->fetchColumn();
        if ($rc) $d['income_coa_id'] = $rc; else err('No revenue (4xxx) account in the chart of accounts');
    }
    $fx = (float)($d['fx_rate'] ?? 1);
    $fcy = (float)($d['amount_fcy'] ?? 0);
    $id = uuid4();
    $code = $d['receipt_code'] ?? seq_code('fund_receipts', 'receipt_code', 'RV-', 4);
    require_write_unit($u, $d, 'fund receipt');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO fund_receipts(id,receipt_code,project_id,bank_account_id,receipt_date,donor,
        description,currency,amount_fcy,fx_rate,amount_ghs,reference_no,receipt_type,income_coa_id,is_posted,created_by,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)")
        ->execute([$id, $code, $d['project_id'], $d['bank_account_id'] ?? null, $d['receipt_date'] ?? date('Y-m-d'),
            $d['donor'] ?? 'Internal', $d['description'] ?? 'Receipt', $d['currency'] ?? 'GHS', $fcy, $fx, $fcy * $fx,
            $d['reference_no'] ?? '', $d['receipt_type'] ?? $d['income_type'] ?? 'Grant Receipt',
            $d['income_coa_id'], $u['username'], $unit]);
    // Fund receipts are actual money received → auto-post to the GL (Dr Bank / Cr Income),
    // mirroring the Python reference, so the receipt immediately carries a jv_id and shows
    // in the cash book / statements (and can be reversed).
    $amt = round($fcy * $fx, 2);
    $bankc = bank_coa_from_account($d['bank_account_id'] ?? null);
    if ($amt > 0 && $bankc) {
        $rdate = (string)($d['receipt_date'] ?? date('Y-m-d'));
        $lines = [['coa_id' => $bankc, 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => 'Receipt: ' . ($d['donor'] ?? ''), 'project_id' => $d['project_id']],
                  ['coa_id' => $d['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Income: ' . ($d['description'] ?? ''), 'project_id' => $d['project_id']]];
        try {
            [$jid, $jvnum] = post_journal($u, 'RV', $rdate, substr($rdate, 0, 7), 'RV: ' . ($d['donor'] ?? '') . ' — ' . ($d['description'] ?? ''), $lines, 'fund_receipts', $id, $unit);
            db()->prepare('UPDATE fund_receipts SET is_posted=1, jv_id=? WHERE id=?')->execute([$jid, $id]);
            ok(['id' => $id, 'receipt_code' => $code, 'jv_number' => $jvnum, 'status' => 'Posted']);
        } catch (Throwable $e) { /* leave unposted; explicit /post endpoint can retry */ }
    }
    ok(['id' => $id, 'receipt_code' => $code]);
}
function api_fund_receipt_post(): void {
    $u = require_role(['Admin', 'Finance Officer']);
    $d = body();
    $rid = (string)($d['id'] ?? ($d['receipt_id'] ?? ''));
    if ($rid === '') err('id is required');
    $st = db()->prepare('SELECT * FROM fund_receipts WHERE id=?'); $st->execute([$rid]); $r = $st->fetch();
    if (!$r) err('Receipt not found');
    if ((int)($r['is_posted'] ?? 0) === 1) err('Receipt already posted');
    $inc = db()->prepare('SELECT id FROM chart_of_accounts WHERE id=?'); $inc->execute([$r['income_coa_id']]);
    if (!$inc->fetch()) err('Invalid income account');
    $bank = operating_bank_coa();
    if (!$bank) err('Could not resolve bank account');
    $amt = round((float)$r['amount_ghs'], 2);
    $lines = [
        ['coa_id' => $bank['id'], 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => 'Receipt: ' . ($r['donor'] ?? ''), 'project_id' => $r['project_id']],
        ['coa_id' => $r['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Income: ' . ($r['description'] ?? ''), 'project_id' => $r['project_id']],
    ];
    try {
        [$jid, $jvnum] = post_journal($u, 'RV', (string)$r['receipt_date'], substr((string)$r['receipt_date'], 0, 7),
            'RV: ' . ($r['donor'] ?? '') . ' — ' . ($r['description'] ?? ''), $lines, 'fund_receipts', $rid, ($r['unit_id'] ?? null) ?: resolve_write_unit($u, $d));
    } catch (Throwable $e) { err('Posting failed: ' . $e->getMessage()); }
    db()->prepare('UPDATE fund_receipts SET is_posted=1, jv_id=? WHERE id=?')->execute([$jid, $rid]);
    ok(['status' => 'Posted', 'jv_number' => $jvnum, 'amount' => $amt]);
}

function api_jv_workflow(): void {
    $u = require_auth();
    $d = body();
    $jid = (string)($d['jv_id'] ?? ($d['id'] ?? ''));
    $action = (string)($d['action'] ?? '');
    if ($jid === '' || $action === '') err('jv_id and action are required');
    $st = db()->prepare('SELECT * FROM journal_vouchers WHERE id=?'); $st->execute([$jid]); $jv = $st->fetch();
    if (!$jv) err('JV not found');
    $role = $u['role']; $status = $jv['status'] ?? 'Draft';
    if ($action === 'submit') {
        if (!in_array($role, ['Admin', 'Finance Officer'], true)) err('Your role cannot submit JVs', 403);
        if ($status !== 'Draft') err("Only Draft JVs can be submitted (current: $status)");
        db()->prepare("UPDATE journal_vouchers SET status='Submitted', submitted_by=?, submitted_at=datetime('now') WHERE id=?")->execute([$u['username'], $jid]);
        ok(['new_status' => 'Submitted', 'jv_number' => $jv['jv_number']]);
    }
    if ($action === 'approve') {
        if (!in_array($role, ['Admin', 'Finance Officer'], true)) err('Your role cannot approve JVs', 403);
        if ($status !== 'Submitted') err("Only Submitted JVs can be approved (current: $status)");
        if (($jv['prepared_by'] ?? '') === $u['username'])
            err('You cannot approve a journal you prepared (segregation of duties) — ask another authorised officer.');
        db()->prepare("UPDATE journal_vouchers SET status='Approved', approved_by=?, approved_at=datetime('now') WHERE id=?")->execute([$u['username'], $jid]);
        ok(['new_status' => 'Approved', 'jv_number' => $jv['jv_number']]);
    }
    if ($action === 'post') {
        if ($role !== 'Admin') err('Only Admins can post JVs to the general ledger', 403);
        if ($status !== 'Approved') err("Only Approved JVs can be posted (current: $status)");
        $ls = db()->prepare("SELECT l.*, c.code AS cc, c.account_name AS an FROM jv_lines l JOIN chart_of_accounts c ON c.id=l.coa_id WHERE l.jv_id=? ORDER BY l.line_number");
        $ls->execute([$jid]); $lines = $ls->fetchAll();
        if (!$lines) err('JV has no lines');
        foreach ($lines as $l) {
            if (money($l['debit_amount'] ?? 0) < 0 || money($l['credit_amount'] ?? 0) < 0)
                err('Journal voucher has a negative line amount; cannot post');
        }
        $gi = db()->prepare("INSERT INTO general_ledger(id,jv_id,jv_number,jv_line_id,ledger_date,period,coa_id,coa_code,account_name,description,debit_amount,credit_amount,project_id,posted_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $l) {
            $gi->execute([uuid4(), $jid, $jv['jv_number'], $l['id'], $jv['jv_date'], $jv['period'], $l['coa_id'], $l['cc'], $l['an'],
                (string)($l['description'] ?? ''), money($l['debit_amount'] ?? 0), money($l['credit_amount'] ?? 0), $l['project_id'] ?? null, $u['username'], ($l['unit_id'] ?? null) ?: ($jv['unit_id'] ?? null)]);
        }
        db()->prepare("UPDATE journal_vouchers SET status='Posted', posted_by=?, posted_at=datetime('now') WHERE id=?")->execute([$u['username'], $jid]);
        ok(['new_status' => 'Posted', 'jv_number' => $jv['jv_number']]);
    }
    if ($action === 'reverse') {
        if ($role !== 'Admin') err('Only Admins can reverse JVs', 403);
        if ($status !== 'Posted') err("Only Posted JVs can be reversed (current: $status)");
        ensure_col('journal_vouchers', 'is_reversal', 'INTEGER');
        ensure_col('journal_vouchers', 'reversal_of', 'TEXT');
        ensure_col('journal_vouchers', 'reversed_by', 'TEXT');
        $g = db()->prepare('SELECT * FROM general_ledger WHERE jv_id=?'); $g->execute([$jid]); $glr = $g->fetchAll();
        if (!$glr) err('No ledger entries to reverse');
        // The reversing JV lands on the ORIGINAL date/period so the books are corrected in
        // the period the error occurred (mirrors server.py reversal dating).
        $rdate = (string)($jv['jv_date'] ?? date('Y-m-d')); $rperiod = (string)($jv['period'] ?? substr($rdate, 0, 7));
        $rid = uuid4(); $rnum = seq_code('journal_vouchers', 'jv_number', 'RJV-' . substr($rperiod, 0, 4) . '-', 4);
        $tdr = 0.0; $tcr = 0.0; foreach ($glr as $r) { $tdr += money($r['credit_amount']); $tcr += money($r['debit_amount']); }
        db()->prepare("INSERT INTO journal_vouchers(id,jv_number,jv_type,jv_date,period,description,total_debit,total_credit,status,prepared_by,posted_by,posted_at,is_reversal,reversal_of,source_module,source_id,unit_id) VALUES(?,?,?,?,?,?,?,?,'Posted',?,?,datetime('now'),1,?,?,?,?)")
            ->execute([$rid, $rnum, 'RJV', $rdate, $rperiod, 'Reversal of ' . $jv['jv_number'] . ': ' . (string)($d['reason'] ?? ''), $tdr, $tcr, $u['username'], $u['username'], $jid, 'reversal', $jid, $jv['unit_id'] ?? null]);
        $gi = db()->prepare("INSERT INTO general_ledger(id,jv_id,jv_number,jv_line_id,ledger_date,period,coa_id,coa_code,account_name,description,debit_amount,credit_amount,project_id,posted_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($glr as $r) {
            $gi->execute([uuid4(), $rid, $rnum, null, $rdate, $rperiod, $r['coa_id'], $r['coa_code'], $r['account_name'],
                'Reversal: ' . (string)($r['description'] ?? ''), money($r['credit_amount']), money($r['debit_amount']), $r['project_id'] ?? null, $u['username'], $r['unit_id'] ?? null]);
        }
        db()->prepare("UPDATE journal_vouchers SET status='Reversed', reversed_by=? WHERE id=?")->execute([$rid, $jid]);
        if (in_array((string)($jv['source_module'] ?? ''), ['fund_receipts', 'receipts'], true) && !empty($jv['source_id'])) {
            try { db()->prepare("UPDATE fund_receipts SET is_posted=0 WHERE id=?")->execute([$jv['source_id']]); } catch (Throwable $e) {}
        }
        ok(['new_status' => 'Reversed', 'jv_number' => $jv['jv_number'], 'reversal_jv' => $rnum, 'rev_date' => $rdate]);
    }
    err("Unknown action: $action");
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3c (assets) — Fixed-asset register + straight-line depreciation to the GL.
// Mirrors server.py _f53_dep_rows / api_run_depreciation: monthly = (cost-residual)/
// (life*12), capped at the remaining depreciable amount; one posted JV per run,
// Dr Depreciation Expense (619) / Cr Accumulated Depreciation (119).
// (Payroll PAYE/SSNIT and fuel are deferred — a large, tax-critical port.)
// ════════════════════════════════════════════════════════════════════════════
function api_assets_list(): void {
    require_auth();
    send(['ok' => true, 'assets' => db()->query('SELECT * FROM asset_register ORDER BY asset_code')->fetchAll()]);
}
function api_asset_save(): void {
    $u = require_role(['Admin', 'Finance Officer']);
    $d = body();
    if (empty($d['asset_name'])) err('asset_name is required');
    $cost = (float)($d['acquisition_cost'] ?? 0);
    $accum = round((float)($d['accumulated_depreciation'] ?? 0), 2);
    $carry = isset($d['carrying_amount']) ? round((float)$d['carrying_amount'], 2) : round($cost - $accum, 2);
    $status = $d['status'] ?? 'Active';
    $id = uuid4();
    $code = $d['asset_code'] ?? seq_code('asset_register', 'asset_code', 'AST-', 4);
    require_write_unit($u, $d, 'fixed asset');
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO asset_register(id,asset_code,asset_name,asset_category,acquisition_date,
        acquisition_cost,useful_life_years,residual_value,accumulated_depreciation,carrying_amount,asset_coa_id,status,created_by,unit_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $code, $d['asset_name'], $d['asset_category'] ?? 'Equipment',
            $d['acquisition_date'] ?? date('Y-m-d'), $cost, (float)($d['useful_life_years'] ?? 0),
            (float)($d['residual_value'] ?? 0), $accum, $carry, $d['asset_coa_id'] ?? null, $status, $u['username'], $unit]);
    ok(['id' => $id, 'asset_code' => $code]);
}
// Find-or-create the chart accounts revaluation/impairment needs (mirror _reval_coa).
function reval_coa(string $kind): ?string {
    $find = function (string $sql) { $r = db()->query($sql)->fetchColumn(); return $r ?: null; };
    $create = function (string $code, string $name, string $cat, string $sub, string $atype) {
        $n = (int)$code;
        while (db()->query("SELECT 1 FROM chart_of_accounts WHERE code='" . $n . "'")->fetchColumn()) $n++;
        $cid = uuid4();
        db()->prepare("INSERT INTO chart_of_accounts(id,code,category,sub_category,account_name,account_type,vat_applicable) VALUES(?,?,?,?,?,?,0)")
            ->execute([$cid, (string)$n, $cat, $sub, $name, $atype]);
        return $cid;
    };
    if ($kind === 'surplus') return $find("SELECT id FROM chart_of_accounts WHERE code LIKE '3%' AND LOWER(account_name) LIKE '%revaluation%' ORDER BY code LIMIT 1") ?: $create('31200001', 'Revaluation Surplus', 'Equity', 'Reserves', 'Equity');
    if ($kind === 'loss') return $find("SELECT id FROM chart_of_accounts WHERE code LIKE '6%' AND LOWER(account_name) LIKE '%impair%' ORDER BY code LIMIT 1") ?: $create('61900031', 'Impairment Loss', 'Expenses', 'Depreciation & Impairment', 'Expense');
    if ($kind === 'accum_impair') return $find("SELECT id FROM chart_of_accounts WHERE code LIKE '119%' AND LOWER(account_name) LIKE '%impair%' ORDER BY code LIMIT 1") ?: $create('11912001', 'Accumulated Impairment', 'Assets', 'Accumulated Depreciation', 'Asset');
    return null;
}
// Dispose/sell/scrap a fixed asset: Dr Accum.Dep + Dr Bank (proceeds) / Cr Asset
// cost, balancing gain (42300012) or loss (61700017). Marks the asset Disposed.
function api_asset_dispose(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $aid = (string)($d['asset_id'] ?? ($d['id'] ?? ''));
    $st = db()->prepare("SELECT * FROM asset_register WHERE id=?"); $st->execute([$aid]); $a = $st->fetch();
    if (!$a) err('Asset not found');
    if (($a['status'] ?? '') === 'Disposed') err('Asset is already disposed');
    $cost = round((float)($a['acquisition_cost'] ?? 0), 2);
    $accum = round((float)($a['accumulated_depreciation'] ?? 0), 2);
    $nbv = round($cost - $accum, 2);
    $proceeds = round((float)($d['proceeds'] ?? 0), 2);
    if ($proceeds < 0) err('Proceeds cannot be negative');
    $cost_coa = $d['cost_coa_id'] ?? null; $accumdep_coa = $d['accumdep_coa_id'] ?? null;
    if ($cost > 0 && !$cost_coa) err('Select the asset cost account (PPE 111xxxxx) to credit');
    if ($accum > 0 && !$accumdep_coa) err('Select the accumulated depreciation account (119xxxxx)');
    $ddate = (string)($d['disposal_date'] ?? date('Y-m-d'));
    $reason = trim((string)($d['reason'] ?? '')) ?: 'Asset disposal';
    $gain_coa = db()->query("SELECT id FROM chart_of_accounts WHERE code='42300012'")->fetchColumn()
        ?: db()->query("SELECT id FROM chart_of_accounts WHERE LOWER(account_name) LIKE '%disposal%' AND code LIKE '4%' ORDER BY code LIMIT 1")->fetchColumn()
        ?: db()->query("SELECT id FROM chart_of_accounts WHERE (LOWER(account_name) LIKE '%other income%' OR LOWER(account_name) LIKE '%sundry income%') AND code LIKE '4%' ORDER BY code LIMIT 1")->fetchColumn();
    $loss_coa = db()->query("SELECT id FROM chart_of_accounts WHERE code='61700017'")->fetchColumn()
        ?: db()->query("SELECT id FROM chart_of_accounts WHERE LOWER(account_name) LIKE '%loss on disposal%' ORDER BY code LIMIT 1")->fetchColumn();
    $bank_coa = null;
    if ($proceeds > 0) { $bankId = (string)($d['bank_account_id'] ?? ''); if ($bankId === '') err('Select the bank account receiving the proceeds'); $bank_coa = bank_coa_from_account($bankId); if (!$bank_coa) err('Bank account could not be resolved'); }
    $gain = round($proceeds + $accum - $cost, 2);
    $desc = 'Disposal of ' . $a['asset_name'] . ' (' . $a['asset_code'] . ')';
    $lines = [];
    if ($accum > 0) $lines[] = ['coa_id' => $accumdep_coa, 'debit_amount' => $accum, 'credit_amount' => 0, 'description' => $desc];
    if ($proceeds > 0) $lines[] = ['coa_id' => $bank_coa, 'debit_amount' => $proceeds, 'credit_amount' => 0, 'description' => $desc];
    if ($cost > 0) $lines[] = ['coa_id' => $cost_coa, 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => $desc];
    if ($gain > 0.005) { if (!$gain_coa) err('Gain/disposal income account (42300012) not found'); $lines[] = ['coa_id' => $gain_coa, 'debit_amount' => 0, 'credit_amount' => $gain, 'description' => 'Gain on disposal of ' . $a['asset_code']]; }
    elseif ($gain < -0.005) { if (!$loss_coa) err('Loss on disposal account (61700017) not found'); $lines[] = ['coa_id' => $loss_coa, 'debit_amount' => round(-$gain, 2), 'credit_amount' => 0, 'description' => 'Loss on disposal of ' . $a['asset_code']]; }
    if (!$lines) err('Nothing to post for this asset');
    try { [$jid, $jvnum] = post_journal($u, 'JV', $ddate, substr($ddate, 0, 7), $desc, $lines, 'asset_disposal', $aid, $a['unit_id'] ?? null); }
    catch (Throwable $e) { err('Could not post disposal: ' . $e->getMessage()); }
    db()->prepare("UPDATE asset_register SET status='Disposed', carrying_amount=0, disposal_date=?, disposal_proceeds=?, disposal_reason=?, updated_at=datetime('now') WHERE id=?")
        ->execute([$ddate, $proceeds, $reason, $aid]);
    ok(['id' => $aid, 'jv_number' => $jvnum, 'nbv' => $nbv, 'proceeds' => $proceeds, 'gain_loss' => $gain,
        'message' => sprintf('Asset disposed as %s (%s GHS %.2f)', $jvnum, $gain >= 0 ? 'gain' : 'loss', abs($gain))]);
}
// IPSAS 17/21 revaluation (up: Dr cost / Cr Revaluation Surplus) or impairment
// (down: Dr Impairment Loss / Cr Accumulated Impairment). Updates carrying amount.
function api_asset_revalue(): void {
    $u = require_auth(); $d = body();
    if (($u['role'] ?? '') !== 'Admin') err('Admin access required for revaluation / impairment');
    $aid = (string)($d['asset_id'] ?? ''); $reason = trim((string)($d['reason'] ?? ''));
    if ($aid === '') err('asset_id is required');
    if (strlen($reason) < 5) err('A justification of at least 5 characters is required');
    if (!is_numeric($d['new_value'] ?? null)) err('new_value must be a number');
    $new_value = round((float)$d['new_value'], 2); if ($new_value < 0) err('new_value cannot be negative');
    $vdate = substr((string)($d['valuation_date'] ?? date('Y-m-d')), 0, 10);
    $st = db()->prepare("SELECT * FROM asset_register WHERE id=?"); $st->execute([$aid]); $a = $st->fetch();
    if (!$a) err('Asset not found');
    if (($a['status'] ?? 'Active') !== 'Active') err('Only an Active asset can be revalued (this one is ' . ($a['status'] ?? '') . ')');
    $nbv = round($a['carrying_amount'] !== null ? (float)$a['carrying_amount'] : ((float)($a['acquisition_cost'] ?? 0) - (float)($a['accumulated_depreciation'] ?? 0)), 2);
    $delta = round($new_value - $nbv, 2);
    if (abs($delta) < 0.005) err('New value equals the current carrying amount — nothing to post');
    if ($delta > 0) {
        $cost_coa = $a['asset_coa_id'] ?: (db()->query("SELECT id FROM chart_of_accounts WHERE code LIKE '111%' ORDER BY code LIMIT 1")->fetchColumn() ?: null);
        if (!$cost_coa) err('No PPE cost account (111x) found');
        $surplus = reval_coa('surplus');
        $lines = [['coa_id' => $cost_coa, 'debit_amount' => $delta, 'credit_amount' => 0, 'description' => 'Revaluation uplift ' . $a['asset_code']],
                  ['coa_id' => $surplus, 'debit_amount' => 0, 'credit_amount' => $delta, 'description' => 'Revaluation surplus ' . $a['asset_code']]];
        $kind = 'Revaluation';
    } else {
        $loss = reval_coa('loss'); $accum = reval_coa('accum_impair');
        $lines = [['coa_id' => $loss, 'debit_amount' => abs($delta), 'credit_amount' => 0, 'description' => 'Impairment loss ' . $a['asset_code']],
                  ['coa_id' => $accum, 'debit_amount' => 0, 'credit_amount' => abs($delta), 'description' => 'Accumulated impairment ' . $a['asset_code']]];
        $kind = 'Impairment';
    }
    $desc = sprintf('%s of %s (%s): carrying GHS %.2f -> GHS %.2f. %s', $kind, $a['asset_name'], $a['asset_code'], $nbv, $new_value, $reason);
    try { [$jid, $jvnum] = post_journal($u, 'JV', $vdate, substr($vdate, 0, 7), $desc, $lines, 'asset_revaluation', $aid, $a['unit_id'] ?? null); }
    catch (Throwable $e) { err('Could not post the ' . strtolower($kind) . ' journal: ' . $e->getMessage()); }
    if ($delta > 0) db()->prepare("UPDATE asset_register SET carrying_amount=?, revaluation_amount=COALESCE(revaluation_amount,0)+?, last_valuation_date=? WHERE id=?")->execute([$new_value, $delta, $vdate, $aid]);
    else db()->prepare("UPDATE asset_register SET carrying_amount=?, impairment_amount=COALESCE(impairment_amount,0)+?, last_valuation_date=? WHERE id=?")->execute([$new_value, abs($delta), $vdate, $aid]);
    ok(['asset_id' => $aid, 'kind' => $kind, 'previous_carrying' => $nbv, 'new_carrying' => $new_value, 'movement' => $delta, 'jv_number' => $jvnum,
        'message' => sprintf('%s posted as %s (GHS %.2f)', $kind, $jvnum, abs($delta))]);
}
function dep_rows(): array {
    $rows = db()->query("SELECT * FROM asset_register WHERE status='Active'")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $cost = (float)($r['acquisition_cost'] ?? 0);
        $res = (float)($r['residual_value'] ?? 0);
        $life = (float)($r['useful_life_years'] ?? 0);
        $accum = (float)($r['accumulated_depreciation'] ?? 0);
        $monthly = $life > 0 ? round(($cost - $res) / ($life * 12), 2) : 0.0;
        $remaining = round(max(0.0, ($cost - $res) - $accum), 2);
        if ($monthly > $remaining) $monthly = $remaining;
        $out[] = ['id' => $r['id'], 'asset_code' => $r['asset_code'], 'cost' => $cost,
                  'accum' => $accum, 'monthly' => $monthly, 'unit_id' => $r['unit_id'] ?? null];
    }
    return $out;
}
function api_depreciation_schedule(): void {
    require_auth();
    $rows = dep_rows();
    send(['ok' => true, 'schedule' => $rows, 'total_monthly' => round(array_sum(array_map(fn($r) => $r['monthly'], $rows)), 2)]);
}
function api_depreciation_run(): void {
    $u = require_role(['Admin']);
    $d = body();
    $month = (string)($d['month'] ?? date('Y-m'));
    $already = db()->prepare('SELECT COUNT(*) FROM depreciation_runs WHERE run_month=?'); $already->execute([$month]);
    if ((int)$already->fetchColumn() > 0 && empty($d['force'])) err("Depreciation already posted for $month");
    $expC = get_coa(['61900011', '619']); $accC = get_coa(['11902004', '119']);
    $rows = dep_rows();
    if ($rows && (!$expC || !$accC)) err('Depreciation accounts (expense 619 / accumulated 119) missing from COA');
    $posted = 0; $total = 0.0; $unit = null;
    $ins = db()->prepare("INSERT INTO depreciation_runs(id,run_month,asset_id,asset_code,monthly_dep,accumulated_after,run_by) VALUES(?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        if ($r['monthly'] <= 0) continue;
        $newAccum = round($r['accum'] + $r['monthly'], 2);
        db()->prepare('UPDATE asset_register SET accumulated_depreciation=?, carrying_amount=? WHERE id=?')
            ->execute([$newAccum, round($r['cost'] - $newAccum, 2), $r['id']]);
        $ins->execute([uuid4(), $month, $r['id'], $r['asset_code'], $r['monthly'], $newAccum, $u['username']]);
        $posted++; $total += $r['monthly']; if (!$unit) $unit = $r['unit_id'];
    }
    $total = round($total, 2);
    $jvnum = null;
    if ($total > 0.005) {
        $ledger_date = (strlen($month) === 7) ? ($month . '-28') : date('Y-m-d');
        $lines = [
            ['coa_id' => $expC['id'], 'debit_amount' => $total, 'credit_amount' => 0, 'description' => "Depreciation $month"],
            ['coa_id' => $accC['id'], 'debit_amount' => 0, 'credit_amount' => $total, 'description' => "Accumulated depreciation $month"],
        ];
        try {
            [$jid, $jvnum] = post_journal($u, 'JV', $ledger_date, substr($ledger_date, 0, 7),
                "Monthly depreciation $month ($posted assets)", $lines, 'asset_depreciation', $month, $unit ?: resolve_write_unit($u, $d));
        } catch (Throwable $e) { err('Depreciation GL posting failed: ' . $e->getMessage()); }
    }
    ok(['status' => 'Posted', 'month' => $month, 'assets' => $posted, 'total' => $total, 'jv_number' => $jvnum]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3d — AR / AP subledgers. AR invoice: Dr Receivables control (123) / Cr
// Income (+ Cr Output VAT 21100024). AP bill: Dr Expense / Cr Payables control
// (21100021). Mirrors server.py api_ar_post_invoice / api_ap_post_bill. Tables are
// lazily created in the Python engine, so PHP ensures them. (Inventory + petty cash
// remain for a later pass.)
// ════════════════════════════════════════════════════════════════════════════
function ensure_arap_tables(): void {
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS ar_customers(id TEXT PRIMARY KEY, customer_code TEXT, customer_name TEXT NOT NULL, customer_type TEXT DEFAULT 'Customer', email TEXT, phone TEXT, tin TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS ar_invoices(id TEXT PRIMARY KEY, invoice_number TEXT, customer_id TEXT, invoice_date TEXT, due_date TEXT, project_id TEXT, income_coa_id TEXT, description TEXT, amount_ghs REAL DEFAULT 0, tax_ghs REAL DEFAULT 0, total_ghs REAL DEFAULT 0, amount_received REAL DEFAULT 0, status TEXT DEFAULT 'Draft', jv_id TEXT, jv_number TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')), posted_at TEXT)");
    $p->exec("CREATE TABLE IF NOT EXISTS ar_invoice_lines(id TEXT PRIMARY KEY, invoice_id TEXT, line_number INTEGER, description TEXT, income_coa_id TEXT, amount_ghs REAL DEFAULT 0)");
    $p->exec("CREATE TABLE IF NOT EXISTS ap_bills(id TEXT PRIMARY KEY, bill_number TEXT, vendor_invoice_no TEXT, vendor_id TEXT, bill_date TEXT, due_date TEXT, project_id TEXT, expense_coa_id TEXT, description TEXT, amount_ghs REAL DEFAULT 0, tax_ghs REAL DEFAULT 0, total_ghs REAL DEFAULT 0, amount_paid REAL DEFAULT 0, status TEXT DEFAULT 'Draft', jv_id TEXT, jv_number TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')), posted_at TEXT)");
    $p->exec("CREATE TABLE IF NOT EXISTS ap_bill_lines(id TEXT PRIMARY KEY, bill_id TEXT, line_number INTEGER, description TEXT, expense_coa_id TEXT, amount_ghs REAL DEFAULT 0)");
    $p->exec("CREATE TABLE IF NOT EXISTS ap_payments(id TEXT PRIMARY KEY, payment_number TEXT, bill_id TEXT, vendor_id TEXT, payment_date TEXT, amount_ghs REAL DEFAULT 0, bank_account_id TEXT, payment_method TEXT, reference TEXT, jv_id TEXT, jv_number TEXT, notes TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS ar_receipts(id TEXT PRIMARY KEY, receipt_number TEXT, invoice_id TEXT, customer_id TEXT, receipt_date TEXT, amount_ghs REAL DEFAULT 0, bank_account_id TEXT, payment_method TEXT, reference TEXT, jv_id TEXT, jv_number TEXT, notes TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS ap_recurring(id TEXT PRIMARY KEY, vendor_id TEXT, description TEXT, expense_coa_id TEXT, amount_ghs REAL DEFAULT 0, project_id TEXT, frequency TEXT DEFAULT 'Monthly', start_date TEXT, end_date TEXT, next_due_date TEXT, day_offset INTEGER DEFAULT 0, auto_post INTEGER DEFAULT 0, active INTEGER DEFAULT 1, last_generated TEXT, bills_generated INTEGER DEFAULT 0, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS ar_recurring(id TEXT PRIMARY KEY, customer_id TEXT, description TEXT, income_coa_id TEXT, amount_ghs REAL DEFAULT 0, project_id TEXT, frequency TEXT DEFAULT 'Monthly', start_date TEXT, end_date TEXT, next_due_date TEXT, day_offset INTEGER DEFAULT 0, auto_post INTEGER DEFAULT 0, active INTEGER DEFAULT 1, last_generated TEXT, invoices_generated INTEGER DEFAULT 0, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    ensure_col('ar_invoices', 'unit_id'); ensure_col('ap_bills', 'unit_id');
}
// Advance an ISO date by one period of the given frequency, clamping the day to the
// target month's length (mirror server.py _aprec_advance).
function aprec_advance(?string $iso, ?string $freq): ?string {
    $iso = substr((string)$iso, 0, 10); $ts = strtotime($iso); if ($ts === false) return $iso;
    if ($freq === 'Weekly') return date('Y-m-d', strtotime($iso . ' +7 days'));
    $y = (int)date('Y', $ts); $m = (int)date('n', $ts); $day = (int)date('j', $ts);
    $months = ['Monthly' => 1, 'Quarterly' => 3, 'Semi-Annual' => 6, 'Annually' => 12][$freq] ?? 1;
    $m0 = $m - 1 + $months; $ny = $y + intdiv($m0, 12); $nm = $m0 % 12 + 1;
    $maxd = (int)date('t', mktime(0, 0, 0, $nm, 1, $ny));
    return sprintf('%04d-%02d-%02d', $ny, $nm, min($day, $maxd));
}
function ar_control_coa(): ?array {
    foreach (["code='12300011'", "code='12300001'", "code LIKE '123%'", "(LOWER(account_name) LIKE '%receivable%' OR LOWER(account_name) LIKE '%debtor%')"] as $w) {
        $r = db()->query("SELECT id FROM chart_of_accounts WHERE $w ORDER BY code LIMIT 1")->fetch();
        if ($r) return $r;
    }
    return null;
}
function ap_control_coa(): ?array {
    foreach (["code='21100021'", "code='21100001'", "((LOWER(account_name) LIKE '%creditor%' OR LOWER(account_name) LIKE '%payable%') AND code LIKE '21%')", "code LIKE '211%'"] as $w) {
        $r = db()->query("SELECT id FROM chart_of_accounts WHERE $w ORDER BY code LIMIT 1")->fetch();
        if ($r) return $r;
    }
    return null;
}
function api_ar_customers_list(): void {
    ensure_arap_tables(); ensure_col('ar_customers', 'unit_id'); $u = require_auth();
    [$sw, $sp] = unit_scope_sql($u, 'ar_customers', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT ar_customers.* FROM ar_customers WHERE 1=1$sw ORDER BY customer_name");
    $st->execute($sp); send(['ok' => true, 'customers' => $st->fetchAll()]);
}
function api_ar_customer_save(): void {
    ensure_arap_tables(); ensure_col('ar_customers', 'unit_id'); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['customer_name'])) err('customer_name is required');
    foreach (['email', 'phone', 'tin', 'customer_type'] as $c) ensure_col('ar_customers', $c);
    // Upsert: when an id is supplied and exists, update in place (lets the UI add an
    // email/phone to a debtor); otherwise create a new customer.
    if (!empty($d['id'])) {
        $ex = db()->prepare('SELECT id FROM ar_customers WHERE id=?'); $ex->execute([$d['id']]);
        if ($ex->fetchColumn()) {
            db()->prepare('UPDATE ar_customers SET customer_name=?, email=?, phone=?, tin=?, customer_type=COALESCE(?,customer_type) WHERE id=?')
                ->execute([$d['customer_name'], $d['email'] ?? '', $d['phone'] ?? '', $d['tin'] ?? null, $d['customer_type'] ?? null, $d['id']]);
            ok(['id' => $d['id'], 'updated' => true]);
        }
    }
    $id = $d['id'] ?? uuid4(); $code = $d['customer_code'] ?? seq_code('ar_customers', 'customer_code', 'CUST-', 4);
    $unit = resolve_write_unit($u, $d);
    db()->prepare('INSERT INTO ar_customers(id,customer_code,customer_name,customer_type,email,phone,tin,created_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $code, $d['customer_name'], $d['customer_type'] ?? 'Customer', $d['email'] ?? '', $d['phone'] ?? '', $d['tin'] ?? null, $u['username'], $unit]);
    ok(['id' => $id, 'customer_code' => $code]);
}
function api_ar_invoices_list(): void {
    ensure_arap_tables(); $u = require_auth();
    [$sw, $sp] = unit_scope_sql($u, 'ar_invoices', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    ensure_col('ar_invoices', 'amount_received', 'REAL');
    $st = db()->prepare("SELECT ar_invoices.*, ROUND(COALESCE(total_ghs,0)-COALESCE(amount_received,0),2) AS balance_ghs FROM ar_invoices WHERE 1=1$sw ORDER BY created_at DESC");
    $st->execute($sp); send(['ok' => true, 'invoices' => $st->fetchAll()]);
}
function api_ar_invoice_save(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['customer_id'])) err('customer_id is required');
    // Accept either a multi-line invoice (lines[]) — the shape the UI/gate sends — or a
    // single top-level income_coa_id/amount_ghs (legacy).
    $ilines = (isset($d['lines']) && is_array($d['lines']) && $d['lines']) ? $d['lines'] : null;
    if ($ilines) {
        $first_coa = $ilines[0]['income_coa_id'] ?? null;
        if (!$first_coa) err('Each invoice line needs an income_coa_id');
        $amount = 0.0; foreach ($ilines as $l) $amount += (float)($l['amount_ghs'] ?? 0);
        $amount = round($amount, 2);
    } else {
        if (empty($d['income_coa_id'])) err('income_coa_id is required');
        $first_coa = $d['income_coa_id'];
        $amount = round((float)($d['amount_ghs'] ?? 0), 2);
        $ilines = [['income_coa_id' => $first_coa, 'amount_ghs' => $amount, 'description' => $d['description'] ?? '']];
    }
    $tax = round((float)($d['tax_ghs'] ?? 0), 2); $total = round($amount + $tax, 2);
    if ($amount <= 0) err('Invoice amount must be greater than zero');
    require_write_unit($u, $d, 'AR invoice');
    $id = uuid4(); $num = $d['invoice_number'] ?? seq_code('ar_invoices', 'invoice_number', 'INV-', 4); $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO ar_invoices(id,invoice_number,customer_id,invoice_date,due_date,project_id,income_coa_id,description,amount_ghs,tax_ghs,total_ghs,status,created_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,'Draft',?,?)")
        ->execute([$id, $num, $d['customer_id'], $d['invoice_date'] ?? date('Y-m-d'), $d['due_date'] ?? null, $d['project_id'] ?? null, $first_coa, $d['description'] ?? '', $amount, $tax, $total, $u['username'], $unit]);
    $ln = 0; $ili = db()->prepare('INSERT INTO ar_invoice_lines(id,invoice_id,line_number,description,income_coa_id,amount_ghs) VALUES(?,?,?,?,?,?)');
    foreach ($ilines as $l) { $ln++; $ili->execute([uuid4(), $id, $ln, $l['description'] ?? '', $l['income_coa_id'], round((float)($l['amount_ghs'] ?? 0), 2)]); }
    ok(['id' => $id, 'invoice_number' => $num]);
}
function api_ar_invoice_post(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $iid = (string)($d['id'] ?? ($d['invoice_id'] ?? '')); if ($iid === '') err('id is required');
    $st = db()->prepare('SELECT * FROM ar_invoices WHERE id=?'); $st->execute([$iid]); $inv = $st->fetch();
    if (!$inv) err('Invoice not found'); if (($inv['status'] ?? '') !== 'Draft') err('Invoice is already posted');
    $ar = ar_control_coa(); if (!$ar) err('No receivables control account (123xxxxx) in the chart of accounts');
    $amount = round((float)$inv['amount_ghs'], 2); $tax = round((float)$inv['tax_ghs'], 2); $total = round($amount + $tax, 2);
    $lines = [['coa_id' => $ar['id'], 'debit_amount' => $total, 'credit_amount' => 0, 'description' => 'Invoice ' . $inv['invoice_number'], 'project_id' => $inv['project_id']]];
    $il = db()->prepare('SELECT * FROM ar_invoice_lines WHERE invoice_id=? ORDER BY line_number'); $il->execute([$iid]); $ils = $il->fetchAll();
    if ($ils) foreach ($ils as $l) $lines[] = ['coa_id' => $l['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => round((float)$l['amount_ghs'], 2), 'description' => $l['description'] ?? '', 'project_id' => $inv['project_id']];
    else $lines[] = ['coa_id' => $inv['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => 'Income', 'project_id' => $inv['project_id']];
    if ($tax > 0) { $vat = get_coa(['21100024']); if (!$vat) err('Invoice has tax but no output VAT account'); $lines[] = ['coa_id' => $vat['id'], 'debit_amount' => 0, 'credit_amount' => $tax, 'description' => 'Output VAT', 'project_id' => $inv['project_id']]; }
    try { [$jid, $jvnum] = post_journal($u, 'JV', (string)($inv['invoice_date'] ?? date('Y-m-d')), substr((string)($inv['invoice_date'] ?? date('Y-m-d')), 0, 7), 'AR Invoice ' . $inv['invoice_number'], $lines, 'ar_invoices', $iid, ($inv['unit_id'] ?? null) ?: resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Could not post invoice: ' . $e->getMessage()); }
    db()->prepare("UPDATE ar_invoices SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $iid]);
    ok(['id' => $iid, 'jv_number' => $jvnum, 'status' => 'Posted', 'total' => $total]);
}
// GET /api/ar/invoices/lines?invoice_id=… — invoice line detail, scoped via the
// parent invoice's unit so a scoped user cannot read another unit's invoice lines.
function api_ar_invoice_lines(): void {
    ensure_arap_tables(); $u = require_auth();
    $iid = (string)($_GET['invoice_id'] ?? ($_GET['id'] ?? '')); if ($iid === '') err('invoice_id is required');
    [$sw, $sp] = unit_scope_sql($u, 'ar_invoices', null);
    $chk = db()->prepare("SELECT 1 FROM ar_invoices WHERE id=?$sw"); $chk->execute(array_merge([$iid], $sp));
    if (!$chk->fetch()) err('Invoice not found', 404);
    $st = db()->prepare("SELECT l.*, c.account_name AS income_account, c.code AS income_code FROM ar_invoice_lines l LEFT JOIN chart_of_accounts c ON c.id=l.income_coa_id WHERE l.invoice_id=? ORDER BY l.line_number");
    $st->execute([$iid]); send(['ok' => true, 'lines' => $st->fetchAll()]);
}
// GET /api/ar/customers/statement?customer_id=… — customer statement (its invoices),
// unit-scoped (a scoped user only sees invoices within its scope).
function api_ar_customer_statement(): void {
    ensure_arap_tables(); $u = require_auth();
    $cid = (string)($_GET['customer_id'] ?? ($_GET['id'] ?? '')); if ($cid === '') err('customer_id is required');
    [$sw, $sp] = unit_scope_sql($u, 'ar_invoices', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT ar_invoices.* FROM ar_invoices WHERE customer_id=?$sw ORDER BY invoice_date, invoice_number");
    $st->execute(array_merge([$cid], $sp)); $rows = $st->fetchAll();
    $billed = 0.0; $received = 0.0;
    foreach ($rows as $r) { $billed += (float)($r['total_ghs'] ?? 0); $received += (float)($r['amount_received'] ?? 0); }
    ok(['customer_id' => $cid, 'invoices' => $rows,
        'total_billed' => round($billed, 2), 'total_received' => round($received, 2),
        'balance' => round($billed - $received, 2)]);
}
function api_ap_bills_list(): void {
    ensure_arap_tables(); $u = require_auth();
    [$sw, $sp] = unit_scope_sql($u, 'ap_bills', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    ensure_col('ap_bills', 'amount_paid', 'REAL');
    $st = db()->prepare("SELECT ap_bills.*, ROUND(COALESCE(total_ghs,0)-COALESCE(amount_paid,0),2) AS balance_ghs FROM ap_bills WHERE 1=1$sw ORDER BY created_at DESC");
    $st->execute($sp); send(['ok' => true, 'bills' => $st->fetchAll()]);
}
function api_ap_bill_save(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['vendor_id'])) err('vendor_id is required');
    // Accept either a multi-line bill (lines[]) — the shape the UI/gate sends — or a
    // single top-level expense_coa_id/amount_ghs (legacy).
    $blines = (isset($d['lines']) && is_array($d['lines']) && $d['lines']) ? $d['lines'] : null;
    if ($blines) {
        $first_coa = $blines[0]['expense_coa_id'] ?? null;
        if (!$first_coa) err('Each bill line needs an expense_coa_id');
        $amount = 0.0; foreach ($blines as $l) $amount += (float)($l['amount_ghs'] ?? 0);
        $amount = round($amount, 2);
    } else {
        if (empty($d['expense_coa_id'])) err('expense_coa_id is required');
        $first_coa = $d['expense_coa_id'];
        $amount = round((float)($d['amount_ghs'] ?? 0), 2);
        $blines = [['expense_coa_id' => $first_coa, 'amount_ghs' => $amount, 'description' => $d['description'] ?? '']];
    }
    $tax = round((float)($d['tax_ghs'] ?? 0), 2); $total = round($amount + $tax, 2);
    if ($amount <= 0) err('Bill amount must be greater than zero');
    require_write_unit($u, $d, 'AP bill');
    $id = uuid4(); $num = $d['bill_number'] ?? seq_code('ap_bills', 'bill_number', 'BILL-', 4); $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO ap_bills(id,bill_number,vendor_invoice_no,vendor_id,bill_date,due_date,project_id,expense_coa_id,description,amount_ghs,tax_ghs,total_ghs,status,created_by,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'Draft',?,?)")
        ->execute([$id, $num, $d['vendor_invoice_no'] ?? '', $d['vendor_id'], $d['bill_date'] ?? date('Y-m-d'), $d['due_date'] ?? null, $d['project_id'] ?? null, $first_coa, $d['description'] ?? '', $amount, $tax, $total, $u['username'], $unit]);
    $ln = 0; $bli = db()->prepare('INSERT INTO ap_bill_lines(id,bill_id,line_number,description,expense_coa_id,amount_ghs) VALUES(?,?,?,?,?,?)');
    foreach ($blines as $l) { $ln++; $bli->execute([uuid4(), $id, $ln, $l['description'] ?? '', $l['expense_coa_id'], round((float)($l['amount_ghs'] ?? 0), 2)]); }
    ok(['id' => $id, 'bill_number' => $num]);
}
function api_ap_bill_post(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $bid = (string)($d['id'] ?? ($d['bill_id'] ?? '')); if ($bid === '') err('id is required');
    $st = db()->prepare('SELECT * FROM ap_bills WHERE id=?'); $st->execute([$bid]); $bill = $st->fetch();
    if (!$bill) err('Bill not found'); if (($bill['status'] ?? '') !== 'Draft') err('Bill is already posted');
    $ap = ap_control_coa(); if (!$ap) err('No payables control account (21100021) in the chart of accounts');
    $total = round((float)$bill['total_ghs'], 2);
    $lines = [];
    $bl = db()->prepare('SELECT * FROM ap_bill_lines WHERE bill_id=? ORDER BY line_number'); $bl->execute([$bid]); $bls = $bl->fetchAll();
    if ($bls) foreach ($bls as $l) $lines[] = ['coa_id' => $l['expense_coa_id'], 'debit_amount' => round((float)$l['amount_ghs'], 2), 'credit_amount' => 0, 'description' => $l['description'] ?? '', 'project_id' => $bill['project_id']];
    else $lines[] = ['coa_id' => $bill['expense_coa_id'], 'debit_amount' => $total, 'credit_amount' => 0, 'description' => 'Expense', 'project_id' => $bill['project_id']];
    $lines[] = ['coa_id' => $ap['id'], 'debit_amount' => 0, 'credit_amount' => $total, 'description' => 'Bill ' . $bill['bill_number'], 'project_id' => $bill['project_id']];
    try { [$jid, $jvnum] = post_journal($u, 'JV', (string)($bill['bill_date'] ?? date('Y-m-d')), substr((string)($bill['bill_date'] ?? date('Y-m-d')), 0, 7), 'AP Bill ' . $bill['bill_number'], $lines, 'ap_bills', $bid, ($bill['unit_id'] ?? null) ?: resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Could not post bill: ' . $e->getMessage()); }
    db()->prepare("UPDATE ap_bills SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $bid]);
    ok(['id' => $bid, 'jv_number' => $jvnum, 'status' => 'Posted', 'total' => $total]);
}

// ── AR receipt (Dr Bank / Cr Receivables) + AP payment (Dr Payables / Cr Bank) ──
function bank_coa_from_account(?string $bank_account_id): ?string {
    if ($bank_account_id) {
        $bk = db()->prepare('SELECT coa_id FROM bank_accounts WHERE id=?'); $bk->execute([$bank_account_id]);
        $c = $bk->fetchColumn(); if ($c) return (string)$c;
    }
    $b = operating_bank_coa(); return $b['id'] ?? null;
}
function api_ar_receipt(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    ensure_col('ar_invoices', 'amount_received', 'REAL');
    $iid = (string)($d['invoice_id'] ?? ($d['id'] ?? '')); if ($iid === '') err('invoice_id is required');
    $amt = round((float)($d['amount_ghs'] ?? 0), 2); if ($amt <= 0) err('Receipt amount must be greater than zero');
    $st = db()->prepare('SELECT * FROM ar_invoices WHERE id=?'); $st->execute([$iid]); $inv = $st->fetch();
    if (!$inv) err('Invoice not found');
    if (($inv['status'] ?? '') === 'Draft') err('Post the invoice before receipting against it');
    $rdate = (string)($d['receipt_date'] ?? date('Y-m-d'));
    if (!empty($inv['invoice_date']) && substr($rdate, 0, 10) < substr((string)$inv['invoice_date'], 0, 10)) err('Receipt date cannot be earlier than the invoice date (' . substr((string)$inv['invoice_date'], 0, 10) . ')');
    $total = round((float)$inv['total_ghs'], 2); $recd = round((float)($inv['amount_received'] ?? 0), 2); $bal = round($total - $recd, 2);
    if ($amt > $bal + 0.01) err('Receipt exceeds the outstanding balance of GHS ' . number_format($bal, 2));
    $bcoa = bank_coa_from_account((string)($d['bank_account_id'] ?? '')); if (!$bcoa) err('Bank account could not be resolved');
    $ar = ar_control_coa(); if (!$ar) err('No receivables control account in the chart of accounts');
    $date = (string)($d['receipt_date'] ?? date('Y-m-d'));
    $lines = [['coa_id' => $bcoa, 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => 'Receipt ' . ($inv['invoice_number'] ?? ''), 'project_id' => $inv['project_id']],
              ['coa_id' => $ar['id'], 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'AR cleared ' . ($inv['invoice_number'] ?? ''), 'project_id' => $inv['project_id']]];
    try { [$jid, $jvnum] = post_journal($u, 'RV', $date, substr($date, 0, 7), 'AR receipt ' . ($inv['invoice_number'] ?? ''), $lines, 'ar_receipts', $iid, $inv['unit_id'] ?? null); }
    catch (Throwable $e) { err('Receipt posting failed: ' . $e->getMessage()); }
    $newrecd = round($recd + $amt, 2); $newbal = round($total - $newrecd, 2); $status = $newbal <= 0.01 ? 'Paid' : 'Part-Paid';
    db()->prepare('UPDATE ar_invoices SET amount_received=?, status=? WHERE id=?')->execute([$newrecd, $status, $iid]);
    ok(['jv_number' => $jvnum, 'status' => $status, 'balance_ghs' => $newbal, 'amount_received' => $newrecd]);
}
function api_ap_payment(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    ensure_col('ap_bills', 'amount_paid', 'REAL');
    $bid = (string)($d['bill_id'] ?? ($d['id'] ?? '')); if ($bid === '') err('bill_id is required');
    $amt = round((float)($d['amount_ghs'] ?? 0), 2); if ($amt <= 0) err('Payment amount must be greater than zero');
    $st = db()->prepare('SELECT * FROM ap_bills WHERE id=?'); $st->execute([$bid]); $bill = $st->fetch();
    if (!$bill) err('Bill not found');
    if (($bill['status'] ?? '') === 'Draft') err('Post the bill before paying it');
    $pdate = (string)($d['payment_date'] ?? date('Y-m-d'));
    if (!empty($bill['bill_date']) && substr($pdate, 0, 10) < substr((string)$bill['bill_date'], 0, 10)) err('Payment date cannot be earlier than the bill date (' . substr((string)$bill['bill_date'], 0, 10) . ')');
    $total = round((float)$bill['total_ghs'], 2); $paid = round((float)($bill['amount_paid'] ?? 0), 2); $bal = round($total - $paid, 2);
    if ($amt > $bal + 0.01) err('Payment exceeds the outstanding balance of GHS ' . number_format($bal, 2));
    $bcoa = bank_coa_from_account((string)($d['bank_account_id'] ?? '')); if (!$bcoa) err('Bank account could not be resolved');
    $ap = ap_control_coa(); if (!$ap) err('No payables control account in the chart of accounts');
    $date = (string)($d['payment_date'] ?? date('Y-m-d'));
    $lines = [['coa_id' => $ap['id'], 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => 'AP paid ' . ($bill['bill_number'] ?? ''), 'project_id' => $bill['project_id']],
              ['coa_id' => $bcoa, 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Payment ' . ($bill['bill_number'] ?? ''), 'project_id' => $bill['project_id']]];
    try { [$jid, $jvnum] = post_journal($u, 'PV', $date, substr($date, 0, 7), 'AP payment ' . ($bill['bill_number'] ?? ''), $lines, 'ap_payments', $bid, $bill['unit_id'] ?? null); }
    catch (Throwable $e) { err('Payment posting failed: ' . $e->getMessage()); }
    $newpaid = round($paid + $amt, 2); $newbal = round($total - $newpaid, 2); $status = $newbal <= 0.01 ? 'Paid' : 'Part-Paid';
    db()->prepare('UPDATE ap_bills SET amount_paid=?, status=? WHERE id=?')->execute([$newpaid, $status, $bid]);
    ok(['jv_number' => $jvnum, 'status' => $status, 'balance_ghs' => $newbal, 'amount_paid' => $newpaid]);
}

// Pay several posted/part-paid bills in one PV (Dr Payables per bill, Cr Bank total),
// record an ap_payments row per bill, and advance each bill's paid/status. Returns the
// run's jv_number, which feeds the payment-run bank file below.
function api_ap_batch_pay(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    ensure_col('ap_bills', 'amount_paid', 'REAL');
    $items = (isset($d['items']) && is_array($d['items']) && $d['items']) ? $d['items']
        : array_map(fn($b) => ['bill_id' => $b], (array)($d['bill_ids'] ?? []));
    if (!$items) err('Select at least one bill to pay');
    $bankId = (string)($d['bank_account_id'] ?? ''); if ($bankId === '') err('Select the bank account to pay from');
    $bcoa = bank_coa_from_account($bankId); $ap = ap_control_coa();
    if (!$bcoa || !$ap) err('Bank or payables control account could not be resolved');
    $pdate = (string)($d['payment_date'] ?? date('Y-m-d'));
    $ref = trim((string)($d['reference'] ?? '')) ?: ('Batch payment ' . $pdate);
    $plans = []; $total = 0.0;
    foreach ($items as $it) {
        $bid = (string)($it['bill_id'] ?? ($it['id'] ?? '')); if ($bid === '') continue;
        $st = db()->prepare('SELECT * FROM ap_bills WHERE id=?'); $st->execute([$bid]); $bill = $st->fetch();
        if (!$bill) err('Bill not found: ' . $bid);
        if (!in_array($bill['status'] ?? '', ['Posted', 'Part-Paid'], true)) continue;
        $bal = round((float)$bill['total_ghs'] - (float)($bill['amount_paid'] ?? 0), 2);
        $amt = isset($it['amount_ghs']) ? round((float)$it['amount_ghs'], 2) : $bal;
        if ($amt <= 0) continue;
        if ($amt > $bal + 0.01) err(sprintf('Payment GHS %.2f exceeds balance GHS %.2f on %s', $amt, $bal, $bill['bill_number']));
        $plans[] = [$bill, $amt]; $total += $amt;
    }
    $total = round($total, 2);
    if (!$plans) err('No payable bills selected (only posted/part-paid bills can be paid)');
    $lines = [];
    foreach ($plans as [$bill, $amt]) $lines[] = ['coa_id' => $ap['id'], 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => 'Payment ' . $bill['bill_number'], 'project_id' => $bill['project_id']];
    $lines[] = ['coa_id' => $bcoa, 'debit_amount' => 0, 'credit_amount' => $total, 'description' => $ref, 'project_id' => null];
    try { [$jid, $jvnum] = post_journal($u, 'PV', $pdate, substr($pdate, 0, 7), 'Batch supplier payment (' . count($plans) . ' bills)', $lines, 'ap_payments', 'batch', resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Could not post batch payment: ' . $e->getMessage()); }
    $results = [];
    $ins = db()->prepare('INSERT INTO ap_payments(id,payment_number,bill_id,vendor_id,payment_date,amount_ghs,bank_account_id,payment_method,reference,jv_id,jv_number,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($plans as [$bill, $amt]) {
        $pnum = seq_code('ap_payments', 'payment_number', 'APP-', 4);
        $ins->execute([uuid4(), $pnum, $bill['id'], $bill['vendor_id'], $pdate, $amt, $bankId, 'Batch', $ref, $jid, $jvnum, 'batch', $u['username']]);
        $newpaid = round((float)($bill['amount_paid'] ?? 0) + $amt, 2);
        $status = $newpaid >= (float)$bill['total_ghs'] - 0.01 ? 'Paid' : 'Part-Paid';
        db()->prepare('UPDATE ap_bills SET amount_paid=?, status=? WHERE id=?')->execute([$newpaid, $status, $bill['id']]);
        $results[] = ['bill_number' => $bill['bill_number'], 'amount' => $amt, 'status' => $status];
    }
    ok(['count' => count($plans), 'total' => $total, 'jv_number' => $jvnum, 'results' => $results,
        'message' => sprintf('Paid %d bill(s) as %s, total GHS %.2f', count($plans), $jvnum, $total)]);
}

// Receipt several posted/part-paid invoices in one RV (Dr Bank total, Cr Receivables
// per invoice), record an ar_receipts row per invoice, and advance each invoice's
// received/status. Overpayment past the outstanding balance is rejected.
function api_ar_batch_receipt(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    ensure_col('ar_invoices', 'amount_received', 'REAL');
    $items = (isset($d['items']) && is_array($d['items']) && $d['items']) ? $d['items']
        : array_map(fn($i) => ['invoice_id' => $i], (array)($d['invoice_ids'] ?? []));
    if (!$items) err('Select at least one invoice to receipt');
    $bankId = (string)($d['bank_account_id'] ?? ''); if ($bankId === '') err('Select the bank account receiving payment');
    $bcoa = bank_coa_from_account($bankId); $ar = ar_control_coa();
    if (!$bcoa || !$ar) err('Bank or receivables control account could not be resolved');
    $rdate = (string)($d['receipt_date'] ?? date('Y-m-d'));
    $ref = trim((string)($d['reference'] ?? '')) ?: ('Batch receipt ' . $rdate);
    $plans = []; $total = 0.0;
    foreach ($items as $it) {
        $iid = (string)($it['invoice_id'] ?? ($it['id'] ?? '')); if ($iid === '') continue;
        $st = db()->prepare('SELECT * FROM ar_invoices WHERE id=?'); $st->execute([$iid]); $inv = $st->fetch();
        if (!$inv) err('Invoice not found: ' . $iid);
        if (!in_array($inv['status'] ?? '', ['Posted', 'Part-Paid'], true)) continue;
        $bal = round((float)$inv['total_ghs'] - (float)($inv['amount_received'] ?? 0), 2);
        $amt = isset($it['amount_ghs']) ? round((float)$it['amount_ghs'], 2) : $bal;
        if ($amt <= 0) continue;
        if ($amt > $bal + 0.01) err(sprintf('Receipt GHS %.2f exceeds balance GHS %.2f on %s', $amt, $bal, $inv['invoice_number']));
        $plans[] = [$inv, $amt]; $total += $amt;
    }
    $total = round($total, 2);
    if (!$plans) err('No receivable invoices selected (only posted/part-paid invoices can be receipted)');
    $lines = [['coa_id' => $bcoa, 'debit_amount' => $total, 'credit_amount' => 0, 'description' => $ref, 'project_id' => null]];
    foreach ($plans as [$inv, $amt]) $lines[] = ['coa_id' => $ar['id'], 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Receipt ' . $inv['invoice_number'], 'project_id' => $inv['project_id']];
    try { [$jid, $jvnum] = post_journal($u, 'RV', $rdate, substr($rdate, 0, 7), 'Batch customer receipt (' . count($plans) . ' invoices)', $lines, 'ar_receipts', 'batch', resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Could not post batch receipt: ' . $e->getMessage()); }
    $results = [];
    $ins = db()->prepare('INSERT INTO ar_receipts(id,receipt_number,invoice_id,customer_id,receipt_date,amount_ghs,bank_account_id,payment_method,reference,jv_id,jv_number,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($plans as [$inv, $amt]) {
        $rnum = seq_code('ar_receipts', 'receipt_number', 'ARR-', 4);
        $ins->execute([uuid4(), $rnum, $inv['id'], $inv['customer_id'], $rdate, $amt, $bankId, 'Batch', $ref, $jid, $jvnum, 'batch', $u['username']]);
        $newrecd = round((float)($inv['amount_received'] ?? 0) + $amt, 2);
        $status = $newrecd >= (float)$inv['total_ghs'] - 0.01 ? 'Paid' : 'Part-Paid';
        db()->prepare('UPDATE ar_invoices SET amount_received=?, status=? WHERE id=?')->execute([$newrecd, $status, $inv['id']]);
        $results[] = ['invoice_number' => $inv['invoice_number'], 'amount' => $amt, 'status' => $status];
    }
    ok(['count' => count($plans), 'total' => $total, 'jv_number' => $jvnum, 'results' => $results,
        'message' => sprintf('Receipted %d invoice(s) as %s, total GHS %.2f', count($plans), $jvnum, $total)]);
}

// Bank transfer instruction for one payment run: per-vendor rows with bank details
// from the vendor master + a bank-upload CSV (base64, UTF-8 BOM). Flags any vendor
// missing bank_name/account_number so finance can complete the master first.
function api_payment_run_file(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $jvn = trim((string)($d['jv_number'] ?? '')); if ($jvn === '') err('jv_number of the payment run is required');
    foreach (['bank_name', 'account_name', 'account_number', 'email'] as $c) ensure_col('vendors', $c);
    $st = db()->prepare(
        "SELECT p.*, v.vendor_name, COALESCE(v.bank_name,'') AS v_bank, COALESCE(v.account_name,'') AS v_acct_name, " .
        "COALESCE(v.account_number,'') AS v_acct_no, COALESCE(v.email,'') AS v_email, " .
        "COALESCE(b.bill_number,'') AS bill_number, COALESCE(b.vendor_invoice_no,'') AS vendor_invoice_no " .
        "FROM ap_payments p LEFT JOIN vendors v ON v.id=p.vendor_id " .
        "LEFT JOIN ap_bills b ON b.id=p.bill_id WHERE p.jv_number=? ORDER BY v.vendor_name");
    $st->execute([$jvn]); $pays = $st->fetchAll();
    if (!$pays) err('No payments found for run ' . $jvn);
    $byv = [];
    foreach ($pays as $p) {
        $vid = (string)($p['vendor_id'] ?? '');
        if (!isset($byv[$vid])) $byv[$vid] = ['vendor_name' => $p['vendor_name'] ?: '(unknown vendor)',
            'bank_name' => $p['v_bank'], 'account_name' => ($p['v_acct_name'] ?: $p['vendor_name']),
            'account_number' => $p['v_acct_no'], 'email' => $p['v_email'], 'amount_ghs' => 0.0, 'bills' => []];
        $byv[$vid]['amount_ghs'] = round($byv[$vid]['amount_ghs'] + (float)($p['amount_ghs'] ?? 0), 2);
        $byv[$vid]['bills'][] = ['bill_number' => $p['bill_number'], 'vendor_invoice_no' => $p['vendor_invoice_no'],
            'amount_ghs' => round((float)($p['amount_ghs'] ?? 0), 2), 'payment_number' => $p['payment_number']];
    }
    $rows = array_values($byv); usort($rows, fn($a, $b) => strcmp($a['vendor_name'], $b['vendor_name']));
    $missing = []; foreach ($rows as $r) if (!($r['bank_name'] && $r['account_number'])) $missing[] = $r['vendor_name'];
    $esc = fn($x) => '"' . str_replace('"', '""', (string)$x) . '"';
    $csv = "Beneficiary Name,Bank,Account Name,Account Number,Amount (GHS),Narration\r\n";
    foreach ($rows as $r) $csv .= implode(',', [$esc($r['vendor_name']), $esc($r['bank_name']), $esc($r['account_name']),
        $esc($r['account_number']), $esc(number_format($r['amount_ghs'], 2, '.', '')), $esc('Payment run ' . $jvn)]) . "\r\n";
    $csv_b64 = base64_encode("\xEF\xBB\xBF" . $csv);
    $total = round(array_sum(array_map(fn($r) => $r['amount_ghs'], $rows)), 2);
    ok(['jv_number' => $jvn, 'rows' => $rows, 'total_ghs' => $total, 'beneficiaries' => count($rows),
        'csv_b64' => $csv_b64, 'filename' => 'bank_schedule_' . str_replace('/', '-', $jvn) . '.csv',
        'missing_bank_details' => $missing, 'remittance_emailed' => 0]);
}

// ── Recurring AP bills / AR invoices — standing templates that generate documents
//    on a schedule. generate() creates one Draft per missed period up to as_of
//    (optionally auto-posting), then advances next_due_date. toggle() pauses/resumes;
//    paused templates are skipped by generate.
function api_ap_recurring_list(): void {
    ensure_arap_tables(); require_auth();
    $rows = db()->query("SELECT t.*, v.vendor_name, v.vendor_code, coa.account_name AS expense_account, coa.code AS expense_code, p.project_code
        FROM ap_recurring t LEFT JOIN vendors v ON v.id=t.vendor_id
        LEFT JOIN chart_of_accounts coa ON coa.id=t.expense_coa_id
        LEFT JOIN projects p ON p.id=t.project_id ORDER BY t.active DESC, t.next_due_date")->fetchAll();
    ok(['templates' => $rows]);
}
function api_ap_save_recurring(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['vendor_id'])) err('Select a vendor');
    if (empty($d['expense_coa_id'])) err('Select the expense account to debit');
    $amount = round((float)($d['amount_ghs'] ?? 0), 2); if ($amount <= 0) err('Amount must be greater than zero');
    $freq = $d['frequency'] ?? 'Monthly'; $start = $d['start_date'] ?? date('Y-m-d');
    $tid = $d['id'] ?? uuid4();
    $ex = db()->prepare('SELECT next_due_date FROM ap_recurring WHERE id=?'); $ex->execute([$tid]); $exrow = $ex->fetch();
    $next = $d['next_due_date'] ?? (($exrow['next_due_date'] ?? null) ?: $start);
    $offset = (int)($d['day_offset'] ?? 0); $auto = !empty($d['auto_post']) ? 1 : 0;
    $active = in_array($d['active'] ?? 1, [0, '0', false, 'false'], true) ? 0 : 1;
    if ($exrow) {
        db()->prepare("UPDATE ap_recurring SET vendor_id=?,description=?,expense_coa_id=?,amount_ghs=?,project_id=?,frequency=?,start_date=?,end_date=?,next_due_date=?,day_offset=?,auto_post=?,active=? WHERE id=?")
            ->execute([$d['vendor_id'], $d['description'] ?? '', $d['expense_coa_id'], $amount, $d['project_id'] ?? null, $freq, $start, $d['end_date'] ?? null, $next, $offset, $auto, $active, $tid]);
    } else {
        db()->prepare("INSERT INTO ap_recurring(id,vendor_id,description,expense_coa_id,amount_ghs,project_id,frequency,start_date,end_date,next_due_date,day_offset,auto_post,active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid, $d['vendor_id'], $d['description'] ?? '', $d['expense_coa_id'], $amount, $d['project_id'] ?? null, $freq, $start, $d['end_date'] ?? null, $next, $offset, $auto, $active, $u['username']]);
    }
    ok(['id' => $tid]);
}
function api_ap_recurring_toggle(): void {
    ensure_arap_tables(); require_role(['Admin', 'Finance Officer']); $d = body();
    $tid = (string)($d['id'] ?? ''); $st = db()->prepare('SELECT active FROM ap_recurring WHERE id=?'); $st->execute([$tid]); $t = $st->fetch();
    if (!$t) err('Template not found');
    $new = $t['active'] ? 0 : 1; db()->prepare('UPDATE ap_recurring SET active=? WHERE id=?')->execute([$new, $tid]);
    ok(['id' => $tid, 'active' => $new]);
}
function api_ap_recurring_generate(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $asof = (string)($d['as_of'] ?? date('Y-m-d')); $onlyId = $d['id'] ?? null;
    $ap = ap_control_coa(); if (!$ap) err('No payables control account (21100021) found');
    $q = "SELECT * FROM ap_recurring WHERE active=1 AND next_due_date IS NOT NULL AND next_due_date<=?"; $params = [$asof];
    if ($onlyId) { $q .= ' AND id=?'; $params[] = $onlyId; }
    $st = db()->prepare($q); $st->execute($params); $templates = $st->fetchAll();
    $generated = [];
    foreach ($templates as $t) {
        $guard = 0; $nd = $t['next_due_date'];
        while ($nd && substr((string)$nd, 0, 10) <= substr($asof, 0, 10) && $guard < 60) {
            if (!empty($t['end_date']) && substr((string)$nd, 0, 10) > substr((string)$t['end_date'], 0, 10)) break;
            $guard++; $amount = round((float)($t['amount_ghs'] ?? 0), 2);
            $bid = uuid4(); $num = seq_code('ap_bills', 'bill_number', 'BILL-', 4);
            $offset = (int)($t['day_offset'] ?? 0); $due = date('Y-m-d', strtotime(substr((string)$nd, 0, 10) . " +$offset days")) ?: $nd;
            $desc = ($t['description'] ?: 'Recurring bill') . ' (' . substr((string)$nd, 0, 10) . ')';
            db()->prepare("INSERT INTO ap_bills(id,bill_number,vendor_invoice_no,vendor_id,bill_date,due_date,project_id,expense_coa_id,description,amount_ghs,tax_ghs,total_ghs,amount_paid,status,created_by) VALUES(?,?,'',?,?,?,?,?,?,?,0,?,0,'Draft',?)")
                ->execute([$bid, $num, $t['vendor_id'], $nd, $due, $t['project_id'] ?? null, $t['expense_coa_id'], $desc, $amount, $amount, $u['username']]);
            db()->prepare('INSERT INTO ap_bill_lines(id,bill_id,line_number,description,expense_coa_id,amount_ghs) VALUES(?,?,1,?,?,?)')->execute([uuid4(), $bid, $desc, $t['expense_coa_id'], $amount]);
            $jvnum = null;
            if (!empty($t['auto_post'])) {
                $lines = [['coa_id' => $t['expense_coa_id'], 'debit_amount' => $amount, 'credit_amount' => 0, 'description' => $desc, 'project_id' => $t['project_id'] ?? null],
                          ['coa_id' => $ap['id'], 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => 'Bill ' . $num, 'project_id' => $t['project_id'] ?? null]];
                try { [$jid, $jvnum] = post_journal($u, 'JV', (string)$nd, substr((string)$nd, 0, 7), 'Bill ' . $num, $lines, 'ap_bills', $bid, null);
                    db()->prepare("UPDATE ap_bills SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $bid]); }
                catch (Throwable $e) { $generated[] = ['template_id' => $t['id'], 'bill_number' => $num, 'due' => $nd, 'amount' => $amount, 'posted' => false, 'note' => $e->getMessage()]; $nd = null; break; }
            }
            $generated[] = ['template_id' => $t['id'], 'bill_number' => $num, 'due' => $nd, 'amount' => $amount, 'posted' => (bool)$jvnum, 'jv_number' => $jvnum];
            $nd = aprec_advance($nd, $t['frequency']);
        }
        if ($nd) db()->prepare('UPDATE ap_recurring SET next_due_date=?, last_generated=?, bills_generated=bills_generated+? WHERE id=?')->execute([$nd, $asof, $guard, $t['id']]);
        else db()->prepare('UPDATE ap_recurring SET last_generated=?, bills_generated=bills_generated+? WHERE id=?')->execute([$asof, $guard, $t['id']]);
    }
    ok(['generated' => $generated, 'count' => count($generated)]);
}
function api_ar_recurring_list(): void {
    ensure_arap_tables(); require_auth();
    $rows = db()->query("SELECT t.*, c.customer_name, c.customer_code, coa.account_name AS income_account, coa.code AS income_code, p.project_code
        FROM ar_recurring t LEFT JOIN ar_customers c ON c.id=t.customer_id
        LEFT JOIN chart_of_accounts coa ON coa.id=t.income_coa_id
        LEFT JOIN projects p ON p.id=t.project_id ORDER BY t.active DESC, t.next_due_date")->fetchAll();
    ok(['templates' => $rows]);
}
function api_ar_save_recurring(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['customer_id'])) err('Select a customer');
    if (empty($d['income_coa_id'])) err('Select the income account to credit');
    $amount = round((float)($d['amount_ghs'] ?? 0), 2); if ($amount <= 0) err('Amount must be greater than zero');
    $freq = $d['frequency'] ?? 'Monthly'; $start = $d['start_date'] ?? date('Y-m-d');
    $tid = $d['id'] ?? uuid4();
    $ex = db()->prepare('SELECT next_due_date FROM ar_recurring WHERE id=?'); $ex->execute([$tid]); $exrow = $ex->fetch();
    $next = $d['next_due_date'] ?? (($exrow['next_due_date'] ?? null) ?: $start);
    $offset = (int)($d['day_offset'] ?? 0); $auto = !empty($d['auto_post']) ? 1 : 0;
    $active = in_array($d['active'] ?? 1, [0, '0', false, 'false'], true) ? 0 : 1;
    if ($exrow) {
        db()->prepare("UPDATE ar_recurring SET customer_id=?,description=?,income_coa_id=?,amount_ghs=?,project_id=?,frequency=?,start_date=?,end_date=?,next_due_date=?,day_offset=?,auto_post=?,active=? WHERE id=?")
            ->execute([$d['customer_id'], $d['description'] ?? '', $d['income_coa_id'], $amount, $d['project_id'] ?? null, $freq, $start, $d['end_date'] ?? null, $next, $offset, $auto, $active, $tid]);
    } else {
        db()->prepare("INSERT INTO ar_recurring(id,customer_id,description,income_coa_id,amount_ghs,project_id,frequency,start_date,end_date,next_due_date,day_offset,auto_post,active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tid, $d['customer_id'], $d['description'] ?? '', $d['income_coa_id'], $amount, $d['project_id'] ?? null, $freq, $start, $d['end_date'] ?? null, $next, $offset, $auto, $active, $u['username']]);
    }
    ok(['id' => $tid]);
}
function api_ar_recurring_toggle(): void {
    ensure_arap_tables(); require_role(['Admin', 'Finance Officer']); $d = body();
    $tid = (string)($d['id'] ?? ''); $st = db()->prepare('SELECT active FROM ar_recurring WHERE id=?'); $st->execute([$tid]); $t = $st->fetch();
    if (!$t) err('Template not found');
    $new = $t['active'] ? 0 : 1; db()->prepare('UPDATE ar_recurring SET active=? WHERE id=?')->execute([$new, $tid]);
    ok(['id' => $tid, 'active' => $new]);
}
function api_ar_recurring_generate(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $asof = (string)($d['as_of'] ?? date('Y-m-d')); $onlyId = $d['id'] ?? null;
    $ar = ar_control_coa(); if (!$ar) err('No receivables control account (123xxxxx) found');
    $q = "SELECT * FROM ar_recurring WHERE active=1 AND next_due_date IS NOT NULL AND next_due_date<=?"; $params = [$asof];
    if ($onlyId) { $q .= ' AND id=?'; $params[] = $onlyId; }
    $st = db()->prepare($q); $st->execute($params); $templates = $st->fetchAll();
    $generated = [];
    foreach ($templates as $t) {
        $guard = 0; $nd = $t['next_due_date'];
        while ($nd && substr((string)$nd, 0, 10) <= substr($asof, 0, 10) && $guard < 60) {
            if (!empty($t['end_date']) && substr((string)$nd, 0, 10) > substr((string)$t['end_date'], 0, 10)) break;
            $guard++; $amount = round((float)($t['amount_ghs'] ?? 0), 2);
            $iid = uuid4(); $num = seq_code('ar_invoices', 'invoice_number', 'INV-', 4);
            $offset = (int)($t['day_offset'] ?? 0); $due = date('Y-m-d', strtotime(substr((string)$nd, 0, 10) . " +$offset days")) ?: $nd;
            $desc = ($t['description'] ?: 'Recurring invoice') . ' (' . substr((string)$nd, 0, 10) . ')';
            db()->prepare("INSERT INTO ar_invoices(id,invoice_number,customer_id,invoice_date,due_date,project_id,income_coa_id,description,amount_ghs,tax_ghs,total_ghs,amount_received,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,0,?,0,'Draft',?)")
                ->execute([$iid, $num, $t['customer_id'], $nd, $due, $t['project_id'] ?? null, $t['income_coa_id'], $desc, $amount, $amount, $u['username']]);
            db()->prepare('INSERT INTO ar_invoice_lines(id,invoice_id,line_number,description,income_coa_id,amount_ghs) VALUES(?,?,1,?,?,?)')->execute([uuid4(), $iid, $desc, $t['income_coa_id'], $amount]);
            $jvnum = null;
            if (!empty($t['auto_post'])) {
                $lines = [['coa_id' => $ar['id'], 'debit_amount' => $amount, 'credit_amount' => 0, 'description' => 'Invoice ' . $num, 'project_id' => $t['project_id'] ?? null],
                          ['coa_id' => $t['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => $amount, 'description' => $desc, 'project_id' => $t['project_id'] ?? null]];
                try { [$jid, $jvnum] = post_journal($u, 'JV', (string)$nd, substr((string)$nd, 0, 7), 'Invoice ' . $num, $lines, 'ar_invoices', $iid, null);
                    db()->prepare("UPDATE ar_invoices SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $iid]); }
                catch (Throwable $e) { $generated[] = ['template_id' => $t['id'], 'invoice_number' => $num, 'due' => $nd, 'amount' => $amount, 'posted' => false, 'note' => $e->getMessage()]; $nd = null; break; }
            }
            $generated[] = ['template_id' => $t['id'], 'invoice_number' => $num, 'due' => $nd, 'amount' => $amount, 'posted' => (bool)$jvnum, 'jv_number' => $jvnum];
            $nd = aprec_advance($nd, $t['frequency']);
        }
        if ($nd) db()->prepare('UPDATE ar_recurring SET next_due_date=?, last_generated=?, invoices_generated=invoices_generated+? WHERE id=?')->execute([$nd, $asof, $guard, $t['id']]);
        else db()->prepare('UPDATE ar_recurring SET last_generated=?, invoices_generated=invoices_generated+? WHERE id=?')->execute([$asof, $guard, $t['id']]);
    }
    ok(['generated' => $generated, 'count' => count($generated)]);
}

// ── Email outbox + CSV bulk import + dunning ────────────────────────────────
function ensure_email_outbox(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS email_outbox(id TEXT PRIMARY KEY, to_email TEXT, subject TEXT, body TEXT, status TEXT DEFAULT 'Queued', error TEXT, created_at TEXT DEFAULT(datetime('now')), sent_at TEXT)");
}
function queue_email(string $to, string $subject, string $bodytext): string {
    ensure_email_outbox(); $eid = uuid4();
    db()->prepare("INSERT INTO email_outbox(id,to_email,subject,body,status) VALUES(?,?,?,?,'Queued')")->execute([$eid, $to, $subject, $bodytext]);
    return $eid;
}
function smtp_configured(): bool { return trim((string)getenv('SMTP_HOST')) !== ''; }

// Parse a delimited file (csv_text, or base64 file_b64) into lc-keyed rows. Header
// keys are lowercased with spaces/dashes -> underscores (mirror _cf_lc_row).
function import_csv_rows(array $d): array {
    $txt = (string)($d['csv_text'] ?? '');
    if ($txt === '' && !empty($d['file_b64'])) $txt = (string)base64_decode(preg_replace('#^data:[^,]*,#', '', (string)$d['file_b64']));
    $txt = str_replace(["\r\n", "\r"], "\n", $txt);
    $lines = array_values(array_filter(explode("\n", $txt), fn($l) => trim($l) !== ''));
    if (count($lines) < 2) return [];
    $delim = (strpos($lines[0], "\t") !== false) ? "\t" : ',';
    $hdr = array_map(fn($h) => strtolower(str_replace(["\xEF\xBB\xBF", ' ', '-'], ['', '_', '_'], trim($h))), str_getcsv($lines[0], $delim, '"', ''));
    $rows = [];
    for ($i = 1; $i < count($lines); $i++) {
        $cells = str_getcsv($lines[$i], $delim, '"', ''); $row = [];
        foreach ($hdr as $j => $k) $row[$k] = isset($cells[$j]) ? trim((string)$cells[$j]) : '';
        $rows[] = $row;
    }
    return $rows;
}
function imp_num($v): float { $s = preg_replace('/[, ]|GHS|GH₵|₵/u', '', (string)$v); return is_numeric($s) ? round((float)$s, 2) : 0.0; }
function imp_date($v): ?string {
    $s = trim((string)$v); if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m)) { $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]; return sprintf('%04d-%02d-%02d', (int)$y, (int)$m[2], (int)$m[1]); }
    return null;
}
function imp_coa(?string $code, ?string $name): ?string {
    if ($code) { $r = db()->prepare("SELECT id FROM chart_of_accounts WHERE code=?"); $r->execute([trim($code)]); $x = $r->fetchColumn(); if ($x) return (string)$x; }
    if ($name) {
        $nm = strtolower(trim($name));
        $r = db()->prepare("SELECT id FROM chart_of_accounts WHERE LOWER(account_name)=?"); $r->execute([$nm]); $x = $r->fetchColumn(); if ($x) return (string)$x;
        $r = db()->prepare("SELECT id FROM chart_of_accounts WHERE LOWER(account_name) LIKE ?"); $r->execute(['%' . $nm . '%']); $x = $r->fetchColumn(); if ($x) return (string)$x;
    }
    return null;
}
function pick(array $r, array $keys) { foreach ($keys as $k) if (isset($r[$k]) && trim((string)$r[$k]) !== '') return $r[$k]; return null; }

function api_ar_import_invoices(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $rows = import_csv_rows($d); if (!$rows) err('No rows found in the uploaded file');
    $dft = db()->query("SELECT id FROM chart_of_accounts WHERE code LIKE '4%' ORDER BY code LIMIT 1")->fetchColumn() ?: null;
    $created = 0; $errors = []; $newids = [];
    foreach ($rows as $i => $r) {
        $cname = trim((string)(pick($r, ['customer', 'customer_name', 'debtor', 'client', 'name']) ?? ''));
        if ($cname === '') { $errors[] = 'Row ' . ($i + 1) . ': missing customer name'; continue; }
        $amt = imp_num(pick($r, ['amount', 'amount_ghs', 'total', 'total_ghs', 'value']));
        if ($amt <= 0) { $errors[] = 'Row ' . ($i + 1) . " ($cname): missing/invalid amount"; continue; }
        $inc = imp_coa(pick($r, ['income_code', 'account_code', 'income_account_code', 'coa_code']), pick($r, ['income_account', 'account', 'account_name'])) ?: $dft;
        if (!$inc) { $errors[] = 'Row ' . ($i + 1) . ': no income account resolved'; continue; }
        $cq = db()->prepare("SELECT id FROM ar_customers WHERE LOWER(customer_name)=?"); $cq->execute([strtolower($cname)]); $cid = $cq->fetchColumn();
        if (!$cid) { $cid = uuid4(); db()->prepare("INSERT INTO ar_customers(id,customer_code,customer_name,created_by) VALUES(?,?,?,?)")->execute([$cid, seq_code('ar_customers', 'customer_code', 'CUST-', 4), $cname, $u['username']]); }
        $iid = uuid4(); $num = trim((string)(pick($r, ['invoice_no', 'invoice_number']) ?? '')) ?: seq_code('ar_invoices', 'invoice_number', 'INV-', 4);
        $idate = imp_date(pick($r, ['invoice_date', 'date'])) ?: date('Y-m-d'); $ddate = imp_date(pick($r, ['due_date', 'due'])) ?: $idate;
        $desc = trim((string)(pick($r, ['description', 'narration', 'details']) ?? ''));
        db()->prepare("INSERT INTO ar_invoices(id,invoice_number,customer_id,invoice_date,due_date,income_coa_id,description,amount_ghs,tax_ghs,total_ghs,amount_received,status,created_by) VALUES(?,?,?,?,?,?,?,?,0,?,0,'Draft',?)")->execute([$iid, $num, $cid, $idate, $ddate, $inc, $desc, $amt, $amt, $u['username']]);
        db()->prepare("INSERT INTO ar_invoice_lines(id,invoice_id,line_number,description,income_coa_id,amount_ghs) VALUES(?,?,1,?,?,?)")->execute([uuid4(), $iid, $desc ?: $num, $inc, $amt]);
        $created++; $newids[] = $iid;
    }
    $posted = 0;
    if (!empty($d['post'])) foreach ($newids as $iid) {
        try { $st = db()->prepare('SELECT * FROM ar_invoices WHERE id=?'); $st->execute([$iid]); $inv = $st->fetch(); $ar = ar_control_coa();
            $lines = [['coa_id' => $ar['id'], 'debit_amount' => round((float)$inv['total_ghs'], 2), 'credit_amount' => 0, 'description' => 'Invoice ' . $inv['invoice_number'], 'project_id' => null],
                      ['coa_id' => $inv['income_coa_id'], 'debit_amount' => 0, 'credit_amount' => round((float)$inv['amount_ghs'], 2), 'description' => $inv['description'] ?? '', 'project_id' => null]];
            [$jid, $jvnum] = post_journal($u, 'JV', (string)$inv['invoice_date'], substr((string)$inv['invoice_date'], 0, 7), 'AR Invoice ' . $inv['invoice_number'], $lines, 'ar_invoices', $iid, null);
            db()->prepare("UPDATE ar_invoices SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $iid]); $posted++;
        } catch (Throwable $e) { $errors[] = 'Post error: ' . $e->getMessage(); }
    }
    ok(['created' => $created, 'posted' => $posted, 'errors' => array_slice($errors, 0, 40), 'error_count' => count($errors),
        'message' => "Imported $created invoice(s)" . (!empty($d['post']) ? ", $posted posted" : ' as drafts')]);
}

function api_ap_import_bills(): void {
    ensure_arap_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $rows = import_csv_rows($d); if (!$rows) err('No rows found in the uploaded file');
    foreach (['bank_name', 'account_name', 'account_number', 'email'] as $c) ensure_col('vendors', $c);
    $dft = db()->query("SELECT id FROM chart_of_accounts WHERE code='61300001'")->fetchColumn() ?: (db()->query("SELECT id FROM chart_of_accounts WHERE code LIKE '6%' ORDER BY code LIMIT 1")->fetchColumn() ?: null);
    $created = 0; $errors = []; $newids = [];
    foreach ($rows as $i => $r) {
        $vname = trim((string)(pick($r, ['vendor', 'vendor_name', 'creditor', 'supplier', 'payee', 'name']) ?? ''));
        if ($vname === '') { $errors[] = 'Row ' . ($i + 1) . ': missing vendor name'; continue; }
        $amt = imp_num(pick($r, ['amount', 'amount_ghs', 'total', 'total_ghs', 'value']));
        if ($amt <= 0) { $errors[] = 'Row ' . ($i + 1) . " ($vname): missing/invalid amount"; continue; }
        $exp = imp_coa(pick($r, ['expense_code', 'account_code', 'expense_account_code', 'coa_code']), pick($r, ['expense_account', 'account', 'account_name'])) ?: $dft;
        if (!$exp) { $errors[] = 'Row ' . ($i + 1) . ': no expense account resolved'; continue; }
        $vq = db()->prepare("SELECT id FROM vendors WHERE LOWER(vendor_name)=?"); $vq->execute([strtolower($vname)]); $vid = $vq->fetchColumn();
        if (!$vid) { $vid = uuid4(); db()->prepare("INSERT INTO vendors(id,vendor_code,vendor_name,vendor_type,created_by) VALUES(?,?,?,'Supplier',?)")->execute([$vid, seq_code('vendors', 'vendor_code', 'VEND-', 5), $vname, $u['username']]); }
        $bid = uuid4(); $num = seq_code('ap_bills', 'bill_number', 'BILL-', 4);
        $vinv = trim((string)(pick($r, ['bill_no', 'invoice_no', 'vendor_invoice_no', 'invoice_number']) ?? ''));
        $bdate = imp_date(pick($r, ['bill_date', 'date', 'invoice_date'])) ?: date('Y-m-d'); $ddate = imp_date(pick($r, ['due_date', 'due'])) ?: $bdate;
        $desc = trim((string)(pick($r, ['description', 'narration', 'details']) ?? ''));
        db()->prepare("INSERT INTO ap_bills(id,bill_number,vendor_invoice_no,vendor_id,bill_date,due_date,expense_coa_id,description,amount_ghs,tax_ghs,total_ghs,amount_paid,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,0,?,0,'Draft',?)")->execute([$bid, $num, $vinv, $vid, $bdate, $ddate, $exp, $desc, $amt, $amt, $u['username']]);
        db()->prepare("INSERT INTO ap_bill_lines(id,bill_id,line_number,description,expense_coa_id,amount_ghs) VALUES(?,?,1,?,?,?)")->execute([uuid4(), $bid, $desc ?: $num, $exp, $amt]);
        $created++; $newids[] = $bid;
    }
    $posted = 0;
    if (!empty($d['post'])) foreach ($newids as $bid) {
        try { $st = db()->prepare('SELECT * FROM ap_bills WHERE id=?'); $st->execute([$bid]); $bill = $st->fetch(); $ap = ap_control_coa(); $tot = round((float)$bill['total_ghs'], 2);
            $lines = [['coa_id' => $bill['expense_coa_id'], 'debit_amount' => $tot, 'credit_amount' => 0, 'description' => $bill['description'] ?? '', 'project_id' => null],
                      ['coa_id' => $ap['id'], 'debit_amount' => 0, 'credit_amount' => $tot, 'description' => 'Bill ' . $bill['bill_number'], 'project_id' => null]];
            [$jid, $jvnum] = post_journal($u, 'JV', (string)$bill['bill_date'], substr((string)$bill['bill_date'], 0, 7), 'AP Bill ' . $bill['bill_number'], $lines, 'ap_bills', $bid, null);
            db()->prepare("UPDATE ap_bills SET status='Posted', jv_id=?, jv_number=?, posted_at=datetime('now') WHERE id=?")->execute([$jid, $jvnum, $bid]); $posted++;
        } catch (Throwable $e) { $errors[] = 'Post error: ' . $e->getMessage(); }
    }
    ok(['created' => $created, 'posted' => $posted, 'errors' => array_slice($errors, 0, 40), 'error_count' => count($errors),
        'message' => "Imported $created bill(s)" . (!empty($d['post']) ? ", $posted posted" : ' as drafts')]);
}

// Build + queue an AR/AP account statement email. SMTP-off => queued with a clear
// message (nothing leaves the server). Returns the result array (no send()).
function build_and_queue_statement(string $typ, string $rid, ?string $overrideEmail = null): array {
    if ($rid === '') return ['ok' => false, 'error' => 'Missing customer/vendor id'];
    if ($typ === 'ap') {
        $r = db()->prepare("SELECT vendor_name AS name, COALESCE(email,'') AS email FROM vendors WHERE id=?"); $r->execute([$rid]); $who = $r->fetch(); $label = 'Payables';
        $bq = db()->prepare("SELECT COALESCE(SUM(total_ghs-COALESCE(amount_paid,0)),0) FROM ap_bills WHERE vendor_id=? AND status IN ('Posted','Part-Paid')"); $bq->execute([$rid]);
    } else {
        $r = db()->prepare("SELECT customer_name AS name, COALESCE(email,'') AS email FROM ar_customers WHERE id=?"); $r->execute([$rid]); $who = $r->fetch(); $label = 'Receivables';
        $bq = db()->prepare("SELECT COALESCE(SUM(total_ghs-COALESCE(amount_received,0)),0) FROM ar_invoices WHERE customer_id=? AND status IN ('Posted','Part-Paid')"); $bq->execute([$rid]);
    }
    if (!$who) return ['ok' => false, 'error' => 'Account not found'];
    $name = (string)($who['name'] ?? ''); $email = trim((string)($overrideEmail ?? ($who['email'] ?? '')));
    if ($email === '' || strpos($email, '@') === false) return ['ok' => false, 'error' => 'No valid email on file for ' . ($name ?: 'this account') . '. Add an email to the account, or provide one.'];
    $bal = round((float)$bq->fetchColumn(), 2);
    $subject = sprintf('UCC Finance — Account Statement (balance GHS %.2f)', $bal);
    $bodytext = sprintf("Dear %s,\n\nPlease find your %s account statement as at %s.\nOutstanding balance: GHS %.2f.\n\nKind regards,\nUCC Finance Office", $name ?: 'Sir/Madam', strtolower($label), date('Y-m-d'), $bal);
    $eid = queue_email($email, $subject, $bodytext);
    if (!smtp_configured()) {
        db()->prepare("UPDATE email_outbox SET status='Queued - SMTP not configured', error=? WHERE id=?")->execute(['Set SMTP_HOST/PORT/USER/PASSWORD/FROM to enable sending.', $eid]);
        return ['ok' => true, 'queued' => true, 'sent' => false, 'to' => $email, 'message' => 'Statement for ' . ($name ?: 'account') . ' queued to ' . $email . '. Configure SMTP to send automatically.'];
    }
    // SMTP configured: best-effort send via PHP mail(); on failure stay queued (graceful).
    $sent = @mail($email, $subject, $bodytext);
    db()->prepare("UPDATE email_outbox SET status=?, sent_at=CASE WHEN ?='Sent' THEN datetime('now') ELSE NULL END WHERE id=?")->execute([$sent ? 'Sent' : 'Queued', $sent ? 'Sent' : 'Queued', $eid]);
    return ['ok' => true, 'queued' => !$sent, 'sent' => (bool)$sent, 'to' => $email, 'message' => $sent ? ('Statement emailed to ' . $email) : ('Statement queued to ' . $email)];
}
function api_email_statement(): void {
    ensure_arap_tables(); require_auth(); $d = body();
    $res = build_and_queue_statement(strtolower((string)($d['type'] ?? 'ar')), (string)($d['id'] ?? ($d['customer_id'] ?? ($d['vendor_id'] ?? ''))), isset($d['to']) ? (string)$d['to'] : null);
    if (!$res['ok']) err($res['error']);
    unset($res['ok']); ok($res);
}

function dunning_rows(): array {
    ensure_col('ar_invoices', 'credited_ghs', 'REAL');
    $rows = db()->query("SELECT c.id AS customer_id, c.customer_name, COALESCE(c.email,'') AS email,
        SUM(i.total_ghs - COALESCE(i.amount_received,0) - COALESCE(i.credited_ghs,0)) AS overdue,
        COUNT(*) AS invoices, MIN(i.due_date) AS oldest_due
        FROM ar_invoices i JOIN ar_customers c ON c.id=i.customer_id
        WHERE i.status IN ('Posted','Part-Paid') AND (i.total_ghs - COALESCE(i.amount_received,0) - COALESCE(i.credited_ghs,0)) > 0.01
          AND i.due_date IS NOT NULL AND date(i.due_date) < date('now')
        GROUP BY c.id ORDER BY overdue DESC")->fetchAll();
    foreach ($rows as &$r) {
        $r['overdue'] = round((float)$r['overdue'], 2);
        $r['has_email'] = trim((string)$r['email']) !== '';
        $od = strtotime(substr((string)$r['oldest_due'], 0, 10)); $r['days_overdue'] = $od ? max(0, (int)floor((time() - $od) / 86400)) : 0;
    }
    unset($r);
    return $rows;
}
function api_dunning_preview(): void {
    ensure_arap_tables(); require_auth();
    $rows = dunning_rows();
    ok(['customers' => $rows, 'total_overdue' => round(array_sum(array_map(fn($r) => $r['overdue'], $rows)), 2), 'count' => count($rows)]);
}
function api_dunning_run(): void {
    ensure_arap_tables(); require_role(['Admin', 'Finance Officer']); $d = body();
    $ids = $d['customer_ids'] ?? null;
    $targets = dunning_rows();
    if ($ids) { $set = array_flip((array)$ids); $targets = array_values(array_filter($targets, fn($c) => isset($set[$c['customer_id']]))); }
    $targets = array_values(array_filter($targets, fn($c) => $c['has_email']));
    if (!$targets) err('No overdue customers with an email address to remind');
    $sent = 0; $queued = 0; $results = [];
    foreach ($targets as $c) {
        $res = build_and_queue_statement('ar', $c['customer_id']);
        if (!empty($res['ok'])) { if (!empty($res['sent'])) $sent++; else $queued++; $results[] = ['customer' => $c['customer_name'], 'to' => $res['to'] ?? null, 'status' => !empty($res['sent']) ? 'sent' : 'queued']; }
        else $results[] = ['customer' => $c['customer_name'], 'status' => 'error', 'error' => $res['error'] ?? ''];
    }
    ok(['sent' => $sent, 'queued' => $queued, 'results' => $results, 'message' => "Dunning: $sent sent, $queued queued (configure SMTP to send)"]);
}

// ── Recurring journals — standing multi-line balanced JVs (depreciation, accruals,
//    prepayments) generated on a schedule and posted via post_journal.
function ensure_recjv(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS rec_journals(id TEXT PRIMARY KEY, name TEXT, description TEXT, frequency TEXT DEFAULT 'Monthly', start_date TEXT, end_date TEXT, next_due_date TEXT, project_id TEXT, active INTEGER DEFAULT 1, jvs_generated INTEGER DEFAULT 0, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    db()->exec("CREATE TABLE IF NOT EXISTS rec_journal_lines(id TEXT PRIMARY KEY, rec_id TEXT, line_no INTEGER, coa_id TEXT, debit REAL DEFAULT 0, credit REAL DEFAULT 0, description TEXT)");
}
function api_rec_journals_list(): void {
    ensure_recjv(); require_auth();
    $rows = db()->query("SELECT t.*, p.project_code,
        (SELECT COALESCE(SUM(debit),0) FROM rec_journal_lines WHERE rec_id=t.id) AS total_debit,
        (SELECT COUNT(*) FROM rec_journal_lines WHERE rec_id=t.id) AS line_count
        FROM rec_journals t LEFT JOIN projects p ON p.id=t.project_id ORDER BY t.active DESC, t.next_due_date")->fetchAll();
    ok(['templates' => $rows]);
}
function api_save_rec_journal(): void {
    ensure_recjv(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $name = trim((string)($d['name'] ?? '')); if ($name === '') err('Template name is required');
    $raw = (isset($d['lines']) && is_array($d['lines'])) ? $d['lines'] : [];
    $norm = [];
    foreach ($raw as $ln) { $dr = round((float)($ln['debit'] ?? 0), 2); $cr = round((float)($ln['credit'] ?? 0), 2); if (empty($ln['coa_id']) || ($dr == 0 && $cr == 0)) continue; $norm[] = ['coa_id' => $ln['coa_id'], 'debit' => $dr, 'credit' => $cr, 'description' => $ln['description'] ?? '']; }
    if (count($norm) < 2) err('Add at least two lines');
    $td = round(array_sum(array_map(fn($l) => $l['debit'], $norm)), 2); $tc = round(array_sum(array_map(fn($l) => $l['credit'], $norm)), 2);
    if (abs($td - $tc) > 0.01) err(sprintf('Journal not balanced: debits GHS %.2f ≠ credits GHS %.2f', $td, $tc));
    $freq = $d['frequency'] ?? 'Monthly'; $start = $d['start_date'] ?? date('Y-m-d'); $tid = $d['id'] ?? uuid4();
    $ex = db()->prepare("SELECT next_due_date FROM rec_journals WHERE id=?"); $ex->execute([$tid]); $exrow = $ex->fetch();
    $nd = $d['next_due_date'] ?? (($exrow['next_due_date'] ?? null) ?: $start);
    $active = in_array($d['active'] ?? 1, [0, '0', false, 'false'], true) ? 0 : 1;
    if ($exrow) db()->prepare("UPDATE rec_journals SET name=?,description=?,frequency=?,start_date=?,end_date=?,next_due_date=?,project_id=?,active=? WHERE id=?")->execute([$name, $d['description'] ?? '', $freq, $start, $d['end_date'] ?? null, $nd, $d['project_id'] ?? null, $active, $tid]);
    else db()->prepare("INSERT INTO rec_journals(id,name,description,frequency,start_date,end_date,next_due_date,project_id,active,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$tid, $name, $d['description'] ?? '', $freq, $start, $d['end_date'] ?? null, $nd, $d['project_id'] ?? null, $active, $u['username']]);
    db()->prepare("DELETE FROM rec_journal_lines WHERE rec_id=?")->execute([$tid]);
    $i = 0; $ins = db()->prepare("INSERT INTO rec_journal_lines(id,rec_id,line_no,coa_id,debit,credit,description) VALUES(?,?,?,?,?,?,?)");
    foreach ($norm as $l) { $i++; $ins->execute([uuid4(), $tid, $i, $l['coa_id'], $l['debit'], $l['credit'], $l['description']]); }
    ok(['id' => $tid]);
}
function api_rec_journal_toggle(): void {
    ensure_recjv(); require_role(['Admin', 'Finance Officer']); $d = body();
    $tid = (string)($d['id'] ?? ''); $st = db()->prepare("SELECT active FROM rec_journals WHERE id=?"); $st->execute([$tid]); $t = $st->fetch();
    if (!$t) err('Template not found'); $new = $t['active'] ? 0 : 1; db()->prepare("UPDATE rec_journals SET active=? WHERE id=?")->execute([$new, $tid]);
    ok(['id' => $tid, 'active' => $new]);
}
function api_rec_journal_generate(): void {
    ensure_recjv(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $asof = (string)($d['as_of'] ?? date('Y-m-d')); $only = $d['id'] ?? null;
    $q = "SELECT * FROM rec_journals WHERE active=1 AND next_due_date IS NOT NULL AND next_due_date<=?"; $params = [$asof];
    if ($only) { $q .= " AND id=?"; $params[] = $only; }
    $st = db()->prepare($q); $st->execute($params); $templates = $st->fetchAll();
    $generated = [];
    foreach ($templates as $t) {
        $lq = db()->prepare("SELECT * FROM rec_journal_lines WHERE rec_id=? ORDER BY line_no"); $lq->execute([$t['id']]); $lt = $lq->fetchAll();
        if (!$lt) continue;
        $nd = $t['next_due_date']; $guard = 0;
        while ($nd && substr((string)$nd, 0, 10) <= substr($asof, 0, 10) && $guard < 60) {
            if (!empty($t['end_date']) && substr((string)$nd, 0, 10) > substr((string)$t['end_date'], 0, 10)) break;
            $guard++;
            $lines = array_map(fn($l) => ['coa_id' => $l['coa_id'], 'debit_amount' => round((float)$l['debit'], 2), 'credit_amount' => round((float)$l['credit'], 2), 'description' => ($l['description'] ?: $t['name']), 'project_id' => $t['project_id'] ?? null], $lt);
            try { [$jid, $jvnum] = post_journal($u, 'JV', (string)$nd, substr((string)$nd, 0, 7), ($t['name'] ?: 'Recurring journal') . ' (' . substr((string)$nd, 0, 10) . ')', $lines, 'rec_journal', $t['id'], null); $generated[] = ['template_id' => $t['id'], 'jv_number' => $jvnum, 'due' => $nd]; }
            catch (Throwable $e) { $generated[] = ['template_id' => $t['id'], 'due' => $nd, 'error' => $e->getMessage()]; $nd = null; break; }
            $nd = aprec_advance($nd, $t['frequency']);
        }
        if ($nd) db()->prepare("UPDATE rec_journals SET next_due_date=?, jvs_generated=jvs_generated+? WHERE id=?")->execute([$nd, $guard, $t['id']]);
        else db()->prepare("UPDATE rec_journals SET jvs_generated=jvs_generated+? WHERE id=?")->execute([$guard, $t['id']]);
    }
    $posted = array_values(array_filter($generated, fn($g) => !empty($g['jv_number'])));
    ok(['generated' => $generated, 'count' => count($posted), 'message' => $posted ? ('Posted ' . count($posted) . ' journal(s)') : 'No journals due']);
}

// ── Generic export engine: CSV, real XLSX (OOXML via ZipArchive) and PDF from a
//    {title, subtitle, columns:[{key,label,align}], rows:[{}|[]]} payload.
function exp_norm(array $d): array {
    $cols = [];
    foreach (($d['columns'] ?? []) as $c) {
        if (is_array($c)) $cols[] = ['key' => $c['key'] ?? ($c['label'] ?? ''), 'label' => $c['label'] ?? ($c['key'] ?? ''), 'align' => $c['align'] ?? 'left'];
        else $cols[] = ['key' => $c, 'label' => $c, 'align' => 'left'];
    }
    $rows = [];
    foreach (($d['rows'] ?? []) as $r) {
        if (is_array($r) && $r !== array_values($r)) { $row = []; foreach ($cols as $c) $row[] = $r[$c['key']] ?? ''; $rows[] = $row; }
        elseif (is_array($r)) $rows[] = array_values($r);
        else $rows[] = [$r];
    }
    return [$cols, $rows];
}
function xlsx_col(int $n): string { $s = ''; $n++; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; }
function build_xlsx(string $title, array $cols, array $rows): string {
    $xe = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $all = array_merge([array_map(fn($c) => $c['label'], $cols)], $rows); $rn = 0;
    foreach ($all as $r) {
        $rn++; $sheet .= '<row r="' . $rn . '">'; $cn = 0;
        foreach ($r as $v) { $sheet .= '<c r="' . xlsx_col($cn) . $rn . '" t="inlineStr"><is><t xml:space="preserve">' . $xe($v) . '</t></is></c>'; $cn++; }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $wbrels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx'); $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $ct); $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $wb); $zip->addFromString('xl/_rels/workbook.xml.rels', $wbrels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet); $zip->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp); return $data;
}
function build_pdf(string $title, string $subtitle, array $cols, array $rows): string {
    $pe = fn($s) => str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], (string)$s);
    $lines = [[16, $title]];
    if ($subtitle !== '') $lines[] = [10, $subtitle];
    $lines[] = [10, implode('  |  ', array_map(fn($c) => $c['label'], $cols))];
    foreach ($rows as $r) $lines[] = [9, implode('  |  ', array_map(fn($v) => (string)$v, $r))];
    $content = "BT 40 800 Td 14 TL\n"; $first = true;
    foreach ($lines as [$sz, $txt]) { $content .= ($first ? "/F1 $sz Tf (" : "/F1 $sz Tf T* (") . $pe($txt) . ") Tj\n"; $first = false; }
    $content .= "ET";
    $objs = [1 => "<</Type/Catalog/Pages 2 0 R>>", 2 => "<</Type/Pages/Kids[3 0 R]/Count 1>>",
        3 => "<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>",
        4 => "<</Length " . strlen($content) . ">>\nstream\n" . $content . "\nendstream",
        5 => "<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>"];
    $pdf = "%PDF-1.4\n"; $off = [];
    for ($i = 1; $i <= 5; $i++) { $off[$i] = strlen($pdf); $pdf .= "$i 0 obj" . $objs[$i] . "endobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
    $pdf .= "trailer<</Size 6/Root 1 0 R>>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}
function build_export(array $d): array {
    $fmt = strtolower((string)($d['format'] ?? 'xlsx'));
    $title = trim((string)($d['title'] ?? 'Export')); $subtitle = trim((string)($d['subtitle'] ?? ''));
    [$cols, $rows] = exp_norm($d);
    $base = preg_replace('/[^A-Za-z0-9 _-]/', '', (string)($d['filename'] ?? ($title ?: 'export'))); $base = str_replace(' ', '_', trim((string)$base)) ?: 'export';
    if ($fmt === 'csv') {
        $esc = fn($x) => '"' . str_replace('"', '""', (string)$x) . '"';
        $csv = implode(',', array_map(fn($c) => $esc($c['label']), $cols)) . "\r\n";
        foreach ($rows as $r) $csv .= implode(',', array_map(fn($v) => $esc($v), $r)) . "\r\n";
        return ['filename' => $base . '.csv', 'mime' => 'text/csv', 'b64' => base64_encode("\xEF\xBB\xBF" . $csv)];
    }
    if (in_array($fmt, ['xlsx', 'excel', 'xls'], true)) return ['filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'b64' => base64_encode(build_xlsx($title, $cols, $rows))];
    if ($fmt === 'pdf') return ['filename' => $base . '.pdf', 'mime' => 'application/pdf', 'b64' => base64_encode(build_pdf($title, $subtitle, $cols, $rows))];
    return ['error' => 'Unsupported format: ' . $fmt];
}
function api_export_file(): void {
    require_auth(); $d = body();
    try { $r = build_export($d); } catch (Throwable $e) { err('Export failed: ' . $e->getMessage()); }
    if (!empty($r['error'])) err($r['error']);
    ok($r);
}

// Month-end flash pack — GL-derived dashboard: SFP totals, period/YTD income &
// expenditure, trial-balance health, and top expenditure lines for the period.
function api_flash_pack(): void {
    require_auth();
    $period = trim((string)($_GET['period'] ?? ''));
    if ($period === '') { $r = db()->query("SELECT period FROM general_ledger ORDER BY period DESC LIMIT 1")->fetchColumn(); $period = $r ?: date('Y-m'); }
    $year = substr($period, 0, 4);
    $sc = function (string $sql, array $p = []) { try { $st = db()->prepare($sql); $st->execute($p); $v = $st->fetchColumn(); return ($v === false || $v === null) ? 0.0 : round((float)$v, 2); } catch (Throwable $e) { return 0.0; } };
    $assets = $sc("SELECT COALESCE(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '1%'");
    $liab = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '2%'");
    $funds = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '3%'");
    $inc_p = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '4%' AND period LIKE ?", [$period . '%']);
    $exp_p = $sc("SELECT COALESCE(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '6%' AND period LIKE ?", [$period . '%']);
    $inc_y = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '4%' AND period LIKE ?", [$year . '%']);
    $exp_y = $sc("SELECT COALESCE(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '6%' AND period LIKE ?", [$year . '%']);
    $tb_dr = $sc("SELECT COALESCE(SUM(COALESCE(debit_amount,0)),0) FROM general_ledger");
    $tb_cr = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)),0) FROM general_ledger");
    $top = [];
    try { foreach (db()->query("SELECT coa_code, MAX(account_name) AS account_name, ROUND(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),2) AS amount FROM general_ledger WHERE coa_code LIKE '6%' AND period LIKE '" . str_replace("'", "''", $period) . "%' GROUP BY coa_code HAVING amount > 0 ORDER BY amount DESC LIMIT 5")->fetchAll() as $r) $top[] = $r; } catch (Throwable $e) {}
    ok(['period' => $period, 'year' => $year, 'as_of' => date('Y-m-d'),
        'sfp' => ['assets' => $assets, 'liabilities' => $liab, 'funds_and_reserves' => $funds, 'net_assets' => round($assets - $liab, 2),
                  'presentation_difference' => round($assets - $liab - $funds + round($inc_y - $exp_y, 2), 2)],
        'income_expenditure' => ['income_period' => $inc_p, 'expenditure_period' => $exp_p, 'surplus_period' => round($inc_p - $exp_p, 2),
                                 'income_ytd' => $inc_y, 'expenditure_ytd' => $exp_y, 'surplus_ytd' => round($inc_y - $exp_y, 2)],
        'trial_balance' => ['total_debit' => $tb_dr, 'total_credit' => $tb_cr, 'balanced' => abs($tb_dr - $tb_cr) < 0.05, 'difference' => round($tb_dr - $tb_cr, 2)],
        'working_capital' => ['cash_and_bank_ghs' => 0.0, 'receivables_ghs' => 0.0, 'payables_ghs' => 0.0, 'inventory_value_ghs' => 0.0,
                              'current_assets_ghs' => 0.0, 'current_liabilities_ghs' => 0.0, 'net_working_capital_ghs' => 0.0, 'current_ratio' => 0.0, 'quick_ratio' => 0.0],
        'top_expenditure' => $top]);
}

// One-click external-audit bundle: a ZIP (base64) of CSV schedules + a manifest.
// Every section is best-effort and GL/table-derived; a failed section is reported
// in the manifest, never kills the pack.
function api_audit_pack(): void {
    require_role(['Admin', 'Finance Officer', 'Auditor']); ensure_arap_tables();
    $fy = substr((string)($_GET['fy'] ?? date('Y')), 0, 4); $d1 = "$fy-01-01"; $d2 = "$fy-12-31";
    $tmp = tempnam(sys_get_temp_dir(), 'apack'); $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE);
    $sections = [];
    $csv = function (array $headers, array $rows): string {
        $esc = fn($x) => '"' . str_replace('"', '""', (string)$x) . '"';
        $s = implode(',', array_map($esc, $headers)) . "\r\n";
        foreach ($rows as $r) $s .= implode(',', array_map($esc, $r)) . "\r\n";
        return "\xEF\xBB\xBF" . $s;
    };
    $add = function (string $name, callable $fn) use (&$sections, $zip, $csv) {
        try { [$h, $rows] = $fn(); $zip->addFromString($name, $csv($h, $rows)); $sections[] = ['name' => $name, 'ok' => true, 'rows' => count($rows)]; }
        catch (Throwable $e) { $sections[] = ['name' => $name, 'ok' => false, 'error' => substr($e->getMessage(), 0, 160)]; }
    };
    $q = function (string $sql, array $p = []) { $st = db()->prepare($sql); $st->execute($p); return $st->fetchAll(); };
    $add("01_trial_balance_$fy.csv", function () use ($q, $d2) {
        $rr = $q("SELECT gl.coa_code AS code, COALESCE(MAX(gl.account_name),'') AS nm, ROUND(SUM(COALESCE(gl.debit_amount,0)),2) AS dr, ROUND(SUM(COALESCE(gl.credit_amount,0)),2) AS cr FROM general_ledger gl WHERE gl.ledger_date <= ? GROUP BY gl.coa_code ORDER BY gl.coa_code", [$d2]);
        $out = []; $td = 0; $tc = 0;
        foreach ($rr as $r) { $d = (float)$r['dr']; $c = (float)$r['cr']; $td += $d; $tc += $c; $out[] = [$r['code'], $r['nm'], $r['dr'], $r['cr'], round(max($d - $c, 0), 2), round(max($c - $d, 0), 2)]; }
        $out[] = ['TOTAL', '', round($td, 2), round($tc, 2), '', ''];
        return [['Account Code', 'Account Name', 'Total Debits', 'Total Credits', 'Debit Balance', 'Credit Balance'], $out];
    });
    $add("02_general_ledger_detail_$fy.csv", function () use ($q, $d1, $d2) {
        $rr = $q("SELECT ledger_date, jv_number, coa_code, account_name, description, COALESCE(debit_amount,0) AS dr, COALESCE(credit_amount,0) AS cr, COALESCE(project_code,'') AS pc, COALESCE(posted_by,'') AS pb FROM general_ledger WHERE ledger_date BETWEEN ? AND ? ORDER BY ledger_date, jv_number", [$d1, $d2]);
        return [['Date', 'Voucher', 'Account', 'Account Name', 'Description', 'Debit', 'Credit', 'Project', 'Posted By'], array_map('array_values', $rr)];
    });
    $add("03_income_expenditure_$fy.csv", function () use ($q, $d1, $d2) {
        $rr = $q("SELECT coa_code, COALESCE(MAX(account_name),'') AS nm, ROUND(SUM(CASE WHEN coa_code LIKE '4%' THEN COALESCE(credit_amount,0)-COALESCE(debit_amount,0) ELSE COALESCE(debit_amount,0)-COALESCE(credit_amount,0) END),2) AS amt FROM general_ledger WHERE (coa_code LIKE '4%' OR coa_code LIKE '6%') AND ledger_date BETWEEN ? AND ? GROUP BY coa_code ORDER BY coa_code", [$d1, $d2]);
        return [['Account', 'Name', 'Amount (income +, expenditure +)'], array_map('array_values', $rr)];
    });
    $add("04_financial_position_$fy.csv", function () use ($q, $d2) {
        $rr = $q("SELECT COALESCE(c.account_type,'(unclassified)') AS typ, COALESCE(NULLIF(gl.coa_code,''),c.code,'') AS code, COALESCE(MAX(gl.account_name),'') AS nm, ROUND(SUM(COALESCE(gl.debit_amount,0)-COALESCE(gl.credit_amount,0)),2) AS net FROM general_ledger gl LEFT JOIN chart_of_accounts c ON c.id=gl.coa_id WHERE gl.ledger_date <= ? GROUP BY gl.coa_id ORDER BY typ, code", [$d2]);
        return [['Account Type', 'Code', 'Name', 'Net (Dr-Cr)'], array_map('array_values', $rr)];
    });
    $add("05_receivables_aging.csv", function () use ($q) {
        $rr = $q("SELECT c.customer_name AS nm, ROUND(SUM(i.total_ghs-COALESCE(i.amount_received,0)),2) AS outstanding, COUNT(*) AS invoices FROM ar_invoices i JOIN ar_customers c ON c.id=i.customer_id WHERE i.status IN ('Posted','Part-Paid') AND (i.total_ghs-COALESCE(i.amount_received,0))>0.01 GROUP BY c.id ORDER BY outstanding DESC");
        return [['Customer', 'Outstanding (GHS)', 'Open Invoices'], array_map('array_values', $rr)];
    });
    $add("06_payables_aging.csv", function () use ($q) {
        $rr = $q("SELECT v.vendor_name AS nm, ROUND(SUM(b.total_ghs-COALESCE(b.amount_paid,0)),2) AS outstanding, COUNT(*) AS bills FROM ap_bills b JOIN vendors v ON v.id=b.vendor_id WHERE b.status IN ('Posted','Part-Paid') AND (b.total_ghs-COALESCE(b.amount_paid,0))>0.01 GROUP BY v.id ORDER BY outstanding DESC");
        return [['Vendor', 'Outstanding (GHS)', 'Open Bills'], array_map('array_values', $rr)];
    });
    $add("07_ppe_register.csv", function () use ($q) {
        $rr = $q("SELECT asset_code, asset_name, asset_category, acquisition_date, ROUND(COALESCE(acquisition_cost,0),2) AS cost, ROUND(COALESCE(accumulated_depreciation,0),2) AS accdep, ROUND(COALESCE(carrying_amount,0),2) AS nbv, COALESCE(status,'') AS st FROM asset_register ORDER BY asset_code");
        return [['Code', 'Name', 'Category', 'Acquired', 'Cost', 'Accum Dep', 'Carrying', 'Status'], array_map('array_values', $rr)];
    });
    $add("08_tax_schedules_$fy.csv", function () use ($q, $d2) {
        $codes = ['21100014' => 'WHT', '21100024' => 'WHVAT', '21100017' => 'PAYE', '21100015' => 'SSNIT', '21100027' => 'UCF', '21100022' => 'VAT'];
        $out = [];
        foreach ($codes as $code => $label) {
            $r = $q("SELECT ROUND(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),2) AS bal FROM general_ledger WHERE coa_code=? AND ledger_date <= ?", [$code, $d2]);
            $out[] = [$code, $label, $r[0]['bal'] ?? 0];
        }
        return [['Account', 'Tax', 'Outstanding (GHS)'], $out];
    });
    $add("09_budget_vs_actual_$fy.csv", function () use ($q, $d1, $d2) {
        $rr = $q("SELECT b.budget_code, COALESCE(c.code,'') AS coa, COALESCE(c.account_name,'') AS nm, ROUND(COALESCE(b.budget_ghs,0),2) AS budget, ROUND(COALESCE((SELECT SUM(a.amount_ghs) FROM actuals a WHERE a.budget_id=b.id AND COALESCE(a.is_posted,0)=1 AND a.expense_date BETWEEN ? AND ?),0),2) AS actual FROM budgets b LEFT JOIN chart_of_accounts c ON c.id=b.coa_id WHERE COALESCE(b.is_deleted,0)=0 ORDER BY coa", [$d1, $d2]);
        return [['Budget', 'Account', 'Name', 'Budget (GHS)', 'Actual (GHS)'], array_map('array_values', $rr)];
    });
    $add("10_bank_balances_$fy.csv", function () use ($q, $d2) {
        $rr = $q("SELECT coa_code, COALESCE(MAX(account_name),'') AS nm, ROUND(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),2) AS bal FROM general_ledger WHERE (coa_code LIKE '126%' OR coa_code LIKE '127%' OR coa_code LIKE '128%' OR coa_code LIKE '129%' OR coa_code='1001') AND ledger_date <= ? GROUP BY coa_code ORDER BY coa_code", [$d2]);
        return [['Account', 'Name', 'Balance (GHS)'], array_map('array_values', $rr)];
    });
    $add("11_audit_log.csv", function () use ($q) {
        $has = db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='audit_log'")->fetchColumn();
        if (!$has) return [['Timestamp', 'Action', 'By', 'Details'], []];
        $cols = array_column(db()->query("PRAGMA table_info(audit_log)")->fetchAll(), 'name');
        $tsc = in_array('timestamp', $cols, true) ? 'timestamp' : (in_array('created_at', $cols, true) ? 'created_at' : "''");
        $byc = in_array('username', $cols, true) ? 'username' : (in_array('performed_by', $cols, true) ? 'performed_by' : "''");
        $detc = in_array('details', $cols, true) ? 'details' : "''";
        $actc = in_array('action', $cols, true) ? 'action' : "''";
        $rr = $q("SELECT COALESCE($tsc,'') AS ts, COALESCE($actc,'') AS act, COALESCE($byc,'') AS by, COALESCE($detc,'') AS det FROM audit_log ORDER BY ts DESC LIMIT 1000");
        return [['Timestamp', 'Action', 'By', 'Details'], array_map('array_values', $rr)];
    });
    $manifest = "UCC-FMS External Audit Pack\r\nFinancial Year: $fy\r\nGenerated: " . date('Y-m-d H:i') . "\r\n\r\nSections:\r\n";
    foreach ($sections as $s2) $manifest .= sprintf(" - %s : %s%s\r\n", $s2['name'], $s2['ok'] ? 'OK' : 'FAILED', isset($s2['rows']) ? (' (' . $s2['rows'] . ' rows)') : '');
    $zip->addFromString('00_INDEX.txt', $manifest);
    $zip->close(); $data = (string)file_get_contents($tmp); @unlink($tmp);
    $bad = array_values(array_filter($sections, fn($s2) => !$s2['ok']));
    ok(['ok' => true, 'fy' => $fy, 'sections' => $sections, 'failed' => array_map(fn($s2) => $s2['name'], $bad),
        'zip_b64' => base64_encode($data), 'filename' => "audit_pack_$fy.zip", 'bytes' => strlen($data)]);
}

// ── Tamper-evident audit log: recompute the SHA-256 hash chain and report
//    integrity (mirror server.py _audit_row_hash / api_audit_verify).
function audit_row_hash($prev, $aid, $ts, $uname, $role, $action, $module, $rec, $det): string {
    $payload = implode('|', [(string)$prev, (string)$aid, (string)$ts, (string)$uname, (string)$role, (string)$action, (string)($module ?? ''), (string)($rec ?? ''), (string)($det ?? '')]);
    return hash('sha256', $payload);
}
function api_audit_verify(): void {
    $u = require_auth();
    if (!in_array($u['role'] ?? '', ['Admin', 'Finance Officer', 'Auditor'], true)) err('Admin, Finance Officer or Auditor role required');
    foreach (['row_hash', 'prev_hash', 'user_role'] as $c) ensure_col('audit_log', $c);
    $rows = db()->query("SELECT id,timestamp,username,COALESCE(user_role,'') AS user_role,action,module,record_id,details,row_hash,prev_hash FROM audit_log ORDER BY timestamp ASC, rowid ASC")->fetchAll();
    $total = count($rows); $checked = 0; $sealed = 0; $prev = 'GENESIS'; $first_broken = null;
    $seal = db()->prepare("UPDATE audit_log SET row_hash=?, prev_hash=? WHERE id=?");
    foreach ($rows as $r) {
        if (empty($r['row_hash'])) {
            // Legacy row predating chaining: seal it into the chain now (first verify
            // acts as the tamper-evident baseline; later edits will break recompute).
            $rh = audit_row_hash($prev, $r['id'], $r['timestamp'], $r['username'], $r['user_role'], $r['action'], $r['module'], $r['record_id'], $r['details']);
            $seal->execute([$rh, $prev, $r['id']]); $sealed++; $checked++; $prev = $rh; continue;
        }
        $base = ($r['prev_hash'] === null || $r['prev_hash'] === '') ? $prev : $r['prev_hash'];
        $expect = audit_row_hash($base, $r['id'], $r['timestamp'], $r['username'], $r['user_role'], $r['action'], $r['module'], $r['record_id'], $r['details']);
        $link_ok = ($r['prev_hash'] === $prev) || ($prev === 'GENESIS' && in_array($r['prev_hash'], [null, '', 'GENESIS'], true));
        if ($r['row_hash'] !== $expect || !$link_ok) { if (!$first_broken) $first_broken = ['id' => $r['id'], 'timestamp' => $r['timestamp'], 'action' => $r['action'], 'username' => $r['username'], 'reason' => $r['row_hash'] !== $expect ? 'content altered' : 'chain link broken']; }
        $checked++; $prev = $r['row_hash'];
    }
    $intact = $first_broken === null;
    ok(['verified' => $intact, 'total_entries' => $total, 'hash_chained' => $checked, 'sealed' => $sealed, 'first_broken' => $first_broken,
        'message' => $intact ? "Audit chain intact — $checked hash-linked entries verified" : ('TAMPERING DETECTED at entry ' . substr((string)$first_broken['id'], 0, 8) . ' (' . $first_broken['reason'] . ')')]);
}

// ── Bank reconciliation — persistent clearing. Tick each cash-book line that has
//    cleared the bank; the cleared state persists (bank_recon_cleared).
function ensure_brc(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS bank_recon_cleared (gl_id TEXT PRIMARY KEY, bank_account_id TEXT, cleared_date TEXT, cleared_by TEXT, created_at TEXT DEFAULT (datetime('now')))");
}
function api_bank_recon_worklist(): void {
    require_auth(); ensure_brc();
    $bid = (string)($_GET['bank_account_id'] ?? ''); if ($bid === '') err('Select a bank account');
    $asat = substr((string)($_GET['as_at'] ?? date('Y-m-d')), 0, 10);
    $bk = db()->prepare("SELECT id, bank_name, account_number, coa_id FROM bank_accounts WHERE id=?"); $bk->execute([$bid]); $b = $bk->fetch();
    if (!$b) err('Bank account not found');
    if (empty($b['coa_id'])) err('This bank account has no linked GL account');
    $clrst = db()->prepare("SELECT gl_id FROM bank_recon_cleared WHERE bank_account_id=?"); $clrst->execute([$bid]);
    $cleared = array_flip(array_column($clrst->fetchAll(), 'gl_id'));
    $st = db()->prepare("SELECT gl.id, gl.ledger_date, gl.jv_number, gl.description, COALESCE(gl.debit_amount,0) dr, COALESCE(gl.credit_amount,0) cr FROM general_ledger gl WHERE gl.coa_id=? AND gl.ledger_date<=? ORDER BY gl.ledger_date, gl.jv_number");
    $st->execute([$b['coa_id'], $asat]);
    $rows = []; $book = 0.0; $ct = 0.0; $ot = 0.0;
    foreach ($st->fetchAll() as $r) {
        $amt = round((float)$r['dr'] - (float)$r['cr'], 2); $isc = isset($cleared[$r['id']]); $book += $amt;
        if ($isc) $ct += $amt; else $ot += $amt;
        $rows[] = ['gl_id' => $r['id'], 'date' => $r['ledger_date'], 'voucher' => $r['jv_number'], 'description' => $r['description'], 'amount' => $amt, 'cleared' => $isc];
    }
    ok(['bank' => ['id' => $b['id'], 'name' => $b['bank_name'], 'account_number' => $b['account_number']], 'as_at' => $asat,
        'lines' => $rows, 'book_balance' => round($book, 2), 'cleared_balance' => round($ct, 2), 'outstanding_total' => round($ot, 2),
        'outstanding_count' => count(array_filter($rows, fn($x) => !$x['cleared']))]);
}
function api_bank_recon_clear(): void {
    require_role(['Admin', 'Finance Officer']); ensure_brc(); $d = body();
    $bid = (string)($d['bank_account_id'] ?? ''); if ($bid === '') err('bank_account_id is required');
    $gl_ids = $d['gl_ids'] ?? []; if (!is_array($gl_ids) || !$gl_ids) err('Select at least one line');
    $cleared = array_key_exists('cleared', $d) ? (bool)$d['cleared'] : true;
    $cdate = substr((string)($d['cleared_date'] ?? date('Y-m-d')), 0, 10); $n = 0;
    $ins = db()->prepare("INSERT OR REPLACE INTO bank_recon_cleared(gl_id,bank_account_id,cleared_date,cleared_by) VALUES(?,?,?,?)");
    $del = db()->prepare("DELETE FROM bank_recon_cleared WHERE gl_id=?");
    $uname = (current_user()['username'] ?? '');
    foreach ($gl_ids as $gid) { if ($cleared) $ins->execute([$gid, $bid, $cdate, $uname]); else $del->execute([$gid]); $n++; }
    ok(['updated' => $n, 'cleared' => $cleared, 'message' => "$n line(s) " . ($cleared ? 'cleared' : 'set outstanding')]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3e — Financial statements derived from the general ledger: Income &
// Expenditure, Statement of Financial Position, and Cash Flow. Mirrors the Python
// GL-based statements; because every JV is balanced, Assets == Liabilities + Net
// Assets holds by construction.
// ════════════════════════════════════════════════════════════════════════════
// ── Institutional read-scoping: resolve a node code → its org subtree's unit_ids,
//    and a user's scope (own_unit | subtree | university) → the unit_ids they may see.
function org_subtree_ids(string $code): array {
    $units = db()->query('SELECT id,code,parent_code FROM org_units')->fetchAll();
    $byParent = []; $idByCode = [];
    foreach ($units as $u) { $byParent[(string)($u['parent_code'] ?? '')][] = $u['code']; $idByCode[$u['code']] = $u['id']; }
    $ids = []; $stack = [$code];
    while ($stack) { $c = array_pop($stack); if (isset($idByCode[$c])) $ids[] = $idByCode[$c]; foreach (($byParent[$c] ?? []) as $ch) $stack[] = $ch; }
    return $ids;
}
function resolve_read_scope(array $u, ?string $node): ?array {
    // null = unrestricted (whole university); array = the unit_ids the viewer may see.
    if ($node) {
        // Accept either an org-unit code or an id; normalise to a code for the subtree walk.
        $chk = db()->prepare('SELECT code FROM org_units WHERE code=? OR id=? LIMIT 1'); $chk->execute([$node, $node]);
        $code = $chk->fetchColumn() ?: $node;
        $ids = org_subtree_ids($code); return $ids ?: ['__none__'];
    }
    $r = db()->prepare('SELECT home_unit_id, scope FROM users WHERE username=?'); $r->execute([$u['username']]); $row = $r->fetch();
    $scope = $row['scope'] ?? 'university'; $home = $row['home_unit_id'] ?? null;
    if ($scope === 'university') return null;
    if (!$home) return ['__none__'];
    $hc = db()->prepare('SELECT code FROM org_units WHERE id=?'); $hc->execute([$home]); $hcode = $hc->fetchColumn();
    if ($scope === 'subtree' && $hcode) return org_subtree_ids($hcode);
    return [$home]; // own_unit
}
function gl_scope_sql(array $u, ?string $node): array {
    $scope = resolve_read_scope($u, $node);
    if ($scope === null) return ['', []];
    $ph = implode(',', array_fill(0, count($scope), '?'));
    return [" AND gl.unit_id IN ($ph)", $scope];
}
// Generic ' AND <alias>.unit_id IN (...)' scope clause for any list/detail query
// whose rows carry unit_id (mirror server.py _ucc_gl_scope_clause). Empty fragment
// = unrestricted (Admin/university). Rows with a NULL unit are hidden from scoped
// users — same behaviour as the Python _ucc_filter_rows_by_scope row filter.
function unit_scope_sql(array $u, string $alias = 't', ?string $node = null): array {
    $scope = resolve_read_scope($u, $node);
    if ($scope === null) return ['', []];
    $ph = implode(',', array_fill(0, count($scope), '?'));
    return [" AND $alias.unit_id IN ($ph)", $scope];
}
function gl_net_by_type(string $type, string $w = '', array $p = []): float {
    // Σ(debit − credit) for accounts of this account_type (positive = net debit).
    $q = "SELECT COALESCE(SUM(gl.debit_amount - gl.credit_amount),0)
          FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
          WHERE c.account_type=? $w";
    $st = db()->prepare($q); $st->execute(array_merge([$type], $p));
    return round((float)$st->fetchColumn(), 2);
}
function gl_date_filter(): array {
    $w = ''; $p = [];
    $df = $_GET['date_from'] ?? ($_GET['period_from'] ?? null);
    $dt = $_GET['date_to'] ?? ($_GET['period_to'] ?? null);
    if ($df) { $w .= ' AND gl.ledger_date>=?'; $p[] = $df; }
    if ($dt) { $w .= ' AND gl.ledger_date<=?'; $p[] = $dt; }
    return [$w, $p];
}
// AS-AT filter: a balance sheet (and a cash *balance*) is cumulative to the
// reporting date — it must include everything up to date_to and IGNORE date_from
// (otherwise it becomes a period statement and drops prior balances).
function gl_asat_filter(): array {
    $dt = $_GET['date_to'] ?? ($_GET['period_to'] ?? null);
    if ($dt) return [' AND gl.ledger_date<=?', [substr((string)$dt, 0, 10)]];
    return ['', []];
}
function api_income_expenditure(): void {
    $u = require_auth();
    [$w, $p] = gl_date_filter();
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null)); $w .= $sw; $p = array_merge($p, $sp);
    $income = round(-gl_net_by_type('Income', $w, $p), 2);     // credit-heavy → negate
    $expenditure = gl_net_by_type('Expense', $w, $p);          // debit-heavy
    $surplus = round($income - $expenditure, 2);
    // per-account expenditure breakdown
    $rows = db()->prepare("SELECT gl.coa_code, gl.account_name, c.account_type,
        ROUND(COALESCE(SUM(gl.debit_amount-gl.credit_amount),0),2) AS net
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE c.account_type IN ('Income','Expense') $w
        GROUP BY gl.coa_code, gl.account_name, c.account_type ORDER BY gl.coa_code");
    $rows->execute($p);
    // Per-account income/expenditure arrays (label/amount) consumed by the suite + UI.
    $eq = db()->prepare("SELECT gl.account_name AS label, ROUND(SUM(gl.debit_amount-gl.credit_amount),2) AS amount
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE c.account_type='Expense' $w GROUP BY gl.account_name HAVING amount<>0 ORDER BY gl.account_name");
    $eq->execute($p); $expenditure_lines = $eq->fetchAll();
    $iq = db()->prepare("SELECT gl.account_name AS label, ROUND(SUM(gl.credit_amount-gl.debit_amount),2) AS amount
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE c.account_type='Income' $w GROUP BY gl.account_name HAVING amount<>0 ORDER BY gl.account_name");
    $iq->execute($p); $income_lines = $iq->fetchAll();
    send(['ok' => true, 'total_income' => $income, 'total_expenditure' => $expenditure,
          'surplus_deficit' => $surplus, 'income' => $income_lines, 'expenditure' => $expenditure_lines,
          'lines' => $rows->fetchAll()]);
}
function api_statutory_filings(): void {
    require_auth();
    $month = $_GET['month'] ?? date('Y-m');
    // WHT / WHVAT from posted payment vouchers in the month; vendor TIN joined by payee.
    $wq = db()->prepare("SELECT a.wht_amount, a.whvat_amount, a.wht_rate, a.wht_type, a.payee, v.tin
        FROM actuals a LEFT JOIN vendors v ON v.vendor_name=a.payee
        WHERE substr(a.expense_date,1,7)=? AND COALESCE(a.is_posted,0)=1");
    $wq->execute([$month]);
    $wht_rows = []; $wht_total = 0.0; $whvat_total = 0.0;
    foreach ($wq->fetchAll() as $r) {
        $w = (float)($r['wht_amount'] ?? 0); $wv = (float)($r['whvat_amount'] ?? 0);
        if ($w > 0) { $wht_total += $w; $wht_rows[] = ['tin' => $r['tin'], 'rate' => (float)($r['wht_rate'] ?? 0), 'amount' => round($w, 2), 'type' => $r['wht_type'], 'payee' => $r['payee']]; }
        $whvat_total += $wv;
    }
    // PAYE / SSNIT from the approved payroll register for the month. Each tier's
    // pensionable "basic" counts only that tier's members, so the rate ratio holds
    // even when non-member employees are present.
    $pq = db()->prepare("SELECT COALESCE(SUM(paye),0) paye,
        COALESCE(SUM(employee_tier1+employer_tier1),0) t1total,
        COALESCE(SUM(CASE WHEN employer_tier1>0 THEN pensionable_emoluments ELSE 0 END),0) t1basic,
        COALESCE(SUM(employer_tier2),0) t2total,
        COALESCE(SUM(CASE WHEN employer_tier2>0 THEN pensionable_emoluments ELSE 0 END),0) t2basic
        FROM payroll_register WHERE payroll_month=? AND status IN ('Approved','Posted')");
    $pq->execute([$month]); $p = $pq->fetch() ?: [];
    send(['ok' => true, 'month' => $month,
        'wht' => ['total' => round($wht_total, 2), 'rows' => $wht_rows],
        'whvat' => ['total' => round($whvat_total, 2)],
        'paye' => ['total' => round((float)($p['paye'] ?? 0), 2)],
        'tier1' => ['total' => round((float)($p['t1total'] ?? 0), 2), 'basic' => round((float)($p['t1basic'] ?? 0), 2)],
        'tier2' => ['total' => round((float)($p['t2total'] ?? 0), 2), 'basic' => round((float)($p['t2basic'] ?? 0), 2)]]);
}
function api_ledger_reset_zero(): void {
    $u = require_role(['Admin']); $d = body();
    if (($d['confirm'] ?? '') !== 'RESET') err("confirm:'RESET' is required to zero the ledger");
    // Clear all transactional/ledger data; preserve master data (COA, users, projects,
    // banks, budgets, employees, vendors, accounting periods).
    $tx = ['general_ledger', 'journal_vouchers', 'jv_lines', 'actuals', 'actual_lines', 'fund_receipts',
        'payroll_register', 'depreciation_runs', 'withholding_payables', 'ar_invoices', 'ar_invoice_lines',
        'ap_bills', 'ap_bill_lines', 'petty_cash_vouchers', 'petty_cash_floats', 'inv_movements', 'fuel_coupon_batches'];
    foreach ($tx as $t) { try { db()->exec("DELETE FROM $t"); } catch (Throwable $e) {} }
    try { db()->exec('UPDATE inv_items SET qty_on_hand=0, avg_cost=0'); } catch (Throwable $e) {}
    try { db()->exec('UPDATE asset_register SET accumulated_depreciation=0, carrying_amount=acquisition_cost'); } catch (Throwable $e) {}
    ok(['reset' => true]);
}
function api_year_end_status(): void {
    require_auth();
    $income = round(-gl_net_by_type('Income'), 2);
    $exp = gl_net_by_type('Expense');
    send(['ok' => true, 'total_income' => $income, 'total_expenditure' => $exp, 'surplus_deficit' => round($income - $exp, 2)]);
}
function api_sfp(): void {
    $u = require_auth();
    [$w, $p] = gl_asat_filter();   // balance sheet is cumulative AS-AT date_to
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null)); $w .= $sw; $p = array_merge($p, $sp);
    $assets = gl_net_by_type('Asset', $w, $p);
    $liabilities = round(-gl_net_by_type('Liability', $w, $p), 2);
    $equity = round(-gl_net_by_type('Equity', $w, $p), 2);
    $income = round(-gl_net_by_type('Income', $w, $p), 2);
    $expenditure = gl_net_by_type('Expense', $w, $p);
    $surplus = round($income - $expenditure, 2);
    $net_assets = round($equity + $surplus, 2);
    $diff = round($assets - ($liabilities + $net_assets), 2);
    // cash component of assets (bank/cash accounts)
    $cashq = db()->prepare("SELECT COALESCE(SUM(gl.debit_amount-gl.credit_amount),0)
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE (c.code LIKE '127%' OR c.code LIKE '126%') $w");
    $cashq->execute($p); $cash = round((float)$cashq->fetchColumn(), 2);
    // Withholding (WHT/WHVAT/UCF) control-account balance — shown on its own SFP line.
    $whtq = db()->prepare("SELECT COALESCE(SUM(gl.credit_amount-gl.debit_amount),0)
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE c.code IN ('21100014','21100024','21100027','2030','2031','2033') $w");
    $whtq->execute($p); $wht_held = round((float)$whtq->fetchColumn(), 2);
    send(['ok' => true,
          // Nested shape consumed by the acceptance suite + UI…
          'assets' => ['total' => $assets, 'cash_and_bank' => $cash],
          'liabilities' => ['total' => $liabilities, 'wht_held' => $wht_held],
          'equity' => ['contributed' => $equity, 'accumulated_surplus' => $surplus, 'total' => $net_assets],
          'net_assets' => $net_assets, 'accumulated_fund' => $equity, 'surplus_deficit' => $surplus,
          'presentation_difference' => $diff, 'basis' => 'general_ledger', 'balances' => abs($diff) < 0.02,
          // …plus flat keys for convenience.
          'total_assets' => $assets, 'total_liabilities' => $liabilities, 'cash' => $cash]);
}
function api_cashflow(): void {
    $u = require_auth();
    [$w, $p] = gl_asat_filter();   // closing cash is the cumulative balance AS-AT date_to
    [$sw, $sp] = gl_scope_sql($u, $_GET['unit'] ?? ($_GET['unit_code'] ?? null)); $w .= $sw; $p = array_merge($p, $sp);
    $cashq = db()->prepare("SELECT COALESCE(SUM(gl.debit_amount-gl.credit_amount),0)
        FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id
        WHERE (c.code LIKE '127%' OR c.code LIKE '126%') $w");
    $cashq->execute($p); $closing = round((float)$cashq->fetchColumn(), 2);
    // Operating proxy: net movement on cash accounts over the period (single-fund basis).
    send(['ok' => true, 'closing_cash' => $closing, 'net_change_in_cash' => $closing,
          'basis' => 'general_ledger']);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3d (inventory) — stores ledger. Receipt: Dr Inventory / Cr Bank (moving-
// average cost). Issue: Dr Expense / Cr Inventory at average cost. Mirrors
// server.py api_inv_receipt / api_inv_issue.
// ════════════════════════════════════════════════════════════════════════════
function ensure_inv_tables(): void {
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS inv_items(id TEXT PRIMARY KEY, item_code TEXT, item_name TEXT NOT NULL, category TEXT, unit TEXT DEFAULT 'each', reorder_level REAL DEFAULT 0, inventory_coa_id TEXT, expense_coa_id TEXT, qty_on_hand REAL DEFAULT 0, avg_cost REAL DEFAULT 0, notes TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS inv_movements(id TEXT PRIMARY KEY, movement_number TEXT, item_id TEXT, movement_type TEXT, movement_date TEXT, qty REAL DEFAULT 0, unit_cost REAL DEFAULT 0, total_cost REAL DEFAULT 0, party TEXT, bank_account_id TEXT, project_id TEXT, reference TEXT, jv_id TEXT, jv_number TEXT, notes TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    ensure_col('inv_movements', 'unit_id');
}
function inv_asset_coa(): ?string {
    foreach (["code='12107006'", "(code LIKE '121%' AND LOWER(account_name) LIKE '%stock%')", "(LOWER(account_name) LIKE '%stock%' OR LOWER(account_name) LIKE '%inventor%')"] as $w) {
        $r = db()->query("SELECT id FROM chart_of_accounts WHERE $w ORDER BY code LIMIT 1")->fetch();
        if ($r) return $r['id'];
    }
    return null;
}
function api_inv_items_list(): void { ensure_inv_tables(); require_auth(); send(['ok' => true, 'items' => db()->query('SELECT * FROM inv_items ORDER BY item_name')->fetchAll()]); }
function api_inv_item_save(): void {
    ensure_inv_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['item_name'])) err('item_name is required');
    require_write_unit($u, $d, 'inventory item');
    $id = uuid4(); $code = $d['item_code'] ?? seq_code('inv_items', 'item_code', 'ITM-', 4);
    db()->prepare('INSERT INTO inv_items(id,item_code,item_name,category,unit,inventory_coa_id,expense_coa_id,qty_on_hand,avg_cost,created_by) VALUES(?,?,?,?,?,?,?,0,0,?)')
        ->execute([$id, $code, $d['item_name'], $d['category'] ?? 'General', $d['unit'] ?? 'each',
            $d['inventory_coa_id'] ?? inv_asset_coa(), $d['expense_coa_id'] ?? null, $u['username']]);
    ok(['id' => $id, 'item_code' => $code]);
}
function _inv_item(string $id): ?array { $st = db()->prepare('SELECT * FROM inv_items WHERE id=?'); $st->execute([$id]); return $st->fetch() ?: null; }
function api_inv_receipt(): void {
    ensure_inv_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $it = _inv_item((string)($d['item_id'] ?? '')); if (!$it) err('Item not found');
    require_write_unit($u, $d, 'inventory receipt');
    $qty = round((float)($d['qty'] ?? 0), 3); $uc = round((float)($d['unit_cost'] ?? 0), 4);
    if ($qty <= 0) err('Receipt quantity must be > 0'); if ($uc < 0) err('Unit cost cannot be negative');
    $total = round($qty * $uc, 2);
    $jvnum = null;
    if ($total > 0) {
        $bank = operating_bank_coa(); $inv = $it['inventory_coa_id'] ?: inv_asset_coa();
        if (!$bank || !$inv) err('Bank or inventory account could not be resolved');
        $date = $d['movement_date'] ?? date('Y-m-d');
        $desc = 'Stock receipt: ' . $it['item_name'] . ' x' . $qty;
        $lines = [['coa_id' => $inv, 'debit_amount' => $total, 'credit_amount' => 0, 'description' => $desc, 'project_id' => $d['project_id'] ?? null],
                  ['coa_id' => $bank['id'], 'debit_amount' => 0, 'credit_amount' => $total, 'description' => $desc, 'project_id' => $d['project_id'] ?? null]];
        try { [$jid, $jvnum] = post_journal($u, 'JV', $date, substr($date, 0, 7), $desc, $lines, 'inv_movements', $it['id'], resolve_write_unit($u, $d)); }
        catch (Throwable $e) { err('Could not post stock receipt: ' . $e->getMessage()); }
    }
    $oldQ = round((float)$it['qty_on_hand'], 3); $newQ = round($oldQ + $qty, 3);
    $newAvg = $newQ > 0 ? round(($oldQ * (float)$it['avg_cost'] + $qty * $uc) / $newQ, 4) : 0;
    db()->prepare('UPDATE inv_items SET qty_on_hand=?, avg_cost=? WHERE id=?')->execute([$newQ, $newAvg, $it['id']]);
    $mnum = seq_code('inv_movements', 'movement_number', 'GRN-', 4);
    db()->prepare("INSERT INTO inv_movements(id,movement_number,item_id,movement_type,movement_date,qty,unit_cost,total_cost,party,project_id,reference,jv_number,created_by) VALUES(?,?,?,'Receipt',?,?,?,?,?,?,?,?,?)")
        ->execute([uuid4(), $mnum, $it['id'], $d['movement_date'] ?? date('Y-m-d'), $qty, $uc, $total, $d['party'] ?? '', $d['project_id'] ?? null, $d['reference'] ?? '', $jvnum, $u['username']]);
    ok(['movement_number' => $mnum, 'qty_on_hand' => $newQ, 'avg_cost' => $newAvg, 'jv_number' => $jvnum]);
}
function api_inv_issue(): void {
    ensure_inv_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $it = _inv_item((string)($d['item_id'] ?? '')); if (!$it) err('Item not found');
    require_write_unit($u, $d, 'inventory issue');
    $qty = round((float)($d['qty'] ?? 0), 3); if ($qty <= 0) err('Issue quantity must be > 0');
    $onHand = round((float)$it['qty_on_hand'], 3);
    if ($qty > $onHand + 0.0001) err("Cannot issue $qty — only $onHand in stock");
    $avg = round((float)$it['avg_cost'], 4); $cost = round($qty * $avg, 2);
    $jvnum = null;
    if ($cost > 0) {
        $inv = $it['inventory_coa_id'] ?: inv_asset_coa(); $exp = $it['expense_coa_id'];
        if (!$inv || !$exp) err('Inventory or expense account could not be resolved');
        $date = $d['movement_date'] ?? date('Y-m-d');
        $desc = 'Stock issue: ' . $it['item_name'] . ' x' . $qty;
        $lines = [['coa_id' => $exp, 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => $desc, 'project_id' => $d['project_id'] ?? null],
                  ['coa_id' => $inv, 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => $desc, 'project_id' => $d['project_id'] ?? null]];
        try { [$jid, $jvnum] = post_journal($u, 'JV', $date, substr($date, 0, 7), $desc, $lines, 'inv_movements', $it['id'], resolve_write_unit($u, $d)); }
        catch (Throwable $e) { err('Could not post stock issue: ' . $e->getMessage()); }
    }
    $newQ = round($onHand - $qty, 3);
    db()->prepare('UPDATE inv_items SET qty_on_hand=? WHERE id=?')->execute([$newQ, $it['id']]);
    $mnum = seq_code('inv_movements', 'movement_number', 'ISS-', 4);
    db()->prepare("INSERT INTO inv_movements(id,movement_number,item_id,movement_type,movement_date,qty,unit_cost,total_cost,party,project_id,jv_number,created_by) VALUES(?,?,?,'Issue',?,?,?,?,?,?,?,?)")
        ->execute([uuid4(), $mnum, $it['id'], $d['movement_date'] ?? date('Y-m-d'), $qty, $avg, $cost, $d['party'] ?? '', $d['project_id'] ?? null, $jvnum, $u['username']]);
    ok(['movement_number' => $mnum, 'qty_on_hand' => $newQ, 'cost' => $cost, 'jv_number' => $jvnum]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3d (petty cash) — imprest floats. Setup: Dr Petty Cash / Cr Bank.
// Voucher: Dr Expense / Cr Petty Cash, guarded by the float's book balance.
// Mirrors server.py api_pc2_setup_float / api_pc2_voucher.
// ════════════════════════════════════════════════════════════════════════════
function ensure_pc_tables(): void {
    $p = db();
    $p->exec("CREATE TABLE IF NOT EXISTS petty_cash_floats (id TEXT PRIMARY KEY, name TEXT NOT NULL, custodian TEXT DEFAULT '', imprest_amount REAL NOT NULL DEFAULT 0, coa_id TEXT, bank_account_id TEXT, established_date TEXT, jv_number TEXT, status TEXT DEFAULT 'Active', created_by TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS petty_cash_vouchers (id TEXT PRIMARY KEY, float_id TEXT NOT NULL, pcv_number TEXT, voucher_date TEXT NOT NULL, payee TEXT NOT NULL, description TEXT DEFAULT '', expense_coa_id TEXT NOT NULL, amount_ghs REAL NOT NULL, receipt_ref TEXT DEFAULT '', status TEXT DEFAULT 'Posted', jv_id TEXT, jv_number TEXT, created_by TEXT, created_at TEXT DEFAULT (datetime('now')))");
    foreach (['department_code', 'project_id', 'unit_id'] as $c) ensure_col('petty_cash_floats', $c);
    ensure_col('petty_cash_vouchers', 'unit_id');
    $p->exec("CREATE TABLE IF NOT EXISTS petty_cash_replenishments (id TEXT PRIMARY KEY, float_id TEXT, repl_date TEXT, amount_ghs REAL, jv_number TEXT, created_by TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $p->exec("CREATE TABLE IF NOT EXISTS petty_cash_voucher_lines (id TEXT PRIMARY KEY, voucher_id TEXT, line_no INTEGER, expense_coa_id TEXT, amount_ghs REAL, description TEXT)");
}
function pc2_imprest_coa(?string $coa_id): ?string {
    if ($coa_id) { $r = db()->prepare('SELECT id,code FROM chart_of_accounts WHERE id=?'); $r->execute([$coa_id]); $x = $r->fetch(); if ($x && (str_starts_with((string)$x['code'], '12') || $x['code'] === '1001')) return $x['id']; }
    foreach (["(LOWER(account_name) LIKE '%imprest%' AND code LIKE '129%')", "((LOWER(account_name) LIKE '%imprest%' OR LOWER(account_name) LIKE '%petty%') AND code LIKE '12%')", "code LIKE '129%'"] as $w) {
        $x = db()->query("SELECT id FROM chart_of_accounts WHERE $w ORDER BY code LIMIT 1")->fetch();
        if ($x) return $x['id'];
    }
    return null;
}
function pc_book_balance(string $fid): float {
    $f = db()->prepare('SELECT imprest_amount FROM petty_cash_floats WHERE id=?'); $f->execute([$fid]); $imp = (float)($f->fetchColumn() ?: 0);
    $v = db()->prepare("SELECT COALESCE(SUM(amount_ghs),0) FROM petty_cash_vouchers WHERE float_id=? AND COALESCE(status,'Posted')='Posted'"); $v->execute([$fid]); $sp = (float)$v->fetchColumn();
    // Cash on hand = imprest − unreplenished spend; replenishments draw cash back in.
    $rp = 0.0; try { $rq = db()->prepare("SELECT COALESCE(SUM(amount_ghs),0) FROM petty_cash_replenishments WHERE float_id=?"); $rq->execute([$fid]); $rp = (float)$rq->fetchColumn(); } catch (Throwable $e) {}
    return round($imp - $sp + $rp, 2);
}
function api_pc2_state(): void {
    ensure_pc_tables(); require_auth();
    $floats = db()->query('SELECT * FROM petty_cash_floats ORDER BY created_at')->fetchAll();
    $coas = [];
    foreach ($floats as &$f) { $f['book_balance'] = pc_book_balance($f['id']); if (!empty($f['coa_id'])) $coas[$f['coa_id']] = true; }
    unset($f);
    if ($coas) { $qm = implode(',', array_fill(0, count($coas), '?')); $g = db()->prepare("SELECT COALESCE(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_id IN ($qm)"); $g->execute(array_keys($coas)); $gl = round((float)$g->fetchColumn(), 2); }
    else $gl = 0.0;
    $tb = 0.0; foreach ($floats as $f) $tb += (float)$f['book_balance']; $tb = round($tb, 2);
    // every official imprest GL account (129x family + any imprest/petty-named 12x) so any
    // department can pick its own float account.
    $imprest = db()->query("SELECT id, code, account_name FROM chart_of_accounts WHERE (code LIKE '129%' OR LOWER(account_name) LIKE '%imprest%' OR LOWER(account_name) LIKE '%petty%') AND code LIKE '12%' ORDER BY code")->fetchAll();
    send(['ok' => true, 'floats' => $floats, 'gl_balance' => $gl, 'total_book_balance' => $tb,
        'gl_tie_ok' => abs($gl - $tb) < 0.01, 'imprest_accounts' => $imprest,
        'vouchers' => db()->query('SELECT * FROM petty_cash_vouchers ORDER BY created_at DESC LIMIT 100')->fetchAll(),
        'replenishments' => db()->query('SELECT * FROM petty_cash_replenishments ORDER BY repl_date DESC LIMIT 30')->fetchAll()]);
}
function api_pc2_setup_float(): void {
    ensure_pc_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $name = trim((string)($d['name'] ?? '')); if ($name === '') err('Float name is required');
    $imp = round((float)($d['imprest_amount'] ?? 0), 2); if ($imp <= 0) err('imprest_amount must be greater than zero');
    if (empty($d['department_code']) && empty($d['project_id'])) err('Tie the float to a department/unit (or a project) for accountability');
    $pc = pc2_imprest_coa($d['coa_id'] ?? null); $bank = operating_bank_coa();
    if (!$pc || !$bank) err('Petty cash or bank account could not be resolved');
    $edate = substr((string)($d['established_date'] ?? ($d['date'] ?? date('Y-m-d'))), 0, 10);
    $lines = [['coa_id' => $pc, 'debit_amount' => $imp, 'credit_amount' => 0, 'description' => "Petty cash float established — $name"],
              ['coa_id' => $bank['id'], 'debit_amount' => 0, 'credit_amount' => $imp, 'description' => "Cash drawn for petty cash float $name"]];
    $unit = resolve_write_unit($u, $d);
    try { [$jid, $jvnum] = post_journal($u, 'JV', $edate, substr($edate, 0, 7), "Petty cash float establishment — $name", $lines, 'petty_cash_float', null, $unit); }
    catch (Throwable $e) { err('Could not post the float establishment: ' . $e->getMessage()); }
    $fid = uuid4();
    db()->prepare("INSERT INTO petty_cash_floats(id,name,custodian,imprest_amount,coa_id,established_date,jv_number,status,created_by,department_code,project_id,unit_id) VALUES(?,?,?,?,?,?,?,'Active',?,?,?,?)")
        ->execute([$fid, $name, $d['custodian'] ?? '', $imp, $pc, $edate, $jvnum, $u['username'], $d['department_code'] ?? null, $d['project_id'] ?? null, $unit]);
    ok(['id' => $fid, 'jv_number' => $jvnum, 'imprest' => $imp]);
}
function api_pc2_voucher(): void {
    ensure_pc_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $fid = (string)($d['float_id'] ?? ''); if ($fid === '') err('float_id is required');
    $payee = trim((string)($d['payee'] ?? '')); if ($payee === '') err('payee is required');
    // Multi-line disbursement: split across several expense accounts (Dr each / Cr the
    // float's imprest for the total). Backward-compatible with single expense_coa_id+amount.
    $norm = [];
    if (isset($d['lines']) && is_array($d['lines']) && $d['lines']) {
        foreach ($d['lines'] as $ln) { $a = round((float)($ln['amount_ghs'] ?? ($ln['amount'] ?? 0)), 2); $coa = $ln['expense_coa_id'] ?? ($ln['coa_id'] ?? null); if ($a > 0 && $coa) $norm[] = ['coa_id' => $coa, 'amount' => $a, 'description' => trim((string)($ln['description'] ?? ''))]; }
        if (!$norm) err('Add at least one expense line (account + amount > 0)');
    } else {
        $exp = $d['expense_coa_id'] ?? null; $a = round((float)($d['amount_ghs'] ?? 0), 2);
        if (!$exp || $a <= 0) err('expense_coa_id and amount_ghs (> 0) are required');
        $norm = [['coa_id' => $exp, 'amount' => $a, 'description' => trim((string)($d['description'] ?? ''))]];
    }
    $amt = round(array_sum(array_column($norm, 'amount')), 2);
    $f = db()->prepare('SELECT * FROM petty_cash_floats WHERE id=?'); $f->execute([$fid]); $fl = $f->fetch();
    if (!$fl) err('Float not found');
    $bal = pc_book_balance($fid);
    if ($amt > $bal + 0.001) err(sprintf('Insufficient float balance: available GHS %.2f, voucher GHS %.2f. Replenish the float first.', $bal, $amt));
    $vdate = substr((string)($d['voucher_date'] ?? date('Y-m-d')), 0, 10);
    $lines = [];
    foreach ($norm as $n) $lines[] = ['coa_id' => $n['coa_id'], 'debit_amount' => $n['amount'], 'credit_amount' => 0, 'description' => ($n['description'] ?: ('Petty cash: ' . $payee))];
    $lines[] = ['coa_id' => $fl['coa_id'], 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Disbursed from float ' . $fl['name']];
    $unit = ($fl['unit_id'] ?? null) ?: resolve_write_unit($u, $d);
    try { [$jid, $jvnum] = post_journal($u, 'JV', $vdate, substr($vdate, 0, 7), 'Petty cash voucher — ' . $payee, $lines, 'petty_cash_voucher', $fid, $unit); }
    catch (Throwable $e) { err('Could not post the voucher: ' . $e->getMessage()); }
    $vid = uuid4(); $pcv = seq_code('petty_cash_vouchers', 'pcv_number', 'PCV-', 4);
    db()->prepare("INSERT INTO petty_cash_vouchers(id,float_id,pcv_number,voucher_date,payee,description,expense_coa_id,amount_ghs,status,jv_number,created_by,unit_id) VALUES(?,?,?,?,?,?,?,?,'Posted',?,?,?)")
        ->execute([$vid, $fid, $pcv, $vdate, $payee, $d['description'] ?? '', $norm[0]['coa_id'], $amt, $jvnum, $u['username'], $unit]);
    $ln = 0; $li = db()->prepare("INSERT INTO petty_cash_voucher_lines(id,voucher_id,line_no,expense_coa_id,amount_ghs,description) VALUES(?,?,?,?,?,?)");
    foreach ($norm as $n) { $ln++; $li->execute([uuid4(), $vid, $ln, $n['coa_id'], $n['amount'], $n['description']]); }
    ok(['id' => $vid, 'pcv_number' => $pcv, 'jv_number' => $jvnum, 'lines' => count($norm), 'total_ghs' => $amt, 'balance_after' => round($bal - $amt, 2)]);
}
function api_pc2_ledger(): void {
    ensure_pc_tables(); require_auth();
    $fid = (string)($_GET['float_id'] ?? ''); if ($fid === '') err('float_id is required');
    $f = db()->prepare('SELECT * FROM petty_cash_floats WHERE id=?'); $f->execute([$fid]); $fl = $f->fetch(); if (!$fl) err('Float not found');
    $fl['book_balance'] = pc_book_balance($fid);
    $vs = db()->prepare('SELECT * FROM petty_cash_vouchers WHERE float_id=? ORDER BY voucher_date, created_at'); $vs->execute([$fid]);
    $rs = db()->prepare('SELECT * FROM petty_cash_replenishments WHERE float_id=? ORDER BY repl_date'); $rs->execute([$fid]);
    ok(['float' => $fl, 'vouchers' => $vs->fetchAll(), 'replenishments' => $rs->fetchAll()]);
}
function api_pc2_replenish(): void {
    ensure_pc_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $fid = (string)($d['float_id'] ?? ''); if ($fid === '') err('float_id is required');
    $f = db()->prepare('SELECT * FROM petty_cash_floats WHERE id=?'); $f->execute([$fid]); $fl = $f->fetch(); if (!$fl) err('Float not found');
    $book = pc_book_balance($fid); $topup = round((float)$fl['imprest_amount'] - $book, 2);
    if ($topup <= 0.01) err('Float is already at its imprest level — nothing to replenish');
    $bankc = bank_coa_from_account((string)($fl['bank_account_id'] ?? '')); if (!$bankc) { $b = operating_bank_coa(); $bankc = $b['id'] ?? null; }
    if (!$bankc) err('Bank account could not be resolved');
    $rdate = substr((string)($d['date'] ?? date('Y-m-d')), 0, 10);
    $lines = [['coa_id' => $fl['coa_id'], 'debit_amount' => $topup, 'credit_amount' => 0, 'description' => 'Petty cash replenishment — ' . $fl['name']],
              ['coa_id' => $bankc, 'debit_amount' => 0, 'credit_amount' => $topup, 'description' => 'Cash to replenish ' . $fl['name']]];
    try { [$jid, $jvnum] = post_journal($u, 'JV', $rdate, substr($rdate, 0, 7), 'Petty cash replenishment — ' . $fl['name'], $lines, 'petty_cash_replenish', $fid, $fl['unit_id'] ?? null); }
    catch (Throwable $e) { err('Could not post the replenishment: ' . $e->getMessage()); }
    db()->prepare("INSERT INTO petty_cash_replenishments(id,float_id,repl_date,amount_ghs,jv_number,created_by) VALUES(?,?,?,?,?,?)")->execute([uuid4(), $fid, $rdate, $topup, $jvnum, $u['username']]);
    ok(['jv_number' => $jvnum, 'amount' => $topup, 'book_balance' => pc_book_balance($fid)]);
}
function api_pc2_void(): void {
    ensure_pc_tables(); $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $vid = (string)($d['voucher_id'] ?? ($d['id'] ?? '')); if ($vid === '') err('voucher_id is required');
    $v = db()->prepare('SELECT * FROM petty_cash_vouchers WHERE id=?'); $v->execute([$vid]); $vc = $v->fetch(); if (!$vc) err('Voucher not found');
    if ((string)($vc['status'] ?? '') === 'Voided') err('Voucher already voided');
    $g = db()->prepare('SELECT * FROM general_ledger WHERE jv_number=?'); $g->execute([$vc['jv_number']]); $rows = $g->fetchAll();
    if ($rows) {
        $rdate = (string)$vc['voucher_date']; $lines = [];
        foreach ($rows as $r) $lines[] = ['coa_id' => $r['coa_id'], 'debit_amount' => money($r['credit_amount']), 'credit_amount' => money($r['debit_amount']), 'description' => 'Void PCV ' . (string)($vc['pcv_number'] ?? '')];
        try { post_journal($u, 'JV', $rdate, substr($rdate, 0, 7), 'Void petty cash voucher ' . (string)($vc['pcv_number'] ?? ''), $lines, 'petty_cash_void', $vid, $vc['unit_id'] ?? null); } catch (Throwable $e) {}
    }
    db()->prepare("UPDATE petty_cash_vouchers SET status='Voided' WHERE id=?")->execute([$vid]);
    ok(['voided' => $vid, 'book_balance' => pc_book_balance((string)$vc['float_id'])]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 3c (payroll) — Ghana PAYE (graduated bands) + 3-tier SSNIT + GRA reliefs.
// Faithful port of server.py calc_employee_payroll / calc_ghana_paye and the
// IPSAS-25 payroll GL journal. PAYE/SSNIT verified against the Python reference
// (basic 5000 → PAYE 779.75, SSNIT Tier-1 675).
// ════════════════════════════════════════════════════════════════════════════
function payroll_settings(): array {
    $out = [];
    foreach (db()->query('SELECT key,value FROM payroll_settings')->fetchAll() as $r) $out[$r['key']] = $r['value'];
    return $out;
}
function paye_bands(): array {
    $maxd = db()->query('SELECT MAX(effective_date) FROM paye_bands')->fetchColumn();
    $st = db()->prepare('SELECT annual_min, annual_max, rate FROM paye_bands WHERE effective_date=? ORDER BY band_order');
    $st->execute([$maxd]);
    return $st->fetchAll();
}
function calc_ghana_paye(float $annual, bool $resident, array $s, array $bands): float {
    if (!$resident) return $annual * (float)($s['nonresident_tax_rate'] ?? 0.20);
    $tax = 0.0; $remaining = $annual;
    foreach ($bands as $b) {
        if ($remaining <= 0) break;
        $width = ($b['annual_max'] !== null && $b['annual_max'] !== '') ? (float)$b['annual_max'] : INF;
        $slice = min($remaining, $width);
        $tax += $slice * (float)$b['rate'];
        $remaining -= $slice;
    }
    return $tax;
}
function calc_employee_payroll(array $e, string $month, array $s, array $bands): array {
    $f = fn($k) => (float)($e[$k] ?? 0);
    $basic = $f('basic_salary'); $market = $f('market_premium');
    $housing = $f('housing_allowance'); $transport = $f('transport_allowance');
    $utility = $f('utility_allowance'); $research = $f('research_allowance'); $other_allow = $f('other_allowance');
    $gross = $basic + $market + $housing + $transport + $utility + $research + $other_allow;
    $pensionable = $basic + $market;
    $resident = ($e['tax_residency'] ?? 'Resident') === 'Resident';
    $has_ssnit = ((int)($e['tier1_member'] ?? 1)) && !in_array($e['employment_type'] ?? '', ['Casual', 'Visiting', 'Contract'], true);
    $has_tier2 = ((int)($e['tier2_member'] ?? 1)) && $has_ssnit;
    $has_tier3 = (int)($e['tier3_member'] ?? 0);
    $emp_tier1 = $has_ssnit ? $pensionable * (float)($s['ssnit_employee_rate'] ?? 0.055) : 0.0;
    $empr_tier1 = $has_ssnit ? $pensionable * (float)($s['tier1_employer_rate'] ?? 0.08) : 0.0;
    $empr_tier2 = $has_tier2 ? $pensionable * (float)($s['tier2_employer_rate'] ?? 0.05) : 0.0;
    $emp_tier3 = $has_tier3 ? $pensionable * (float)($s['tier3_employee_rate'] ?? 0.0) : 0.0;
    $relief = 0.0;
    if ($resident) {
        $relief += $f('tax_relief_marriage') / 12;
        $relief += min((int)$f('tax_relief_children'), (int)($s['child_relief_max'] ?? 3)) * (float)($s['child_relief_per'] ?? 600) / 12;
        $relief += min((int)$f('tax_relief_aged_dependent'), (int)($s['aged_dependent_max'] ?? 2)) * (float)($s['aged_dependent_relief'] ?? 1000) / 12;
        $relief += (int)$f('tax_relief_aged_self') * (float)($s['aged_self_relief'] ?? 1500) / 12;
        $relief += min($f('tax_relief_training'), (float)($s['training_relief'] ?? 2000)) / 12;
        $relief += min($f('tax_relief_life_insurance'), (float)($s['life_insurance_cap'] ?? 2000), $gross * 12 * (float)($s['pension_relief_cap'] ?? 0.165)) / 12;
        if ((int)$f('tax_relief_disabled')) $relief += $gross * (float)($s['disability_relief_pct'] ?? 0.25);
    }
    $taxable = max(0, $gross - $emp_tier1 - $relief);
    $paye = calc_ghana_paye($taxable * 12, $resident, $s, $bands) / 12;
    $loan = $f('monthly_loan_repayment'); $union = $f('union_deduction'); $other = $f('other_deduction');
    $total_ded = $emp_tier1 + $paye + $loan + $union + $other;
    $net = $gross - $total_ded;
    return [
        'payroll_month' => $month, 'employee_id' => $e['id'], 'basic_salary' => round($basic, 2),
        'market_premium' => round($market, 2), 'housing_allowance' => round($housing, 2),
        'transport_allowance' => round($transport, 2), 'utility_allowance' => round($utility, 2),
        'research_allowance' => round($research, 2), 'other_allowance' => round($other_allow, 2),
        'gross_pay' => round($gross, 2), 'pensionable_emoluments' => round($pensionable, 2),
        'employee_tier1' => round($emp_tier1, 2), 'employee_tier2' => 0, 'employee_tier3' => round($emp_tier3, 2),
        'total_employee_pension' => round($emp_tier1 + $emp_tier3, 2), 'taxable_income' => round($taxable, 2),
        'total_relief' => round($relief, 2), 'paye' => round($paye, 2), 'loan_deduction' => round($loan, 2),
        'union_deduction' => round($union, 2), 'other_deduction' => round($other, 2),
        'total_deductions' => round($total_ded, 2), 'net_pay' => round($net, 2),
        'employer_tier1' => round($empr_tier1, 2), 'employer_tier2' => round($empr_tier2, 2),
        'total_employer_cost' => round($gross + $empr_tier1 + $empr_tier2, 2),
        'tax_residency' => $e['tax_residency'] ?? 'Resident', 'division' => $e['division'] ?? '',
        'funding_source' => $e['funding_source'] ?? '', 'project_code' => $e['project_code'] ?? '',
        'unit_id' => $e['unit_id'] ?? null,
    ];
}
function api_payroll_employee_save(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['full_name'])) err('full_name is required');
    require_write_unit($u, $d, 'employee');
    $id = uuid4(); $emp_code = $d['employee_id'] ?? seq_code('employees', 'employee_id', 'EMP-', 4);
    $unit = resolve_write_unit($u, $d);
    $cols = ['id', 'employee_id', 'full_name', 'division', 'employment_type', 'tax_residency', 'basic_salary',
        'market_premium', 'housing_allowance', 'transport_allowance', 'utility_allowance', 'research_allowance',
        'other_allowance', 'tier1_member', 'tier2_member', 'tier3_member', 'monthly_loan_repayment',
        'union_deduction', 'other_deduction', 'tax_relief_marriage', 'tax_relief_children', 'status', 'created_by', 'unit_id'];
    ensure_col('employees', 'unit_id');
    $vals = [$id, $emp_code, $d['full_name'], $d['division'] ?? 'ADMIN', $d['employment_type'] ?? 'Permanent',
        $d['tax_residency'] ?? 'Resident', (float)($d['basic_salary'] ?? 0), (float)($d['market_premium'] ?? 0),
        (float)($d['housing_allowance'] ?? 0), (float)($d['transport_allowance'] ?? 0), (float)($d['utility_allowance'] ?? 0),
        (float)($d['research_allowance'] ?? 0), (float)($d['other_allowance'] ?? 0), (int)($d['tier1_member'] ?? 1),
        (int)($d['tier2_member'] ?? 1), (int)($d['tier3_member'] ?? 0), (float)($d['monthly_loan_repayment'] ?? 0),
        (float)($d['union_deduction'] ?? 0), (float)($d['other_deduction'] ?? 0), (float)($d['tax_relief_marriage'] ?? 0),
        (int)($d['tax_relief_children'] ?? 0), $d['status'] ?? 'Active', $u['username'], $unit];
    db()->prepare('INSERT INTO employees(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    ok(['id' => $id, 'employee_id' => $emp_code]);
}
function api_payroll_register(): void {
    require_auth();
    $m = $_GET['month'] ?? null; $q = 'SELECT r.*, e.full_name FROM payroll_register r JOIN employees e ON r.employee_id=e.id';
    $p = []; if ($m) { $q .= ' WHERE r.payroll_month=?'; $p[] = $m; }
    $q .= ' ORDER BY r.payroll_month DESC';
    $st = db()->prepare($q); $st->execute($p); send(['ok' => true, 'register' => $st->fetchAll()]);
}
function api_payroll_run(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $month = (string)($d['month'] ?? ''); if ($month === '') err('month (YYYY-MM) is required');
    if ((int)db()->query("SELECT COUNT(*) FROM payroll_register WHERE payroll_month=" . db()->quote($month) . " AND status='Approved'")->fetchColumn() > 0)
        err("Payroll for $month is already approved and locked");
    db()->prepare("DELETE FROM payroll_register WHERE payroll_month=? AND status='Draft'")->execute([$month]);
    ensure_col('payroll_register', 'unit_id');
    $s = payroll_settings(); $bands = paye_bands();
    $emps = db()->query("SELECT * FROM employees WHERE status='Active'")->fetchAll();
    $cols = ['id', 'payroll_month', 'employee_id', 'basic_salary', 'market_premium', 'housing_allowance',
        'transport_allowance', 'utility_allowance', 'research_allowance', 'other_allowance', 'gross_pay',
        'pensionable_emoluments', 'employee_tier1', 'employee_tier2', 'employee_tier3', 'total_employee_pension',
        'taxable_income', 'total_relief', 'paye', 'loan_deduction', 'union_deduction', 'other_deduction',
        'total_deductions', 'net_pay', 'employer_tier1', 'employer_tier2', 'total_employer_cost',
        'tax_residency', 'division', 'funding_source', 'project_code', 'status', 'created_by', 'unit_id'];
    $ins = db()->prepare('INSERT INTO payroll_register(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')');
    $n = 0;
    foreach ($emps as $e) {
        $r = calc_employee_payroll($e, $month, $s, $bands);
        $r['id'] = uuid4(); $r['status'] = 'Draft'; $r['created_by'] = $u['username'];
        $ins->execute(array_map(fn($c) => $r[$c] ?? null, $cols));
        $n++;
    }
    ok(['month' => $month, 'count' => $n]);
}
function api_payroll_approve(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $month = (string)($d['month'] ?? ''); if ($month === '') err('month is required');
    if (db()->query("SELECT 1 FROM journal_vouchers WHERE source_module='payroll' AND source_id=" . db()->quote($month) . " AND status='Posted'")->fetch())
        { ok(['gl_posted' => true, 'message' => "Payroll $month already posted"]); }
    db()->prepare("UPDATE payroll_register SET status='Approved', approved_by=?, approved_at=datetime('now') WHERE payroll_month=? AND status='Draft'")->execute([$u['username'], $month]);
    $t = db()->prepare("SELECT COALESCE(SUM(gross_pay),0) g, COALESCE(SUM(paye),0) paye, COALESCE(SUM(employee_tier1),0) e1,
        COALESCE(SUM(employer_tier1),0) r1, COALESCE(SUM(employee_tier2),0) e2, COALESCE(SUM(employer_tier2),0) r2,
        COALESCE(SUM(loan_deduction),0) loan, COALESCE(SUM(union_deduction),0) uni, COALESCE(SUM(other_deduction),0) oth,
        COALESCE(SUM(net_pay),0) net, COALESCE(SUM(total_employer_cost),0) cost
        FROM payroll_register WHERE payroll_month=? AND status='Approved'");
    $t->execute([$month]); $x = $t->fetch();
    if (!$x || (float)$x['cost'] <= 0) ok(['gl_posted' => false, 'message' => 'No approved payroll to post']);
    $cost = round((float)$x['cost'], 2); $paye = round((float)$x['paye'], 2);
    $ssnit = round((float)$x['e1'] + (float)$x['r1'], 2); $tier2 = round((float)$x['e2'] + (float)$x['r2'], 2);
    $ded = round((float)$x['loan'] + (float)$x['uni'] + (float)$x['oth'], 2); $net = round((float)$x['net'], 2);
    $crchk = $paye + $ssnit + $tier2 + $ded + $net;
    if (abs($cost - $crchk) > 0.005) $net = round($net + ($cost - $crchk), 2);
    $empr_ssnit = round((float)$x['r1'] + (float)$x['r2'], 2);
    $gross_basic = round($cost - $empr_ssnit, 2);
    $pcoa = function (...$codes) { foreach ($codes as $c) { $r = db()->prepare('SELECT id FROM chart_of_accounts WHERE code=?'); $r->execute([(string)$c]); if ($v = $r->fetch()) return $v['id']; } return null; };
    $id_sal = $pcoa('61101001', '6000'); $id_ssx = $pcoa('61101003', '61101001');
    $id_paye = $pcoa('21100017', '2010'); $id_ssn = $pcoa('21100015', '2011');
    $id_t2 = $pcoa('21100021', '2012'); $id_ded = $pcoa('21100021', '2038'); $id_net = $pcoa('21200005', '21100021', '2036');
    if (!$id_sal || !$id_paye || !$id_ssn || !$id_net) err('Payroll GL accounts missing from chart of accounts');
    $lines = [['coa_id' => $id_sal, 'debit_amount' => $gross_basic, 'credit_amount' => 0, 'description' => "Salaries & wages $month"]];
    if ($empr_ssnit > 0 && $id_ssx) $lines[] = ['coa_id' => $id_ssx, 'debit_amount' => $empr_ssnit, 'credit_amount' => 0, 'description' => "Employer SSNIT $month"];
    if ($paye > 0) $lines[] = ['coa_id' => $id_paye, 'debit_amount' => 0, 'credit_amount' => $paye, 'description' => 'PAYE payable (GRA)'];
    if ($ssnit > 0) $lines[] = ['coa_id' => $id_ssn, 'debit_amount' => 0, 'credit_amount' => $ssnit, 'description' => 'SSNIT Tier 1 payable'];
    if ($tier2 > 0) $lines[] = ['coa_id' => $id_t2, 'debit_amount' => 0, 'credit_amount' => $tier2, 'description' => 'Tier 2 pension payable'];
    if ($ded > 0) $lines[] = ['coa_id' => $id_ded, 'debit_amount' => 0, 'credit_amount' => $ded, 'description' => 'Staff deductions payable'];
    $lines[] = ['coa_id' => $id_net, 'debit_amount' => 0, 'credit_amount' => $net, 'description' => 'Net salary payable'];
    $jvdate = (strlen($month) === 7 ? $month : substr($month, 0, 7)) . '-01';
    try { [$jid, $jvnum] = post_journal($u, 'JV', $jvdate, substr($jvdate, 0, 7), "Payroll $month", $lines, 'payroll', $month, resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Payroll GL posting failed: ' . $e->getMessage()); }
    ok(['gl_posted' => true, 'jv_number' => $jvnum, 'total_cost' => $cost, 'paye' => $paye, 'ssnit_tier1' => $ssnit, 'net' => $net]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 5 (parallel-run support) — master-data create endpoints the regression
// suite needs: projects, bank accounts, users. Parity with server.py.
// ════════════════════════════════════════════════════════════════════════════
function api_projects_list(): void {
    $u = require_auth(); ensure_col('projects', 'unit_id');
    // Mirror Python's institutional read-scope: a unit-homed viewer sees only its own
    // (subtree) rows; Admin/university is unrestricted. Rows with no unit are hidden
    // from scoped users (a node param rolls a college up to its schools).
    [$sw, $sp] = unit_scope_sql($u, 'projects', $_GET['unit'] ?? ($_GET['unit_code'] ?? null));
    $st = db()->prepare("SELECT projects.* FROM projects WHERE 1=1$sw ORDER BY project_code");
    $st->execute($sp); send($st->fetchAll());
}
function api_project_save(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['title'])) err('title is required');
    ensure_col('projects', 'unit_id');
    $id = uuid4(); $code = $d['project_code'] ?? seq_code('projects', 'project_code', 'PRJ-', 4);
    $fcy = (float)($d['budget_fcy'] ?? 0); $fx = (float)($d['fx_rate'] ?? 1);
    $unit = resolve_write_unit($u, $d);
    db()->prepare("INSERT INTO projects(id,project_code,title,donor,division,start_date,end_date,currency,budget_fcy,fx_rate,budget_ghs,status,unit_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $code, $d['title'], $d['donor'] ?? 'Internal', $d['division'] ?? 'ADMIN',
            $d['start_date'] ?? date('Y') . '-01-01', $d['end_date'] ?? date('Y') . '-12-31',
            $d['currency'] ?? 'GHS', $fcy, $fx, $fcy * $fx, $d['status'] ?? 'Active', $unit]);
    ok(['id' => $id, 'project_code' => $code]);
}
function api_bank_accounts_list(): void { require_auth(); send(db()->query('SELECT * FROM bank_accounts ORDER BY account_name')->fetchAll()); }
function api_bank_account_save(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    if (empty($d['account_name']) || empty($d['account_number'])) err('account_name and account_number are required');
    require_write_unit($u, $d, 'bank account');
    $id = uuid4();
    db()->prepare("INSERT INTO bank_accounts(id,account_name,bank_name,branch,account_number,account_type,currency,opening_balance,created_by) VALUES(?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $d['account_name'], $d['bank_name'] ?? '', $d['branch'] ?? '', $d['account_number'],
            $d['account_type'] ?? 'Current', $d['currency'] ?? 'GHS', (float)($d['opening_balance'] ?? 0), $u['username']]);
    ok(['id' => $id]);
}
// The seed's users table carries a column-level CHECK limiting role to the three
// operational roles. Widen it once (preserving all columns/data) so read-only roles
// like Auditor can be provisioned. Idempotent: skips once 'Auditor' is in the DDL.
function ensure_user_roles(): void {
    $ddl = db()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$ddl || stripos($ddl, 'role IN') === false || stripos($ddl, 'Auditor') !== false) return;
    $new = preg_replace_callback('/role\s+IN\s*\(([^)]*)\)/i', fn($m) => "role IN (" . $m[1] . ",'Auditor','Viewer','Internal Auditor','Read Only')", $ddl, 1);
    if (!$new || $new === $ddl) return;
    $newDDL = preg_replace('/CREATE TABLE\s+["`]?users["`]?/i', 'CREATE TABLE users__new', $new, 1);
    if (!$newDDL || $newDDL === $new) return;
    $cols = implode(',', array_map(fn($c) => $c['name'], db()->query("PRAGMA table_info(users)")->fetchAll()));
    try {
        db()->exec('PRAGMA foreign_keys=OFF');
        db()->exec($newDDL);
        db()->exec("INSERT INTO users__new ($cols) SELECT $cols FROM users");
        db()->exec("DROP TABLE users");
        db()->exec("ALTER TABLE users__new RENAME TO users");
    } catch (Throwable $e) { /* leave the original table in place on any failure */ }
}
function api_user_create(): void {
    $u = require_role(['Admin']); $d = body();
    if (empty($d['username']) || empty($d['full_name']) || empty($d['role'])) err('username, full_name and role are required');
    ensure_user_roles();
    if (empty($d['password'])) err('Password required for new user');
    $id = uuid4();
    ensure_col('users', 'home_unit_id'); ensure_col('users', 'scope');
    db()->prepare("INSERT INTO users(id,username,password_hash,full_name,role,email,active,home_unit_id,scope) VALUES(?,?,?,?,?,?,1,?,?)")
        ->execute([$id, $d['username'], hash('sha256', (string)$d['password']), $d['full_name'], $d['role'],
            $d['email'] ?? '', $d['home_unit_id'] ?? null, $d['scope'] ?? ($d['role'] === 'Admin' ? 'university' : 'own_unit')]);
    ok(['id' => $id]);
}

// ════════════════════════════════════════════════════════════════════════════
// PHASE 5 (parallel-run): opening balances, year-end close, withholding lifecycle.
// ════════════════════════════════════════════════════════════════════════════
function api_opening_balances_post(): void {
    $u = require_role(['Admin', 'Finance Officer']); $d = body();
    $rows = $d['lines'] ?? []; if (count($rows) < 2) err('At least 2 opening-balance lines are required');
    $lines = [];
    foreach ($rows as $l) {
        // Accept BOTH payload shapes (mirror server.py api_post_opening_balances):
        //   * PHP-native : coa_code | code      + debit | credit         + opening_date
        //   * Python-style: coa_id (uuid)        + debit_amount | credit_amount + as_at_date
        $coa = null;
        $cid = $l['coa_id'] ?? null;
        if ($cid) {
            $c = db()->prepare('SELECT id FROM chart_of_accounts WHERE id=?'); $c->execute([$cid]); $coa = $c->fetch();
            if (!$coa) err("Account id $cid not found in the chart of accounts");
        } else {
            $code = $l['coa_code'] ?? $l['code'] ?? $l['account_code'] ?? null; if (!$code) err('coa_id or coa_code is required on each line');
            $c = db()->prepare('SELECT id FROM chart_of_accounts WHERE code=?'); $c->execute([$code]); $coa = $c->fetch();
            if (!$coa) err("Account $code not found in the chart of accounts");
        }
        $dr = money($l['debit_amount'] ?? ($l['debit'] ?? 0));
        $cr = money($l['credit_amount'] ?? ($l['credit'] ?? 0));
        // Capture per-line unit_id so opening balances can attribute each line to a
        // different org unit (mirror server.py api_post_opening_balances). It flows to
        // the GL via post_journal's per-line bind.
        $lines[] = ['coa_id' => $coa['id'], 'debit_amount' => $dr, 'credit_amount' => $cr,
                    'description' => $l['narration'] ?? ($d['description'] ?? 'Opening balance'),
                    'unit_id' => ($l['unit_id'] ?? null) ?: null];
    }
    // Hard-require a unit at the header (or first line) so a scoped poster cannot leave
    // the opening-balance batch unattributed (mirror server.py opening-balance guard).
    $ob_guard = $d;
    if (empty($ob_guard['unit_id']) && !empty($lines[0]['unit_id'])) $ob_guard['unit_id'] = $lines[0]['unit_id'];
    require_write_unit($u, $ob_guard, 'opening balance batch');
    $date = substr((string)($d['as_at_date'] ?? ($d['opening_date'] ?? ($d['date'] ?? date('Y-m-d')))), 0, 10);
    try { [$jid, $jvnum] = post_journal($u, 'AJV', $date, substr($date, 0, 7), $d['description'] ?? 'Opening balances', $lines, 'opening_balances', null, resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Could not post opening balances: ' . $e->getMessage()); }
    ok(['jv_number' => $jvnum, 'status' => 'Posted']);
}
function api_year_end_close(): void {
    $u = require_role(['Admin']); $d = body();
    $fy = trim((string)($d['financial_year'] ?? '')); if ($fy === '') err('financial_year is required');
    $notes = trim((string)($d['notes'] ?? '')); if ($notes === '') err('notes is required');
    db()->exec("CREATE TABLE IF NOT EXISTS year_end_closes(id TEXT PRIMARY KEY, financial_year TEXT, surplus_deficit REAL, retained_earnings_before REAL, retained_earnings_after REAL, status TEXT DEFAULT 'Closed', prepared_by TEXT, approved_by TEXT, closed_at TEXT, notes TEXT)");
    $dup = db()->prepare("SELECT 1 FROM year_end_closes WHERE financial_year=?"); $dup->execute([$fy]);
    if ($dup->fetchColumn()) err('Year-end close already exists for ' . $fy);
    $d1 = "$fy-01-01"; $d2 = "$fy-12-31";
    // Surplus/deficit from the GL for the FY (ties to the I&E statement).
    $inc = (float)(db()->query("SELECT COALESCE(SUM(COALESCE(gl.credit_amount,0)-COALESCE(gl.debit_amount,0)),0) FROM general_ledger gl JOIN chart_of_accounts c ON c.id=gl.coa_id WHERE gl.ledger_date BETWEEN '$d1' AND '$d2' AND (c.category='Revenue' OR c.account_type IN ('Income','Revenue') OR c.code LIKE '4%')")->fetchColumn() ?: 0);
    $exp = (float)(db()->query("SELECT COALESCE(SUM(COALESCE(gl.debit_amount,0)-COALESCE(gl.credit_amount,0)),0) FROM general_ledger gl JOIN chart_of_accounts c ON c.id=gl.coa_id WHERE gl.ledger_date BETWEEN '$d1' AND '$d2' AND (c.category='Expenses' OR c.account_type='Expense' OR c.code LIKE '5%' OR c.code LIKE '6%')")->fetchColumn() ?: 0);
    $surplus = round($inc - $exp, 2);
    $prior = (string)((int)$fy - 1);
    $pst = db()->prepare("SELECT retained_earnings_after FROM year_end_closes WHERE financial_year=?"); $pst->execute([$prior]);
    $re_before = round((float)($pst->fetchColumn() ?: 0), 2); $re_after = round($re_before + $surplus, 2);
    // Closing journal: clear cumulative I/E balances up to the FY end into the
    // Accumulated Fund. An unused FY (no postings on/before its year-end) closes
    // cleanly with no journal.
    $af = db()->query("SELECT id FROM chart_of_accounts WHERE code='31100001'")->fetchColumn()
        ?: (db()->query("SELECT id FROM chart_of_accounts WHERE account_type='Equity' AND (LOWER(account_name) LIKE '%accumulat%' OR LOWER(account_name) LIKE '%fund%' OR LOWER(account_name) LIKE '%reserve%') ORDER BY code LIMIT 1")->fetchColumn()
        ?: db()->query("SELECT id FROM chart_of_accounts WHERE account_type='Equity' ORDER BY code LIMIT 1")->fetchColumn());
    $lines = []; $jvnum = null;
    if ($af) {
        $rows = db()->query("SELECT gl.coa_id AS cid, COALESCE(NULLIF(gl.coa_code,''),c.code,'') AS code, COALESCE(c.category,'') AS cat, COALESCE(c.account_type,'') AS typ, SUM(COALESCE(gl.debit_amount,0)) AS dr, SUM(COALESCE(gl.credit_amount,0)) AS cr FROM general_ledger gl LEFT JOIN chart_of_accounts c ON c.id=gl.coa_id WHERE gl.ledger_date <= '$d2' GROUP BY gl.coa_id")->fetchAll();
        foreach ($rows as $r) {
            $c0 = substr((string)$r['code'], 0, 1); $dr = (float)$r['dr']; $cr = (float)$r['cr'];
            if ($r['cat'] === 'Revenue' || in_array($r['typ'], ['Income', 'Revenue'], true) || $c0 === '4') {
                $bal = round($cr - $dr, 2); if (abs($bal) > 0.005) $lines[] = ['coa_id' => $r['cid'], 'debit_amount' => $bal > 0 ? $bal : 0, 'credit_amount' => $bal < 0 ? -$bal : 0, 'description' => "Year-end close of income $fy"];
            } elseif ($r['cat'] === 'Expenses' || $r['typ'] === 'Expense' || in_array($c0, ['5', '6'], true)) {
                $bal = round($dr - $cr, 2); if (abs($bal) > 0.005) $lines[] = ['coa_id' => $r['cid'], 'credit_amount' => $bal > 0 ? $bal : 0, 'debit_amount' => $bal < 0 ? -$bal : 0, 'description' => "Year-end close of expenditure $fy"];
            }
        }
        if ($lines) {
            if ($surplus >= 0) $lines[] = ['coa_id' => $af, 'credit_amount' => $surplus, 'debit_amount' => 0, 'description' => "Surplus for $fy transferred to Accumulated Fund"];
            else $lines[] = ['coa_id' => $af, 'debit_amount' => -$surplus, 'credit_amount' => 0, 'description' => "Deficit for $fy transferred to Accumulated Fund"];
            try { [$jid, $jvnum] = post_journal($u, 'AJV', $d2, "$fy-12", "Year-end close $fy — Income & Expenditure to Accumulated Fund", $lines, 'year_end_close', $fy, null); }
            catch (Throwable $e) { err('Year-end close failed: ' . $e->getMessage()); }
        }
    }
    db()->prepare("INSERT INTO year_end_closes(id,financial_year,surplus_deficit,retained_earnings_before,retained_earnings_after,status,prepared_by,approved_by,closed_at,notes) VALUES(?,?,?,?,?,'Closed',?,?,datetime('now'),?)")
        ->execute([uuid4(), $fy, $surplus, $re_before, $re_after, $u['username'], $u['username'], $notes]);
    ok(['close_id' => $fy, 'financial_year' => $fy, 'surplus_deficit' => $surplus, 'retained_earnings_after' => $re_after, 'closing_jv' => $jvnum, 'jv_number' => $jvnum, 'message' => "Financial year $fy closed"]);
}
function ensure_wh_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS withholding_payables(id TEXT PRIMARY KEY, actual_id TEXT, source_pv_number TEXT,
        payable_type TEXT, payable_label TEXT, beneficiary TEXT, coa_id TEXT, project_id TEXT, amount_ghs REAL,
        status TEXT DEFAULT 'Pending', due_date TEXT, settled_jv TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
    // The table may pre-exist from the Python seed without this column.
    ensure_col('withholding_payables', 'settled_jv');
}
function create_withholding_payables(string $aid, array $a, array $t, ?string $jvnum, array $u): void {
    ensure_wh_table();
    $payables = [['WHT', $t['wht'], ['2030'], 'WHT Payable (' . ($a['wht_type'] ?? '') . ')', 'Ghana Revenue Authority'],
                 ['WHVAT', $t['whvat'], ['2031', '2034'], 'WHVAT Payable', 'Ghana Revenue Authority'],
                 ['UCF', $t['ucf'], ['2033', '2035'], 'UCC Common Fund Payable', 'University of Cape Coast']];
    foreach ($payables as $pp) {
        $amt = round((float)$pp[1], 2); if ($amt <= 0) continue;
        $coa = get_coa($pp[2]); if (!$coa) continue;
        db()->prepare("INSERT INTO withholding_payables(id,actual_id,source_pv_number,payable_type,payable_label,beneficiary,coa_id,project_id,amount_ghs,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Pending',?)")
            ->execute([uuid4(), $aid, $jvnum, $pp[0], $pp[3], $pp[4], $coa['id'], $a['project_id'] ?? null, $amt, $u['username']]);
    }
}
function api_withholding_list(): void {
    require_auth(); ensure_wh_table();
    $status = $_GET['status'] ?? null;
    $q = 'SELECT * FROM withholding_payables'; $p = [];
    if ($status) { $q .= ' WHERE status=?'; $p[] = $status; }
    $q .= ' ORDER BY created_at DESC';
    $st = db()->prepare($q); $st->execute($p);
    send(['ok' => true, 'rows' => $st->fetchAll()]);
}
function api_withholding_settle(): void {
    $u = require_role(['Admin', 'Finance Officer']); ensure_wh_table(); $d = body();
    $id = (string)($d['id'] ?? ''); if ($id === '') err('id is required');
    $st = db()->prepare('SELECT * FROM withholding_payables WHERE id=?'); $st->execute([$id]); $w = $st->fetch();
    if (!$w) err('Withholding payable not found');
    if (in_array(strtolower((string)$w['status']), ['settled', 'paid', 'remitted'], true)) err('Already settled');
    $bank = bank_coa_from_account((string)($d['bank_account_id'] ?? '')); if (!$bank) err('Bank account could not be resolved');
    $amt = round((float)$w['amount_ghs'], 2);
    // Self-describing remittance narration: "<rate>% WHT - <payee> - <purpose> (source <PV>)".
    $payee = (string)($w['beneficiary'] ?? ''); $rate_pct = '';
    if (!empty($w['actual_id'])) {
        $oa = db()->prepare('SELECT payee, wht_rate FROM actuals WHERE id=?'); $oa->execute([$w['actual_id']]); $orig = $oa->fetch();
        if ($orig) {
            if (!empty($orig['payee'])) $payee = (string)$orig['payee'];
            if (!empty($orig['wht_rate'])) $rate_pct = rtrim(rtrim(sprintf('%.2f', (float)$orig['wht_rate'] * 100), '0'), '.');
        }
    }
    $head = ((string)$w['payable_type'] === 'WHT') ? (($rate_pct !== '' ? $rate_pct . '% ' : '') . 'WHT') : (string)$w['payable_label'];
    $desc = $head . ' - ' . $payee . ' - remittance to ' . (string)($w['beneficiary'] ?? 'authority') . ' (source ' . (string)($w['source_pv_number'] ?? '') . ')';
    $lines = [['coa_id' => $w['coa_id'], 'debit_amount' => $amt, 'credit_amount' => 0, 'description' => $desc],
              ['coa_id' => $bank, 'debit_amount' => 0, 'credit_amount' => $amt, 'description' => 'Payment to ' . (string)($w['beneficiary'] ?? '')]];
    $date = substr((string)($d['payment_date'] ?? date('Y-m-d')), 0, 10);
    try { [$jid, $jvnum] = post_journal($u, 'PV', $date, substr($date, 0, 7), $desc, $lines, 'withholding_settlement', $id, resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Settlement posting failed: ' . $e->getMessage()); }
    // 'Paid' satisfies the table's status CHECK and the suite's "settled" filter.
    db()->prepare("UPDATE withholding_payables SET status='Paid', settled_jv=? WHERE id=?")->execute([$jvnum, $id]);
    ok(['status' => 'Paid', 'jv_number' => $jvnum, 'amount' => $amt]);
}

// ── Fuel coupons (procurement → Dr Fuel Stock / Cr Bank) ────────────────────
function ensure_fuel_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS fuel_coupon_batches(id TEXT PRIMARY KEY, batch_number TEXT, procurement_date TEXT,
        supplier TEXT, denomination REAL, quantity REAL, face_value REAL, cost_value REAL, serial_from TEXT, serial_to TEXT,
        bank_account_id TEXT, stock_coa_id TEXT, ledger_posted INTEGER DEFAULT 0, jv_id TEXT, created_by TEXT, created_at TEXT DEFAULT(datetime('now')))");
}
function fuel_stock_coa(): ?string {
    foreach (["(LOWER(account_name) LIKE '%fuel%' AND code LIKE '12%')", "(LOWER(account_name) LIKE '%coupon%')", "(code LIKE '121%' AND LOWER(account_name) LIKE '%stock%')", "code='12107006'"] as $w) {
        $r = db()->query("SELECT id FROM chart_of_accounts WHERE $w ORDER BY code LIMIT 1")->fetch();
        if ($r) return $r['id'];
    }
    return inv_asset_coa();
}
function api_fuel_batch_save(): void {
    $u = require_role(['Admin', 'Finance Officer']); ensure_fuel_table(); $d = body();
    $qty = (float)($d['quantity'] ?? 0); $den = (float)($d['denomination'] ?? 0);
    $cost = (float)($d['cost_value'] ?? ($den * $qty)); $face = $den * $qty;
    $id = uuid4(); $bn = seq_code('fuel_coupon_batches', 'batch_number', 'FCB-', 4);
    db()->prepare("INSERT INTO fuel_coupon_batches(id,batch_number,procurement_date,supplier,denomination,quantity,face_value,cost_value,serial_from,serial_to,bank_account_id,ledger_posted,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,0,?)")
        ->execute([$id, $bn, $d['procurement_date'] ?? date('Y-m-d'), $d['supplier'] ?? '', $den, $qty, $face, $cost, $d['serial_from'] ?? '', $d['serial_to'] ?? '', $d['bank_account_id'] ?? null, $u['username']]);
    ok(['id' => $id, 'batch_id' => $id, 'batch_number' => $bn]);
}
function api_fuel_batch_post(): void {
    $u = require_role(['Admin', 'Finance Officer']); ensure_fuel_table(); $d = body();
    $bid = (string)($d['batch_id'] ?? ($d['id'] ?? '')); if ($bid === '') err('batch_id is required');
    $st = db()->prepare('SELECT * FROM fuel_coupon_batches WHERE id=?'); $st->execute([$bid]); $b = $st->fetch();
    if (!$b) err('Batch not found'); if ((int)($b['ledger_posted'] ?? 0)) err('Batch already posted');
    $cost = round((float)$b['cost_value'], 2); $stock = fuel_stock_coa(); $bank = operating_bank_coa();
    if (!$stock || !$bank) err('Fuel stock or bank account could not be resolved');
    $lines = [['coa_id' => $stock, 'debit_amount' => $cost, 'credit_amount' => 0, 'description' => 'Fuel coupons procured ' . $b['batch_number']],
              ['coa_id' => $bank['id'], 'debit_amount' => 0, 'credit_amount' => $cost, 'description' => 'Payment for fuel coupons']];
    $date = (string)($b['procurement_date'] ?? date('Y-m-d'));
    try { [$jid, $jvnum] = post_journal($u, 'JV', $date, substr($date, 0, 7), 'Fuel coupon procurement ' . $b['batch_number'], $lines, 'fuel_coupons', $bid, resolve_write_unit($u, $d)); }
    catch (Throwable $e) { err('Fuel posting failed: ' . $e->getMessage()); }
    db()->prepare('UPDATE fuel_coupon_batches SET ledger_posted=1, jv_id=? WHERE id=?')->execute([$jid, $bid]);
    ok(['status' => 'Posted', 'jv_number' => $jvnum, 'cost' => $cost]);
}

// ── Reports: Trends + Consolidation export (mirror server.py) ───────────────
function api_trends(): void {
    require_auth();
    $n = max(1, min(24, (int)($_GET['months'] ?? 6)));
    $y = (int)date('Y'); $m = (int)date('n'); $periods = [];
    for ($i = 0; $i < $n; $i++) { $periods[] = sprintf('%04d-%02d', $y, $m); $m--; if ($m === 0) { $m = 12; $y--; } }
    $periods = array_reverse($periods);
    $st = db()->prepare(
        "SELECT substr(gl.ledger_date,1,7) AS mo, " .
        "SUM(CASE WHEN substr(COALESCE(NULLIF(gl.coa_code,''),c.code,''),1,1) IN ('4','7') THEN COALESCE(gl.credit_amount,0)-COALESCE(gl.debit_amount,0) ELSE 0 END) AS inc, " .
        "SUM(CASE WHEN substr(COALESCE(NULLIF(gl.coa_code,''),c.code,''),1,1) IN ('5','6') THEN COALESCE(gl.debit_amount,0)-COALESCE(gl.credit_amount,0) ELSE 0 END) AS exp " .
        "FROM general_ledger gl LEFT JOIN chart_of_accounts c ON c.id=gl.coa_id " .
        "LEFT JOIN journal_vouchers jv ON jv.id=gl.jv_id " .
        "WHERE substr(gl.ledger_date,1,7) BETWEEN ? AND ? AND (jv.status IS NULL OR jv.status='Posted') " .
        "GROUP BY substr(gl.ledger_date,1,7)");
    $st->execute([$periods[0], $periods[count($periods) - 1]]);
    $map = [];
    foreach ($st->fetchAll() as $r) { $map[$r['mo']] = [round((float)$r['inc'], 2), round((float)$r['exp'], 2)]; }
    $series = [];
    foreach ($periods as $p) { [$inc, $exp] = $map[$p] ?? [0.0, 0.0]; $series[] = ['period' => $p, 'income' => $inc, 'expenditure' => $exp, 'surplus' => round($inc - $exp, 2)]; }
    ok(['series' => $series]);
}
function api_consolidation_export(): void {
    $u = require_auth();
    $pf = $_GET['period_from'] ?? (date('Y') . '-01-01');
    $pt = $_GET['period_to'] ?? date('Y-m-d');
    $st = db()->prepare(
        "SELECT gl.coa_code AS code, MAX(gl.account_name) AS nm, COALESCE(MAX(c.account_type),'') AS at, " .
        "COALESCE(SUM(gl.debit_amount),0) AS dr, COALESCE(SUM(gl.credit_amount),0) AS cr " .
        "FROM general_ledger gl LEFT JOIN chart_of_accounts c ON c.id=gl.coa_id " .
        "WHERE gl.ledger_date <= ? GROUP BY gl.coa_code ORDER BY gl.coa_code");
    $st->execute([substr((string)$pt, 0, 10)]);
    $lines = []; $tdr = 0.0; $tcr = 0.0;
    foreach ($st->fetchAll() as $r) {
        if (($r['code'] ?? '') === '') continue;
        $dr = round((float)$r['dr'], 2); $cr = round((float)$r['cr'], 2);
        if (!$dr && !$cr) continue;
        $lines[] = ['code' => $r['code'], 'name' => $r['nm'] ?? '', 'debit' => $dr, 'credit' => $cr];
        $tdr += $dr; $tcr += $cr;
    }
    $name = (string)(setting_get('institution_name', 'UCC FMS') ?? 'UCC FMS');
    ok(['entity_code' => (string)(setting_get('institution_code', 'UCC') ?? 'UCC'), 'entity_name' => $name,
        'period_from' => $pf, 'period_to' => $pt,
        'total_debit' => round($tdr, 2), 'total_credit' => round($tcr, 2),
        'lines' => $lines, 'format' => 'ucc-consolidation-v1']);
}

// ── Finance overview (command-centre roll-up) + Financial-integrity cockpit ────
function api_finance_overview(): void {
    require_auth();
    $cash = round((float)db()->query("SELECT COALESCE(SUM(gl.debit_amount-gl.credit_amount),0) FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id WHERE c.code LIKE '127%' OR c.code LIKE '126%'")->fetchColumn(), 2);
    $recv = 0.0; $ar = ar_control_coa();
    if ($ar) { $s = db()->prepare("SELECT COALESCE(SUM(debit_amount-credit_amount),0) FROM general_ledger WHERE coa_id=?"); $s->execute([$ar['id']]); $recv = round((float)$s->fetchColumn(), 2); }
    $pay = 0.0; $ap = ap_control_coa();
    if ($ap) { $s = db()->prepare("SELECT COALESCE(SUM(credit_amount-debit_amount),0) FROM general_ledger WHERE coa_id=?"); $s->execute([$ap['id']]); $pay = round((float)$s->fetchColumn(), 2); }
    $inv = 0.0; try { ensure_inv_tables(); $inv = round((float)db()->query("SELECT COALESCE(SUM(qty_on_hand*avg_cost),0) FROM inv_items")->fetchColumn(), 2); } catch (Throwable $e) {}
    $tax = round((float)db()->query("SELECT COALESCE(SUM(gl.credit_amount-gl.debit_amount),0) FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id WHERE c.code IN ('21100014','21100024','21100027','2030','2031','2033')")->fetchColumn(), 2);
    $ca = round($cash + $recv + $inv, 2); $nwc = round($ca - $pay, 2);
    $overdue = 0; try { $overdue = (int)db()->query("SELECT COUNT(*) FROM ar_invoices WHERE status IN ('Posted','Part-Paid') AND COALESCE(due_date,'') < date('now') AND ROUND(COALESCE(total_ghs,0)-COALESCE(amount_received,0),2)>0.01")->fetchColumn(); } catch (Throwable $e) {}
    $low = 0; try { $low = (int)db()->query("SELECT COUNT(*) FROM inv_items WHERE COALESCE(reorder_level,0)>0 AND COALESCE(qty_on_hand,0) <= reorder_level")->fetchColumn(); } catch (Throwable $e) {}
    $pos = 0; try { $pos = (int)db()->query("SELECT COUNT(*) FROM purchase_orders WHERE COALESCE(status,'')='Received'")->fetchColumn(); } catch (Throwable $e) {}
    ok(['cash' => $cash, 'receivables' => $recv, 'payables' => $pay, 'inventory_value' => $inv,
        'current_assets' => $ca, 'net_working_capital' => $nwc, 'overdue_customers' => $overdue,
        'low_stock_items' => $low, 'pos_to_bill' => $pos, 'tax_outstanding' => $tax]);
}
function api_financial_integrity(): void {
    require_auth(); $checks = [];
    $sub = (float)db()->query("SELECT COALESCE(SUM(amount_ghs),0) FROM withholding_payables WHERE COALESCE(status,'') NOT IN ('Paid','Cancelled')")->fetchColumn();
    $glw = (float)db()->query("SELECT COALESCE(SUM(gl.credit_amount-gl.debit_amount),0) FROM general_ledger gl JOIN chart_of_accounts c ON gl.coa_id=c.id WHERE c.code IN ('21100014','21100024','21100027','2030','2031','2033')")->fetchColumn();
    $whd = round($sub - $glw, 2);
    $checks[] = ['key' => 'withholding_tie', 'status' => abs($whd) < 0.02 ? 'pass' : 'fail', 'message' => sprintf('Subledger GHS %.2f vs GL control GHS %.2f (diff %.2f)', $sub, $glw, $whd)];
    $a = gl_net_by_type('Asset'); $l = round(-gl_net_by_type('Liability'), 2); $eq = round(-gl_net_by_type('Equity'), 2);
    $inc = round(-gl_net_by_type('Income'), 2); $exp = gl_net_by_type('Expense'); $na = round($eq + ($inc - $exp), 2);
    $diff = round($a - ($l + $na), 2);
    $checks[] = ['key' => 'sfp_balance', 'status' => abs($diff) < 0.05 ? 'pass' : 'fail', 'message' => sprintf('Assets %.2f = Liabilities %.2f + Net assets %.2f (diff %.2f)', $a, $l, $na, $diff)];
    $arc = 0.0; $ar = ar_control_coa();
    if ($ar) { $s = db()->prepare("SELECT COALESCE(SUM(debit_amount-credit_amount),0) FROM general_ledger WHERE coa_id=?"); $s->execute([$ar['id']]); $arc = round((float)$s->fetchColumn(), 2); }
    $arsub = 0.0; try { $arsub = round((float)db()->query("SELECT COALESCE(SUM(ROUND(COALESCE(total_ghs,0)-COALESCE(amount_received,0),2)),0) FROM ar_invoices WHERE status IN ('Posted','Part-Paid')")->fetchColumn(), 2); } catch (Throwable $e) {}
    $ard = round($arc - $arsub, 2);
    $checks[] = ['key' => 'ar_control_tie', 'status' => abs($ard) < 0.05 ? 'pass' : 'fail', 'message' => sprintf('AR control GHS %.2f vs open invoices GHS %.2f (diff %.2f)', $arc, $arsub, $ard)];
    ok(['checks' => $checks]);
}

// ── Cash book: opening + receipts(Dr) − payments(Cr) = closing, with optional
//    net view that hides a reversed entry together with its reversal (both in window). ─
function api_cashbook(): void {
    require_auth();
    $dt = $_GET['date_to'] ?? ($_GET['period_to'] ?? date('Y-m-d'));
    $df = $_GET['date_from'] ?? ($_GET['period_from'] ?? (substr((string)$dt, 0, 7) . '-01'));
    $where = ''; $params = [];
    if (!empty($_GET['bank_account_id'])) {
        $bk = db()->prepare('SELECT coa_id FROM bank_accounts WHERE id=?'); $bk->execute([$_GET['bank_account_id']]);
        $bcoa = $bk->fetchColumn(); if ($bcoa) { $where = 'gl.coa_id=?'; $params = [$bcoa]; }
    }
    if ($where === '') $where = "(gl.coa_code LIKE '126%' OR gl.coa_code LIKE '127%' OR gl.coa_code LIKE '128%' OR gl.coa_code LIKE '129%' OR gl.coa_code='1001')";
    $net = $_GET['net'] ?? '1'; $net_on = !in_array((string)$net, ['0', 'false', 'no', ''], true);
    $op = db()->prepare("SELECT COALESCE(SUM(COALESCE(gl.debit_amount,0)-COALESCE(gl.credit_amount,0)),0) FROM general_ledger gl WHERE $where AND gl.ledger_date < ?");
    $op->execute(array_merge($params, [$df])); $opening = round((float)$op->fetchColumn(), 2);
    $rq = db()->prepare("SELECT gl.ledger_date, gl.jv_number, gl.description, COALESCE(gl.debit_amount,0) AS receipt, COALESCE(gl.credit_amount,0) AS payment FROM general_ledger gl WHERE $where AND gl.ledger_date BETWEEN ? AND ? ORDER BY gl.ledger_date, gl.jv_number");
    $rq->execute(array_merge($params, [$df, $dt])); $rows = $rq->fetchAll();
    $hidden = 0;
    if ($net_on && $rows) {
        $present = []; foreach ($rows as $r) if (!empty($r['jv_number'])) $present[$r['jv_number']] = true;
        $hide = [];
        try {
            $pr = db()->query("SELECT o.jv_number AS onum, r.jv_number AS rnum FROM journal_vouchers r JOIN journal_vouchers o ON r.reversal_of=o.id WHERE COALESCE(r.is_reversal,0)=1 AND o.jv_number IS NOT NULL AND r.jv_number IS NOT NULL");
            foreach ($pr->fetchAll() as $p) { if (isset($present[$p['onum']]) && isset($present[$p['rnum']])) { $hide[$p['onum']] = true; $hide[$p['rnum']] = true; } }
        } catch (Throwable $e) {}
        if ($hide) { $kept = []; foreach ($rows as $r) if (!isset($hide[$r['jv_number']])) $kept[] = $r; $hidden = count($rows) - count($kept); $rows = $kept; }
    }
    $bal = $opening; $tin = 0.0; $tout = 0.0; $out = [];
    foreach ($rows as $r) {
        $rc = round((float)$r['receipt'], 2); $pm = round((float)$r['payment'], 2);
        $bal = round($bal + $rc - $pm, 2); $tin += $rc; $tout += $pm;
        $out[] = ['ledger_date' => $r['ledger_date'], 'jv_number' => $r['jv_number'], 'description' => $r['description'], 'receipt' => $rc, 'payment' => $pm, 'balance' => $bal];
    }
    ok(['date_from' => $df, 'date_to' => $dt, 'opening_balance' => $opening, 'rows' => $out,
        'total_receipts' => round($tin, 2), 'total_payments' => round($tout, 2),
        'closing_balance' => round($opening + $tin - $tout, 2), 'net_view' => $net_on,
        'hidden_cancelled_lines' => $hidden, 'basis' => 'general_ledger']);
}

// ── Reversals register + Admin re-date of a posted journal ─────────────────────
function api_reversals_register(): void {
    require_auth();
    ensure_col('journal_vouchers', 'is_reversal', 'INTEGER'); ensure_col('journal_vouchers', 'reversal_of', 'TEXT');
    $rows = db()->query("SELECT id,jv_number,jv_type,jv_date,period,description,total_debit,total_credit,reversal_of,is_reversal,posted_by,posted_at
        FROM journal_vouchers WHERE COALESCE(is_reversal,0)=1 ORDER BY jv_date DESC, jv_number DESC")->fetchAll();
    ok(['reversals' => $rows, 'count' => count($rows)]);
}
function api_redate_reversal(): void {
    $u = require_role(['Admin']); $d = body();
    $jvn = trim((string)($d['jv_number'] ?? '')); $aid = trim((string)($d['actual_id'] ?? ''));
    if ($jvn === '' && $aid !== '') {
        $s = db()->prepare('SELECT jv_id FROM actuals WHERE id=?'); $s->execute([$aid]); $jid0 = $s->fetchColumn();
        if ($jid0) { $s2 = db()->prepare('SELECT jv_number FROM journal_vouchers WHERE id=?'); $s2->execute([$jid0]); $jvn = (string)$s2->fetchColumn(); }
    }
    if ($jvn === '') err('jv_number or actual_id is required');
    $s = db()->prepare('SELECT * FROM journal_vouchers WHERE jv_number=?'); $s->execute([$jvn]); $jv = $s->fetch();
    if (!$jv) err('Journal not found');
    $new_date = substr(trim((string)($d['new_date'] ?? '')), 0, 10);
    if ($new_date === '' && !empty($jv['reversal_of'])) {
        $o = db()->prepare('SELECT jv_date FROM journal_vouchers WHERE id=?'); $o->execute([$jv['reversal_of']]); $od = $o->fetchColumn();
        if ($od) $new_date = substr((string)$od, 0, 10);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) err('new_date must be YYYY-MM-DD (the corrected transaction date)');
    $old_period = (string)($jv['period'] ?? '');
    $new_period = strlen($old_period) === 4 ? substr($new_date, 0, 4) : substr($new_date, 0, 7);
    db()->prepare('UPDATE journal_vouchers SET jv_date=?, period=? WHERE id=?')->execute([$new_date, $new_period, $jv['id']]);
    $st = db()->prepare('UPDATE general_ledger SET ledger_date=?, period=? WHERE jv_id=?'); $st->execute([$new_date, $new_period, $jv['id']]);
    $moved = $st->rowCount();
    ok(['jv_number' => $jvn, 'new_date' => $new_date, 'new_period' => $new_period, 'ledger_lines_moved' => $moved]);
}

// ── Tax schedules: per control-account accrued / remitted / outstanding ────────
// Statutory tax pack — WHT / VAT-withholding / PAYE / SSNIT / UCF / VAT schedules
// derived from the GL for a period: opening (prior periods, credit-debit), accrued
// (period credits less remittance-reversals), remitted (period debits whose JV
// credits a bank, less reversals), adjustments (period debits with no bank leg),
// outstanding = opening + accrued − remitted − adjustments. Mirrors server.py.
function api_tax_schedules(): void {
    require_auth();
    $period = trim((string)($_GET['period'] ?? ''));
    if ($period === '') { $r = db()->query("SELECT period FROM general_ledger ORDER BY period DESC LIMIT 1")->fetchColumn(); $period = $r ?: date('Y-m'); }
    $taxes = [
        ['key' => 'wht', 'label' => 'Withholding Tax (GRA)', 'codes' => ['21100014']],
        ['key' => 'whvat', 'label' => 'VAT Withholding', 'codes' => ['21100024']],
        ['key' => 'paye', 'label' => 'PAYE', 'codes' => ['21100017']],
        ['key' => 'ssnit', 'label' => 'SSNIT / Social Security', 'codes' => ['21100015']],
        ['key' => 'ucf', 'label' => 'UCC Common Fund', 'codes' => ['21100027']],
        ['key' => 'vat', 'label' => 'VAT Returns', 'codes' => ['21100022']],
    ];
    $bankset = "(g2.coa_code LIKE '126%' OR g2.coa_code LIKE '127%' OR g2.coa_code LIKE '128%' OR g2.coa_code LIKE '129%' OR g2.coa_code='1001')";
    $sc = function (string $sql, array $params) {
        try { $st = db()->prepare($sql); $st->execute($params); $v = $st->fetchColumn(); return ($v === false || $v === null) ? 0.0 : round((float)$v, 2); }
        catch (Throwable $e) { return 0.0; }
    };
    $summary = []; $detail = [];
    foreach ($taxes as $t) {
        $inlist = "('" . implode("','", $t['codes']) . "')";
        $opening = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)-COALESCE(debit_amount,0)),0) FROM general_ledger WHERE coa_code IN $inlist AND period < ?", [$period]);
        $accrued = $sc("SELECT COALESCE(SUM(COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code IN $inlist AND period LIKE ?", [$period . '%']);
        $remitted = $sc("SELECT COALESCE(SUM(COALESCE(gl.debit_amount,0)),0) FROM general_ledger gl WHERE gl.coa_code IN $inlist AND gl.period LIKE ? AND COALESCE(gl.debit_amount,0)>0 AND EXISTS(SELECT 1 FROM general_ledger g2 WHERE g2.jv_id=gl.jv_id AND COALESCE(g2.credit_amount,0)>0 AND $bankset)", [$period . '%']);
        $adjustments = $sc("SELECT COALESCE(SUM(COALESCE(gl.debit_amount,0)),0) FROM general_ledger gl WHERE gl.coa_code IN $inlist AND gl.period LIKE ? AND COALESCE(gl.debit_amount,0)>0 AND NOT EXISTS(SELECT 1 FROM general_ledger g2 WHERE g2.jv_id=gl.jv_id AND COALESCE(g2.credit_amount,0)>0 AND $bankset)", [$period . '%']);
        $revq = " AND COALESCE(gl.credit_amount,0)>0 AND EXISTS(SELECT 1 FROM general_ledger g2 WHERE g2.jv_id=gl.jv_id AND COALESCE(g2.debit_amount,0)>0 AND $bankset)";
        $remit_reversal = $sc("SELECT COALESCE(SUM(COALESCE(gl.credit_amount,0)),0) FROM general_ledger gl WHERE gl.coa_code IN $inlist AND gl.period LIKE ?" . $revq, [$period . '%']);
        $accrued = round($accrued - $remit_reversal, 2);
        $remitted = round($remitted - $remit_reversal, 2);
        $outstanding = round($opening + $accrued - $remitted - $adjustments, 2);
        $summary[] = ['key' => $t['key'], 'label' => $t['label'], 'code' => $t['codes'][0], 'opening' => $opening,
            'accrued' => $accrued, 'remitted' => $remitted, 'adjustments' => $adjustments, 'outstanding' => $outstanding, 'lines' => 0];
        $detail[$t['key']] = [];
    }
    ok(['period' => $period, 'summary' => $summary, 'detail' => $detail, 'taxes' => $summary,
        'total_outstanding' => round(array_sum(array_map(fn($s) => $s['outstanding'], $summary)), 2),
        'total_accrued' => round(array_sum(array_map(fn($s) => $s['accrued'], $summary)), 2)]);
}

// ── PPE movement schedule (IPSAS 17) — roll-forward by category, tied to the GL ──
function api_ppe_schedule(): void {
    require_auth();
    foreach (['disposal_date', 'revaluation_amount', 'impairment_amount', 'last_valuation_date'] as $c) ensure_col('asset_register', $c);
    $fy = substr((string)($_GET['fy'] ?? date('Y')), 0, 4); $d1 = "$fy-01-01"; $d2 = "$fy-12-31";
    $assets = db()->query("SELECT asset_category, acquisition_date, COALESCE(acquisition_cost,0) AS cost,
        COALESCE(accumulated_depreciation,0) AS accdep, COALESCE(revaluation_amount,0) AS reval,
        COALESCE(impairment_amount,0) AS impair, COALESCE(last_valuation_date,'') AS val_date,
        COALESCE(disposal_date,'') AS disposal_date FROM asset_register")->fetchAll();
    $cats = [];
    $C = function ($n) use (&$cats) { $n = $n ?: 'General'; if (!isset($cats[$n])) $cats[$n] = ['category' => $n, 'cost_bf' => 0.0, 'additions' => 0.0, 'disposals_cost' => 0.0, 'revaluation' => 0.0, 'impairment' => 0.0, 'cost_cf' => 0.0, 'dep_bf' => 0.0, 'dep_charge' => 0.0, 'dep_released' => 0.0, 'dep_cf' => 0.0, 'nbv_bf' => 0.0, 'nbv_cf' => 0.0]; return $n; };
    foreach ($assets as $a) {
        $n = $C($a['asset_category']); $cost = round((float)$a['cost'], 2);
        $acq = substr((string)$a['acquisition_date'], 0, 10); $disp = substr((string)$a['disposal_date'], 0, 10);
        $din = $disp && $disp >= $d1 && $disp <= $d2; $dbef = $disp && $disp < $d1;
        if ($dbef) continue;
        if ($acq < $d1) $cats[$n]['cost_bf'] += $cost; elseif ($acq <= $d2) $cats[$n]['additions'] += $cost;
        if ($din) { $cats[$n]['disposals_cost'] += $cost; $cats[$n]['dep_released'] += round((float)$a['accdep'], 2); }
        if (substr((string)$a['val_date'], 0, 4) === $fy) { $cats[$n]['revaluation'] += round((float)$a['reval'], 2); $cats[$n]['impairment'] += round((float)$a['impair'], 2); }
        if (!$din) $cats[$n]['dep_cf'] += round((float)$a['accdep'], 2);
    }
    try { foreach (db()->query("SELECT ar.asset_category AS cat, COALESCE(SUM(dr.monthly_dep),0) AS amt FROM depreciation_runs dr JOIN asset_register ar ON ar.id=dr.asset_id WHERE dr.run_month LIKE '$fy%' GROUP BY ar.asset_category")->fetchAll() as $r) { $c = $r['cat'] ?: 'General'; if (isset($cats[$c])) $cats[$c]['dep_charge'] = round((float)$r['amt'], 2); } } catch (Throwable $e) {}
    foreach ($cats as &$c) {
        $c['cost_cf'] = round($c['cost_bf'] + $c['additions'] - $c['disposals_cost'] + $c['revaluation'], 2);
        $c['dep_bf'] = round($c['dep_cf'] + $c['dep_released'] - $c['dep_charge'] - $c['impairment'], 2);
        $c['dep_cf'] = round($c['dep_cf'] + $c['impairment'], 2);
        $c['nbv_bf'] = round($c['cost_bf'] - $c['dep_bf'], 2); $c['nbv_cf'] = round($c['cost_cf'] - $c['dep_cf'], 2);
    }
    unset($c);
    $rows = array_values($cats); usort($rows, fn($a, $b) => strcmp($a['category'], $b['category']));
    $tot = [];
    foreach (['cost_bf', 'additions', 'disposals_cost', 'revaluation', 'impairment', 'cost_cf', 'dep_bf', 'dep_charge', 'dep_released', 'dep_cf', 'nbv_bf', 'nbv_cf'] as $k) { $s = 0.0; foreach ($rows as $r) $s += $r[$k]; $tot[$k] = round($s, 2); }
    $glsum = function ($prefix, $nature) use ($d2) { $r = db()->prepare("SELECT COALESCE(SUM(COALESCE(debit_amount,0)),0), COALESCE(SUM(COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE ? AND ledger_date <= ?"); $r->execute([$prefix, $d2]); $x = $r->fetch(PDO::FETCH_NUM); $d = (float)$x[0]; $cr = (float)$x[1]; return round($nature === 'debit' ? ($d - $cr) : ($cr - $d), 2); };
    $gl = ['gl_cost_111x' => $glsum('111%', 'debit'), 'gl_accum_119x' => $glsum('119%', 'credit')];
    $gl['register_vs_gl_cost'] = round($tot['cost_cf'] - $gl['gl_cost_111x'], 2);
    $gl['register_vs_gl_accum'] = round($tot['dep_cf'] - $gl['gl_accum_119x'], 2);
    ok(['fy' => $fy, 'rows' => $rows, 'totals' => $tot, 'gl_tie' => $gl]);
}

function api_ipsas24(): void {
    // IPSAS 24 Statement of Comparison of Budget and Actual Amounts (expenditure
    // appropriations). Original = final less logged revisions; Final = approved
    // budget_ghs; Actual = posted PVs in the FY charged to each line, on the same
    // accrual basis as the I&E. Plus unbudgeted posted spend and the IPSAS 24.47
    // reconciliation to total general-ledger expenditure.
    require_auth();
    $fy = substr((string)($_GET['fy'] ?? date('Y')), 0, 4); $d1 = "$fy-01-01"; $d2 = "$fy-12-31";
    $haveRev = (bool) db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='budget_revisions'")->fetchColumn();
    $revSub = $haveRev
        ? "COALESCE((SELECT SUM(br.change_amount_ghs) FROM budget_revisions br WHERE br.budget_id=b.id),0)"
        : "0";
    $st = db()->prepare(
        "SELECT b.id, b.budget_code, COALESCE(c.code,'') AS coa_code, " .
        "COALESCE(c.account_name, b.budget_code) AS account_name, " .
        "COALESCE(p.project_code,'') AS project_code, COALESCE(b.budget_ghs,0) AS final_budget, " .
        $revSub . " AS revised_by, " .
        "COALESCE((SELECT SUM(a.amount_ghs) FROM actuals a WHERE a.budget_id=b.id " .
        "AND COALESCE(a.is_posted,0)=1 AND a.expense_date BETWEEN ? AND ?),0) AS actual, " .
        "COALESCE((SELECT SUM(MAX(0, cm.amount_ghs - COALESCE((SELECT SUM(ax.amount_ghs) " .
        "FROM actuals ax WHERE ax.commitment_id=cm.id AND COALESCE(ax.is_posted,0)=1),0))) " .
        "FROM commitments cm WHERE cm.budget_id=b.id " .
        "AND COALESCE(cm.status,'') NOT IN ('Cancelled','Fully Paid')),0) AS open_commitments " .
        "FROM budgets b LEFT JOIN chart_of_accounts c ON b.coa_id=c.id " .
        "LEFT JOIN projects p ON b.project_id=p.id " .
        "WHERE COALESCE(b.is_deleted,0)=0 " .
        "AND NOT (COALESCE(c.code,'') LIKE '4%' OR c.category='Revenue' OR c.account_type IN ('Income','Revenue')) " .
        "ORDER BY coa_code, b.budget_code");
    $st->execute([$d1, $d2]);
    $lines = []; $agg = [];
    foreach ($st->fetchAll() as $r) {
        $final = round((float)$r['final_budget'], 2);
        $orig  = round($final - (float)$r['revised_by'], 2);
        $act   = round((float)$r['actual'], 2);
        $com   = round((float)$r['open_commitments'], 2);
        $var   = round($final - $act, 2);
        $pct   = $final ? round($act / $final * 100, 1) : 0.0;
        $lines[] = ['budget_code' => $r['budget_code'], 'coa_code' => $r['coa_code'],
            'account_name' => $r['account_name'], 'project_code' => $r['project_code'],
            'original' => $orig, 'final' => $final, 'actual' => $act,
            'open_commitments' => $com, 'variance' => $var, 'utilisation_pct' => $pct];
        $k = $r['coa_code'] . '|' . $r['account_name'];
        if (!isset($agg[$k])) $agg[$k] = ['coa_code' => $r['coa_code'], 'account_name' => $r['account_name'],
            'original' => 0.0, 'final' => 0.0, 'actual' => 0.0, 'open_commitments' => 0.0];
        $agg[$k]['original'] += $orig; $agg[$k]['final'] += $final;
        $agg[$k]['actual'] += $act; $agg[$k]['open_commitments'] += $com;
    }
    $by_account = [];
    foreach ($agg as $a) {
        foreach (['original', 'final', 'actual', 'open_commitments'] as $kk) $a[$kk] = round($a[$kk], 2);
        $a['variance'] = round($a['final'] - $a['actual'], 2);
        $a['utilisation_pct'] = $a['final'] ? round($a['actual'] / $a['final'] * 100, 1) : 0.0;
        $by_account[] = $a;
    }
    usort($by_account, fn($x, $y) => strcmp($x['coa_code'] ?: 'zzz', $y['coa_code'] ?: 'zzz'));
    $ust = db()->prepare("SELECT COALESCE(SUM(amount_ghs),0), COUNT(*) FROM actuals WHERE (budget_id IS NULL OR budget_id='') AND COALESCE(is_posted,0)=1 AND expense_date BETWEEN ? AND ?");
    $ust->execute([$d1, $d2]); $u = $ust->fetch(PDO::FETCH_NUM);
    $unbudgeted = ['amount' => round((float)$u[0], 2), 'count' => (int)$u[1]];
    $gst = db()->prepare("SELECT COALESCE(SUM(COALESCE(debit_amount,0)-COALESCE(credit_amount,0)),0) FROM general_ledger WHERE coa_code LIKE '6%' AND ledger_date BETWEEN ? AND ?");
    $gst->execute([$d1, $d2]); $gl_exp = round((float)$gst->fetchColumn(), 2);
    $sum = fn($k) => round(array_sum(array_map(fn($l) => $l[$k], $lines)), 2);
    $tot = ['original' => $sum('original'), 'final' => $sum('final'), 'actual' => $sum('actual'), 'open_commitments' => $sum('open_commitments')];
    $tot['variance'] = round($tot['final'] - $tot['actual'], 2);
    $tot['utilisation_pct'] = $tot['final'] ? round($tot['actual'] / $tot['final'] * 100, 1) : 0.0;
    $recon = ['gl_expenditure' => $gl_exp, 'budget_linked_actual' => $tot['actual'],
        'unbudgeted_actual' => $unbudgeted['amount'],
        'other_journal_expenditure' => round($gl_exp - $tot['actual'] - $unbudgeted['amount'], 2)];
    $material = array_values(array_filter($by_account, fn($l) => $l['final'] > 0 && abs($l['variance']) >= max(0.10 * $l['final'], 1.0)));
    ok(['fy' => $fy, 'lines' => $lines, 'by_account' => $by_account, 'totals' => $tot,
        'unbudgeted' => $unbudgeted, 'gl_reconciliation' => $recon, 'material_variances' => $material]);
}

// ── Front controller ────────────────────────────────────────────────────────
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // Serve the SPA + static assets so the PHP backend is browsable like the Python app
    // (in production Apache/cPanel serves these; this makes `php -S` a complete app too).
    if ($method === 'GET' && ($path === '/' || $path === '' || $path === '/index.html')) {
        $f = dirname(__DIR__) . '/index.html';
        if (is_file($f)) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); readfile($f); exit; }
    }
    if ($method === 'GET' && preg_match('#^/assets/[A-Za-z0-9._/\-]+$#', $path)) {
        $real = realpath(dirname(__DIR__) . $path); $base = realpath(dirname(__DIR__) . '/assets');
        if ($real && $base && strpos($real, $base) === 0 && is_file($real)) {
            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $mt = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'css' => 'text/css', 'js' => 'application/javascript', 'ico' => 'image/x-icon'][$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mt); readfile($real); exit;
        }
    }
    if ($path === '/healthz') { try { db()->query('SELECT 1'); ok(['status' => 'ok', 'db' => 'ok', 'app' => 'UCC-FMS-PHP']); } catch (Throwable $e) { send(['ok' => false, 'db' => 'error'], 503); } }
    if ($path === '/api/login'  && $method === 'POST') api_login();
    if ($path === '/api/logout' && $method === 'POST') api_logout();
    if ($path === '/api/me'     && $method === 'GET')  api_me();
    if ($path === '/api/verify-mfa' && $method === 'POST') api_verify_mfa();
    if ($path === '/api/mfa/enroll' && $method === 'POST') api_mfa_enroll();
    // Auditor accounts are strictly read-only: block every write (POST) past the auth
    // endpoints with a clear message, while GET reports and exports stay available.
    if ($method === 'POST') { $au = current_user(); if ($au && ($au['role'] ?? '') === 'Auditor') err('This account is read-only (Auditor) — writes are not permitted', 403); }
    if ($path === '/api/settings/dual-control' && $method === 'GET') api_dual_control_get();
    if ($path === '/api/settings/dual-control' && $method === 'POST') api_dual_control_set();
    if ($path === '/api/org-units' && $method === 'GET') api_org_units();

    // Phase 5 — master-data create (parallel-run support)
    if ($path === '/api/projects' && $method === 'GET') api_projects_list();
    if ($path === '/api/projects' && $method === 'POST') api_project_save();
    if ($path === '/api/bank-accounts' && $method === 'GET') api_bank_accounts_list();
    if ($path === '/api/bank-accounts' && $method === 'POST') api_bank_account_save();
    if ($path === '/api/users' && $method === 'POST') api_user_create();

    // Phase 2 — accounting core
    if ($path === '/api/coa' && $method === 'GET') api_coa();
    if ($path === '/api/go-live-enforcement/mode' && $method === 'POST') api_golive_mode();
    if ($path === '/api/jvs' && $method === 'GET') api_jvs_list();
    if ($path === '/api/jvs/detail' && $method === 'GET') api_jv_detail();
    if ($path === '/api/jvs' && $method === 'POST') api_jvs_create();
    if ($path === '/api/journal-vouchers/post' && $method === 'POST') api_jv_post();
    if ($path === '/api/ledger-summary' && $method === 'GET') api_ledger_summary();
    if ($path === '/api/trial-balance' && $method === 'GET') api_trial_balance();
    if ($path === '/api/trends' && $method === 'GET') api_trends();
    if ($path === '/api/consolidation/export' && $method === 'GET') api_consolidation_export();
    if ($path === '/api/finance-overview' && $method === 'GET') api_finance_overview();
    if ($path === '/api/financial-integrity' && $method === 'GET') api_financial_integrity();
    if ($path === '/api/cashbook' && $method === 'GET') api_cashbook();
    if ($path === '/api/reversals-register' && $method === 'GET') api_reversals_register();
    if ($path === '/api/tax-schedules' && $method === 'GET') api_tax_schedules();
    if ($path === '/api/ppe-schedule' && $method === 'GET') api_ppe_schedule();
    if ($path === '/api/ipsas24' && $method === 'GET') api_ipsas24();
    if ($path === '/api/journals/redate' && $method === 'POST') api_redate_reversal();
    if ($path === '/api/general-ledger' && $method === 'GET') api_general_ledger();
    if ($path === '/api/accounting-periods' && $method === 'GET') api_accounting_periods();
    if ($path === '/api/accounting-periods' && $method === 'POST') api_accounting_period_action();

    // Phase 3a — payments (PV) + tax engine + budgets/commitments/vendors
    if (($path === '/api/vendors' || $path === '/api/ap/vendors') && $method === 'GET') api_vendors_list();
    if (($path === '/api/vendors' || $path === '/api/ap/vendors') && $method === 'POST') api_vendor_save();
    if ($path === '/api/budgets' && $method === 'GET') api_budgets_list();
    if ($path === '/api/budgets' && $method === 'POST') api_budget_save();
    if ($path === '/api/commitments' && $method === 'GET') api_commitments_list();
    if ($path === '/api/commitments' && $method === 'POST') api_commitment_save();
    if ($path === '/api/actuals' && $method === 'GET') api_actuals_list();
    if ($path === '/api/actuals' && $method === 'POST') api_actual_save();
    if ($path === '/api/actuals/post' && $method === 'POST') api_actual_post();
    if ($path === '/api/actuals/update' && $method === 'POST') api_actual_update();
    if ($path === '/api/actuals/multiline' && $method === 'POST') api_save_multiline_actual();
    if ($path === '/api/actuals/lines' && $method === 'GET') api_get_actual_lines();

    // Phase 3b — receipts (RV) + JV workflow (submit/approve/post with SoD)
    if ($path === '/api/fund-receipts' && $method === 'GET') api_fund_receipts_list();
    if ($path === '/api/fund-receipts' && $method === 'POST') api_fund_receipt_save();
    if ($path === '/api/fund-receipts/post' && $method === 'POST') api_fund_receipt_post();
    if ($path === '/api/jvs/workflow' && $method === 'POST') api_jv_workflow();

    // Phase 3c (assets) — fixed assets + straight-line depreciation
    if ($path === '/api/assets' && $method === 'GET') api_assets_list();
    if ($path === '/api/assets' && $method === 'POST') api_asset_save();
    if ($path === '/api/assets/dispose' && $method === 'POST') api_asset_dispose();
    if ($path === '/api/assets/revalue' && $method === 'POST') api_asset_revalue();
    if ($path === '/api/depreciation/schedule' && $method === 'GET') api_depreciation_schedule();
    if ($path === '/api/depreciation/run' && $method === 'POST') api_depreciation_run();

    // Phase 3d — AR / AP subledgers
    if ($path === '/api/ar/customers' && $method === 'GET') api_ar_customers_list();
    if ($path === '/api/ar/customers' && $method === 'POST') api_ar_customer_save();
    if ($path === '/api/ar/customers/statement' && $method === 'GET') api_ar_customer_statement();
    if ($path === '/api/ar/invoices/lines' && $method === 'GET') api_ar_invoice_lines();
    if ($path === '/api/ar/invoices' && $method === 'GET') api_ar_invoices_list();
    if ($path === '/api/ar/invoices' && $method === 'POST') api_ar_invoice_save();
    if ($path === '/api/ar/invoices/post' && $method === 'POST') api_ar_invoice_post();
    if ($path === '/api/ar/receipt' && $method === 'POST') api_ar_receipt();
    if ($path === '/api/ap/bills' && $method === 'GET') api_ap_bills_list();
    if ($path === '/api/ap/bills' && $method === 'POST') api_ap_bill_save();
    if ($path === '/api/ap/bills/post' && $method === 'POST') api_ap_bill_post();
    if ($path === '/api/ap/payment' && $method === 'POST') api_ap_payment();
    if ($path === '/api/ap/batch-pay' && $method === 'POST') api_ap_batch_pay();
    if ($path === '/api/ar/batch-receipt' && $method === 'POST') api_ar_batch_receipt();
    if ($path === '/api/ap/recurring' && $method === 'GET') api_ap_recurring_list();
    if ($path === '/api/ap/recurring' && $method === 'POST') api_ap_save_recurring();
    if ($path === '/api/ap/recurring/toggle' && $method === 'POST') api_ap_recurring_toggle();
    if ($path === '/api/ap/recurring/generate' && $method === 'POST') api_ap_recurring_generate();
    if ($path === '/api/ar/recurring' && $method === 'GET') api_ar_recurring_list();
    if ($path === '/api/ar/recurring' && $method === 'POST') api_ar_save_recurring();
    if ($path === '/api/ar/recurring/toggle' && $method === 'POST') api_ar_recurring_toggle();
    if ($path === '/api/ar/recurring/generate' && $method === 'POST') api_ar_recurring_generate();
    if ($path === '/api/ar/import-invoices' && $method === 'POST') api_ar_import_invoices();
    if ($path === '/api/ap/import-bills' && $method === 'POST') api_ap_import_bills();
    if ($path === '/api/email-statement' && $method === 'POST') api_email_statement();
    if ($path === '/api/dunning-preview' && $method === 'GET') api_dunning_preview();
    if ($path === '/api/dunning-run' && $method === 'POST') api_dunning_run();
    if ($path === '/api/rec-journals' && $method === 'GET') api_rec_journals_list();
    if ($path === '/api/rec-journals' && $method === 'POST') api_save_rec_journal();
    if ($path === '/api/rec-journals/toggle' && $method === 'POST') api_rec_journal_toggle();
    if ($path === '/api/rec-journals/generate' && $method === 'POST') api_rec_journal_generate();
    if ($path === '/api/ap/payment-run-file' && $method === 'POST') api_payment_run_file();

    // Phase 3d (inventory) — stores ledger
    if ($path === '/api/inv/items' && $method === 'GET') api_inv_items_list();
    if ($path === '/api/inv/items' && $method === 'POST') api_inv_item_save();
    if ($path === '/api/inv/receipt' && $method === 'POST') api_inv_receipt();
    if ($path === '/api/inv/issue' && $method === 'POST') api_inv_issue();

    // Phase 3c (payroll) — Ghana PAYE + SSNIT
    if ($path === '/api/payroll/employees' && $method === 'POST') api_payroll_employee_save();
    if ($path === '/api/payroll/register' && $method === 'GET') api_payroll_register();
    if ($path === '/api/payroll/run' && $method === 'POST') api_payroll_run();
    if ($path === '/api/payroll/approve' && $method === 'POST') api_payroll_approve();

    // Phase 3d (petty cash) — imprest floats
    if ($path === '/api/petty-cash2' && $method === 'GET') api_pc2_state();
    if ($path === '/api/petty-cash2/float' && $method === 'POST') api_pc2_setup_float();
    if ($path === '/api/petty-cash2/voucher' && $method === 'POST') api_pc2_voucher();
    if ($path === '/api/petty-cash2/replenish' && $method === 'POST') api_pc2_replenish();
    if ($path === '/api/petty-cash2/voucher/void' && $method === 'POST') api_pc2_void();
    if ($path === '/api/petty-cash2/ledger' && $method === 'GET') api_pc2_ledger();

    // Phase 3e — financial statements (GL-derived)
    if ($path === '/api/income-expenditure' && $method === 'GET') api_income_expenditure();
    if ($path === '/api/sfp' && $method === 'GET') api_sfp();
    if ($path === '/api/cashflow' && $method === 'GET') api_cashflow();
    if ($path === '/api/ledger/reset-zero' && $method === 'POST') api_ledger_reset_zero();
    if ($path === '/api/year-end-status' && $method === 'GET') api_year_end_status();
    if ($path === '/api/year-end-close' && $method === 'POST') api_year_end_close();
    if ($path === '/api/export-file' && $method === 'POST') api_export_file();
    if ($path === '/api/flash-pack' && $method === 'GET') api_flash_pack();
    if ($path === '/api/audit-pack' && $method === 'GET') api_audit_pack();
    if ($path === '/api/audit/verify' && $method === 'GET') api_audit_verify();
    if ($path === '/api/bank-recon/worklist' && $method === 'GET') api_bank_recon_worklist();
    if ($path === '/api/bank-recon/clear' && $method === 'POST') api_bank_recon_clear();
    if ($path === '/api/statutory-filings' && $method === 'GET') api_statutory_filings();
    if ($path === '/api/opening-balances/post' && $method === 'POST') api_opening_balances_post();
    if ($path === '/api/withholding-payables' && $method === 'GET') api_withholding_list();
    if ($path === '/api/withholding-payables/settle' && $method === 'POST') api_withholding_settle();
    if ($path === '/api/fuel-coupons/batch' && $method === 'POST') api_fuel_batch_save();
    if ($path === '/api/fuel-coupons/batch/post' && $method === 'POST') api_fuel_batch_post();

    // Role-guard demonstration (Phase 1 acceptance: read-only roles cannot write).
    if ($path === '/api/_phase1_write_probe' && $method === 'POST') {
        require_role(['Admin', 'Finance Officer']); // Auditor/Viewer are blocked
        ok(['wrote' => true]);
    }

    err('Not found', 404);
} catch (Throwable $e) {
    err('Server error: ' . substr($e->getMessage(), 0, 200), 500);
}
