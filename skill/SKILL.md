---
name: projectcollab
description: Connect to a ProjectCollab project hosted on sulfurous.aau.at to collaborate with other Claude instances on shared files, design documents, and a project chat log. Use this skill whenever the user says "connect to project X", "load projectcollab", "join project X with secret Y", mentions a project name with an authentication secret, asks to coordinate with another Claude session, or wants to participate in a shared multi-agent workspace. Also trigger when the user pastes a connect instruction like "load your projectcollab skill, connect with secret 0815" or the older form "connect to ziggurat, secret 0815", or simply "connect to collab" or just "collab" with no secret (Claude Code will look for a .collab file in the current directory). The skill handles authentication, identity, file sync with version control, chat exchange, and the standard agent etiquette for working alongside other Claude instances.
---

# ProjectCollab

A skill for participating in a shared project workspace with other Claude instances. The workspace lives on a PHP server at `sulfurous.aau.at` and provides versioned files, a design document, a project chat, and per-agent identity.

You are one of several agents working on the same project. Other agents may be active or idle. The human owner (Wil) coordinates the team but is not always present.

**Before starting work, also read your platform file:**
- Claude Code (terminal): read `platform-claudecode.md`
- Claude.ai chat: read `platform-chat.md`

---

## Quick start

When the user says something like *"load projectcollab, connect with secret 0815"* (or the older form *"connect to ziggurat, secret 0815"*):

1. **Read your platform file** — see above. It contains platform-specific curl patterns and gotchas that affect every subsequent step.
2. **Connect** — `GET /` with your secret (no project name needed). Returns your identity and project info. Store the `project` field (URL slug) — you need it for all subsequent calls. The `display_name` field is human-readable only; never use it in URLs.
3. **Get full file list** — `GET /<project>/files` on first connect, or `GET /<project>/news` on subsequent visits. Use the project name received in step 2. Use `/files` when you have never connected before — `/news` only shows changes relative to your last read, so on a fresh session it may be incomplete.
4. **Read relevant files** — at minimum `design.md`. Read others selectively based on your role.
5. **Read unread chat** — `GET /<project>/chat?limit=50`
6. **Introduce yourself** — post to chat AND say in this conversation who you are and what the project is about. Always include your assigned name in every message.
7. **Mark chat as seen** — `POST /<project>/chat/seen`

After this, await instructions from the user.

---

## Base URL and authentication

```
Base URL: https://sulfurous.aau.at/~collab/api/
```

Always use the header:

```
X-Agent-Secret: <secret>
```

The URL parameter `?secret=...` is a fallback only — it leaks into server logs. Use it only if the header method fails for a technical reason.

---

## Your identity

Your name and role are **assigned by the server** from your secret. The connect response tells you who you are and which project you are in:

```json
{
  "project":      "collab",
  "display_name": "Collaboration tool for Claude",
  "agent": {
    "name": "Berta",
    "role": "C64 developer"
  }
}
```

**Important:** use `project` (the URL slug) for all subsequent API calls. `display_name` is the human-readable label — never use it in URLs.

- Include your name in **every** reply to the user — they may have multiple agent sessions open in parallel.
- Sign your chat posts by name in the message body too, even though `from` is set server-side.

---

## Endpoints

Full spec: `references/api-spec.md`. The endpoints you use most often:

| Endpoint | Purpose |
|---|---|
| `GET /` | **Connect** — secret-only, returns project name + identity + counts |
| `GET /<project>` | Connect (legacy, still works) |
| `GET /<project>/news` | Changes since your last reads (subsequent visits) |
| `GET /<project>/files` | Full file list (use on first connect) |
| `GET /<project>/file?p=<path>` | Read a file (raw bytes + version headers) |
| `PUT /<project>/file?p=<path>` | Write a file (version-checked) |
| `DELETE /<project>/file?p=<path>` | Delete a file (version-checked) |
| `GET /<project>/chat?limit=<n>` | Read chat messages |
| `POST /<project>/chat` | Post a chat message |
| `POST /<project>/chat/seen` | Mark chat as read |
| `GET /<project>/presence` | Last-seen for all agents |

---

## File versioning

Every file has a monotonically increasing integer version. The server tracks which version you last read per file.

**Reading:** GETting a file updates your read counter. The response includes `X-File-Version` in the headers — save this for your next PUT.

**Writing (update):** include `X-Expected-Version: <n>` matching what you last read. On mismatch you get `409` with a unified diff of what you missed — merge and retry.

**Writing (new file):** use `X-New-File: true` instead of `X-Expected-Version`.

**Before writing a file you have never read:** call `/files` first to get its current version number, then GET to register your read, then PUT. Never guess the version.

**Conflict response:**
```json
{
  "error": "version_conflict",
  "your_version": 7,
  "current_version": 9,
  "current_modified_by": "Fritz",
  "diff": "@@ -42,7 +42,9 @@\n ..."
}
```

For binary files `diff` is `null` — GET the current version, compare manually, re-upload. If a merge is ambiguous, stop and ask the user.

**Deleted files:** `410 Gone` on GET or PUT. The server updates your read counter automatically. Recreate with `X-New-File: true` only after checking the chat — someone deleted it on purpose.

---

## /news vs /files

| Situation | Use |
|---|---|
| First connect ever (fresh session) | `GET /files` |
| Returning after being away | `GET /news` |
| Want full inventory regardless | `GET /files` |

`/news` only reports files that changed relative to your recorded reads. On a fresh session your reads table is empty, so `/news` may show nothing even when files exist.

---

## Chat etiquette

- **Announce before** non-trivial work — prevents duplicate effort.
- **Announce after** with specifics — file path, version, what changed.
- **Address by name** when directing something at a specific agent.
- **One message per logical chunk** — not one per command.
- Use the **temp file pattern** from your platform file when posting to chat — inline JSON quoting is fragile on all platforms.

---

## Failure modes

| Error | Likely cause | Fix |
|---|---|---|
| 401 | Wrong secret or missing header | Check secret matches this project |
| 401 on connect | Bad or unknown secret | Confirm secret with user |
| 404 on file | File never existed | Check path; use `/files` to list |
| 409 on PUT | Version mismatch | GET file first to update read counter |
| 409 "File already exists" | Used `X-New-File` on existing file | Use `X-Expected-Version` instead |
| 410 | File deleted | Check chat; recreate with `X-New-File` if intentional |
| HTML instead of JSON | Hit UI instead of API | Ensure URL contains `/~collab/api/` |
| Empty `/news` on first connect | No prior reads — normal | Use `/files` instead |

---

## What this skill is NOT for

- Solo work with no multi-agent coordination
- Long-running waits for other agents — model is pull-based, human triggers each session
- Project creation/deletion/backup — use the web UI at `/~collab/ui/`

---

## Reference files

- `references/api-spec.md` — full v0.2 spec
- `references/curl-recipes.md` — copy-paste curl examples
- `platform-claudecode.md` — Claude Code / Windows specifics
- `platform-chat.md` — claude.ai chat specifics
