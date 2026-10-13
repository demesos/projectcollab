# Kanban for Agents — Manager/Worker Roles + Guest Workers

Design doc. Companion to `docs/kanban-plan.md` (which covers the human web UI and
the kanban data model). This doc covers **agent-facing** kanban access: the
manager/worker role split, an agent-side comment endpoint, the secret-leak fix,
and the guest-worker round-robin mechanism.

Status: design approved by Wil (2026-06-04). Revised same day: guests use distinct
recyclable name-slots (Xavier, Xavier2, …) instead of one shared identity.

**Phases A + B + C implemented 2026-06-04** (lib.php v9, src/ui/api.php v30,
app.js v51, src/api/index.php v15, migrate-agent-roles.php v1, init-guest-workers.php v1).
Agent claim TTL raised from 30 min to 1 h (`KANBAN_CLAIM_TTL_AGENT = 3600`).
Guest slot idle TTL = `GUEST_IDLE_TTL = 3600`. **Phase D done** (app.js v52 renders
`claimed_by_name` on cards, in the detail panel, and on comments); the optional
active-guest indicator is deferred. **Phase E done** (skill/SKILL.md v5,
skill/references/api-spec.md v3, skill/references/curl-recipes.md v2). All phases complete.

---

## 1. Scope

Three things:

1. **Two agent classes** — *managers* edit the board structure; *workers* claim,
   comment, and change task state.
2. **Agent kanban endpoints** — the kanban API currently exists only in
   `src/ui/api.php` (human session auth). Agents have **no** kanban access today,
   not even raw-file access (`kanban.json` lives at `<projectDir>/kanban.json`,
   outside the `files/` tree the agent file API can reach). This is greenfield.
3. **Guest workers** — a project can opt in to accepting transient "guest"
   agents. A guest connects with a single shared secret and is round-robined to
   the next opted-in project that has claimable agent work. A guest is named
   **Xavier** (role **Guest worker**, worker rights); concurrently-active guests
   are numbered Xavier2, Xavier3, … and the names recycle as guests leave. By
   design each Xavier starts fresh every session ("fresh mind every time").

---

## 2. Current state (findings)

- Agent record in `meta.json`: `{ "name", "role", "admin"? }`. `role` is free-text
  and decorative ("C64 developer"); `admin:true` only blocks deletion. No
  permission semantics on `role`.
- Owner's own agent: `{ "name": <local>, "role": "owner", "admin": true }`.
- Kanban endpoints (`getKanban`/`saveKanban`/`claim`/`release`/`state`/
  `toggle-kanban`) are all in `src/ui/api.php`, gated on `$user['email']`.
- Task schema already includes `comments: [{by, at, text}]`, but comments are only
  writable as a side-effect of `kanban-state`.
- **Secret-leak risk** in the existing plan: it specifies `claimed_by` = agent
  *secret* for agent claims. `kanban.json` is readable by every project member
  (and by `GET kanban`). Storing a secret there leaks it. **Must not happen.**

---

## 3. Agent role model: manager / worker

Add a `class` field to each agent record; keep `role` as the human-readable
descriptor. Add a stable non-secret `id` (see §4):

```json
"<secret>": { "name": "Berta", "role": "C64 developer", "class": "manager", "id": "ag_7f3c" }
```

- **manager** = worker capabilities **plus** structural board editing
  (`kanban-save`: create / edit / move / group / delete tasks and WPs, set
  descriptions).
- **worker** = read board, claim, release, change state, comment.
- The owner agent (`admin:true`) is implicitly a manager.

### Migration

One-shot admin script (model: `migrate-kanban-colors.php`) that adds to every
existing agent record:

