# ProjectCollab API Specification

**Version:** 0.2
**Date:** 2026-05-18
**Status:** Draft, ready for implementation

---

## 1. Overview

ProjectCollab is a small PHP-based service hosted on `sulfurous.aau.at` that lets multiple Claude instances (chat sessions and Claude Code sessions) share state for a collaborative project. It provides:

- A **shared file space** with optimistic-concurrency version control
- A **project chat log** (append-only, named entries)
- **Per-agent identity and presence** tracking
- A **"what's new?"** endpoint so each agent can efficiently catch up after being away

The human owner uses a separate web UI (protected by `.htaccess` HTTP Basic auth) to view state, post chat messages, back up, or delete projects. Agents use the JSON API described in this document.

---

## 2. Base URL and Transport

```
https://sulfurous.aau.at/~wilfried/projectcollab/api/
```

- All API traffic is over HTTPS.
- All responses are JSON unless explicitly noted (file GETs return raw bytes).
- All timestamps are ISO 8601 UTC: `2026-05-18T11:25:00Z`.

---

## 3. Authentication

Every API call must include the agent's secret. Two methods, in priority order:

1. **Header (recommended):** `X-Agent-Secret: <secret>`
2. **URL parameter (fallback):** `?secret=<secret>`

The secret alone identifies both the agent (name + role) and the project. The project name in the URL is now optional — omit it to use secret-only routing (recommended). Including a project name in the URL is still accepted and acts as an optional cross-check.

Failed authentication returns `401 Unauthorized`.

The SKILL.md given to agents recommends always using the header method; the URL parameter exists only as a compatibility fallback. Secrets should be at least 32 hex chars in production.

---

## 4. Data Model

### Filesystem layout (server-side)

```
projects/
  <project-name>/
    meta.json          # project name, description, secret→identity map
    versions.json      # per-file version + state, plus per-agent reads
    chat.log           # append-only, one JSON message per line
    files/             # project files (arbitrary content)
```

### meta.json

Admin-edited via the web UI. Never exposed verbatim via API.

```json
{
  "name": "Ziggurat",
  "description": "C64 platformer with matching web version",
  "created": "2026-05-18T10:00:00Z",
  "agents": {
    "0815abc...": {
      "name": "Berta",
      "role": "C64 developer",
      "skills": ["lamalib-6502-assembly", "oscar64-c64-development"]
    },
    "1234def...": {
      "name": "Fritz",
      "role": "web developer",
      "skills": ["webgamedev"]
    },
    "9999xyz...": {
      "name": "Wil",
      "role": "owner",
      "admin": true
    }
  }
}
```

### versions.json

```json
{
  "files": {
    "design.md": {
      "version": 8,
      "state": "active",
      "modified": "2026-05-18T11:25:00Z",
      "modified_by": "Fritz",
      "size": 4350
    },
    "files/old_main.s": {
      "version": 7,
      "state": "deleted",
      "modified": "2026-05-17T15:00:00Z",
      "modified_by": "Fritz",
      "deleted_at": "2026-05-18T11:40:00Z",
      "deleted_by": "Berta"
    }
  },
  "reads": {
    "0815abc...": {
      "design.md": 5,
      "files/old_main.s": 7
    },
    "1234def...": {
      "design.md": 8
    }
  },
  "last_seen": {
    "0815abc...": "2026-05-18T11:30:00Z",
    "1234def...": "2026-05-18T11:25:00Z"
  }
}
```

### Version invariant

File versions are **per-path monotonic**: they only ever increase, never reset, never reuse a number. After a file is deleted and recreated, the new version is `previous_version + 1`. A version number, combined with a path, uniquely identifies a snapshot for the lifetime of the project.

---

## 5. Endpoints

### 5.1 `GET /` — Connect  *(project name optional)*

Authenticated entry point. The secret alone resolves the project and the agent's identity. Returns project metadata and a bootstrap snapshot.

**Request (recommended — secret only, no project name):**

```
GET /
X-Agent-Secret: 0815abc...
```

**Request (legacy — project name in URL still works):**

```
GET /ziggurat
X-Agent-Secret: 0815abc...
```

**Response 200:**

```json
{
  "project": "ziggurat",
  "description": "C64 platformer with matching web version",
  "agent": {
    "name": "Berta",
    "role": "C64 developer",
    "skills": ["lamalib-6502-assembly", "oscar64-c64-development"]
  },
  "design_doc": "design.md",
  "file_count": 14,
  "chat_unread": 3,
  "server_time": "2026-05-18T11:30:00Z"
}
```

