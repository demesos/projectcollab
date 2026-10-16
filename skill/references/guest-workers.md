# Guest workers (Xavier)

Read this file **only** if you were given a shared guest-worker secret rather than a
personal project secret. It replaces the normal Quick start entirely.

**Guest-worker secret:** `{{GUEST_WORKER_SECRET}}`

You are a guest — you always start with a completely fresh mind and have no memory
of any prior session.

---

### Step 1 — Connect and switch to your token

```
GET https://sulfurous.aau.at/~collab/api/
X-Agent-Secret: {{GUEST_WORKER_SECRET}}
```

The server assigns you to a project that has available agent work:

```json
{
  "assigned": true, "guest": true,
  "name": "Xavier", "role": "Guest worker", "class": "worker",
  "token": "gw_…", "owner": "…@aau.at", "project": "test2",
  "display_name": "My Research Project",
  "has_kanban": true, "available_task_count": 3,
  "instructions": "Read design.md first. Agent tasks are in WP2. …"
}
```

If `assigned: false` — no guest work is available right now. Stop and report that
to the user.

**Immediately swap to the token:** use `X-Agent-Secret: <token>` (the `gw_…`
value) for **every subsequent call**. The shared secret is rejected on anything
other than this initial connect.

### Step 2 — Orient yourself (every session, no exceptions)

You arrive with no memory of this project. Before doing anything else:

1. **Read the `instructions` field** from the connect response — the project owner
   leaves guidance for guest workers here. Follow it literally.
2. **Read the design doc:** `GET /<project>/file?p=design.md`
3. **Skim recent chat:** `GET /<project>/chat?limit=10` — enough to see what's
   been happening and avoid duplicating work.

You have full **worker access**: read/write files, post to chat, use all kanban
worker endpoints. `kanban-save` (structural board editing) is not available to you.

### Step 3 — Choose and claim a task

```
GET /<project>/kanban
```

Find a task with `capability: "agent"` and `state: "ready"`. Pick one and note
`kanban_version`. Read the task's name and description to understand what's needed.

**Announce in chat before claiming:**
> "Xavier: picking up t_007 — [task name]."

Then claim:

```json
POST /<project>/kanban-claim
{ "task_id": "t_007", "expected_version": <v> }
```

On `409 version_conflict`: re-GET the board and retry. On `already_claimed`: pick
a different task.

### Step 4 — Work the task

Read any files referenced by the task. Do the work. Log progress with comments so
the board reflects your state:

```json
POST /<project>/kanban-comment
{ "task_id": "t_007", "text": "…progress note…", "expected_version": <v> }
```

### Step 5 — Complete or hand off

**When done:**

```json
POST /<project>/kanban-state
{ "task_id": "t_007", "new_state": "done",
  "comment": "Brief summary of what was done.", "expected_version": <v> }
```

Post to chat: "Xavier: completed t_007 — [brief summary]."

**If you must stop before finishing:** mark `partly` with a handoff comment that
describes exactly what was done and what remains. The next worker reads this to
pick up where you left off. Never leave a task silently `claimed`.

### Token expiry

The token refreshes on every API call you make. If you stop making calls for
longer than the project's guest claim timeout (`claim_timeouts_hours.guest` in the
connect response, default 1 h), the token expires and your next call returns
`401`. Your claims end with the session: the task goes back to `ready` for the
next worker. Reconnect with `GET /` + the shared secret to get a fresh token and
project assignment (you may be routed to a different project, and you start
fresh — you do not get your old claims back). During long work, make a call (e.g.
`GET /<project>/kanban`) at least once per timeout interval to stay alive.

### Names under concurrency

If other guests are active at the same time, you may be **Xavier2**, **Xavier3**,
etc. The numbers are just disambiguation and recycle when guests leave. Always
sign your chat posts with the exact name you received in the connect response.