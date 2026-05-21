// ProjectCollab — Single Page App
// Vanilla JS, no dependencies.

const API = '../api';           // agent API base
const ADMIN = 'api.php';        // admin API base
const POLL_INTERVAL = 5000;     // 5s

let state = {
    view: 'list',
    project: null,        // current project name
    projectOwner: null,   // email of project owner
    projectData: null,    // full project info from admin API
    ownerSecret: null,    // for calling agent API
    tab: 'files',
    mode: 'browse',       // 'browse' | 'editing' — blocks polling when editing
    pollTimer: null,
};

let fileSortKey  = 'path'; let fileSortDir  = 1;
let projSortKey  = 'name'; let projSortDir  = 1;
let userSortKey  = 'role'; let userSortDir  = -1;  // admin-first by default

// --- Theme ---------------------------------------------------------------

function initTheme() {
    const stored = localStorage.getItem('theme');
    if (stored) {
        document.documentElement.setAttribute('data-theme', stored);
    } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
}

document.getElementById('theme-toggle').addEventListener('click', () => {
    const current = document.documentElement.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
});

// --- HTTP helpers --------------------------------------------------------

async function adminGet(action, params = {}) {
    const url = new URL(ADMIN, location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const res = await fetch(url);
    if (!res.ok) throw new Error((await res.json()).error || res.statusText);
    return res.json();
}

async function adminPost(action, body) {
    const url = new URL(ADMIN, location.href);
    url.searchParams.set('action', action);
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || res.statusText);
    return data;
}

async function agentApi(path, options = {}) {
    if (!state.ownerSecret) throw new Error('No owner secret available');
    const url = `${API}/${state.project}${path}`;
    const headers = { 'X-Agent-Secret': state.ownerSecret, ...(options.headers || {}) };
    const res = await fetch(url, { ...options, headers });
    if (res.status === 204) return null;
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
        const data = await res.json();
        if (!res.ok) throw new Error(data.message || data.error || res.statusText);
        return data;
    }
    return { raw: await res.arrayBuffer(), headers: res.headers };
}

// --- Utilities -----------------------------------------------------------

function el(tag, props = {}, ...children) {
    const e = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
        if (k === 'class') e.className = v;
        else if (k === 'on') for (const [ev, fn] of Object.entries(v)) e.addEventListener(ev, fn);
        else if (k === 'html') e.innerHTML = v;
        else if (v !== null && v !== undefined && v !== false) e.setAttribute(k, v);
    }
    for (const c of children.flat()) {
        if (c === null || c === undefined || c === false) continue;
        e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return e;
}

function fmtTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString();
}

function fmtRelative(iso) {
    if (!iso) return 'never';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
    return `${Math.floor(diff/86400)}d ago`;
}

function statusDot(iso) {
    if (!iso) return el('span', { class: 'status-dot never', title: 'never connected' });
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    const cls = diff < 300 ? 'active' : 'idle';
    return el('span', { class: `status-dot ${cls}`, title: fmtTime(iso) });
}

function copyButton(text) {
    return el('button', {
        class: 'copy-btn',
        on: {
            click: async (e) => {
                await navigator.clipboard.writeText(text);
                e.target.textContent = 'copied!';
                setTimeout(() => e.target.textContent = 'copy', 1200);
            }
        }
    }, 'copy');
}

function setStatus(msg) {
    document.getElementById('status').textContent = msg || '';
}

function setError(msg) {
    setStatus(`⚠ ${msg}`);
    setTimeout(() => setStatus(''), 6000);
}

function setBreadcrumb(parts) {
    const nav = document.getElementById('breadcrumb');
    nav.innerHTML = '';
    parts.forEach((p, i) => {
        if (i > 0) nav.appendChild(document.createTextNode(' / '));
        if (p.onClick) {
            nav.appendChild(el('a', { href: '#', on: { click: (e) => { e.preventDefault(); p.onClick(); } } }, p.label));
        } else {
            nav.appendChild(document.createTextNode(p.label));
        }
    });
}

function setMode(mode) {
    state.mode = mode;
}

// --- Polling -------------------------------------------------------------

function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(refreshProjectView, POLL_INTERVAL);
}

function stopPolling() {
    if (state.pollTimer) {
        clearInterval(state.pollTimer);
        state.pollTimer = null;
    }
}

async function refreshProjectView() {
    if (state.view === 'list') { await refreshListProjects(); return; }
    if (state.view !== 'project') return;

    // Skip when user is in any editing/detail mode
    if (state.mode === 'editing') return;

    // Also skip if any input/textarea/select has focus (belt-and-suspenders)
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT')) return;

    try {
        if (state.tab === 'chat') await refreshChat();
        else if (state.tab === 'agents') await refreshAgents();
        else if (state.tab === 'files') await refreshFiles();
        // settings tab: no auto-refresh (user may be editing description)
    } catch (e) {
        console.error('Poll error:', e);
    }
}

// --- Views: List ---------------------------------------------------------

const PROJ_SORT_FNS = {
    name:     (a, b) => a.display_name.localeCompare(b.display_name),
    files:    (a, b) => a.file_count - b.file_count,
    chat:     (a, b) => a.chat_count - b.chat_count,
    agents:   (a, b) => a.agent_count - b.agent_count,
    activity: (a, b) => (a.last_activity || '').localeCompare(b.last_activity || ''),
};

function makeProjRow(p) {
    return el('tr', { style: 'cursor: pointer', on: { click: () => showProject(p.name, p.owner) } },
        el('td', {}, el('strong', {}, p.display_name),
            p.role_in_project === 'member' ? el('span', { class: 'muted' }, ' shared') : null),
        el('td', { class: 'muted' }, p.description),
        el('td', { class: 'right' }, String(p.file_count)),
        el('td', { class: 'right' }, String(p.chat_count)),
        el('td', { class: 'right' }, String(p.agent_count)),
        el('td', { class: 'muted' }, fmtRelative(p.last_activity)),
    );
}

function sortProjects(projects) {
    return [...projects].sort((a, b) => projSortDir * (PROJ_SORT_FNS[projSortKey] || PROJ_SORT_FNS.name)(a, b));
}

