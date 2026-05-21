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
            $meta = json_decode((string) file_get_contents($metaPath), true);
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

function session_login(string $email): void {
    session_boot();
    session_regenerate_id(true);
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
