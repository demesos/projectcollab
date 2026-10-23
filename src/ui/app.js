// ProjectCollab — Single Page App
// Vanilla JS, no dependencies.

const ADMIN = 'api.php';        // human UI API
const POLL_INTERVAL = 5000;     // 5s
const HEX_PREVIEW_THRESHOLD = 32 * 1024;  // files >= this size require explicit view selection instead of auto-preview

// Extension -> MIME type, for files we know how to render as an <img>.
const IMAGE_EXT_MIME = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif',
    webp: 'image/webp', bmp: 'image/bmp', ico: 'image/x-icon',
    svg: 'image/svg+xml',
};
function imageMimeFor(path) {
    const m = /\.([a-z0-9]+)$/i.exec(path);
    return m ? IMAGE_EXT_MIME[m[1].toLowerCase()] : undefined;
}
function isImageFile(path) {
    return imageMimeFor(path) !== undefined;
}

let state = {
    view: 'list',
    project: null,        // current project name
    projectOwner: null,   // email of project owner
    projectData: null,    // full project info from admin API
    tab: 'files',
    mode: 'browse',       // 'browse' | 'editing' — blocks polling when editing
    pollTimer: null,
};

let fileSortKey  = 'path'; let fileSortDir  = 1;
let projSortKey  = 'activity'; let projSortDir  = -1;  // most recently changed first
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

// --- Color swatch (header) ----------------------------------------------
//
// Left-click  → POST set-user-color (no body → server auto-generates a fresh
//               color separated ≥25° from azzurro and other users' colors).
// Right-click → POST cycle-user-color (rotates color_history backwards
//               through last 5 picks).
//
// The swatch reflects window.CURRENT_USER.color, which is seeded by
// index.php and updated optimistically here from server responses. Hover
// tooltip shows the history.

function applySwatchColor(color, history) {
    if (color) {
        window.CURRENT_USER.color = color;
        const sw = document.getElementById('color-swatch');
        if (sw) sw.style.background = color;
    }
    if (Array.isArray(history)) {
        window.CURRENT_USER.color_history = history;
        const sw = document.getElementById('color-swatch');
        if (sw) {
            const lines = ['Click: new color', 'Right-click: previous'];
            if (history.length) lines.push('', 'History: ' + history.join(', '));
            sw.title = lines.join('\n');
        }
    }
}

(function initColorSwatch() {
    const sw = document.getElementById('color-swatch');
    if (!sw) return;
    // Initial tooltip (history may already be present from index.php)
    applySwatchColor(window.CURRENT_USER && window.CURRENT_USER.color,
                     (window.CURRENT_USER && window.CURRENT_USER.color_history) || []);

    sw.addEventListener('click', async (e) => {
        e.preventDefault();
        try {
            const r = await adminPost('set-user-color', {});
            applySwatchColor(r.color, r.history);
            setStatus('New color assigned');
        } catch (err) { setError(err.message); }
    });

    sw.addEventListener('contextmenu', async (e) => {
        e.preventDefault();
        try {
            const r = await adminPost('cycle-user-color', {});
            applySwatchColor(r.color, r.history);
            if (r.cycled) setStatus('Cycled to previous color');
            else setStatus('No previous colors yet');
        } catch (err) { setError(err.message); }
    });
})();

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

// uiApi: all human UI calls go through api.php (session-authenticated).
// The browser never touches owner_secret.
async function uiApi(action, options = {}) {
    const url = new URL(ADMIN, location.href);
    url.searchParams.set('action', action);
    if (state.project)      url.searchParams.set('p', state.project);
    if (state.projectOwner) url.searchParams.set('owner', state.projectOwner);
    for (const [k, v] of Object.entries(options.params || {})) url.searchParams.set(k, v);

    // Apache mod_userdir blocks PUT/DELETE — tunnel them as POST with override header
    let method = options.method || 'GET';
    const headers = { ...(options.headers || {}) };
    if (method === 'PUT' || method === 'DELETE') {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    const res = await fetch(url, {
        method,
        headers,
        body: options.body !== undefined ? options.body : undefined,
    });
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

function setActiveNav(id) {
    ['projects-link', 'skill-link', 'about-link', 'users-link'].forEach(lid => {
        const e = document.getElementById(lid);
        if (e) e.classList.toggle('nav-active', lid === id);
    });
}

function setTitle(sub) {
    document.title = sub ? `Collab \u2013 ${sub}` : 'Collab';
}

function setMode(mode) {
    state.mode = mode;
}

// --- Unsaved file-edit guard ----------------------------------------------
// Tracks whether the currently open file viewer (text or hex) has unsaved
// edits, so navigating away (tab switch, in-app back, browser back button)
// can offer to save or discard instead of silently losing changes.
let fileEditDirty = false;
let fileEditSaveFn = null;    // async () => Promise<void> — saves current edits
let fileEditDiscardFn = null; // () => void — clears current edits in place

function setFileEditDirty(dirty, saveFn, discardFn) {
    fileEditDirty = dirty;
    fileEditSaveFn = dirty ? saveFn : null;
    fileEditDiscardFn = dirty ? discardFn : null;
}

// Runs `proceed` immediately if there are no unsaved file edits; otherwise
// shows a confirmation popup with Update file / Discard edits / Cancel.
function confirmLeaveIfDirty(proceed) {
    if (!fileEditDirty) { proceed(); return; }
    showLeaveFileConfirm(async () => {
        try {
            if (fileEditSaveFn) await fileEditSaveFn();
            setFileEditDirty(false);
            proceed();
        } catch (e) { setError(e.message); }
    }, () => {
        if (fileEditDiscardFn) fileEditDiscardFn();
        setFileEditDirty(false);
        proceed();
    });
}

function showLeaveFileConfirm(onUpdate, onDiscard) {
    const overlay = el('div', { class: 'modal-overlay' });
    const card = el('div', { class: 'modal-card' },
        el('h2', {}, 'Update this file?'),
        el('p', {}, 'You have unsaved edits in the file viewer.'),
        el('div', { class: 'row', style: 'margin-top: 1rem' },
            el('button', { on: { click: () => { overlay.remove(); onUpdate(); } }}, 'Update file'),
            el('button', { class: 'secondary', on: { click: () => { overlay.remove(); onDiscard(); } }}, 'Discard edits'),
            el('button', { class: 'secondary', on: { click: () => overlay.remove() }}, 'Cancel'),
        ),
    );
    overlay.appendChild(card);
    document.body.appendChild(overlay);
}

// --- Browser back-button guard --------------------------------------------
// This SPA doesn't push per-view history entries, so the browser Back button
// would otherwise leave the app entirely without warning. We push a
// same-document history entry up front and intercept popstate with a
// confirmation — also honouring unsaved file edits if any are open.
(function initBackGuard() {
    history.pushState({ collabGuard: true }, '', location.href);
    window.addEventListener('popstate', function onPopState() {
        const goBack = () => { window.removeEventListener('popstate', onPopState); history.back(); };
        const stay = () => history.pushState({ collabGuard: true }, '', location.href);
        if (fileEditDirty) {
            stay();
            showLeaveFileConfirm(async () => {
                try { if (fileEditSaveFn) await fileEditSaveFn(); setFileEditDirty(false); goBack(); }
                catch (e) { setError(e.message); }
            }, () => { if (fileEditDiscardFn) fileEditDiscardFn(); setFileEditDirty(false); goBack(); });
        } else if (confirm('Leave Collab?')) {
            goBack();
        } else {
            stay();
        }
    });
})();

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
        await pollNews();
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
        // Preserve scroll position over the tbody swap.
        const savedScrollY = window.scrollY;
        tbody.innerHTML = '';
        sorted.forEach(p => tbody.appendChild(makeProjRow(p)));
        window.scrollTo(0, savedScrollY);
    } catch (_) {}        // silently ignore poll errors
}

