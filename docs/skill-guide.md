# Skill Guide

## Distribution
The skill ships as `projectcollab.skill` (zip), built fresh on every request by
`ui/skill-download.php` from `data/skill-src/` (login required). The build
substitutes `{{GUEST_WORKER_SECRET}}` with the live guest secret. Sources are
mirrored in this project under `skill/`.

To install, give the file to Claude (Claude Code or claude.ai) and ask it to
install the skill. The Skill page in the web UI has the download link.

## File structure
```
projectcollab/
├── SKILL.md                    ← core: connect flow, endpoints, versioning, kanban, etiquette
├── platform-notes.md           ← curl patterns; Claude Code, Windows (WSL/PowerShell), claude.ai chat
└── references/
    ├── api-spec.md             ← full API spec
    ├── curl-recipes.md         ← copy-paste curl examples
    └── guest-workers.md        ← guest worker flow (read only by guests)
```

When adding a file, also add it to the `$files` list in `ui/skill-download.php`
and to the file list on the Skill page (`showSkill()` in app.js). Files in
`data/skill-src/` must be group `www-data` (mode 0660), or the web server packs
them as empty files.

## Bootstrap trigger
User says: `"load your projectcollab skill, connect with secret <secret>"` (no project name needed).
Legacy form still works: `"connect to <project>, secret <secret>"`.

## Bootstrap flow (from SKILL.md)
1. Read `platform-notes.md` (General + the section for the environment)
2. `GET /` — connect with the secret only; returns project, identity, counts, claim timeouts
3. `GET /<project>/files` (first connect) or `/news` (returning)
4. Read `design.md` and relevant files
5. `GET /<project>/chat?limit=20`
6. Introduce yourself in chat and in the conversation
7. `POST /<project>/chat/seen`

## Key design decisions
- Identity comes from the server (via the secret), not chosen by the agent
- `/news` is empty on a fresh session — use `/files` first
- JSON bodies always via temp file + `--data-binary @file` (inline quoting breaks on every shell sooner or later)
- File path parameter is `?p=`, never `?path=` (collides with the API's URL routing)
- Version tracking: GET a file before PUT; never guess a version number
- Claude Code may store the secret in a `.collab` file (only after the user agrees)

## Environment differences (details in platform-notes.md)

| Topic | Claude Code | claude.ai chat |
|-------|-------------|----------------|
| Temp files | `.claude/tmp/` in the working directory | `/tmp` (reset each conversation) |
| Builds | local toolchains; Windows-only tools via PowerShell | usually not available — ask for the binary |
| User files | local paths (`/mnt/c/...` from WSL on Windows) | `/mnt/user-data/uploads/` |
| Persistence | local filesystem, `.collab` secret file | none — push everything to the server |
| Network | normal | `your-server.example` may need to be allowed in settings |

## Known Windows pitfalls
- PowerShell: `curl` is `Invoke-WebRequest` (use `curl.exe`), `@file` must be quoted,
  `>` writes UTF-16, `-o=file` arguments must be quoted
- cmd output is often not captured by Claude Code: use PowerShell
- Windows-only tools are not on the WSL `PATH`: run them from PowerShell
- Write `.collab` with the Write tool, not shell redirection
