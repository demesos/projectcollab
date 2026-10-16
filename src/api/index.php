<?php
/**
 * ProjectCollab API Router v0.2
 *
 * Endpoints (all paths prefixed by /~collab/api/<project>):
 *   GET    /                  - connect
 *   GET    /news              - what's new since last reads
 *   GET    /file?path=...     - read a file
 *   PUT    /file?path=...     - write a file (X-Expected-Version or X-New-File)
 *   DELETE /file?path=...     - delete a file (X-Expected-Version)
 *   GET    /files             - list all files
 *   GET    /chat              - read chat (since, limit)
 *   POST   /chat              - post a message
 *   POST   /chat/seen         - mark chat as seen
 *   GET    /presence          - last_seen for all agents
 *
 * Auth: X-Agent-Secret header (preferred), ?secret= (fallback).
 * Data: /home/collab/data/projects/<project>/{meta.json,versions.json,chat.log,files/...}
 */

declare(strict_types=1);

require '/home/collab/data/lib/lib.php';

const MAX_PATH_LEN = 200;
const CHAT_DEFAULT_LIMIT = 50;
const CHAT_MAX_LIMIT = 500;

header('Content-Type: application/json; charset=utf-8');

$path = isset($_GET['path']) ? trim($_GET['path'], '/') : '';
unset($_GET['path']);

$secret = $_SERVER['HTTP_X_AGENT_SECRET']
       ?? $_GET['secret']
       ?? null;

if (!$secret) {
    respond(401, ['error' => 'unauthorized', 'message' => 'Missing X-Agent-Secret header']);
}

$segments = $path === '' ? [] : explode('/', $path);

// Secret-only mode: no project name in URL (new simplified connect).
// Also handles the legacy case where the first segment is a valid project name.
if (empty($segments) || $segments[0] === '') {
    // Resolve from secret alone — project name not required.
    $project  = '';
    $endpoint = '';
    $sub      = '';
} else {
    $project  = $segments[0];
    $endpoint = $segments[1] ?? '';
    $sub      = $segments[2] ?? '';
    if (!valid_project_slug($project)) {
        respond(400, ['error' => 'bad_request', 'message' => 'Invalid project name']);
    }
}

// Resolve (owner, project, dir) from secret. Project name is an optional cross-check.
$resolved = find_project_by_secret((string) $secret, $project);

if (!$resolved) {
    // --- Guest worker paths -------------------------------------------------
    if (is_guest_secret((string) $secret)) {
        // The bare shared guest secret is only valid for connect: GET / .
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $endpoint === '' && $project === '') {
            handleGuestConnect();   // round-robin assignment; responds and exits
        }
        respond(400, ['error' => 'guest_connect_required',
            'message' => 'Connect first: GET / with the guest secret returns a session token to use as X-Agent-Secret.']);
    }

    // A guest session token?
    $gslot = guest_resolve_token((string) $secret);
    if ($gslot === null) {
        respond(401, ['error' => 'unauthorized', 'message' => 'Invalid secret']);
    }

    // Synthesize a worker context bound to the token's assigned project.
    $projectDir = $gslot['dir'];
    $project    = $gslot['project'];
    $meta       = meta_load($projectDir);
    if (empty($meta['guest_workers'])) {
        respond(403, ['error' => 'guest_not_open',
            'message' => 'This project no longer accepts guest workers. Reconnect with GET /.']);
    }
    $agent = [
        'name'   => $gslot['name'],
        'role'   => 'Guest worker',
        'class'  => 'worker',
        'id'     => $gslot['id'],   // per-session: a reused slot never inherits old claims
        'secret' => (string) $secret,
        'guest'  => true,
    ];
    // Guest presence lives in guest-workers.json (slot last_seen); do not write
    // into the project's versions.json.
} else {
    // --- Normal registered agent -------------------------------------------
    if ($project === '') {
        $project = $resolved['project'];
    }
    $projectDir = $resolved['dir'];
    $meta       = $resolved['meta'];

    $agent = $meta['agents'][$secret];
    $agent['secret'] = $secret;

    // Update last_seen on every authenticated request.
    withLock($projectDir, function() use ($projectDir, $secret) {
        $v = readVersions($projectDir);
        $v['last_seen'][$secret] = nowIso();
        writeVersions($projectDir, $v);
    });
}

$method = $_SERVER['REQUEST_METHOD'];
$route = "$method $endpoint" . ($sub ? "/$sub" : '');

switch ($route) {
    case 'GET ':                handleConnect($projectDir, $meta, $agent, $project); break;
    case 'GET news':            handleNews($projectDir, $agent); break;
    case 'GET file':            handleGetFile($projectDir, $agent); break;
    case 'PUT file':            handlePutFile($projectDir, $agent); break;
    case 'DELETE file':         handleDeleteFile($projectDir, $agent); break;
    case 'GET files':           handleListFiles($projectDir); break;
    case 'GET chat':            handleGetChat($projectDir, $agent); break;
    case 'POST chat':           handlePostChat($projectDir, $agent); break;
    case 'POST chat/seen':      handleChatSeen($projectDir, $agent); break;
    case 'POST files/seen':     handleFilesSeen($projectDir, $agent); break;
    case 'GET presence':        handlePresence($projectDir, $meta); break;
    case 'GET kanban':          handleGetKanban($projectDir, $agent); break;
    case 'POST kanban-claim':   handleKanbanClaim($projectDir, $agent); break;
    case 'POST kanban-release': handleKanbanRelease($projectDir, $agent); break;
    case 'POST kanban-assign':  handleKanbanAssign($projectDir, $agent); break;
    case 'POST kanban-state':   handleKanbanState($projectDir, $agent); break;
    case 'POST kanban-comment': handleKanbanComment($projectDir, $agent); break;
    case 'POST kanban-save':    handleKanbanSave($projectDir, $agent); break;
    case 'GET claim-timeouts':  handleGetClaimTimeouts($projectDir); break;
    case 'POST claim-timeouts': handleSetClaimTimeouts($projectDir, $agent); break;
    default:
        respond(404, ['error' => 'not_found', 'message' => "No route: $route"]);
}