async function showList() {
    stopPolling();
    setMode('browse');
    state.view = 'list';
    state.project = null;
    setActiveNav('projects-link');
    setTitle(null);
    setBreadcrumb([]);

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'loading' }, 'Loading projects…'));

    try {
        const { projects } = await adminGet('list-projects');
        app.innerHTML = '';

        app.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem;' },
            el('button', { on: { click: showCreateProject } }, '+ New project'),
            el('button', { class: 'secondary', on: { click: () => showRestoreBackup(app) } }, '↩ Restore backup'),
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

    // Fetch existing slugs for live availability check
    let existingSlugs = new Set();
    try {
        const { projects } = await adminGet('list-projects');
        existingSlugs = new Set((projects || []).map(p => p.name));
    } catch (_) {}

    const displayInput = el('input', { type: 'text', id: 'p-display', placeholder: 'My Project' });
    const slugInput    = el('input', { type: 'text', id: 'p-name',    placeholder: 'my_project' });
    const slugStatus   = el('span',  { id: 'p-slug-status', style: 'margin-left: 0.5rem; font-weight: bold; font-size: 0.9rem' });
    const slugError    = el('div',   { id: 'p-name-error', class: 'muted', style: 'color: var(--danger); display: none; margin-top: 0.25rem' });

    let slugEdited = false;

    function slugFromDisplay(name) {
        return name.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_-]/g, '').slice(0, 16);
    }

    function updateSlugStatus(slug) {
        if (!slug) {
            slugStatus.textContent = '';
            slugError.style.display = 'none';
            return;
        }
        if (!/^[a-z0-9_-]+$/.test(slug)) {
            slugStatus.textContent = '✗';
            slugStatus.style.color = 'var(--danger)';
            slugError.textContent = 'Only lowercase letters (a–z), digits, dash (-), and underscore (_) allowed.';
            slugError.style.display = '';
        } else if (existingSlugs.has(slug)) {
            slugStatus.textContent = '✗ taken';
            slugStatus.style.color = 'var(--danger)';
            slugError.textContent = `The slug "${slug}" is already in use.`;
            slugError.style.display = '';
        } else {
            slugStatus.textContent = '✓ available';
            slugStatus.style.color = 'var(--success)';
            slugError.style.display = 'none';
        }
    }

    displayInput.addEventListener('input', () => {
        if (slugEdited) return;
        const base = slugFromDisplay(displayInput.value);
        if (!base) { slugInput.value = ''; updateSlugStatus(''); return; }
        // Find the first available slug (base, base1, base2, …)
        let candidate = base;
        if (existingSlugs.has(candidate)) {
            for (let i = 1; i < 100; i++) {
                const suffix = String(i);
                candidate = base.slice(0, 16 - suffix.length) + suffix;
                if (!existingSlugs.has(candidate)) break;
            }
        }
        slugInput.value = candidate;
        updateSlugStatus(candidate);
    });

    slugInput.addEventListener('input', () => {
        slugEdited = true;
        updateSlugStatus(slugInput.value);
    });

    // If user clears the slug field, re-enable auto-generation
    slugInput.addEventListener('change', () => {
        if (!slugInput.value) slugEdited = false;
    });

    const card = el('div', { class: 'card' },
        el('h1', {}, 'Create project'),
        el('label', {}, 'Project name'),
        displayInput,
        el('label', {}, 'URL slug'),
        el('div', { style: 'display: flex; align-items: center; gap: 0.25rem' },
            slugInput,
            slugStatus,
        ),
        el('div', { class: 'muted' }, 'Lowercase letters, digits, dash, underscore — no spaces. Max 16 characters.'),
        slugError,
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
        el('label', { class: 'row', style: 'gap: 0.5rem; align-items: center; margin-top: 0.75rem' },
            el('input', { type: 'checkbox', id: 'p-kanban', checked: 'checked' }),
            el('span', {}, 'Kanban board'),
        ),
        el('div', { class: 'muted' }, 'Starts with one empty "Backlog" work package. Can be switched off later in Settings.'),
        el('div', { class: 'row', style: 'margin-top: 1rem' },
            el('button', { on: { click: async () => {
                try {
                    const name = document.getElementById('p-name').value.trim();
                    if (!name) throw new Error('Project slug is required');
                    if (!/^[a-z0-9_-]+$/.test(name)) throw new Error('Invalid slug: only lowercase letters, digits, dash (-), and underscore (_) allowed.');
                    if (existingSlugs.has(name)) throw new Error(`The slug "${name}" is already in use.`);
                    const display_name = document.getElementById('p-display').value.trim() || name;
                    const description = document.getElementById('p-desc').value.trim();
                    const naming_scheme = document.getElementById('p-scheme').value;
                    const has_kanban = document.getElementById('p-kanban').checked;
                    await adminPost('create-project', { name, display_name, description, naming_scheme, has_kanban });
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

// Red reminder under the project title: every task currently claimed by
// (or assigned by a manager to) the logged-in user and not yet finished.
function updateAssignmentNotice(board) {
    const box = document.getElementById('assignment-notice');
    if (!box) return;
    box.innerHTML = '';
    const me = window.CURRENT_USER && window.CURRENT_USER.email;
    if (!me || !board || !board.wps) return;
    for (const wp of board.wps) {
        const labels = kanbanComputeLabels(wp, board.wps.indexOf(wp) + 1);
        (wp.tasks || []).forEach((t, i) => {
            if (t.state !== 'claimed' || t.claimed_by !== me) return;
            box.appendChild(el('div', { class: 'assignment-notice' },
                `You have been assigned to task ${t.label || labels[i]}`,
                t.name ? ` (${t.name})` : '',
                '. It is still unfinished.'));
        });
    }
}

async function showProject(name, owner = null) {
    setMode('browse');
    state.view = 'project';
    state.project = name;
    state.projectOwner = owner || (window.CURRENT_USER && window.CURRENT_USER.email);
    state.tab = 'files';
    setTitle(name);
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
        setTitle(data.display_name);

        app.innerHTML = '';
        app.appendChild(el('h1', {}, data.display_name));
        if (data.role_in_project !== 'owner') {
            app.appendChild(el('div', { class: 'project-owner muted' }, 'Project owned by ', el('strong', {}, data.owner)));
        }
        app.appendChild(el('div', { id: 'assignment-notice' }));
        app.appendChild(el('div', { class: 'muted', style: 'margin-bottom: 1rem' }, data.description));
        const canShare = window.CURRENT_USER.role === 'admin' || data.role_in_project === 'owner';
        app.appendChild(buildTabStrip(data));
        app.appendChild(el('div', { id: 'tab-content' }));

        renderTab();
        if (data.has_kanban) {
            adminGet('kanban', { p: name, owner: state.projectOwner })
                .then(updateAssignmentNotice).catch(() => {});
        }
        pollNews();   // show badges immediately, don't wait for first poll tick
        startPolling();
    } catch (e) {
        app.innerHTML = '';
        app.appendChild(el('div', { class: 'error' }, e.message));
    }
}

function buildTabStrip(data) {
    return el('div', { class: 'tabs' },
        tabBtn('files', 'Files'),
        data.has_kanban ? tabBtn('kanban', 'Kanban') : null,
        tabBtn('chat', 'Chat'),
        tabBtn('agents', 'Agents'),
        tabBtn('share', 'Share'),
        tabBtn('settings', 'Settings'),
    );
}

function tabBtn(key, label) {
    const btn = el('button', {
        id: 'tab-btn-' + key,
        class: state.tab === key ? 'active' : '',
        on: { click: () => confirmLeaveIfDirty(() => {
            setMode('browse');
            state.tab = key;
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderTab();
        }) }
    }, label);
    return btn;
}

async function pollNews() {
    if (!state.project) return;
    try {
        const news = await uiApi('news');
        const fileTabUnread = news.files_list_unread ? 1 : 0;
        const chatUnread = news.chat_unread || 0;
        updateBadges({ files: fileTabUnread, chat: chatUnread });
    } catch (e) { /* silent — badges are best-effort */ }
}

function updateBadges(counts) {
    for (const [key, count] of Object.entries(counts)) {
        const btn = document.getElementById('tab-btn-' + key);
        if (!btn) continue;
        // Remove old badge if present
        const old = btn.querySelector('.tab-badge');
        if (old) old.remove();
        if (count > 0) {
            btn.appendChild(el('span', { class: 'tab-badge' }));
        }
    }
}

function renderTab() {
    const pane = document.getElementById('tab-content');
    if (!pane) return;
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading…'));
    if (state.tab === 'files') renderFiles();
    else if (state.tab === 'kanban') renderKanban();
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
        const { files } = await uiApi('files');
        const pane = document.getElementById('tab-content');
        if (!pane) return;
        // Preserve window scroll position over the rebuild so polling
        // doesn't yank the user back to the top.
        const savedScrollY = window.scrollY;
        pane.innerHTML = '';

        // Mark file list as seen — clears the tab dot independently of per-file read dots
        uiApi('files-seen', { method: 'POST' }).catch(() => {});
        const tabBtn = document.getElementById('tab-btn-files');
        if (tabBtn) { const b = tabBtn.querySelector('.tab-badge'); if (b) b.remove(); }

        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { on: { click: showCreateFile } }, '+ Create File'),
            el('button', { style: 'margin-left: 0.5rem', on: { click: showUploadFile } }, '+ Upload File'),
        ));

        if (files.length === 0) {
            pane.appendChild(el('div', { class: 'empty' }, 'No files yet.'));
            window.scrollTo(0, savedScrollY);
            return;
        }

        const active = files.filter(f => f.state === 'active');
        const deleted = files.filter(f => f.state === 'deleted');

        const makeRow = (f) => el('tr', { class: f.state === 'deleted' ? 'deleted' : '' },
            el('td', {},
                f.state === 'active'
                    ? el('a', { href: '#', class: 'file-link', on: { click: (e) => { e.preventDefault(); showFile(f.path, f.size); } } }, f.path)
                    : el('span', {}, f.path)
            ),
            el('td', { class: 'right' }, `v${f.version}`),
            el('td', { class: 'right' }, f.size !== null ? fmtSize(f.size) : '—'),
            el('td', { class: 'muted' }, fmtRelative(f.modified)),
            el('td', { class: 'muted' }, f.modified_by || '—'),
            el('td', {},
                f.state === 'active'
                    ? el('span', { class: 'row', style: 'gap: 0.25rem; flex-wrap: nowrap' },
                        el('button', { class: 'secondary', title: 'Download', on: { click: () => downloadFile(f.path) }}, el('img', { src: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEoAAABKCAYAAAAc0MJxAAAFIklEQVR4nO2cvU7zPBiGb9tpgBbKUhhAApYKlVMoCwssbBWHwcJZsHAUDAxsdGFATBwCExJigxE1gBChub+Bz3ndQoKbpiQgX5LVqM2Pn6uPU9d2K0jC8T2y6Ar8FpwoSwoRpZt7t9vl6uoqPc+jUopSSgohKISglJJKKXqex9XVVXa7XZrHFlLpIsrb2xuazSYBUClFIQQBDBQhBJVSBMBms8m3t7fC6ltY03t9fcXDwwOEEIiiCCQhhIhfF0KAJKIoghACDw8PeH19Laq6xYkSQkBKGQvSYjTDz0spB0T+NKW5mX917ylT16UwUbrt6+20/Yb3L4LSZFTZcaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIsmYgom0kAz/Pi6ae0aShzH8/zcrl2Fr6/cgaEEOj3+3h5eUnc5+npaeRZmMfHR7y/vyfuW61WoZTKWOt0RJ7vgJ60vLy85MHBAe7u7uLnh69DMpY1PPk5UMH/XxNCYHZ29lP26UlSAFhbW8PR0RG2trbE8MxzLsHlWcIwRKvV+rSO4KdKq9ViGIa5x5V709OZoZSKm+B3+9tmVBpKKaumnJk8revFFqenpwRAz/MopYxXpiDn7NHnlFLS8zwC4OnpKc265FVyT1FdwcPDw1iWDihPWeY5taTDw8OJSJqIKJLo9/sgif39fQJgpVLJVZZ5rkqlQgDc39+nee1fIUpXOIoidDqdXGV9JanT6TCKoolJmqgonf5PT09ot9sDsnTJIkkXLandblN3MybR5CYuyqz4/f39wDJEM+hRJZnnaDabvL+/n7ikiYsi/90zrq+vsbCwMBBolqKPXVhY4PX19cA1frUoknh/fwdJXFxccHp6Ol7xa3u/MjNJSsmZmRleXFzQPPefEGUGdHx8HHcbbPpYX/WVjo+Pf1TSj4oyA7PtY6X1lX5S0o+LIu37WEX0lUolSgea1scqqq+UVlKHWcwxo2H0sEcWyI8vuc/Pz9jZ2eHV1RUqlcqnsSbP8xCGIdrtNs7Pz0WtVouPzcJY8SQZDIIAS0tLrNfrnJ+fZ71eH9heXl5mEASZ36G0Ppa5nVdfKQgCLC8vJ8aztLSUGk/iC71eD9VqNfHTqFarsdfrjd0EyX99LN3UdFPMs6/U6/VQq9US46lWq6nxJI6Z6zFq/VMMPZKot80x76xIKdHv97GxsYGTkxP4vo8wDBGGIXzfx8nJCTY2NtDv9yHleMP7Y8eT9g7Mzc0lfhrV6/WxM2o4s87Ozri+vs5Wq8Vut8s8MsmMp16vJ8YzNzeXGs9EJhdGRf94aHd3V2xvb0MIgUql8pHyY2ZSXpRCFID452i+7wMAoigqjSSgZBOgppgySQJKJqrMOFGWOFGWjCxq1LUAZcF2rUPi8aMeQDJ+/G4tQJmwXeuQROaUCIIA6+vrLPIH0aNAfkx0ZCVRlLn4wZzyNh+DIMh84SIxM8qcsk9700fOKNu1AGVF133U5pcoKh5eGDr5OO28LOi6D7eUtJgSP/WmpqbQaDQgpRz4I4e/gvnHE1JKNBoNTE1NJe7/pSiS8H0fm5ubiKIISqlP/3rxWxn+dw6lFKIoQrvdhu/7yVn11ZCCHkm8vb3FysrKpyGJv1DMWFZWVnh7ezsQ+3D5dhzn5uYGe3t7XFxcHGvdQFmKGcPi4iL39vZ4c3PzrYdc13D+Zdx3PUucKEucKEucKEucKEucKEv+A97GN/uTECooAAAAAElFTkSuQmCC', class: 'dl-icon', alt: 'download', style: 'width:14px;height:14px;vertical-align:middle' })),
                        el('button', { class: 'danger', on: { click: () => deleteFile(f.path, f.version) }}, 'delete')
                    )
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
        window.scrollTo(0, savedScrollY);
    } catch (e) {
        setError(e.message);
    }
}

function fmtSize(bytes) {
    if (bytes < 1024) return `${bytes}B`;
    if (bytes < 1024*1024) return `${(bytes/1024).toFixed(1)}K`;
    return `${(bytes/1024/1024).toFixed(1)}M`;
}

// Entry point from the file list. Files at/above HEX_PREVIEW_THRESHOLD don't
// auto-fetch+render (that's what made large binary files slow to open) —
// instead the user explicitly picks a view, or just downloads.
async function showFile(path, size) {
    if (typeof size === 'number' && size >= HEX_PREVIEW_THRESHOLD) {
        showFileChooser(path, size);
        return;
    }
    await openFileViewer(path);
}

function showFileChooser(path, size) {
    setMode('editing');  // block polling while viewing
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
        el('button', { class: 'secondary', on: { click: () => confirmLeaveIfDirty(() => { setMode('browse'); state.tab = 'files'; renderTab(); }) }}, '← Files'),
        el('strong', {}, path),
        el('span', { class: 'muted' }, fmtSize(size)),
    ));
    pane.appendChild(el('p', { class: 'muted' },
        `This file is ${fmtSize(size)} — larger than the ${fmtSize(HEX_PREVIEW_THRESHOLD)} auto-preview limit. Rendering it takes a while, so pick how you want to view it, or just download it.`));
    const chooserBtns = [];
    if (isImageFile(path)) {
        chooserBtns.push(el('button', { on: { click: () => openFileViewer(path, 'img') }}, 'View as image'));
    }
    chooserBtns.push(
        el('button', { on: { click: () => openFileViewer(path, 'txt') }}, 'View as text'),
        el('button', { on: { click: () => openFileViewer(path, 'hex') }}, 'View as hex'),
        el('button', { class: 'secondary', on: { click: () => downloadFile(path) }}, 'Download'),
    );
    pane.appendChild(el('div', { class: 'row' }, ...chooserBtns));
}

// Fetches and renders the file. `forcedMode` (optional: 'txt' | 'hex') skips
// the size-based auto default — used when the user picked a view explicitly
// from showFileChooser().
async function openFileViewer(path, forcedMode) {
    setMode('editing');  // block polling while viewing/editing
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading file…'));

    try {
        const res = await uiApi('file', { params: { file: path } });
        const arrayBuf = res.raw;
        const version = res.headers.get('X-File-Version');
        const modifiedBy = res.headers.get('X-File-Modified-By');

        const bytes = new Uint8Array(arrayBuf);
        const isBinary = detectBinary(bytes);
        const originalText = isBinary ? null : new TextDecoder().decode(bytes);
        const isMarkdown = !isBinary && /\.md$/i.test(path);
        const imageMime = imageMimeFor(path);
        const isImage = imageMime !== undefined;
        let imgBlobUrl = null;  // revoked whenever we leave/re-render the image view

        pane.innerHTML = '';

        // Back button + meta
        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { class: 'secondary', on: { click: () => confirmLeaveIfDirty(() => { revokeImgBlobUrl(); setMode('browse'); state.tab = 'files'; renderTab(); }) }}, '← Files'),
            el('strong', {}, path),
            el('span', { class: 'muted' }, `v${version} · by ${modifiedBy} · ${fmtSize(bytes.length)}`),
        ));

        function revokeImgBlobUrl() {
            if (imgBlobUrl) { URL.revokeObjectURL(imgBlobUrl); imgBlobUrl = null; }
        }

        // View modes: 'md' | 'txt' | 'hex' | 'img'. forcedMode picks the initial
        // mode when the user chose one explicitly (large-file chooser); falls
        // back sensibly if that mode isn't valid for this file (e.g. 'txt' on
        // binary). Image files default to the image view when recognized by
        // extension, regardless of whether their bytes look "binary".
        let viewMode;
        if (forcedMode === 'md' && isMarkdown) viewMode = 'md';
        else if (forcedMode === 'txt' && !isBinary) viewMode = 'txt';
        else if (forcedMode === 'img' && isImage) viewMode = 'img';
        else if (forcedMode === 'hex') viewMode = 'hex';
        else viewMode = isImage ? 'img' : (isMarkdown ? 'md' : (isBinary ? 'hex' : 'txt'));

        // liveBytes is the editable byte sequence (plain array, mutable length —
        // unlike the original `bytes` Uint8Array — so Insert/Delete can grow or
        // shrink it, not just overwrite existing byte values).
        let liveBytes = Array.from(bytes);
        let hexDirty = false;          // true once any hex edit/insert/delete happened
        let textValue = originalText;  // live textarea content (only meaningful when !isBinary)

        // Only text files are editable in txt/md view (binary files stay read-only
        // there); hex view is byte-level and works for any file.
        function isDirty() {
            return hexDirty || (!isBinary && textValue !== originalText);
        }
        function syncDirtyState() {
            setFileEditDirty(isDirty(), saveCurrentEdits, discardCurrentEdits);
        }
        async function saveCurrentEdits() {
            if (hexDirty) {
                const edited = Uint8Array.from(liveBytes);
                const d = await uiApi('file', {
                    method: 'PUT',
                    params: { file: path },
                    headers: { 'X-Expected-Version': version, 'Content-Type': 'application/octet-stream' },
                    body: edited,
                });
                setStatus(`Saved as v${d.version}`);
            } else if (!isBinary && textValue !== originalText) {
                const encoded = new TextEncoder().encode(textValue);
                const d = await uiApi('file', {
                    method: 'PUT',
                    params: { file: path },
                    headers: { 'X-Expected-Version': version, 'Content-Type': 'text/plain; charset=utf-8' },
                    body: encoded,
                });
                setStatus(`Saved as v${d.version}`);
            }
        }
        function discardCurrentEdits() {
            liveBytes = Array.from(bytes);
            hexDirty = false;
            textValue = originalText;
        }

        // Focuses a hex cell by byte index after a re-render (used after
        // Insert/Delete so the cursor lands on the new/adjacent byte).
        function focusByteAt(idx) {
            const cell = document.querySelector(`[data-byte-idx="${idx}"]`);
            if (cell) cell.focus();
        }

        const btnBar = el('div', { class: 'row', style: 'margin-bottom: 0.75rem; gap: 0.25rem' });

        function makeViewBtn(label, mode) {
            return el('button', {
                class: 'secondary view-toggle',
                'data-viewmode': mode,
                on: { click: () => {
                    if (viewMode === mode) return;
                    viewMode = mode;
                    syncToggleBtns();
                    renderContent();
                    updateButtons();
                }}
            }, label);
        }

        const imgBtn = isImage   ? makeViewBtn('image view', 'img') : null;
        const mdBtn  = isMarkdown ? makeViewBtn('Markdown view', 'md') : null;
        const txtBtn = isBinary   ? null : makeViewBtn('txt view', 'txt');
        const hexBtn = makeViewBtn('hex view', 'hex');

        function syncToggleBtns() {
            [imgBtn, mdBtn, txtBtn, hexBtn].forEach(b => {
                if (!b) return;
                const active = b.getAttribute('data-viewmode') === viewMode;
                b.classList.toggle('view-toggle-active', active);
            });
        }

        if (imgBtn) btnBar.appendChild(imgBtn);
        if (mdBtn)  btnBar.appendChild(mdBtn);
        if (txtBtn) btnBar.appendChild(txtBtn);
        btnBar.appendChild(hexBtn);
        syncToggleBtns();
        pane.appendChild(btnBar);
        const dlBtn = el('button', {
            class: 'secondary',
            style: 'margin-left: 0.5rem',
            title: 'Download file',
            on: { click: () => downloadFile(path) }
        }, el('img', { src: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEoAAABKCAYAAAAc0MJxAAAFIklEQVR4nO2cvU7zPBiGb9tpgBbKUhhAApYKlVMoCwssbBWHwcJZsHAUDAxsdGFATBwCExJigxE1gBChub+Bz3ndQoKbpiQgX5LVqM2Pn6uPU9d2K0jC8T2y6Ar8FpwoSwoRpZt7t9vl6uoqPc+jUopSSgohKISglJJKKXqex9XVVXa7XZrHFlLpIsrb2xuazSYBUClFIQQBDBQhBJVSBMBms8m3t7fC6ltY03t9fcXDwwOEEIiiCCQhhIhfF0KAJKIoghACDw8PeH19Laq6xYkSQkBKGQvSYjTDz0spB0T+NKW5mX917ylT16UwUbrt6+20/Yb3L4LSZFTZcaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIscaIsmYgom0kAz/Pi6ae0aShzH8/zcrl2Fr6/cgaEEOj3+3h5eUnc5+npaeRZmMfHR7y/vyfuW61WoZTKWOt0RJ7vgJ60vLy85MHBAe7u7uLnh69DMpY1PPk5UMH/XxNCYHZ29lP26UlSAFhbW8PR0RG2trbE8MxzLsHlWcIwRKvV+rSO4KdKq9ViGIa5x5V709OZoZSKm+B3+9tmVBpKKaumnJk8revFFqenpwRAz/MopYxXpiDn7NHnlFLS8zwC4OnpKc265FVyT1FdwcPDw1iWDihPWeY5taTDw8OJSJqIKJLo9/sgif39fQJgpVLJVZZ5rkqlQgDc39+nee1fIUpXOIoidDqdXGV9JanT6TCKoolJmqgonf5PT09ot9sDsnTJIkkXLandblN3MybR5CYuyqz4/f39wDJEM+hRJZnnaDabvL+/n7ikiYsi/90zrq+vsbCwMBBolqKPXVhY4PX19cA1frUoknh/fwdJXFxccHp6Ol7xa3u/MjNJSsmZmRleXFzQPPefEGUGdHx8HHcbbPpYX/WVjo+Pf1TSj4oyA7PtY6X1lX5S0o+LIu37WEX0lUolSgea1scqqq+UVlKHWcwxo2H0sEcWyI8vuc/Pz9jZ2eHV1RUqlcqnsSbP8xCGIdrtNs7Pz0WtVouPzcJY8SQZDIIAS0tLrNfrnJ+fZ71eH9heXl5mEASZ36G0Ppa5nVdfKQgCLC8vJ8aztLSUGk/iC71eD9VqNfHTqFarsdfrjd0EyX99LN3UdFPMs6/U6/VQq9US46lWq6nxJI6Z6zFq/VMMPZKot80x76xIKdHv97GxsYGTkxP4vo8wDBGGIXzfx8nJCTY2NtDv9yHleMP7Y8eT9g7Mzc0lfhrV6/WxM2o4s87Ozri+vs5Wq8Vut8s8MsmMp16vJ8YzNzeXGs9EJhdGRf94aHd3V2xvb0MIgUql8pHyY2ZSXpRCFID452i+7wMAoigqjSSgZBOgppgySQJKJqrMOFGWOFGWjCxq1LUAZcF2rUPi8aMeQDJ+/G4tQJmwXeuQROaUCIIA6+vrLPIH0aNAfkx0ZCVRlLn4wZzyNh+DIMh84SIxM8qcsk9700fOKNu1AGVF133U5pcoKh5eGDr5OO28LOi6D7eUtJgSP/WmpqbQaDQgpRz4I4e/gvnHE1JKNBoNTE1NJe7/pSiS8H0fm5ubiKIISqlP/3rxWxn+dw6lFKIoQrvdhu/7yVn11ZCCHkm8vb3FysrKpyGJv1DMWFZWVnh7ezsQ+3D5dhzn5uYGe3t7XFxcHGvdQFmKGcPi4iL39vZ4c3PzrYdc13D+Zdx3PUucKEucKEucKEucKEucKEv+A97GN/uTECooAAAAAElFTkSuQmCC', class: 'dl-icon', alt: 'download', style: 'width:14px;height:14px;vertical-align:middle' }));
        btnBar.appendChild(dlBtn);

        // --- Content renderer ---
        function renderContent() {
            const existing = document.getElementById('file-view-area');
            if (existing) existing.remove();
            revokeImgBlobUrl();  // safe no-op unless we're leaving/re-entering the image view

            if (viewMode === 'img') {
                const wrapper = el('div', { id: 'file-view-area', class: 'file-content img-view' });
                imgBlobUrl = URL.createObjectURL(new Blob([bytes], { type: imageMime }));
                wrapper.appendChild(el('img', { src: imgBlobUrl, alt: path }));
                pane.appendChild(wrapper);

            } else if (viewMode === 'md') {
                const mdDiv = el('div', { id: 'file-view-area', class: 'file-content md-view' });
                mdDiv.innerHTML = simpleMarkdown(originalText);
                pane.appendChild(mdDiv);

            } else if (viewMode === 'txt') {
                const ta = el('textarea', { id: 'file-view-area', class: 'file-content txt-view txt-edit', spellcheck: 'false' });
                ta.value = textValue;
                ta.addEventListener('input', () => {
                    textValue = ta.value;
                    syncDirtyState();
                    updateButtons();
                });
                pane.appendChild(ta);

            } else {
                // hex view — byte-level editing works for any file, binary or text
                // (only the txt/md text-view stays restricted to non-binary files).
                // Typing hex digits overwrites a byte's value; Insert adds a new
                // 00 byte before the cursor; Delete removes the byte under the cursor.
                const wrapper = el('div', { id: 'file-view-area' });
                wrapper.appendChild(el('p', { class: 'muted', style: 'margin: 0 0 0.4rem 0; font-size: 0.8rem' },
                    'Type hex digits to edit a byte · Insert = add a byte before the cursor · Delete = remove the byte under the cursor.'));
                const container = el('div', { class: 'hex-editor-container file-content' });
                renderHexEditor(container, liveBytes, bytes, (idx, val) => {
                    liveBytes[idx] = val;
                    hexDirty = true;
                    syncDirtyState();
                    updateButtons();
                }, (idx) => {
                    liveBytes.splice(idx, 0, 0x00);
                    hexDirty = true;
                    syncDirtyState();
                    renderContent();
                    updateButtons();
                    focusByteAt(idx);
                }, (idx) => {
                    if (liveBytes.length === 0) return;
                    liveBytes.splice(idx, 1);
                    hexDirty = true;
                    syncDirtyState();
                    renderContent();
                    updateButtons();
                    focusByteAt(Math.min(idx, liveBytes.length - 1));
                });
                wrapper.appendChild(container);
                pane.appendChild(wrapper);
            }
        }

        // --- Action buttons ---
        const btnRow = el('div', { class: 'row', style: 'margin-top: 1rem', id: 'file-btn-row' });

        function updateButtons() {
            btnRow.innerHTML = '';
            if (isDirty()) {
                let label = 'Update file';
                if (hexDirty) {
                    const diff = liveBytes.length - bytes.length;
                    label = diff === 0
                        ? 'Save hex edits'
                        : `Save hex edits (${diff > 0 ? '+' : ''}${diff} byte${Math.abs(diff) === 1 ? '' : 's'})`;
                }
                btnRow.appendChild(el('button', { on: { click: async () => {
                    try {
                        await saveCurrentEdits();
                        setFileEditDirty(false);
                        setMode('browse'); state.tab = 'files'; renderTab();
                    } catch (e) { setError(e.message); }
                }}}, label));
                btnRow.appendChild(el('button', { class: 'secondary', on: { click: () => {
                    discardCurrentEdits();
                    syncDirtyState();
                    renderContent();
                    updateButtons();
                }}}, 'Discard edits'));
            }
            btnRow.appendChild(el('button', { class: 'secondary', on: { click: () => confirmLeaveIfDirty(() => { setMode('browse'); state.tab = 'files'; renderTab(); }) }}, 'Close'));
        }

        renderContent();
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

// --- Markdown renderer (lightweight, no external deps) ---
function simpleMarkdown(md) {
    if (!md) return '';

    function esc(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function inline(s) {
        return s
            .replace(/`([^`]+)`/g, '<code class="md-inline-code">$1</code>')
            .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/__(.+?)__/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/_(.+?)_/g, '<em>$1</em>')
            .replace(/~~(.+?)~~/g, '<del>$1</del>')
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    }

    const lines = md.split('\n');
    const out = [];
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        // Fenced code block
        if (/^```/.test(line)) {
            const codeLines = [];
            i++;
            while (i < lines.length && !/^```/.test(lines[i])) { codeLines.push(esc(lines[i])); i++; }
            i++;
            out.push(`<pre class="md-code"><code>${codeLines.join('\n')}</code></pre>`);
            continue;
        }

        // Headings
        const hm = line.match(/^(#{1,6}) (.+)$/);
        if (hm) { out.push(`<h${hm[1].length}>${inline(esc(hm[2]))}</h${hm[1].length}>`); i++; continue; }

        // HR
        if (/^---+$/.test(line.trim())) { out.push('<hr>'); i++; continue; }

        // Blockquote
        if (/^> /.test(line)) { out.push(`<blockquote>${inline(esc(line.slice(2)))}</blockquote>`); i++; continue; }

        // Table: a run of lines containing |, where line[1] is a separator (|---|)
        if (/\|/.test(line)) {
            const tableLines = [];
            while (i < lines.length && /\|/.test(lines[i])) { tableLines.push(lines[i]); i++; }
            const sepIdx = tableLines.findIndex(l => /^\|?[\s\-:]+\|[\s\-:|]+$/.test(l));
            if (sepIdx === 1 && tableLines.length >= 2) {
                const parseRow = row => row.replace(/^\|/, '').replace(/\|$/, '').split('|').map(c => c.trim());
                const headers = parseRow(tableLines[0]);
                let thtml = '<table class="md-table"><thead><tr>';
                thtml += headers.map(h => `<th>${inline(esc(h))}</th>`).join('');
                thtml += '</tr></thead><tbody>';
                for (const row of tableLines.slice(2)) {
                    if (!/\|/.test(row)) continue;
                    thtml += '<tr>' + parseRow(row).map(c => `<td>${inline(esc(c))}</td>`).join('') + '</tr>';
                }
                thtml += '</tbody></table>';
                out.push(thtml);
            } else {
                for (const tl of tableLines) out.push(`<p>${inline(esc(tl))}</p>`);
            }
            continue;
        }

        // Unordered list
        if (/^[ \t]*[-*+] /.test(line)) {
            const items = [];
            while (i < lines.length && /^[ \t]*[-*+] /.test(lines[i])) {
                items.push(`<li>${inline(esc(lines[i].replace(/^[ \t]*[-*+] /, '')))}</li>`); i++;
            }
            out.push(`<ul>${items.join('')}</ul>`);
            continue;
        }

        // Ordered list
        if (/^[ \t]*\d+\. /.test(line)) {
            const items = [];
            while (i < lines.length && /^[ \t]*\d+\. /.test(lines[i])) {
                items.push(`<li>${inline(esc(lines[i].replace(/^[ \t]*\d+\. /, '')))}</li>`); i++;
            }
            out.push(`<ol>${items.join('')}</ol>`);
            continue;
        }

        // Blank line
        if (line.trim() === '') { i++; continue; }

        // Paragraph
        const paraLines = [];
        while (i < lines.length && lines[i].trim() !== '' &&
               !/^(#{1,6} |```|---+$|> |\|.*\||[ \t]*[-*+] |[ \t]*\d+\. )/.test(lines[i])) {
            paraLines.push(inline(esc(lines[i]))); i++;
        }
        if (paraLines.length) out.push(`<p>${paraLines.join('<br>')}</p>`);
    }

    return out.join('\n');
}

// --- Hex editor ---
// Renders bytes as an interactive table into `container`.
// `edits` is a plain object {byteIndex: newValue}. Read from it to show current state.
// If `onEdit` is a function(idx, val), cells are contentEditable; otherwise read-only.
// `liveBytes` is the current (possibly resized) byte sequence being displayed;
// `originalBytes` (may be null) is used only for change-highlighting when its
// length still matches liveBytes — once an Insert/Delete has shifted things,
// position-based diff highlighting is skipped rather than shown misleadingly.
// onEdit(idx, val) overwrites a byte's value; onInsert(idx)/onDelete(idx) are
// only wired up when byte-level insert/delete is supported by the caller.
function renderHexEditor(container, liveBytes, originalBytes, onEdit, onInsert, onDelete) {
    container.innerHTML = '';
    const editable = typeof onEdit === 'function';
    const sameLength = !!originalBytes && originalBytes.length === liveBytes.length;
    const COLS = 16;
    const table = document.createElement('table');
    table.className = 'hex-table';

    for (let row = 0; row < Math.ceil(liveBytes.length / COLS); row++) {
        const tr = document.createElement('tr');
        const offset = row * COLS;

        // Address
        const addrTd = document.createElement('td');
        addrTd.className = 'hex-addr';
        addrTd.textContent = offset.toString(16).padStart(6, '0');
        tr.appendChild(addrTd);

        // Hex bytes
        const hexTd = document.createElement('td');
        hexTd.className = 'hex-bytes';
        for (let col = 0; col < COLS; col++) {
            const idx = offset + col;
            if (col === 8) {
                const sep = document.createElement('span');
                sep.className = 'hex-sep';
                sep.textContent = ' ';
                hexTd.appendChild(sep);
            }
            if (idx >= liveBytes.length) {
                const pad = document.createElement('span');
                pad.className = 'hex-cell hex-pad';
                pad.textContent = '  ';
                hexTd.appendChild(pad);
                if (col < COLS - 1) hexTd.appendChild(document.createTextNode(' '));
                continue;
            }
            const displayVal = liveBytes[idx];
            const changed = sameLength && originalBytes[idx] !== displayVal;
            const cell = document.createElement('span');
            cell.className = 'hex-cell' + (editable ? ' hex-editable' : '') + (changed ? ' hex-changed' : '');
            cell.title = `0x${idx.toString(16).toUpperCase()} (${idx})`;
            cell.textContent = displayVal.toString(16).padStart(2, '0');
            cell.dataset.byteIdx = String(idx);

            if (editable) {
                cell.contentEditable = 'true';
                cell.setAttribute('contenteditable', 'true');
                cell.tabIndex = 0;
                cell.spellcheck = false;
                cell.addEventListener('focus', () => {
                    const sel = window.getSelection(), range = document.createRange();
                    range.selectNodeContents(cell);
                    sel.removeAllRanges(); sel.addRange(range);
                });
                cell.addEventListener('keydown', e => {
                    if (e.key === 'Insert' && typeof onInsert === 'function') {
                        e.preventDefault();
                        onInsert(idx);
                        return;
                    }
                    if (e.key === 'Delete' && typeof onDelete === 'function') {
                        e.preventDefault();
                        onDelete(idx);
                        return;
                    }
                    if (!'0123456789abcdefABCDEF'.includes(e.key) &&
                        !['Backspace','Tab','ArrowLeft','ArrowRight'].includes(e.key)) {
                        e.preventDefault();
                    }
                    if (e.key === 'Enter') { e.preventDefault(); cell.blur(); }
                });
                cell.addEventListener('blur', () => {
                    const raw = cell.textContent.replace(/\s/g, '').slice(0, 2).padStart(2, '0');
                    const val = parseInt(raw, 16);
                    if (!isNaN(val) && val >= 0 && val <= 255) {
                        onEdit(idx, val);
                        cell.textContent = val.toString(16).padStart(2, '0');
                        cell.classList.toggle('hex-changed', sameLength && val !== originalBytes[idx]);
                    } else {
                        cell.textContent = displayVal.toString(16).padStart(2, '0');
                    }
                });
            }
            hexTd.appendChild(cell);
            if (col < COLS - 1) hexTd.appendChild(document.createTextNode(' '));
        }
        tr.appendChild(hexTd);

        // ASCII
        const ascTd = document.createElement('td');
        ascTd.className = 'hex-ascii';
        for (let col = 0; col < COLS; col++) {
            const idx = offset + col;
            if (idx >= liveBytes.length) break;
            const v = liveBytes[idx];
            const span = document.createElement('span');
            span.textContent = (v >= 32 && v < 127) ? String.fromCharCode(v) : '.';
            ascTd.appendChild(span);
        }
        tr.appendChild(ascTd);
        table.appendChild(tr);
    }
    container.appendChild(table);
}

async function downloadFile(path) {
    try {
        const res = await uiApi('file', { params: { file: path } });
        const blob = new Blob([res.raw], { type: res.headers.get('Content-Type') || 'application/octet-stream' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = path.split('/').pop();
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000);
    } catch (e) { setError(e.message); }
}

async function deleteFile(path, version) {
    if (!confirm(`Delete ${path}?`)) return;
    try {
        await uiApi('file', {
            method: 'DELETE',
            params: { file: path },
            headers: { 'X-Expected-Version': String(version) },
        });
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
                const d = await uiApi('file', {
                    method: 'PUT',
                    params: { file: path },
                    headers: { 'X-New-File': 'true', 'Content-Type': 'text/plain' },
                    body: content,
                });
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
                const d = await uiApi('file', {
                    method: 'PUT',
                    params: { file: path },
                    headers: { 'X-New-File': 'true', 'Content-Type': file.type || 'application/octet-stream' },
                    body: arrayBuffer,
                });
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
        const { messages } = await uiApi('chat', { params: { limit: 200 } });
        const pane = document.getElementById('tab-content');
        if (!pane) return;

        // Mark chat as seen — clears the tab badge
        uiApi('chat-seen', { method: 'POST' }).catch(() => {});
        const chatTabBtn = document.getElementById('tab-btn-chat');
        if (chatTabBtn) { const b = chatTabBtn.querySelector('.tab-badge'); if (b) b.remove(); }

        // Preserve textarea content if polling re-renders while user is typing
        const oldInput = document.getElementById('chat-input');
        const oldText = oldInput ? oldInput.value : '';

        // The chat refresh is on a 5s timer and we tear down the whole
        // chat-box on every tick. By default the user wants to *stay where
        // they are reading* — we snapshot the scroll position before
        // rebuilding and restore it after. The only exception is when the
        // user just posted a message (postChat sets jumpToNewest=true);
        // in that case they want to see their post land at the bottom.
        const oldBox = document.getElementById('chat-box');
        let prevScrollTop = 0;
        let prevScrollHeight = 0;
        let hadBox = false;
        if (oldBox) {
            hadBox = true;
            prevScrollTop    = oldBox.scrollTop;
            prevScrollHeight = oldBox.scrollHeight;
        }
        const jumpToNewest = state.chatJumpToNewest === true;
        state.chatJumpToNewest = false;

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

        // Find the name the server will record (the agent whose secret we're using)
        const postingAs = window.CURRENT_USER ? window.CURRENT_USER.email.split('@')[0] : 'you';
        pane.appendChild(el('label', {}, `Post message (as ${postingAs})`));
        const ta = el('textarea', { rows: 3, id: 'chat-input' });
        ta.value = oldText;
        pane.appendChild(ta);
        pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem' },
            el('button', { on: { click: postChat }}, 'Post'),
            el('button', { class: 'danger', style: 'margin-left: 0.5rem', on: { click: showClearChatConfirm }}, 'Clear chat'),
        ));

        const cb = document.getElementById('chat-box');
        if (jumpToNewest || !hadBox) {
            // First open of the tab, or user just posted → show the tail.
            cb.scrollTop = cb.scrollHeight;
        } else {
            // Polling tick: preserve the user's viewing position exactly.
            // If new messages were appended below the user's current view,
            // scrollHeight grew but everything above is unchanged, so the
            // same scrollTop keeps the same content visible.
            cb.scrollTop = prevScrollTop;
        }
    } catch (e) {
        setError(e.message);
    }
}

async function postChat() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    try {
        await uiApi('chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text }),
        });
        input.value = '';
        // User just posted — show them their message landing at the bottom.
        state.chatJumpToNewest = true;
        refreshChat();
    } catch (e) { setError(e.message); }
}

function showClearChatConfirm() {
    const overlay = el('div', { class: 'modal-overlay', on: { click: (e) => { if (e.target === overlay) overlay.remove(); }}});
    const keepCb = el('input', { type: 'checkbox', id: 'clear-chat-keep-last' });
    const countInput = el('input', {
        type: 'number', id: 'clear-chat-keep-count', min: '1', value: '1',
        style: 'width: 4em; margin: 0 0.4em', disabled: 'disabled',
    });
    keepCb.addEventListener('change', () => {
        if (keepCb.checked) countInput.removeAttribute('disabled');
        else countInput.setAttribute('disabled', 'disabled');
    });
    const card = el('div', { class: 'modal-card' },
        el('h2', {}, 'Clear chat?'),
        el('p', {}, 'This permanently deletes the chat history for this project.'),
        el('label', { style: 'display: flex; align-items: center; gap: 0.25rem; flex-direction: row' },
            keepCb,
            'keep last',
            countInput,
            'post(s)',
        ),
        el('div', { class: 'row', style: 'margin-top: 1rem' },
            el('button', { class: 'danger', on: { click: async () => {
                const keepLast = keepCb.checked;
                const n = Math.max(1, parseInt(countInput.value, 10) || 1);
                try {
                    await uiApi('chat-clear', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ keep_count: keepLast ? n : 0 }),
                    });
                    overlay.remove();
                    setStatus('Chat cleared');
                    refreshChat();
                } catch (e) { setError(e.message); }
            }}}, 'Clear chat'),
            el('button', { class: 'secondary', on: { click: () => overlay.remove() }}, 'Cancel'),
        ),
    );
    overlay.appendChild(card);
    document.body.appendChild(overlay);
}

