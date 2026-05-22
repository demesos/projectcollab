<?php
/**
 * Admin API for the human web UI (multi-user).
 *
 * Auth: PHP session (require_login / require_role).
 * Endpoints:
 *   --- Projects ---
 *   GET    ?action=list-projects                       (owned + shared)
 *   GET    ?action=project&owner=<email>&p=<name>      (omit owner = current user)
 *   POST   ?action=create-project                      body: {name, display_name, description, naming_scheme}
 *   POST   ?action=delete-project                      body: {owner, name}
 *   POST   ?action=update-description                  body: {owner, project, description}
 *   POST   ?action=share-project                       body: {owner, project, username}
 *   POST   ?action=unshare-project                     body: {owner, project, username}
 *   POST   ?action=leave-project                       body: {owner, project}
 *   GET    ?action=backup&owner=<email>&p=<name>       (admin or owner)
 *   --- Agents ---
 *   POST   ?action=add-agent                           body: {owner, project, name, role}
 *   POST   ?action=remove-agent                        body: {owner, project, secret}
 *   --- Users ---
 *   GET    ?action=list-users                          (admin)
 *   GET    ?action=user-suggestions                    (any logged-in: list of emails for autocomplete)
 *   POST   ?action=create-user                         body: {email, role, password?, send_email?}      (admin)
 *   POST   ?action=remove-user                         body: {email, confirm}                            (admin)
 *   POST   ?action=change-role                         body: {email, role}                               (admin)
 *   POST   ?action=set-password                        body: {email, password}                           (admin sets others, user sets own)
 *   POST   ?action=user-impact                         body: {email}  -> owned/shared listings           (admin)
 *   GET    ?action=whoami                              (any logged-in)
 */

declare(strict_types=1);
require '/home/collab/data/lib/lib.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
// Support method override for PUT/DELETE (blocked by Apache mod_userdir)
if ($method === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
}

try {
    // All admin-API endpoints require a logged-in user.
    $user = require_login();

    switch ("$method $action") {
        case 'GET list-projects':        listProjects($user); break;
        case 'GET project':              getProject($user); break;
        case 'POST create-project':      createProject($user, json_body()); break;
        case 'POST delete-project':      deleteProject($user, json_body()); break;
        case 'POST update-description':  updateDescription($user, json_body()); break;
        case 'POST share-project':       shareProject($user, json_body()); break;
        case 'POST unshare-project':     unshareProject($user, json_body()); break;
        case 'POST leave-project':       leaveProject($user, json_body()); break;
        case 'GET backup':               backupProject($user); break;
        case 'POST restore-backup':       restoreBackup($user); break;

        case 'POST add-agent':           addAgent($user, json_body()); break;
        case 'POST remove-agent':        removeAgent($user, json_body()); break;

        case 'GET list-users':           listUsers($user); break;
        case 'GET user-suggestions':     userSuggestions($user); break;
        case 'POST create-user':         createUser($user, json_body()); break;
        case 'POST remove-user':         removeUser($user, json_body()); break;
        case 'POST change-role':         changeRole($user, json_body()); break;
        case 'POST set-password':        setPassword($user, json_body()); break;
        case 'POST user-impact':         userImpact($user, json_body()); break;
        case 'GET whoami':               respond_json(200, ['email'=>$user['email'], 'role'=>$user['role']]); break;
        case 'GET skill-zip':            downloadSkillZip(); break;

        // --- File proxy (human UI acts as owner agent) ---
        case 'GET files':                proxyGetFiles($user); break;
        case 'POST files-seen':          proxyFilesSeen($user); break;
        case 'GET file':                 proxyGetFile($user); break;
        case 'PUT file':                 proxyPutFile($user); break;
        case 'DELETE file':              proxyDeleteFile($user); break;

        // --- Chat proxy (posts attributed to human display name) ---
        case 'GET chat':                 proxyGetChat($user); break;
        case 'POST chat-seen':           proxyChatSeen($user); break;
        case 'POST chat':                proxyPostChat($user, json_body()); break;

        // --- News/presence proxy ---
        case 'GET news':                 proxyGetNews($user); break;
        case 'GET presence':             proxyGetPresence($user); break;

        default:
            respond_json(404, ['error' => "Unknown action: $method $action"]);
    }
} catch (Throwable $e) {
    respond_json(500, ['error' => 'server_error', 'message' => $e->getMessage()]);
}

// =============================================================================
// PROJECT HANDLERS
// =============================================================================