// Lightweight poll refresh — only replaces tbody rows, no full re-render.
async function refreshListProjects() {
    const tbody = document.getElementById('proj-tbody');
    if (!tbody) return;   // list not currently shown
    try {
        const { projects } = await adminGet('list-projects');
        const sorted = sortProjects(projects);
        tbody.innerHTML = '';
        sorted.forEach(p => tbody.appendChild(makeProjRow(p)));
    } catch (_) {}        // silently ignore poll errors
}

async function showList() {
    stopPolling();
    setMode('browse');
    state.view = 'list';
    state.project = null;
    setBreadcrumb([{ label: 'Projects' }]);

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'loading' }, 'Loading projects…'));

    try {
        const { projects } = await adminGet('list-projects');
        app.innerHTML = '';

        app.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem;' },
            el('button', { on: { click: showCreateProject } }, '+ New project'),
            el('button', { class: 'secondary', on: { click: () => showRestoreBackup(app) } }, '↩ Restore backup'),
            (window.CURRENT_USER && window.CURRENT_USER.role === 'admin')
                ? el('button', { class: 'secondary', style: 'margin-left: auto', on: { click: showUsers } }, '\u{1F465} Users')
                : null,
        ));

        if (projects.length === 0) {
            app.appendChild(el('div', { class: 'empty' }, 'No projects yet. Create one to get started.'));
            startPolling();
            return;
        }

        function makeProjSortTh(label, key, cls = '') {
            const arrow = projSortKey === key ? (projSortDir === 1 ? ' ↑' : ' ↓') : '';
            return el('th', { class: cls, style: 'cursor:pointer;user-select:none', on: { click: () => {
                if (projSortKey === key) projSortDir *= -1; else { projSortKey = key; projSortDir = 1; }
                showList();
            }}}, label + arrow);
        }

        const tbody = el('tbody', { id: 'proj-tbody' },
            ...sortProjects(projects).map(makeProjRow)
        );
        const table = el('table', {},
            el('thead', {}, el('tr', {},
                makeProjSortTh('Project', 'name'),
                el('th', {}, 'Description'),
                makeProjSortTh('Files', 'files', 'right'),
                makeProjSortTh('Chat', 'chat', 'right'),
                makeProjSortTh('Agents', 'agents', 'right'),
                makeProjSortTh('Last activity', 'activity'),
            )),
            tbody,
        );
        app.appendChild(table);
        startPolling();   // lightweight poll — only tbody rows update
    } catch (e) {
        app.innerHTML = '';
        app.appendChild(el('div', { class: 'error' }, e.message));
    }
}


// --- Restore backup -------------------------------------------------------

function showRestoreBackup(appEl) {
    // Remove any existing restore panel
    const existing = document.getElementById('restore-panel');
    if (existing) { existing.remove(); return; }

    const panel = el('div', { id: 'restore-panel', class: 'card', style: 'margin-bottom: 1rem' },
        el('h2', { style: 'margin-top: 0' }, '↩ Restore backup'),
        el('p', { class: 'muted' }, 'Select a .tar.gz backup file created with the Backup button.'),
        el('input', { type: 'file', id: 'restore-file', accept: '.tar.gz,.gz',
            style: 'display: block; margin-bottom: 0.75rem' }),
        el('div', { class: 'row' },
            el('button', { on: { click: () => doRestore() } }, 'Restore'),
            el('button', { class: 'secondary', on: { click: () => panel.remove() } }, 'Cancel'),
        ),
        el('div', { id: 'restore-status', style: 'margin-top: 0.75rem' }),
    );

    // Insert after toolbar (first child)
    appEl.insertBefore(panel, appEl.children[1] || null);

    async function doRestore(overwrite = false, rename = '') {
        const fileInput = document.getElementById('restore-file');
        const statusDiv = document.getElementById('restore-status');
        if (!fileInput || !fileInput.files[0]) {
            statusDiv.textContent = '⚠ Please select a file.';
            return;
        }

        statusDiv.textContent = 'Uploading…';

        const fd = new FormData();
        fd.append('backup', fileInput.files[0]);
        fd.append('overwrite', overwrite ? 'true' : 'false');
        fd.append('rename', rename);

        try {
            const url = new URL(ADMIN, location.href);
            url.searchParams.set('action', 'restore-backup');
            const res = await fetch(url, { method: 'POST', body: fd });
            const data = await res.json();

            if (!res.ok) {
                statusDiv.textContent = '⚠ ' + (data.error || 'Upload failed');
                return;
            }

            if (data.conflict) {
                // Show conflict resolution inline
                showConflict(statusDiv, data, fileInput, doRestore);
                return;
            }

            // Success
            panel.remove();
            setStatus(`Restored "${data.display_name}" (${data.project})`);
            showList();

        } catch (e) {
            statusDiv.textContent = '⚠ ' + e.message;
        }
    }

    function showConflict(statusDiv, data, fileInput, doRestoreFn) {
        statusDiv.innerHTML = '';
        statusDiv.appendChild(el('div', { class: 'warn', style: 'margin-bottom: 0.75rem' },
            `Project "${data.display_name}" (${data.project}) already exists. What would you like to do?`
        ));

        const renameInput = el('input', {
            type: 'text',
            id: 'restore-rename',
            placeholder: 'New project name (slug)',
            style: 'display: none; margin-top: 0.5rem; margin-bottom: 0.5rem; width: 16rem',
        });

        let renameVisible = false;
        const renameBtn = el('button', { class: 'secondary', on: { click: () => {
            renameVisible = !renameVisible;
            renameInput.style.display = renameVisible ? 'block' : 'none';
            if (renameVisible) {
                renameInput.value = data.project + '-restored';
                renameInput.focus();
                restoreAsBtn.style.display = 'inline-block';
            } else {
                restoreAsBtn.style.display = 'none';
            }
        }}}, 'Rename');

        const restoreAsBtn = el('button', { style: 'display: none', on: { click: () => {
            const newName = renameInput.value.trim();
            if (!newName) { statusDiv.querySelector('#restore-rename').focus(); return; }
            statusDiv.textContent = 'Restoring…';
            doRestoreFn(false, newName);
        }}}, 'Restore as…');

        statusDiv.appendChild(el('div', { class: 'row' },
            el('button', { class: 'danger', on: { click: () => {
                statusDiv.textContent = 'Restoring (overwrite)…';
                doRestoreFn(true, '');
            }}}, 'Overwrite'),
            renameBtn,
            el('button', { class: 'secondary', on: { click: () => {
                statusDiv.innerHTML = '';
                statusDiv.textContent = 'Cancelled.';
            }}}, 'Cancel'),
        ));
        statusDiv.appendChild(renameInput);
        statusDiv.appendChild(restoreAsBtn);
    }
}