// --- Tab: Agents ---------------------------------------------------------

async function renderAgents() {
    await refreshAgents();
}

async function refreshAgents() {
    if (state.tab !== 'agents' || state.mode === 'editing') return;
    try {
        const data = await adminGet('project', { p: state.project, owner: state.projectOwner });
        state.projectData = data;
        state.projectOwner = data.owner || state.projectOwner;
        const presence = await uiApi('presence');

        const pane = document.getElementById('tab-content');
        if (!pane) return;
        // Preserve scroll position over the rebuild.
        const savedScrollY = window.scrollY;
        pane.innerHTML = '';

        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 1rem' },
            el('button', { on: { click: showAddAgent }}, '+ Add agent'),
        ));

        const table = el('table', {},
            el('thead', {}, el('tr', {},
                el('th', {}, ''),
                el('th', {}, 'Name'),
                el('th', {}, 'Role'),
                el('th', {}, 'Type'),
                el('th', {}, 'Secret'),
                el('th', {}, 'Last seen'),
                el('th', {}, ''),
            )),
            // The owner agent is the web UI's internal identity — not listed.
            el('tbody', {}, ...data.agents.filter(a => !a.admin).map(a =>
                el('tr', {},
                    el('td', {}, a.admin ? null : statusDot(a.last_seen)),
                    el('td', {}, el('strong', {}, a.name), a.admin ? el('span', { class: 'muted' }, ' (owner)') : null),
                    el('td', {}, a.role),
                    el('td', { class: 'muted' }, a.class || 'manager'),
                    // Secret is null for agents created by someone else (server hides it);
                    // admins still get it, rendered greyed out when a.mine is false.
                    el('td', {}, a.secret
                        ? [el('span', { class: a.mine ? 'secret' : 'secret foreign' }, a.secret), copyButton(a.secret)]
                        : el('span', { class: 'foreign-note' }, 'not your agent')),
                    el('td', { class: 'muted' }, a.admin ? '—' : fmtRelative(a.last_seen)),
                    el('td', {},
                        (a.admin || !a.secret) ? null : el('button', {
                            class: a.mine ? 'danger' : 'danger foreign',
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
        window.scrollTo(0, savedScrollY);
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

    pane.appendChild(el('label', { for: 'a-class' }, 'Type'));
    pane.appendChild(el('select', { id: 'a-class' },
        el('option', { value: 'worker' }, 'Worker — claim, comment, change task state'),
        el('option', { value: 'manager' }, 'Manager — also edit the board (add/move/group tasks)')
    ));

    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 1rem' },
        el('button', { id: 'a-add-btn', on: { click: async () => {
            try {
                let name = select.value;
                if (name === '__custom__') {
                    name = customInput.value.trim();
                    if (!name) throw new Error('Custom name required');
                }
                if (used.has(name)) throw new Error(`An agent named "${name}" already exists in this project`);
                const role = document.getElementById('a-role').value.trim() || 'Developer';
                const klass = document.getElementById('a-class').value;
                const addBtn = document.getElementById('a-add-btn');
                if (addBtn) { addBtn.disabled = true; addBtn.textContent = 'Adding…'; }
                const r = await adminPost('add-agent', { owner: state.projectOwner, project: state.project, name, role, class: klass });
                const refreshed = await adminGet('project', { p: state.project, owner: state.projectOwner });
                state.projectData = refreshed;
                state.projectOwner = refreshed.owner || state.projectOwner;
                setStatus(`Added ${name}. Secret: ${r.secret}`);
                setMode('browse');
                state.tab = 'agents';
                renderTab();
            } catch (e) {
                const addBtn = document.getElementById('a-add-btn');
                if (addBtn) { addBtn.disabled = false; addBtn.textContent = 'Add'; }
                setError(e.message);
            }
        }}}, 'Add'),
        el('button', { class: 'secondary', on: { click: () => { setMode('browse'); state.tab = 'agents'; renderTab(); } }}, 'Cancel'),
    ));
}

async function removeAgent(secret, name) {
    if (!confirm(`Remove agent ${name}? This invalidates their secret.`)) return;
    try {
        await adminPost('remove-agent', { owner: state.projectOwner, project: state.project, secret });
        setStatus(`Removed ${name}`);
        refreshAgents();
    } catch (e) { setError(e.message); }
}

// --- Tab: Kanban ---------------------------------------------------------
//
// Phase 2.3 — read-only board rendering. Fetches /ui/api.php?action=kanban
// and lays out WP columns (CSS grid) with task cards. Editing, drag-drop,
// claim/release wiring, and the task editor modal land in Phases 3 and 4.

// State machine cheat-sheet (server-authoritative):
//   open      — deps not satisfied (waiting on an earlier non-parallel task)
//   ready     — claimable, nobody on it
//   claimed   — someone is working on it; actor_color comes from server
//   partly    — was done, then bumped back (test failed / state→partly)
//   done      — completed

function kanbanComputeLabels(wp, wpIndex) {
    // Walks tasks and produces a display label for each:
    //   T<wpIndex>.<groupIndex>     for a solitary task
    //   T<wpIndex>.<groupIndex>a/b/c for a parallel group
    //
    // Returns an array parallel to wp.tasks.
    const labels = new Array(wp.tasks.length);
    let groupStart = 0;
    let groupIndex = 0;  // 1-based group counter within WP

    for (let i = 0; i <= wp.tasks.length; i++) {
        const startsNewGroup = i === wp.tasks.length
                            || i === 0
                            || !wp.tasks[i].parallel_with_prev;
        if (startsNewGroup && i > 0) {
            const size = i - groupStart;
            if (size === 1) {
                labels[groupStart] = `T${wpIndex}.${groupIndex}`;
            } else {
                for (let j = 0; j < size; j++) {
                    const letter = String.fromCharCode(97 + j);  // 'a', 'b', ...
                    labels[groupStart + j] = `T${wpIndex}.${groupIndex}${letter}`;
                }
            }
            groupStart = i;
        }
        if (i < wp.tasks.length && !wp.tasks[i].parallel_with_prev) {
            groupIndex += 1;
        }
    }
    return labels;
}

function kanbanTaskCard(task, label, userColors, isFirst) {
    const card = el('div', {
        class: 'kanban-task k-state-' + (task.state || 'ready'),
        title: (task.description || task.name || '')
             + (task.claim_expires_at ? '\n\nClaim expires: ' + fmtTime(task.claim_expires_at) : ''),
        'data-task-id': task.id,
    });

    // Right-click → context menu (delete, move, edit). Phase 3.4.
    card.addEventListener('contextmenu', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        showKanbanContextMenu(kanbanTaskMenuItems(task), ev.clientX, ev.clientY);
    });

    // Drag-and-drop. Phase 3.5.
    attachTaskDnd(card, task);

    // Background = actor color. For unpinned humans actor_color is null
    // (server set it that way); we use a neutral var.
    if (task.actor_color) {
        card.style.background = task.actor_color;
    } else {
        card.classList.add('k-actor-unpinned');
    }

    // Header row: state icon, label, parallel toggle, capability glyph
    const header = el('div', { class: 'k-task-head' });
    let stateIcon = '';
    if      (task.state === 'open')    stateIcon = '⌛';
    else if (task.state === 'done')    stateIcon = '✓';
    else if (task.state === 'partly')  stateIcon = '↺';
    else if (task.state === 'claimed') stateIcon = '●';
    // 'ready' → no icon
    if (stateIcon) header.appendChild(el('span', { class: 'k-state-icon' }, stateIcon));
    header.appendChild(el('span', { class: 'k-task-label' }, label));

    // Parallel-with-previous toggle. ASCII 'I' for standalone, 'II' for
    // running parallel with the task above — both render universally
    // without the tofu risk of the Unicode parallel-to glyph. The button
    // also has a visible border/background so it reads as a toggle.
    // First task in a WP can't be parallel; the button is fully hidden
    // in that case (via CSS .k-task-par:disabled).
    const parBtn = el('button', {
        class: 'k-task-par' + (task.parallel_with_prev ? ' k-task-par-on' : ''),
        title: isFirst
            ? 'First task in WP — cannot be parallel'
            : (task.parallel_with_prev
                ? 'Runs in parallel with task above — click to ungroup'
                : 'Click to run this task in parallel with task above'),
    }, task.parallel_with_prev ? 'II' : 'I');
    if (isFirst) {
        parBtn.disabled = true;
    } else {
        parBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            kanbanToggleParallel(task.id);
        });
    }
    header.appendChild(parBtn);

    // Edit button — opens the task editor modal (Phase 3.3).
    // Sits between the parallel toggle and the capability glyph so it's
    // always at a predictable spot on the right side of the header.
    const editBtn = el('button', {
        class: 'k-task-edit',
        title: 'Edit task details',
    }, '✎');
    editBtn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        showKanbanTaskEditor(task.id);
    });
    header.appendChild(editBtn);

    // Capability marker: H for human, A for agent — small, dim
    const cap = task.capability === 'human' ? 'H' : 'A';
    header.appendChild(el('span', { class: 'k-task-cap', title: task.capability || 'agent' }, cap));
    card.appendChild(header);

    // Task name (1-2 lines, ellipsis). Always rendered (even if empty) so
    // the user has a target for double-click rename.
    const nameDiv = el('div', { class: 'k-task-name' }, task.name || '(unnamed)');
    if (!task.name) nameDiv.classList.add('k-task-name-empty');
    makeEditable(nameDiv, task.name || '', (next) => kanbanRenameTask(task.id, next));
    card.appendChild(nameDiv);

    // Footer strip: claimer, comment count and primary action live here.
    // When the task has comments, hovering anywhere on the strip pops up
    // the full comment thread (see attachKanbanCommentsPopup).
    const foot = el('div', { class: 'k-task-foot' });

    // Claimer line (only when claimed/partly and we know who)
    if ((task.state === 'claimed' || task.state === 'partly') && task.claimed_by) {
        const claimedByMe = task.claimed_by === (window.CURRENT_USER && window.CURRENT_USER.email);
        const nm = task.claimed_by_name || task.claimed_by;
        let display, tip;
        if (claimedByMe) {
            display = 'you';
            tip     = 'Claimed by you';
        } else if (nm.indexOf('@') >= 0) {
            // Email — show first letter(s) of local part, in uppercase
            const local = nm.split('@')[0];
            display = local.charAt(0).toUpperCase() + (local.charAt(1) || '');
            tip     = 'Claimed by ' + nm;
        } else if (/^(ag_|guest_)/.test(nm)) {
            // Raw id with no friendly name (pre-migration claim)
            display = 'agent';
            tip     = 'Claimed by an agent';
        } else {
            // Friendly agent / guest name (e.g. "Berta", "Xavier")
            display = nm;
            tip     = 'Claimed by ' + nm;
        }
        foot.appendChild(el('div', { class: 'k-task-claimer', title: tip },
            '👤 ' + display));
    }

    // Comment indicator if any
    if (Array.isArray(task.comments) && task.comments.length > 0) {
        foot.appendChild(el('div', { class: 'k-task-comments' },
            '💬 ' + task.comments.length));
    }

    // Phase 4: primary-action button for the most natural next step.
    // - ready  → Claim
    // - partly → Resume (claim again, pick up where it was left)
    // - claimed BY ME → Mark done   (secondary actions in right-click menu)
    // - claimed by SOMEONE ELSE → no button (just the claimer badge)
    // - done  → no button (Reopen lives in the context menu)
    // - open  → no button (upstream task hasn't finished yet)
    const me = window.CURRENT_USER && window.CURRENT_USER.email;
    const claimedByMe = task.claimed_by === me;
    let primaryAction = null;
    if (task.state === 'ready') {
        primaryAction = { label: 'Claim', cls: '', fn: () => kanbanClaim(task.id) };
    } else if (task.state === 'partly') {
        primaryAction = { label: 'Resume', cls: '', fn: () => kanbanClaim(task.id) };
    } else if (task.state === 'claimed' && claimedByMe) {
        primaryAction = { label: 'Mark done', cls: 'k-task-done-btn',
                          fn: () => kanbanSetState(task.id, 'done') };
    }
    if (primaryAction) {
        const btn = el('button', {
            class: 'k-task-primary ' + primaryAction.cls,
            title: primaryAction.label,
        }, primaryAction.label);
        btn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            primaryAction.fn();
        });
        foot.appendChild(btn);
    }

    if (foot.childNodes.length > 0) {
        if (Array.isArray(task.comments) && task.comments.length > 0) {
            attachKanbanCommentsPopup(foot, task.comments);
        }
        card.appendChild(foot);
    }

    return card;
}

