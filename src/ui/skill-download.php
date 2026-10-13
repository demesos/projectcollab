<?php
/**
 * Streams the projectcollab.skill package, built fresh on every request from
 * the plain-text sources in data/skill-src/ with {{GUEST_WORKER_SECRET}}
 * substituted for this install's live secret (data/guest-worker.env).
 *
 * This replaces a previously-static, pre-built ui/projectcollab.skill binary.
 * That static copy had two problems: it had to be manually rebuilt and
 * redeployed any time the secret rotated or the docs changed (the same
 * drift risk as any other hand-copied artifact), and - because it sat under
 * ui/ with no .htaccess gate - it was downloadable by anyone unauthenticated,
 * secret included. This endpoint requires login and is always current.
 */

require '/home/collab/data/lib/lib.php';
require_login();

const SKILL_SRC_DIR = COLLAB_DATA_ROOT . '/skill-src';

function skill_build_zip(string $secret): string {
    $tmp = tempnam(sys_get_temp_dir(), 'skill_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create skill zip');
    }

    $zip->addEmptyDir('projectcollab');
    $zip->addEmptyDir('projectcollab/references');

    $files = [
        'SKILL.md'                      => 'SKILL.md',
        'platform-claudecode.md'        => 'platform-claudecode.md',
        'platform-chat.md'              => 'platform-chat.md',
        'references/api-spec.md'        => 'references/api-spec.md',
        'references/curl-recipes.md'    => 'references/curl-recipes.md',
        'references/guest-workers.md'   => 'references/guest-workers.md',
    ];
    foreach ($files as $rel => $entry) {
        $path = SKILL_SRC_DIR . '/' . $rel;
        if (!is_file($path)) throw new RuntimeException("Missing skill source file: $rel");
        $content = file_get_contents($path);
        $content = str_replace('{{GUEST_WORKER_SECRET}}', $secret, $content);
        $zip->addFromString('projectcollab/' . $entry, $content);
    }

    $zip->close();
    return $tmp;
}

$secret = guest_secret_ensure();
$tmpZip = skill_build_zip($secret);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="projectcollab.skill"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);
unlink($tmpZip);
