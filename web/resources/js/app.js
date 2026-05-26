const mount = document.getElementById('heybean-web-app');

if (mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';
    const calendarInitialWindowDays = 56;
    const calendarWindowChunkDays = 28;

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
        refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.2 6.5L3 16"/><path d="M3 21v-5h5"/><path d="M3 12A9 9 0 0 1 18.2 5.5L21 8"/><path d="M21 3v5h-5"/></svg>',
        chevronLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
        chevronRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
    };

    const state = {
        authMode: initialMode,
        token: readToken(),
        remember: localStorage.getItem(rememberKey) === 'true',
        phase: 'loading',
        selected: 'today',
        selectedDay: dateOnly(new Date()),
        calendarWindowStart: initialCalendarWindowStart(new Date()),
        calendarWindowDayCount: calendarInitialWindowDays,
        timelineScrollRestore: null,
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
        voiceStatus: '',
        voiceStatusTone: '',
        chatExpanded: false,
        calendarRefreshing: false,
        taskFilter: 'active',
        reminderFilter: 'pending',
        expandedTaskIds: new Set(),
        busy: false,
        error: '',
        notice: '',
        modal: null,
    };

    let voiceHoldActive = false;
    let voiceHoldPressed = false;
    let voiceStartPending = false;
    let voiceSubmitOnEnd = false;
    let suppressNextSendClick = false;
    let timelineDrag = null;
    let timelineSuppressClick = false;

    boot();
    bindResponsiveCalendar();
    bindCurrentTimeTicker();

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

    function bindCurrentTimeTicker() {
        window.setInterval(() => {
            if (state.phase !== 'signedIn' || state.selected !== 'today' || state.showMonth || state.modal) return;
            updateCurrentTimeMarker();
        }, 30000);
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
            const [user, summary, tasks, pastTasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api('/auth/me'),
                api('/today'),
                api('/tasks'),
                api('/tasks/past'),
                api('/reminders'),
                api('/calendar-events'),
                api('/event-categories'),
                api('/google-calendar/status').catch(() => null),
            ]);
            state.user = mergeUser(user, summary?.user, summary);
            state.summary = summary;
            state.tasks = mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks));
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
            if (needsBeanOnboarding()) {
                state.selected = 'bean';
                state.chatExpanded = false;
                state.chatRunState = 'Onboarding';
            }
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

    function currentAgentProfile() {
        return state.user?.active_workspace_agent_profile
            || state.user?.activeWorkspaceAgentProfile
            || state.user?.agent_profile
            || state.user?.agentProfile
            || state.summary?.agent_profile
            || {};
    }

    function profileSettings(profile = currentAgentProfile()) {
        return typeof profile.settings === 'object' && profile.settings ? profile.settings : {};
    }

    function profileOnboarding(profile = currentAgentProfile()) {
        const settings = profileSettings(profile);
        return typeof settings.onboarding === 'object' && settings.onboarding ? settings.onboarding : {};
    }

    function profilePersonality(profile = currentAgentProfile()) {
        const settings = profileSettings(profile);
        return profile.agent_personality || profile.agentPersonality || profile.personality_type || profile.personalityType || settings.personality_type || settings.personalityType || 'balanced';
    }

    function profilePriorities(profile = currentAgentProfile()) {
        const onboarding = profileOnboarding(profile);
        return normalizeList(profile.onboarding_priorities || profile.onboardingPriorities || onboarding.priorities);
    }

    function profileOnboardingContext(profile = currentAgentProfile()) {
        const onboarding = profileOnboarding(profile);
        return profile.onboarding_context || profile.onboardingContext || onboarding.context || '';
    }

    function profileOnboardingComplete(profile = currentAgentProfile()) {
        const onboarding = profileOnboarding(profile);
        return onboarding.completed === true || onboarding.completed === 1 || onboarding.completed === 'true';
    }

    function needsBeanOnboarding() {
        const userComplete = state.user?.onboard_complete === true || state.user?.onboardComplete === true;
        return !userComplete && !profileOnboardingComplete();
    }

    function onboardingIntroMessage() {
        return 'Hey, I’m Bean. I’ll ask a few quick questions so I can learn your preferred style, top priorities, and anything important about your schedule or reminders. Start by telling me who you are and what you want Bean to help with most.';
    }

    function normalizeList(value) {
        return Array.isArray(value) ? value : [];
    }

    function mergeById(...lists) {
        const merged = new Map();
        lists.flatMap(normalizeList).forEach((item, index) => {
            if (!item) return;
            const key = item.id === undefined || item.id === null ? `unkeyed-${index}` : String(item.id);
            merged.set(key, { ...(merged.get(key) || {}), ...item });
        });
        return [...merged.values()];
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
        const showCalendarRefresh = state.selected === 'today';
        return `
            <div class="hb-app">
                <header class="hb-topbar">
                    ${topNavMarkup()}
                    ${topWorkspaceSwitcherMarkup()}
                    <span class="hb-spacer"></span>
                    ${state.selected === 'today' && state.showMonth ? monthSwitcherMarkup(parseLocalDate(state.selectedDay)) : ''}
                    <button class="hb-header-pill" data-today type="button">${escapeHtml(topbarTodayLabel(new Date()))}</button>
                    <button class="hb-header-pill hb-month-pill" data-calendar-month type="button">${icons.calendar}<span>${escapeHtml(monthLabel(new Date()))}</span></button>
                    <button class="hb-critical" type="button" title="${critical} critical items">${critical}</button>
                    ${showAdd ? `<button class="hb-icon-button" type="button" data-open-create="${state.selected === 'today' ? 'event' : state.selected.slice(0, -1)}" aria-label="${escapeAttr(addTitle)}">${icons.add}</button>` : ''}
                    ${showCalendarRefresh ? `<button class="hb-icon-button" type="button" data-refresh-calendar aria-label="Refresh calendar" title="Refresh calendar" ${state.calendarRefreshing ? 'disabled' : ''}>${state.calendarRefreshing ? '<span class="hb-spinner hb-spinner-tiny"></span>' : icons.refresh}</button>` : ''}
                    ${topProfileMenuMarkup()}
                </header>
                <main class="hb-main ${state.selected === 'bean' ? 'hb-main-chat' : ''} ${state.selected === 'today' ? 'hb-main-today' : ''}">
                    ${state.selected === 'bean' ? chatMarkup() : appPanelMarkup()}
                </main>
                ${state.selected === 'bean' ? '' : approvalSheetMarkup()}
                ${bottomMenuMarkup()}
                ${state.selected === 'bean' ? '' : floatingBeanButtonMarkup()}
                ${state.chatExpanded && state.selected !== 'bean' ? desktopChatMarkup({ expanded: true }) : ''}
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
                    ${atAGlanceMarkup()}
                    ${todayTasksMarkup()}
                </aside>
            </div>`;
    }

    function todayMarkup() {
        const selected = parseLocalDate(state.selectedDay);
        const visibleDays = visibleCalendarDays(selected);
        return `
            <section class="hb-card hb-card-pad hb-calendar-card">
                <div class="hb-calendar">
                    ${state.showMonth ? monthGridMarkup(selected) : ''}
                    ${state.showMonth ? '' : timelineMarkup(visibleDays)}
                </div>
            </section>`;
    }

    function tasksMarkup() {
        const completed = state.taskFilter === 'done';
        const items = completed
            ? state.tasks.filter((task) => taskCompleted(task))
            : activeTopLevelTasks();
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

    function chatMarkup(options = {}) {
        const working = state.busy && state.chatRunState !== 'Ready';
        const messages = state.messages.length ? state.messages : [
            { id: 'intro', role: 'assistant', content: needsBeanOnboarding() ? onboardingIntroMessage() : 'Hey, I’m Bean. Tell me what you need planned, captured, moved, or remembered.' },
        ];
        const expandLabel = state.chatExpanded ? 'Close' : 'Expand';
        return `
            <section class="hb-chat">
                <div class="hb-chat-top">
                    <span class="hb-run-pill ${working ? 'hb-run-pill-working' : ''}">${escapeHtml(state.chatRunState)}</span>
                    <span class="hb-spacer"></span>
                    ${options.expandable ? `<button class="hb-button-secondary hb-chat-expand-action" type="button" data-toggle-chat-expand aria-label="${escapeAttr(expandLabel)}">${escapeHtml(expandLabel)}</button>` : ''}
                    <button class="hb-button-ghost" type="button" data-new-session>${icons.add} /new</button>
                </div>
                <div class="hb-chat-messages" id="hb-chat-messages">
                    ${messages.map((message, index) => messageMarkup(message, index, messages)).join('')}
                    ${working ? '' : pendingApprovalChatMarkup()}
                    ${working ? messageMarkup({ id: 'busy', role: 'assistant', content: state.chatRunState || 'Working…', progress: true }) : ''}
                </div>
                <div class="hb-chat-voice-status ${state.voiceStatusTone === 'error' ? 'hb-chat-voice-status-error' : ''}" data-voice-status ${state.voiceStatus ? '' : 'hidden'}>${escapeHtml(state.voiceStatus)}</div>
                <form class="hb-chat-dock ${state.voiceListening ? 'hb-chat-dock-listening' : ''}" data-action="chat">
                    <textarea name="message" placeholder="${state.voiceListening ? 'Listening… release to send' : 'Message Bean…'}" rows="1" ${state.busy ? 'disabled' : ''}>${escapeHtml(state.voiceDraft)}</textarea>
                    <button class="hb-button-secondary hb-chat-text-send-button" type="submit" ${state.busy ? 'disabled' : ''} aria-label="Send message">${icons.send}</button>
                    <button class="${state.busy ? 'hb-button-danger' : 'hb-button'} hb-chat-voice-button" type="button" ${state.busy ? 'disabled' : 'data-voice-hold'} aria-label="${state.busy ? 'Bean is working' : 'Hold to talk'}">${state.busy ? icons.stop : `<img class="hb-send-bean-logo" src="${escapeAttr(logoUrl)}" alt="">`}</button>
                </form>
            </section>`;
    }

    function desktopChatMarkup(options = {}) {
        return `
            <section class="hb-desktop-chat ${options.expanded ? 'hb-desktop-chat-expanded' : ''}" aria-label="Bean chat">
                ${chatMarkup({ expandable: true })}
            </section>`;
    }

    function floatingBeanButtonMarkup() {
        return `
            <button class="hb-bean-button hb-floating-bean-button ${state.chatExpanded ? 'hb-bean-button-active' : ''}" type="button" data-toggle-chat-expand aria-label="${state.chatExpanded ? 'Close Bean chat' : 'Open Bean chat'}">
                <img src="${escapeAttr(logoUrl)}" alt="">
            </button>`;
    }

    function settingsMarkup() {
        const user = state.user || {};
        const prefs = user.notification_preferences || {};
        const profile = currentAgentProfile();
        const priorities = profilePriorities(profile);
        const context = profileOnboardingContext(profile);
        const complete = profileOnboardingComplete(profile);
        const workspaceItems = workspaces();
        const activeWorkspaceId = String(currentWorkspaceId() || '');
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
                    <div><strong>Bean preferences</strong><small>${escapeHtml(personalityLabel(profilePersonality(profile)))} • ${escapeHtml(priorities.length ? priorities.join(', ') : 'No priorities selected yet')}${context ? ` • ${escapeHtml(context)}` : ''}${complete ? '' : ' • Onboarding not finished'}</small></div>
                    <button class="hb-button-ghost" type="button" data-open-agent>Update</button>
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    <strong>Notification preferences</strong>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_push" ${prefs.reminder_push !== false ? 'checked' : ''}> Reminder push notifications</label>
                    <label class="hb-switch-row"><input type="checkbox" data-pref="reminder_email" ${prefs.reminder_email === true ? 'checked' : ''}> Reminder emails</label>
                </div>
                <div class="hb-surface-soft hb-card-pad">
                    <strong>Workspaces</strong>
                    ${workspaceSwitcherMarkup(workspaceItems, activeWorkspaceId)}
                    <div class="hb-list" style="margin-top:10px">${workspaceItems.map((workspace) => {
                        const workspaceId = String(workspace.id || '');
                        const active = workspaceId === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault;
                        return `
                        <div class="hb-workspace-block">
                            <div class="hb-compact-item">
                                <span class="hb-compact-icon">${icons.calendar}</span>
                                <div><strong>${escapeHtml(workspaceDisplayName(workspace))}</strong><small>${escapeHtml(workspaceTypeLabel(workspace))}${active ? ' · Active' : ''}</small></div>
                                <div class="hb-row-actions">
                                    <button class="${active ? 'hb-button-secondary' : 'hb-button-ghost'}" type="button" data-set-workspace="${escapeAttr(workspace.id)}" ${active ? 'disabled' : ''}>${active ? 'Current' : 'Switch'}</button>
                                    ${workspace.type === 'personal' || workspace.kind === 'personal' ? '' : `<button class="hb-button-ghost" type="button" data-rename-workspace="${escapeAttr(workspace.id)}">Rename</button><button class="hb-button-ghost" type="button" data-invite-workspace="${escapeAttr(workspace.id)}">Invite</button><button class="hb-button-ghost" type="button" data-leave-workspace="${escapeAttr(workspace.id)}">Leave</button>`}
                                </div>
                            </div>
                            ${workspaceMembersMarkup(workspace)}
                        </div>
                    `;
                    }).join('') || '<div class="hb-empty">No workspaces loaded</div>'}</div>
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

    function workspaceSwitcherMarkup(workspaceItems, activeWorkspaceId) {
        if (!workspaceItems.length) {
            return '<p class="hb-item-meta">No workspaces loaded.</p>';
        }

        const activeWorkspace = workspaceItems.find((workspace) => String(workspace.id) === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault) || workspaceItems[0];

        return `
            <div class="hb-workspace-switcher">
                <label class="hb-label">Active workspace
                    <select class="hb-select" data-workspace-select ${workspaceItems.length < 2 ? 'disabled' : ''}>
                        ${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}
                    </select>
                </label>
                <p class="hb-item-meta">Using ${escapeHtml(workspaceDisplayName(activeWorkspace))}.</p>
            </div>`;
    }

    function topWorkspaceSwitcherMarkup() {
        const workspaceItems = workspaces();
        if (!workspaceItems.length) return '';
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        const activeWorkspace = workspaceItems.find((workspace) => String(workspace.id) === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault) || workspaceItems[0];
        return `
            <label class="hb-top-workspace-switcher" title="Switch workspace">
                <span class="hb-top-workspace-icon" aria-hidden="true">${icons.settings}</span>
                <span class="hb-top-workspace-label">Workspace</span>
                <select data-top-workspace-select ${workspaceItems.length < 2 ? 'disabled' : ''} aria-label="Switch workspace">
                    ${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}
                </select>
            </label>`;
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
        const tasks = activeTopLevelTasks().filter((task) => isSameDay(task.due_at || task.dueAt, today));
        return `
            <section class="hb-card hb-card-pad hb-today-tasks-card">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.tasks, 'Tasks for today', `${tasks.length} tasks`)}
                    <button class="hb-icon-button" type="button" data-open-create="task" aria-label="Create task" title="Create task">${icons.add}</button>
                </div>
                ${itemListMarkup(tasks, 'task', 'No tasks scheduled for today')}
            </section>`;
    }

    function atAGlanceMarkup() {
        const days = [new Date(), addDays(new Date(), 1), addDays(new Date(), 2)];
        const eventCount = eventsForDays(days).length;
        return `
            <section class="hb-card hb-card-pad hb-glance-card">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.calendar, 'At a glance', `${eventCount} upcoming ${eventCount === 1 ? 'event' : 'events'}`)}
                    <button class="hb-icon-button" type="button" data-open-create="event" aria-label="Create event" title="Create event">${icons.add}</button>
                </div>
                <div class="hb-glance-list">
                    ${days.map((day) => glanceDayMarkup(day)).join('')}
                </div>
            </section>`;
    }

    function glanceDayMarkup(day) {
        const events = eventsForDay(day);
        return `
            <div class="hb-glance-day">
                <div class="hb-glance-day-label">${escapeHtml(glanceDayLabel(day))}</div>
                <div class="hb-glance-events">
                    ${events.length ? events.map((event) => glanceEventMarkup(event)).join('') : '<div class="hb-empty hb-glance-empty">No events</div>'}
                </div>
            </div>`;
    }

    function glanceDayLabel(day) {
        const parsed = parseLocalDate(day);
        if (sameDate(parsed, new Date())) return `Today ${monthDayLabel(parsed)}`;
        if (sameDate(parsed, addDays(new Date(), 1))) return `Tomorrow ${monthDayLabel(parsed)}`;
        return `${weekdayShort(parsed)} ${monthDayLabel(parsed)}`;
    }

    function glanceEventMarkup(event) {
        const color = safeColor(event.color);
        return `
            <article class="hb-glance-event" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <div class="hb-event-time">${escapeHtml(eventTime(event))}</div>
                <button class="hb-event-title" type="button" data-edit-event="${event.id}">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</button>
            </article>`;
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
            </nav>`;
    }

    function topNavMarkup() {
        const nav = [
            ['today', 'Calendar', icons.calendar],
            ['tasks', 'Tasks', icons.tasks],
            ['reminders', 'Reminders', icons.reminders],
        ];
        return `
            <nav class="hb-top-nav" aria-label="App navigation">
                ${nav.map(navButton).join('')}
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
        const currentTimeMarker = currentTimeMarkerMarkup(days, startHour, endHour);
        return `
            <div class="hb-timeline hb-timeline-multi-day" data-timeline-start-hour="${startHour}" data-timeline-end-hour="${endHour}" style="--hb-hour-count:${hours.length};--hb-day-count:${days.length};--hb-day-min-width:${minDayWidth}px;--hb-timeline-min-width:${74 + (days.length * minDayWidth)}px" aria-label="${escapeAttr(calendarRangeLabel(days))} timeline">
                <div class="hb-timeline-head">
                    <div class="hb-timeline-hour"></div>
                    ${days.map((day) => `<button class="hb-timeline-day-head ${sameDate(day, parseLocalDate(state.selectedDay)) ? 'hb-timeline-day-head-active' : ''}" type="button" data-select-day="${dateOnly(day)}" aria-pressed="${sameDate(day, parseLocalDate(state.selectedDay))}"><strong>${escapeHtml(timelineDayHeaderLabel(day))}</strong><span>${escapeHtml(monthDayLabel(day))}</span></button>`).join('')}
                </div>
                ${allDayRowMarkup(days)}
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
                        ${days.map((day) => `<div class="hb-timeline-day-column">${eventsForDay(day).filter((event) => !eventAllDay(event)).map((event) => timedEventMarkup(event, day, startHour, endHour)).join('')}</div>`).join('')}
                    </div>
                    ${currentTimeMarker}
                </div>
            </div>`;
    }

    function currentTimeMarkerMarkup(days, startHour, endHour) {
        const now = new Date();
        const todayIndex = days.findIndex((day) => sameDate(parseLocalDate(day), now));
        if (todayIndex < 0) return '';

        const timelineStart = new Date(now);
        timelineStart.setHours(startHour, 0, 0, 0);
        const timelineEnd = new Date(now);
        timelineEnd.setHours(endHour + 1, 0, 0, 0);
        if (now < timelineStart || now > timelineEnd) return '';

        const minutesFromStart = (now - timelineStart) / 60000;
        const top = Math.max(0, minutesFromStart / 60 * 64);
        const dayColumn = todayIndex + 2;

        return `
            <div class="hb-now-marker" data-today-index="${todayIndex}" style="--hb-now-top:${top.toFixed(2)}px" aria-label="Current time ${escapeAttr(formatTime(now))}">
                <div class="hb-now-label">${escapeHtml(formatTime(now))}</div>
                <div class="hb-now-line" style="grid-column:${dayColumn}"></div>
            </div>`;
    }

    function allDayRowMarkup(days) {
        const dayEvents = days.map((day) => allDayEventsForDay(day));
        if (!dayEvents.some((events) => events.length)) return '';
        return `
            <div class="hb-all-day-row">
                <div class="hb-timeline-hour">All day</div>
                ${dayEvents.map((events) => `
                    <div class="hb-all-day-cell">
                        ${events.map((event) => allDayEventMarkup(event)).join('')}
                    </div>
                `).join('')}
            </div>`;
    }

    function monthGridMarkup(selected) {
        const first = new Date(selected.getFullYear(), selected.getMonth(), 1);
        const leading = first.getDay();
        const daysInMonth = new Date(selected.getFullYear(), selected.getMonth() + 1, 0).getDate();
        const totalCells = Math.ceil((leading + daysInMonth) / 7) * 7;
        const weekCount = totalCells / 7;
        return `
            <div class="hb-month-view">
                <div class="hb-month-grid" style="--hb-month-week-count:${weekCount}">
                    ${Array.from({ length: 7 }, (_, index) => `<div class="hb-month-weekday">${weekdayShort(new Date(2026, 1, index + 1))}</div>`).join('')}
                    ${Array.from({ length: totalCells }, (_, index) => {
                        const dayNumber = index - leading + 1;
                        if (dayNumber < 1 || dayNumber > daysInMonth) return '<div class="hb-month-cell hb-month-cell-empty"></div>';
                        const day = new Date(selected.getFullYear(), selected.getMonth(), dayNumber);
                        return monthCellMarkup(day, dayNumber);
                    }).join('')}
                </div>
            </div>`;
    }

    function monthCellMarkup(day, dayNumber) {
        const events = eventsForDay(day);
        const allDayEvents = events.filter((event) => eventAllDay(event));
        const timedEvents = events.filter((event) => !eventAllDay(event));
        const today = new Date();
        return `
            <div class="hb-month-cell ${sameDate(day, today) ? 'hb-month-cell-active' : ''}">
                <div class="hb-month-cell-head">
                    <button class="hb-month-date" type="button" data-select-day="${dateOnly(day)}" aria-label="${escapeAttr(dayLabel(day))}">
                        <strong>${dayNumber}</strong>
                    </button>
                    ${allDayEvents.length ? `<div class="hb-month-all-day-list">${allDayEvents.map((event) => monthAllDayEventMarkup(event)).join('')}</div>` : ''}
                </div>
                <div class="hb-month-event-list ${timedEvents.length >= 3 ? 'hb-month-event-list-scroll' : ''}">
                    ${timedEvents.map((event) => monthEventMarkup(event)).join('')}
                </div>
            </div>`;
    }

    function monthAllDayEventMarkup(event) {
        const color = safeColor(event.color);
        return `
            <button class="hb-month-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <span class="hb-month-event-title">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthEventMarkup(event) {
        const color = safeColor(event.color);
        return `
            <button class="hb-month-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <span class="hb-month-event-time">${escapeHtml(eventStartTime(event))}</span>
                <span class="hb-month-event-title">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthSwitcherMarkup(selected) {
        const selectedMonth = new Date(selected.getFullYear(), selected.getMonth(), 1);
        return `
            <div class="hb-month-nav" aria-label="Month navigation">
                <button class="hb-icon-button hb-month-arrow" type="button" data-shift-month="-1" aria-label="Previous month">${icons.chevronLeft}</button>
                <button class="hb-month-current" type="button" data-select-month="${dateOnly(selectedMonth)}" aria-pressed="true">${escapeHtml(monthLabel(selectedMonth))}</button>
                <button class="hb-icon-button hb-month-arrow" type="button" data-shift-month="1" aria-label="Next month">${icons.chevronRight}</button>
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
                <div class="hb-modal-actions">
                    <button class="hb-button-ghost" type="button" data-approval-deny="${approval.id}">Deny</button>
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
        const critical = item.is_critical || item.isCritical;
        const taskNotes = kind === 'task' ? taskNotesText(item) : '';
        const subtasks = kind === 'task' ? subtasksFor(item) : [];
        const expanded = kind === 'task' && state.expandedTaskIds.has(String(item.id));
        const expandable = kind === 'task' && (taskNotes || subtasks.length || (!completed && !taskParentId(item)));
        return `
            <article class="hb-item hb-item-${kind} ${completed ? 'hb-item-complete' : ''}" style="${completed ? '' : `background:${hexAlpha(color, .14)};border-color:${hexAlpha(color, .34)}`}">
                ${kind === 'task' && critical ? `<span class="hb-star hb-item-critical-star" style="color:${escapeAttr(color)}">★</span>` : ''}
                <label class="hb-check"><input type="checkbox" data-toggle-${kind}="${item.id}" ${completed ? 'checked' : ''}></label>
                <button class="hb-item-main" type="button" data-edit-${kind}="${item.id}">
                    <div class="hb-item-title">${kind !== 'task' && critical ? `<span class="hb-star" style="color:${escapeAttr(color)}">★</span>` : ''}<span>${escapeHtml(item.title || item.name || 'Untitled')}</span>${expandable ? `<span class="hb-task-expand-icon" data-toggle-task-details="${item.id}" aria-label="${expanded ? 'Hide task details' : 'Show task details'}">${expanded ? '▲' : '▼'}</span>` : ''}</div>
                    ${subtitle ? `<div class="hb-item-meta">${escapeHtml(subtitle)}</div>` : ''}
                </button>
                ${expanded ? taskDetailsMarkup(item, taskNotes, subtasks) : ''}
            </article>`;
    }

    function taskDetailsMarkup(task, notes, subtasks) {
        return `
            <div class="hb-task-details">
                ${notes ? `<div class="hb-task-notes">${escapeHtml(notes)}</div>` : ''}
                <div class="hb-subtask-block">
                    <div class="hb-subtask-header"><strong>Sub-tasks</strong><button class="hb-button-ghost hb-subtask-add" type="button" data-create-subtask="${task.id}">Add sub-task</button></div>
                    ${subtasks.length ? `<div class="hb-subtask-list">${subtasks.map((subtask) => itemMarkup(subtask, 'task')).join('')}</div>` : '<div class="hb-empty hb-surface-soft">No active sub-tasks</div>'}
                </div>
            </div>`;
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
        if (eventAllDay(event)) return '';
        const style = timelineEventStyle(event, day, startHour, endHour);
        if (!style) return '';
        const color = safeColor(event.color);
        return `
            <article class="hb-event hb-timed-event" style="${style.css};background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}" data-duration-minutes="${style.minutes}">
                <div class="hb-event-time">${escapeHtml(eventTime(event))}</div>
                <button class="hb-event-title" type="button" data-edit-event="${event.id}">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</button>
            </article>`;
    }

    function allDayEventMarkup(event) {
        const color = safeColor(event.color);
        return `<button class="hb-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">${event.is_critical || event.isCritical ? '★ ' : ''}${escapeHtml(event.title || event.name || 'Untitled')}</button>`;
    }

    function messageMarkup(message, index = 0, messages = []) {
        const user = message.role === 'user';
        const metadata = typeof message.metadata === 'object' && message.metadata ? message.metadata : {};
        const model = metadata.model || metadata?.model_route?.model || '';
        const content = user ? (message.content || '') : conversationalMessageContent(message.content || '');
        return `
            <article class="hb-message ${user ? 'hb-message-user' : ''}">
                <div class="hb-message-head">
                    ${message.progress ? '<span class="hb-spinner" style="width:13px;height:13px;border-width:2px"></span>' : ''}
                    <span>${user ? 'You' : 'Bean'}</span>
                    ${model ? `<span class="hb-message-model">${escapeHtml(model)}</span>` : ''}
                </div>
                <div class="hb-message-body">${escapeHtml(content)}</div>
            </article>`;
    }

    function pendingApprovalChatMarkup() {
        const approval = pendingApprovalForSession();
        if (!approval) return '';
        return `
            <article class="hb-message hb-message-approval">
                <div class="hb-message-head">
                    <span>Bean</span>
                    <span class="hb-message-model">Approval needed</span>
                </div>
                <div class="hb-message-body">${escapeHtml(approvalDescription(approval))}</div>
                <div class="hb-message-actions">
                    <button class="hb-button-ghost" type="button" data-approval-deny="${approval.id}">Deny</button>
                    <button class="hb-button" type="button" data-approval-approve="${approval.id}">Approve</button>
                </div>
            </article>`;
    }

    function conversationalMessageContent(content) {
        let current = String(content || '').trim();
        for (let index = 0; index < 3; index += 1) {
            const parsed = structuredMessageJson(current);
            if (!parsed || Array.isArray(parsed)) return current;
            const next = ['message', 'content', 'assistant_message', 'response']
                .map((key) => parsed[key])
                .find((value) => typeof value === 'string' && value.trim() !== '');
            if (!next) return current;
            current = next.trim();
        }
        return current;
    }

    function structuredMessageJson(content) {
        const trimmed = String(content || '').trim();
        if (!trimmed) return null;
        const candidates = [trimmed];
        const fenced = trimmed.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i);
        if (fenced) candidates.push(fenced[1].trim());
        const firstBrace = trimmed.indexOf('{');
        const lastBrace = trimmed.lastIndexOf('}');
        if (firstBrace !== -1 && lastBrace > firstBrace) {
            candidates.push(trimmed.slice(firstBrace, lastBrace + 1));
        }
        for (const candidate of candidates) {
            try {
                return JSON.parse(candidate);
            } catch (error) {
                // Try the next candidate form.
            }
        }
        return null;
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
        return itemModalMarkup(modal.type, modal.item, modal.parentTask);
    }

    function itemModalMarkup(kind, item = null, parentTask = null) {
        const editing = Boolean(item);
        const isReminder = kind === 'reminder';
        const isEvent = kind === 'event';
        const when = item ? toDatetimeLocal(item.due_at || item.dueAt || item.remind_at || item.starts_at || item.startsAt) : '';
        const end = item ? toDatetimeLocal(item.ends_at || item.endsAt) : '';
        const workspaceId = item?.workspace_id || item?.workspaceId || currentWorkspaceId();
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="${kind}">
                    ${parentTask ? `<input type="hidden" name="parentTaskId" value="${escapeAttr(parentTask.id)}">` : ''}
                    ${sectionTitle(isEvent ? icons.calendar : isReminder ? icons.reminders : icons.tasks, parentTask ? 'New sub-task' : `${editing ? 'Edit' : 'New'} ${kind}`, parentTask ? `Assigned to ${parentTask.title || parentTask.name || 'task'}` : '')}
                    ${labelInput(`${capitalize(kind)} title`, 'title', 'text', item?.title || item?.name || '', 'required')}
                    ${kind === 'task' ? `<label class="hb-label">Notes<textarea class="hb-textarea" name="notes" placeholder="Add task details">${escapeHtml(item?.notes || '')}</textarea></label>` : ''}
                    ${isEvent ? eventDetailFieldsMarkup(item) : ''}
                    ${isEvent ? eventTimeFieldsMarkup(item, when, end) : labelInput(isReminder ? 'Remind me at' : 'Due date', 'time', 'datetime-local', when, isReminder ? 'required' : '')}
                    <div class="hb-field-row">
                        ${categorySelectMarkup(item)}
                        ${labelInput('Color', 'color', 'color', safeColor(item?.color || categoryColor(item?.category)))}
                    </div>
                    <button class="hb-button-ghost" type="button" data-open-categories>Manage categories</button>
                    ${!isReminder ? `<label class="hb-checkbox-row"><input type="checkbox" name="critical" ${item?.is_critical || item?.isCritical ? 'checked' : ''}> Critical</label>` : ''}
                    ${isEvent ? eventConnectionsMarkup(item, workspaceId, editing) : ''}
                    ${isEvent ? recurrenceFieldsMarkup(item) : ''}
                    ${isReminder ? reminderRecurrenceFieldsMarkup(item) : ''}
                    ${kind === 'task' && editing && !taskParentId(item) ? `<button class="hb-button-ghost" type="button" data-create-subtask="${item.id}">Add sub-task</button>` : ''}
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

    function eventTimeFieldsMarkup(item = null, when = '', end = '') {
        const allDay = eventAllDay(item);
        const startDate = allDay ? storedDateOnly(item?.starts_at || item?.startsAt || new Date()) : dateOnly(item?.starts_at || item?.startsAt || new Date());
        const endDate = allDayEndDateInputValue(item, startDate);
        return `
            <label class="hb-checkbox-row hb-all-day-toggle"><input type="checkbox" name="allDay" data-all-day-toggle ${allDay ? 'checked' : ''}> All day</label>
            <div class="hb-field-row" data-timed-fields ${allDay ? 'hidden' : ''}>
                ${labelInput('Starts at', 'time', 'datetime-local', when, allDay ? 'disabled' : 'required')}
                ${labelInput('Ends at', 'endsAt', 'datetime-local', end, allDay ? 'disabled' : '')}
            </div>
            <div class="hb-field-row" data-all-day-fields ${allDay ? '' : 'hidden'}>
                ${labelInput('Start date', 'allDayStart', 'date', startDate, allDay ? 'required' : 'disabled')}
                ${labelInput('End date', 'allDayEnd', 'date', endDate, allDay ? 'required' : 'disabled')}
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
        const profile = currentAgentProfile();
        const priorities = new Set(profilePriorities(profile));
        const personality = profilePersonality(profile);
        const options = ['balanced', 'coach', 'organizer', 'creative'];
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="agent">
                    ${sectionTitle(icons.tune, 'Edit Bean preferences', 'Review the current settings and save only what you want to change.')}
                    <label class="hb-label">Choose Bean’s personality<select class="hb-select" name="personality">${options.map((option) => `<option value="${option}" ${option === personality ? 'selected' : ''}>${personalityLabel(option)}</option>`).join('')}</select></label>
                    <div class="hb-label">What should Bean prioritize?
                        <div class="hb-tabs">${['Work', 'Family', 'Health', 'Planning', 'Reminders', 'Focus'].map((priority) => `<label class="hb-chip"><input type="checkbox" name="priorities" value="${priority}" ${priorities.has(priority) ? 'checked' : ''}> ${priority}</label>`).join('')}</div>
                    </div>
                    <label class="hb-label">Anything Bean should know?<textarea class="hb-textarea" name="context" placeholder="Example: I work nights, protect family time, and need gentle nudges.">${escapeHtml(profileOnboardingContext(profile))}</textarea></label>
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
        const days = recurrenceDays(item?.metadata);
        return `
            <label class="hb-label">Event recurrence
                <select class="hb-select" name="recurrence">
                    ${recurrenceOptions().map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
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
        const metadata = item?.metadata || {};
        const recurrence = metadata.recurrence || 'none';
        const days = recurrenceDays(metadata);
        const intervalUnit = metadata.interval_unit || metadata.intervalUnit || metadata.unit || 'days';
        return `
            <label class="hb-label">Reminder repeats
                <select class="hb-select" name="reminderRecurrence">
                    ${recurrenceOptions().map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
                </select>
            </label>
            <div class="hb-tabs">
                ${['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map((day) => `<label class="hb-chip"><input type="checkbox" name="reminderSpecificDays" value="${day}" ${days.has(day) ? 'checked' : ''}> ${day.toUpperCase()}</label>`).join('')}
            </div>
            <div class="hb-field-row">
                ${labelInput('Repeat interval', 'reminderInterval', 'number', metadata.interval || '', 'min="1"')}
                <label class="hb-label">Interval unit<select class="hb-select" name="reminderIntervalUnit"><option value="days">Days</option><option value="weeks" ${intervalUnit === 'weeks' ? 'selected' : ''}>Weeks</option><option value="months" ${intervalUnit === 'months' ? 'selected' : ''}>Months</option></select></label>
            </div>`;
    }

    function recurrenceOptions() {
        return ['none', 'daily', 'weekly', 'monthly', 'specific_days', 'interval'];
    }

    function recurrenceDays(metadata = {}) {
        return new Set(normalizeList(metadata?.specific_days || metadata?.specificDays || metadata?.days));
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
            if (button.dataset.nav === 'bean') {
                state.chatExpanded = true;
                state.error = '';
                state.notice = '';
                render();
                scrollChatToBottom();
                return;
            }
            state.selected = button.dataset.nav;
            if (state.selected !== 'bean') state.chatExpanded = false;
            state.error = '';
            state.notice = '';
            render();
            scrollChatToBottom();
        }));
        mount.querySelectorAll('[data-toggle-chat-expand]').forEach((button) => button.addEventListener('click', () => {
            state.chatExpanded = !state.chatExpanded;
            render();
            scrollChatToBottom();
        }));
        mount.querySelector('[data-today]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.selectedDay = dateOnly(new Date());
            resetCalendarWindow(new Date());
            state.showMonth = false;
            render();
        });
        mount.querySelector('[data-calendar-month]')?.addEventListener('click', () => {
            const today = new Date();
            state.selected = 'today';
            state.selectedDay = dateOnly(today);
            resetCalendarWindow(today);
            state.showMonth = true;
            render();
        });
        mount.querySelectorAll('[data-select-day]').forEach((button) => button.addEventListener('click', () => {
            state.selectedDay = button.dataset.selectDay;
            state.showMonth = false;
            render();
        }));
        mount.querySelectorAll('[data-select-month]').forEach((button) => button.addEventListener('click', () => {
            selectMonth(button.dataset.selectMonth);
        }));
        mount.querySelectorAll('[data-shift-month]').forEach((button) => button.addEventListener('click', () => {
            shiftMonth(Number(button.dataset.shiftMonth || 0));
        }));
        mount.querySelector('[data-refresh-calendar]')?.addEventListener('click', refreshCalendar);
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
        mount.querySelectorAll('[data-toggle-task-details]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            toggleTaskDetails(button.dataset.toggleTaskDetails);
        }));
        mount.querySelectorAll('[data-create-subtask]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const parent = findById(state.tasks, button.dataset.createSubtask);
            if (parent) openModal('task', { parentTask: parent });
        }));
        mount.querySelector('[data-create-workspace]')?.addEventListener('click', () => openModal('workspace', { mode: 'create' }));
        mount.querySelector('[data-accept-workspace]')?.addEventListener('click', () => openModal('workspace', { mode: 'accept' }));
        mount.querySelectorAll('[data-rename-workspace]').forEach((button) => button.addEventListener('click', () => openModal('workspace', { mode: 'rename', workspace: findWorkspace(button.dataset.renameWorkspace) })));
        mount.querySelectorAll('[data-invite-workspace]').forEach((button) => button.addEventListener('click', () => openModal('workspace', { mode: 'invite', workspace: findWorkspace(button.dataset.inviteWorkspace) })));
        mount.querySelectorAll('[data-leave-workspace]').forEach((button) => button.addEventListener('click', () => leaveWorkspace(button.dataset.leaveWorkspace)));
        mount.querySelectorAll('[data-remove-member]').forEach((button) => button.addEventListener('click', () => removeMember(button.dataset.workspaceId, button.dataset.removeMember)));
        mount.querySelectorAll('[data-member-role]').forEach((select) => select.addEventListener('change', () => updateMemberRole(select.dataset.workspaceId, select.dataset.memberRole, select.value)));
        mount.querySelectorAll('[data-set-workspace]').forEach((button) => button.addEventListener('click', () => setWorkspace(button.dataset.setWorkspace)));
        mount.querySelector('[data-workspace-select]')?.addEventListener('change', (event) => setWorkspace(event.currentTarget.value));
        mount.querySelector('[data-top-workspace-select]')?.addEventListener('change', (event) => setWorkspace(event.currentTarget.value));
        mount.querySelectorAll('[data-pref]').forEach((input) => input.addEventListener('change', updateNotificationPrefs));
        mount.querySelectorAll('[data-google-action]').forEach((button) => button.addEventListener('click', () => googleAction(button.dataset.googleAction)));
        mount.querySelectorAll('[data-google-calendar]').forEach((input) => input.addEventListener('change', updateGoogleCalendarSelection));
        mount.querySelectorAll('[data-approval-approve]').forEach((button) => button.addEventListener('click', () => approveApproval(button.dataset.approvalApprove, false)));
        mount.querySelectorAll('[data-approval-deny]').forEach((button) => button.addEventListener('click', () => denyApproval(button.dataset.approvalDeny)));
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
        mount.querySelectorAll('[data-voice-hold]').forEach((button) => {
            button.addEventListener('pointerdown', handleVoicePointerDown);
            button.addEventListener('pointerup', handleVoicePointerUp);
            button.addEventListener('pointercancel', handleVoicePointerCancel);
            button.addEventListener('click', handleVoiceClick);
            button.addEventListener('contextmenu', handleVoiceContextMenu);
        });
        bindTimelineHorizontalScroll();
        mount.querySelector('[data-new-session]')?.addEventListener('click', newSession);
        scrollTimelineToSelected();
        scrollChatToBottom();
    }

    function openModal(type, itemOrOptions = null) {
        state.modal = itemOrOptions && type === 'workspace'
            ? { type, mode: itemOrOptions.mode, workspace: itemOrOptions.workspace }
            : itemOrOptions && type === 'task' && itemOrOptions.parentTask
                ? { type, item: null, parentTask: itemOrOptions.parentTask }
            : { type, item: itemOrOptions };
        render();
    }

    function toggleTaskDetails(id) {
        if (!id) return;
        const key = String(id);
        if (state.expandedTaskIds.has(key)) {
            state.expandedTaskIds.delete(key);
        } else {
            state.expandedTaskIds.add(key);
        }
        render();
    }

    function bindModalActions() {
        mount.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => {
            state.modal = null;
            render();
        }));
        mount.querySelectorAll('[data-modal-delete]').forEach((button) => button.addEventListener('click', deleteModalItem));
        mount.querySelector('[data-modal-form]')?.addEventListener('submit', submitModal);
        mount.querySelector('[data-open-categories]')?.addEventListener('click', () => openModal('categories'));
        mount.querySelectorAll('[data-category-row]').forEach((form) => form.addEventListener('submit', saveCategoryRow));
        mount.querySelectorAll('[data-delete-category]').forEach((button) => button.addEventListener('click', () => deleteCategory(button.dataset.deleteCategory)));
        mount.querySelectorAll('[data-category-select]').forEach((select) => select.addEventListener('change', syncSelectedCategoryColor));
        mount.querySelectorAll('[data-all-day-toggle]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => toggleAllDayFields(checkbox));
            toggleAllDayFields(checkbox);
        });
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
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const parentTaskId = data.parentTaskId || taskParentId(item);
            const body = {
                title: data.title,
                type: 'todo',
                due_at: fromDatetimeLocal(data.time),
                notes: data.notes || null,
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                metadata: {
                    ...existingMetadata,
                    ...(parentTaskId ? { parent_task_id: Number(parentTaskId) } : {}),
                },
            };
            item ? await api(`/tasks/${item.id}`, { method: 'PATCH', body }) : await api('/tasks', { method: 'POST', body });
        } else if (kind === 'reminder') {
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const body = {
                title: data.title,
                remind_at: fromDatetimeLocal(data.time),
                status: item?.status || 'pending',
                category: data.category || null,
                color,
                metadata: {
                    ...existingMetadata,
                    recurrence: data.reminderRecurrence || 'none',
                    specific_days: Array.from(form.querySelectorAll('input[name="reminderSpecificDays"]:checked')).map((input) => input.value),
                    interval: data.reminderInterval ? Number(data.reminderInterval) : null,
                    interval_unit: data.reminderIntervalUnit || null,
                },
            };
            item ? await api(`/reminders/${item.id}`, { method: 'PATCH', body }) : await api('/reminders', { method: 'POST', body });
        } else if (kind === 'event') {
            const syncTo = Array.from(form.querySelectorAll('input[name="syncWorkspaceIds"]:checked')).map((input) => Number(input.value)).filter(Boolean);
            const allDay = form.elements.allDay?.checked || false;
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const body = {
                title: data.title,
                description: data.description || null,
                location: data.location || null,
                starts_at: allDay ? fromDateInputStart(data.allDayStart) : fromDatetimeLocal(data.time),
                ends_at: allDay ? fromDateInputEndInclusive(data.allDayEnd || data.allDayStart) : fromDatetimeLocal(data.endsAt),
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                recurrence: data.recurrence || 'none',
                status: data.status || 'confirmed',
                sync_to_workspace_ids: syncTo,
                metadata: {
                    ...existingMetadata,
                    recurrence: data.recurrence || 'none',
                    specific_days: Array.from(form.querySelectorAll('input[name="specificDays"]:checked')).map((input) => input.value),
                    interval: data.interval ? Number(data.interval) : null,
                    interval_unit: data.intervalUnit || null,
                    all_day: allDay,
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
        const body = kind === 'event' ? deleteEventPayload(state.modal?.item) : null;
        const snapshot = snapshotLists(kind);
        state.modal = null;
        removeCachedItem(kind, id);
        render();
        try {
            await api(path, { method: 'DELETE', ...(body ? { body } : {}) });
            await refreshOnly(true, { skipCalendarSync: kind === 'event' });
        } catch (error) {
            restoreSnapshot(kind, snapshot);
            state.error = friendlyError(error, `delete that ${kind}`);
            render();
        }
    }

    function snapshotLists(kind) {
        if (kind === 'task') return state.tasks.slice();
        if (kind === 'reminder') return state.reminders.slice();
        if (kind === 'event') return state.calendar.slice();
        return [];
    }

    function restoreSnapshot(kind, snapshot) {
        if (kind === 'task') state.tasks = snapshot;
        if (kind === 'reminder') state.reminders = snapshot;
        if (kind === 'event') state.calendar = snapshot;
    }

    function removeCachedItem(kind, id) {
        const matches = (item) => String(item.id) === String(id);
        if (kind === 'task') state.tasks = state.tasks.filter((item) => !matches(item));
        if (kind === 'reminder') state.reminders = state.reminders.filter((item) => !matches(item));
        if (kind === 'event') state.calendar = state.calendar.filter((item) => !matches(item));
    }

    function deleteEventPayload(event = null) {
        if (!event) return {};
        const workspaceIds = normalizeList(event.linked_workspace_ids || event.linkedWorkspaceIds)
            .concat([event.workspace_id || event.workspaceId])
            .map((id) => Number(id))
            .filter(Boolean);
        const uniqueWorkspaceIds = Array.from(new Set(workspaceIds));
        return {
            delete_from_workspace_ids: uniqueWorkspaceIds,
            recurring_delete_mode: eventIsRecurring(event) ? 'all' : undefined,
            recurring_occurrence_date: eventAllDay(event) ? storedDateOnly(event.starts_at || event.startsAt || new Date()) : dateOnly(event.starts_at || event.startsAt || new Date()),
        };
    }

    function eventIsRecurring(event = null) {
        const recurrence = event?.recurrence || event?.metadata?.recurrence || 'none';
        return recurrence && recurrence !== 'none';
    }

    function toggleAllDayFields(checkbox) {
        const form = checkbox.closest('form');
        if (!form) return;
        const allDay = checkbox.checked;
        const timedFields = form.querySelector('[data-timed-fields]');
        const allDayFields = form.querySelector('[data-all-day-fields]');
        setFieldGroupState(timedFields, !allDay);
        setFieldGroupState(allDayFields, allDay);
    }

    function setFieldGroupState(group, enabled) {
        if (!group) return;
        group.hidden = !enabled;
        group.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !enabled;
            if (field.name === 'time' || field.name === 'allDayStart' || field.name === 'allDayEnd') {
                field.required = enabled && field.name !== 'endsAt';
            }
        });
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

    function handleVoicePointerDown(event) {
        if (state.busy || (typeof event.button === 'number' && event.button !== 0)) return;
        voiceHoldPressed = true;
        voiceStartPending = true;
        startVoiceHoldInput().then((started) => {
            voiceStartPending = false;
            voiceHoldActive = started;
            if (started && !voiceHoldPressed) {
                finishVoiceHoldInput(false);
            }
        }).catch(() => {
            voiceStartPending = false;
            voiceHoldActive = false;
            voiceHoldPressed = false;
            state.voiceStatus = 'Chrome could not start voice input. Check microphone permissions and try again.';
            state.voiceStatusTone = 'error';
            render();
        });
        suppressNextSendClick = true;
        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
    }

    function handleVoicePointerUp(event) {
        voiceHoldPressed = false;
        if (voiceStartPending && !state.voiceListening) {
            event.preventDefault();
            suppressNextSendClick = true;
            return;
        }
        if (!voiceHoldActive && !state.voiceListening) return;
        event.preventDefault();
        suppressNextSendClick = true;
        sendVoiceDraftImmediately();
    }

    function handleVoicePointerCancel() {
        voiceHoldPressed = false;
        if (voiceHoldActive || state.voiceListening) {
            finishVoiceHoldInput(false);
            suppressNextSendClick = true;
        }
    }

    function handleVoiceClick(event) {
        if (!suppressNextSendClick) return;
        event.preventDefault();
        event.stopPropagation();
        suppressNextSendClick = false;
    }

    function handleVoiceContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();
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
        state.voiceStatus = '';
        state.voiceStatusTone = '';
        state.chatRunState = 'Working…';
        render();
        try {
            if (!state.session?.id) {
                const onboarding = needsBeanOnboarding();
                state.session = await api('/assistant/sessions', {
                    method: 'POST',
                    body: { title: onboarding ? 'Welcome to Bean' : 'Workspace chat', runtime_mode: onboarding ? 'onboarding' : 'chat', workspace_id: state.user?.active_workspace?.id || state.summary?.workspace?.id || null },
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

    async function startVoiceHoldInput() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!window.isSecureContext) {
            state.voiceStatus = 'Voice input needs HTTPS, localhost, or another secure browser context in Chrome.';
            state.voiceStatusTone = 'error';
            render();
            return false;
        }
        if (!SpeechRecognition) {
            state.voiceStatus = 'Voice input is not available in this browser. Chrome desktop supports it best; iPhone/iPad Chrome may not.';
            state.voiceStatusTone = 'error';
            render();
            return false;
        }
        if (state.voiceListening && state.voiceRecognition) {
            return true;
        }
        const hasMicrophoneAccess = await requestMicrophoneAccess();
        if (!hasMicrophoneAccess || !voiceHoldPressed) {
            return false;
        }
        const recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.onaudiostart = () => {
            setVoiceStatus('Microphone is on. Keep holding and speak.', '');
        };
        recognition.onspeechstart = () => {
            setVoiceStatus('Listening… release to send.', '');
        };
        recognition.onspeechend = () => {
            setVoiceStatus('Got it. Release to send.', '');
        };
        recognition.onresult = (event) => {
            const transcript = Array.from(event.results).map((result) => result[0]?.transcript || '').join(' ').trim();
            state.voiceDraft = transcript;
            if (transcript) {
                setVoiceStatus('Release to send.', '');
            }
            const textarea = mount.querySelector('textarea[name="message"]');
            if (textarea) {
                textarea.value = transcript;
                resizeChatInput(textarea);
            }
        };
        recognition.onend = () => {
            const shouldSubmit = voiceSubmitOnEnd;
            const content = state.voiceDraft.trim();
            state.voiceListening = false;
            state.voiceRecognition = null;
            voiceHoldActive = false;
            voiceHoldPressed = false;
            voiceSubmitOnEnd = false;
            if (shouldSubmit && content && !state.busy) {
                state.voiceStatus = '';
                state.voiceStatusTone = '';
                sendChatContent(content);
                return;
            }
            if (shouldSubmit && !content) {
                state.voiceStatus = 'I did not catch anything. Hold the Bean button, speak, then release.';
                state.voiceStatusTone = 'error';
            }
            render();
        };
        recognition.onerror = (event) => {
            state.voiceListening = false;
            state.voiceRecognition = null;
            voiceHoldActive = false;
            voiceHoldPressed = false;
            voiceSubmitOnEnd = false;
            state.voiceStatus = voiceErrorMessage(event.error);
            state.voiceStatusTone = 'error';
            render();
        };
        state.voiceRecognition = recognition;
        state.voiceListening = true;
        state.error = '';
        setVoiceStatus('Starting microphone…', '');
        try {
            recognition.start();
        } catch (error) {
            state.voiceListening = false;
            state.voiceRecognition = null;
            state.voiceStatus = 'Voice input is already active. Release and try again.';
            state.voiceStatusTone = 'error';
            render();
            return false;
        }
        markVoiceListening();
        clearVoiceDraft();
        return true;
    }

    async function requestMicrophoneAccess() {
        if (!navigator.mediaDevices?.getUserMedia) {
            setVoiceStatus('This browser cannot request microphone access for the web app.', 'error');
            return false;
        }

        setVoiceStatus('Chrome should ask for microphone access now…', '');
        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            setVoiceStatus('Microphone permission granted. Starting speech recognition…', '');
            return true;
        } catch (error) {
            state.voiceStatus = microphoneAccessErrorMessage(error);
            state.voiceStatusTone = 'error';
            render();
            return false;
        } finally {
            stream?.getTracks().forEach((track) => track.stop());
        }
    }

    function finishVoiceHoldInput(shouldSubmit) {
        voiceSubmitOnEnd = shouldSubmit;
        if (!state.voiceRecognition) {
            state.voiceListening = false;
            voiceHoldActive = false;
            voiceHoldPressed = false;
            voiceSubmitOnEnd = false;
            if (!shouldSubmit) render();
            return;
        }
        try {
            state.voiceRecognition.stop();
        } catch (error) {
            state.voiceListening = false;
            state.voiceRecognition = null;
            voiceHoldActive = false;
            voiceHoldPressed = false;
            voiceSubmitOnEnd = false;
            state.voiceStatus = 'Voice input stopped. Hold the Bean button to try again.';
            state.voiceStatusTone = 'error';
            render();
        }
    }

    function sendVoiceDraftImmediately() {
        const content = currentVoiceContent();
        if (!content) {
            finishVoiceHoldInput(true);
            return;
        }

        const recognition = state.voiceRecognition;
        voiceSubmitOnEnd = false;
        state.voiceListening = false;
        state.voiceRecognition = null;
        voiceHoldActive = false;
        voiceHoldPressed = false;
        state.voiceStatus = '';
        state.voiceStatusTone = '';
        clearVoiceDraft();

        if (recognition) {
            recognition.onend = null;
            recognition.onerror = null;
            try {
                recognition.stop();
            } catch (error) {
                // The transcript is already captured; sending should not depend on stop().
            }
        }

        sendChatContent(content);
    }

    function currentVoiceContent() {
        return (state.voiceDraft || mount.querySelector('textarea[name="message"]')?.value || '').trim();
    }

    function markVoiceListening() {
        mount.querySelectorAll('.hb-chat-dock').forEach((dock) => dock.classList.add('hb-chat-dock-listening'));
        setVoiceStatus('Hold and speak. Release to send.', '');
        const textarea = mount.querySelector('textarea[name="message"]');
        if (textarea) {
            textarea.placeholder = 'Listening… release to send';
            textarea.focus();
        }
    }

    function clearVoiceDraft() {
        state.voiceDraft = '';
        const textarea = mount.querySelector('textarea[name="message"]');
        if (textarea) {
            textarea.value = '';
            resizeChatInput(textarea);
        }
    }

    function setVoiceStatus(message, tone = '') {
        state.voiceStatus = message;
        state.voiceStatusTone = tone;
        mount.querySelectorAll('[data-voice-status]').forEach((element) => {
            element.hidden = !message;
            element.textContent = message;
            element.classList.toggle('hb-chat-voice-status-error', tone === 'error');
        });
    }

    function voiceErrorMessage(error) {
        if (error === 'not-allowed' || error === 'service-not-allowed') {
            return 'Speech recognition was not allowed after microphone access. Check Chrome site settings for Microphone and try again.';
        }
        if (error === 'no-speech') {
            return 'I did not hear speech. Hold the Bean button, speak clearly, then release.';
        }
        if (error === 'audio-capture') {
            return 'No microphone was found. Check your input device and browser microphone permission.';
        }
        if (error === 'network') {
            return 'Chrome could not reach speech recognition. Check your connection and try again.';
        }
        return 'Voice input stopped. You can still type to Bean.';
    }

    function microphoneAccessErrorMessage(error) {
        if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
            return 'Chrome did not grant microphone access. Click the lock icon in the address bar, set Microphone to Allow, then try again.';
        }
        if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') {
            return 'Chrome could not find a microphone. Check your input device and system privacy settings.';
        }
        if (error?.name === 'NotReadableError' || error?.name === 'TrackStartError') {
            return 'Chrome could not start the microphone. Another app may be using it, or system privacy settings may be blocking it.';
        }
        return 'Chrome could not request microphone access. Check browser and system microphone permissions.';
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
            const onboarding = needsBeanOnboarding();
            state.session = await api('/assistant/sessions', {
                method: 'POST',
                body: { title: onboarding ? 'Welcome to Bean' : 'Workspace chat', runtime_mode: onboarding ? 'onboarding' : 'chat', workspace_id: state.user?.active_workspace?.id || state.summary?.workspace?.id || null },
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

    async function refreshOnly(shouldRender = true, options = {}) {
        try {
            const calendarPath = options.skipCalendarSync ? '/calendar-events?skip_google_sync=1' : '/calendar-events';
            const [summary, tasks, pastTasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api('/today'),
                api('/tasks'),
                api('/tasks/past'),
                api('/reminders'),
                api(calendarPath),
                api('/event-categories'),
                api('/google-calendar/status').catch(() => state.googleStatus),
            ]);
            state.summary = summary;
            state.tasks = mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks));
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

    async function refreshCalendar() {
        if (state.calendarRefreshing) return;
        state.calendarRefreshing = true;
        state.error = '';
        render();
        try {
            const [calendar, googleStatus] = await Promise.all([
                api('/calendar-events'),
                api('/google-calendar/status').catch(() => state.googleStatus),
            ]);
            state.calendar = normalizeList(calendar);
            state.googleStatus = googleStatus;
            state.notice = 'Calendar refreshed.';
        } catch (error) {
            state.error = friendlyError(error, 'refresh the calendar');
        } finally {
            state.calendarRefreshing = false;
            render();
        }
    }

    function selectMonth(value) {
        const month = parseLocalDate(value);
        const selected = parseLocalDate(state.selectedDay);
        const daysInTargetMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        state.selectedDay = dateOnly(new Date(month.getFullYear(), month.getMonth(), Math.min(selected.getDate(), daysInTargetMonth)));
        state.showMonth = true;
        render();
    }

    function shiftMonth(amount) {
        const selected = parseLocalDate(state.selectedDay);
        const target = new Date(selected.getFullYear(), selected.getMonth() + amount, 1);
        selectMonth(dateOnly(target));
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
        if (!id || String(id) === String(currentWorkspaceId())) return;
        const workspace = findWorkspace(id);
        try {
            await api('/workspaces/default', { method: 'PATCH', body: { workspace_id: Number(id) } });
            await loadSignedIn();
            state.notice = `Switched to ${workspaceDisplayName(workspace)}.`;
            render();
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

    function activeTopLevelTasks() {
        return activeTasks().filter((task) => !taskParentId(task));
    }

    function taskParentId(task) {
        const metadata = typeof task?.metadata === 'object' && task.metadata ? task.metadata : {};
        return metadata.parent_task_id || metadata.parentTaskId || metadata.parent_id || metadata.parentId || null;
    }

    function subtasksFor(task, includeCompleted = false) {
        return state.tasks
            .filter((candidate) => String(taskParentId(candidate) || '') === String(task?.id || ''))
            .filter((candidate) => includeCompleted || !taskCompleted(candidate))
            .sort(compareTasks);
    }

    function taskNotesText(task) {
        return String(task?.notes || task?.metadata?.notes || '').trim();
    }

    function compareTasks(a, b) {
        const aDue = parseLocalDate(a?.due_at || a?.dueAt || '');
        const bDue = parseLocalDate(b?.due_at || b?.dueAt || '');
        const aHasDue = Boolean(a?.due_at || a?.dueAt);
        const bHasDue = Boolean(b?.due_at || b?.dueAt);
        if (aHasDue && bHasDue && aDue.getTime() !== bDue.getTime()) return aDue - bDue;
        if (aHasDue !== bHasDue) return aHasDue ? -1 : 1;
        return Number(a?.id || 0) - Number(b?.id || 0);
    }

    function pendingReminders() {
        return state.reminders.filter((reminder) => !reminderCompleted(reminder));
    }

    function criticalItems() {
        return [
            ...state.tasks.filter((item) => !taskCompleted(item) && (item.is_critical || item.isCritical)),
            ...state.calendar.filter((item) => (item.is_critical || item.isCritical) && (eventAllDay(item) ? eventIntersectsDay(item, new Date()) : isSameDay(item.starts_at || item.startsAt, new Date()))),
        ];
    }

    function workspaces() {
        return normalizeList(state.user?.workspaces || state.summary?.workspaces);
    }

    function currentWorkspaceId() {
        return state.user?.active_workspace?.id || state.user?.activeWorkspace?.id || state.summary?.workspace?.id || workspaces().find((workspace) => workspace.active || workspace.is_default || workspace.isDefault)?.id || workspaces()[0]?.id || '';
    }

    function workspaceDisplayName(workspace = {}) {
        if (!workspace) return 'Workspace';
        if (workspace.type === 'personal' || workspace.kind === 'personal') return 'Personal';
        return workspace.name || 'Workspace';
    }

    function workspaceTypeLabel(workspace = {}) {
        const type = workspace.type || workspace.kind || 'workspace';
        return type === 'personal' ? 'private workspace' : `${type} workspace`;
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
        return task.due_at || task.dueAt ? formatDateTime(task.due_at || task.dueAt) : '';
    }

    function reminderSubtitle(reminder) {
        const bits = [];
        if (reminder.category) bits.push(reminder.category);
        if (reminder.remind_at || reminder.due_at || reminder.dueAt) bits.push(formatDateTime(reminder.remind_at || reminder.due_at || reminder.dueAt));
        return bits.join(' · ') || 'No reminder time';
    }

    function eventsForDay(day) {
        return state.calendar
            .filter((event) => eventAllDay(event) ? eventIntersectsDay(event, day) : isSameDay(event.starts_at || event.startsAt, day))
            .sort((a, b) => new Date(a.starts_at || a.startsAt || 0) - new Date(b.starts_at || b.startsAt || 0));
    }

    function allDayEventsForDay(day) {
        return eventsForDay(day).filter((event) => eventAllDay(event));
    }

    function eventsForDays(days) {
        return days.flatMap((day) => eventsForDay(day));
    }

    function eventTime(event) {
        if (eventAllDay(event)) return 'All day';
        const start = event.starts_at || event.startsAt;
        const end = event.ends_at || event.endsAt;
        if (!start) return 'All day';
        const startLabel = formatTime(start);
        return end ? `${startLabel} – ${formatTime(end)}` : startLabel;
    }

    function eventStartTime(event) {
        if (eventAllDay(event)) return 'All day';
        const start = event.starts_at || event.startsAt;
        return start ? formatTime(start) : 'All day';
    }

    function timelineEventStyle(event, day, startHour, endHour) {
        if (eventAllDay(event)) return null;
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

    function eventAllDay(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        const value = event?.all_day ?? event?.allDay ?? metadata.all_day ?? metadata.allDay;
        return value === true || value === 1 || ['true', '1', 'yes'].includes(String(value || '').toLowerCase());
    }

    function eventIntersectsDay(event, day) {
        const startValue = event.starts_at || event.startsAt;
        if (!startValue) return false;
        if (eventAllDay(event)) {
            const dayValue = dateOnly(day);
            const startDate = storedDateOnly(startValue);
            const endValue = event.ends_at || event.endsAt;
            const endDate = endValue ? storedDateOnly(endValue) : dateOnly(addDays(startDate, 1));
            return startDate <= dayValue && endDate > dayValue;
        }
        const dayStart = new Date(parseLocalDate(day));
        dayStart.setHours(0, 0, 0, 0);
        const dayEnd = addDays(dayStart, 1);
        const start = new Date(startValue);
        const endValue = event.ends_at || event.endsAt;
        const end = endValue ? new Date(endValue) : addDays(start, 1);
        return start < dayEnd && end > dayStart;
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

    function visibleCalendarDays(start) {
        ensureCalendarWindowCovers(start);
        const firstVisible = parseLocalDate(state.calendarWindowStart);
        const dayCount = Math.max(calendarInitialWindowDays, Number(state.calendarWindowDayCount || calendarInitialWindowDays));
        return Array.from({ length: dayCount }, (_, index) => addDays(firstVisible, index));
    }

    function initialCalendarWindowStart(date) {
        return dateOnly(addDays(weekDays(parseLocalDate(date))[0], -14));
    }

    function resetCalendarWindow(date) {
        state.calendarWindowStart = initialCalendarWindowStart(date);
        state.calendarWindowDayCount = calendarInitialWindowDays;
        state.timelineScrollRestore = null;
    }

    function ensureCalendarWindowCovers(date) {
        const selected = parseLocalDate(date);
        let start = state.calendarWindowStart ? parseLocalDate(state.calendarWindowStart) : parseLocalDate(initialCalendarWindowStart(selected));
        let dayCount = Math.max(calendarInitialWindowDays, Number(state.calendarWindowDayCount || calendarInitialWindowDays));
        let changed = false;

        while (selected < addDays(start, 14)) {
            start = addDays(start, -calendarWindowChunkDays);
            dayCount += calendarWindowChunkDays;
            changed = true;
        }

        while (selected > addDays(start, dayCount - 15)) {
            dayCount += calendarWindowChunkDays;
            changed = true;
        }

        if (!state.calendarWindowStart || changed) {
            state.calendarWindowStart = dateOnly(start);
            state.calendarWindowDayCount = dayCount;
        }
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

    function allDayEndDateInputValue(item, fallbackStartDate) {
        const end = item?.ends_at || item?.endsAt;
        if (!end || !eventAllDay(item)) return fallbackStartDate;
        const inclusive = parseLocalDate(storedDateOnly(end));
        inclusive.setDate(inclusive.getDate() - 1);
        return dateOnly(inclusive);
    }

    function storedDateOnly(value) {
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value)) return value.slice(0, 10);
        return dateOnly(value);
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

    function sameMonth(a, b) {
        const first = parseLocalDate(a);
        const second = parseLocalDate(b);
        return first.getFullYear() === second.getFullYear() && first.getMonth() === second.getMonth();
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

    function topbarTodayLabel(date) {
        return parseLocalDate(date).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric' });
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

    function fromDateInputStart(value) {
        if (!value) return null;
        return `${value}T00:00:00.000Z`;
    }

    function fromDateInputEndInclusive(value) {
        if (!value) return null;
        const end = parseLocalDate(value);
        end.setDate(end.getDate() + 1);
        end.setHours(0, 0, 0, 0);
        return `${dateOnly(end)}T00:00:00.000Z`;
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

    function scrollTimelineToSelected() {
        if (state.selected !== 'today' || state.showMonth) return;
        requestAnimationFrame(() => {
            const timeline = mount.querySelector('.hb-timeline');
            const selected = mount.querySelector('.hb-timeline-day-head-active');
            if (!timeline) return;
            const restore = state.timelineScrollRestore;
            if (restore) {
                state.timelineScrollRestore = null;
                const maxScrollLeft = Math.max(0, timeline.scrollWidth - timeline.clientWidth);
                const maxScrollTop = Math.max(0, timeline.scrollHeight - timeline.clientHeight);
                timeline.scrollLeft = Math.min(Math.max(restore.left || 0, 0), maxScrollLeft);
                timeline.scrollTop = Math.min(Math.max(restore.top || 0, 0), maxScrollTop);
                return;
            }
            if (!selected) return;
            timeline.scrollLeft = Math.max(0, selected.offsetLeft - 74);
            scrollTimelineToCurrentTime(timeline);
        });
    }

    function scrollTimelineToCurrentTime(timeline) {
        const marker = timeline.querySelector('.hb-now-marker');
        const body = timeline.querySelector('.hb-timeline-body');
        if (!marker || !body) return;
        const markerTop = body.offsetTop + marker.offsetTop;
        const target = markerTop - Math.round(timeline.clientHeight * 0.38);
        const maxScrollTop = Math.max(0, timeline.scrollHeight - timeline.clientHeight);
        timeline.scrollTop = Math.min(Math.max(target, 0), maxScrollTop);
    }

    function bindTimelineHorizontalScroll() {
        const timeline = mount.querySelector('.hb-timeline');
        if (!timeline) return;
        timeline.addEventListener('pointerdown', handleTimelinePointerDown);
        timeline.addEventListener('pointermove', handleTimelinePointerMove, { passive: false });
        timeline.addEventListener('pointerup', handleTimelinePointerEnd);
        timeline.addEventListener('pointercancel', handleTimelinePointerEnd);
        timeline.addEventListener('click', handleTimelineClick, true);
        timeline.addEventListener('scroll', handleTimelineScroll, { passive: true });
        timeline.addEventListener('wheel', handleTimelineWheel, { passive: false });
    }

    function handleTimelinePointerDown(event) {
        const timeline = event.currentTarget;
        if (state.showMonth || !timelineCanScrollHorizontally(timeline) || (typeof event.button === 'number' && event.button !== 0)) return;
        timelineDrag = {
            timeline,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            scrollLeft: timeline.scrollLeft,
            active: false,
        };
    }

    function handleTimelinePointerMove(event) {
        if (!timelineDrag || timelineDrag.pointerId !== event.pointerId) return;
        const deltaX = event.clientX - timelineDrag.startX;
        const deltaY = event.clientY - timelineDrag.startY;
        if (!timelineDrag.active) {
            if (Math.max(Math.abs(deltaX), Math.abs(deltaY)) < 8) return;
            if (Math.abs(deltaX) <= Math.abs(deltaY)) {
                timelineDrag = null;
                return;
            }
            timelineDrag.active = true;
            timelineDrag.timeline.classList.add('hb-timeline-dragging');
            timelineDrag.timeline.setPointerCapture?.(event.pointerId);
        }
        event.preventDefault();
        const maxScrollLeft = Math.max(0, timelineDrag.timeline.scrollWidth - timelineDrag.timeline.clientWidth);
        timelineDrag.timeline.scrollLeft = Math.min(Math.max(timelineDrag.scrollLeft - deltaX, 0), maxScrollLeft);
        maybeExtendTimelineWindow(timelineDrag.timeline);
    }

    function handleTimelinePointerEnd(event) {
        if (!timelineDrag || timelineDrag.pointerId !== event.pointerId) return;
        const wasDragging = timelineDrag.active;
        const timeline = timelineDrag.timeline;
        timeline.classList.remove('hb-timeline-dragging');
        timeline.releasePointerCapture?.(event.pointerId);
        timelineDrag = null;
        maybeExtendTimelineWindow(timeline);
        if (!wasDragging) return;
        timelineSuppressClick = true;
        window.setTimeout(() => { timelineSuppressClick = false; }, 0);
    }

    function handleTimelineClick(event) {
        if (!timelineSuppressClick) return;
        event.preventDefault();
        event.stopPropagation();
        timelineSuppressClick = false;
    }

    function handleTimelineWheel(event) {
        const timeline = event.currentTarget;
        if (!timelineCanScrollHorizontally(timeline)) return;
        const horizontalDelta = Math.abs(event.deltaX) > 0 ? event.deltaX : event.shiftKey ? event.deltaY : 0;
        if (!horizontalDelta || Math.abs(horizontalDelta) <= Math.abs(event.deltaY) && !event.shiftKey) return;
        const maxScrollLeft = Math.max(0, timeline.scrollWidth - timeline.clientWidth);
        const nextScrollLeft = Math.min(Math.max(timeline.scrollLeft + horizontalDelta, 0), maxScrollLeft);
        if (nextScrollLeft === timeline.scrollLeft) {
            maybeExtendTimelineWindow(timeline, horizontalDelta < 0 ? 'previous' : 'next');
            return;
        }
        event.preventDefault();
        timeline.scrollLeft = nextScrollLeft;
        maybeExtendTimelineWindow(timeline);
    }

    function timelineCanScrollHorizontally(timeline) {
        return timeline.scrollWidth - timeline.clientWidth > 2;
    }

    function handleTimelineScroll(event) {
        maybeExtendTimelineWindow(event.currentTarget);
    }

    function maybeExtendTimelineWindow(timeline, direction = 'auto') {
        if (state.selected !== 'today' || state.showMonth || !timelineCanScrollHorizontally(timeline)) return false;
        if (direction === 'auto' && timelineDrag?.active) return false;
        const maxScrollLeft = Math.max(0, timeline.scrollWidth - timeline.clientWidth);
        const dayWidth = timelineDayWidth(timeline);
        const edgePadding = Math.max(dayWidth * 2, Math.round(timeline.clientWidth * .35));
        const shouldPrepend = direction === 'previous' || (direction === 'auto' && timeline.scrollLeft <= edgePadding);
        const shouldAppend = direction === 'next' || (direction === 'auto' && timeline.scrollLeft >= maxScrollLeft - edgePadding);
        if (!shouldPrepend && !shouldAppend) return false;

        const start = state.calendarWindowStart ? parseLocalDate(state.calendarWindowStart) : parseLocalDate(initialCalendarWindowStart(state.selectedDay));
        state.calendarWindowStart = shouldPrepend ? dateOnly(addDays(start, -calendarWindowChunkDays)) : dateOnly(start);
        state.calendarWindowDayCount = Math.max(calendarInitialWindowDays, Number(state.calendarWindowDayCount || calendarInitialWindowDays))
            + (shouldPrepend ? calendarWindowChunkDays : 0)
            + (shouldAppend ? calendarWindowChunkDays : 0);
        state.timelineScrollRestore = {
            left: timeline.scrollLeft + (shouldPrepend ? dayWidth * calendarWindowChunkDays : 0),
            top: timeline.scrollTop,
        };
        render();
        return true;
    }

    function timelineDayWidth(timeline) {
        const cssWidth = Number.parseFloat(getComputedStyle(timeline).getPropertyValue('--hb-day-min-width'));
        if (Number.isFinite(cssWidth) && cssWidth > 0) return cssWidth;
        const dayHead = timeline.querySelector('.hb-timeline-day-head');
        return dayHead?.getBoundingClientRect().width || 150;
    }

    function updateCurrentTimeMarker() {
        const marker = mount.querySelector('.hb-now-marker');
        const timeline = mount.querySelector('.hb-timeline');
        if (!marker || !timeline) return;
        const startHour = Number(timeline.dataset.timelineStartHour || 6);
        const endHour = Number(timeline.dataset.timelineEndHour || 22);
        const now = new Date();
        const timelineStart = new Date(now);
        timelineStart.setHours(startHour, 0, 0, 0);
        const timelineEnd = new Date(now);
        timelineEnd.setHours(endHour + 1, 0, 0, 0);
        if (now < timelineStart || now > timelineEnd) return;
        const top = ((now - timelineStart) / 60000) / 60 * 64;
        const label = marker.querySelector('.hb-now-label');
        marker.style.setProperty('--hb-now-top', `${top.toFixed(2)}px`);
        marker.setAttribute('aria-label', `Current time ${formatTime(now)}`);
        if (label) label.textContent = formatTime(now);
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
