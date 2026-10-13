<?php
/**
 * ProjectCollab shared library.
 * Path on server: /home/collab/data/lib/lib.php
 * Included by ui/*.php, api/index.php, admin/*.php.
 */

declare(strict_types=1);

const COLLAB_DATA_ROOT    = '/home/collab/data';
const COLLAB_USERS_ROOT   = '/home/collab/data/users';
const COLLAB_CONFIG_PATH  = '/home/collab/data/config.json';
const COLLAB_USERS_PATH   = '/home/collab/data/users.json';

// --- Config ----------------------------------------------------------------

function cfg(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!is_file(COLLAB_CONFIG_PATH)) { $cached = []; return $cached; }
    $cached = json_decode((string) file_get_contents(COLLAB_CONFIG_PATH), true) ?: [];
    return $cached;
}

function cfg_get(string $key, $default = null) {
    return cfg()[$key] ?? $default;
}

// --- User database (users.json) -------------------------------------------

function users_load(): array {
    if (!is_file(COLLAB_USERS_PATH)) return ['users' => []];
    $d = json_decode((string) file_get_contents(COLLAB_USERS_PATH), true);
    return is_array($d) ? $d : ['users' => []];
}

function users_save(array $data): void {
    $tmp = COLLAB_USERS_PATH . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0660);
    @chgrp($tmp, 'www-data');
    rename($tmp, COLLAB_USERS_PATH);
}

function user_get(string $email): ?array {
    $d = users_load();
    return $d['users'][$email] ?? null;
}

function user_put(string $email, array $info): void {
    $d = users_load();
    $d['users'][$email] = $info;
    users_save($d);
}

function user_delete_row(string $email): bool {
    $d = users_load();
    if (!isset($d['users'][$email])) return false;
    unset($d['users'][$email]);
    users_save($d);
    return true;
}

function user_password_hash(string $pw): string {
    return password_hash($pw, PASSWORD_DEFAULT);
}

function user_password_verify(string $email, string $pw): bool {
    $u = user_get($email);
    if (!$u || empty($u['password_hash'])) return false;
    return password_verify($pw, $u['password_hash']);
}

// --- Validation -----------------------------------------------------------

