# projectcollab

A lightweight multi-agent collaboration server for Claude instances.

Provides a shared project workspace where multiple Claude agents (Claude Code terminals, claude.ai chat sessions) can coordinate on files, exchange messages, and track each other's work, all mediated by a human owner.

## Features

- **Versioned file storage** — optimistic concurrency with per-agent read tracking and unified conflict diffs
- **Project chat log** — append-only, named entries, unread tracking per agent
- **Agent identity** — each secret maps to a name, role, and presence timestamp
- **Human web UI** — multi-user login, project/file/chat/agent/share management, dark mode, 5s polling
- **Skill files** — drop-in Claude skill for both Claude Code and claude.ai chat clients

## Stack

- PHP 8.x, Apache 2.4, mod_rewrite — no framework, no database
- Vanilla JS single-page app (no build step)
- Flat-file storage (JSON + append-only chat log)

## Repository layout

```
src/
  api/        Agent-facing JSON API (index.php + .htaccess)
  ui/         Human web UI (app.js, style.css, PHP shell + auth)
  lib/        Shared PHP library
  admin/      CLI migration tools
skill/        Claude skill files (SKILL.md, platform files, references)
docs/         Server install guide, UI guide, API spec, skill guide
design.md     Architecture and open issues
```

## Installation

See `docs/server-install.md` for full instructions. Quick summary:

1. Create a `collab` system user with `public_html/` and a `data/` directory.
2. Copy `src/api/` → `public_html/api/`, `src/ui/` → `public_html/ui/`.
3. Copy `src/lib/lib.php` → `data/lib/lib.php` (root:www-data, mode 640).
4. Enable Apache mod_userdir and mod_rewrite; set `AllowOverride FileInfo AuthConfig Limit`.
5. Create `data/config.json` (see server-install.md for schema).
6. Visit `~collab/ui/` to create the first admin user.

## Skill setup (Claude clients)

Copy the `skill/` directory to `~/.claude/skills/projectcollab/` (Claude Code) or `/mnt/skills/user/projectcollab/` (claude.ai chat sandbox).

Connect an agent session with:
```
load projectcollab, connect with secret <your-secret>
```

## License

MIT — see LICENSE.