function listProjects(array $user): void {
    $items = projects_visible_to($user['email']);
    $out = [];
    foreach ($items as $it) {
        $meta = $it['meta'];
        $versionsPath = $it['dir'] . '/versions.json';
        $versions = is_file($versionsPath)
            ? (json_decode((string) file_get_contents($versionsPath), true) ?: [])
            : [];
        $lastActivity = '1970-01-01T00:00:00Z';
        foreach (($versions['last_seen'] ?? []) as $ts) {
            if ($ts > $lastActivity) $lastActivity = $ts;
        }
        $fileCount = 0;
        foreach (($versions['files'] ?? []) as $f) {
            if (($f['state'] ?? 'active') === 'active') $fileCount++;
        }
        $chatCount = 0;
        if (is_file($it['dir'] . '/chat.log')) {
            $chatCount = max(0, count(file($it['dir'] . '/chat.log', FILE_SKIP_EMPTY_LINES)));
        }
        $out[] = [
            'name'             => $it['project'],
            'owner'            => $it['owner'],
            'role_in_project'  => $it['role_in_project'],
            'display_name'     => $meta['name'] ?? $it['project'],
            'description'      => $meta['description'] ?? '',
            'created'          => $meta['created'] ?? null,
            'naming_scheme'    => $meta['naming_scheme'] ?? 'german',
            'shared_with'      => $meta['shared_with'] ?? [],
            'agent_count'      => count($meta['agents'] ?? []),
            'file_count'       => $fileCount,
            'chat_count'       => $chatCount,
            'last_activity'    => $lastActivity === '1970-01-01T00:00:00Z' ? null : $lastActivity,
        ];
    }
    respond_json(200, ['projects' => $out]);
}

function getProject(array $user): void {
    $project = (string) ($_GET['p'] ?? '');
    $owner = (string) ($_GET['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);

    $dir = project_dir($owner, $project);
    $meta = jsonRead("$dir/meta.json") ?? [];
    $versions = jsonRead("$dir/versions.json") ?? [];

    $agents = [];
    foreach ($meta['agents'] ?? [] as $secret => $info) {
        $agents[] = [
            'secret'    => $secret,
            'name'      => $info['name'] ?? '',
            'role'      => $info['role'] ?? '',
            'admin'     => !empty($info['admin']),
            'last_seen' => $versions['last_seen'][$secret] ?? null,
        ];
    }

    respond_json(200, [
        'name'             => $project,
        'owner'            => $owner,
        'role_in_project'  => $owner === $user['email'] ? 'owner' : 'member',
        'display_name'     => $meta['name'] ?? $project,
        'description'      => $meta['description'] ?? '',
        'created'          => $meta['created'] ?? null,
        'naming_scheme'    => $meta['naming_scheme'] ?? 'german',
        'shared_with'      => $meta['shared_with'] ?? [],
        'agents'           => $agents,
    ]);
}

function createProject(array $user, array $body): void {
    // Developer or admin can create projects.
    if (!has_role_at_least($user, 'developer')) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'Developer role required to create projects']);
    }

    $name = (string) ($body['name'] ?? '');
    if (!valid_project_slug($name)) {
        respond_json(400, ['error' => 'Invalid project name (use [a-z0-9_-] only)']);
    }
    $owner = $user['email'];
    $dir = project_dir($owner, $name);
    if (is_dir($dir)) {
        respond_json(409, ['error' => 'Project already exists']);
    }

    $allowedSchemes = ['english','german','italian','french','spanish','swedish','slovene'];
    $namingScheme = $body['naming_scheme'] ?? 'german';
    if (!in_array($namingScheme, $allowedSchemes, true)) $namingScheme = 'german';

    // Make sure owner's user dir exists.
    $userBase = user_dir($owner);
    if (!is_dir("$userBase/projects")) {
        if (!is_dir($userBase)) {
            mkdir($userBase, 0770, true);
            @chgrp($userBase, 'www-data');
        }
        mkdir("$userBase/projects", 0770, true);
        @chgrp("$userBase/projects", 'www-data');
    }

    mkdir($dir, 0770, true);
    mkdir("$dir/files", 0770, true);
    @chgrp($dir, 'www-data');
    @chgrp("$dir/files", 'www-data');

    $ownerSecret = bin2hex(random_bytes(16));
    $ownerLocal = strstr($owner, '@', true) ?: $owner;
    $meta = [
        'name'           => $body['display_name'] ?? ucfirst($name),
        'description'    => $body['description'] ?? 'New project.',
        'created'        => now_iso(),
        'naming_scheme'  => $namingScheme,
        'shared_with'    => [],
        'agents'         => [
            $ownerSecret => ['name' => $ownerLocal, 'role' => 'owner', 'admin' => true],
        ],
    ];
    writeWithPerms("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    writeWithPerms("$dir/versions.json", json_encode(['files' => new stdClass(), 'reads' => new stdClass(), 'last_seen' => new stdClass()], JSON_PRETTY_PRINT), 0660);
    writeWithPerms("$dir/chat.log", '', 0660);
    writeWithPerms("$dir/.lock", '', 0660);

    respond_json(200, ['name' => $name, 'owner' => $owner]);
}

function deleteProject(array $user, array $body): void {
    $project = (string) ($body['name'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    // Only the project owner or a global admin can delete.
    if ($owner !== $user['email'] && !has_role_at_least($user, 'admin')) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'Only the project owner or an admin can delete']);
    }
    if (!has_role_at_least($user, 'developer')) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'Developer role required']);
    }
    $dir = project_dir($owner, $project);
    if (!is_dir($dir)) respond_json(404, ['error' => 'Project not found']);
    rrmdir($dir);
    respond_json(200, ['deleted' => $project]);
}

function updateDescription(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    $metaPath = "$dir/meta.json";
    $meta = jsonRead($metaPath);
    $meta['description'] = (string) ($body['description'] ?? '');
    writeWithPerms($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['ok' => true]);
}