async function showCreateProject() {
    setMode('editing');  // block polling while in this form
    const app = document.getElementById('app');
    app.innerHTML = '';
    setBreadcrumb([
        { label: 'Projects', onClick: () => { setMode('browse'); showList(); } },
        { label: 'New project' },
    ]);

    const slugInput = el('input', { type: 'text', id: 'p-name', placeholder: 'ziggurat' });
    const displayInput = el('input', { type: 'text', id: 'p-display', placeholder: 'Ziggurat' });

    // Copy slug → display name suggestion as user types, only if display not manually edited
    let displayEdited = false;
    displayInput.addEventListener('input', () => { displayEdited = true; });
    slugInput.addEventListener('input', () => {
        if (!displayEdited) {
            // Capitalise first letter as suggestion
            const slug = slugInput.value;
            displayInput.value = slug.charAt(0).toUpperCase() + slug.slice(1);
        }
    });

    const card = el('div', { class: 'card' },
        el('h1', {}, 'Create project'),
        el('label', {}, 'Name (URL slug)'),
        slugInput,
        el('div', { class: 'muted' }, 'Lowercase letters, digits, dash, underscore'),
        el('label', {}, 'Display name'),
        displayInput,
        el('label', {}, 'Description'),
        el('textarea', { id: 'p-desc', rows: 3 }),
        el('label', { for: 'p-scheme' }, 'Agent naming scheme'),
        el('select', { id: 'p-scheme' },
            ...Object.keys(NAMING_SCHEMES).map(k => {
                const labels = {
                    english: 'English (Arthur, Berta, Carl, …)',
                    german:  'German (Albert, Berta, Clara, …)',
                    italian: 'Italian (Andrea, Bruno, Claudio, …)',
                    french:  'French (Antoine, Bernard, Claire, …)',
                    spanish: 'Spanish (Antonio, Blanca, Carlos, …)',
                    swedish: 'Swedish (Alva, Bo, Cajsa, …)',
                    slovene: 'Slovene (Andrej, Bojan, Cvetka, …)',
                };
                const opt = el('option', { value: k }, labels[k] || k);
                if (k === DEFAULT_SCHEME) opt.selected = true;
                return opt;
            })
        ),
        el('div', { class: 'muted' }, 'Defines the default name suggestions when adding agents.'),
        el('div', { class: 'row', style: 'margin-top: 1rem' },
            el('button', { on: { click: async () => {
                try {
                    const name = document.getElementById('p-name').value.trim();
                    if (!name) throw new Error('Slug required');
                    const display_name = document.getElementById('p-display').value.trim() || name;
                    const description = document.getElementById('p-desc').value.trim();
                    const naming_scheme = document.getElementById('p-scheme').value;
                    const r = await adminPost('create-project', { name, display_name, description, naming_scheme });
                    // Provision .lock file so agents can connect immediately
                    setStatus(`Created project "${display_name}"`);
                    setMode('browse');
                    showProject(name);
                } catch (e) { setError(e.message); }
            }}}, 'Create'),
            el('button', { class: 'secondary', on: { click: () => { setMode('browse'); showList(); } }}, 'Cancel'),
        )
    );
    app.appendChild(card);
}

// --- Views: Project ------------------------------------------------------

async function showProject(name, owner = null) {
    setMode('browse');
    state.view = 'project';
    state.project = name;
    state.projectOwner = owner || (window.CURRENT_USER && window.CURRENT_USER.email);
    state.tab = 'files';
    setBreadcrumb([
        { label: 'Projects', onClick: showList },
        { label: name },
    ]);

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'loading' }, 'Loading project…'));

    try {
        const data = await adminGet('project', { p: name, owner: state.projectOwner });
        state.projectData = data;
        state.projectOwner = data.owner || state.projectOwner;
        state.ownerSecret = data.owner_secret;

        app.innerHTML = '';
        app.appendChild(el('h1', {}, data.display_name));
        app.appendChild(el('div', { class: 'muted', style: 'margin-bottom: 1rem' }, data.description));
        const canShare = window.CURRENT_USER.role === 'admin' || state.projectOwner === window.CURRENT_USER.email;
        const tabs = el('div', { class: 'tabs' },
            tabBtn('files', 'Files'),
            tabBtn('chat', 'Chat'),
            tabBtn('agents', 'Agents'),
            tabBtn('share', 'Share'),
            tabBtn('settings', 'Settings'),
        );
        app.appendChild(tabs);
        app.appendChild(el('div', { id: 'tab-content' }));

        renderTab();
        startPolling();
    } catch (e) {
        app.innerHTML = '';
        app.appendChild(el('div', { class: 'error' }, e.message));
    }
}

function tabBtn(key, label) {
    const btn = el('button', {
        class: state.tab === key ? 'active' : '',
        on: { click: () => {
            setMode('browse');
            state.tab = key;
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderTab();
        }}
    }, label);
    return btn;
}

function renderTab() {
    const pane = document.getElementById('tab-content');
    if (!pane) return;
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading…'));
    if (state.tab === 'files') renderFiles();
    else if (state.tab === 'chat') renderChat();
    else if (state.tab === 'agents') renderAgents();
    else if (state.tab === 'share') renderShare();
    else if (state.tab === 'settings') renderSettings();
}

// --- Tab: Files ----------------------------------------------------------

async function renderFiles() {
    await refreshFiles();
}