// --- Handlers --------------------------------------------------------------

function handleConnect(string $projectDir, array $meta, array $agent, string $project): void {
    withLock($projectDir, function() use ($projectDir, $meta, $agent, $project) {
        $versions = readVersions($projectDir);
        $versions['last_seen'][$agent['secret']] = nowIso();
        writeVersions($projectDir, $versions);

        $fileCount = 0;
        foreach (($versions['files'] ?? []) as $f) {
            if (($f['state'] ?? 'active') === 'active') $fileCount++;
        }
        $chatUnread = countUnreadChat($projectDir, $versions, $agent['secret']);
        $chatTotal  = countTotalChat($projectDir);

        respond(200, [
            'project'      => $project,          // URL slug — use this in all subsequent calls
            'display_name' => $meta['name'],      // human-readable project name
            'description'  => $meta['description'],
            'agent'        => ['name' => $agent['name'], 'role' => $agent['role'], 'class' => $agent['class'] ?? 'manager'],
            'design_doc'   => 'design.md',
            'has_kanban'   => project_has_kanban($projectDir),
            'claim_timeouts_hours' => claim_timeouts_hours($meta),   // agent/guest renew on any activity
            'file_count'   => $fileCount,
            'chat_unread'  => $chatUnread,
            'chat_total'   => $chatTotal,
            'server_time'  => nowIso(),
        ]);
    });
}

function handleNews(string $projectDir, array $agent): void {
    withLock($projectDir, function() use ($projectDir, $agent) {
        $versions = readVersions($projectDir);
        $reads = $versions['reads'][$agent['secret']] ?? [];
        $files = $versions['files'] ?? [];

        $added = [];
        $updated = [];
        $deleted = [];
        $unchanged = 0;
        $deletionUpdates = [];

        foreach ($files as $path => $info) {
            $currentVer = $info['version'];
            $state = $info['state'] ?? 'active';
            $yourVer = $reads[$path] ?? null;

            if ($state === 'deleted') {
                if ($yourVer !== null && $yourVer < $currentVer) {
                    $deleted[] = [
                        'path'         => $path,
                        'your_version' => $yourVer,
                        'deleted_at'   => $info['deleted_at'] ?? null,
                        'deleted_by'   => $info['deleted_by'] ?? null,
                    ];
                    $deletionUpdates[$path] = $currentVer;
                }
            } else {
                if ($yourVer === null) {
                    $added[] = [
                        'path'        => $path,
                        'version'     => $currentVer,
                        'modified'    => $info['modified'],
                        'modified_by' => $info['modified_by'],
                        'size'        => $info['size'] ?? null,
                    ];
                } elseif ($yourVer < $currentVer) {
                    $wasDeletedInBetween = !empty($info['recreated_from_deletion'])
                        && $info['recreated_from_deletion'] >= $yourVer;
                    $updated[] = [
                        'path'                   => $path,
                        'your_version'           => $yourVer,
                        'current_version'        => $currentVer,
                        'modified'               => $info['modified'],
                        'modified_by'            => $info['modified_by'],
                        'size'                   => $info['size'] ?? null,
                        'was_deleted_in_between' => $wasDeletedInBetween,
                    ];
                } else {
                    $unchanged++;
                }
            }
        }

        if (!empty($deletionUpdates)) {
            foreach ($deletionUpdates as $p => $ver) {
                $versions['reads'][$agent['secret']][$p] = $ver;
            }
            writeVersions($projectDir, $versions);
        }

        $chatUnread = countUnreadChat($projectDir, $versions, $agent['secret']);

        // files_list_unread: true if any file was modified after the agent last viewed the file list
        $filesListSeen = $versions['files_list_seen'][$agent['secret']] ?? '1970-01-01T00:00:00Z';
        $filesListUnread = false;
        foreach ($files as $path => $info) {
            $modified = $info['modified'] ?? '1970-01-01T00:00:00Z';
            if ($modified > $filesListSeen) {
                $filesListUnread = true;
                break;
            }
        }

        respond(200, [
            'files_added'       => $added,
            'files_updated'     => $updated,
            'files_deleted'     => $deleted,
            'files_unchanged'   => $unchanged,
            'files_list_unread' => $filesListUnread,
            'chat_unread'       => $chatUnread,
            'as_of'             => nowIso(),
        ]);
    });
}

function handleGetFile(string $projectDir, array $agent): void {
    $relPath = validateFilePath($_GET["p"] ?? "");

    withLock($projectDir, function() use ($projectDir, $agent, $relPath) {
        $versions = readVersions($projectDir);
        $info = $versions['files'][$relPath] ?? null;

        if (!$info) {
            respond(404, ['error' => 'not_found', 'message' => 'File does not exist']);
        }

        if (($info['state'] ?? 'active') === 'deleted') {
            $versions['reads'][$agent['secret']][$relPath] = $info['version'];
            writeVersions($projectDir, $versions);
            respond(410, [
                'error'                => 'file_deleted',
                'path'                 => $relPath,
                'version_when_deleted' => $info['version'],
                'deleted_at'           => $info['deleted_at'] ?? null,
                'deleted_by'           => $info['deleted_by'] ?? null,
            ]);
        }

        $absPath = $projectDir . '/files/' . $relPath;
        if (!is_file($absPath)) {
            respond(500, ['error' => 'server_error', 'message' => 'File missing on disk but version tracked']);
        }

        $versions['reads'][$agent['secret']][$relPath] = $info['version'];
        writeVersions($projectDir, $versions);

        header('Content-Type: ' . ($info['mime'] ?? 'application/octet-stream'));
        header('X-File-Version: ' . $info['version']);
        header('X-File-Modified: ' . $info['modified']);
        header('X-File-Modified-By: ' . $info['modified_by']);
        header('Content-Length: ' . filesize($absPath));
        readfile($absPath);
        exit;
    });
}

