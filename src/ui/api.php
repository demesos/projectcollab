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
 *   POST   ?action=set-claim-timeouts                  body: {owner, project, agent_hours?, guest_hours?, human_hours?}
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

        case 'POST set-user-color':      setUserColor($user, json_body()); break;
        case 'POST cycle-user-color':    cycleUserColor($user); break;
        case 'GET user-colors':          userColorsList($user); break;
        case 'POST toggle-kanban':       toggleKanban($user, json_body()); break;
        case 'POST toggle-guest-workers': toggleGuestWorkers($user, json_body()); break;
        case 'POST set-claim-timeouts':  setClaimTimeouts($user, json_body()); break;

        // --- Kanban CRUD ---
        case 'GET kanban':               getKanban($user); break;
        case 'POST kanban-save':         saveKanban($user, json_body()); break;
        case 'POST kanban-claim':        claimKanbanTask($user, json_body()); break;
        case 'POST kanban-release':      releaseKanbanTask($user, json_body()); break;
        case 'POST kanban-state':        stateKanbanTask($user, json_body()); break;

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
        case 'POST chat-clear':          proxyClearChat($user, json_body()); break;

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
            'agent_count'      => count(array_filter($meta['agents'] ?? [], fn($a) => empty($a['admin']))),
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

    // Secrets are only revealed to the agent's own creator (see agentOwner()).
    // Admins and the project owner see every secret; for agents that are not
    // theirs 'mine' is false so the UI can render secret + remove button greyed out.
    $seesAll = has_role_at_least($user, 'admin') || $owner === $user['email'];
    $agents = [];
    foreach ($meta['agents'] ?? [] as $secret => $info) {
        $mine = agentOwner($info, $owner) === $user['email'];
        $agents[] = [
            'secret'    => ($mine || $seesAll) ? $secret : null,
            'mine'      => $mine,
            'name'      => $info['name'] ?? '',
            'role'      => $info['role'] ?? '',
            'class'     => $info['class'] ?? 'manager',
            'id'        => $info['id'] ?? null,
            'admin'     => !empty($info['admin']),
            'last_seen' => $versions['last_seen'][$secret] ?? null,
        ];
    }

    respond_json(200, [
        'name'             => $project,
        'owner'            => $owner,
        'role_in_project'  => $owner === $user['email'] ? 'owner' : 'member',
        'has_kanban'       => !empty($meta['has_kanban']),
        'guest_workers'    => !empty($meta['guest_workers']),
        'claim_timeouts_hours' => claim_timeouts_hours($meta),
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
        'has_kanban'     => !array_key_exists('has_kanban', $body) || !empty($body['has_kanban']),   // default on
        'shared_with'    => [],
        'agents'         => [
            $ownerSecret => ['name' => $ownerLocal, 'role' => 'owner', 'admin' => true, 'class' => 'manager', 'id' => gen_agent_id()],
        ],
    ];
    writeWithPerms("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
    writeWithPerms("$dir/versions.json", json_encode(['files' => new stdClass(), 'reads' => new stdClass(), 'last_seen' => new stdClass()], JSON_PRETTY_PRINT), 0660);
    writeWithPerms("$dir/chat.log", '', 0660);
    writeWithPerms("$dir/.lock", '', 0660);
    if ($meta['has_kanban']) kanban_save($dir, kanban_default());   // starts with one empty "Backlog" WP

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
    $description = (string) ($body['description'] ?? '');
    metaUpdate($dir, function(array &$meta) use ($description) { $meta['description'] = $description; });
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
    $meta = metaUpdate($dir, function(array &$meta) use ($username) {
        $shared = $meta['shared_with'] ?? [];
        if (!in_array($username, $shared, true)) {
            $shared[] = $username;
        }
        $meta['shared_with'] = array_values(array_unique($shared));
    });
    respond_json(200, ['shared_with' => $meta['shared_with']]);
}

function unshareProject(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    $username = (string) ($body['username'] ?? '');
    requireProjectOwner($user, $owner, $project);

    $dir = project_dir($owner, $project);
    $meta = metaUpdate($dir, function(array &$meta) use ($username) {
        $shared = $meta['shared_with'] ?? [];
        $meta['shared_with'] = array_values(array_filter($shared, fn($u) => $u !== $username));
    });
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
    $email = $user['email'];
    metaUpdate($dir, function(array &$meta) use ($email) {
        $shared = $meta['shared_with'] ?? [];
        if (!in_array($email, $shared, true)) {
            respond_json(400, ['error' => 'You are not a member of this project']);
        }
        $meta['shared_with'] = array_values(array_filter($shared, fn($u) => $u !== $email));
    });
    respond_json(200, ['left' => $project]);
}

function backupProject(array $user): void {
    $project = (string) ($_GET['p'] ?? '');
    $owner = (string) ($_GET['owner'] ?? $user['email']);
    // Owner/admin only: the archive contains meta.json with every agent secret.
    requireProjectOwner($user, $owner, $project);
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

    $name = trim((string) ($body['name'] ?? ''));
    $role = trim((string) ($body['role'] ?? ''));
    if ($name === '' || $role === '') {
        respond_json(400, ['error' => 'name and role required']);
    }

    $class = trim((string) ($body['class'] ?? 'worker'));
    if (!valid_agent_class($class)) {
        respond_json(400, ['error' => "class must be one of: manager, worker"]);
    }

    $secret = bin2hex(random_bytes(16));
    $id     = gen_agent_id();
    $agent  = ['name' => $name, 'role' => $role, 'class' => $class, 'id' => $id,
               'created_by' => $user['email']];
    metaUpdate($dir, function(array &$meta) use ($secret, $agent) { $meta['agents'][$secret] = $agent; });
    respond_json(200, ['secret' => $secret, 'name' => $name, 'role' => $role, 'class' => $class, 'id' => $id]);
}

function removeAgent(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);

    $secret = (string) ($body['secret'] ?? '');
    $dir = project_dir($owner, $project);

    metaUpdate($dir, function(array &$meta) use ($secret, $owner, $user) {
        if (!isset($meta['agents'][$secret])) {
            respond_json(404, ['error' => 'Agent not found']);
        }
        if (!empty($meta['agents'][$secret]['admin'])) {
            respond_json(400, ['error' => 'Cannot remove the owner agent']);
        }
        if (agentOwner($meta['agents'][$secret], $owner) !== $user['email']
            && $owner !== $user['email'] && !has_role_at_least($user, 'admin')) {
            respond_json(403, ['error' => 'forbidden', 'message' => 'Only the creator of this agent or the project owner can remove it']);
        }
        unset($meta['agents'][$secret]);
    });
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
                if (in_array($target, $meta['shared_with'] ?? [], true)) {
                    metaUpdate("$projRoot/$proj", function(array &$meta) use ($target) {
                        $meta['shared_with'] = array_values(array_filter($meta['shared_with'] ?? [], fn($u) => $u !== $target));
                    });
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
            unset($agentInfo['created_by']);       // shares are dropped → all agents belong to the restoring owner
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


// ==========================================================================
// === Color endpoints + Kanban toggle (Checkpoint 1.2) =====================
// ==========================================================================

/**
 * POST set-user-color
 *   body: { color?: "#RRGGBB" }     — if absent, server generates one
 * Updates the current user's color. Pushes the new color onto color_history
 * (deduping; capping at KANBAN_COLOR_HISTORY_MAX). Active color is always at
 * index 0 of color_history.
 *
 * Validation:
 *   - If color supplied, must be #RRGGBB hex and pass separation check vs.
 *     azzurro and other users' colors. If too close, returns 409 with the
 *     conflicting user's email so the client can show a helpful message.
 *   - To bypass separation (user really wants this exact color), client may
 *     send {color, force: true}.
 */
function setUserColor(array $user, array $body): void {
    $email = $user['email'];
    $info  = user_get($email) ?? [];
    $color = isset($body['color']) ? trim((string) $body['color']) : '';
    $force = !empty($body['force']);

    if ($color === '') {
        // Generate fresh — exclude current user's own color so we don't
        // collide with it (which would be silly).
        $color = generate_user_color(collect_other_user_colors($email));
    } else {
        if (!valid_hex_color($color)) {
            respond_json(400, ['error' => 'invalid_color',
                'message' => 'Color must be in #RRGGBB form']);
        }
        if (!$force) {
            $conflict = checkColorSeparation($email, $color);
            if ($conflict !== null) {
                respond_json(409, [
                    'error' => 'color_too_close',
                    'message' => $conflict['message'],
                    'conflict_with' => $conflict['with'],
                    'distance' => $conflict['distance'],
                ]);
            }
        }
    }

    $history = $info['color_history'] ?? [];
    // De-dupe (remove existing occurrences of $color), then prepend.
    $history = array_values(array_filter($history, fn($c) => $c !== $color));
    array_unshift($history, $color);
    if (count($history) > KANBAN_COLOR_HISTORY_MAX) {
        $history = array_slice($history, 0, KANBAN_COLOR_HISTORY_MAX);
    }

    $info['color']         = $color;
    $info['color_history'] = $history;
    user_put($email, $info);

    respond_json(200, ['color' => $color, 'history' => $history]);
}

/**
 * POST cycle-user-color
 *   body: {}
 * Cycles color_history by one step: takes the SECOND color in history and
 * makes it the active color. Active rotates to the end. This makes
 * right-click "go back" through previous picks, wrapping around after the
 * full list.
 *
 * If history has 0 or 1 entries, nothing changes (returns current).
 */
function cycleUserColor(array $user): void {
    $email = $user['email'];
    $info  = user_get($email) ?? [];
    $history = $info['color_history'] ?? [];
    if (count($history) < 2) {
        respond_json(200, [
            'color' => $info['color'] ?? null,
            'history' => $history,
            'cycled' => false,
        ]);
    }

    // Rotate left: [A, B, C, D] -> [B, C, D, A]
    $front = array_shift($history);
    $history[] = $front;
    $info['color']         = $history[0];
    $info['color_history'] = $history;
    user_put($email, $info);

    respond_json(200, [
        'color' => $info['color'],
        'history' => $history,
        'cycled' => true,
    ]);
}

/**
 * GET user-colors
 * Returns { email: "#RRGGBB", ... } for every user with a color set.
 * Open to any logged-in user (mirrors user-suggestions).
 */
function userColorsList(array $user): void {
    $d = users_load();
    $out = [];
    foreach (($d['users'] ?? []) as $email => $info) {
        if (!empty($info['color']) && valid_hex_color($info['color'])) {
            $out[$email] = $info['color'];
        }
    }
    respond_json(200, ['colors' => $out, 'azzurro' => KANBAN_AZZURRO]);
}

/**
 * POST toggle-kanban
 *   body: { owner, project, enabled: bool }
 * Owner-or-admin gated. Sets has_kanban in meta.json. If turning on for the
 * first time, seeds kanban.json with a single "Phase 0" WP.
 */
function toggleKanban(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    $enabled = !empty($body['enabled']);
    requireProjectOwner($user, $owner, $project);

    $dir = project_dir($owner, $project);
    project_set_kanban($dir, $enabled);
    // Guest workers require a board; if Kanban is turned off, turn guests off too
    // so the invariant (guest_workers => has_kanban) holds.
    if (!$enabled && project_has_guest_workers($dir)) {
        project_set_guest_workers($dir, false);
    }
    respond_json(200, [
        'enabled' => $enabled,
        'has_kanban_file' => is_file(kanban_path($dir)),
        'guest_workers' => project_has_guest_workers($dir),
    ]);
}

/**
 * POST toggle-guest-workers
 *   body: { owner, project, enabled: bool }
 * Owner-or-admin gated. Sets guest_workers in meta.json. Enabling requires the
 * Kanban board to be enabled first (guests only ever work board tasks).
 */
function setClaimTimeouts(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    requireProjectOwner($user, $owner, $project);
    $dir = project_dir($owner, $project);
    $meta = metaUpdate($dir, function(array &$meta) use ($body) {
        $new = claim_timeouts_apply($meta, $body);
        if (is_string($new)) respond_json(400, ['error' => 'bad_request', 'message' => $new]);
        $meta['claim_timeouts'] = $new;
    });
    respond_json(200, ['claim_timeouts_hours' => claim_timeouts_hours($meta)]);
}

function toggleGuestWorkers(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    $enabled = !empty($body['enabled']);
    requireProjectOwner($user, $owner, $project);

    $dir = project_dir($owner, $project);
    if ($enabled && !project_has_kanban($dir)) {
        respond_json(400, ['error' => 'kanban_required',
            'message' => 'Enable the Kanban board before allowing guest workers.']);
    }
    project_set_guest_workers($dir, $enabled);
    respond_json(200, ['guest_workers' => $enabled]);
}

// --- Helpers --------------------------------------------------------------

/**
 * Check if $color is too close to azzurro or to any OTHER user's color.
 * Returns null if OK, or ['with' => email|'azzurro', 'message' => ..., 'distance' => deg].
 */
function checkColorSeparation(string $email, string $color): ?array {
    [$h, , ] = hex_to_hsl($color);
    [$ah, , ] = hex_to_hsl(KANBAN_AZZURRO);
    $da = hue_distance($h, $ah);
    if ($da < KANBAN_HUE_MIN_SEPARATION) {
        return [
            'with' => 'azzurro',
            'message' => sprintf('Too close to azzurro (agent color): %.0f° apart, need ≥%.0f°',
                $da, KANBAN_HUE_MIN_SEPARATION),
            'distance' => round($da, 1),
        ];
    }
    $d = users_load();
    foreach (($d['users'] ?? []) as $otherEmail => $info) {
        if ($otherEmail === $email) continue;
        $oc = $info['color'] ?? '';
        if (!valid_hex_color($oc)) continue;
        [$oh, , ] = hex_to_hsl($oc);
        $du = hue_distance($h, $oh);
        if ($du < KANBAN_HUE_MIN_SEPARATION) {
            return [
                'with' => $otherEmail,
                'message' => sprintf('Too close to %s: %.0f° apart, need ≥%.0f°',
                    $otherEmail, $du, KANBAN_HUE_MIN_SEPARATION),
                'distance' => round($du, 1),
            ];
        }
    }
    return null;
}


// ==========================================================================
// === Kanban CRUD endpoints (Checkpoint 1.3) ===============================
// ==========================================================================

/**
 * GET kanban?owner=&p=
 * Returns full Kanban board with computed states, expired claims swept,
 * and per-task actor_color attached for the client.
 */
function getKanban(array $user): void {
    $project = (string) ($_GET['p'] ?? '');
    $owner   = (string) ($_GET['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);

    if (!project_has_kanban($dir)) {
        respond_json(404, ['error' => 'kanban_disabled',
            'message' => 'Kanban is not enabled for this project']);
    }

    // Take the lock once: sweep runs inside, version only bumps if something
    // actually expired (callback signals no other change).
    [$data, ] = kanban_with_lock($dir, null, function(array &$d) {
        return ['_no_change' => true];
    });
    kanban_compute_states($data);
    kanban_annotate($data, $dir);

    // Attach actor_color per task
    $colorMap = ['agent' => KANBAN_AZZURRO];
    $usersData = users_load();
    foreach (($usersData['users'] ?? []) as $email => $info) {
        if (!empty($info['color']) && valid_hex_color($info['color'])) {
            $colorMap[$email] = $info['color'];
        }
    }
    foreach ($data['wps'] as &$wp) {
        foreach ($wp['tasks'] as &$task) {
            $cap = $task['capability'] ?? 'agent';
            $actorEmail = $task['actor_email'] ?? null;
            if ($cap === 'agent') {
                $task['actor_color'] = KANBAN_AZZURRO;
                $task['actor_label'] = 'agent';
            } elseif ($actorEmail && isset($colorMap[$actorEmail])) {
                $task['actor_color'] = $colorMap[$actorEmail];
                $task['actor_label'] = $actorEmail;
            } else {
                // Unpinned human task — neutral warm fallback
                $task['actor_color'] = null;
                $task['actor_label'] = 'human';
            }
        }
        unset($task);
    }
    unset($wp);

    respond_json(200, $data);
}

/**
 * POST kanban-save
 *   body: { owner, project, wps, expected_version, next_wp_id?, next_task_id? }
 * Structural save: replaces the whole wps array. Validates schema, preserves
 * claimed/done state where present, increments kanban_version.
 *
 * Permission: any project member (matches file-write permissions).
 */
function saveKanban(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    if (!project_has_kanban($dir)) {
        respond_json(404, ['error' => 'kanban_disabled']);
    }

    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    $wps = $body['wps'] ?? null;
    if (!is_array($wps)) respond_json(400, ['error' => 'bad_request',
        'message' => 'wps must be an array']);

    // Schema validation
    $err = validateKanbanWps($wps);
    if ($err !== null) respond_json(400, ['error' => 'invalid_schema', 'message' => $err]);

    try {
        [$saved, ] = kanban_with_lock($dir, $expected, function(array &$d) use ($wps, $body) {
            $d['wps'] = $wps;
            if (isset($body['next_wp_id']))   $d['next_wp_id']   = (int) $body['next_wp_id'];
            if (isset($body['next_task_id'])) $d['next_task_id'] = (int) $body['next_task_id'];
        });
        respond_json(200, ['kanban_version' => $saved['kanban_version'],
            'wps' => $saved['wps']]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) {
            $cur = (int) substr($e->getMessage(), strlen('KANBAN_VERSION_CONFLICT:'));
            $latest = kanban_load($dir);
            kanban_compute_states($latest);
            respond_json(409, [
                'error' => 'version_conflict',
                'message' => "Kanban was modified. Your version was $expected, current is $cur.",
                'current_version' => $cur,
                'current' => $latest,
            ]);
        }
        throw $e;
    }
}

/**
 * POST kanban-claim
 *   body: { owner, project, task_id, expected_version }
 * Atomic claim. Sets state='claimed', claimed_by=user.email, claimed_at=now.
 * Fails 409 if already claimed by someone else, or if version stale.
 * If the task is currently 'open', the claim is rejected with 'task_not_ready'.
 */
function claimKanbanTask(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    if (!project_has_kanban($dir)) respond_json(404, ['error' => 'kanban_disabled']);

    $taskId   = (string) ($body['task_id'] ?? '');
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond_json(400, ['error' => 'bad_request', 'message' => 'task_id required']);

    $email = $user['email'];
    try {
        [$saved, $result] = kanban_with_lock($dir, $expected, function(array &$d) use ($taskId, $email) {
            $task = &kanbanFindTask($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            $state = $task['state'] ?? 'open';
            if ($state === 'open') return ['_no_change' => true, 'error' => 'task_not_ready'];
            if ($state === 'claimed') {
                $existing = $task['claimed_by'] ?? null;
                if ($existing !== null && $existing !== $email) {
                    return ['_no_change' => true, 'error' => 'already_claimed',
                            'claimed_by' => $existing,
                            'claimed_at' => $task['claimed_at'] ?? null];
                }
                // Same user re-claiming is a no-op refresh.
            }
            $task['state']      = 'claimed';
            $task['claimed_by'] = $email;
            $task['claimed_by_name'] = $email;
            $task['claimed_at'] = now_iso();
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            $code = $result['error'] === 'task_not_found' ? 404
                  : ($result['error'] === 'already_claimed' ? 409 : 409);
            respond_json($code, $result);
        }
        $task = kanbanFindTask($saved, $taskId);
        respond_json(200, ['kanban_version' => $saved['kanban_version'],
            'task' => $task]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) {
            respond_versionConflict($dir, $e);
        }
        throw $e;
    }
}

/**
 * POST kanban-release
 *   body: { owner, project, task_id, expected_version }
 * Release my own claim. Only the claimer can release (owner/admin can too).
 * Reverts state to 'ready'.
 */
function releaseKanbanTask(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    if (!project_has_kanban($dir)) respond_json(404, ['error' => 'kanban_disabled']);

    $taskId   = (string) ($body['task_id'] ?? '');
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond_json(400, ['error' => 'bad_request', 'message' => 'task_id required']);

    $email = $user['email'];
    $isPriv = ($owner === $email) || has_role_at_least($user, 'admin');

    try {
        [$saved, $result] = kanban_with_lock($dir, $expected, function(array &$d)
                use ($taskId, $email, $isPriv) {
            $task = &kanbanFindTask($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            if (($task['state'] ?? '') !== 'claimed') {
                return ['_no_change' => true, 'error' => 'not_claimed'];
            }
            $cb = $task['claimed_by'] ?? null;
            if ($cb !== $email && !$isPriv) {
                return ['_no_change' => true, 'error' => 'forbidden',
                        'message' => 'Only the claimer or owner can release'];
            }
            $task['state']      = 'ready';
            $task['claimed_by'] = null;
            $task['claimed_by_name'] = null;
            $task['claimed_at'] = null;
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            $code = $result['error'] === 'task_not_found' ? 404
                  : ($result['error'] === 'forbidden' ? 403 : 409);
            respond_json($code, $result);
        }
        $task = kanbanFindTask($saved, $taskId);
        respond_json(200, ['kanban_version' => $saved['kanban_version'],
            'task' => $task]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) {
            respond_versionConflict($dir, $e);
        }
        throw $e;
    }
}

/**
 * POST kanban-state
 *   body: { owner, project, task_id, new_state, comment?, expected_version }
 * Transition a task to a new state. Allowed transitions:
 *   claimed → partly  (with comment, optional)
 *   claimed → done    (with comment, optional)
 *   partly  → claimed (re-claim for follow-up, by anyone capable)
 *   done    → partly  (test failed; with comment recommended)
 *   any     → ready   (release: handled by kanban-release instead)
 *
 * The 'comment' field, if present, is appended to task.comments as
 *   { by, at, text }.
 *
 * Permissions:
 *   - 'claimed → partly|done': only the claimer (or owner/admin) can do this.
 *   - 'partly → claimed': any project member (auto-sets claimer = user).
 *   - 'done → partly': any project member (tester role).
 */
function stateKanbanTask(array $user, array $body): void {
    $project = (string) ($body['project'] ?? '');
    $owner   = (string) ($body['owner'] ?? $user['email']);
    requireProjectAccess($user, $owner, $project);
    $dir = project_dir($owner, $project);
    if (!project_has_kanban($dir)) respond_json(404, ['error' => 'kanban_disabled']);

    $taskId    = (string) ($body['task_id'] ?? '');
    $newState  = (string) ($body['new_state'] ?? '');
    $comment   = isset($body['comment']) ? trim((string) $body['comment']) : '';
    $expected  = isset($body['expected_version']) ? (int) $body['expected_version'] : null;

    if ($taskId === '') respond_json(400, ['error' => 'bad_request', 'message' => 'task_id required']);
    if (!in_array($newState, ['partly', 'done', 'claimed'], true)) {
        respond_json(400, ['error' => 'bad_request',
            'message' => "new_state must be one of: partly, done, claimed"]);
    }

    $email = $user['email'];
    $isPriv = ($owner === $email) || has_role_at_least($user, 'admin');

    try {
        [$saved, $result] = kanban_with_lock($dir, $expected,
                function(array &$d) use ($taskId, $newState, $comment, $email, $isPriv) {
            $task = &kanbanFindTask($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            $cur = $task['state'] ?? 'open';
            $allowed = false;

            if ($cur === 'claimed' && ($newState === 'partly' || $newState === 'done')) {
                $cb = $task['claimed_by'] ?? null;
                if ($cb === $email || $isPriv) $allowed = true;
                else return ['_no_change' => true, 'error' => 'forbidden',
                    'message' => 'Only the claimer can mark partly/done'];
            } elseif ($cur === 'partly' && $newState === 'claimed') {
                $allowed = true;
            } elseif ($cur === 'done' && $newState === 'partly') {
                $allowed = true;
            } elseif ($cur === 'ready' && $newState === 'claimed') {
                $allowed = true;
            } else {
                return ['_no_change' => true, 'error' => 'invalid_transition',
                    'message' => "Cannot move from $cur to $newState"];
            }

            $task['state'] = $newState;
            if ($newState === 'claimed') {
                $task['claimed_by'] = $email;
                $task['claimed_by_name'] = $email;
                $task['claimed_at'] = now_iso();
            } elseif ($newState === 'done') {
                $task['completed_by'] = $email;
                $task['completed_by_name'] = $email;
                $task['completed_at'] = now_iso();
                $task['claimed_by']   = null;
                $task['claimed_by_name'] = null;
                $task['claimed_at']   = null;
            } elseif ($newState === 'partly') {
                // keep claimed_by/at as historical info but clear "active" claim
                $task['claimed_by'] = null;
                $task['claimed_by_name'] = null;
                $task['claimed_at'] = null;
            }
            if ($comment !== '') {
                if (!isset($task['comments']) || !is_array($task['comments'])) {
                    $task['comments'] = [];
                }
                $task['comments'][] = [
                    'by' => $email,
                    'by_name' => $email,
                    'at' => now_iso(),
                    'text' => $comment,
                ];
            }
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            $code = $result['error'] === 'task_not_found' ? 404
                  : ($result['error'] === 'forbidden' ? 403 : 409);
            respond_json($code, $result);
        }
        $task = kanbanFindTask($saved, $taskId);
        respond_json(200, ['kanban_version' => $saved['kanban_version'],
            'task' => $task]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) {
            respond_versionConflict($dir, $e);
        }
        throw $e;
    }
}

// --- Kanban CRUD helpers --------------------------------------------------

/**
 * Locate a task by id within the board. Returns a reference to the task
 * (so callers can mutate) or null if not found.
 * IMPORTANT: callers MUST use & on the assignment: `$t = &kanbanFindTask(...)`.
 */
function &kanbanFindTask(array &$data, string $taskId) {
    $null = null;
    foreach ($data['wps'] as $wi => $wp) {
        foreach (($wp['tasks'] ?? []) as $ti => $task) {
            if (($task['id'] ?? '') === $taskId) {
                return $data['wps'][$wi]['tasks'][$ti];
            }
        }
    }
    return $null;
}

/**
 * Validate the wps array schema. Returns null on success or an error string.
 * Delegates to the shared implementation in lib.php.
 */
function validateKanbanWps(array $wps): ?string {
    return kanban_validate_wps($wps);
}

/**
 * Emit a 409 with the latest board state. Used after KANBAN_VERSION_CONFLICT.
 */
function respond_versionConflict(string $dir, RuntimeException $e): void {
    $cur = (int) substr($e->getMessage(), strlen('KANBAN_VERSION_CONFLICT:'));
    $latest = kanban_load($dir);
    kanban_compute_states($latest);
    kanban_annotate($latest, $dir);
    respond_json(409, [
        'error' => 'version_conflict',
        'message' => "Kanban was modified. Current version is $cur.",
        'current_version' => $cur,
        'current' => $latest,
    ]);
}

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

// Locked read-modify-write of a project's meta.json. $fn receives the meta
// array by reference. Refuses to write if the current file can't be parsed,
// so a damaged meta.json is never replaced by a near-empty one.
function metaUpdate(string $dir, callable $fn): array {
    return uiWithLock($dir, function() use ($dir, $fn) {
        $meta = jsonRead("$dir/meta.json");
        if ($meta === null) respond_json(500, ['error' => 'meta_unreadable', 'message' => 'meta.json missing or unreadable']);
        $fn($meta);
        writeWithPerms("$dir/meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
        return $meta;
    });
}

function uiWithLock(string $dir, callable $fn) {
    $fh = fopen("$dir/.lock", 'c');
    if (!$fh || !flock($fh, LOCK_EX)) respond_json(500, ['error' => 'Lock failed']);
    try { return $fn(); }
    finally { flock($fh, LOCK_UN); fclose($fh); }
}

function readVersions(string $dir): array {
    $path = "$dir/versions.json";
    $v = jsonRead($path);
    if ($v === null) {
        // Never continue with an empty index when the file exists but can't be
        // parsed: the caller would write it back and wipe the project's index.
        if (is_file($path)) respond_json(500, ['error' => 'versions_unreadable', 'message' => 'versions.json unreadable, try again']);
        $v = [];
    }
    // 'files'/'reads' are maps. An empty map may be stored as [] (json_encode([]));
    // numeric file names decode to int keys, which is fine — never reset non-empty data.
    $files = $v['files'] ?? [];
    if (!is_array($files)) $files = [];
    $reads = $v['reads'] ?? [];
    if (!is_array($reads)) $reads = [];
    $v['files']     = $files;
    $v['reads']     = $reads;
    $v['last_seen'] = $v['last_seen'] ?? [];
    return $v;
}

function writeVersions(string $dir, array $v): void {
    // 'files' and 'reads' must always be JSON objects ({}) not arrays ([]).
    // PHP encodes an empty array as [] but an empty stdClass as {}.
    // A corrupt or uninitialized [] would make all files invisible on the next read.
    if (empty($v['files'])) $v['files'] = new \stdClass();
    if (empty($v['reads'])) $v['reads'] = new \stdClass();
    writeWithPerms("$dir/versions.json", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0660);
}

function validateFilePath(string $path): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
        respond_json(400, ['error' => 'Invalid file path']);
    }
    // Same positive whitelist as the agent API (src/api/index.php) so that
    // files uploaded via the web UI can always be retrieved via the agent
    // API. Uploading a file with characters outside this set is rejected
    // here with a clear message rather than silently creating a file that
    // the agent API will later refuse to serve.
    //
    // Allowed:  a-z A-Z 0-9  . _ - /  SPACE ( ) + ! ' , @ = ~
    // Blocked:  % # ? * \ & ; | ` < > "  (URL-structural, shell-dangerous,
    //           or forbidden on Windows filesystems)
    if (!preg_match('#^[a-zA-Z0-9._/\x20()+!\'\\,@=~-]+$#', $path)) {
        respond_json(400, ['error' => 'illegal_characters',
            'message' => 'Filename contains illegal characters. '
                . 'Allowed: letters, digits, spaces, and: . _ - ( ) + ! \' , @ = ~ /']);
    }
    foreach (explode('/', $path) as $comp) {
        if ($comp === '' || trim($comp) === '' || $comp !== trim($comp)) {
            respond_json(400, ['error' => 'illegal_characters',
                'message' => 'Path component is empty or has leading/trailing whitespace']);
        }
    }
    return $path;
}

function touchLastSeen(string $dir, string $secret): void {
    // Read-modify-write of versions.json: must hold the project lock, otherwise
    // it can overwrite concurrent agent/UI changes with a stale copy.
    uiWithLock($dir, function() use ($dir, $secret) {
        $v = readVersions($dir);
        $v['last_seen'][$secret] = now_iso();
        writeVersions($dir, $v);
    });
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

// POST ?action=chat-clear  (optionally keeps the last N messages)
function proxyClearChat(array $user, array $body): void {
    [$secret, $owner, $project, $dir] = resolveProjectContext($user);
    // keep_count: number of most recent messages to retain (0 = clear all).
    // keep_last (legacy bool) is still honoured as "keep 1" for older clients.
    $keepCount = isset($body['keep_count']) ? max(0, (int)$body['keep_count']) : (!empty($body['keep_last']) ? 1 : 0);
    $chatPath = "$dir/chat.log";
    uiWithLock($dir, function() use ($chatPath, $keepCount) {
        if (!is_file($chatPath)) return;
        if ($keepCount > 0) {
            $lines = file($chatPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $validLines = array_values(array_filter($lines, fn($l) => is_array(json_decode($l, true))));
            $kept = array_slice($validLines, -$keepCount);
            writeWithPerms($chatPath, $kept ? (implode("\n", $kept) . "\n") : '', 0660);
        } else {
            writeWithPerms($chatPath, '', 0660);
        }
    });
    respond_json(200, ['ok' => true]);
}

// GET ?action=news
function proxyGetNews(array $user): void {
    [$secret, $owner, $project, $dir, $meta] = resolveProjectContext($user);
    $userKey = $user['email'];

    // Touch last_seen for the agent matching this human user (by email local-part).
    // Runs every 5s poll — cheap write, no extra HTTP call needed.
    $displayName = strstr($user['email'], '@', true) ?: $user['email'];
    foreach ($meta['agents'] ?? [] as $s => $info) {
        if (strcasecmp($info['name'] ?? '', $displayName) === 0 && $s !== $secret) {
            uiWithLock($dir, function() use ($dir, $s) {
                $v2 = readVersions($dir);
                $v2['last_seen'][$s] = now_iso();
                writeVersions($dir, $v2);
            });
            break;
        }
    }

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
    [$secret, $owner, $project, $dir, $meta] = resolveProjectContext($user);
    $v   = readVersions($dir);
    $out = [];
    foreach ($meta['agents'] ?? [] as $s => $info) {
        $out[] = ['name' => $info['name'] ?? '', 'role' => $info['role'] ?? '', 'last_seen' => $v['last_seen'][$s] ?? null];
    }
    respond_json(200, ['agents' => $out]);
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

// Email of the user who owns an agent. Agents created before 'created_by'
// existed (and the owner agent) belong to the project owner.
function agentOwner(array $info, string $projectOwner): string {
    return (string) ($info['created_by'] ?? $projectOwner);
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
    // Write to a temp file and rename() into place so concurrent readers never
    // see a truncated/half-written file. A truncated versions.json used to
    // decode as empty and get written back, wiping the whole file index.
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("Could not write $path");
    }
    @chgrp($tmp, 'www-data');
    @chmod($tmp, $mode);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Could not replace $path");
    }
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
