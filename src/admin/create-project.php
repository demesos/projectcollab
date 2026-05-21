<?php
/**
 * create-project.php — CLI helper to bootstrap a new project.
 *
 * Run as the collab user:
 *   sudo -u collab php /home/collab/public_html/admin/create-project.php <name>
 *
 * Creates files with mode 660 and group www-data so PHP (running as www-data)
 * can write to them.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

if ($argc < 2) {
    die("Usage: php create-project.php <project-name>\n");
}

$project = $argv[1];
if (!preg_match('/^[a-z0-9_-]+$/i', $project)) {
    die("Invalid project name. Use [a-z0-9_-] only.\n");
}

$dataRoot = '/home/collab/data/projects';
$projectDir = $dataRoot . '/' . $project;

if (is_dir($projectDir)) {
    die("Project '$project' already exists.\n");
}

$ownerSecret = bin2hex(random_bytes(16));

mkdir($projectDir, 0770, true);
mkdir($projectDir . '/files', 0770, true);

// Set group ownership so www-data can write
@chgrp($projectDir, 'www-data');
@chgrp($projectDir . '/files', 'www-data');

$meta = [
    'name'        => ucfirst($project),
    'description' => 'New project — edit this description.',
    'created'     => gmdate('Y-m-d\TH:i:s\Z'),
    'agents'      => [
        $ownerSecret => [
            'name'  => 'Wil',
            'role'  => 'owner',
            'admin' => true,
        ],
    ],
];

writeWithPerms(
    $projectDir . '/meta.json',
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    0660  // meta.json: writable by www-data (admin API edits it)
);

writeWithPerms(
    $projectDir . '/versions.json',
    json_encode(['files' => new stdClass(), 'reads' => new stdClass(), 'last_seen' => new stdClass()], JSON_PRETTY_PRINT),
    0660  // versions.json: www-data writes to this constantly
);

writeWithPerms($projectDir . '/chat.log', '', 0660);
writeWithPerms($projectDir . '/.lock', '', 0660);

// design.md is a stub; the API will store the real one under files/design.md
writeWithPerms(
    $projectDir . '/design.md',
    "# $project\n\nProject design document. Replace this with the real plan.\n",
    0660
);

echo "Project created: $project\n";
echo "Owner secret:    $ownerSecret\n";
echo "Test with:\n";
echo "  curl -s -H 'X-Agent-Secret: $ownerSecret' \\\n";
echo "    https://sulfurous.aau.at/~collab/api/$project\n";

function writeWithPerms(string $path, string $content, int $mode): void {
    file_put_contents($path, $content);
    @chgrp($path, 'www-data');
    @chmod($path, $mode);
}