function valid_email(string $s): bool {
    if (strlen($s) > 254) return false;
    return (bool) preg_match('/^[a-zA-Z0-9._+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $s);
}

function valid_project_slug(string $s): bool {
    return strlen($s) > 0 && strlen($s) <= 64 && (bool) preg_match('/^[a-z0-9_-]+$/i', $s);
}

function valid_role(string $r): bool {
    return in_array($r, ['admin','developer','collaborator'], true);
}

// --- Paths (with traversal protection) ------------------------------------

function user_dir(string $email): string {
    if (!valid_email($email)) {
        throw new InvalidArgumentException("Invalid email: " . substr($email, 0, 100));
    }
    $dir = COLLAB_USERS_ROOT . '/' . $email;
    // Belt-and-suspenders: the resolved path must stay under USERS_ROOT.
    if (is_dir($dir)) {
        $real = realpath($dir);
        $root = realpath(COLLAB_USERS_ROOT);
        if ($real === false || $root === false || strpos($real, $root . '/') !== 0) {
            throw new RuntimeException("Path traversal attempt");
        }
    }
    return $dir;
}

function project_dir(string $owner, string $project): string {
    if (!valid_project_slug($project)) {
        throw new InvalidArgumentException("Invalid project name");
    }
    $dir = user_dir($owner) . '/projects/' . $project;
    if (basename($dir) !== $project) {
        throw new RuntimeException("Path traversal attempt");
    }
    return $dir;
}

// --- Agent API: find project owning a given secret ------------------------

/**
 * Find (owner, project) for a secret, optionally cross-checked against the
 * URL's project name. Returns [owner, project, dir, meta] or null.
 *
 * Linear scan over users × projects. Fine for any realistic project count;
 * can be replaced with an index file later if needed.
 */
function find_project_by_secret(string $secret, string $expectedProject = ''): ?array {
    if ($expectedProject !== '' && !valid_project_slug($expectedProject)) return null;
    if (!is_dir(COLLAB_USERS_ROOT)) return null;

    foreach (scandir(COLLAB_USERS_ROOT) as $owner) {
        if ($owner === '.' || $owner === '..') continue;
        if (!valid_email($owner)) continue;
        $projRoot = COLLAB_USERS_ROOT . '/' . $owner . '/projects';
        if (!is_dir($projRoot)) continue;
        foreach (scandir($projRoot) as $proj) {
            if ($proj === '.' || $proj === '..') continue;
            if (!valid_project_slug($proj)) continue;
            if ($expectedProject !== '' && $proj !== $expectedProject) continue;
            $metaPath = "$projRoot/$proj/meta.json";
            if (!is_file($metaPath)) continue;
            $raw = (string) file_get_contents($metaPath);
            $meta = json_decode($raw, true);
            if ($meta === null && trim($raw) !== '') {
                // Torn/partial read (concurrent write) or corrupt file - log and skip,
                // rather than silently risking a stale/wrong match on retry.
                error_log(sprintf(
                    'find_project_by_secret: json_decode failed for %s (%s, %d bytes read)',
                    $metaPath, json_last_error_msg(), strlen($raw)
                ));
                continue;
            }
            if (is_array($meta) && isset($meta['agents'][$secret])) {
                return [
                    'owner'   => $owner,
                    'project' => $proj,
                    'dir'     => "$projRoot/$proj",
                    'meta'    => $meta,
                ];
            }
        }
    }
    return null;
}

/**
 * Return the list of projects the given user can see — owned + shared.
 * Each entry: ['owner', 'project', 'dir', 'meta', 'role_in_project']
 * where role_in_project is 'owner' or 'member'.
 */
function projects_visible_to(string $email): array {
    $out = [];
    if (!is_dir(COLLAB_USERS_ROOT)) return $out;

    foreach (scandir(COLLAB_USERS_ROOT) as $owner) {
        if ($owner === '.' || $owner === '..') continue;
        if (!valid_email($owner)) continue;
        $projRoot = COLLAB_USERS_ROOT . '/' . $owner . '/projects';
        if (!is_dir($projRoot)) continue;
        foreach (scandir($projRoot) as $proj) {
            if ($proj === '.' || $proj === '..') continue;
            if (!valid_project_slug($proj)) continue;
            $metaPath = "$projRoot/$proj/meta.json";
            if (!is_file($metaPath)) continue;
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (!is_array($meta)) continue;
            $sharedWith = $meta['shared_with'] ?? [];
            if ($owner === $email) {
                $out[] = ['owner'=>$owner, 'project'=>$proj, 'dir'=>"$projRoot/$proj", 'meta'=>$meta, 'role_in_project'=>'owner'];
            } elseif (in_array($email, $sharedWith, true)) {
                $out[] = ['owner'=>$owner, 'project'=>$proj, 'dir'=>"$projRoot/$proj", 'meta'=>$meta, 'role_in_project'=>'member'];
            }
        }
    }
    return $out;
}

/**
 * Can $email access (read/write inside) project $owner/$project?
 * Owner or shared_with member → yes. Admin role → also yes (admins see all).
 */
function user_can_access(string $email, string $owner, string $project): bool {
    if ($email === $owner) {
        return is_dir(project_dir($owner, $project));
    }
    $u = user_get($email);
    if ($u && ($u['role'] ?? '') === 'admin') {
        return is_dir(project_dir($owner, $project));
    }
    $metaPath = project_dir($owner, $project) . '/meta.json';
    if (!is_file($metaPath)) return false;
    $meta = json_decode((string) file_get_contents($metaPath), true);
    $sharedWith = $meta['shared_with'] ?? [];
    return in_array($email, $sharedWith, true);
}

// --- Session / auth (UI side) ---------------------------------------------

function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return false;
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $days = (int) cfg_get('session_lifetime_days', 7);
    // Use a private session directory.
    // On Debian/Ubuntu the PHP package cron cleans /var/lib/php/sessions/
    // using the gc_maxlifetime from the SYSTEM php.ini (default: 1440 s = 24 min),
    // completely ignoring any ini_set() we do here.  Sessions stored in a
    // non-default path are invisible to that cron and are only subject to
    // PHP's built-in probabilistic GC, which DOES honour our ini_set value.
    $sessDir = COLLAB_DATA_ROOT . '/sessions';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0700, true);
    ini_set('session.save_path', $sessDir);
    ini_set('session.cookie_lifetime', (string) ($days * 86400));
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', isHttps() ? '1' : '0');
    ini_set('session.gc_maxlifetime', (string) ($days * 86400));
    session_name('COLLABSESS');
    session_start();
}

