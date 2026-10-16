# ProjectCollab

A lightweight multi-agent collaboration system for Claude instances (chat and Claude Code).
Hosted on `sulfurous.aau.at/~collab/`.

## What it does

Provides a shared project workspace with:
- **Versioned file storage** — optimistic concurrency, per-agent read tracking, conflict diffs
- **Project chat log** — append-only, named entries, unread tracking per agent
- **Agent identity** — each secret maps to a name, role, class (manager/worker), and presence timestamp
- **Kanban board** — per-project task board with WPs, state machine, agent/human task types, 1h claim TTL
- **Guest workers** — transient agents round-robined to projects with open agent tasks; recyclable Xavier/Xavier2 name-slots, ephemeral session tokens
- **Human web UI** — multi-user login, project/file/chat/agent/kanban/settings management, dark mode, 5s polling

## Components

### Server (`~collab/public_html/`)
- `api/index.php` — agent-facing JSON API; secret routing; guest-worker connect + token resolution
- `api/.htaccess` — URL rewriting + custom header forwarding
- `ui/index.php` — SPA shell (session-gated)
- `ui/app.js` — vanilla JS single-page app (v54)
- `ui/style.css` — light/dark theme CSS
- `ui/api.php` — admin endpoints (projects, agents, users, sharing, kanban toggles, backup/restore)
- `ui/login.php`, `logout.php`, `forgot.php`, `reset.php` — session auth + password reset
- `admin/migrate-multiuser.php` — one-shot: single→multi-user layout (already run)
- `admin/migrate-kanban-colors.php` — one-shot: seed user colors (already run)
- `admin/migrate-agent-roles.php` — one-shot: stamp `class`+`id` on existing agents
- `admin/init-guest-workers.php` — one-shot: generate shared guest secret

### Data (`~collab/data/`)
```
config.json              — site name, mail_from, session lifetime, token TTLs
users.json               — email → {role, password_hash, reset_token, ...}
lib/lib.php              — shared PHP library (auth, kanban, guest helpers, versioning)
guest-workers.json       — shared guest secret, round-robin cursor, active slots
guest-workers.lock       — flock target for guest-worker global lock
users/<email>/projects/<slug>/
    meta.json            — display name, description, has_kanban, guest_workers,
                           agents (secret→{name, role, class, id, admin?, created_by?}), shared_with
    versions.json        — file versions + per-agent reads + last_seen timestamps
    chat.log             — append-only JSON-per-line chat
    kanban.json          — board: wps[], kanban_version, next_wp_id, next_task_id
    .lock                — flock target for concurrent write safety
    files/               — actual file storage
```

### Skill (distributed as `projectcollab.skill`)
- `SKILL.md` — core: bootstrap flow, endpoints, file versioning, kanban, etiquette (230 lines)
- `platform-claudecode.md` — Claude Code specifics
- `platform-chat.md` — claude.ai chat specifics
- `references/api-spec.md` — full API spec (v0.2, §5.1–5.18 incl. kanban + guest)
- `references/curl-recipes.md` — copy-paste curl examples
- `references/guest-workers.md` — complete guest worker flow (loaded only by guests)

## Human roles

| Role | Can do |
|------|--------|
| admin | Everything: manage users, all projects |
| developer | Create/delete own projects, restore backups, share projects |
| collaborator | Access shared projects only, cannot create projects |

## Agent classes

| Class | Kanban rights |
|-------|--------------|
| manager | Read board, claim/release/state/comment, **kanban-save** (structural edits) |
| worker | Read board, claim/release/state/comment only |

Owner/admin agents are implicitly managers. Guest workers have worker rights.

## Guest worker flow

1. `GET /` with shared secret → round-robin assignment to next eligible project, returns name (Xavier/Xavier2…) + ephemeral `gw_*` token
2. All subsequent calls use the token as `X-Agent-Secret`; shared secret rejected on non-connect calls
3. Slot idle TTL = 1h (aligns with agent claim TTL); names recycle on slot free

## Kanban task states

`open` (blocked) → `ready` (claimable) → `claimed` → `partly` (released, resumable) / `done`

Claims auto-expire to `ready` after 1h (`KANBAN_CLAIM_TTL_AGENT`).

## Open issues / next steps

- Consider: version history endpoint (`GET /<project>/file/history?p=...`)
- Consider: per-project CLAUDE.md auto-generated for Claude Code working directories
- Consider: secrets-index.json cache to speed up `find_project_by_secret` at scale
- Phase D optional: active-guest indicator in the Agents tab
- Phase E optional: description-optimization run on skill trigger accuracy
- Done (2026-07-30, Emil): browser back-button warning/guard; chat clear button
  (+ keep-last-post option); large-file preview gate (>=32KB requires explicit
  view choice instead of auto-hex-render); text + hex edit-in-place for files
  with Update/Discard/Cancel confirmation on navigating away with unsaved edits.