function handlePutFile(string $projectDir, array $agent): void {
    $relPath = validateFilePath($_GET["p"] ?? "");
    $body = file_get_contents('php://input');
    $mime = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
    $expectedVersion = $_SERVER['HTTP_X_EXPECTED_VERSION'] ?? null;
    $isNewFile = !empty($_SERVER['HTTP_X_NEW_FILE']);

    withLock($projectDir, function() use ($projectDir, $agent, $relPath, $body, $mime, $expectedVersion, $isNewFile) {
        $versions = readVersions($projectDir);
        $info = $versions['files'][$relPath] ?? null;
        $state = $info['state'] ?? null;

        $now = nowIso();
        $size = strlen($body);

        if ($isNewFile) {
            if ($info && $state === 'active') {
                respond(409, [
                    'error'           => 'version_conflict',
                    'message'         => 'File already exists. Use X-Expected-Version for updates.',
                    'current_version' => $info['version'],
                ]);
            }
            $newVersion = $info ? $info['version'] + 1 : 1;
            $recreatedFromDeletion = $info ? $info['version'] : null;
        } else {
            if (!$info) {
                respond(404, [
                    'error'   => 'not_found',
                    'message' => 'File does not exist. Use X-New-File: true to create it.',
                ]);
            }
            if ($state === 'deleted') {
                $versions['reads'][$agent['secret']][$relPath] = $info['version'];
                writeVersions($projectDir, $versions);
                respond(410, [
                    'error'            => 'file_deleted',
                    'message'          => 'File was deleted. Use X-New-File: true to recreate.',
                    'your_version'     => $expectedVersion ? (int)$expectedVersion : null,
                    'deletion_version' => $info['version'],
                    'deleted_at'       => $info['deleted_at'] ?? null,
                    'deleted_by'       => $info['deleted_by'] ?? null,
                    'hint'             => 'To recreate, retry with header X-New-File: true.',
                ]);
            }

            $claimedVersion = $expectedVersion !== null
                ? (int)$expectedVersion
                : ($versions['reads'][$agent['secret']][$relPath] ?? null);

            if ($claimedVersion === null) {
                respond(400, [
                    'error'   => 'bad_request',
                    'message' => 'Must provide X-Expected-Version or have read the file first.',
                ]);
            }

            if ($claimedVersion !== $info['version']) {
                $diff = null;
                $absPath = $projectDir . '/files/' . $relPath;
                if (isTextFile($info['mime'] ?? '') && is_file($absPath)) {
                    $diff = unifiedDiff($body, file_get_contents($absPath));
                }
                respond(409, [
                    'error'               => 'version_conflict',
                    'message'             => "File was modified by {$info['modified_by']} at {$info['modified']}. Your version was $claimedVersion, current is {$info['version']}.",
                    'your_version'        => $claimedVersion,
                    'current_version'     => $info['version'],
                    'current_modified'    => $info['modified'],
                    'current_modified_by' => $info['modified_by'],
                    'diff'                => $diff,
                ]);
            }

            $newVersion = $info['version'] + 1;
            $recreatedFromDeletion = null;
        }

        $absPath = $projectDir . '/files/' . $relPath;
        $dir = dirname($absPath);
        if (!is_dir($dir)) mkdir($dir, 0770, true);

        $tmp = $absPath . '.tmp.' . getmypid();
        file_put_contents($tmp, $body);
        rename($tmp, $absPath);

        // Use filesize() as authoritative size — strlen($body) can return 0
        // if php://input was empty (e.g. post_max_size exceeded or stream already read).
        $actualSize = filesize($absPath);
        if ($actualSize === false) $actualSize = $size;

        $newInfo = [
            'version'     => $newVersion,
            'state'       => 'active',
            'modified'    => $now,
            'modified_by' => $agent['name'],
            'size'        => $actualSize,
            'mime'        => $mime,
        ];
        if ($recreatedFromDeletion !== null) {
            $newInfo['recreated_from_deletion'] = $recreatedFromDeletion;
        }
        $versions['files'][$relPath] = $newInfo;
        $versions['reads'][$agent['secret']][$relPath] = $newVersion;
        writeVersions($projectDir, $versions);

        $prevVersion = $info['version'] ?? 0;
        respond(200, [
            'path'             => $relPath,
            'version'          => $newVersion,
            'previous_version' => $prevVersion,
            'size'             => $actualSize,
            'modified'         => $now,
            'written_by'       => $agent['name'],
        ]);
    });
}

