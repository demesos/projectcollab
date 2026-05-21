# ProjectCollab

A lightweight multi-agent collaboration system for Claude instances (chat and Claude Code).
Hosted on `sulfurous.aau.at/~collab/`.

## What it does

Provides a shared project workspace with:
- **Versioned file storage** — optimistic concurrency, per-agent read tracking, conflict diffs
- **Project chat log** — append-only, named entries, unread tracking per agent
- **Agent identity** — each secret maps to a name, role, and presence timestamp
- **Human web UI** — multi-user login, project/file/chat/agent/share management, dark mode, 5s polling

## Components

### Server (`~collab/public_html/`)
- `api/index.php` — agent-facing JSON API; secret-only routing (no project name in URL needed)
- `api/.htaccess` — URL rewriting + custom header forwarding
- `ui/index.php` — SPA shell (session-gated, exposes CURRENT_USER to JS)
- `ui/app.js` — vanilla JS single-page app
- `ui/style.css` — light/dark theme CSS
- `ui/api.php` — admin endpoints for UI (projects, agents, users, sharing, backup/restore)
- `ui/login.php`, `logout.php`, `forgot.php`, `reset.php` — session auth + password reset
- `ui/.htaccess` — session auth (HTTP Basic auth removed)
- `admin/migrate-multiuser.php` — one-shot migration from single-user to multi-user layout (already run)

### Data (`~collab/data/`)
```
config.json              — site name, mail_from, session lifetime, token TTLs
users.json               — email → {role, password_hash, reset_token, ...}
lib/lib.php              — shared PHP library (auth, path resolution, mail, helpers)
users/<email>/projects/<slug>/
    meta.json            — display name, description, agents (secret→name/role/admin), shared_with
    versions.json        — file versions + per-agent reads + last_seen timestamps
    chat.log             — append-only JSON-per-line chat
    .lock                — flock target for concurrent write safety
    files/               — actual file storage
```

### Skill (`~/.claude/skills/projectcollab/` or `/mnt/skills/user/projectcollab/` on client machines)
- `SKILL.md` — platform-agnostic core (bootstrap flow, endpoints, versioning, etiquette)
- `platform-claudecode.md` — Claude Code specifics (curl patterns, binary upload, build tools)
- `platform-chat.md` — claude.ai chat specifics (sandbox notes, skills path, memory caveat)
- `references/api-spec.md` — full API specification
- `references/curl-recipes.md` — copy-paste curl examples

## Roles

| Role | Can do |
|------|--------|
| admin | Everything: manage users, all projects |
| developer | Create/delete own projects, restore backups, share projects |
| collaborator | Access shared projects only, cannot create projects |

## Open issues / next steps

- Consider: version history endpoint (`GET /<project>/file/history?p=...`)
- Consider: per-project CLAUDE.md auto-generated for Claude Code working directories
- Consider: secrets-index.json cache to speed up `find_project_by_secret` at scale