// --- Kanban comment hover popup -------------------------------------
// One shared floating box, filled on hover of a card's footer strip.
// Stays open while the pointer is over the box itself so long threads
// can be scrolled; hides shortly after the pointer leaves both.
let kanbanCommentsPopupEl = null;
let kanbanCommentsPopupOwner = null;
let kanbanCommentsPopupHideTimer = null;
let kanbanCommentsPopupShowTimer = null;
let kanbanCommentsPopupWatch = null;

function kanbanCommentsPopup() {
    if (kanbanCommentsPopupEl) return kanbanCommentsPopupEl;
    const box = el('div', { class: 'k-comments-popup' });
    box.addEventListener('mouseenter', () => clearTimeout(kanbanCommentsPopupHideTimer));
    box.addEventListener('mouseleave', () => scheduleKanbanCommentsPopupHide());
    document.body.appendChild(box);
    // Position is fixed to the viewport, so drop it when the board scrolls
    // (but not when the popup itself is scrolled) or a drag begins.
    window.addEventListener('scroll', (ev) => {
        if (ev.target !== box) hideKanbanCommentsPopup();
    }, true);
    document.addEventListener('dragstart', () => hideKanbanCommentsPopup(), true);
    kanbanCommentsPopupEl = box;
    return box;
}

function hideKanbanCommentsPopup() {
    clearTimeout(kanbanCommentsPopupShowTimer);
    clearTimeout(kanbanCommentsPopupHideTimer);
    clearInterval(kanbanCommentsPopupWatch);
    kanbanCommentsPopupOwner = null;
    if (kanbanCommentsPopupEl) kanbanCommentsPopupEl.style.display = 'none';
}

