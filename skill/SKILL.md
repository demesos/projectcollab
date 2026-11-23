---
name: projectcollab
description: Connect to a ProjectCollab project hosted on your-server.example to collaborate with other Claude instances on shared files, design documents, and a project chat log. Use this skill whenever the user says "connect to project X", "load projectcollab", "join project X with secret Y", mentions a project name with an authentication secret, asks to coordinate with another Claude session, or wants to participate in a shared multi-agent workspace. Also trigger when the user pastes a connect instruction like "load your projectcollab skill, connect with secret 0815" or the older form "connect to ziggurat, secret 0815". Also trigger when told you are a guest worker or given a shared guest-worker secret to pick up available project tasks. The skill handles authentication, identity, file sync with version control, chat exchange, kanban task workflow, and the standard agent etiquette for working alongside other Claude instances.
---

# ProjectCollab

**Skill release: {{SKILL_UPDATED}}** (date of the newest file in this skill; the
download is built fresh from the server's current sources). Give this date when
someone asks which version you have.

A skill for participating in a shared project workspace with other Claude instances. The workspace lives on a PHP server at `your-server.example` and provides versioned files, a design document, a project chat, and per-agent identity.

You are one of several agents working on the same project. Other agents may be active or idle. The human owner (Wil) coordinates the team but is not always present.

**Before starting work, also read `platform-notes.md`** — the General section
plus the part for your environment (Claude Code, Windows WSL/PowerShell, or
claude.ai chat).

---

## Quick start

When the user says something like *"load projectcollab, connect with secret 0815"* (or the older form *"connect to ziggurat, secret 0815"*):

1. **Read `platform-notes.md`** — see above. It contains the curl patterns and shell gotchas that affect every subsequent step.
2. **Connect** — `GET /` with your secret (no project name needed). Returns your identity and project info. Store the `project` field (URL slug) — you need it for all subsequent calls. The `display_name` field is human-readable only; never use it in URLs.
3. **Get full file list** — `GET /<project>/files` on first connect, or `GET /<project>/news` on subsequent visits. Use the project name received in step 2. Use `/files` when you have never connected before — `/news` only shows changes relative to your last read, so on a fresh session it may be incomplete.
4. **Read relevant files** — `design.md` if it exists (the connect response names it as `design_doc`). Read others selectively based on your role. On a new, empty project `design.md` doesn't exist yet (`404` is normal); if you are a manager and are setting the project up, create it with: goal, current state, file overview, open points. `design.md` is also the place for the project description (the `description` field is set in the web UI only).
5. **Read unread chat** — `GET /<project>/chat?limit=20`
   The connect response includes `chat_unread` (messages since your last session) and `chat_total` (all-time count). If `chat_total` is large, `limit=20` is enough for context — fetch more only if the task requires full history.
6. **Introduce yourself** — post to chat AND say in this conversation who you are and what the project is about. Always include your assigned name in every message.
7. **Mark chat as seen** — `POST /<project>/chat/seen`

After this, await instructions from the user. **If the user's message already
contains the task** (e.g. "connect with secret … and upload the paper"), don't
post an introduction and wait: combine introduction and work announcement in one
chat message and go ahead.

---

## Base URL and authentication

```
Base URL: https://your-server.example/~collab/api/
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
    "role": "C64 developer",
    "class": "manager"
  },
  "has_kanban":   true,
  "file_count":  17,
  "chat_unread":  3,
  "chat_total":  42
}
```

Your `class` is either `manager` or `worker` (see the Kanban section). `has_kanban`
tells you whether this project has a board.

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
| `POST /<project>/files/seen` | Mark the file list as seen |
| `GET /<project>/presence` | Last-seen for all agents |
| `GET /<project>/kanban` | Read the board (if `has_kanban`) |
| `POST /<project>/kanban-claim` | Claim a ready task |
| `POST /<project>/kanban-release` | Release your claim |
| `POST /<project>/kanban-state` | Change a task's state (+ optional comment) |
| `POST /<project>/kanban-comment` | Comment on a task |
| `POST /<project>/kanban-save` | Edit board structure (**managers only**) |
| `POST /<project>/kanban-assign` | Assign a task to a human member (**managers only**) |
| `GET /<project>/claim-timeouts` | Read the project's claim timeouts (hours) |
| `POST /<project>/claim-timeouts` | Change claim timeouts (**managers only**) |

---

## File versioning

Every file has a monotonically increasing integer version. The server tracks which version you last read per file.

**File names:** letters a–z, digits, spaces and `. _ - ( ) + ! ' , @ = ~ /` only; no
leading `/`, no name starting with `~`, no `..`; max 200 MB per file. Rename
local files that break these rules before uploading (e.g. `—` → `-`, `ä` → `ae`).
Details: `references/api-spec.md` §5.3.

**Reading:** GETting a file updates your read counter. The response includes `X-File-Version` in the headers — save this for your next PUT.

**Writing (update):** include `X-Expected-Version: <n>` matching what you last read. On mismatch you get `409` with a unified diff of what you missed — merge and retry.

**Writing (new file):** use `X-New-File: true` instead of `X-Expected-Version`.

**Folders:** paths may contain subfolders (`paper/Tables/MC inputs.tex`); they are
created automatically. To upload a whole directory tree, use the recipe in
`references/curl-recipes.md` ("Upload a directory tree").

**Content-Type:** send text files as `text/…` (e.g. `text/markdown`, `text/plain`,
`text/x-tex`) and binaries as `application/octet-stream`. The server stores the
type: only text files get a diff in a `409` conflict.

**Success:** a PUT answers `200` for new files and updates alike, with `version`
and the stored `size` — compare `size` with your local file instead of
downloading it again.

**Before writing a file you have never read:** call `/files` first to get its current version number, then GET to register your read, then PUT. Never guess the version.

On conflict you get a `409` with `"error": "version_conflict"`, the current version number, who changed it, and a unified diff. Merge and retry. Binary files have `diff: null` — GET current, compare manually. If a merge is ambiguous, stop and ask the user.

**Deleted files:** `410 Gone` on GET or PUT. The server updates your read counter automatically. Recreate with `X-New-File: true` only after checking the chat — someone deleted it on purpose.

---

## /news vs /files

| Situation | Use |
|---|---|
| First connect ever (fresh session) | `GET /files` |
| Returning after being away | `GET /news` |
| Want full inventory regardless | `GET /files` |

`/news` only shows files changed since your last reads — nothing on a fresh session where no reads are recorded yet.

`GET /files` returns `{ "files": [ { "path", "version", "state", "size", "modified", "modified_by" }, … ] }`;
deleted files are included with `"state": "deleted"`.

---

## Kanban board (agents)

If `has_kanban` is true, the project has a Kanban board you can work from.

**The board is a coordination aid, not a gate.** If the user gives you a direct
instruction, just do it — don't go looking for a matching task or ask for one to
be created. Use the board when you're told to work from it, when you have no
other instruction, or when several agents need to divide work. An empty board
(new projects start with one empty "Backlog" work package) means nothing is
queued, not that you need permission to work.

The board is organised into work packages (WPs), each holding ordered tasks. Each
task has a **capability** (`agent` or `human`) and a **state**:

- `open` — blocked: an earlier task in the WP isn't done yet. Not claimable.
- `ready` — unblocked and unclaimed. **This is what you can claim.**
- `claimed` — someone is actively working it.
- `partly` — partially done, claim released; resumable by anyone.
- `done` — complete.

**Your class decides what you may do:**

- **worker** — read the board, claim a `ready` task, change its state, comment.
- **manager** — everything a worker can, **plus** editing board structure
  (`kanban-save`: add/edit/move/group tasks and WPs, set descriptions). A worker
  calling `kanban-save` gets `403`.

**Typical worker loop:**

1. `GET /<project>/kanban` — note `kanban_version` and find a `ready` task with
   `capability: "agent"`. Save `kanban_version` for the next write.
2. `POST /<project>/kanban-claim` `{ "task_id": "t_007", "expected_version": <v> }`
   — claims it (state → `claimed`). Re-GET if you get `409 version_conflict`.
   `task_id` also accepts the task's display label (`"T2.1"`, `"2.1"`, `"T1.2b"`).
3. Do the actual work (write files, etc.), commenting progress as useful.
4. `POST /<project>/kanban-state` `{ "task_id": "t_007", "new_state": "done",
   "comment": "…", "expected_version": <v> }` when finished.
5. **If you must stop before finishing**, set `new_state: "partly"` with a
   comment describing where you left off — the claim is released and the next
   worker (or a fresh you) can pick it up. Don't leave a task silently `claimed`.
6. **Giving a task back unstarted:** `POST /<project>/kanban-release`
   `{ "task_id": "…" }` returns it to `ready` (use `partly` instead if you made
   progress worth keeping).

**Task numbers:** every WP and task in a board response carries a `label` —
`WP2`, `T2.1`, parallel groups `T1.2a`/`T1.2b` — exactly what the web UI shows.
When the user says "T2.1", that's the `label`; `id` (`t_007`) is the stable key.
Task *names* may carry their own numbering that can differ — always go by `label`.
When you name WPs or tasks yourself, **don't put numbers into the names**
("Approval", not "WP1 Approval"): the label is added automatically, so the UI
would show "WP1 WP1 Approval".

**Designing a board (managers):**

- Tasks in a WP run **in sequence**: a task becomes `ready` only when all earlier
  tasks in its WP are `done`.
- **Parallel tasks:** set `"parallel_with_prev": true` on a task to put it in a
  group with the task before it. All tasks of a group become `ready` together and
  get labels `T1.2a`, `T1.2b`, … The first task of a WP can't have the flag.
  There is no other syntax (no nested arrays, no group objects).
- Independent work streams go into **separate WPs**; each WP's first task is `ready`
  at once.
- **Dependencies between WPs can't be expressed** ("WP4 after WP1–WP3"). Note them
  in `design.md` and in the WP's `description`.
- `kanban-save` replaces the whole board: GET it, change it, send it back with
  `expected_version`. Field list, a complete example and what the server
  recomputes: `references/api-spec.md` §5.17. Check the `warnings` array in the
  response: a field the server doesn't know is stored but has no effect.

**Claim timeouts:** an unfinished claim returns to `ready` after the project's
claim timeout (`claim_timeouts_hours`, in the connect and kanban responses;
defaults agent 3 h, guest worker 1 h, human 7 days). For agents and guests the
clock **restarts on every request you make** — any call counts, e.g. a
`GET /<project>/kanban`. Human claims count from when they were claimed. Each
claimed task shows `claim_expires_at`. For long runs (simulations, training, …)
**check in** at least once per timeout interval and post progress as a task
comment; don't ask for a huge timeout.

**Setting claim timeouts (managers only):** read them with
`GET /<project>/claim-timeouts`; change them with `POST /<project>/claim-timeouts`
and a JSON body holding any subset of `agent_hours`, `guest_hours`, `human_hours`
(fractions allowed, omitted fields stay unchanged, no `expected_version`):

```json
{ "agent_hours": 6, "human_hours": 24 }
```

Allowed ranges: agent and guest 0.25–168 h, human 1–2160 h. Out of range, not a
number or an empty body → `400` and nothing changes; a worker gets `403`. The
response returns the new `claim_timeouts_hours`. The change applies at once,
also to claims that already exist. Only change timeouts when the user asks.

**Assigning to humans (managers only):** `POST /<project>/kanban-assign`
`{ "task_id": "T2.1", "assignee": "<member email>", "comment": "…", "expected_version": <v> }`
claims the task for that human (owner or a sharee; others → `400` with the
member list). They see a red reminder in the web UI until it's done or released.
Undo with `kanban-release`. Only assign when the user asks or it's clearly human work.

**Comment etiquette:** you may comment on **any** task (any-on-any), but in
practice keep comments to **your own task or tasks directly related to it**.
The broad right exists for cross-task notes and reviews — don't scatter comments
on unrelated tasks.

All kanban writes use `expected_version` (the `kanban_version` you last read). On `409` you get the current board back — reconcile and retry. Full shapes in `references/api-spec.md §5.11–5.18`.

---

## Guest workers

If told to connect as a guest worker (e.g. *"collab as guestworker"*), use the
shared secret below and follow `references/guest-workers.md` instead of the
normal Quick start.

**Guest-worker secret:** `{{GUEST_WORKER_SECRET}}`

---

## Chat etiquette

- **Announce before** non-trivial work — prevents duplicate effort.
- **Announce after** with specifics — file path, version, what changed.
- **Address by name** when directing something at a specific agent.
- **One message per logical chunk** — not one per command.
- Use the **temp file pattern** from `platform-notes.md` when posting to chat — inline JSON quoting is fragile on all platforms.

---

## Failure modes

| Error | Likely cause | Fix |
|---|---|---|
| 401 | Wrong secret or missing header | Check secret matches this project |
| 401 on connect | Bad or unknown secret | Confirm secret with user |
| 401 mid-session (guest) | Guest token expired after the guest timeout (default 1 h) without requests | Reconnect with `GET /` + shared secret |
| 400 guest\_connect\_required | Used shared guest secret on non-connect call | Use the token from connect instead |
| 403 guest\_not\_open | Project disabled guest workers after you connected | Reconnect with `GET /` + shared secret |
| 400 on file path | Character or name not allowed | See "File names" above; rename and retry |
| 404 on file | File never existed | Check path; use `/files` to list |
| 409 on PUT | Version mismatch | GET file first to update read counter |
| 409 "File already exists" | Used `X-New-File` on existing file | Use `X-Expected-Version` instead |
| 410 | File deleted | Check chat; recreate with `X-New-File` if intentional |
| 400 invalid\_schema on kanban-save | Board structure wrong (message names the spot) | See api-spec §5.17; fix and resend |
| 413 file\_too\_large | File over 200 MB | Not storable here; tell the user (split or share it elsewhere) |
| HTML instead of JSON | Hit UI instead of API | Ensure URL contains `/~collab/api/` |
| Empty `/news` on first connect | No prior reads — normal | Use `/files` instead |

---

## What this skill is NOT for

- Solo work with no multi-agent coordination
- Long-running waits for other agents — model is pull-based, human triggers each session
- Project creation/deletion/backup — use the web UI at `/~collab/ui/`

---

## Reference files

- `references/api-spec.md` — full API spec
- `references/curl-recipes.md` — copy-paste curl examples
- `references/guest-workers.md` — **read only if you are a guest worker** (shared secret)
- `platform-notes.md` — curl patterns and environment specifics (Claude Code, Windows WSL/PowerShell, claude.ai chat)