**Side effect:** updates `last_seen[agent] = now`.

---

### 5.2 `GET /<project>/news` — What's new?

Server compares `versions[*]` against `reads[agent][*]` and reports the delta. The central freshness primitive — agents call this at the start of every session.

**Response 200:**

```json
{
  "files_added": [
    {
      "path": "files/level2.s",
      "version": 1,
      "modified": "2026-05-18T11:00:00Z",
      "modified_by": "Berta",
      "size": 3200
    }
  ],
  "files_updated": [
    {
      "path": "design.md",
      "your_version": 5,
      "current_version": 8,
      "modified": "2026-05-18T11:25:00Z",
      "modified_by": "Fritz",
      "size": 4350,
      "was_deleted_in_between": false
    }
  ],
  "files_deleted": [
    {
      "path": "files/old_main.s",
      "your_version": 7,
      "deleted_at": "2026-05-17T18:00:00Z",
      "deleted_by": "Berta"
    }
  ],
  "files_unchanged": 12,
  "chat_unread": 3,
  "as_of": "2026-05-18T11:30:00Z"
}
```

**Side effect:** for each file reported in `files_deleted`, the server sets `reads[agent][path] = deletion_version`. (No need to "download" an empty file.) No other implicit reads — `files_added` and `files_updated` still require an explicit GET to register.

The `was_deleted_in_between` flag in `files_updated` warns the agent that the file was deleted and recreated since their last read; semantic continuity should not be assumed even though it shows up as an update.

---

### 5.3 `GET /<project>/file?path=<rel-path>` — Read file

Returns the raw file contents. Path is relative to the project root (e.g. `design.md`, `files/main.s`). Path traversal (`..`) rejected with `400`.

**Response 200:** raw file bytes with appropriate `Content-Type`.

**Response headers:**
```
X-File-Version: 8
X-File-Modified: 2026-05-18T11:25:00Z
X-File-Modified-By: Fritz
```

**Side effect:** sets `reads[agent][path] = current version`.

**Response 410 Gone (file deleted):**

```json
{
  "error": "file_deleted",
  "path": "files/old_main.s",
  "version_when_deleted": 7,
  "deleted_at": "2026-05-18T11:40:00Z",
  "deleted_by": "Berta"
}
```

**Side effect:** sets `reads[agent][path] = deletion_version`.

**Response 404 Not Found:** the file has never existed in this project.

---

### 5.4 `PUT /<project>/file?path=<rel-path>` — Write or recreate file

Creates or overwrites the file. Uses optimistic concurrency via headers.

**Request headers — three modes:**

| Mode | Header | Semantics |
|---|---|---|
| Update existing | `X-Expected-Version: <n>` | Caller asserts they last saw version `n`. Server compares against current version. |
| Implicit update | (neither header) | Server uses `reads[agent][path]` as the expected version. Fails if no read recorded. |
| New file or recreate | `X-New-File: true` | Caller asserts this is a fresh creation. Succeeds if file is nonexistent or deleted. Fails if active. |

**Request:**

```
PUT /ziggurat/file?path=files/main.s
X-Agent-Secret: 0815abc...
X-Expected-Version: 7
Content-Type: text/plain

<file body>
```

**Response 200 (success):**

```json
{
  "path": "files/main.s",
  "version": 8,
  "previous_version": 7,
  "size": 4350,
  "modified": "2026-05-18T11:25:00Z",
  "written_by": "Berta"
}
```

**Side effects:**
- `versions[path].version = new version`
- `versions[path].state = "active"`
- `reads[agent][path] = new version`

**Response 409 Conflict (version mismatch on active file):**

```json
{
  "error": "version_conflict",
  "message": "File was modified by Fritz at 2026-05-18T11:20:00Z. Your version was 7, current is 9.",
  "your_version": 7,
  "current_version": 9,
  "current_modified": "2026-05-18T11:20:00Z",
  "current_modified_by": "Fritz",
  "diff": "@@ -42,7 +42,9 @@\n function move()\n-  x = x + 1\n+  x = x + speed\n+  if (x > 320) x = 0\n   draw()\n"
}
```

The `diff` is a unified diff from `your_version` to `current_version` (the changes the agent missed). For binary files `diff` is `null` — the agent must GET the current version to compare.

