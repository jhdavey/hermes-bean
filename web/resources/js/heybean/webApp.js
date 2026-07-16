import { appThemes, appThemesByKey, icons, subscriptionPlans, systemDarkScheme, themeModes } from './config.js';

export function reconcileAllDayEndDateInput(startValue, endValue) {
    const start = String(startValue || '').trim();
    if (endValue || !/^\d{4}-\d{2}-\d{2}$/.test(start)) return String(endValue || '');
    const date = new Date(`${start}T00:00:00Z`);
    if (Number.isNaN(date.getTime())) return '';
    date.setUTCDate(date.getUTCDate() + 1);
    return date.toISOString().slice(0, 10);
}

export function mountHeyBeanWebApp(mount) {
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';
    const initialMode = mount.dataset.authMode || 'login';
    const state = {
        token: sessionStorage.getItem(tokenKey) || localStorage.getItem(tokenKey) || '',
        remember: localStorage.getItem(rememberKey) === 'true',
        phase: 'loading',
        authMode: initialMode,
        selected: initialMode === 'admin' ? 'admin' : 'today',
        user: null,
        summary: null,
        tasks: [],
        reminders: [],
        calendar: [],
        categories: [],
        notes: [],
        noteFolders: [],
        selectedNoteId: '',
        googleStatus: null,
        outlookStatus: null,
        subscription: null,
        issueReports: null,
        planLimits: null,
        coupons: null,
        modal: null,
        busy: false,
        error: '',
        notice: '',
    };

    systemDarkScheme?.addEventListener?.('change', applyTheme);
    boot();

    async function boot() {
        if (initialMode === 'subscribe' && state.token) {
            await loadSignedIn();
            return;
        }
        if (state.token) await loadSignedIn();
        else {
            state.phase = initialMode === 'register' ? 'register' : 'signedOut';
            render();
        }
    }

    async function api(path, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        if (state.token) headers.Authorization = `Bearer ${state.token}`;
        if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
        const response = await fetch(`/api${path}`, {
            method: options.method || 'GET',
            headers,
            body: options.body instanceof FormData ? options.body : options.body ? JSON.stringify(options.body) : undefined,
            keepalive: options.keepalive === true,
        });
        const payload = response.status === 204 ? null : await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(payload?.error?.message || payload?.message || `Request failed (${response.status})`);
            error.status = response.status;
            throw error;
        }
        return payload?.data ?? payload;
    }

    function workspacePath(path) {
        const workspaceId = state.user?.active_workspace?.id || state.user?.default_workspace_id;
        if (!workspaceId) return path;
        return `${path}${path.includes('?') ? '&' : '?'}workspace_id=${encodeURIComponent(workspaceId)}`;
    }

    async function loadSignedIn() {
        state.phase = 'loading';
        state.error = '';
        render();
        try {
            const user = await api('/auth/me');
            state.user = user;
            const notesAllowed = user?.plan_limits?.notes_enabled !== false;
            const results = await Promise.all([
                api(workspacePath('/today')),
                api(workspacePath('/tasks')),
                api(workspacePath('/tasks/past')).catch(() => []),
                api(workspacePath('/reminders')),
                api(workspacePath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1')),
                api(workspacePath('/event-categories')),
                notesAllowed ? api(workspacePath('/note-folders')) : Promise.resolve([]),
                notesAllowed ? api(workspacePath('/notes')) : Promise.resolve([]),
                api('/google-calendar/status?cached=1').catch(() => null),
                api('/outlook-calendar/status?cached=1').catch(() => null),
                api('/billing/subscription').catch(() => null),
            ]);
            const [summary, tasks, pastTasks, reminders, calendar, categories, folders, notes, google, outlook, subscription] = results;
            state.summary = summary;
            state.user = { ...user, ...(summary?.user || {}) };
            state.tasks = mergeById(tasks, pastTasks);
            state.reminders = list(reminders);
            state.calendar = list(calendar);
            state.categories = list(categories);
            state.noteFolders = list(folders);
            state.notes = list(notes);
            state.selectedNoteId ||= String(state.notes[0]?.id || '');
            state.googleStatus = google;
            state.outlookStatus = outlook;
            state.subscription = subscription;
            state.phase = 'signedIn';
            render();
            if (state.selected === 'admin') loadAdmin();
        } catch (error) {
            if (error.status === 401) clearToken();
            state.phase = 'signedOut';
            state.error = friendlyError(error);
            render();
        }
    }

    function render() {
        applyTheme();
        mount.innerHTML = state.phase === 'loading'
            ? loadingMarkup()
            : state.phase === 'signedIn'
                ? signedInMarkup()
                : state.phase === 'register'
                    ? registerMarkup()
                    : signedOutMarkup();
        bindActions();
        if (state.modal) {
            mount.insertAdjacentHTML('beforeend', modalMarkup(state.modal));
            bindModalActions();
        }
    }

    function loadingMarkup() {
        return '<div class="hb-loading-screen"><div class="hb-spinner" aria-hidden="true"></div><p>Loading HeyBean…</p></div>';
    }

    function signedOutMarkup() {
        return `<main class="hb-auth-screen"><section class="hb-card hb-auth-card">
            <img class="hb-auth-logo" src="${escapeAttr(mount.dataset.logo || '/images/bean-logo.png')}" alt="HeyBean">
            <h1>Welcome back</h1><p>Sign in to manage your day.</p>${statusMarkup()}
            <form class="hb-form" data-login>${field('Email', 'email', 'email', '', 'required autocomplete="email"')}${field('Password', 'password', 'password', '', 'required autocomplete="current-password"')}
                <label class="hb-switch-row"><input name="remember" type="checkbox" ${state.remember ? 'checked' : ''}> <span>Keep me signed in</span></label>
                <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>Sign in</button>
            </form><div class="hb-auth-links"><button type="button" data-show-register>Create account</button><a href="/forgot-password">Forgot password?</a></div>
        </section></main>`;
    }

    function registerMarkup() {
        const selectedPlan = new URLSearchParams(location.search).get('plan') || 'premium';
        return `<main class="hb-auth-screen"><section class="hb-card hb-auth-card hb-auth-card-wide">
            <img class="hb-auth-logo" src="${escapeAttr(mount.dataset.logo || '/images/bean-logo.png')}" alt="HeyBean">
            <h1>Create your account</h1><p>Set up calendars, tasks, reminders, notes, and workspaces in one place.</p>${statusMarkup()}
            <form class="hb-form" data-register>
                ${field('Name', 'name', 'text', '', 'required autocomplete="name"')}${field('Email', 'email', 'email', '', 'required autocomplete="email"')}
                ${field('Password', 'password', 'password', '', 'required minlength="12" autocomplete="new-password"')}${field('Confirm password', 'password_confirmation', 'password', '', 'required minlength="12" autocomplete="new-password"')}
                <label class="hb-label">Appearance<select class="hb-select" name="theme_mode">${themeModes.map((mode) => `<option value="${mode.key}">${mode.label}</option>`).join('')}</select></label>
                <input type="hidden" name="plan" value="${escapeAttr(selectedPlan)}"><button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>Create account</button>
            </form><div class="hb-auth-links"><button type="button" data-show-login>Already have an account?</button></div>
        </section></main>`;
    }

    function signedInMarkup() {
        return `<div class="hb-app"><header class="hb-topbar">
            <a class="hb-brand" href="/app"><img src="${escapeAttr(mount.dataset.logo || '/images/bean-logo.png')}" alt=""><span>HeyBean</span></a>
            ${workspaceSwitcherMarkup()}<span class="hb-spacer"></span>${topNavigationMarkup()}
            <button class="hb-icon-button" type="button" data-create="${state.selected === 'today' ? 'event' : state.selected === 'reminders' ? 'reminder' : state.selected === 'notes' ? 'note' : 'task'}" aria-label="Add item">${icons.add}</button>
            <button class="hb-icon-button" type="button" data-nav="settings" aria-label="Settings">${icons.settings}</button>
        </header><main class="hb-main"><div class="hb-shell">${statusMarkup()}${viewMarkup()}</div></main>${bottomNavigationMarkup()}</div>`;
    }

    function topNavigationMarkup() {
        const items = navigationItems();
        if (state.user?.is_admin) items.push(['admin', 'Admin', icons.activity]);
        return `<nav class="hb-top-nav" aria-label="Primary">${items.map(navButton).join('')}</nav>`;
    }

    function bottomNavigationMarkup() {
        return `<nav class="hb-bottom-menu" aria-label="Primary"><div class="hb-bottom-bar">${navigationItems().map(navButton).join('')}</div></nav>`;
    }

    function navigationItems() {
        return [['today', 'Calendar', icons.calendar], ['tasks', 'Tasks', icons.tasks], ['reminders', 'Reminders', icons.reminders], ['notes', 'Notes', icons.notes]];
    }

    function navButton([key, label, icon]) {
        return `<button class="hb-nav-item ${state.selected === key ? 'hb-nav-item-active' : ''}" type="button" data-nav="${key}">${icon}<span>${label}</span></button>`;
    }

    function workspaceSwitcherMarkup() {
        const workspaces = list(state.user?.workspaces);
        const active = state.user?.active_workspace?.id || state.user?.default_workspace_id;
        if (!workspaces.length) return '';
        return `<label class="hb-workspace-switcher"><span class="hb-compact-icon">${icons.spaces}</span><select data-workspace aria-label="Workspace">${workspaces.map((workspace) => `<option value="${workspace.id}" ${String(workspace.id) === String(active) ? 'selected' : ''}>${escapeHtml(workspace.name || 'Workspace')}</option>`).join('')}</select></label>`;
    }

    function viewMarkup() {
        if (state.selected === 'tasks') return tasksMarkup();
        if (state.selected === 'reminders') return remindersMarkup();
        if (state.selected === 'notes') return notesMarkup();
        if (state.selected === 'settings') return settingsMarkup();
        if (state.selected === 'admin') return adminMarkup();
        return calendarMarkup();
    }

    function section(title, subtitle, body, action = '') {
        return `<section class="hb-card hb-card-pad"><div class="hb-section-action-row"><div><h1>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle)}</p></div>${action}</div>${body}</section>`;
    }

    function calendarMarkup() {
        const events = [...state.calendar].sort((a, b) => dateValue(a.starts_at) - dateValue(b.starts_at));
        const body = events.length ? `<div class="hb-list">${events.map((event) => itemRow(event, 'event')).join('')}</div>` : emptyMarkup('No calendar events yet.');
        return `<div class="hb-dashboard-grid"><div class="hb-primary-column">${section('Calendar', 'Your connected and HeyBean events.', body, addButton('event', 'Add event'))}</div><aside class="hb-side-column">${commandCenterMarkup()}</aside></div>`;
    }

    function commandCenterMarkup() {
        const now = new Date();
        const end = new Date(now); end.setHours(23, 59, 59, 999);
        const agenda = [
            ...state.calendar.filter((item) => dateValue(item.starts_at) <= end.getTime() && dateValue(item.ends_at || item.starts_at) >= now.getTime()).map((item) => ({ ...item, kind: 'event', when: item.starts_at })),
            ...state.tasks.filter((item) => item.status !== 'completed' && item.due_at && dateValue(item.due_at) <= end.getTime()).map((item) => ({ ...item, kind: 'task', when: item.due_at })),
            ...state.reminders.filter((item) => item.status === 'scheduled' && item.remind_at && dateValue(item.remind_at) <= end.getTime()).map((item) => ({ ...item, kind: 'reminder', when: item.remind_at })),
        ].sort((a, b) => dateValue(a.when) - dateValue(b.when));
        const upcomingDays = [1, 2].map((offset) => {
            const day = new Date(now); day.setDate(day.getDate() + offset); day.setHours(0, 0, 0, 0);
            const next = new Date(day); next.setDate(next.getDate() + 1);
            const items = state.calendar.filter((item) => dateValue(item.starts_at) >= day.getTime() && dateValue(item.starts_at) < next.getTime());
            return `<div class="hb-glance-day"><strong>${offset === 1 ? 'Tomorrow' : new Intl.DateTimeFormat(undefined, { weekday: 'long' }).format(day)}</strong>${items.map((item) => centerRow(item, 'event', item.starts_at)).join('') || '<small>No events</small>'}</div>`;
        }).join('');
        return `<section class="hb-card hb-command-center-card" aria-label="Command center"><div class="hb-card-pad"><div class="hb-section-action-row"><div><h2>Command center</h2><p>Today and what comes next.</p></div></div><div class="hb-command-center-agenda-list">${agenda.map((item) => centerRow(item, item.kind, item.when)).join('') || emptyMarkup('Nothing else scheduled for today.')}</div><div class="hb-command-center-glance-list">${upcomingDays}</div></div></section>`;
    }

    function centerRow(item, kind, when) {
        return `<button class="hb-command-center-row hb-command-center-row-${kind}" type="button" data-edit="${kind}:${item.id}"><span class="hb-command-center-time">${escapeHtml(when ? new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(new Date(when)) : '')}</span><span class="hb-command-center-copy"><strong>${escapeHtml(item.title || 'Untitled')}</strong><small>${escapeHtml(kind === 'event' ? item.location || 'Event' : kind === 'task' ? 'Task' : 'Reminder')}</small></span></button>`;
    }

    function tasksMarkup() {
        const active = state.tasks.filter((task) => task.status !== 'completed');
        const completed = state.tasks.filter((task) => task.status === 'completed');
        return section('Tasks', 'Plan and complete work across your workspaces.', `${active.length ? `<div class="hb-list">${active.map((task) => itemRow(task, 'task')).join('')}</div>` : emptyMarkup('No active tasks.')}${completed.length ? `<details class="hb-history"><summary>Completed (${completed.length})</summary><div class="hb-list">${completed.map((task) => itemRow(task, 'task')).join('')}</div></details>` : ''}`, addButton('task', 'Add task'));
    }

    function remindersMarkup() {
        const scheduled = state.reminders.filter((reminder) => reminder.status === 'scheduled');
        const completed = state.reminders.filter((reminder) => reminder.status === 'completed');
        return section('Reminders', 'Keep important follow-ups visible.', `${scheduled.length ? `<div class="hb-list">${scheduled.map((reminder) => itemRow(reminder, 'reminder')).join('')}</div>` : emptyMarkup('No scheduled reminders.')}${completed.length ? `<details class="hb-history"><summary>Completed (${completed.length})</summary><div class="hb-list">${completed.map((reminder) => itemRow(reminder, 'reminder')).join('')}</div></details>` : ''}`, addButton('reminder', 'Add reminder'));
    }

    function notesMarkup() {
        if (state.user?.plan_limits?.notes_enabled === false) return section('Notes', 'Store longer plans and lists.', emptyMarkup('Notes are not available on this plan.'));
        const selected = state.notes.find((note) => String(note.id) === String(state.selectedNoteId)) || state.notes[0];
        return `<section class="hb-card hb-notes-shell"><aside class="hb-note-sidebar"><div class="hb-section-action-row"><h1>Notes</h1>${addButton('note', 'New')}</div>${state.notes.map((note) => `<button class="hb-note-list-line ${String(note.id) === String(selected?.id) ? 'hb-note-list-line-active' : ''}" type="button" data-note="${note.id}"><strong>${escapeHtml(note.title || 'Untitled')}</strong><small>${escapeHtml(note.plain_text || '')}</small></button>`).join('') || emptyMarkup('No notes yet.')}</aside><div class="hb-note-editor">${selected ? `<form data-note-form data-note-id="${selected.id}" class="hb-form"><input class="hb-note-title" name="title" value="${escapeAttr(selected.title || '')}" placeholder="Title"><textarea class="hb-textarea hb-note-body" name="plain_text" rows="18" placeholder="Start writing…">${escapeHtml(selected.plain_text || '')}</textarea><div class="hb-modal-actions"><button class="hb-button-danger" type="button" data-delete-note="${selected.id}">Delete</button><button class="hb-button" type="submit">Save</button></div></form>` : emptyMarkup('Choose or create a note.')}</div></section>`;
    }

    function settingsMarkup() {
        const theme = state.user?.theme || 'green';
        const mode = state.user?.theme_mode || 'auto';
        return `${section('Settings', 'Manage your profile, appearance, integrations, and account.', `
            <form class="hb-form" data-profile>${field('Name', 'name', 'text', state.user?.name || '', 'required')}${field('Email', 'email', 'email', state.user?.email || '', 'required')}<button class="hb-button" type="submit">Save profile</button></form>
            <hr class="hb-divider"><form class="hb-form" data-theme><label class="hb-label">Color<select class="hb-select" name="theme">${appThemes.map((item) => `<option value="${item.key}" ${item.key === theme ? 'selected' : ''}>${item.label}</option>`).join('')}</select></label><label class="hb-label">Appearance<select class="hb-select" name="theme_mode">${themeModes.map((item) => `<option value="${item.key}" ${item.key === mode ? 'selected' : ''}>${item.label}</option>`).join('')}</select></label><button class="hb-button-secondary" type="submit">Apply appearance</button></form>`)}
            ${section('Calendar connections', 'Connect, synchronize, or disconnect providers.', providerMarkup('google', 'Google Calendar', state.googleStatus) + providerMarkup('outlook', 'Microsoft Outlook', state.outlookStatus))}
            ${section('Account', 'Export your information or close your account.', `<div class="hb-account-actions"><button class="hb-button-secondary" type="button" data-export>Export data</button><button class="hb-button-secondary" type="button" data-logout>Sign out</button><button class="hb-button-danger" type="button" data-delete-account>Delete account</button></div>`)}
            ${section('Feedback', 'Tell us about a problem with HeyBean.', '<button class="hb-button-secondary" type="button" data-report>Report an issue</button>')}`;
    }

    function providerMarkup(key, label, status) {
        const connected = status?.connected === true;
        return `<div class="hb-switch-row"><span><strong>${label}</strong><small>${connected ? 'Connected' : 'Not connected'}</small></span><div class="hb-row-actions"><button class="hb-button-secondary" type="button" data-provider="${key}:${connected ? 'sync' : 'connect'}">${connected ? 'Sync now' : 'Connect'}</button>${connected ? `<button class="hb-button-ghost" type="button" data-provider="${key}:disconnect">Disconnect</button>` : ''}</div></div>`;
    }

    function adminMarkup() {
        if (!state.user?.is_admin) return section('Admin', 'Administrator access is required.', emptyMarkup('You do not have access to this page.'));
        const open = list(state.issueReports?.issue_reports);
        const closed = list(state.issueReports?.archived_issue_reports);
        return `${section('Administration', 'Manage plans, coupons, and submitted issues.', `<button class="hb-button-secondary" type="button" data-refresh-admin>Refresh</button>`)}
            ${section('Issue reports', `${open.length} open, ${closed.length} closed`, open.map((report) => `<div class="hb-switch-row"><span><strong>${escapeHtml(report.message)}</strong><small>${escapeHtml(report.user?.email || '')}</small></span><button class="hb-button-secondary" type="button" data-close-report="${report.id}">Close</button></div>`).join('') || emptyMarkup('No open issue reports.'))}
            ${section('Plan limits', 'Current plan entitlements.', `<pre class="hb-code-block">${escapeHtml(JSON.stringify(state.planLimits || {}, null, 2))}</pre>`)}
            ${section('Coupons', 'Active promotional codes.', `<div class="hb-list">${list(state.coupons?.coupons || state.coupons).map((coupon) => `<div class="hb-switch-row"><strong>${escapeHtml(coupon.code)}</strong><small>${escapeHtml(coupon.description || '')}</small></div>`).join('') || emptyMarkup('No coupons.')}</div>`)}`;
    }

    function itemRow(item, kind) {
        const complete = kind === 'task' ? item.status === 'completed' : kind === 'reminder' ? item.status === 'completed' : false;
        const when = kind === 'event' ? item.starts_at : kind === 'task' ? item.due_at : item.remind_at;
        return `<article class="hb-item"><div class="hb-item-copy"><strong>${escapeHtml(item.title || 'Untitled')}</strong><small>${escapeHtml(when ? formatDate(when) : 'No date')}</small></div><div class="hb-row-actions">${kind !== 'event' ? `<button class="hb-icon-button" type="button" data-toggle="${kind}:${item.id}" aria-label="${complete ? 'Reopen' : 'Complete'}">${icons.checkCircle}</button>` : ''}<button class="hb-button-ghost" type="button" data-edit="${kind}:${item.id}">Edit</button></div></article>`;
    }

    function addButton(kind, label) {
        return `<button class="hb-button" type="button" data-create="${kind}">${icons.add}<span>${label}</span></button>`;
    }

    function modalMarkup(modal) {
        if (modal.kind === 'report') return reportModalMarkup();
        const item = modal.item || {};
        if (modal.kind === 'note') return noteCreateModalMarkup();
        const isEvent = modal.kind === 'event';
        const isReminder = modal.kind === 'reminder';
        const value = isEvent ? item.starts_at : isReminder ? item.remind_at : item.due_at;
        const end = isEvent ? item.ends_at : '';
        return `<div class="hb-modal-backdrop" role="dialog" aria-modal="true"><section class="hb-card hb-modal"><h2>${item.id ? 'Edit' : 'Add'} ${modal.kind}</h2><form class="hb-form" data-item-form data-kind="${modal.kind}" data-id="${item.id || ''}">${field('Title', 'title', 'text', item.title || '', 'required')}${field(isEvent ? 'Starts' : isReminder ? 'Remind at' : 'Due', 'when', 'datetime-local', toLocalInput(value))}${isEvent ? field('Ends', 'ends', 'datetime-local', toLocalInput(end)) + field('Location', 'location', 'text', item.location || '') + '<label class="hb-label">Status<select class="hb-select" name="status"><option value="scheduled">Scheduled</option><option value="cancelled">Cancelled</option></select></label>' : ''}<label class="hb-label">Details<textarea class="hb-textarea" name="description" rows="4">${escapeHtml(item.description || item.notes || '')}</textarea></label><div class="hb-modal-actions">${item.id ? '<button class="hb-button-danger" type="button" data-delete-item>Delete</button>' : ''}<button class="hb-button-secondary" type="button" data-close>Cancel</button><button class="hb-button" type="submit">Save</button></div></form></section></div>`;
    }

    function noteCreateModalMarkup() {
        return `<div class="hb-modal-backdrop" role="dialog" aria-modal="true"><section class="hb-card hb-modal"><h2>New note</h2><form class="hb-form" data-new-note>${field('Title', 'title', 'text', '', 'required')}<label class="hb-label">Text<textarea class="hb-textarea" name="plain_text" rows="8"></textarea></label><div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close>Cancel</button><button class="hb-button" type="submit">Save</button></div></form></section></div>`;
    }

    function reportModalMarkup() {
        return `<div class="hb-modal-backdrop" role="dialog" aria-modal="true"><section class="hb-card hb-modal"><h2>Report an issue</h2><form class="hb-form" data-report-form><label class="hb-label">What happened?<textarea class="hb-textarea" name="message" rows="6" required></textarea></label><label class="hb-label">Screenshots<input class="hb-input" type="file" name="screenshots[]" accept="image/png,image/jpeg,image/webp" multiple></label><div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close>Cancel</button><button class="hb-button" type="submit">Send report</button></div></form></section></div>`;
    }

    function bindActions() {
        mount.querySelector('[data-show-register]')?.addEventListener('click', () => { state.phase = 'register'; history.pushState({}, '', '/register'); render(); });
        mount.querySelector('[data-show-login]')?.addEventListener('click', () => { state.phase = 'signedOut'; history.pushState({}, '', '/login'); render(); });
        mount.querySelector('[data-login]')?.addEventListener('submit', submitLogin);
        mount.querySelector('[data-register]')?.addEventListener('submit', submitRegister);
        mount.querySelectorAll('[data-nav]').forEach((button) => button.addEventListener('click', () => { state.selected = button.dataset.nav; state.error = ''; history.pushState({}, '', state.selected === 'admin' ? '/admin' : '/app'); render(); if (state.selected === 'admin') loadAdmin(); }));
        mount.querySelectorAll('[data-create]').forEach((button) => button.addEventListener('click', () => openItem(button.dataset.create)));
        mount.querySelectorAll('[data-edit]').forEach((button) => button.addEventListener('click', () => { const [kind, id] = button.dataset.edit.split(':'); openItem(kind, findItem(kind, id)); }));
        mount.querySelectorAll('[data-toggle]').forEach((button) => button.addEventListener('click', () => toggleItem(button.dataset.toggle)));
        mount.querySelectorAll('[data-note]').forEach((button) => button.addEventListener('click', () => { state.selectedNoteId = button.dataset.note; render(); }));
        mount.querySelector('[data-note-form]')?.addEventListener('submit', saveNote);
        mount.querySelector('[data-delete-note]')?.addEventListener('click', deleteNote);
        mount.querySelector('[data-profile]')?.addEventListener('submit', saveProfile);
        mount.querySelector('[data-theme]')?.addEventListener('submit', saveTheme);
        mount.querySelector('[data-workspace]')?.addEventListener('change', switchWorkspace);
        mount.querySelectorAll('[data-provider]').forEach((button) => button.addEventListener('click', () => providerAction(button.dataset.provider)));
        mount.querySelector('[data-report]')?.addEventListener('click', () => { state.modal = { kind: 'report' }; render(); });
        mount.querySelector('[data-export]')?.addEventListener('click', exportData);
        mount.querySelector('[data-logout]')?.addEventListener('click', logout);
        mount.querySelector('[data-delete-account]')?.addEventListener('click', deleteAccount);
        mount.querySelector('[data-refresh-admin]')?.addEventListener('click', loadAdmin);
        mount.querySelectorAll('[data-close-report]').forEach((button) => button.addEventListener('click', () => closeReport(button.dataset.closeReport)));
    }

    function bindModalActions() {
        mount.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', closeModal));
        mount.querySelector('[data-item-form]')?.addEventListener('submit', saveItem);
        mount.querySelector('[data-delete-item]')?.addEventListener('click', deleteItem);
        mount.querySelector('[data-new-note]')?.addEventListener('submit', createNote);
        mount.querySelector('[data-report-form]')?.addEventListener('submit', submitReport);
    }

    async function submitLogin(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        await run(async () => {
            const result = await api('/auth/login', { method: 'POST', body: { email: data.email, password: data.password } });
            persistToken(result.token, data.remember === 'on');
            history.pushState({}, '', '/app');
            await loadSignedIn();
        });
    }

    async function submitRegister(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        await run(async () => {
            const result = await api('/auth/register', { method: 'POST', body: { ...data, billing_interval: 'monthly' } });
            persistToken(result.token, true);
            history.pushState({}, '', `/subscribe?plan=${encodeURIComponent(data.plan || 'premium')}`);
            await loadSignedIn();
        });
    }

    function openItem(kind, item = null) {
        state.modal = { kind, item };
        render();
    }

    function closeModal() {
        state.modal = null;
        render();
    }

    async function saveItem(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const kind = form.dataset.kind;
        const id = form.dataset.id;
        const data = Object.fromEntries(new FormData(form));
        const body = { title: data.title };
        if (kind === 'task') Object.assign(body, { due_at: fromLocalInput(data.when), status: state.modal?.item?.status || 'open', type: 'todo', description: data.description || null });
        if (kind === 'reminder') Object.assign(body, { remind_at: fromLocalInput(data.when), status: state.modal?.item?.status || 'scheduled', notes: data.description || null });
        if (kind === 'event') Object.assign(body, { starts_at: fromLocalInput(data.when), ends_at: fromLocalInput(data.ends), status: data.status || 'scheduled', all_day: false, location: data.location || null, description: data.description || null });
        await run(async () => {
            const resource = resourceFor(kind);
            await api(`${workspacePath(`/${resource}${id ? `/${id}` : ''}`)}`, { method: id ? 'PATCH' : 'POST', body });
            state.modal = null;
            await refreshResources();
        });
    }

    async function deleteItem() {
        const kind = state.modal?.kind;
        const id = state.modal?.item?.id;
        if (!kind || !id || !confirm('Delete this item?')) return;
        await run(async () => { await api(`/${resourceFor(kind)}/${id}`, { method: 'DELETE' }); state.modal = null; await refreshResources(); });
    }

    async function toggleItem(value) {
        const [kind, id] = value.split(':');
        const item = findItem(kind, id);
        const completed = item?.status === 'completed';
        await run(async () => { await api(`/${resourceFor(kind)}/${id}`, { method: 'PATCH', body: { status: completed ? (kind === 'task' ? 'open' : 'scheduled') : 'completed' } }); await refreshResources(); });
    }

    async function refreshResources() {
        const [tasks, past, reminders, calendar, folders, notes] = await Promise.all([api(workspacePath('/tasks')), api(workspacePath('/tasks/past')).catch(() => []), api(workspacePath('/reminders')), api(workspacePath('/calendar-events?skip_google_sync=1&skip_outlook_sync=1')), api(workspacePath('/note-folders')).catch(() => []), api(workspacePath('/notes')).catch(() => [])]);
        state.tasks = mergeById(tasks, past); state.reminders = list(reminders); state.calendar = list(calendar); state.noteFolders = list(folders); state.notes = list(notes); render();
    }

    async function createNote(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget));
        await run(async () => { const note = await api(workspacePath('/notes'), { method: 'POST', body: data }); state.selectedNoteId = String(note.id); state.modal = null; await refreshResources(); });
    }

    async function saveNote(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form));
        await run(async () => { await api(`/notes/${form.dataset.noteId}`, { method: 'PATCH', body: data }); await refreshResources(); });
    }

    async function deleteNote(event) {
        const id = event.currentTarget.dataset.deleteNote;
        if (!confirm('Delete this note?')) return;
        await run(async () => { await api(`/notes/${id}`, { method: 'DELETE' }); state.selectedNoteId = ''; await refreshResources(); });
    }

    async function saveProfile(event) {
        event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget));
        await run(async () => { state.user = { ...state.user, ...(await api('/auth/me', { method: 'PATCH', body: data })) }; state.notice = 'Profile saved.'; render(); });
    }

    async function saveTheme(event) {
        event.preventDefault(); const data = Object.fromEntries(new FormData(event.currentTarget));
        await run(async () => { state.user = { ...state.user, ...(await api('/auth/me', { method: 'PATCH', body: data })) }; state.notice = 'Appearance updated.'; render(); });
    }

    async function switchWorkspace(event) {
        await run(async () => { state.user = { ...state.user, ...(await api('/workspaces/default', { method: 'PATCH', body: { workspace_id: Number(event.target.value) } })) }; await loadSignedIn(); });
    }

    async function providerAction(value) {
        const [provider, action] = value.split(':');
        await run(async () => {
            if (action === 'connect') {
                const result = await api(`/${provider}-calendar/auth-url`, { method: 'POST' });
                const url = result?.url || result?.auth_url;
                if (url) location.href = url;
                return;
            }
            await api(action === 'disconnect' ? `/${provider}-calendar` : `/${provider}-calendar/${action}`, { method: action === 'disconnect' ? 'DELETE' : 'POST' });
            await loadSignedIn();
        });
    }

    async function submitReport(event) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        data.set('page_url', location.href);
        await run(async () => { await api('/issue-reports', { method: 'POST', body: data }); state.modal = null; state.notice = 'Issue report sent. Thank you.'; render(); });
    }

    async function loadAdmin() {
        if (!state.user?.is_admin) return;
        await run(async () => {
            [state.issueReports, state.planLimits, state.coupons] = await Promise.all([api('/admin/issue-reports/summary'), api('/admin/plan-limits'), api('/admin/coupon-codes')]);
            render();
        }, false);
    }

    async function closeReport(id) {
        await run(async () => { await api(`/admin/issue-reports/${id}`, { method: 'PATCH', body: { status: 'closed' } }); await loadAdmin(); });
    }

    async function exportData() {
        await run(async () => {
            const data = await api('/account/export');
            const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a'); link.href = url; link.download = 'heybean-export.json'; link.click(); URL.revokeObjectURL(url);
        });
    }

    async function deleteAccount() {
        if (!confirm('Permanently delete your account and all of its data?')) return;
        await run(async () => { await api('/account', { method: 'DELETE' }); clearToken(); state.phase = 'signedOut'; render(); });
    }

    async function logout() {
        await api('/auth/logout', { method: 'POST' }).catch(() => null);
        clearToken(); state.phase = 'signedOut'; state.user = null; history.pushState({}, '', '/login'); render();
    }

    async function run(action, rerender = true) {
        if (state.busy) return;
        state.busy = true; state.error = ''; if (rerender) render();
        try { await action(); } catch (error) { state.error = friendlyError(error); }
        state.busy = false; if (rerender) render();
    }

    function persistToken(token, remember) {
        state.token = token; state.remember = remember;
        sessionStorage.removeItem(tokenKey); localStorage.removeItem(tokenKey);
        (remember ? localStorage : sessionStorage).setItem(tokenKey, token);
        localStorage.setItem(rememberKey, remember ? 'true' : 'false');
    }

    function clearToken() {
        state.token = ''; sessionStorage.removeItem(tokenKey); localStorage.removeItem(tokenKey);
    }

    function applyTheme() {
        const theme = appThemesByKey.get(state.user?.theme || 'green') || appThemes[0];
        const mode = state.user?.theme_mode || 'auto';
        const dark = mode === 'dark' || (mode === 'auto' && systemDarkScheme?.matches);
        document.documentElement.style.setProperty('--hb-accent', theme.accent);
        document.documentElement.dataset.hbTheme = theme.key;
        document.documentElement.dataset.hbMode = dark ? 'dark' : 'light';
    }

    function findItem(kind, id) {
        const source = kind === 'task' ? state.tasks : kind === 'reminder' ? state.reminders : state.calendar;
        return source.find((item) => String(item.id) === String(id));
    }

    function resourceFor(kind) { return kind === 'event' ? 'calendar-events' : `${kind}s`; }
    function list(value) { return Array.isArray(value) ? value : []; }
    function mergeById(...values) { return [...new Map(values.flatMap(list).map((item) => [String(item.id), item])).values()]; }
    function emptyMarkup(text) { return `<div class="hb-empty">${escapeHtml(text)}</div>`; }
    function statusMarkup() { return `${state.error ? `<div class="hb-error" role="alert">${escapeHtml(state.error)}</div>` : ''}${state.notice ? `<div class="hb-notice" role="status">${escapeHtml(state.notice)}</div>` : ''}`; }
    function field(label, name, type, value = '', attributes = '') { return `<label class="hb-label">${escapeHtml(label)}<input class="hb-input" type="${type}" name="${name}" value="${escapeAttr(value)}" ${attributes}></label>`; }
    function dateValue(value) { const date = new Date(value || 0); return Number.isNaN(date.getTime()) ? 0 : date.getTime(); }
    function formatDate(value) { const date = new Date(value); return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date); }
    function toLocalInput(value) { if (!value) return ''; const date = new Date(value); if (Number.isNaN(date.getTime())) return ''; const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000); return local.toISOString().slice(0, 16); }
    function fromLocalInput(value) { return value ? new Date(value).toISOString() : null; }
    function friendlyError(error) { return error?.message || 'Something went wrong. Please try again.'; }
    function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character])); }
    function escapeAttr(value) { return escapeHtml(value).replace(/`/g, '&#096;'); }
}
