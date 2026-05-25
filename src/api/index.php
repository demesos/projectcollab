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
    respond(401, ['error' => 'unauthorized', 'message' => 'Invalid secret']);
}

// If no project was given in the URL, fill it in from the resolved data.
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
            'agent'        => ['name' => $agent['name'], 'role' => $agent['role']],
            'design_doc'   => 'design.md',
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
    if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $path)) {
        respond(400, ['error' => 'bad_request', 'message' => 'path contains illegal characters']);
    }
    return $path;
}

function readVersions(string $projectDir): array {
    $path = $projectDir . '/versions.json';
    if (!file_exists($path)) {
        return ['files' => [], 'reads' => [], 'last_seen' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return ['files' => [], 'reads' => [], 'last_seen' => []];
    }
    $data['files']     = $data['files']     ?? [];
    $data['reads']     = $data['reads']     ?? [];
    $data['last_seen'] = $data['last_seen'] ?? [];
    return $data;
}

function writeVersions(string $projectDir, array $versions): void {
    $path = $projectDir . '/versions.json';
    $tmp = $path . '.tmp.' . getmypid();
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