function shareProject(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    $username = (string) ($body['username'] ?? '');
    requireProjectOwner($user, $owner, $project);
    if (!valid_email($username)) respond_json(400, ['error' => 'Invalid username (email)']);
    if (!user_get($username)) respond_json(404, ['error' => 'User does not exist']);
    if ($username === $owner) respond_json(400, ['error' => 'Cannot share with owner']);

    $dir = project_dir($owner, $project);
    $meta = jsonRead("$dir/meta.json");
    $shared = $meta['shared_with'] ?? [];
    if (!in_array($username, $shared, true)) {
        $shared[] = $username;
    }
    $meta['shared_with'] = array_values(array_unique($shared));
    writeWithPerms("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['shared_with' => $meta['shared_with']]);
}

function unshareProject(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    $username = (string) ($body['username'] ?? '');
    requireProjectOwner($user, $owner, $project);

    $dir = project_dir($owner, $project);
    $meta = jsonRead("$dir/meta.json");
    $shared = $meta['shared_with'] ?? [];
    $meta['shared_with'] = array_values(array_filter($shared, fn($u) => $u !== $username));
    writeWithPerms("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['shared_with' => $meta['shared_with']]);
}

function leaveProject(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? '');
    if ($owner === $user['email']) {
        respond_json(400, ['error' => 'Owners cannot leave their own project — delete it instead']);
    }
    $dir = project_dir($owner, $project);
    if (!is_dir($dir)) respond_json(404, ['error' => 'Project not found']);
    $metaPath = "$dir/meta.json";
    $meta = jsonRead($metaPath);
    $shared = $meta['shared_with'] ?? [];
    if (!in_array($user['email'], $shared, true)) {
        respond_json(400, ['error' => 'You are not a member of this project']);
    }
    $meta['shared_with'] = array_values(array_filter($shared, fn($u) => $u !== $user['email']));
    writeWithPerms($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['left' => $project]);
}

function backupProject(array $user): void {
    $project = (string) ($_GET['p'] ?? '');
    $owner = (string) ($_GET['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    if (!is_dir($dir)) respond_json(404, ['error' => 'Project not found']);
    $stamp = gmdate('Ymd-His');
    $filename = "$project-$stamp.tar.gz";
    header('Content-Type: application/gzip');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $parent = dirname($dir);
    $base = basename($dir);
    passthru("tar -czf - -C " . escapeshellarg($parent) . " " . escapeshellarg($base));
    exit;
}

// =============================================================================
// AGENT HANDLERS (per project)
// =============================================================================

function addAgent(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);

    $dir = project_dir($owner, $project);
    $metaPath = "$dir/meta.json";
    $meta = jsonRead($metaPath);

    $name = trim((string) ($body['name'] ?? ''));
    $role = trim((string) ($body['role'] ?? ''));
    if ($name === '' || $role === '') {
        respond_json(400, ['error' => 'name and role required']);
    }

    $secret = bin2hex(random_bytes(16));
    $meta['agents'][$secret] = ['name' => $name, 'role' => $role];

    writeWithPerms($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['secret' => $secret, 'name' => $name, 'role' => $role]);
}

function removeAgent(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);

    $secret = (string) ($body['secret'] ?? '');
    $dir = project_dir($owner, $project);
    $metaPath = "$dir/meta.json";
    $meta = jsonRead($metaPath);

    if (!isset($meta['agents'][$secret])) {
        respond_json(404, ['error' => 'Agent not found']);
    }
    if (!empty($meta['agents'][$secret]['admin'])) {
        respond_json(400, ['error' => 'Cannot remove the owner agent']);
    }
    unset($meta['agents'][$secret]);
    writeWithPerms($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    respond_json(200, ['removed' => $secret]);
}

// =============================================================================
// USER HANDLERS
// =============================================================================

function listUsers(array $user): void {
    if (!has_role_at_least($user, 'admin')) {
        respond_json(403, ['error' => 'forbidden']);
    }
    $d = users_load();
    $out = [];
    foreach ($d['users'] ?? [] as $email => $info) {
        $owned = 0;
        $shared = 0;
        foreach (projects_visible_to($email) as $p) {
            if ($p['role_in_project'] === 'owner') $owned++;
            else $shared++;
        }
        $out[] = [
            'email'         => $email,
            'role'          => $info['role'] ?? 'collaborator',
            'created'       => $info['created'] ?? null,
            'last_login'    => $info['last_login'] ?? null,
            'owned_count'   => $owned,
            'shared_count'  => $shared,
        ];
    }
    respond_json(200, ['users' => $out]);
}

function userSuggestions(array $user): void {
    // Any logged-in user can get the list (for sharing autocomplete).
    $d = users_load();
    $emails = array_keys($d['users'] ?? []);
    sort($emails);
    respond_json(200, ['emails' => $emails]);
}

function createUser(array $user, array $body): void {
    if (!has_role_at_least($user, 'admin')) {
        respond_json(403, ['error' => 'forbidden']);
    }
    $email = trim((string) ($body['email'] ?? ''));
    $role = (string) ($body['role'] ?? 'collaborator');
    $pw = (string) ($body['password'] ?? '');
    $sendEmail = !empty($body['send_email']);

    if (!valid_email($email)) respond_json(400, ['error' => 'Invalid email']);
    if (!valid_role($role)) respond_json(400, ['error' => 'Invalid role']);
    if (user_get($email)) respond_json(409, ['error' => 'User already exists']);

    if ($pw === '' && !$sendEmail) {
        respond_json(400, ['error' => 'Either set a password or request emailed reset link']);
    }
    if ($pw !== '' && strlen($pw) < 8) {
        respond_json(400, ['error' => 'Password must be at least 8 characters']);
    }

    $info = [
        'role'                  => $role,
        'password_hash'         => $pw !== '' ? user_password_hash($pw) : '',
        'created'               => now_iso(),
        'last_login'            => null,
        'reset_token'           => null,
        'reset_token_expires'   => null,
        'reset_token_requested' => null,
    ];
    user_put($email, $info);

    // Send email if requested (or if no password was set, force-send).
    $emailed = false;
    if ($sendEmail || $pw === '') {
        $token = gen_token();
        $ttl = (int) cfg_get('invite_token_ttl_minutes', 4320); // 3 days default for invites
        $info['reset_token']           = hash('sha256', $token);
        $info['reset_token_expires']   = gmdate('Y-m-d\TH:i:s\Z', time() + $ttl * 60);
        $info['reset_token_requested'] = now_iso();
        user_put($email, $info);

        $base = rtrim((string) cfg_get('site_url', ''), '/');
        $url = $base . '/reset.php?token=' . urlencode($token) . '&email=' . urlencode($email);
        $ttlDays = round($ttl / 60 / 24, 1);
        $ttlLabel = $ttl >= 1440 ? "{$ttlDays} days" : "{$ttl} minutes";
        $siteName = (string) cfg_get('site_name', 'ProjectCollab');
        $body = "Hello,\n\n"
              . "An account has been created for you on $siteName.\n\n"
              . "Click the link below to set your password (valid for $ttlLabel):\n\n"
              . "$url\n\n"
              . "Your username will be: $email\n";
        $emailed = send_mail($email, "Welcome to $siteName — set your password", $body);
    }

    respond_json(200, ['email' => $email, 'role' => $role, 'emailed' => $emailed]);
}

function userImpact(array $user, array $body): void {
    if (!has_role_at_least($user, 'admin')) respond_json(403, ['error' => 'forbidden']);
    $target = (string) ($body['email'] ?? '');
    if (!valid_email($target) || !user_get($target)) respond_json(404, ['error' => 'User not found']);

    $owned = [];
    $member = [];
    foreach (projects_visible_to($target) as $p) {
        $row = ['owner' => $p['owner'], 'project' => $p['project'], 'display_name' => $p['meta']['name'] ?? $p['project']];
        if ($p['role_in_project'] === 'owner') $owned[] = $row;
        else $member[] = $row;
    }
    respond_json(200, ['email' => $target, 'owned_projects' => $owned, 'shared_projects' => $member]);
}

function removeUser(array $user, array $body): void {
    if (!has_role_at_least($user, 'admin')) respond_json(403, ['error' => 'forbidden']);
    $target = (string) ($body['email'] ?? '');
    $confirm = (string) ($body['confirm'] ?? '');
    if ($confirm !== $target) {
        respond_json(400, ['error' => 'Confirmation text must match the email exactly']);
    }
    if ($target === $user['email']) {
        respond_json(400, ['error' => "You can't remove yourself"]);
    }
    if (!valid_email($target) || !user_get($target)) respond_json(404, ['error' => 'User not found']);

    // 1. Delete every project owned by this user.
    $userBase = user_dir($target);
    if (is_dir("$userBase/projects")) {
        foreach (scandir("$userBase/projects") as $p) {
            if ($p === '.' || $p === '..') continue;
            if (valid_project_slug($p)) {
                rrmdir("$userBase/projects/$p");
            }
        }
        @rmdir("$userBase/projects");
    }
    if (is_dir($userBase)) @rmdir($userBase);

    // 2. Remove from shared_with of every other project.
    if (is_dir(COLLAB_USERS_ROOT)) {
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
                $sw = $meta['shared_with'] ?? [];
                if (in_array($target, $sw, true)) {
                    $meta['shared_with'] = array_values(array_filter($sw, fn($u) => $u !== $target));
                    writeWithPerms($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
                }
            }
        }
    }

    // 3. Remove the user row itself.
    user_delete_row($target);

    respond_json(200, ['removed' => $target]);
}

function changeRole(array $user, array $body): void {
    if (!has_role_at_least($user, 'admin')) respond_json(403, ['error' => 'forbidden']);
    $target = (string) ($body['email'] ?? '');
    $role = (string) ($body['role'] ?? '');
    if (!valid_role($role)) respond_json(400, ['error' => 'Invalid role']);
    $u = user_get($target);
    if (!$u) respond_json(404, ['error' => 'User not found']);
    if ($target === $user['email'] && $role !== 'admin') {
        respond_json(400, ['error' => "Can't demote yourself"]);
    }
    $u['role'] = $role;
    user_put($target, $u);
    respond_json(200, ['ok' => true]);
}

function setPassword(array $user, array $body): void {
    $target = (string) ($body['email'] ?? $user['email']);
    $newPw = (string) ($body['password'] ?? '');
    $oldPw = (string) ($body['current_password'] ?? '');

    if (strlen($newPw) < 8) {
        respond_json(400, ['error' => 'Password must be at least 8 characters']);
    }
    $u = user_get($target);
    if (!$u) respond_json(404, ['error' => 'User not found']);

    if ($target === $user['email']) {
        // Self: must verify current password.
        if (!user_password_verify($user['email'], $oldPw)) {
            respond_json(400, ['error' => 'Current password is incorrect']);
        }
    } else {
        // Other: admin only.
        if (!has_role_at_least($user, 'admin')) respond_json(403, ['error' => 'forbidden']);
    }
    $u['password_hash'] = user_password_hash($newPw);
    user_put($target, $u);
    respond_json(200, ['ok' => true]);
}


function restoreBackup(array $user): void {
    if (!has_role_at_least($user, 'developer')) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'Developer role required']);
    }

    $overwrite = ($_POST['overwrite'] ?? '') === 'true';
    $rename    = trim($_POST['rename'] ?? '');

    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['backup']['error'] ?? 'missing';
        respond_json(400, ['error' => 'No file uploaded (code: ' . $errCode . ')']);
    }

    $tmpFile = $_FILES['backup']['tmp_name'];

    // Use a temp dir under the user's own projects folder so rename() stays
    // on the same filesystem as the destination (cross-device rename fails).
    $owner      = $user['email'];
    $userProjDir = user_dir($owner) . '/projects';
    if (!is_dir($userProjDir)) {
        mkdir($userProjDir, 0770, true);
        @chgrp($userProjDir, 'www-data');
    }
    $tmpDir = $userProjDir . '/.restore-' . bin2hex(random_bytes(8));
    @mkdir($tmpDir, 0770, true);
    @chgrp($tmpDir, 'www-data');

    try {
        // Extract archive
        $cmd = 'tar -xzf ' . escapeshellarg($tmpFile) . ' -C ' . escapeshellarg($tmpDir) . ' 2>&1';
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            respond_json(400, ['error' => 'Extraction failed: ' . implode(' ', $out)]);
        }

        // Find the single project directory
        $entries = array_values(array_filter(
            scandir($tmpDir),
            fn($f) => $f !== '.' && $f !== '..' && is_dir("$tmpDir/$f")
        ));
        if (count($entries) !== 1) {
            respond_json(400, ['error' => 'Archive must contain exactly one project directory']);
        }
        $extractedName = $entries[0];
        $extractedDir  = "$tmpDir/$extractedName";

        // Validate meta.json
        $metaPath = "$extractedDir/meta.json";
        if (!is_file($metaPath)) {
            respond_json(400, ['error' => 'Not a valid ProjectCollab backup (meta.json missing)']);
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            respond_json(400, ['error' => 'Corrupt meta.json in backup']);
        }

        // Determine target project name
        $targetName = ($rename !== '' && valid_project_slug($rename)) ? $rename : $extractedName;
        if (!valid_project_slug($targetName)) {
            respond_json(400, ['error' => 'Invalid project name: ' . htmlspecialchars($targetName)]);
        }

        $targetDir = project_dir($owner, $targetName);
        $exists    = is_dir($targetDir);

        // Conflict: project exists and caller hasn't confirmed overwrite or rename
        if ($exists && !$overwrite && $rename === '') {
            respond_json(200, [
                'conflict'     => true,
                'project'      => $targetName,
                'display_name' => $meta['name'] ?? $targetName,
            ]);
        }

        // Remove existing if overwriting
        if ($exists && $overwrite) {
            rrmdir($targetDir);
        }

        // Rebuild agent secrets:
        // - Keep a secret if it is globally free AND the restoring user matches the backup owner.
        // - Regenerate if the secret is already in use anywhere, or if a different user is restoring
        //   (we never hand another user's live secret to a new owner).
        //
        // Collect all secrets currently in use across the entire system.
        $usedSecrets = [];
        if (is_dir(COLLAB_USERS_ROOT)) {
            foreach (scandir(COLLAB_USERS_ROOT) as $u) {
                if ($u === '.' || $u === '..') continue;
                $pr = COLLAB_USERS_ROOT . "/$u/projects";
                if (!is_dir($pr)) continue;
                foreach (scandir($pr) as $p) {
                    if ($p === '.' || $p === '..') continue;
                    $mp = "$pr/$p/meta.json";
                    if (!is_file($mp)) continue;
                    $m = json_decode((string) file_get_contents($mp), true);
                    if (!is_array($m)) continue;
                    foreach (array_keys($m['agents'] ?? []) as $s) {
                        $usedSecrets[$s] = true;
                    }
                }
            }
        }

        // Determine original owner from backup meta (admin-flagged agent name ≈ owner local-part).
        // We compare by restoring user to decide whether to keep owner-agent secrets.
        $ownerLocal      = strstr($owner, '@', true) ?: $owner;
        $backupOwnerName = null;
        foreach ($meta['agents'] as $agentInfo) {
            if (!empty($agentInfo['admin'])) { $backupOwnerName = $agentInfo['name'] ?? null; break; }
        }
        // "Same owner" heuristic: local-part of restoring email matches the backup owner agent name.
        $sameOwner = ($backupOwnerName !== null && strtolower($backupOwnerName) === strtolower($ownerLocal));

        $newAgents = [];
        foreach ($meta['agents'] as $oldSecret => $agentInfo) {
            $isOwnerAgent = !empty($agentInfo['admin']);
            // Keep the old secret only if it is free AND owner matches (or it's not the owner agent).
            if (!isset($usedSecrets[$oldSecret]) && ($sameOwner || !$isOwnerAgent)) {
                $newSecret = $oldSecret;           // safe to reuse
            } else {
                $newSecret = bin2hex(random_bytes(16));   // collision or different owner → fresh secret
            }
            if ($isOwnerAgent) {
                $agentInfo['name'] = $ownerLocal;  // always update owner display name to restoring user
            }
            $newAgents[$newSecret] = $agentInfo;
            $usedSecrets[$newSecret] = true;       // mark as used so later agents in same loop don't collide
        }
        $meta['agents']      = $newAgents;
        $meta['shared_with'] = [];
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Move into place
        if (!rename($extractedDir, $targetDir)) {
            respond_json(500, ['error' => 'Could not move project into place']);
        }

        // Fix permissions
        system('chgrp -R www-data ' . escapeshellarg($targetDir) . ' 2>/dev/null');
        system('find ' . escapeshellarg($targetDir) . ' -type d -exec chmod 770 {} \; 2>/dev/null');
        system('find ' . escapeshellarg($targetDir) . ' -type f -exec chmod 660 {} \; 2>/dev/null');

        respond_json(200, ['ok' => true, 'project' => $targetName, 'display_name' => $meta['name'] ?? $targetName]);

    } finally {
        if (is_dir($tmpDir)) {
            system('rm -rf ' . escapeshellarg($tmpDir) . ' 2>/dev/null');
        }
    }
}