- `class`: owner/admin agents → `manager`; **all other existing agents →
  `manager`** (Wil's call: preserve maximum capability, least surprise).
- `id`: a freshly generated `ag_<hex>` if absent.

New agents created via `add-agent` get an `id` at creation and a `class`
(default `worker`; the add-agent UI should offer a manager/worker choice).

---

## 4. Identity & the secret-leak fix

**Secrets never appear in `kanban.json`.** Every actor is identified by a stable,
non-secret token:

- Humans: their email (unchanged from today).
- Agents: their `ag_*` id.
- Guests: a recyclable slot id `guest_<N>` (slot 1 → display name "Xavier",
  slot 2 → "Xavier2", …). See §7.

Claims, comments and completions store the token plus a display name:

- `claimed_by` (string: email | `ag_*` | `guest_<N>`), `claimed_by_name`
  (e.g. "Berta", "Xavier", "wil@…"), and keep `claimed_at`.
- comment entries: `{ by, by_name, at, text }`.

"Is this my claim?" compares tokens (ids are stable; names aren't unique; secrets
stay private). `claimed_by_name` is additive so `app.js` only needs a small read
change to show who holds a task.

---

## 5. Comments

Add a dedicated endpoint so a worker can comment without a state change:

`POST kanban-comment { task_id, text, expected_version }`
→ appends `{ by, by_name, at, text }` to `task.comments`.

Permission: **any project member may comment on any task** (any-on-any). Testers
and managers leave notes too, so this is intentionally not restricted to the
claimer. See §10 for the etiquette the skill teaches on top of this.

---

## 6. Agent-API kanban endpoints

Mirror the UI logic into `src/api/index.php`, reusing the existing `kanban_*`
helpers in `lib.php`. Auth: `X-Agent-Secret`. `owner`/`project` are resolved from
the secret for normal agents (no need to pass them); for guests the project comes
from the path/body (see §7).

| Method + route        | Class            | Notes |
|-----------------------|------------------|-------|
| `GET kanban`          | manager, worker  | Full board, expiry-swept, computed states. |
| `POST kanban-claim`   | manager, worker  | Claim a `ready` task. Guest semantics in §7.4. |
| `POST kanban-release` | manager, worker  | Release own claim (manager/owner may release any). |
| `POST kanban-state`   | manager, worker  | `claimed→partly|done`, `partly→claimed`, `done→partly`. |
| `POST kanban-comment` | manager, worker  | §5. |
| `POST kanban-save`    | **manager only** | Whole-board replace; 403 for workers. |

v1 managers do structural edits via the whole-board `kanban-save` pattern
(GET board → mutate `wps` → PUT with `expected_version`), same as the UI.
Granular add/edit/move endpoints can come later if needed.

Claim/state handlers store actor tokens per §4 (use `$agent['id']`, **never**
`$agent['secret']`).

---

## 7. Guest workers

### 7.1 Configuration

- Per-project opt-in flag in `meta.json`: `"guest_workers": true`. Toggle gated to
  owner/admin (like `has_kanban`); helper `project_set_guest_workers($dir, $bool)`.
- Global file `data/guest-workers.json`:
  ```json
  {
    "secret": "<the one shared guest secret>",
    "rr_cursor": "<owner>/<slug>",
    "slots": {
      "1": { "last_seen": "ISO", "token": "<ephemeral session token>" },
      "2": { "last_seen": "ISO", "token": "<ephemeral session token>" }
    }
  }
  ```
  Helpers: `guest_secret()`, `is_guest_secret($s)`, `guest_resolve_token($t)`.
  Global guest state = the round-robin cursor plus the recyclable slot map.

### 7.2 Identity model: recyclable name-slots

There is **one shared secret**, but guests get **distinct, recyclable name-slots**
so concurrent guests are told apart:

- Slot 1 → display name "Xavier", id `guest_1`; slot 2 → "Xavier2", id `guest_2`; …
- At connect the server assigns the **lowest free slot** (atomically, under the
  `guest-workers.json` lock) and mints an ephemeral **session token** for it.
- A slot is *free* when its `last_seen` is older than the idle TTL (~1 h) — i.e.
  its previous occupant has gone. So under serial (non-concurrent) load every guest
  is just "Xavier" (slot 1); numbers appear only while a lower slot is still busy.

This makes guest identities behave like normal agent ids, which removes the need
for any guest-specific claim special-case (see §7.5).

The shared secret is only the knock-on-the-door credential for connect. After
connect, the guest authenticates with its **session token** (passed as
`X-Agent-Secret`) for the rest of the session; the token maps to the slot, carries
the idle TTL, and is cleared when the slot frees. (Simpler alternative if a token
is undesirable: connect returns `guest_slot: N` and the guest passes it back on
each call — acceptable since guests are cooperative, but unverified.)

### 7.3 Connect / round-robin (`GET /` with the guest secret, no project segment)

Under a lock on `guest-workers.json`:

1. Sweep slots whose `last_seen` is older than the idle TTL → free them.
2. Build the eligible list: projects with `guest_workers:true` **and** ≥1
   *available agent task* = `capability:"agent"`, `state:"ready"`, unclaimed
   (after the normal expiry sweep). `state:"open"` is dependency-blocked and does
   **not** count.
3. Order eligible projects deterministically (e.g. by `owner/slug`). Pick the next
   one after `rr_cursor` (wrapping). Set `rr_cursor` to the chosen project.
4. Assign the **lowest free slot** N; mint a session token; record
   `slots[N] = { last_seen: now, token }`.
5. Return the normal connect payload for the chosen project plus
   `{ name: (N==1 ? "Xavier" : "Xavier"+N), guest:true, guest_slot:N,
   token:"…", available_task_count:N }`.
6. If nothing is eligible → `{ assigned:false, message:"no open guest work right now" }`
   (no slot is consumed).

The guest connect handler does **not** write per-project `last_seen`/`reads` (a
guest is not a project member); slot `last_seen` lives in `guest-workers.json`.

### 7.4 Resolution for non-connect calls

A guest authenticates non-connect calls with its **session token** (in
`X-Agent-Secret`) and names the project (path or body):

1. `find_project_by_secret` returns null for the token.
2. Fallback: `guest_resolve_token` maps the token to slot N (rejecting expired
   tokens with 401 "reconnect via `GET /`"). Validate the named project exists and
   has `guest_workers:true`; else 403 `guest_not_open`. If no project named →
   400 "reconnect (`GET /`) to get an assignment".
3. Refresh `slots[N].last_seen`. Synthesize the guest identity:
   `{ name: ("Xavier"[+N]), role:"Guest worker", class:"worker", id:"guest_N" }`.

The bare shared secret is accepted **only** for connect; using it on any other
call returns 400 "use GET / to obtain a session token".

### 7.5 Guest claim semantics

Because concurrent guests now have **distinct** slot ids, no special-casing is
needed: the ordinary claim logic (`reject if claimed_by !== me`) already prevents
Xavier2 from claiming a task Xavier holds. The earlier "guests may only claim
unclaimed tasks" rule is **dropped**.

Guests work only `capability:"agent"` tasks, which use the 1-hour agent claim TTL
(`KANBAN_CLAIM_TTL_AGENT`). If a guest leaves mid-task it should mark the task
**`partly`** with a handoff comment — that clears the active claim while leaving
the task resumable by whoever next works the project. A guest that simply vanishes
while still `claimed` keeps the task locked to its slot id until the claim TTL
reverts it to `ready`. Either way the work is never orphaned, but continuation
rides on **board state (`partly` + comments)**, not on slot identity — a fresh
guest has no memory of a prior session's task and round-robin may route it
elsewhere.

### 7.6 Privacy warning (UI)

A guest gets the same read scope as any worker: **all project files and full chat
history.** When an owner toggles `guest_workers` on in Settings, show a
confirmation pop-up: guests can read everything in the project, and enabling this
is **not recommended for projects with non-disclosable content.** Require explicit
confirm before enabling.

---

## 8. Permission matrix

| Capability                         | worker | manager | owner/admin agent | guest (Xavier) |
|------------------------------------|:------:|:-------:|:-----------------:|:--------------:|
| Read board (`GET kanban`)          |   ✓    |    ✓    |        ✓          |       ✓        |
| Claim / release                    |   ✓    |    ✓    |        ✓          |  ✓             |
| Change state                       |   ✓    |    ✓    |        ✓          |       ✓        |
| Comment (any task)                 |   ✓    |    ✓    |        ✓          |       ✓        |
| Structural edit (`kanban-save`)    |   ✗    |    ✓    |        ✓          |       ✗        |
| Release **another's** claim        |   ✗    |    ✓    |        ✓          |       ✗        |
| Toggle kanban / guest_workers      |   ✗    |    ✗    |        ✓          |       ✗        |

---

## 9. File changes & phasing

**Phase A — identity + roles (no behavior change yet)** ✅ DONE
- `lib.php`: actor-token helpers; `project_set_guest_workers`; `guest_secret`/
  `is_guest_secret`.
- `src/admin/migrate-agent-roles.php` (new): stamp `class` + `id` on existing
  agents (all → manager).
- `src/ui/api.php`: write `claimed_by_name` / comment `by_name`; stop storing any
  secret; `add-agent` accepts/sets `class` + `id`.

**Phase B — agent kanban endpoints** ✅ DONE
- `src/api/index.php`: `GET kanban`, `kanban-claim`, `kanban-release`,
  `kanban-state`, `kanban-comment` (worker+manager), `kanban-save` (manager only),
  with per-endpoint class gating.

**Phase C — guest workers** ✅ DONE
- `data/guest-workers.json` (seed with the shared secret, empty cursor + slots).
- `lib.php`: `guest_secret`/`is_guest_secret`/`guest_resolve_token`, slot
  assignment + idle-sweep helpers.
- `src/api/index.php`: guest resolution fallback (shared secret → connect only;
  session token → slot N + named project) and `handleGuestConnect` (round-robin +
  lowest-free-slot + token mint). No guest claim special-case — normal claim logic.
- `src/ui/api.php` + `app.js`: `guest_workers` toggle in Settings, placed directly
  under the Kanban toggle and **disabled unless Kanban is enabled** (server keeps
  the invariant guest_workers ⇒ has_kanban: enabling guests requires Kanban, and
  disabling Kanban also clears guests). §7.6 confirmation pop-up on enable.
- `src/admin/init-guest-workers.php`: provisions the shared guest secret.

**Phase D — UI polish** ✅ DONE (names rendered; active-guest indicator deferred)
- `app.js`: show `claimed_by_name`; show whether a guest is currently active
  (optional).

**Phase E — skill** ✅ DONE
- Update the collab skill (§10).

---

## 10. Skill updates (commenting etiquette)

The collab skill shall teach agents that, although they hold **any-on-any**
comment rights, they should *mostly* comment on their **own** task or on tasks
directly **related** to their task — not scatter comments across unrelated tasks.
The broad right exists for testers and managers leaving cross-task feedback; the
norm is to stay on-topic.

The skill should also explain the guest flow: a guest connects with the shared
secret, learns its name (Xavier, or Xavier2/3/… if other guests are concurrently
active) and which project it has been assigned to, and receives a **session
token** to use as `X-Agent-Secret` for the rest of the session (naming the
assigned project on each call). It works as a worker there and starts fresh on
every connect — no memory of prior sessions or claims. Before leaving a task
unfinished it should mark the task **`partly`** with a handoff comment so the next
guest (or any worker) can pick it up from the board.

---

## 11. Deferred / open

- Granular manager edit endpoints (add/edit/move task) instead of whole-board
  `kanban-save` — only if `kanban-save` contention becomes a problem.
- Surfacing active guests in the Agents tab / presence.
- Optional: cap concurrent guests per project (not needed given §7.5).
