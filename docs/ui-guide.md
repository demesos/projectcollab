# Web UI Guide

URL: `https://sulfurous.aau.at/~collab/ui/`
Auth: HTTP Basic (username: wilfried, password set via htpasswd)

## Structure
- `ui/index.php` — SPA shell (just loads style.css + app.js)
- `ui/style.css` — CSS variables for light/dark theme
- `ui/app.js` — all UI logic, ~860 lines vanilla JS, no dependencies
- `ui/api.php` — admin endpoints (protected by same .htaccess Basic auth)
- `ui/.htaccess` — HTTP Basic auth gate

## Admin API endpoints (ui/api.php)
All require HTTP Basic auth (browser handles this automatically).
```
GET  ?action=list-projects
GET  ?action=project&p=<name>       → includes agent secrets + last_seen
POST ?action=create-project         body: {name, display_name, description}
POST ?action=delete-project         body: {name}
POST ?action=add-agent              body: {project, name, role} → returns secret
POST ?action=remove-agent           body: {project, secret}
POST ?action=update-description     body: {project, description}
GET  ?action=backup&p=<name>        → streams tar.gz download
```

## SPA architecture (app.js)
Single global `state` object:
- `view` — 'list' | 'project'
- `project` — current project name
- `projectData` — fetched from admin API
- `ownerSecret` — used for agent API calls
- `tab` — 'files' | 'chat' | 'agents' | 'settings'
- `mode` — 'browse' | 'editing' (blocks polling when editing)

Polling runs every 5s via `setInterval` (constant `POLL_INTERVAL` at the top of
`app.js`). Skipped when `state.mode === 'editing'` or when any input/textarea/select
has focus.

`setMode('editing')` is called in:
- showFile() — viewing/editing a file
- showUploadFile() — upload form
- showAddAgent() — add agent form
- renderSettings() — settings tab (always editing)
- showCreateProject() — create project form

`setMode('browse')` is called when returning to list views or switching tabs.

## File viewer
- Filenames are clickable links (not buttons)
- Auto-detects binary (>10% non-printable bytes in first 512)
- Text files: editable textarea + Save button
- Binary files: hex dump only (address + hex + ASCII, 16 bytes/row)
- Toggle button switches text ↔ hex for text files

## Known issues
- New project "Project not found" error: caused by data/ dir missing write perms for www-data
  Fix: `chmod 770 /home/collab/data /home/collab/data/projects`
- Agent list not refreshing after add: fixed by refetching projectData after adminPost
- Auto-refresh cancelling file view: fixed by mode system above