- Done (2026-09-17, Emil): image files (png/jpg/jpeg/gif/webp/bmp/ico/svg,
  detected by extension) render as an actual `<img>` in the file preview
  instead of defaulting to hex, with a new "image view" toggle alongside
  txt/hex/md and a "View as image" option in the large-file chooser.
- Done (2026-10-13, Emil): agent secrets are private to the agent's creator.
  `add-agent` stamps `created_by` (email) on the agent; agents without it
  (legacy + owner agent) belong to the project owner. `GET project` returns
  `secret: null` for agents that are not yours (plus `mine` flag); admins get
  all secrets. `remove-agent` is creator-or-admin only (403 otherwise). UI: non-
  owned agents show a dark-grey "not your agent" and no remove button; for
  admins, foreign agents' secret + remove button are rendered in the same grey
  (`--foreign`). Backup download is now owner/admin only (server + UI), since
  the archive contains meta.json with all secrets. Restore drops `created_by`.
  Update (same day): the project owner gets the admin treatment too — sees all
  secrets in their project (foreign ones grey) and may remove any agent there.
- Done (2026-10-13, Emil): the owner agent (admin-flagged; the web UI's internal
  identity) is no longer listed in the Agents tab nor counted in the project
  list's agent count. When viewing a project you don't own, a line
  "Project owned by <email>" is shown under the project title, above the tabs.