function scheduleKanbanCommentsPopupHide() {
    clearTimeout(kanbanCommentsPopupHideTimer);
    kanbanCommentsPopupHideTimer = setTimeout(hideKanbanCommentsPopup, 200);
}

function attachKanbanCommentsPopup(foot, comments) {
    foot.classList.add('k-task-foot-has-comments');
    // Empty title suppresses the card's native description tooltip (and
    // the children's) while the pointer is on the strip.
    foot.setAttribute('title', '');
    foot.querySelectorAll('[title]').forEach(n => n.removeAttribute('title'));

    foot.addEventListener('mouseenter', () => {
        clearTimeout(kanbanCommentsPopupHideTimer);
        if (kanbanCommentsPopupOwner === foot) return;
        // Short delay so sweeping the pointer across the board doesn't
        // flash popups.
        clearTimeout(kanbanCommentsPopupShowTimer);
        kanbanCommentsPopupShowTimer = setTimeout(show, 500);
    });
    function show() {
        if (!foot.isConnected) return;
        const box = kanbanCommentsPopup();
        box.replaceChildren(
            el('div', { class: 'k-comments-popup-head' },
                comments.length === 1 ? '1 comment' : comments.length + ' comments'),
            ...comments.map(c => el('div', { class: 'k-comments-popup-item' },
                el('div', { class: 'k-comments-popup-meta' },
                    (c.by_name || c.by || '(unknown)') + ' · ' + fmtTime(c.at)),
                el('div', { class: 'k-comments-popup-text' }, c.text || ''),
            )),
        );
        box.style.display = 'block';
        box.scrollTop = box.scrollHeight;   // newest comment in view

        // Place below the strip; flip above if it would run off-screen.
        const r = foot.getBoundingClientRect();
        const bw = box.offsetWidth, bh = box.offsetHeight;
        const left = Math.max(8, Math.min(r.left, window.innerWidth - bw - 8));
        let top = r.bottom + 4;
        if (top + bh > window.innerHeight - 8) top = Math.max(8, r.top - bh - 4);
        box.style.left = left + 'px';
        box.style.top  = top + 'px';

        kanbanCommentsPopupOwner = foot;
        // Board re-renders (polling, edits) can remove the strip without
        // a mouseleave — drop the popup with it.
        clearInterval(kanbanCommentsPopupWatch);
        kanbanCommentsPopupWatch = setInterval(() => {
            if (!foot.isConnected) hideKanbanCommentsPopup();
        }, 500);
    }
    foot.addEventListener('mouseleave', () => {
        clearTimeout(kanbanCommentsPopupShowTimer);
        scheduleKanbanCommentsPopupHide();
    });
    foot.addEventListener('mousedown', () => hideKanbanCommentsPopup());
}

// --- Kanban structural edits (Phase 3.1: add only) ------------------
//
// All structural changes go through POST kanban-save, which replaces the
// whole wps array. We send expected_version for optimistic concurrency;
// on 409 we re-fetch and tell the user the board changed under them
// (their pending add is discarded — acceptable for v1).
//
// Stable IDs (`wp_NNN`, `t_NNN`) are allocated client-side from the
// current next_wp_id / next_task_id, then the incremented values are
// sent back in the save so the server keeps them in sync.

async function kanbanSaveBoard(wps, nextWpId, nextTaskId) {
    const body = {
        owner: state.projectOwner,
        project: state.project,
        wps: wps,
        expected_version: state.kanbanVersion,
    };
    if (nextWpId   !== undefined) body.next_wp_id   = nextWpId;
    if (nextTaskId !== undefined) body.next_task_id = nextTaskId;
    try {
        const r = await adminPost('kanban-save', body);
        state.kanbanVersion = r.kanban_version;
        // Server returns the saved wps (which may have been normalized).
        // Silent refresh — keep the pane in place, just swap the board
        // grid offscreen so the user doesn't see a blank flash.
        await kanbanRefreshSilently();
        return true;
    } catch (e) {
        if (/version_conflict|kanban_version_conflict/i.test(e.message)) {
            setError('Board changed elsewhere — reloading.');
            await kanbanRefreshSilently();
        } else {
            setError(e.message);
        }
        return false;
    }
}

// --- Phase 4: claim / release / state -----------------------------------
//
// All three endpoints (kanban-claim, kanban-release, kanban-state) already
// live on the server. They each take expected_version for optimistic
// concurrency and refuse the call on mismatch. We refresh the board
// silently after every successful call so the UI stays in sync.
//
// kanbanClaim:  ready|partly → claimed by current user
// kanbanRelease: claimed by me → ready  (admin/owner may force-release others)
// kanbanSetState: claimed → done|partly,  partly → claimed,  done → partly

async function kanbanClaim(taskId) {
    try {
        const r = await adminPost('kanban-claim', {
            owner: state.projectOwner,
            project: state.project,
            task_id: taskId,
            expected_version: state.kanbanVersion,
        });
        state.kanbanVersion = r.kanban_version;
        await kanbanRefreshSilently();
    } catch (e) {
        if (/already_claimed/i.test(e.message)) {
            // Server returns who's holding it — show that to the user
            // rather than a raw error.
            setError(e.message);
            await kanbanRefreshSilently();
        } else if (/version_conflict|kanban_version_conflict/i.test(e.message)) {
            setError('Board changed elsewhere — reloading.');
            await kanbanRefreshSilently();
        } else {
            setError(e.message);
        }
    }
}

async function kanbanRelease(taskId) {
    try {
        const r = await adminPost('kanban-release', {
            owner: state.projectOwner,
            project: state.project,
            task_id: taskId,
            expected_version: state.kanbanVersion,
        });
        state.kanbanVersion = r.kanban_version;
        await kanbanRefreshSilently();
    } catch (e) {
        if (/version_conflict|kanban_version_conflict/i.test(e.message)) {
            setError('Board changed elsewhere — reloading.');
            await kanbanRefreshSilently();
        } else {
            setError(e.message);
        }
    }
}

async function kanbanSetState(taskId, newState, comment) {
    // newState: 'done' | 'partly' | 'claimed'
    // For 'partly' it's friendly to capture a quick note about what's
    // half-done; we prompt for it inline. Other transitions don't need
    // a comment.
    let commentText = comment;
    if (newState === 'partly' && commentText === undefined) {
        commentText = prompt('Optional note for the partial completion (what'
                           + ' still needs to be done?):', '');
        if (commentText === null) return;   // user cancelled the prompt
        commentText = commentText.trim();
    }
    const body = {
        owner: state.projectOwner,
        project: state.project,
        task_id: taskId,
        new_state: newState,
        expected_version: state.kanbanVersion,
    };
    if (commentText) body.comment = commentText;
    try {
        const r = await adminPost('kanban-state', body);
        state.kanbanVersion = r.kanban_version;
        await kanbanRefreshSilently();
    } catch (e) {
        if (/version_conflict|kanban_version_conflict/i.test(e.message)) {
            setError('Board changed elsewhere — reloading.');
            await kanbanRefreshSilently();
        } else {
            setError(e.message);
        }
    }
}

function makeTaskDefault(taskId) {
    return {
        id: 't_' + String(taskId).padStart(3, '0'),
        name: 'New task',
        description: '',
        actor_email: null,
        capability: 'agent',
        state: 'ready',
        claimed_by: null,
        claimed_at: null,
        completed_by: null,
        completed_at: null,
        parallel_with_prev: false,
        comments: [],
    };
}

async function kanbanAddTask(wpId) {
    if (!state.kanbanData) return;
    // Deep-clone to avoid mutating state before the server confirms.
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const wp = wps.find(w => w.id === wpId);
    if (!wp) { setError('WP not found: ' + wpId); return; }
    const nextTaskId = state.kanbanData.next_task_id || 1;
    wp.tasks.push(makeTaskDefault(nextTaskId));
    await kanbanSaveBoard(wps, undefined, nextTaskId + 1);
}

async function kanbanAddWp() {
    if (!state.kanbanData) return;
    const name = prompt('New work package name:', 'New WP');
    if (name === null) return;   // user cancelled
    const trimmed = name.trim() || 'New WP';
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const nextWpId = state.kanbanData.next_wp_id || 1;
    wps.push({
        id: 'wp_' + String(nextWpId).padStart(3, '0'),
        name: trimmed,
        tasks: [],
    });
    await kanbanSaveBoard(wps, nextWpId + 1, undefined);
}

// --- Phase 3.2: inline rename --------------------------------------------
//
// makeEditable(node, currentValue, onSave) turns a text-bearing element
// into an inline editor on double-click. Enter or blur commits, Escape
// cancels. While editing we set mode='editing' so the 5s poll cycle
// doesn't re-render the board out from under the user.

function makeEditable(node, currentValue, onSave) {
    node.title = (node.title ? node.title + ' · ' : '') + 'Double-click to rename';
    node.style.cursor = 'text';
    node.addEventListener('dblclick', (ev) => {
        ev.stopPropagation();
        // Already editing? skip.
        if (node.querySelector('input')) return;

        setMode('editing');
        node.classList.add('editing');
        const original = currentValue;
        const input = el('input', {
            type: 'text',
            class: 'kanban-rename-input',
        });
        input.value = original;
        node.textContent = '';
        node.appendChild(input);
        input.focus();
        input.select();

        let done = false;
        const finish = async (commit) => {
            if (done) return;
            done = true;
            const next = input.value.trim();
            // Restore the original text first (the board re-render on
            // success will replace it anyway).
            node.classList.remove('editing');
            node.textContent = original;
            setMode('browse');
            if (commit && next && next !== original) {
                await onSave(next);
            }
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter')      { e.preventDefault(); finish(true); }
            else if (e.key === 'Escape'){ e.preventDefault(); finish(false); }
        });
        input.addEventListener('blur', () => finish(true));
    });
}

async function kanbanRenameWp(wpId, newName) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const wp = wps.find(w => w.id === wpId);
    if (!wp) { setError('WP not found: ' + wpId); return; }
    wp.name = newName;
    await kanbanSaveBoard(wps);
}

async function kanbanRenameTask(taskId, newName) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    let found = null;
    for (const wp of wps) {
        const t = wp.tasks.find(t => t.id === taskId);
        if (t) { t.name = newName; found = t; break; }
    }
    if (!found) { setError('Task not found: ' + taskId); return; }
    await kanbanSaveBoard(wps);
}

// Toggle parallel_with_prev on a task. The first task in a WP can never
// be parallel (there is no "previous" to be parallel with) — the UI
// disables the button in that case.
async function kanbanToggleParallel(taskId) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    let target = null, targetIdx = -1;
    for (const wp of wps) {
        const idx = wp.tasks.findIndex(t => t.id === taskId);
        if (idx >= 0) { target = wp.tasks[idx]; targetIdx = idx; break; }
    }
    if (!target) { setError('Task not found: ' + taskId); return; }
    if (targetIdx === 0) {
        setError('First task in a WP cannot be parallel');
        return;
    }
    target.parallel_with_prev = !target.parallel_with_prev;
    await kanbanSaveBoard(wps);
}

// --- Phase 3.4: delete, move, context menu ------------------------------
//
// Right-click on a task card or WP header opens a context menu with
// destructive/structural actions. Long-press on touch will be added in a
// future pass — for v1, right-click is the only trigger.
//
// Schema invariant: the first task of every WP must have
// parallel_with_prev: false (a chain of parallels needs an anchor). After
// any structural mutation that could move a task into position 0, we run
// normalizeParallelFlags to enforce it.

function normalizeParallelFlags(wps) {
    for (const wp of wps) {
        for (let i = 0; i < (wp.tasks || []).length; i++) {
            if (i === 0 && wp.tasks[i].parallel_with_prev) {
                wp.tasks[i].parallel_with_prev = false;
            }
        }
    }
}

async function kanbanDeleteTask(taskId) {
    if (!state.kanbanData) return;
    const found = findTaskById(taskId, state.kanbanData.wps || []);
    if (!found) { setError('Task not found: ' + taskId); return; }
    const label = found.task.name ? `"${found.task.name}"` : 'this task';
    if (!confirm(`Delete ${label}?`)) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    for (const wp of wps) {
        const idx = wp.tasks.findIndex(t => t.id === taskId);
        if (idx >= 0) {
            // Parallel-flag inheritance rule: the task that follows the
            // deleted one keeps its parallel_with_prev only if the
            // deleted task was itself parallel. Otherwise — i.e. we just
            // removed an anchor — the survivor becomes the new anchor
            // and its flag is cleared. A task that was already standalone
            // before stays standalone regardless.
            const deletedWasParallel = !!wp.tasks[idx].parallel_with_prev;
            wp.tasks.splice(idx, 1);
            if (!deletedWasParallel && idx < wp.tasks.length) {
                wp.tasks[idx].parallel_with_prev = false;
            }
            break;
        }
    }
    normalizeParallelFlags(wps);
    await kanbanSaveBoard(wps);
}

async function kanbanDeleteWp(wpId) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const idx = wps.findIndex(w => w.id === wpId);
    if (idx < 0) { setError('WP not found: ' + wpId); return; }
    const wp = wps[idx];
    if ((wp.tasks || []).length > 0) {
        // Belt-and-suspenders — the menu item is already disabled in this
        // case, but a stale board state could let it through.
        setError('Cannot delete WP that still contains tasks');
        return;
    }
    if (!confirm(`Delete work package "${wp.name || '(unnamed)'}"?`)) return;
    wps.splice(idx, 1);
    await kanbanSaveBoard(wps);
}

async function kanbanMoveTaskToWp(taskId, targetWpId) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    let moved = null;
    for (const wp of wps) {
        const idx = wp.tasks.findIndex(t => t.id === taskId);
        if (idx >= 0) {
            // Same inheritance rule as delete: if the task being moved
            // out was NOT itself parallel (i.e. it was an anchor), the
            // task that takes its place must lose its parallel flag too,
            // since it has nothing in front of it to be parallel with.
            const movedWasParallel = !!wp.tasks[idx].parallel_with_prev;
            moved = wp.tasks.splice(idx, 1)[0];
            if (!movedWasParallel && idx < wp.tasks.length) {
                wp.tasks[idx].parallel_with_prev = false;
            }
            break;
        }
    }
    if (!moved) { setError('Task not found: ' + taskId); return; }
    const target = wps.find(w => w.id === targetWpId);
    if (!target) { setError('Target WP not found: ' + targetWpId); return; }
    // Append to end of target. The moved task loses its parallel grouping
    // (it's in a new context); normalizeParallelFlags also catches the
    // position-0 case.
    moved.parallel_with_prev = false;
    target.tasks.push(moved);
    normalizeParallelFlags(wps);
    await kanbanSaveBoard(wps);
}

// --- Context menu (shared across task cards and WP headers) ----------
//
// showKanbanContextMenu(items, x, y) builds a popup at viewport coords
// (x,y). items is [{label, disabled?, danger?, submenu?, onClick?}]. A
// submenu is itself an array of items, expanded on hover. Click outside
// or Escape dismisses; selecting a non-submenu item dismisses then runs
// its onClick.

let kanbanCtxMenuEl = null;
function dismissKanbanCtxMenu() {
    if (kanbanCtxMenuEl) {
        kanbanCtxMenuEl.remove();
        kanbanCtxMenuEl = null;
    }
    document.removeEventListener('mousedown', onKanbanCtxDocClick, true);
    document.removeEventListener('contextmenu', onKanbanCtxDocClick, true);
    document.removeEventListener('keydown',   onKanbanCtxKey,       true);
}
function onKanbanCtxDocClick(e) {
    // Clicks/right-clicks inside the menu (including submenus) keep the
    // menu open — the item's own handler does the dismiss + action.
    if (kanbanCtxMenuEl && kanbanCtxMenuEl.contains(e.target)) return;
    dismissKanbanCtxMenu();
}
function onKanbanCtxKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); dismissKanbanCtxMenu(); }
}