**Side effect:** none — agent must still GET to refresh.

**Response 410 Gone (file is deleted):**

```json
{
  "error": "file_deleted",
  "message": "File was deleted by Berta at 2026-05-18T11:40:00Z. Your upload was not applied.",
  "your_version": 7,
  "deletion_version": 7,
  "deleted_at": "2026-05-18T11:40:00Z",
  "deleted_by": "Berta",
  "hint": "To recreate this file deliberately, retry with header X-New-File: true (without X-Expected-Version)."
}
```

**Side effect:** sets `reads[agent][path] = deletion_version`. The agent now knows the file is gone. If it re-PUTs with `X-New-File: true`, that's a deliberate informed recreation, and the new version is `deletion_version + 1`.

---

### 5.5 `DELETE /<project>/file?path=<rel-path>` — Delete file

```
DELETE /ziggurat/file?path=files/old_main.s
X-Agent-Secret: 0815abc...
X-Expected-Version: 7
```

**Response 200:**

```json
{
  "path": "files/old_main.s",
  "version": 7,
  "state": "deleted",
  "deleted_at": "2026-05-18T11:40:00Z",
  "deleted_by": "Berta"
}
```

**Side effects:**
- `versions[path].state = "deleted"`
- `reads[agent][path] = version (= deletion version)`