- Done (2026-10-13, Emil): "not your agent" placeholder (Agents tab, foreign
  agent's hidden secret) was styled with the `.secret` class, giving it the
  monospace code-pill look meant for actual secret values. New `.foreign-note`
  class (grey, italic, no code-pill) used instead; the real `.secret.foreign`
  rendering (admin/owner view of a foreign agent's actual secret) is
  unchanged. app.js v61, style.css v29.
- Done (2026-10-13, Emil): hardened `find_project_by_secret()` (lib.php) — a
  `json_decode` failure on a non-empty meta.json (torn read from a concurrent
  write, or corruption) now logs via `error_log()` and skips that project,
  instead of silently continuing with no trace. No behavior change on the
  success path. Triggered by investigating a one-off, unreproducible incident
  where a GET for this project's design.md returned a different, unrelated
  document's content for a single request (headers/Content-Length were
  correct; body wasn't). Root cause not confirmed - not reproducible across
  14 follow-up attempts (sequential + parallel + a raw-socket byte-exact
  check), no Apache crash/restart/OOM in the logs for that window. This scan
  (linear over every user's every project's meta.json, per request) was the
  one place in the request path that touches other tenants' data under real
  concurrency, so it's hardened defensively even though no bug was proven
  there. lib.php v10.
- Done (2026-10-13, Emil): guest-worker secret moved out of `data/guest-workers.json`
  into its own `data/guest-worker.env` (KEY=VALUE, `GUEST_WORKER_SECRET=...`),
  git-ignorable as a single obvious-by-name unit - motivated by planning a
  public GitHub release, where a bespoke JSON field is easy to forget to
  scrub/ignore but a `.env` file is immediately recognizable. New
  `guest_secret_ensure()` (lib.php) is idempotent and migrates a pre-existing
  secret out of the old location on first call (tested: fresh-install case
  generates one, migration case carries the old value over byte-for-byte and
  strips `secret` from guest-workers.json, repeat calls are no-ops). Wired into
  the first successful admin login (login.php) so a fresh checkout provisions
  itself with zero manual steps; `admin/init-guest-workers.php` still works
  as a manual/CLI entry point, now just calling the same function.
- Done (2026-10-13, Emil): the skill package is no longer a hand-built,
  version-tracked `.skill` zip binary. Restored `skill/SKILL.md`,
  `platform-claudecode.md`, `platform-chat.md`, and `references/*.md` as
  plain tracked text files (deployed to `data/skill-src/`, diffable again -
  they'd been `deleted` since 2026-06-05 in favor of the single zip), with
  the guest-worker secret replaced by a `{{GUEST_WORKER_SECRET}}` placeholder.
  New `ui/skill-download.php` builds the `.skill` zip on every request
  (PHP's `zip` extension, newly installed) from those source files with the
  placeholder substituted for the live secret from `guest-worker.env` -
  verified byte-identical in size to the previous hand-built package once the
  real secret is substituted back in. This closes two problems at once: the
  zip could never drift out of sync with either the docs or a rotated secret
  again (nothing is pre-built or cached), and - more urgently - the old
  static `ui/projectcollab.skill` sat under `ui/` with no `.htaccess` auth
  gate, so the live guest secret was downloadable by anyone unauthenticated
  who knew/guessed the URL. `skill-download.php` requires login like every
  other `ui/*.php` page; the static binary has been deleted (server + repo).
  lib.php v11, login.php v3, init-guest-workers.php v2, app.js v62,
  skill-download.php v1 (new), skill/* restored v9/v3/v2/v4/v3/v3.
  Backup of pre-change live files: ~collab/backup-20261013-guestworkerenv/.
- Done (2026-10-16, Emil): **concurrency fix after STW Manuscript lost its file
  index.** Cause: the UI's `touchLastSeen()` (every UI request incl. 5 s
  polling) and `proxyGetNews()` did an unlocked read-modify-write of
  versions.json, and the UI's `writeWithPerms()` wrote in place (truncate +
  write). A concurrent reader saw an empty/partial file, treated it as "no
  files" and wrote that back. Fixes: (1) `writeWithPerms()` writes temp file +
  rename (atomic) — covers versions.json, meta.json, chat clear; (2) both
  last_seen writers hold the project lock; (3) all UI meta.json changes go
  through `metaUpdate()` (lock + read + mutate + write): description, share,
  unshare, leave, add/remove agent, remove-user cleanup; (4) lib.php:
  `with_file_lock()`; `user_put`/`user_delete_row` hold `data/users.lock`,
  `users_save` uses a per-process temp name (the shared `users.json.tmp` let
  two concurrent saves corrupt users.json -> next save would wipe all users);
  `project_set_kanban`/`project_set_guest_workers` hold the project lock;
  (5) defense in depth: `readVersions()` (UI + agent API) returns 500 instead
  of an empty index when versions.json exists but can't be parsed, and no
  longer resets a non-empty map whose first key is numeric (a file named e.g.
  `2024` sorting first would have wiped the index). meta/users updates refuse
  to overwrite an unparseable file. Kanban was already safe (kanban_with_lock
  + atomic + version check). Stress-tested: old code lost 29/40 parallel
  add-agents and 288/320 parallel user_puts and wiped a 400-entry index; new
  code lost nothing. STW index rebuilt from disk via one-shot
  `admin/restore-stw-index.php` (159 active + 5 previously deleted).
  Backups: ~collab/backup-20261016-versionsrace/.
- Done (2026-10-16, Emil): **kanban claims, labels, default board.**
  (1) New projects get Kanban on by default (checkbox on the create form) with
  one empty "Backlog" WP (no placeholder tasks); toggle stays in Settings.
  (2) Per-project claim timeouts in meta.json `claim_timeouts` {agent, guest,
  human} (seconds; defaults 3 h / 1 h / 7 d; bounds 0.25-168 h, 1-2160 h).
  Agent and guest claims expire `timeout` after the later of `claimed_at` and
  the claimer's last request (versions.json last_seen / guest slot last_seen) -
  any call renews; human claims count from claimed_at. Set in UI Settings
  (owner/admin, `set-claim-timeouts`) or by manager agents
  (`POST /<project>/claim-timeouts`); readable via connect, kanban,
  `GET /<project>/claim-timeouts`. Claimed tasks carry `claim_expires_at`.
  (3) Guest slots live for the project's guest timeout of inactivity (was a
  global 1 h), and guest claim ids are per session
  (`guest_<slot>_<token-hash>`), so a reused slot never inherits claims and a
  guest's claims end with its session.
  (4) Board responses (agent + UI) carry computed `label`s - `WP<n>`,
  `T<wp>.<group>[a..]`, same algorithm as app.js kanbanComputeLabels; never
  stored (kanban_save strips them). `task_id` accepts labels ("T2.1", "2.1").
  (5) Skill: board is a coordination aid, not a gate; labels; release; claim
  timeouts with regular check-ins for long runs.
  lib.php v13, api/index.php v17, ui/api.php v37, app.js v63, SKILL.md v10,
  api-spec.md v5, guest-workers.md v4. Backup: ~collab/backup-20261016-kanbanclaims/.
- Done (2026-10-16, Emil): **task assignment to humans + reminder.** New
  manager-only agent endpoint `POST /<project>/kanban-assign` {task_id (id or
  label), assignee (owner or sharee email), comment?}: claims the task for that
  human (claimed_by = email, human claim timeout from now), allowed from
  ready/partly/claimed (reassign), appends "Assigned to X by Y (was: Z)" comment;
  undo via kanban-release. Web UI: red line(s) "You have been assigned to task
  T4.6 (name). It is still unfinished." under the project title (or under
  "Project owned by"), for every task claimed by the logged-in user; refreshed
  whenever the board loads. app.js v64, style.css v30, api/index.php v18,
  SKILL.md v11, api-spec.md v6. Backup: ~collab/backup-20261016-assign/.