function buildKanbanCtxItem(it, parentMenu) {
    if (it.separator) {
        return el('div', { class: 'k-ctx-sep' });
    }
    const row = el('div', {
        class: 'k-ctx-item'
            + (it.disabled ? ' k-ctx-disabled' : '')
            + (it.danger   ? ' k-ctx-danger'   : '')
            + (it.submenu  ? ' k-ctx-has-sub'  : ''),
    }, it.label + (it.submenu ? '  ›' : ''));

    if (it.submenu && !it.disabled) {
        const openSub = () => {
            // Close any sibling submenu first
            parentMenu.querySelectorAll(':scope > .k-ctx-item .k-ctx-submenu').forEach(s => s.remove());
            const subEl = el('div', { class: 'k-ctx-menu k-ctx-submenu' });
            it.submenu.forEach(sub => subEl.appendChild(buildKanbanCtxItem(sub, subEl)));
            row.appendChild(subEl);
            // Position to the right of the parent item.
            subEl.style.left = '100%';
            subEl.style.top  = '0';
        };
        row.addEventListener('mouseenter', openSub);
    } else if (!it.disabled && it.onClick) {
        row.addEventListener('click', (ev) => {
            ev.stopPropagation();
            // Dismiss first, then run — so any UI the action opens
            // (modal, prompt) isn't auto-dismissed by our outer click handler.
            dismissKanbanCtxMenu();
            it.onClick();
        });
        // Close any open submenu when hovering a non-submenu sibling, so
        // the menu doesn't get visually cluttered.
        row.addEventListener('mouseenter', () => {
            parentMenu.querySelectorAll(':scope > .k-ctx-item .k-ctx-submenu').forEach(s => s.remove());
        });
    } else if (it.disabled) {
        // Disabled item — also clear submenus on hover for consistency.
        row.addEventListener('mouseenter', () => {
            parentMenu.querySelectorAll(':scope > .k-ctx-item .k-ctx-submenu').forEach(s => s.remove());
        });
    }
    return row;
}

function showKanbanContextMenu(items, x, y) {
    dismissKanbanCtxMenu();
    const menu = el('div', { class: 'k-ctx-menu' });
    // Suppress the browser's default context menu inside our menu.
    menu.addEventListener('contextmenu', (e) => e.preventDefault());
    items.forEach(it => menu.appendChild(buildKanbanCtxItem(it, menu)));
    // Position. Cap to viewport so right-clicking near the edge doesn't
    // push the menu off-screen — measure after appending.
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
    document.body.appendChild(menu);
    const rect = menu.getBoundingClientRect();
    if (rect.right > window.innerWidth - 4) {
        menu.style.left = Math.max(4, window.innerWidth - rect.width - 4) + 'px';
    }
    if (rect.bottom > window.innerHeight - 4) {
        menu.style.top = Math.max(4, window.innerHeight - rect.height - 4) + 'px';
    }
    kanbanCtxMenuEl = menu;
    // useCapture:true so we see the click before any handler that might
    // stopPropagation. We check inside the handler whether the target is
    // inside the menu — only outside clicks dismiss.
    // Using mousedown (not click) so the menu disappears as soon as the
    // user starts an outside click, which feels snappier.
    document.addEventListener('mousedown',   onKanbanCtxDocClick, true);
    document.addEventListener('contextmenu', onKanbanCtxDocClick, true);
    document.addEventListener('keydown',     onKanbanCtxKey,      true);
}

// Build the menu for a task. Excludes the task's current WP from "Move to".
function kanbanTaskMenuItems(task) {
    const wps = (state.kanbanData?.wps || []);
    const currentWp = wps.find(w => w.tasks.some(t => t.id === task.id));
    const otherWps = wps.filter(w => w !== currentWp);
    const taskIdx = currentWp ? currentWp.tasks.indexOf(task) : -1;
    const isFirst = taskIdx === 0;

    const moveSubmenu = otherWps.length > 0
        ? otherWps.map(w => ({
              label: 'WP' + (wps.indexOf(w) + 1) + ' — ' + (w.name || '(unnamed)'),
              onClick: () => kanbanMoveTaskToWp(task.id, w.id),
          }))
        : [{ label: '(no other WPs)', disabled: true }];

    const parallelLabel = isFirst
        ? 'Group with previous (first task — N/A)'
        : (task.parallel_with_prev
            ? 'Ungroup from previous'
            : 'Group with previous');

    // Phase 4: claim/release/state actions, contextually enabled per the
    // state machine (ready→claim, claimed-by-me→release/done/partly,
    // partly→claim, done→reopen). Project owner / global admin can
    // force-release anyone's claim.
    const me           = window.CURRENT_USER && window.CURRENT_USER.email;
    const claimedByMe  = task.claimed_by === me;
    const claimedByOther = !!task.claimed_by && !claimedByMe;
    const isOwnerOrAdmin = state.projectData?.role_in_project === 'owner'
                        || (window.CURRENT_USER && window.CURRENT_USER.role === 'admin');

    const workflow = [];
    if (task.state === 'ready') {
        workflow.push({ label: 'Claim',
                        onClick: () => kanbanClaim(task.id) });
    } else if (task.state === 'partly') {
        workflow.push({ label: 'Resume (claim)',
                        onClick: () => kanbanClaim(task.id) });
    } else if (task.state === 'claimed' && claimedByMe) {
        workflow.push({ label: 'Mark done',
                        onClick: () => kanbanSetState(task.id, 'done') });
        workflow.push({ label: 'Mark partly done…',
                        onClick: () => kanbanSetState(task.id, 'partly') });
        workflow.push({ label: 'Release claim',
                        onClick: () => kanbanRelease(task.id) });
    } else if (task.state === 'claimed' && claimedByOther) {
        if (isOwnerOrAdmin) {
            workflow.push({ label: 'Force release (admin)',
                            danger: true,
                            onClick: () => kanbanRelease(task.id) });
        } else {
            workflow.push({ label: 'Claimed by ' + (task.claimed_by_name || task.claimed_by || 'someone'),
                            disabled: true });
        }
    } else if (task.state === 'done') {
        workflow.push({ label: 'Reopen (mark partly)',
                        onClick: () => kanbanSetState(task.id, 'partly') });
    } else if (task.state === 'open') {
        workflow.push({ label: 'Waiting on upstream task',
                        disabled: true });
    }

    const items = [];
    workflow.forEach(w => items.push(w));
    if (workflow.length > 0) items.push({ separator: true });
    items.push({ label: 'Edit…',         onClick: () => showKanbanTaskEditor(task.id) });
    items.push({ label: parallelLabel,
                 disabled: isFirst,
                 onClick: () => kanbanToggleParallel(task.id) });
    items.push({ label: 'Move to WP',    submenu: moveSubmenu, disabled: otherWps.length === 0 });
    items.push({ separator: true });
    items.push({ label: 'Delete task',   danger: true, onClick: () => kanbanDeleteTask(task.id) });
    return items;
}

function kanbanWpMenuItems(wp) {
    const empty = (wp.tasks || []).length === 0;
    return [
        { label: 'Rename WP',      onClick: () => {
            // Trigger the existing inline-rename via the name span.
            const node = document.querySelector(`.kanban-wp[data-wp-id="${wp.id}"] .kanban-wp-name`);
            if (node) {
                const ev = new MouseEvent('dblclick', { bubbles: true });
                node.dispatchEvent(ev);
            }
        }},
        { label: 'Add task',       onClick: () => kanbanAddTask(wp.id) },
        { separator: true },
        { label: empty ? 'Delete WP' : 'Delete WP (must be empty)',
          danger: true,
          disabled: !empty,
          onClick: () => kanbanDeleteWp(wp.id) },
    ];
}

// --- Phase 3.5: drag-and-drop reordering --------------------------------
//
// Native HTML5 DnD. Two scenarios:
// 1. Task drag: drop on another task to insert before/after, or onto an
//    empty WP column area to append.
// 2. WP drag: drop a WP header onto another WP column to reorder
//    horizontally.
//
// Group semantics: when a task is the START of a parallel group (i.e.
// the next task has parallel_with_prev:true), dragging it brings the
// whole contiguous parallel run along. Tasks that are themselves
// parallel_with_prev:true (middle/end of a group) drag alone — they
// effectively peel off the group.
//
// After every move, normalizeParallelFlags clears any task that ended up
// at index 0 of its WP but still had parallel_with_prev set, since that
// would be a schema violation.

// state.kanbanDrag = { type: 'task' | 'wp', id: '...' }

// Find the contiguous "drag unit" for a task: itself + any immediately
// following tasks with parallel_with_prev=true (the rest of the group it
// anchors). Returns the indices [start, endExclusive) into wp.tasks.
function kanbanDragUnit(wp, taskIdx) {
    if (taskIdx < 0 || taskIdx >= wp.tasks.length) return [taskIdx, taskIdx];
    const startsGroup = (taskIdx + 1 < wp.tasks.length)
        && wp.tasks[taskIdx + 1].parallel_with_prev;
    if (!startsGroup) return [taskIdx, taskIdx + 1];   // just this one task
    let j = taskIdx + 1;
    while (j < wp.tasks.length && wp.tasks[j].parallel_with_prev) j++;
    return [taskIdx, j];
}

async function kanbanMoveTaskUnit(srcTaskId, dstWpId, dstIndex /* before-this-index in target */) {
    if (!state.kanbanData) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));

    // Locate the source unit
    let srcWp = null, srcStart = -1, srcEnd = -1;
    for (const w of wps) {
        const i = w.tasks.findIndex(t => t.id === srcTaskId);
        if (i >= 0) {
            srcWp = w;
            const [a, b] = kanbanDragUnit(w, i);
            srcStart = a; srcEnd = b;
            break;
        }
    }
    if (!srcWp) { setError('Task not found: ' + srcTaskId); return; }

    const dstWp = wps.find(w => w.id === dstWpId);
    if (!dstWp) { setError('Target WP not found: ' + dstWpId); return; }

    // Same inheritance rule as delete: if the moved unit's head was NOT
    // parallel (i.e. we just yanked out an anchor), the next task in
    // source becomes the new anchor and must lose parallel_with_prev.
    const removedHeadWasParallel = !!srcWp.tasks[srcStart].parallel_with_prev;

    // Slice the unit out of source.
    const unit = srcWp.tasks.splice(srcStart, srcEnd - srcStart);
    if (!removedHeadWasParallel && srcStart < srcWp.tasks.length) {
        srcWp.tasks[srcStart].parallel_with_prev = false;
    }

    // If dropping into the same WP after removing the source unit, the
    // destination index may have shifted. Adjust.
    let insertAt = dstIndex;
    if (srcWp === dstWp && dstIndex > srcStart) {
        insertAt = dstIndex - unit.length;
    }
    // Clamp.
    if (insertAt < 0) insertAt = 0;
    if (insertAt > dstWp.tasks.length) insertAt = dstWp.tasks.length;

    // The first task of the moved unit always becomes the new unit
    // anchor — it loses any old parallel_with_prev association to the
    // tasks it was running alongside before the move (those stayed
    // behind). Tasks 2..N of the unit keep their internal flags so the
    // group itself moves as a coherent block.
    unit[0].parallel_with_prev = false;
    dstWp.tasks.splice(insertAt, 0, ...unit);

    normalizeParallelFlags(wps);
    await kanbanSaveBoard(wps);
}

async function kanbanMoveWp(srcWpId, beforeWpId) {
    // beforeWpId === null → move to end.
    if (!state.kanbanData) return;
    if (srcWpId === beforeWpId) return;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const srcIdx = wps.findIndex(w => w.id === srcWpId);
    if (srcIdx < 0) { setError('WP not found: ' + srcWpId); return; }
    const [moving] = wps.splice(srcIdx, 1);

    let insertAt;
    if (beforeWpId === null) {
        insertAt = wps.length;
    } else {
        insertAt = wps.findIndex(w => w.id === beforeWpId);
        if (insertAt < 0) insertAt = wps.length;
    }
    wps.splice(insertAt, 0, moving);
    await kanbanSaveBoard(wps);
}

// Compute drop position for a task drag inside a WP task list, based on
// mouse Y. Returns the index BEFORE which the dragged unit should land.
// Walks all .kanban-task children of the list (including those inside
// parallel-group wrappers).
function kanbanComputeTaskDropIndex(listEl, clientY) {
    const cards = listEl.querySelectorAll('.kanban-task');
    let idx = 0;
    for (const card of cards) {
        const rect = card.getBoundingClientRect();
        const mid  = rect.top + rect.height / 2;
        if (clientY < mid) return idx;
        idx++;
    }
    return idx;   // after the last card
}

// Show a thin drop indicator line between cards. We use a single shared
// element appended to the body, repositioned on each dragover.
let kanbanDropIndicator = null;
function ensureDropIndicator() {
    if (!kanbanDropIndicator) {
        kanbanDropIndicator = el('div', { class: 'kanban-drop-indicator' });
        kanbanDropIndicator.style.display = 'none';
        document.body.appendChild(kanbanDropIndicator);
    }
    return kanbanDropIndicator;
}
function positionTaskDropIndicator(listEl, dropIndex) {
    const ind = ensureDropIndicator();
    const cards = listEl.querySelectorAll('.kanban-task');
    const listRect = listEl.getBoundingClientRect();
    let top;
    if (cards.length === 0) {
        top = listRect.top + 4;
    } else if (dropIndex >= cards.length) {
        const last = cards[cards.length - 1].getBoundingClientRect();
        top = last.bottom;
    } else {
        const c = cards[dropIndex].getBoundingClientRect();
        top = c.top;
    }
    ind.classList.remove('kanban-drop-indicator-vertical');
    ind.classList.add('kanban-drop-indicator-horizontal');
    ind.style.left  = (listRect.left  - 2) + 'px';
    ind.style.top   = (top - 2) + 'px';
    ind.style.width = (listRect.width + 4) + 'px';
    ind.style.height = '3px';
    ind.style.display = 'block';
}
function positionWpDropIndicator(targetCol, beforeOrAfter) {
    // beforeOrAfter: 'before' → left edge of targetCol; 'after' → right edge.
    const ind = ensureDropIndicator();
    const rect = targetCol.getBoundingClientRect();
    const x = beforeOrAfter === 'before' ? rect.left : rect.right;
    ind.classList.remove('kanban-drop-indicator-horizontal');
    ind.classList.add('kanban-drop-indicator-vertical');
    ind.style.left  = (x - 2) + 'px';
    ind.style.top   = rect.top + 'px';
    ind.style.width = '3px';
    ind.style.height = rect.height + 'px';
    ind.style.display = 'block';
}
function hideDropIndicator() {
    if (kanbanDropIndicator) kanbanDropIndicator.style.display = 'none';
}

// Wire task-card DnD. Called from kanbanTaskCard.
function attachTaskDnd(card, task) {
    card.draggable = true;
    card.addEventListener('dragstart', (ev) => {
        // Don't hijack drags that start on an interactive child — the
        // inline rename input is the main concern (text selection inside
        // it triggers dragstart on the card otherwise).
        const t = ev.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
            ev.preventDefault();
            return;
        }
        state.kanbanDrag = { type: 'task', id: task.id };
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', task.id);
        card.classList.add('k-dragging');
        // If this card anchors a parallel group, dim the rest of the
        // group too so the visual matches the move-as-unit behaviour.
        const wps = state.kanbanData?.wps || [];
        const myWp = wps.find(w => w.tasks.some(x => x.id === task.id));
        if (myWp) {
            const idx = myWp.tasks.findIndex(x => x.id === task.id);
            const [a, b] = kanbanDragUnit(myWp, idx);
            for (let k = a + 1; k < b; k++) {
                const sib = document.querySelector(
                    `.kanban-task[data-task-id="${myWp.tasks[k].id}"]`);
                if (sib) sib.classList.add('k-dragging');
            }
        }
    });
    card.addEventListener('dragend', () => {
        // Clear .k-dragging from every card — covers the whole unit.
        document.querySelectorAll('.kanban-task.k-dragging').forEach(c =>
            c.classList.remove('k-dragging'));
        hideDropIndicator();
        state.kanbanDrag = null;
        document.querySelectorAll('.kanban-wp.k-dropzone').forEach(n =>
            n.classList.remove('k-dropzone'));
    });
}

