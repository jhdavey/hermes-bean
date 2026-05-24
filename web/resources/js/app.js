const mount = document.getElementById('heybean-web-app');

if (mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';

    const icons = {
        add: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="3"/></svg>',
        tasks: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 2 2 4-5"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>',
        reminders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06A2 2 0 1 1 7.03 3.8l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.15.38.36.7.6 1 .3.25.68.4 1.1.4H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51.6Z"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>',
        stop: '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"/></svg>',
        user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        tune: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M2 14h4M10 8h4M18 16h4"/></svg>',
        activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
    };

    const state = {
        authMode: initialMode,
        token: readToken(),
        remember: localStorage.getItem(rememberKey) === 'true',
        phase: 'loading',
        selected: 'today',
        selectedDay: dateOnly(new Date()),
        calendarVisibleDayCount: calendarVisibleDayCount(),
        showMonth: false,
        user: null,
        summary: null,
        tasks: [],
        reminders: [],
        calendar: [],
        categories: [],
        approvals: [],
        blockers: [],
        activity: [],
        googleStatus: null,
        googleAuthUrl: '',
        messages: [],
        session: null,
        chatRunState: 'Ready',
        voiceListening: false,
        voiceRecognition: null,
        voiceDraft: '',
        taskFilter: 'active',
        reminderFilter: 'pending',
        busy: false,
        error: '',
        notice: '',
        modal: null,
    };

    boot();
    bindResponsiveCalendar();

    async function boot() {
        if (state.token) {
            await loadSignedIn();
        } else {
            state.phase = 'signedOut';
            render();
        }
    }

    function bindResponsiveCalendar() {
        let pending = 0;
        window.addEventListener('resize', () => {
            window.clearTimeout(pending);
            pending = window.setTimeout(() => {
                const count = calendarVisibleDayCount();
                if (count === state.calendarVisibleDayCount) return;
                state.calendarVisibleDayCount = count;
                if (state.phase === 'signedIn' && state.selected === 'today' && !state.showMonth) {
                    render();
                }
            }, 120);
        });
    }

    function readToken() {
        return localStorage.getItem(tokenKey) || sessionStorage.getItem(tokenKey) || '';
    }

    function persistToken(token, remember) {
        localStorage.removeItem(tokenKey);
        sessionStorage.removeItem(tokenKey);
        localStorage.setItem(rememberKey, remember ? 'true' : 'false');
        (remember ? localStorage : sessionStorage).setItem(tokenKey, token);
        state.token = token;
        state.remember = remember;
    }

    function clearToken() {
        localStorage.removeItem(tokenKey);
        sessionStorage.removeItem(tokenKey);
        state.token = '';
    }

    async function api(path, options = {}) {
        const headers = {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
            ...(options.headers || {}),
        };
        const response = await fetch(`/api${path}`, {
            method: options.method || 'GET',
            headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        if (response.status === 204) return null;
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const details = payload.errors
                ? Object.values(payload.errors).flat().join(' ')
                : payload.message;
            throw new Error(details || 'Something went wrong.');
        }
        return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
    }

    async function loadSignedIn() {
        state.phase = 'loading';
        render();
        try {
            const [user, summary, tasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api('/auth/me'),
                api('/today'),
                api('/tasks'),
                api('/reminders'),
                api('/calendar-events'),
                api('/event-categories'),
                api('/google-calendar/status').catch(() => null),
            ]);
            state.user = mergeUser(user, summary?.user, summary);
            state.summary = summary;
            state.tasks = normalizeList(tasks.length ? tasks : summary?.tasks);
            state.reminders = normalizeList(reminders.length ? reminders : summary?.reminders);
            state.calendar = normalizeList(calendar.length ? calendar : summary?.calendar_events);
            state.categories = normalizeList(categories);
            state.approvals = normalizeList(summary?.approvals);
            state.blockers = normalizeList(summary?.blockers);
            state.activity = normalizeList(summary?.activity_events);
            state.googleStatus = googleStatus;
            state.session = summary?.session || null;
            state.phase = 'signedIn';
            state.error = '';
            if (state.session?.id) {
                resumeSession(state.session.id);
            }
        } catch (error) {
            clearToken();
            state.phase = 'signedOut';
            state.error = friendlyError(error, 'load your account');
        }
        render();
    }

    function mergeUser(...parts) {
        return Object.assign({}, ...parts.filter(Boolean));
    }

    function normalizeList(value) {
        return Array.isArray(value) ? value : [];
    }

    function render() {
        mount.innerHTML = state.phase === 'signedIn' ? signedInMarkup() : signedOutMarkup();
        bindCommonActions();
        if (state.phase === 'signedIn') bindSignedInActions();
        if (state.modal) {
            mount.insertAdjacentHTML('beforeend', modalMarkup(state.modal));
            bindModalActions();
        }
    }

    function signedOutMarkup() {
        if (state.phase === 'loading') {
            return `<div class="hb-loading-screen"><div class="hb-spinner"></div><p>Loading HeyBean…</p></div>`;
        }
        const register = state.authMode === 'register';
        const forgot = state.authMode === 'forgot';
        return `
            <div class="hb-app">
                <main class="hb-auth-wrap">
                    <section class="hb-card hb-auth-card">
                        <div class="hb-auth-title">
                            ${register ? `<span class="hb-section-icon">${icons.user}</span>` : `<img src="${escapeAttr(logoUrl)}" alt="">`}
                            <div>
                                <h1>${forgot ? 'Reset password' : register ? 'Create your Hermes Bean account' : 'Login'}</h1>
                                ${register ? '<p>Create your account with your email and a secure 12+ character password</p>' : ''}
                            </div>
                        </div>
                        ${state.error ? `<div class="hb-error">${escapeHtml(state.error)}</div>` : ''}
                        ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                        ${forgot ? forgotFormMarkup() : authFormMarkup(register)}
                        <div class="hb-auth-links">
                            <a class="hb-button-ghost" href="/privacy">Privacy</a>
                            <a class="hb-button-ghost" href="/terms">Terms</a>
                            <a class="hb-button-ghost" href="/support">Support</a>
                        </div>
                    </section>
                </main>
            </div>`;
    }

    function authFormMarkup(register) {
        return `
            <form class="hb-form" data-action="${register ? 'register' : 'login'}">
                ${register ? labelInput('Name', 'name', 'text', '', 'required autocomplete="name"') : ''}
                ${labelInput('Email', 'email', 'email', '', 'required autocomplete="email"')}
                ${labelInput('Password', 'password', 'password', '', `required autocomplete="${register ? 'new-password' : 'current-password'}" minlength="${register ? '12' : '1'}"`)}
                ${register ? '<p class="hb-item-meta">Minimum 12 characters</p>' : `
                    <label class="hb-checkbox-row"><input type="checkbox" name="remember" ${state.remember ? 'checked' : ''}> Remember me</label>
                `}
                <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? (register ? 'Creating account…' : 'Signing in…') : (register ? 'Create account' : 'Sign in')}</button>
                <div class="hb-link-row">
                    <button class="hb-button-ghost" type="button" data-auth-mode="${register ? 'login' : 'register'}">${register ? 'Already have an account? Sign in' : 'Create an account'}</button>
                    <button class="hb-button-ghost" type="button" data-auth-mode="forgot">Forgot password?</button>
                </div>
            </form>`;
    }

    function forgotFormMarkup() {
        return `
            <form class="hb-form" data-action="forgot">
                <p class="hb-item-meta">Enter the email used for your account and we’ll send a password reset link.</p>
                ${labelInput('Account email', 'email', 'email', '', 'required autocomplete="email"')}
                <button class="hb-button" type="submit" ${state.busy ? 'disabled' : ''}>${state.busy ? 'Sending…' : 'Send reset link'}</button>
                <div class="hb-link-row">
                    <button class="hb-button-ghost" type="button" data-auth-mode="login">Back to login</button>
                    <button class="hb-button-ghost" type="button" data-auth-mode="register">Create an account</button>
                </div>
            </form>`;
    }

    function signedInMarkup() {
        const critical = criticalItems().length;
        const addTitle = state.selected === 'tasks' ? 'Add task' : state.selected === 'reminders' ? 'Add reminder' : 'Create event';
        const showAdd = ['today', 'tasks', 'reminders'].includes(state.selected);
        return `
            <div class="hb-app">
                <header class="hb-topbar">
                    <button class="hb-header-pill hb-month-pill" data-calendar-month type="button">‹ ${monthLabel(new Date())} ${icons.calendar}</button>
                    <span class="hb-spacer"></span>
                    <button class="hb-header-pill" data-today type="button">${dayLabel(new Date())}</button>
                    <button class="hb-critical" type="button" title="${critical} critical items">${critical}</button>
                    ${showAdd ? `<button class="hb-icon-button" type="button" data-open-create="${state.selected === 'today' ? 'event' : state.selected.slice(0, -1)}" aria-label="${escapeAttr(addTitle)}">${icons.add}</button>` : ''}
                    ${topProfileMenuMarkup()}
                </header>
                <main class="hb-main ${state.selected === 'bean' ? 'hb-main-chat' : ''}">
                    ${state.selected === 'bean' ? chatMarkup() : appPanelMarkup()}
                </main>
                ${state.selected === 'bean' ? '' : approvalSheetMarkup()}
                ${bottomMenuMarkup()}
            </div>`;
    }

    function appPanelMarkup() {
        if (state.selected === 'settings') {
            return `<div class="hb-shell">${settingsMarkup()}</div>`;
        }
        const primary = state.selected === 'today'
            ? todayMarkup()
            : state.selected === 'tasks'
                ? tasksMarkup()
                : remindersMarkup();
        return `
            <div class="hb-shell hb-dashboard-grid">
                <div class="hb-primary-column">${primary}</div>
                <aside class="hb-side-column">
                    ${todayTasksMarkup()}
                </aside>
            </div>`;
    }

    function todayMarkup() {
        const selected = parseLocalDate(state.selectedDay);
        const visibleDays = visibleCalendarDays(selected);
        const events = eventsForDays(visibleDays);
        return `
            <section class="hb-card hb-card-pad">
                ${sectionTitle(icons.calendar, 'Calendar', `${events.length} events across ${calendarRangeLabel(visibleDays)}`)}
                <div class="hb-calendar">
                    ${state.showMonth ? monthGridMarkup(selected) : `<div class="hb-day-strip">
                        ${weekDaysForWindow(selected, visibleDays).map((day) => `
                            <button class="hb-day ${visibleDays.some((visibleDay) => sameDate(day, visibleDay)) ? 'hb-day-active' : ''} ${sameDate(day, selected) ? 'hb-day-anchor' : ''}" type="button" data-select-day="${dateOnly(day)}" aria-pressed="${visibleDays.some((visibleDay) => sameDate(day, visibleDay))}">
                                <strong>${weekdayShort(day)}</strong>
                                <span>${day.getDate()} · ${eventsForDay(day).length} events</span>
                            </button>
                        `).join('')}
                    </div>`}
                    ${state.showMonth ? '' : timelineMarkup(visibleDays)}
                </div>
            </section>`;
    }

    function tasksMarkup() {
        const completed = state.taskFilter === 'done';
        const items = state.tasks.filter((task) => taskCompleted(task) === completed);
        return `
            <section class="hb-card hb-card-pad">
                ${sectionTitle(icons.tasks, 'Tasks', completed ? 'Completed tasks' : 'Active tasks')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-task-filter="active" aria-pressed="${!completed}">Active</button>
                    <button class="hb-chip" type="button" data-task-filter="done" aria-pressed="${completed}">Done</button>
                </div>
                ${itemListMarkup(items, 'task', completed ? 'No completed tasks' : 'No active tasks')}
            </section>`;
    }

    function remindersMarkup() {
        const completed = state.reminderFilter === 'completed';
        const items = state.reminders.filter((reminder) => reminderCompleted(reminder) === completed);
        return `
            <section class="hb-card hb-card-pad">
                ${sectionTitle(icons.reminders, 'Reminders', completed ? 'Completed reminders' : 'Pending reminders')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-reminder-filter="pending" aria-pressed="${!completed}">Pending</button>
                    <button class="hb-chip" type="button" data-reminder-filter="completed" aria-pressed="${completed}">Completed</button>
                </div>
                ${itemListMarkup(items, 'reminder', completed ? 'No completed reminders' : 'No pending reminders')}
            </section>`;
    }

    function chatMarkup() {
        const working = state.busy && state.chatRunState !== 'Ready';
        const messages = state.messages.length ? state.messages : [
            { id: 'intro', role: 'assistant', content: 'Hey, I’m Bean. Tell me what you need planned, captured, moved, or remembered.' },
        ];
        return `
            <section class="hb-chat">
                <div class="hb-chat-top">
                    <span class="hb-run-pill ${working ? 'hb-run-pill-working' : ''}">${escapeHtml(state.chatRunState)}</span>
                    <span class="hb-spacer"></span>
                    <button class="hb-button-ghost" type="button" data-refresh-activity>${icons.activity} Activity</button>
                    <button class="hb-button-ghost" type="button" data-new-session>${icons.add} /new</button>
                </div>
                <div class="hb-chat-messages" id="hb-chat-messages">
                    ${messages.map((message, index) => messageMarkup(message, index, messages)).join('')}
                    ${working ? messageMarkup({ id: 'busy', role: 'assistant', content: state.chatRunState || 'Working…', progress: true }) : ''}
                </div>
                <form class="hb-chat-dock ${state.voiceListening ? 'hb-chat-dock-listening' : ''}" data-action="chat">
                    <textarea name="message" placeholder="${state.voiceListening ? 'Listening… speak now or type to correct the transcript' : 'Message Bean…'}" rows="1" ${state.busy ? 'disabled' : ''}>${escapeHtml(state.voiceDraft)}</textarea>
                    <button class="hb-button-secondary hb-voice-button" type="button" data-voice-toggle aria-label="Voice input">${state.voiceListening ? '●' : '🎙'}</button>
                    <button class="${state.busy ? 'hb-button-danger' : 'hb-button'}" type="${state.busy ? 'button' : 'submit'}" ${state.busy ? 'data-stop-chat' : ''} aria-label="${state.busy ? 'Stop' : 'Send'}">${state.busy ? icons.stop : icons.send}</button>
                </form>
            </section>`;
    }

    function settingsMarkup() {
        const user = state.user || {};
        const prefs = user.notification_preferences || {};
        const profile = user.active_workspace_agent_profile || user.agent_profile || {};
        const priorities = Array.isArray(profile.onboarding_priorities) ? profile.onboarding_priorities : [];
        return `
            <section class="hb-card hb-card-pad hb-settings-grid">
                ${sectionTitle(icons.settings, 'Settings', 'Focused Hermes Bean preferences')}
                ${state.error ? `<div class="hb-error">${escapeHtml(state.error)}</div>` : ''}
                ${state.notice ? `<div class="hb-success">${escapeHtml(state.notice)}</div>` : ''}
                <div class="hb-compact-item">
                    <span class="hb-compact-icon">${icons.user}</span>
                    <div><strong>${escapeHtml(user.name || 'Account')}</strong><small>${escapeHtml(user.email || '')}</small></div>
                    <button class="hb-button-ghost" type="button" data-open-profile>Email</button>
                </div>
                <div class="hb-compact-item">
                    <span class="hb-compact-icon">${icons.tune}</span>
                    <div><strong>Bean preferences</strong><small>${escapeHtml(personalityLabel(profile.agent_personality || profile.personality_type))} • ${escapeHtml(priorities.length ? priorities.join(', ') : 'No priorities selected yet')}</small></div>
                    <button class="hb-button-ghost" type="button" data-open-agent>Update</button>
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    <strong>Notification preferences</strong>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_push" ${prefs.reminder_push !== false ? 'checked' : ''}> Reminder push notifications</label>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_email" ${prefs.reminder_email === true ? 'checked' : ''}> Reminder emails</label>
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    <strong>Workspaces</strong>
                    <div class="hb-list" style="margin-top:10px">${workspaces().map((workspace) => `
                        <div class="hb-workspace-block">
                            <div class="hb-compact-item">
                                <span class="hb-compact-icon">${icons.calendar}</span>
                                <div><strong>${escapeHtml(workspace.name || 'Workspace')}</strong><small>${escapeHtml(workspace.type || workspace.kind || 'workspace')} ${workspace.active ? '· Active' : ''}</small></div>
                                <div class="hb-row-actions">
                                    <button class="hb-button-ghost" type="button" data-set-workspace="${escapeAttr(workspace.id)}">Set default</button>
                                    ${workspace.type === 'personal' || workspace.kind === 'personal' ? '' : `<button class="hb-button-ghost" type="button" data-rename-workspace="${escapeAttr(workspace.id)}">Rename</button><button class="hb-button-ghost" type="button" data-invite-workspace="${escapeAttr(workspace.id)}">Invite</button><button class="hb-button-ghost" type="button" data-leave-workspace="${escapeAttr(workspace.id)}">Leave</button>`}
                                </div>
                            </div>
                            ${workspaceMembersMarkup(workspace)}
                        </div>
                    `).join('') || '<div class="hb-empty">No workspaces loaded</div>'}</div>
                    <div class="hb-account-actions">
                        <button class="hb-button-secondary" type="button" data-create-workspace>Create household</button>
                        <button class="hb-button-secondary" type="button" data-accept-workspace>Accept invite</button>
                    </div>
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    ${googleCalendarMarkup()}
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    <strong>Calendar preferences</strong>
                    <div class="hb-field-row" style="margin-top:10px">
                        ${labelInput('Start hour', 'startHour', 'number', localStorage.getItem('heybean.calendar.startHour') || '6', 'min="0" max="23" data-calendar-pref="startHour"')}
                        ${labelInput('End hour', 'endHour', 'number', localStorage.getItem('heybean.calendar.endHour') || '22', 'min="1" max="24" data-calendar-pref="endHour"')}
                    </div>
                </div>
                <div class="hb-card hb-card-pad">
                    <strong>Account controls</strong>
                    <div class="hb-account-actions">
                        <a class="hb-button-secondary" href="/privacy">Privacy</a>
                        <a class="hb-button-secondary" href="/terms">Terms</a>
                        <a class="hb-button-secondary" href="/support">Support</a>
                        <button class="hb-button-secondary" type="button" data-export-account>Export data</button>
                        <button class="hb-button-secondary" type="button" data-logout>Sign out</button>
                        <button class="hb-button-danger" type="button" data-delete-account>Delete account</button>
                    </div>
                </div>
            </section>`;
    }

    function topProfileMenuMarkup() {
        const user = state.user || {};
        const name = user.name || 'Account';
        const email = user.email || '';
        return `
            <details class="hb-profile-menu">
                <summary class="hb-profile-trigger" aria-label="Account menu">
                    <span class="hb-avatar" aria-hidden="true">${escapeHtml(userInitials(name, email))}</span>
                    <span class="hb-profile-copy"><strong>${escapeHtml(name)}</strong><small>${escapeHtml(email)}</small></span>
                </summary>
                <div class="hb-profile-popover">
                    <button class="hb-profile-action" type="button" data-nav="settings">${icons.settings}<span>Settings</span></button>
                    <button class="hb-profile-action" type="button" data-logout>${icons.user}<span>Sign out</span></button>
                </div>
            </details>`;
    }

    function todayTasksMarkup() {
        const today = new Date();
        const tasks = activeTasks().filter((task) => isSameDay(task.due_at || task.dueAt, today));
        return `
            <section class="hb-card hb-card-pad hb-today-tasks-card">
                ${sectionTitle(icons.tasks, 'Tasks for today', `${tasks.length} tasks`)}
                ${itemListMarkup(tasks, 'task', 'No tasks scheduled for today')}
            </section>`;
    }

    function workspaceMembersMarkup(workspace) {
        const memberships = normalizeList(workspace.memberships || workspace.members).filter((membership) => !['removed', 'left'].includes(String(membership.status || '').toLowerCase()));
        if (!memberships.length) return '';
        return `
            <div class="hb-member-list">
                ${memberships.map((membership) => {
                    const memberUser = membership.user || {};
                    const title = memberUser.name || membership.invited_email || membership.invitedEmail || 'Invited member';
                    const subtitle = memberUser.email || membership.invited_email || membership.status || '';
                    return `
                        <div class="hb-member-row">
                            <div><strong>${escapeHtml(title)}</strong><small>${escapeHtml(subtitle)}</small></div>
                            <select class="hb-select hb-role-select" data-workspace-id="${escapeAttr(workspace.id)}" data-member-role="${escapeAttr(membership.id)}">
                                <option value="member" ${membership.role !== 'owner' ? 'selected' : ''}>Member</option>
                                <option value="owner" ${membership.role === 'owner' ? 'selected' : ''}>Owner</option>
                            </select>
                            <button class="hb-button-ghost" type="button" data-workspace-id="${escapeAttr(workspace.id)}" data-remove-member="${escapeAttr(membership.id)}">Remove</button>
                        </div>`;
                }).join('')}
            </div>`;
    }

    function googleCalendarMarkup() {
        const status = state.googleStatus;
        const connected = status?.connected === true;
        const calendars = normalizeList(status?.calendars);
        const selected = new Set(normalizeList(status?.selected_calendar_ids));
        return `
            <strong>Calendar sync</strong>
            <p class="hb-item-meta">${connected ? `Connected${status?.last_synced_at ? ` · last sync ${formatDateTime(status.last_synced_at)}` : ''}` : 'Connect your calendar to import events into HeyBean.'}</p>
            ${status?.last_error ? `<div class="hb-error">${escapeHtml(status.last_error)}</div>` : ''}
            ${calendars.length ? `<div class="hb-list hb-google-list">${calendars.map((calendar) => `
                <label class="hb-switch-row"><input type="checkbox" data-google-calendar value="${escapeAttr(calendar.id)}" ${selected.has(calendar.id) || calendar.selected ? 'checked' : ''}> <span><strong>${escapeHtml(calendar.summary || calendar.name || calendar.id)}</strong><small>${escapeHtml(calendar.access_role || calendar.accessRole || 'reader')}</small></span></label>
            `).join('')}</div>` : ''}
            <div class="hb-account-actions">
                <button class="hb-button-secondary" type="button" data-google-action="connect">${connected ? 'Reconnect' : 'Connect calendar'}</button>
                ${state.googleAuthUrl ? `<button class="hb-button-secondary" type="button" data-google-action="copy">Copy auth link</button><button class="hb-button-secondary" type="button" data-google-action="check">Check connection</button>` : ''}
                <button class="hb-button-secondary" type="button" data-google-action="sync" ${connected ? '' : 'disabled'}>Sync</button>
                <button class="hb-button-ghost" type="button" data-google-action="disconnect" ${connected ? '' : 'disabled'}>Disconnect</button>
            </div>`;
    }

    function bottomMenuMarkup() {
        const nav = [
            ['today', 'Calendar', icons.calendar],
            ['tasks', 'Tasks', icons.tasks],
            ['reminders', 'Reminders', icons.reminders],
            ['settings', 'Settings', icons.settings],
        ];
        return `
            <nav class="hb-bottom-menu" aria-label="App navigation">
                <div class="hb-bottom-bar">
                    ${nav.slice(0, 2).map(navButton).join('')}
                    <span></span>
                    ${nav.slice(2).map(navButton).join('')}
                </div>
                <button class="hb-bean-button ${state.selected === 'bean' ? 'hb-bean-button-active' : ''}" type="button" data-nav="bean" aria-label="Bean chat"><img src="${escapeAttr(logoUrl)}" alt=""></button>
            </nav>`;
    }

    function navButton([key, label, icon]) {
        return `<button class="hb-nav-item ${state.selected === key ? 'hb-nav-item-active' : ''}" type="button" data-nav="${key}">${icon}<span>${label}</span></button>`;
    }

    function timelineMarkup(days) {
        const startHour = Number(localStorage.getItem('heybean.calendar.startHour') || 6);
        const endHour = Number(localStorage.getItem('heybean.calendar.endHour') || 22);
        const hours = Array.from({ length: Math.max(1, endHour - startHour + 1) }, (_, index) => startHour + index);
        const minDayWidth = days.length >= 7 ? 150 : days.length >= 4 ? 180 : 220;
        return `
            <div class="hb-timeline hb-timeline-multi-day" style="--hb-hour-count:${hours.length};--hb-day-count:${days.length};--hb-day-min-width:${minDayWidth}px;--hb-timeline-min-width:${74 + (days.length * minDayWidth)}px" aria-label="${escapeAttr(calendarRangeLabel(days))} timeline">
                <div class="hb-timeline-head">
                    <div class="hb-timeline-hour"></div>
                    ${days.map((day) => `<div class="hb-timeline-day-head"><strong>${escapeHtml(timelineDayHeaderLabel(day))}</strong><span>${escapeHtml(monthDayLabel(day))}</span></div>`).join('')}
                </div>
                <div class="hb-timeline-body">
                    <div class="hb-timeline-hour-grid" aria-hidden="true">
                        ${hours.map((hour) => `
                            <div class="hb-timeline-row">
                                <div class="hb-timeline-hour">${hourLabel(hour)}</div>
                                ${days.map(() => '<div class="hb-timeline-slot"></div>').join('')}
                            </div>
                        `).join('')}
                    </div>
                    <div class="hb-timeline-events-grid">
                        <div class="hb-timeline-gutter" aria-hidden="true"></div>
                        ${days.map((day) => `<div class="hb-timeline-day-column">${eventsForDay(day).map((event) => timedEventMarkup(event, day, startHour, endHour)).join('')}</div>`).join('')}
                    </div>
                </div>
            </div>`;
    }

    function monthGridMarkup(selected) {
        const first = new Date(selected.getFullYear(), selected.getMonth(), 1);
        const leading = first.getDay();
        const daysInMonth = new Date(selected.getFullYear(), selected.getMonth() + 1, 0).getDate();
        const totalCells = Math.ceil((leading + daysInMonth) / 7) * 7;
        return `
            <div class="hb-month-grid">
                ${Array.from({ length: 7 }, (_, index) => `<div class="hb-month-weekday">${weekdayShort(new Date(2026, 1, index + 1))}</div>`).join('')}
                ${Array.from({ length: totalCells }, (_, index) => {
                    const dayNumber = index - leading + 1;
                    if (dayNumber < 1 || dayNumber > daysInMonth) return '<div class="hb-month-cell hb-month-cell-empty"></div>';
                    const day = new Date(selected.getFullYear(), selected.getMonth(), dayNumber);
                    const count = eventsForDay(day).length;
                    return `<button class="hb-month-cell ${sameDate(day, selected) ? 'hb-month-cell-active' : ''}" type="button" data-select-day="${dateOnly(day)}"><strong>${dayNumber}</strong><span>${count ? `${count} event${count === 1 ? '' : 's'}` : ''}</span></button>`;
                }).join('')}
            </div>`;
    }

    function approvalSheetMarkup() {
        const approval = pendingApproval();
        if (!approval) return '';
        return `
            <section class="hb-approval-sheet" role="dialog" aria-modal="true" aria-label="Approval required">
                <div class="hb-approval-handle"></div>
                ${sectionTitle(icons.settings, 'I need approval', "Approve or deny Bean's next action")}
                <div class="hb-approval-action">${escapeHtml(approvalDescription(approval))}</div>
                <textarea class="hb-textarea hb-approval-change" placeholder="Tell Bean what to do instead…"></textarea>
                <div class="hb-modal-actions">
                    <button class="hb-button-ghost" type="button" data-approval-deny="${approval.id}">Deny</button>
                    <button class="hb-button-secondary" type="button" data-approval-change="${approval.id}">Send change</button>
                    <button class="hb-button-secondary" type="button" data-approval-always="${approval.id}">Always approve</button>
                    <button class="hb-button" type="button" data-approval-approve="${approval.id}">Approve</button>
                </div>
            </section>`;
    }

    function itemListMarkup(items, kind, emptyText) {
        return `<div class="hb-list">${items.length ? items.map((item) => itemMarkup(item, kind)).join('') : `<div class="hb-empty hb-surface-soft">${emptyText}</div>`}</div>`;
    }

    function itemMarkup(item, kind) {
        const completed = kind === 'task' ? taskCompleted(item) : reminderCompleted(item);
        const color = safeColor(item.color);
        const subtitle = kind === 'task' ? taskSubtitle(item) : reminderSubtitle(item);
        return `
            <article class="hb-item ${completed ? 'hb-item-complete' : ''}" style="${completed ? '' : `background:${hexAlpha(color, .14)};border-color:${hexAlpha(color, .34)}`}">
                <label class="hb-check"><input type="checkbox" data-toggle-${kind}="${item.id}" ${completed ? 'checked' : ''}></label>
                <button class="hb-item-main" type="button" data-edit-${kind}="${item.id}">
                    <div class="hb-item-title">${item.is_critical || item.isCritical ? `<span class="hb-star" style="color:${escapeAttr(color)}">★</span>` : ''}<span>${escapeHtml(item.title || item.name || 'Untitled')}</span></div>
                    <div class="hb-item-meta">${escapeHtml(subtitle)}</div>
                </button>
                <button class="hb-icon-button" type="button" data-edit-${kind}="${item.id}" aria-label="Edit ${kind}">${icons.edit}</button>
            </article>`;
    }

    function eventMarkup(event) {
        const color = safeColor(event.color);
        return `
            <article class="hb-event" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <div class="hb-event-time">${escapeHtml(eventTime(event))}</div>
                <button class="hb-event-title" type="button" data-edit-event="${event.id}">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</button>
            </article>`;
    }

    function timedEventMarkup(event, day, startHour, endHour) {
        const style = timelineEventStyle(event, day, startHour, endHour);
        if (!style) return '';
        const color = safeColor(event.color);
        return `
            <article class="hb-event hb-timed-event" style="${style.css};background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}" data-duration-minutes="${style.minutes}">
                <div class="hb-event-time">${escapeHtml(eventTime(event))}</div>
                <button class="hb-event-title" type="button" data-edit-event="${event.id}">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</button>
            </article>`;
    }

    function messageMarkup(message, index = 0, messages = []) {
        const user = message.role === 'user';
        const metadata = typeof message.metadata === 'object' && message.metadata ? message.metadata : {};
        const model = metadata.model || metadata?.model_route?.model || '';
        const approval = !user && isLatestAssistantMessage(index, messages) ? pendingApprovalForSession() : null;
        return `
            <article class="hb-message ${user ? 'hb-message-user' : ''}">
                <div class="hb-message-head">
                    ${message.progress ? '<span class="hb-spinner" style="width:13px;height:13px;border-width:2px"></span>' : ''}
                    <span>${user ? 'You' : 'Bean'}</span>
                    ${model ? `<span class="hb-message-model">${escapeHtml(model)}</span>` : ''}
                </div>
                <div class="hb-message-body">${escapeHtml(message.content || '')}</div>
                ${approval ? `<div class="hb-message-actions"><button class="hb-button" type="button" data-approval-approve="${approval.id}">Approve</button><button class="hb-button-ghost" type="button" data-approval-deny="${approval.id}">Deny</button></div>` : ''}
            </article>`;
    }

    function sectionTitle(icon, title, subtitle = '') {
        return `
            <div class="hb-section-title">
                <span class="hb-section-icon">${icon}</span>
                <div><h2>${escapeHtml(title)}</h2>${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}</div>
            </div>`;
    }

    function labelInput(label, name, type, value = '', attrs = '') {
        return `<label class="hb-label">${escapeHtml(label)}<input class="hb-input" type="${type}" name="${escapeAttr(name)}" value="${escapeAttr(value)}" placeholder="${escapeAttr(label)}" ${attrs}></label>`;
    }

    function modalMarkup(modal) {
        if (modal.type === 'profile') return profileModalMarkup();
        if (modal.type === 'agent') return agentModalMarkup();
        if (modal.type === 'workspace') return workspaceModalMarkup(modal.mode, modal.workspace);
        if (modal.type === 'categories') return categoriesModalMarkup();
        return itemModalMarkup(modal.type, modal.item);
    }

    function itemModalMarkup(kind, item = null) {
        const editing = Boolean(item);
        const isReminder = kind === 'reminder';
        const isEvent = kind === 'event';
        const when = item ? toDatetimeLocal(item.due_at || item.dueAt || item.remind_at || item.starts_at || item.startsAt) : '';
        const end = item ? toDatetimeLocal(item.ends_at || item.endsAt) : '';
        const workspaceId = item?.workspace_id || item?.workspaceId || currentWorkspaceId();
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="${kind}">
                    ${sectionTitle(isEvent ? icons.calendar : isReminder ? icons.reminders : icons.tasks, `${editing ? 'Edit' : 'New'} ${kind}`, '')}
                    ${labelInput(`${capitalize(kind)} title`, 'title', 'text', item?.title || item?.name || '', 'required')}
                    ${isEvent ? eventDetailFieldsMarkup(item) : ''}
                    ${labelInput(isEvent ? 'Starts at' : isReminder ? 'Remind me at' : 'Due date', 'time', 'datetime-local', when, isReminder || isEvent ? 'required' : '')}
                    ${isEvent ? labelInput('Ends at', 'endsAt', 'datetime-local', end) : ''}
                    <div class="hb-field-row">
                        ${categorySelectMarkup(item)}
                        ${labelInput('Color', 'color', 'color', safeColor(item?.color || categoryColor(item?.category)))}
                    </div>
                    <button class="hb-button-ghost" type="button" data-open-categories>Manage categories</button>
                    ${!isReminder ? `<label class="hb-checkbox-row"><input type="checkbox" name="critical" ${item?.is_critical || item?.isCritical ? 'checked' : ''}> Critical</label>` : ''}
                    ${isEvent ? eventConnectionsMarkup(item, workspaceId, editing) : ''}
                    ${isEvent ? recurrenceFieldsMarkup(item) : ''}
                    ${isReminder ? reminderRecurrenceFieldsMarkup(item) : ''}
                    <div class="hb-modal-actions">
                        ${editing ? `<button class="hb-button-danger" type="button" data-modal-delete="${kind}" data-id="${item.id}">Delete</button>` : ''}
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                        <button class="hb-button" type="submit">${editing ? 'Save' : 'Create'}</button>
                    </div>
                </form>
            </div>`;
    }

    function eventDetailFieldsMarkup(item = null) {
        return `
            <label class="hb-label">Description<textarea class="hb-textarea" name="description" placeholder="Description">${escapeHtml(item?.description || '')}</textarea></label>
            <div class="hb-field-row">
                ${labelInput('Location', 'location', 'text', item?.location || '')}
                <label class="hb-label">Status<select class="hb-select" name="status">
                    ${['confirmed', 'tentative', 'cancelled'].map((status) => `<option value="${status}" ${String(item?.status || 'confirmed') === status ? 'selected' : ''}>${capitalize(status)}</option>`).join('')}
                </select></label>
            </div>`;
    }

    function categorySelectMarkup(item = null) {
        const current = item?.category || '';
        const categories = categoryOptions(current);
        return `
            <label class="hb-label">Category<select class="hb-select" name="category" data-category-select>
                <option value="" data-category-color="">None</option>
                ${categories.map((category) => `<option value="${escapeAttr(category.name)}" data-category-color="${escapeAttr(safeColor(category.color))}" ${category.name === current ? 'selected' : ''}>${escapeHtml(category.name)}</option>`).join('')}
            </select></label>`;
    }

    function eventConnectionsMarkup(item, workspaceId, editing) {
        const linked = new Set(normalizeList(item?.linked_workspace_ids || item?.linkedWorkspaceIds).map(String));
        const sourceWorkspaceId = String(workspaceId || currentWorkspaceId() || '');
        const otherWorkspaces = workspaces().filter((workspace) => String(workspace.id) !== sourceWorkspaceId);
        const sourceWorkspace = workspaces().find((workspace) => String(workspace.id) === sourceWorkspaceId);
        return `
            <div class="hb-surface-soft hb-card-pad hb-event-connections">
                <strong>Connections</strong>
                <label class="hb-label">Workspace
                    <select class="hb-select" name="workspaceId" ${editing ? 'disabled' : ''}>
                        ${workspaces().map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === sourceWorkspaceId ? 'selected' : ''}>${escapeHtml(workspace.name || 'Workspace')}</option>`).join('')}
                    </select>
                </label>
                ${editing ? `<input type="hidden" name="workspaceId" value="${escapeAttr(sourceWorkspaceId)}"><p class="hb-item-meta">Saved in ${escapeHtml(sourceWorkspace?.name || 'this workspace')}.</p>` : ''}
                ${otherWorkspaces.length ? `<div class="hb-label">Connected workspaces
                    <div class="hb-option-list">
                        ${otherWorkspaces.map((workspace) => `<label class="hb-switch-row"><input type="checkbox" name="syncWorkspaceIds" value="${escapeAttr(workspace.id)}" ${linked.has(String(workspace.id)) ? 'checked' : ''}> <span><strong>${escapeHtml(workspace.name || 'Workspace')}</strong><small>${escapeHtml(workspace.type || workspace.kind || 'workspace')}</small></span></label>`).join('')}
                    </div>
                </div>` : '<p class="hb-item-meta">No other workspaces connected to this account.</p>'}
                ${googleEventConnectionMarkup(item, sourceWorkspace)}
            </div>`;
    }

    function googleEventConnectionMarkup(item, workspace) {
        const googleCalendarId = item?.google_calendar_id || item?.googleCalendarId || item?.metadata?.google_calendar_id || item?.metadata?.googleCalendarId || '';
        const googleSummary = item?.metadata?.google_calendar_summary || item?.metadata?.googleCalendarSummary || googleCalendarId;
        const mappings = normalizeList(workspace?.google_calendar_mappings || workspace?.googleCalendarMappings);
        const defaultMapping = mappings.find((mapping) => mapping.is_default_export || mapping.isDefaultExport) || mappings[0];
        if (googleCalendarId) {
            return `<p class="hb-item-meta">Google Calendar: ${escapeHtml(googleSummary || googleCalendarId)}</p>`;
        }
        if (defaultMapping) {
            return `<p class="hb-item-meta">Google export: ${escapeHtml(defaultMapping.google_calendar_id || defaultMapping.googleCalendarId || 'default calendar')}</p>`;
        }
        return state.googleStatus?.connected ? '<p class="hb-item-meta">Google Calendar is connected. Pick workspace calendars in Settings.</p>' : '<p class="hb-item-meta">Google Calendar is not connected.</p>';
    }

    function profileModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="profile">
                    ${sectionTitle(icons.user, 'Account email', '')}
                    ${labelInput('Email', 'email', 'email', state.user?.email || '', 'required')}
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Cancel</button><button class="hb-button" type="submit">Save</button></div>
                </form>
            </div>`;
    }

    function agentModalMarkup() {
        const profile = state.user?.active_workspace_agent_profile || state.user?.agent_profile || {};
        const priorities = new Set(Array.isArray(profile.onboarding_priorities) ? profile.onboarding_priorities : []);
        const personality = profile.agent_personality || profile.personality_type || 'balanced';
        const options = ['balanced', 'coach', 'organizer', 'creative'];
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="agent">
                    ${sectionTitle(icons.tune, 'Edit Bean preferences', 'Review the current settings and save only what you want to change.')}
                    <label class="hb-label">Choose Bean’s personality<select class="hb-select" name="personality">${options.map((option) => `<option value="${option}" ${option === personality ? 'selected' : ''}>${personalityLabel(option)}</option>`).join('')}</select></label>
                    <div class="hb-label">What should Bean prioritize?
                        <div class="hb-tabs">${['Work', 'Family', 'Health', 'Planning', 'Reminders', 'Focus'].map((priority) => `<label class="hb-chip"><input type="checkbox" name="priorities" value="${priority}" ${priorities.has(priority) ? 'checked' : ''}> ${priority}</label>`).join('')}</div>
                    </div>
                    <label class="hb-label">Anything Bean should know?<textarea class="hb-textarea" name="context" placeholder="Example: I work nights, protect family time, and need gentle nudges.">${escapeHtml(profile.onboarding_context || '')}</textarea></label>
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Cancel</button><button class="hb-button" type="submit">Save</button></div>
                </form>
            </div>`;
    }

    function workspaceModalMarkup(mode, workspace = null) {
        const create = mode === 'create';
        const rename = mode === 'rename';
        const invite = mode === 'invite';
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="${create ? 'workspace-create' : rename ? 'workspace-rename' : invite ? 'workspace-invite' : 'workspace-accept'}">
                    ${sectionTitle(icons.calendar, create ? 'Create household' : rename ? 'Rename household' : invite ? `Invite to ${workspace?.name || 'workspace'}` : 'Accept workspace invitation', '')}
                    ${labelInput(create || rename ? 'Household name' : invite ? 'Email' : 'Invitation token or link', create || rename ? 'name' : invite ? 'email' : 'token', invite ? 'email' : 'text', rename ? workspace?.name || '' : '', 'required')}
                    <input type="hidden" name="workspaceId" value="${escapeAttr(workspace?.id || '')}">
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Cancel</button><button class="hb-button" type="submit">${create ? 'Create' : rename ? 'Save' : invite ? 'Invite' : 'Accept'}</button></div>
                </form>
            </div>`;
    }

    function recurrenceFieldsMarkup(item) {
        const recurrence = item?.recurrence || item?.metadata?.recurrence || 'none';
        const days = new Set(normalizeList(item?.metadata?.specific_days || item?.metadata?.specificDays));
        return `
            <label class="hb-label">Event recurrence
                <select class="hb-select" name="recurrence">
                    ${['none', 'daily', 'weekly', 'monthly', 'specific_days', 'interval'].map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
                </select>
            </label>
            <div class="hb-tabs">
                ${['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map((day) => `<label class="hb-chip"><input type="checkbox" name="specificDays" value="${day}" ${days.has(day) ? 'checked' : ''}> ${day.toUpperCase()}</label>`).join('')}
            </div>
            <div class="hb-field-row">
                ${labelInput('Repeat interval', 'interval', 'number', item?.metadata?.interval || '', 'min="1"')}
                <label class="hb-label">Interval unit<select class="hb-select" name="intervalUnit"><option value="days">Days</option><option value="weeks" ${item?.metadata?.interval_unit === 'weeks' ? 'selected' : ''}>Weeks</option><option value="months" ${item?.metadata?.interval_unit === 'months' ? 'selected' : ''}>Months</option></select></label>
            </div>`;
    }

    function reminderRecurrenceFieldsMarkup(item) {
        const recurrence = item?.metadata?.recurrence || 'none';
        return `<label class="hb-label">Reminder repeats<select class="hb-select" name="reminderRecurrence">${['none', 'daily', 'weekly', 'monthly'].map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}</select></label>`;
    }

    function categoriesModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <section class="hb-card hb-modal">
                    ${sectionTitle(icons.tune, 'Categories', 'Create, recolor, or delete item categories.')}
                    <form class="hb-form hb-category-create" data-modal-form="category-create">
                        <div class="hb-field-row">${labelInput('Name', 'name', 'text', '', 'required')}${labelInput('Color', 'color', 'color', '#16A34A')}</div>
                        <button class="hb-button" type="submit">Add category</button>
                    </form>
                    <div class="hb-list hb-category-list">
                        ${state.categories.map((category) => `
                            <form class="hb-compact-item" data-category-row="${category.id}">
                                <span class="hb-color-swatch" style="background:${escapeAttr(safeColor(category.color))}"></span>
                                <input class="hb-input" name="name" value="${escapeAttr(category.name)}">
                                <div class="hb-row-actions">
                                    <input class="hb-input hb-color-input" type="color" name="color" value="${escapeAttr(safeColor(category.color))}">
                                    <button class="hb-button-secondary" type="submit">Save</button>
                                    <button class="hb-button-danger" type="button" data-delete-category="${category.id}">Delete</button>
                                </div>
                            </form>`).join('') || '<div class="hb-empty">No categories yet.</div>'}
                    </div>
                    <div class="hb-modal-actions"><button class="hb-button-secondary" type="button" data-close-modal>Done</button></div>
                </section>
            </div>`;
    }

    function bindCommonActions() {
        mount.querySelectorAll('[data-auth-mode]').forEach((button) => button.addEventListener('click', () => {
            state.authMode = button.dataset.authMode;
            state.error = '';
            state.notice = '';
            history.pushState({}, '', state.authMode === 'register' ? '/register' : state.authMode === 'forgot' ? '/forgot-password' : '/login');
            render();
        }));
        mount.querySelectorAll('form[data-action="login"], form[data-action="register"], form[data-action="forgot"]').forEach((form) => form.addEventListener('submit', submitAuth));
    }

    async function submitAuth(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const action = form.dataset.action;
        const data = Object.fromEntries(new FormData(form).entries());
        state.busy = true;
        state.error = '';
        state.notice = '';
        render();
        try {
            if (action === 'forgot') {
                const result = await api('/auth/forgot-password', { method: 'POST', body: { email: data.email } });
                state.busy = false;
                state.notice = result.message || 'If that email matches a HeyBean account, we sent a password reset link.';
                state.authMode = 'login';
                history.pushState({}, '', '/login');
                render();
                return;
            }
            const payload = action === 'register'
                ? { name: data.name, email: data.email, password: data.password, password_confirmation: data.password }
                : { email: data.email, password: data.password };
            const result = await api(`/auth/${action}`, { method: 'POST', body: payload });
            persistToken(result.token, action === 'login' && data.remember === 'on');
            state.busy = false;
            history.pushState({}, '', '/app');
            await loadSignedIn();
        } catch (error) {
            state.busy = false;
            state.error = friendlyError(error, action === 'register' ? 'create your account' : action === 'forgot' ? 'send a password reset link' : 'sign in');
            render();
        }
    }

    function bindSignedInActions() {
        mount.querySelectorAll('[data-nav]').forEach((button) => button.addEventListener('click', () => {
            state.selected = button.dataset.nav;
            state.error = '';
            state.notice = '';
            render();
            scrollChatToBottom();
        }));
        mount.querySelector('[data-today]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.selectedDay = dateOnly(new Date());
            state.showMonth = false;
            render();
        });
        mount.querySelector('[data-calendar-month]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.showMonth = true;
            render();
        });
        mount.querySelectorAll('[data-select-day]').forEach((button) => button.addEventListener('click', () => {
            state.selectedDay = button.dataset.selectDay;
            state.showMonth = false;
            render();
        }));
        mount.querySelectorAll('[data-open-create]').forEach((button) => button.addEventListener('click', () => openModal(button.dataset.openCreate)));
        mount.querySelectorAll('[data-edit-task]').forEach((button) => button.addEventListener('click', () => openModal('task', findById(state.tasks, button.dataset.editTask))));
        mount.querySelectorAll('[data-edit-reminder]').forEach((button) => button.addEventListener('click', () => openModal('reminder', findById(state.reminders, button.dataset.editReminder))));
        mount.querySelectorAll('[data-edit-event]').forEach((button) => button.addEventListener('click', () => openModal('event', findById(state.calendar, button.dataset.editEvent))));
        mount.querySelectorAll('[data-toggle-task]').forEach((input) => input.addEventListener('change', () => toggleTask(findById(state.tasks, input.dataset.toggleTask))));
        mount.querySelectorAll('[data-toggle-reminder]').forEach((input) => input.addEventListener('change', () => toggleReminder(findById(state.reminders, input.dataset.toggleReminder))));
        mount.querySelectorAll('[data-task-filter]').forEach((button) => button.addEventListener('click', () => { state.taskFilter = button.dataset.taskFilter; render(); }));
        mount.querySelectorAll('[data-reminder-filter]').forEach((button) => button.addEventListener('click', () => { state.reminderFilter = button.dataset.reminderFilter; render(); }));
        mount.querySelectorAll('[data-logout]').forEach((button) => button.addEventListener('click', logout));
        mount.querySelector('[data-delete-account]')?.addEventListener('click', deleteAccount);
        mount.querySelector('[data-export-account]')?.addEventListener('click', exportAccount);
        mount.querySelectorAll('[data-open-profile]').forEach((button) => button.addEventListener('click', () => openModal('profile')));
        mount.querySelectorAll('[data-open-agent]').forEach((button) => button.addEventListener('click', () => openModal('agent')));
        mount.querySelector('[data-create-workspace]')?.addEventListener('click', () => openModal('workspace', { mode: 'create' }));
        mount.querySelector('[data-accept-workspace]')?.addEventListener('click', () => openModal('workspace', { mode: 'accept' }));
        mount.querySelectorAll('[data-rename-workspace]').forEach((button) => button.addEventListener('click', () => openModal('workspace', { mode: 'rename', workspace: findWorkspace(button.dataset.renameWorkspace) })));
        mount.querySelectorAll('[data-invite-workspace]').forEach((button) => button.addEventListener('click', () => openModal('workspace', { mode: 'invite', workspace: findWorkspace(button.dataset.inviteWorkspace) })));
        mount.querySelectorAll('[data-leave-workspace]').forEach((button) => button.addEventListener('click', () => leaveWorkspace(button.dataset.leaveWorkspace)));
        mount.querySelectorAll('[data-remove-member]').forEach((button) => button.addEventListener('click', () => removeMember(button.dataset.workspaceId, button.dataset.removeMember)));
        mount.querySelectorAll('[data-member-role]').forEach((select) => select.addEventListener('change', () => updateMemberRole(select.dataset.workspaceId, select.dataset.memberRole, select.value)));
        mount.querySelectorAll('[data-set-workspace]').forEach((button) => button.addEventListener('click', () => setWorkspace(button.dataset.setWorkspace)));
        mount.querySelectorAll('[data-pref]').forEach((input) => input.addEventListener('change', updateNotificationPrefs));
        mount.querySelectorAll('[data-google-action]').forEach((button) => button.addEventListener('click', () => googleAction(button.dataset.googleAction)));
        mount.querySelectorAll('[data-google-calendar]').forEach((input) => input.addEventListener('change', updateGoogleCalendarSelection));
        mount.querySelectorAll('[data-approval-approve]').forEach((button) => button.addEventListener('click', () => approveApproval(button.dataset.approvalApprove, false)));
        mount.querySelectorAll('[data-approval-always]').forEach((button) => button.addEventListener('click', () => approveApproval(button.dataset.approvalAlways, true)));
        mount.querySelectorAll('[data-approval-deny]').forEach((button) => button.addEventListener('click', () => denyApproval(button.dataset.approvalDeny)));
        mount.querySelectorAll('[data-approval-change]').forEach((button) => button.addEventListener('click', () => changeApproval(button.dataset.approvalChange)));
        mount.querySelectorAll('[data-calendar-pref]').forEach((input) => input.addEventListener('change', () => localStorage.setItem(`heybean.calendar.${input.dataset.calendarPref}`, input.value)));
        mount.querySelectorAll('[data-category-select]').forEach((select) => select.addEventListener('change', syncSelectedCategoryColor));
        const chatForm = mount.querySelector('form[data-action="chat"]');
        chatForm?.addEventListener('submit', submitChat);
        const chatInput = chatForm?.querySelector('textarea[name="message"]');
        if (chatInput) {
            chatInput.addEventListener('input', handleChatInput);
            chatInput.addEventListener('keydown', handleChatKeydown);
            resizeChatInput(chatInput);
        }
        mount.querySelector('[data-new-session]')?.addEventListener('click', newSession);
        mount.querySelector('[data-refresh-activity]')?.addEventListener('click', refreshOnly);
        mount.querySelector('[data-voice-toggle]')?.addEventListener('click', toggleVoiceInput);
        scrollChatToBottom();
    }

    function openModal(type, itemOrOptions = null) {
        state.modal = itemOrOptions && type === 'workspace'
            ? { type, mode: itemOrOptions.mode, workspace: itemOrOptions.workspace }
            : { type, item: itemOrOptions };
        render();
    }

    function bindModalActions() {
        mount.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => {
            state.modal = null;
            render();
        }));
        mount.querySelector('[data-modal-delete]')?.addEventListener('click', deleteModalItem);
        mount.querySelector('[data-modal-form]')?.addEventListener('submit', submitModal);
        mount.querySelector('[data-open-categories]')?.addEventListener('click', () => openModal('categories'));
        mount.querySelectorAll('[data-category-row]').forEach((form) => form.addEventListener('submit', saveCategoryRow));
        mount.querySelectorAll('[data-delete-category]').forEach((button) => button.addEventListener('click', () => deleteCategory(button.dataset.deleteCategory)));
        mount.querySelectorAll('[data-category-select]').forEach((select) => select.addEventListener('change', syncSelectedCategoryColor));
        mount.querySelector('.hb-modal-backdrop')?.addEventListener('click', (event) => {
            if (event.target.classList.contains('hb-modal-backdrop')) {
                state.modal = null;
                render();
            }
        });
    }

    async function submitModal(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const kind = form.dataset.modalForm;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            if (kind === 'profile') {
                state.user = await api('/auth/me', { method: 'PATCH', body: { email: data.email } });
            } else if (kind === 'agent') {
                const priorities = Array.from(form.querySelectorAll('input[name="priorities"]:checked')).map((input) => input.value);
                state.user = await api('/auth/me', {
                    method: 'PATCH',
                    body: {
                        agent_personality: data.personality,
                        onboarding_priorities: priorities,
                        onboarding_context: data.context || null,
                    },
                });
            } else if (kind === 'workspace-create') {
                await api('/workspaces', { method: 'POST', body: { name: data.name } });
                await loadSignedIn();
            } else if (kind === 'workspace-rename') {
                await api(`/workspaces/${data.workspaceId}`, { method: 'PATCH', body: { name: data.name } });
                await loadSignedIn();
            } else if (kind === 'workspace-invite') {
                const membership = await api(`/workspaces/${data.workspaceId}/invitations`, { method: 'POST', body: { email: data.email } });
                state.notice = membership.invitation_accept_url || membership.invitationAcceptUrl || 'Invitation sent.';
            } else if (kind === 'workspace-accept') {
                const token = workspaceToken(data.token);
                await api(`/workspace-invitations/${encodeURIComponent(token)}/accept`, { method: 'POST' });
                await loadSignedIn();
            } else if (kind === 'category-create') {
                await api('/event-categories', { method: 'POST', body: { name: data.name, color: data.color || '#16A34A' } });
                await refreshOnly(false);
                state.modal = { type: 'categories' };
                render();
                return;
            } else {
                await saveItem(kind, state.modal?.item, data, form);
            }
            state.modal = null;
            state.notice = 'Saved.';
            await refreshOnly();
        } catch (error) {
            state.error = friendlyError(error, 'save that change');
            state.modal = null;
            render();
        }
    }

    async function saveItem(kind, item, data, form) {
        const color = data.color || '#34C759';
        if (kind === 'task') {
            const body = {
                title: data.title,
                type: 'todo',
                due_at: fromDatetimeLocal(data.time),
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
            };
            item ? await api(`/tasks/${item.id}`, { method: 'PATCH', body }) : await api('/tasks', { method: 'POST', body });
        } else if (kind === 'reminder') {
            const body = {
                title: data.title,
                remind_at: fromDatetimeLocal(data.time),
                status: item?.status || 'pending',
                category: data.category || null,
                color,
                metadata: { recurrence: data.reminderRecurrence || 'none' },
            };
            item ? await api(`/reminders/${item.id}`, { method: 'PATCH', body }) : await api('/reminders', { method: 'POST', body });
        } else if (kind === 'event') {
            const syncTo = Array.from(form.querySelectorAll('input[name="syncWorkspaceIds"]:checked')).map((input) => Number(input.value)).filter(Boolean);
            const body = {
                title: data.title,
                description: data.description || null,
                location: data.location || null,
                starts_at: fromDatetimeLocal(data.time),
                ends_at: fromDatetimeLocal(data.endsAt),
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                recurrence: data.recurrence || 'none',
                status: data.status || 'confirmed',
                sync_to_workspace_ids: syncTo,
                metadata: {
                    recurrence: data.recurrence || 'none',
                    specific_days: Array.from(form.querySelectorAll('input[name="specificDays"]:checked')).map((input) => input.value),
                    interval: data.interval ? Number(data.interval) : null,
                    interval_unit: data.intervalUnit || null,
                },
            };
            if (!item && data.workspaceId) body.workspace_id = Number(data.workspaceId);
            item ? await api(`/calendar-events/${item.id}`, { method: 'PATCH', body }) : await api('/calendar-events', { method: 'POST', body });
        }
    }

    function syncSelectedCategoryColor(event) {
        const option = event.currentTarget.selectedOptions?.[0];
        const color = option?.dataset?.categoryColor;
        const input = event.currentTarget.closest('form')?.querySelector('input[name="color"]');
        if (input && color) input.value = safeColor(color);
    }

    async function deleteModalItem(event) {
        const kind = event.currentTarget.dataset.modalDelete;
        const id = event.currentTarget.dataset.id;
        if (!confirm(`Delete this ${kind}?`)) return;
        const path = kind === 'task' ? `/tasks/${id}` : kind === 'reminder' ? `/reminders/${id}` : `/calendar-events/${id}`;
        try {
            await api(path, { method: 'DELETE' });
            state.modal = null;
            await refreshOnly();
        } catch (error) {
            state.modal = null;
            state.error = friendlyError(error, `delete that ${kind}`);
            render();
        }
    }

    async function toggleTask(task) {
        if (!task) return;
        const completed = taskCompleted(task);
        await api(`/tasks/${task.id}`, {
            method: 'PATCH',
            body: {
                status: completed ? 'pending' : 'completed',
                completed_at: completed ? null : new Date().toISOString(),
            },
        });
        await refreshOnly();
    }

    async function toggleReminder(reminder) {
        if (!reminder) return;
        await api(`/reminders/${reminder.id}`, {
            method: 'PATCH',
            body: { status: reminderCompleted(reminder) ? 'pending' : 'completed' },
        });
        await refreshOnly();
    }

    function handleChatInput(event) {
        state.voiceDraft = event.currentTarget.value;
        resizeChatInput(event.currentTarget);
    }

    function handleChatKeydown(event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        event.preventDefault();
        event.currentTarget.form?.requestSubmit();
    }

    function resizeChatInput(textarea) {
        const lineHeight = Number.parseFloat(getComputedStyle(textarea).lineHeight) || 20;
        const maxHeight = Math.ceil((lineHeight * 4) + 22);
        textarea.style.height = 'auto';
        textarea.style.height = `${Math.min(textarea.scrollHeight, maxHeight)}px`;
        textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden';
    }

    async function submitChat(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const content = new FormData(form).get('message')?.toString().trim();
        if (!content || state.busy) return;
        await sendChatContent(content);
    }

    async function sendChatContent(content) {
        state.messages.push({ id: `local-${Date.now()}`, role: 'user', content });
        state.busy = true;
        state.voiceDraft = '';
        state.chatRunState = 'Working…';
        render();
        try {
            if (!state.session?.id) {
                state.session = await api('/assistant/sessions', {
                    method: 'POST',
                    body: { title: 'Workspace chat', workspace_id: state.user?.active_workspace?.id || state.summary?.workspace?.id || null },
                });
            }
            const result = await api(`/assistant/sessions/${state.session.id}/messages`, {
                method: 'POST',
                body: { content },
            });
            state.session = result.session || state.session;
            state.activity = normalizeList(result.events).length ? result.events : state.activity;
            if (result.user_message) replaceLocalUserMessage(result.user_message);
            if (result.assistant_message) state.messages.push(result.assistant_message);
            state.chatRunState = result.status === 'blocked' ? 'Blocked for approval' : 'Ready';
            await refreshOnly(false);
        } catch (error) {
            state.messages.push({ id: `error-${Date.now()}`, role: 'assistant', content: friendlyError(error, 'send that message') });
            state.chatRunState = 'Failed';
        } finally {
            state.busy = false;
            render();
            scrollChatToBottom();
        }
    }

    function toggleVoiceInput() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            state.error = 'Voice input is not available in this browser.';
            render();
            return;
        }
        if (state.voiceListening && state.voiceRecognition) {
            state.voiceRecognition.stop();
            return;
        }
        const recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.onresult = (event) => {
            const transcript = Array.from(event.results).map((result) => result[0]?.transcript || '').join(' ').trim();
            state.voiceDraft = transcript;
            const textarea = mount.querySelector('textarea[name="message"]');
            if (textarea) {
                textarea.value = transcript;
                resizeChatInput(textarea);
            }
        };
        recognition.onend = () => {
            state.voiceListening = false;
            state.voiceRecognition = null;
            render();
        };
        recognition.onerror = () => {
            state.voiceListening = false;
            state.voiceRecognition = null;
            state.error = 'Voice input stopped. You can still type to Bean.';
            render();
        };
        state.voiceRecognition = recognition;
        state.voiceListening = true;
        recognition.start();
        render();
    }

    function replaceLocalUserMessage(message) {
        const lastLocal = [...state.messages].reverse().find((item) => String(item.id).startsWith('local-') && item.role === 'user');
        if (!lastLocal) {
            state.messages.push(message);
            return;
        }
        const index = state.messages.indexOf(lastLocal);
        state.messages[index] = message;
    }

    async function newSession() {
        try {
            state.session = await api('/assistant/sessions', {
                method: 'POST',
                body: { title: 'Workspace chat', workspace_id: state.user?.active_workspace?.id || state.summary?.workspace?.id || null },
            });
            state.messages = [];
            state.chatRunState = 'Ready';
            render();
        } catch (error) {
            state.error = friendlyError(error, 'start a new chat');
            render();
        }
    }

    async function resumeSession(id) {
        try {
            const session = await api(`/assistant/sessions/${id}`);
            state.session = session.session || session;
            state.messages = normalizeList(session.messages);
            state.activity = normalizeList(session.activity_events || session.events).length ? normalizeList(session.activity_events || session.events) : state.activity;
            render();
        } catch (_) {
            // A missing old session should not block the rest of the app.
        }
    }

    async function refreshOnly(shouldRender = true) {
        try {
            const [summary, tasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api('/today'),
                api('/tasks'),
                api('/reminders'),
                api('/calendar-events'),
                api('/event-categories'),
                api('/google-calendar/status').catch(() => state.googleStatus),
            ]);
            state.summary = summary;
            state.tasks = normalizeList(tasks.length ? tasks : summary?.tasks);
            state.reminders = normalizeList(reminders.length ? reminders : summary?.reminders);
            state.calendar = normalizeList(calendar.length ? calendar : summary?.calendar_events);
            state.categories = normalizeList(categories);
            state.approvals = normalizeList(summary?.approvals);
            state.blockers = normalizeList(summary?.blockers);
            state.activity = normalizeList(summary?.activity_events);
            state.googleStatus = googleStatus;
            state.user = mergeUser(state.user, summary?.user, summary);
            if (shouldRender) render();
        } catch (error) {
            state.error = friendlyError(error, 'refresh the app');
            if (shouldRender) render();
        }
    }

    async function updateNotificationPrefs(event) {
        const current = state.user?.notification_preferences || {};
        const next = {
            ...current,
            [event.currentTarget.dataset.pref]: event.currentTarget.checked,
        };
        try {
            state.user = await api('/auth/me', { method: 'PATCH', body: { notification_preferences: next } });
            state.notice = 'Notification preferences saved.';
            render();
        } catch (error) {
            state.error = friendlyError(error, 'save notification preferences');
            render();
        }
    }

    async function setWorkspace(id) {
        try {
            await api('/workspaces/default', { method: 'PATCH', body: { workspace_id: Number(id) } });
            await loadSignedIn();
        } catch (error) {
            state.error = friendlyError(error, 'switch workspaces');
            render();
        }
    }

    async function googleAction(action) {
        try {
            if (action === 'connect') {
                const result = await api('/google-calendar/auth-url', { method: 'POST' });
                state.googleAuthUrl = result.auth_url;
                window.open(result.auth_url, '_blank', 'noopener,noreferrer');
                state.notice = 'Finish approving calendar access in the browser, then tap Check connection.';
            } else if (action === 'copy') {
                await navigator.clipboard.writeText(state.googleAuthUrl);
                state.notice = 'Calendar authorization link copied.';
            } else if (action === 'check' || action === 'sync') {
                const result = await api('/google-calendar/sync', { method: 'POST' });
                state.googleStatus = result.status;
                state.notice = `Synced ${result.imported || 0} connected events.`;
            } else if (action === 'disconnect') {
                state.googleStatus = await api('/google-calendar', { method: 'DELETE' });
                state.notice = 'Calendar sync disconnected.';
            }
            render();
        } catch (error) {
            state.error = friendlyError(error, 'update calendar sync');
            render();
        }
    }

    async function updateGoogleCalendarSelection() {
        const selected = Array.from(mount.querySelectorAll('[data-google-calendar]:checked')).map((input) => input.value);
        try {
            state.googleStatus = await api('/google-calendar/calendars', {
                method: 'PATCH',
                body: { selected_calendar_ids: selected, default_calendar_id: selected[0] || null },
            });
            state.notice = 'Calendar choices saved.';
            render();
        } catch (error) {
            state.error = friendlyError(error, 'save calendar choices');
            render();
        }
    }

    async function leaveWorkspace(id) {
        if (!confirm('Leave this workspace?')) return;
        await api(`/workspaces/${id}/leave`, { method: 'POST' });
        await loadSignedIn();
    }

    async function updateMemberRole(workspaceId, memberId, role) {
        await api(`/workspaces/${workspaceId}/members/${memberId}`, { method: 'PATCH', body: { role } });
        await loadSignedIn();
    }

    async function removeMember(workspaceId, memberId) {
        if (!confirm('Remove this member from the workspace?')) return;
        await api(`/workspaces/${workspaceId}/members/${memberId}`, { method: 'DELETE' });
        await loadSignedIn();
    }

    async function approveApproval(id, alwaysApprove) {
        await api(`/approvals/${id}/approve`, { method: 'POST', body: { always_approve: alwaysApprove } });
        await refreshOnly();
    }

    async function denyApproval(id) {
        await api(`/approvals/${id}/deny`, { method: 'POST' });
        await refreshOnly();
    }

    async function changeApproval(id) {
        const revised = mount.querySelector('.hb-approval-change')?.value?.trim();
        await denyApproval(id);
        if (revised) {
            state.selected = 'bean';
            await sendChatContent(revised);
        }
    }

    async function saveCategoryRow(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form).entries());
        await api(`/event-categories/${form.dataset.categoryRow}`, { method: 'PATCH', body: { name: data.name, color: data.color } });
        await refreshOnly(false);
        state.modal = { type: 'categories' };
        render();
    }

    async function deleteCategory(id) {
        if (!confirm('Delete this category from items?')) return;
        await api(`/event-categories/${id}`, { method: 'DELETE' });
        await refreshOnly(false);
        state.modal = { type: 'categories' };
        render();
    }

    async function logout() {
        try { await api('/auth/logout', { method: 'POST' }); } catch (_) {}
        clearToken();
        state.phase = 'signedOut';
        state.authMode = 'login';
        history.pushState({}, '', '/login');
        render();
    }

    async function deleteAccount() {
        if (!confirm('Delete your HeyBean account and data? This cannot be undone.')) return;
        try {
            await api('/account', { method: 'DELETE' });
            clearToken();
            state.phase = 'signedOut';
            state.authMode = 'login';
            state.notice = 'Your account has been deleted.';
            history.pushState({}, '', '/login');
            render();
        } catch (error) {
            state.error = friendlyError(error, 'delete your account');
            render();
        }
    }

    async function exportAccount() {
        try {
            const data = await api('/account/export');
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `heybean-account-export-${dateOnly(new Date())}.json`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            state.error = friendlyError(error, 'export your account');
            render();
        }
    }

    function activeTasks() {
        return state.tasks.filter((task) => !taskCompleted(task));
    }

    function pendingReminders() {
        return state.reminders.filter((reminder) => !reminderCompleted(reminder));
    }

    function criticalItems() {
        return [
            ...state.tasks.filter((item) => !taskCompleted(item) && (item.is_critical || item.isCritical)),
            ...state.calendar.filter((item) => (item.is_critical || item.isCritical) && isSameDay(item.starts_at || item.startsAt, new Date())),
        ];
    }

    function workspaces() {
        return normalizeList(state.user?.workspaces || state.summary?.workspaces);
    }

    function currentWorkspaceId() {
        return state.user?.active_workspace?.id || state.user?.activeWorkspace?.id || state.summary?.workspace?.id || workspaces().find((workspace) => workspace.active || workspace.is_default || workspace.isDefault)?.id || workspaces()[0]?.id || '';
    }

    function categoryOptions(current = '') {
        const byName = new Map();
        state.categories.forEach((category) => {
            if (!category?.name || byName.has(category.name)) return;
            byName.set(category.name, category);
        });
        if (current && !byName.has(current)) {
            byName.set(current, { name: current, color: categoryColor(current) });
        }
        return Array.from(byName.values()).sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    function categoryColor(name) {
        return state.categories.find((category) => category.name === name)?.color || '';
    }

    function findById(list, id) {
        return list.find((item) => String(item.id) === String(id));
    }

    function findWorkspace(id) {
        return workspaces().find((workspace) => String(workspace.id) === String(id));
    }

    function pendingApproval() {
        return state.approvals.find((approval) => !approval.status || String(approval.status).toLowerCase() === 'pending');
    }

    function pendingApprovalForSession() {
        const sessionId = state.session?.id || state.summary?.session?.id;
        return state.approvals.find((approval) => {
            if (approval.status && String(approval.status).toLowerCase() !== 'pending') return false;
            const approvalSessionId = approval.conversation_session_id || approval.conversationSessionId;
            return !sessionId || !approvalSessionId || String(approvalSessionId) === String(sessionId);
        });
    }

    function isLatestAssistantMessage(index, messages) {
        for (let cursor = messages.length - 1; cursor >= 0; cursor -= 1) {
            if (messages[cursor]?.role !== 'user') return cursor === index;
        }
        return false;
    }

    function approvalDescription(approval) {
        const action = approval.payload?.action || {};
        const type = String(action.type || approval.title || 'take the next action').replaceAll('.', ' ');
        const risk = String(action.risk || 'unknown').toLowerCase();
        const description = String(approval.description || '').trim();
        return description ? `${description} This action is marked ${risk} risk.` : `Bean wants to ${type}. This action is marked ${risk} risk.`;
    }

    function taskCompleted(task) {
        return Boolean(task?.completed_at || task?.completedAt || ['completed', 'complete', 'done'].includes(String(task?.status || '').toLowerCase()));
    }

    function reminderCompleted(reminder) {
        return Boolean(reminder?.completed_at || reminder?.completedAt || ['completed', 'complete', 'done'].includes(String(reminder?.status || '').toLowerCase()));
    }

    function taskSubtitle(task) {
        const bits = [];
        if (task.category) bits.push(task.category);
        if (task.due_at || task.dueAt) bits.push(`Due ${formatDateTime(task.due_at || task.dueAt)}`);
        if (task.is_critical || task.isCritical) bits.push('Critical');
        return bits.join(' · ') || 'No due date';
    }

    function reminderSubtitle(reminder) {
        const bits = [];
        if (reminder.category) bits.push(reminder.category);
        if (reminder.remind_at || reminder.due_at || reminder.dueAt) bits.push(formatDateTime(reminder.remind_at || reminder.due_at || reminder.dueAt));
        return bits.join(' · ') || 'No reminder time';
    }

    function eventsForDay(day) {
        return state.calendar
            .filter((event) => isSameDay(event.starts_at || event.startsAt, day))
            .sort((a, b) => new Date(a.starts_at || a.startsAt || 0) - new Date(b.starts_at || b.startsAt || 0));
    }

    function eventsForDays(days) {
        return days.flatMap((day) => eventsForDay(day));
    }

    function eventTime(event) {
        const start = event.starts_at || event.startsAt;
        const end = event.ends_at || event.endsAt;
        if (!start) return 'All day';
        const startLabel = formatTime(start);
        return end ? `${startLabel} – ${formatTime(end)}` : startLabel;
    }

    function timelineEventStyle(event, day, startHour, endHour) {
        const startValue = event.starts_at || event.startsAt;
        if (!startValue) return null;
        const start = new Date(startValue);
        const fallbackEnd = new Date(start);
        fallbackEnd.setHours(fallbackEnd.getHours() + 1);
        const end = event.ends_at || event.endsAt ? new Date(event.ends_at || event.endsAt) : fallbackEnd;
        const dayStart = new Date(parseLocalDate(day));
        dayStart.setHours(startHour, 0, 0, 0);
        const dayEnd = new Date(parseLocalDate(day));
        dayEnd.setHours(endHour + 1, 0, 0, 0);
        const visibleStart = new Date(Math.max(start.getTime(), dayStart.getTime()));
        const visibleEnd = new Date(Math.min(end.getTime(), dayEnd.getTime()));
        if (visibleEnd <= dayStart || visibleStart >= dayEnd || visibleEnd <= visibleStart) return null;
        const minutesFromStart = Math.max(0, (visibleStart - dayStart) / 60000);
        const durationMinutes = Math.max(15, (visibleEnd - visibleStart) / 60000);
        const hourHeight = 64;
        return {
            minutes: Math.round(durationMinutes),
            css: `top:${(minutesFromStart / 60) * hourHeight}px;height:${(durationMinutes / 60) * hourHeight}px`,
        };
    }

    function weekDays(center) {
        const base = parseLocalDate(center);
        const offset = base.getDay();
        const start = new Date(base);
        start.setDate(base.getDate() - offset);
        return Array.from({ length: 7 }, (_, index) => {
            const day = new Date(start);
            day.setDate(start.getDate() + index);
            return day;
        });
    }

    function weekDaysForWindow(center, visibleDays) {
        const days = weekDays(center);
        const lastVisible = visibleDays[visibleDays.length - 1];
        while (!days.some((day) => sameDate(day, lastVisible))) {
            days.shift();
            days.push(addDays(days[days.length - 1], 1));
        }
        return days;
    }

    function visibleCalendarDays(start) {
        const selected = parseLocalDate(start);
        if (state.calendarVisibleDayCount >= 7) return weekDays(selected);
        return Array.from({ length: state.calendarVisibleDayCount }, (_, index) => addDays(selected, index));
    }

    function calendarVisibleDayCount() {
        const width = window.innerWidth || 0;
        if (width >= 1280) return 7;
        if (width >= 820) return 4;
        return 2;
    }

    function addDays(date, amount) {
        const next = new Date(parseLocalDate(date));
        next.setDate(next.getDate() + amount);
        return next;
    }

    function dateOnly(date) {
        const d = parseLocalDate(date);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function parseLocalDate(value) {
        if (value instanceof Date) return value;
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
            const [year, month, day] = value.split('-').map(Number);
            return new Date(year, month - 1, day);
        }
        return value ? new Date(value) : new Date();
    }

    function isSameDay(value, day) {
        if (!value) return false;
        return sameDate(parseLocalDate(value), parseLocalDate(day));
    }

    function sameDate(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function monthLabel(date) {
        return parseLocalDate(date).toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
    }

    function dayLabel(date) {
        const parsed = parseLocalDate(date);
        if (sameDate(parsed, new Date())) return 'Today';
        if (sameDate(parsed, addDays(new Date(), 1))) return 'Tomorrow';
        return parsed.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    }

    function timelineDayHeaderLabel(date) {
        const parsed = parseLocalDate(date);
        if (sameDate(parsed, new Date())) return `Today, ${weekdayShort(parsed)}`;
        return dayLabel(parsed);
    }

    function weekdayShort(date) {
        return parseLocalDate(date).toLocaleDateString(undefined, { weekday: 'short' });
    }

    function monthDayLabel(date) {
        return parseLocalDate(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function calendarRangeLabel(days) {
        if (days.length <= 2) return days.map((day) => dayLabel(day)).join(' and ');
        return `${dayLabel(days[0])} - ${dayLabel(days[days.length - 1])}`;
    }

    function formatDateTime(value) {
        if (!value) return '';
        return new Date(value).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function formatTime(value) {
        if (!value) return '';
        return new Date(value).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function hourLabel(hour) {
        const normalized = ((hour % 24) + 24) % 24;
        const suffix = normalized >= 12 ? 'PM' : 'AM';
        const display = normalized % 12 || 12;
        return `${display} ${suffix}`;
    }

    function recurrenceLabel(value) {
        return {
            none: 'None',
            daily: 'Daily',
            weekly: 'Weekly',
            monthly: 'Monthly',
            specific_days: 'Specific days',
            interval: 'Every interval',
        }[value] || value;
    }

    function toDatetimeLocal(value) {
        if (!value) return '';
        const date = new Date(value);
        const pad = (n) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function fromDatetimeLocal(value) {
        return value ? new Date(value).toISOString() : null;
    }

    function safeColor(value) {
        return /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#34C759';
    }

    function hexAlpha(hex, alpha) {
        const safe = safeColor(hex).slice(1);
        const r = parseInt(safe.slice(0, 2), 16);
        const g = parseInt(safe.slice(2, 4), 16);
        const b = parseInt(safe.slice(4, 6), 16);
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function workspaceToken(input) {
        const raw = String(input || '').trim();
        try {
            const url = new URL(raw);
            const index = url.pathname.split('/').indexOf('workspace-invitations');
            if (index >= 0) return url.pathname.split('/')[index + 1] || raw;
        } catch (_) {}
        return raw;
    }

    function personalityLabel(value = 'balanced') {
        return { balanced: 'Balanced', coach: 'Coach', organizer: 'Organizer', creative: 'Creative' }[value] || 'Balanced';
    }

    function userInitials(name = '', email = '') {
        const source = String(name || email || 'Account').trim();
        const words = source.includes('@') ? [source.charAt(0)] : source.split(/\s+/).filter(Boolean);
        return words.slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('') || 'A';
    }

    function capitalize(value) {
        return String(value).charAt(0).toUpperCase() + String(value).slice(1);
    }

    function scrollChatToBottom() {
        requestAnimationFrame(() => {
            const scroller = document.getElementById('hb-chat-messages');
            if (scroller) scroller.scrollTop = scroller.scrollHeight;
        });
    }

    function friendlyError(error, action) {
        const message = error?.message || 'Something went wrong.';
        if (/failed to fetch/i.test(message)) return `Could not ${action}. Check your connection and try again.`;
        return message;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }
}
