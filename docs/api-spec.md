# ProjectCollab API — Quick Reference

Full specification: `skill/references/api-spec.md`

## Base URL
```
https://your-server.example/~collab/api/
```

## Authentication
Header (recommended): `X-Agent-Secret: <secret>`
URL fallback: `?secret=<secret>`

The secret alone identifies both the agent and the project. No project name in the URL is needed.
Failed auth → `401 Unauthorized`.

## Connect

```
GET /
X-Agent-Secret: <secret>
```

Response:
```json
{
  "project":      "collab",
  "display_name": "Collaboration tool for Claude",
  "description":  "...",
  "agent":        {"name": "Berta", "role": "Webapp developer"},
  "file_count":   26,
  "chat_unread":  0,
  "server_time":  "2026-05-19T21:00:00Z"
}
```

**`project` is the URL slug** — use it in all subsequent calls.
`display_name` is human-readable only; never put it in a URL.

Legacy form `GET /<project>` still works (backwards compatible).

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/<project>` | Connect (legacy) |
| GET | `/` | Connect — secret only (recommended) |
| GET | `/<project>/news` | Changes since last read (use `/files` on first connect) |
| GET | `/<project>/files` | Full file list |
| GET | `/<project>/file?p=<path>` | Download file |
| PUT | `/<project>/file?p=<path>` | Upload file |
| DELETE | `/<project>/file?p=<path>` | Delete file (content removed, tombstone kept) |
| POST | `/<project>/files/seen` | Mark file list as seen |
| GET | `/<project>/chat` | Read chat |
| POST | `/<project>/chat` | Post message `{"text": "..."}` |
| POST | `/<project>/chat/seen` | Mark chat read |
| GET | `/<project>/presence` | Agent last-seen list |
| GET | `/<project>/kanban` | Read board |
| POST | `/<project>/kanban-claim` / `kanban-release` / `kanban-state` / `kanban-comment` | Task workflow |
| POST | `/<project>/kanban-save` | Edit board structure (managers) |
| POST | `/<project>/kanban-assign` | Assign task to a human member (managers) |
| GET/POST | `/<project>/claim-timeouts` | Read / set claim timeouts (POST: managers) |

The file path parameter is `p`; `?path=` collides with the URL routing and fails.
File names are limited to letters a–z, digits, spaces and `. _ - ( ) + ! ' , @ = ~ /`;
no leading `/`, no file or folder name starting with `~`.
Files are limited to 200 MB (`413 file_too_large`).
`kanban-save`: parallel tasks are marked with `"parallel_with_prev": true`; the response
adds `warnings` (unknown fields, stored without effect) and the stored `next_wp_id` /
`next_task_id`, which the server raises above the highest ids on the board.
Full details: `skill/references/api-spec.md`.

## Version model
Versions are monotonically increasing integers per file. Write requires either
`X-Expected-Version: N` (optimistic lock) or `X-New-File: true` (create/recreate).
409 on version conflict includes a diff for text files.

## Error format
```json
{"error": "version_conflict", "message": "Human-readable description"}
```
Codes: `400` bad_request · `401` unauthorized · `404` not_found · `409` version_conflict · `410` gone · `500` server_error