// =============================================================================
// AGENT API PROXY — direct filesystem implementation (no HTTP loopback)
// =============================================================================

function resolveProjectContext(array $user): array {
    $project = (string) ($_GET['p'] ?? '');
    $owner   = (string) ($_GET['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir  = project_dir($owner, $project);
    $meta = jsonRead("$dir/meta.json") ?? [];
    $ownerSecret = null;
    foreach ($meta['agents'] ?? [] as $secret => $info) {
        if (!empty($info['admin'])) { $ownerSecret = $secret; break; }
    }
    if (!$ownerSecret) respond_json(500, ['error' => 'No owner agent found']);
    // Update owner agent last_seen on every human UI activity
    touchLastSeen($dir, $ownerSecret);
    return [$ownerSecret, $owner, $project, $dir, $meta];
}

function uiWithLock(string $dir, callable $fn) {
    $fh = fopen("$dir/.lock", 'c');
    if (!$fh || !flock($fh, LOCK_EX)) respond_json(500, ['error' => 'Lock failed']);
    try { return $fn(); }
    finally { flock($fh, LOCK_UN); fclose($fh); }
}

function readVersions(string $dir): array {
    $v = jsonRead("$dir/versions.json") ?? [];
    if (!isset($v['files']))     $v['files']     = [];
    if (!isset($v['reads']))     $v['reads']     = [];
    if (!isset($v['last_seen'])) $v['last_seen'] = [];
    return $v;
}

function writeVersions(string $dir, array $v): void {
    writeWithPerms("$dir/versions.json", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
}

function validateFilePath(string $path): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
        respond_json(400, ['error' => 'Invalid file path']);
    }
    return $path;
}

function touchLastSeen(string $dir, string $secret): void {
    // Best-effort, no lock — just a timestamp update, losing one occasionally is fine.
    $v = readVersions($dir);
    $v['last_seen'][$secret] = now_iso();
    writeVersions($dir, $v);
}

// GET ?action=files
function proxyGetFiles(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $versions = readVersions($dir);
    // Update last_seen
    uiWithLock($dir, function() use ($dir, $secret, &$versions) {
        $versions = readVersions($dir);
        $versions['last_seen'][$secret] = now_iso();
        writeVersions($dir, $versions);
    });
    $out = [];
    foreach ($versions['files'] as $path => $info) {
        $entry = [
            'path'        => $path,
            'version'     => $info['version'],
            'state'       => $info['state'] ?? 'active',
            'modified'    => $info['modified'] ?? null,
            'modified_by' => $info['modified_by'] ?? null,
            'size'        => $info['size'] ?? null,
        ];
        if (($entry['state'] === 'active') && (($entry['size'] ?? 0) === 0)) {
            $abs = "$dir/files/$path";
            if (is_file($abs)) { $fsz = filesize($abs); if ($fsz !== false && $fsz > 0) $entry['size'] = $fsz; }
        }
        if (isset($info['deleted_at']))  $entry['deleted_at']  = $info['deleted_at'];
        if (isset($info['deleted_by']))  $entry['deleted_by']  = $info['deleted_by'];
        $out[] = $entry;
    }
    respond_json(200, ['files' => $out]);
}

// POST ?action=files-seen
function proxyFilesSeen(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $userKey = $user['email'];
    uiWithLock($dir, function() use ($dir, $userKey) {
        $v = readVersions($dir);
        $v['files_list_seen'][$userKey] = now_iso();
        writeVersions($dir, $v);
    });
    respond_json(200, ['ok' => true]);
}

// GET ?action=file&file=path
function proxyGetFile(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $relPath = validateFilePath((string)($_GET['file'] ?? ''));
    $versions = readVersions($dir);
    $info = $versions['files'][$relPath] ?? null;
    if (!$info || ($info['state'] ?? 'active') === 'deleted') respond_json(404, ['error' => 'not_found']);
    $absPath = "$dir/files/$relPath";
    if (!is_file($absPath)) respond_json(404, ['error' => 'not_found']);
    // Mark as read
    uiWithLock($dir, function() use ($dir, $secret, $relPath, $info) {
        $v = readVersions($dir);
        $v['reads'][$secret][$relPath] = $info['version'];
        writeVersions($dir, $v);
    });
    header('X-File-Version: ' . $info['version']);
    header('X-File-Modified-By: ' . ($info['modified_by'] ?? ''));
    header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
    readfile($absPath);
    exit;
}

// PUT ?action=file&file=path
function proxyPutFile(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $relPath     = validateFilePath((string)($_GET['file'] ?? ''));
    $isNewFile   = !empty($_SERVER['HTTP_X_NEW_FILE']);
    $expectedVer = isset($_SERVER['HTTP_X_EXPECTED_VERSION']) ? (int)$_SERVER['HTTP_X_EXPECTED_VERSION'] : null;
    $mime        = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
    $body        = (string) file_get_contents('php://input');
    $bodyLen     = strlen($body);
    $displayName = strstr($user['email'], '@', true) ?: $user['email'];

    uiWithLock($dir, function() use ($dir, $secret, $relPath, $isNewFile, $expectedVer, $mime, $body, $bodyLen, $displayName) {
        $v    = readVersions($dir);
        $info = $v['files'][$relPath] ?? null;
        $now  = now_iso();

        if ($isNewFile) {
            if ($info && ($info['state'] ?? 'active') === 'active') {
                respond_json(409, ['error' => 'file_exists', 'message' => 'File already exists.']);
            }
            $newVersion = $info ? $info['version'] + 1 : 1;
        } else {
            if (!$info) respond_json(404, ['error' => 'not_found']);
            $claimed = $expectedVer ?? ($v['reads'][$secret][$relPath] ?? null);
            if ($claimed === null) respond_json(400, ['error' => 'bad_request', 'message' => 'Provide X-Expected-Version']);
            if ($claimed !== $info['version']) respond_json(409, ['error' => 'version_conflict', 'message' => "Conflict: current v{$info['version']}, you have v$claimed"]);
            $newVersion = $info['version'] + 1;
        }

        $absPath = "$dir/files/$relPath";
        $dirPath = dirname($absPath);
        if (!is_dir($dirPath)) mkdir($dirPath, 0770, true);
        $tmp = "$absPath.tmp." . getmypid();
        file_put_contents($tmp, $body);
        rename($tmp, $absPath);
        $size = filesize($absPath) ?: $bodyLen;

        $v['files'][$relPath] = ['version' => $newVersion, 'state' => 'active', 'modified' => $now, 'modified_by' => $displayName, 'size' => $size, 'mime' => $mime];
        $v['reads'][$secret][$relPath] = $newVersion;
        writeVersions($dir, $v);

        respond_json(200, ['path' => $relPath, 'version' => $newVersion, 'previous_version' => $info['version'] ?? 0, 'size' => $size, 'body_received' => $bodyLen, 'modified' => $now, 'written_by' => $displayName]);
    });
}

// DELETE ?action=file&file=path
function proxyDeleteFile(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $relPath     = validateFilePath((string)($_GET['file'] ?? ''));
    $expectedVer = isset($_SERVER['HTTP_X_EXPECTED_VERSION']) ? (int)$_SERVER['HTTP_X_EXPECTED_VERSION'] : null;
    uiWithLock($dir, function() use ($dir, $secret, $relPath, $expectedVer) {
        $v    = readVersions($dir);
        $info = $v['files'][$relPath] ?? null;
        if (!$info || ($info['state'] ?? 'active') === 'deleted') respond_json(404, ['error' => 'not_found']);
        $claimed = $expectedVer ?? ($v['reads'][$secret][$relPath] ?? null);
        if ($claimed !== null && $claimed !== $info['version']) respond_json(409, ['error' => 'version_conflict']);
        $now = now_iso();
        $v['files'][$relPath]['state']      = 'deleted';
        $v['files'][$relPath]['deleted_at'] = $now;
        $v['files'][$relPath]['deleted_by'] = $secret;
        writeVersions($dir, $v);
        respond_json(200, ['path' => $relPath, 'state' => 'deleted', 'deleted_at' => $now]);
    });
}

// GET ?action=chat
function proxyGetChat(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $limit    = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $chatPath = "$dir/chat.log";
    $messages = [];
    if (is_file($chatPath)) {
        $lines = file($chatPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $m = json_decode($line, true);
            if (is_array($m)) $messages[] = $m;
        }
    }
    $messages = array_slice($messages, -$limit);
    respond_json(200, ['messages' => $messages]);
}

// POST ?action=chat-seen
function proxyChatSeen(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $userKey = $user['email'];
    uiWithLock($dir, function() use ($dir, $userKey) {
        $v = readVersions($dir);
        $v['chat_seen'][$userKey] = now_iso();
        writeVersions($dir, $v);
    });
    respond_json(200, ['ok' => true]);
}

// POST ?action=chat  (human post — attributed to email local-part)
function proxyPostChat(array $user, array $body): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $displayName = strstr($user['email'], '@', true) ?: $user['email'];
    $text = trim((string)($body['text'] ?? ''));
    if ($text === '') respond_json(400, ['error' => 'text must not be empty']);
    $chatPath = "$dir/chat.log";
    $msg = null;
    uiWithLock($dir, function() use ($chatPath, $displayName, $text, &$msg) {
        $id = 1;
        if (is_file($chatPath)) {
            $lines = file($chatPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $l) { $m = json_decode($l, true); if (is_array($m) && isset($m['id'])) $id = max($id, (int)$m['id'] + 1); }
        }
        $msg = ['id' => $id, 'time' => now_iso(), 'from' => $displayName, 'text' => $text];
        file_put_contents($chatPath, json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    });
    respond_json(200, ['id' => $msg['id'], 'time' => $msg['time'], 'from' => $msg['from']]);
}

// GET ?action=news
function proxyGetNews(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $userKey = $user['email'];
    $v = readVersions($dir);

    // files_list_unread: true only if new/changed files appeared since user last viewed the list
    $filesListSeen = $v['files_list_seen'][$userKey] ?? '1970-01-01T00:00:00Z';
    $latestFile = '1970-01-01T00:00:00Z';
    foreach ($v['files'] as $info) {
        if (($info['modified'] ?? '') > $latestFile) $latestFile = $info['modified'];
    }
    $filesListUnread = $latestFile > $filesListSeen;

    // chat_unread: count messages since this user last saw the chat
    $chatSeen = $v['chat_seen'][$userKey] ?? null;
    $chatUnread = 0;
    $chatPath = "$dir/chat.log";
    if (is_file($chatPath)) {
        $lines = file($chatPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $m = json_decode($l, true);
            if (is_array($m) && isset($m['time']) && ($chatSeen === null || $m['time'] > $chatSeen)) $chatUnread++;
        }
    }
    respond_json(200, ['files_list_unread' => $filesListUnread, 'chat_unread' => $chatUnread, 'files_unchanged' => count($v['files']), 'as_of' => now_iso()]);
}

// GET ?action=presence
function proxyGetPresence(array $user): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    $v    = readVersions($dir);
    $meta = jsonRead("$dir/meta.json") ?? [];
    $out  = [];
    foreach ($meta['agents'] ?? [] as $s => $info) {
        $out[] = ['name' => $info['name'] ?? '', 'role' => $info['role'] ?? '', 'last_seen' => $v['last_seen'][$s] ?? null];
    }
    respond_json(200, ['agents' => $out]);
}