function handleDeleteFile(string $projectDir, array $agent): void {
    $relPath = validateFilePath($_GET["p"] ?? "");
    $expectedVersion = $_SERVER['HTTP_X_EXPECTED_VERSION'] ?? null;

    withLock($projectDir, function() use ($projectDir, $agent, $relPath, $expectedVersion) {
        $versions = readVersions($projectDir);
        $info = $versions['files'][$relPath] ?? null;

        if (!$info) {
            respond(404, ['error' => 'not_found', 'message' => 'File does not exist']);
        }

        if (($info['state'] ?? 'active') === 'deleted') {
            $versions['reads'][$agent['secret']][$relPath] = $info['version'];
            writeVersions($projectDir, $versions);
            respond(410, [
                'error'                => 'file_deleted',
                'message'              => 'File is already deleted.',
                'version_when_deleted' => $info['version'],
                'deleted_at'           => $info['deleted_at'] ?? null,
                'deleted_by'           => $info['deleted_by'] ?? null,
            ]);
        }

        $claimedVersion = $expectedVersion !== null
            ? (int)$expectedVersion
            : ($versions['reads'][$agent['secret']][$relPath] ?? null);

        if ($claimedVersion === null) {
            respond(400, [
                'error'   => 'bad_request',
                'message' => 'Must provide X-Expected-Version or have read the file first.',
            ]);
        }

        if ($claimedVersion !== $info['version']) {
            $diff = null;
            $absPath = $projectDir . '/files/' . $relPath;
            if (isTextFile($info['mime'] ?? '') && is_file($absPath)) {
                $diff = unifiedDiff('', file_get_contents($absPath));
            }
            respond(409, [
                'error'               => 'version_conflict',
                'your_version'        => $claimedVersion,
                'current_version'     => $info['version'],
                'current_modified'    => $info['modified'],
                'current_modified_by' => $info['modified_by'],
                'diff'                => $diff,
            ]);
        }

        $now = nowIso();
        $info['state'] = 'deleted';
        $info['deleted_at'] = $now;
        $info['deleted_by'] = $agent['name'];
        $versions['files'][$relPath] = $info;
        $versions['reads'][$agent['secret']][$relPath] = $info['version'];
        writeVersions($projectDir, $versions);

        respond(200, [
            'path'       => $relPath,
            'version'    => $info['version'],
            'state'      => 'deleted',
            'deleted_at' => $now,
            'deleted_by' => $agent['name'],
        ]);
    });
}

function handleListFiles(string $projectDir): void {
    $versions = readVersions($projectDir);
    $since = $_GET['since'] ?? null;

    $out = [];
    foreach (($versions['files'] ?? []) as $path => $info) {
        if ($since !== null) {
            $relevant = ($info['modified'] ?? '') > $since
                     || ($info['deleted_at'] ?? '') > $since;
            if (!$relevant) continue;
        }
        $entry = [
            'path'        => $path,
            'version'     => $info['version'],
            'state'       => $info['state'] ?? 'active',
            'modified'    => $info['modified'] ?? null,
            'modified_by' => $info['modified_by'] ?? null,
            'size'        => $info['size'] ?? null,
        ];
        // If size is 0 or null for an active file, cross-check with actual filesize.
        if (($entry['state'] === 'active') && (($entry['size'] ?? 0) === 0)) {
            $absPath = $projectDir . '/files/' . $path;
            if (is_file($absPath)) {
                $fsz = filesize($absPath);
                if ($fsz !== false && $fsz > 0) $entry['size'] = $fsz;
            }
        }
        if (($info['state'] ?? 'active') === 'deleted') {
            $entry['deleted_at'] = $info['deleted_at'] ?? null;
            $entry['deleted_by'] = $info['deleted_by'] ?? null;
        }
        $out[] = $entry;
    }

    respond(200, ['files' => $out]);
}

function handleGetChat(string $projectDir, array $agent): void {
    $since = $_GET['since'] ?? null;
    $limit = isset($_GET['limit'])
        ? min(CHAT_MAX_LIMIT, max(1, (int)$_GET['limit']))
        : CHAT_DEFAULT_LIMIT;

    $chatPath = $projectDir . '/chat.log';
    $messages = [];

    if (is_file($chatPath)) {
        $fh = fopen($chatPath, 'r');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $msg = json_decode($line, true);
                if (!is_array($msg)) continue;
                if ($since !== null && ($msg['time'] ?? '') <= $since) continue;
                $messages[] = $msg;
            }
            fclose($fh);
        }
    }

    $hasMore = false;
    if ($since === null && count($messages) > $limit) {
        $messages = array_slice($messages, -$limit);
        $hasMore = true;
    }

    respond(200, ['messages' => $messages, 'has_more' => $hasMore]);
}

function handlePostChat(string $projectDir, array $agent): void {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !isset($payload['text']) || !is_string($payload['text'])) {
        respond(400, ['error' => 'bad_request', 'message' => 'Body must be JSON with a "text" field']);
    }

    $text = trim($payload['text']);
    if ($text === '') {
        respond(400, ['error' => 'bad_request', 'message' => 'text must not be empty']);
    }

    withLock($projectDir, function() use ($projectDir, $agent, $text) {
        $chatPath = $projectDir . '/chat.log';

        $id = 1;
        if (is_file($chatPath)) {
            $fh = fopen($chatPath, 'r');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    $msg = json_decode(trim($line), true);
                    if (is_array($msg) && isset($msg['id'])) {
                        $id = max($id, (int)$msg['id'] + 1);
                    }
                }
                fclose($fh);
            }
        }

        $msg = [
            'id'   => $id,
            'time' => nowIso(),
            'from' => $agent['name'],
            'text' => $text,
        ];

        file_put_contents($chatPath, json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        respond(200, ['id' => $id, 'time' => $msg['time'], 'from' => $agent['name']]);
    });
}

function handleChatSeen(string $projectDir, array $agent): void {
    withLock($projectDir, function() use ($projectDir, $agent) {
        $versions = readVersions($projectDir);
        $now = nowIso();
        $versions['last_seen'][$agent['secret']] = $now;
        writeVersions($projectDir, $versions);
        respond(200, ['last_seen' => $now]);
    });
}

function handleFilesSeen(string $projectDir, array $agent): void {
    withLock($projectDir, function() use ($projectDir, $agent) {
        $versions = readVersions($projectDir);
        $now = nowIso();
        $versions['files_list_seen'][$agent['secret']] = $now;
        writeVersions($projectDir, $versions);
        respond(200, ['files_list_seen' => $now]);
    });
}