function session_user(): ?array {
    session_boot();
    $email = $_SESSION['user'] ?? null;
    if (!$email) return null;
    $u = user_get($email);
    if (!$u) { session_destroy(); return null; }
    $u['email'] = $email;
    return $u;
}

function session_login(string $email, bool $remember = true): void {
    session_boot();
    session_regenerate_id(true);
    // Explicitly set the session cookie in BOTH branches.
    //
    // Root cause of the double-login bug:
    //   session_regenerate_id() does not reliably honour session.cookie_lifetime
    //   when that value was set via ini_set() rather than in php.ini.  In some
    //   PHP/OS combinations it silently emits a session cookie (no Max-Age /
    //   Expires) even when cookie_lifetime > 0.  The browser then discards the
    //   cookie on close and the user has to log in again.
    //
    // Fix: always call setcookie() explicitly after session_regenerate_id(),
    // mirroring what the !$remember branch already did.
    $p = session_get_cookie_params();
    if ($remember) {
        // Persistent cookie — survives browser close for the configured lifetime.
        setcookie(session_name(), session_id(),
                  time() + $p['lifetime'],
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    } else {
        // Session cookie — no Max-Age/Expires; browser discards on close.
        setcookie(session_name(), session_id(), 0,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    $_SESSION['user'] = $email;
    $u = user_get($email);
    if ($u) {
        $u['last_login'] = now_iso();
        user_put($email, $u);
    }
}

function session_logout(): void {
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): array {
    $u = session_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function require_role(string $minRole): array {
    $u = require_login();
    $rank = ['collaborator'=>1, 'developer'=>2, 'admin'=>3];
    if (($rank[$u['role']] ?? 0) < ($rank[$minRole] ?? 99)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden', 'message' => 'Insufficient role']);
        exit;
    }
    return $u;
}

function has_role_at_least(array $u, string $minRole): bool {
    $rank = ['collaborator'=>1, 'developer'=>2, 'admin'=>3];
    return ($rank[$u['role']] ?? 0) >= ($rank[$minRole] ?? 99);
}

// --- Mail -----------------------------------------------------------------

function send_mail(string $to, string $subject, string $body): bool {
    $from = (string) cfg_get('mail_from', 'noreply@localhost');
    $fromName = (string) cfg_get('mail_from_name', 'ProjectCollab');
    $headers = [];
    $headers[] = 'From: ' . encodeMailName($fromName) . ' <' . $from . '>';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'X-Mailer: ProjectCollab';
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function encodeMailName(string $name): string {
    return preg_match('/^[\x20-\x7E]+$/', $name) ? $name : '=?UTF-8?B?' . base64_encode($name) . '?=';
}

// --- Misc helpers ---------------------------------------------------------

function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

function gen_token(int $bytes = 24): string { return bin2hex(random_bytes($bytes)); }

function json_body(): array {
    $raw = (string) file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function html_escape(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function respond_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

// ==========================================================================
// === Kanban + user color helpers ==========================================
// ==========================================================================

const KANBAN_AZZURRO            = '#85B7EB';
const KANBAN_HUE_MIN_SEPARATION = 25.0;  // degrees
const KANBAN_HUE_RELAXED        = 15.0;  // degrees (fallback after 50 tries)
const KANBAN_COLOR_HISTORY_MAX  = 5;
const KANBAN_CLAIM_TTL_AGENT    = 3600;        // 1 hour
const KANBAN_CLAIM_TTL_HUMAN    = 7 * 86400;   // 7 days

// --- Color math -----------------------------------------------------------

function hex_to_hsl(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return [0.0, 0.0, 0.0];
    $r = hexdec(substr($hex, 0, 2)) / 255.0;
    $g = hexdec(substr($hex, 2, 2)) / 255.0;
    $b = hexdec(substr($hex, 4, 2)) / 255.0;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2.0;
    if ($max === $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2.0 - $max - $min) : $d / ($max + $min);
    if      ($max === $r) $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
    else if ($max === $g) $h = (($b - $r) / $d) + 2;
    else                  $h = (($r - $g) / $d) + 4;
    return [$h * 60.0, $s, $l];
}

function hsl_to_hex(float $h, float $s, float $l): string {
    $h = fmod(fmod($h, 360.0) + 360.0, 360.0);
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60.0, 2) - 1));
    $m = $l - $c / 2.0;
    if      ($h <  60) { $r = $c; $g = $x; $b = 0;  }
    else if ($h < 120) { $r = $x; $g = $c; $b = 0;  }
    else if ($h < 180) { $r = 0;  $g = $c; $b = $x; }
    else if ($h < 240) { $r = 0;  $g = $x; $b = $c; }
    else if ($h < 300) { $r = $x; $g = 0;  $b = $c; }
    else               { $r = $c; $g = 0;  $b = $x; }
    return sprintf('#%02X%02X%02X',
        (int) round(($r + $m) * 255),
        (int) round(($g + $m) * 255),
        (int) round(($b + $m) * 255));
}

function hue_distance(float $h1, float $h2): float {
    $d = fmod(abs($h1 - $h2), 360.0);
    return $d > 180.0 ? 360.0 - $d : $d;
}

function valid_hex_color(string $s): bool {
    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $s);
}

/**
 * Generate a fresh color whose hue is well separated from azzurro and from
 * any provided forbidden_hex list. Falls back to a looser separation after
 * 50 attempts, and finally returns *something* even if everything's crowded.
 */
function generate_user_color(array $forbidden_hex = []): string {
    $forbidden_hues = [hex_to_hsl(KANBAN_AZZURRO)[0]];
    foreach ($forbidden_hex as $hx) {
        if (valid_hex_color($hx)) $forbidden_hues[] = hex_to_hsl($hx)[0];
    }
    $pick = function(float $min_sep) use ($forbidden_hues): ?string {
        for ($i = 0; $i < 50; $i++) {
            $h = mt_rand(0, 35999) / 100.0;
            $ok = true;
            foreach ($forbidden_hues as $fh) {
                if (hue_distance($h, $fh) < $min_sep) { $ok = false; break; }
            }
            if ($ok) {
                $s = mt_rand(55, 80) / 100.0;
                $l = mt_rand(45, 60) / 100.0;
                return hsl_to_hex($h, $s, $l);
            }
        }
        return null;
    };
    $c = $pick(KANBAN_HUE_MIN_SEPARATION);
    if ($c !== null) return $c;
    $c = $pick(KANBAN_HUE_RELAXED);
    if ($c !== null) return $c;
    // Last resort: random color, no separation guarantee.
    return hsl_to_hex(mt_rand(0, 35999) / 100.0, 0.65, 0.5);
}

/**
 * Collect all existing user colors EXCEPT the given email's own color.
 * Used when generating a fresh color for a specific user.
 */
function collect_other_user_colors(string $exclude_email): array {
    $d = users_load();
    $out = [];
    foreach (($d['users'] ?? []) as $email => $info) {
        if ($email === $exclude_email) continue;
        $c = $info['color'] ?? null;
        if ($c && valid_hex_color($c)) $out[] = $c;
    }
    return $out;
}

// --- Kanban store ---------------------------------------------------------

function kanban_path(string $dir): string {
    return rtrim($dir, '/') . '/kanban.json';
}

function kanban_default(): array {
    return [
        'wps' => [
            ['id' => 'wp_001', 'name' => 'Phase 0', 'tasks' => []],
        ],
        'next_wp_id'     => 2,
        'next_task_id'   => 1,
        'kanban_version' => 1,
    ];
}

function kanban_load(string $dir): array {
    $path = kanban_path($dir);
    if (!is_file($path)) return kanban_default();
    $d = json_decode((string) file_get_contents($path), true);
    if (!is_array($d) || !isset($d['wps'])) return kanban_default();
    // Defensive defaults
    $d['kanban_version'] = (int) ($d['kanban_version'] ?? 1);
    $d['next_wp_id']     = (int) ($d['next_wp_id']     ?? 1);
    $d['next_task_id']   = (int) ($d['next_task_id']   ?? 1);
    return $d;
}

function kanban_save(string $dir, array $data): void {
    $path = kanban_path($dir);
    $tmp  = $path . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0660);
    @chgrp($tmp, 'www-data');
    rename($tmp, $path);
}

/**
 * Sweep expired claims. Returns true if any claim was reverted.
 */
function kanban_resolve_expirations(array &$data): bool {
    $now = time();
    $changed = false;
    foreach ($data['wps'] as &$wp) {
        foreach ($wp['tasks'] as &$task) {
            if (($task['state'] ?? '') !== 'claimed') continue;
            $claimedAt = strtotime($task['claimed_at'] ?? '');
            if (!$claimedAt) continue;
            $ttl = (($task['capability'] ?? 'agent') === 'human')
                ? KANBAN_CLAIM_TTL_HUMAN : KANBAN_CLAIM_TTL_AGENT;
            if ($now - $claimedAt > $ttl) {
                $task['state']      = 'ready';
                $task['claimed_by'] = null;
                $task['claimed_by_name'] = null;
                $task['claimed_at'] = null;
                $changed = true;
            }
        }
        unset($task);
    }
    unset($wp);
    return $changed;
}

/**
 * Recompute open/ready states across the board. Does NOT touch
 * claimed/partly/done. A task is 'ready' iff all earlier non-parallel
 * tasks in its WP are 'done'. Parallel siblings within a group share the
 * readiness of the group's first task.
 */
function kanban_compute_states(array &$data): void {
    foreach ($data['wps'] as &$wp) {
        $groupReady = true;
        $allEarlierDone = true;
        foreach ($wp['tasks'] as $i => &$task) {
            $isParallel = !empty($task['parallel_with_prev']) && $i > 0;
            if (!$isParallel) {
                $groupReady = $allEarlierDone;
            }
            $state = $task['state'] ?? 'open';
            if ($state === 'open' || $state === 'ready') {
                $task['state'] = $groupReady ? 'ready' : 'open';
            }
            // Once we see any non-done task, no later group can become ready
            if (($task['state'] ?? 'open') !== 'done') {
                $allEarlierDone = false;
            }
        }
        unset($task);
    }
    unset($wp);
}

/**
 * Atomic kanban update under flock. The callback receives the loaded data
 * (already expiration-swept) and may mutate it. The callback may return:
 *   - null / nothing  → treat as "yes, changed" (default); write + bump version
 *   - ['_no_change' => true, ...other...] → don't bump or write; pass result through
 *   - any other array → treat as "yes, changed"; the array is the result payload
 *
 * If $expected_version is non-null and doesn't match, throws RuntimeException
 * with message starting with "KANBAN_VERSION_CONFLICT:" that the caller catches.
 *
 * Returns [final_data, callback_result].
 */
function kanban_with_lock(string $dir, ?int $expected_version, callable $fn) {
    $lockPath = $dir . '/.lock';
    if (!is_file($lockPath)) { @touch($lockPath); @chmod($lockPath, 0660); }
    $fh = fopen($lockPath, 'r');
    if (!$fh) throw new RuntimeException('Cannot open lock');
    if (!flock($fh, LOCK_EX)) { fclose($fh); throw new RuntimeException('Cannot acquire lock'); }
    try {
        $data = kanban_load($dir);
        $sweepChanged = kanban_resolve_expirations($data);
        if ($expected_version !== null && (int)$data['kanban_version'] !== $expected_version) {
            throw new RuntimeException('KANBAN_VERSION_CONFLICT:' . (int)$data['kanban_version']);
        }
        $result = $fn($data);  // may mutate $data
        $skipWrite = is_array($result) && !empty($result['_no_change']);
        if ($skipWrite && !$sweepChanged) {
            // Pure no-op: don't write, don't bump version
            return [$data, $result];
        }
        kanban_compute_states($data);
        $data['kanban_version'] = ((int)$data['kanban_version']) + 1;
        kanban_save($dir, $data);
        return [$data, $result];
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// --- Per-project Kanban enablement ----------------------------------------

function project_has_kanban(string $dir): bool {
    $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
    return is_array($meta) && !empty($meta['has_kanban']);
}

function project_set_kanban(string $dir, bool $enabled): void {
    $meta = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
    if (!is_array($meta)) $meta = [];
    $meta['has_kanban'] = $enabled;
    $tmp = $dir . '/meta.json.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0660);
    @chgrp($tmp, 'www-data');
    rename($tmp, $dir . '/meta.json');
    if ($enabled && !is_file(kanban_path($dir))) {
        kanban_save($dir, kanban_default());
    }
}

// ==========================================================================
// === Agent identity + roles (Phase A) =====================================
// ==========================================================================

const AGENT_CLASSES = ['manager', 'worker'];

/**
 * A stable, non-secret identifier for an agent. Stored in the agent record and
 * used in kanban claims/comments instead of the secret — the secret must NEVER
 * be written into kanban.json (it is readable by every project member).
 */
function gen_agent_id(): string {
    return 'ag_' . bin2hex(random_bytes(4));
}

function valid_agent_class(string $c): bool {
    return in_array($c, AGENT_CLASSES, true);
}

/** Read a project's meta.json (or [] if missing/corrupt). */
function meta_load(string $dir): array {
    $m = json_decode((string) @file_get_contents($dir . '/meta.json'), true);
    return is_array($m) ? $m : [];
}

/** Atomically write a project's meta.json. */
function meta_save(string $dir, array $meta): void {
    $tmp = $dir . '/meta.json.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($tmp, 0660);
    @chgrp($tmp, 'www-data');
    rename($tmp, $dir . '/meta.json');
}

// --- Per-project guest-worker enablement (foundation; wired up in Phase C) --

function project_has_guest_workers(string $dir): bool {
    $meta = meta_load($dir);
    return !empty($meta['guest_workers']);
}

function project_set_guest_workers(string $dir, bool $enabled): void {
    $meta = meta_load($dir);
    $meta['guest_workers'] = $enabled;
    meta_save($dir, $meta);
}

// ==========================================================================
// === Shared kanban helpers (used by both the UI and the agent API) ========
// ==========================================================================

/**
 * Locate a task by id within the board. Returns a reference to the task (so
 * callers can mutate) or null if not found. Callers MUST assign by reference:
 *   $t = &kanban_find_task($data, $id);
 */
function &kanban_find_task(array &$data, string $taskId) {
    $null = null;
    foreach (($data['wps'] ?? []) as $wi => $wp) {
        foreach (($wp['tasks'] ?? []) as $ti => $task) {
            if (($task['id'] ?? '') === $taskId) {
                return $data['wps'][$wi]['tasks'][$ti];
            }
        }
    }
    return $null;
}

/**
 * Validate a wps array schema. Returns null on success or an error string.
 */
function kanban_validate_wps(array $wps): ?string {
    $seenWpIds = [];
    $seenTaskIds = [];
    foreach ($wps as $i => $wp) {
        if (!is_array($wp)) return "wp[$i] is not an object";
        $id = $wp['id'] ?? null;
        if (!is_string($id) || !preg_match('/^wp_\d+$/', $id)) {
            return "wp[$i].id is missing or malformed";
        }
        if (isset($seenWpIds[$id])) return "duplicate wp id: $id";
        $seenWpIds[$id] = true;

        $name = $wp['name'] ?? '';
        if (!is_string($name)) return "wp[$i].name must be a string";

        $tasks = $wp['tasks'] ?? [];
        if (!is_array($tasks)) return "wp[$i].tasks must be an array";

        foreach ($tasks as $j => $t) {
            if (!is_array($t)) return "wp[$i].tasks[$j] is not an object";
            $tid = $t['id'] ?? null;
            if (!is_string($tid) || !preg_match('/^t_\d+$/', $tid)) {
                return "wp[$i].tasks[$j].id missing or malformed";
            }
            if (isset($seenTaskIds[$tid])) return "duplicate task id: $tid";
            $seenTaskIds[$tid] = true;

            $cap = $t['capability'] ?? 'agent';
            if (!in_array($cap, ['agent', 'human'], true)) {
                return "wp[$i].tasks[$j].capability must be 'agent' or 'human'";
            }
            $state = $t['state'] ?? 'open';
            if (!in_array($state, ['open', 'ready', 'claimed', 'partly', 'done'], true)) {
                return "wp[$i].tasks[$j].state invalid: $state";
            }
            if ($j === 0 && !empty($t['parallel_with_prev'])) {
                return "wp[$i].tasks[0].parallel_with_prev cannot be true (no previous task)";
            }
        }
    }
    return null;
}

// ==========================================================================
// === Guest workers (Phase C) ==============================================
// ==========================================================================
//
// A single shared guest secret (stored in data/guest-workers.json) lets
// transient "guest" agents connect. On connect they are round-robined to the
// next opted-in project that has claimable agent work, assigned the lowest free
// name-slot (1 -> "Xavier", 2 -> "Xavier2", ...), and given an ephemeral session
// token used as X-Agent-Secret for the rest of the session. Slots recycle once
// idle past GUEST_IDLE_TTL. See docs/kanban-agents-plan.md §7.

const GUEST_IDLE_TTL = 3600;   // 1 h, aligned with the agent claim TTL

function guest_file(): string { return COLLAB_DATA_ROOT . '/guest-workers.json'; }

function guest_data_load(): array {
    $d = json_decode((string) @file_get_contents(guest_file()), true);
    if (!is_array($d)) $d = [];
    if (!isset($d['slots']) || !is_array($d['slots'])) $d['slots'] = [];
    if (!array_key_exists('rr_cursor', $d)) $d['rr_cursor'] = '';
    return $d;
}

// The shared secret itself lives in data/guest-worker.env (gitignore-friendly,
// KEY=VALUE), not in guest-workers.json alongside it - so a fresh checkout of
// this repo never ships a real secret, and the whole file can be git-ignored
// as a unit without auditing its JSON contents.
const GUEST_SECRET_ENV_PATH = COLLAB_DATA_ROOT . '/guest-worker.env';

function guest_secret(): ?string {
    if (!is_file(GUEST_SECRET_ENV_PATH)) return null;
    $env = @parse_ini_file(GUEST_SECRET_ENV_PATH, false, INI_SCANNER_RAW);
    $s = is_array($env) ? ($env['GUEST_WORKER_SECRET'] ?? '') : '';
    return (is_string($s) && $s !== '') ? $s : null;
}

/** Idempotent: if data/guest-worker.env already holds a secret, return it
 *  unchanged. Otherwise create one - migrating a pre-existing secret out of
 *  the legacy guest-workers.json location if found there, else generating a
 *  fresh one - and persist it. Safe to call on every admin login. */
function guest_secret_ensure(): string {
    $existing = guest_secret();
    if ($existing !== null) return $existing;

    $lockPath = COLLAB_DATA_ROOT . '/guest-worker.env.lock';
    $fh = fopen($lockPath, 'c');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        throw new RuntimeException('guest secret lock failed');
    }
    try {
        $existing = guest_secret();   // re-check: lost the race to another process
        if ($existing !== null) return $existing;

        $legacy = guest_data_load()['secret'] ?? null;
        $secret = (is_string($legacy) && $legacy !== '') ? $legacy : bin2hex(random_bytes(16));

        $tmp = GUEST_SECRET_ENV_PATH . '.tmp.' . getmypid();
        file_put_contents($tmp, "GUEST_WORKER_SECRET=$secret\n");
        @chmod($tmp, 0660);
        @chgrp($tmp, 'www-data');
        rename($tmp, GUEST_SECRET_ENV_PATH);

        if (is_string($legacy) && $legacy !== '') {
            guest_with_lock(function (array &$d) { unset($d['secret']); });
        }
        return $secret;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function is_guest_secret(string $s): bool {
    $gs = guest_secret();
    return $gs !== null && hash_equals($gs, $s);
}

/** Run $fn under an exclusive global lock with the guest data by reference,
 *  then persist. Returns whatever $fn returns. */
function guest_with_lock(callable $fn) {
    $lockPath = COLLAB_DATA_ROOT . '/guest-workers.lock';
    $fh = fopen($lockPath, 'c');
    if ($fh === false) throw new RuntimeException('guest lock open failed');
    if (!flock($fh, LOCK_EX)) { fclose($fh); throw new RuntimeException('guest lock failed'); }
    try {
        $d = guest_data_load();
        $result = $fn($d);
        $tmp = guest_file() . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0660);
        @chgrp($tmp, 'www-data');
        rename($tmp, guest_file());
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function guest_slot_name(int $n): string { return $n === 1 ? 'Xavier' : 'Xavier' . $n; }

/** Count ready + unclaimed agent-capability tasks (claimable guest work). */
function guest_available_task_count(string $dir): int {
    if (!project_has_kanban($dir)) return 0;
    $data = kanban_load($dir);
    kanban_resolve_expirations($data);
    kanban_compute_states($data);
    $n = 0;
    foreach (($data['wps'] ?? []) as $wp) {
        foreach (($wp['tasks'] ?? []) as $t) {
            $cap   = $t['capability'] ?? 'agent';
            $state = $t['state'] ?? 'open';
            if ($cap === 'agent' && $state === 'ready' && empty($t['claimed_by'])) $n++;
        }
    }
    return $n;
}

/** All guest-enabled projects with >=1 claimable agent task, ordered by owner/slug. */
function guest_eligible_projects(): array {
    $out = [];
    $root = COLLAB_USERS_ROOT;
    if (!is_dir($root)) return $out;
    foreach (scandir($root) as $owner) {
        if ($owner === '.' || $owner === '..' || !valid_email($owner)) continue;
        $projRoot = "$root/$owner/projects";
        if (!is_dir($projRoot)) continue;
        foreach (scandir($projRoot) as $proj) {
            if ($proj === '.' || $proj === '..' || !valid_project_slug($proj)) continue;
            $dir = "$projRoot/$proj";
            if (!is_file("$dir/meta.json")) continue;
            if (empty(meta_load($dir)['guest_workers'])) continue;
            $avail = guest_available_task_count($dir);
            if ($avail < 1) continue;
            $out[] = ['owner' => $owner, 'project' => $proj, 'dir' => $dir, 'available' => $avail];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['owner'] . '/' . $a['project'], $b['owner'] . '/' . $b['project']));
    return $out;
}

/** Resolve a guest session token to its assigned slot/project, refreshing the
 *  slot's last_seen. Returns ['n','name','owner','project','dir'] or null. */
function guest_resolve_token(string $token): ?array {
    if (strpos($token, 'gw_') !== 0) return null;
    return guest_with_lock(function (array &$d) use ($token) {
        $now = time();
        $found = null;
        foreach (array_keys($d['slots']) as $n) {
            $slot = $d['slots'][$n];
            $ls = strtotime($slot['last_seen'] ?? '1970-01-01T00:00:00Z');
            if ($now - $ls > GUEST_IDLE_TTL) { unset($d['slots'][$n]); continue; }
            if (hash_equals((string) ($slot['token'] ?? ''), $token)) {
                $d['slots'][$n]['last_seen'] = now_iso();
                $owner   = (string) ($slot['owner'] ?? '');
                $project = (string) ($slot['project'] ?? '');
                $found = [
                    'n'       => (int) $n,
                    'name'    => guest_slot_name((int) $n),
                    'owner'   => $owner,
                    'project' => $project,
                    'dir'     => project_dir($owner, $project),
                ];
            }
        }
        return $found;
    });
}

/** Round-robin assign a connecting guest. Returns the assignment array (with a
 *  freshly minted token) or ['assigned' => false] if no work is available. */
function guest_assign(): array {
    return guest_with_lock(function (array &$d) {
        $now = time();
        foreach (array_keys($d['slots']) as $n) {
            $ls = strtotime($d['slots'][$n]['last_seen'] ?? '1970-01-01T00:00:00Z');
            if ($now - $ls > GUEST_IDLE_TTL) unset($d['slots'][$n]);
        }
        $elig = guest_eligible_projects();
        if (empty($elig)) return ['assigned' => false];

        $cursor = (string) ($d['rr_cursor'] ?? '');
        $pick = null;
        foreach ($elig as $e) {
            if (strcmp($e['owner'] . '/' . $e['project'], $cursor) > 0) { $pick = $e; break; }
        }
        if ($pick === null) $pick = $elig[0];   // wrap around
        $d['rr_cursor'] = $pick['owner'] . '/' . $pick['project'];

        $n = 1;
        while (isset($d['slots'][(string) $n])) $n++;
        $token = 'gw_' . bin2hex(random_bytes(16));
        $d['slots'][(string) $n] = [
            'last_seen' => now_iso(),
            'token'     => $token,
            'owner'     => $pick['owner'],
            'project'   => $pick['project'],
        ];
        return [
            'assigned'  => true,
            'n'         => $n,
            'name'      => guest_slot_name($n),
            'token'     => $token,
            'owner'     => $pick['owner'],
            'project'   => $pick['project'],
            'dir'       => $pick['dir'],
            'available' => $pick['available'],
        ];
    });
}
