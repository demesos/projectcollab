# Server Installation Guide

## Server
`sulfurous.aau.at` — Ubuntu, Apache 2.4, PHP 8.x (mod_php), runs as `www-data`.

## Users and paths
```
System user:    collab  (shell: /usr/sbin/nologin)
Public files:   /home/collab/public_html/
  api/          agent-facing JSON API
  ui/           human web UI (session-based login)
  admin/        CLI tools (migrate-multiuser.php)
Data:           /home/collab/data/
  config.json         site config (mail_from, session TTL, etc.)
  users.json          user accounts
  lib/lib.php         shared PHP library
  users/<email>/      per-user project storage
```

URL base: `https://sulfurous.aau.at/~collab/`
- API: `~collab/api/`
- UI:  `~collab/ui/`  (session login — no HTTP Basic auth)

## File permissions (critical)
PHP runs as `www-data`. All data files must be group `www-data`.

| Type | Owner | Group | Mode |
|------|-------|-------|------|
| Data directories | collab | www-data | 770 |
| Data files (json, log, lock) | collab | www-data | 660 |
| lib/lib.php | root | www-data | 640 |
| Public PHP/JS/CSS | collab | www-data | 644 |
| Shared library dir | root | www-data | 750 |

## Apache requirements
- mod_userdir, mod_rewrite enabled
- `AllowOverride FileInfo AuthConfig Limit` in userdir.conf

## Updating a single file

When Berta or another agent produces a single updated file, download it and run the matching `install` command. The agent will tell you the exact command — but here is the mapping for reference:

| File produced | Install command |
|---|---|
| `app_vN.js` | `install -o collab -g www-data -m 644 app_vN.js /home/collab/public_html/ui/app.js` |
| `ui_api_vN.php` | `install -o collab -g www-data -m 644 ui_api_vN.php /home/collab/public_html/ui/api.php` |
| `ui_index_vN.php` | `install -o collab -g www-data -m 644 ui_index_vN.php /home/collab/public_html/ui/index.php` |
| `style_vN.css` | `install -o collab -g www-data -m 644 style_vN.css /home/collab/public_html/ui/style.css` |
| `api_index_vN.php` | `install -o collab -g www-data -m 644 api_index_vN.php /home/collab/public_html/api/index.php` |
| `favicon.svg` | `install -o collab -g www-data -m 644 favicon.svg /home/collab/public_html/ui/favicon.svg` |
| `lib_vN.php` | `install -o root -g www-data -m 640 lib_vN.php /home/collab/data/lib/lib.php` |
| `login.php` / `forgot.php` / `reset.php` | `install -o collab -g www-data -m 644 <file> /home/collab/public_html/ui/<file>` |

After installing, verify with `md5sum` — the agent always states the expected hash.

**Tip:** the agents use versioned filenames (e.g. `app_v7.js`) so a downloaded file never silently overwrites a staging copy you haven't checked yet.

## Deploy workflow

Files arrive in `/home/wilfried/`. Run all commands as root.
Use `install` — it copies, sets owner, and sets permissions atomically.

```bash
cd /home/wilfried

# Agent API
install -o collab -g www-data -m 644 api_index.php  /home/collab/public_html/api/index.php

# UI files
install -o collab -g www-data -m 644 app.js         /home/collab/public_html/ui/app.js
install -o collab -g www-data -m 644 style.css      /home/collab/public_html/ui/style.css
install -o collab -g www-data -m 644 ui_index.php   /home/collab/public_html/ui/index.php
install -o collab -g www-data -m 644 ui_api.php     /home/collab/public_html/ui/api.php
install -o collab -g www-data -m 644 login.php      /home/collab/public_html/ui/login.php
install -o collab -g www-data -m 644 logout.php     /home/collab/public_html/ui/logout.php
install -o collab -g www-data -m 644 forgot.php     /home/collab/public_html/ui/forgot.php
install -o collab -g www-data -m 644 reset.php      /home/collab/public_html/ui/reset.php
install -o collab -g www-data -m 644 favicon.svg    /home/collab/public_html/ui/favicon.svg

# Shared library (root-owned, www-data readable)
install -o root -g www-data -m 640 lib.php  /home/collab/data/lib/lib.php

# Clean staging copies (adjust to match what you actually deployed)
rm /home/wilfried/*.php /home/wilfried/*.js /home/wilfried/*.css /home/wilfried/*.svg 2>/dev/null
```

**Notes**
- `~` resolves to `/root` when running as root — always use full paths.
- Browser caches `app.js` aggressively: use Ctrl+Shift+R after UI changes.
- `api.php` in the outputs is `ui/api.php` (admin API), not `api/index.php` (agent API) — check filenames carefully.
- Partial deploys: run only the relevant `install` lines.

## Creating a new project
Via the UI at `~collab/ui/` — creates all files and sets permissions correctly.

## Adding a new user
Via the UI → Users page (admin only) → "+ New user". Set a password or send an invite link by email. Invite links are valid for 3 days; password-reset links for 60 minutes.

## Config tuning (`/home/collab/data/config.json`)
```json
{
  "site_name":                "ProjectCollab",
  "site_url":                 "https://sulfurous.aau.at/~collab/ui/",
  "mail_from":                "wilfried.elmenreich@aau.at",
  "mail_from_name":           "ProjectCollab",
  "session_lifetime_days":    7,
  "reset_token_ttl_minutes":  60,
  "invite_token_ttl_minutes": 4320
}
```

## Known gotchas
- `?p=` not `?path=` for file path param (`?path=` conflicts with the rewrite rule)
- PUT/DELETE require `X-Expected-Version` or a prior GET to register the read counter
- `declare(strict_types=1)` throughout — PHP 7+ required (PHP 8 on this server)
- mod_php means PHP always runs as `www-data` regardless of file owner
- `find_project_by_secret()` does a linear scan — fine at current scale
