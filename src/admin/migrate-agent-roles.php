<?php
/**
 * Migration: stamp `class` and `id` onto every existing agent record.
 *
 * For every agent in every project's meta.json:
 *   - if it has no `id`,    assign a fresh stable `ag_*` id (gen_agent_id()).
 *   - if it has no `class`, set it to 'manager' (Wil's call: existing agents
 *     keep maximum capability; least surprise).
 *
 * Idempotent: re-running only touches agents still missing a field.
 * No behavior change on its own — the class/id fields are not consulted until
 * the agent kanban endpoints (Phase B) and guest workers (Phase C) land.
 *
 * Run as: sudo -u www-data php /home/collab/public_html/admin/migrate-agent-roles.php
 * Or once via URL (admin-gated):
 *   curl -b "session=..." https://sulfurous.aau.at/~collab/admin/migrate-agent-roles.php
 */

require '/home/collab/data/lib/lib.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $user = require_role('admin');
    header('Content-Type: text/plain; charset=utf-8');
}

$root = COLLAB_USERS_ROOT;
if (!is_dir($root)) {
    echo "No users root at $root\n";
    exit;
}

$projectsTouched = 0;
$agentsStamped   = 0;
$agentsSkipped   = 0;

foreach (scandir($root) as $owner) {
    if ($owner === '.' || $owner === '..') continue;
    if (!valid_email($owner)) continue;
    $projRoot = "$root/$owner/projects";
    if (!is_dir($projRoot)) continue;

    foreach (scandir($projRoot) as $proj) {
        if ($proj === '.' || $proj === '..') continue;
        if (!valid_project_slug($proj)) continue;
        $dir = "$projRoot/$proj";
        if (!is_file("$dir/meta.json")) continue;

        $meta = meta_load($dir);
        if (empty($meta['agents']) || !is_array($meta['agents'])) continue;

        $changed = false;
        foreach ($meta['agents'] as $secret => &$info) {
            if (!is_array($info)) continue;
            $before = $info;
            if (empty($info['id'])) {
                $info['id'] = gen_agent_id();
            }
            if (empty($info['class']) || !valid_agent_class($info['class'])) {
                $info['class'] = 'manager';
            }
            if ($info !== $before) {
                $agentsStamped++;
                $changed = true;
                $name = $info['name'] ?? '(unnamed)';
                echo "  $owner/$proj: $name -> class={$info['class']} id={$info['id']}\n";
            } else {
                $agentsSkipped++;
            }
        }
        unset($info);

        if ($changed) {
            meta_save($dir, $meta);
            $projectsTouched++;
        }
    }
}

echo "\nDone: $agentsStamped agents stamped across $projectsTouched project(s), "
   . "$agentsSkipped already complete.\n";