async function refreshFiles() {
    if (state.tab !== 'files' || state.mode === 'editing') return;
    try {
        const { files } = await agentApi('/files');
        const pane = document.getElementById('tab-content');
        if (!pane) return;
        pane.innerHTML = '';

        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { on: { click: showCreateFile } }, '+ Create File'),
            el('button', { style: 'margin-left: 0.5rem', on: { click: showUploadFile } }, '+ Upload File'),
        ));

        if (files.length === 0) {
            pane.appendChild(el('div', { class: 'empty' }, 'No files yet.'));
            return;
        }

        const active = files.filter(f => f.state === 'active');
        const deleted = files.filter(f => f.state === 'deleted');

        const makeRow = (f) => el('tr', { class: f.state === 'deleted' ? 'deleted' : '' },
            el('td', {},
                f.state === 'active'
                    ? el('a', { href: '#', class: 'file-link', on: { click: (e) => { e.preventDefault(); showFile(f.path); } } }, f.path)
                    : el('span', {}, f.path)
            ),
            el('td', { class: 'right' }, `v${f.version}`),
            el('td', { class: 'right' }, f.size !== null ? fmtSize(f.size) : '—'),
            el('td', { class: 'muted' }, fmtRelative(f.modified)),
            el('td', { class: 'muted' }, f.modified_by || '—'),
            el('td', {},
                f.state === 'active'
                    ? el('button', { class: 'danger', on: { click: () => deleteFile(f.path, f.version) }}, 'delete')
                    : el('span', { class: 'muted' }, 'deleted')
            )
        );

        // Sort active files
        const sortFns = {
            path:     (a, b) => a.path.localeCompare(b.path),
            version:  (a, b) => a.version - b.version,
            size:     (a, b) => (a.size ?? -1) - (b.size ?? -1),
            modified: (a, b) => (a.modified || '').localeCompare(b.modified || ''),
            by:       (a, b) => (a.modified_by || '').localeCompare(b.modified_by || ''),
        };
        const sorted = [...active].sort((a, b) => fileSortDir * (sortFns[fileSortKey] || sortFns.path)(a, b));

        function makeSortTh(label, key, cls = '') {
            const arrow = fileSortKey === key ? (fileSortDir === 1 ? ' ↑' : ' ↓') : '';
            return el('th', { class: cls, style: 'cursor:pointer; user-select:none', on: { click: () => {
                if (fileSortKey === key) fileSortDir *= -1;
                else { fileSortKey = key; fileSortDir = 1; }
                refreshFiles();
            }}}, label + arrow);
        }

        const table = el('table', {},
            el('thead', {}, el('tr', {},
                makeSortTh('Path', 'path'),
                makeSortTh('Ver', 'version', 'right'),
                makeSortTh('Size', 'size', 'right'),
                makeSortTh('Modified', 'modified'),
                makeSortTh('By', 'by'),
                el('th', {}, ''),
            )),
            el('tbody', {}, ...sorted.map(makeRow))
        );
        pane.appendChild(table);

        if (deleted.length > 0) {
            const details = el('details', { style: 'margin-top: 1rem' },
                el('summary', { class: 'muted', style: 'cursor:pointer' }, `${deleted.length} deleted file(s)`),
                el('table', { style: 'margin-top:0.5rem' },
                    el('tbody', {}, ...deleted.map(makeRow))
                )
            );
            pane.appendChild(details);
        }
    } catch (e) {
        setError(e.message);
    }
}

function fmtSize(bytes) {
    if (bytes < 1024) return `${bytes}B`;
    if (bytes < 1024*1024) return `${(bytes/1024).toFixed(1)}K`;
    return `${(bytes/1024/1024).toFixed(1)}M`;
}

async function showFile(path) {
    setMode('editing');  // block polling while viewing/editing
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading file…'));

    try {
        const res = await fetch(`${API}/${state.project}/file?p=${encodeURIComponent(path)}`, {
            headers: { 'X-Agent-Secret': state.ownerSecret },
        });
        const arrayBuf = await res.arrayBuffer();
        const version = res.headers.get('X-File-Version');
        const modifiedBy = res.headers.get('X-File-Modified-By');
        const mime = res.headers.get('Content-Type') || '';

        const bytes = new Uint8Array(arrayBuf);
        const isBinary = detectBinary(bytes);
        const text = isBinary ? null : new TextDecoder().decode(bytes);

        pane.innerHTML = '';

        // Back button + meta
        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'files'; renderTab(); } }}, '← Files'),
            el('strong', {}, path),
            el('span', { class: 'muted' }, `v${version} · by ${modifiedBy} · ${fmtSize(bytes.length)}`),
        ));

        // View mode toggle for text files
        let viewMode = 'text';  // 'text' | 'hex'
        const toggleBtn = el('button', { class: 'secondary', style: 'margin-bottom: 0.75rem' });

        function renderContent() {
            const existing = document.getElementById('file-view-area');
            if (existing) existing.remove();

            if (viewMode === 'hex' || isBinary) {
                toggleBtn.textContent = isBinary ? 'Hex view' : 'Switch to text';
                if (!isBinary) toggleBtn.textContent = 'Switch to text';
                const hexDiv = el('div', { id: 'file-view-area', class: 'file-content' });
                hexDiv.textContent = hexDump(bytes);
                pane.appendChild(hexDiv);
            } else {
                toggleBtn.textContent = 'Switch to hex';
                const ta = el('textarea', { rows: 25, id: 'file-view-area' });
                ta.value = text;
                pane.appendChild(ta);
            }
        }

        if (isBinary) {
            toggleBtn.textContent = 'Hex view';
            toggleBtn.disabled = true;
        } else {
            toggleBtn.textContent = 'Switch to hex';
            toggleBtn.addEventListener('click', () => {
                viewMode = viewMode === 'text' ? 'hex' : 'text';
                renderContent();
                updateButtons();
            });
        }
        pane.appendChild(toggleBtn);
        renderContent();

        // Save / Cancel buttons (only for text files)
        const btnRow = el('div', { class: 'row', style: 'margin-top: 1rem', id: 'file-btn-row' });

        function updateButtons() {
            btnRow.innerHTML = '';
            if (!isBinary && viewMode === 'text') {
                btnRow.appendChild(el('button', { on: { click: async () => {
                    try {
                        const content = document.getElementById('file-view-area').value;
                        const r = await fetch(`${API}/${state.project}/file?p=${encodeURIComponent(path)}`, {
                            method: 'PUT',
                            headers: {
                                'X-Agent-Secret': state.ownerSecret,
                                'X-Expected-Version': version,
                                'Content-Type': 'text/plain',
                            },
                            body: content,
                        });
                        const d = await r.json();
                        if (!r.ok) throw new Error(d.message || d.error);
                        setStatus(`Saved as v${d.version}`);
                        setMode('browse');
                        state.tab = 'files';
                        renderTab();
                    } catch (e) { setError(e.message); }
                }}}, 'Save'));
            }
            btnRow.appendChild(el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'files'; renderTab(); } }}, 'Close'));
        }
        updateButtons();
        pane.appendChild(btnRow);

    } catch (e) {
        setError(e.message);
    }
}

