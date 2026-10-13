# Kanban Implementation Plan

**Author:** Gustav (Kanban Implementer)
**Date:** 2026-05-26
**Status:** Draft for review

## 1. Scope summary

Add a project-scoped Kanban board with:

1. A new **"Kanban"** tab on each project page (positioned after Files, before Chat — see open question #1).
2. A graphical board of work packages (WPs, horizontal) containing tasks (vertical), with parallel-group spacing, capability-based colors, and state indicators (hourglass = waiting, plain = ready/open).
3. **Inline editing**: add task button at the end of each WP, double-click to edit task or WP name, drag-drop tasks within/across WPs, drag-drop WPs horizontally, right-click task to toggle parallel grouping with the previous task.
4. **User color picker**: a colored swatch in the header between "Log out" and the theme toggle. Left-click cycles to a fresh randomly-generated color (well separated from all other users' colors and from azzurro). Right-click goes back through the last 5 chosen colors.
5. Initial state: one empty WP called "Phase 0".

Permission classes are not finalized yet — see open question #2.

---

## 2. Data model

### 2.1 Per-project: `kanban.json`

Stored at `users/<owner>/projects/<slug>/kanban.json`. Single file per project, written under the same `flock(.lock)` discipline as `versions.json`.

```json
{
  "version": 1,
  "wps": [
    {
      "id": "wp_001",
      "name": "Foundation",
      "tasks": [
        {
          "id": "t_001",
          "name": "Define data structures",
          "description": "",
          "actor_email": null,
          "capability": "agent",
          "state": "ready",
          "claimed_by": null,
          "claimed_at": null,
          "completed_by": null,
          "completed_at": null,
          "parallel_with_prev": false,
          "comments": []
        }
      ]
    }
  ],
  "next_wp_id": 2,
  "next_task_id": 2,
  "kanban_version": 7
}
```

**Field rationale:**

- `id` for WPs and tasks is a stable string (`wp_001`, `t_001`) so reorderings don't require renumbering. Display names like `T1.2a` are computed on the client from position + parallel-group membership.
- `capability` is one of `"agent"` (any Claude), or `"human"`. If `"human"` then `actor_email` may optionally pin a specific human (their personal color is used). If `actor_email` is null, the task uses a neutral "any human" color (still warm-family). Agents always share azzurro.
- `state`: `"open"` | `"ready"` | `"claimed"` | `"partly"` | `"done"`. Computed when needed: a task is `ready` iff all earlier non-parallel tasks in its WP are `done`. We store `state` explicitly anyway so the server is authoritative — but on save the server recomputes `open`/`ready` transitions while preserving `claimed`/`partly`/`done`.
- `claimed_by` is an email (humans) or an agent secret (agents). `claimed_at` is ISO 8601. Timeouts (30 min agents, 7 days humans) are checked server-side on every read; expired claims silently revert to `ready`.
- `parallel_with_prev: true` means "this task runs in parallel with the task immediately above it in the same WP." A chain of true values forms a parallel group (the first task in the group has `false`, the rest `true`). This is how the a/b/c grouping is stored.
- `comments`: append-only `[{by, at, text}]`. Used for the "partly finished" notes, test feedback, etc.
- `kanban_version` is the monotonic version number for optimistic concurrency on the whole file. Each successful write increments it. Clients send `If-Match: <n>` (or `X-Expected-Kanban-Version`) on writes; mismatch returns 409 with the current state.

### 2.2 Computed display names

The client renders `T<wp_index>.<task_index><letter?>`:

- `wp_index` = position of the WP in `wps[]` + 1
- `task_index` = position of the parallel-group start within the WP + 1
- `letter` = a/b/c… among consecutive tasks where `parallel_with_prev: true` (the first task in a group gets `a` only if there is at least one parallel sibling below it)

This is purely cosmetic and recomputed on every render. Reordering changes the displayed numbers but never breaks references because internally we use stable IDs.

### 2.3 Per-user: color and history

Stored in `users.json` under each user record:

```json
{
  "email": "wil@aau.at",
  "role": "developer",
  "color": "#D85A30",
  "color_history": ["#D85A30", "#993556", "#1D9E75", "#7F77DD", "#BA7517"]
}
```

The `color` field is the active one. `color_history` is up to 5 entries, most-recent-first, includes the active color. New users get a random color assigned at account creation (well separated from azzurro and from all existing users' colors). Right-click on the swatch cycles through history (oldest at top, then wraps).

**Azzurro is reserved.** Computed-distance algorithm rejects colors within ~25° hue of azzurro `#85B7EB`, and within ~20° hue of any other user's color.

---

## 3. API endpoints

All under `ui/api.php` (UI-side, session-authenticated), following the existing pattern:

| Method | Action | Purpose |
|---|---|---|
| `GET`  | `kanban` | Fetch full board: `{wps: [...], kanban_version: N}`. Server resolves expired claims, computes `ready` states, attaches per-actor color (from `users.json`). |
| `POST` | `kanban-save` | Replace the whole `wps` array. Body: `{wps: [...], expected_version: N}`. Server validates schema, increments version, writes atomically. Returns new `kanban_version` or 409 with current. |
| `POST` | `kanban-claim` | Body: `{task_id, expected_version}`. Atomic claim. Returns 409 if already claimed by someone else, or if version stale. |
| `POST` | `kanban-release` | Body: `{task_id, expected_version}`. Release my claim. |
| `POST` | `kanban-state` | Body: `{task_id, new_state, comment?, expected_version}`. Move a task between `claimed → done` etc. |
| `POST` | `set-user-color` | Body: `{color}`. Saves to current user, pushes onto history. Server validates separation from azzurro and other users. Returns `{color, history}`. |
| `GET`  | `user-colors` | Returns `{<email>: <hex>, ...}` for everyone the current user can see (so the board can color tasks pinned to other humans). |

Three reasons for the granular endpoints (claim/release/state) on top of the bulk `kanban-save`:

1. They're contention hotspots — claiming should be a single atomic op, not "fetch entire board, modify, save entire board."
2. They have different permission semantics (any agent can claim a task, but only certain roles can edit the structure).
3. They produce useful chat-log side effects later (e.g. auto-post "Gustav claimed T1.4").

The bulk `kanban-save` is used for structural edits (drag, rename, add WP). It's fine to keep it big-and-simple since structural edits are infrequent.

---

## 4. UI layout

### 4.1 Tab placement

New tab between Files and Chat:

```
Files | Kanban | Chat | Agents | Share | Settings
```

Reasoning: Files and Kanban are both "workspace content"; Chat sits to their right as the communication channel. Open question #1: should Kanban be the leftmost tab instead (since it's the project plan)?

### 4.2 Board

CSS grid with `grid-template-columns: repeat(auto-fit, minmax(160px, 1fr))` — adapts to viewport. Each WP is a column with:

- Header: WP name + index (double-click to rename, drag-handle for reordering WPs)
- Task list: vertical stack, parallel groups visually tighter spaced and wrapped in a dashed-border background
- `[+ Add task]` button as the last row

### 4.3 Task card

- Compact (12–13px label, 1 line for ID, optional 1 line for name)
- Background = actor color, full saturation for `claimed`, desaturated for `open`/`ready`, green-bordered for `done`, dashed-orange-bordered for `partly`
- Hourglass icon for `open` (waiting on dependency), no icon for `ready`, person icon when claimed showing actor initials, checkmark for `done`
- Double-click → modal editor (name, description, capability, pinned actor, comments)
- Right-click → context menu: "Group with previous" / "Ungroup" / "Delete task"
- Drag handle: the whole card; drop targets are between tasks and between WPs

### 4.4 Drag and drop

Native HTML5 DnD. A draggable task that's the start of a parallel group drags the whole group together. Drop zones are visualized as a thin colored line between tasks (or between WP columns for horizontal WP reordering).

### 4.5 Color swatch in header

In `index.php`, between `<a href="logout.php">` and `<button id="theme-toggle">`:

```html
<button id="color-swatch" title="Click: new color · Right-click: previous"
        style="background:#D85A30"></button>
```

Same 24×24 rounded rectangle shape as the theme toggle. Left-click → generate new color via `set-user-color`. Right-click → cycle backwards through history (uses last 5 from `color_history`, oldest at the end, wraps to current after cycling through).

---

## 5. Color generation algorithm

Server-side, in `lib.php`:

```
generate_user_color(exclude_emails):
  forbidden = [AZZURRO] + [users[e].color for e in exclude_emails]
  for attempt in 1..50:
    hue = random(0, 360)
    if min_hue_distance(hue, forbidden) >= 25°: ok
  saturation = random(55%, 80%)
  lightness  = random(45%, 60%)
  return hsl_to_hex(hue, saturation, lightness)
```

If after 50 attempts no slot is found (only happens with ~15+ users), relax to 15° separation. This is a soft constraint — colors are aids, not identity.

---

## 6. State machine recap

```
open ──(deps satisfied)──► ready ──(claim)──► claimed ──(state→partly)──► partly
                              ▲                  │                          │
                              │(release/timeout) │                          │(any agent claims)
                              ◄──────────────────┘                          ▼
                                                                          claimed
                                                                            │
                                                                            ▼
                                                                          done

done ──(test fails / state→partly)──► partly
```

Claim timeouts:

- `agent` claim: 30 min wall-clock since `claimed_at`. Auto-revert to `ready`.
- `human` claim: 7 days. Email reminders at 3, 5, 6 days. Auto-revert at 7 days. (Email machinery uses the existing `mail()` helper.)

Open question #3: claim timeout reminders need a cron job. Defer to phase 2 — phase 1 just expires silently on read.

---

## 7. Permissions

Open question #2 details: for now, propose:

- **Read board**: any project member.
- **Claim/release/mark done**: any project member (humans) or agent with project secret.
- **Structural edit** (add/delete/reorder WPs and tasks, rename): owner + admin only. *Tentative — to be revisited when agent levels are designed.*
- **Edit own claimed task** (description, comments): always allowed.
- **Color change**: always allowed (own user only).

This keeps the planning layer locked-down for now, lets agents claim/work/complete freely. Easy to relax once agent levels exist.

---

## 8. Files to change

| File | Change |
|---|---|
| `src/lib/lib.php` | Add `generate_user_color()`, `kanban_load()`, `kanban_save()`, `kanban_resolve_expirations()`, `kanban_compute_states()`, `hue_distance()` helpers. |
| `src/ui/api.php` | Add 7 new endpoint cases (kanban, kanban-save, kanban-claim, kanban-release, kanban-state, set-user-color, user-colors). |
| `src/ui/index.php` | Add `<button id="color-swatch">` between logout and theme toggle. Pass user's current color to JS. |
| `src/ui/app.js` | Add `renderKanban()`, `tabBtn('kanban', 'Kanban')`, drag-drop handlers, task editor modal, color-swatch click handlers, `showKanban` integration. |
| `src/ui/style.css` | Kanban board CSS (WP columns, task cards, color states, parallel-group styling, drag-drop indicators, color-swatch button). |
| `src/admin/create-project.php` | On project creation, write initial `kanban.json` with one empty WP "Phase 0". |
| `src/lib/lib.php` (user create) | When creating a user, assign initial random color via `generate_user_color()`. |

**One-shot migration script** (`src/admin/migrate-add-kanban.php`):
- For every existing project: if no `kanban.json`, write `{wps: [{id:"wp_001", name:"Phase 0", tasks:[]}], next_wp_id: 2, next_task_id: 1, kanban_version: 1}`.
- For every existing user: if no `color`, assign one via `generate_user_color()`; set `color_history: [color]`.

---

## 9. Implementation phases

I propose splitting this into reviewable chunks. Each phase ends with a working deploy.

**Phase 1: backend foundation**
- lib.php helpers (kanban load/save, color generation)
- ui/api.php endpoints (all 7)
- create-project initial kanban.json
- migration script
- *No UI yet — verify with curl.*

**Phase 2: read-only Kanban view**
- New tab in app.js
- Render-only board (no editing)
- Color swatch in header (functional)
- *User can see the board and change their color.*

**Phase 3: editing**
- Add WP, add task, rename, delete
- Drag-drop reordering
- Right-click parallel grouping
- Task editor modal

**Phase 4: workflow**
- Claim / release / state transitions
- Comments
- Expiration on read
- Color resolution for actor-pinned tasks

**Phase 5 (later):** email reminders for human-task timeouts, agent level integration.

---

## 10. Open questions for Wil

1. **Tab position**: leftmost (before Files) or between Files and Chat? My instinct says between, but Kanban being the project plan is an argument for first.

2. **Permission classes for structural edits**: my proposal in §7 is owner + admin only for now. Is that what you have in mind, or should all project members be able to edit the plan?

3. **Claim reminders**: email at 3/5/6 days for human-claimed tasks. Should "claim" emit a chat post too (like "Wil claimed T1.4")? Symmetry would suggest yes; noise would suggest no.

4. **Phase 0 vs. import**: when a project is first created, do we always want exactly "Phase 0" with zero tasks, or should we provide a "starter template" picker (empty / single-WP / 7-WP-stages)? My default is plain empty Phase 0.

5. **What happens to a task pinned to a human user who is later deleted?** Proposal: `actor_email` is cleared, task falls back to "any human" capability. The color reverts to the neutral warm tone.

6. **Drag-drop on touch devices**: phase 3 default is desktop-only. Mobile gets read-only Kanban for v1. Acceptable?

Please answer these (or push back on any of the design), and I'll start Phase 1.
