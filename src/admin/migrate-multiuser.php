#!/usr/bin/env php
<?php
/**
 * ProjectCollab multi-user migration.
 *
 * Run ONCE as root:
 *   php /home/collab/public_html/admin/migrate-multiuser.php
 *
 * Moves data/projects/* into data/users/<admin-email>/projects/* and
 * creates data/users.json with the admin user.
 */

declare(strict_types=1);
require '/home/collab/data/lib/lib.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

echo "ProjectCollab — multi-user migration\n";
echo "====================================\n\n";

$oldProjectsDir  = '/home/collab/data/projects';
$newProjectsRoot = COLLAB_USERS_ROOT;

// 1. Sanity checks
if (!is_dir($oldProjectsDir)) {
    fwrite(STDERR, "ERROR: $oldProjectsDir not found. Already migrated?\n");
    exit(1);
}
if (is_dir($newProjectsRoot)) {
    $existing = array_filter(scandir($newProjectsRoot), fn($f) => $f !== '.' && $f !== '..');
    if (!empty($existing)) {
        fwrite(STDERR, "ERROR: $newProjectsRoot already populated. Aborting.\n");
        fwrite(STDERR, "If redoing migration, remove that directory first.\n");
        exit(1);
    }
}
if (is_file(COLLAB_USERS_PATH)) {
    fwrite(STDERR, "ERROR: " . COLLAB_USERS_PATH . " already exists. Aborting.\n");
    exit(1);
}

$projects = array_values(array_filter(
    scandir($oldProjectsDir),
    fn($f) => $f !== '.' && $f !== '..' && is_dir("$oldProjectsDir/$f")
));
echo "Found " . count($projects) . " project(s): " . implode(', ', $projects) . "\n\n";

// 2. Prompt for admin email + password
echo "Admin email (becomes the login username): ";
$email = trim((string) fgets(STDIN));
if (!valid_email($email)) {
    fwrite(STDERR, "Invalid email format.\n");
    exit(1);
}

echo "Password for $email: ";
system('stty -echo');
$pw1 = trim((string) fgets(STDIN));
echo "\nConfirm password: ";
$pw2 = trim((string) fgets(STDIN));
system('stty echo');
echo "\n";
if ($pw1 !== $pw2) { fwrite(STDERR, "Passwords do not match.\n"); exit(1); }
if (strlen($pw1) < 8)  { fwrite(STDERR, "Password must be at least 8 characters.\n"); exit(1); }

// 3. Write/check config.json
if (!is_file(COLLAB_CONFIG_PATH)) {
    echo "\nWriting default config.json...\n";
    $defaultCfg = [
        'site_name'                 => 'ProjectCollab',
        'site_url'                  => 'https://sulfurous.aau.at/~collab/ui/',
        'mail_from'                 => $email,
        'mail_from_name'            => 'ProjectCollab',
        'session_lifetime_days'     => 7,
        'reset_token_ttl_minutes'   => 60,
        'rate_limit_reset_minutes'  => 5,
    ];
    file_put_contents(COLLAB_CONFIG_PATH, json_encode($defaultCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod(COLLAB_CONFIG_PATH, 0660);
    @chgrp(COLLAB_CONFIG_PATH, 'www-data');
}

// 4. Confirm
echo "\nReady to migrate:\n";
echo "  - Move:   $oldProjectsDir/*\n";
echo "  - To:     $newProjectsRoot/$email/projects/\n";
echo "  - Create: " . COLLAB_USERS_PATH . " (admin: $email)\n";
echo "\nProceed? [yes/no] ";
$answer = strtolower(trim((string) fgets(STDIN)));
if ($answer !== 'yes') { echo "Aborted.\n"; exit(0); }

// 5. Make directories
$userBase = "$newProjectsRoot/$email";
$userProjectsDir = "$userBase/projects";
if (!is_dir($userProjectsDir)) {
    mkdir($userProjectsDir, 0770, true);
}
foreach ([$newProjectsRoot, $userBase, $userProjectsDir] as $d) {
    @chgrp($d, 'www-data');
    @chmod($d, 0770);
}

// 6. Move projects
foreach ($projects as $p) {
    $from = "$oldProjectsDir/$p";
    $to   = "$userProjectsDir/$p";
    echo "  Moving $p... ";
    if (!rename($from, $to)) {
        echo "FAILED\n";
        exit(1);
    }
    // Reset group recursively
    system('chgrp -R www-data ' . escapeshellarg($to));
    echo "OK\n";
}

// 7. Write users.json
$users = [
    'users' => [
        $email => [
            'role'                  => 'admin',
            'password_hash'         => user_password_hash($pw1),
            'created'               => now_iso(),
            'last_login'            => null,
            'reset_token'           => null,
            'reset_token_expires'   => null,
            'reset_token_requested' => null,
        ],
    ],
];
users_save($users);
echo "\nusers.json created.\n";

// 8. Permission housekeeping on data/lib
@chgrp('/home/collab/data/lib', 'www-data');
@chmod('/home/collab/data/lib', 0750);
foreach (glob('/home/collab/data/lib/*.php') as $f) {
    @chgrp($f, 'www-data');
    @chmod($f, 0640);
}

echo "\n=== Migration complete ===\n";
echo "\nLeft empty at $oldProjectsDir/ — remove with:\n";
echo "  rmdir $oldProjectsDir\n";
echo "\nLog in at: " . cfg_get('site_url', 'https://sulfurous.aau.at/~collab/ui/') . "\n";
echo "Username:  $email\n";
echo "Role:      admin\n";