function detectBinary(bytes) {
    // Heuristic: if more than 10% of the first 512 bytes are non-printable non-whitespace, treat as binary
    const sample = bytes.slice(0, 512);
    let nonText = 0;
    for (const b of sample) {
        if (b < 9 || (b > 13 && b < 32) || b === 127) nonText++;
    }
    return nonText / sample.length > 0.1;
}

function hexDump(bytes) {
    const lines = [];
    for (let i = 0; i < bytes.length; i += 16) {
        const chunk = bytes.slice(i, i + 16);
        const addr = i.toString(16).padStart(6, '0');
        const hex = Array.from(chunk).map(b => b.toString(16).padStart(2, '0')).join(' ').padEnd(47, ' ');
        const ascii = Array.from(chunk).map(b => (b >= 32 && b < 127) ? String.fromCharCode(b) : '.').join('');
        lines.push(`${addr}  ${hex}  ${ascii}`);
    }
    return lines.join('\n');
}

async function deleteFile(path, version) {
    if (!confirm(`Delete ${path}?`)) return;
    try {
        const res = await fetch(`${API}/${state.project}/file?p=${encodeURIComponent(path)}`, {
            method: 'DELETE',
            headers: {
                'X-Agent-Secret': state.ownerSecret,
                'X-Expected-Version': String(version),
            },
        });
        if (!res.ok) {
            const d = await res.json();
            throw new Error(d.message || d.error);
        }
        setStatus(`Deleted ${path}`);
        refreshFiles();
    } catch (e) { setError(e.message); }
}

function showCreateFile() {
    setMode('editing');  // block polling
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('h2', {}, 'Create new file'));
    pane.appendChild(el('label', {}, 'Path (relative, e.g. files/main.s)'));
    pane.appendChild(el('input', { type: 'text', id: 'up-path' }));
    pane.appendChild(el('label', {}, 'Content'));
    pane.appendChild(el('textarea', { rows: 15, id: 'up-content' }));
    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 1rem' },
        el('button', { on: { click: async () => {
            try {
                const path = document.getElementById('up-path').value.trim();
                const content = document.getElementById('up-content').value;
                if (!path) throw new Error('Path required');
                const res = await fetch(`${API}/${state.project}/file?p=${encodeURIComponent(path)}`, {
                    method: 'PUT',
                    headers: {
                        'X-Agent-Secret': state.ownerSecret,
                        'X-New-File': 'true',
                        'Content-Type': 'text/plain',
                    },
                    body: content,
                });
                const d = await res.json();
                if (!res.ok) throw new Error(d.message || d.error);
                setStatus(`Created ${path} at v${d.version}`);
                setMode('browse');
                state.tab = 'files';
                renderTab();
            } catch (e) { setError(e.message); }
        }}}, 'Create File'),
        el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'files'; renderTab(); } }}, 'Cancel'),
    ));
}

function showUploadFile() {
    setMode('editing');  // block polling
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('h2', {}, 'Upload file'));
    pane.appendChild(el('label', {}, 'Path (relative, e.g. assets/logo.png — leave blank to use filename)'));
    const pathInput = el('input', { type: 'text', id: 'up-path', placeholder: 'auto from filename' });
    pane.appendChild(pathInput);
    pane.appendChild(el('label', {}, 'File'));
    const fileInput = el('input', { type: 'file', id: 'up-file' });
    pane.appendChild(fileInput);
    const statusDiv = el('div', { class: 'muted', style: 'margin-top: 0.5rem' });
    pane.appendChild(statusDiv);
    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 1rem' },
        el('button', { on: { click: async () => {
            try {
                const file = document.getElementById('up-file').files[0];
                if (!file) throw new Error('No file selected');
                let path = document.getElementById('up-path').value.trim();
                if (!path) path = file.name;
                statusDiv.textContent = 'Uploading…';
                const arrayBuffer = await file.arrayBuffer();
                const res = await fetch(`${API}/${state.project}/file?p=${encodeURIComponent(path)}`, {
                    method: 'PUT',
                    headers: {
                        'X-Agent-Secret': state.ownerSecret,
                        'X-New-File': 'true',
                        'Content-Type': file.type || 'application/octet-stream',
                    },
                    body: arrayBuffer,
                });
                const d = await res.json();
                if (!res.ok) throw new Error(d.message || d.error);
                statusDiv.textContent = '';
                setStatus(`Uploaded ${path} (${d.size} bytes) at v${d.version}`);
                setMode('browse');
                state.tab = 'files';
                renderTab();
            } catch (e) { statusDiv.textContent = ''; setError(e.message); }
        }}}, 'Upload File'),
        el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'files'; renderTab(); } }}, 'Cancel'),
    ));
}

// --- Tab: Chat -----------------------------------------------------------

async function renderChat() {
    await refreshChat();
}

async function refreshChat() {
    if (state.tab !== 'chat') return;
    try {
        const { messages } = await agentApi('/chat?limit=200');
        const pane = document.getElementById('tab-content');
        if (!pane) return;

        // Preserve textarea content if polling re-renders while user is typing
        const oldInput = document.getElementById('chat-input');
        const oldText = oldInput ? oldInput.value : '';

        pane.innerHTML = '';

        const box = el('div', { class: 'chat-box', id: 'chat-box' });
        if (messages.length === 0) {
            box.appendChild(el('div', { class: 'empty' }, 'No messages yet.'));
        } else {
            messages.forEach(m => {
                box.appendChild(el('div', { class: 'chat-msg' },
                    el('div', { class: 'chat-meta' },
                        el('strong', {}, m.from),
                        ' · ',
                        fmtTime(m.time),
                    ),
                    el('div', { class: 'chat-text' }, m.text),
                ));
            });
        }
        pane.appendChild(box);

        pane.appendChild(el('label', {}, `Post message (as ${window.CURRENT_USER ? window.CURRENT_USER.email.split('@')[0] : 'you'})`));
        const ta = el('textarea', { rows: 3, id: 'chat-input' });
        ta.value = oldText;
        pane.appendChild(ta);
        pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem' },
            el('button', { on: { click: postChat }}, 'Post'),
        ));

        const cb = document.getElementById('chat-box');
        cb.scrollTop = cb.scrollHeight;
    } catch (e) {
        setError(e.message);
    }
}