function handlePresence(string $projectDir, array $meta): void {
    $versions = readVersions($projectDir);
    $lastSeen = $versions['last_seen'] ?? [];

    $out = [];
    foreach ($meta['agents'] as $secret => $info) {
        $out[] = [
            'name'      => $info['name'],
            'role'      => $info['role'],
            'last_seen' => $lastSeen[$secret] ?? null,
        ];
    }

    respond(200, ['agents' => $out]);
}

// --- Guest workers ---------------------------------------------------------

function handleGuestConnect(): void {
    $a = guest_assign();
    if (empty($a['assigned'])) {
        respond(200, [
            'assigned' => false,
            'message'  => 'No guest work available right now. Reconnect later with GET /.',
            'server_time' => now_iso(),
        ]);
    }
    $dir  = $a['dir'];
    $meta = meta_load($dir);
    respond(200, [
        'assigned'             => true,
        'guest'                => true,
        'name'                 => $a['name'],
        'role'                 => 'Guest worker',
        'class'                => 'worker',
        'token'                => $a['token'],
        'owner'                => $a['owner'],
        'project'              => $a['project'],
        'display_name'         => $meta['display_name'] ?? $a['project'],
        'description'          => $meta['description'] ?? '',
        'design_doc'           => 'design.md',
        'has_kanban'           => true,
        'claim_timeouts_hours' => claim_timeouts_hours($meta),
        'available_task_count' => $a['available'],
        'instructions'         => 'You are a guest worker. From now on send the token as your X-Agent-Secret and address the assigned project in the path, e.g. GET /' . $a['project']
                                . '/kanban. Claim a ready task, work it, then mark it done. If you must stop before finishing, set the task to "partly" with a comment so the next worker can continue. Your claim and this session stay alive as long as you make a request at least every claim_timeouts_hours.guest hours. You start fresh on every connect.',
        'server_time'          => now_iso(),
    ]);
}

// --- Kanban (agent-facing) -------------------------------------------------
//
// Mirrors the human UI kanban endpoints (src/ui/api.php) but authed by the
// agent secret. owner/project come from the resolved secret, not the body.
// Reuses the shared kanban_* helpers in lib.php. claimed_by/comment authors are
// stored as the agent's stable id (NEVER the secret) + a display name.

/** Stable, non-secret id for the calling agent (falls back to a hash of the
 *  secret if the agent predates the class/id migration). */
function agentId(array $agent): string {
    if (!empty($agent['id'])) return (string) $agent['id'];
    return 'ag_' . substr(hash('sha256', (string) ($agent['secret'] ?? '')), 0, 8);
}

/** Managers (and the owner/admin agent) may edit board structure. */
function agentIsManager(array $agent): bool {
    return (($agent['class'] ?? 'manager') === 'manager') || !empty($agent['admin']);
}

