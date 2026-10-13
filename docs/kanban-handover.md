# Kanban & UI Implementation — Handover

**From:** Gustav (third instance, Kanban Implementer)
**Date:** 2026-05-27
**Status:** Phases 1, 2, and 3.1–3.2 complete and deployed. Phase 3.3+ not started.

---

## Completed work — current server versions

| File | Version | What changed |
|------|---------|-------------|
| `src/lib/lib.php` | v3 | Phase 1: all Kanban + color helpers |
| `src/ui/api.php` | v26 | Phase 1: 9 Kanban endpoints; Phase 1: `has_kanban` in getProject; **v26: filename charset validation added to uploader** |
| `src/api/index.php` | v12 | **v11: spaces allowed in paths; v12: expanded charset `()+'!,@=~` allowed; meaningful error messages** |
| `src/admin/migrate-kanban-colors.php` | v2 | One-shot migration — already run, all users have colors |
| `src/ui/index.php` | v8 | Phase 2.1: `color`/`color_history` in `CURRENT_USER`; `<button id="color-swatch">` in header |
| `src/ui/style.css` | v18 | Phase 2.1: swatch; Phase 2.3: Kanban board grid+cards; Phase 3.1: add buttons; Phase 3.2: inline rename input + parallel toggle |
| `src/ui/app.js` | v43 | Full Kanban UI through Phase 3.2 + scroll preservation fix (see below) |
| `docs/kanban-plan.md` | v1 | Full design doc — read this first |

---

## What is done and working

### Filename validation (completed this session)

Two validators were out of sync:
- `src/api/index.php` (agent API) had a strict ASCII-only whitelist that blocked spaces and common characters like `()`.
- `src/ui/api.php` (web UI uploader) had no charset check — accepted anything, meaning the agent API would later refuse to serve what the web UI uploaded.

Both are now in sync with the same positive whitelist:

**Allowed:** `a-zA-Z0-9 . _ - / SPACE ( ) + ! ' , @ = ~`

**Blocked (with reason in the error message):**
- `%` — URL-encoding ambiguity
- `#` `?` — URL-structural characters browsers truncate silently
- `* \ & ; | \` < > "` — shell-dangerous or Windows-forbidden

The agent API returns a human-readable error listing the allowed characters. The UI uploader returns the same message so users get clear feedback during upload.

Regex used in both: `#^[a-zA-Z0-9._/\x20()+!\'\,@=~-]+$#`

### Scroll preservation (completed this session)

All auto-refreshing list views now preserve the user's scroll position:

- **Chat tab:** `scrollTop` is snapshotted before each 5s rebuild and restored after. The only exception is when the user just posted (`postChat` sets `state.chatJumpToNewest = true`) — in that case the next refresh jumps to the tail so they see their message land. The old always-scroll-to-bottom behavior is gone.
- **Files tab, Agents tab:** `window.scrollY` is preserved across `pane.innerHTML = ''` and rebuild.
- **Project list:** same, defensive treatment for the `tbody` swap.

### Color swatch in header (Phase 2.1)

`<button id="color-swatch">` between Log out and ◐ theme toggle. Left-click → `POST set-user-color` (server generates a fresh color with ≥25° hue separation). Right-click → `POST cycle-user-color` (cycles through last 5). Background updates optimistically; tooltip shows history. CSS: same shape as `#theme-toggle`.

### Kanban tab (Phases 2.2, 2.3, 3.1, 3.2)

**Settings toggle (Phase 2.2):** "Kanban board" section in Settings, owner/admin only. Checkbox calls `POST toggle-kanban`. On change, the tab strip is surgically replaced in-place (`buildTabStrip(data)`) — user stays on Settings. No full project reload.

**Read-only board (Phase 2.3):**
- CSS grid `repeat(auto-fit, minmax(180px, 1fr))` of WP columns.
- `kanbanComputeLabels(wp, wpIndex)` — display labels `T1.1`, `T1.1a/b/c` for parallel groups.
- `kanbanTaskCard(task, label, userColors, isFirst)` — state icon (⌛●✓↺), label, parallel toggle (∥/·), H/A capability glyph, task name (2-line clamp), claimer initials, comment count.
- State visual treatment via CSS `filter: saturate() brightness()` — no need to know actor hex.
- Refresh button in top bar. `state.kanbanData` and `state.kanbanVersion` stored for write paths.

**Add WP / Add task (Phase 3.1):**
- `[+ New WP]` cell at end of board grid — `prompt()` for name, appends WP, calls `kanbanSaveBoard`.
- `[+ Add task]` button at bottom of every WP column — appends a default agent task (name='New task', state=ready), calls `kanbanSaveBoard`.
- `kanbanSaveBoard(wps, nextWpId?, nextTaskId?)` — wraps `POST kanban-save` with optimistic concurrency (`expected_version`). On 409 re-fetches and warns user.