async function postChat() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    try {
        const res = await fetch(`${API}/${state.project}/chat`, {
            method: 'POST',
            headers: {
                'X-Agent-Secret': state.ownerSecret,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ text }),
        });
        const d = await res.json();
        if (!res.ok) throw new Error(d.message || d.error);
        input.value = '';
        refreshChat();
    } catch (e) { setError(e.message); }
}

// --- Tab: Agents ---------------------------------------------------------

async function renderAgents() {
    await refreshAgents();
}

async function refreshAgents() {
    if (state.tab !== 'agents' || state.mode === 'editing') return;
    try {
        const data = await adminGet('project', { p: state.project });
        const presence = await agentApi('/presence');

        const pane = document.getElementById('tab-content');
        if (!pane) return;
        pane.innerHTML = '';

        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { on: { click: showAddAgent }}, '+ Add agent'),
        ));

        const table = el('table', {},
            el('thead', {}, el('tr', {},
                el('th', {}, ''),
                el('th', {}, 'Name'),
                el('th', {}, 'Role'),
                el('th', {}, 'Secret'),
                el('th', {}, 'Last seen'),
                el('th', {}, ''),
            )),
            el('tbody', {}, ...data.agents.map(a =>
                el('tr', {},
                    el('td', {}, statusDot(a.last_seen)),
                    el('td', {}, el('strong', {}, a.name), a.admin ? el('span', { class: 'muted' }, ' (owner)') : null),
                    el('td', {}, a.role),
                    el('td', {}, el('span', { class: 'secret' }, a.secret), copyButton(a.secret)),
                    el('td', { class: 'muted' }, fmtRelative(a.last_seen)),
                    el('td', {},
                        a.admin ? null : el('button', {
                            class: 'danger',
                            on: { click: () => removeAgent(a.secret, a.name) }
                        }, 'remove')
                    ),
                )
            ))
        );
        pane.appendChild(table);

        pane.appendChild(el('div', { class: 'warn' },
            'Share a secret with a Claude session: "load your projectcollab skill, connect to ',
            el('code', {}, state.project),
            ', secret <paste>".'
        ));
    } catch (e) {
        setError(e.message);
    }
}

const NAMING_SCHEMES = {
    english:  ['Arthur',  'Berta',  'Carl',    'Dick',    'Emil',       'Frank',     'George'],
    german:   ['Albert',  'Berta',  'Clara',   'Dirk',    'Emil',       'Franz',     'Gustav'],
    italian:  ['Andrea',  'Bruno',  'Claudio', 'Davide',  'Enrico',     'Federico',  'Giulia'],
    french:   ['Antoine', 'Bernard','Claire',  'Diane',   'Elise',      'François',  'Gerard'],
    spanish:  ['Antonio', 'Blanca', 'Carlos',  'Diego',   'Esperanza',  'Fernanda',  'Gonzalo'],
    swedish:  ['Alva',    'Bo',     'Cajsa',   'Danne',   'Erik',       'Frida',     'Göran'],
    slovene:  ['Andrej',  'Bojan',  'Cvetka',  'Drago',   'Edvard',     'Franc',     'Galina'],
};
const DEFAULT_SCHEME = 'german';

function showAddAgent() {
    setMode('editing');  // block polling
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';

    const used = new Set((state.projectData.agents || []).map(a => a.name));
    const scheme = state.projectData.naming_scheme || DEFAULT_SCHEME;
    const names = NAMING_SCHEMES[scheme] || NAMING_SCHEMES[DEFAULT_SCHEME];
    const available = names.filter(n => !used.has(n));

    pane.appendChild(el('h2', {}, 'Add agent'));
    pane.appendChild(el('div', { class: 'muted', style: 'margin-bottom: 0.5rem' },
        `Naming scheme: ${scheme}`));
    pane.appendChild(el('label', { for: 'a-name-select' }, 'Agent name'));

    const select = el('select', { id: 'a-name-select' },
        ...available.map(n => el('option', { value: n }, n)),
        el('option', { value: '__custom__' }, 'Custom name…')
    );
    pane.appendChild(select);

    const customInput = el('input', {
        type: 'text',
        id: 'a-name-custom',
        placeholder: 'Type a custom name',
        style: 'display: none; margin-top: 0.5rem;'
    });
    pane.appendChild(customInput);

    select.addEventListener('change', () => {
        if (select.value === '__custom__') {
            customInput.style.display = '';
            customInput.focus();
        } else {
            customInput.style.display = 'none';
        }
    });

    pane.appendChild(el('label', { for: 'a-role' }, 'Role'));
    pane.appendChild(el('input', { type: 'text', id: 'a-role', placeholder: 'Developer' }));

    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 1rem' },
        el('button', { on: { click: async () => {
            try {
                let name = select.value;
                if (name === '__custom__') {
                    name = customInput.value.trim();
                    if (!name) throw new Error('Custom name required');
                }
                const role = document.getElementById('a-role').value.trim() || 'Developer';
                const r = await adminPost('add-agent', { project: state.project, name, role });
                setStatus(`Added ${name}. Secret: ${r.secret}`);
                state.projectData = await adminGet('project', { p: state.project });
                setMode('browse');
                state.tab = 'agents';
                renderTab();
            } catch (e) { setError(e.message); }
        }}}, 'Add'),
        el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'agents'; renderTab(); } }}, 'Cancel'),
    ));
}

async function removeAgent(secret, name) {
    if (!confirm(`Remove agent ${name}? This invalidates their secret.`)) return;
    try {
        await adminPost('remove-agent', { project: state.project, secret });
        setStatus(`Removed ${name}`);
        refreshAgents();
    } catch (e) { setError(e.message); }
}

// --- Tab: Settings -------------------------------------------------------