// Wire the task list (.kanban-wp-tasks) as a drop zone for task drags.
function attachTaskListDnd(listEl, wp) {
    listEl.addEventListener('dragover', (ev) => {
        if (!state.kanbanDrag || state.kanbanDrag.type !== 'task') return;
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        const dropIdx = kanbanComputeTaskDropIndex(listEl, ev.clientY);
        positionTaskDropIndicator(listEl, dropIdx);
        listEl.closest('.kanban-wp')?.classList.add('k-dropzone');
    });
    listEl.addEventListener('dragleave', (ev) => {
        // dragleave fires when entering a child — only react when truly
        // leaving the list. Check relatedTarget.
        if (!listEl.contains(ev.relatedTarget)) {
            listEl.closest('.kanban-wp')?.classList.remove('k-dropzone');
            hideDropIndicator();
        }
    });
    listEl.addEventListener('drop', (ev) => {
        if (!state.kanbanDrag || state.kanbanDrag.type !== 'task') return;
        ev.preventDefault();
        const dropIdx = kanbanComputeTaskDropIndex(listEl, ev.clientY);
        const srcId = state.kanbanDrag.id;
        const dstWpId = wp.id;
        listEl.closest('.kanban-wp')?.classList.remove('k-dropzone');
        hideDropIndicator();
        state.kanbanDrag = null;
        kanbanMoveTaskUnit(srcId, dstWpId, dropIdx);
    });
}

// Wire WP header DnD: drag a WP header to reorder columns.
function attachWpHeadDnd(head, wp) {
    head.draggable = true;
    head.addEventListener('dragstart', (ev) => {
        // Block when the drag starts on an inline rename input.
        const t = ev.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
            ev.preventDefault();
            return;
        }
        state.kanbanDrag = { type: 'wp', id: wp.id };
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', wp.id);
        head.closest('.kanban-wp')?.classList.add('k-dragging');
        ev.stopPropagation();
    });
    head.addEventListener('dragend', () => {
        head.closest('.kanban-wp')?.classList.remove('k-dragging');
        hideDropIndicator();
        state.kanbanDrag = null;
        document.querySelectorAll('.kanban-wp.k-dropzone').forEach(n =>
            n.classList.remove('k-dropzone'));
    });
}

// Wire whole WP column as a drop target for WP drags.
function attachWpColumnDnd(col, wp) {
    col.addEventListener('dragover', (ev) => {
        if (!state.kanbanDrag || state.kanbanDrag.type !== 'wp') return;
        if (state.kanbanDrag.id === wp.id) return;
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'move';
        const rect = col.getBoundingClientRect();
        const before = ev.clientX < rect.left + rect.width / 2;
        positionWpDropIndicator(col, before ? 'before' : 'after');
    });
    col.addEventListener('drop', (ev) => {
        if (!state.kanbanDrag || state.kanbanDrag.type !== 'wp') return;
        if (state.kanbanDrag.id === wp.id) return;   // dropped on self → no-op
        ev.preventDefault();
        const rect = col.getBoundingClientRect();
        const before = ev.clientX < rect.left + rect.width / 2;
        const srcId = state.kanbanDrag.id;
        const dstId = wp.id;
        hideDropIndicator();
        state.kanbanDrag = null;
        // 'before this WP' → insert at this WP's index; 'after this WP' →
        // insert before the next WP (or at end if last).
        const wps = state.kanbanData?.wps || [];
        const dstIdx = wps.findIndex(w => w.id === dstId);
        if (dstIdx < 0) return;
        let beforeWpId;
        if (before) {
            beforeWpId = dstId;
        } else {
            beforeWpId = (dstIdx + 1 < wps.length) ? wps[dstIdx + 1].id : null;
        }
        kanbanMoveWp(srcId, beforeWpId);
    });
}

function kanbanWpColumn(wp, wpIndex, userColors) {
    const col = el('div', { class: 'kanban-wp', 'data-wp-id': wp.id });
    const nameSpan = el('span', { class: 'kanban-wp-name' }, wp.name || '(unnamed)');
    makeEditable(nameSpan, wp.name || '', (next) => kanbanRenameWp(wp.id, next));
    const head = el('div', { class: 'kanban-wp-head' },
        el('span', { class: 'kanban-wp-index' }, 'WP' + wpIndex),
        nameSpan,
    );
    head.addEventListener('contextmenu', (ev) => {
        ev.preventDefault();
        showKanbanContextMenu(kanbanWpMenuItems(wp), ev.clientX, ev.clientY);
    });
    // Phase 3.5: make WP header draggable for column reordering.
    attachWpHeadDnd(head, wp);
    col.appendChild(head);

    const list = el('div', { class: 'kanban-wp-tasks' });
    // Phase 3.5: task list is a drop zone for task drags.
    attachTaskListDnd(list, wp);
    const labels = kanbanComputeLabels(wp, wpIndex);

    // Walk tasks, wrapping consecutive parallel_with_prev runs in a group
    // container for tighter visual spacing + dashed border.
    let i = 0;
    while (i < wp.tasks.length) {
        // Find end of group (the first task is the start of a group)
        let j = i + 1;
        while (j < wp.tasks.length && wp.tasks[j].parallel_with_prev) j++;
        const size = j - i;
        if (size === 1) {
            list.appendChild(kanbanTaskCard(wp.tasks[i], labels[i], userColors, i === 0));
        } else {
            const group = el('div', { class: 'kanban-task-group' });
            for (let k = i; k < j; k++) {
                group.appendChild(kanbanTaskCard(wp.tasks[k], labels[k], userColors, k === 0));
            }
            list.appendChild(group);
        }
        i = j;
    }

    if (wp.tasks.length === 0) {
        list.appendChild(el('div', { class: 'kanban-wp-empty muted' }, '(no tasks)'));
    }

    // [+ Add task] button — bottom of every WP column.
    list.appendChild(el('button', {
        class: 'kanban-add-task',
        title: 'Add a new task to this WP',
        on: { click: () => kanbanAddTask(wp.id) },
    }, '+ Add task'));

    col.appendChild(list);

    // Phase 3.5: the whole column is a drop target for WP-header drags.
    attachWpColumnDnd(col, wp);

    return col;
}

// --- Phase 3.3: task editor modal ---------------------------------------
//
// Click the ✎ button on a task card → showKanbanTaskEditor(taskId) opens a
// modal with the multi-field editor: name, description, capability A/H,
// pinned actor (humans only), and a read-only comments list. Save bundles
// all changed fields into a single POST kanban-save via
// kanbanSaveTaskFields → kanbanSaveBoard (which handles 409 the same as
// the other structural edits).
//
// Cancel discards changes. Esc and clicking the overlay backdrop also
// dismiss. setMode('editing') blocks the 5s poll cycle while the modal
// is open.

function findTaskById(taskId, wps) {
    for (const wp of (wps || [])) {
        const t = (wp.tasks || []).find(t => t.id === taskId);
        if (t) return { task: t, wp: wp };
    }
    return null;
}

// Build the actor-pinning dropdown. For 'human' tasks: owner + shared_with
// members. For 'agent' tasks: hidden — agents always share azzurro, no
// pinning concept yet (kanban-plan.md §2.1).
function buildActorOptions(capability, currentActorEmail) {
    const opts = [el('option', { value: '' }, capability === 'human'
        ? '— any human —'
        : '— any agent —')];
    if (capability === 'human') {
        const candidates = new Set();
        if (state.projectOwner) candidates.add(state.projectOwner);
        for (const e of (state.projectData?.shared_with || [])) candidates.add(e);
        // Preserve a stale pin even if the user is no longer a member —
        // kanban-plan.md §10 q5 keeps the pin until manually cleared.
        if (currentActorEmail) candidates.add(currentActorEmail);
        Array.from(candidates).sort().forEach(email => {
            opts.push(el('option', { value: email }, email));
        });
    }
    return opts;
}

async function kanbanSaveTaskFields(taskId, fields) {
    if (!state.kanbanData) return false;
    const wps = JSON.parse(JSON.stringify(state.kanbanData.wps || []));
    const found = findTaskById(taskId, wps);
    if (!found) { setError('Task not found: ' + taskId); return false; }
    Object.assign(found.task, fields);
    return await kanbanSaveBoard(wps);
}