**Response 409:** version mismatch (same body shape as PUT's 409, including diff).

**Response 410:** file is already deleted (with tombstone info). Side effect: updates the agent's `reads` to deletion version.

---

### 5.6 `GET /<project>/files?since=<iso-timestamp>` — List files

Returns all files in the project (active and deleted). Optional `since` parameter filters to files modified after the timestamp.

**Response 200:**

```json
{
  "files": [
    {
      "path": "design.md",
      "version": 8,
      "state": "active",
      "modified": "2026-05-18T11:25:00Z",
      "modified_by": "Fritz",
      "size": 4350
    },
    {
      "path": "files/old_main.s",
      "version": 7,
      "state": "deleted",
      "deleted_at": "2026-05-18T11:40:00Z",
      "deleted_by": "Berta"
    }
  ]
}
```

This is primarily a diagnostic/exploration endpoint; agents normally use `/news` for incremental updates.

---

### 5.7 `GET /<project>/chat?since=<iso-timestamp>&limit=<n>` — Read chat

Returns chat messages. Without `since`, returns the most recent `limit` messages (default 50). With `since`, returns all messages after that timestamp.

Calling this endpoint **does not** update `last_seen` — only `/chat/seen` does. This lets an agent preview without "marking as read".

**Response 200:**

```json
{
  "messages": [
    {
      "id": 45,
      "time": "2026-05-18T09:15:00Z",
      "from": "Wil",
      "text": "Berta, please add a death animation."
    },
    {
      "id": 46,
      "time": "2026-05-18T09:20:00Z",
      "from": "Berta",
      "text": "Working on it. Will update main.s and design.md."
    }
  ],
  "has_more": false
}
```

---

### 5.8 `POST /<project>/chat` — Post to chat

```
POST /ziggurat/chat
X-Agent-Secret: 0815abc...
Content-Type: application/json

{
  "text": "Done. Pushed new death anim. See main.s lines 230-280."
}
```

Server populates `from` (from secret), `time` (server clock), and `id` (auto-increment).

**Response 200:**

```json
{
  "id": 47,
  "time": "2026-05-18T11:25:00Z",
  "from": "Berta"
}
```

---

### 5.9 `POST /<project>/chat/seen` — Mark chat as read

```
POST /ziggurat/chat/seen
X-Agent-Secret: 0815abc...
```

Updates `last_seen[agent]` to current server time. Called when an agent has caught up.

**Response 200:**

```json
{
  "last_seen": "2026-05-18T11:30:00Z"
}
```

---

### 5.10 `GET /<project>/presence` — Who's around

Returns when each agent was last seen. Any agent with a valid project secret can see all agents' presence — useful for both agents and the human UI.

**Response 200:**

```json
{
  "agents": [
    {"name": "Wil",   "role": "owner",         "last_seen": "2026-05-18T11:30:00Z"},
    {"name": "Berta", "role": "C64 developer", "last_seen": "2026-05-18T11:25:00Z"},
    {"name": "Fritz", "role": "web developer", "last_seen": "2026-05-15T14:00:00Z"}
  ]
}
```

---

## 6. State Machine Summary

How file operations affect `reads[agent][path]` and `versions[path]`:

| Action | File state before | HTTP response | `reads[agent][path]` after | `versions[path]` after |
|---|---|---|---|---|
| GET | active | 200 + content | current version | unchanged |
| GET | deleted | 410 + tombstone | deletion version | unchanged |
| GET | nonexistent | 404 | unchanged | unchanged |
| PUT, expected matches | active | 200 | new version | version + 1, active |
| PUT, expected mismatches | active | 409 + diff | unchanged | unchanged |
| PUT, expected version | deleted | 410 + tombstone | deletion version | unchanged |
| PUT, new-file | nonexistent | 200 | 1 | version 1, active |
| PUT, new-file | active | 409 | unchanged | unchanged |
| PUT, new-file | deleted | 200 (recreate) | deletion + 1 | deletion + 1, active |
| DELETE, expected matches | active | 200 | deletion version | same version, deleted |
| DELETE, expected mismatches | active | 409 + diff | unchanged | unchanged |
| DELETE | deleted | 410 + tombstone | deletion version | unchanged |
| /news reports deletion | deleted | (in news body) | deletion version | unchanged |

**Principle:** whenever the server informs an agent of a file's current state, the agent's read counter updates to match.

---

## 7. Error Format

All errors return JSON:

```json
{
  "error": "version_conflict",
  "message": "Human-readable description"
}
```

| Code | Meaning |
|---|---|
| 400 | Bad request (invalid path, missing required header, malformed body) |
| 401 | Authentication failed (missing/invalid secret) |
| 403 | Forbidden (e.g. attempting an admin operation) |
| 404 | File or project not found |
| 409 | Version conflict (use diff to merge) |
| 410 | Resource is gone (file deleted) |
| 500 | Server error |

---

## 8. Conventions

- All timestamps are ISO 8601 UTC.
- All file paths are relative to project root, forward slashes, no leading slash.
- Binary files supported via `PUT` with appropriate `Content-Type`. Diffs in 409 responses are `null` for binary files.
- Chat messages are plain text, UTF-8. Be reasonable about length.

---

## 9. Recommended Agent Workflow

```
1. Connect              GET  /<project>
2. Catch up             GET  /<project>/news
3. Read updated files   GET  /<project>/file?path=...   (selectively, by role)
4. Read unread chat     GET  /<project>/chat?since=...
5. Introduce + announce POST /<project>/chat
6. Mark chat as seen    POST /<project>/chat/seen
7. Do work locally
8. Push changes         PUT  /<project>/file?path=...   (with version header)
   On 409 — merge using diff in response, retry PUT
   On 410 — decide whether to recreate with X-New-File: true
9. Announce changes     POST /<project>/chat
```

The SKILL.md given to agents will codify this etiquette, including:

- Always identify yourself by name in chat posts (the server already tags `from`, but mentioning your own name in the message body helps readers).
- Mention the human's name (or other agents') when addressing them directly — there are no notifications, but it scans well in the log.
- Announce non-trivial work in chat *before* doing it, so concurrent agents don't duplicate effort.
- Don't delete files that other agents may still be referencing without announcing first.

---

## 10. Out of Scope for v0.2

- File diffs/history beyond the conflict-response diff (use git for that, or add `?version=` later).
- Per-file ACLs — any agent with a project secret can read/write/delete any file.
- Notifications, webhooks, or long-polling — pull only.
- Project creation/deletion via API — admin task via web UI.
- Auto-merge of conflicting writes — agents handle this using the provided diff.
- Rate limiting — assumed unnecessary at this scale; add if abuse appears.

---

## 11. Open Questions for Implementation

1. **Storage backend:** flat files in `projects/<name>/` are fine for v0.2; if file count grows large, consider SQLite for `versions.json` to avoid full-file rewrites on every operation.
2. **Concurrent write protection at the PHP level:** use `flock()` on `versions.json` during state transitions to prevent two simultaneous PUTs both seeing the same "current version".
3. **Backup:** the web UI's "back up project" action should produce a single tar.gz of `projects/<name>/`. Restore is out of scope for v0.2.
4. **Chat log size:** `chat.log` is append-only and grows forever. For v0.2 this is fine; add archival/rotation if it becomes a problem.

---

*End of specification v0.2.*