async function renderSettings() {
    setMode('editing');  // settings always blocks polling — user is likely editing
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';

    pane.appendChild(el('h2', {}, 'Description'));
    const desc = el('textarea', { rows: 3, id: 's-desc' });
    desc.value = state.projectData.description || '';
    pane.appendChild(desc);
    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem' },
        el('button', { on: { click: async () => {
            try {
                await adminPost('update-description', { project: state.project, description: document.getElementById('s-desc').value });
                setStatus('Description updated');
            } catch (e) { setError(e.message); }
        }}}, 'Save description'),
    ));

    pane.appendChild(el('h2', {}, 'Backup'));
    pane.appendChild(el('div', { class: 'row' },
        el('a', {
            class: 'btn',
            href: `${ADMIN}?action=backup&p=${encodeURIComponent(state.project)}`,
            download: ''
        }, 'Download .tar.gz')
    ));

    pane.appendChild(el('h2', {}, 'Danger zone'));
    pane.appendChild(el('div', { class: 'warn' }, 'Deleting a project removes ALL files, chat history, and agent secrets permanently.'));
    pane.appendChild(el('div', { class: 'row' },
        el('button', { class: 'danger', on: { click: async () => {
            const t = prompt(`Type the project name (${state.project}) to confirm deletion:`);
            if (t !== state.project) { setStatus('Cancelled'); return; }
            try {
                await adminPost('delete-project', { name: state.project });
                setStatus(`Deleted ${state.project}`);
                setMode('browse');
                showList();
            } catch (e) { setError(e.message); }
        }}}, 'Delete project'),
    ));
}


// --- Tab: Share ----------------------------------------------------------

async function renderShare() {
    setMode('browse');
    const pane = document.getElementById('tab-content');
    if (!pane) return;
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading…'));

    try {
        const data = await adminGet('project', { p: state.project, owner: state.projectOwner });
        state.projectData = data;
        pane.innerHTML = '';

        const isOwner = state.projectOwner === window.CURRENT_USER.email
                     || window.CURRENT_USER.role === 'admin';

        pane.appendChild(el('h2', {}, 'Shared with'));

        const members = data.shared_with || [];
        if (members.length === 0) {
            pane.appendChild(el('p', { class: 'muted' }, 'Not shared with anyone yet.'));
        } else {
            const table = el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', {}, 'User'),
                    el('th', {}, ''),
                )),
                el('tbody', {}, ...members.map(email =>
                    el('tr', {},
                        el('td', {}, email),
                        el('td', {},
                            isOwner ? el('button', { class: 'danger', on: { click: async () => {
                                if (!confirm(`Remove ${email} from this project?`)) return;
                                try {
                                    await adminPost('unshare-project', {
                                        owner: state.projectOwner, project: state.project, username: email
                                    });
                                    renderShare();
                                } catch (e) { setError(e.message); }
                            }}}, 'remove') : null
                        ),
                    )
                ))
            );
            pane.appendChild(table);
        }

        if (isOwner) {
            pane.appendChild(el('h2', {}, 'Add member'));

            // Fetch user suggestions for datalist
            let suggestions = [];
            try {
                const s = await adminGet('user-suggestions');
                suggestions = (s.emails || []).filter(e =>
                    e !== state.projectOwner && !members.includes(e)
                );
            } catch (_) {}

            const datalistId = 'share-suggestions';
            const datalist = el('datalist', { id: datalistId },
                ...suggestions.map(e => el('option', { value: e }))
            );
            const input = el('input', {
                type: 'email',
                placeholder: 'user@example.com',
                list: datalistId,
                style: 'width: 20rem; max-width: 100%',
            });
            pane.appendChild(datalist);
            pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem; gap: 0.5rem; align-items: center' },
                input,
                el('button', { on: { click: async () => {
                    const username = input.value.trim();
                    if (!username) return;
                    try {
                        await adminPost('share-project', {
                            owner: state.projectOwner, project: state.project, username
                        });
                        input.value = '';
                        setStatus(`Shared with ${username}`);
                        renderShare();
                    } catch (e) { setError(e.message); }
                }}}, 'Add'),
            ));
        }

        if (!isOwner) {
            pane.appendChild(el('div', { class: 'warn', style: 'margin-top: 1.5rem' },
                'You are a member of this project. Only the project owner can modify sharing.'
            ));
            pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.75rem' },
                el('button', { class: 'danger', on: { click: async () => {
                    if (!confirm('Leave this project? You will lose access immediately.')) return;
                    try {
                        await adminPost('leave-project', { owner: state.projectOwner, project: state.project });
                        setStatus('Left project');
                        showList();
                    } catch (e) { setError(e.message); }
                }}}, 'Leave project'),
            ));
        }
    } catch (e) {
        setError(e.message);
    }
}

// --- Users management (admin only) ---------------------------------------

async function showUsers() {
    stopPolling();
    setMode('browse');
    state.view = 'users';
    state.project = null;
    setBreadcrumb([
        { label: 'Projects', onClick: showList },
        { label: 'Users' },
    ]);

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'loading' }, 'Loading users…'));

    try {
        const { users } = await adminGet('list-users');
        app.innerHTML = '';

        app.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { on: { click: () => showCreateUser(app, users) } }, '+ New user'),
        ));

        const roleRank = { admin: 3, developer: 2, collaborator: 1 };
        const userSortFns = {
            email:    (a, b) => a.email.localeCompare(b.email),
            role:     (a, b) => (roleRank[a.role] || 0) - (roleRank[b.role] || 0),
            lastlogin:(a, b) => (a.last_login || '').localeCompare(b.last_login || ''),
            owns:     (a, b) => (a.owned_count ?? 0) - (b.owned_count ?? 0),
            shared:   (a, b) => (a.shared_count ?? 0) - (b.shared_count ?? 0),
        };
        function makeUserSortTh(label, key, cls = '') {
            const arrow = userSortKey === key ? (userSortDir === 1 ? ' ↑' : ' ↓') : '';
            return el('th', { class: cls, style: 'cursor:pointer;user-select:none', on: { click: () => {
                if (userSortKey === key) userSortDir *= -1; else { userSortKey = key; userSortDir = 1; }
                showUsers();
            }}}, label + arrow);
        }
        const userSorted = [...users].sort((a, b) => userSortDir * (userSortFns[userSortKey] || userSortFns.role)(a, b));

        const tbody = el('tbody');
        userSorted.forEach(u => tbody.appendChild(makeUserRow(u, app, users)));

        const table = el('table', {},
            el('thead', {}, el('tr', {},
                makeUserSortTh('Email', 'email'),
                makeUserSortTh('Role', 'role'),
                makeUserSortTh('Last login', 'lastlogin'),
                makeUserSortTh('Owns', 'owns', 'right'),
                makeUserSortTh('Member of', 'shared', 'right'),
                el('th', {}, ''),
            )),
            tbody,
        );
        app.appendChild(table);
    } catch (e) {
        app.innerHTML = '';
        app.appendChild(el('div', { class: 'error' }, e.message));
    }
}