**Inline rename + parallel toggle (Phase 3.2):**
- `makeEditable(node, currentValue, onSave)` — double-click turns any text node into an input. Enter/blur → commit; Escape → cancel. Adds `.editing` class to node, which drops the `-webkit-line-clamp` so the input is a comfortable full-width box. `setMode('editing')` blocks polling during edit.
- WP name and task name are both editable via double-click.
- `kanbanToggleParallel(taskId)` — flips `parallel_with_prev`, calls `kanbanSaveBoard`. Button shows `∥` when grouped, dim `·` when standalone. Disabled on first task of each WP.

---

## Phase 3 — what's NOT done yet

The plan for remaining Phase 3 checkpoints:

### 3.3 — Task editor modal
A modal dialog for multi-field task editing:
- **Description** (textarea — the name is just a label, description is the full brief)
- **Capability** toggle: A (agent) ↔ H (human)
- **Pinned actor** — optional email for human tasks; for agent tasks, selecting from the project's agent list (stored as `actor_email` on the task)
- **Comments** — read-only list from `task.comments[]`

The modal should be opened from the task card (click, not double-click — double-click is rename). A small edit icon on hover or a single-click on the card body (not the name) works. The modal calls `kanban-save` with the full wps array on Save.

### 3.4 — Right-click context menu (or button row)
- Delete task
- Delete WP (only if empty, or with a confirmation)
- Move task to different WP (or defer to drag-drop)

### 3.5 — Drag-drop reordering
Native HTML5 DnD. A task that is the start of a parallel group drags the whole group. Drop zones are thin lines between tasks and between WP columns. This is the most complex UI piece — leave for last.

---

## Phase 4 — claim/release/state wiring

All endpoints are live (`kanban-claim`, `kanban-release`, `kanban-state`). UI work:
- Small action toolbar that appears on task card click/hover (or in the modal): Claim, Release, Mark done, Mark failed.
- `kanban-claim`: 409 returns current claimer's email — surface "Already claimed by X" in UI.
- `kanban-state` transitions: `claimed→done|partly`, `partly→claimed`, `done→partly`, `ready→claimed`.

---

## Key file locations on server

All UI assets install to `~collab/public_html/ui/`:
```bash
sudo install -o collab -g collab -m 644 <file> ~collab/public_html/ui/<file>
```

The agent API installs to `~collab/public_html/api/`:
```bash
sudo install -o collab -g collab -m 644 index.php ~collab/public_html/api/index.php
```

`lib.php` installs to `/home/collab/data/lib/lib.php` with `-o root` (readable by www-data group):
```bash
sudo install -o root -g www-data -m 640 lib.php /home/collab/data/lib/lib.php
```

---

## Gotchas / decisions made

- `parallel_with_prev: true` on the *first* task in a WP is a schema violation — server validation should reject it, UI disables the toggle button for position 0.
- The Kanban tab only appears when `data.has_kanban === true` in the `GET project` response. `buildTabStrip(data)` is the helper to rebuild it — used on first render and on toggle.
- `kanbanSaveBoard` always increments `next_task_id` / `next_wp_id` — these are sent back to the server so it can validate stable-ID allocation. If you add a batch-add feature, make sure to increment correctly.
- State visual treatment uses CSS `filter` — watch for stacking-context issues if you add `box-shadow` or `transform` effects in Phase 3/4 hover states.
- `state.chatJumpToNewest` is a one-shot flag — set in `postChat`, consumed and cleared in `refreshChat`. Don't add more uses of it; the point is that polling never scrolls on its own.
- The two `validateFilePath` functions (agent API and UI API) are now in sync but are not shared code. If the allowed charset needs changing again, update **both**: `src/api/index.php` and `src/ui/api.php`.

---

## How to start

1. Read this doc and `docs/kanban-plan.md` (especially §3 state machine and §6 claim/release transitions).
2. Read chat msgs #61–#69 for the session history.
3. Verify Phase 3.2 is healthy: open a project with Kanban enabled, double-click a WP name and a task name, check the parallel toggle (∥/·) on multi-task WPs.
4. Start **Phase 3.3** (task editor modal). The design is above. The modal pattern already used in the codebase: search for `showAddAgent` and `showCreateFile` in `app.js` — they use the `.modal-overlay` + `.modal-card` pattern already styled in `style.css`.
5. Check file versions before every PUT — Emil edits `app.js` frequently.

— Gustav (third instance)
