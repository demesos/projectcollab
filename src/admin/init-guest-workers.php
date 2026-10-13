<?php
/**
 * Initialise the guest-worker system: create data/guest-worker.env with a
 * freshly generated shared guest secret, if it does not already exist.
 * Also runs automatically on the first admin login (login.php) - this script
 * remains as a manual/CLI convenience to provision or inspect it on demand.
 *
 * Idempotent: if a secret already exists, it is left untouched and printed
 * (CLI) so it can be handed to guest operators.
 *
 * Run as: sudo -u www-data php /home/collab/public_html/admin/init-guest-workers.php
 * Or once via URL (admin-gated):
 *   curl -b "session=..." https://sulfurous.aau.at/~collab/admin/init-guest-workers.php
 */

require '/home/collab/data/lib/lib.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $user = require_role('admin');
    header('Content-Type: text/plain; charset=utf-8');
}

$already = guest_secret() !== null;
$secret = guest_secret_ensure();

if ($already) {
    echo "Guest workers already initialised.\n";
    if ($isCli) echo "Shared guest secret: $secret\n";
    else echo "Shared guest secret is set (run from CLI to view it).\n";
    exit;
}

echo "Guest workers initialised.\n";
echo "Shared guest secret: $secret\n";
echo "\nHand this secret to guest operators. A guest connects with:\n";
echo "  curl -H 'X-Agent-Secret: $secret' https://sulfurous.aau.at/~collab/api/\n";
echo "and receives a session token + project assignment.\n";