function makeUserRow(u, app, allUsers) {
    const isSelf = u.email === window.CURRENT_USER.email;
    const roleSelect = el('select', { style: 'font: inherit; padding: 0.1rem 0.25rem' },
        ['admin', 'developer', 'collaborator'].map(r => {
            const opt = el('option', { value: r }, r);
            if (r === u.role) opt.selected = true;
            return opt;
        })
    );
    roleSelect.addEventListener('change', async () => {
        try {
            await adminPost('change-role', { email: u.email, role: roleSelect.value });
            setStatus(`${u.email} role → ${roleSelect.value}`);
            if (isSelf) location.reload();
        } catch (e) {
            setError(e.message);
            roleSelect.value = u.role; // revert
        }
    });

    return el('tr', {},
        el('td', {}, u.email, isSelf ? el('span', { class: 'muted' }, ' (you)') : null),
        el('td', {}, roleSelect),
        el('td', { class: 'muted' }, u.last_login ? fmtRelative(u.last_login) : 'never'),
        el('td', { class: 'right' }, String(u.owned_count ?? 0)),
        el('td', { class: 'right' }, String(u.shared_count ?? 0)),
        el('td', { style: 'white-space: nowrap' },
            el('button', { class: 'secondary', style: 'margin-right: 0.25rem', on: { click: () => promptSetPassword(u.email) }}, 'pw'),
            isSelf ? null : el('button', { class: 'danger', on: { click: () => confirmRemoveUser(u, app) }}, 'remove'),
        ),
    );
}

function showCreateUser(app, existingUsers) {
    const overlay = el('div', { class: 'modal-overlay', on: { click: (e) => { if (e.target === overlay) overlay.remove(); }}});
    const card = el('div', { class: 'modal-card' },
        el('h2', {}, 'New user'),
        el('label', {}, 'Email'),
        el('input', { type: 'email', id: 'nu-email', placeholder: 'user@example.com' }),
        el('label', {}, 'Role'),
        el('select', { id: 'nu-role' },
            el('option', { value: 'collaborator' }, 'Collaborator'),
            el('option', { value: 'developer' }, 'Developer'),
            el('option', { value: 'admin' }, 'Admin'),
        ),
        el('label', { style: 'display: flex; align-items: center; gap: 0.5rem; flex-direction: row' },
            el('input', { type: 'checkbox', id: 'nu-sendemail', checked: 'checked' }),
            'Send password-set link by email'
        ),
        el('div', { id: 'nu-pwrow' },
            el('label', {}, 'Password (leave blank to use email link)'),
            el('input', { type: 'password', id: 'nu-pw', autocomplete: 'new-password' }),
        ),
        el('div', { class: 'row', style: 'margin-top: 1rem' },
            el('button', { on: { click: async () => {
                const email = document.getElementById('nu-email').value.trim();
                const role  = document.getElementById('nu-role').value;
                const pw    = document.getElementById('nu-pw').value;
                const sendEmail = document.getElementById('nu-sendemail').checked;
                try {
                    const r = await adminPost('create-user', { email, role, password: pw, send_email: sendEmail });
                    overlay.remove();
                    setStatus(`Created ${email}${r.emailed ? ' — welcome email sent' : ''}`);
                    showUsers(); // refresh
                } catch (e) { setError(e.message); }
            }}}, 'Create'),
            el('button', { class: 'secondary', on: { click: () => overlay.remove() }}, 'Cancel'),
        ),
    );
    overlay.appendChild(card);
    document.body.appendChild(overlay);
    setTimeout(() => document.getElementById('nu-email')?.focus(), 50);
}

async function promptSetPassword(email) {
    const isSelf = email === window.CURRENT_USER.email;
    const pw1 = prompt(isSelf
        ? `New password for ${email}:
(8+ characters)`
        : `Set new password for ${email}:
(8+ characters, admin override)`
    );
    if (pw1 === null) return;
    if (pw1.length < 8) { setError('Password must be at least 8 characters'); return; }

    let currentPw = '';
    if (isSelf) {
        currentPw = prompt('Current password (required to confirm):') || '';
        if (!currentPw) return;
    }

    try {
        await adminPost('set-password', { email, password: pw1, current_password: currentPw });
        setStatus(`Password updated for ${email}`);
    } catch (e) { setError(e.message); }
}

async function confirmRemoveUser(u, app) {
    // Show impact first
    let impact = null;
    try { impact = await adminPost('user-impact', { email: u.email }); } catch (_) {}

    let msg = `Remove user ${u.email}?`;
    if (impact) {
        const owned = impact.owned_projects.map(p => p.project).join(', ') || 'none';
        const shared = impact.shared_projects.map(p => p.project).join(', ') || 'none';
        msg += `

Owned projects (will be DELETED): ${owned}
Shared access (will be removed): ${shared}`;
    }
    msg += `

Type the email address to confirm:`;
    const confirm = prompt(msg);
    if (confirm !== u.email) { setStatus('Cancelled'); return; }

    try {
        await adminPost('remove-user', { email: u.email, confirm: u.email });
        setStatus(`Removed ${u.email}`);
        showUsers();
    } catch (e) { setError(e.message); }
}


// --- About ---------------------------------------------------------------

function showAbout() {
    stopPolling();
    setMode('browse');
    state.view = 'about';
    state.project = null;
    setBreadcrumb([
        { label: 'Projects', onClick: showList },
        { label: 'About' },
    ]);

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'card about-card' },
        el('h1', {}, 'ProjectCollab'),
        el('p', {}, 'A lightweight multi-agent collaboration system for Claude instances. Provides versioned shared files, a project chat log, per-agent identity, and a web UI for human oversight — all hosted on a plain PHP/Apache server with no external dependencies.'),
        el('hr'),
        el('h2', {}, 'License'),
        el('pre', { class: 'about-license' },
`MIT License

Copyright (c) 2026 Wilfried Elmenreich

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.`
        ),
        el('hr'),
        el('p', { class: 'muted about-version' }, 'Version 0.1 · Built by Wil and Claude · 2026'),
    ));
}

// --- Boot ----------------------------------------------------------------

initTheme();
showList();
