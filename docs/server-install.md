# Server Installation Guide

State as of 2026-11-04 (checked on the server).

## Server
`your-server.example` — Ubuntu, Apache 2.4 with mod_php (PHP 8.4), mod_userdir, mod_rewrite.
PHP always runs as **`www-data`**, regardless of who owns the PHP file.

## Users and paths

```
System user:  collab  (normal shell; member of group www-data; no sudo)

/home/collab/public_html/          → https://your-server.example/~collab/
  index.html                         redirect to ui/
  api/                               agent API (index.php + .htaccess rewrite → index.php?path=…)
  ui/                                web UI: index.php (SPA shell), app.js, style.css, api.php,
                                     login/logout/forgot/reset.php, skill-download.php, favicon.svg

/home/collab/data/                 (not web-reachable)
  config.json                        site config, see "Config"
  users.json, users.lock             user accounts (roles admin / developer / collaborator)
  remember_tokens.json               "remember me" login tokens
  sessions/                          PHP sessions (www-data only)
  lib/lib.php                        shared PHP library, included by all PHP entry points
  skill-src/                         skill sources; ui/skill-download.php zips them on request
  guest-worker.env                   shared guest-worker secret ({{GUEST_WORKER_SECRET}} in the skill)
  guest-workers.json, .lock          guest slots / round-robin state
  users/<email>/projects/<slug>/     one folder per project:
      meta.json                        name, description, agents (secret → name/role/class), sharing
      versions.json                    file versions, tombstones, per-agent reads, last_seen
      kanban.json                      board
      chat.log                         chat (one JSON object per line)
      .lock                            flock target; every read-modify-write holds it
      files/                           file contents (deleted files: content removed, tombstone kept)
  projects/                          legacy location before multi-user (May 2026), empty
```

URLs: API `~collab/api/`, UI `~collab/ui/` (PHP session login; no HTTP Basic auth).

## Permissions — what actually matters

| Path | Typical owner:group | Mode | Note |
|---|---|---|---|
| `data/` and its top-level files | collab:www-data or www-data:www-data | 770 / 660 | must be readable+writable by group www-data |
| `data/lib/lib.php` | collab:www-data | 640 | |
| `data/skill-src/**` | collab:www-data | 660 | a file not readable by www-data is packed as an **empty** file |
| `data/users/<email>/…` | www-data:www-data | 770 or **750** | created by PHP; with 750 the collab account can read but **not modify or delete** |
| `public_html/**` | collab:collab | 644 (dirs 755) | world-readable is enough |

Consequences:
- Files written by an editor or agent tool as collab get group `collab` — run
  `chgrp www-data <file>; chmod 0660 <file>` for anything under `data/`.
- The collab account cannot change project data directly (no sudo). Data migrations must
  run as www-data, e.g. as an admin-gated PHP script under `public_html/admin/` opened in
  the browser while logged in as admin (suggested pattern: dry run by default, `?apply=1`
  to apply). The repository ships none.

## Apache requirements
- mod_userdir and mod_rewrite enabled; `AllowOverride FileInfo AuthConfig Limit Indexes` for userdirs (userdir.conf)
- mod_userdir blocks PUT/DELETE for the UI, so the UI tunnels them as POST with
  `X-HTTP-Method-Override` (handled in ui/api.php). The agent API allows them via
  `<Limit>` in `api/.htaccess`
- `api/.htaccess` routes everything to `index.php?path=$1` — this is why the file
  parameter is `?p=` and never `?path=`; it also copies the `X-Agent-Secret`,
  `X-Expected-Version` and `X-New-File` headers into the environment

## Deploying changes

Agents work directly on the server (Claude Code running as collab). For every change:

1. **Back up** the live files you will replace: `~/backup-YYYYMMDD-<topic>/`
   (Wil decides when backups are deleted).
2. **Test first** when the change is risky: a temporary copy of the instance
   (`public_html/ui-test`, `api-test`, separate `data-test` with its own lib.php copy,
   session cookie name and test login) keeps live data untouched. Remove it afterwards;
   its www-data-owned folders have to be deleted through the test instance itself.
3. **Check syntax**: `php -l file.php`, `node --check app.js`.
4. **Replace atomically**: copy to a temp name in the same folder, `chmod`, then `mv`
   over the live file, so Apache never serves a half-written file.
5. **Verify**: fetch the live URL and compare with the intended file; smoke-test
   `GET /~collab/api/` with an agent secret and the login page.
6. **Mirror** the changed files into the collab project (`src/…`, `skill/…`).

Project ↔ server mapping: `src/api/*` → `public_html/api/`, `src/ui/*` → `public_html/ui/`,
`src/lib/lib.php` → `data/lib/lib.php`,
`skill/*` → `data/skill-src/`.

## Everyday operations
- **New project:** UI → Projects → create (sets up meta, versions, kanban, owner agent).
- **New user:** UI → Users (admin) → "+ New user"; set a password or send an invite
  (invite link valid 3 days by default, password-reset link 60 minutes). Mail is sent
  with PHP `mail()` from `mail_from`.
- **Backup / restore a project:** download a tar.gz in Project → Settings (owner/admin);
  restore via "↩ Restore backup" on the Projects page. The archive contains
  `meta.json` with all agent secrets.

## Config (`data/config.json`)
```json
{
  "site_name":                "ProjectCollab",
  "site_url":                 "https://your-server.example/~collab/ui/",
  "mail_from":                "…",
  "mail_from_name":           "ProjectCollab",
  "session_lifetime_days":    7,
  "reset_token_ttl_minutes":  60,
  "rate_limit_reset_minutes": 5,
  "invite_token_ttl_minutes": 4320
}
```
`invite_token_ttl_minutes` is optional (default 4320 = 3 days).

## Known gotchas
- `?p=` not `?path=` for the file path parameter (rewrite rule)
- File names: letters a–z, digits, spaces and `. _ - ( ) + ! ' , @ = ~ /` only; no leading `/`,
  no name starting with `~`, no `..`, max 200 characters (`MAX_PATH_LEN`) — the same rules in `validateFilePath` (api/index.php,
  ui/api.php) and `filePathProblem` (app.js); change all three together
- PUT/DELETE need `X-Expected-Version` (or a prior GET that registered the read)
- File size limit 200 MB: `MAX_FILE_BYTES` in api/index.php and ui/api.php, `MAX_UPLOAD_BYTES`
  in app.js — change all three together. Uploads are streamed to a temp file
  (`<project>/.upload.*`), so PHP's `memory_limit` (128M) does not limit file size. PHP
  settings (php.ini 8.4, not changeable by collab): `memory_limit 128M`,
  `post_max_size 8M` (does not apply to the raw upload bodies), `upload_max_filesize 2M`
  (only multipart forms; since 2026-11-06 no upload uses them). "Restore backup" accepts up
  to 2 GB (`MAX_RESTORE_BYTES` in ui/api.php and app.js)
- Every read-modify-write of meta.json / versions.json / kanban.json must hold the project
  `.lock`; never write back an index that failed to parse
- `exit` (also inside `respond_json`) skips PHP `finally` blocks — clean up temp files with
  `register_shutdown_function`
- `find_project_by_secret()` scans all projects linearly — fine at current scale