function kanbanBody(): array {
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function respondKanbanConflict(string $projectDir, RuntimeException $e): void {
    $cur = (int) substr($e->getMessage(), strlen('KANBAN_VERSION_CONFLICT:'));
    $latest = kanban_load($projectDir);
    kanban_compute_states($latest);
    kanban_annotate($latest, $projectDir);
    respond(409, [
        'error'           => 'version_conflict',
        'message'         => "Kanban was modified. Current version is $cur.",
        'current_version' => $cur,
        'current'         => $latest,
    ]);
}

function handleGetKanban(string $projectDir, array $agent): void {
    if (!project_has_kanban($projectDir)) {
        respond(404, ['error' => 'kanban_disabled',
            'message' => 'Kanban is not enabled for this project']);
    }
    // Lock once to sweep expired claims; no structural change.
    [$data, ] = kanban_with_lock($projectDir, null, function(array &$d) {
        return ['_no_change' => true];
    });
    kanban_compute_states($data);
    kanban_annotate($data, $projectDir);
    respond(200, [
        'kanban_version' => $data['kanban_version'],
        'claim_timeouts_hours' => claim_timeouts_hours(meta_load($projectDir)),
        'wps'            => $data['wps'],
        'next_wp_id'     => $data['next_wp_id']   ?? null,
        'next_task_id'   => $data['next_task_id'] ?? null,
    ]);
}

function handleKanbanClaim(string $projectDir, array $agent): void {
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $taskId   = (string) ($body['task_id'] ?? '');
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond(400, ['error' => 'bad_request', 'message' => 'task_id required']);

    $aid = agentId($agent); $aname = $agent['name'] ?? 'agent';
    try {
        [$saved, $result] = kanban_with_lock($projectDir, $expected,
                function(array &$d) use ($taskId, $aid, $aname) {
            $task = &kanban_find_task($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            $state = $task['state'] ?? 'open';
            if ($state === 'open') return ['_no_change' => true, 'error' => 'task_not_ready'];
            if ($state === 'claimed') {
                $existing = $task['claimed_by'] ?? null;
                if ($existing !== null && $existing !== $aid) {
                    return ['_no_change' => true, 'error' => 'already_claimed',
                            'claimed_by' => $existing,
                            'claimed_by_name' => $task['claimed_by_name'] ?? null,
                            'claimed_at' => $task['claimed_at'] ?? null];
                }
            }
            $task['state']           = 'claimed';
            $task['claimed_by']      = $aid;
            $task['claimed_by_name'] = $aname;
            $task['claimed_at']      = now_iso();
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            respond($result['error'] === 'task_not_found' ? 404 : 409, $result);
        }
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'],
            'task' => kanban_find_task($saved, $taskId)]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

function handleKanbanRelease(string $projectDir, array $agent): void {
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $taskId   = (string) ($body['task_id'] ?? '');
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond(400, ['error' => 'bad_request', 'message' => 'task_id required']);

    $aid = agentId($agent); $isPriv = agentIsManager($agent);
    try {
        [$saved, $result] = kanban_with_lock($projectDir, $expected,
                function(array &$d) use ($taskId, $aid, $isPriv) {
            $task = &kanban_find_task($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            if (($task['state'] ?? '') !== 'claimed') return ['_no_change' => true, 'error' => 'not_claimed'];
            $cb = $task['claimed_by'] ?? null;
            if ($cb !== $aid && !$isPriv) {
                return ['_no_change' => true, 'error' => 'forbidden',
                        'message' => 'Only the claimer or a manager can release'];
            }
            $task['state']           = 'ready';
            $task['claimed_by']      = null;
            $task['claimed_by_name'] = null;
            $task['claimed_at']      = null;
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            $code = $result['error'] === 'task_not_found' ? 404
                  : ($result['error'] === 'forbidden' ? 403 : 409);
            respond($code, $result);
        }
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'],
            'task' => kanban_find_task($saved, $taskId)]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

/**
 * Managers only: assign a task to a human project member (the owner or a
 * sharee, by email). The task becomes claimed by that human, exactly as if they
 * had claimed it in the web UI (human claim timeout, counted from now), and a
 * comment records who assigned it. Reassigning a claimed task is allowed.
 * To unassign, use kanban-release.
 */
function handleKanbanAssign(string $projectDir, array $agent): void {
    if (!agentIsManager($agent)) {
        respond(403, ['error' => 'forbidden', 'message' => 'Only manager agents can assign tasks']);
    }
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $taskId   = (string) ($body['task_id'] ?? '');
    $assignee = strtolower(trim((string) ($body['assignee'] ?? '')));
    $note     = isset($body['comment']) ? trim((string) $body['comment']) : '';
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond(400, ['error' => 'bad_request', 'message' => 'task_id required']);

    // Project members = owner (from the data path) + shared_with.
    $owner   = basename(dirname($projectDir, 2));
    $members = array_merge([$owner], meta_load($projectDir)['shared_with'] ?? []);
    $members = array_values(array_unique(array_map('strtolower', $members)));
    if (!in_array($assignee, $members, true)) {
        respond(400, ['error' => 'bad_request',
            'message' => 'assignee must be the email of a human project member',
            'members' => $members]);
    }

    $aid = agentId($agent); $aname = $agent['name'] ?? 'agent';
    try {
        [$saved, $result] = kanban_with_lock($projectDir, $expected,
                function(array &$d) use ($taskId, $assignee, $note, $aid, $aname) {
            $task = &kanban_find_task($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            $state = $task['state'] ?? 'open';
            if ($state === 'open') return ['_no_change' => true, 'error' => 'task_not_ready'];
            if ($state === 'done') return ['_no_change' => true, 'error' => 'task_done'];
            $prev = ($state === 'claimed') ? ($task['claimed_by_name'] ?? $task['claimed_by'] ?? null) : null;
            $task['state']           = 'claimed';
            $task['claimed_by']      = $assignee;
            $task['claimed_by_name'] = $assignee;
            $task['claimed_at']      = now_iso();
            if (!isset($task['comments']) || !is_array($task['comments'])) $task['comments'] = [];
            $text = "Assigned to $assignee by $aname" . ($prev && $prev !== $assignee ? " (was: $prev)" : '') . '.';
            if ($note !== '') $text .= ' ' . $note;
            $task['comments'][] = ['by' => $aid, 'by_name' => $aname, 'at' => now_iso(), 'text' => $text];
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            respond($result['error'] === 'task_not_found' ? 404 : 409, $result);
        }
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'],
            'task' => kanban_find_task($saved, $taskId)]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

function handleKanbanState(string $projectDir, array $agent): void {
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $taskId   = (string) ($body['task_id'] ?? '');
    $newState = (string) ($body['new_state'] ?? '');
    $comment  = isset($body['comment']) ? trim((string) $body['comment']) : '';
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond(400, ['error' => 'bad_request', 'message' => 'task_id required']);
    if (!in_array($newState, ['partly', 'done', 'claimed'], true)) {
        respond(400, ['error' => 'bad_request', 'message' => 'new_state must be one of: partly, done, claimed']);
    }

    $aid = agentId($agent); $aname = $agent['name'] ?? 'agent'; $isPriv = agentIsManager($agent);
    try {
        [$saved, $result] = kanban_with_lock($projectDir, $expected,
                function(array &$d) use ($taskId, $newState, $comment, $aid, $aname, $isPriv) {
            $task = &kanban_find_task($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            $cur = $task['state'] ?? 'open';

            if ($cur === 'claimed' && ($newState === 'partly' || $newState === 'done')) {
                $cb = $task['claimed_by'] ?? null;
                if ($cb !== $aid && !$isPriv) {
                    return ['_no_change' => true, 'error' => 'forbidden',
                            'message' => 'Only the claimer can mark partly/done'];
                }
            } elseif ($cur === 'partly' && $newState === 'claimed') {
            } elseif ($cur === 'done' && $newState === 'partly') {
            } elseif ($cur === 'ready' && $newState === 'claimed') {
            } else {
                return ['_no_change' => true, 'error' => 'invalid_transition',
                        'message' => "Cannot move from $cur to $newState"];
            }

            $task['state'] = $newState;
            if ($newState === 'claimed') {
                $task['claimed_by']      = $aid;
                $task['claimed_by_name'] = $aname;
                $task['claimed_at']      = now_iso();
            } elseif ($newState === 'done') {
                $task['completed_by']      = $aid;
                $task['completed_by_name'] = $aname;
                $task['completed_at']      = now_iso();
                $task['claimed_by']        = null;
                $task['claimed_by_name']   = null;
                $task['claimed_at']        = null;
            } elseif ($newState === 'partly') {
                $task['claimed_by']      = null;
                $task['claimed_by_name'] = null;
                $task['claimed_at']      = null;
            }
            if ($comment !== '') {
                if (!isset($task['comments']) || !is_array($task['comments'])) $task['comments'] = [];
                $task['comments'][] = ['by' => $aid, 'by_name' => $aname, 'at' => now_iso(), 'text' => $comment];
            }
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            $code = $result['error'] === 'task_not_found' ? 404
                  : ($result['error'] === 'forbidden' ? 403 : 409);
            respond($code, $result);
        }
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'],
            'task' => kanban_find_task($saved, $taskId)]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

function handleKanbanComment(string $projectDir, array $agent): void {
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $taskId   = (string) ($body['task_id'] ?? '');
    $text     = isset($body['text']) ? trim((string) $body['text']) : '';
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;
    if ($taskId === '') respond(400, ['error' => 'bad_request', 'message' => 'task_id required']);
    if ($text === '')   respond(400, ['error' => 'bad_request', 'message' => 'text required']);

    $aid = agentId($agent); $aname = $agent['name'] ?? 'agent';
    try {
        [$saved, $result] = kanban_with_lock($projectDir, $expected,
                function(array &$d) use ($taskId, $text, $aid, $aname) {
            $task = &kanban_find_task($d, $taskId);
            if ($task === null) return ['_no_change' => true, 'error' => 'task_not_found'];
            if (!isset($task['comments']) || !is_array($task['comments'])) $task['comments'] = [];
            $task['comments'][] = ['by' => $aid, 'by_name' => $aname, 'at' => now_iso(), 'text' => $text];
            return null;
        });
        if (is_array($result) && isset($result['error'])) {
            respond($result['error'] === 'task_not_found' ? 404 : 409, $result);
        }
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'],
            'task' => kanban_find_task($saved, $taskId)]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

function handleKanbanSave(string $projectDir, array $agent): void {
    if (!agentIsManager($agent)) {
        respond(403, ['error' => 'forbidden',
            'message' => 'Only manager agents can edit the board structure']);
    }
    if (!project_has_kanban($projectDir)) respond(404, ['error' => 'kanban_disabled']);
    $body = kanbanBody();
    $wps = $body['wps'] ?? null;
    if (!is_array($wps)) respond(400, ['error' => 'bad_request', 'message' => 'wps must be an array']);
    $err = kanban_validate_wps($wps);
    if ($err !== null) respond(400, ['error' => 'invalid_schema', 'message' => $err]);
    $expected = isset($body['expected_version']) ? (int) $body['expected_version'] : null;

    try {
        [$saved, ] = kanban_with_lock($projectDir, $expected, function(array &$d) use ($wps, $body) {
            $d['wps'] = $wps;
            if (isset($body['next_wp_id']))   $d['next_wp_id']   = (int) $body['next_wp_id'];
            if (isset($body['next_task_id'])) $d['next_task_id'] = (int) $body['next_task_id'];
        });
        kanban_annotate($saved, $projectDir);
        respond(200, ['kanban_version' => $saved['kanban_version'], 'wps' => $saved['wps']]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'KANBAN_VERSION_CONFLICT:') === 0) respondKanbanConflict($projectDir, $e);
        throw $e;
    }
}

// --- Claim timeouts ---------------------------------------------------------

function handleGetClaimTimeouts(string $projectDir): void {
    respond(200, ['claim_timeouts_hours' => claim_timeouts_hours(meta_load($projectDir)),
        'renewal' => 'agent and guest claims renew on any request by the claimer; human claims count from claimed_at']);
}

/** Managers may change the project's claim timeouts. Body: {agent_hours?, guest_hours?, human_hours?}. */
function handleSetClaimTimeouts(string $projectDir, array $agent): void {
    if (!agentIsManager($agent)) {
        respond(403, ['error' => 'forbidden', 'message' => 'Only manager agents can change claim timeouts']);
    }
    $body = kanbanBody();
    $result = with_file_lock("$projectDir/.lock", function() use ($projectDir, $body) {
        $meta = json_decode((string) @file_get_contents("$projectDir/meta.json"), true);
        if (!is_array($meta)) respond(500, ['error' => 'meta_unreadable']);
        $new = claim_timeouts_apply($meta, $body);
        if (is_string($new)) respond(400, ['error' => 'bad_request', 'message' => $new]);
        $meta['claim_timeouts'] = $new;
        meta_save($projectDir, $meta);
        return $meta;
    });
    respond(200, ['claim_timeouts_hours' => claim_timeouts_hours($result)]);
}

// --- Helpers ---------------------------------------------------------------

function respond(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function nowIso(): string {
    return gmdate('Y-m-d\TH:i:s\Z');
}

function validateFilePath(string $path): string {
    $path = trim($path, '/');
    if ($path === '') {
        respond(400, ['error' => 'bad_request', 'message' => 'path parameter required']);
    }
    if (strlen($path) > MAX_PATH_LEN) {
        respond(400, ['error' => 'bad_request', 'message' => 'path too long']);
    }
    if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
        respond(400, ['error' => 'bad_request', 'message' => 'invalid path']);
    }
    // Allowed characters — deliberately a positive whitelist so we don't
    // accidentally permit URL-structural or shell-dangerous characters.
    //
    // Allowed:  a-z A-Z 0-9  . _ - /  SPACE ( ) + ! ' , @ = ~
    //
    // Blocked (reason):
    //   %  — URL-encoding prefix; a name like "foo%2Fbar" would be
    //         ambiguous after URL-decoding in some contexts.
    //   #  — URL fragment separator; browsers silently truncate anything
    //         after '#' in a URL.
    //   ?  — URL query separator; same browser truncation problem.
    //   * ? \ — shell glob / Windows path characters.
    //   & ; | ` — shell operators / injection risk.
    //   < > "  — shell redirection / forbidden on Windows filesystems.
    //   NUL ..  — already caught above (path traversal / injection).
    if (!preg_match('#^[a-zA-Z0-9._/\x20()+!\'\\,@=~-]+$#', $path)) {
        respond(400, ['error' => 'bad_request', 'message' =>
            'path contains illegal characters — allowed: '
            . 'letters, digits, spaces, and: . _ - ( ) + ! \' , @ = ~ /']);
    }
    // Reject any path component that is empty (would mean '//'), purely
    // whitespace, or has leading/trailing whitespace. This keeps the
    // common case ("My File.txt") working while blocking invisible or
    // ambiguous filenames like "  " or " foo".
    foreach (explode('/', $path) as $comp) {
        if ($comp === '' || trim($comp) === '' || $comp !== trim($comp)) {
            respond(400, ['error' => 'bad_request', 'message' =>
                'path component is empty or has leading/trailing whitespace']);
        }
    }
    return $path;
}

function readVersions(string $projectDir): array {
    $path = $projectDir . '/versions.json';
    if (!file_exists($path)) {
        return ['files' => [], 'reads' => [], 'last_seen' => []];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        // Never continue with an empty index when the file exists but can't be
        // parsed: the caller would write it back and wipe the project's index.
        respond(500, ['error' => 'versions_unreadable', 'message' => 'versions.json unreadable, try again']);
    }
    // 'files'/'reads' are maps. An empty map may be stored as [] (json_encode([]));
    // numeric file names decode to int keys, which is fine — never reset non-empty data.
    $files = $data['files'] ?? [];
    if (!is_array($files)) $files = [];
    $reads = $data['reads'] ?? [];
    if (!is_array($reads)) $reads = [];
    $data['files']     = $files;
    $data['reads']     = $reads;
    $data['last_seen'] = $data['last_seen'] ?? [];
    return $data;
}

function writeVersions(string $projectDir, array $versions): void {
    $path = $projectDir . '/versions.json';
    $tmp = $path . '.tmp.' . getmypid();
    // 'files' and 'reads' must always be JSON objects ({}) not arrays ([]).
    // PHP encodes an empty array as [] but an empty stdClass as {}.
    // A corrupt or uninitialized [] would make all files invisible on the next read.
    if (empty($versions['files'])) $versions['files'] = new \stdClass();
    if (empty($versions['reads'])) $versions['reads'] = new \stdClass();
    file_put_contents($tmp, json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

function countUnreadChat(string $projectDir, array $versions, string $secret): int {
    $chatPath = $projectDir . '/chat.log';
    if (!file_exists($chatPath)) return 0;
    $lastSeen = $versions['last_seen'][$secret] ?? '1970-01-01T00:00:00Z';
    $count = 0;
    $fh = fopen($chatPath, 'r');
    if (!$fh) return 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $msg = json_decode($line, true);
        if (is_array($msg) && isset($msg['time']) && $msg['time'] > $lastSeen) {
            $count++;
        }
    }
    fclose($fh);
    return $count;
}

function countTotalChat(string $projectDir): int {
    $chatPath = $projectDir . '/chat.log';
    if (!file_exists($chatPath)) return 0;
    $count = 0;
    $fh = fopen($chatPath, 'r');
    if (!$fh) return 0;
    while (($line = fgets($fh)) !== false) {
        if (trim($line) !== '') $count++;
    }
    fclose($fh);
    return $count;
}

function isTextFile(string $mime): bool {
    if ($mime === '') return false;
    if (strpos($mime, 'text/') === 0) return true;
    if (in_array($mime, [
        'application/json', 'application/javascript',
        'application/xml', 'application/x-yaml',
    ], true)) return true;
    return false;
}

function unifiedDiff(string $a, string $b): string {
    $aLines = explode("\n", $a);
    $bLines = explode("\n", $b);
    $lcs = lcs($aLines, $bLines);
    $out = '';
    $i = 0; $j = 0;
    foreach ($lcs as $common) {
        while ($i < count($aLines) && $aLines[$i] !== $common) {
            $out .= "-" . $aLines[$i] . "\n"; $i++;
        }
        while ($j < count($bLines) && $bLines[$j] !== $common) {
            $out .= "+" . $bLines[$j] . "\n"; $j++;
        }
        $out .= " " . $common . "\n";
        $i++; $j++;
    }
    while ($i < count($aLines)) { $out .= "-" . $aLines[$i++] . "\n"; }
    while ($j < count($bLines)) { $out .= "+" . $bLines[$j++] . "\n"; }
    return $out;
}

function lcs(array $a, array $b): array {
    $m = count($a); $n = count($b);
    if ($m === 0 || $n === 0) return [];
    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = 1; $i <= $m; $i++) {
        for ($j = 1; $j <= $n; $j++) {
            if ($a[$i-1] === $b[$j-1]) {
                $dp[$i][$j] = $dp[$i-1][$j-1] + 1;
            } else {
                $dp[$i][$j] = max($dp[$i-1][$j], $dp[$i][$j-1]);
            }
        }
    }
    $result = [];
    $i = $m; $j = $n;
    while ($i > 0 && $j > 0) {
        if ($a[$i-1] === $b[$j-1]) {
            array_unshift($result, $a[$i-1]); $i--; $j--;
        } elseif ($dp[$i-1][$j] >= $dp[$i][$j-1]) {
            $i--;
        } else {
            $j--;
        }
    }
    return $result;
}

function withLock(string $projectDir, callable $fn) {
    $lockPath = $projectDir . '/.lock';
    $fh = fopen($lockPath, 'c');
    if (!$fh) {
        respond(500, ['error' => 'server_error', 'message' => 'Cannot acquire project lock']);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        respond(500, ['error' => 'server_error', 'message' => 'Lock failed']);
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