function showKanbanTaskEditor(taskId) {
    if (!state.kanbanData) return;
    const found = findTaskById(taskId, state.kanbanData.wps || []);
    if (!found) { setError('Task not found: ' + taskId); return; }
    const task = found.task;

    setMode('editing');

    // Display label — compute it the same way the card does so the header
    // reads e.g. "Edit task T1.2a".
    const wpIndex = (state.kanbanData.wps || []).indexOf(found.wp) + 1;
    const labels  = kanbanComputeLabels(found.wp, wpIndex);
    const taskIdx = found.wp.tasks.indexOf(task);
    const label   = labels[taskIdx] || '';

    const overlay = el('div', {
        class: 'modal-overlay',
        on: { click: (e) => { if (e.target === overlay) closeEditor(false); }},
    });

    const nameInput = el('input', { type: 'text', id: 'kt-name', placeholder: 'Task name' });
    nameInput.value = task.name || '';

    const descInput = el('textarea', { id: 'kt-desc', rows: 5, placeholder: 'Full task brief…' });
    descInput.value = task.description || '';

    const capSelect = el('select', { id: 'kt-cap' },
        el('option', { value: 'agent' }, 'A — Agent'),
        el('option', { value: 'human' }, 'H — Human'),
    );
    capSelect.value = task.capability === 'human' ? 'human' : 'agent';

    const actorWrap   = el('div', { id: 'kt-actor-wrap' });
    const actorLabel  = el('label', { for: 'kt-actor' }, 'Pinned to');
    const actorSelect = el('select', { id: 'kt-actor' },
        ...buildActorOptions(capSelect.value, task.actor_email));
    actorSelect.value = task.actor_email || '';
    actorWrap.appendChild(actorLabel);
    actorWrap.appendChild(actorSelect);

    // Hide pinned-actor row for agent tasks (no per-agent pinning yet).
    function syncActorVisibility() {
        if (capSelect.value === 'human') {
            actorWrap.style.display = '';
        } else {
            actorWrap.style.display = 'none';
        }
    }
    capSelect.addEventListener('change', () => {
        // Rebuild options for the new capability — drop stale agent options
        // when switching to human, and vice versa.
        actorSelect.innerHTML = '';
        const opts = buildActorOptions(capSelect.value, task.actor_email);
        opts.forEach(o => actorSelect.appendChild(o));
        // Clear selection when switching to agent (no pinning supported)
        if (capSelect.value !== 'human') actorSelect.value = '';
        syncActorVisibility();
    });
    syncActorVisibility();

    // Comments list — read-only. Append-only edit comes in Phase 4.
    const commentsBox = el('div', { class: 'kt-comments' });
    const comments = Array.isArray(task.comments) ? task.comments : [];
    if (comments.length === 0) {
        commentsBox.appendChild(el('div', { class: 'muted' }, 'No comments yet.'));
    } else {
        comments.forEach(c => {
            commentsBox.appendChild(el('div', { class: 'kt-comment' },
                el('div', { class: 'kt-comment-meta muted' },
                    (c.by_name || c.by || '(unknown)') + ' · ' + fmtTime(c.at)),
                el('div', { class: 'kt-comment-text' }, c.text || ''),
            ));
        });
    }

    let saving = false;
    async function commit() {
        if (saving) return;
        const newName  = nameInput.value.trim();
        const newDesc  = descInput.value;
        const newCap   = capSelect.value === 'human' ? 'human' : 'agent';
        const newActor = (newCap === 'human' && actorSelect.value)
                       ? actorSelect.value
                       : null;

        // Build a fields-delta that only includes changed fields. This
        // avoids spurious version bumps and keeps the wire small.
        const fields = {};
        if (newName !== (task.name || ''))            fields.name = newName;
        if (newDesc !== (task.description || ''))     fields.description = newDesc;
        if (newCap  !== (task.capability || 'agent')) fields.capability = newCap;
        if (newActor !== (task.actor_email || null))  fields.actor_email = newActor;

        if (Object.keys(fields).length === 0) {
            closeEditor(false);
            return;
        }
        saving = true;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        const ok = await kanbanSaveTaskFields(task.id, fields);
        if (ok) {
            closeEditor(true);
        } else {
            // kanbanSaveBoard already showed an error. Re-enable so user
            // can retry or cancel.
            saving = false;
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    }

    function closeEditor(/*saved*/) {
        document.removeEventListener('keydown', onKeyDown);
        overlay.remove();
        setMode('browse');
    }
    function onKeyDown(e) {
        if (e.key === 'Escape') { e.preventDefault(); closeEditor(false); }
    }
    document.addEventListener('keydown', onKeyDown);

    const saveBtn   = el('button', { id: 'kt-save', on: { click: commit }}, 'Save');
    const cancelBtn = el('button', { class: 'secondary', on: { click: () => closeEditor(false) }}, 'Cancel');

    const card = el('div', { class: 'modal-card kanban-task-modal' },
        el('h2', {}, 'Edit task' + (label ? ' ' + label : '')),
        el('label', { for: 'kt-name' }, 'Name'),
        nameInput,
        el('label', { for: 'kt-desc' }, 'Description'),
        descInput,
        el('label', { for: 'kt-cap' }, 'Capability'),
        capSelect,
        actorWrap,
        el('label', {}, 'Comments'),
        commentsBox,
        el('div', { class: 'row', style: 'margin-top: 1rem; justify-content: flex-end' },
            cancelBtn,
            saveBtn,
        ),
    );

    overlay.appendChild(card);
    document.body.appendChild(overlay);
    setTimeout(() => nameInput.focus(), 50);
}

// Build just the board-grid element (the columns + the [+ New WP] cell).
// Shared by renderKanban (first render) and kanbanRefreshSilently (after
// every save). Pure builder — no side effects on the DOM.
function kanbanBuildGrid(board) {
    const wps = board.wps || [];
    const grid = el('div', { class: 'kanban-board' });
    wps.forEach((wp, idx) => {
        grid.appendChild(kanbanWpColumn(wp, idx + 1, state.userColors));
    });
    grid.appendChild(el('button', {
        class: 'kanban-add-wp',
        title: 'Add a new work package',
        on: { click: kanbanAddWp },
    }, '+ New WP'));
    return grid;
}

// Silent refresh: fetch fresh board + colors, then swap the existing
// .kanban-board element with a freshly-built one. The surrounding pane
// (top bar, progress notice) stays put, so there's no blank-flash. Used
// after kanban-save returns — the visible disruption is reduced from a
// full pane wipe to a single replaceChild.
async function kanbanRefreshSilently() {
    const pane = document.getElementById('tab-content');
    if (!pane) return;
    const oldGrid = pane.querySelector('.kanban-board');
    if (!oldGrid) {
        // No existing grid — fall through to a full render. Happens if
        // the user navigated away and back during a save.
        return renderKanban();
    }
    let board, colorResp;
    try {
        [board, colorResp] = await Promise.all([
            adminGet('kanban',      { p: state.project, owner: state.projectOwner }),
            adminGet('user-colors').catch(() => ({ colors: {}, azzurro: '#85B7EB' })),
        ]);
    } catch (e) {
        if (/kanban_disabled/i.test(e.message)) return renderKanban();
        setError(e.message);
        return;
    }
    state.kanbanData    = board;
    state.kanbanVersion = board.kanban_version;
    state.userColors    = colorResp.colors || {};
    updateAssignmentNotice(board);

    const newGrid = kanbanBuildGrid(board);
    oldGrid.parentNode.replaceChild(newGrid, oldGrid);

    // Update the "Board vN · M work package(s)" text in the top bar.
    const versionLabel = pane.querySelector('.kanban-bar .muted');
    if (versionLabel) {
        versionLabel.textContent =
            'Board v' + (board.kanban_version || 0) +
            ' · ' + (board.wps || []).length + ' work package(s)';
    }
}

async function renderKanban() {
    setMode('browse');
    const pane = document.getElementById('tab-content');
    if (!pane) return;
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading board…'));

    try {
        // Parallel fetches: board + user colors (for actor lookups beyond
        // what the server already attaches).
        const [board, colorResp] = await Promise.all([
            adminGet('kanban', { p: state.project, owner: state.projectOwner }),
            adminGet('user-colors').catch(() => ({ colors: {}, azzurro: '#85B7EB' })),
        ]);
        state.kanbanData = board;
        state.kanbanVersion = board.kanban_version;
        state.userColors = colorResp.colors || {};
        updateAssignmentNotice(board);

        pane.innerHTML = '';

        // Top bar: version + refresh
        const bar = el('div', { class: 'kanban-bar' },
            el('span', { class: 'muted' },
                'Board v' + (board.kanban_version || 0) +
                ' · ' + (board.wps || []).length + ' work package(s)'),
            el('button', { class: 'kanban-refresh', on: { click: kanbanRefreshSilently } }, '↻ Refresh'),
        );
        pane.appendChild(bar);

        // Board grid (shared builder used by silent refresh too)
        const grid = kanbanBuildGrid(board);
        pane.appendChild(grid);

        const wps = board.wps || [];
        if (wps.length === 0) {
            pane.appendChild(el('p', { class: 'muted',
                style: 'margin-top: 0.5rem; font-size: 0.85rem' },
                'No work packages yet — Phase 0 may have been deleted. ' +
                'Use [+ New WP] to start.'));
        }

        // Tips strip — replace once everyone knows the gestures. Kept
        // short and dense so it doesn't dominate the page.
        pane.appendChild(el('div', { class: 'muted',
            style: 'margin-top: 1rem; font-size: 0.85rem' },
            'Double-click name → rename. Click ✎ → edit details. Right-click → menu (claim, release, mark done, delete, move…). ' +
            'Click [Claim] / [Mark done] for the common case. Drag to reorder.'));
    } catch (e) {
        pane.innerHTML = '';
        if (/kanban_disabled/i.test(e.message)) {
            pane.appendChild(el('div', { class: 'muted' },
                'Kanban is disabled for this project. Enable it in Settings.'));
        } else {
            pane.appendChild(el('div', { class: 'error' }, e.message));
        }
    }
}

// --- Tab: Settings -------------------------------------------------------

async function renderSettings() {
    setMode('editing');  // settings always blocks polling — user is likely editing
    const pane = document.getElementById('tab-content');
    pane.innerHTML = '';
    pane.appendChild(el('div', { class: 'loading' }, 'Loading…'));

    let projectData = state.projectData;
    try {
        const fresh = await adminGet('project', { p: state.project, owner: state.projectOwner });
        projectData = fresh;
        state.projectData = fresh;
        state.projectOwner = fresh.owner || state.projectOwner;
    } catch (_) { /* use cached data if fetch fails */ }

    pane.innerHTML = '';

    pane.appendChild(el('h2', {}, 'Description'));
    const desc = el('textarea', { rows: 3, id: 's-desc' });
    desc.value = projectData.description || '';
    pane.appendChild(desc);
    pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem' },
        el('button', { on: { click: async () => {
            try {
                await adminPost('update-description', { owner: state.projectOwner, project: state.project, description: document.getElementById('s-desc').value });
                setStatus('Description updated');
            } catch (e) { setError(e.message); }
        }}}, 'Save description'),
    ));

    // --- Kanban board toggle (owner / admin only) ----------------------
    // Server enforces this (toggle-kanban returns 403 to non-owners), but
    // we hide the UI from non-owners so they don't see a button that
    // can't work for them.
    const canToggleKanban = projectData.role_in_project === 'owner'
                         || window.CURRENT_USER.role === 'admin';
    if (canToggleKanban) {
        let syncGuestTimeoutRow = () => {};   // set once the claim-timeout inputs exist
        pane.appendChild(el('h2', {}, 'Kanban board'));
        const cb = el('input', { type: 'checkbox', id: 's-kanban' });
        if (projectData.has_kanban) cb.checked = true;
        cb.addEventListener('change', async () => {
            const wanted = cb.checked;
            cb.disabled = true;
            try {
                await adminPost('toggle-kanban', {
                    owner: state.projectOwner,
                    project: state.project,
                    enabled: wanted,
                });
                state.projectData.has_kanban = wanted;
                setStatus(wanted ? 'Kanban enabled' : 'Kanban disabled');
                syncGuestTimeoutRow();
                // Guest workers depend on the board: reflect that here.
                guestCb.disabled = !wanted;
                if (!wanted && guestCb.checked) {
                    guestCb.checked = false;
                    state.projectData.guest_workers = false;
                }
                // Rebuild just the tab strip so the Kanban tab appears /
                // disappears. We deliberately do NOT re-call showProject()
                // because that would bounce the user out of Settings and
                // back to Files. Settings tab content is untouched.
                const oldTabs = document.querySelector('.tabs');
                if (oldTabs) {
                    oldTabs.parentNode.replaceChild(buildTabStrip(state.projectData), oldTabs);
                }
            } catch (e) {
                // Revert checkbox on failure
                cb.checked = !wanted;
                setError(e.message);
            } finally {
                cb.disabled = false;
            }
        });
        pane.appendChild(el('label', { class: 'row', style: 'gap: 0.5rem; align-items: center' },
            cb,
            el('span', {}, 'Enable Kanban board for this project'),
        ));
        pane.appendChild(el('div', { class: 'muted', style: 'font-size: 0.85rem; margin-top: 0.25rem' },
            'Adds a Kanban tab between Files and Chat. ' +
            'On first enable, an empty "Backlog" work package is seeded automatically.'));

        // --- Guest workers (depends on Kanban) -------------------------
        const guestCb = el('input', { type: 'checkbox', id: 's-guest' });
        if (projectData.guest_workers) guestCb.checked = true;
        guestCb.disabled = !projectData.has_kanban;
        guestCb.addEventListener('change', async () => {
            const wanted = guestCb.checked;
            if (wanted) {
                const ok = confirm(
                    'Allow guest workers?\n\n' +
                    'Guest workers are AI instances that connect to the collab system ' +
                    'and offer work support. By enabling guest workers you allow these ' +
                    'external AIs to read your project data (all files and chat) and ' +
                    'complete tasks for it.\n\n' +
                    'Not recommended for projects with non-disclosable content.\n\nContinue?'
                );
                if (!ok) { guestCb.checked = false; return; }
            }
            guestCb.disabled = true;
            try {
                await adminPost('toggle-guest-workers', {
                    owner: state.projectOwner,
                    project: state.project,
                    enabled: wanted,
                });
                state.projectData.guest_workers = wanted;
                setStatus(wanted ? 'Guest workers allowed' : 'Guest workers disabled');
                syncGuestTimeoutRow();
            } catch (e) {
                guestCb.checked = !wanted;
                setError(e.message);
            } finally {
                guestCb.disabled = !state.projectData.has_kanban;
            }
        });
        pane.appendChild(el('label', { class: 'row', style: 'gap: 0.5rem; align-items: center; margin-top: 0.75rem' },
            guestCb,
            el('span', {}, 'Allow guest workers'),
        ));
        pane.appendChild(el('div', { class: 'muted', style: 'font-size: 0.85rem; margin-top: 0.25rem' },
            'Guest workers are AI instances that connect to the collab system and offer ' +
            'work support. By enabling guest workers you allow these external AIs to read ' +
            'your project data and complete tasks for it.'));

        // --- Claim timeouts ----------------------------------------------
        const ct = projectData.claim_timeouts_hours || { agent: 3, guest: 1, human: 168 };
        const ctInput = (id, val, min, max) => {
            const i = el('input', { type: 'number', id, min, max, step: '0.25', style: 'width: 6rem' });
            i.value = val;
            return i;
        };
        const ctAgent = ctInput('s-ct-agent', ct.agent, 0.25, 168);
        const ctGuest = ctInput('s-ct-guest', ct.guest, 0.25, 168);
        const ctHuman = ctInput('s-ct-human', ct.human, 1, 2160);
        const ctRow = (input, label) => el('label', { class: 'row', style: 'gap: 0.5rem; align-items: center; margin-top: 0.4rem' },
            input, el('span', {}, label));
        const guestRow = ctRow(ctGuest, 'hours: guest-worker claims');
        syncGuestTimeoutRow = () => { guestRow.hidden = !state.projectData.guest_workers; };
        syncGuestTimeoutRow();
        pane.appendChild(el('h3', {}, 'Claim timeouts'));
        pane.appendChild(ctRow(ctAgent, 'hours: agent claims'));
        pane.appendChild(guestRow);
        pane.appendChild(ctRow(ctHuman, 'hours: human claims'));
        pane.appendChild(el('div', { class: 'muted', style: 'font-size: 0.85rem; margin-top: 0.25rem' },
            'An unfinished claim returns to "ready" after this time. Agent and guest claims ' +
            'restart the clock whenever the claimer talks to the server; human claims count ' +
            'from the moment of claiming. Manager agents can change these values too.'));
        pane.appendChild(el('div', { class: 'row', style: 'margin-top: 0.5rem' },
            el('button', { class: 'secondary', on: { click: async () => {
                try {
                    const body = { owner: state.projectOwner, project: state.project,
                        agent_hours: Number(ctAgent.value), human_hours: Number(ctHuman.value) };
                    if (state.projectData.guest_workers) body.guest_hours = Number(ctGuest.value);
                    const r = await adminPost('set-claim-timeouts', body);
                    state.projectData.claim_timeouts_hours = r.claim_timeouts_hours;
                    setStatus('Claim timeouts saved');
                } catch (e) { setError(e.message); }
            }}}, 'Save timeouts'),
        ));
    }

    const isProjectOwner = projectData.role_in_project === 'owner';
    const isGlobalAdmin  = window.CURRENT_USER.role === 'admin';

    // Backup contains all agent secrets → owner/admin only (server enforces too).
    if (isProjectOwner || isGlobalAdmin) {
        pane.appendChild(el('h2', {}, 'Backup'));
        pane.appendChild(el('div', { class: 'row' },
            el('a', {
                class: 'btn',
                href: `${ADMIN}?action=backup&p=${encodeURIComponent(state.project)}&owner=${encodeURIComponent(state.projectOwner)}`,
                download: ''
            }, 'Download .tar.gz')
        ));
    }
    // A member who is also a global admin can both leave AND delete
    const canDelete = isProjectOwner || isGlobalAdmin;
    const canLeave  = !isProjectOwner;  // owner can't leave their own project

    pane.appendChild(el('h2', {}, 'Danger zone'));

    if (canLeave) {
        pane.appendChild(el('div', { class: 'warn' }, 'You are a member of this project. Leaving will remove your access.'));
        pane.appendChild(el('div', { class: 'row', style: 'margin-bottom: 0.5rem' },
            el('button', { class: 'danger', on: { click: async () => {
                if (!confirm('Leave this project? You will lose access unless re-invited.')) return;
                try {
                    await adminPost('leave-project', { owner: state.projectOwner, project: state.project });
                    setStatus('You have left the project.');
                    setMode('browse');
                    showList();
                } catch (e) { setError(e.message); }
            }}}, 'Leave project'),
        ));
    }

    if (canDelete) {
        pane.appendChild(el('div', { class: 'warn' }, 'Deleting a project removes ALL files, chat history, and agent secrets permanently.'));
        pane.appendChild(el('div', { class: 'row' },
            el('button', { class: 'danger', on: { click: async () => {
                const t = prompt(`Type the project name (${state.project}) to confirm deletion:`);
                if (t !== state.project) { setStatus('Cancelled'); return; }
                try {
                    await adminPost('delete-project', { owner: state.projectOwner, name: state.project });
                    setStatus(`Deleted ${state.project}`);
                    setMode('browse');
                    showList();
                } catch (e) { setError(e.message); }
            }}}, 'Delete project'),
        ));
    }
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

        const isOwner = (data.role_in_project === 'owner')
                     || window.CURRENT_USER.role === 'admin';

        pane.appendChild(el('h2', {}, 'Shared with'));

        const members = data.shared_with || [];

        // Fetch user list for role display (admin only, best-effort)
        let userRoles = {};
        try {
            const { users } = await adminGet('list-users');
            users.forEach(u => { userRoles[u.email] = u.role; });
        } catch (_) {}

        if (members.length === 0) {
            pane.appendChild(el('p', { class: 'muted' }, 'Not shared with anyone yet.'));
        } else {
            const table = el('table', {},
                el('thead', {}, el('tr', {},
                    el('th', {}, 'User'),
                    el('th', {}, 'Role'),
                    el('th', {}, ''),
                )),
                el('tbody', {}, ...members.map(email =>
                    el('tr', {},
                        el('td', {}, email),
                        el('td', { class: 'muted' }, userRoles[email] || '—'),
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
    setActiveNav('users-link');
    setTitle('Users');
    setBreadcrumb([]);

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


// --- Skill ---------------------------------------------------------------

function showSkill() {
    stopPolling();
    setMode('browse');
    state.view = 'skill';
    state.project = null;
    setActiveNav('skill-link');
    setTitle('Skill');
    setBreadcrumb([]);

    const SKILL_URL = 'skill-download.php';

    const app = document.getElementById('app');
    app.innerHTML = '';
    app.appendChild(el('div', { class: 'card skill-card' },

        el('h1', {}, 'ProjectCollab Skill'),

        // What is a skill
        el('p', {}, 'A skill is a bundle of Markdown instruction files that Claude reads at the start of a session to learn how to use a specific tool or system.'),

        el('hr'),

        // What is contained
        el('h2', {}, 'What is in this skill'),
        el('p', {}, 'The ProjectCollab skill contains:'),
        el('ul', {},
            el('li', {}, el('code', {}, 'SKILL.md'), ' — Core bootstrap flow, API endpoints, versioning protocol, and chat etiquette. Platform-agnostic.'),
            el('li', {}, el('code', {}, 'platform-claudecode.md'), ' — Claude Code specifics: .collab secret file, Windows/WSL curl patterns, PowerShell build tools, binary upload paths.'),
            el('li', {}, el('code', {}, 'platform-chat.md'), ' — claude.ai chat specifics: sandbox notes, skills path, memory system caveat.'),
            el('li', {}, el('code', {}, 'references/api-spec.md'), ' — Full API specification (v0.2): all endpoints, request/response shapes, state machine, error codes.'),
            el('li', {}, el('code', {}, 'references/curl-recipes.md'), ' — Copy-paste curl examples for every endpoint.'),
        ),

        el('hr'),

        // How to use
        el('h2', {}, 'How to use this skill'),
        el('p', {}, 'Once installed, connect to a project by telling Claude:'),
        el('pre', { class: 'skill-code' }, 'load your projectcollab skill, connect with secret <your-secret>'),
        el('p', {}, 'Claude will read its platform file, call the API to discover its identity and project, read the project files and chat, introduce itself, and await instructions.'),
        el('p', {}, 'The project name and agent identity are assigned by the server — you do not need to specify them. Each agent secret maps to a name, role, and set of skills defined in the project configuration.'),

        el('hr'),

        // How to install
        el('h2', {}, 'How to install this skill'),

        el('h3', {}, 'Claude.ai web chat'),
        el('p', {},
            'Download the skill file: ',
            el('a', { href: SKILL_URL, download: '' }, '⬇ projectcollab.skill'),
        ),
        el('p', {}, 'Then copy the extracted files into the skills folder on the server where the chat sandbox mounts its skills:'),
        el('pre', { class: 'skill-code' },
`unzip projectcollab.skill -d /tmp/skill-extract\ncp -r /tmp/skill-extract/projectcollab /mnt/skills/user/projectcollab`),
        el('p', { class: 'muted' }, 'The skill is active immediately — no restart required. Claude will read it on the next session when you ask it to load the projectcollab skill.'),

        el('h3', {}, 'Claude Code'),
        el('p', {},
            'Download the skill file: ',
            el('a', { href: SKILL_URL, download: '' }, '⬇ projectcollab.skill'),
        ),
        el('p', {}, 'Run in PowerShell:'),
        el('pre', { class: 'skill-code' },
`Expand-Archive projectcollab.skill -DestinationPath $HOME\\.claude\\skills\\ -Force`),
        el('p', { class: 'muted' }, 'Installs to: ', el('code', {}, 'C:\\Users\\<you>\\.claude\\skills\\projectcollab\\')),
        el('p', {}, 'Or on Linux/macOS:'),
        el('pre', { class: 'skill-code' },
`unzip projectcollab.skill -d ~/.claude/skills/`),

        el('hr'),
        el('p', { class: 'muted about-version' }, 'Skill format v1 · ProjectCollab 0.1'),
    ));
}

// --- About ---------------------------------------------------------------

function showAbout() {
    stopPolling();
    setMode('browse');
    state.view = 'about';
    state.project = null;
    setActiveNav('about-link');
    setTitle('About');
    setBreadcrumb([]);

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
