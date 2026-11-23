# Web UI Guide

URL: `https://your-server.example/~collab/ui/`
Auth: PHP session login (`login.php`, users in `data/users.json`, roles admin/developer/collaborator;
password reset via `forgot.php` / `reset.php`). Every PHP page and `api.php` calls
`require_login()` from `data/lib/lib.php`.

## Structure
- `ui/index.php` — SPA shell (loads style.css + app.js, injects `window.CURRENT_USER`)
- `ui/style.css` — CSS variables for light/dark theme
- `ui/app.js` — all UI logic, vanilla JS, no dependencies
- `ui/api.php` — session-authenticated API for the UI (endpoint list in its header comment);
  file/chat/news calls are proxied with the project's owner agent, the browser never sees that secret
- `ui/skill-download.php` — builds `projectcollab.skill` from `data/skill-src/`

Apache `mod_userdir` blocks PUT/DELETE, so the UI sends them as POST with
`X-HTTP-Method-Override` (see `uiApi()` in app.js).

## SPA architecture (app.js)
Single global `state` object: `view` ('list' | 'project' | 'skill' | …), `project`,
`projectOwner`, `projectData`, `tab` ('files' | 'kanban' | 'chat' | 'agents' | 'share' |
'settings'), `mode` ('browse' | 'editing').

Polling runs every 5 s (`POLL_INTERVAL`). Skipped when `state.mode === 'editing'` or
when an input/textarea/select has focus (file-list checkboxes are exempt).
`setMode('editing')` is used by the file viewer, create/upload forms, add-agent and
create-project forms.

## Files tab
- Default sort: newest change first; click a column header to change it
- Toolbar: + Upload File, + Create File, Download selected (zip), Delete selected
- Checkboxes per file + select-all. The selection lives in `fileSelection` (by path), so
  it survives the list rebuild on every poll; deleted files drop out of it
- Row buttons: download, re-upload (replace with a new version), delete
- Drag & drop: onto the list = upload new files (dialog with optional target folder),
  onto a row = replace that file, onto the upload form = choose the file
- Batch dialogs (`showFilesDialog`) freeze list rebuilds (`filesFrozen()`) and re-fetch
  versions when they open; deletes/replaces send the version shown in the dialog, so a
  file changed meanwhile is skipped and listed instead of being overwritten
- File names must match the server rules (letters a–z, digits, spaces,
  `. _ - ( ) + ! ' , @ = ~ /`; no leading `/`; no name starting with `~`; max 200 characters). `filePathProblem`
  in app.js mirrors `validateFilePath` in ui/api.php and api/index.php — change all three
  together. Forms show the problem inline and suggest an allowed name (`safeFileName`)
- Upload form: blank path = file name; a path ending in `/` = that folder + file name
- Deleting removes the file content; the tombstone stays in versions.json
- Uploads: max 200 MB per file (`MAX_UPLOAD_BYTES`), checked before sending; sent with
  `uploadFileBody()` (XMLHttpRequest, for the progress display) — not via `uiApi()`
- `uiApi()` throws for every non-OK response, also when the server returns no JSON
- "↩ Restore backup" (Projects page): raw body via `xhrUpload()` with progress, max 2 GB
  (`MAX_RESTORE_BYTES`); conflict → Overwrite / Rename (re-sends the file)

## File viewer
- Files below 32 KB open directly; larger ones offer a choice of view or download
- Text files: editable, Save button; binary files: hex view/editor
- Unsaved edits are guarded on tab switch and browser back

## Settings tab
- Description (any member), Kanban toggle + claim timeouts (owner/admin), Backup (owner/admin)
- Danger zone: Leave project (members), Rename project and Delete project (owner/admin)
- Rename changes only the display name (`meta.json` `name`, action `rename-project`,
  1–100 characters, single line). The URL slug is the project's directory name and is in
  every agent URL and guest-worker slot, so it is never changed — renaming is safe while
  agents are working. Other open browser tabs show the new name after their next reload

## Deployment notes
- Back up changed live files first (`~/backup-YYYYMMDD-<topic>/`), replace atomically
  (copy to a temp name in the same folder, then `mv`)
- Files under `data/` must be group `www-data`; project folders are created by
  www-data and are not writable by the collab account — data migrations run as
  an admin-gated script
