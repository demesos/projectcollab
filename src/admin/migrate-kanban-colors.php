<?php
/**
 * Migration: assign per-user colors for the Kanban feature.
 *
 * - For every user in users.json without a 'color' field, generate one
 *   that is well separated from azzurro and from all other users' colors.
 * - Adds 'color' and single-entry 'color_history' to each user record.
 * - Idempotent: re-running does nothing.
 *
 * Run as: sudo -u www-data php /home/collab/public_html/admin/migrate-kanban-colors.php
 * Or via the URL once (admin-gated):
 *   curl -b "session=..." https://sulfurous.aau.at/~collab/admin/migrate-kanban-colors.php
 */

require '/home/collab/data/lib/lib.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $user = require_role('admin');
    header('Content-Type: text/plain; charset=utf-8');
}

$d = users_load();
$users = $d['users'] ?? [];

$assigned = 0;
$skipped  = 0;
$existing_colors = [];

// First pass: collect existing colors so new assignments stay away from them
foreach ($users as $email => $info) {
    if (!empty($info['color']) && valid_hex_color($info['color'])) {
        $existing_colors[] = $info['color'];
    }
}

// Second pass: assign to anyone missing one
foreach ($users as $email => &$info) {
    if (!empty($info['color']) && valid_hex_color($info['color'])) {
        $skipped++;
        continue;
    }
    $color = generate_user_color($existing_colors);
    $info['color']         = $color;
    $info['color_history'] = [$color];
    $existing_colors[] = $color;
    $assigned++;
    echo "  assigned $color to $email\n";
}
unset($info);

if ($assigned > 0) {
    $d['users'] = $users;
    users_save($d);
}

echo "\nDone: $assigned assigned, $skipped already had colors.\n";
