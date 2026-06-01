import {
    commandAfterWakePhrase,
    normalizedVoiceCommand,
    voiceCommandNeedsAgentWork,
    voiceCommandWantsDetailedChat,
    voiceCancelRequested,
} from './voiceWake.js';

const mount = document.getElementById('heybean-web-app');

if (mount) {
    const logoUrl = mount.dataset.logo || '/images/bean-logo.png';
    const initialMode = mount.dataset.authMode || 'login';
    const tokenKey = 'heybean.web.token';
    const rememberKey = 'heybean.web.remember';
    const dashboardChangeKey = 'heybean.dashboard.changeId';
    const dashboardDataCacheKey = 'heybean.dashboard.data';
    const kioskVoiceKey = 'heybean.kioskVoice';
    const calendarInitialWindowDays = 56;
    const calendarWindowChunkDays = 28;

    const icons = {
        add: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="3"/></svg>',
        tasks: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 2 2 4-5"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/></svg>',
        reminders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06A2 2 0 1 1 7.03 3.8l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.15.38.36.7.6 1 .3.25.68.4 1.1.4H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51.6Z"/></svg>',
        spaces: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>',
        checkCircle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.5 11 14.5 15.5 9.5"/><circle cx="12" cy="12" r="9"/></svg>',
        send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>',
        stop: '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="m16.5 3.5 4 4L7 21H3v-4L16.5 3.5Z"/></svg>',
        user: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        tune: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M2 14h4M10 8h4M18 16h4"/></svg>',
        activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
        mic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/></svg>',
        menu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
        refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.2 6.5L3 16"/><path d="M3 21v-5h5"/><path d="M3 12A9 9 0 0 1 18.2 5.5L21 8"/><path d="M21 3v5h-5"/></svg>',
        chevronLeft: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
        chevronRight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
        history: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>',
    };

    const state = {
        authMode: initialMode,
        token: readToken(),
        remember: localStorage.getItem(rememberKey) === 'true',
        phase: 'loading',
        selected: initialSelectedView(),
        selectedDay: dateOnly(new Date()),
        calendarWindowStart: initialCalendarWindowStart(new Date()),
        calendarWindowDayCount: calendarInitialWindowDays,
        timelineScrollRestore: null,
        calendarVisibleDayCount: calendarVisibleDayCount(),
        showMonth: true,
        user: null,
        summary: null,
        tasks: [],
        reminders: [],
        calendar: [],
        categories: [],
        approvals: [],
        blockers: [],
        activity: [],
        adminUsage: null,
        adminUsageLoading: false,
        issueReportSubmitting: false,
        ttsPreviewing: false,
        googleStatus: null,
        googleAuthUrl: '',
        messages: [],
        session: null,
        chatSessions: [],
        chatHistoryOpen: false,
        chatRunState: 'Ready',
        voiceListening: false,
        voiceRecognition: null,
        voiceDraft: '',
        voiceStatus: '',
        voiceStatusTone: '',
        chatExpanded: false,
        kioskVoiceEnabled: kioskVoiceRequested(),
        kioskVoicePhase: 'idle',
        kioskVoiceMessage: '',
        onboardingJustCompleted: false,
        calendarRefreshing: false,
        taskFilter: 'active',
        reminderFilter: 'pending',
        dashboardChangeLastId: 0,
        pendingTaskUpserts: new Map(),
        pendingTaskDeletes: new Set(),
        pendingReminderUpserts: new Map(),
        pendingReminderDeletes: new Set(),
        expandedTaskIds: new Set(),
        pendingCalendarUpserts: new Map(),
        pendingCalendarDeletes: new Set(),
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
    let dashboardChangeAbort = null;
    let dashboardChangeLoopActive = false;
    let dashboardRefreshTimer = 0;
    let deferredDashboardRenderPending = false;
    let deferredDashboardRenderTimer = 0;
    let dashboardRefreshGeneration = 0;
    let kioskRecognition = null;
    let kioskBargeRecognition = null;
    let kioskRecognitionActive = false;
    let kioskBargeRecognitionActive = false;
    let kioskRecognitionShouldRestart = false;
    let kioskCommandText = '';
    let kioskConversationActive = false;
    let kioskQuickReplyGeneration = 0;
    let kioskCommandTimer = 0;
    let kioskRestartTimer = 0;
    let kioskBargeRestartTimer = 0;
    let kioskAutoCloseTimer = 0;
    let kioskHeardTimer = 0;
    let kioskConversationTimer = 0;
    let kioskBridgeTimer = 0;
    let kioskMicrophoneReady = false;
    let kioskPreferredAudioDeviceId = localStorage.getItem('heybean-preferred-audio-input') || '';
    let kioskAudioUnlocked = false;
    let kioskAudioContext = null;
    let kioskActiveAudioSource = null;
    let kioskActiveAudioElement = null;
    let kioskLastTtsError = '';
    let kioskRealtime = null;
    let kioskRealtimeStarting = false;
    let kioskRealtimeUnavailable = false;
    let kioskRealtimePendingUser = null;
    let kioskRealtimeAssistantDraft = null;
    let kioskRealtimeSuppressNextAssistantPersist = false;
    let kioskRealtimeVoiceOnlyAssistant = false;
    let kioskRealtimeToolFallbackTimer = 0;
    let kioskRealtimeToolFallbackContent = '';
    let kioskRealtimeReconnectTimer = 0;
    let kioskRealtimeReconnectAttempts = 0;
    const kioskRealtimeMaxReconnectAttempts = 5;
    const kioskRealtimeConnectTimeoutMs = 15000;
    const kioskRealtimeProcessedCalls = new Set();
    const kioskRealtimeRunWatchTimers = new Map();
    let chatRequestCounter = 0;
    let activeChatRequestId = 0;
    const cancelledChatRequestIds = new Set();

    boot();
    bindResponsiveCalendar();
    bindCurrentTimeTicker();
    bindDashboardRealtimeFallbacks();
    bindDeferredDashboardRenderFlush();

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
            if (state.phase !== 'signedIn') return;
            updateTopbarCurrentTime();
            if (state.selected !== 'today' || state.showMonth || state.modal) return;
            updateCurrentTimeMarker();
        }, 30000);
    }

    function bindDashboardRealtimeFallbacks() {
        window.addEventListener('focus', () => {
            if (state.phase !== 'signedIn') return;
            scheduleDashboardRealtimeRefresh();
            startDashboardChangeFeed();
        });
        window.setInterval(() => {
            if (state.phase !== 'signedIn') return;
            scheduleDashboardRealtimeRefresh();
            startDashboardChangeFeed();
        }, 120000);
    }

    function bindDeferredDashboardRenderFlush() {
        ['focusout', 'pointerup', 'keyup', 'change'].forEach((eventName) => {
            mount.addEventListener(eventName, () => {
                if (!deferredDashboardRenderPending) return;
                window.clearTimeout(deferredDashboardRenderTimer);
                deferredDashboardRenderTimer = window.setTimeout(flushDeferredDashboardRender, 250);
            }, true);
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

    function initialSelectedView() {
        return window.location.pathname === '/admin' ? 'admin' : 'today';
    }

    function kioskVoiceRequested() {
        const params = new URLSearchParams(window.location.search);
        const value = params.get('kiosk') ?? params.get('voice');
        if (value !== null) {
            const normalized = String(value).toLowerCase();
            if (['1', 'true', 'yes', 'on'].includes(normalized)) {
                localStorage.setItem(kioskVoiceKey, 'true');
            } else if (['0', 'false', 'no', 'off'].includes(normalized)) {
                localStorage.removeItem(kioskVoiceKey);
            }
        }
        return localStorage.getItem(kioskVoiceKey) === 'true';
    }

    function pathForView(view) {
        return view === 'admin' ? '/admin' : '/app';
    }

    async function fetchWithTimeout(url, options = {}, timeoutMs = 0) {
        if (!timeoutMs || !window.AbortController) return fetch(url, options);
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeoutMs);
        try {
            return await fetch(url, {
                ...options,
                signal: options.signal || controller.signal,
            });
        } finally {
            window.clearTimeout(timer);
        }
    }

    async function api(path, options = {}) {
        const headers = {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
            ...(options.headers || {}),
        };
        const response = await fetchWithTimeout(`/api${path}`, {
            method: options.method || 'GET',
            headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
        }, options.timeoutMs || 0);
        if (response.status === 204) return null;
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const details = payload.errors
                ? Object.values(payload.errors).flat().join(' ')
                : payload.message;
            const error = new Error(details || 'Something went wrong.');
            error.status = response.status;
            error.payload = payload;
            error.body = JSON.stringify(payload).slice(0, 1000);
            throw error;
        }
        return Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
    }

    async function apiForm(path, formData, options = {}) {
        const response = await fetch(`/api${path}`, {
            method: options.method || 'POST',
            headers: {
                Accept: 'application/json',
                ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
                ...(options.headers || {}),
            },
            body: formData,
        });
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
            const user = await api('/auth/me');
            let refreshError = null;
            const recover = async (request, fallback) => {
                try {
                    return await request;
                } catch (error) {
                    refreshError ??= error;
                    return fallback;
                }
            };
            state.user = user;
            state.dashboardChangeLastId = Number(localStorage.getItem(dashboardChangeStorageKey()) || 0);
            const cachedWorkspaceId = currentWorkspaceIdFromUser(user);
            if (cachedWorkspaceId && applyDashboardCache(cachedWorkspaceId)) {
                state.phase = 'signedIn';
                state.error = '';
                render();
            }

            const [summary, tasks, pastTasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                recover(api('/today'), state.summary || {}),
                recover(api('/tasks'), state.tasks),
                recover(api('/tasks/past'), []),
                recover(api('/reminders'), state.reminders),
                recover(api('/calendar-events?skip_google_sync=1'), state.calendar),
                recover(api('/event-categories'), state.categories),
                api('/google-calendar/status?cached=1').catch(() => null),
            ]);
            state.user = mergeUser(user, summary?.user, summary);
            state.summary = summary;
            state.tasks = reconcileTaskRefresh(mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks)));
            state.reminders = reconcileReminderRefresh(reminders.length ? reminders : summary?.reminders);
            state.calendar = reconcileCalendarRefresh(calendar.length ? calendar : summary?.calendar_events);
            state.categories = normalizeList(categories);
            state.approvals = normalizeList(summary?.approvals);
            state.blockers = normalizeList(summary?.blockers);
            state.activity = normalizeList(summary?.activity_events);
            state.googleStatus = googleStatus;
            state.session = null;
            state.messages = [];
            state.phase = 'signedIn';
            state.error = refreshError ? friendlyError(refreshError, 'refresh your latest data') : '';
            if (needsBeanOnboarding()) {
                state.selected = 'bean';
                state.chatExpanded = false;
                state.chatRunState = 'Onboarding';
            }
            if (state.selected === 'admin') {
                loadAdminUsage();
            }
            await loadChatSessions({ resumeToday: true });
            startDashboardChangeFeed();
            startKioskVoiceMode({ requestPermission: false });
            saveDashboardCache();
            refreshCalendarInBackground();
        } catch (error) {
            stopDashboardChangeFeed();
            stopKioskVoiceMode();
            clearToken();
            state.phase = 'signedOut';
            state.error = friendlyError(error, 'load your account');
        }
        render();
    }

    function mergeUser(...parts) {
        return Object.assign({}, ...parts.filter(Boolean));
    }

    function currentWorkspaceIdFromUser(user) {
        return user?.active_workspace?.id
            || user?.activeWorkspace?.id
            || user?.default_workspace_id
            || user?.defaultWorkspaceId
            || null;
    }

    function dashboardCacheStorageKey(workspaceId = currentWorkspaceId()) {
        return `${dashboardDataCacheKey}.${state.user?.id || 'anon'}.${workspaceId || 'default'}`;
    }

    function saveDashboardCache(workspaceId = currentWorkspaceId()) {
        if (!workspaceId || !state.user) return;
        try {
            localStorage.setItem(dashboardCacheStorageKey(workspaceId), JSON.stringify({
                summary: state.summary,
                tasks: state.tasks,
                reminders: state.reminders,
                calendar: state.calendar,
                categories: state.categories,
                approvals: state.approvals,
                blockers: state.blockers,
                activity: state.activity,
                googleStatus: state.googleStatus,
                savedAt: new Date().toISOString(),
            }));
        } catch (_) {
            // Cache writes are best-effort; API refresh remains the source of truth.
        }
    }

    function applyDashboardCache(workspaceId) {
        try {
            const cached = JSON.parse(localStorage.getItem(dashboardCacheStorageKey(workspaceId)) || 'null');
            if (!cached || typeof cached !== 'object') return false;
            state.summary = cached.summary || state.summary;
            state.tasks = normalizeList(cached.tasks);
            state.reminders = normalizeList(cached.reminders);
            state.calendar = reconcileCalendarRefresh(cached.calendar);
            state.categories = normalizeList(cached.categories);
            state.approvals = normalizeList(cached.approvals);
            state.blockers = normalizeList(cached.blockers);
            state.activity = normalizeList(cached.activity);
            state.googleStatus = cached.googleStatus || state.googleStatus;
            return true;
        } catch (_) {
            return false;
        }
    }

    function clearDashboardDataForWorkspace(workspaceId) {
        state.summary = {
            ...(state.summary || {}),
            workspace: findWorkspace(workspaceId) || state.summary?.workspace,
            tasks: [],
            reminders: [],
            calendar_events: [],
            approvals: [],
            blockers: [],
            activity_events: [],
        };
        state.tasks = [];
        state.reminders = [];
        state.calendar = [];
        state.categories = [];
        state.approvals = [];
        state.blockers = [];
        state.activity = [];
    }

    function snapshotDashboardState() {
        return {
            user: state.user,
            summary: state.summary,
            tasks: state.tasks,
            reminders: state.reminders,
            calendar: state.calendar,
            categories: state.categories,
            approvals: state.approvals,
            blockers: state.blockers,
            activity: state.activity,
            googleStatus: state.googleStatus,
            pendingTaskUpserts: new Map(state.pendingTaskUpserts),
            pendingTaskDeletes: new Set(state.pendingTaskDeletes),
            pendingReminderUpserts: new Map(state.pendingReminderUpserts),
            pendingReminderDeletes: new Set(state.pendingReminderDeletes),
            pendingCalendarUpserts: new Map(state.pendingCalendarUpserts),
            pendingCalendarDeletes: new Set(state.pendingCalendarDeletes),
        };
    }

    function restoreDashboardState(snapshot) {
        state.user = snapshot.user;
        state.summary = snapshot.summary;
        state.tasks = snapshot.tasks;
        state.reminders = snapshot.reminders;
        state.calendar = snapshot.calendar;
        state.categories = snapshot.categories;
        state.approvals = snapshot.approvals;
        state.blockers = snapshot.blockers;
        state.activity = snapshot.activity;
        state.googleStatus = snapshot.googleStatus;
        state.pendingTaskUpserts = snapshot.pendingTaskUpserts;
        state.pendingTaskDeletes = snapshot.pendingTaskDeletes;
        state.pendingReminderUpserts = snapshot.pendingReminderUpserts;
        state.pendingReminderDeletes = snapshot.pendingReminderDeletes;
        state.pendingCalendarUpserts = snapshot.pendingCalendarUpserts;
        state.pendingCalendarDeletes = snapshot.pendingCalendarDeletes;
    }

    function setActiveWorkspaceLocally(workspaceId) {
        const id = String(workspaceId);
        const workspace = findWorkspace(id);
        const nextWorkspaces = workspaces().map((candidate) => ({
            ...candidate,
            active: String(candidate.id) === id,
            is_default: String(candidate.id) === id,
            isDefault: String(candidate.id) === id,
        }));
        state.user = {
            ...(state.user || {}),
            default_workspace_id: Number(workspaceId),
            defaultWorkspaceId: Number(workspaceId),
            active_workspace: workspace || state.user?.active_workspace,
            activeWorkspace: workspace || state.user?.activeWorkspace,
            workspaces: nextWorkspaces,
        };
        state.summary = {
            ...(state.summary || {}),
            workspace: workspace || state.summary?.workspace,
            workspaces: nextWorkspaces.length ? nextWorkspaces : state.summary?.workspaces,
        };
    }

    function currentAgentProfile() {
        return state.summary?.agent_profile
            || state.summary?.agentProfile
            || currentAgentProfileFromUser(state.user)
            || {};
    }

    function currentAgentProfileFromUser(user) {
        return user?.active_workspace_agent_profile
            || user?.activeWorkspaceAgentProfile
            || user?.agent_profile
            || user?.agentProfile
            || null;
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

    function profileTtsSettings(profile = currentAgentProfile()) {
        const settings = profileSettings(profile);
        return typeof settings.tts === 'object' && settings.tts ? settings.tts : {};
    }

    function profileTtsProvider(profile = currentAgentProfile()) {
        return 'openai';
    }

    function allowDebugBrowserVoiceFallback() {
        return localStorage.getItem('heybean-debug-browser-voice') === 'true';
    }

    function profileTtsVoice(profile = currentAgentProfile()) {
        const tts = profileTtsSettings(profile);
        return supportedOpenAiVoice(tts.openai_voice || tts.openaiVoice || 'coral');
    }

    function supportedOpenAiVoice(voice) {
        const normalized = String(voice || '').toLowerCase().trim();
        const legacyMap = { nova: 'shimmer', onyx: 'ash', fable: 'ballad' };
        const mapped = legacyMap[normalized] || normalized;
        const supported = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];
        return supported.includes(mapped) ? mapped : 'coral';
    }

    function profileTtsInstructions(profile = currentAgentProfile()) {
        const tts = profileTtsSettings(profile);
        return tts.openai_instructions || tts.openaiInstructions || 'Speak naturally, warmly, and concisely as Bean.';
    }

    function profileOnboardingComplete(profile = currentAgentProfile()) {
        const onboarding = profileOnboarding(profile);
        return onboarding.completed === true || onboarding.completed === 1 || onboarding.completed === 'true';
    }

    function profilePreferencesReady(profile = currentAgentProfile()) {
        if (!profileOnboardingComplete(profile)) return false;
        return profilePriorities(profile).length > 0 || profileOnboardingContext(profile).trim() !== '';
    }

    function needsBeanOnboarding() {
        if (state.user?.needs_bean_onboarding !== undefined) return state.user.needs_bean_onboarding === true;
        if (state.user?.needsBeanOnboarding !== undefined) return state.user.needsBeanOnboarding === true;
        const userComplete = state.user?.onboard_complete === true || state.user?.onboardComplete === true;
        return !userComplete || !profilePreferencesReady();
    }

    function onboardingIntroMessage() {
        return 'Hey, I’m Bean. This is a quick onboarding interview so I can learn your preferred style, top priorities, and any important schedule or reminder context. Start by telling me who you are and what you want Bean to help with most.';
    }

    function userIsAdmin() {
        return state.user?.is_admin === true || state.user?.isAdmin === true;
    }

    function userIsBeta() {
        return state.user?.is_beta === true || state.user?.isBeta === true || Boolean(state.user?.beta_user || state.user?.betaUser);
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
        deferredDashboardRenderPending = false;
        window.clearTimeout(deferredDashboardRenderTimer);
        deferredDashboardRenderTimer = 0;
        const modalKey = state.modal ? modalIdentity(state.modal) : '';
        const existingModal = modalKey ? mount.querySelector('[data-modal-root]') : null;
        const preservedModal = existingModal?.dataset?.modalKey === modalKey ? existingModal : null;
        const preservedModalState = preservedModal ? captureModalDomState(preservedModal) : null;
        if (preservedModal) preservedModal.remove();

        mount.innerHTML = state.phase === 'signedIn' ? signedInMarkup() : signedOutMarkup();
        bindCommonActions();
        if (state.phase === 'signedIn') bindSignedInActions();
        if (state.modal) {
            if (preservedModal) {
                mount.appendChild(preservedModal);
                restoreModalDomState(preservedModalState);
            } else {
                mount.insertAdjacentHTML('beforeend', `<div data-modal-root data-modal-key="${escapeAttr(modalKey)}">${modalMarkup(state.modal)}</div>`);
                bindModalActions();
            }
        }
    }

    function renderDashboardDataUpdate({ deferIfEditing = false } = {}) {
        if (deferIfEditing && shouldDeferDashboardRender()) {
            deferredDashboardRenderPending = true;
            scheduleDeferredDashboardRender();
            return false;
        }
        render();
        return true;
    }

    function shouldDeferDashboardRender() {
        if (state.modal) return true;
        const active = document.activeElement;
        if (!active || active === document.body || !mount.contains(active)) return false;
        return Boolean(active.closest('input, textarea, select, form, [contenteditable="true"], [role="dialog"]'));
    }

    function scheduleDeferredDashboardRender() {
        window.clearTimeout(deferredDashboardRenderTimer);
        deferredDashboardRenderTimer = window.setTimeout(flushDeferredDashboardRender, 900);
    }

    function flushDeferredDashboardRender() {
        if (!deferredDashboardRenderPending) return;
        if (shouldDeferDashboardRender()) {
            scheduleDeferredDashboardRender();
            return;
        }
        render();
    }

    function captureModalDomState(root) {
        const active = root.contains(document.activeElement) ? document.activeElement : null;
        return {
            scrollPositions: modalScrollContainers(root).map((element) => ({
                element,
                top: element.scrollTop,
                left: element.scrollLeft,
            })),
            active,
            selectionStart: active && typeof active.selectionStart === 'number' ? active.selectionStart : null,
            selectionEnd: active && typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
        };
    }

    function restoreModalDomState(snapshot) {
        if (!snapshot) return;
        const restore = () => {
            snapshot.scrollPositions.forEach(({ element, top, left }) => {
                if (!element.isConnected) return;
                element.scrollTop = top;
                element.scrollLeft = left;
            });
            if (snapshot.active?.isConnected) {
                try {
                    snapshot.active.focus({ preventScroll: true });
                } catch (_) {
                    snapshot.active.focus();
                }
                if (snapshot.selectionStart !== null && typeof snapshot.active.setSelectionRange === 'function') {
                    try {
                        snapshot.active.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd ?? snapshot.selectionStart);
                    } catch (_) {
                        // Some input types expose selection APIs inconsistently.
                    }
                }
            }
        };
        restore();
        window.requestAnimationFrame(restore);
    }

    function modalScrollContainers(root) {
        return [root, ...root.querySelectorAll('.hb-modal, .hb-modal-backdrop')]
            .filter((element, index, list) => list.indexOf(element) === index);
    }

    function modalIdentity(modal = {}) {
        return [
            modal.type || '',
            modal.mode || '',
            modal.item?.id || '',
            modal.parentTask?.id || '',
            modal.workspace?.id || '',
        ].map((part) => String(part)).join(':');
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
        const criticalTasks = criticalTasksForToday();
        const criticalReminders = criticalRemindersForToday();
        const criticalEvents = criticalEventsForToday();
        const addTitle = state.selected === 'tasks' ? 'Add task' : state.selected === 'reminders' ? 'Add reminder' : 'Create event';
        const showAdd = ['today', 'tasks', 'reminders'].includes(state.selected);
        const showRefresh = ['today', 'tasks', 'reminders', 'admin'].includes(state.selected);
        const now = new Date();
        return `
            <div class="hb-app">
                ${betaBannerMarkup()}
                <header class="hb-topbar">
                    ${topBeanControlsMarkup()}
                    <span class="hb-spacer"></span>
                    <time class="hb-topbar-current-time" data-current-time datetime="${escapeAttr(now.toISOString())}">${escapeHtml(formatTime(now))}</time>
                    <button class="hb-header-pill" data-today type="button"><span>${escapeHtml(topbarTodayLabel(now))}</span></button>
                    <button class="hb-header-pill hb-month-pill" data-calendar-month type="button">${icons.calendar}<span>${escapeHtml(monthLabel(now))}</span></button>
                    ${state.selected === 'today' && state.showMonth ? `<div class="hb-topbar-month-cluster">${monthSwitcherMarkup(parseLocalDate(state.selectedDay))}</div>` : ''}
                    ${topNavMarkup()}
                    ${topWorkspaceSwitcherMarkup('hb-top-workspace-switcher-nav')}
                    ${showAdd ? `<button class="hb-icon-button hb-topbar-action" type="button" data-open-create="${state.selected === 'today' ? 'event' : state.selected.slice(0, -1)}" aria-label="${escapeAttr(addTitle)}">${icons.add}</button>` : ''}
                    ${showRefresh ? `<button class="hb-icon-button hb-topbar-action" type="button" data-refresh-app aria-label="Refresh" title="Refresh" ${state.calendarRefreshing ? 'disabled' : ''}>${state.calendarRefreshing ? '<span class="hb-spinner hb-spinner-tiny"></span>' : icons.refresh}</button>` : ''}
                    ${criticalMenuMarkup(criticalTasks, criticalReminders, criticalEvents)}
                    ${topProfileMenuMarkup()}
                    ${topOverflowMenuMarkup()}
                </header>
                <main class="hb-main ${state.selected === 'bean' ? 'hb-main-chat' : ''} ${state.selected === 'today' ? 'hb-main-today' : ''} ${['tasks', 'reminders'].includes(state.selected) ? 'hb-main-board' : ''} ${state.selected === 'admin' ? 'hb-main-admin' : ''}">
                    ${state.selected === 'bean' ? chatMarkup() : appPanelMarkup()}
                </main>
                ${state.selected === 'bean' ? '' : approvalSheetMarkup()}
                ${bottomMenuMarkup()}
                ${state.chatExpanded && state.selected !== 'bean' ? desktopChatMarkup({ expanded: true }) : ''}
            </div>`;
    }

    function betaBannerMarkup() {
        if (!userIsBeta()) return '';

        return `<button class="hb-beta-banner" type="button" data-open-issue-report>You are in beta testing. If you have any issues please report them here.</button>`;
    }

    function appPanelMarkup() {
        if (state.selected === 'settings') {
            return `<div class="hb-shell">${settingsMarkup()}</div>`;
        }
        if (state.selected === 'admin' && !userIsAdmin()) {
            return `<div class="hb-shell"><section class="hb-card hb-card-pad hb-admin-access-card">
                ${sectionTitle(icons.activity, 'Admin access required', 'Sign in with an admin account or grant admin permissions to your current account.')}
                <div class="hb-error">The current signed-in account does not have admin access.</div>
                <div class="hb-account-actions">
                    <button class="hb-button" type="button" data-admin-login>Sign in as admin</button>
                    <button class="hb-button-secondary" type="button" data-nav="settings">Account settings</button>
                </div>
            </section></div>`;
        }
        if (state.selected === 'admin' && userIsAdmin()) {
            return `<div class="hb-shell">${adminMarkup()}</div>`;
        }
        const showSideColumn = state.selected === 'today';
        const primary = state.selected === 'today'
            ? todayMarkup()
            : state.selected === 'tasks'
                ? tasksMarkup()
                : remindersMarkup();
        return `
            <div class="hb-shell hb-dashboard-grid ${showSideColumn ? '' : 'hb-dashboard-grid-single'}">
                <div class="hb-primary-column">${primary}</div>
                ${showSideColumn ? `<aside class="hb-side-column">
                    ${atAGlanceMarkup()}
                    ${todayTasksMarkup()}
                </aside>` : ''}
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
            <section class="hb-card hb-card-pad hb-board-card">
                ${sectionTitle(icons.tasks, 'Tasks', completed ? 'Completed tasks' : 'Active tasks')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-task-filter="active" aria-pressed="${!completed}">Active</button>
                    <button class="hb-chip" type="button" data-task-filter="done" aria-pressed="${completed}">Done</button>
                </div>
                ${dayBoardMarkup(items, 'task', completed ? 'No completed tasks' : 'No active tasks')}
            </section>`;
    }

    function remindersMarkup() {
        const completed = state.reminderFilter === 'completed';
        const items = state.reminders.filter((reminder) => reminderCompleted(reminder) === completed);
        return `
            <section class="hb-card hb-card-pad hb-board-card">
                ${sectionTitle(icons.reminders, 'Reminders', completed ? 'Completed reminders' : 'Pending reminders')}
                <div class="hb-tabs">
                    <button class="hb-chip" type="button" data-reminder-filter="pending" aria-pressed="${!completed}">Pending</button>
                    <button class="hb-chip" type="button" data-reminder-filter="completed" aria-pressed="${completed}">Completed</button>
                </div>
                ${dayBoardMarkup(items, 'reminder', completed ? 'No completed reminders' : 'No pending reminders')}
            </section>`;
    }

    function adminMarkup() {
        const usage = state.adminUsage || {};
        const totals = usage.totals || {};
        const alerts = normalizeList(usage.alerts);
        const issueReports = normalizeList(usage.issue_reports || usage.issueReports);
        const recentLogs = normalizeList(usage.recent_logs || usage.recentLogs);
        const byModel = normalizeList(usage.by_model || usage.byModel);
        const byRoute = normalizeList(usage.by_route_tier || usage.byRouteTier);
        const topUsers = normalizeList(usage.top_users || usage.topUsers);
        const topWorkspaces = normalizeList(usage.top_workspaces || usage.topWorkspaces);
        return `
            <section class="hb-card hb-card-pad hb-admin-panel">
                <div class="hb-section-action-row">
                    ${sectionTitle(icons.activity, 'Admin monitor', 'AI cost, usage limits, and user activity')}
                    <button class="hb-button-secondary" type="button" data-refresh-admin ${state.adminUsageLoading ? 'disabled' : ''}>${state.adminUsageLoading ? 'Refreshing...' : 'Refresh'}</button>
                </div>
                ${state.error ? `<div class="hb-error">${escapeHtml(state.error)}</div>` : ''}
                ${state.adminUsageLoading && !state.adminUsage ? '<div class="hb-empty hb-surface-soft">Loading AI usage metrics...</div>' : ''}
                <div class="hb-admin-metrics">
                    ${adminMetricMarkup('Users', totals.users, 'Total accounts')}
                    ${adminMetricMarkup('Workspaces', totals.workspaces, 'Total spaces')}
                    ${adminMetricMarkup('Actions today', totals.ai_actions_today, `${formatTokens(totals.tokens_today)} tokens`)}
                    ${adminMetricMarkup('Month cost', formatCurrency(totals.cost_month), `${formatTokens(totals.tokens_month)} tokens`)}
                    ${adminMetricMarkup('Today cost', formatCurrency(totals.cost_today), `${totals.ai_actions_month || 0} actions this month`)}
                    ${adminMetricMarkup('Open alerts', totals.open_alerts, 'Warnings and hard caps')}
                    ${adminMetricMarkup('Issue reports', totals.open_issue_reports, 'Open beta feedback')}
                </div>
                <div class="hb-admin-grid">
                    ${adminListBlockMarkup('Beta issue reports', issueReports, adminIssueReportRowMarkup, 'No issue reports yet.')}
                    ${adminListBlockMarkup('Budget and spike alerts', alerts, adminAlertRowMarkup, 'No alerts yet.')}
                    ${adminListBlockMarkup('Model mix this month', byModel, adminAggregateRowMarkup, 'No model usage yet.')}
                    ${adminListBlockMarkup('Route tiers this month', byRoute, adminAggregateRowMarkup, 'No routed usage yet.')}
                    ${adminListBlockMarkup('Top users this month', topUsers, adminOwnerRowMarkup, 'No user usage yet.')}
                    ${adminListBlockMarkup('Top workspaces this month', topWorkspaces, adminWorkspaceRowMarkup, 'No workspace usage yet.')}
                </div>
                <div class="hb-admin-log-card">
                    <div class="hb-section-action-row">
                        <strong>Recent AI usage logs</strong>
                        <span class="hb-item-meta">${recentLogs.length} latest</span>
                    </div>
                    <div class="hb-admin-log-table">
                        <div class="hb-admin-log-head"><span>When</span><span>User</span><span>Workspace</span><span>Model</span><span>Tokens</span><span>Cost</span><span>Status</span></div>
                        ${recentLogs.map(adminLogRowMarkup).join('') || '<div class="hb-empty">No AI usage logs yet.</div>'}
                    </div>
                </div>
            </section>`;
    }

    function adminMetricMarkup(label, value, meta) {
        return `
            <div class="hb-admin-metric">
                <span>${escapeHtml(label)}</span>
                <strong>${escapeHtml(value ?? 0)}</strong>
                <small>${escapeHtml(meta || '')}</small>
            </div>`;
    }

    function adminListBlockMarkup(title, items, rowRenderer, emptyText) {
        return `
            <div class="hb-surface-soft hb-card-pad hb-admin-list-block">
                <strong>${escapeHtml(title)}</strong>
                <div class="hb-admin-list">
                    ${items.length ? items.map(rowRenderer).join('') : `<div class="hb-empty">${escapeHtml(emptyText)}</div>`}
                </div>
            </div>`;
    }

    function adminAlertRowMarkup(alert) {
        const severity = String(alert.severity || 'warning').toLowerCase();
        return `
            <div class="hb-admin-row hb-admin-alert-row hb-admin-alert-${escapeAttr(severity)}">
                <div><strong>${escapeHtml(alert.alert_type || alert.alertType || 'Alert')}</strong><small>${escapeHtml(alert.message || '')}</small></div>
                <span>${escapeHtml(formatDateTime(alert.created_at || alert.createdAt))}</span>
            </div>`;
    }

    function adminAggregateRowMarkup(row) {
        return `
            <div class="hb-admin-row">
                <div><strong>${escapeHtml(row.key || 'Unknown')}</strong><small>${escapeHtml(`${row.actions || 0} actions · ${formatTokens(row.tokens)}`)}</small></div>
                <span>${escapeHtml(formatCurrency(row.cost))}</span>
            </div>`;
    }

    function adminOwnerRowMarkup(row) {
        return `
            <div class="hb-admin-row">
                <div><strong>${escapeHtml(row.name || row.email || 'User')}</strong><small>${escapeHtml(`${row.email || ''} · ${row.subscription_tier || row.subscriptionTier || 'free'}`)}</small></div>
                <span>${escapeHtml(formatCurrency(row.cost))}</span>
            </div>`;
    }

    function adminWorkspaceRowMarkup(row) {
        return `
            <div class="hb-admin-row">
                <div><strong>${escapeHtml(row.name || 'Workspace')}</strong><small>${escapeHtml(`${row.type || 'workspace'} · ${row.actions || 0} actions · ${formatTokens(row.tokens)}`)}</small></div>
                <span>${escapeHtml(formatCurrency(row.cost))}</span>
            </div>`;
    }

    function adminIssueReportRowMarkup(report) {
        const user = report.user || {};
        const workspace = report.workspace || {};
        const screenshots = normalizeList(report.screenshots);
        return `
            <div class="hb-admin-row hb-admin-issue-row">
                <div>
                    <strong>${escapeHtml(report.message || 'Issue report')}</strong>
                    <small>${escapeHtml(user.email || user.name || 'Unknown user')} · ${escapeHtml(workspace.name || 'No workspace')} · ${escapeHtml(formatDateTime(report.created_at || report.createdAt))}</small>
                    ${report.page_url || report.pageUrl ? `<a href="${escapeAttr(report.page_url || report.pageUrl)}" target="_blank" rel="noreferrer">Reported page</a>` : ''}
                    ${screenshots.length ? `<div class="hb-admin-issue-shots">${screenshots.map((shot, index) => `<a href="${escapeAttr(shot.url || '')}" target="_blank" rel="noreferrer">Screenshot ${index + 1}</a>`).join('')}</div>` : ''}
                </div>
                <span>${escapeHtml(report.status || 'open')}</span>
            </div>`;
    }

    function adminLogRowMarkup(log) {
        const user = log.user || {};
        const workspace = log.workspace || {};
        return `
            <div class="hb-admin-log-row">
                <span>${escapeHtml(formatDateTime(log.created_at || log.createdAt))}</span>
                <span>${escapeHtml(user.name || user.email || `#${log.user_id || log.userId || ''}`)}</span>
                <span>${escapeHtml(workspace.name || (log.workspace_id || log.workspaceId ? `#${log.workspace_id || log.workspaceId}` : 'None'))}</span>
                <span>${escapeHtml(log.model || 'unknown')}<small>${escapeHtml(log.route_tier || log.routeTier || '')}</small></span>
                <span>${escapeHtml(formatTokens(log.total_tokens || log.totalTokens))}</span>
                <span>${escapeHtml(formatCurrency(log.estimated_cost_usd || log.estimatedCostUsd))}</span>
                <span><mark class="hb-admin-status">${escapeHtml(log.status || 'logged')}</mark></span>
            </div>`;
    }

    function chatMarkup(options = {}) {
        const working = state.busy && state.chatRunState !== 'Ready';
        const messages = state.messages.length ? state.messages : [
            { id: 'intro', role: 'assistant', content: needsBeanOnboarding() ? onboardingIntroMessage() : 'Hey, I’m Bean. Tell me what you need planned, captured, moved, or remembered.' },
        ];
        const expandLabel = state.chatExpanded ? 'Close' : 'Expand';
        const title = currentChatTitle();
        return `
            <section class="hb-chat">
                <div class="hb-chat-top">
                    <span class="hb-run-pill ${working ? 'hb-run-pill-working' : ''}">${escapeHtml(state.chatRunState)}</span>
                    <strong class="hb-chat-session-title">${escapeHtml(title)}</strong>
                    <span class="hb-spacer"></span>
                    <button class="hb-button-ghost hb-chat-history-toggle ${state.chatHistoryOpen ? 'hb-chat-history-toggle-active' : ''}" type="button" data-toggle-chat-history aria-expanded="${state.chatHistoryOpen ? 'true' : 'false'}">${icons.history}<span>History</span></button>
                    ${options.expandable ? `<button class="hb-button-secondary hb-chat-expand-action" type="button" data-toggle-chat-expand aria-label="${escapeAttr(expandLabel)}">${escapeHtml(expandLabel)}</button>` : ''}
                    <button class="hb-button-ghost hb-chat-new-session" type="button" data-new-session ${state.busy ? 'disabled' : ''}>${icons.add}<span>New</span></button>
                </div>
                ${state.chatHistoryOpen ? chatHistoryMarkup() : ''}
                <div class="hb-chat-messages" id="hb-chat-messages">
                    ${onboardingInterviewIntroMarkup()}
                    ${messages.map((message, index) => messageMarkup(message, index, messages)).join('')}
                    ${working ? '' : pendingApprovalChatMarkup()}
                    ${working ? '' : onboardingCompletionMarkup()}
                    ${working ? messageMarkup({ id: 'busy', role: 'assistant', content: state.chatRunState || 'Working…', progress: true }) : ''}
                </div>
                <div class="hb-chat-voice-status ${state.voiceStatusTone === 'error' ? 'hb-chat-voice-status-error' : ''}" data-voice-status ${state.voiceStatus ? '' : 'hidden'}>${escapeHtml(state.voiceStatus)}</div>
                <form class="hb-chat-dock ${state.voiceListening ? 'hb-chat-dock-listening' : ''}" data-action="chat">
                    <textarea name="message" placeholder="${state.voiceListening ? 'Listening… release to send' : 'Message Bean…'}" rows="1" ${state.busy ? 'disabled' : ''}>${escapeHtml(state.voiceDraft)}</textarea>
                    <button class="hb-button-secondary hb-chat-text-send-button" type="submit" ${state.busy ? 'disabled' : ''} aria-label="Send message">${icons.send}</button>
                    <button class="${state.busy ? 'hb-button-danger' : 'hb-button'} hb-chat-voice-button" type="button" ${state.busy ? 'data-cancel-turn' : 'data-voice-hold'} aria-label="${state.busy ? 'Stop Bean' : 'Hold to talk'}">${state.busy ? icons.stop : `<img class="hb-send-bean-logo" src="${escapeAttr(logoUrl)}" alt="">`}</button>
                </form>
            </section>`;
    }

    function chatHistoryMarkup() {
        const sessions = normalizeList(state.chatSessions);
        return `
            <div class="hb-chat-history" aria-label="Previous Bean conversations">
                ${sessions.length
                    ? sessions.map((session) => chatHistoryItemMarkup(session)).join('')
                    : '<div class="hb-chat-history-empty">No previous conversations yet.</div>'}
            </div>`;
    }

    function chatHistoryItemMarkup(session) {
        const active = String(session.id || '') === String(state.session?.id || '');
        const latest = session.latest_message || session.latestMessage || null;
        const preview = latest?.content || 'No messages yet';
        const count = Number(session.messages_count || session.messagesCount || 0);
        return `
            <button class="hb-chat-history-item ${active ? 'hb-chat-history-item-active' : ''}" type="button" data-resume-session="${escapeAttr(session.id)}" ${active || state.busy ? 'disabled' : ''}>
                <span>
                    <strong>${escapeHtml(chatSessionTitle(session))}</strong>
                    <small>${escapeHtml(chatSessionMeta(session, count))}</small>
                </span>
                <em>${escapeHtml(preview)}</em>
            </button>`;
    }

    function onboardingInterviewIntroMarkup() {
        if (!needsBeanOnboarding()) return '';
        return `
            <article class="hb-chat-onboarding-card">
                <div class="hb-chat-onboarding-kicker">${icons.tune}<span>Quick onboarding interview</span></div>
                <strong>Bean is learning how to help this workspace.</strong>
                <p>Answer a few short questions about priorities, communication style, and anything Bean should remember. When it’s saved, you’ll get a clear way back to the dashboard.</p>
            </article>`;
    }

    function onboardingCompletionMarkup() {
        const sessionMode = state.session?.runtime_mode || state.session?.runtimeMode || '';
        if (needsBeanOnboarding() || !(state.onboardingJustCompleted || sessionMode === 'onboarding')) return '';
        return `
            <article class="hb-chat-onboarding-card hb-chat-onboarding-complete">
                <div class="hb-chat-onboarding-kicker">${icons.checkCircle}<span>Onboarding saved</span></div>
                <strong>Bean is ready for this workspace.</strong>
                <p>Your preferences are saved. You can keep chatting or head back to the dashboard.</p>
                <div class="hb-message-actions">
                    <button class="hb-button" type="button" data-onboarding-dashboard>Go to dashboard</button>
                    <button class="hb-button-secondary" type="button" data-new-session>Start a new chat</button>
                </div>
            </article>`;
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

    function topBeanControlsMarkup() {
        return `
            <div class="hb-topbar-bean-controls">
                <button class="hb-bean-button hb-topbar-bean-button ${state.chatExpanded || state.selected === 'bean' ? 'hb-bean-button-active' : ''}" type="button" data-toggle-chat-expand aria-label="${state.chatExpanded ? 'Close Bean chat' : 'Open Bean chat'}" title="Bean chat">
                    <img src="${escapeAttr(logoUrl)}" alt="">
                </button>
                ${kioskVoicePillMarkup({ topbar: true })}
            </div>`;
    }

    function kioskVoicePillMarkup(options = {}) {
        const requested = state.kioskVoiceEnabled;
        const ready = kioskVoiceReady();
        const phase = ready ? (state.kioskVoicePhase === 'idle' ? 'armed' : state.kioskVoicePhase || 'armed') : 'disabled';
        const label = kioskVoicePillLabel({ requested, ready, phase });
        const actionLabel = ready ? 'Turn off kiosk voice' : label;
        return `
            <button class="hb-kiosk-voice-pill hb-kiosk-voice-pill-button hb-kiosk-voice-pill-${escapeAttr(phase)} ${options.standalone ? 'hb-kiosk-voice-pill-standalone' : ''} ${options.topbar ? 'hb-kiosk-voice-pill-topbar' : ''}" type="button" data-toggle-kiosk-voice aria-live="polite" aria-label="${escapeAttr(actionLabel)}" title="${escapeAttr(actionLabel)}" aria-pressed="${ready}">
                <span class="hb-kiosk-voice-pill-icon" aria-hidden="true">${icons.mic}</span>
                <span>${escapeHtml(label)}</span>
            </button>`;
    }

    function kioskVoicePillLabel({ requested, ready, phase }) {
        if (ready) {
            if (phase === 'armed' && !state.kioskVoiceMessage) return 'Bean voice ready';
            return state.kioskVoiceMessage || phase;
        }
        if (!requested) return 'Enable microphone to chat';
        return state.kioskVoiceMessage || 'Connecting Bean voice';
    }

    function settingsMarkup() {
        const user = state.user || {};
        const prefs = user.notification_preferences || {};
        const profile = currentAgentProfile();
        const priorities = profilePriorities(profile);
        const context = profileOnboardingContext(profile);
        const complete = profilePreferencesReady(profile);
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

    function topWorkspaceSwitcherMarkup(extraClass = '') {
        const workspaceItems = workspaces();
        if (!workspaceItems.length) return '';
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        const activeWorkspace = workspaceItems.find((workspace) => String(workspace.id) === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault) || workspaceItems[0];
        return `
            <label class="hb-top-workspace-switcher ${escapeAttr(extraClass)}" title="Switch workspace">
                <span class="hb-top-workspace-icon" aria-hidden="true">${icons.spaces}</span>
                <select data-top-workspace-select ${workspaceItems.length < 2 ? 'disabled' : ''} aria-label="Switch workspace">
                    ${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}
                </select>
            </label>`;
    }

    function topOverflowMenuMarkup() {
        const workspaceItems = workspaces();
        const activeWorkspaceId = String(currentWorkspaceId() || '');
        const activeWorkspace = workspaceItems.find((workspace) => String(workspace.id) === activeWorkspaceId || workspace.active || workspace.is_default || workspace.isDefault) || workspaceItems[0];
        return `
            <details class="hb-overflow-menu">
                <summary class="hb-icon-button hb-overflow-trigger" aria-label="Open app menu" title="Menu">${icons.menu}</summary>
                <div class="hb-overflow-popover">
                    ${overflowMenuAction('today', 'Calendar', icons.calendar)}
                    ${overflowMenuAction('tasks', 'Tasks', icons.tasks)}
                    ${overflowMenuAction('reminders', 'Reminders', icons.reminders)}
                    ${workspaceItems.length ? `<label class="hb-overflow-workspace"><span>${icons.spaces}<strong>Workspace</strong></span><select data-top-workspace-select ${workspaceItems.length < 2 ? 'disabled' : ''} aria-label="Switch workspace">${workspaceItems.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === String(activeWorkspace?.id) ? 'selected' : ''}>${escapeHtml(workspaceDisplayName(workspace))}</option>`).join('')}</select></label>` : ''}
                    <button class="hb-overflow-action" type="button" data-open-create="event">${icons.add}<span>New event</span></button>
                    <button class="hb-overflow-action" type="button" data-refresh-app ${state.calendarRefreshing ? 'disabled' : ''}>${state.calendarRefreshing ? '<span class="hb-spinner hb-spinner-tiny"></span>' : icons.refresh}<span>Refresh</span></button>
                    ${overflowMenuAction('settings', 'Settings', icons.settings)}
                </div>
            </details>`;
    }

    function overflowMenuAction(key, label, icon) {
        return `<button class="hb-overflow-action ${state.selected === key ? 'hb-overflow-action-active' : ''}" type="button" data-nav="${key}">${icon}<span>${label}</span></button>`;
    }

    function topProfileMenuMarkup() {
        const user = state.user || {};
        const name = user.name || 'Account';
        const email = user.email || '';
        return `
            <details class="hb-profile-menu">
                <summary class="hb-profile-trigger" aria-label="${escapeAttr(`Account menu for ${name}`)}" title="Account menu">
                    <span class="hb-avatar" aria-hidden="true">${escapeHtml(userInitials(name, email))}</span>
                </summary>
                <div class="hb-profile-popover">
                    ${userIsAdmin() ? `<button class="hb-profile-action" type="button" data-nav="admin">${icons.activity}<span>Admin monitor</span></button>` : ''}
                    <button class="hb-profile-action" type="button" data-nav="settings">${icons.settings}<span>Settings</span></button>
                    <button class="hb-profile-action" type="button" data-logout>${icons.user}<span>Sign out</span></button>
                </div>
            </details>`;
    }

    function todayTasksMarkup() {
        const today = new Date();
        const tasks = activeTopLevelTasks().filter((task) => itemOverdue(task, 'task') || isSameDay(task.due_at || task.dueAt, today)).sort(compareTasks);
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
            <div class="hb-glance-day ${events.length ? '' : 'hb-glance-day-empty'}">
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
            <button class="hb-glance-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <div class="hb-event-time">${escapeHtml(eventStartTime(event))}</div>
                <div class="hb-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</div>
            </button>`;
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
        if (userIsAdmin()) nav.splice(3, 0, ['admin', 'Admin', icons.activity]);
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
        if (userIsAdmin()) nav.push(['admin', 'Admin', icons.activity]);
        return `
            <nav class="hb-top-nav" aria-label="App navigation">
                ${nav.slice(0, 3).map((item) => navButton(item, { iconOnly: true })).join('')}
                ${nav.slice(3).map((item) => navButton(item, { iconOnly: true })).join('')}
            </nav>`;
    }

    function navButton([key, label, icon], options = {}) {
        return `<button class="hb-nav-item ${state.selected === key ? 'hb-nav-item-active' : ''}" type="button" data-nav="${key}" aria-label="${escapeAttr(label)}" title="${escapeAttr(label)}">${icon}${options.iconOnly ? '' : `<span>${label}</span>`}</button>`;
    }

    function criticalMenuMarkup(tasks, reminders, events) {
        const count = tasks.length + reminders.length + events.length;
        return `
            <details class="hb-critical-menu">
                <summary class="hb-critical" title="${count} critical items" aria-label="Critical items">${count}</summary>
                <div class="hb-critical-popover" role="menu">
                    <div class="hb-critical-list">
                        ${count === 0 ? criticalDropdownRowMarkup(icons.checkCircle, 'Nothing critical today', '') : ''}
                        ${tasks.map((task) => criticalDropdownRowMarkup(icons.tasks, task.title || task.name || 'Untitled', criticalTaskSubtitle(task), `critical-task-item-${escapeAttr(task.id)}`)).join('')}
                        ${reminders.map((reminder) => criticalDropdownRowMarkup(icons.reminders, reminder.title || reminder.name || 'Untitled', criticalReminderSubtitle(reminder), `critical-reminder-item-${escapeAttr(reminder.id)}`)).join('')}
                        ${events.map((event) => criticalDropdownRowMarkup(icons.calendar, event.title || event.name || 'Untitled', criticalEventSubtitle(event), `critical-event-item-${escapeAttr(event.id)}`)).join('')}
                    </div>
                </div>
            </details>`;
    }

    function criticalDropdownRowMarkup(icon, title, subtitle = '', key = '') {
        return `
            <div class="hb-critical-row" ${key ? `data-critical-row="${key}"` : ''}>
                <span class="hb-critical-row-icon" aria-hidden="true">${icon}</span>
                <span class="hb-critical-row-copy">
                    <strong>${escapeHtml(title)}</strong>
                    ${subtitle ? `<small>${escapeHtml(subtitle)}</small>` : ''}
                </span>
            </div>`;
    }

    function timelineMarkup(days) {
        const startHour = Number(localStorage.getItem('heybean.calendar.startHour') || 6);
        const endHour = Number(localStorage.getItem('heybean.calendar.endHour') || 22);
        const hours = Array.from({ length: Math.max(1, endHour - startHour + 1) }, (_, index) => startHour + index);
        const minDayWidth = timelineDayMinWidth();
        const currentTimeMarker = currentTimeMarkerMarkup(days, startHour, endHour);
        return `
            <div class="hb-timeline hb-timeline-multi-day" data-timeline-start-hour="${startHour}" data-timeline-end-hour="${endHour}" style="--hb-hour-count:${hours.length};--hb-day-count:${days.length};--hb-day-min-width:${minDayWidth}px;--hb-timeline-min-width:${74 + (days.length * minDayWidth)}px" aria-label="${escapeAttr(calendarRangeLabel(days))} timeline">
                <div class="hb-timeline-head">
                    <div class="hb-timeline-hour"></div>
                    ${days.map((day) => `<button class="hb-timeline-day-head ${sameDate(day, parseLocalDate(state.selectedDay)) ? 'hb-timeline-day-head-active' : ''}" type="button" data-select-day="${dateOnly(day)}" aria-pressed="${sameDate(day, parseLocalDate(state.selectedDay))}"><strong>${escapeHtml(timelineDayHeaderLabel(day))}</strong><span>${escapeHtml(monthDayLabel(day))}</span></button>`).join('')}
                </div>
                ${multiDayRowMarkup(days)}
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
                        ${days.map((day) => `<div class="hb-timeline-day-column">${eventsForDay(day).filter((event) => !eventAllDay(event) && !eventMultiDayTimed(event)).map((event) => timedEventMarkup(event, day, startHour, endHour)).join('')}</div>`).join('')}
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

    function multiDayRowMarkup(days) {
        const dayEvents = days.map((day) => multiDayTimedEventsForDay(day));
        if (!dayEvents.some((events) => events.length)) return '';
        return `
            <div class="hb-multi-day-row hb-multi-day-row-collapsed" data-multi-day-row aria-hidden="true">
                <div class="hb-timeline-hour">Multi-Day</div>
                ${dayEvents.map((events, index) => `
                    <div class="hb-multi-day-cell" data-multi-day-cell data-has-multi-day="${events.length ? 'true' : 'false'}">
                        ${events.map((event) => multiDayEventMarkup(event, days[index])).join('')}
                    </div>
                `).join('')}
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
                        const day = addDays(first, index - leading);
                        return monthCellMarkup(day, sameMonth(day, first));
                    }).join('')}
                </div>
            </div>`;
    }

    function monthCellMarkup(day, isCurrentMonth = true) {
        const events = eventsForDay(day);
        const allDayEvents = events.filter((event) => eventAllDay(event));
        const multiDayEvents = events.filter((event) => eventMultiDayTimed(event));
        const timedEvents = events.filter((event) => !eventAllDay(event) && !eventMultiDayTimed(event));
        const today = new Date();
        const cellClasses = [
            'hb-month-cell',
            sameDate(day, today) ? 'hb-month-cell-active' : '',
            isCurrentMonth ? '' : 'hb-month-cell-adjacent',
        ].filter(Boolean).join(' ');
        return `
            <div class="${cellClasses}">
                <div class="hb-month-cell-head">
                    <button class="hb-month-date" type="button" data-select-day="${dateOnly(day)}" aria-label="${escapeAttr(dayLabel(day))}">
                        <strong>${escapeHtml(isCurrentMonth ? String(day.getDate()) : monthDayLabel(day))}</strong>
                    </button>
                    ${multiDayEvents.length ? `<div class="hb-month-all-day-list">${multiDayEvents.map((event) => monthMultiDayEventMarkup(event, day)).join('')}</div>` : ''}
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
                <span class="hb-month-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthMultiDayEventMarkup(event, day) {
        const color = safeColor(event.color);
        const time = multiDayEventDayTime(event, day, { showEndTime: false });
        return `
            <button class="hb-month-all-day-event hb-month-multi-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                ${time ? `<span class="hb-month-event-time">${escapeHtml(time)}</span>` : ''}
                <span class="hb-month-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function monthEventMarkup(event) {
        const color = safeColor(event.color);
        return `
            <button class="hb-month-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <span class="hb-month-event-time">${escapeHtml(eventStartTime(event))}</span>
                <span class="hb-month-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
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

    function dayBoardMarkup(items, kind, emptyText) {
        const days = itemBoardDays(items, kind);
        const allLabel = kind === 'task' ? 'All tasks' : 'All reminders';
        const allItems = items.slice().sort(itemSortFunction(kind));
        return `
            <div class="hb-day-board-shell">
                <div class="hb-day-board" aria-label="${escapeAttr(kind === 'task' ? 'Tasks by day' : 'Reminders by day')}">
                    ${days.map((day) => dayBoardColumnMarkup(day, itemsForItemDay(items, kind, day), kind, emptyText)).join('')}
                </div>
                ${dayBoardColumnMarkup(null, allItems, kind, emptyText, allLabel, 'hb-day-board-column-all')}
            </div>`;
    }

    function dayBoardColumnMarkup(day, items, kind, emptyText, overrideLabel = '', extraClass = '') {
        const label = overrideLabel || (day ? glanceDayLabel(parseLocalDate(day)) : 'No date');
        return `
            <section class="hb-day-board-column ${day ? '' : 'hb-day-board-column-unscheduled'} ${extraClass}" aria-label="${escapeAttr(label)}">
                <div class="hb-day-board-head">
                    <strong>${escapeHtml(label)}</strong>
                    <span>${escapeHtml(itemCountLabel(items.length, kind))}</span>
                </div>
                <div class="hb-list hb-day-board-list">
                    ${items.length ? items.map((item) => itemMarkup(item, kind)).join('') : `<div class="hb-empty hb-surface-soft">${escapeHtml(emptyText)}</div>`}
                </div>
            </section>`;
    }

    function itemMarkup(item, kind) {
        const completed = kind === 'task' ? taskCompleted(item) : reminderCompleted(item);
        const color = safeColor(item.color);
        const overdue = itemOverdue(item, kind);
        const baseSubtitle = kind === 'task' ? taskSubtitle(item) : reminderSubtitle(item);
        const subtitle = overdue ? ['overdue', baseSubtitle].filter(Boolean).join(' · ') : baseSubtitle;
        const critical = kind === 'task' ? taskCritical(item) : reminderCritical(item);
        const taskNotes = kind === 'task' ? taskNotesText(item) : '';
        const subtasks = kind === 'task' ? subtasksFor(item) : [];
        const expanded = kind === 'task' && state.expandedTaskIds.has(String(item.id));
        const expandable = kind === 'task' && (taskNotes || subtasks.length || (!completed && !taskParentId(item)));
        return `
            <article class="hb-item hb-item-${kind} ${completed ? 'hb-item-complete' : ''} ${overdue ? 'hb-item-overdue' : ''}" style="${completed ? '' : `background:${hexAlpha(color, .14)};border-color:${hexAlpha(color, .34)}`}">
                ${kind === 'task' && critical ? '<span class="hb-star hb-item-critical-star">★</span>' : ''}
                <label class="hb-check"><input type="checkbox" data-toggle-${kind}="${item.id}" ${completed ? 'checked' : ''}></label>
                <button class="hb-item-main" type="button" data-edit-${kind}="${item.id}">
                    <div class="hb-item-title">${kind !== 'task' && critical ? '<span class="hb-star">★</span>' : ''}<span>${escapeHtml(item.title || item.name || 'Untitled')}</span>${expandable ? `<span class="hb-task-expand-icon" data-toggle-task-details="${item.id}" aria-label="${expanded ? 'Hide task details' : 'Show task details'}">${expanded ? '▲' : '▼'}</span>` : ''}</div>
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
            <button class="hb-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                <div class="hb-event-time">${escapeHtml(eventTime(event))}</div>
                <div class="hb-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</div>
            </button>`;
    }

    function timedEventMarkup(event, day, startHour, endHour) {
        if (eventAllDay(event)) return '';
        const style = timelineEventStyle(event, day, startHour, endHour);
        if (!style) return '';
        const color = safeColor(event.color);
        const shortClass = style.minutes <= 30 ? ' hb-timed-event-short' : '';
        return `
            <button class="hb-event hb-timed-event${shortClass}" type="button" data-edit-event="${event.id}" style="${style.css};background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}" data-duration-minutes="${style.minutes}">
                <div class="hb-event-time">${escapeHtml(eventStartTime(event))}</div>
                <div class="hb-event-title">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</div>
            </button>`;
    }

    function allDayEventMarkup(event) {
        const color = safeColor(event.color);
        return `<button class="hb-all-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</button>`;
    }

    function multiDayEventMarkup(event, day) {
        const color = safeColor(event.color);
        const time = multiDayEventDayTime(event, day);
        return `
            <button class="hb-multi-day-event" type="button" data-edit-event="${event.id}" style="background:${hexAlpha(color, .12)};border-color:${hexAlpha(color, .30)}">
                ${time ? `<span class="hb-multi-day-event-time">${escapeHtml(time)}</span>` : ''}
                <span>${criticalStarMarkup(event)}${escapeHtml(event.title || event.name || 'Untitled')}</span>
            </button>`;
    }

    function criticalStarMarkup(item) {
        return item?.is_critical || item?.isCritical ? '<span class="hb-star hb-critical-star" aria-hidden="true">★</span> ' : '';
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
        if (modal.type === 'issue-report') return issueReportModalMarkup();
        if (modal.type === 'profile') return profileModalMarkup();
        if (modal.type === 'agent') return agentModalMarkup();
        if (modal.type === 'workspace') return workspaceModalMarkup(modal.mode, modal.workspace);
        if (modal.type === 'categories') return categoriesModalMarkup();
        if (modal.type === 'recurring-delete') return recurringDeleteModalMarkup(modal.item);
        return itemModalMarkup(modal.type, modal.item, modal.parentTask);
    }

    function issueReportModalMarkup() {
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Report a beta issue">
                <form class="hb-card hb-modal hb-form hb-issue-report-modal" data-modal-form="issue-report">
                    ${sectionTitle(icons.activity, 'Report an issue', 'Tell us what happened so we can fix it quickly.')}
                    ${state.error ? `<div class="hb-error">${escapeHtml(state.error)}</div>` : ''}
                    <label class="hb-label">What happened?
                        <textarea class="hb-textarea hb-issue-report-textarea" name="message" required maxlength="4000" placeholder="Describe what you were doing, what went wrong, and what you expected instead."></textarea>
                    </label>
                    <label class="hb-label">Screenshots <span class="hb-label-optional">optional</span>
                        <input class="hb-input" type="file" name="screenshots" accept="image/png,image/jpeg,image/webp" multiple>
                    </label>
                    <p class="hb-item-meta">You can attach up to 5 screenshots. We’ll include your current page and workspace automatically.</p>
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal ${state.issueReportSubmitting ? 'disabled' : ''}>Cancel</button>
                        <button class="hb-button" type="submit" ${state.issueReportSubmitting ? 'disabled' : ''}>${state.issueReportSubmitting ? 'Sending...' : 'Send report'}</button>
                    </div>
                </form>
            </div>`;
    }

    function itemModalMarkup(kind, item = null, parentTask = null) {
        const editing = Boolean(item);
        const isReminder = kind === 'reminder';
        const isEvent = kind === 'event';
        const eventStart = isEvent ? (item?.starts_at || item?.startsAt || defaultEventStart()) : null;
        const eventEnd = isEvent ? (item?.ends_at || item?.endsAt || defaultEventEnd(eventStart)) : null;
        const when = isEvent
            ? toDatetimeLocal(eventStart)
            : item ? toDatetimeLocal(item.due_at || item.dueAt || item.remind_at) : '';
        const end = isEvent ? toDatetimeLocal(eventEnd) : '';
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
                    ${categoryManagerToggleMarkup()}
                    ${!isReminder ? `<label class="hb-checkbox-row"><input type="checkbox" name="critical" ${item?.is_critical || item?.isCritical ? 'checked' : ''}> Critical</label>` : ''}
                    ${workspaceConnectionsMarkup(kind, item, workspaceId, editing)}
                    ${recurrenceFieldsMarkup(kind, item)}
                    ${kind === 'task' && editing && !taskParentId(item) ? `<button class="hb-button-ghost" type="button" data-create-subtask="${item.id}">Add sub-task</button>` : ''}
                    <div class="hb-modal-actions">
                        ${editing ? `<button class="hb-button-danger" type="button" data-modal-delete="${kind}" data-id="${item.id}">Delete</button>` : ''}
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                        <button class="hb-button" type="submit">${editing ? 'Save' : 'Create'}</button>
                    </div>
                </form>
            </div>`;
    }

    function recurringDeleteModalMarkup(item = null) {
        const title = item?.title || item?.name || 'this event';
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true" aria-label="Delete recurring event">
                <div class="hb-card hb-modal hb-form">
                    ${sectionTitle(icons.calendar, 'Delete recurring event', title)}
                    <p class="hb-item-meta">Choose how much of this series to remove.</p>
                    <div class="hb-recurring-delete-options">
                        <button class="hb-button-secondary" type="button" data-recurring-delete-mode="single">Only this event</button>
                        <button class="hb-button-secondary" type="button" data-recurring-delete-mode="future">This and following events</button>
                        <button class="hb-button-danger" type="button" data-recurring-delete-mode="all">All events in the series</button>
                    </div>
                    <div class="hb-modal-actions">
                        <button class="hb-button-secondary" type="button" data-close-modal>Cancel</button>
                    </div>
                </div>
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
        const startSource = item?.starts_at || item?.startsAt || when || defaultEventStart();
        const startDate = allDay ? storedDateOnly(startSource) : dateOnly(startSource);
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

    function categoryManagerToggleMarkup() {
        return `
            <div class="hb-inline-category-shell">
                <button class="hb-button-ghost" type="button" data-open-categories aria-expanded="false">Manage categories</button>
                <div class="hb-inline-category-manager" data-category-manager hidden>
                    <div class="hb-inline-category-head">
                        <strong>Categories</strong>
                        <span data-inline-category-message></span>
                    </div>
                    <div class="hb-inline-category-create">
                        <label class="hb-label">New category<input class="hb-input" type="text" data-inline-category-name placeholder="Category name"></label>
                        <label class="hb-label">Color<input class="hb-input hb-color-input" type="color" data-inline-category-color value="#16A34A"></label>
                        <button class="hb-button-secondary" type="button" data-inline-category-create>Add</button>
                    </div>
                    <div class="hb-list hb-category-list" data-inline-category-list>${inlineCategoryRowsMarkup()}</div>
                </div>
            </div>`;
    }

    function inlineCategoryRowsMarkup() {
        return state.categories.map((category) => `
            <div class="hb-compact-item" data-inline-category-row="${category.id}">
                <span class="hb-color-swatch" style="background:${escapeAttr(safeColor(category.color))}"></span>
                <input class="hb-input" data-inline-category-row-name value="${escapeAttr(category.name)}" aria-label="Category name">
                <div class="hb-row-actions">
                    <input class="hb-input hb-color-input" type="color" data-inline-category-row-color value="${escapeAttr(safeColor(category.color))}" aria-label="Category color">
                    <button class="hb-button-secondary" type="button" data-inline-category-save="${category.id}">Save</button>
                    <button class="hb-button-danger" type="button" data-inline-category-delete="${category.id}">Delete</button>
                </div>
            </div>`).join('') || '<div class="hb-empty">No categories yet.</div>';
    }

    function workspaceConnectionsMarkup(kind, item, workspaceId, editing) {
        const linked = new Set(normalizeList(item?.linked_workspace_ids || item?.linkedWorkspaceIds).map(String));
        const sourceWorkspaceId = String(workspaceId || currentWorkspaceId() || '');
        const allWorkspaces = workspaces();
        const sourceWorkspace = allWorkspaces.find((workspace) => String(workspace.id) === sourceWorkspaceId);
        const title = kind === 'event' ? 'Connections' : 'Workspaces';
        return `
            <div class="hb-surface-soft hb-card-pad hb-event-connections hb-workspace-picker" data-workspace-picker>
                <strong>${title}</strong>
                <label class="hb-label">Primary workspace
                    <select class="hb-select" name="workspaceId" data-primary-workspace-select ${editing ? 'disabled' : ''}>
                        ${allWorkspaces.map((workspace) => `<option value="${escapeAttr(workspace.id)}" ${String(workspace.id) === sourceWorkspaceId ? 'selected' : ''}>${escapeHtml(workspace.name || 'Workspace')}</option>`).join('')}
                    </select>
                </label>
                ${editing ? `<input type="hidden" name="workspaceId" value="${escapeAttr(sourceWorkspaceId)}"><p class="hb-item-meta">Saved in ${escapeHtml(sourceWorkspace?.name || 'this workspace')}.</p>` : ''}
                <div data-sync-workspace-options>${workspaceSyncOptionsMarkup(sourceWorkspaceId, linked)}</div>
                ${kind === 'event' ? `<div data-google-export-options>${googleEventConnectionMarkup(item, sourceWorkspace)}</div>` : ''}
            </div>`;
    }

    function workspaceSyncOptionsMarkup(sourceWorkspaceId, linked = new Set()) {
        const otherWorkspaces = workspaces().filter((workspace) => String(workspace.id) !== String(sourceWorkspaceId));
        return otherWorkspaces.length ? `<div class="hb-label">Also assign to
            <div class="hb-option-list">
                ${otherWorkspaces.map((workspace) => `<label class="hb-switch-row"><input type="checkbox" name="syncWorkspaceIds" value="${escapeAttr(workspace.id)}" ${linked.has(String(workspace.id)) ? 'checked' : ''}> <span><strong>${escapeHtml(workspace.name || 'Workspace')}</strong><small>${escapeHtml(workspace.type || workspace.kind || 'workspace')}</small></span></label>`).join('')}
            </div>
        </div>` : '<p class="hb-item-meta">No other workspaces connected to this account.</p>';
    }

    function googleEventConnectionMarkup(item, workspace) {
        if (state.googleStatus?.connected !== true) {
            return '<p class="hb-item-meta">Google Calendar is not connected.</p>';
        }
        const calendars = writableGoogleCalendars();
        if (!calendars.length) {
            return '<p class="hb-item-meta">No writable Google calendars are available.</p>';
        }
        const selected = new Set(defaultGoogleCalendarExportIds(item, workspace));
        return `
            <div class="hb-label">Export to Google Calendar
                <div class="hb-option-list">
                    ${calendars.map((calendar) => `<label class="hb-switch-row"><input type="checkbox" name="googleCalendarIds" value="${escapeAttr(calendar.id)}" ${selected.has(String(calendar.id)) ? 'checked' : ''}> <span><strong>${escapeHtml(calendar.summary || calendar.name || calendar.id)}</strong><small>${escapeHtml(calendar.access_role || calendar.accessRole || 'writer')}</small></span></label>`).join('')}
                </div>
            </div>`;
    }

    function writableGoogleCalendars() {
        return normalizeList(state.googleStatus?.calendars).filter((calendar) => ['owner', 'writer'].includes(String(calendar.access_role || calendar.accessRole || 'reader')));
    }

    function defaultGoogleCalendarExportIds(item = null, workspace = null) {
        const metadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
        const existingIds = normalizeList(metadata.google_calendar_ids || metadata.googleCalendarIds);
        if (existingIds.length) return existingIds.map(String);
        const existingSingle = item?.google_calendar_id || item?.googleCalendarId || metadata.google_calendar_id || metadata.googleCalendarId;
        if (existingSingle) return [String(existingSingle)];

        const mappings = normalizeList(workspace?.google_calendar_mappings || workspace?.googleCalendarMappings);
        const defaultMapping = mappings.find((mapping) => mapping.is_default_export || mapping.isDefaultExport);
        if (defaultMapping) return [String(defaultMapping.google_calendar_id || defaultMapping.googleCalendarId)];
        if (mappings.length) return mappings.map((mapping) => String(mapping.google_calendar_id || mapping.googleCalendarId)).filter(Boolean);
        const fallback = state.googleStatus?.default_calendar_id || state.googleStatus?.calendar_id;
        return fallback ? [String(fallback)] : [];
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
        const ttsVoice = profileTtsVoice(profile);
        const ttsInstructions = profileTtsInstructions(profile);
        const options = ['balanced', 'coach', 'organizer', 'creative'];
        const voices = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];
        return `
            <div class="hb-modal-backdrop" role="dialog" aria-modal="true">
                <form class="hb-card hb-modal hb-form" data-modal-form="agent">
                    ${sectionTitle(icons.tune, 'Edit Bean preferences', 'Review the current settings and save only what you want to change.')}
                    <input type="hidden" name="workspaceId" value="${escapeAttr(currentWorkspaceId() || '')}">
                    <label class="hb-label">Choose Bean’s personality<select class="hb-select" name="personality">${options.map((option) => `<option value="${option}" ${option === personality ? 'selected' : ''}>${personalityLabel(option)}</option>`).join('')}</select></label>
                    <div class="hb-label">What should Bean prioritize?
                        <div class="hb-tabs">${['Work', 'Family', 'Health', 'Planning', 'Reminders', 'Focus'].map((priority) => `<label class="hb-chip"><input type="checkbox" name="priorities" value="${priority}" ${priorities.has(priority) ? 'checked' : ''}> ${priority}</label>`).join('')}</div>
                    </div>
                    <label class="hb-label">Anything Bean should know?<textarea class="hb-textarea" name="context" placeholder="Example: I work nights, protect family time, and need gentle nudges.">${escapeHtml(profileOnboardingContext(profile))}</textarea></label>
                    <div class="hb-surface-soft hb-card-pad hb-tts-settings">
                        <strong>Voice responses</strong>
                        <p class="hb-item-meta">Bean uses the app OpenAI voice connection automatically.</p>
                        <div class="hb-field-row">
                            <div class="hb-label hb-tts-voice-picker">OpenAI voice
                                <div class="hb-tts-preview-row">
                                    <select class="hb-select" name="ttsOpenAiVoice">${voices.map((voice) => `<option value="${voice}" ${voice === ttsVoice ? 'selected' : ''}>${capitalize(voice)}</option>`).join('')}</select>
                                    <button class="hb-button-secondary hb-tts-preview-button" type="button" data-preview-tts-voice>${state.ttsPreviewing ? 'Playing...' : 'Preview'}</button>
                                </div>
                            </div>
                        </div>
                        <div class="hb-tts-preview-status" data-tts-preview-status hidden></div>
                        <label class="hb-label">OpenAI voice style<textarea class="hb-textarea hb-tts-style" name="ttsOpenAiInstructions" placeholder="Example: Warm, natural, concise, and lightly upbeat.">${escapeHtml(ttsInstructions)}</textarea></label>
                        <p class="hb-item-meta">OpenAI voices are AI-generated.</p>
                    </div>
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

    function recurrenceFieldsMarkup(kind, item) {
        const recurrence = itemRecurrenceValue(item);
        const recurrenceMeta = recurrenceMetadata(item?.metadata);
        const days = recurrenceDays(item?.metadata);
        const unit = recurrenceMeta.unit || recurrenceMeta.interval_unit || recurrenceMeta.intervalUnit || 'days';
        return `
            <label class="hb-label">${capitalize(kind)} recurrence
                <select class="hb-select" name="recurrence" data-recurrence-select>
                    ${recurrenceOptions().map((value) => `<option value="${value}" ${value === recurrence ? 'selected' : ''}>${recurrenceLabel(value)}</option>`).join('')}
                </select>
            </label>
            <div class="hb-tabs" data-recurrence-days ${recurrence === 'specific_days' ? '' : 'hidden'}>
                ${['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map((day) => `<label class="hb-chip"><input type="checkbox" name="specificDays" value="${day}" ${days.has(day) ? 'checked' : ''}> ${day.toUpperCase()}</label>`).join('')}
            </div>
            <div class="hb-field-row" data-recurrence-interval ${recurrence === 'interval' ? '' : 'hidden'}>
                ${labelInput('Repeat interval', 'interval', 'number', recurrenceMeta.interval || '', 'min="1"')}
                <label class="hb-label">Interval unit<select class="hb-select" name="intervalUnit"><option value="days">Days</option><option value="weeks" ${unit === 'weeks' ? 'selected' : ''}>Weeks</option><option value="months" ${unit === 'months' ? 'selected' : ''}>Months</option><option value="years" ${unit === 'years' ? 'selected' : ''}>Years</option></select></label>
            </div>`;
    }

    function recurrenceOptions() {
        return ['none', 'daily', 'weekly', 'monthly', 'yearly', 'specific_days', 'interval'];
    }

    function itemRecurrenceValue(item = null) {
        return normalizeRecurrenceValue(item?.recurrence ?? item?.metadata?.recurrence);
    }

    function normalizeRecurrenceValue(value) {
        if (value && typeof value === 'object') {
            if (value.interval && !value.value && !value.type && !value.frequency) return 'interval';
            if ((value.specific_days || value.specificDays || value.days) && !value.value && !value.type && !value.frequency) return 'specific_days';
            return normalizeRecurrenceValue(value.value || value.type || value.frequency || value.freq || value.recurrence || value.rule);
        }
        const normalized = String(value || 'none').toLowerCase().trim().replace(/[-\s]+/g, '_');
        const aliases = {
            '': 'none',
            no: 'none',
            never: 'none',
            once: 'none',
            one_time: 'none',
            day: 'daily',
            days: 'daily',
            every_day: 'daily',
            week: 'weekly',
            weeks: 'weekly',
            every_week: 'weekly',
            month: 'monthly',
            months: 'monthly',
            every_month: 'monthly',
            year: 'yearly',
            years: 'yearly',
            annual: 'yearly',
            annually: 'yearly',
            every_year: 'yearly',
            specific_day: 'specific_days',
            selected_days: 'specific_days',
            days_of_week: 'specific_days',
            custom: 'interval',
            custom_interval: 'interval',
        };
        return aliases[normalized] || normalized;
    }

    function recurrenceMetadata(metadata = {}) {
        const recurrence = metadata?.recurrence && typeof metadata.recurrence === 'object' ? metadata.recurrence : {};
        return { ...metadata, ...recurrence };
    }

    function recurrenceDays(metadata = {}) {
        const recurrence = recurrenceMetadata(metadata);
        return new Set(normalizeList(metadata?.specific_days || metadata?.specificDays || metadata?.days || recurrence.specific_days || recurrence.specificDays || recurrence.days));
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
            history.pushState({}, '', initialSelectedView() === 'admin' ? '/admin' : '/app');
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
            history.pushState({}, '', pathForView(state.selected));
            render();
            if (state.selected === 'admin') loadAdminUsage();
            scrollChatToBottom();
        }));
        mount.querySelectorAll('[data-toggle-chat-expand]').forEach((button) => button.addEventListener('click', () => {
            state.chatExpanded = !state.chatExpanded;
            render();
            scrollChatToBottom();
        }));
        mount.querySelector('[data-toggle-kiosk-voice]')?.addEventListener('click', toggleKioskVoiceMode);
        mount.querySelector('[data-onboarding-dashboard]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.chatExpanded = false;
            state.onboardingJustCompleted = false;
            state.error = '';
            state.notice = '';
            render();
        });
        mount.querySelector('[data-admin-login]')?.addEventListener('click', () => {
            stopDashboardChangeFeed();
            stopKioskVoiceMode();
            clearToken();
            state.phase = 'signedOut';
            state.authMode = 'login';
            state.user = null;
            state.summary = null;
            state.error = '';
            state.notice = 'Sign in with an admin account.';
            history.pushState({}, '', '/admin');
            render();
        });
        mount.querySelector('[data-today]')?.addEventListener('click', () => {
            state.selected = 'today';
            state.selectedDay = dateOnly(new Date());
            resetCalendarWindow(new Date());
            state.showMonth = false;
            history.pushState({}, '', '/app');
            render();
        });
        mount.querySelector('[data-calendar-month]')?.addEventListener('click', () => {
            const today = new Date();
            state.selected = 'today';
            state.selectedDay = dateOnly(today);
            resetCalendarWindow(today);
            state.showMonth = true;
            history.pushState({}, '', '/app');
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
        mount.querySelectorAll('[data-refresh-app]').forEach((button) => button.addEventListener('click', refreshCurrentView));
        mount.querySelector('[data-refresh-admin]')?.addEventListener('click', () => loadAdminUsage(true));
        mount.querySelectorAll('[data-open-create]').forEach((button) => button.addEventListener('click', () => openModal(button.dataset.openCreate)));
        mount.querySelector('[data-open-issue-report]')?.addEventListener('click', () => openModal('issue-report'));
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
        mount.querySelectorAll('[data-top-workspace-select]').forEach((select) => select.addEventListener('change', (event) => setWorkspace(event.currentTarget.value)));
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
        mount.querySelectorAll('[data-cancel-turn]').forEach((button) => button.addEventListener('click', cancelBeanTurn));
        bindTimelineHorizontalScroll();
        mount.querySelector('[data-toggle-chat-history]')?.addEventListener('click', () => {
            state.chatHistoryOpen = !state.chatHistoryOpen;
            render();
        });
        mount.querySelectorAll('[data-resume-session]').forEach((button) => button.addEventListener('click', () => resumeSession(button.dataset.resumeSession)));
        mount.querySelectorAll('[data-new-session]').forEach((button) => button.addEventListener('click', newSession));
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
        mount.querySelectorAll('[data-recurring-delete-mode]').forEach((button) => button.addEventListener('click', confirmRecurringDelete));
        mount.querySelector('[data-modal-form]')?.addEventListener('submit', submitModal);
        mount.querySelector('[data-open-categories]')?.addEventListener('click', toggleInlineCategoryManager);
        mount.querySelectorAll('[data-inline-category-create]').forEach((button) => button.addEventListener('click', createInlineCategory));
        mount.querySelectorAll('[data-inline-category-save]').forEach((button) => button.addEventListener('click', saveInlineCategory));
        mount.querySelectorAll('[data-inline-category-delete]').forEach((button) => button.addEventListener('click', deleteInlineCategory));
        mount.querySelectorAll('[data-category-row]').forEach((form) => form.addEventListener('submit', saveCategoryRow));
        mount.querySelectorAll('[data-delete-category]').forEach((button) => button.addEventListener('click', () => deleteCategory(button.dataset.deleteCategory)));
        mount.querySelectorAll('[data-category-select]').forEach((select) => select.addEventListener('change', syncSelectedCategoryColor));
        mount.querySelector('[data-preview-tts-voice]')?.addEventListener('click', previewSelectedTtsVoice);
        mount.querySelectorAll('form[data-modal-form="event"]').forEach(bindEventTimeInputs);
        mount.querySelectorAll('[data-primary-workspace-select]').forEach((select) => select.addEventListener('change', handlePrimaryWorkspaceChange));
        mount.querySelectorAll('[data-recurrence-select]').forEach((select) => {
            select.addEventListener('change', () => toggleRecurrenceFields(select.closest('form')));
            toggleRecurrenceFields(select.closest('form'));
        });
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

    async function previewSelectedTtsVoice(event) {
        const button = event.currentTarget;
        const form = button.closest('form');
        const status = form?.querySelector('[data-tts-preview-status]');
        const voice = form?.querySelector('select[name="ttsOpenAiVoice"]')?.value || profileTtsVoice();
        if (!form || state.ttsPreviewing) return;

        setTtsPreviewStatus(status, '', '');

        state.ttsPreviewing = true;
        button.disabled = true;
        button.textContent = 'Playing...';
        try {
            setTtsPreviewStatus(status, `Playing ${capitalize(voice)}...`, '');
            if (!await ensureOpenAiAudioUnlocked()) {
                throw new Error('audio_not_unlocked');
            }
            const response = await fetch('/api/assistant/tts', {
                method: 'POST',
                headers: {
                    Accept: 'audio/wav',
                    'Content-Type': 'application/json',
                    ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
                },
                body: JSON.stringify({
                    text: "Hi, I'm Bean. This is a quick preview of this voice.",
                    voice,
                    workspace_id: currentWorkspaceId() || null,
                }),
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                throw new Error(beanVoiceStatusMessage(payload?.message || "Bean's voice preview failed."));
            }
            const audioBuffer = await response.arrayBuffer();
            const played = await playOpenAiAudioBuffer(audioBuffer) || await playAudioBlobFallback(audioBuffer, response.headers.get('Content-Type') || 'audio/wav');
            if (!played) throw new Error("Bean's voice needs one click.");
            setTtsPreviewStatus(status, `${capitalize(voice)} preview played.`, 'success');
        } catch (error) {
            setTtsPreviewStatus(status, beanVoiceStatusMessage(error?.message || "Bean's voice preview failed."), 'error');
        } finally {
            state.ttsPreviewing = false;
            button.disabled = false;
            button.textContent = 'Preview';
        }
    }

    function setTtsPreviewStatus(element, message, tone = '') {
        if (!element) return;
        element.hidden = !message;
        element.textContent = message;
        element.classList.toggle('hb-tts-preview-status-error', tone === 'error');
        element.classList.toggle('hb-tts-preview-status-success', tone === 'success');
    }

    function handlePrimaryWorkspaceChange(event) {
        const select = event.currentTarget;
        const picker = select.closest('[data-workspace-picker]');
        if (!picker) return;
        const sourceWorkspaceId = String(select.value || '');
        const checkedSyncIds = new Set(Array.from(picker.querySelectorAll('input[name="syncWorkspaceIds"]:checked')).map((input) => String(input.value)).filter((id) => id !== sourceWorkspaceId));
        const syncContainer = picker.querySelector('[data-sync-workspace-options]');
        if (syncContainer) syncContainer.innerHTML = workspaceSyncOptionsMarkup(sourceWorkspaceId, checkedSyncIds);

        const workspace = findWorkspace(sourceWorkspaceId);
        const googleContainer = picker.querySelector('[data-google-export-options]');
        if (googleContainer) googleContainer.innerHTML = googleEventConnectionMarkup(null, workspace);
    }

    function toggleRecurrenceFields(form) {
        if (!form) return;
        const recurrence = form.querySelector('[data-recurrence-select]')?.value || 'none';
        setFieldGroupState(form.querySelector('[data-recurrence-days]'), recurrence === 'specific_days');
        setFieldGroupState(form.querySelector('[data-recurrence-interval]'), recurrence === 'interval');
    }

    function toggleInlineCategoryManager(event) {
        const button = event.currentTarget;
        const panel = button.closest('.hb-inline-category-shell')?.querySelector('[data-category-manager]');
        if (!panel) return;
        const opening = panel.hidden;
        panel.hidden = !opening;
        button.setAttribute('aria-expanded', String(opening));
    }

    async function createInlineCategory(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-category-manager]');
        const nameInput = panel?.querySelector('[data-inline-category-name]');
        const colorInput = panel?.querySelector('[data-inline-category-color]');
        const name = String(nameInput?.value || '').trim();
        const color = safeColor(colorInput?.value || '#16A34A');
        if (!panel || !name) {
            setInlineCategoryMessage(panel, 'Add a category name.', 'error');
            return;
        }
        await withInlineCategoryBusy(button, async () => {
            const category = await api('/event-categories', { method: 'POST', body: { name, color } });
            cacheCategory(category);
            nameInput.value = '';
            colorInput.value = '#16A34A';
            refreshInlineCategoryControls(panel, name, color);
            setInlineCategoryMessage(panel, 'Added.', '');
        });
    }

    async function saveInlineCategory(event) {
        const button = event.currentTarget;
        const row = button.closest('[data-inline-category-row]');
        const panel = button.closest('[data-category-manager]');
        const name = String(row?.querySelector('[data-inline-category-row-name]')?.value || '').trim();
        const color = safeColor(row?.querySelector('[data-inline-category-row-color]')?.value || '#16A34A');
        if (!row || !name) {
            setInlineCategoryMessage(panel, 'Category name is required.', 'error');
            return;
        }
        await withInlineCategoryBusy(button, async () => {
            const category = await api(`/event-categories/${row.dataset.inlineCategoryRow}`, { method: 'PATCH', body: { name, color } });
            cacheCategory(category);
            refreshInlineCategoryControls(panel, name, color);
            setInlineCategoryMessage(panel, 'Saved.', '');
        });
    }

    async function deleteInlineCategory(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-category-manager]');
        if (!confirm('Delete this category from items?')) return;
        await withInlineCategoryBusy(button, async () => {
            await api(`/event-categories/${button.dataset.inlineCategoryDelete}`, { method: 'DELETE' });
            state.categories = state.categories.filter((category) => String(category.id) !== String(button.dataset.inlineCategoryDelete));
            refreshInlineCategoryControls(panel, '');
            setInlineCategoryMessage(panel, 'Deleted.', '');
        });
    }

    async function withInlineCategoryBusy(button, callback) {
        try {
            button.disabled = true;
            await callback();
        } catch (error) {
            setInlineCategoryMessage(button.closest('[data-category-manager]'), friendlyError(error, 'update categories'), 'error');
        } finally {
            button.disabled = false;
        }
    }

    function cacheCategory(category) {
        if (!category) return;
        state.categories = upsertById(state.categories, category);
    }

    function refreshInlineCategoryControls(panel, selectedName = null, selectedColor = '') {
        if (!panel) return;
        const form = panel.closest('form');
        const select = form?.querySelector('select[name="category"]');
        const colorInput = form?.querySelector('input[name="color"]');
        const current = selectedName === null ? select?.value || '' : selectedName;
        if (select) {
            select.innerHTML = categoryOptions(current)
                .map((category) => `<option value="${escapeAttr(category.name)}" data-category-color="${escapeAttr(safeColor(category.color))}" ${category.name === current ? 'selected' : ''}>${escapeHtml(category.name)}</option>`)
                .join('');
            select.insertAdjacentHTML('afterbegin', `<option value="" data-category-color="" ${current ? '' : 'selected'}>None</option>`);
            select.value = current;
        }
        if (colorInput && current) {
            colorInput.value = safeColor(selectedColor || categoryColor(current));
        }
        const list = panel.querySelector('[data-inline-category-list]');
        if (list) list.innerHTML = inlineCategoryRowsMarkup();
        bindInlineCategoryActions(panel);
    }

    function bindInlineCategoryActions(panel) {
        panel?.querySelectorAll('[data-inline-category-save]').forEach((button) => button.addEventListener('click', saveInlineCategory));
        panel?.querySelectorAll('[data-inline-category-delete]').forEach((button) => button.addEventListener('click', deleteInlineCategory));
    }

    function setInlineCategoryMessage(panel, message, tone = '') {
        const target = panel?.querySelector('[data-inline-category-message]');
        if (!target) return;
        target.textContent = message;
        target.classList.toggle('hb-inline-category-message-error', tone === 'error');
    }

    function bindEventTimeInputs(form) {
        const startInput = form.querySelector('input[name="time"]');
        const endInput = form.querySelector('input[name="endsAt"]');
        const allDayStart = form.querySelector('input[name="allDayStart"]');
        const allDayEnd = form.querySelector('input[name="allDayEnd"]');
        if (startInput) {
            startInput.dataset.previousValue = startInput.value;
            startInput.addEventListener('change', () => syncEventEndWithStart(form));
        }
        if (endInput) {
            endInput.addEventListener('change', () => {
                endInput.dataset.userEdited = 'true';
                if (startInput?.value && endInput.value && new Date(endInput.value) <= new Date(startInput.value)) {
                    endInput.value = toDatetimeLocal(defaultEventEnd(startInput.value));
                }
            });
        }
        allDayStart?.addEventListener('change', () => {
            if (allDayEnd && (!allDayEnd.value || allDayEnd.value < allDayStart.value)) allDayEnd.value = allDayStart.value;
        });
    }

    function syncEventEndWithStart(form, force = false) {
        const startInput = form.querySelector('input[name="time"]');
        const endInput = form.querySelector('input[name="endsAt"]');
        if (!startInput?.value || !endInput) return;
        const previousStart = startInput.dataset.previousValue;
        const previousEnd = endInput.value;
        const shouldSync = force
            || !previousEnd
            || endInput.dataset.userEdited !== 'true'
            || new Date(previousEnd) <= new Date(startInput.value);
        if (shouldSync) {
            const duration = previousStart && previousEnd
                ? Math.max(15, Math.round((new Date(previousEnd) - new Date(previousStart)) / 60000))
                : 60;
            endInput.value = toDatetimeLocal(addMinutes(startInput.value, Number.isFinite(duration) ? duration : 60));
            endInput.dataset.userEdited = 'false';
        }
        startInput.dataset.previousValue = startInput.value;
    }

    async function submitModal(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const kind = form.dataset.modalForm;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            if (kind === 'profile') {
                state.user = await api('/auth/me', { method: 'PATCH', body: { email: data.email } });
            } else if (kind === 'issue-report') {
                await submitIssueReport(form);
                return;
            } else if (kind === 'agent') {
                const priorities = Array.from(form.querySelectorAll('input[name="priorities"]:checked')).map((input) => input.value);
                state.user = await api('/auth/me', {
                    method: 'PATCH',
                    body: {
                        agent_personality: data.personality,
                        onboarding_priorities: priorities,
                        onboarding_context: data.context || null,
                        tts_openai_voice: data.ttsOpenAiVoice || 'coral',
                        tts_openai_instructions: data.ttsOpenAiInstructions || null,
                        workspace_id: data.workspaceId ? Number(data.workspaceId) : null,
                    },
                });
                await refreshOnly(false);
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
                const saved = await saveItem(kind, state.modal?.item, data, form);
                cacheSavedItem(kind, saved);
            }
            state.modal = null;
            state.notice = 'Saved.';
            render();
            refreshOnlyInBackground({ skipCalendarSync: true });
        } catch (error) {
            state.error = friendlyError(error, 'save that change');
            state.modal = null;
            render();
        }
    }

    async function submitIssueReport(form) {
        const formData = new FormData();
        const message = String(form.querySelector('textarea[name="message"]')?.value || '').trim();
        const files = Array.from(form.querySelector('input[name="screenshots"]')?.files || []).slice(0, 5);
        formData.append('message', message);
        formData.append('workspace_id', currentWorkspaceId() || '');
        formData.append('page_url', window.location.href);
        files.forEach((file) => formData.append('screenshots[]', file));

        state.issueReportSubmitting = true;
        state.error = '';
        render();
        try {
            await apiForm('/issue-reports', formData);
            state.issueReportSubmitting = false;
            state.modal = null;
            state.notice = 'Issue report sent. Thank you for helping test Bean.';
            render();
            if (state.selected === 'admin') loadAdminUsage(true);
        } catch (error) {
            state.issueReportSubmitting = false;
            state.error = friendlyError(error, 'send that issue report');
            render();
        }
    }

    async function saveItem(kind, item, data, form) {
        const color = data.color || '#34C759';
        if (kind === 'task') {
            const syncTo = selectedSyncWorkspaceIds(form);
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const parentTaskId = data.parentTaskId || taskParentId(item);
            const recurrence = recurrenceFormData(form, data);
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
                    ...recurrence.metadata,
                },
                sync_to_workspace_ids: syncTo,
            };
            if (!item && data.workspaceId) body.workspace_id = Number(data.workspaceId);
            return item ? await api(`/tasks/${item.id}`, { method: 'PATCH', body }) : await api('/tasks', { method: 'POST', body });
        } else if (kind === 'reminder') {
            const syncTo = selectedSyncWorkspaceIds(form);
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const recurrence = recurrenceFormData(form, data);
            const body = {
                title: data.title,
                remind_at: fromDatetimeLocal(data.time),
                status: item?.status || 'pending',
                category: data.category || null,
                color,
                metadata: {
                    ...existingMetadata,
                    ...recurrence.metadata,
                },
                sync_to_workspace_ids: syncTo,
            };
            if (!item && data.workspaceId) body.workspace_id = Number(data.workspaceId);
            return item ? await api(`/reminders/${item.id}`, { method: 'PATCH', body }) : await api('/reminders', { method: 'POST', body });
        } else if (kind === 'event') {
            const syncTo = selectedSyncWorkspaceIds(form);
            const allDay = form.elements.allDay?.checked || false;
            const existingMetadata = typeof item?.metadata === 'object' && item?.metadata ? item.metadata : {};
            const recurrence = recurrenceFormData(form, data);
            const body = {
                title: data.title,
                description: data.description || null,
                location: data.location || null,
                starts_at: allDay ? fromDateInputStart(data.allDayStart) : fromDatetimeLocal(data.time),
                ends_at: allDay ? fromDateInputEndInclusive(data.allDayEnd || data.allDayStart) : fromDatetimeLocal(data.endsAt),
                category: data.category || null,
                color,
                is_critical: form.elements.critical?.checked || false,
                recurrence: recurrence.value,
                status: data.status || 'confirmed',
                sync_to_workspace_ids: syncTo,
                metadata: {
                    ...existingMetadata,
                    ...recurrence.metadata,
                    google_calendar_ids: selectedGoogleCalendarIds(form),
                    all_day: allDay,
                },
            };
            if (!item && data.workspaceId) body.workspace_id = Number(data.workspaceId);
            const saved = item ? await api(`/calendar-events/${item.id}`, { method: 'PATCH', body }) : await api('/calendar-events', { method: 'POST', body });
            return saved ? {
                ...saved,
                linked_workspace_ids: normalizeList(saved.linked_workspace_ids || saved.linkedWorkspaceIds).length
                    ? normalizeList(saved.linked_workspace_ids || saved.linkedWorkspaceIds)
                    : [saved.workspace_id || saved.workspaceId, ...syncTo].filter(Boolean),
            } : saved;
        }
        return null;
    }

    function recurrenceFormData(form, data = {}) {
        const recurrence = data.recurrence || 'none';
        const specificDays = recurrence === 'specific_days'
            ? Array.from(form.querySelectorAll('input[name="specificDays"]:checked')).map((input) => input.value)
            : [];
        const intervalUnit = recurrence === 'interval' ? data.intervalUnit || 'days' : null;
        return {
            value: recurrence,
            metadata: {
                recurrence,
                specific_days: specificDays,
                days: specificDays,
                interval: recurrence === 'interval' && data.interval ? Number(data.interval) : null,
                interval_unit: intervalUnit,
                unit: intervalUnit,
            },
        };
    }

    function selectedSyncWorkspaceIds(form) {
        return Array.from(form.querySelectorAll('input[name="syncWorkspaceIds"]:checked'))
            .map((input) => Number(input.value))
            .filter(Boolean);
    }

    function selectedGoogleCalendarIds(form) {
        if (state.googleStatus?.connected !== true) return [];
        return Array.from(form.querySelectorAll('input[name="googleCalendarIds"]:checked'))
            .map((input) => String(input.value))
            .filter(Boolean);
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
        if (kind === 'event' && eventIsRecurring(state.modal?.item)) {
            state.modal = { type: 'recurring-delete', item: state.modal.item };
            render();
            return;
        }
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

    async function confirmRecurringDelete(event) {
        const mode = event.currentTarget.dataset.recurringDeleteMode;
        const item = state.modal?.item;
        if (!item || !mode) return;
        const snapshot = snapshotLists('event');
        state.modal = null;
        removeCachedRecurringEvents(item, mode);
        render();
        try {
            await api(`/calendar-events/${item.id}`, {
                method: 'DELETE',
                body: deleteEventPayload(item, mode),
            });
            await refreshOnly(true, { skipCalendarSync: true });
        } catch (error) {
            restoreSnapshot('event', snapshot);
            state.error = friendlyError(error, 'delete that recurring event');
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
        if (kind === 'task') {
            state.tasks = snapshot;
            state.pendingTaskUpserts.clear();
            snapshot.forEach((item) => state.pendingTaskDeletes.delete(String(item.id)));
        }
        if (kind === 'reminder') {
            state.reminders = snapshot;
            state.pendingReminderUpserts.clear();
            snapshot.forEach((item) => state.pendingReminderDeletes.delete(String(item.id)));
        }
        if (kind === 'event') {
            state.calendar = snapshot;
            state.pendingCalendarUpserts.clear();
            snapshot.forEach((item) => state.pendingCalendarDeletes.delete(String(item.id)));
        }
    }

    function cacheSavedItem(kind, item) {
        if (!item) return;
        if (kind === 'task') {
            state.pendingTaskDeletes.delete(String(item.id));
            state.pendingTaskUpserts.set(String(item.id), item);
            state.tasks = upsertById(state.tasks, item);
        }
        if (kind === 'reminder') {
            state.pendingReminderDeletes.delete(String(item.id));
            state.pendingReminderUpserts.set(String(item.id), item);
            state.reminders = upsertById(state.reminders, item);
        }
        if (kind === 'event') {
            state.pendingCalendarDeletes.delete(String(item.id));
            state.pendingCalendarUpserts.set(String(item.id), item);
            state.calendar = upsertById(state.calendar, item);
        }
        saveDashboardCache();
    }

    function reconcileTaskRefresh(items) {
        return reconcilePendingRefresh(items, state.pendingTaskUpserts, state.pendingTaskDeletes);
    }

    function reconcileReminderRefresh(items) {
        return reconcilePendingRefresh(items, state.pendingReminderUpserts, state.pendingReminderDeletes);
    }

    function reconcileCalendarRefresh(items) {
        return reconcilePendingRefresh(items, state.pendingCalendarUpserts, state.pendingCalendarDeletes);
    }

    function reconcilePendingRefresh(items, pendingUpserts, pendingDeletes) {
        const source = normalizeList(items);
        const sourceIds = new Set(source.map((item) => String(item.id)));
        const list = source.filter((item) => !pendingDeletes.has(String(item.id)));
        const seenIds = new Set(list.map((item) => String(item.id)));
        pendingDeletes.forEach((id) => {
            if (!sourceIds.has(id)) pendingDeletes.delete(id);
        });
        pendingUpserts.forEach((item, id) => {
            if (pendingDeletes.has(id)) {
                pendingUpserts.delete(id);
                return;
            }
            if (seenIds.has(id)) {
                pendingUpserts.delete(id);
                return;
            }
            list.push(item);
        });
        return list;
    }

    function upsertById(items, nextItem) {
        const nextId = String(nextItem?.id ?? '');
        if (!nextId) return normalizeList(items);
        const list = normalizeList(items);
        const index = list.findIndex((item) => String(item.id) === nextId);
        if (index < 0) return [...list, nextItem];
        return list.map((item, itemIndex) => (itemIndex === index ? { ...item, ...nextItem } : item));
    }

    function removeCachedItem(kind, id) {
        const matches = (item) => String(item.id) === String(id);
        if (kind === 'task') {
            state.pendingTaskDeletes.add(String(id));
            state.pendingTaskUpserts.delete(String(id));
            state.tasks = state.tasks.filter((item) => !matches(item));
        }
        if (kind === 'reminder') {
            state.pendingReminderDeletes.add(String(id));
            state.pendingReminderUpserts.delete(String(id));
            state.reminders = state.reminders.filter((item) => !matches(item));
        }
        if (kind === 'event') {
            state.pendingCalendarDeletes.add(String(id));
            state.pendingCalendarUpserts.delete(String(id));
            state.calendar = state.calendar.filter((item) => !matches(item));
        }
        saveDashboardCache();
    }

    function removeCachedRecurringEvents(event, mode) {
        const sourceId = recurringSourceId(event);
        const selectedDate = recurringOccurrenceDate(event);
        state.calendar = state.calendar.filter((candidate) => {
            if (recurringSourceId(candidate) !== sourceId) return true;
            const candidateDate = recurringOccurrenceDate(candidate);
            const remove = mode === 'all'
                || (mode === 'single' && candidateDate === selectedDate)
                || (mode === 'future' && candidateDate >= selectedDate);
            if (remove) {
                state.pendingCalendarDeletes.add(String(candidate.id));
                state.pendingCalendarUpserts.delete(String(candidate.id));
            }
            if (mode === 'all') return false;
            if (mode === 'single') return candidateDate !== selectedDate;
            if (mode === 'future') return candidateDate < selectedDate;
            return true;
        });
        saveDashboardCache();
    }

    function deleteEventPayload(event = null, recurringMode = null) {
        if (!event) return {};
        const workspaceIds = normalizeList(event.linked_workspace_ids || event.linkedWorkspaceIds)
            .concat([event.workspace_id || event.workspaceId])
            .map((id) => Number(id))
            .filter(Boolean);
        const uniqueWorkspaceIds = Array.from(new Set(workspaceIds));
        return {
            delete_from_workspace_ids: uniqueWorkspaceIds,
            recurring_delete_mode: recurringMode || (eventIsRecurring(event) ? 'all' : undefined),
            recurring_occurrence_date: recurringOccurrenceDate(event),
        };
    }

    function eventIsRecurring(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        const recurrence = event?.recurrence || metadata.recurrence || 'none';
        return (recurrence && recurrence !== 'none')
            || metadata.recurrence_generated === true
            || metadata.recurrence_generated === 1
            || metadata.recurrence_generated === 'true'
            || Boolean(metadata.recurrence_parent_event_id);
    }

    function recurringSourceId(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        return String(metadata.recurrence_parent_event_id || event?.id || '');
    }

    function recurringOccurrenceDate(event = null) {
        const metadata = typeof event?.metadata === 'object' && event?.metadata ? event.metadata : {};
        return String(metadata.recurrence_occurrence_date || (eventAllDay(event) ? storedDateOnly(event?.starts_at || event?.startsAt || new Date()) : dateOnly(event?.starts_at || event?.startsAt || new Date())));
    }

    function toggleAllDayFields(checkbox) {
        const form = checkbox.closest('form');
        if (!form) return;
        const allDay = checkbox.checked;
        if (allDay) {
            const startInput = form.querySelector('input[name="time"]');
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            const allDayEnd = form.querySelector('input[name="allDayEnd"]');
            if (startInput?.value && allDayStart) {
                allDayStart.value = dateOnly(startInput.value);
                if (allDayEnd && (!allDayEnd.value || allDayEnd.value < allDayStart.value)) allDayEnd.value = allDayStart.value;
            }
        } else {
            const startInput = form.querySelector('input[name="time"]');
            const endInput = form.querySelector('input[name="endsAt"]');
            const allDayStart = form.querySelector('input[name="allDayStart"]');
            if (allDayStart?.value && startInput && endInput && !startInput.value) {
                const start = parseLocalDate(allDayStart.value);
                start.setHours(9, 0, 0, 0);
                startInput.value = toDatetimeLocal(start);
                endInput.value = toDatetimeLocal(defaultEventEnd(start));
            }
        }
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
        const snapshot = snapshotLists('task');
        const completedAt = new Date().toISOString();
        const recurringNextDueAt = !completed && taskIsRecurring(task) ? nextRecurringTaskDueAt(task) : null;
        const optimistic = recurringNextDueAt
            ? {
                ...task,
                status: 'open',
                completed_at: null,
                due_at: recurringNextDueAt.toISOString(),
                metadata: {
                    ...(task.metadata || {}),
                    last_completed_at: completedAt,
                    ...(task.due_at ? { last_completed_due_at: task.due_at } : {}),
                    completion_count: taskCompletionCount(task) + 1,
                },
            }
            : {
                ...task,
                status: completed ? 'pending' : 'completed',
                completed_at: completed ? null : completedAt,
            };
        state.pendingTaskUpserts.set(String(task.id), optimistic);
        state.tasks = upsertById(state.tasks, optimistic);
        state.error = '';
        saveDashboardCache();
        render();
        try {
            const saved = await api(`/tasks/${task.id}`, {
                method: 'PATCH',
                body: {
                    status: completed ? 'pending' : 'completed',
                    completed_at: completed ? null : completedAt,
                },
            });
            cacheSavedItem('task', saved);
            refreshOnlyInBackground({ skipCalendarSync: true });
        } catch (error) {
            restoreSnapshot('task', snapshot);
            state.error = friendlyError(error, completed ? 'reopen that task' : 'complete that task');
            render();
        }
    }

    async function toggleReminder(reminder) {
        if (!reminder) return;
        const completed = reminderCompleted(reminder);
        const snapshot = snapshotLists('reminder');
        const optimistic = { ...reminder, status: completed ? 'pending' : 'completed' };
        state.pendingReminderUpserts.set(String(reminder.id), optimistic);
        state.reminders = upsertById(state.reminders, optimistic);
        state.error = '';
        saveDashboardCache();
        render();
        try {
            const saved = await api(`/reminders/${reminder.id}`, {
                method: 'PATCH',
                body: { status: completed ? 'pending' : 'completed' },
            });
            cacheSavedItem('reminder', saved);
            refreshOnlyInBackground({ skipCalendarSync: true });
        } catch (error) {
            restoreSnapshot('reminder', snapshot);
            state.error = friendlyError(error, completed ? 'reopen that reminder' : 'complete that reminder');
            render();
        }
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
            restartKioskVoiceListeningSoon(900);
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

    async function cancelBeanTurn(event = null) {
        event?.preventDefault?.();
        event?.stopPropagation?.();
        if (activeChatRequestId) {
            cancelledChatRequestIds.add(activeChatRequestId);
        }
        state.busy = false;
        state.chatRunState = 'Ready';
        state.voiceStatus = '';
        state.voiceStatusTone = '';
        kioskConversationActive = false;
        kioskCommandText = '';
        if (kioskRealtime?.dataChannel?.readyState === 'open') {
            try { kioskRealtime.dataChannel.send(JSON.stringify({ type: 'response.cancel' })); } catch (_) {}
        }
        stopKioskSpeechPlayback();
        setKioskVoiceStatus(
            state.kioskVoiceEnabled ? (kioskRealtimeConnected() ? 'armed' : 'working') : 'idle',
            state.kioskVoiceEnabled ? (kioskRealtimeConnected() ? 'Bean voice ready' : 'Reconnecting') : ''
        );
        render();
        scrollChatToBottom();
        if (state.kioskVoiceEnabled) {
            if (kioskRealtimeConnected()) {
                restartKioskVoiceListeningSoon(700);
            } else {
                scheduleKioskRealtimeReconnect('cancel_reconnect');
            }
        }

        if (!state.session?.id) return;
        try {
            state.session = await api(`/assistant/sessions/${state.session.id}/cancel`, { method: 'POST' });
        } catch (error) {
            // A completed turn can race the cancel request; the UI has already been released.
        }
    }

    async function sendChatContent(content, options = {}) {
        const requestId = ++chatRequestCounter;
        activeChatRequestId = requestId;
        const wasOnboarding = needsBeanOnboarding();
        let result = null;
        let assistantContent = '';
        window.clearTimeout(kioskAutoCloseTimer);
        if (options.autoOpenChat && state.selected !== 'bean') {
            state.chatExpanded = true;
        }
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
                    body: chatSessionPayload(onboarding),
                });
            }
            const metadata = {
                client_context: clientContextPayload(),
                ...(options.voiceQuickReply || options.voiceQuickReplyPending
                    ? {
                        voice_context: {
                            mode: 'live_voice',
                            ...(options.voiceDetailedChat ? { detailed_chat: true } : {}),
                            ...(options.voiceQuickReply
                                ? { quick_reply: String(options.voiceQuickReply).trim().slice(0, 220) }
                                : { quick_reply_pending: true }),
                        },
                    }
                    : {}),
            };
            result = await api(`/assistant/sessions/${state.session.id}/messages`, {
                method: 'POST',
                body: { content, metadata },
            });
            options.onAgentResult?.(result);
            if (cancelledChatRequestIds.has(requestId)) {
                state.session = result.session || state.session;
                state.activity = normalizeList(result.events).length ? result.events : state.activity;
                state.chatRunState = 'Ready';
                await refreshOnly(false);
                return { result, assistantContent: '' };
            }
            state.session = result.session || state.session;
            state.activity = normalizeList(result.events).length ? result.events : state.activity;
            if (result.user_message) replaceLocalUserMessage(result.user_message);
            if (result.assistant_message) {
                state.messages.push(result.assistant_message);
                assistantContent = result.assistant_message.content || '';
            }
            state.chatRunState = result.status === 'blocked' ? 'Blocked' : 'Ready';
            await refreshOnly(false);
            if (wasOnboarding && !needsBeanOnboarding()) {
                state.onboardingJustCompleted = true;
            }
            loadChatSessions({ resumeToday: false, shouldRender: false }).then(() => render()).catch(() => {});
        } catch (error) {
            if (!cancelledChatRequestIds.has(requestId)) {
                assistantContent = friendlyError(error, 'send that message');
                state.messages.push({ id: `error-${Date.now()}`, role: 'assistant', content: assistantContent });
                state.chatRunState = 'Failed';
            }
        } finally {
            cancelledChatRequestIds.delete(requestId);
            if (activeChatRequestId === requestId) {
                activeChatRequestId = 0;
                state.busy = false;
            }
            render();
            scrollChatToBottom();
            if (options.autoCloseChatMs) {
                scheduleKioskChatAutoClose(options.autoCloseChatMs);
            }
        }
        return { result, assistantContent };
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
        pauseKioskVoiceListening();
        const hasMicrophoneAccess = await requestMicrophoneAccess();
        if (!hasMicrophoneAccess || !voiceHoldPressed) {
            restartKioskVoiceListeningSoon(700);
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
                sendChatContent(content).finally(() => restartKioskVoiceListeningSoon(900));
                return;
            }
            if (shouldSubmit && !content) {
                state.voiceStatus = 'I did not catch anything. Hold the Bean button, speak, then release.';
                state.voiceStatusTone = 'error';
            }
            render();
            restartKioskVoiceListeningSoon(700);
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
            restartKioskVoiceListeningSoon(900);
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
            restartKioskVoiceListeningSoon(900);
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

        sendChatContent(content).finally(() => restartKioskVoiceListeningSoon(900));
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

    function shouldUseRealtimeKioskVoice() {
        return !kioskRealtimeSupportFailureReason();
    }

    function kioskRealtimeSupportFailureReason() {
        if (!window.isSecureContext) return 'unsupported_browser_security_context';
        if (!window.RTCPeerConnection) return 'unsupported_browser_webrtc';
        if (!navigator.mediaDevices?.getUserMedia) return 'unsupported_browser_microphone';
        return '';
    }

    function kioskRealtimeConnected() {
        return Boolean(kioskRealtime?.connected && kioskRealtime?.peerConnection);
    }

    function reportKioskRealtimeIssue(type, details = {}) {
        const payload = {
            event_type: String(type || 'unknown_realtime_issue').slice(0, 100),
            session_id: kioskRealtime?.sessionId || state.session?.id || null,
            phase: state.kioskVoicePhase || '',
            message: state.kioskVoiceMessage || '',
            details: {
                ...details,
                user_agent: navigator.userAgent || '',
            },
        };
        if (window.console?.warn) {
            console.warn('Bean realtime voice issue', payload);
        }
        if (!state.token) return;
        fetchWithTimeout('/api/assistant/realtime/client-events', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${state.token}`,
            },
            body: JSON.stringify(payload),
        }, 5000).catch(() => {});
    }

    function clearKioskRealtimeReconnect() {
        window.clearTimeout(kioskRealtimeReconnectTimer);
        kioskRealtimeReconnectTimer = 0;
    }

    function scheduleKioskRealtimeReconnect(reason, details = {}, delay = null) {
        if (!state.kioskVoiceEnabled || state.phase !== 'signedIn' || !state.token) return;
        clearKioskRealtimeReconnect();
        const nextAttempt = kioskRealtimeReconnectAttempts + 1;
        if (nextAttempt > kioskRealtimeMaxReconnectAttempts) {
            setKioskVoiceStatus('error', 'Voice unavailable');
            reportKioskRealtimeIssue('realtime_reconnect_exhausted', {
                reason,
                attempt: kioskRealtimeReconnectAttempts,
                max_attempts: kioskRealtimeMaxReconnectAttempts,
                ...details,
            });
            return;
        }
        kioskRealtimeReconnectAttempts = nextAttempt;
        const wait = delay ?? Math.min(1000 * (2 ** Math.min(kioskRealtimeReconnectAttempts - 1, 4)), 15000);
        setKioskVoiceStatus('working', 'Reconnecting');
        reportKioskRealtimeIssue('realtime_reconnect_scheduled', {
            reason,
            wait_ms: wait,
            attempt: kioskRealtimeReconnectAttempts,
            ...details,
        });
        kioskRealtimeReconnectTimer = window.setTimeout(() => {
            kioskRealtimeReconnectTimer = 0;
            if (!state.kioskVoiceEnabled || kioskRealtimeConnected() || kioskRealtimeStarting) return;
            startKioskRealtimeVoiceMode({ requestPermission: false, reconnect: true });
        }, wait);
    }

    async function startKioskRealtimeVoiceMode(options = {}) {
        if (kioskRealtimeConnected() || kioskRealtimeStarting) return true;
        if (!state.kioskVoiceEnabled || state.phase !== 'signedIn' || !state.token || state.voiceListening) return false;
        kioskRealtimeStarting = true;
        setKioskVoiceStatus('working', options.reconnect ? 'Reconnecting' : 'Connecting Bean voice');
        let stream = null;
        let peerConnection = null;
        try {
            const unsupportedReason = kioskRealtimeSupportFailureReason();
            if (unsupportedReason) {
                reportKioskRealtimeIssue(unsupportedReason, {
                    secure_context: window.isSecureContext,
                    has_peer_connection: Boolean(window.RTCPeerConnection),
                    has_get_user_media: Boolean(navigator.mediaDevices?.getUserMedia),
                });
                setKioskVoiceStatus('error', 'Voice unavailable');
                return false;
            }
            if (!await requestKioskMicrophoneAccess(Boolean(options.requestPermission))) {
                setKioskVoiceStatus('error', "Bean voice couldn't connect");
                return false;
            }
            stream = await navigator.mediaDevices.getUserMedia({ audio: await kioskAudioConstraints() });
            await rememberKioskMicrophoneFromStream(stream);
            kioskMicrophoneReady = true;
            await ensureRealtimeChatSession();

            peerConnection = new RTCPeerConnection();
            const remoteAudio = new Audio();
            remoteAudio.autoplay = true;
            remoteAudio.playsInline = true;
            peerConnection.ontrack = (event) => {
                remoteAudio.srcObject = event.streams[0];
                remoteAudio.play().catch(() => {});
            };
            stream.getTracks().forEach((track) => peerConnection.addTrack(track, stream));
            const dataChannel = peerConnection.createDataChannel('oai-events');
            const realtimeState = {
                peerConnection,
                dataChannel,
                stream,
                remoteAudio,
                disconnectTimer: 0,
                connected: false,
                sessionId: state.session?.id || null,
            };
            const reconnectFromFailure = (type, details = {}) => {
                if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled) return;
                window.clearTimeout(realtimeState.disconnectTimer);
                realtimeState.disconnectTimer = 0;
                reportKioskRealtimeIssue(type, details);
                stopKioskRealtimeVoiceMode({ preserveStatus: true, preserveReconnect: true });
                scheduleKioskRealtimeReconnect(type, details);
            };
            const waitForTransientDisconnect = (type, details = {}) => {
                if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled || realtimeState.disconnectTimer) return;
                setKioskVoiceStatus('working', 'Reconnecting');
                reportKioskRealtimeIssue(`${type}_transient`, details);
                realtimeState.disconnectTimer = window.setTimeout(() => {
                    realtimeState.disconnectTimer = 0;
                    if (kioskRealtime !== realtimeState || !state.kioskVoiceEnabled) return;
                    const connectionState = peerConnection.connectionState || '';
                    const iceConnectionState = peerConnection.iceConnectionState || '';
                    if (
                        ['failed', 'closed', 'disconnected'].includes(connectionState)
                        || ['failed', 'closed', 'disconnected'].includes(iceConnectionState)
                        || dataChannel.readyState !== 'open'
                    ) {
                        reconnectFromFailure(type, {
                            ...details,
                            connection_state: connectionState,
                            ice_connection_state: iceConnectionState,
                            data_channel_state: dataChannel.readyState,
                        });
                        return;
                    }
                    setKioskVoiceStatus('armed', 'Bean voice ready');
                }, 5000);
            };
            peerConnection.onconnectionstatechange = () => {
                const connectionState = peerConnection.connectionState || '';
                if (['failed', 'closed'].includes(connectionState)) {
                    reconnectFromFailure('webrtc_connection_failure', { connection_state: connectionState });
                    return;
                }
                if (connectionState === 'disconnected') {
                    waitForTransientDisconnect('webrtc_connection_disconnected', { connection_state: connectionState });
                    return;
                }
                if (connectionState === 'connected') {
                    window.clearTimeout(realtimeState.disconnectTimer);
                    realtimeState.disconnectTimer = 0;
                    setKioskVoiceStatus('armed', 'Bean voice ready');
                }
            };
            peerConnection.oniceconnectionstatechange = () => {
                const iceConnectionState = peerConnection.iceConnectionState || '';
                if (['failed', 'closed'].includes(iceConnectionState)) {
                    reconnectFromFailure('ice_webrtc_connection_failure', { ice_connection_state: iceConnectionState });
                    return;
                }
                if (iceConnectionState === 'disconnected') {
                    waitForTransientDisconnect('ice_webrtc_connection_disconnected', { ice_connection_state: iceConnectionState });
                    return;
                }
                if (['connected', 'completed'].includes(iceConnectionState)) {
                    window.clearTimeout(realtimeState.disconnectTimer);
                    realtimeState.disconnectTimer = 0;
                    setKioskVoiceStatus('armed', 'Bean voice ready');
                }
            };
            kioskRealtime = realtimeState;
            dataChannel.onopen = () => {
                realtimeState.connected = true;
                kioskRealtimeUnavailable = false;
                kioskRealtimeReconnectAttempts = 0;
                clearKioskRealtimeReconnect();
                setKioskVoiceStatus('armed', 'Bean voice ready');
                render();
            };
            dataChannel.onmessage = (event) => handleKioskRealtimeEvent(event);
            dataChannel.onerror = (event) => reconnectFromFailure('data_channel_error', {
                ready_state: dataChannel.readyState,
                message: event?.message || '',
            });
            dataChannel.onclose = (event) => reconnectFromFailure('data_channel_close', {
                ready_state: dataChannel.readyState,
                code: event?.code || null,
                reason: event?.reason || '',
            });

            const offer = await peerConnection.createOffer();
            await peerConnection.setLocalDescription(offer);
            const sdpResponse = await fetchWithTimeout('/api/assistant/realtime/calls', {
                method: 'POST',
                headers: {
                    Accept: 'application/sdp',
                    'Content-Type': 'application/json',
                    ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
                },
                body: JSON.stringify({
                    session_id: state.session?.id,
                    sdp: offer.sdp,
                    voice: profileTtsVoice(),
                    metadata: {
                        source: 'web_realtime_voice',
                        client_context: clientContextPayload(),
                    },
                }),
            }, kioskRealtimeConnectTimeoutMs);
            const sdpBody = await sdpResponse.text();
            if (!sdpResponse.ok) {
                let payload = null;
                try { payload = JSON.parse(sdpBody); } catch (_) {}
                reportKioskRealtimeIssue('server_realtime_calls_failure', {
                    status: sdpResponse.status,
                    body: sdpBody.slice(0, 1000),
                    payload,
                });
                const error = new Error(payload?.message || `Realtime voice failed (${sdpResponse.status}).`);
                error.reported = true;
                error.status = sdpResponse.status;
                error.body = sdpBody.slice(0, 1000);
                error.payload = payload;
                error.nonRetryable = payload?.retryable === false;
                throw error;
            }
            await peerConnection.setRemoteDescription({ type: 'answer', sdp: sdpBody });
            return true;
        } catch (error) {
            stopKioskRealtimeVoiceMode({ preserveStatus: true, preserveReconnect: true });
            if (stream) stream.getTracks().forEach((track) => track.stop());
            try { peerConnection?.close(); } catch (_) {}
            if (!error?.reported) {
                reportKioskRealtimeIssue('realtime_start_failure', {
                    name: error?.name || '',
                    message: error?.message || '',
                    status: error?.status || null,
                    body: error?.body || '',
                });
            }
            if (error?.nonRetryable) {
                clearKioskRealtimeReconnect();
                setKioskVoiceStatus('error', 'Voice unavailable');
                reportKioskRealtimeIssue('realtime_start_not_retryable', {
                    name: error?.name || '',
                    message: error?.message || '',
                    upstream_message: error?.payload?.upstream_message || '',
                    upstream_status: error?.payload?.status || null,
                });
            } else {
                setKioskVoiceStatus('error', "Bean voice couldn't connect");
                scheduleKioskRealtimeReconnect('realtime_start_failure', {
                    name: error?.name || '',
                    message: error?.message || '',
                });
            }
            return false;
        } finally {
            kioskRealtimeStarting = false;
        }
    }

    async function ensureRealtimeChatSession() {
        if (state.session?.id) return state.session;
        const onboarding = needsBeanOnboarding();
        state.session = await api('/assistant/sessions', {
            method: 'POST',
            body: chatSessionPayload(onboarding),
        });
        await loadChatSessions({ resumeToday: false, shouldRender: false });
        return state.session;
    }

    function stopKioskRealtimeVoiceMode(options = {}) {
        const realtime = kioskRealtime;
        kioskRealtime = null;
        kioskRealtimeStarting = false;
        if (!options.preserveReconnect) {
            clearKioskRealtimeReconnect();
            kioskRealtimeReconnectAttempts = 0;
        }
        kioskRealtimePendingUser = null;
        kioskRealtimeAssistantDraft = null;
        kioskRealtimeSuppressNextAssistantPersist = false;
        kioskRealtimeVoiceOnlyAssistant = false;
        window.clearTimeout(kioskRealtimeToolFallbackTimer);
        kioskRealtimeToolFallbackTimer = 0;
        kioskRealtimeToolFallbackContent = '';
        kioskRealtimeProcessedCalls.clear();
        kioskRealtimeRunWatchTimers.forEach((timer) => window.clearTimeout(timer));
        kioskRealtimeRunWatchTimers.clear();
        if (realtime?.dataChannel?.readyState === 'open') {
            try { realtime.dataChannel.close(); } catch (_) {}
        }
        try { realtime?.peerConnection?.close(); } catch (_) {}
        realtime?.stream?.getTracks().forEach((track) => track.stop());
        if (realtime?.remoteAudio) {
            try {
                realtime.remoteAudio.pause();
                realtime.remoteAudio.srcObject = null;
            } catch (_) {}
        }
        if (!options.preserveStatus) {
            state.kioskVoicePhase = 'idle';
            state.kioskVoiceMessage = '';
        }
    }

    function handleKioskRealtimeEvent(event) {
        let payload = null;
        try {
            payload = JSON.parse(event.data);
        } catch (_) {
            return;
        }
        const type = payload?.type || '';
        if (type === 'input_audio_buffer.speech_started') {
            if (kioskConversationActive) {
                setKioskVoiceStatus('listening', 'listening');
            }
            return;
        }
        if (type === 'input_audio_buffer.speech_stopped') {
            if (kioskConversationActive) {
                setKioskVoiceStatus('working', 'thinking');
            }
            return;
        }
        if (type === 'conversation.item.input_audio_transcription.completed') {
            handleRealtimeUserTranscript(payload);
            return;
        }
        if (type === 'response.created') {
            setKioskVoiceStatus('responding', "Bean's voice");
            return;
        }
        if (type === 'response.audio_transcript.delta' || type === 'response.output_text.delta') {
            appendRealtimeAssistantDelta(payload);
            return;
        }
        if (type === 'response.audio_transcript.done' || type === 'response.output_text.done') {
            finishRealtimeAssistantTranscript(payload);
            return;
        }
        if (type === 'response.function_call_arguments.done') {
            processRealtimeFunctionCall(payload.name, payload.call_id, payload.arguments);
            return;
        }
        if (type === 'response.done') {
            processRealtimeResponseDone(payload);
            return;
        }
        if (type === 'error') {
            setKioskVoiceStatus('error', payload?.error?.message || 'voice error');
        }
    }

    function handleRealtimeUserTranscript(payload) {
        const raw = String(payload.transcript || '').trim();
        if (!raw) return;
        const command = commandAfterWakePhrase(raw);
        const isWakeTurn = command !== null;
        if (!isWakeTurn && !kioskConversationActive) {
            setKioskVoiceStatus('armed', 'Bean voice ready');
            return;
        }
        if (conversationEndRequested(raw)) {
            endKioskConversation('done');
            return;
        }
        if (isWakeTurn) {
            beginKioskConversation();
        }
        const content = (isWakeTurn ? command : raw).trim();
        if (!content) {
            setKioskVoiceStatus('listening', 'listening');
            armKioskConversationTimeout();
            return;
        }
        kioskRealtimePendingUser = {
            itemId: payload.item_id || `rt-user-${Date.now()}`,
            content,
            persisted: false,
        };
        upsertRealtimeLocalMessage({
            id: `rt-user-${kioskRealtimePendingUser.itemId}`,
            role: 'user',
            content,
            metadata: { local_realtime_turn: true },
        });
        armRealtimeToolFallback(content);
        setKioskVoiceStatus('working', 'thinking');
        sendRealtimeResponseCreate();
    }

    function appendRealtimeAssistantDelta(payload) {
        const delta = String(payload.delta || '');
        if (!delta) return;
        const draft = ensureRealtimeAssistantDraft(payload.response_id || payload.item_id);
        draft.content += delta;
        if (!kioskRealtimeVoiceOnlyAssistant) {
            upsertRealtimeLocalMessage(draft);
        }
        setKioskVoiceStatus('responding', "Bean's voice");
    }

    function finishRealtimeAssistantTranscript(payload) {
        const text = String(payload.transcript || payload.text || '').trim();
        if (!text) return;
        const draft = ensureRealtimeAssistantDraft(payload.response_id || payload.item_id);
        draft.content = text;
        if (!kioskRealtimeVoiceOnlyAssistant) {
            upsertRealtimeLocalMessage(draft);
        }
    }

    function ensureRealtimeAssistantDraft(id = '') {
        const draftId = id || kioskRealtimeAssistantDraft?.itemId || `rt-assistant-${Date.now()}`;
        if (!kioskRealtimeAssistantDraft || kioskRealtimeAssistantDraft.itemId !== draftId) {
            kioskRealtimeAssistantDraft = {
                id: `rt-assistant-${draftId}`,
                itemId: draftId,
                role: 'assistant',
                content: '',
                metadata: { local_realtime_turn: true },
            };
        }
        return kioskRealtimeAssistantDraft;
    }

    function upsertRealtimeLocalMessage(message) {
        const index = state.messages.findIndex((item) => String(item.id) === String(message.id));
        if (index >= 0) {
            state.messages[index] = { ...state.messages[index], ...message };
        } else {
            state.messages.push(message);
        }
        if (state.phase === 'signedIn') render();
        scrollChatToBottom();
    }

    function processRealtimeResponseDone(payload) {
        const output = normalizeList(payload?.response?.output);
        output
            .filter((item) => item?.type === 'function_call')
            .forEach((item) => processRealtimeFunctionCall(item.name, item.call_id, item.arguments));
        const hasFunctionCall = output.some((item) => item?.type === 'function_call');
        if (!hasFunctionCall && !kioskRealtimeToolFallbackContent) {
            persistRealtimeConversationTurn();
        } else {
            kioskRealtimePendingUser = null;
        }
        if (state.kioskVoiceEnabled && kioskRealtimeConnected()) {
            if (kioskConversationActive) {
                setKioskVoiceStatus('listening', 'listening');
                armKioskConversationTimeout();
            } else {
                setKioskVoiceStatus('armed', 'Bean voice ready');
            }
        }
    }

    async function processRealtimeFunctionCall(name, callId, rawArguments = '{}') {
        clearRealtimeToolFallback();
        const callKey = callId || `${name}-${rawArguments}`;
        if (!name || kioskRealtimeProcessedCalls.has(callKey)) return;
        kioskRealtimeProcessedCalls.add(callKey);
        let args = {};
        try {
            args = typeof rawArguments === 'string' ? JSON.parse(rawArguments || '{}') : (rawArguments || {});
        } catch (_) {
            args = {};
        }
        setKioskVoiceStatus('working', 'working in background');
        try {
            const result = await api('/assistant/realtime/tool-calls', {
                method: 'POST',
                body: {
                    session_id: kioskRealtime?.sessionId || state.session?.id,
                    tool_name: name,
                    call_id: callId || null,
                    arguments: {
                        ...args,
                        client_context: {
                            ...(args.client_context || {}),
                            ...clientContextPayload(),
                        },
                    },
                },
            });
            sendRealtimeFunctionOutput(callId, result);
            if (result?.run_id) {
                watchRealtimeAssistantRun(result.run_id);
            }
            await loadChatSessions({ resumeToday: false, shouldRender: false }).catch(() => {});
            scheduleDashboardRealtimeRefresh([{ type: 'realtime_tool_call' }]);
        } catch (error) {
            sendRealtimeFunctionOutput(callId, {
                ok: false,
                message: friendlyError(error, 'start that background work'),
            });
            setKioskVoiceStatus('error', 'work failed');
        }
    }

    function armRealtimeToolFallback(content) {
        clearRealtimeToolFallback();
        const command = String(content || '').trim();
        if (!voiceCommandNeedsAgentWork(command)) return;
        kioskRealtimeToolFallbackContent = command;
        kioskRealtimeToolFallbackTimer = window.setTimeout(() => {
            kioskRealtimeToolFallbackTimer = 0;
            const pending = kioskRealtimeToolFallbackContent;
            kioskRealtimeToolFallbackContent = '';
            if (!pending || !state.kioskVoiceEnabled || !kioskRealtimeConnected()) return;
            queueRealtimeFallbackWork(pending);
        }, 2600);
    }

    function clearRealtimeToolFallback() {
        window.clearTimeout(kioskRealtimeToolFallbackTimer);
        kioskRealtimeToolFallbackTimer = 0;
        kioskRealtimeToolFallbackContent = '';
    }

    async function queueRealtimeFallbackWork(content) {
        if (!state.session?.id) return;
        setKioskVoiceStatus('working', 'working in background');
        try {
            const result = await api('/assistant/realtime/tool-calls', {
                method: 'POST',
                body: {
                    session_id: kioskRealtime?.sessionId || state.session.id,
                    tool_name: 'queue_bean_work',
                    call_id: `client_fallback_${Date.now()}`,
                    arguments: {
                        content,
                        client_context: clientContextPayload(),
                    },
                },
            });
            if (result?.run_id) {
                watchRealtimeAssistantRun(result.run_id);
            }
            await loadChatSessions({ resumeToday: false, shouldRender: false }).catch(() => {});
            scheduleDashboardRealtimeRefresh([{ type: 'realtime_tool_fallback' }]);
        } catch (error) {
            setKioskVoiceStatus('error', friendlyError(error, 'start that background work'));
        }
    }

    function sendRealtimeFunctionOutput(callId, result) {
        const dataChannel = kioskRealtime?.dataChannel;
        if (!callId || dataChannel?.readyState !== 'open') return;
        dataChannel.send(JSON.stringify({
            type: 'conversation.item.create',
            item: {
                type: 'function_call_output',
                call_id: callId,
                output: JSON.stringify(result),
            },
        }));
        sendRealtimeResponseCreate();
    }

    function sendRealtimeResponseCreate(options = {}) {
        const dataChannel = kioskRealtime?.dataChannel;
        if (dataChannel?.readyState !== 'open') return false;
        dataChannel.send(JSON.stringify({ type: 'response.create', ...options }));
        return true;
    }

    function watchRealtimeAssistantRun(runId, attempt = 0) {
        const id = Number(runId || 0);
        if (!id || kioskRealtimeRunWatchTimers.has(id)) return;
        const delay = attempt === 0 ? 900 : Math.min(1800 + (attempt * 450), 4500);
        const timer = window.setTimeout(async () => {
            kioskRealtimeRunWatchTimers.delete(id);
            if (!state.kioskVoiceEnabled) return;
            try {
                const run = await api(`/assistant/runs/${id}`);
                const status = String(run?.status || '').toLowerCase();
                if (['queued', 'running'].includes(status) && attempt < 45) {
                    watchRealtimeAssistantRun(id, attempt + 1);
                    return;
                }
                if (status === 'completed') {
                    handleRealtimeAssistantRunCompleted(run);
                    return;
                }
                if (status === 'failed') {
                    const message = run?.error ? `I could not finish that: ${run.error}` : 'I could not finish that request.';
                    deliverRealtimeBackgroundResult(message, id);
                    return;
                }
                if (status === 'cancelled') {
                    deliverRealtimeBackgroundResult('That request was cancelled.', id);
                }
            } catch (_) {
                if (attempt < 8) watchRealtimeAssistantRun(id, attempt + 1);
            }
        }, delay);
        kioskRealtimeRunWatchTimers.set(id, timer);
    }

    function handleRealtimeAssistantRunCompleted(run) {
        scheduleDashboardRealtimeRefresh([{ type: 'realtime_run_completed' }]);
        const assistantMessage = run?.assistant_message || run?.assistantMessage || null;
        const content = String(assistantMessage?.content || '').trim();
        if (!content) {
            deliverRealtimeBackgroundResult('I finished that request.', run?.id);
            return;
        }
        appendPersistedAssistantMessage(assistantMessage);
        if (kioskRealtimeConnected()) {
            deliverRealtimeBackgroundResult(content, run?.id);
            return;
        }
    }

    function appendPersistedAssistantMessage(message) {
        if (!message?.id || state.messages.some((item) => String(item.id) === String(message.id))) return;
        state.messages.push(message);
        state.chatRunState = 'Ready';
        render();
        scrollChatToBottom();
    }

    function deliverRealtimeBackgroundResult(content, runId = null) {
        const text = speechTextFromAssistant(content);
        if (!text) return;
        if (!kioskRealtimeConnected()) {
            upsertRealtimeLocalMessage({
                id: `rt-run-${runId || Date.now()}`,
                role: 'assistant',
                content: text,
                metadata: { local_realtime_turn: true, background_result: true },
            });
            return;
        }
        const dataChannel = kioskRealtime?.dataChannel;
        if (dataChannel?.readyState !== 'open') return;
        kioskRealtimeSuppressNextAssistantPersist = true;
        kioskRealtimeVoiceOnlyAssistant = true;
        dataChannel.send(JSON.stringify({
            type: 'conversation.item.create',
            item: {
                type: 'message',
                role: 'user',
                content: [{
                    type: 'input_text',
                    text: `Background work for my previous request is complete. Tell me this result naturally and concisely, without mentioning background work: ${text}`,
                }],
            },
        }));
        sendRealtimeResponseCreate();
    }

    async function persistRealtimeConversationTurn() {
        const sessionId = kioskRealtime?.sessionId || state.session?.id;
        const userTurn = kioskRealtimePendingUser;
        const assistantTurn = kioskRealtimeAssistantDraft;
        const suppressAssistantPersist = kioskRealtimeSuppressNextAssistantPersist;
        kioskRealtimePendingUser = null;
        kioskRealtimeAssistantDraft = null;
        kioskRealtimeSuppressNextAssistantPersist = false;
        kioskRealtimeVoiceOnlyAssistant = false;
        if (!sessionId) return;
        try {
            if (userTurn?.content && !userTurn.persisted) {
                await api('/assistant/realtime/messages', {
                    method: 'POST',
                    body: {
                        session_id: sessionId,
                        role: 'user',
                        content: userTurn.content,
                        metadata: { realtime: { item_id: userTurn.itemId } },
                    },
                });
            }
            if (assistantTurn?.content && !suppressAssistantPersist) {
                await api('/assistant/realtime/messages', {
                    method: 'POST',
                    body: {
                        session_id: sessionId,
                        role: 'assistant',
                        content: assistantTurn.content,
                        metadata: { realtime: { item_id: assistantTurn.itemId } },
                    },
                });
            }
            loadChatSessions({ resumeToday: false, shouldRender: false }).catch(() => {});
        } catch (_) {
            // Local realtime messages stay visible even if persistence races a page unload.
        }
    }

    async function startKioskVoiceMode(options = {}) {
        if (!state.kioskVoiceEnabled || state.phase !== 'signedIn' || !state.token) return;
        if (!shouldUseRealtimeKioskVoice()) {
            const reason = kioskRealtimeSupportFailureReason();
            reportKioskRealtimeIssue(reason || 'unsupported_browser_realtime', {
                secure_context: window.isSecureContext,
                has_peer_connection: Boolean(window.RTCPeerConnection),
                has_get_user_media: Boolean(navigator.mediaDevices?.getUserMedia),
            });
            setKioskVoiceStatus('error', 'Voice unavailable');
            return;
        }
        await startKioskRealtimeVoiceMode(options);
    }

    async function requestKioskMicrophoneAccess(requestPermission = false) {
        if (kioskMicrophoneReady) return true;
        if (!navigator.mediaDevices?.getUserMedia) {
            setKioskVoiceStatus('error', 'microphone unavailable');
            reportKioskRealtimeIssue('unsupported_browser_microphone', {
                has_get_user_media: Boolean(navigator.mediaDevices?.getUserMedia),
            });
            return false;
        }
        const permission = await microphonePermissionState();
        if (permission === 'denied') {
            setKioskVoiceStatus('error', 'mic blocked');
            reportKioskRealtimeIssue('mic_permission_failure', {
                permission_state: permission,
            });
            return false;
        }
        if (!requestPermission && permission !== 'granted') {
            setKioskVoiceStatus('error', 'click mic to allow');
            return false;
        }
        if (!requestPermission && permission === 'granted') {
            kioskMicrophoneReady = true;
            return true;
        }
        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: await kioskAudioConstraints() });
            await rememberKioskMicrophoneFromStream(stream);
            kioskMicrophoneReady = true;
            return true;
        } catch (error) {
            kioskMicrophoneReady = false;
            setKioskVoiceStatus('error', kioskMicrophoneAccessMessage(error));
            reportKioskRealtimeIssue('mic_permission_failure', {
                name: error?.name || '',
                message: error?.message || '',
            });
            return false;
        } finally {
            stream?.getTracks().forEach((track) => track.stop());
        }
    }

    async function kioskAudioConstraints() {
        const base = {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
        };
        const deviceId = await preferredLocalAudioInputDeviceId();
        return deviceId ? { ...base, deviceId: { ideal: deviceId } } : base;
    }

    async function preferredLocalAudioInputDeviceId() {
        if (!navigator.mediaDevices?.enumerateDevices) return '';
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const audioInputs = devices.filter((device) => device.kind === 'audioinput' && device.deviceId);
            const localInput = audioInputs.find((device) => {
                const label = String(device.label || '').toLowerCase();
                return label && !/(iphone|ipad|continuity|camera|nearby)/.test(label);
            });
            const storedInput = audioInputs.find((device) => device.deviceId === kioskPreferredAudioDeviceId);
            const storedLabel = String(storedInput?.label || '').toLowerCase();
            const storedIsRemote = /(iphone|ipad|continuity|camera|nearby)/.test(storedLabel);
            const deviceId = localInput?.deviceId || (storedInput && !storedIsRemote ? storedInput.deviceId : '');
            if (deviceId) {
                kioskPreferredAudioDeviceId = deviceId;
                localStorage.setItem('heybean-preferred-audio-input', deviceId);
            } else if (storedIsRemote) {
                kioskPreferredAudioDeviceId = '';
                localStorage.removeItem('heybean-preferred-audio-input');
            }
            return deviceId;
        } catch (_) {
            return '';
        }
    }

    async function rememberKioskMicrophoneFromStream(stream) {
        const deviceId = stream?.getAudioTracks?.()[0]?.getSettings?.().deviceId || '';
        if (!deviceId) return;
        if (navigator.mediaDevices?.enumerateDevices) {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const active = devices.find((device) => device.kind === 'audioinput' && device.deviceId === deviceId);
                if (/(iphone|ipad|continuity|camera|nearby)/i.test(active?.label || '')) {
                    const localDeviceId = await preferredLocalAudioInputDeviceId();
                    if (localDeviceId) return;
                }
            } catch (_) {}
        }
        kioskPreferredAudioDeviceId = deviceId;
        localStorage.setItem('heybean-preferred-audio-input', deviceId);
    }

    function kioskVoiceReady() {
        return state.kioskVoiceEnabled && kioskRealtimeConnected();
    }

    async function microphonePermissionState() {
        if (!navigator.permissions?.query) return '';
        try {
            const permission = await navigator.permissions.query({ name: 'microphone' });
            return permission.state || '';
        } catch (error) {
            return '';
        }
    }

    function pauseKioskVoiceListening() {
        kioskRecognitionShouldRestart = false;
        window.clearTimeout(kioskRestartTimer);
        window.clearTimeout(kioskCommandTimer);
        window.clearTimeout(kioskHeardTimer);
        window.clearTimeout(kioskConversationTimer);
        kioskRestartTimer = 0;
        kioskCommandTimer = 0;
        kioskHeardTimer = 0;
        kioskConversationTimer = 0;
        if (kioskRecognition) {
            const recognition = kioskRecognition;
            kioskRecognition = null;
            recognition.onend = null;
            recognition.onerror = null;
            try { recognition.stop(); } catch (_) {}
        }
        kioskRecognitionActive = false;
    }

    function startKioskBargeInListening() {
        if (!allowDebugBrowserVoiceFallback()) return;
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!state.kioskVoiceEnabled || !SpeechRecognition || kioskBargeRecognition || kioskRecognitionActive) return;
        window.clearTimeout(kioskBargeRestartTimer);
        kioskBargeRestartTimer = 0;

        const recognition = new SpeechRecognition();
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.lang = 'en-US';
        kioskBargeRecognition = recognition;
        recognition.onstart = () => {
            kioskBargeRecognitionActive = true;
        };
        recognition.onresult = (event) => {
            const transcript = speechTranscript(event, { fromResultIndex: true });
            if (!voiceCancelRequested(transcript)) return;
            stopKioskBargeInListening();
            cancelKioskVoiceCapture();
        };
        recognition.onend = () => {
            kioskBargeRecognition = null;
            kioskBargeRecognitionActive = false;
            restartKioskBargeInListeningSoon();
        };
        recognition.onerror = () => {
            kioskBargeRecognition = null;
            kioskBargeRecognitionActive = false;
            restartKioskBargeInListeningSoon(700);
        };
        try {
            recognition.start();
        } catch (error) {
            kioskBargeRecognition = null;
            kioskBargeRecognitionActive = false;
            restartKioskBargeInListeningSoon(350);
        }
    }

    function stopKioskBargeInListening() {
        window.clearTimeout(kioskBargeRestartTimer);
        kioskBargeRestartTimer = 0;
        if (!kioskBargeRecognition) {
            kioskBargeRecognitionActive = false;
            return;
        }
        const recognition = kioskBargeRecognition;
        kioskBargeRecognition = null;
        recognition.onend = null;
        recognition.onerror = null;
        recognition.onresult = null;
        try { recognition.stop(); } catch (_) {}
        kioskBargeRecognitionActive = false;
    }

    function restartKioskBargeInListeningSoon(delay = 300) {
        window.clearTimeout(kioskBargeRestartTimer);
        if (!state.kioskVoiceEnabled || kioskBargeRecognition || kioskRecognitionActive) return;
        if (!state.busy && !['working', 'responding'].includes(state.kioskVoicePhase)) return;
        kioskBargeRestartTimer = window.setTimeout(() => {
            kioskBargeRestartTimer = 0;
            startKioskBargeInListening();
        }, delay);
    }

    function stopKioskVoiceMode() {
        stopKioskRealtimeVoiceMode();
        pauseKioskVoiceListening();
        stopKioskBargeInListening();
        window.clearTimeout(kioskAutoCloseTimer);
        window.clearTimeout(kioskHeardTimer);
        window.clearTimeout(kioskConversationTimer);
        kioskAutoCloseTimer = 0;
        kioskHeardTimer = 0;
        kioskConversationTimer = 0;
        kioskCommandText = '';
        kioskConversationActive = false;
        state.kioskVoicePhase = 'idle';
        state.kioskVoiceMessage = '';
        stopKioskSpeechPlayback();
    }

    function restartKioskVoiceListeningSoon(delay = 900) {
        window.clearTimeout(kioskRestartTimer);
        if (!state.kioskVoiceEnabled || state.phase !== 'signedIn') return;
        if (kioskRealtimeConnected() || kioskRealtimeStarting) return;
        kioskRestartTimer = window.setTimeout(() => {
            kioskRestartTimer = 0;
            startKioskRealtimeVoiceMode({ requestPermission: false });
        }, delay);
    }

    function speechTranscript(event, options = {}) {
        const results = Array.from(event.results || []);
        const startIndex = options.fromResultIndex ? Math.max(0, event.resultIndex || 0) : 0;

        return results
            .slice(startIndex)
            .filter((result) => !options.finalOnly || result.isFinal)
            .map((result) => result[0]?.transcript || '')
            .join(' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function speechTranscriptCandidates(event, options = {}) {
        const results = Array.from(event.results || []);
        const startIndex = options.fromResultIndex ? Math.max(0, event.resultIndex || 0) : 0;
        const selectedResults = results.slice(startIndex).filter((result) => !options.finalOnly || result.isFinal);
        if (!selectedResults.length) return [];

        const primaryParts = selectedResults.map((result) => result[0]?.transcript || '');
        const candidates = new Set([primaryParts.join(' ')]);
        selectedResults.forEach((result, index) => {
            Array.from(result).slice(1, 4).forEach((alternative) => {
                const transcript = alternative?.transcript || '';
                if (!transcript.trim()) return;
                const parts = [...primaryParts];
                parts[index] = transcript;
                candidates.add(parts.join(' '));
            });
        });

        return Array.from(candidates)
            .map((candidate) => candidate.replace(/\s+/g, ' ').trim())
            .filter(Boolean);
    }

    function beginKioskConversation() {
        kioskConversationActive = true;
        window.clearTimeout(kioskConversationTimer);
    }

    function armKioskConversationTimeout() {
        window.clearTimeout(kioskConversationTimer);
        if (!kioskConversationActive || !state.kioskVoiceEnabled) return;
        kioskConversationTimer = window.setTimeout(() => {
            kioskConversationTimer = 0;
            endKioskConversation();
        }, 15000);
    }

    function endKioskConversation(message = '') {
        window.clearTimeout(kioskConversationTimer);
        window.clearTimeout(kioskCommandTimer);
        window.clearTimeout(kioskHeardTimer);
        window.clearTimeout(kioskBridgeTimer);
        kioskConversationTimer = 0;
        kioskCommandTimer = 0;
        kioskHeardTimer = 0;
        kioskBridgeTimer = 0;
        kioskConversationActive = false;
        kioskQuickReplyGeneration += 1;
        kioskCommandText = '';
        setKioskVoiceStatus('armed', message || 'say hey bean');
    }

    function cancelKioskVoiceCapture() {
        stopKioskBargeInListening();
        stopKioskSpeechPlayback();
        pauseKioskVoiceListening();
        endKioskConversation('cancelled');
        if (state.busy) {
            cancelBeanTurn();
            return;
        }
        restartKioskVoiceListeningSoon(650);
    }

    function conversationEndRequested(transcript) {
        return /\b(?:thanks|thank you|that'?s all|stop listening|cancel)\s+(?:bean|been|beam|being)\b/i.test(transcript)
            || /\b(?:thanks|thank you),?\s*(?:that'?s all|we'?re done)\b/i.test(transcript);
    }

    function showKioskHeardTranscript(transcript, options = {}) {
        if (!transcript || ['working', 'responding'].includes(state.kioskVoicePhase)) return;
        if (!kioskConversationActive) return;
        const preview = transcript.length > 44 ? `${transcript.slice(0, 41)}...` : transcript;
        const phase = options.phase || (kioskConversationActive ? 'heard' : 'armed');
        setKioskVoiceStatus(phase, `heard "${preview}"`);
        window.clearTimeout(kioskHeardTimer);
        kioskHeardTimer = window.setTimeout(() => {
            kioskHeardTimer = 0;
            if (state.kioskVoiceEnabled && kioskRecognitionActive && ['armed', 'heard'].includes(state.kioskVoicePhase)) {
                setKioskVoiceStatus(kioskConversationActive ? 'listening' : 'armed', kioskConversationActive ? 'listening' : 'say hey bean');
            }
        }, 1600);
    }

    function armKioskCommandSubmit() {
        window.clearTimeout(kioskCommandTimer);
        kioskCommandTimer = window.setTimeout(finishKioskVoiceCommand, 900);
    }

    async function finishKioskVoiceCommand() {
        const content = kioskCommandText.trim();
        window.clearTimeout(kioskCommandTimer);
        kioskCommandTimer = 0;
        kioskCommandText = '';
        pauseKioskVoiceListening();
        if (!content || state.busy) {
            setKioskVoiceStatus(kioskConversationActive ? 'listening' : 'idle', kioskConversationActive ? 'listening' : '');
            restartKioskVoiceListeningSoon(900);
            return;
        }

        const quickReplyGeneration = ++kioskQuickReplyGeneration;
        const turnStartedAt = Date.now();
        const spokenSegments = [];
        const voiceTurn = { lastSpeech: Promise.resolve(false) };
        const likelyNeedsAgentWork = voiceCommandNeedsAgentWork(content);
        const wantsDetailedChat = voiceCommandWantsDetailedChat(content);
        setKioskVoiceStatus('working', 'thinking');
        const quickReplyTask = fetchKioskQuickReply(content, quickReplyGeneration);
        const quickReply = likelyNeedsAgentWork
            ? await timeoutPromise(quickReplyTask, 900, null)
            : await quickReplyTask;
        const quickReplyText = quickReply?.text || fallbackKioskQuickReply(content, likelyNeedsAgentWork);
        let shouldContinueAgent = quickReply ? quickReply.continueAgent !== false : likelyNeedsAgentWork || wantsDetailedChat;
        if (quickReplyText && wantsDetailedChat) {
            shouldContinueAgent = true;
        } else if (quickReplyText && !likelyNeedsAgentWork) {
            shouldContinueAgent = false;
        }
        const allowLateQuickReply = !quickReplyText && likelyNeedsAgentWork;
        if (!quickReplyText && !likelyNeedsAgentWork) {
            shouldContinueAgent = true;
        }
        let finalResponseReady = false;
        let quickReplySpeech = quickReplyText
            ? speakKioskVoiceSegment(quickReplyText, quickReplyGeneration, spokenSegments)
            : Promise.resolve(false);
        trackKioskSpeechThenWorking(quickReplySpeech, quickReplyGeneration, () => finalResponseReady);
        voiceTurn.lastSpeech = quickReplySpeech;
        if (allowLateQuickReply) {
            quickReplyTask.then((lateQuickReply) => {
                const lateQuickReplyText = lateQuickReply?.text || '';
                if (!lateQuickReplyText || finalResponseReady) return false;
                quickReplySpeech = speakKioskVoiceSegment(lateQuickReplyText, quickReplyGeneration, spokenSegments);
                trackKioskSpeechThenWorking(quickReplySpeech, quickReplyGeneration, () => finalResponseReady);
                voiceTurn.lastSpeech = quickReplySpeech;
                return quickReplySpeech;
            });
        }

        if (!shouldContinueAgent) {
            startKioskBargeInListening();
            appendKioskLocalVoiceExchange(content, quickReplyText);
            try {
                await quickReplySpeech;
            } finally {
                stopKioskBargeInListening();
            }
            if (!kioskConversationActive) {
                restartKioskVoiceListeningSoon(650);
                return;
            }
            setKioskVoiceStatus('listening', 'listening');
            armKioskConversationTimeout();
            restartKioskVoiceListeningSoon(1200);
            return;
        }

        startKioskBargeInListening();
        const bridgeReply = runKioskBridgeReplies(
            content,
            quickReplyGeneration,
            spokenSegments,
            turnStartedAt,
            () => finalResponseReady,
            voiceTurn,
        );
        try {
            const response = await sendChatContent(content, {
                voiceQuickReply: quickReplyText,
                voiceQuickReplyPending: !quickReplyText,
                voiceDetailedChat: wantsDetailedChat,
                onAgentResult: () => {
                    finalResponseReady = true;
                    bridgeReply.cancel();
                },
            });
            finalResponseReady = true;
            bridgeReply.cancel();
            await quickReplySpeech;
            await voiceTurn.lastSpeech;
            await bridgeReply.promise;
            kioskQuickReplyGeneration += 1;
            if (!kioskConversationActive) return;
            const assistantContent = response?.assistantContent || '';
            if (!assistantContent) {
                setKioskVoiceStatus('error', 'no response');
                await sleep(1200);
            } else {
                const finalVoice = finalVoiceForTurn(content, quickReplyText, assistantContent, {
                    wantsDetailedChat,
                });
                const spoken = finalVoice.text
                    ? await speakKioskResponse(finalVoice.text, finalVoice.handoff ? {} : { pendingMessage: 'working in background' })
                    : true;
                if (!spoken) {
                    if (profileTtsProvider() === 'openai' && kioskLastTtsError) {
                        await sleep(4500);
                    } else {
                        setKioskVoiceStatus('responding', 'responded');
                        await sleep(900);
                    }
                }
            }
        } catch (error) {
            finalResponseReady = true;
            bridgeReply.cancel();
            setKioskVoiceStatus('error', friendlyError(error, 'send that message'));
            await sleep(1800);
        } finally {
            stopKioskBargeInListening();
        }
        if (!kioskConversationActive) {
            restartKioskVoiceListeningSoon(650);
            return;
        }
        setKioskVoiceStatus('listening', 'listening');
        armKioskConversationTimeout();
        restartKioskVoiceListeningSoon(1200);
    }

    function speakKioskVoiceSegment(text, generation, spokenSegments) {
        const cleanText = String(text || '').trim();
        if (!cleanText) return Promise.resolve(false);
        spokenSegments.push(cleanText);
        return speakKioskAcknowledgement(text, {
            shouldPlay: () => kioskConversationActive && generation === kioskQuickReplyGeneration,
        });
    }

    function appendKioskLocalVoiceExchange(userContent, assistantContent) {
        if (userContent) {
            state.messages.push({
                id: `voice-user-${Date.now()}`,
                role: 'user',
                content: userContent,
                metadata: { local_voice_turn: true },
            });
        }
        if (assistantContent) {
            state.messages.push({
                id: `voice-assistant-${Date.now()}`,
                role: 'assistant',
                content: assistantContent,
                metadata: { local_voice_turn: true, quick_reply_only: true },
            });
        }
        if (state.phase === 'signedIn') render();
        scrollChatToBottom();
    }

    function runKioskBridgeReplies(content, generation, spokenSegments, startedAt, finalReady, voiceTurn) {
        window.clearTimeout(kioskBridgeTimer);
        let resolveBridge = () => {};
        let settled = false;
        let cancelled = false;
        const resolveOnce = (value) => {
            if (settled) return;
            settled = true;
            resolveBridge(value);
        };
        const promise = new Promise((resolve) => {
            resolveBridge = resolve;
            (async () => {
                let spokeBridge = false;
                for (let bridgeCount = 0; bridgeCount < 1; bridgeCount += 1) {
                    await sleep(7000);
                    if (cancelled) break;
                    await voiceTurn.lastSpeech;
                    if (cancelled || finalReady() || !kioskConversationActive || generation !== kioskQuickReplyGeneration) break;
                    if (spokenSegments.length === 0) continue;
                    const bridge = await fetchKioskQuickReply(content, generation, {
                        stage: 'bridge',
                        spokenSegments,
                        elapsedMs: Date.now() - startedAt,
                    });
                    const bridgeText = bridge?.text || '';
                    if (!bridgeText || cancelled || finalReady() || !kioskConversationActive || generation !== kioskQuickReplyGeneration) break;
                    const speech = speakKioskVoiceSegment(bridgeText, generation, spokenSegments);
                    trackKioskSpeechThenWorking(speech, generation, finalReady);
                    voiceTurn.lastSpeech = speech;
                    spokeBridge = await speech || spokeBridge;
                }
                resolveOnce(spokeBridge);
            })();
        });
        return {
            promise,
            cancel: () => {
                cancelled = true;
                window.clearTimeout(kioskBridgeTimer);
                kioskBridgeTimer = 0;
                resolveOnce(false);
            },
        };
    }

    function clientContextPayload() {
        const now = new Date();
        const offsetMinutes = -now.getTimezoneOffset();
        const sign = offsetMinutes >= 0 ? '+' : '-';
        const absolute = Math.abs(offsetMinutes);
        const hours = String(Math.floor(absolute / 60)).padStart(2, '0');
        const minutes = String(absolute % 60).padStart(2, '0');
        const offset = `${sign}${hours}:${minutes}`;

        return {
            current_local_time: localIsoWithOffset(now, offset),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
            timezone_offset: offset,
            timezone_offset_minutes: offsetMinutes,
        };
    }

    function localIsoWithOffset(date, offset) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hour = String(date.getHours()).padStart(2, '0');
        const minute = String(date.getMinutes()).padStart(2, '0');
        const second = String(date.getSeconds()).padStart(2, '0');

        return `${year}-${month}-${day}T${hour}:${minute}:${second}${offset}`;
    }

    function setKioskVoiceStatus(phase, message) {
        state.kioskVoicePhase = phase;
        state.kioskVoiceMessage = message;
        if (state.phase === 'signedIn') render();
    }

    function openKioskChat() {
        if (state.selected === 'bean') return;
        state.chatExpanded = true;
        render();
        scrollChatToBottom();
    }

    function scheduleKioskChatAutoClose(delay) {
        window.clearTimeout(kioskAutoCloseTimer);
        kioskAutoCloseTimer = window.setTimeout(() => {
            kioskAutoCloseTimer = 0;
            if (state.selected !== 'bean' && state.chatExpanded && !state.busy) {
                state.chatExpanded = false;
                render();
            }
        }, delay);
    }

    async function fetchKioskQuickReply(content, generation, options = {}) {
        try {
            const response = await api('/assistant/voice/quick-reply', {
                method: 'POST',
                body: {
                    content,
                    workspace_id: currentWorkspaceId() || null,
                    client_context: clientContextPayload(),
                    stage: options.stage || 'first',
                    spoken_segments: options.spokenSegments || [],
                    elapsed_ms: options.elapsedMs || 0,
                },
            });
            const text = String(response?.text || '').trim();
            if (!text || !kioskConversationActive || generation !== kioskQuickReplyGeneration) return null;
            return {
                text,
                continueAgent: response?.continue_agent !== false && response?.continueAgent !== false,
            };
        } catch (_) {
            return null;
        }
    }

    function fallbackKioskQuickReply(content, likelyNeedsAgentWork) {
        if (!likelyNeedsAgentWork) return '';
        const command = normalizedVoiceCommand(content);
        if (/\b(?:weather|forecast)\b/.test(command)) {
            const location = fallbackLocationHint(command);
            return location ? `Sure, I'll check ${location}'s weather now.` : "Sure, I'll check the weather now.";
        }
        if (/\b(?:flight|flights|airfare|ticket|tickets)\b/.test(command)) return "Absolutely, I'll check the latest flight info now.";
        if (/\b(?:hotel|hotels|reservation|booking|bookings)\b/.test(command)) return "Sure, I'll check the current availability now.";
        if (/\b(?:traffic|delay|delays)\b/.test(command)) return "Yeah, I'll check traffic now.";
        if (/\b(?:news|stock|stocks|sports|score|scores)\b/.test(command)) return "Sure, I'll check the latest now.";
        if (/\b(?:calendar|calendars|event|events|agenda|google calendar)\b/.test(command)) return "Absolutely, I'll check your calendar now.";
        if (/\b(?:task|tasks|todo|to do)\b/.test(command)) return "Sure, I'll check your tasks now.";
        if (/\b(?:reminder|reminders)\b/.test(command)) return "Absolutely, I'll check your reminders now.";
        return "Sure, I'll check now.";
    }

    function fallbackLocationHint(command) {
        const match = command.match(/\b(?:in|for|near)\s+([a-z][a-z\s]+?)(?:\s+(?:right now|now|today|tonight|tomorrow)|$)/);
        if (!match) return '';
        return match[1]
            .replace(/\b(?:florida|fl|usa|united states)\b/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    function timeoutPromise(promise, timeoutMs, fallback) {
        let timeoutId = 0;
        const timeout = new Promise((resolve) => {
            timeoutId = window.setTimeout(() => resolve(fallback), timeoutMs);
        });
        return Promise.race([promise, timeout]).finally(() => window.clearTimeout(timeoutId));
    }

    function speakKioskAcknowledgement(text, options = {}) {
        if (!text) return Promise.resolve(false);
        if (profileTtsProvider() === 'openai') {
            return playOpenAiTts(text, { status: 'responding', shouldPlay: options.shouldPlay, quietFailure: true }).then((spoken) => {
                if (spoken) return true;
                reportKioskRealtimeIssue('openai_tts_emergency_fallback_failure', {
                    message: kioskLastTtsError || "Bean's voice unavailable",
                });
                if (allowDebugBrowserVoiceFallback()) return speakBrowserTts(text);
                setKioskVoiceStatus('error', "Bean voice couldn't connect");
                return false;
            });
        }
        return allowDebugBrowserVoiceFallback() ? speakBrowserTts(text) : Promise.resolve(false);
    }

    function trackKioskSpeechThenWorking(speech, generation, finalReady) {
        Promise.resolve(speech).then(() => {
            if (!state.busy || finalReady?.()) return;
            if (!kioskConversationActive || generation !== kioskQuickReplyGeneration) return;
            setKioskVoiceStatus('working', 'working');
        }).catch(() => {});
    }

    function stopKioskSpeechPlayback() {
        window.speechSynthesis?.cancel();
        if (kioskActiveAudioSource) {
            try { kioskActiveAudioSource.stop(0); } catch (_) {}
            kioskActiveAudioSource = null;
        }
        if (kioskActiveAudioElement) {
            try {
                kioskActiveAudioElement.pause();
                kioskActiveAudioElement.currentTime = 0;
            } catch (_) {}
            kioskActiveAudioElement = null;
        }
    }

    function speakKioskResponse(content, options = {}) {
        const text = speechTextFromAssistant(content);
        return speakKioskResponseText(text, options);
    }

    function speakKioskResponseText(text, options = {}) {
        if (profileTtsProvider() === 'openai') {
            return playOpenAiTts(text, { ...options, quietFailure: true }).then((spoken) => {
                if (spoken) return true;
                reportKioskRealtimeIssue('openai_tts_emergency_fallback_failure', {
                    message: kioskLastTtsError || "Bean's voice unavailable",
                });
                if (allowDebugBrowserVoiceFallback()) return speakBrowserTts(text);
                setKioskVoiceStatus('error', "Bean voice couldn't connect");
                return false;
            });
        }
        return allowDebugBrowserVoiceFallback() ? speakBrowserTts(text) : Promise.resolve(false);
    }

    function finalVoiceForTurn(userContent, quickReplyText, assistantContent, options = {}) {
        const text = speechTextFromAssistant(assistantContent);
        if (!text) return { text: '', handoff: false };
        if (!quickReplyText) return { text, handoff: false };
        if (options.wantsDetailedChat || finalResponseIsDetailed(assistantContent, text)) {
            return { text: finalDetailNotice(userContent), handoff: true };
        }
        if (quickReplyCoversFinal(quickReplyText, text)) {
            return { text: '', handoff: false };
        }
        return { text, handoff: false };
    }

    function finalResponseIsDetailed(rawContent, spokenText) {
        const raw = String(rawContent || '');
        return spokenText.length > 520
            || raw.length > 700
            || /(?:^|\n)\s*(?:[-*]|\d+[.)])\s+\S/.test(raw)
            || (raw.match(/\n/g) || []).length >= 3;
    }

    function finalDetailNotice(userContent) {
        const command = String(userContent || '').toLowerCase();
        if (/\b(?:workout|exercise|routine|training|stretch|stretches)\b/.test(command)) {
            return "I've written the full workout plan in chat.";
        }
        if (/\b(?:recipe|cook|meal)\b/.test(command)) {
            return "I've written the full recipe in chat.";
        }
        if (/\b(?:plan|guide|steps|instructions)\b/.test(command)) {
            return "I've written the full plan in chat.";
        }
        return "I've written the full details in chat.";
    }

    function quickReplyCoversFinal(quickReplyText, finalText) {
        const quick = normalizeComparableSpeech(quickReplyText);
        const final = normalizeComparableSpeech(finalText);
        return quick.length >= 40 && (final.startsWith(quick.slice(0, 80)) || quickSimilarity(quick, final) > 0.72);
    }

    function normalizeComparableSpeech(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9\s]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function quickSimilarity(a, b) {
        const aWords = new Set(a.split(' ').filter((word) => word.length > 3));
        const bWords = new Set(b.split(' ').filter((word) => word.length > 3));
        if (!aWords.size || !bWords.size) return 0;
        let overlap = 0;
        aWords.forEach((word) => {
            if (bWords.has(word)) overlap += 1;
        });
        return overlap / Math.min(aWords.size, bWords.size);
    }

    async function playOpenAiTts(text, options = {}) {
        if (!text) return false;
        let url = '';
        try {
            kioskLastTtsError = '';
            if (options.shouldPlay && !options.shouldPlay()) return false;
            stopKioskSpeechPlayback();
            if (!await ensureOpenAiAudioUnlocked()) {
                throw new Error('audio_not_unlocked');
            }
            if (options.shouldPlay && !options.shouldPlay()) return false;
            if (options.pendingMessage) {
                setKioskVoiceStatus('working', options.pendingMessage);
            } else {
                setKioskVoiceStatus(options.status || 'responding', "Bean's voice");
            }
            const response = await fetch('/api/assistant/tts', {
                method: 'POST',
                headers: {
                    Accept: 'audio/wav',
                    'Content-Type': 'application/json',
                    ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
                },
                body: JSON.stringify({ text, voice: profileTtsVoice(), workspace_id: currentWorkspaceId() || null }),
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                rememberOpenAiTtsError(payload?.message || "Bean's voice unavailable", !options.quietFailure);
                return false;
            }
            const audioBuffer = await response.arrayBuffer();
            if (!audioBuffer.byteLength) {
                rememberOpenAiTtsError("Bean's voice returned empty audio", !options.quietFailure);
                return false;
            }
            if (options.shouldPlay && !options.shouldPlay()) return false;
            const onStart = () => setKioskVoiceStatus(options.status || 'responding', "Bean's voice");
            if (await playOpenAiAudioBuffer(audioBuffer, { onStart })) return true;
            if (options.shouldPlay && !options.shouldPlay()) return false;
            return playAudioBlobFallback(audioBuffer, response.headers.get('Content-Type') || 'audio/wav', { onStart });
        } catch (error) {
            const message = error?.message === 'audio_not_unlocked' || error?.name === 'NotAllowedError'
                ? "Bean's voice needs one click"
                : `Bean voice playback failed${error?.name ? `: ${error.name}` : ''}`;
            rememberOpenAiTtsError(message, !options.quietFailure);
            return false;
        } finally {
            if (url) URL.revokeObjectURL(url);
        }
    }

    async function playAudioBlobFallback(arrayBuffer, contentType = 'audio/wav', options = {}) {
        if (!arrayBuffer?.byteLength) return false;
        let url = '';
        try {
            const blob = new Blob([arrayBuffer], { type: contentType });
            url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            kioskActiveAudioElement = audio;
            audio.playsInline = true;
            await new Promise((resolve, reject) => {
                audio.onended = resolve;
                audio.onerror = reject;
                audio.play().then(() => {
                    options.onStart?.();
                }).catch(reject);
            });
            return true;
        } catch (error) {
            return false;
        } finally {
            if (kioskActiveAudioElement?.src === url) kioskActiveAudioElement = null;
            if (url) URL.revokeObjectURL(url);
        }
    }

    async function ensureOpenAiAudioUnlocked() {
        if (kioskAudioUnlocked && (!kioskAudioContext || kioskAudioContext.state === 'running')) return true;
        await unlockKioskAudio();
        return kioskAudioUnlocked && (!kioskAudioContext || kioskAudioContext.state === 'running');
    }

    function rememberOpenAiTtsError(message, showStatus = true) {
        kioskLastTtsError = beanVoiceStatusMessage(message);
        if (showStatus) setKioskVoiceStatus('error', kioskLastTtsError);
    }

    function beanVoiceStatusMessage(message) {
        return String(message || "Bean's voice unavailable")
            .replace(/OpenAI text-to-speech/gi, "Bean's voice")
            .replace(/OpenAI voice/gi, "Bean's voice")
            .replace(/OpenAI/gi, 'Bean voice')
            .replace(/API key/gi, 'voice key');
    }

    async function playOpenAiAudioBuffer(arrayBuffer, options = {}) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass || !arrayBuffer?.byteLength) return false;
        try {
            kioskAudioContext ??= new AudioContextClass();
            if (kioskAudioContext.state === 'suspended') {
                await kioskAudioContext.resume();
            }
            if (kioskAudioContext.state !== 'running') return false;
            const decoded = await kioskAudioContext.decodeAudioData(arrayBuffer.slice(0));
            await new Promise((resolve, reject) => {
                const source = kioskAudioContext.createBufferSource();
                kioskActiveAudioSource = source;
                source.buffer = decoded;
                source.connect(kioskAudioContext.destination);
                source.onended = () => {
                    if (kioskActiveAudioSource === source) kioskActiveAudioSource = null;
                    resolve();
                };
                try {
                    source.start(0);
                    options.onStart?.();
                } catch (error) {
                    if (kioskActiveAudioSource === source) kioskActiveAudioSource = null;
                    reject(error);
                }
            });
            return true;
        } catch (error) {
            return false;
        }
    }

    async function unlockKioskAudio() {
        if (kioskAudioUnlocked) return true;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        let webAudioUnlocked = false;
        try {
            if (AudioContextClass) {
                kioskAudioContext ??= new AudioContextClass();
                if (kioskAudioContext.state === 'suspended') {
                    await kioskAudioContext.resume();
                }
                if (kioskAudioContext.state === 'running') {
                    const source = kioskAudioContext.createBufferSource();
                    const gain = kioskAudioContext.createGain();
                    source.buffer = kioskAudioContext.createBuffer(1, 1, kioskAudioContext.sampleRate);
                    gain.gain.value = 0;
                    source.connect(gain);
                    gain.connect(kioskAudioContext.destination);
                    source.start(0);
                    webAudioUnlocked = true;
                }
            }
            const audio = new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YQAAAAA=');
            audio.muted = true;
            audio.playsInline = true;
            await audio.play().catch((error) => {
                if (!webAudioUnlocked) throw error;
            });
            kioskAudioUnlocked = true;
            return true;
        } catch (error) {
            kioskAudioUnlocked = false;
            return false;
        }
    }

    function speakBrowserTts(text) {
        if (!allowDebugBrowserVoiceFallback()) return Promise.resolve(false);
        if (!text || !window.speechSynthesis || !window.SpeechSynthesisUtterance) {
            return Promise.resolve(false);
        }
        return new Promise((resolve) => {
            stopKioskSpeechPlayback();
            setKioskVoiceStatus('responding', 'responding');
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.rate = 1;
            utterance.pitch = 1;
            utterance.volume = 1;
            utterance.onend = () => resolve(true);
            utterance.onerror = () => resolve(false);
            window.speechSynthesis.speak(utterance);
        });
    }

    function speechTextFromAssistant(content) {
        return String(content || '')
            .replace(/```[\s\S]*?```/g, ' ')
            .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
            .replace(/[#*_>`]/g, '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 1200);
    }

    function kioskVoiceErrorMessage(error) {
        if (error === 'not-allowed' || error === 'service-not-allowed') return 'allow microphone access';
        if (error === 'audio-capture') return 'microphone unavailable';
        return 'voice paused';
    }

    async function toggleKioskVoiceMode() {
        if (state.kioskVoiceEnabled && !kioskRealtimeConnected()) {
            clearKioskRealtimeReconnect();
            kioskRealtimeReconnectAttempts = 0;
            setKioskVoiceStatus('working', 'Connecting Bean voice');
            render();
            await unlockKioskAudio();
            startKioskVoiceMode({ requestPermission: true });
            return;
        }
        state.kioskVoiceEnabled = !state.kioskVoiceEnabled;
        if (state.kioskVoiceEnabled) {
            kioskRealtimeUnavailable = false;
            localStorage.setItem(kioskVoiceKey, 'true');
            kioskConversationActive = false;
            state.kioskVoicePhase = 'working';
            state.kioskVoiceMessage = 'Connecting Bean voice';
            render();
            await unlockKioskAudio();
            startKioskVoiceMode({ requestPermission: true });
            return;
        }
        localStorage.removeItem(kioskVoiceKey);
        stopKioskVoiceMode();
        render();
    }

    function kioskMicrophoneAccessMessage(error) {
        if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') return 'mic blocked';
        if (error?.name === 'NotFoundError' || error?.name === 'DevicesNotFoundError') return 'no microphone';
        if (error?.name === 'NotReadableError' || error?.name === 'TrackStartError') return 'mic busy';
        return 'allow mic';
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
        if (state.busy) return;
        try {
            const onboarding = needsBeanOnboarding();
            state.onboardingJustCompleted = false;
            state.chatHistoryOpen = false;
            state.session = await api('/assistant/sessions', {
                method: 'POST',
                body: chatSessionPayload(onboarding),
            });
            state.messages = [];
            state.chatRunState = 'Ready';
            await loadChatSessions({ resumeToday: false, shouldRender: false });
            render();
        } catch (error) {
            state.error = friendlyError(error, 'start a new chat');
            render();
        }
    }

    function chatSessionPayload(onboarding = needsBeanOnboarding()) {
        const today = dateOnly(new Date());
        return {
            title: onboarding ? 'Welcome to Bean' : `${monthDayLabel(today)} with Bean`,
            runtime_mode: onboarding ? 'onboarding' : 'chat',
            workspace_id: currentWorkspaceId() || null,
            metadata: { daily_date: today, source: 'web_chat' },
        };
    }

    async function loadChatSessions(options = {}) {
        if (!state.token || state.phase !== 'signedIn') return;
        const params = new URLSearchParams({
            date: dateOnly(new Date()),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
            limit: '30',
        });
        const workspaceId = currentWorkspaceId();
        if (workspaceId) params.set('workspace_id', workspaceId);

        const result = await api(`/assistant/sessions?${params.toString()}`);
        state.chatSessions = normalizeList(result.sessions);

        if (options.resumeToday && !state.busy) {
            const todaySession = result.today_session || result.todaySession || null;
            if (todaySession?.id) {
                await resumeSession(todaySession.id, { keepHistoryOpen: true });
            }
        }

        if (options.shouldRender !== false) render();
    }

    async function resumeSession(id, options = {}) {
        if (state.busy) return;
        try {
            const session = await api(`/assistant/sessions/${id}`);
            state.session = session.session || session;
            state.messages = normalizeList(session.messages);
            state.chatRunState = 'Ready';
            state.activity = normalizeList(session.activity_events || session.events).length ? normalizeList(session.activity_events || session.events) : state.activity;
            if (!options.keepHistoryOpen) state.chatHistoryOpen = false;
            render();
            scrollChatToBottom();
        } catch (_) {
            // A missing old session should not block the rest of the app.
        }
    }

    async function refreshOnly(shouldRender = true, options = {}) {
        const generation = ++dashboardRefreshGeneration;
        try {
            const calendarPath = options.skipCalendarSync === false ? '/calendar-events' : '/calendar-events?skip_google_sync=1';
            const [summary, tasks, pastTasks, reminders, calendar, categories, googleStatus] = await Promise.all([
                api('/today'),
                api('/tasks'),
                api('/tasks/past'),
                api('/reminders'),
                api(calendarPath),
                api('/event-categories'),
                api('/google-calendar/status?cached=1').catch(() => state.googleStatus),
            ]);
            if (generation !== dashboardRefreshGeneration) return;
            state.summary = summary;
            state.tasks = reconcileTaskRefresh(mergeById(normalizeList(tasks.length ? tasks : summary?.tasks), normalizeList(pastTasks)));
            state.reminders = reconcileReminderRefresh(reminders.length ? reminders : summary?.reminders);
            state.calendar = reconcileCalendarRefresh(calendar.length ? calendar : summary?.calendar_events);
            state.categories = normalizeList(categories);
            state.approvals = normalizeList(summary?.approvals);
            state.blockers = normalizeList(summary?.blockers);
            state.activity = normalizeList(summary?.activity_events);
            state.googleStatus = googleStatus;
            state.user = mergeUser(state.user, summary?.user, summary);
            saveDashboardCache();
            if (shouldRender) renderDashboardDataUpdate({ deferIfEditing: options.deferRender === true });
        } catch (error) {
            state.error = friendlyError(error, 'refresh the app');
            if (shouldRender) renderDashboardDataUpdate({ deferIfEditing: options.deferRender === true });
        }
    }

    function refreshOnlyInBackground(options = {}) {
        refreshOnly(true, { ...options, deferRender: true });
    }

    function startDashboardChangeFeed() {
        if (!state.token || state.phase !== 'signedIn' || dashboardChangeLoopActive) return;
        dashboardChangeLoopActive = true;
        pollDashboardChanges();
    }

    function stopDashboardChangeFeed() {
        dashboardChangeLoopActive = false;
        window.clearTimeout(dashboardRefreshTimer);
        dashboardRefreshTimer = 0;
        if (dashboardChangeAbort) {
            dashboardChangeAbort.abort();
            dashboardChangeAbort = null;
        }
    }

    async function pollDashboardChanges() {
        if (!dashboardChangeLoopActive || !state.token) return;
        dashboardChangeAbort = new AbortController();
        try {
            const response = await fetch(`/api/dashboard-changes?after=${encodeURIComponent(state.dashboardChangeLastId)}&wait=25&limit=100`, {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${state.token}`,
                },
                signal: dashboardChangeAbort.signal,
            });
            if (response.status === 401) {
                stopDashboardChangeFeed();
                return;
            }
            if (!response.ok) throw new Error('Dashboard change feed failed.');
            const payload = await response.json();
            const data = payload.data || payload;
            const changes = normalizeList(data.changes);
            const latestId = Number(data.latest_id || data.latestId || 0);
            const previousLastId = state.dashboardChangeLastId;
            if (latestId !== state.dashboardChangeLastId) {
                state.dashboardChangeLastId = latestId;
                localStorage.setItem(dashboardChangeStorageKey(), String(latestId));
            }
            if (changes.length || (latestId > 0 && latestId < previousLastId)) {
                scheduleDashboardRealtimeRefresh(changes);
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                await sleep(2500);
            }
        } finally {
            dashboardChangeAbort = null;
            if (dashboardChangeLoopActive) {
                window.setTimeout(pollDashboardChanges, 120);
            }
        }
    }

    function scheduleDashboardRealtimeRefresh(changes = []) {
        window.clearTimeout(dashboardRefreshTimer);
        dashboardRefreshTimer = window.setTimeout(() => {
            dashboardRefreshTimer = 0;
            if (state.phase !== 'signedIn') return;
            refreshOnlyInBackground({ skipCalendarSync: true });
            if (state.selected === 'admin') {
                loadAdminUsage(true);
            }
        }, changes.length ? 350 : 100);
    }

    function sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function dashboardChangeStorageKey() {
        return `${dashboardChangeKey}.${state.user?.id || 'anon'}`;
    }

    function refreshCalendarInBackground() {
        const generation = ++dashboardRefreshGeneration;
        api('/calendar-events')
            .then((calendar) => {
                if (generation !== dashboardRefreshGeneration) return;
                state.calendar = reconcileCalendarRefresh(calendar);
                saveDashboardCache();
                renderDashboardDataUpdate({ deferIfEditing: true });
            })
            .catch(() => {
                // Manual refresh surfaces Google sync failures; background import should not interrupt local edits.
            });
    }

    async function loadAdminUsage(force = false) {
        if (!userIsAdmin() || (state.adminUsage && !force)) return;
        state.adminUsageLoading = true;
        state.error = '';
        render();
        try {
            state.adminUsage = await api('/admin/usage/summary');
        } catch (error) {
            state.error = friendlyError(error, 'load admin metrics');
        } finally {
            state.adminUsageLoading = false;
            render();
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
            state.calendar = reconcileCalendarRefresh(calendar);
            state.googleStatus = googleStatus;
            state.notice = 'Calendar refreshed.';
        } catch (error) {
            state.error = friendlyError(error, 'refresh the calendar');
        } finally {
            state.calendarRefreshing = false;
            render();
        }
    }

    async function refreshCurrentView() {
        if (state.selected === 'admin') {
            await loadAdminUsage(true);
            return;
        }
        if (state.selected === 'today') {
            await refreshCalendar();
            return;
        }
        if (state.calendarRefreshing) return;
        state.calendarRefreshing = true;
        state.error = '';
        render();
        try {
            await refreshOnly(false, { skipCalendarSync: true });
            if (!state.error) {
                state.notice = 'Refreshed.';
            }
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
        const previous = snapshotDashboardState();
        dashboardRefreshGeneration++;
        setActiveWorkspaceLocally(id);
        state.pendingTaskUpserts.clear();
        state.pendingTaskDeletes.clear();
        state.pendingReminderUpserts.clear();
        state.pendingReminderDeletes.clear();
        state.pendingCalendarUpserts.clear();
        state.pendingCalendarDeletes.clear();
        if (!applyDashboardCache(id)) {
            clearDashboardDataForWorkspace(id);
        }
        state.notice = `Switched to ${workspaceDisplayName(workspace)}.`;
        state.error = '';
        render();
        try {
            await api('/workspaces/default', { method: 'PATCH', body: { workspace_id: Number(id) } });
            refreshOnly(false, { skipCalendarSync: true }).then(() => {
                state.notice = `Switched to ${workspaceDisplayName(workspace)}.`;
                renderDashboardDataUpdate({ deferIfEditing: true });
            });
        } catch (error) {
            restoreDashboardState(previous);
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
        stopDashboardChangeFeed();
        stopKioskVoiceMode();
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
            stopDashboardChangeFeed();
            stopKioskVoiceMode();
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
        const overdueOrder = compareOverdueItems(a, b, 'task');
        if (overdueOrder !== 0) return overdueOrder;
        const aDue = parseLocalDate(a?.due_at || a?.dueAt || '');
        const bDue = parseLocalDate(b?.due_at || b?.dueAt || '');
        const aHasDue = Boolean(a?.due_at || a?.dueAt);
        const bHasDue = Boolean(b?.due_at || b?.dueAt);
        if (aHasDue && bHasDue && aDue.getTime() !== bDue.getTime()) return aDue - bDue;
        if (aHasDue !== bHasDue) return aHasDue ? -1 : 1;
        return Number(a?.id || 0) - Number(b?.id || 0);
    }

    function compareReminders(a, b) {
        const overdueOrder = compareOverdueItems(a, b, 'reminder');
        if (overdueOrder !== 0) return overdueOrder;
        const aDateValue = reminderDateValue(a);
        const bDateValue = reminderDateValue(b);
        const aDate = parseLocalDate(aDateValue || '');
        const bDate = parseLocalDate(bDateValue || '');
        const aHasDate = Boolean(aDateValue);
        const bHasDate = Boolean(bDateValue);
        if (aHasDate && bHasDate && aDate.getTime() !== bDate.getTime()) return aDate - bDate;
        if (aHasDate !== bHasDate) return aHasDate ? -1 : 1;
        return Number(a?.id || 0) - Number(b?.id || 0);
    }

    function itemSortFunction(kind) {
        return kind === 'task' ? compareTasks : compareReminders;
    }

    function itemBoardDays() {
        return [new Date(), addDays(new Date(), 1), addDays(new Date(), 2)].map(dateOnly);
    }

    function itemsForItemDay(items, kind, day) {
        return items
            .filter((item) => itemBoardDateOnly(item, kind) === day)
            .sort(itemSortFunction(kind));
    }

    function itemBoardDateOnly(item, kind) {
        if (itemOverdue(item, kind)) return dateOnly(new Date());
        return itemDateOnly(item, kind);
    }

    function itemDateOnly(item, kind) {
        const value = itemDateValue(item, kind);
        if (!value) return '';
        const parsed = parseLocalDate(value);
        return Number.isNaN(parsed.getTime()) ? '' : dateOnly(parsed);
    }

    function itemDateValue(item, kind) {
        return kind === 'task' ? (item?.due_at || item?.dueAt || '') : reminderDateValue(item);
    }

    function taskCritical(task) {
        return Boolean(task?.is_critical || task?.isCritical || itemOverdue(task, 'task'));
    }

    function reminderCritical(reminder) {
        return Boolean(reminder?.is_critical || reminder?.isCritical || itemOverdue(reminder, 'reminder'));
    }

    function itemOverdue(item, kind) {
        const completed = kind === 'task' ? taskCompleted(item) : reminderCompleted(item);
        if (completed) return false;
        const value = itemDateValue(item, kind);
        if (!value) return false;
        const parsed = parseLocalDate(value);
        return !Number.isNaN(parsed.getTime()) && parsed < new Date();
    }

    function compareOverdueItems(a, b, kind) {
        const aOverdue = itemOverdue(a, kind);
        const bOverdue = itemOverdue(b, kind);
        if (aOverdue !== bOverdue) return aOverdue ? -1 : 1;
        return 0;
    }

    function reminderDateValue(reminder) {
        return reminder?.remind_at || reminder?.remindAt || reminder?.due_at || reminder?.dueAt || '';
    }

    function itemCountLabel(count, kind) {
        const noun = kind === 'task' ? 'task' : 'reminder';
        return `${count} ${noun}${count === 1 ? '' : 's'}`;
    }

    function pendingReminders() {
        return state.reminders.filter((reminder) => !reminderCompleted(reminder));
    }

    function criticalItems() {
        return [...criticalTasksForToday(), ...criticalRemindersForToday(), ...criticalEventsForToday()];
    }

    function criticalTasksForToday() {
        const today = new Date();
        return activeTopLevelTasks()
            .filter((task) => taskCritical(task) && (itemOverdue(task, 'task') || isSameDay(task.due_at || task.dueAt, today)))
            .sort(compareTasks);
    }

    function criticalRemindersForToday() {
        const today = new Date();
        return pendingReminders()
            .filter((reminder) => reminderCritical(reminder) && (itemOverdue(reminder, 'reminder') || isSameDay(reminderDateValue(reminder), today)))
            .sort(compareReminders);
    }

    function criticalEventsForToday() {
        const today = new Date();
        return state.calendar
            .filter((event) => (event.is_critical || event.isCritical) && eventIntersectsDay(event, today))
            .sort((a, b) => new Date(a.starts_at || a.startsAt || 0) - new Date(b.starts_at || b.startsAt || 0));
    }

    function criticalTaskSubtitle(task) {
        const parts = [];
        const dueLabel = task.due_at || task.dueAt ? formatDateTime(task.due_at || task.dueAt) : '';
        if (task.category) parts.push(task.category);
        if (itemOverdue(task, 'task')) parts.push('overdue');
        if (dueLabel) parts.push(`Due ${dueLabel}`);
        if (taskIsRecurring(task)) parts.push(recurrenceSummary(task));
        return parts.join(' · ');
    }

    function criticalReminderSubtitle(reminder) {
        const parts = [];
        const dateLabel = reminderDateValue(reminder) ? formatDateTime(reminderDateValue(reminder)) : '';
        if (reminder.category) parts.push(reminder.category);
        if (itemOverdue(reminder, 'reminder')) parts.push('overdue');
        if (dateLabel) parts.push(dateLabel);
        if (itemIsRecurring(reminder)) parts.push(recurrenceSummary(reminder));
        return parts.join(' · ') || 'No reminder time';
    }

    function criticalEventSubtitle(event) {
        const parts = [];
        if (event.starts_at || event.startsAt || event.ends_at || event.endsAt) parts.push(eventTime(event));
        if (event.category) parts.push(event.category);
        const recurrence = itemRecurrenceValue(event);
        if (recurrence && recurrence !== 'none') parts.push(recurrenceSummary(event));
        return parts.join(' · ') || 'Unscheduled';
    }

    function taskIsRecurring(task) {
        return itemIsRecurring(task);
    }

    function itemIsRecurring(item) {
        const recurrence = itemRecurrenceValue(item);
        return recurrence && recurrence !== 'none';
    }

    function taskCompletionCount(task) {
        const count = Number.parseInt(task?.metadata?.completion_count || 0, 10);
        return Number.isFinite(count) ? count : 0;
    }

    function nextRecurringTaskDueAt(task) {
        if (!task) return null;
        const recurrence = itemRecurrenceValue(task);
        if (!recurrence || recurrence === 'none') return null;
        const metadata = recurrenceMetadata(task.metadata);
        let cursor = parseLocalDate(task.due_at || task.dueAt || new Date());
        const now = new Date();
        for (let guard = 0; guard < 500; guard += 1) {
            cursor = advanceRecurringDate(cursor, recurrence, metadata);
            if (!cursor) return null;
            if (cursor > now) return cursor;
        }
        return null;
    }

    function advanceRecurringDate(date, recurrence, metadata = {}) {
        if (recurrence === 'daily') return addDays(date, 1);
        if (recurrence === 'weekly') return addDays(date, 7);
        if (recurrence === 'monthly') return addMonthsNoOverflow(date, 1);
        if (recurrence === 'yearly') return addYearsNoOverflow(date, 1);
        if (recurrence === 'specific_days') return nextSpecificRecurringDay(date, metadata);
        if (recurrence === 'interval') {
            const interval = Math.max(1, Number.parseInt(metadata.interval || 1, 10));
            const unit = String(metadata.unit || metadata.interval_unit || metadata.intervalUnit || 'days').toLowerCase();
            if (unit === 'week' || unit === 'weeks') return addDays(date, interval * 7);
            if (unit === 'month' || unit === 'months') return addMonthsNoOverflow(date, interval);
            if (unit === 'year' || unit === 'years') return addYearsNoOverflow(date, interval);
            return addDays(date, interval);
        }
        return null;
    }

    function nextSpecificRecurringDay(date, metadata = {}) {
        const days = recurrenceDays(metadata);
        if (!days.size) return null;
        let cursor = addDays(date, 1);
        for (let guard = 0; guard < 14; guard += 1) {
            const key = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][cursor.getDay()];
            if (days.has(key)) return cursor;
            cursor = addDays(cursor, 1);
        }
        return null;
    }

    function recurrenceSummary(item) {
        const recurrence = itemRecurrenceValue(item);
        if (recurrence === 'interval') return intervalRecurrenceSummary(item?.metadata);
        return recurrence && recurrence !== 'none' ? recurrenceLabel(recurrence) : '';
    }

    function intervalRecurrenceSummary(metadata = {}) {
        const recurrence = recurrenceMetadata(metadata);
        const interval = Number.parseInt(recurrence?.interval, 10);
        if (!Number.isFinite(interval) || interval <= 0) return 'Custom interval';
        const unit = String(recurrence?.unit || recurrence?.interval_unit || recurrence?.intervalUnit || 'days').toLowerCase();
        return `Every ${interval} ${intervalUnitLabel(unit, interval)}`;
    }

    function intervalUnitLabel(unit, interval) {
        const normalized = {
            day: 'day',
            days: 'day',
            week: 'week',
            weeks: 'week',
            month: 'month',
            months: 'month',
            year: 'year',
            years: 'year',
        }[unit] || unit.replace(/s$/, '') || 'day';
        return interval === 1 ? normalized : `${normalized}s`;
    }

    function workspaces() {
        return normalizeList(state.user?.workspaces || state.summary?.workspaces);
    }

    function currentWorkspaceId() {
        return state.summary?.workspace?.id || state.summary?.workspaceId || state.user?.active_workspace?.id || state.user?.activeWorkspace?.id || workspaces().find((workspace) => workspace.active || workspace.is_default || workspace.isDefault)?.id || workspaces()[0]?.id || '';
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
        return [
            task.due_at || task.dueAt ? formatDateTime(task.due_at || task.dueAt) : '',
            recurrenceSummary(task),
        ].filter(Boolean).join(' · ');
    }

    function reminderSubtitle(reminder) {
        const bits = [];
        if (reminder.category) bits.push(reminder.category);
        if (reminder.remind_at || reminder.due_at || reminder.dueAt) bits.push(formatDateTime(reminder.remind_at || reminder.due_at || reminder.dueAt));
        if (itemIsRecurring(reminder)) bits.push(recurrenceSummary(reminder));
        return bits.join(' · ') || 'No reminder time';
    }

    function eventsForDay(day) {
        return state.calendar
            .filter((event) => eventIntersectsDay(event, day))
            .sort((a, b) => new Date(a.starts_at || a.startsAt || 0) - new Date(b.starts_at || b.startsAt || 0));
    }

    function allDayEventsForDay(day) {
        return eventsForDay(day).filter((event) => eventAllDay(event));
    }

    function multiDayTimedEventsForDay(day) {
        return eventsForDay(day).filter((event) => eventMultiDayTimed(event));
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

    function eventEndTime(event) {
        if (eventAllDay(event)) return 'All day';
        const end = event.ends_at || event.endsAt;
        return end ? formatTime(end) : '';
    }

    function eventMultiDayTimed(event) {
        if (eventAllDay(event)) return false;
        const startValue = event.starts_at || event.startsAt;
        const endValue = event.ends_at || event.endsAt;
        if (!startValue || !endValue) return false;
        const start = parseLocalDate(startValue);
        const end = parseLocalDate(endValue);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return false;
        return dateOnly(start) !== dateOnly(end);
    }

    function multiDayEventDayTime(event, day, options = {}) {
        const dayValue = dateOnly(day);
        if (dateOnly(event.starts_at || event.startsAt) === dayValue) return eventStartTime(event);
        if (dateOnly(event.ends_at || event.endsAt) === dayValue) {
            return options.showEndTime === false ? '' : eventEndTime(event);
        }
        return '';
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
        const hourHeight = 88;
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
        const end = endValue ? new Date(endValue) : addMinutes(start, 60);
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
        if (width >= 1280) return 5;
        if (width >= 820) return 4;
        return 2;
    }

    function timelineDayMinWidth() {
        const width = window.innerWidth || 0;
        const visibleDayCount = Math.max(1, state.calendarVisibleDayCount || calendarVisibleDayCount());
        const reservedWidth = width >= 900 ? 340 : 32;
        const estimatedTimelineWidth = Math.max(360, width - reservedWidth);
        return Math.max(150, Math.floor((estimatedTimelineWidth - 74) / visibleDayCount));
    }

    function defaultEventStart() {
        const selected = parseLocalDate(state.selectedDay || new Date());
        const now = new Date();
        const start = new Date(selected);
        if (sameDate(selected, now)) {
            start.setHours(Math.min(Math.max(now.getHours() + 1, 8), 21), 0, 0, 0);
        } else {
            start.setHours(9, 0, 0, 0);
        }
        return start;
    }

    function defaultEventEnd(start) {
        return addMinutes(start || defaultEventStart(), 60);
    }

    function addMinutes(value, amount) {
        const date = new Date(parseLocalDate(value));
        date.setMinutes(date.getMinutes() + amount);
        return date;
    }

    function addDays(date, amount) {
        const next = new Date(parseLocalDate(date));
        next.setDate(next.getDate() + amount);
        return next;
    }

    function addMonthsNoOverflow(date, amount) {
        const source = parseLocalDate(date);
        const next = new Date(source);
        const day = source.getDate();
        next.setDate(1);
        next.setMonth(next.getMonth() + amount);
        next.setDate(Math.min(day, daysInMonth(next.getFullYear(), next.getMonth())));
        return next;
    }

    function addYearsNoOverflow(date, amount) {
        const source = parseLocalDate(date);
        const next = new Date(source);
        const day = source.getDate();
        next.setDate(1);
        next.setFullYear(next.getFullYear() + amount);
        next.setDate(Math.min(day, daysInMonth(next.getFullYear(), next.getMonth())));
        return next;
    }

    function daysInMonth(year, monthIndex) {
        return new Date(year, monthIndex + 1, 0).getDate();
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
        const parsed = parseLocalDate(date);
        return `${weekdayShort(parsed)} ${ordinalDay(parsed.getDate())}`;
    }

    function ordinalDay(day) {
        const teen = day % 100;
        if (teen >= 11 && teen <= 13) return `${day}th`;
        return `${day}${({ 1: 'st', 2: 'nd', 3: 'rd' })[day % 10] || 'th'}`;
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

    function currentChatTitle() {
        return state.session ? chatSessionTitle(state.session) : 'Today with Bean';
    }

    function chatSessionTitle(session) {
        const title = String(session?.title || '').trim();
        if (title && title !== 'Workspace chat') return title;
        const created = session?.created_at || session?.createdAt || session?.last_activity_at || session?.lastActivityAt;
        if (!created) return 'Today with Bean';
        const date = new Date(created);
        if (sameDate(date, new Date())) return 'Today with Bean';
        return `${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} with Bean`;
    }

    function chatSessionMeta(session, messageCount = 0) {
        const value = session?.last_activity_at || session?.lastActivityAt || session?.updated_at || session?.updatedAt || session?.created_at || session?.createdAt;
        const date = value ? new Date(value) : null;
        const when = date
            ? sameDate(date, new Date())
                ? `Today ${date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`
                : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
            : 'No activity';
        const count = Number(messageCount || 0);
        return `${when} · ${count} ${count === 1 ? 'message' : 'messages'}`;
    }

    function formatCurrency(value) {
        const amount = Number(value || 0);
        return amount.toLocaleString(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: amount >= 1 ? 2 : 4, maximumFractionDigits: amount >= 1 ? 2 : 4 });
    }

    function formatTokens(value) {
        const tokens = Number(value || 0);
        if (tokens >= 1000000) return `${(tokens / 1000000).toFixed(tokens >= 10000000 ? 0 : 1)}M`;
        if (tokens >= 1000) return `${(tokens / 1000).toFixed(tokens >= 10000 ? 0 : 1)}K`;
        return tokens.toLocaleString();
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
        const normalized = normalizeRecurrenceValue(value);
        return {
            none: 'None',
            daily: 'Daily',
            weekly: 'Weekly',
            monthly: 'Monthly',
            yearly: 'Yearly',
            specific_days: 'Specific days',
            interval: 'Every interval',
        }[normalized] || '';
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
                updateMultiDayRowVisibility(timeline);
                return;
            }
            if (!selected) return;
            timeline.scrollLeft = Math.max(0, selected.offsetLeft - 74);
            scrollTimelineToCurrentTime(timeline);
            updateMultiDayRowVisibility(timeline);
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
        requestAnimationFrame(() => updateMultiDayRowVisibility(timeline));
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
        updateMultiDayRowVisibility(timelineDrag.timeline);
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
        updateMultiDayRowVisibility(timeline);
        maybeExtendTimelineWindow(timeline);
    }

    function timelineCanScrollHorizontally(timeline) {
        return timeline.scrollWidth - timeline.clientWidth > 2;
    }

    function handleTimelineScroll(event) {
        updateMultiDayRowVisibility(event.currentTarget);
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

    function updateMultiDayRowVisibility(timeline) {
        const row = timeline?.querySelector('[data-multi-day-row]');
        if (!timeline || !row) return;
        const cells = Array.from(row.querySelectorAll('[data-multi-day-cell]'));
        if (!cells.length) return;

        const firstDayHead = timeline.querySelector('.hb-timeline-day-head');
        const dayWidth = firstDayHead?.getBoundingClientRect().width || timelineDayWidth(timeline);
        const firstDayOffset = Number.isFinite(firstDayHead?.offsetLeft) ? firstDayHead.offsetLeft : 74;
        const visibleStart = timeline.scrollLeft + firstDayOffset;
        const visibleEnd = timeline.scrollLeft + timeline.clientWidth;
        const hasVisibleMultiDayEvent = cells.some((cell, index) => {
            if (cell.dataset.hasMultiDay !== 'true') return false;
            const dayStart = firstDayOffset + (index * dayWidth);
            const dayEnd = dayStart + dayWidth;
            return dayEnd > visibleStart + 1 && dayStart < visibleEnd - 1;
        });

        row.classList.toggle('hb-multi-day-row-collapsed', !hasVisibleMultiDayEvent);
        row.setAttribute('aria-hidden', hasVisibleMultiDayEvent ? 'false' : 'true');
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

    function updateTopbarCurrentTime() {
        const time = mount.querySelector('[data-current-time]');
        if (!time) return;
        const now = new Date();
        time.dateTime = now.toISOString();
        time.textContent = formatTime(now);
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