function downloadSkillZip(): void {
    $skillDir = '/home/collab/data/skill';
    if (!is_dir($skillDir)) respond_json(404, ['error' => 'Skill files not found on server']);

    $zipFile = tempnam(sys_get_temp_dir(), 'skill_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE) !== true) respond_json(500, ['error' => 'Could not create zip']);

    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($skillDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $rel = 'projectcollab/' . substr($file->getPathname(), strlen($skillDir) + 1);
        $zip->addFile($file->getPathname(), $rel);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="projectcollab.skill"');
    header('Content-Length: ' . filesize($zipFile));
    readfile($zipFile);
    unlink($zipFile);
    exit;
}

// =============================================================================
// HELPERS
// =============================================================================

function requireProjectAccess(array $user, string $owner, string $project): void {
    if (!valid_email($owner)) respond_json(400, ['error' => 'Invalid owner']);
    if (!valid_project_slug($project)) respond_json(400, ['error' => 'Invalid project']);
    if (!is_dir(project_dir($owner, $project))) respond_json(404, ['error' => 'Project not found']);
    if (!user_can_access($user['email'], $owner, $project)) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'You do not have access to this project']);
    }
}

function requireProjectOwner(array $user, string $owner, string $project): void {
    if ($owner !== $user['email'] && !has_role_at_least($user, 'admin')) {
        respond_json(403, ['error' => 'forbidden', 'message' => 'Only the owner can do this']);
    }
    requireProjectAccess($user, $owner, $project);
}

function jsonRead(string $path): ?array {
    if (!is_file($path)) return null;
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function writeWithPerms(string $path, string $content, int $mode): void {
    file_put_contents($path, $content);
    @chgrp($path, 'www-data');
    @chmod($path, $mode);
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        if (is_dir($p)) rrmdir($p);
        else @unlink($p);
    }
    @rmdir($dir);
}
